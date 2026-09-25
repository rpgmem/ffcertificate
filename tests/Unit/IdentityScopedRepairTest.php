<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Maintenance\IdentityRepair;

/**
 * Correcting the document on ONE of two logins (#1397 sprint 4).
 *
 * THE DEFECT THIS EXISTS AGAINST IS THE FIX ITSELF, UNSCOPED.
 *
 * Two logins sharing one identifier is a finding where one of them typed it
 * wrong. Correcting it means rewriting that login's rows — and the rewrite
 * matches on the HASH, which both logins carry. Applied without a scope it
 * would rewrite the other person's rows too, quietly, to a number that is not
 * theirs, and both rows would then hold a value whose check digit is fine.
 *
 * So the scope has to be in the statement and in the `WHERE`, and that is
 * what these drive: the rows LISTED and the rows WRITTEN must both be one
 * account's.
 *
 * @covers \FreeFormCertificate\Maintenance\IdentityRepair
 */
class IdentityScopedRepairTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * An RF whose check digit matches.
	 *
	 * @var string
	 */
	private const GOOD_RF = '1234561';

	/**
	 * Every row each table holds, keyed by table.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $rows = array();

	/**
	 * Writes that reached `update()`.
	 *
	 * @var array<int, array{table: string, where: array<string, mixed>, where_format: array<int, string>}>
	 */
	private array $updates = array();

	/**
	 * Set up Brain\Monkey and the `$wpdb` double.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		class_exists( '\FreeFormCertificate\Maintenance\IdentityRepair' );

		$this->rows    = array();
		$this->updates = array();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';

		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( $sql, ...$args ) {
				return array(
					'sql'  => $sql,
					'args' => $args,
				);
			}
		);

		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			static function ( $prepared ) {
				$sql = (string) ( $prepared['sql'] ?? '' );

				return 0 === strpos( $sql, 'SELECT' ) ? null : ( $prepared['args'][0] ?? null );
			}
		);

		// HONOURS THE SCOPE, WHICH IS THE WHOLE POINT OF THIS HARNESS.
		//
		// The sibling `IdentityRepairTest` answers by hash alone, which is
		// enough for an unscoped repair and would make every case here pass
		// against the defect — a filter applied only in PHP would look
		// identical. This reads `user_id = %d` from the statement.
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $prepared ) {
				$sql   = (string) ( $prepared['sql'] ?? '' );
				$table = (string) ( $prepared['args'][0] ?? '' );
				$hash  = (string) ( $prepared['args'][2] ?? '' );
				$rows  = array();

				foreach ( $this->rows[ $table ] ?? array() as $row ) {
					if ( ( $row['rf_hash'] ?? '' ) !== $hash ) {
						continue;
					}

					if ( false !== strpos( $sql, 'AND user_id = %d' )
						&& (int) ( $row['user_id'] ?? 0 ) !== (int) ( $prepared['args'][3] ?? 0 ) ) {
						continue;
					}

					$rows[] = $row;
				}

				return $rows;
			}
		);

		$wpdb->shouldReceive( 'update' )->andReturnUsing(
			function ( $table, $data, $where, $format, $where_format ) {
				$this->updates[] = array(
					'table'        => (string) $table,
					'where'        => (array) $where,
					'where_format' => (array) $where_format,
				);

				return 1;
			}
		);

		$wpdb->shouldReceive( 'query' )->andReturn( 1 );
		$wpdb->shouldReceive( 'get_row' )->andReturn( null );
		$wpdb->shouldReceive( 'get_col' )->andReturn( array() );

		$GLOBALS['wpdb'] = $wpdb;
	}

	/**
	 * Tear down Brain\Monkey.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A repair whose index write is stubbed.
	 *
	 * @return IdentityRepair
	 */
	private function repair(): IdentityRepair {
		return new class() extends IdentityRepair {

			/**
			 * @param int    $user_id  Account.
			 * @param string $new_hash Corrected hash.
			 * @param string $column   Index column.
			 * @return bool
			 */
			protected function reindex( int $user_id, string $new_hash, string $column = 'rf_hash' ): bool {
				return true;
			}
		};
	}

	/**
	 * Two logins carrying one identifier, which is the shared tier's shape.
	 *
	 * @return void
	 */
	private function two_logins_share_it(): void {
		$this->rows['wp_ffc_submissions'] = array(
			array(
				'id'      => 1,
				'user_id' => 398,
				'rf_hash' => 'shared',
			),
			array(
				'id'      => 2,
				'user_id' => 513,
				'rf_hash' => 'shared',
			),
		);
	}

	/**
	 * Unscoped, the finding is refused — one corrected value cannot serve two
	 * people. This is the behaviour the scope exists to make reachable, so it
	 * is asserted here too: if it ever stopped refusing, the scope would have
	 * become optional rather than required.
	 */
	public function test_without_a_scope_a_shared_identifier_is_refused(): void {
		$this->two_logins_share_it();

		$result = $this->repair()->repair( 'shared', self::GOOD_RF );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_identity_repair_ambiguous', $result->get_error_code() );
		$this->assertSame( array(), $this->updates );
	}

	/**
	 * Scoped, it goes through — and the write carries the account, so the
	 * other login's row is out of reach of the statement rather than merely
	 * absent from a list.
	 */
	public function test_a_scoped_correction_writes_only_that_account(): void {
		$this->two_logins_share_it();

		$result = $this->repair()->repair( 'shared', self::GOOD_RF, 0, 'rf', 398 );

		$this->assertIsArray( $result );
		$this->assertSame( 398, $result['account'] );
		$this->assertCount( 1, $this->updates );

		$write = $this->updates[0];

		$this->assertSame( 'shared', $write['where']['rf_hash'] );
		$this->assertArrayHasKey(
			'user_id',
			$write['where'],
			'Without the account in the WHERE the rewrite reaches the other login\'s rows.'
		);
		$this->assertSame( 398, $write['where']['user_id'] );
		$this->assertSame( array( '%s', '%d' ), $write['where_format'] );
	}

	/**
	 * A scope naming an account that holds none of these rows is refused in
	 * its own words, not as "already repaired" — which would send an operator
	 * away from a finding that is still there.
	 */
	public function test_a_scope_that_owns_nothing_says_so(): void {
		$this->two_logins_share_it();

		$result = $this->repair()->repair( 'shared', self::GOOD_RF, 0, 'rf', 999 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_identity_repair_not_theirs', $result->get_error_code() );
		$this->assertSame( array(), $this->updates );
	}

	/**
	 * The unscoped write is unchanged: no account in the `WHERE`, so a plain
	 * repair still rewrites every row carrying the hash.
	 */
	public function test_an_unscoped_repair_still_matches_on_the_hash_alone(): void {
		$this->rows['wp_ffc_submissions'] = array(
			array(
				'id'      => 1,
				'user_id' => 398,
				'rf_hash' => 'wrong',
			),
			array(
				'id'      => 2,
				'user_id' => 398,
				'rf_hash' => 'wrong',
			),
		);

		$result = $this->repair()->repair( 'wrong', self::GOOD_RF );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $this->updates );
		$this->assertSame( array( 'rf_hash' => 'wrong' ), $this->updates[0]['where'] );
		$this->assertSame( array( '%s' ), $this->updates[0]['where_format'] );
	}
}
