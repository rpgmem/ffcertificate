<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Audience\AudienceBookingExportSource;

/**
 * Tests for AudienceBookingExportSource: the 17-column layout + per-row
 * formatting, the user-name cache, the filter/count/keyset-page delegation to
 * AudienceBookingReader, and the per-phase authorization gates. The job
 * lifecycle it plugs into is tested in BatchedCsvExportTest. Migrated from the
 * former synchronous AudienceBookingCsvExporter (issue #772).
 *
 * Process isolation is used so the delegation tests can alias-mock the static
 * AudienceBookingReader without the alias leaking across the suite.
 *
 * @covers \FreeFormCertificate\Audience\AudienceBookingExportSource
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AudienceBookingExportSourceTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var AudienceBookingExportSource */
	private $source;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		class_exists( '\\FreeFormCertificate\\Audience\\AudienceBookingExportSource' );

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		// RequestInput::get_post_* read the superglobals through these helpers.
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );

		$this->source = new AudienceBookingExportSource();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		unset(
			$_POST['schedule_id'],
			$_POST['environment_id'],
			$_POST['status'],
			$_POST['date_from'],
			$_POST['date_to']
		);
		parent::tearDown();
	}

	/** Invoke a private/protected method on the source. */
	private function invoke( string $method, array $args = array() ) {
		$ref = new \ReflectionMethod( AudienceBookingExportSource::class, $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( $this->source, $args );
	}

	private function sample_row(): array {
		return array(
			'id'                  => 7,
			'environment_name'    => 'Room A',
			'schedule_id'         => 3,
			'booking_date'        => '2026-05-20',
			'start_time'          => '09:00:00',
			'end_time'            => '10:00:00',
			'is_all_day'          => 0,
			'booking_type'        => 'audience',
			'description'         => 'Team sync',
			'status'              => 'active',
			'created_by'          => 5,
			'created_at'          => '2026-05-01 08:00:00',
			'cancelled_by'        => 0,
			'cancelled_at'        => null,
			'cancellation_reason' => null,
		);
	}

	// ==================================================================
	// type() / header()
	// ==================================================================

	public function test_type_is_audience_bookings(): void {
		$this->assertSame( 'audience_bookings', $this->source->type() );
	}

	public function test_header_has_seventeen_columns(): void {
		$header = $this->source->header( array(), array() );
		$this->assertCount( 17, $header );
		$this->assertSame( 'ID', $header[0] );
		$this->assertSame( 'Environment', $header[1] );
		$this->assertSame( 'Cancellation Reason', $header[16] );
	}

	// ==================================================================
	// format_row()
	// ==================================================================

	public function test_format_row_maps_columns_in_order(): void {
		Mockery::mock( 'alias:\FreeFormCertificate\Audience\AudienceBookingReader' )
			->shouldReceive( 'get_booking_audiences' )->andReturn(
				array( (object) array( 'name' => 'Nurses' ), (object) array( 'name' => 'Doctors' ) )
			)
			->shouldReceive( 'get_booking_users' )->andReturn( array( 1, 2, 3 ) );

		Functions\when( 'get_userdata' )->justReturn( (object) array( 'display_name' => 'Alice' ) );

		$result = $this->source->format_row( $this->sample_row(), array() );

		$this->assertSame( '7', $result[0] );
		$this->assertSame( 'Room A', $result[1] );
		$this->assertSame( '3', $result[2] );
		$this->assertSame( '2026-05-20', $result[3] );
		$this->assertSame( '09:00:00', $result[4] );
		$this->assertSame( '10:00:00', $result[5] );
		$this->assertSame( 'No', $result[6] );
		$this->assertSame( 'Audience', $result[7] );
		$this->assertSame( 'Team sync', $result[8] );
		$this->assertSame( 'Active', $result[9] );
		$this->assertSame( 'Nurses, Doctors', $result[10] );
		$this->assertSame( '3', $result[11] );
		$this->assertSame( 'Alice', $result[12] );
		$this->assertSame( '2026-05-01 08:00:00', $result[13] );
		$this->assertSame( '', $result[14] );
		$this->assertSame( '', $result[15] );
		$this->assertSame( '', $result[16] );
	}

	public function test_format_row_all_day_and_unknown_labels(): void {
		Mockery::mock( 'alias:\FreeFormCertificate\Audience\AudienceBookingReader' )
			->shouldReceive( 'get_booking_audiences' )->andReturn( array() )
			->shouldReceive( 'get_booking_users' )->andReturn( array() );

		$row               = $this->sample_row();
		$row['is_all_day'] = 1;
		$row['booking_type'] = 'weird';
		$row['status']       = 'archived';
		$row['created_by']   = 0;

		$result = $this->source->format_row( $row, array() );

		$this->assertSame( 'Yes', $result[6] );
		$this->assertSame( 'weird', $result[7] );
		$this->assertSame( 'archived', $result[9] );
		$this->assertSame( '', $result[10] );
		$this->assertSame( '0', $result[11] );
		$this->assertSame( '', $result[12] );
	}

	public function test_user_name_deleted_shows_id_and_caches(): void {
		$calls = 0;
		Functions\when( 'get_userdata' )->alias(
			static function () use ( &$calls ) {
				++$calls;
				return false;
			}
		);
		$this->assertSame( 'ID: 99', $this->invoke( 'user_name', array( 99 ) ) );
		$this->assertSame( 'ID: 99', $this->invoke( 'user_name', array( 99 ) ) );
		$this->assertSame( 1, $calls, 'get_userdata called once for the same id' );
	}

	public function test_user_name_empty_for_zero(): void {
		$this->assertSame( '', $this->invoke( 'user_name', array( 0 ) ) );
	}

	// ==================================================================
	// sanitize_filters() / count() / fetch_page() / cursor_of()
	// ==================================================================

	public function test_sanitize_filters_maps_request_to_reader_keys(): void {
		$_POST['schedule_id']    = '4';
		$_POST['environment_id'] = '3';
		$_POST['status']         = 'active';
		$_POST['date_from']      = '2026-05-01';
		$_POST['date_to']        = '2026-05-31';

		$filters = $this->source->sanitize_filters();
		$this->assertSame( 4, $filters['schedule_id'] );
		$this->assertSame( 3, $filters['environment_id'] );
		$this->assertSame( 'active', $filters['status'] );
		$this->assertSame( '2026-05-01', $filters['start_date'] );
		$this->assertSame( '2026-05-31', $filters['end_date'] );
	}

	public function test_sanitize_filters_omits_empty(): void {
		// Nothing in $_POST → every optional filter dropped.
		$this->assertSame( array(), $this->source->sanitize_filters() );
	}

	public function test_count_delegates_to_reader(): void {
		Mockery::mock( 'alias:\FreeFormCertificate\Audience\AudienceBookingReader' )
			->shouldReceive( 'count_for_export' )->once()
			->with( array( 'status' => 'active' ) )->andReturn( 12 );

		$this->assertSame( 12, $this->source->count( array( 'status' => 'active' ) ) );
	}

	public function test_fetch_page_casts_reader_objects_to_arrays(): void {
		Mockery::mock( 'alias:\FreeFormCertificate\Audience\AudienceBookingReader' )
			->shouldReceive( 'find_by_cursor' )->once()
			->with( array( 'status' => 'active' ), 10, 50 )
			->andReturn( array( (object) array( 'id' => 5 ), (object) array( 'id' => 4 ) ) );

		$page = $this->source->fetch_page( array( 'status' => 'active' ), array(), 10, 50 );
		$this->assertSame( array( array( 'id' => 5 ), array( 'id' => 4 ) ), $page );
	}

	public function test_cursor_of_reads_id(): void {
		$this->assertSame( 4, $this->source->cursor_of( array( 'id' => 4 ) ) );
		$this->assertSame( 0, $this->source->cursor_of( array() ) );
	}

	public function test_build_context_is_empty(): void {
		$this->assertSame( array(), $this->source->build_context( array( 'status' => 'x' ) ) );
	}

	public function test_filename_is_dated(): void {
		// gmdate() is an internal function Patchwork can't redefine, so assert
		// the shape (audience-bookings-YYYY-MM-DD.csv) rather than a fixed date.
		$this->assertMatchesRegularExpression(
			'/^audience-bookings-\d{4}-\d{2}-\d{2}\.csv$/',
			$this->source->filename( array(), array() )
		);
	}

	// ==================================================================
	// authorize_start() / authorize_batch() / authorize_download()
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

	public function test_authorize_download_rejects_on_user_mismatch(): void {
		$this->stub_terminators();
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'wp_die' );
		$this->source->authorize_download( array( 'user_id' => 99 ) );
	}

	public function test_job_owner_fields_returns_user_id(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 42 );
		$this->assertSame( array( 'user_id' => 42 ), $this->source->job_owner_fields() );
	}
}
