<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Reregistration\ReregistrationSubmissionDetailsRenderer;

/**
 * @covers \FreeFormCertificate\Reregistration\ReregistrationSubmissionDetailsRenderer
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class ReregistrationSubmissionDetailsRendererTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'esc_html_e' )->alias( function ( $text ) { echo $text; } );
        Functions\when( 'wp_kses_post' )->alias( function ( $html ) { return '[KSES]' . $html . '[/KSES]'; } );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Alias mocks for the renderer's static dependencies.
     * Each test that calls build() must invoke this first.
     */
    private function mock_static_deps( array $group_labels = array(), string $status_label = 'Pending' ): void {
        $seederMock = Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationStandardFieldsSeeder' );
        $seederMock->shouldReceive( 'get_group_labels' )->andReturn( $group_labels );

        $repoMock = Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationSubmissionRepository' );
        $repoMock->shouldReceive( 'get_status_label' )->andReturn( $status_label );

        $dateMock = Mockery::mock( 'alias:FreeFormCertificate\Core\DateFormatter' );
        $dateMock->shouldReceive( 'format_datetime' )->andReturnUsing(
            function ( $ts ) { return '[FORMATTED ' . (int) $ts . ']'; }
        );

        $fichaMock = Mockery::mock( 'alias:FreeFormCertificate\Reregistration\FichaGenerator' );
        $fichaMock->shouldReceive( 'format_field_value' )->andReturnUsing(
            function ( $field, $value ) { return (string) $value; }
        );
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
        $this->mock_static_deps( array(), 'Pending Review' );

        $renderer = new ReregistrationSubmissionDetailsRenderer();
        $html     = $renderer->build_submission_details_html(
            $this->make_submission( array( 'status' => 'pending' ) ),
            array(),
            array()
        );

        $this->assertStringContainsString( 'ffc-status-pending', $html );
        $this->assertStringContainsString( 'Pending Review', $html );
    }

    public function test_renders_submitted_at_when_present(): void {
        $this->mock_static_deps();

        $renderer = new ReregistrationSubmissionDetailsRenderer();
        $html     = $renderer->build_submission_details_html(
            $this->make_submission( array( 'submitted_at' => 1715000000 ) ),
            array(),
            array()
        );

        $this->assertStringContainsString( '[FORMATTED 1715000000]', $html );
        $this->assertStringContainsString( 'Submitted:', $html );
    }

    public function test_omits_submitted_at_paragraph_when_zero(): void {
        $this->mock_static_deps();

        $renderer = new ReregistrationSubmissionDetailsRenderer();
        $html     = $renderer->build_submission_details_html(
            $this->make_submission( array( 'submitted_at' => 0 ) ),
            array(),
            array()
        );

        $this->assertStringNotContainsString( 'Submitted:', $html );
        $this->assertStringNotContainsString( '[FORMATTED', $html );
    }

    // ------------------------------------------------------------------
    // Field grouping
    // ------------------------------------------------------------------

    public function test_groups_fields_by_field_group_preserving_first_seen_order(): void {
        $this->mock_static_deps(
            array(
                'personal' => 'Personal Data',
                'contact'  => 'Contact Information',
            )
        );

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
        $this->mock_static_deps( array( 'personal' => 'Custom Personal Label' ) );

        $renderer = new ReregistrationSubmissionDetailsRenderer();
        $html     = $renderer->build_submission_details_html(
            $this->make_submission(),
            array( $this->make_field( 'name', 'Name', 'personal' ) ),
            array( 'name' => 'X' )
        );

        $this->assertStringContainsString( 'Custom Personal Label', $html );
    }

    public function test_falls_back_to_humanized_group_key_when_seeder_lacks_label(): void {
        $this->mock_static_deps( array() );

        $renderer = new ReregistrationSubmissionDetailsRenderer();
        $html     = $renderer->build_submission_details_html(
            $this->make_submission(),
            array( $this->make_field( 'k', 'L', 'work_history' ) ),
            array( 'k' => 'v' )
        );

        $this->assertStringContainsString( 'Work history', $html );
    }

    public function test_renders_empty_group_key_as_other_fields(): void {
        $this->mock_static_deps();

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
        $this->mock_static_deps();

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
        $this->mock_static_deps();

        $renderer = new ReregistrationSubmissionDetailsRenderer();
        $html     = $renderer->build_submission_details_html(
            $this->make_submission(),
            array( $this->make_field( 'wh', 'Working Hours', 'personal', 'working_hours' ) ),
            array( 'wh' => '<table><tr><td>Mon</td><td>9-5</td></tr></table>' )
        );

        $this->assertStringContainsString( '[KSES]', $html, 'working_hours field type must go through wp_kses_post' );
        $this->assertStringContainsString( '<table>', $html );
    }

    public function test_non_html_field_value_is_escaped_via_esc_html(): void {
        $this->mock_static_deps();

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
        $this->mock_static_deps();

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
        $this->mock_static_deps();

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
        $this->mock_static_deps();

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
        $this->mock_static_deps();

        $renderer = new ReregistrationSubmissionDetailsRenderer();
        $html     = $renderer->build_submission_details_html( $this->make_submission(), array(), array() );

        $this->assertStringContainsString( 'class="ffc-submission-details"', $html );
        $this->assertStringContainsString( 'class="ffc-submission-meta"', $html );
    }

    public function test_returns_string_even_for_empty_fields_input(): void {
        $this->mock_static_deps();

        $renderer = new ReregistrationSubmissionDetailsRenderer();
        $html     = $renderer->build_submission_details_html( $this->make_submission(), array(), array() );

        $this->assertIsString( $html );
        $this->assertNotSame( '', $html );
    }
}
