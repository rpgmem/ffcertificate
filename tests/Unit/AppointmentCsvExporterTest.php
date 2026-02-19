<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\SelfScheduling\AppointmentCsvExporter;

/**
 * Tests for AppointmentCsvExporter: format_csv_row status labels,
 * consent display, user lookups, dynamic columns.
 *
 * Uses Reflection to access private methods and replace injected repos.
 */
class AppointmentCsvExporterTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var AppointmentCsvExporter */
    private $exporter;

    /** @var \Mockery\MockInterface */
    private $calendarRepo;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'add_action' )->justReturn( true );
        Functions\when( 'wp_cache_get' )->justReturn( false );
        Functions\when( 'wp_cache_set' )->justReturn( true );
        Functions\when( 'wp_cache_delete' )->justReturn( true );

        // global $wpdb for repo constructors
        global $wpdb;
        $wpdb = Mockery::mock( 'wpdb' );
        $wpdb->prefix = 'wp_';

        $this->exporter = new AppointmentCsvExporter();

        // Replace calendar_repository with a mock
        $this->calendarRepo = Mockery::mock( 'FreeFormCertificate\Repositories\CalendarRepository' );
        $this->calendarRepo->shouldReceive( 'findById' )->andReturn( array( 'title' => 'Test Calendar' ) )->byDefault();

        $ref = new \ReflectionProperty( AppointmentCsvExporter::class, 'calendar_repository' );
        $ref->setAccessible( true );
        $ref->setValue( $this->exporter, $this->calendarRepo );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Invoke the private format_csv_row method.
     */
    private function format_row( array $row, array $dynamic_keys = array() ): array {
        $ref = new \ReflectionMethod( AppointmentCsvExporter::class, 'format_csv_row' );
        $ref->setAccessible( true );
        return $ref->invokeArgs( $this->exporter, array( $row, $dynamic_keys ) );
    }

    /**
     * Build a minimal valid appointment row.
     */
    private function base_row(): array {
        return array(
            'id'                  => 42,
            'calendar_id'         => 1,
            'user_id'             => 10,
            'name'                => 'Maria Silva',
            'email'               => 'maria@example.com',
            'phone'               => '11999990000',
            'appointment_date'    => '2030-01-15',
            'start_time'          => '10:00',
            'end_time'            => '10:30',
            'status'              => 'confirmed',
            'user_notes'          => 'Note',
            'admin_notes'         => '',
            'consent_given'       => 1,
            'consent_date'        => '2030-01-10',
            // consent_ip derived from decrypted user_ip
            'consent_text'        => 'I agree',
            'created_at'          => '2030-01-10 09:00:00',
            'updated_at'          => '2030-01-10 09:00:00',
            'approved_at'         => '',
            'approved_by'         => '',
            'cancelled_at'        => '',
            'cancelled_by'        => '',
            'cancellation_reason' => '',
            'reminder_sent_at'    => '',
            'user_ip'             => '192.168.1.1',
            'user_agent'          => 'Mozilla/5.0',
            'custom_data'         => '',
        );
    }

    // ==================================================================
    // Status label translation
    // ==================================================================

    /**
     * @dataProvider status_labels_provider
     */
    public function test_status_label( string $status, string $expected ): void {
        Functions\when( 'get_userdata' )->justReturn( false );

        $row = $this->base_row();
        $row['status'] = $status;
        $line = $this->format_row( $row );
        // Status is at index 10
        $this->assertSame( $expected, $line[10] );
    }

    public static function status_labels_provider(): array {
        return array(
            'pending'   => array( 'pending', 'Pending' ),
            'confirmed' => array( 'confirmed', 'Confirmed' ),
            'cancelled' => array( 'cancelled', 'Cancelled' ),
            'completed' => array( 'completed', 'Completed' ),
            'no_show'   => array( 'no_show', 'No Show' ),
            'unknown'   => array( 'custom_status', 'custom_status' ),
        );
    }

    // ==================================================================
    // Consent display
    // ==================================================================

    public function test_consent_given_yes(): void {
        Functions\when( 'get_userdata' )->justReturn( false );

        $row = $this->base_row();
        $row['consent_given'] = 1;
        $line = $this->format_row( $row );
        // Consent given at index 13
        $this->assertSame( 'Yes', $line[13] );
    }

    public function test_consent_given_no(): void {
        Functions\when( 'get_userdata' )->justReturn( false );

        $row = $this->base_row();
        $row['consent_given'] = 0;
        $line = $this->format_row( $row );
        $this->assertSame( 'No', $line[13] );
    }

    public function test_consent_given_not_set(): void {
        Functions\when( 'get_userdata' )->justReturn( false );

        $row = $this->base_row();
        unset( $row['consent_given'] );
        $line = $this->format_row( $row );
        $this->assertSame( '', $line[13] );
    }

    // ==================================================================
    // User lookup for approved_by / cancelled_by
    // ==================================================================

    public function test_approved_by_user_display_name(): void {
        $user = new \WP_User( 5 );
        $user->display_name = 'Admin User';
        Functions\when( 'get_userdata' )->alias( function ( $id ) use ( $user ) {
            return $id === 5 ? $user : false;
        } );

        $row = $this->base_row();
        $row['approved_by'] = 5;
        $line = $this->format_row( $row );
        // Approved by at index 20
        $this->assertSame( 'Admin User', $line[20] );
    }

    public function test_approved_by_deleted_user_shows_id(): void {
        Functions\when( 'get_userdata' )->justReturn( false );

        $row = $this->base_row();
        $row['approved_by'] = 99;
        $line = $this->format_row( $row );
        $this->assertSame( 'ID: 99', $line[20] );
    }

    public function test_cancelled_by_user_display_name(): void {
        $user = new \WP_User( 7 );
        $user->display_name = 'Moderator';
        Functions\when( 'get_userdata' )->alias( function ( $id ) use ( $user ) {
            return $id === 7 ? $user : false;
        } );

        $row = $this->base_row();
        $row['cancelled_by'] = 7;
        $line = $this->format_row( $row );
        // Cancelled by at index 22
        $this->assertSame( 'Moderator', $line[22] );
    }

    // ==================================================================
    // Calendar title lookup
    // ==================================================================

    public function test_calendar_title_from_repo(): void {
        Functions\when( 'get_userdata' )->justReturn( false );

        $line = $this->format_row( $this->base_row() );
        // Calendar title at index 1
        $this->assertSame( 'Test Calendar', $line[1] );
    }

    public function test_calendar_deleted_shows_fallback(): void {
        Functions\when( 'get_userdata' )->justReturn( false );
        $this->calendarRepo->shouldReceive( 'findById' )->andReturn( array() );

        $line = $this->format_row( $this->base_row() );
        $this->assertSame( '(Deleted)', $line[1] );
    }

    // ==================================================================
    // Dynamic columns
    // ==================================================================

    public function test_dynamic_columns_appended(): void {
        Functions\when( 'get_userdata' )->justReturn( false );

        $row = $this->base_row();
        $row['custom_data'] = '{"cpf":"12345678901","sector":"TI"}';
        $line = $this->format_row( $row, array( 'cpf', 'sector' ) );
        // Fixed columns = 27 (indices 0-26), dynamic start at 27
        $this->assertSame( '12345678901', $line[27] );
        $this->assertSame( 'TI', $line[28] );
    }

    public function test_dynamic_columns_missing_key_returns_empty(): void {
        Functions\when( 'get_userdata' )->justReturn( false );

        $row = $this->base_row();
        $row['custom_data'] = '{"cpf":"123"}';
        $line = $this->format_row( $row, array( 'cpf', 'missing_field' ) );
        $this->assertSame( '123', $line[27] );
        $this->assertSame( '', $line[28] );
    }

    // ==================================================================
    // get_fixed_headers()
    // ==================================================================

    public function test_fixed_headers_count(): void {
        $ref = new \ReflectionMethod( AppointmentCsvExporter::class, 'get_fixed_headers' );
        $ref->setAccessible( true );
        $headers = $ref->invoke( $this->exporter );
        $this->assertCount( 27, $headers );
    }

    public function test_fixed_headers_first_is_id(): void {
        $ref = new \ReflectionMethod( AppointmentCsvExporter::class, 'get_fixed_headers' );
        $ref->setAccessible( true );
        $headers = $ref->invoke( $this->exporter );
        $this->assertSame( 'ID', $headers[0] );
    }
}
