<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\ActivityLogQuery;

/**
 * Tests for ActivityLogQuery: query building, statistics caching,
 * log cleanup, run_cleanup settings-driven behaviour.
 */
class ActivityLogQueryTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var \Mockery\MockInterface */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'absint' )->alias( function ( $v ) {
			return abs( (int) $v );
		} );
		Functions\when( 'wp_parse_args' )->alias( function ( $args, $defaults ) {
			return array_merge( $defaults, $args );
		} );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_timezone' )->alias( static fn() => new \DateTimeZone( 'America/Sao_Paulo' ) );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
			if ( $key === 'ffc_settings' ) {
				return array( 'activity_log_retention_days' => 90 );
			}
			return $default;
		} );

		global $wpdb;
		$this->wpdb = Mockery::mock( 'wpdb' )->makePartial();
		$this->wpdb->prefix = 'wp_';
		$wpdb = $this->wpdb;
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ==================================================================
	// get_activities() — query building & JSON context decoding
	// ==================================================================

	public function test_get_activities_defaults(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SELECT ...' );
		$this->wpdb->shouldReceive( 'get_results' )
			->once()
			->andReturn( array() );

		$result = ActivityLogQuery::get_activities();
		$this->assertSame( array(), $result );
	}

	public function test_get_activities_decodes_json_context(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SELECT ...' );
		$this->wpdb->shouldReceive( 'get_results' )
			->andReturn( array(
				array( 'id' => 1, 'action' => 'test', 'context' => '{"key":"value"}' ),
			) );

		$result = ActivityLogQuery::get_activities();
		$this->assertSame( array( 'key' => 'value' ), $result[0]['context'] );
	}

	public function test_get_activities_decrypts_the_client_ip(): void {
		$ciphertext = \FreeFormCertificate\Core\Encryption::encrypt( '203.0.113.5' );
		$this->assertNotNull( $ciphertext, 'The test keys must encrypt, or this test proves nothing.' );

		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SELECT ...' );
		$this->wpdb->shouldReceive( 'get_results' )
			->andReturn( array(
				// New row: the address lives only in the ciphertext (#1574).
				array( 'id' => 1, 'action' => 'a', 'context' => '', 'user_ip' => null, 'user_ip_encrypted' => $ciphertext ),
				// Row written before the migration ran: plaintext still there.
				array( 'id' => 2, 'action' => 'a', 'context' => '', 'user_ip' => '198.51.100.7', 'user_ip_encrypted' => null ),
				// Unreadable ciphertext reads as no address, never as the blob.
				array( 'id' => 3, 'action' => 'a', 'context' => '', 'user_ip' => null, 'user_ip_encrypted' => 'v2:garbage' ),
				// No address at all.
				array( 'id' => 4, 'action' => 'a', 'context' => '' ),
			) );

		$result = ActivityLogQuery::get_activities();

		$this->assertSame( '203.0.113.5', $result[0]['user_ip'] );
		$this->assertSame( '198.51.100.7', $result[1]['user_ip'] );
		$this->assertNull( $result[2]['user_ip'] );
		$this->assertNull( $result[3]['user_ip'] );
	}

	public function test_get_activities_no_longer_orders_by_the_ip(): void {
		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->with( Mockery::on( static fn( $sql ) => is_string( $sql ) && false !== strpos( $sql, 'ORDER BY created_at' ) ), Mockery::any(), Mockery::any(), Mockery::any() )
			->andReturn( 'SELECT ...' );
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( array() );

		ActivityLogQuery::get_activities( array( 'orderby' => 'user_ip' ) );
	}

	public function test_get_activities_invalid_json_context_becomes_empty_array(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SELECT ...' );
		$this->wpdb->shouldReceive( 'get_results' )
			->andReturn( array(
				array( 'id' => 1, 'action' => 'test', 'context' => 'not json' ),
			) );

		$result = ActivityLogQuery::get_activities();
		$this->assertSame( array(), $result[0]['context'] );
	}

	public function test_get_activities_empty_context_becomes_empty_array(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SELECT ...' );
		$this->wpdb->shouldReceive( 'get_results' )
			->andReturn( array(
				array( 'id' => 1, 'action' => 'test', 'context' => '' ),
			) );

		$result = ActivityLogQuery::get_activities();
		$this->assertSame( array(), $result[0]['context'] );
	}

	public function test_get_activities_decrypts_when_only_ciphertext_present(): void {
		// Sensitive rows store the JSON in context_encrypted with a NULL
		// plaintext column. resolve_context() must restore the JSON on read.
		$payload   = array( 'email' => 'alice@example.com' );
		$encrypted = \FreeFormCertificate\Core\Encryption::encrypt( wp_json_encode( $payload ) );
		$this->assertNotNull( $encrypted, 'Sanity: Encryption must be configured by bootstrap.' );

		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SELECT ...' );
		$this->wpdb->shouldReceive( 'get_results' )
			->andReturn( array(
				array(
					'id'                => 1,
					'action'            => 'sensitive_event',
					'context'           => null,
					'context_encrypted' => $encrypted,
				),
			) );

		$result = ActivityLogQuery::get_activities();
		$this->assertSame( $payload, $result[0]['context'] );
	}

	public function test_get_activities_prefers_plaintext_when_both_columns_present(): void {
		// Defensive: pre-migration rows still have both columns populated.
		// The plaintext column wins to avoid an unnecessary decrypt and to
		// preserve byte-for-byte legacy behavior.
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SELECT ...' );
		$this->wpdb->shouldReceive( 'get_results' )
			->andReturn( array(
				array(
					'id'                => 1,
					'action'            => 'sensitive_event',
					'context'           => '{"plain":true}',
					'context_encrypted' => 'v2:irrelevant',
				),
			) );

		$result = ActivityLogQuery::get_activities();
		$this->assertSame( array( 'plain' => true ), $result[0]['context'] );
	}

	public function test_get_activities_with_level_filter(): void {
		$all_prepares = array();
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () use ( &$all_prepares ) {
			$all_prepares[] = func_get_args()[0];
			return 'PREPARED';
		} );
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( array() );

		ActivityLogQuery::get_activities( array( 'level' => 'error' ) );

		$found = false;
		foreach ( $all_prepares as $sql ) {
			if ( str_contains( $sql, 'level = %s' ) ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'Expected level filter in prepared queries' );
	}

	public function test_get_activities_with_search_filter(): void {
		$all_prepares = array();
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () use ( &$all_prepares ) {
			$all_prepares[] = func_get_args()[0];
			return 'PREPARED';
		} );
		$this->wpdb->shouldReceive( 'esc_like' )->with( 'test' )->andReturn( 'test' );
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( array() );

		ActivityLogQuery::get_activities( array( 'search' => 'test' ) );

		$found = false;
		foreach ( $all_prepares as $sql ) {
			if ( str_contains( $sql, 'action LIKE %s OR context LIKE %s' ) ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'Expected search filter in prepared queries' );
	}

	public function test_get_activities_invalid_orderby_defaults_to_created_at(): void {
		$prepared = null;
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () use ( &$prepared ) {
			$prepared = func_get_args()[0];
			return 'SELECT ...';
		} );
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( array() );

		ActivityLogQuery::get_activities( array( 'orderby' => 'DROP TABLE' ) );
		$this->assertStringContainsString( 'ORDER BY created_at', $prepared );
	}

	public function test_get_activities_valid_orderby_accepted(): void {
		$prepared = null;
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () use ( &$prepared ) {
			$prepared = func_get_args()[0];
			return 'SELECT ...';
		} );
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( array() );

		ActivityLogQuery::get_activities( array( 'orderby' => 'action' ) );
		$this->assertStringContainsString( 'ORDER BY action', $prepared );
	}

	public function test_get_activities_order_normalized_to_asc_desc(): void {
		$prepared = null;
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () use ( &$prepared ) {
			$prepared = func_get_args()[0];
			return 'SELECT ...';
		} );
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( array() );

		ActivityLogQuery::get_activities( array( 'order' => 'asc' ) );
		$this->assertStringContainsString( 'ASC', $prepared );
	}

	public function test_get_activities_invalid_order_defaults_to_desc(): void {
		$prepared = null;
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () use ( &$prepared ) {
			$prepared = func_get_args()[0];
			return 'SELECT ...';
		} );
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( array() );

		ActivityLogQuery::get_activities( array( 'order' => 'INVALID' ) );
		$this->assertStringContainsString( 'DESC', $prepared );
	}

	// ==================================================================
	// count_activities()
	// ==================================================================

	public function test_count_activities_returns_integer(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SELECT COUNT...' );
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( '42' );

		$this->assertSame( 42, ActivityLogQuery::count_activities() );
	}

	public function test_count_activities_with_filters(): void {
		$all_prepares = array();
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () use ( &$all_prepares ) {
			$all_prepares[] = func_get_args()[0];
			return 'PREPARED';
		} );
		$this->wpdb->shouldReceive( 'esc_like' )->andReturn( 'term' );
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( '5' );

		$count = ActivityLogQuery::count_activities( array(
			'level'     => 'warning',
			'action'    => 'login',
			'user_id'   => 10,
			'date_from' => '2030-01-01',
			'search'    => 'term',
		) );

		$this->assertSame( 5, $count );

		$all_sql = implode( ' ', $all_prepares );
		$this->assertStringContainsString( 'level = %s', $all_sql );
		$this->assertStringContainsString( 'action = %s', $all_sql );
		$this->assertStringContainsString( 'user_id = %d', $all_sql );
		$this->assertStringContainsString( 'created_at >= %d', $all_sql );
	}

	// ==================================================================
	// get_stats() — transient caching
	// ==================================================================

	public function test_stats_returns_cached_when_available(): void {
		$cached = array( 'total' => 100, 'by_level' => array() );
		Functions\when( 'get_transient' )->justReturn( $cached );

		$result = ActivityLogQuery::get_stats( 30 );
		$this->assertSame( 100, $result['total'] );
	}

	public function test_stats_queries_db_when_no_cache(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( '50' );
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( array() );

		$result = ActivityLogQuery::get_stats( 7 );
		$this->assertSame( 50, $result['total'] );
		$this->assertSame( 7, $result['period_days'] );
		$this->assertArrayHasKey( 'by_level', $result );
		$this->assertArrayHasKey( 'top_actions', $result );
		$this->assertArrayHasKey( 'top_users', $result );
	}

	// ==================================================================
	// Retention (#2340): bounded statements, a follow-up run when cut short
	// ==================================================================

	/**
	 * A `query()` that answers each DELETE with the next count in `$answers`,
	 * and records the statements.
	 *
	 * @param array<int, int>    $answers Rows each DELETE removes, in order.
	 * @param array<int, string> $ran     Filled with the statements.
	 */
	private function deletes( array $answers, array &$ran ): void {
		$this->wpdb->shouldReceive( 'query' )->andReturnUsing(
			static function ( $sql ) use ( &$answers, &$ran ) {
				$ran[] = (string) $sql;
				return array() === $answers ? 0 : array_shift( $answers );
			}
		);
	}

	public function test_cleanup_deletes_by_age_in_bounded_statements(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn( $sql, ...$args ) => $sql . ' | ' . implode( ',', $args ) );
		$ran = array();
		$this->deletes( array( ActivityLogQuery::CLEANUP_BATCH, 15 ), $ran );

		$result = ActivityLogQuery::cleanup( 90 );

		$this->assertSame(
			array(
				'deleted' => ActivityLogQuery::CLEANUP_BATCH + 15,
				'batches' => 2,
				'more'    => false,
			),
			$result
		);
		$this->assertCount( 2, $ran, 'A statement deleting less than its limit is the last.' );
		$this->assertStringStartsWith( 'DELETE FROM %i WHERE created_at < %d ORDER BY id LIMIT %d', $ran[0] );
		$this->assertStringEndsWith( ',' . ActivityLogQuery::CLEANUP_BATCH, $ran[0] );
	}

	public function test_cleanup_stops_at_its_budget_and_says_rows_remain(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'DELETE ...' );
		$ran = array();
		$this->deletes( array_fill( 0, 10, ActivityLogQuery::CLEANUP_BATCH ), $ran );

		$result = ActivityLogQuery::cleanup( 90, 3 );

		$this->assertSame( 3, $result['batches'] );
		$this->assertTrue( $result['more'] );
		$this->assertCount( 3, $ran );
	}

	public function test_run_cleanup_clears_the_stats_when_rows_left(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'DELETE ...' );
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( '0' );
		$ran = array();
		$this->deletes( array( 3 ), $ran );
		$cleared = array();
		Functions\when( 'delete_transient' )->alias(
			function ( $key ) use ( &$cleared ) {
				$cleared[] = $key;
				return true;
			}
		);

		$this->assertSame( 3, ActivityLogQuery::run_cleanup() );
		$this->assertSame( array( 'ffc_activity_stats_7', 'ffc_activity_stats_30', 'ffc_activity_stats_90' ), $cleared );
	}

	public function test_run_cleanup_cut_short_schedules_a_follow_up_run(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'DELETE ...' );
		$ran = array();
		$this->deletes( array_fill( 0, ActivityLogQuery::CLEANUP_BATCHES_PER_RUN, ActivityLogQuery::CLEANUP_BATCH ), $ran );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\expect( 'wp_schedule_single_event' )->once()->with( \Mockery::type( 'int' ), ActivityLogQuery::CLEANUP_CONTINUE_HOOK );
		$this->wpdb->shouldNotReceive( 'get_var' );

		ActivityLogQuery::run_cleanup();

		$this->assertCount( ActivityLogQuery::CLEANUP_BATCHES_PER_RUN, $ran, 'The record cap waits for the next run once the budget is spent.' );
	}

	public function test_run_cleanup_that_finishes_schedules_nothing(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'DELETE ...' );
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( '0' );
		$ran = array();
		$this->deletes( array( 10 ), $ran );
		Functions\expect( 'wp_schedule_single_event' )->never();

		$this->assertSame( 10, ActivityLogQuery::run_cleanup() );
	}

	public function test_run_cleanup_zero_retention_skips(): void {
		Functions\when( 'get_option' )->justReturn( array( 'activity_log_retention_days' => 0 ) );
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SELECT COUNT(*)' );
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( '0' );
		$this->wpdb->shouldNotReceive( 'query' );

		$this->assertSame( 0, ActivityLogQuery::run_cleanup() );
	}

	/**
	 * The record cap (#2338): rows beyond it go, oldest first, the way the
	 * rate-limit logs are trimmed -- in bounded statements (#2340).
	 */
	public function test_trim_to_deletes_the_oldest_rows_beyond_the_cap(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn( $sql, ...$args ) => $sql . ' | ' . implode( ',', $args ) );
		$this->wpdb->shouldReceive( 'get_var' )->with( \Mockery::pattern( '/^SELECT COUNT/' ) )->andReturn( '1500' );
		$this->wpdb->shouldReceive( 'get_var' )->with( \Mockery::pattern( '/OFFSET %d \| wp_ffc_activity_log,1000$/' ) )->andReturn( '700' );
		$this->wpdb->shouldReceive( 'query' )->once()->with( 'DELETE FROM %i WHERE id <= %d ORDER BY id LIMIT %d | wp_ffc_activity_log,700,' . ActivityLogQuery::CLEANUP_BATCH )->andReturn( 500 );

		$this->assertSame( 500, ActivityLogQuery::trim_to( 1000 )['deleted'] );
	}

	public function test_trim_to_deletes_nothing_under_the_cap(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SELECT COUNT(*)' );
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( '800' );
		$this->wpdb->shouldNotReceive( 'query' );

		$this->assertSame( 0, ActivityLogQuery::trim_to( 1000 )['deleted'] );
	}

	public function test_run_cleanup_trims_to_the_record_cap_even_when_kept_indefinitely(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'activity_log_retention_days' => 0,
				'activity_log_max_records'    => 1000,
			)
		);
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn( $sql, ...$args ) => $sql . ' | ' . implode( ',', $args ) );
		$this->wpdb->shouldReceive( 'get_var' )->with( \Mockery::pattern( '/^SELECT COUNT/' ) )->andReturn( '1001' );
		$this->wpdb->shouldReceive( 'get_var' )->with( \Mockery::pattern( '/OFFSET/' ) )->andReturn( '1' );
		$this->wpdb->shouldReceive( 'query' )->once()->with( \Mockery::pattern( '/^DELETE FROM %i WHERE id <= %d ORDER BY id LIMIT %d/' ) )->andReturn( 1 );

		$this->assertSame( 1, ActivityLogQuery::run_cleanup() );
	}

	public function test_run_cleanup_missing_setting_defaults_to_90(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn( $sql, ...$args ) => $sql . ' | ' . implode( ',', $args ) );
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( '0' );
		$ran = array();
		$this->deletes( array( 5 ), $ran );
		$before = time() - 90 * DAY_IN_SECONDS;

		$this->assertSame( 5, ActivityLogQuery::run_cleanup() );
		$cutoff = (int) explode( ',', $ran[0] )[1];
		$this->assertGreaterThanOrEqual( $before, $cutoff );
		$this->assertLessThanOrEqual( time() - 90 * DAY_IN_SECONDS, $cutoff );
	}

	// ------------------------------------------------------------------
	// action_in filter — issue #331 history service consumer
	// ------------------------------------------------------------------

	public function test_get_activities_accepts_action_in_array(): void {
		$captured = null;
		$this->wpdb->shouldReceive( 'prepare' )
			->andReturnUsing(
				function ( $sql ) use ( &$captured ) {
					if ( null === $captured && str_contains( $sql, 'action IN' ) ) {
						$captured = $sql;
					}
					return $sql;
				}
			);
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( array() );

		ActivityLogQuery::get_activities(
			array( 'action_in' => array( 'recruitment_call_created', 'recruitment_call_cancelled' ) )
		);

		$this->assertNotNull( $captured );
		$this->assertStringContainsString( 'action IN', (string) $captured );
	}

	public function test_get_activities_ignores_empty_action_in(): void {
		$captured_sqls = array();
		$this->wpdb->shouldReceive( 'prepare' )
			->andReturnUsing(
				function ( $sql ) use ( &$captured_sqls ) {
					$captured_sqls[] = $sql;
					return $sql;
				}
			);
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( array() );

		ActivityLogQuery::get_activities( array( 'action_in' => array() ) );

		$combined = implode( ' ', $captured_sqls );
		$this->assertStringNotContainsString( 'action IN', $combined );
	}

	// ------------------------------------------------------------------
	// distinct_actions() — admin filter dropdown delegate
	// ------------------------------------------------------------------

	public function test_distinct_actions_returns_string_list(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn ( $sql ) => $sql );
		$this->wpdb->shouldReceive( 'get_col' )->once()->andReturn( array( 'recruitment_call_created', 'submission_received' ) );

		$actions = ActivityLogQuery::distinct_actions();

		$this->assertSame( array( 'recruitment_call_created', 'submission_received' ), $actions );
	}

	public function test_distinct_actions_returns_empty_array_when_no_rows(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn ( $sql ) => $sql );
		$this->wpdb->shouldReceive( 'get_col' )->once()->andReturn( array() );

		$this->assertSame( array(), ActivityLogQuery::distinct_actions() );
	}

	/**
	 * A cache hit does not query the database (#1234).
	 *
	 * This is the assertion that carries the fix. The filter `<select>` of the
	 * Activity Log screen is redrawn on every render, and this is the fastest
	 * growing table in the plugin -- the `DISTINCT` uses the `KEY action` index,
	 * so it walks the INDEX and not the table, but it still walks all of it to
	 * produce a few dozen values.
	 *
	 * `shouldNotReceive` is what pins this: comparing the returned value would
	 * not be enough, because the uncached read would return the same list.
	 */
	public function test_distinct_actions_cache_hit_never_touches_the_database(): void {
		Functions\when( 'get_transient' )->justReturn( array( 'cached_action' ) );
		$this->wpdb->shouldNotReceive( 'get_col' );

		$this->assertSame( array( 'cached_action' ), ActivityLogQuery::distinct_actions() );
	}

	/**
	 * The computed result is written to the transient.
	 *
	 * The pair of the assertion above: without the write, every render would be
	 * a miss and the cache would exist only on the read path.
	 */
	public function test_distinct_actions_stores_what_it_computed(): void {
		$stored = null;
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value ) use ( &$stored ) {
				$stored = $value;
				return true;
			}
		);
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn ( $sql ) => $sql );
		$this->wpdb->shouldReceive( 'get_col' )->once()->andReturn( array( 'a', 'b' ) );

		ActivityLogQuery::distinct_actions();

		$this->assertSame( array( 'a', 'b' ), $stored );
	}

	// ------------------------------------------------------------------
	// redact_user_id() — privacy / user-cleanup delegate
	// ------------------------------------------------------------------

	public function test_redact_user_id_short_circuits_on_non_positive(): void {
		$this->wpdb->shouldNotReceive( 'query' );

		$this->assertSame( 0, ActivityLogQuery::redact_user_id( 0 ) );
		$this->assertSame( 0, ActivityLogQuery::redact_user_id( -5 ) );
	}

	public function test_redact_user_id_returns_zero_when_table_missing(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn ( $sql ) => $sql );
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( null );
		$this->wpdb->shouldNotReceive( 'query' );

		$this->assertSame( 0, ActivityLogQuery::redact_user_id( 42 ) );
	}

	public function test_redact_user_id_runs_update_when_table_present(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn ( $sql ) => $sql );
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( 'wp_ffc_activity_log' );
		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( 3 );

		$this->assertSame( 3, ActivityLogQuery::redact_user_id( 42 ) );
	}

	// ==================================================================
	// Remaining filters, the export cursor and the submission audit trail
	// ==================================================================

	/**
	 * Record every prepare() call as "sql | arg, arg".
	 *
	 * @return \ArrayObject<int, string>
	 */
	private function record_prepares(): \ArrayObject {
		$prepared = new \ArrayObject();
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( $sql, ...$args ) use ( $prepared ) {
				$flat       = is_array( $args[0] ?? null ) ? $args[0] : $args;
				$prepared[] = $sql . ' | ' . implode( ', ', array_map( 'strval', $flat ) );
				return $sql;
			}
		);
		return $prepared;
	}

	public function test_get_activities_filters_by_action_user_and_date_range(): void {
		$prepared = $this->record_prepares();
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( array() );

		ActivityLogQuery::get_activities(
			array(
				'action'    => 'submission_created',
				'user_id'   => '-7',
				'date_from' => '2026-01-01',
				'date_to'   => '2026-01-31',
			)
		);

		$this->assertContains( 'action = %s | submission_created', (array) $prepared );
		$this->assertContains( 'user_id = %d | 7', (array) $prepared );
		// A date is read in the site's timezone (UTC-3): midnight there is 03:00 UTC,
		// and the last day counts to its last second (#2340).
		$this->assertContains( 'created_at >= %d | 1767236400', (array) $prepared );
		$this->assertContains( 'created_at <= %d | 1769914799', (array) $prepared );
	}

	public function test_find_by_cursor_filters_and_pages_by_id(): void {
		$prepared = $this->record_prepares();
		$this->wpdb->shouldReceive( 'esc_like' )->andReturnUsing( static fn( $s ) => $s );
		$sql = null;
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturnUsing(
			static function ( $query ) use ( &$sql ) {
				$sql = $query;
				return array(
					array(
						'id'      => 9,
						'action'  => 'login',
						'context' => '{"k":1}',
						'user_ip' => '198.51.100.4',
					),
				);
			}
		);

		$rows = ActivityLogQuery::find_by_cursor(
			array(
				'level'  => 'warning',
				'action' => 'login',
				'search' => 'abc',
			),
			100,
			25
		);

		$this->assertContains( 'level = %s | warning', (array) $prepared );
		$this->assertContains( 'action = %s | login', (array) $prepared );
		$this->assertContains( '(action LIKE %s OR context LIKE %s) | %abc%, %abc%', (array) $prepared );
		$this->assertContains( 'SELECT * FROM %i WHERE {where} AND id < %d ORDER BY id DESC LIMIT %d | wp_ffc_activity_log, 100, 25', str_replace( '1=1 AND level = %s AND action = %s AND (action LIKE %s OR context LIKE %s)', '{where}', (array) $prepared ) );
		$this->assertSame( array( 'k' => 1 ), $rows[0]['context'] );
		$this->assertSame( '198.51.100.4', $rows[0]['user_ip'], 'a legacy plaintext IP still reads' );
	}

	public function test_find_by_cursor_returns_nothing_when_the_query_fails(): void {
		$this->record_prepares();
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( null );

		$this->assertSame( array(), ActivityLogQuery::find_by_cursor( array(), PHP_INT_MAX, 50 ) );
	}

	public function test_distinct_actions_returns_empty_when_the_query_fails(): void {
		$this->record_prepares();
		$this->wpdb->shouldReceive( 'get_col' )->once()->andReturn( null );

		$this->assertSame( array(), ActivityLogQuery::distinct_actions() );
	}

	public function test_get_submission_logs_is_empty_without_the_submission_id_column(): void {
		\FreeFormCertificate\Core\ActivityLog::clear_column_cache();
		$this->record_prepares();
		$this->wpdb->shouldReceive( 'get_col' )->once()->andReturn( array( 'id', 'action' ) );
		$this->wpdb->shouldNotReceive( 'get_results' );

		$logs = ActivityLogQuery::get_submission_logs( 5 );
		\FreeFormCertificate\Core\ActivityLog::clear_column_cache();

		$this->assertSame( array(), $logs );
	}

	public function test_get_submission_logs_decrypts_context_and_ip(): void {
		\FreeFormCertificate\Core\ActivityLog::clear_column_cache();
		$context = \FreeFormCertificate\Core\Encryption::encrypt( '{"field":"value"}' );
		$ip      = \FreeFormCertificate\Core\Encryption::encrypt( '203.0.113.5' );
		$this->assertNotNull( $context, 'The test keys must encrypt, or this test proves nothing.' );
		$prepared = $this->record_prepares();
		$this->wpdb->shouldReceive( 'get_col' )->once()->andReturn( array( 'id', 'submission_id' ) );
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn(
			array(
				array(
					'id'                => 1,
					'context'           => '',
					'context_encrypted' => $context,
					'user_ip_encrypted' => $ip,
				),
				array(
					'id'                => 2,
					'context'           => '{"kept":true}',
					'context_encrypted' => $context,
					'user_ip'           => '',
				),
				array(
					'id'                => 3,
					'context'           => '',
					'context_encrypted' => 'not-a-ciphertext',
					'user_ip_encrypted' => 'not-a-ciphertext',
				),
			)
		);

		$logs = ActivityLogQuery::get_submission_logs( 5, 10 );
		\FreeFormCertificate\Core\ActivityLog::clear_column_cache();

		$this->assertContains( 'SELECT * FROM %i WHERE submission_id = %d ORDER BY created_at DESC LIMIT %d | wp_ffc_activity_log, 5, 10', (array) $prepared );
		$this->assertSame( '{"field":"value"}', $logs[0]['context'] );
		$this->assertSame( '{"field":"value"}', $logs[0]['context_decrypted'] );
		$this->assertSame( '203.0.113.5', $logs[0]['user_ip'] );
		$this->assertSame( '{"kept":true}', $logs[1]['context'], 'a plaintext context is not overwritten' );
		$this->assertNull( $logs[1]['user_ip'] );
		$this->assertArrayNotHasKey( 'context_decrypted', $logs[2], 'an undecryptable context is left alone' );
		$this->assertNull( $logs[2]['user_ip'], 'an undecryptable IP reads as null, never as the ciphertext' );
	}

	public function test_ip_of_reads_one_entrys_address_for_a_reveal(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->andReturn(
			array( 'id' => 5, 'user_ip' => null, 'user_ip_encrypted' => \FreeFormCertificate\Core\Encryption::encrypt( '2804:14c::9' ) ),
			array( 'id' => 6, 'user_ip' => '203.0.113.5', 'user_ip_encrypted' => null ),
			null
		);

		$this->assertSame( '2804:14c::9', ActivityLogQuery::ip_of( 5 ), 'the encrypted address, decrypted' );
		$this->assertSame( '203.0.113.5', ActivityLogQuery::ip_of( 6 ), 'a row older than the encryption' );
		$this->assertSame( '', ActivityLogQuery::ip_of( 7 ), 'a missing entry' );
		$this->assertSame( '', ActivityLogQuery::ip_of( 0 ), 'no id, no query' );
	}

	public function test_entry_of_reads_an_entrys_address_and_details_once(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn(
			array(
				'id'                => 5,
				'user_ip'           => '203.0.113.5',
				'user_ip_encrypted' => null,
				'context'           => '{"email":"ana@example.org"}',
			)
		);

		$this->assertSame(
			array(
				'user_ip' => '203.0.113.5',
				'context' => array( 'email' => 'ana@example.org' ),
			),
			ActivityLogQuery::entry_of( 5 )
		);
		$this->assertNull( ActivityLogQuery::entry_of( 0 ), 'no id, no query' );
	}
}
