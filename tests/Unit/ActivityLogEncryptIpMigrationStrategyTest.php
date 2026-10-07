<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Migrations\Strategies\ActivityLogEncryptIpMigrationStrategy;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ActivityLogEncryptIpMigrationStrategy: older activity log rows
 * have their plaintext client IP encrypted into `user_ip_encrypted` and the
 * plaintext cleared (#1574).
 *
 * @covers \FreeFormCertificate\Migrations\Strategies\ActivityLogEncryptIpMigrationStrategy
 * @runClassInSeparateProcess
 * @preserveGlobalState disabled
 */
class ActivityLogEncryptIpMigrationStrategyTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var \Mockery\MockInterface */
	private $wpdb;

	private ActivityLogEncryptIpMigrationStrategy $strategy;

	/**
	 * Whether the table and both columns exist in the simulated schema.
	 *
	 * @var bool
	 */
	private bool $schema = true;

	/**
	 * Simulated rows: id => plaintext IP (null once cleared).
	 *
	 * @var array<int, string|null>
	 */
	private array $plain = array();

	/**
	 * Simulated rows: id => ciphertext.
	 *
	 * @var array<int, string|null>
	 */
	private array $cipher = array();

	/**
	 * When true, every UPDATE fails.
	 *
	 * @var bool
	 */
	private bool $update_fails = false;

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
				if ( false !== strpos( $sql, "user_ip IS NOT NULL AND user_ip <> ''" ) ) {
					return (string) count( array_filter( $this->plain, static fn( $v ) => null !== $v && '' !== $v ) );
				}
				if ( false !== strpos( $sql, 'user_ip_encrypted IS NOT NULL' ) ) {
					return (string) count( array_filter( $this->cipher, static fn( $v ) => null !== $v && '' !== $v ) );
				}
				return null;
			}
		);
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( string $sql ) {
				if ( 0 === strpos( $sql, 'SHOW COLUMNS' ) ) {
					return $this->schema ? array( (object) array( 'Field' => 'x' ) ) : array();
				}
				$limit = (int) substr( $sql, (int) strrpos( $sql, ',' ) + 1 );
				$out   = array();
				foreach ( $this->plain as $id => $ip ) {
					if ( null !== $ip && '' !== $ip && count( $out ) < $limit ) {
						$out[] = array( 'id' => (string) $id, 'user_ip' => $ip );
					}
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
				$id                  = (int) $where['id'];
				$this->plain[ $id ]  = $data['user_ip'];
				$this->cipher[ $id ] = $data['user_ip_encrypted'];
				return 1;
			}
		);

		if ( ! class_exists( 'FreeFormCertificate\Migrations\Strategies\WP_Error' ) ) {
			class_alias( 'WP_Error', 'FreeFormCertificate\Migrations\Strategies\WP_Error' );
		}

		Functions\when( '__' )->returnArg();
		Functions\when( 'FreeFormCertificate\Migrations\Strategies\__' )->returnArg();

		$this->strategy = new ActivityLogEncryptIpMigrationStrategy();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_a_batch_encrypts_each_address_and_clears_the_plaintext(): void {
		$this->plain = array(
			1 => '203.0.113.5',
			2 => '198.51.100.7',
		);

		$result = $this->strategy->execute( 'activity_log_encrypt_ip', array( 'batch_size' => 50 ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 2, $result['processed'] );
		$this->assertFalse( $result['has_more'] );
		$this->assertNull( $this->plain[1] );
		$this->assertNull( $this->plain[2] );
		$this->assertSame( '203.0.113.5', Encryption::decrypt( (string) $this->cipher[1] ) );
		$this->assertSame( '198.51.100.7', Encryption::decrypt( (string) $this->cipher[2] ) );
	}

	public function test_has_more_while_rows_remain_beyond_the_batch(): void {
		$this->plain = array(
			1 => '203.0.113.1',
			2 => '203.0.113.2',
			3 => '203.0.113.3',
		);

		$first = $this->strategy->execute( 'activity_log_encrypt_ip', array( 'batch_size' => 2 ) );
		$this->assertSame( 2, $first['processed'] );
		$this->assertTrue( $first['has_more'] );

		$second = $this->strategy->execute( 'activity_log_encrypt_ip', array( 'batch_size' => 2 ) );
		$this->assertSame( 1, $second['processed'] );
		$this->assertFalse( $second['has_more'] );
	}

	public function test_a_batch_that_changes_nothing_stops_even_with_rows_pending(): void {
		// The failure the CLAUDE.md "Batched migrations" rule exists for: if
		// every UPDATE fails, the same rows are selected again, so asking for
		// another batch would loop forever.
		$this->plain        = array( 1 => '203.0.113.9' );
		$this->update_fails = true;

		$result = $this->strategy->execute( 'activity_log_encrypt_ip', array( 'batch_size' => 10 ) );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 0, $result['processed'] );
		$this->assertFalse( $result['has_more'] );
		$this->assertSame( array( 'boom' ), $result['errors'] );
	}

	public function test_a_corrupt_batch_size_still_processes_a_row(): void {
		$this->plain = array( 1 => '203.0.113.4' );

		$result = $this->strategy->execute( 'activity_log_encrypt_ip', array( 'batch_size' => 'all' ) );

		$this->assertSame( 1, $result['processed'] );
	}

	public function test_status_counts_pending_and_migrated(): void {
		$this->plain  = array(
			1 => '203.0.113.1',
			2 => null,
		);
		$this->cipher = array(
			1 => null,
			2 => 'v2:abc',
		);

		$status = $this->strategy->calculate_status( 'activity_log_encrypt_ip', array() );

		$this->assertSame( 1, $status['pending'] );
		$this->assertSame( 1, $status['migrated'] );
		$this->assertSame( 2, $status['total'] );
		$this->assertFalse( $status['is_complete'] );
	}

	public function test_an_install_without_the_column_reads_complete_and_does_nothing(): void {
		$this->schema = false;
		$this->plain  = array( 1 => '203.0.113.1' );

		$status = $this->strategy->calculate_status( 'activity_log_encrypt_ip', array() );
		$result = $this->strategy->execute( 'activity_log_encrypt_ip', array() );

		$this->assertTrue( $status['is_complete'] );
		$this->assertSame( 0, $result['processed'] );
		$this->assertSame( '203.0.113.1', $this->plain[1] );
	}

	public function test_can_run_and_name(): void {
		$this->assertTrue( $this->strategy->can_run( 'activity_log_encrypt_ip', array() ) );
		$this->assertSame( 'Activity Log: Encrypt Client IPs', $this->strategy->get_name() );
	}
}
