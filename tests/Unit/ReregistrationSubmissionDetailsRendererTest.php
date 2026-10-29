<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Reregistration\ReregistrationSubmissionDetailsRenderer;

/**
 * Runs the real ReregistrationStandardFieldsSeeder (group labels),
 * ReregistrationSubmissionReader (status labels), RecordGenerator (value
 * formatting) and DateFormatter (over a stubbed `ffc_settings` option and
 * `wp_date`). None of them touches the database on this path.
 *
 * @covers \FreeFormCertificate\Reregistration\ReregistrationSubmissionDetailsRenderer
 */
class ReregistrationSubmissionDetailsRendererTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( function ( $text ) { echo $text; } );
		Functions\when( 'wp_kses_post' )->alias( function ( $html ) { return '[KSES]' . $html . '[/KSES]'; } );
		Functions\when( 'wp_timezone' )->alias( static fn() => new \DateTimeZone( 'UTC' ) );
		Functions\when( 'wp_date' )->alias(
			static fn( $format, $ts = null, $tz = null ) => ( new \DateTimeImmutable( '@' . (int) $ts ) )->setTimezone( $tz ?? new \DateTimeZone( 'UTC' ) )->format( $format )
		);
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) {
				if ( 'ffc_settings' === $key ) {
					// What DateFormatter reads.
					return array(
						'date_format' => 'Y-m-d',
						'time_format' => 'H:i',
					);
				}
				return $default;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function make_field( string $key, string $label, string $group = 'personal', string $type = 'text' ): object {
		return (object) array(
			'field_key'   => $key,
			'field_label' => $label,
			'field_group' => $group,
			'field_type'  => $type,
		);
	}

	private function make_submission( array $overrides = array() ): object {
		return (object) array_merge(
			array(
				'status'       => 'pending',
				'submitted_at' => 0,
				'notes'        => '',
			),
			$overrides
		);
	}

	// ------------------------------------------------------------------
	// Submission metadata block
	// ------------------------------------------------------------------

	public function test_renders_status_badge_with_class_and_label(): void {
		$renderer = new ReregistrationSubmissionDetailsRenderer();
		$html     = $renderer->build_submission_details_html(
			$this->make_submission( array( 'status' => 'submitted' ) ),
			array(),
			array()
		);

		$this->assertStringContainsString( 'ffc-reregistration-status-submitted', $html );
		// The reader's label for `submitted`, not the raw key.
		$this->assertStringContainsString( 'Submitted — Pending Review', $html );
	}

	public function test_renders_submitted_at_when_present(): void {
		$renderer = new ReregistrationSubmissionDetailsRenderer();
		$html     = $renderer->build_submission_details_html(
			$this->make_submission( array( 'submitted_at' => 1715000000 ) ),
			array(),
			array()
		);

		// 1715000000 is 2024-05-06 12:53:20 UTC, in the stored Y-m-d / H:i formats.
		$this->assertStringContainsString( '2024-05-06 12:53', $html );
		$this->assertStringContainsString( 'Submitted:', $html );
	}

	public function test_omits_submitted_at_paragraph_when_zero(): void {
		$renderer = new ReregistrationSubmissionDetailsRenderer();
		$html     = $renderer->build_submission_details_html(
			$this->make_submission( array( 'submitted_at' => 0 ) ),
			array(),
			array()
		);

		$this->assertStringNotContainsString( 'Submitted:', $html );
		$this->assertStringNotContainsString( '1970-01-01', $html );
	}

	// ------------------------------------------------------------------
	// Field grouping
	// ------------------------------------------------------------------

	public function test_groups_fields_by_field_group_preserving_first_seen_order(): void {
		$fields = array(
			$this->make_field( 'name', 'Name', 'personal' ),
			$this->make_field( 'email', 'Email', 'contact' ),
			$this->make_field( 'birthdate', 'Birth Date', 'personal' ),
		);

		$renderer = new ReregistrationSubmissionDetailsRenderer();
		$html     = $renderer->build_submission_details_html(
			$this->make_submission(),
			$fields,
			array( 'name' => 'John', 'email' => 'j@e.com', 'birthdate' => '1990-01-01' )
		);

		$personal_pos = strpos( $html, 'Personal Data' );
		$contact_pos  = strpos( $html, 'Contact Information' );
		$this->assertNotFalse( $personal_pos );
		$this->assertNotFalse( $contact_pos );
		$this->assertLessThan( $contact_pos, $personal_pos, 'personal must precede contact (first-seen order)' );
	}

	public function test_uses_group_label_from_seeder_when_present(): void {
		$renderer = new ReregistrationSubmissionDetailsRenderer();
		$html     = $renderer->build_submission_details_html(
			$this->make_submission(),
			array( $this->make_field( 'wh', 'Hours', 'schedule' ) ),
			array( 'wh' => 'X' )
		);

		// The seeder names `schedule` "Work Schedule"; humanizing the key would say "Schedule".
		$this->assertStringContainsString( '<legend>Work Schedule</legend>', $html );
	}

	public function test_falls_back_to_humanized_group_key_when_seeder_lacks_label(): void {
		$renderer = new ReregistrationSubmissionDetailsRenderer();
		$html     = $renderer->build_submission_details_html(
			$this->make_submission(),
			array( $this->make_field( 'k', 'L', 'work_history' ) ),
			array( 'k' => 'v' )
		);

		$this->assertStringContainsString( 'Work history', $html );
	}

	public function test_renders_empty_group_key_as_other_fields(): void {
		$renderer = new ReregistrationSubmissionDetailsRenderer();
		$html     = $renderer->build_submission_details_html(
			$this->make_submission(),
			array( $this->make_field( 'k', 'L', '' ) ),
			array( 'k' => 'v' )
		);

		$this->assertStringContainsString( 'Other Fields', $html );
	}

	// ------------------------------------------------------------------
	// Field value rendering
	// ------------------------------------------------------------------

	public function test_renders_empty_value_as_em_dash_span(): void {
		$renderer = new ReregistrationSubmissionDetailsRenderer();
		$html     = $renderer->build_submission_details_html(
			$this->make_submission(),
			array( $this->make_field( 'optional', 'Optional', 'personal' ) ),
			array() // No value supplied — defaults to ''.
		);

		$this->assertStringContainsString( 'ffc-details-empty', $html );
		$this->assertStringContainsString( '&mdash;', $html );
	}

	public function test_working_hours_field_routes_through_wp_kses_post(): void {
		$renderer = new ReregistrationSubmissionDetailsRenderer();
		$html     = $renderer->build_submission_details_html(
			$this->make_submission(),
			array( $this->make_field( 'wh', 'Working Hours', 'personal', 'working_hours' ) ),
			array( 'wh' => '[{"day":1,"entry1":"09:00","exit1":"12:00","entry2":"13:00","exit2":"17:00"}]' )
		);

		$this->assertStringContainsString( '[KSES]<table', $html, 'working_hours field type must go through wp_kses_post' );
		$this->assertStringContainsString( '>Mon</th>', $html );
	}

	public function test_non_html_field_value_is_escaped_via_esc_html(): void {
		$renderer = new ReregistrationSubmissionDetailsRenderer();
		$html     = $renderer->build_submission_details_html(
			$this->make_submission(),
			array( $this->make_field( 'name', 'Name', 'personal', 'text' ) ),
			array( 'name' => 'O\'Brien' )
		);

		// wp_kses_post stub wraps with [KSES]; esc_html stub returns arg —
		// so the marker MUST NOT appear for a plain text field.
		$this->assertStringNotContainsString( '[KSES]', $html );
		$this->assertStringContainsString( "O'Brien", $html );
	}

	public function test_renders_field_label_via_dt(): void {
		$renderer = new ReregistrationSubmissionDetailsRenderer();
		$html     = $renderer->build_submission_details_html(
			$this->make_submission(),
			array( $this->make_field( 'name', 'Full Name', 'personal' ) ),
			array( 'name' => 'Jane' )
		);

		$this->assertStringContainsString( '<dt>Full Name</dt>', $html );
	}

	// ------------------------------------------------------------------
	// Review notes block
	// ------------------------------------------------------------------

	public function test_renders_notes_block_when_notes_present(): void {
		$renderer = new ReregistrationSubmissionDetailsRenderer();
		$html     = $renderer->build_submission_details_html(
			$this->make_submission( array( 'notes' => 'Approved by manager.' ) ),
			array(),
			array()
		);

		$this->assertStringContainsString( 'Review Notes', $html );
		$this->assertStringContainsString( 'Approved by manager.', $html );
	}

	public function test_omits_notes_block_when_notes_empty(): void {
		$renderer = new ReregistrationSubmissionDetailsRenderer();
		$html     = $renderer->build_submission_details_html(
			$this->make_submission( array( 'notes' => '' ) ),
			array(),
			array()
		);

		$this->assertStringNotContainsString( 'Review Notes', $html );
	}

	// ------------------------------------------------------------------
	// Wrapper / structure
	// ------------------------------------------------------------------

	public function test_output_is_wrapped_in_submission_details_container(): void {
		$renderer = new ReregistrationSubmissionDetailsRenderer();
		$html     = $renderer->build_submission_details_html( $this->make_submission(), array(), array() );

		$this->assertStringContainsString( 'class="ffc-submission-details"', $html );
		$this->assertStringContainsString( 'class="ffc-submission-meta"', $html );
	}

	public function test_returns_string_even_for_empty_fields_input(): void {
		$renderer = new ReregistrationSubmissionDetailsRenderer();
		$html     = $renderer->build_submission_details_html( $this->make_submission(), array(), array() );

		$this->assertIsString( $html );
		$this->assertNotSame( '', $html );
	}
}
