<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use FreeFormCertificate\Tests\Support\BatchedExportSourceContractTestCase;
use FreeFormCertificate\Audience\AudienceBookingExportSource;

/**
 * Tests for AudienceBookingExportSource: the 18-column layout + per-row
 * formatting, the user-name cache, the filter/count/keyset-page delegation to
 * AudienceBookingReader, and the per-phase authorization gates. The job
 * lifecycle it plugs into is tested in BatchedCsvExportTest. Migrated from the
 * former synchronous AudienceBookingCsvExporter (issue #772).
 *
 * AudienceBookingReader is the real class: the `$wpdb` double below answers
 * each of its queries by SQL text, with the values bound to it, so every test
 * runs in the shared process (#2091).
 *
 * @covers \FreeFormCertificate\Audience\AudienceBookingExportSource
 * @preserveGlobalState disabled
 */
class AudienceBookingExportSourceTest extends BatchedExportSourceContractTestCase {


	/** @var AudienceBookingExportSource */
	private $source;

	/** @var list<array<int, mixed>> Every prepare() call, as its flattened argument list. */
	private array $prepared = array();

	/** @var list<array{0: string, 1: string}> Every read, as array( method, SQL ). */
	private array $ran = array();

	/** @var list<object> Audience rows the booking-audiences query answers. */
	private array $booking_audiences = array();

	/** @var list<string> User ids the booking-users query answers. */
	private array $booking_users = array();

	/** @var int What the export count query answers. */
	private int $export_count = 0;

	/** @var list<object> Booking rows the keyset page query answers. */
	private array $page_rows = array();

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		class_exists( '\\FreeFormCertificate\\Audience\\AudienceBookingExportSource' );

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']   = $this->wpdb_double();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		// RequestInput::get_post_* read the superglobals through these helpers.
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );

		$this->source = new AudienceBookingExportSource();
	}


	/**
	 * The source this file builds, for the contract the base asserts.
	 *
	 * @return object
	 */
	protected function export_source() {
		return $this->source;
	}

	protected function tearDown(): void {
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
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

	/**
	 * A `$wpdb` double answering the four AudienceBookingReader queries this
	 * source reaches, by SQL text. Any other query fails the test.
	 *
	 * @return Mockery\MockInterface
	 */
	private function wpdb_double() {
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';

		$this->prepared = array();
		$this->ran      = array();
		$prepared       = &$this->prepared;
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( ...$args ) use ( &$prepared ) {
				$flat       = 2 === count( $args ) && is_array( $args[1] ) ? array_merge( array( $args[0] ), $args[1] ) : $args;
				$prepared[] = $flat;
				return 'Q' . ( count( $prepared ) - 1 ) . ':' . $flat[0];
			}
		);
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $sql ) {
				$this->ran[] = array( 'get_results', (string) $sql );
				if ( false !== strpos( (string) $sql, 'WHERE ba.booking_id = %d' ) ) {
					return $this->booking_audiences;
				}
				if ( false !== strpos( (string) $sql, 'ORDER BY b.id DESC' ) ) {
					return $this->page_rows;
				}
				$this->fail( 'Unexpected get_results(): ' . $sql );
			}
		);
		$wpdb->shouldReceive( 'get_col' )->andReturnUsing(
			function ( $sql ) {
				$this->ran[] = array( 'get_col', (string) $sql );
				if ( false !== strpos( (string) $sql, 'SELECT user_id FROM %i WHERE booking_id = %d' ) ) {
					return $this->booking_users;
				}
				$this->fail( 'Unexpected get_col(): ' . $sql );
			}
		);
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( $sql ) {
				$this->ran[] = array( 'get_var', (string) $sql );
				if ( false !== strpos( (string) $sql, 'SELECT COUNT(*)' ) ) {
					return (string) $this->export_count;
				}
				$this->fail( 'Unexpected get_var(): ' . $sql );
			}
		);

		return $wpdb;
	}

	/**
	 * The prepare() argument list behind a prepared query string.
	 *
	 * @return array<int, mixed>
	 */
	private function bound_to( string $sql ): array {
		if ( preg_match( '/^Q(\d+):/', $sql, $match ) ) {
			return $this->prepared[ (int) $match[1] ] ?? array();
		}
		return array();
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

	public function test_header_has_eighteen_columns(): void {
		$header = $this->source->header( array(), array() );
		$this->assertCount( 18, $header );
		$this->assertSame( 'ID', $header[0] );
		$this->assertSame( 'Environment', $header[1] );
		$this->assertSame( 'Cancellation Reason', $header[17] );
	}

	// ==================================================================
	// format_row()
	// ==================================================================

	public function test_format_row_maps_columns_in_order(): void {
		$this->booking_audiences = array( (object) array( 'name' => 'Nurses' ), (object) array( 'name' => 'Doctors' ) );
		$this->booking_users     = array( '1', '2', '3' );

		Functions\when( 'get_userdata' )->justReturn( (object) array( 'display_name' => 'Alice' ) );

		$result = $this->source->format_row( $this->sample_row(), array() );

		$this->assertSame( '7', $result[0] );
		$this->assertSame( 'Room A', $result[1] );
		$this->assertSame( '3', $result[2] );
		$this->assertSame( '2026-05-20', $result[3] );
		$this->assertSame( '', $result[4] );
		$this->assertSame( '09:00:00', $result[5] );
		$this->assertSame( '10:00:00', $result[6] );
		$this->assertSame( 'No', $result[7] );
		$this->assertSame( 'Audience', $result[8] );
		$this->assertSame( 'Team sync', $result[9] );
		$this->assertSame( 'Active', $result[10] );
		$this->assertSame( 'Nurses, Doctors', $result[11] );
		$this->assertSame( '3', $result[12] );
		$this->assertSame( 'Alice', $result[13] );
		$this->assertSame( '2026-05-01 08:00:00', $result[14] );
		$this->assertSame( '', $result[15] );
		$this->assertSame( '', $result[16] );
		$this->assertSame( '', $result[17] );

		// Both lookups are for the row's own booking id.
		foreach ( $this->ran as list( , $sql ) ) {
			$bound = $this->bound_to( $sql );
			$this->assertSame( 7, end( $bound ), 'Each booking lookup binds the row id last.' );
		}
		$this->assertCount( 2, $this->ran, 'One audience query and one participant query per row.' );
	}

	public function test_format_row_all_day_and_unknown_labels(): void {
		$this->booking_audiences = array();
		$this->booking_users     = array();

		$row               = $this->sample_row();
		$row['is_all_day'] = 1;
		$row['booking_type'] = 'weird';
		$row['status']       = 'archived';
		$row['created_by']   = 0;

		$result = $this->source->format_row( $row, array() );

		$this->assertSame( 'Yes', $result[7] );
		$this->assertSame( 'weird', $result[8] );
		$this->assertSame( 'archived', $result[10] );
		$this->assertSame( '', $result[11] );
		$this->assertSame( '0', $result[12] );
		$this->assertSame( '', $result[13] );
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
		$this->export_count = 12;

		$this->assertSame( 12, $this->source->count( array( 'status' => 'active' ) ) );

		// One count query, carrying the status filter the source was given.
		$this->assertCount( 1, $this->ran );
		$this->assertSame( 'get_var', $this->ran[0][0] );
		$this->assertStringContainsString( 'b.status = %s', $this->ran[0][1] );
		$this->assertSame(
			array( 'wp_ffc_audience_bookings', 'wp_ffc_audience_environments', 'active' ),
			array_slice( $this->bound_to( $this->ran[0][1] ), 1 )
		);
	}

	public function test_fetch_page_casts_reader_objects_to_arrays(): void {
		$this->page_rows = array( (object) array( 'id' => 5 ), (object) array( 'id' => 4 ) );

		$page = $this->source->fetch_page( array( 'status' => 'active' ), array(), 10, 50 );
		$this->assertSame( array( array( 'id' => 5 ), array( 'id' => 4 ) ), $page );

		// One keyset page, bound to the filters, the cursor and the size given.
		$this->assertCount( 1, $this->ran );
		$this->assertSame( 'get_results', $this->ran[0][0] );
		$this->assertStringContainsString( 'b.status = %s', $this->ran[0][1] );
		$this->assertSame(
			array( 'wp_ffc_audience_bookings', 'wp_ffc_audience_environments', 10, 'active', 50 ),
			array_slice( $this->bound_to( $this->ran[0][1] ), 1 )
		);
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

}
