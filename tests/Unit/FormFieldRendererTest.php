<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Frontend\FormFieldRenderer;
use PHPUnit\Framework\TestCase;

/**
 * The participant-facing markup of an input field: the checkbox group that
 * used to render as an empty box, and the limits as attributes and as a
 * sentence (#1729).
 *
 * @covers \FreeFormCertificate\Frontend\FormFieldRenderer
 */
class FormFieldRendererTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->alias( static fn( $v ) => htmlspecialchars( (string) $v, ENT_QUOTES ) );
		Functions\when( 'esc_attr' )->alias( static fn( $v ) => htmlspecialchars( (string) $v, ENT_QUOTES ) );
		Functions\when( 'esc_textarea' )->alias( static fn( $v ) => htmlspecialchars( (string) $v, ENT_QUOTES ) );
		Functions\when( '_n' )->alias( static fn( $single, $plural, $n ) => 1 === (int) $n ? $single : $plural );
		Functions\when( 'number_format_i18n' )->alias( static fn( $n, $d = 0 ) => number_format( (float) $n, (int) $d ) );
		Functions\when( 'checked' )->alias(
			static function ( $a, $b = true ) {
				if ( (string) $a === (string) $b ) {
					echo " checked='checked'";
				}
			}
		);
		Functions\when( 'selected' )->alias( static fn( $a, $b = true, $echo = true ) => (string) $a === (string) $b ? " selected='selected'" : '' );
		Functions\when( 'wp_date' )->alias( static fn( $format, $ts ) => gmdate( 'd/m/Y', (int) $ts ) );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_timezone' )->alias( static fn() => new \DateTimeZone( 'UTC' ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function render( array $field ): string {
		$field += array(
			'name'  => 'f',
			'label' => 'Field',
		);
		return ( new FormFieldRenderer() )->render( $field, (string) $field['type'] );
	}

	public function test_a_checkbox_renders_one_box_per_option_posting_a_list(): void {
		$html = $this->render(
			array(
				'type'    => 'checkbox',
				'choices' => array( 'PHP', 'Python, 3' ),
			)
		);
		$this->assertStringContainsString( '<fieldset class="ffc-form-field ffc-choice-field ffc-choice-checkbox"', $html );
		$this->assertStringContainsString( '<legend class="ffc-field-legend">', $html );
		$this->assertSame( 2, substr_count( $html, 'type="checkbox" name="f[]"' ) );
		$this->assertStringContainsString( 'value="Python, 3"', $html );
		// The old fallback: a single box styled as a text input.
		$this->assertStringNotContainsString( 'class="ffc-input" type="checkbox"', $html );
	}

	public function test_a_required_checkbox_group_never_puts_required_on_a_box(): void {
		$html = $this->render(
			array(
				'type'     => 'checkbox',
				'required' => '1',
				'choices'  => array( 'A', 'B' ),
			)
		);
		$this->assertStringContainsString( 'data-ffc-required="1"', $html );
		$this->assertStringNotContainsString( ' required', str_replace( 'data-ffc-required', '', $html ) );
	}

	public function test_a_capped_checkbox_announces_its_maximum_and_carries_a_counter(): void {
		$html = $this->render(
			array(
				'type'       => 'checkbox',
				'max_select' => '2',
				'choices'    => array( 'A', 'B', 'C' ),
			)
		);
		$this->assertStringContainsString( 'data-ffc-max-select="2"', $html );
		$this->assertStringContainsString( 'Choose up to 2 options.', $html );
		$this->assertStringContainsString( 'aria-describedby="f-hint"', $html );
		$this->assertStringContainsString( 'ffc-select-counter', $html );
	}

	public function test_a_required_radio_keeps_html_required_and_reads_a_legacy_string(): void {
		$html = $this->render(
			array(
				'type'     => 'radio',
				'required' => '1',
				'options'  => 'Yes, No',
				'shuffle'  => '1',
			)
		);
		$this->assertSame( 2, substr_count( $html, 'type="radio" name="f" value=' ) );
		$this->assertSame( 2, substr_count( $html, 'required aria-required="true"' ) );
		$this->assertStringContainsString( 'data-ffc-shuffle="1"', $html );
	}

	public function test_a_textarea_has_rows_its_limits_a_counter_and_a_hint(): void {
		$html = $this->render(
			array(
				'type'       => 'textarea',
				'min_length' => '50',
				'max_length' => '500',
			)
		);
		$this->assertStringContainsString( 'rows="4"', $html );
		$this->assertStringContainsString( 'maxlength="500"', $html );
		$this->assertStringContainsString( 'minlength="50"', $html );
		$this->assertStringContainsString( '0 / 500', $html );
		$this->assertStringContainsString( 'Between 50 and 500 characters.', $html );
	}

	public function test_a_text_field_without_limits_shows_only_the_ceiling_counter(): void {
		$html = $this->render( array( 'type' => 'text' ) );
		$this->assertStringContainsString( 'maxlength="255"', $html );
		$this->assertStringContainsString( '0 / 255', $html );
		$this->assertStringNotContainsString( 'ffc-field-hint', $html );
		$this->assertStringNotContainsString( 'aria-describedby', $html );
	}

	public function test_a_number_carries_its_range_and_step(): void {
		$html = $this->render(
			array(
				'type'     => 'number',
				'min'      => '0',
				'max'      => '10',
				'decimals' => '2',
			)
		);
		$this->assertStringContainsString( 'min="0" max="10" step="0.01"', $html );
		$this->assertStringContainsString( 'Between 0 and 10.', $html );
	}

	public function test_a_date_announces_its_range_in_the_wall_clock_day(): void {
		$html = $this->render(
			array(
				'type' => 'date',
				'min'  => '1800-01-01',
			)
		);
		$this->assertStringContainsString( 'min="1800-01-01"', $html );
		$this->assertStringContainsString( 'From 01/01/1800.', $html );
	}

	public function test_a_select_can_shuffle_and_keeps_its_empty_first_option(): void {
		$html = $this->render(
			array(
				'type'    => 'select',
				'choices' => array( 'A', 'B' ),
				'shuffle' => '1',
			)
		);
		$this->assertStringContainsString( 'data-ffc-shuffle="1"', $html );
		$this->assertMatchesRegularExpression( '/<select[^>]*><option value="">Select\.\.\.<\/option><option value="A">/', $html );
	}

	public function test_hint_for_one_sided_ranges(): void {
		$renderer = new FormFieldRenderer();
		$this->assertSame( '5 or more.', $renderer->hint( array( 'type' => 'number', 'min' => '5' ) ) );
		$this->assertSame( '5 or less.', $renderer->hint( array( 'type' => 'number', 'max' => '5' ) ) );
		$this->assertSame( 'Until 31/12/1999.', $renderer->hint( array( 'type' => 'date', 'max' => '1999-12-31' ) ) );
		$this->assertSame( 'At least 3 characters.', $renderer->hint( array( 'type' => 'text', 'min_length' => '3' ) ) );
		$this->assertSame( 'Up to 80 characters.', $renderer->hint( array( 'type' => 'text', 'max_length' => '80' ) ) );
		$this->assertSame( '', $renderer->hint( array( 'type' => 'email' ) ) );
	}
}
