<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\SelfScheduling\AppointmentExportSource;

/**
 * Tests for AppointmentExportSource: the fixed + dynamic column layout, per-row
 * formatting (PII decryption, status label, calendar-title cache), the
 * dynamic-key scan, the filter/count/keyset-page delegation, filename building,
 * and the per-phase authorization gates. The job lifecycle it plugs into is
 * tested in BatchedCsvExportTest. Migrated from the former synchronous
 * AppointmentCsvExporter (issue #772).
 *
 * The injected repositories are regular Mockery mocks (both are instance repos),
 * so this needs no process isolation / alias mocks.
 *
 * @covers \FreeFormCertificate\SelfScheduling\AppointmentExportSource
 */
class AppointmentExportSourceTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var AppointmentExportSource */
	private $source;

	/** @var \Mockery\MockInterface */
	private $appointments;

	/** @var \Mockery\MockInterface */
	private $calendars;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		class_exists( '\\FreeFormCertificate\\SelfScheduling\\AppointmentExportSource' );

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->alias( static fn( $v ) => strtolower( (string) $v ) );
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		Functions\when( 'get_userdata' )->justReturn( false );

		$ref          = new \ReflectionClass( AppointmentExportSource::class );
		$this->source = $ref->newInstanceWithoutConstructor();

		$this->appointments = Mockery::mock( 'FreeFormCertificate\Repositories\AppointmentRepository' );
		$this->calendars    = Mockery::mock( 'FreeFormCertificate\Repositories\CalendarRepository' );
		$this->set_prop( 'appointment_repository', $this->appointments );
		$this->set_prop( 'calendar_repository', $this->calendars );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		unset( $_POST['calendar_id'], $_POST['status'], $_POST['start_date'], $_POST['end_date'] );
		parent::tearDown();
	}

	private function set_prop( string $name, $value ): void {
		$prop = new \ReflectionProperty( AppointmentExportSource::class, $name );
		$prop->setAccessible( true );
		$prop->setValue( $this->source, $value );
	}

	private function invoke( string $method, array $args = array() ) {
		$ref = new \ReflectionMethod( AppointmentExportSource::class, $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( $this->source, $args );
	}

	private function sample_row(): array {
		return array(
			'id'                    => 7,
			'calendar_id'           => 3,
			'user_id'               => 10,
			'name'                  => 'Jane Doe',
			'email'                 => 'jane@example.com',
			'email_encrypted'       => '',
			'phone'                 => '555-1234',
			'phone_encrypted'       => '',
			'user_ip'               => '203.0.113.5',
			'user_ip_encrypted'     => '',
			'appointment_date'      => '2026-05-20',
			'start_time'            => '09:00:00',
			'end_time'              => '10:00:00',
			'status'                => 'confirmed',
			'user_notes'            => 'note',
			'admin_notes'           => 'adm',
			'consent_given'         => 1,
			'consent_date'          => '',
			'consent_text'          => 'I agree',
			'created_at'            => '2026-05-01 08:00:00',
			'updated_at'            => '2026-05-02 08:00:00',
			'approved_at'           => '',
			'approved_by'           => '',
			'cancelled_at'          => '',
			'cancelled_by'          => '',
			'cancellation_reason'   => '',
			'reminder_sent_at'      => '',
			'user_agent'            => 'Mozilla',
			'custom_data'           => '{"ramal":"42","setor":"TI"}',
			'custom_data_encrypted' => '',
		);
	}

	// ==================================================================
	// type() / header()
	// ==================================================================

	public function test_type_is_appointments(): void {
		$this->assertSame( 'appointments', $this->source->type() );
	}

	public function test_get_fixed_headers_has_27_columns(): void {
		$headers = $this->invoke( 'get_fixed_headers' );
		$this->assertCount( 27, $headers );
		$this->assertSame( 'ID', $headers[0] );
		$this->assertSame( 'Calendar', $headers[1] );
		$this->assertSame( 'User Agent', $headers[26] );
	}

	public function test_header_merges_fixed_and_dynamic(): void {
		$header = $this->source->header( array(), array( 'dynamic_keys' => array( 'ramal', 'setor' ) ) );
		$this->assertCount( 29, $header ); // 27 fixed + 2 dynamic.
		$this->assertSame( 'ID', $header[0] );
	}

	// ==================================================================
	// format_row() / format_csv_row()
	// ==================================================================

	public function test_format_row_layout_and_pii(): void {
		$this->calendars->shouldReceive( 'findById' )->with( 3 )->andReturn( array( 'title' => 'Room A' ) );

		$result = $this->source->format_row( $this->sample_row(), array( 'dynamic_keys' => array( 'ramal', 'setor' ) ) );

		$this->assertCount( 29, $result ); // 27 fixed + 2 dynamic.
		$this->assertSame( 7, $result[0] );
		$this->assertSame( 'Room A', $result[1] );
		$this->assertSame( 3, $result[2] );
		$this->assertSame( 10, $result[3] );
		$this->assertSame( 'Jane Doe', $result[4] );
		$this->assertSame( 'jane@example.com', $result[5] );
		$this->assertSame( '555-1234', $result[6] );
		$this->assertSame( 'Confirmed', $result[10] );
		$this->assertSame( '203.0.113.5', $result[25] ); // User IP column.
		// Dynamic columns appended in key order.
		$this->assertSame( '42', $result[27] );
		$this->assertSame( 'TI', $result[28] );
	}

	public function test_format_row_unknown_status_passthrough(): void {
		$this->calendars->shouldReceive( 'findById' )->andReturn( array( 'title' => 'X' ) );
		$row           = $this->sample_row();
		$row['status'] = 'weird';
		$result        = $this->source->format_row( $row, array( 'dynamic_keys' => array() ) );
		$this->assertSame( 'weird', $result[10] );
	}

	public function test_format_row_deleted_calendar_shows_placeholder(): void {
		$this->calendars->shouldReceive( 'findById' )->with( 3 )->andReturn( null );
		$result = $this->source->format_row( $this->sample_row(), array( 'dynamic_keys' => array() ) );
		$this->assertSame( '(Deleted)', $result[1] );
	}

	public function test_format_row_formats_instant_when_present(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_date' )->alias( static fn( $format, $ts = null ) => gmdate( (string) $format, $ts ) );
		Functions\when( 'wp_timezone' )->alias( static fn() => new \DateTimeZone( 'UTC' ) );
		$this->calendars->shouldReceive( 'findById' )->andReturn( array( 'title' => 'X' ) );

		$row                 = $this->sample_row();
		$row['consent_date'] = 1747731600; // A valid unix ts.
		$result              = $this->source->format_row( $row, array( 'dynamic_keys' => array() ) );

		$this->assertNotSame( '', $result[14] ); // Consent Date rendered.
	}

	public function test_calendar_title_cached(): void {
		$this->calendars->shouldReceive( 'findById' )->with( 3 )->once()->andReturn( array( 'title' => 'Room A' ) );
		$this->assertSame( 'Room A', $this->invoke( 'get_calendar_title_cached', array( 3 ) ) );
		$this->assertSame( 'Room A', $this->invoke( 'get_calendar_title_cached', array( 3 ) ) );
	}

	// ==================================================================
	// sanitize_filters() / count() / build_context() / fetch_page()
	// ==================================================================

	public function test_sanitize_filters_maps_request(): void {
		$_POST['calendar_id'] = '3';
		$_POST['status']      = 'Confirmed';

		$filters = $this->source->sanitize_filters();
		$this->assertSame( array( 3 ), $filters['calendar_ids'] );
		$this->assertSame( array( 'confirmed' ), $filters['statuses'] );
		$this->assertNull( $filters['start_date'] );
		$this->assertNull( $filters['end_date'] );
	}

	public function test_sanitize_filters_all_when_empty(): void {
		$filters = $this->source->sanitize_filters();
		$this->assertNull( $filters['calendar_ids'] );
		$this->assertSame( array(), $filters['statuses'] );
	}

	public function test_count_delegates(): void {
		$this->appointments->shouldReceive( 'countForExport' )->once()
			->with( array( 3 ), array( 'confirmed' ), null, null )->andReturn( 15 );

		$this->assertSame(
			15,
			$this->source->count(
				array(
					'calendar_ids' => array( 3 ),
					'statuses'     => array( 'confirmed' ),
					'start_date'   => null,
					'end_date'     => null,
				)
			)
		);
	}

	public function test_fetch_page_delegates_keyset(): void {
		$rows = array( array( 'id' => 5 ), array( 'id' => 4 ) );
		$this->appointments->shouldReceive( 'getExportBatch' )->once()
			->with( null, array(), null, null, 10, 50 )->andReturn( $rows );

		$page = $this->source->fetch_page(
			array(
				'calendar_ids' => null,
				'statuses'     => array(),
				'start_date'   => null,
				'end_date'     => null,
			),
			array(),
			10,
			50
		);
		$this->assertSame( $rows, $page );
	}

	public function test_build_context_scans_dynamic_keys_via_keyset(): void {
		// First call returns a batch, second returns empty → loop terminates.
		$this->appointments->shouldReceive( 'getExportKeysBatch' )->twice()->andReturn(
			array( array( 'id' => 9, 'custom_data' => '{"ramal":"1"}', 'custom_data_encrypted' => '' ) ),
			array()
		);

		$context = $this->source->build_context(
			array(
				'calendar_ids' => null,
				'statuses'     => array(),
				'start_date'   => null,
				'end_date'     => null,
			)
		);
		$this->assertSame( array( 'ramal' ), $context['dynamic_keys'] );
	}

	public function test_cursor_of_reads_id(): void {
		$this->assertSame( 4, $this->source->cursor_of( array( 'id' => 4 ) ) );
		$this->assertSame( 0, $this->source->cursor_of( array() ) );
	}

	// ==================================================================
	// filename()
	// ==================================================================

	public function test_filename_single_calendar_uses_title(): void {
		Functions\when( 'sanitize_file_name' )->alias( static fn( $v ) => str_replace( ' ', '-', strtolower( (string) $v ) ) );
		$this->calendars->shouldReceive( 'findById' )->with( 3 )->andReturn( array( 'title' => 'Room A' ) );

		$name = $this->source->filename( array( 'calendar_ids' => array( 3 ) ), array() );
		$this->assertMatchesRegularExpression( '/^room-a-appointments-\d{4}-\d{2}-\d{2}\.csv$/', $name );
	}

	public function test_filename_multiple_calendars(): void {
		Functions\when( 'sanitize_file_name' )->returnArg();
		$name = $this->source->filename( array( 'calendar_ids' => array( 3, 4 ) ), array() );
		$this->assertMatchesRegularExpression( '/^2-calendars-appointments-\d{4}-\d{2}-\d{2}\.csv$/', $name );
	}

	public function test_filename_all_calendars(): void {
		Functions\when( 'sanitize_file_name' )->returnArg();
		$name = $this->source->filename( array( 'calendar_ids' => null ), array() );
		$this->assertMatchesRegularExpression( '/^all-calendars-appointments-\d{4}-\d{2}-\d{2}\.csv$/', $name );
	}

	// ==================================================================
	// authorize_*()
	// ==================================================================

	private function stub_terminators(): void {
		Functions\when( 'wp_send_json_error' )->alias(
			static function () {
				throw new \RuntimeException( 'json_error' );
			}
		);
		Functions\when( 'wp_die' )->alias(
			static function () {
				throw new \RuntimeException( 'wp_die' );
			}
		);
	}

	public function test_authorize_start_rejects_without_capability(): void {
		$this->stub_terminators();
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'json_error' );
		$this->source->authorize_start();
	}

	public function test_authorize_batch_rejects_on_user_mismatch(): void {
		$this->stub_terminators();
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'json_error' );
		$this->source->authorize_batch( array( 'user_id' => 99 ) );
	}

	public function test_authorize_download_rejects_on_bad_nonce(): void {
		$this->stub_terminators();
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'wp_die' );
		$this->source->authorize_download( array( 'user_id' => 1 ) );
	}

	public function test_job_owner_fields_returns_user_id(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 42 );
		$this->assertSame( array( 'user_id' => 42 ), $this->source->job_owner_fields() );
	}
}
