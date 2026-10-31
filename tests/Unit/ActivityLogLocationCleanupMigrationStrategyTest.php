<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Migrations\Strategies\ActivityLogLocationCleanupMigrationStrategy;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ActivityLogLocationCleanupMigrationStrategy: the debug rows that
 * logged visitors' positions are deleted, and nothing else (#2040).
 *
 * Runs in the shared process over a `$wpdb` double; only global functions
 * are stubbed, and the strategy imports `WP_Error`, so no alias is needed.
 *
 * @covers \FreeFormCertificate\Migrations\Strategies\ActivityLogLocationCleanupMigrationStrategy
 */
class ActivityLogLocationCleanupMigrationStrategyTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var \Mockery\MockInterface */
	private $wpdb;

	private ActivityLogLocationCleanupMigrationStrategy $strategy;

	/** Whether the table exists in the simulated schema. */
	private bool $schema = true;

	/**
	 * Simulated rows: id => action.
	 *
	 * @var array<int, string>
	 */
	private array $rows = array();

	/** When true, every DELETE fails. */
	private bool $delete_fails = false;

	/** When true, the count query keeps reporting rows the DELETE cannot reach. */
	private bool $count_lies = false;

	/** @var array{0: bool, 1: mixed} Whether `$wpdb` was set, and its value. */
	private array $wpdb_backup = array( false, null );

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->wpdb_backup = array( array_key_exists( 'wpdb', $GLOBALS ), $GLOBALS['wpdb'] ?? null );

		global $wpdb;
		$wpdb             = Mockery::mock( 'wpdb' )->makePartial();
		$wpdb->prefix     = 'wp_';
		$wpdb->last_error = '';
		$this->wpdb       = $wpdb;

		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing( static fn( $v ) => $v );
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( string $query, ...$args ): string {
				return $query . ' | ' . implode( ',', array_map( 'strval', $args ) );
			}
		);
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( string $sql ) {
				if ( 0 === strpos( $sql, 'SHOW TABLES' ) ) {
					return $this->schema ? 'wp_ffc_activity_log' : null;
				}
				if ( 0 === strpos( $sql, 'SELECT COUNT(*)' ) ) {
					return (string) ( $this->count_lies ? 7 : count( $this->matching() ) );
				}
				return null;
			}
		);
		$wpdb->shouldReceive( 'query' )->andReturnUsing(
			function ( string $sql ) {
				if ( $this->delete_fails ) {
					$this->wpdb->last_error = 'boom';
					return false;
				}
				$limit = (int) substr( $sql, (int) strrpos( $sql, ',' ) + 1 );
				$gone  = array_slice( $this->matching(), 0, $limit, true );
				foreach ( array_keys( $gone ) as $id ) {
					unset( $this->rows[ $id ] );
				}
				return $this->count_lies ? 0 : count( $gone );
			}
		);

		Functions\when( '__' )->returnArg();

		$this->strategy = new ActivityLogLocationCleanupMigrationStrategy();
	}

	protected function tearDown(): void {
		if ( $this->wpdb_backup[0] ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup[1];
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Rows the migration is after.
	 *
	 * @return array<int, string>
	 */
	private function matching(): array {
		return array_filter( $this->rows, static fn( string $action ) => ActivityLogLocationCleanupMigrationStrategy::ACTION === $action );
	}

	public function test_batches_delete_the_debug_rows_and_nothing_else(): void {
		$this->rows = array(
			1 => 'ip_geolocation_debug',
			2 => 'submission_created',
			3 => 'ip_geolocation_debug',
			4 => 'ip_geolocation_debug',
			5 => 'access_denied',
		);

		$first = $this->strategy->execute( 'activity_log_location_cleanup', array( 'batch_size' => 2 ) );
		$this->assertTrue( $first['success'] );
		$this->assertSame( 2, $first['processed'] );
		$this->assertTrue( $first['has_more'] );

		$second = $this->strategy->execute( 'activity_log_location_cleanup', array( 'batch_size' => 2 ) );
		$this->assertSame( 1, $second['processed'] );
		$this->assertFalse( $second['has_more'] );

		$this->assertSame(
			array(
				2 => 'submission_created',
				5 => 'access_denied',
			),
			$this->rows
		);
		$this->assertTrue( $this->strategy->calculate_status( 'activity_log_location_cleanup', array() )['is_complete'] );
	}

	public function test_status_counts_what_is_left(): void {
		$this->rows = array(
			1 => 'ip_geolocation_debug',
			2 => 'ip_geolocation_debug',
			3 => 'submission_created',
		);

		$status = $this->strategy->calculate_status( 'activity_log_location_cleanup', array() );

		$this->assertSame( 2, $status['pending'] );
		$this->assertFalse( $status['is_complete'] );
		$this->assertSame( 0, $status['percent'] );
	}

	// A batch that deletes nothing ends the run, whatever the count says (#1378).
	public function test_a_batch_that_deletes_nothing_ends_the_run(): void {
		$this->count_lies = true;

		$result = $this->strategy->execute( 'activity_log_location_cleanup', array( 'batch_size' => 10 ) );

		$this->assertSame( 0, $result['processed'] );
		$this->assertFalse( $result['has_more'] );
	}

	public function test_a_failed_delete_reports_the_error_and_stops(): void {
		$this->rows         = array( 1 => 'ip_geolocation_debug' );
		$this->delete_fails = true;

		$result = $this->strategy->execute( 'activity_log_location_cleanup', array() );

		$this->assertFalse( $result['success'] );
		$this->assertFalse( $result['has_more'] );
		$this->assertSame( array( 'boom' ), $result['errors'] );
	}

	public function test_a_missing_table_is_complete_and_cannot_run(): void {
		$this->schema = false;

		$this->assertTrue( $this->strategy->calculate_status( 'activity_log_location_cleanup', array() )['is_complete'] );
		$this->assertSame( 0, $this->strategy->execute( 'activity_log_location_cleanup', array() )['processed'] );
		$this->assertInstanceOf( \WP_Error::class, $this->strategy->can_run( 'activity_log_location_cleanup', array() ) );
	}

	public function test_a_present_table_can_run_and_has_a_name(): void {
		$this->assertTrue( $this->strategy->can_run( 'activity_log_location_cleanup', array() ) );
		$this->assertSame( 'Activity Log: Delete Logged Locations', $this->strategy->get_name() );
	}

	public function test_a_nonsense_batch_size_still_deletes(): void {
		$this->rows = array( 1 => 'ip_geolocation_debug' );

		$result = $this->strategy->execute( 'activity_log_location_cleanup', array( 'batch_size' => 'all' ) );

		$this->assertSame( 1, $result['processed'] );
	}
}
