<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Audience\AudienceAccountEmail;
use FreeFormCertificate\Audience\AudienceAjaxController;
use FreeFormCertificate\Tests\Support\CallLog;

/**
 * Tests for AudienceAjaxController — the admin-ajax endpoints extracted from
 * AudienceLoader (frontend-audit Item 3). Covers hook registration, every
 * handler's outcomes through the AjaxTrait + wp_send_json path, and the
 * custom-fields helpers (moved verbatim from the loader, where they were the
 * only previously-tested part of this surface).
 *
 * Every collaborator is the real class — Capabilities, RequestInput, the
 * booking reader and writer, the schedule, environment and audience
 * repositories, the custom-field reader and writer, the standard-fields
 * seeder, PiiAccessPolicy and the permission row — over a `$wpdb` double
 * that answers each read by its SQL text from the rows a test stores and
 * records every write. A query the double does not know is recorded and
 * fails the test after it ran: failing inside the handler would only be
 * swallowed by its catch-all.
 *
 * @covers \FreeFormCertificate\Audience\AudienceAjaxController
 */
class AudienceAjaxControllerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<int, array{ok: bool, data: mixed}> */
	private array $responses = array();

	/** @var list<string> Capabilities the current user holds. */
	private array $caps = array();

	/** @var list<string> Every capability current_user_can() was asked for. */
	private array $cap_checks = array();

	/** @var array<int, array<string, mixed>> Booking rows (joined with the environment) by id. */
	private array $bookings = array();

	/** @var array<int, list<array<string, mixed>>> Audience rows linked to a booking, by booking id. */
	private array $booking_audiences = array();

	/** @var array<int, list<string>> User ids linked to a booking, by booking id. */
	private array $booking_users = array();

	/** @var array<int, array<string, mixed>> Calendar rows by id. */
	private array $schedules = array();

	/** @var array<string, array<string, mixed>> Permission rows by "schedule:user". */
	private array $permissions = array();

	/** @var list<array<string, mixed>> Environment rows. */
	private array $environments = array();

	/** @var array<int, array<string, mixed>> Audience rows by id. */
	private array $audiences = array();

	/** @var array<int, array<string, mixed>> Custom-field rows by id. */
	private array $fields = array();

	/** @var list<string> Field keys a reregistration submission answered (#2242). */
	private array $answered_keys = array();

	/** @var array<string, bool> Tables whose writes the double answers with false. */
	private array $failing_tables = array();

	/** @var list<array{op: string, table: string, data: array<string, mixed>, where: array<string, mixed>}> */
	private array $writes = array();

	/** @var list<string> Queries the double was not taught to answer. */
	private array $unexpected = array();

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	/** @var array<string, mixed> The `$_POST` an earlier test left, restored afterwards. */
	private array $post_backup = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->post_backup = $_POST ?? array();

		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'sanitize_title' )->alias( static fn( $s ) => strtolower( (string) preg_replace( '/\s+/', '-', trim( (string) $s ) ) ) );
		Functions\when( 'sanitize_sql_orderby' )->returnArg();
		Functions\when( 'wp_parse_args' )->alias( static fn( $args, $defaults = array() ) => array_merge( $defaults, (array) $args ) );
		Functions\when( 'wp_json_encode' )->alias( static fn( $v ) => json_encode( $v ) );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'current_time' )->justReturn( '2026-05-20 10:00:00' );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		// Debug::is_enabled() guards on function_exists( 'get_option' ): chosen
		// here, with every debug area off, rather than inherited.
		Functions\when( 'get_option' )->alias( static fn( $key, $default = false ) => $default );
		// No profile holds a custom-field answer unless a test says so (#2242).
		Functions\when( 'get_users' )->justReturn( array() );

		// The audience manager holds the granular cap, never manage_options,
		// so a handler that regressed to a different cap is refused (#739).
		$this->caps       = array( 'ffc_manage_audiences' );
		$this->cap_checks = array();
		Functions\when( 'current_user_can' )->alias(
			function ( $cap ) {
				$this->cap_checks[] = $cap;
				return in_array( $cap, $this->caps, true );
			}
		);

		$this->responses = array();
		Functions\when( 'wp_send_json_success' )->alias( function ( $data = null ) {
			$this->responses[] = array( 'ok' => true, 'data' => $data );
			throw new \RuntimeException( 'wp_send_json_success' );
		} );
		Functions\when( 'wp_send_json_error' )->alias( function ( $data = null ) {
			$this->responses[] = array( 'ok' => false, 'data' => $data );
			throw new \RuntimeException( 'wp_send_json_error' );
		} );

		$this->bookings          = array();
		$this->booking_audiences = array();
		$this->booking_users     = array();
		$this->schedules         = array();
		$this->permissions       = array();
		$this->environments      = array();
		$this->audiences         = array();
		$this->fields            = array();
		$this->answered_keys     = array();
		$this->failing_tables    = array();
		$this->writes            = array();
		$this->unexpected        = array();

		// The permission row memoises its reveal nonce for the request.
		$nonce = new \ReflectionProperty( AudienceAccountEmail::class, 'nonce' );
		$nonce->setAccessible( true );
		$nonce->setValue( null, null );

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']   = $this->wpdb();
	}

	protected function tearDown(): void {
		$_POST = $this->post_backup;
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	protected function assertPostConditions(): void {
		$this->assertSame( array(), $this->unexpected, 'The $wpdb double received a query it was not taught.' );
	}

	/**
	 * The `$wpdb` double: reads answer from the stored rows by SQL text and
	 * bound table, writes are recorded and answer 1 (or false for a table in
	 * `$failing_tables`).
	 *
	 * @return \Mockery\MockInterface
	 */
	private function wpdb() {
		$wpdb            = Mockery::mock( 'wpdb' );
		$wpdb->prefix    = 'wp_';
		$wpdb->insert_id = 0;
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( $sql, ...$args ) {
				$flat = 1 === count( $args ) && is_array( $args[0] ) ? $args[0] : $args;
				return array(
					'sql'  => $sql,
					'args' => $flat,
				);
			}
		);
		$wpdb->shouldReceive( 'get_row' )->andReturnUsing( fn( $q ) => $this->answer_row( $q ) );
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing( fn( $q ) => $this->answer_results( $q ) );
		$wpdb->shouldReceive( 'get_col' )->andReturnUsing(
			function ( $q ) {
				if ( false !== strpos( $q['sql'], 'SELECT user_id FROM %i WHERE booking_id = %d' ) ) {
					return $this->booking_users[ $q['args'][1] ] ?? array();
				}
				$this->unexpected[] = 'get_col: ' . $q['sql'];
				return array();
			}
		);
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing( static fn( $text ) => $text );
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( $q ) {
				if ( 'SELECT 1 FROM %i WHERE data LIKE %s LIMIT 1' === $q['sql'] ) {
					foreach ( $this->answered_keys as $key ) {
						if ( '%"' . $key . '":%' === $q['args'][1] ) {
							return '1';
						}
					}
					return null;
				}
				if ( 'wp_ffc_custom_fields' === $q['args'][0] && false !== strpos( $q['sql'], 'SELECT COUNT(*) FROM %i WHERE audience_id = %d AND field_key = %s' ) ) {
					return (string) count( $this->fields_where( array( 'audience_id' => $q['args'][1], 'field_key' => $q['args'][2] ) ) );
				}
				$this->unexpected[] = 'get_var: ' . $q['sql'];
				return null;
			}
		);
		$wpdb->shouldReceive( 'insert' )->andReturnUsing(
			function ( $table, $data ) use ( $wpdb ) {
				$this->writes[] = array( 'op' => 'insert', 'table' => $table, 'data' => $data, 'where' => array() );
				if ( isset( $this->failing_tables[ $table ] ) ) {
					return false;
				}
				$wpdb->insert_id = 99;
				return 1;
			}
		);
		$wpdb->shouldReceive( 'update' )->andReturnUsing(
			function ( $table, $data, $where ) {
				$this->writes[] = array( 'op' => 'update', 'table' => $table, 'data' => $data, 'where' => $where );
				return isset( $this->failing_tables[ $table ] ) ? false : 1;
			}
		);
		$wpdb->shouldReceive( 'delete' )->andReturnUsing(
			function ( $table, $where ) {
				$this->writes[] = array( 'op' => 'delete', 'table' => $table, 'data' => array(), 'where' => $where );
				return isset( $this->failing_tables[ $table ] ) ? false : 1;
			}
		);
		$wpdb->shouldReceive( 'query' )->andReturnUsing(
			function ( $q ) {
				if ( is_array( $q ) && false !== strpos( $q['sql'], "SET status = 'cancelled'" ) ) {
					// AudienceBookingWriter::cancel(): only an active row changes (#2243).
					list( $table, $by, $at, $reason, $id ) = $q['args'];
					$this->writes[] = array(
						'op'    => 'update',
						'table' => $table,
						'data'  => array(
							'status'              => 'cancelled',
							'cancelled_by'        => $by,
							'cancelled_at'        => $at,
							'cancellation_reason' => $reason,
						),
						'where' => array( 'id' => $id ),
					);
					if ( isset( $this->failing_tables[ $table ] ) ) {
						return false;
					}
					if ( 'active' !== ( $this->bookings[ $id ]['status'] ?? null ) ) {
						return 0;
					}
					$this->bookings[ $id ]['status'] = 'cancelled';
					return 1;
				}
				$this->unexpected[] = 'query: ' . ( is_array( $q ) ? $q['sql'] : $q );
				return false;
			}
		);
		return $wpdb;
	}

	/**
	 * @param array{sql: string, args: array<int, mixed>} $q Prepared query.
	 * @return object|null
	 */
	private function answer_row( array $q ) {
		$sql   = $q['sql'];
		$args  = $q['args'];
		$table = $args[0] ?? '';
		if ( false !== strpos( $sql, 'INNER JOIN %i e ON b.environment_id = e.id' ) ) {
			return isset( $this->bookings[ $args[2] ] ) ? (object) $this->bookings[ $args[2] ] : null;
		}
		if ( false !== strpos( $sql, 'SELECT * FROM %i WHERE id = %d' ) ) {
			$rows = array(
				'wp_ffc_audience_schedules' => $this->schedules,
				'wp_ffc_audiences'          => $this->audiences,
				'wp_ffc_custom_fields'      => $this->fields,
			);
			if ( isset( $rows[ $table ] ) ) {
				return isset( $rows[ $table ][ $args[1] ] ) ? (object) $rows[ $table ][ $args[1] ] : null;
			}
		}
		if ( 'wp_ffc_audience_schedule_permissions' === $table && false !== strpos( $sql, 'WHERE schedule_id = %d AND user_id = %d' ) ) {
			$key = $args[1] . ':' . $args[2];
			return isset( $this->permissions[ $key ] ) ? (object) $this->permissions[ $key ] : null;
		}
		if ( 'wp_ffc_custom_fields' === $table && false !== strpos( $sql, 'WHERE audience_id = %d AND field_key = %s LIMIT 1' ) ) {
			$found = $this->fields_where( array( 'audience_id' => $args[1], 'field_key' => $args[2] ) );
			return $found ? (object) $found[0] : null;
		}
		$this->unexpected[] = 'get_row: ' . $sql;
		return null;
	}

	/**
	 * @param array{sql: string, args: array<int, mixed>} $q Prepared query.
	 * @return list<object>
	 */
	private function answer_results( array $q ): array {
		$sql   = $q['sql'];
		$args  = $q['args'];
		$table = $args[0] ?? '';
		$rows  = null;
		if ( false !== strpos( $sql, 'WHERE ba.booking_id = %d' ) ) {
			$rows = $this->booking_audiences[ $args[2] ] ?? array();
		} elseif ( 'wp_ffc_audience_environments' === $table && false !== strpos( $sql, 'WHERE schedule_id = %d ' ) ) {
			$rows = array_values( array_filter( $this->environments, static fn( $e ) => (int) $e['schedule_id'] === (int) $args[1] ) );
		} elseif ( 'wp_ffc_audiences' === $table && false !== strpos( $sql, 'WHERE parent_id = %d ' ) ) {
			$rows = array_values( array_filter( $this->audiences, static fn( $a ) => (int) ( $a['parent_id'] ?? 0 ) === (int) $args[1] ) );
		} elseif ( 'wp_ffc_custom_fields' === $table && false !== strpos( $sql, 'WHERE audience_id = %d ORDER BY' ) ) {
			$rows = $this->fields_where( array( 'audience_id' => $args[1] ) );
		}
		if ( null === $rows ) {
			$this->unexpected[] = 'get_results: ' . $sql;
			return array();
		}
		return array_map( static fn( $r ) => (object) $r, $rows );
	}

	/**
	 * Stored custom-field rows matching every column given.
	 *
	 * @param array<string, mixed> $match Column => value.
	 * @return list<array<string, mixed>>
	 */
	private function fields_where( array $match ): array {
		return array_values(
			array_filter(
				$this->fields,
				static function ( $row ) use ( $match ) {
					foreach ( $match as $col => $value ) {
						if ( (string) $row[ $col ] !== (string) $value ) {
							return false;
						}
					}
					return true;
				}
			)
		);
	}

	/**
	 * The writes the double received on one table.
	 *
	 * @param string $table Table name.
	 * @return list<array{op: string, table: string, data: array<string, mixed>, where: array<string, mixed>}>
	 */
	private function writes_to( string $table ): array {
		return array_values( array_filter( $this->writes, static fn( $w ) => $table === $w['table'] ) );
	}

	/** Run a handler, swallowing the wp_send_json short-circuit exception. */
	private function dispatch( callable $fn ): void {
		try {
			$fn();
		} catch ( \RuntimeException $e ) {
			// wp_send_json_* throws in the stub to mimic wp_die().
		}
	}

	/**
	 * A stored booking row, as the joined reader query returns it.
	 *
	 * @param array<string, mixed> $overrides Columns to change.
	 * @return array<string, mixed>
	 */
	private function booking( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'               => 9,
				'environment_id'   => 3,
				'schedule_id'      => 5,
				'created_by'       => 11,
				'booking_date'     => '2026-05-20',
				'start_time'       => '09:00',
				'end_time'         => '10:00',
				'environment_name' => 'Room A',
				'description'      => 'desc',
				'booking_type'     => 'meeting',
				'status'           => 'active',
				'created_at'       => '2026-05-01',
			),
			$overrides
		);
	}

	public function test_register_adds_every_ajax_hook(): void {
		$hooks = array(
			'wp_ajax_ffc_audience_cancel_booking',
			'wp_ajax_ffc_audience_get_booking',
			'wp_ajax_ffc_search_users',
			'wp_ajax_ffc_audience_get_environments',
			'wp_ajax_ffc_audience_add_user_permission',
			'wp_ajax_ffc_audience_update_user_permission',
			'wp_ajax_ffc_audience_remove_user_permission',
			'wp_ajax_ffc_save_custom_fields',
			'wp_ajax_ffc_delete_custom_field',
			'wp_ajax_ffc_replicate_field_options',
		);
		foreach ( $hooks as $h ) {
			Actions\expectAdded( $h );
		}

		( new AudienceAjaxController() )->register();
	}

	public function test_booking_handlers_gate_on_the_granular_audience_cap(): void {
		$_POST = array( 'nonce' => 'n' );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_cancel_booking() );

		// Admin first, then the granular cap that let the manager in (#739).
		$this->assertSame( array( 'manage_options', 'ffc_manage_audiences' ), $this->cap_checks );
	}

	public function test_booking_handlers_refuse_a_user_without_the_cap(): void {
		$this->caps = array( 'ffc_manage_custom_fields' );
		$_POST      = array( 'nonce' => 'n', 'booking_id' => '9' );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_cancel_booking() );

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertStringContainsString( 'Permission denied', $this->responses[0]['data']['message'] );
		$this->assertSame( array(), $this->writes );
	}

	// ── cancel_booking ─────────────────────────────────────────────────

	public function test_cancel_booking_rejects_invalid_id(): void {
		$_POST = array( 'nonce' => 'n' );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_cancel_booking() );

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertStringContainsString( 'Invalid booking ID', $this->responses[0]['data']['message'] );
	}

	public function test_cancel_booking_reports_not_found(): void {
		$_POST = array( 'nonce' => 'n', 'booking_id' => '9' );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_cancel_booking() );

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertStringContainsString( 'Booking not found', $this->responses[0]['data']['message'] );
	}

	public function test_cancel_booking_rejects_already_cancelled(): void {
		$_POST          = array( 'nonce' => 'n', 'booking_id' => '9' );
		$this->bookings = array( 9 => $this->booking( array( 'status' => 'cancelled' ) ) );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_cancel_booking() );

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertStringContainsString( 'already cancelled', $this->responses[0]['data']['message'] );
		$this->assertSame( array(), $this->writes );
	}

	/**
	 * The writer refuses a cancellation without a reason before it writes.
	 */
	public function test_cancel_booking_reports_failure_without_a_reason(): void {
		$_POST          = array( 'nonce' => 'n', 'booking_id' => '9' );
		$this->bookings = array( 9 => $this->booking() );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_cancel_booking() );

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertStringContainsString( 'Failed to cancel', $this->responses[0]['data']['message'] );
		$this->assertSame( array(), $this->writes );
	}

	/**
	 * A cancel the database refused is reported as a failure, and the
	 * cancellation hook (audit and notifications) does not fire (#2183).
	 */
	public function test_cancel_booking_reports_a_refused_write_and_fires_no_hook(): void {
		$_POST                = array( 'nonce' => 'n', 'booking_id' => '9', 'reason' => 'x' );
		$this->bookings       = array( 9 => $this->booking() );
		$this->failing_tables = array( 'wp_ffc_audience_bookings' => true );
		$actions              = CallLog::record( 'do_action' );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_cancel_booking() );

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertStringContainsString( 'Failed to cancel', $this->responses[0]['data']['message'] );
		$this->assertSame( 'update', $this->writes[0]['op'] );
		$this->assertSame( 0, $actions->count() );
	}

	public function test_cancel_booking_succeeds(): void {
		$_POST          = array( 'nonce' => 'n', 'booking_id' => '9', 'reason' => 'x' );
		$this->bookings = array( 9 => $this->booking() );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_cancel_booking() );

		$this->assertTrue( $this->responses[0]['ok'] );
		$this->assertStringContainsString( 'cancelled successfully', $this->responses[0]['data']['message'] );
		$this->assertSame(
			array(
				array(
					'op'    => 'update',
					'table' => 'wp_ffc_audience_bookings',
					'data'  => array(
						'status'              => 'cancelled',
						'cancelled_by'        => 1,
						'cancelled_at'        => '2026-05-20 10:00:00',
						'cancellation_reason' => 'x',
					),
					'where' => array( 'id' => 9 ),
				),
			),
			$this->writes
		);
	}

	// ── get_booking ────────────────────────────────────────────────────

	public function test_get_booking_rejects_invalid_id(): void {
		$_POST = array( 'nonce' => 'n' );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_get_booking() );

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertStringContainsString( 'Invalid booking ID', $this->responses[0]['data']['message'] );
	}

	public function test_get_booking_reports_not_found(): void {
		$_POST = array( 'nonce' => 'n', 'booking_id' => '4' );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_get_booking() );

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertStringContainsString( 'Booking not found', $this->responses[0]['data']['message'] );
	}

	public function test_get_booking_maps_full_payload(): void {
		$_POST                      = array( 'nonce' => 'n', 'booking_id' => '4' );
		$this->bookings             = array( 4 => $this->booking( array( 'id' => 4, 'is_all_day' => 1 ) ) );
		$this->booking_audiences[4] = array( array( 'id' => 2, 'name' => 'Aud2' ) );
		$this->booking_users[4]     = array( '7' );

		Functions\when( 'get_userdata' )->alias(
			static function ( $id ) {
				if ( 11 === $id ) {
					return (object) array( 'ID' => 11, 'display_name' => 'Creator', 'user_email' => 'c@e.com' );
				}
				return (object) array( 'ID' => 7, 'display_name' => 'Member', 'user_email' => 'm@e.com' );
			}
		);

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_get_booking() );

		$this->assertTrue( $this->responses[0]['ok'] );
		$data = $this->responses[0]['data'];
		$this->assertSame( 4, $data['id'] );
		$this->assertSame( 'Creator', $data['created_by'] );
		$this->assertSame( 1, $data['is_all_day'] );
		$this->assertSame( array( array( 'id' => 2, 'name' => 'Aud2' ) ), $data['audiences'] );
		$this->assertSame( 7, $data['users'][0]['id'] );
		$this->assertSame( 'm@e.com', $data['users'][0]['email'] );
	}

	public function test_get_booking_unknown_creator_and_empty_relations(): void {
		$_POST          = array( 'nonce' => 'n', 'booking_id' => '4' );
		$this->bookings = array( 4 => $this->booking( array( 'id' => 4, 'created_by' => 0, 'description' => '' ) ) );

		Functions\when( 'get_userdata' )->justReturn( false );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_get_booking() );

		$this->assertTrue( $this->responses[0]['ok'] );
		$data = $this->responses[0]['data'];
		$this->assertSame( 'Unknown', $data['created_by'] );
		$this->assertSame( 0, $data['is_all_day'] );
		$this->assertSame( array(), $data['audiences'] );
		$this->assertSame( array(), $data['users'] );
	}

	// ── search_users ───────────────────────────────────────────────────

	public function test_search_users_short_query_returns_empty(): void {
		$_POST = array( 'nonce' => 'n', 'query' => 'a' );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_search_users() );

		$this->assertTrue( $this->responses[0]['ok'] );
		$this->assertSame( array(), $this->responses[0]['data'] );
	}

	public function test_search_users_maps_results(): void {
		$_POST = array( 'nonce' => 'n', 'query' => 'john' );

		Functions\when( 'get_users' )->justReturn(
			array(
				(object) array( 'ID' => 3, 'display_name' => 'John', 'user_email' => 'j@e.com' ),
			)
		);

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_search_users() );

		$this->assertTrue( $this->responses[0]['ok'] );
		$this->assertSame(
			array( array( 'id' => 3, 'name' => 'John', 'email' => 'j@e.com' ) ),
			$this->responses[0]['data']
		);
	}

	// ── get_environments ───────────────────────────────────────────────

	public function test_get_environments_empty_for_invalid_schedule(): void {
		$_POST = array( 'nonce' => 'n', 'schedule_id' => '0' );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_get_environments() );

		$this->assertTrue( $this->responses[0]['ok'] );
		$this->assertSame( array(), $this->responses[0]['data'] );
	}

	public function test_get_environments_maps_results(): void {
		$_POST              = array( 'nonce' => 'n', 'schedule_id' => '5' );
		$this->environments = array(
			array( 'id' => 8, 'schedule_id' => 5, 'name' => 'Room' ),
			array( 'id' => 9, 'schedule_id' => 6, 'name' => 'Other calendar' ),
		);

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_get_environments() );

		$this->assertTrue( $this->responses[0]['ok'] );
		$this->assertSame( array( array( 'id' => 8, 'name' => 'Room' ) ), $this->responses[0]['data'] );
	}

	// ── add_user_permission ────────────────────────────────────────────

	public function test_add_user_permission_rejects_missing_params(): void {
		$_POST = array( '_wpnonce' => 'n' );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_add_user_permission() );

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertStringContainsString( 'Missing required parameters', $this->responses[0]['data']['message'] );
	}

	public function test_add_user_permission_reports_calendar_not_found(): void {
		$_POST = array( '_wpnonce' => 'n', 'schedule_id' => '5', 'user_id' => '7' );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_add_user_permission() );

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertStringContainsString( 'Calendar not found', $this->responses[0]['data']['message'] );
	}

	public function test_add_user_permission_reports_user_not_found(): void {
		$_POST           = array( '_wpnonce' => 'n', 'schedule_id' => '5', 'user_id' => '7' );
		$this->schedules = array( 5 => array( 'id' => 5 ) );
		Functions\when( 'get_userdata' )->justReturn( false );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_add_user_permission() );

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertStringContainsString( 'User not found', $this->responses[0]['data']['message'] );
	}

	public function test_add_user_permission_rejects_existing_access(): void {
		$_POST             = array( '_wpnonce' => 'n', 'schedule_id' => '5', 'user_id' => '7' );
		$this->schedules   = array( 5 => array( 'id' => 5 ) );
		$this->permissions = array( '5:7' => array( 'id' => 70, 'schedule_id' => 5, 'user_id' => 7, 'can_book' => 1 ) );
		Functions\when( 'get_userdata' )->justReturn( (object) array( 'ID' => 7, 'display_name' => 'U', 'user_email' => 'u@e.com' ) );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_add_user_permission() );

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertStringContainsString( 'already has access', $this->responses[0]['data']['message'] );
		$this->assertSame( array(), $this->writes );
	}

	public function test_add_user_permission_reports_set_failure(): void {
		$_POST                = array( '_wpnonce' => 'n', 'schedule_id' => '5', 'user_id' => '7' );
		$this->schedules      = array( 5 => array( 'id' => 5 ) );
		$this->failing_tables = array( 'wp_ffc_audience_schedule_permissions' => true );
		Functions\when( 'get_userdata' )->justReturn( (object) array( 'ID' => 7, 'display_name' => 'U', 'user_email' => 'u@e.com' ) );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_add_user_permission() );

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertStringContainsString( 'Error adding user permissions', $this->responses[0]['data']['message'] );
	}

	/**
	 * The row draws the account email masked (#1824); the policy decides
	 * whether it carries the Reveal button. The viewer (user 1) is not the
	 * account's owner, so the answer is whether they hold the audiences PII
	 * capability.
	 *
	 * @param bool $can_reveal Whether the viewer holds the PII capability.
	 * @return \ArrayObject<int, array{0: int, 1: string}> The user_can() calls, recorded.
	 */
	private function stubAccountEmail( bool $can_reveal ): \ArrayObject {
		Functions\when( 'is_email' )->justReturn( true );
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'wp_create_nonce' )->justReturn( 'reveal-nonce' );
		$asked = new \ArrayObject();
		Functions\when( 'user_can' )->alias(
			static function ( $uid, $cap ) use ( $can_reveal, $asked ) {
				$asked[] = array( $uid, $cap );
				return 'ffc_view_audiences_pii' === $cap && $can_reveal;
			}
		);
		return $asked;
	}

	/**
	 * Store the calendar and the account a successful add reaches.
	 */
	private function storeAddablePermission(): void {
		$_POST           = array( '_wpnonce' => 'n', 'schedule_id' => '5', 'user_id' => '7' );
		$this->schedules = array( 5 => array( 'id' => 5 ) );
		Functions\when( 'get_userdata' )->justReturn( (object) array( 'ID' => 7, 'display_name' => 'Jane', 'user_email' => 'jane@e.com' ) );
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( static function ( $t ) { echo $t; } );
	}

	/**
	 * @return array<string, array{0: bool}>
	 */
	public static function reveal_tiers(): array {
		return array(
			'may reveal' => array( true ),
			'masked'     => array( false ),
		);
	}

	/**
	 * @dataProvider reveal_tiers
	 *
	 * @param bool $can_reveal Whether the viewer holds the PII capability.
	 */
	public function test_add_user_permission_succeeds_with_html( bool $can_reveal ): void {
		$asked = $this->stubAccountEmail( $can_reveal );
		$this->storeAddablePermission();

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_add_user_permission() );

		$this->assertTrue( $this->responses[0]['ok'] );
		$html = $this->responses[0]['data']['html'];
		$this->assertStringContainsString( 'Jane', $html );
		// The row the script inserts is masked by the server, never sent clear (#1824).
		$this->assertStringNotContainsString( 'jane@e.com', $html );
		$this->assertStringContainsString( '<span class="ffc-pii-value" data-field="email">j***@e.com</span>', $html );
		$this->assertSame(
			$can_reveal,
			str_contains( $html, 'data-type="audience_account" data-field="email" data-submission-id="7" data-nonce="reveal-nonce"' )
		);
		// The policy was asked about the audiences PII capability.
		$this->assertContains( array( 1, 'ffc_view_audiences_pii' ), $asked->getArrayCopy() );
		// The grant written is the default one: book, nothing else.
		$this->assertSame(
			array(
				array(
					'op'    => 'insert',
					'table' => 'wp_ffc_audience_schedule_permissions',
					'data'  => array(
						'schedule_id'            => 5,
						'user_id'                => 7,
						'can_book'               => 1,
						'can_cancel_others'      => 0,
						'can_override_conflicts' => 0,
					),
					'where' => array(),
				),
			),
			$this->writes
		);
	}

	public function test_add_user_permission_row_uses_the_toggle_markup_of_the_page(): void {
		$this->stubAccountEmail( false );
		$this->storeAddablePermission();

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_add_user_permission() );

		$html = $this->responses[0]['data']['html'];
		// A row added by AJAX draws the same toggles as the initial render
		// (#1771): three AdminUI toggles, can_book on and the other two off.
		$this->assertSame( 3, substr_count( $html, 'class="ffc-control-toggle ffc-control-toggle-mark"' ) );
		$this->assertSame( 3, substr_count( $html, 'ffc-control-toggle-track' ) );
		$this->assertStringContainsString( 'name="ffc_perm_7_can_book" value="1" checked class="ffc-perm-toggle" data-perm="can_book"', $html );
		$this->assertStringContainsString( 'name="ffc_perm_7_can_cancel_others" value="1" class="ffc-perm-toggle" data-perm="can_cancel_others"', $html );
		$this->assertStringContainsString( 'name="ffc_perm_7_can_override_conflicts" value="1" class="ffc-perm-toggle" data-perm="can_override_conflicts"', $html );
	}

	// ── update_user_permission ─────────────────────────────────────────

	public function test_update_user_permission_rejects_missing_params(): void {
		$_POST = array( '_wpnonce' => 'n', 'schedule_id' => '5' );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_update_user_permission() );

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertStringContainsString( 'Missing required parameters', $this->responses[0]['data']['message'] );
	}

	public function test_update_user_permission_rejects_invalid_permission(): void {
		$_POST = array( '_wpnonce' => 'n', 'schedule_id' => '5', 'user_id' => '7', 'permission' => 'bad' );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_update_user_permission() );

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertStringContainsString( 'Invalid permission', $this->responses[0]['data']['message'] );
	}

	public function test_update_user_permission_reports_no_access(): void {
		$_POST = array( '_wpnonce' => 'n', 'schedule_id' => '5', 'user_id' => '7', 'permission' => 'can_book' );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_update_user_permission() );

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertStringContainsString( 'does not have access', $this->responses[0]['data']['message'] );
		$this->assertSame( array(), $this->writes );
	}

	/**
	 * A stored grant of user 7 on calendar 5: book only.
	 */
	private function storeBookOnlyGrant(): void {
		$this->permissions = array(
			'5:7' => array(
				'id'                     => 70,
				'schedule_id'            => 5,
				'user_id'                => 7,
				'can_book'               => 1,
				'can_cancel_others'      => 0,
				'can_override_conflicts' => 0,
			),
		);
	}

	public function test_update_user_permission_succeeds(): void {
		$_POST = array(
			'_wpnonce'    => 'n',
			'schedule_id' => '5',
			'user_id'     => '7',
			'permission'  => 'can_cancel_others',
			'value'       => '1',
		);
		$this->storeBookOnlyGrant();

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_update_user_permission() );

		$this->assertTrue( $this->responses[0]['ok'] );
		// Only the flipped permission changes; the stored row is updated in place.
		$this->assertSame(
			array(
				array(
					'op'    => 'update',
					'table' => 'wp_ffc_audience_schedule_permissions',
					'data'  => array( 'can_book' => 1, 'can_cancel_others' => 1, 'can_override_conflicts' => 0 ),
					'where' => array( 'id' => 70 ),
				),
			),
			$this->writes
		);
	}

	public function test_update_user_permission_reports_set_failure(): void {
		$_POST = array(
			'_wpnonce'    => 'n',
			'schedule_id' => '5',
			'user_id'     => '7',
			'permission'  => 'can_book',
			'value'       => '0',
		);
		$this->storeBookOnlyGrant();
		$this->failing_tables = array( 'wp_ffc_audience_schedule_permissions' => true );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_update_user_permission() );

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertStringContainsString( 'Error updating permission', $this->responses[0]['data']['message'] );
	}

	// ── remove_user_permission ─────────────────────────────────────────

	public function test_remove_user_permission_rejects_missing_params(): void {
		$_POST = array( '_wpnonce' => 'n' );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_remove_user_permission() );

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertStringContainsString( 'Missing required parameters', $this->responses[0]['data']['message'] );
	}

	public function test_remove_user_permission_reports_failure(): void {
		$_POST                = array( '_wpnonce' => 'n', 'schedule_id' => '5', 'user_id' => '7' );
		$this->failing_tables = array( 'wp_ffc_audience_schedule_permissions' => true );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_remove_user_permission() );

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertStringContainsString( 'Error removing user access', $this->responses[0]['data']['message'] );
	}

	public function test_remove_user_permission_succeeds(): void {
		$_POST = array( '_wpnonce' => 'n', 'schedule_id' => '5', 'user_id' => '7' );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_remove_user_permission() );

		$this->assertTrue( $this->responses[0]['ok'] );
		$this->assertSame(
			array(
				array(
					'op'    => 'delete',
					'table' => 'wp_ffc_audience_schedule_permissions',
					'data'  => array(),
					'where' => array( 'schedule_id' => 5, 'user_id' => 7 ),
				),
			),
			$this->writes
		);
	}

	// ── delete_custom_field ────────────────────────────────────────────

	public function test_delete_custom_field_rejects_invalid_id(): void {
		$this->caps = array( 'ffc_delete_custom_fields' );
		$_POST      = array( 'nonce' => 'n' );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_delete_custom_field() );

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertStringContainsString( 'Invalid field ID', $this->responses[0]['data']['message'] );
	}

	public function test_delete_custom_field_reports_not_found(): void {
		$this->caps = array( 'ffc_delete_custom_fields' );
		$_POST      = array( 'nonce' => 'n', 'field_id' => '3' );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_delete_custom_field() );

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertStringContainsString( 'Field not found', $this->responses[0]['data']['message'] );
	}

	public function test_delete_custom_field_blocks_standard(): void {
		$this->caps   = array( 'ffc_delete_custom_fields' );
		$_POST        = array( 'nonce' => 'n', 'field_id' => '3' );
		$this->fields = array( 3 => array( 'id' => 3, 'audience_id' => 4, 'field_key' => 'department', 'field_source' => 'standard' ) );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_delete_custom_field() );

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertStringContainsString( 'cannot be deleted', $this->responses[0]['data']['message'] );
		$this->assertSame( array(), $this->writes );
	}

	public function test_delete_custom_field_reports_failure(): void {
		$this->caps           = array( 'ffc_delete_custom_fields' );
		$_POST                = array( 'nonce' => 'n', 'field_id' => '3' );
		$this->fields         = array( 3 => array( 'id' => 3, 'audience_id' => 4, 'field_key' => 'shoe', 'field_source' => 'custom' ) );
		$this->failing_tables = array( 'wp_ffc_custom_fields' => true );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_delete_custom_field() );

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertStringContainsString( 'Failed to delete', $this->responses[0]['data']['message'] );
	}

	public function test_delete_custom_field_succeeds(): void {
		$this->caps   = array( 'ffc_delete_custom_fields' );
		$_POST        = array( 'nonce' => 'n', 'field_id' => '3' );
		$this->fields = array( 3 => array( 'id' => 3, 'audience_id' => 4, 'field_key' => 'shoe', 'field_source' => 'custom' ) );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_delete_custom_field() );

		$this->assertTrue( $this->responses[0]['ok'] );
		$this->assertStringContainsString( 'deleted successfully', $this->responses[0]['data']['message'] );
		$this->assertSame(
			array(
				array(
					'op'    => 'delete',
					'table' => 'wp_ffc_custom_fields',
					'data'  => array(),
					'where' => array( 'id' => 3 ),
				),
			),
			$this->writes
		);
	}

	public function test_delete_custom_field_refuses_an_answered_field(): void {
		$this->caps          = array( 'ffc_delete_custom_fields' );
		$_POST               = array( 'nonce' => 'n', 'field_id' => '3' );
		$this->fields        = array( 3 => array( 'id' => 3, 'audience_id' => 4, 'field_key' => 'shoe_answered', 'field_source' => 'custom' ) );
		$this->answered_keys = array( 'shoe_answered' );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_delete_custom_field() );

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertStringContainsString( 'already has answers', $this->responses[0]['data']['message'] );
		$this->assertSame( array(), $this->writes, 'An answered field is deactivated, never deleted (#2242).' );
	}

	// ── replicate_field_options ────────────────────────────────────────

	public function test_replicate_field_options_rejects_invalid_id(): void {
		$this->caps = array( 'ffc_manage_custom_fields' );
		$_POST      = array( 'nonce' => 'n' );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_replicate_field_options() );

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertStringContainsString( 'Invalid data', $this->responses[0]['data']['message'] );
	}

	public function test_replicate_field_options_reports_audience_not_found(): void {
		$this->caps = array( 'ffc_manage_custom_fields' );
		$_POST      = array( 'nonce' => 'n', 'audience_id' => '4' );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_replicate_field_options() );

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertStringContainsString( 'Audience not found', $this->responses[0]['data']['message'] );
	}

	public function test_replicate_field_options_succeeds(): void {
		$this->caps = array( 'ffc_manage_custom_fields' );
		Functions\when( '_n' )->alias( static fn( $s, $p, $n ) => $n === 1 ? $s : $p );
		$_POST = array( 'nonce' => 'n', 'audience_id' => '4' );

		// Audience 4 has two children; its standard field's options reach the
		// same field on each of them.
		$this->audiences = array(
			4 => array( 'id' => 4, 'parent_id' => null, 'name' => 'Parent' ),
			5 => array( 'id' => 5, 'parent_id' => 4, 'name' => 'Child A' ),
			6 => array( 'id' => 6, 'parent_id' => 4, 'name' => 'Child B' ),
		);
		$this->fields    = array(
			20 => array( 'id' => 20, 'audience_id' => 4, 'field_key' => 'department', 'field_source' => 'standard', 'field_options' => '{"choices":["A","B"]}' ),
			21 => array( 'id' => 21, 'audience_id' => 5, 'field_key' => 'department', 'field_source' => 'standard', 'field_options' => null ),
			22 => array( 'id' => 22, 'audience_id' => 6, 'field_key' => 'department', 'field_source' => 'standard', 'field_options' => null ),
		);

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_replicate_field_options() );

		$this->assertTrue( $this->responses[0]['ok'] );
		$this->assertSame( 2, $this->responses[0]['data']['updated'] );
		$this->assertSame(
			array( array( 'id' => 21 ), array( 'id' => 22 ) ),
			array_column( $this->writes_to( 'wp_ffc_custom_fields' ), 'where' )
		);
	}

	// ── save_custom_fields ─────────────────────────────────────────────

	public function test_save_custom_fields_rejects_invalid_json(): void {
		$this->caps = array( 'ffc_manage_custom_fields' );
		$_POST      = array( 'nonce' => 'n', 'audience_id' => '4', 'fields' => 'not-json' );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_save_custom_fields() );

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertStringContainsString( 'Invalid field data format', $this->responses[0]['data']['message'] );
	}

	public function test_save_custom_fields_rejects_missing_audience(): void {
		$this->caps = array( 'ffc_manage_custom_fields' );
		$_POST      = array( 'nonce' => 'n', 'audience_id' => '0', 'fields' => '[]' );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_save_custom_fields() );

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertStringContainsString( 'Invalid data', $this->responses[0]['data']['message'] );
	}

	public function test_save_custom_fields_reports_audience_not_found(): void {
		$this->caps = array( 'ffc_manage_custom_fields' );
		$_POST      = array( 'nonce' => 'n', 'audience_id' => '4', 'fields' => '[]' );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_save_custom_fields() );

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertStringContainsString( 'Audience not found', $this->responses[0]['data']['message'] );
	}

	/**
	 * Post a field list for audience 4, which exists.
	 *
	 * @param list<array<string, mixed>> $fields The editor's payload.
	 */
	private function postFields( array $fields ): void {
		$this->caps         = array( 'ffc_manage_custom_fields' );
		$this->audiences[4] = array( 'id' => 4, 'parent_id' => null, 'name' => 'Parent' );
		$_POST              = array( 'nonce' => 'n', 'audience_id' => '4', 'fields' => json_encode( $fields ) );
	}

	public function test_save_custom_fields_creates_new_field(): void {
		$this->postFields(
			array(
				array(
					'id'      => 'new_1',
					'label'   => 'My Field',
					'key'     => 'my_field',
					'type'    => 'text',
					'choices' => array( 'A', '', 'B' ),
				),
			)
		);

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_save_custom_fields() );

		$this->assertTrue( $this->responses[0]['ok'] );
		$this->assertSame( array( 99 ), $this->responses[0]['data']['saved_ids'] );
		$writes = $this->writes_to( 'wp_ffc_custom_fields' );
		$this->assertCount( 1, $writes );
		$this->assertSame( 'insert', $writes[0]['op'] );
		$this->assertSame( 4, $writes[0]['data']['audience_id'] );
		$this->assertSame( 'my_field', $writes[0]['data']['field_key'] );
		$this->assertSame( 'custom', $writes[0]['data']['field_source'] );
		// The empty choice is dropped before it is stored.
		$this->assertSame( '{"choices":["A","B"]}', $writes[0]['data']['field_options'] );
	}

	/**
	 * A field bound to the login e-mail let any participant change it from a
	 * form, and through the import an importer could point an administrator's
	 * login at their own mailbox (#2242). The editor refuses the binding.
	 */
	public function test_save_custom_fields_refuses_a_binding_to_the_login_email(): void {
		$this->postFields(
			array(
				array(
					'id'          => 'new_1',
					'label'       => 'Contact',
					'key'         => 'contact',
					'type'        => 'text',
					'profile_key' => 'user_email',
				),
			)
		);

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_save_custom_fields() );

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertStringContainsString( 'cannot write to the profile key "user_email"', $this->responses[0]['data']['message'] );
		$this->assertSame( array(), $this->writes_to( 'wp_ffc_custom_fields' ), 'Nothing is saved.' );
	}

	public function test_save_custom_fields_reports_label_error(): void {
		$this->postFields(
			array(
				array( 'id' => 'new_1', 'label' => '', 'type' => 'text' ),
			)
		);

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_save_custom_fields() );

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertStringContainsString( 'label is required', $this->responses[0]['data']['message'] );
		$this->assertSame( array(), $this->writes );
	}

	public function test_save_custom_fields_updates_existing_field(): void {
		$this->postFields(
			array(
				array( 'id' => 12, 'label' => 'Updated', 'type' => 'text' ),
			)
		);
		$this->fields = array( 12 => array( 'id' => 12, 'audience_id' => 4, 'field_key' => 'shoe', 'field_source' => 'custom', 'is_sensitive' => 0 ) );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_save_custom_fields() );

		$this->assertTrue( $this->responses[0]['ok'] );
		$this->assertSame( array( 12 ), $this->responses[0]['data']['saved_ids'] );
		$writes = $this->writes_to( 'wp_ffc_custom_fields' );
		$this->assertCount( 1, $writes );
		$this->assertSame( 'update', $writes[0]['op'] );
		$this->assertSame( array( 'id' => 12 ), $writes[0]['where'] );
		$this->assertSame( 'Updated', $writes[0]['data']['field_label'] );
	}

	public function test_save_custom_fields_locks_standard_field(): void {
		$this->postFields(
			array(
				array(
					'id'      => 12,
					'label'   => 'Std',
					'type'    => 'text',
					'key'     => 'should_be_dropped',
					'choices' => array( 'X' ),
				),
			)
		);
		$this->fields = array( 12 => array( 'id' => 12, 'audience_id' => 4, 'field_key' => 'department', 'field_source' => 'standard', 'field_options' => null ) );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_save_custom_fields() );

		$this->assertTrue( $this->responses[0]['ok'] );
		$writes = $this->writes_to( 'wp_ffc_custom_fields' );
		$this->assertCount( 1, $writes );
		$this->assertSame( array( 'id' => 12 ), $writes[0]['where'] );
		// Locked standard field: key must be stripped, label and options retained.
		$this->assertArrayNotHasKey( 'field_key', $writes[0]['data'] );
		$this->assertArrayHasKey( 'field_label', $writes[0]['data'] );
		$this->assertArrayHasKey( 'field_options', $writes[0]['data'] );
	}

	public function test_save_custom_fields_reports_create_failure(): void {
		$this->postFields(
			array(
				array( 'id' => 'new_1', 'label' => 'Fail', 'type' => 'text' ),
			)
		);
		$this->failing_tables = array( 'wp_ffc_custom_fields' => true );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_save_custom_fields() );

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertStringContainsString( 'Failed to create', $this->responses[0]['data']['message'] );
	}

	// ── Custom-fields helpers (moved verbatim from AudienceLoader) ──────

	/**
	 * @param array<int, mixed> $args
	 * @return mixed
	 */
	private function invoke_controller( string $method, array $args ) {
		$ctrl = new AudienceAjaxController();
		$ref  = new \ReflectionMethod( AudienceAjaxController::class, $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( $ctrl, $args );
	}

	public function test_sanitize_dependent_groups_dedups_and_drops_empty(): void {
		Functions\when( 'sanitize_text_field' )->alias( 'trim' );

		$raw = array(
			'Div A' => array( 'S1', 'S1', 'S2' ), // dedup.
			''      => array( 'Orphan' ),          // empty division dropped.
			'Div B' => 'not-an-array',             // non-array sectors dropped.
			'Div C' => array( 'X', '', 'Y' ),      // empty sector dropped.
		);

		$result = $this->invoke_controller( 'sanitize_dependent_groups', array( $raw ) );

		$this->assertSame(
			array(
				'Div A' => array( 'S1', 'S2' ),
				'Div C' => array( 'X', 'Y' ),
			),
			$result
		);
	}

	public function test_preserve_dependent_labels_keeps_existing_labels(): void {
		$existing = (object) array(
			'field_options' => json_encode(
				array(
					'groups'       => array( 'Old' => array( 'x' ) ),
					'parent_label' => 'Division',
					'child_label'  => 'Department',
				)
			),
		);
		$options  = array( 'groups' => array( 'New' => array( 'y' ) ) );

		$result = $this->invoke_controller( 'preserve_dependent_labels', array( $existing, $options ) );

		$this->assertSame( 'Division', $result['parent_label'] );
		$this->assertSame( 'Department', $result['child_label'] );
		$this->assertSame( array( 'New' => array( 'y' ) ), $result['groups'] );
	}

	public function test_preserve_dependent_labels_noop_without_groups(): void {
		$existing = (object) array( 'field_options' => json_encode( array( 'choices' => array( 'a' ) ) ) );
		$options  = array( 'choices' => array( 'b' ) );

		$result = $this->invoke_controller( 'preserve_dependent_labels', array( $existing, $options ) );

		$this->assertSame( $options, $result );
	}
}
