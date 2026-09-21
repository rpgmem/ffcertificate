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
 * Rewriting a stored RF (#1368).
 *
 * The refusals carry this class: a repair that writes when it should have
 * refused puts one person's identifier on another person's record, and the
 * result passes every check afterwards because the value is well-formed. So
 * each refusal is asserted by driving the exact condition, never by reading
 * the code.
 *
 * @covers \FreeFormCertificate\Maintenance\IdentityRepair
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class IdentityRepairTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $wpdb;

	/**
	 * Rows each table answers with, keyed by `table => hash => rows`.
	 *
	 * @var array<string, array<string, array<int, array<string, mixed>>>>
	 */
	private array $rows = array();

	/**
	 * Statements that reached `update()`, as `table => data`.
	 *
	 * @var array<int, array{table: string, data: array<string, mixed>, where: array<string, mixed>}>
	 */
	private array $updates = array();

	/**
	 * Transaction control statements, in order.
	 *
	 * @var array<int, string>
	 */
	private array $control = array();

	/**
	 * Whether the identity index accepted the write.
	 *
	 * @var bool
	 */
	private bool $index_ok = true;

	/**
	 * Tables whose `update()` answers false.
	 *
	 * @var array<int, string>
	 */
	private array $refusing = array();

	/**
	 * Set up Brain\Monkey, the $wpdb double and the encryption seams.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		class_exists( '\FreeFormCertificate\Maintenance\IdentityRepair' );

		$this->rows     = array();
		$this->updates  = array();
		$this->control  = array();
		$this->index_ok = true;
		$this->refusing = array();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'do_action' )->justReturn( null );

		// The GLOBAL one, never `FreeFormCertificate\Settings\get_option`:
		// stubbing the namespaced name CREATES it, and from then on every
		// unqualified call inside that namespace stops falling back to the
		// global -- the blast radius CLAUDE.md records.
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';

		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( $sql, ...$args ) {
				return array( 'sql' => $sql, 'args' => $args );
			}
		);

		// Every `ffc_*` table this class knows exists.
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			static function ( $prepared ) {
				return $prepared['args'][0] ?? null;
			}
		);

		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $prepared ) {
				$table = (string) ( $prepared['args'][0] ?? '' );
				$hash  = (string) ( $prepared['args'][1] ?? '' );

				return $this->rows[ $table ][ $hash ] ?? array();
			}
		);

		$wpdb->shouldReceive( 'update' )->andReturnUsing(
			function ( $table, $data, $where ) {
				$this->updates[] = array(
					'table' => (string) $table,
					'data'  => (array) $data,
					'where' => (array) $where,
				);

				return in_array( (string) $table, $this->refusing, true ) ? false : 1;
			}
		);

		$wpdb->shouldReceive( 'query' )->andReturnUsing(
			function ( $sql ) {
				$this->control[] = (string) $sql;
				return 1;
			}
		);

		$GLOBALS['wpdb'] = $wpdb;
		$this->wpdb      = $wpdb;
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
	 * A repair whose index write can be steered.
	 *
	 * @return IdentityRepair
	 */
	private function repair(): IdentityRepair {
		return new class( $this ) extends IdentityRepair {

			/** @var IdentityRepairTest */
			private $test;

			/**
			 * @param IdentityRepairTest $test The case, for the index verdict.
			 */
			public function __construct( $test ) {
				$this->test = $test;
			}

			/**
			 * @param int    $user_id  Account.
			 * @param string $new_hash Corrected hash.
			 * @return bool
			 */
			protected function reindex( int $user_id, string $new_hash ): bool {
				return $this->test->index_accepts();
			}
		};
	}

	/**
	 * Whether the stubbed identity index accepts the write.
	 *
	 * @return bool
	 */
	public function index_accepts(): bool {
		return $this->index_ok;
	}

	/**
	 * Teach a table which rows carry a hash.
	 *
	 * @param string                          $table Unprefixed table.
	 * @param string                          $hash  The rf_hash.
	 * @param array<int, array<string, mixed>> $rows  Rows to answer with.
	 * @return void
	 */
	private function given( string $table, string $hash, array $rows ): void {
		$this->rows[ 'wp_' . $table ][ $hash ] = $rows;
	}

	/**
	 * An RF whose check digit matches.
	 *
	 * 7·1+6·2+5·3+4·4+3·5+2·6 = 77; 77 mod 11 = 0; dv = (11−0) mod 10 = 1.
	 * Worth spelling out because r = 0 is one of the two residues that both
	 * yield dv = 1 — the scheme's blind spot, recorded in #1345.
	 */
	private const GOOD_RF = '1234561';

	/**
	 * The same six digits under the wrong check digit.
	 */
	private const BAD_RF = '1234560';

	/**
	 * A value whose check digit does not match is refused, and nothing is
	 * written — the screen must not be able to store a second wrong number.
	 */
	public function test_it_refuses_a_value_failing_the_check_digit(): void {
		$result = $this->repair()->repair( 'subject', self::BAD_RF );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_identity_repair_invalid', $result->get_error_code() );
		$this->assertSame( array(), $this->updates, 'A refused repair must write nothing.' );
	}

	/**
	 * A finding naming two accounts is refused.
	 *
	 * This is the refusal that protects a person from another person's
	 * correction, and it cannot be caught downstream: both rows would end up
	 * holding a value whose check digit is fine.
	 */
	public function test_it_refuses_a_finding_that_names_two_accounts(): void {
		$this->given(
			'ffc_submissions',
			'subject',
			array(
				array( 'id' => 1, 'user_id' => 7 ),
				array( 'id' => 2, 'user_id' => 9 ),
			)
		);

		$result = $this->repair()->repair( 'subject', self::GOOD_RF );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_identity_repair_ambiguous', $result->get_error_code() );
		$this->assertSame( array(), $this->updates );
	}

	/**
	 * A corrected value that already exists is a merge, and is refused.
	 */
	public function test_it_refuses_a_collision(): void {
		$this->given( 'ffc_submissions', 'subject', array( array( 'id' => 1, 'user_id' => 7 ) ) );
		$this->given( 'ffc_submissions', $this->hash_of( self::GOOD_RF ), array( array( 'id' => 5, 'user_id' => 8 ) ) );

		$result = $this->repair()->repair( 'subject', self::GOOD_RF );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_identity_repair_collision', $result->get_error_code() );
		$this->assertSame( array(), $this->updates );
	}

	/**
	 * A finding nobody carries any more is a result, not a failure.
	 */
	public function test_a_repaired_finding_is_idempotent(): void {
		$result = $this->repair()->repair( 'subject', self::GOOD_RF );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_identity_repair_gone', $result->get_error_code() );
	}

	/**
	 * An index that refuses rolls the rows back.
	 *
	 * The index and the rows disagreeing is the one outcome worse than not
	 * repairing at all: every later read resolves the account to a value its
	 * rows do not carry.
	 */
	public function test_an_index_refusal_rolls_back(): void {
		$this->given( 'ffc_submissions', 'subject', array( array( 'id' => 1, 'user_id' => 7 ) ) );
		$this->index_ok = false;

		$result = $this->repair()->repair( 'subject', self::GOOD_RF );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_identity_repair_index_failed', $result->get_error_code() );
		$this->assertContains( 'ROLLBACK', $this->control );
		$this->assertNotContains( 'COMMIT', $this->control );
	}

	/**
	 * A store refusing the write rolls the whole repair back.
	 *
	 * Found by mutation, not by design: flipping this branch's `ROLLBACK` to
	 * `COMMIT` left the suite green, which meant a half-written repair — some
	 * stores on the new value, the rest on the old — could ship unnoticed.
	 */
	public function test_a_store_refusal_rolls_back(): void {
		$this->given( 'ffc_submissions', 'subject', array( array( 'id' => 1, 'user_id' => 7 ) ) );
		$this->refusing = array( 'wp_ffc_submissions' );

		$result = $this->repair()->repair( 'subject', self::GOOD_RF );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_identity_repair_failed', $result->get_error_code() );
		$this->assertContains( 'ROLLBACK', $this->control );
		$this->assertNotContains( 'COMMIT', $this->control );
	}

	/**
	 * A repair rewrites BOTH columns, keyed on the old hash.
	 *
	 * The pair is what makes the row findable: writing the ciphertext without
	 * the hash leaves every later lookup resolving the old value, and writing
	 * the hash without the ciphertext leaves a row nobody can decrypt back.
	 */
	public function test_it_rewrites_the_pair_across_every_store_holding_it(): void {
		$this->given( 'ffc_submissions', 'subject', array( array( 'id' => 1, 'user_id' => 7 ) ) );
		$this->given( 'ffc_recruitment_candidate', 'subject', array( array( 'id' => 4, 'user_id' => 0 ) ) );

		$result = $this->repair()->repair( 'subject', self::GOOD_RF );

		$this->assertIsArray( $result );
		$this->assertCount( 2, $this->updates, 'Every store carrying the hash must be rewritten.' );
		$this->assertContains( 'COMMIT', $this->control );

		foreach ( $this->updates as $update ) {
			$this->assertArrayHasKey( 'rf_encrypted', $update['data'] );
			$this->assertSame( $this->hash_of( self::GOOD_RF ), $update['data']['rf_hash'] );
			$this->assertSame( array( 'rf_hash' => 'subject' ), $update['where'], 'The write must key on the OLD hash.' );
		}
	}

	/**
	 * Nothing the repair writes or logs is the identifier itself.
	 */
	public function test_it_never_writes_the_value_anywhere_readable(): void {
		$this->given( 'ffc_submissions', 'subject', array( array( 'id' => 1, 'user_id' => 7 ) ) );

		$this->repair()->repair( 'subject', self::GOOD_RF );

		foreach ( $this->updates as $update ) {
			$this->assertNotContains( self::GOOD_RF, array_map( 'strval', $update['data'] ), 'The plaintext RF must never reach a column.' );
		}
	}

	/**
	 * The hash the encryption seam would produce for a value.
	 *
	 * @param string $rf The value.
	 * @return string
	 */
	private function hash_of( string $rf ): string {
		return (string) \FreeFormCertificate\Core\SensitiveFieldRegistry::hash_identifier( 'rf', $rf );
	}
}
