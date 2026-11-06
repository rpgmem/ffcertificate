<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Core\ActivityLogInstants;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * The activity log's creation times move to instants (#2340): one bounded
 * statement per stretch of the site timezone, under a lock, never on the
 * front end.
 *
 * @covers \FreeFormCertificate\Core\ActivityLogInstants
 */
class ActivityLogInstantsTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var mixed */
	private $wpdb_backup;

	/** @var array<int, string> Statements `query()` received. */
	private array $ran = array();

	/** @var array<string, string|null> Column => its type, null when absent. */
	private array $columns = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->ran         = array();
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		Functions\when( 'wp_timezone' )->alias( static fn() => new \DateTimeZone( 'America/Sao_Paulo' ) );
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->wpdb_backup;
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A `$wpdb` whose table has the given columns, and that answers each
	 * conversion statement with the next number of rows in `$affected`.
	 *
	 * @param array<string, string|null> $columns  Column => type, null when absent.
	 * @param array<int, int>            $affected Rows each UPDATE converts, in order.
	 * @return void
	 */
	private function table( array $columns, array $affected = array() ): void {
		$this->columns = $columns;
		$wpdb          = Mockery::mock( 'wpdb' );
		$wpdb->prefix  = 'wp_';
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing( static fn( $s ) => $s );
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( $sql, ...$args ): string {
				$args = is_array( $args[0] ?? null ) ? $args[0] : $args;
				return vsprintf( str_replace( array( '%i', '%d' ), array( '%s', '%s' ), (string) $sql ), array_map( 'strval', $args ) );
			}
		);
		$wpdb->shouldReceive( 'get_var' )->andReturn( 'wp_ffc_activity_log' );
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $sql ) {
				if ( 1 === preg_match( '/^SHOW COLUMNS FROM \S+ LIKE (\S+)$/', (string) $sql, $m ) ) {
					$type = $this->columns[ $m[1] ] ?? null;
					return null === $type ? array() : array(
						(object) array(
							'Field' => $m[1],
							'Type'  => $type,
						),
					);
				}
				return array();
			}
		);
		$wpdb->shouldReceive( 'get_row' )->andReturn(
			array(
				'first_at' => '2017-10-01 10:00:00',
				'last_at'  => '2018-03-01 10:00:00',
			)
		);
		$wpdb->shouldReceive( 'query' )->andReturnUsing(
			function ( $sql ) use ( &$affected ) {
				$this->ran[] = (string) $sql;
				if ( 0 === strpos( (string) $sql, 'UPDATE' ) ) {
					return array() === $affected ? 0 : array_shift( $affected );
				}
				if ( 1 === preg_match( '/ADD COLUMN (\w+)/', (string) $sql, $m ) ) {
					$this->columns[ $m[1] ] = 'bigint(20) unsigned';
				}
				if ( 1 === preg_match( '/DROP COLUMN (\w+)$/', (string) $sql, $m ) ) {
					unset( $this->columns[ $m[1] ] );
				}
				if ( 1 === preg_match( '/CHANGE (\w+) (\w+)/', (string) $sql, $m ) ) {
					unset( $this->columns[ $m[1] ] );
					$this->columns[ $m[2] ] = 'bigint(20) unsigned';
				}
				return 1;
			}
		);
		$wpdb->shouldReceive( 'suppress_errors' )->andReturn( false );
		$wpdb->last_error = '';
		$GLOBALS['wpdb']  = $wpdb;
	}

	/**
	 * The UPDATE statements that ran.
	 *
	 * @return array<int, string>
	 */
	private function updates(): array {
		return array_values( array_filter( $this->ran, static fn( string $sql ): bool => 0 === strpos( $sql, 'UPDATE' ) ) );
	}

	public function test_each_offset_of_the_timezone_is_one_stretch_of_wall_clock(): void {
		// São Paulo kept daylight saving until 2019: -02:00 from 15 Oct 2017 to 18 Feb 2018.
		$stretches = ActivityLogInstants::stretches( new \DateTimeZone( 'America/Sao_Paulo' ), 1506816000, 1520000000 );

		$this->assertSame(
			array(
				array(
					'offset' => -10800,
					'from'   => '2017-09-30 21:00:00',
					'to'     => '2017-10-15 00:00:00',
				),
				array(
					'offset' => -7200,
					'from'   => '2017-10-15 01:00:00',
					'to'     => '2018-02-18 00:00:00',
				),
				array(
					'offset' => -10800,
					'from'   => '2018-02-17 23:00:00',
					'to'     => '2018-03-02 11:13:20',
				),
			),
			$stretches,
			'Midnight to 01:00 on 15 Oct never happened; 23:00 to midnight on 17 Feb happened twice.'
		);
	}

	public function test_a_fixed_offset_is_one_stretch(): void {
		$this->assertSame(
			array(
				array(
					'offset' => -10800,
					'from'   => '2026-10-31 21:00:00',
					'to'     => '2026-11-01 21:00:00',
				),
			),
			ActivityLogInstants::stretches( new \DateTimeZone( '-03:00' ), 1793491200, 1793577600 )
		);
	}

	public function test_the_backfill_subtracts_each_stretch_offset_and_fills_the_skipped_hour(): void {
		$this->table( array( 'created_at' => 'datetime', 'created_at_ts' => 'bigint(20) unsigned' ), array( 5, 7, 2, 1 ) );

		$changed = ActivityLogInstants::backfill( 'wp_ffc_activity_log', new \DateTimeZone( 'America/Sao_Paulo' ) );

		$this->assertSame( 15, $changed );
		$updates = $this->updates();
		$this->assertCount( 4, $updates, 'Three stretches and the skipped hour, each converting fewer rows than its limit.' );
		$this->assertStringContainsString( "created_at) - -10800 WHERE created_at_ts = 0 AND created_at >= 2017-09-29 07:00:00 AND created_at < 2017-10-15 00:00:00", $updates[0] );
		$this->assertStringContainsString( "created_at) - -7200 WHERE created_at_ts = 0 AND created_at >= 2017-10-15 01:00:00 AND created_at < 2018-02-18 00:00:00", $updates[1] );
		$this->assertStringContainsString( "created_at >= 2018-02-17 23:00:00", $updates[2] );
		$this->assertStringContainsString( "created_at) - -10800 WHERE created_at_ts = 0 AND created_at >= 2017-10-15 00:00:00 AND created_at < 2017-10-15 01:00:00", $updates[3], 'The hour that never happened is read with the offset before the change.' );
		foreach ( $updates as $sql ) {
			$this->assertStringContainsString( 'TIMESTAMPDIFF(SECOND', $sql, 'The server reads the value without its session timezone.' );
			$this->assertStringEndsWith( 'LIMIT ' . ActivityLogInstants::BATCH, $sql );
		}
	}

	public function test_a_stretch_runs_again_while_its_statement_fills_its_limit(): void {
		$this->table( array( 'created_at' => 'datetime', 'created_at_ts' => 'bigint(20) unsigned' ), array( ActivityLogInstants::BATCH, ActivityLogInstants::BATCH, 3 ) );

		$changed = ActivityLogInstants::backfill( 'wp_ffc_activity_log', new \DateTimeZone( 'America/Sao_Paulo' ) );

		$this->assertSame( 2 * ActivityLogInstants::BATCH + 3, $changed );
		$this->assertStringContainsString( '- -10800', $this->updates()[2], 'The first stretch ran three times before the next began.' );
		$this->assertStringContainsString( '- -7200', $this->updates()[3] );
	}

	public function test_a_table_with_no_dated_row_converts_nothing(): void {
		$wpdb = Mockery::mock( 'wpdb' );
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$wpdb->shouldReceive( 'get_row' )->andReturn(
			array(
				'first_at' => null,
				'last_at'  => null,
			)
		);
		$wpdb->shouldNotReceive( 'query' );
		$GLOBALS['wpdb'] = $wpdb;

		$this->assertSame( 0, ActivityLogInstants::backfill( 'wp_ffc_activity_log', new \DateTimeZone( 'UTC' ) ) );
	}

	public function test_in_wp_admin_the_column_is_filled_then_swapped(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'add_option' )->justReturn( true );
		Functions\expect( 'delete_option' )->once()->with( ActivityLogInstants::LOCK_OPTION );
		$this->table( array( 'created_at' => 'datetime' ), array( 1, 1, 1, 1 ) );

		$this->assertTrue( ActivityLogInstants::migrate() );

		$alters = array_values( array_filter( $this->ran, static fn( string $sql ): bool => 0 === strpos( $sql, 'ALTER' ) ) );
		$this->assertStringContainsString( 'ADD COLUMN created_at_ts', $alters[0] );
		$this->assertSame( 'ALTER TABLE wp_ffc_activity_log DROP COLUMN created_at', $alters[1] );
		$this->assertSame( 'ALTER TABLE wp_ffc_activity_log CHANGE created_at_ts created_at BIGINT UNSIGNED NOT NULL DEFAULT 0', $alters[2] );
		$this->assertStringContainsString( 'ADD INDEX created_at', $alters[3] );
		$this->assertNotEmpty( $this->updates(), 'The rows are filled before the old column goes.' );
		$this->assertLessThan( array_search( $alters[1], $this->ran, true ), array_search( $this->updates()[0], $this->ran, true ) );
	}

	public function test_a_swap_cut_short_is_finished_without_dropping_anything(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'add_option' )->justReturn( true );
		Functions\when( 'delete_option' )->justReturn( true );
		$this->table( array( 'created_at_ts' => 'bigint(20) unsigned' ) );

		$this->assertTrue( ActivityLogInstants::migrate() );

		foreach ( $this->ran as $sql ) {
			$this->assertStringNotContainsString( 'DROP COLUMN', $sql, 'The filled column is the only copy left.' );
		}
		$this->assertSame( array(), $this->updates() );
	}

	public function test_a_converted_column_needs_no_lock(): void {
		Functions\expect( 'add_option' )->never();
		$this->table( array( 'created_at' => 'bigint(20) unsigned' ) );

		$this->assertTrue( ActivityLogInstants::migrate() );
		$this->assertSame( array(), $this->ran );
	}

	public function test_a_front_end_request_leaves_the_column_alone(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\expect( 'add_option' )->never();
		$this->table( array( 'created_at' => 'datetime' ) );

		$this->assertFalse( ActivityLogInstants::migrate() );
		$this->assertSame( array(), $this->ran );
	}

	public function test_a_request_that_finds_the_lock_held_leaves_the_column_alone(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'add_option' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( (string) ( time() - 30 ) );
		Functions\expect( 'update_option' )->never();
		$this->table( array( 'created_at' => 'datetime' ) );

		$this->assertFalse( ActivityLogInstants::migrate() );
		$this->assertSame( array(), $this->ran );
	}

	public function test_a_lock_left_by_a_request_that_died_is_taken_over(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'add_option' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( (string) ( time() - 3600 ) );
		Functions\expect( 'update_option' )->once();
		Functions\when( 'delete_option' )->justReturn( true );
		$this->table( array( 'created_at' => 'datetime' ), array( 1, 1, 1, 1 ) );

		$this->assertTrue( ActivityLogInstants::migrate() );
	}

	public function test_a_missing_table_is_already_converted(): void {
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SHOW TABLES' );
		$wpdb->shouldReceive( 'get_var' )->andReturn( null );
		$wpdb->shouldNotReceive( 'query' );
		$GLOBALS['wpdb'] = $wpdb;

		$this->assertTrue( ActivityLogInstants::migrate(), 'A fresh table is created with the BIGINT column.' );
	}
}
