<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Frontend\Submission\FieldRulesGuard;
use FreeFormCertificate\Frontend\Submission\SubmissionContext;
use FreeFormCertificate\Frontend\Submission\SubmissionRejected;
use PHPUnit\Framework\TestCase;

/**
 * The server-side rules of each field, enforced whatever the browser
 * allowed (#1729).
 *
 * @covers \FreeFormCertificate\Frontend\Submission\FieldRulesGuard
 */
class FieldRulesGuardTest extends TestCase {

	private FieldRulesGuard $guard;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( '_n' )->alias( static fn( $single, $plural, $n ) => 1 === (int) $n ? $single : $plural );
		Functions\when( 'number_format_i18n' )->alias( static fn( $n, $d = 0 ) => number_format( (float) $n, (int) $d ) );
		Functions\when( 'wp_date' )->alias( static fn( $format, $ts ) => gmdate( 'd/m/Y', (int) $ts ) );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_timezone' )->alias( static fn() => new \DateTimeZone( 'UTC' ) );
		$this->guard = new FieldRulesGuard();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function field( string $type, array $extra = array() ): array {
		return array(
			'type'  => $type,
			'name'  => 'f',
			'label' => 'Field',
		) + $extra;
	}

	public function test_a_required_field_left_empty_is_refused(): void {
		$this->assertSame( 'Field is required.', $this->guard->check( $this->field( 'text', array( 'required' => '1' ) ), array( 'f' => '  ' ) ) );
		$this->assertNull( $this->guard->check( $this->field( 'text' ), array() ) );
	}

	public function test_an_empty_email_is_left_to_the_preflight(): void {
		$this->assertNull( $this->guard->check( $this->field( 'email', array( 'required' => '1' ) ), array() ) );
	}

	public function test_a_required_checkbox_needs_one_box(): void {
		$field = $this->field( 'checkbox', array( 'required' => '1', 'choices' => array( 'A', 'B' ) ) );
		$this->assertSame( 'Field: choose at least one option.', $this->guard->check( $field, array( 'f' => array() ) ) );
		$this->assertNull( $this->guard->check( $field, array( 'f' => array( 'B' ) ) ) );
	}

	public function test_an_answer_outside_the_options_is_refused(): void {
		$field = $this->field( 'radio', array( 'choices' => array( 'A', 'B' ) ) );
		$this->assertSame( 'Field has an answer that is not one of its options.', $this->guard->check( $field, array( 'f' => 'Z' ) ) );
	}

	public function test_an_option_with_an_ampersand_matches_its_sanitized_answer(): void {
		// wp_kses writes a bare & as &amp; on the way in.
		$field = $this->field( 'select', array( 'choices' => array( 'Research & Development' ) ) );
		$this->assertNull( $this->guard->check( $field, array( 'f' => 'Research &amp; Development' ) ) );
	}

	public function test_a_legacy_comma_string_field_still_validates(): void {
		$field = $this->field( 'select', array( 'options' => 'SP, RJ' ) );
		$this->assertNull( $this->guard->check( $field, array( 'f' => 'RJ' ) ) );
	}

	public function test_a_single_choice_field_refuses_a_list(): void {
		$field = $this->field( 'radio', array( 'choices' => array( 'A', 'B' ) ) );
		$this->assertSame( 'Field has an invalid answer.', $this->guard->check( $field, array( 'f' => array( 'A', 'B' ) ) ) );
	}

	public function test_a_text_field_refuses_a_list(): void {
		$this->assertSame( 'Field has an invalid answer.', $this->guard->check( $this->field( 'text' ), array( 'f' => array( 'a' ) ) ) );
	}

	public function test_a_checkbox_over_its_maximum_is_refused(): void {
		$field = $this->field( 'checkbox', array( 'choices' => array( 'A', 'B', 'C' ), 'max_select' => '2' ) );
		$this->assertSame( 'Field: choose up to 2 options.', $this->guard->check( $field, array( 'f' => array( 'A', 'B', 'C' ) ) ) );
		$this->assertNull( $this->guard->check( $field, array( 'f' => array( 'A', 'C' ) ) ) );
	}

	public function test_length_is_counted_in_characters_after_decoding(): void {
		$field = $this->field( 'text', array( 'min_length' => '3', 'max_length' => '4' ) );
		$this->assertSame( 'Field needs at least 3 characters.', $this->guard->check( $field, array( 'f' => 'ab' ) ) );
		$this->assertSame( 'Field accepts up to 4 characters.', $this->guard->check( $field, array( 'f' => 'abcde' ) ) );
		// "a&b" stored as "a&amp;b" is three characters, not seven.
		$this->assertNull( $this->guard->check( $field, array( 'f' => 'a&amp;b' ) ) );
		$this->assertNull( $this->guard->check( $field, array( 'f' => 'ação' ) ) );
	}

	public function test_the_server_ceiling_holds_without_a_configured_maximum(): void {
		$long = str_repeat( 'x', 256 );
		$this->assertSame( 'Field accepts up to 255 characters.', $this->guard->check( $this->field( 'text' ), array( 'f' => $long ) ) );
		$this->assertNull( $this->guard->check( $this->field( 'textarea' ), array( 'f' => $long ) ) );
	}

	public function test_number_rules(): void {
		$field = $this->field( 'number', array( 'min' => '1', 'max' => '10' ) );
		$this->assertSame( 'Field must be a number.', $this->guard->check( $field, array( 'f' => 'ten' ) ) );
		$this->assertSame( 'Field must be a whole number.', $this->guard->check( $field, array( 'f' => '2.5' ) ) );
		$this->assertSame( 'Field is out of range. Between 1 and 10.', $this->guard->check( $field, array( 'f' => '11' ) ) );
		$this->assertNull( $this->guard->check( $field, array( 'f' => '10' ) ) );

		$decimal = $this->field( 'number', array( 'decimals' => '1' ) );
		$this->assertSame( 'Field accepts up to 1 decimal places.', $this->guard->check( $decimal, array( 'f' => '2.55' ) ) );
		$this->assertNull( $this->guard->check( $decimal, array( 'f' => '2.50' ) ) );
	}

	public function test_date_rules(): void {
		$field = $this->field( 'date', array( 'min' => '1800-01-01', 'max' => '1999-12-31' ) );
		$this->assertSame( 'Field must be a valid date.', $this->guard->check( $field, array( 'f' => '1889-02-30' ) ) );
		$this->assertSame( 'Field is out of range. Between 01/01/1800 and 31/12/1999.', $this->guard->check( $field, array( 'f' => '2001-01-01' ) ) );
		$this->assertNull( $this->guard->check( $field, array( 'f' => '1889-11-15' ) ) );
	}

	public function test_display_fields_are_never_checked(): void {
		$this->assertNull( $this->guard->check( array( 'type' => 'info', 'required' => '1' ), array() ) );
		$this->assertNull( $this->guard->check( $this->field( 'hidden', array( 'required' => '1' ) ), array() ) );
	}

	public function test_apply_refuses_with_every_broken_rule(): void {
		$ctx                  = new SubmissionContext();
		$ctx->fields_config   = array(
			$this->field( 'text', array( 'required' => '1' ) ),
			array(
				'type'     => 'number',
				'name'     => 'age',
				'label'    => 'Age',
				'required' => '1',
			),
		);
		$ctx->submission_data = array( 'age' => 'x' );

		try {
			$this->guard->apply( $ctx );
			$this->fail( 'Expected a rejection.' );
		} catch ( SubmissionRejected $e ) {
			$payload = $e->get_payload();
			$this->assertSame( 'Field is required.', $payload['message'] );
			$this->assertSame( array( 'Field is required.', 'Age must be a number.' ), $payload['errors'] );
		}
	}

	public function test_apply_passes_a_valid_submission(): void {
		$ctx                  = new SubmissionContext();
		$ctx->fields_config   = array( $this->field( 'text', array( 'required' => '1' ) ) );
		$ctx->submission_data = array( 'f' => 'ok' );
		$this->guard->apply( $ctx );
		$this->assertSame( array( 'f' => 'ok' ), $ctx->submission_data );
	}
}
