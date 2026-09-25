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
		$this->plain    = array();
		$this->ciphers  = array();

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

		// Two statements reach `get_var`: the `SHOW TABLES LIKE <table>` probe,
		// which answers with the table so every store exists, and the
		// consolidation's ciphertext read, whose statement starts `SELECT`.
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( $prepared ) {
				$sql = (string) ( $prepared['sql'] ?? '' );

				if ( 0 === strpos( $sql, 'SELECT' ) ) {
					$hash = (string) ( $prepared['args'][3] ?? '' );

					return $this->ciphers[ $hash ] ?? null;
				}

				return $prepared['args'][0] ?? null;
			}
		);

		// `SELECT id, user_id FROM %i WHERE %i = %s` -- table, COLUMN, hash.
		// The column is what makes the repair work over CPF as well as RF, and
		// reading the hash from the old position silently answered every
		// lookup with nothing, which every refusal reads as "already repaired".
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $prepared ) {
				$table = (string) ( $prepared['args'][0] ?? '' );
				$hash  = (string) ( $prepared['args'][2] ?? '' );

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
			 * @param string $column   Index column.
			 * @return bool
			 */
			protected function reindex( int $user_id, string $new_hash, string $column = 'rf_hash' ): bool {
				return $this->test->index_accepts();
			}

			/**
			 * Decryption without a key, so a consolidation can be driven.
			 *
			 * @param string $cipher Stored ciphertext.
			 * @return string|null
			 */
			protected function decrypt( string $cipher ): ?string {
				return $this->test->plaintext_of( $cipher );
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
	 * What a ciphertext decrypts to, for the consolidation seam.
	 *
	 * @param string $cipher Stored ciphertext.
	 * @return string|null
	 */
	public function plaintext_of( string $cipher ): ?string {
		return $this->plain[ $cipher ] ?? null;
	}

	/**
	 * Ciphertext => plaintext, for the consolidation seam.
	 *
	 * @var array<string, string>
	 */
	private array $plain = array();

	/**
	 * Hash => the ciphertext a store answers with.
	 *
	 * @var array<string, string>
	 */
	private array $ciphers = array();

	/**
	 * Teach a hash which ciphertext it carries, and what that reads as.
	 *
	 * @param string      $hash   The stored hash.
	 * @param string      $cipher Its ciphertext.
	 * @param string|null $plain  What that decrypts to, or null for unreadable.
	 * @return void
	 */
	private function stores_value( string $hash, string $cipher, ?string $plain ): void {
		$this->ciphers[ $hash ] = $cipher;

		if ( null !== $plain ) {
			$this->plain[ $cipher ] = $plain;
		}
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

	// ==================================================================
	// The collision is scoped by ACCOUNT -- #1386
	// ==================================================================

	/**
	 * The shape every one of production's 32 typo findings has: one account,
	 * two RFs, one of them mistyped. The corrected value is the account's
	 * OTHER RF, so it always has rows -- which the unscoped refusal read as a
	 * merge, and refused every case the tool exists for.
	 */
	public function test_it_consolidates_when_the_value_is_the_same_account_s_other_rf(): void {
		$this->given( 'ffc_submissions', 'subject', array( array( 'id' => 1, 'user_id' => 398 ) ) );
		$this->given(
			'ffc_submissions',
			$this->hash_of( self::GOOD_RF ),
			array( array( 'id' => 5, 'user_id' => 398 ) )
		);

		$result = $this->repair()->repair( 'subject', self::GOOD_RF );

		$this->assertIsArray( $result, 'A typo on one account is a repair, not a merge.' );
		$this->assertContains( 'COMMIT', $this->control );
		$this->assertCount( 1, $this->updates );
		$this->assertSame( array( 'rf_hash' => 'subject' ), $this->updates[0]['where'] );
	}

	/**
	 * The refusal the scoping must NOT weaken: the value belongs to somebody
	 * else, so writing it would put one person's identifier on another's
	 * record -- undetectably, because the result is well-formed.
	 */
	public function test_it_still_refuses_when_the_value_belongs_to_another_account(): void {
		$this->given( 'ffc_submissions', 'subject', array( array( 'id' => 1, 'user_id' => 398 ) ) );
		$this->given(
			'ffc_submissions',
			$this->hash_of( self::GOOD_RF ),
			array( array( 'id' => 5, 'user_id' => 513 ) )
		);

		$result = $this->repair()->repair( 'subject', self::GOOD_RF );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_identity_repair_collision', $result->get_error_code() );
		$this->assertSame( array(), $this->updates );
	}

	/**
	 * A colliding row owned by NOBODY names no account, so it cannot be shown
	 * to be the same person -- an unpromoted candidacy is exactly that shape.
	 * Absence of evidence is not evidence here, so it refuses.
	 */
	public function test_it_refuses_when_the_colliding_rows_name_nobody(): void {
		$this->given( 'ffc_submissions', 'subject', array( array( 'id' => 1, 'user_id' => 398 ) ) );
		$this->given(
			'ffc_recruitment_candidate',
			$this->hash_of( self::GOOD_RF ),
			array( array( 'id' => 5, 'user_id' => 0 ) )
		);

		$result = $this->repair()->repair( 'subject', self::GOOD_RF );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_identity_repair_collision', $result->get_error_code() );
		$this->assertSame( array(), $this->updates );
	}

	/**
	 * A subject with no account at all cannot be shown to own the value
	 * either, whoever the colliding rows belong to.
	 */
	public function test_it_refuses_to_consolidate_for_a_subject_with_no_account(): void {
		$this->given( 'ffc_submissions', 'subject', array( array( 'id' => 1, 'user_id' => 0 ) ) );
		$this->given(
			'ffc_submissions',
			$this->hash_of( self::GOOD_RF ),
			array( array( 'id' => 5, 'user_id' => 0 ) )
		);

		$result = $this->repair()->repair( 'subject', self::GOOD_RF );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_identity_repair_collision', $result->get_error_code() );
		$this->assertSame( array(), $this->updates );
	}

	/**
	 * `ffc_recruitment_candidate` holds each RF once (`UNIQUE KEY uq_rf_hash`),
	 * so a consolidation with both identifiers recorded there would leave two
	 * rows claiming one. The database would refuse it as a duplicate key,
	 * which says nothing an operator can act on -- so this refuses first, and
	 * says what is in the way.
	 */
	public function test_it_refuses_a_consolidation_the_unique_store_cannot_hold(): void {
		$this->given( 'ffc_recruitment_candidate', 'subject', array( array( 'id' => 1, 'user_id' => 398 ) ) );
		$this->given(
			'ffc_recruitment_candidate',
			$this->hash_of( self::GOOD_RF ),
			array( array( 'id' => 5, 'user_id' => 398 ) )
		);

		$result = $this->repair()->repair( 'subject', self::GOOD_RF );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_identity_repair_unique_store', $result->get_error_code() );
		$this->assertSame( array(), $this->updates, 'Nothing may be written before the store refuses.' );
	}

	/**
	 * The same account holding the value in a store WITHOUT the unique key is
	 * the ordinary consolidation, so the refusal above must not reach it.
	 */
	public function test_the_unique_store_refusal_does_not_reach_the_other_stores(): void {
		$this->given( 'ffc_submissions', 'subject', array( array( 'id' => 1, 'user_id' => 398 ) ) );
		$this->given(
			'ffc_recruitment_candidate',
			$this->hash_of( self::GOOD_RF ),
			array( array( 'id' => 5, 'user_id' => 398 ) )
		);

		$result = $this->repair()->repair( 'subject', self::GOOD_RF );

		$this->assertIsArray( $result, 'Only a subject row IN the unique store can collide there.' );
		$this->assertContains( 'COMMIT', $this->control );
	}

	// ==================================================================
	// consolidate(), and the verbs over CPF -- #1386
	// ==================================================================

	/**
	 * A valid CPF, by the two-check-digit rule `validate_cpf()` applies.
	 */
	private const GOOD_CPF = '52998224725';

	/**
	 * The mechanical case end to end: two hashes in, no value anywhere.
	 *
	 * The correct number is the account's other identifier, so the operator
	 * types nothing and the screen never holds it. What proves the value did
	 * not travel is that the test supplies it ONLY through the decryption
	 * seam -- no argument carries it.
	 */
	public function test_it_consolidates_from_the_account_s_sound_identifier(): void {
		$right = $this->hash_of( self::GOOD_RF );

		$this->given( 'ffc_submissions', 'wrong', array( array( 'id' => 1, 'user_id' => 398 ) ) );
		$this->given( 'ffc_submissions', $right, array( array( 'id' => 5, 'user_id' => 398 ) ) );
		$this->stores_value( $right, 'cipherRight', self::GOOD_RF );

		$result = $this->repair()->consolidate( 'wrong', $right );

		$this->assertIsArray( $result );
		$this->assertSame( $right, $result['hash'], 'The rows must end on the sound identifier.' );
		$this->assertContains( 'COMMIT', $this->control );
		$this->assertSame( array( 'rf_hash' => 'wrong' ), $this->updates[0]['where'] );
	}

	/**
	 * A TARGET THAT CANNOT BE READ IS NOT A TARGET.
	 *
	 * The queue only offers this where the value decrypted, but the queue was
	 * built from an earlier scan and the key can change in between — the same
	 * reason `repair()` resolves its rows at write time. Writing a guess over
	 * the evidence is the one outcome that cannot be undone.
	 */
	public function test_it_refuses_to_consolidate_into_a_value_it_cannot_read(): void {
		$right = $this->hash_of( self::GOOD_RF );

		$this->given( 'ffc_submissions', 'wrong', array( array( 'id' => 1, 'user_id' => 398 ) ) );
		$this->given( 'ffc_submissions', $right, array( array( 'id' => 5, 'user_id' => 398 ) ) );
		$this->stores_value( $right, 'cipherRight', null );

		$result = $this->repair()->consolidate( 'wrong', $right );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_identity_consolidate_unreadable', $result->get_error_code() );
		$this->assertSame( array(), $this->updates );
	}

	/**
	 * Consolidating into nothing, or into itself, writes nothing.
	 */
	public function test_it_refuses_a_consolidation_with_no_target(): void {
		foreach ( array( '', 'wrong' ) as $target ) {
			$result = $this->repair()->consolidate( 'wrong', $target );

			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 'ffc_identity_consolidate_no_target', $result->get_error_code() );
		}

		$this->assertSame( array(), $this->updates );
	}

	/**
	 * EVERY REFUSAL OF THE REPAIR STILL APPLIES.
	 *
	 * A consolidation is a repair whose value came from the account rather
	 * than from HR, so it must not become a way around the rule that a value
	 * belonging to somebody else is a merge.
	 */
	public function test_a_consolidation_does_not_bypass_the_other_account_refusal(): void {
		$right = $this->hash_of( self::GOOD_RF );

		$this->given( 'ffc_submissions', 'wrong', array( array( 'id' => 1, 'user_id' => 398 ) ) );
		$this->given( 'ffc_submissions', $right, array( array( 'id' => 5, 'user_id' => 513 ) ) );
		$this->stores_value( $right, 'cipherRight', self::GOOD_RF );

		$result = $this->repair()->consolidate( 'wrong', $right );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_identity_repair_collision', $result->get_error_code() );
		$this->assertSame( array(), $this->updates );
	}

	/**
	 * The same verb over CPF: the columns follow the identifier, and the rule
	 * that says a value is well formed is `validate_cpf()`'s two check digits.
	 */
	public function test_it_repairs_a_cpf_through_the_cpf_columns(): void {
		$this->given( 'ffc_submissions', 'subject', array( array( 'id' => 1, 'user_id' => 398 ) ) );

		$result = $this->repair()->repair( 'subject', self::GOOD_CPF, 0, 'cpf' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'cpf_encrypted', $this->updates[0]['data'] );
		$this->assertSame( array( 'cpf_hash' => 'subject' ), $this->updates[0]['where'] );
		$this->assertNotContains(
			self::GOOD_CPF,
			array_map( 'strval', $this->updates[0]['data'] ),
			'The plaintext CPF must never reach a column.'
		);
	}

	/**
	 * A CPF failing its check digits is refused exactly as an RF is.
	 */
	public function test_it_refuses_a_cpf_failing_its_check_digits(): void {
		$this->given( 'ffc_submissions', 'subject', array( array( 'id' => 1, 'user_id' => 398 ) ) );

		$result = $this->repair()->repair( 'subject', '52998224724', 0, 'cpf' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_identity_repair_invalid', $result->get_error_code() );
		$this->assertSame( array(), $this->updates );
	}

	/**
	 * An identifier this class does not handle is refused before anything is
	 * read, rather than composing a statement against a column that may exist.
	 */
	public function test_it_refuses_an_identifier_it_does_not_handle(): void {
		$result = $this->repair()->repair( 'subject', self::GOOD_RF, 0, 'email' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_identity_repair_unknown_field', $result->get_error_code() );
		$this->assertSame( array(), $this->updates );
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
