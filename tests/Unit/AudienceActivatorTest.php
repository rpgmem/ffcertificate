<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Audience\AudienceActivator;

/**
 * Tests for AudienceActivator: table creation, capabilities registration,
 * migration, table status reporting, and drop operations.
 *
 * @covers \FreeFormCertificate\Audience\AudienceActivator
 */
class AudienceActivatorTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		global $wpdb;
		$wpdb = Mockery::mock( 'wpdb' )->makePartial();
		$wpdb->prefix = 'wp_';
		$wpdb->last_error = '';
		$this->wpdb = $wpdb;

		// Default wpdb stubs
		$this->wpdb->shouldReceive( 'get_charset_collate' )
			->andReturn( 'DEFAULT CHARSET utf8mb4' )
			->byDefault();
		$this->wpdb->shouldReceive( 'prepare' )
			->andReturnUsing( function () {
				$args = func_get_args();
				$sql  = $args[0];
				for ( $i = 1; $i < count( $args ); $i++ ) {
					$val = is_string( $args[ $i ] ) ? "'{$args[$i]}'" : $args[ $i ];
					$sql = preg_replace( '/%[sidf]/', $val, $sql, 1 );
				}
				return $sql;
			} )
			->byDefault();
		// Default: all tables exist — return the queried table name
		$this->wpdb->shouldReceive( 'get_var' )
			->andReturnUsing( function ( $query ) {
				if ( preg_match( "/SHOW TABLES LIKE\s+'([^']+)'/", $query, $m ) ) {
					return $m[1];
				}
				return null;
			} )
			->byDefault();
		$this->wpdb->shouldReceive( 'get_results' )
			->andReturn( [] )
			->byDefault();
		$this->wpdb->shouldReceive( 'query' )
			->andReturn( 1 )
			->byDefault();

		// Default WP function stubs
		Functions\when( 'dbDelta' )->justReturn( [] );
		Functions\when( 'get_role' )->justReturn( null );

		// This activator's chain became guarded by `FFC_VERSION` (#1231), so it
		// reads and writes a version option. The value returned here is
		// DIFFERENT from `FFC_VERSION` on purpose: these tests exercise the
		// chain's body, which only runs when the guard does not match.
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( 'update_option' )->justReturn( true );

		// `get_role()` resolved here only because some OTHER test in the process
		// had stubbed it first — the order-dependence CLAUDE.md warns about, and
		// it bit as soon as `maybe_migrate()` grew a `get_role( 'subscriber' )`
		// call (#1302). Pinned, so the branch taken is the one this file
		// decides; individual tests override it with their own alias.
		Functions\when( 'get_role' )->justReturn( null );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ==================================================================
	// create_tables() — schedules table
	// ==================================================================

	public function test_create_tables_creates_schedules_table(): void {
		$this->wpdb->shouldReceive( 'get_var' )
			->andReturnUsing( function ( $query ) {
				if ( stripos( $query, 'SHOW TABLES LIKE' ) !== false
					&& stripos( $query, 'ffc_audience_schedules' ) !== false
				) {
					return null;
				}
				if ( stripos( $query, 'SHOW TABLES LIKE' ) !== false ) {
					return preg_match( "/SHOW TABLES LIKE\s+'([^']+)'/", $query, $tm ) ? $tm[1] : 'existing_table';
				}
				return null;
			} );

		$delta_sqls = [];
		Functions\when( 'dbDelta' )->alias( function ( $sql ) use ( &$delta_sqls ) {
			$delta_sqls[] = $sql;
		} );

		AudienceActivator::create_tables();

		$schedules_delta = array_filter( $delta_sqls, function ( $sql ) {
			return stripos( $sql, 'ffc_audience_schedules' ) !== false;
		} );

		$this->assertNotEmpty( $schedules_delta, 'dbDelta should be called with schedules table SQL' );
		$found_sql = reset( $schedules_delta );
		$this->assertStringContainsString( 'CREATE TABLE', $found_sql );
		$this->assertStringContainsString( 'visibility', $found_sql );
	}

	// ==================================================================
	// create_tables() — all 9 tables
	// ==================================================================

	public function test_create_tables_creates_all_tables_when_none_exist(): void {
		// No tables exist
		$this->wpdb->shouldReceive( 'get_var' )
			->andReturnUsing( function ( $query ) {
				if ( stripos( $query, 'SHOW TABLES LIKE' ) !== false ) {
					return null;
				}
				return null;
			} );

		$delta_count = 0;
		Functions\when( 'dbDelta' )->alias( function () use ( &$delta_count ) {
			$delta_count++;
		} );

		AudienceActivator::create_tables();

		$this->assertSame( 9, $delta_count, 'dbDelta should be called 9 times for 9 audience tables' );
	}

	// ==================================================================
	// create_tables() — skips existing tables
	// ==================================================================

	public function test_create_tables_skips_existing_tables(): void {
		// Default setup: all tables exist

		$delta_called = false;
		Functions\when( 'dbDelta' )->alias( function () use ( &$delta_called ) {
			$delta_called = true;
		} );

		AudienceActivator::create_tables();

		$this->assertFalse( $delta_called, 'dbDelta should not be called when all tables already exist' );
	}

	// ==================================================================
	// register_capabilities() — ffc_end_user role
	// ==================================================================

	public function test_register_capabilities_adds_to_ffc_user_role(): void {
		$ffc_user_role = new \WP_Role();

		Functions\when( 'get_role' )->alias( function ( $role ) use ( $ffc_user_role ) {
			if ( $role === 'ffc_end_user' ) {
				return $ffc_user_role;
			}
			return null;
		} );

		AudienceActivator::register_capabilities();

		$this->assertArrayHasKey( 'ffc_view_own_audience_bookings', $ffc_user_role->capabilities );
		$this->assertTrue( $ffc_user_role->capabilities['ffc_view_own_audience_bookings'] );
	}

	// ==================================================================
	// register_capabilities() — never WordPress's own roles
	// ==================================================================

	/**
	 * The inversion of the test this replaces (#1302).
	 *
	 * It used to assert that `subscriber` RECEIVES `ffc_view_own_audience_bookings`
	 * — which was true, and was the defect: an FFC capability on a role
	 * WordPress owns, on every install from activation, reaching every
	 * subscriber whether or not they belong to any audience.
	 *
	 * What it was covering is that only the self-join route granted the
	 * capability; the admin-side paths did not.
	 * `AudienceWriter::add_member()` now grants it at the single point all of
	 * them pass through, so membership carries it.
	 */
	public function test_register_capabilities_never_touches_a_wordpress_role(): void {
		$roles = array(
			'subscriber'    => new \WP_Role(),
			'editor'        => new \WP_Role(),
			'administrator' => new \WP_Role(),
			'ffc_end_user'  => new \WP_Role(),
		);

		Functions\when( 'get_role' )->alias(
			function ( $role ) use ( $roles ) {
				return $roles[ $role ] ?? null;
			}
		);

		AudienceActivator::register_capabilities();

		foreach ( array( 'subscriber', 'editor', 'administrator' ) as $core_role ) {
			$this->assertSame(
				array(),
				$roles[ $core_role ]->capabilities,
				sprintf( '%s belongs to WordPress; an ffc_ capability there outlives any reason for it.', $core_role )
			);
		}

		// The FFC role still gets it — that is what the role is for, and it is
		// what makes `grant_audience_capabilities()` a no-op for FFC users
		// rather than a redundant per-user grant.
		$this->assertTrue( $roles['ffc_end_user']->capabilities['ffc_view_own_audience_bookings'] );
	}

	// ==================================================================
	// register_capabilities() — handles missing roles
	// ==================================================================

	public function test_register_capabilities_handles_missing_roles(): void {
		// Every managed role is absent on this site. The guard must hold, and
		// the routine must still have consulted each role it manages — an empty
		// consult list would mean the grant silently stopped happening.
		$consulted = array();
		Functions\when( 'get_role' )->alias(
			static function ( $slug ) use ( &$consulted ) {
				$consulted[] = (string) $slug;
				return null;
			}
		);

		AudienceActivator::register_capabilities();

		$this->assertSame(
			array( 'ffc_end_user' ),
			$consulted,
			'The booking cap is granted to exactly one role, and it is ours (#1302).'
		);
	}

	// ==================================================================
	// drop_tables()
	// ==================================================================

	public function test_drop_tables_drops_all_nine(): void {
		$prepared_queries = [];
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () use ( &$prepared_queries ) {
			$args = func_get_args();
			$prepared_queries[] = $args;
			return 'PREPARED_QUERY';
		} );
		$this->wpdb->shouldReceive( 'query' )->with( 'PREPARED_QUERY' )->andReturn( 1 );

		AudienceActivator::drop_tables();

		$drop_queries = array_filter( $prepared_queries, function ( $args ) {
			return stripos( $args[0], 'DROP TABLE IF EXISTS' ) !== false;
		} );

		$this->assertCount( 9, $drop_queries, 'Should prepare DROP TABLE for all 9 audience tables' );
	}

	// ==================================================================
	// get_tables_status() — all exist
	// ==================================================================

	public function test_get_tables_status_all_exist(): void {
		$call_count = 0;
		$this->wpdb->shouldReceive( 'get_var' )
			->andReturnUsing( function ( $query ) use ( &$call_count ) {
				$call_count++;
				if ( stripos( $query, 'SHOW TABLES LIKE' ) !== false ) {
					return preg_match( "/SHOW TABLES LIKE\s+'([^']+)'/", $query, $tm ) ? $tm[1] : 'existing_table';
				}
				// SELECT COUNT(*) queries — return a count
				if ( stripos( $query, 'SELECT COUNT' ) !== false ) {
					return '5';
				}
				return null;
			} );

		$status = AudienceActivator::get_tables_status();

		$this->assertCount( 9, $status, 'Should return status for 9 tables' );

		foreach ( $status as $key => $info ) {
			$this->assertTrue( $info['exists'], "Table '$key' should be reported as existing" );
			$this->assertSame( 5, $info['count'], "Table '$key' should have count 5" );
			$this->assertArrayHasKey( 'table', $info );
		}

		$this->assertArrayHasKey( 'schedules', $status );
		$this->assertArrayHasKey( 'audiences', $status );
		$this->assertArrayHasKey( 'bookings', $status );
	}

	// ==================================================================
	// get_tables_status() — none exist
	// ==================================================================

	public function test_get_tables_status_none_exist(): void {
		$this->wpdb->shouldReceive( 'get_var' )
			->andReturnUsing( function ( $query ) {
				if ( stripos( $query, 'SHOW TABLES LIKE' ) !== false ) {
					return null;
				}
				return null;
			} );

		$status = AudienceActivator::get_tables_status();

		$this->assertCount( 9, $status, 'Should return status for 9 tables' );

		foreach ( $status as $key => $info ) {
			$this->assertFalse( $info['exists'], "Table '$key' should be reported as not existing" );
			$this->assertSame( 0, $info['count'], "Table '$key' should have count 0 when not existing" );
		}
	}

	// ==================================================================
	// maybe_migrate() — runs when tables exist
	// ==================================================================

	public function test_maybe_migrate_runs_migrations_when_table_exists(): void {
		// Schedules table exists, audiences table exists
		$this->wpdb->shouldReceive( 'get_var' )
			->andReturnUsing( function ( $query ) {
				if ( stripos( $query, 'SHOW TABLES LIKE' ) !== false ) {
					return preg_match( "/SHOW TABLES LIKE\s+'([^']+)'/", $query, $tm ) ? $tm[1] : 'existing_table';
				}
				return null;
			} );

		// column_exists returns empty (columns missing) to trigger migration
		$this->wpdb->shouldReceive( 'get_results' )
			->andReturn( [] );

		$query_calls = [];
		$this->wpdb->shouldReceive( 'query' )
			->andReturnUsing( function ( $query ) use ( &$query_calls ) {
				$query_calls[] = $query;
				return 1;
			} );

		// The email-token migration (#653) runs from maybe_migrate(); mark it
		// already done so it short-circuits and this test stays focused on the
		// schema ALTERs.
		Functions\when( 'get_option' )->justReturn( 1 );

		AudienceActivator::maybe_migrate();

		// Should have issued ALTER TABLE queries for column additions
		$alter_queries = array_filter( $query_calls, function ( $q ) {
			return stripos( $q, 'ALTER TABLE' ) !== false;
		} );

		$this->assertNotEmpty( $alter_queries, 'Migration should execute ALTER TABLE queries when tables exist' );
	}

	// ==================================================================
	// maybe_migrate() — skips when table missing
	// ==================================================================

	public function test_maybe_migrate_skips_when_table_missing(): void {
		// No tables exist
		$this->wpdb->shouldReceive( 'get_var' )
			->andReturnUsing( function ( $query ) {
				if ( stripos( $query, 'SHOW TABLES LIKE' ) !== false ) {
					return null;
				}
				return null;
			} );

		$query_called = false;
		$this->wpdb->shouldReceive( 'query' )
			->andReturnUsing( function () use ( &$query_called ) {
				$query_called = true;
				return 1;
			} );

		AudienceActivator::maybe_migrate();

		$this->assertFalse( $query_called, 'No ALTER TABLE queries should run when tables do not exist' );
	}

	// ==================================================================
	// create_tables() — calls register_capabilities
	// ==================================================================

	public function test_create_tables_calls_register_capabilities(): void {
		// All tables exist (skip creation), but register_capabilities should still be called
		$ffc_user_role = new \WP_Role();
		$subscriber_role = new \WP_Role();

		Functions\when( 'get_role' )->alias( function ( $role ) use ( $ffc_user_role, $subscriber_role ) {
			if ( $role === 'ffc_end_user' ) {
				return $ffc_user_role;
			}
			if ( $role === 'subscriber' ) {
				return $subscriber_role;
			}
			return null;
		} );

		AudienceActivator::create_tables();

		// Verify capabilities were added by register_capabilities (called from create_tables)
		$this->assertArrayHasKey(
			'ffc_view_own_audience_bookings',
			$ffc_user_role->capabilities,
			'create_tables() should call register_capabilities() which adds cap to ffc_end_user'
		);
		$this->assertSame(
			array(),
			$subscriber_role->capabilities,
			'create_tables() must not put an FFC capability on a WordPress role (#1302).'
		);
	}

	// ==================================================================
	// maybe_migrate() — moving the capability off `subscriber` (#1302)
	// ==================================================================

	/**
	 * **The order is forced, and it is the opposite of the intuitive one.**
	 *
	 * `CapabilityManager::grant_audience_capabilities()` skips a user who
	 * already `has_cap()`, and a capability inherited from a role answers that
	 * question yes. So seeding the members while `subscriber` still carries
	 * `ffc_view_own_audience_bookings` grants NOTHING to the very users the
	 * migration exists for — they would lose the capability with the role and
	 * never receive it personally.
	 *
	 * The observable is cheap: `grant_audience_capabilities()` opens with
	 * `get_userdata()`, so the first of those calls marks the start of the
	 * back-fill. The strip must come before it.
	 */
	public function test_the_subscriber_grant_is_stripped_before_members_are_seeded(): void {
		$sequence = array();

		$subscriber_role = \Mockery::mock( '\WP_Role' );
		$subscriber_role->shouldReceive( 'remove_cap' )
			->with( 'ffc_view_own_audience_bookings' )
			->once()
			->andReturnUsing(
				static function () use ( &$sequence ) {
					$sequence[] = 'strip';
				}
			);

		Functions\when( 'get_role' )->alias(
			static function ( $slug ) use ( $subscriber_role ) {
				return 'subscriber' === $slug ? $subscriber_role : null;
			}
		);
		Functions\when( 'get_userdata' )->alias(
			static function ( $user_id ) use ( &$sequence ) {
				$sequence[] = 'grant:' . (int) $user_id;
				return false;
			}
		);
		// The email-token migration (#653) also runs from `maybe_migrate()`;
		// mark it done so this test stays on the capability move. The schema
		// gate must still read empty, or nothing runs at all.
		Functions\when( 'get_option' )->alias(
			static function ( $name ) {
				return 'ffc_audience_email_tokens_migrated_v1' === $name ? '1' : '';
			}
		);

		$this->wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			static function ( $query ) {
				return preg_match( "/SHOW TABLES LIKE\s+'([^']+)'/", (string) $query, $m ) ? $m[1] : null;
			}
		);
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( array() );
		$this->wpdb->shouldReceive( 'query' )->andReturn( 1 );
		$this->wpdb->shouldReceive( 'get_col' )->andReturn( array( 11, 22 ) );

		AudienceActivator::maybe_migrate();

		$this->assertSame(
			array( 'strip', 'grant:11', 'grant:22' ),
			$sequence,
			'Seeding before the strip grants nothing: has_cap() still answers yes through the role.'
		);
	}

	/**
	 * One-shot: the second run must not re-strip or re-walk.
	 *
	 * Without the flag this walks every audience member on every upgrade, and
	 * worse, re-strips a capability an operator may have deliberately restored.
	 */
	public function test_the_capability_move_runs_only_once(): void {
		$subscriber_role = \Mockery::mock( '\WP_Role' );
		$subscriber_role->shouldNotReceive( 'remove_cap' );

		Functions\when( 'get_role' )->alias(
			static function ( $slug ) use ( $subscriber_role ) {
				return 'subscriber' === $slug ? $subscriber_role : null;
			}
		);
		Functions\when( 'get_option' )->alias(
			static function ( $name ) {
				// The move's own flag reads as done; the schema gate must still
				// let `maybe_migrate()` through, or this proves nothing.
				return in_array(
					$name,
					array( 'ffc_audience_cap_off_subscriber_v1', 'ffc_audience_email_tokens_migrated_v1' ),
					true
				) ? '1' : '';
			}
		);
		Functions\expect( 'get_userdata' )->never();

		$this->wpdb->shouldReceive( 'get_var' )->andReturn( null );
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( array() );
		$this->wpdb->shouldReceive( 'query' )->andReturn( 1 );
		$this->wpdb->shouldReceive( 'get_col' )->andReturn( array( 11 ) );

		AudienceActivator::maybe_migrate();
	}
}
