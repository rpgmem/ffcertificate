<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Core\IpAddress;
use FreeFormCertificate\Migrations\Strategies\RateLimitLogsEncryptIpMigrationStrategy;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Tests for RateLimitLogsEncryptIpMigrationStrategy: older rate-limit log
 * rows lose their plaintext address, which is encrypted, and an address
 * identifier becomes the address fingerprint (#2109).
 *
 * @covers \FreeFormCertificate\Migrations\Strategies\RateLimitLogsEncryptIpMigrationStrategy
 */
class RateLimitLogsEncryptIpMigrationStrategyTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var \Mockery\MockInterface */
	private $wpdb;

	private RateLimitLogsEncryptIpMigrationStrategy $strategy;

	/**
	 * Whether the table and both columns exist in the simulated schema.
	 *
	 * @var bool
	 */
	private bool $schema = true;

	/**
	 * Simulated rows by id.
	 *
	 * @var array<int, array{type: string, identifier: string, ip_address: string|null, ip_address_encrypted: string|null}>
	 */
	private array $rows = array();

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
					return $this->schema ? 'wp_ffc_rate_limit_logs' : null;
				}
				if ( false !== strpos( $sql, 'WHERE' ) ) {
					return (string) count( $this->pending() );
				}
				return (string) count( $this->rows );
			}
		);
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( string $sql ) {
				if ( 0 === strpos( $sql, 'SHOW COLUMNS' ) ) {
					return $this->schema ? array( (object) array( 'Field' => 'x' ) ) : array();
				}
				$limit = (int) substr( $sql, (int) strrpos( $sql, ',' ) + 1 );
				$out   = array();
				foreach ( $this->pending() as $id ) {
					if ( count( $out ) < $limit ) {
						$out[] = array( 'id' => (string) $id ) + $this->rows[ $id ];
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
				$id                = (int) $where['id'];
				$this->rows[ $id ] = array_merge( $this->rows[ $id ], $data );
				return 1;
			}
		);

		Functions\when( '__' )->returnArg();

		$this->strategy = new RateLimitLogsEncryptIpMigrationStrategy();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Ids of the rows the strategy's predicate selects, evaluated the way
	 * the SQL does: an address in `ip_address`, or an `ip` identifier that
	 * carries a dot or a colon.
	 *
	 * @return array<int, int>
	 */
	private function pending(): array {
		$ids = array();
		foreach ( $this->rows as $id => $row ) {
			$plain   = null !== $row['ip_address'] && '' !== $row['ip_address'];
			$address = 'ip' === $row['type'] && 1 === preg_match( '/[.:]/', $row['identifier'] );
			if ( $plain || $address ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/**
	 * @param string      $type       Row type.
	 * @param string      $identifier Identifier.
	 * @param string|null $address    Plaintext address.
	 * @param string|null $cipher     Ciphertext.
	 * @return array{type: string, identifier: string, ip_address: string|null, ip_address_encrypted: string|null}
	 */
	private function row( string $type, string $identifier, ?string $address, ?string $cipher = null ): array {
		return array(
			'type'                 => $type,
			'identifier'           => $identifier,
			'ip_address'           => $address,
			'ip_address_encrypted' => $cipher,
		);
	}

	public function test_a_batch_encrypts_the_address_and_rekeys_address_identifiers(): void {
		$this->rows = array(
			1 => $this->row( 'ip', '203.0.113.5', '203.0.113.5' ),
			2 => $this->row( 'blacklist', hash( 'sha256', '198.51.100.7' ), '198.51.100.7' ),
			3 => $this->row( 'email', hash( 'sha256', 'a@example.org' ), '2804:14c::9' ),
		);

		$result = $this->strategy->execute( 'rate_limit_logs_encrypt_ip', array( 'batch_size' => 50 ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 3, $result['processed'] );
		$this->assertFalse( $result['has_more'] );

		foreach ( $this->rows as $row ) {
			$this->assertNull( $row['ip_address'], 'no plaintext address is left' );
		}
		$this->assertSame( '203.0.113.5', Encryption::decrypt( (string) $this->rows[1]['ip_address_encrypted'] ) );
		$this->assertSame( '2804:14c::9', Encryption::decrypt( (string) $this->rows[3]['ip_address_encrypted'] ) );

		$this->assertSame( IpAddress::fingerprint( '203.0.113.5' ), $this->rows[1]['identifier'] );
		$this->assertSame( IpAddress::fingerprint( '198.51.100.7' ), $this->rows[2]['identifier'], 'an unkeyed hash of an address is re-keyed' );
		$this->assertSame( hash( 'sha256', 'a@example.org' ), $this->rows[3]['identifier'], 'an e-mail row keeps its own identifier' );
	}

	public function test_an_ip_row_older_than_the_address_column_is_rekeyed_from_its_identifier(): void {
		$this->rows = array( 1 => $this->row( 'ip', '2804:14C:0:0::1', null ) );

		$result = $this->strategy->execute( 'rate_limit_logs_encrypt_ip', array( 'batch_size' => 10 ) );

		$this->assertSame( 1, $result['processed'] );
		$this->assertSame( IpAddress::fingerprint( '2804:14c::1' ), $this->rows[1]['identifier'] );
		$this->assertSame( '2804:14C:0:0::1', Encryption::decrypt( (string) $this->rows[1]['ip_address_encrypted'] ) );
		$this->assertSame( array(), $this->pending() );
	}

	public function test_an_existing_ciphertext_is_kept(): void {
		$this->rows = array( 1 => $this->row( 'ip', '203.0.113.5', '203.0.113.5', 'v2:kept' ) );

		$this->strategy->execute( 'rate_limit_logs_encrypt_ip', array( 'batch_size' => 10 ) );

		$this->assertSame( 'v2:kept', $this->rows[1]['ip_address_encrypted'] );
		$this->assertNull( $this->rows[1]['ip_address'] );
	}

	public function test_has_more_while_rows_remain_beyond_the_batch(): void {
		$this->rows = array(
			1 => $this->row( 'ip', '203.0.113.1', '203.0.113.1' ),
			2 => $this->row( 'ip', '203.0.113.2', '203.0.113.2' ),
			3 => $this->row( 'ip', '203.0.113.3', '203.0.113.3' ),
		);

		$first = $this->strategy->execute( 'rate_limit_logs_encrypt_ip', array( 'batch_size' => 2 ) );
		$this->assertSame( 2, $first['processed'] );
		$this->assertTrue( $first['has_more'] );

		$second = $this->strategy->execute( 'rate_limit_logs_encrypt_ip', array( 'batch_size' => 2 ) );
		$this->assertSame( 1, $second['processed'] );
		$this->assertFalse( $second['has_more'] );
	}

	public function test_a_batch_that_changes_nothing_stops_even_with_rows_pending(): void {
		$this->rows         = array( 1 => $this->row( 'ip', '203.0.113.9', '203.0.113.9' ) );
		$this->update_fails = true;

		$result = $this->strategy->execute( 'rate_limit_logs_encrypt_ip', array( 'batch_size' => 10 ) );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 0, $result['processed'] );
		$this->assertFalse( $result['has_more'] );
		$this->assertSame( array( 'boom' ), $result['errors'] );
	}

	public function test_status_counts_pending_rows_against_the_table(): void {
		$fingerprint = IpAddress::fingerprint( '203.0.113.2' );
		$this->rows  = array(
			1 => $this->row( 'ip', '203.0.113.1', '203.0.113.1' ),
			2 => $this->row( 'ip', $fingerprint, null, 'v2:abc' ),
			3 => $this->row( 'read', hash( 'sha256', 'x' ), null ),
		);

		$status = $this->strategy->calculate_status( 'rate_limit_logs_encrypt_ip', array() );

		$this->assertSame( 1, $status['pending'] );
		$this->assertSame( 2, $status['migrated'] );
		$this->assertSame( 3, $status['total'] );
		$this->assertFalse( $status['is_complete'] );
	}

	public function test_an_install_without_the_column_reads_complete_and_does_nothing(): void {
		$this->schema = false;
		$this->rows   = array( 1 => $this->row( 'ip', '203.0.113.1', '203.0.113.1' ) );

		$status = $this->strategy->calculate_status( 'rate_limit_logs_encrypt_ip', array() );
		$result = $this->strategy->execute( 'rate_limit_logs_encrypt_ip', array() );

		$this->assertTrue( $status['is_complete'] );
		$this->assertSame( 0, $result['processed'] );
		$this->assertSame( '203.0.113.1', $this->rows[1]['ip_address'] );
	}

	public function test_can_run_and_name(): void {
		$this->assertTrue( $this->strategy->can_run( 'rate_limit_logs_encrypt_ip', array() ) );
		$this->assertSame( 'Rate Limit Log: Encrypt Addresses', $this->strategy->get_name() );
	}
}
