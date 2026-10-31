<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Migrations\Strategies\ActivityLogAccessDeniedIpMigrationStrategy;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ActivityLogAccessDeniedIpMigrationStrategy: the plaintext
 * address older refusal rows copied into their context is removed, and
 * nothing else changes (#2109).
 *
 * @covers \FreeFormCertificate\Migrations\Strategies\ActivityLogAccessDeniedIpMigrationStrategy
 */
class ActivityLogAccessDeniedIpMigrationStrategyTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var \Mockery\MockInterface */
	private $wpdb;

	private ActivityLogAccessDeniedIpMigrationStrategy $strategy;

	/** Whether the table exists in the simulated schema. */
	private bool $schema = true;

	/**
	 * Simulated rows: id => [action, context].
	 *
	 * @var array<int, array{0: string, 1: string|null}>
	 */
	private array $rows = array();

	/** When true, every UPDATE fails. */
	private bool $update_fails = false;

	/** When true, every UPDATE reports that it changed nothing. */
	private bool $update_noop = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

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
					return (string) count( $this->matching() );
				}
				return null;
			}
		);
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( string $sql ) {
				if ( 0 === strpos( $sql, 'SHOW COLUMNS' ) ) {
					return $this->schema ? array( (object) array( 'Field' => 'context' ) ) : array();
				}
				$limit = (int) substr( $sql, (int) strrpos( $sql, ',' ) + 1 );
				$out   = array();
				foreach ( array_slice( $this->matching(), 0, $limit, true ) as $id => $row ) {
					$out[] = array(
						'id'      => (string) $id,
						'context' => $row[1],
					);
				}
				return $out;
			}
		);
		$wpdb->shouldReceive( 'update' )->andReturnUsing(
			function ( string $table, array $data, array $where ) {
				if ( $this->update_fails ) {
					$this->wpdb->last_error = 'boom';
					return false;
				}
				if ( $this->update_noop ) {
					return 0;
				}
				$this->rows[ $where['id'] ][1] = $data['context'];
				return 1;
			}
		);

		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$this->strategy = new ActivityLogAccessDeniedIpMigrationStrategy();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Rows the migration is after.
	 *
	 * @return array<int, array{0: string, 1: string|null}>
	 */
	private function matching(): array {
		return array_filter(
			$this->rows,
			static fn( array $row ) => ActivityLogAccessDeniedIpMigrationStrategy::ACTION === $row[0]
				&& false !== strpos( (string) $row[1], '"identifier"' )
		);
	}

	public function test_batches_remove_the_address_and_keep_the_reason(): void {
		$this->rows = array(
			1 => array( 'access_denied', '{"reason":"geolocation_invalid","identifier":"203.0.113.7"}' ),
			2 => array( 'submission_created', '{"identifier":"kept"}' ),
			3 => array( 'access_denied', '{"reason":"datetime_invalid","identifier":"2804:14c::1"}' ),
			4 => array( 'access_denied', '{"reason":"outside"}' ),
			5 => array( 'access_denied', '{"identifier":"198.51.100.9"}' ),
		);

		$first = $this->strategy->execute( 'activity_log_access_denied_ip', array( 'batch_size' => 2 ) );
		$this->assertTrue( $first['success'] );
		$this->assertSame( 2, $first['processed'] );
		$this->assertTrue( $first['has_more'] );

		$second = $this->strategy->execute( 'activity_log_access_denied_ip', array( 'batch_size' => 2 ) );
		$this->assertSame( 1, $second['processed'] );
		$this->assertFalse( $second['has_more'] );

		$this->assertSame(
			array(
				1 => array( 'access_denied', '{"reason":"geolocation_invalid"}' ),
				2 => array( 'submission_created', '{"identifier":"kept"}' ),
				3 => array( 'access_denied', '{"reason":"datetime_invalid"}' ),
				4 => array( 'access_denied', '{"reason":"outside"}' ),
				5 => array( 'access_denied', '[]' ),
			),
			$this->rows,
			'only refusal rows lose the key; every other row and key is untouched'
		);
		$this->assertTrue( $this->strategy->calculate_status( 'activity_log_access_denied_ip', array() )['is_complete'] );
	}

	public function test_a_context_that_does_not_decode_is_cleared(): void {
		$this->rows = array( 1 => array( 'access_denied', '{"identifier":"203.0.113.7"' ) );

		$result = $this->strategy->execute( 'activity_log_access_denied_ip', array() );

		$this->assertSame( 1, $result['processed'] );
		$this->assertNull( $this->rows[1][1] );
	}

	public function test_status_counts_what_is_left(): void {
		$this->rows = array(
			1 => array( 'access_denied', '{"identifier":"203.0.113.7"}' ),
			2 => array( 'access_denied', '{"reason":"x"}' ),
		);

		$status = $this->strategy->calculate_status( 'activity_log_access_denied_ip', array() );

		$this->assertSame( 1, $status['pending'] );
		$this->assertFalse( $status['is_complete'] );
	}

	// A batch that changes nothing ends the run, whatever the count says (#1378).
	public function test_a_batch_that_changes_nothing_ends_the_run(): void {
		$this->rows        = array( 1 => array( 'access_denied', '{"identifier":"203.0.113.7"}' ) );
		$this->update_noop = true;

		$result = $this->strategy->execute( 'activity_log_access_denied_ip', array() );

		$this->assertSame( 0, $result['processed'] );
		$this->assertFalse( $result['has_more'] );
	}

	public function test_a_failed_update_reports_the_error(): void {
		$this->rows         = array( 1 => array( 'access_denied', '{"identifier":"203.0.113.7"}' ) );
		$this->update_fails = true;

		$result = $this->strategy->execute( 'activity_log_access_denied_ip', array() );

		$this->assertFalse( $result['success'] );
		$this->assertFalse( $result['has_more'] );
		$this->assertSame( array( 'boom' ), $result['errors'] );
	}

	public function test_a_missing_table_is_complete_and_cannot_run(): void {
		$this->schema = false;

		$this->assertTrue( $this->strategy->calculate_status( 'activity_log_access_denied_ip', array() )['is_complete'] );
		$this->assertSame( 0, $this->strategy->execute( 'activity_log_access_denied_ip', array() )['processed'] );
		$this->assertInstanceOf( \WP_Error::class, $this->strategy->can_run( 'activity_log_access_denied_ip', array() ) );
	}

	public function test_a_present_table_can_run_and_has_a_name(): void {
		$this->assertTrue( $this->strategy->can_run( 'activity_log_access_denied_ip', array() ) );
		$this->assertSame( 'Activity Log: Clear Refused Addresses', $this->strategy->get_name() );
	}
}
