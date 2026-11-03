<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Migrations\Strategies\RateLimitKeyedIdentifiersMigrationStrategy;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * The rows identified by an unkeyed e-mail or CPF hash are deleted, and only
 * those written before the change (#2343).
 *
 * @covers \FreeFormCertificate\Migrations\Strategies\RateLimitKeyedIdentifiersMigrationStrategy
 */
class RateLimitKeyedIdentifiersMigrationStrategyTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var \Mockery\MockInterface */
	private $wpdb;

	/** @var array{0: bool, 1: mixed} Whether `$wpdb` was set, and its value. */
	private array $wpdb_backup = array( false, null );

	/**
	 * Rows per table: id => type, as the double holds them.
	 *
	 * @var array<string, array<int, string>>
	 */
	private array $rows = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->wpdb_backup = array( array_key_exists( 'wpdb', $GLOBALS ), $GLOBALS['wpdb'] ?? null );

		$this->rows = array(
			'wp_ffc_rate_limits'     => array(
				1 => 'email',
				2 => 'ip',
				3 => 'cpf',
				9 => 'cpf',
			),
			'wp_ffc_rate_limit_logs' => array(
				4 => 'email',
				5 => 'global',
				7 => 'email',
			),
		);

		global $wpdb;
		$wpdb             = Mockery::mock( 'wpdb' );
		$wpdb->prefix     = 'wp_';
		$wpdb->last_error = '';
		$this->wpdb       = $wpdb;

		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static fn( $sql, ...$args ) => array(
				'sql'  => (string) $sql,
				'args' => $args,
			)
		);
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( $query ) {
				$sql = $query['sql'];
				if ( 0 === strpos( $sql, 'SHOW TABLES LIKE' ) ) {
					return $query['args'][0];
				}
				return (string) count( $this->matching( $query ) );
			}
		);
		$wpdb->shouldReceive( 'query' )->andReturnUsing(
			function ( $query ) {
				$ids   = array_slice( $this->matching( $query ), 0, (int) end( $query['args'] ) );
				$table = $query['args'][0];
				foreach ( $ids as $id ) {
					unset( $this->rows[ $table ][ $id ] );
				}
				return count( $ids );
			}
		);

		Functions\when( '__' )->returnArg();
		// Last id per table written before identifiers were keyed: id 9 and
		// id 7 came after, so they are keyed rows and stay.
		Functions\when( 'get_option' )->justReturn(
			array(
				'ffc_rate_limits'     => 3,
				'ffc_rate_limit_logs' => 4,
			)
		);
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
	 * Ids of the rows a prepared statement selects.
	 *
	 * @param array{sql: string, args: array<int, mixed>} $query Prepared statement.
	 * @return list<int>
	 */
	private function matching( array $query ): array {
		$table = $query['args'][0];
		$types = array( $query['args'][1], $query['args'][2] );
		$last  = false !== strpos( $query['sql'], 'id <=' ) ? (int) $query['args'][3] : PHP_INT_MAX;
		$ids   = array();
		foreach ( $this->rows[ $table ] ?? array() as $id => $type ) {
			if ( in_array( $type, $types, true ) && $id <= $last ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	public function test_only_email_and_cpf_rows_from_before_the_cutover_are_pending(): void {
		$status = ( new RateLimitKeyedIdentifiersMigrationStrategy() )->calculate_status( 'rate_limit_keyed_identifiers', array() );

		$this->assertSame( 3, $status['pending'], 'Rows 1 and 3 of the counters, row 4 of the log.' );
		$this->assertSame( 5, $status['total'] );
		$this->assertFalse( $status['is_complete'] );
	}

	public function test_the_batches_delete_them_and_stop(): void {
		$strategy = new RateLimitKeyedIdentifiersMigrationStrategy();

		$first = $strategy->execute( 'rate_limit_keyed_identifiers', array( 'batch_size' => 1 ) );
		$this->assertSame( 2, $first['processed'], 'One row per table per batch.' );
		$this->assertTrue( $first['has_more'] );

		$second = $strategy->execute( 'rate_limit_keyed_identifiers', array( 'batch_size' => 1 ) );
		$this->assertSame( 1, $second['processed'] );
		$this->assertFalse( $second['has_more'] );

		$this->assertSame( array( 2 => 'ip', 9 => 'cpf' ), $this->rows['wp_ffc_rate_limits'], 'The keyed row after the cutover and the address row stay.' );
		$this->assertSame( array( 5 => 'global', 7 => 'email' ), $this->rows['wp_ffc_rate_limit_logs'] );
		$this->assertTrue( $strategy->calculate_status( 'rate_limit_keyed_identifiers', array() )['is_complete'] );
	}

	public function test_an_install_with_no_recorded_cutover_has_nothing_to_delete(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		$this->wpdb->shouldNotReceive( 'query' );

		$strategy = new RateLimitKeyedIdentifiersMigrationStrategy();
		$result   = $strategy->execute( 'rate_limit_keyed_identifiers', array() );

		$this->assertSame( 0, $result['processed'] );
		$this->assertFalse( $result['has_more'] );
		$this->assertTrue( $strategy->calculate_status( 'rate_limit_keyed_identifiers', array() )['is_complete'] );
		$this->assertTrue( $strategy->can_run( 'rate_limit_keyed_identifiers', array() ) );
	}
}
