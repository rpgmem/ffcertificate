<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Core\FormField;
use PHPUnit\Framework\TestCase;

/**
 * A form field's stored options and limits: both shapes of the options, the
 * server ceilings, and what an editor row is saved as (#1729).
 *
 * @covers \FreeFormCertificate\Core\FormField
 */
class FormFieldTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => trim( strip_tags( (string) $v ) ) );
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ---- choices -------------------------------------------------------

	public function test_the_list_keeps_a_comma_inside_an_option(): void {
		$field = array( 'choices' => array( 'Yes, I agree', ' No ', '' ) );
		$this->assertSame( array( 'Yes, I agree', 'No' ), FormField::choices( $field ) );
	}

	public function test_a_field_saved_before_the_list_is_read_from_its_comma_string(): void {
		$this->assertSame( array( 'A', 'B', 'C' ), FormField::choices( array( 'options' => 'A, B,,C ' ) ) );
	}

	public function test_a_legacy_options_list_is_read_as_is(): void {
		$this->assertSame( array( 'SP', 'RJ' ), FormField::choices( array( 'options' => array( 'SP', 'RJ' ) ) ) );
	}

	public function test_the_list_wins_over_a_stale_string(): void {
		$field = array(
			'choices' => array( 'New' ),
			'options' => 'Old, Older',
		);
		$this->assertSame( array( 'New' ), FormField::choices( $field ) );
	}

	public function test_non_scalar_entries_are_dropped(): void {
		$this->assertSame( array( 'A' ), FormField::choices( array( 'choices' => array( array( 'x' ), 'A', null ) ) ) );
	}

	// ---- limits --------------------------------------------------------

	public function test_length_falls_back_to_the_type_ceiling(): void {
		$this->assertSame( FormField::TEXT_MAX_LENGTH, FormField::max_length( array( 'type' => 'text' ) ) );
		$this->assertSame( FormField::TEXTAREA_MAX_LENGTH, FormField::max_length( array( 'type' => 'textarea' ) ) );
		$this->assertSame( 0, FormField::max_length( array( 'type' => 'email' ) ) );
	}

	public function test_a_configured_maximum_never_exceeds_the_ceiling(): void {
		$this->assertSame( 80, FormField::max_length( array( 'type' => 'text', 'max_length' => '80' ) ) );
		$this->assertSame( 255, FormField::max_length( array( 'type' => 'text', 'max_length' => '9000' ) ) );
	}

	public function test_the_minimum_never_exceeds_the_maximum(): void {
		$field = array(
			'type'       => 'textarea',
			'min_length' => '500',
			'max_length' => '100',
		);
		$this->assertSame( 100, FormField::min_length( $field ) );
		$this->assertTrue( FormField::has_configured_length( $field ) );
		$this->assertFalse( FormField::has_configured_length( array( 'type' => 'textarea' ) ) );
	}

	public function test_max_select_is_a_checkbox_setting(): void {
		$this->assertSame( 3, FormField::max_select( array( 'type' => 'checkbox', 'max_select' => '3' ) ) );
		$this->assertSame( 0, FormField::max_select( array( 'type' => 'radio', 'max_select' => '3' ) ) );
	}

	public function test_shuffle_is_a_choice_setting(): void {
		$this->assertTrue( FormField::shuffles( array( 'type' => 'select', 'shuffle' => '1' ) ) );
		$this->assertFalse( FormField::shuffles( array( 'type' => 'text', 'shuffle' => '1' ) ) );
	}

	public function test_bounds_are_validated_for_their_type(): void {
		$this->assertSame( '1.5', FormField::min( array( 'type' => 'number', 'min' => '1.5' ) ) );
		$this->assertSame( '', FormField::min( array( 'type' => 'number', 'min' => 'abc' ) ) );
		$this->assertSame( '2026-02-28', FormField::max( array( 'type' => 'date', 'max' => '2026-02-28' ) ) );
		$this->assertSame( '', FormField::max( array( 'type' => 'date', 'max' => '2026-02-30' ) ) );
		$this->assertSame( '', FormField::max( array( 'type' => 'text', 'max' => '5' ) ) );
	}

	public function test_the_step_follows_the_decimal_places(): void {
		$this->assertSame( '1', FormField::step( array( 'type' => 'number' ) ) );
		$this->assertSame( '0.01', FormField::step( array( 'type' => 'number', 'decimals' => '2' ) ) );
		$this->assertSame( '0.0001', FormField::step( array( 'type' => 'number', 'decimals' => '99' ) ) );
	}

	public function test_decimals_in_ignores_trailing_zeros(): void {
		$this->assertSame( 0, FormField::decimals_in( '12' ) );
		$this->assertSame( 2, FormField::decimals_in( '1.25' ) );
		$this->assertSame( 1, FormField::decimals_in( '1.50' ) );
	}

	public function test_is_date_refuses_an_impossible_day(): void {
		$this->assertTrue( FormField::is_date( '2024-02-29' ) );
		$this->assertFalse( FormField::is_date( '2023-02-29' ) );
		$this->assertFalse( FormField::is_date( '15/11/1889' ) );
	}

	// ---- sanitize_limits ------------------------------------------------

	public function test_a_choice_row_is_saved_as_a_list(): void {
		$clean = FormField::sanitize_limits(
			array(
				'choices'    => array( ' A ', '<b>B</b>', '' ),
				'shuffle'    => '1',
				'max_select' => '9',
			),
			'checkbox'
		);
		$this->assertSame( array( 'A', 'B' ), $clean['choices'] );
		$this->assertSame( '1', $clean['shuffle'] );
		// Capped at the number of options: a larger maximum means nothing.
		$this->assertSame( '2', $clean['max_select'] );
		$this->assertArrayNotHasKey( 'min_length', $clean );
	}

	public function test_a_row_posted_by_an_older_editor_is_converted(): void {
		$clean = FormField::sanitize_limits( array( 'options' => 'X, Y' ), 'radio' );
		$this->assertSame( array( 'X', 'Y' ), $clean['choices'] );
		$this->assertArrayNotHasKey( 'max_select', $clean );
	}

	public function test_length_limits_are_bounded_on_save(): void {
		$clean = FormField::sanitize_limits(
			array(
				'min_length' => '300',
				'max_length' => '5000',
			),
			'text'
		);
		// The maximum is the ceiling (stored as "no own maximum"); the
		// minimum cannot pass it.
		$this->assertSame( '', $clean['max_length'] );
		$this->assertSame( '255', $clean['min_length'] );
	}

	public function test_a_reversed_range_is_swapped(): void {
		$clean = FormField::sanitize_limits(
			array(
				'min'      => '10',
				'max'      => '2',
				'decimals' => '7',
			),
			'number'
		);
		$this->assertSame( '2', $clean['min'] );
		$this->assertSame( '10', $clean['max'] );
		$this->assertSame( (string) FormField::MAX_DECIMALS, $clean['decimals'] );
	}

	public function test_a_date_range_is_read_from_its_own_keys(): void {
		$clean = FormField::sanitize_limits(
			array(
				'min'      => '5',
				'min_date' => '2000-01-01',
				'max_date' => 'not a date',
			),
			'date'
		);
		$this->assertSame( '2000-01-01', $clean['min'] );
		$this->assertSame( '', $clean['max'] );
		$this->assertArrayNotHasKey( 'decimals', $clean );
	}

	public function test_a_type_without_limits_stores_none(): void {
		$this->assertSame( array(), FormField::sanitize_limits( array( 'min' => '1' ), 'email' ) );
	}
}
