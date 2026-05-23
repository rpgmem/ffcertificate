<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Frontend\VerificationResponseRenderer;

/**
 * @covers \FreeFormCertificate\Frontend\VerificationResponseRenderer
 * @runClassInSeparateProcess
 * @preserveGlobalState disabled
 */
class VerificationResponseRendererTest extends TestCase {

    use MockeryPHPUnitIntegration;

    private VerificationResponseRenderer $renderer;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'esc_url' )->returnArg();
        Functions\when( 'esc_html_e' )->alias( function ( $text ) { echo $text; } );
        Functions\when( 'wp_kses_post' )->returnArg();
        Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
            if ( $key === 'date_format' ) return 'Y-m-d';
            if ( $key === 'time_format' ) return 'H:i';
            return $default;
        } );
        Functions\when( 'date_i18n' )->alias( function ( $format, $ts ) {
            return date( $format, $ts );
        } );
        Functions\when( 'wp_date' )->alias( function ( $format, $ts = null, $tz = null ) {
            return date( $format, $ts ?? time() );
        } );
        Functions\when( 'wp_timezone' )->alias( function () {
            return new \DateTimeZone( 'UTC' );
        } );
        Functions\when( 'is_email' )->alias( function ( $email ) {
            return false !== filter_var( (string) $email, FILTER_VALIDATE_EMAIL );
        } );

        if ( ! defined( 'ABSPATH' ) ) {
            define( 'ABSPATH', '/tmp/' );
        }

        $this->renderer = new VerificationResponseRenderer();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // get_field_label() — known fields
    // ==================================================================

    public function test_get_field_label_returns_known_labels(): void {
        $this->assertSame( 'CPF/RF', $this->renderer->get_field_label( 'cpf_rf' ) );
        $this->assertSame( 'Name', $this->renderer->get_field_label( 'name' ) );
        $this->assertSame( 'Email', $this->renderer->get_field_label( 'email' ) );
        $this->assertSame( 'Program', $this->renderer->get_field_label( 'program' ) );
        $this->assertSame( 'Date', $this->renderer->get_field_label( 'date' ) );
        $this->assertSame( 'RG', $this->renderer->get_field_label( 'rg' ) );
        $this->assertSame( 'Phone', $this->renderer->get_field_label( 'phone' ) );
        $this->assertSame( 'ZIP Code', $this->renderer->get_field_label( 'zip' ) );
    }

    // ==================================================================
    // get_field_label() — unknown field falls back to ucwords
    // ==================================================================

    public function test_get_field_label_falls_back_for_unknown_field(): void {
        $this->assertSame( 'Custom Field', $this->renderer->get_field_label( 'custom_field' ) );
        $this->assertSame( 'My Data', $this->renderer->get_field_label( 'my-data' ) );
    }

    // ==================================================================
    // format_field_value() — string values
    // ==================================================================

    public function test_format_field_value_returns_string_as_is(): void {
        $this->assertSame( 'John', $this->renderer->format_field_value( 'name', 'John' ) );
    }

    // ==================================================================
    // format_field_value() — array values
    // ==================================================================

    public function test_format_field_value_joins_array_values(): void {
        $this->assertSame( 'a, b, c', $this->renderer->format_field_value( 'tags', array( 'a', 'b', 'c' ) ) );
    }

    // ==================================================================
    // format_field_value() — document fields (cpf, cpf_rf, rg)
    // ==================================================================

    public function test_format_field_value_formats_document_fields(): void {
        // 6.7.2 — cpf_rf is now MASKED for the public /valid page
        // (not formatted in full). Asserts the result is non-empty
        // and carries the masked-CPF marker (asterisks).
        $result = $this->renderer->format_field_value( 'cpf_rf', '12345678901' );
        $this->assertNotEmpty( $result );
        $this->assertStringContainsString( '*', $result );
    }

    public function test_format_field_value_masks_cpf(): void {
        // /valid is public — full CPF surfaced is a privacy leak.
        $result = $this->renderer->format_field_value( 'cpf_rf', '12345678909' );
        $this->assertSame( '123.***.***-09', $result );
    }

    public function test_format_field_value_masks_email(): void {
        // Same privacy concern for email.
        $result = $this->renderer->format_field_value( 'email', 'maria.silva@example.com' );
        $this->assertStringContainsString( '*', $result );
        $this->assertStringContainsString( '@example.com', $result );
        $this->assertStringNotContainsString( 'maria.silva@example.com', $result );
    }

    public function test_format_field_value_does_not_mask_other_fields(): void {
        // Name / program / arbitrary fields stay verbatim — no over-masking.
        $this->assertSame( 'Maria Silva', $this->renderer->format_field_value( 'name', 'Maria Silva' ) );
        $this->assertSame( 'Programa 123', $this->renderer->format_field_value( 'program', 'Programa 123' ) );
    }

    // ==================================================================
    // format_appointment_verification_response()
    // ==================================================================

    public function test_format_appointment_verification_response_renders_html(): void {
        $result = array(
            'data' => array(
                'name'           => 'Maria Silva',
                'cpf_rf'         => '12345678901',
                'calendar_title' => 'Workshop PHP',
            ),
            'appointment' => array(
                'validation_code'  => 'APT123456',
                'appointment_date' => '2025-06-15',
                'start_time'       => '10:00',
                'end_time'         => '11:00',
                'status'           => 'confirmed',
                'created_at'       => '2025-06-01 09:00:00',
            ),
        );

        $html = $this->renderer->format_appointment_verification_response( $result );

        $this->assertStringContainsString( 'ffc-appointment-verification', $html );
        $this->assertStringContainsString( 'Appointment Receipt Valid', $html );
        $this->assertStringContainsString( 'Confirmed', $html );
        $this->assertStringContainsString( 'Maria Silva', $html );
        $this->assertStringContainsString( 'Workshop PHP', $html );
        $this->assertStringContainsString( 'Download Receipt (PDF)', $html );
    }

    // ==================================================================
    // format_appointment_verification_response() — pending status
    // ==================================================================

    public function test_format_appointment_renders_pending_status(): void {
        $result = array(
            'data' => array( 'name' => 'João' ),
            'appointment' => array(
                'appointment_date' => '2025-07-01',
                'start_time'       => '14:00',
                'status'           => 'pending',
                'created_at'       => '2025-06-20 10:00:00',
            ),
        );

        $html = $this->renderer->format_appointment_verification_response( $result );

        $this->assertStringContainsString( 'Pending Approval', $html );
        $this->assertStringContainsString( 'ffc-status-pending', $html );
    }

    // ==================================================================
    // format_appointment_verification_response() — empty dates
    // ==================================================================

    public function test_format_appointment_shows_na_for_missing_dates(): void {
        $result = array(
            'data' => array(),
            'appointment' => array(
                'appointment_date' => '',
                'start_time'       => '',
                'status'           => 'cancelled',
                'created_at'       => '',
            ),
        );

        $html = $this->renderer->format_appointment_verification_response( $result );

        $this->assertStringContainsString( 'N/A', $html );
        $this->assertStringContainsString( 'Cancelled', $html );
    }

    // ==================================================================
    // format_reregistration_verification_response()
    // ==================================================================

    public function test_format_reregistration_renders_approved_status(): void {
        $result = array(
            'reregistration' => array(
                'auth_code'    => 'RR123456',
                'display_name' => 'Carlos Santos',
                'cpf'          => '98765432100',
                'email'        => 'carlos@example.com',
                'submitted_at' => '2025-05-10 08:30:00',
                'status'       => 'approved',
                'status_label' => 'Approved',
                'title'        => 'Rematrícula 2025',
            ),
        );

        $html = $this->renderer->format_reregistration_verification_response( $result );

        $this->assertStringContainsString( 'ffc-reregistration-verification', $html );
        $this->assertStringContainsString( 'Reregistration Record Valid', $html );
        $this->assertStringContainsString( 'success', $html ); // status class
        $this->assertStringContainsString( 'Carlos Santos', $html );
        $this->assertStringContainsString( 'carlos@example.com', $html );
        $this->assertStringContainsString( 'Rematrícula 2025', $html );
        $this->assertStringContainsString( 'Download Ficha (PDF)', $html );
    }

    // ==================================================================
    // format_reregistration_verification_response() — rejected status
    // ==================================================================

    public function test_format_reregistration_renders_rejected_status(): void {
        $result = array(
            'reregistration' => array(
                'auth_code'    => '',
                'display_name' => 'Ana',
                'cpf'          => '',
                'email'        => '',
                'submitted_at' => '',
                'status'       => 'rejected',
                'status_label' => 'Rejected',
                'title'        => 'Rematrícula',
            ),
        );

        $html = $this->renderer->format_reregistration_verification_response( $result );

        $this->assertStringContainsString( 'error', $html ); // status class for rejected
        $this->assertStringContainsString( 'ffc-status-rejected', $html );
    }

    // ==================================================================
    // format_reregistration_verification_response() — info status
    // ==================================================================

    public function test_format_reregistration_renders_info_status_for_other(): void {
        $result = array(
            'reregistration' => array(
                'auth_code'    => '',
                'display_name' => '',
                'cpf'          => '',
                'email'        => '',
                'submitted_at' => '',
                'status'       => 'pending',
                'status_label' => 'Pending',
                'title'        => 'Test',
            ),
        );

        $html = $this->renderer->format_reregistration_verification_response( $result );

        $this->assertStringContainsString( 'info', $html ); // default status class
    }

    // ==================================================================
    // format_verification_response() — certificate
    // ==================================================================

    public function test_format_verification_response_renders_certificate(): void {
        if ( ! defined( 'FFC_PLUGIN_DIR' ) ) {
            define( 'FFC_PLUGIN_DIR', '/tmp/ffc_test_dir/' );
        }

        // Create a minimal template
        @mkdir( '/tmp/ffc_test_dir/templates', 0777, true );
        file_put_contents(
            '/tmp/ffc_test_dir/templates/certificate-preview.php',
            '<div class="ffc-certificate-preview">'
            . '<span class="ffc-auth-code"><?php echo esc_html( $display_code ); ?></span>'
            . '<span class="ffc-form-title"><?php echo esc_html( $form_title ); ?></span>'
            . '</div>'
        );

        Functions\when( 'get_post' )->justReturn( (object) array( 'post_title' => 'My Certificate' ) );

        $submission = (object) array(
            'form_id'         => 1,
            'submission_date' => '2025-06-01 10:00:00',
        );
        $data = array(
            'auth_code' => 'ABC123',
            'name'      => 'John Doe',
            'email'     => 'john@example.com',
        );

        $html = $this->renderer->format_verification_response( $submission, $data );

        $this->assertStringContainsString( 'ffc-certificate-preview', $html );
        $this->assertStringContainsString( 'My Certificate', $html );

        // Cleanup
        @unlink( '/tmp/ffc_test_dir/templates/certificate-preview.php' );
    }

    // ==================================================================
    // generate_appointment_verification_pdf()
    // ==================================================================

    public function test_generate_appointment_verification_pdf(): void {
        $result = array(
            'data' => array( 'calendar_title' => 'Event Title' ),
            'appointment' => array(
                'calendar_id'     => 0,
                'validation_code' => 'V123',
            ),
        );

        $pdf_generator = Mockery::mock( 'FreeFormCertificate\Generators\PdfGenerator' );
        $pdf_generator->shouldReceive( 'generate_appointment_pdf_data' )
            ->once()
            ->with( $result['appointment'], array( 'title' => 'Event Title' ) )
            ->andReturn( array( 'pdf' => 'data' ) );

        $pdf_data = $this->renderer->generate_appointment_verification_pdf( $result, $pdf_generator );

        $this->assertSame( array( 'pdf' => 'data' ), $pdf_data );
    }

    // ==================================================================
    // generate_appointment_verification_pdf() — with calendar_id
    // ==================================================================

    // ==================================================================
    // build_schedule_exception_block() — #366 Sprint 8
    // ==================================================================

    /**
     * Invoke the private helper via Reflection so we can drive it
     * directly without hauling in the full template.
     */
    private function invoke_block_builder( object $submission ): ?array {
        $ref = new \ReflectionMethod( VerificationResponseRenderer::class, 'build_schedule_exception_block' );
        $ref->setAccessible( true );
        return $ref->invoke( $this->renderer, $submission );
    }

    public function test_block_returns_null_when_no_override_columns_set(): void {
        $submission = (object) array(
            'id'                      => 555,
            'schedule_start_override' => '',
            'schedule_end_override'   => '',
        );

        $this->assertNull(
            $this->invoke_block_builder( $submission ),
            'submissions without override columns must short-circuit before the audit query'
        );
    }

    public function test_block_returns_null_when_audit_row_missing(): void {
        // Submission has overrides but no matching audit row (e.g. an
        // old install with cleanup that ran past the retention window).
        // We still want a graceful nullish render.
        $submission = (object) array(
            'id'                      => 555,
            'schedule_start_override' => '08:00',
            'schedule_end_override'   => '17:30',
        );

        $query_class = Mockery::mock( 'alias:\FreeFormCertificate\Core\ActivityLogQuery' );
        $query_class->shouldReceive( 'get_submission_logs' )
            ->with( 555, Mockery::any() )
            ->andReturn( array() );

        $this->assertNull( $this->invoke_block_builder( $submission ) );
    }

    public function test_block_assembles_full_payload_from_audit_context(): void {
        $submission = (object) array(
            'id'                      => 555,
            'schedule_start_override' => '08:00',
            'schedule_end_override'   => '17:30',
        );

        $context_row = array(
            'action'  => 'schedule_override_created',
            'context' => array(
                'form_id'               => 42,
                'submission_id'         => 555,
                'schedule_start_before' => '08:00',
                'schedule_end_before'   => '18:00',
                'schedule_start_after'  => '08:00',
                'schedule_end_after'    => '17:30',
                'operator_cpf_masked'   => '123.***.***-45',
                'ts'                    => 1779413591,
            ),
        );

        $query_class = Mockery::mock( 'alias:\FreeFormCertificate\Core\ActivityLogQuery' );
        $query_class->shouldReceive( 'get_submission_logs' )->andReturn( array( $context_row ) );

        Functions\when( '_n' )->alias( static fn( $s, $p, $c ) => 1 === (int) $c ? $s : $p );

        $block = $this->invoke_block_builder( $submission );

        $this->assertNotNull( $block );
        $this->assertSame( '8h to 18h', $block['before_range'] );
        $this->assertSame( '8h to 17h30', $block['after_range'] );
        $this->assertSame( '123.***.***-45', $block['operator'] );
        // ts_label depends on wp_date/timezone stubs from setUp; just
        // assert it was rendered (non-empty).
        $this->assertNotSame( '', $block['ts_label'] );
    }

    public function test_block_decodes_context_when_stored_as_json_string(): void {
        // ActivityLogQuery may return the context field as a JSON
        // string (when encryption is disabled and the row is read raw).
        // The helper must decode it.
        $submission = (object) array(
            'id'                      => 555,
            'schedule_start_override' => '',
            'schedule_end_override'   => '17:30',
        );

        $context_row = array(
            'action'  => 'schedule_override_created',
            'context' => json_encode(
                array(
                    'schedule_start_before' => '08:00',
                    'schedule_end_before'   => '18:00',
                    'schedule_start_after'  => '',
                    'schedule_end_after'    => '17:30',
                    'operator_cpf_masked'   => '',
                    'ts'                    => 1779413591,
                )
            ),
        );

        $query_class = Mockery::mock( 'alias:\FreeFormCertificate\Core\ActivityLogQuery' );
        $query_class->shouldReceive( 'get_submission_logs' )->andReturn( array( $context_row ) );

        $block = $this->invoke_block_builder( $submission );

        $this->assertNotNull( $block );
        $this->assertSame( '8h to 18h', $block['before_range'] );
        // 6.7.2 — When only one end of the schedule was overridden (here
        // end shifted 18:00 → 17:30, start untouched), the recorded
        // range now falls back to the BEFORE start so the user sees a
        // complete two-end range. Pre-6.7.2 collapsed to "17h30" only.
        $this->assertSame( '8h to 17h30', $block['after_range'] );
    }

    public function test_generate_appointment_pdf_fetches_calendar_when_id_present(): void {
        $result = array(
            'data' => array( 'calendar_title' => 'Fallback' ),
            'appointment' => array(
                'calendar_id'     => 5,
                'validation_code' => 'V456',
            ),
        );

        $calendar_data = array( 'title' => 'Full Calendar', 'id' => 5 );

        $calendarRepoMock = Mockery::mock( 'overload:FreeFormCertificate\Repositories\CalendarRepository' );
        $calendarRepoMock->shouldReceive( 'findById' )
            ->with( 5 )
            ->andReturn( $calendar_data );

        $pdf_generator = Mockery::mock( 'FreeFormCertificate\Generators\PdfGenerator' );
        $pdf_generator->shouldReceive( 'generate_appointment_pdf_data' )
            ->once()
            ->with( $result['appointment'], $calendar_data )
            ->andReturn( array( 'pdf' => 'calendar_data' ) );

        $pdf_data = $this->renderer->generate_appointment_verification_pdf( $result, $pdf_generator );

        $this->assertSame( array( 'pdf' => 'calendar_data' ), $pdf_data );
    }
}
