<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Maintenance\IdentityMerge;
use FreeFormCertificate\Maintenance\IdentityAcceptance;
use FreeFormCertificate\Maintenance\IdentityQueue;
use FreeFormCertificate\Maintenance\IdentityConflictQuery;
use WP_Error;

/**
 * Consolidating two logins that turned out to be one person (#1386).
 *
 * A merge is the one verb that cannot be undone by another verb: once two
 * accounts' records sit under one login, nothing afterwards can tell which
 * came from where. So the agreement rule matters more here than anywhere, and
 * every refusal is driven rather than read.
 *
 * @covers \FreeFormCertificate\Maintenance\IdentityMerge
 */
class IdentityMergeTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Rows a table answers with, keyed `table => rows`.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $rows = array();

	/**
	 * The index row per account, keyed by user id.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $index = array();

	/**
	 * Writes that reached `update()`.
	 *
	 * @var array<int, array{table: string, data: array<string, mixed>, where: array<string, mixed>}>
	 */
	private array $updates = array();

	/**
	 * Relationship tables a prepared `query()` deleted from, in order.
	 *
	 * @var array<int, string>
	 */
	private array $deletes = array();

	/**
	 * Duplicate rows a relationship table reports, by table.
	 *
	 * @var array<string, int>
	 */
	private array $duplicates = array();

	/**
	 * Tables whose prepared DELETE answers false while their update succeeds.
	 *
	 * SEPARATE FROM `$refusing` ON PURPOSE. With one list the double refuses
	 * both statements, so the update's own guard catches every case and the
	 * delete's guard is never the thing that fires — a mutation removing it
	 * survived, and the fixture was why. In production they fail apart.
	 *
	 * @var array<int, string>
	 */
	private array $refusing_delete = array();

	/**
	 * Transaction control statements, in order.
	 *
	 * @var array<int, string>
	 */
	private array $control = array();

	/**
	 * Whether the stubbed index write is accepted.
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
	 * Set up Brain\Monkey and the $wpdb double.
	 */
	/** @var array<string, array<string, mixed>> The acceptance record, as the stub serves it. */
	private array $accepted = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		class_exists( '\FreeFormCertificate\Maintenance\IdentityMerge' );

		$this->rows     = array();
		$this->index    = array();
		$this->updates  = array();
		$this->control    = array();
		$this->deletes    = array();
		$this->duplicates = array();
		$this->refusing_delete = array();
		$this->index_ok = true;
		$this->refusing = array();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'do_action' )->justReturn( null );
		$this->accepted = array();

		// THE ACCEPTANCE RECORD IS AN OPTION, SO IT IS SERVED FROM HERE (#1532).
		//
		// `refusal()` is static and reads the record directly, which is the only
		// shape available to it; `$this->accepted` is what a case puts in it.
		Functions\when( 'get_option' )->alias(
			function ( $key, $default_value = false ) {
				if ( IdentityAcceptance::OPTION === $key ) {
					return $this->accepted;
				}

				return array();
			}
		);
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

		// Every `ffc_*` table this class knows exists.
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			static function ( $prepared ) {
				return $prepared['args'][0] ?? null;
			}
		);

		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $prepared ) {
				$table = (string) ( $prepared['args'][0] ?? '' );
				$owner = (int) ( $prepared['args'][1] ?? 0 );

				return array_values(
					array_filter(
						$this->rows[ $table ] ?? array(),
						static function ( $row ) use ( $owner ) {
							return (int) ( $row['user_id'] ?? 0 ) === $owner;
						}
					)
				);
			}
		);

		$wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function ( $prepared ) {
				$owner = (int) ( $prepared['args'][1] ?? 0 );

				return $this->index[ $owner ] ?? null;
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

		// TWO SHAPES REACH `query()`, AND ONLY ONE IS TRANSACTION CONTROL.
		//
		// `START TRANSACTION` / `COMMIT` / `ROLLBACK` arrive as strings. The
		// relationship merge (#1368) sends a PREPARED statement, which this
		// double hands back as an array, so casting everything to string put
		// "Array" in the control log and raised a conversion notice. The
		// prepared ones are recorded apart and answer with the configured
		// duplicate count, which is what the delete-before-update returns.
		$wpdb->shouldReceive( 'query' )->andReturnUsing(
			function ( $sql ) {
				if ( is_array( $sql ) ) {
					$table = (string) ( $sql['args'][0] ?? '' );

					$this->deletes[] = $table;

					if ( in_array( $table, $this->refusing, true ) || in_array( $table, $this->refusing_delete, true ) ) {
						return false;
					}

					return $this->duplicates[ $table ] ?? 0;
				}

				$this->control[] = (string) $sql;

				return 1;
			}
		);

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
	 * A merge whose index writes can be steered.
	 *
	 * @return IdentityMerge
	 */
	private function merge(): IdentityMerge {
		return new class( $this ) extends IdentityMerge {

			/** @var IdentityMergeTest */
			private $test;

			/**
			 * Take the case, for the index verdict.
			 *
			 * @param IdentityMergeTest $test The case.
			 */
			public function __construct( $test ) {
				$this->test = $test;
			}

			/**
			 * @param int                   $user_id Account.
			 * @param array<string, string> $data    Columns.
			 * @return bool
			 */
			protected function reindex( int $user_id, array $data ): bool {
				return $this->test->record_index( $user_id, $data );
			}

			/**
			 * OVERRIDDEN SO THE CASES SAY WHAT THE SCAN FOUND (#1491).
			 *
			 * The real one constructs `IdentityConflictQuery` and decrypts. Left
			 * alone it would put a query nobody wrote for it to the `$wpdb`
			 * double, which answers with nothing and therefore never refuses --
			 * green for a reason unrelated to the check.
			 *
			 * @param array<string, mixed>              $agreement Unused; the case states the answer.
			 * @param array<string, array<int, string>> $records   Unused, same reason.
			 * @return array<string, array<string, string>>
			 */
			protected function verdicts( array $agreement, array $records ): array {
				return $this->test->verdicts;
			}
		};
	}

	/**
	 * What the check-digit scan says, as a case chooses to state it.
	 *
	 * @var array<string, array<string, string>>
	 */
	public array $verdicts = array();

	/**
	 * Record an index write and report whether it was accepted.
	 *
	 * @param int                   $user_id Account.
	 * @param array<string, string> $data    Columns written.
	 * @return bool
	 */
	public function record_index( int $user_id, array $data ): bool {
		$this->updates[] = array(
			'table' => 'index',
			'data'  => $data,
			'where' => array( 'user_id' => $user_id ),
		);

		return $this->index_ok;
	}

	/**
	 * Teach a store its rows.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows.
	 * @return void
	 */
	private function given( array $rows ): void {
		$this->rows['wp_ffc_submissions'] = $rows;
	}

	/**
	 * One record row.
	 *
	 * @param int    $owner Account.
	 * @param string $cpf   Its cpf_hash.
	 * @param string $rf    Its rf_hash.
	 * @return array<string, mixed>
	 */
	private static function row( int $owner, string $cpf, string $rf ): array {
		return array(
			'user_id'  => $owner,
			'cpf_hash' => $cpf,
			'rf_hash'  => $rf,
		);
	}

	/**
	 * THE SHARED TIER: one identifier, two logins. The operator picks which
	 * survives, and the records follow.
	 */
	public function test_it_moves_every_record_onto_the_account_the_operator_kept(): void {
		$this->given(
			array(
				self::row( 5784, 'cpfA', 'rfShared' ),
				self::row( 6092, 'cpfA', 'rfShared' ),
			)
		);

		$result = $this->merge()->merge( 5784, 6092 );

		$this->assertIsArray( $result );
		$this->assertSame( 6092, $result['emptied'] );
		$this->assertContains( 'COMMIT', $this->control );
		$this->assertSame( array( 'user_id' => 5784 ), $this->updates[0]['data'] );
		$this->assertSame( array( 'user_id' => 6092 ), $this->updates[0]['where'], 'Records move BY ACCOUNT, not by identifier.' );
	}

	/**
	 * WHAT THE PERSON IS ALLOWED TO DO MOVES WITH WHAT THEY OWN (#1368).
	 *
	 * Audience membership, a place on a booking and a permission on one
	 * schedule are not records — they are the access. Leaving them on the
	 * emptied login is #1367's defect one level over: the row moves and the
	 * thing granting it does not, so somebody logs in as the survivor with
	 * their bookable groups attached to a login they no longer use.
	 */
	public function test_the_relationships_move_with_the_records(): void {
		$this->given(
			array(
				self::row( 5784, 'cpfA', 'rfShared' ),
				self::row( 6092, 'cpfA', 'rfShared' ),
			)
		);

		$result = $this->merge()->merge( 5784, 6092 );

		$this->assertIsArray( $result );

		$moved = array_column( $this->updates, 'table' );

		foreach ( array( 'wp_ffc_audience_members', 'wp_ffc_audience_booking_users', 'wp_ffc_audience_schedule_permissions' ) as $table ) {
			$this->assertContains( $table, $moved, $table . ' was left on the emptied login, so the person lost what it granted.' );
			$this->assertContains( $table, $this->deletes, $table . ' was updated without first clearing the pairings the survivor already holds.' );
		}
	}

	/**
	 * THE UNIQUE KEY IS WHY THE DELETE COMES FIRST.
	 *
	 * Each relationship carries `UNIQUE KEY (<parent>, user_id)`, so a donor
	 * row whose pairing the survivor already holds cannot become theirs. It is
	 * dropped rather than updated, and the drop is REPORTED: a survivor who
	 * already had that membership is why a row disappears, and a total alone
	 * cannot be told apart from a row that went missing.
	 */
	public function test_a_pairing_the_survivor_already_holds_is_dropped_and_counted(): void {
		$this->given(
			array(
				self::row( 5784, 'cpfA', 'rfShared' ),
				self::row( 6092, 'cpfA', 'rfShared' ),
			)
		);

		$this->duplicates['wp_ffc_audience_members'] = 3;

		$result = $this->merge()->merge( 5784, 6092 );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'relationships', $result );
		$this->assertSame(
			3,
			$result['relationships']['wp_ffc_audience_members']['duplicates'],
			'A dropped duplicate must be reported, never folded into the move count.'
		);
	}

	/**
	 * A relationship that refuses rolls the whole merge back.
	 *
	 * The records and the access are one decision: committing the first
	 * without the second is the state this exists to prevent.
	 */
	public function test_a_refusing_relationship_rolls_the_merge_back(): void {
		$this->given(
			array(
				self::row( 5784, 'cpfA', 'rfShared' ),
				self::row( 6092, 'cpfA', 'rfShared' ),
			)
		);

		$this->refusing[] = 'wp_ffc_audience_members';

		$result = $this->merge()->merge( 5784, 6092 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertContains( 'ROLLBACK', $this->control );
		$this->assertNotContains( 'COMMIT', $this->control );
	}

	/**
	 * A DELETE that fails is a merge that fails, even when the update would not.
	 *
	 * The two statements fail apart: the delete clears the pairings the
	 * survivor already holds, and if it does not, the update that follows hits
	 * the unique key. Guarding only the update means committing a merge whose
	 * duplicate clearing silently did nothing.
	 */
	public function test_a_refusing_delete_rolls_the_merge_back(): void {
		$this->given(
			array(
				self::row( 5784, 'cpfA', 'rfShared' ),
				self::row( 6092, 'cpfA', 'rfShared' ),
			)
		);

		$this->refusing_delete[] = 'wp_ffc_audience_members';

		$result = $this->merge()->merge( 5784, 6092 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertContains( 'ROLLBACK', $this->control );
		$this->assertNotContains( 'COMMIT', $this->control );
	}

	/**
	 * THE GAP-FILLING HALF: one login has only a CPF, the other only an RF,
	 * and they agree on the CPF. The survivor ends up holding both.
	 */
	public function test_the_survivor_gains_what_it_did_not_hold(): void {
		$this->given(
			array(
				self::row( 5784, 'cpfA', '' ),
				self::row( 6092, 'cpfA', 'rfB' ),
			)
		);

		$result = $this->merge()->merge( 5784, 6092 );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'rf' ), $result['gained'] );

		$gained = array_values(
			array_filter(
				$this->updates,
				static function ( $update ) {
					return 'index' === $update['table'];
				}
			)
		);

		$this->assertCount( 1, $gained );
		$this->assertSame( array( 'rf_hash' => 'rfB' ), $gained[0]['data'] );
		$this->assertSame( 5784, $gained[0]['where']['user_id'] );
	}

	/**
	 * A DISAGREEMENT REFUSES THE MERGE, and this is the refusal that matters
	 * most: once two accounts' records sit under one login, nothing afterwards
	 * can tell which came from where.
	 */
	public function test_it_refuses_accounts_that_disagree_on_an_identifier(): void {
		$this->given(
			array(
				self::row( 5784, 'cpfA', 'rfShared' ),
				self::row( 6092, 'cpfB', 'rfShared' ),
			)
		);

		$result = $this->merge()->merge( 5784, 6092 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_identity_merge_conflict', $result->get_error_code() );
		$this->assertSame( array(), $this->updates );
	}

	/**
	 * THE REFUSAL SAYS WHICH OF ITS TWO REASONS REFUSED (#1477).
	 *
	 * Taken from the pair that reported this, off the production audit: two
	 * logins carrying the SAME RF, which is why the queue paired them, and one
	 * of them carrying a second RF besides. The merge is correctly refused --
	 * the survivor would inherit a number nobody has explained -- but it was
	 * refused as "those accounts hold different values for RF", so the operator
	 * went looking for a disagreement between two identical numbers.
	 *
	 * The assertion is on the sentence and not only on the code, because the
	 * sentence is the whole defect: a code nobody reads was never wrong.
	 */
	public function test_a_second_value_on_the_absorbed_account_is_not_reported_as_a_disagreement(): void {
		$this->given(
			array(
				self::row( 5666, '', '414ea6753d972d46' ),
				self::row( 6499, '', '414ea6753d972d46' ),
				self::row( 6499, '', '16dd8f605603f2c0' ),
			)
		);

		$result = $this->merge()->merge( 5666, 6499 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_identity_merge_multiple_identities', $result->get_error_code() );
		$this->assertSame( array(), $this->updates, 'A refused merge writes nothing.' );

		$said = $result->get_error_message();

		$this->assertStringNotContainsString(
			'different values for',
			$said,
			'They hold the same RF. Calling it a disagreement sends the operator to compare two identical numbers.'
		);
		$this->assertStringContainsString( '6499', $said, 'The refusal has to name the account to go and fix.' );
		$this->assertStringContainsString( 'RF', $said );
	}

	/**
	 * The same pair merges the other way round, and that asymmetry is the rule
	 * working rather than a hole in it: the survivor keeps what it has, so
	 * absorbing the single-valued side hands nobody an unexplained number.
	 *
	 * It is deliberately NOT recommended by the refusal above -- reversing it
	 * resolves nothing, since the survivor still carries both values and now
	 * has more records sitting behind an unresolved identity.
	 */
	public function test_it_allows_the_direction_that_gives_the_survivor_nothing_new(): void {
		$this->given(
			array(
				self::row( 5666, '', '414ea6753d972d46' ),
				self::row( 6499, '', '414ea6753d972d46' ),
				self::row( 6499, '', '16dd8f605603f2c0' ),
			)
		);

		$result = $this->merge()->plan( 6499, 5666 );

		$this->assertIsArray( $result, 'The single-valued side is absorbable.' );
		$this->assertContains( 'rf', $result['matches'] );
	}

	/**
	 * Two accounts that share nothing are two people, whatever an operator
	 * believes. An absent value is not agreement.
	 */
	public function test_it_refuses_accounts_that_share_nothing(): void {
		$this->given(
			array(
				self::row( 5784, 'cpfA', 'rfA' ),
				self::row( 6092, '', 'rfB' ),
			)
		);

		$result = $this->merge()->merge( 5784, 6092 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_identity_merge_conflict', $result->get_error_code() );
		$this->assertSame( array(), $this->updates );
	}

	/**
	 * An account carrying nothing this can read has nothing to merge from,
	 * and saying so beats moving rows on no evidence at all.
	 */
	public function test_it_refuses_when_the_absorbed_account_holds_nothing(): void {
		$this->given( array( self::row( 5784, 'cpfA', 'rfA' ) ) );

		$result = $this->merge()->merge( 5784, 6092 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_identity_merge_nothing_to_move', $result->get_error_code() );
		$this->assertSame( array(), $this->updates );
	}

	/**
	 * THE EMPTIED LOGIN STOPS CLAIMING WHAT IT NO LONGER HOLDS -- and is NOT
	 * deleted. Deleting a WordPress user fires `deleted_user`, whose cleanup
	 * has its own policy, and it cannot be undone.
	 */
	public function test_the_emptied_account_is_cleared_but_never_deleted(): void {
		$this->given(
			array(
				self::row( 5784, 'cpfA', 'rfShared' ),
				self::row( 6092, 'cpfA', 'rfShared' ),
			)
		);

		$this->merge()->merge( 5784, 6092 );

		$cleared = array_values(
			array_filter(
				$this->updates,
				static function ( $update ) {
					return 'wp_ffc_user_profiles' === $update['table'];
				}
			)
		);

		$this->assertCount( 1, $cleared );
		$this->assertSame( array( 'user_id' => 6092 ), $cleared[0]['where'] );
		$this->assertNull( $cleared[0]['data']['cpf_hash'] );
		$this->assertNull( $cleared[0]['data']['rf_hash'] );

		$source = (string) file_get_contents( __DIR__ . '/../../includes/maintenance/class-ffc-identity-merge.php' );

		$this->assertStringNotContainsString( 'wp_delete_user', $source, 'A merge must never delete the login it emptied.' );
	}

	/**
	 * A store refusing the write rolls the whole merge back: half-merged
	 * accounts are worse than none, because both read as complete.
	 */
	public function test_a_store_refusal_rolls_the_merge_back(): void {
		$this->given(
			array(
				self::row( 5784, 'cpfA', 'rfShared' ),
				self::row( 6092, 'cpfA', 'rfShared' ),
			)
		);
		$this->refusing = array( 'wp_ffc_submissions' );

		$result = $this->merge()->merge( 5784, 6092 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_identity_merge_failed', $result->get_error_code() );
		$this->assertContains( 'ROLLBACK', $this->control );
		$this->assertNotContains( 'COMMIT', $this->control );
	}

	/**
	 * The index refusing rolls back too.
	 */
	public function test_an_index_refusal_rolls_the_merge_back(): void {
		$this->given(
			array(
				self::row( 5784, 'cpfA', '' ),
				self::row( 6092, 'cpfA', 'rfB' ),
			)
		);
		$this->index_ok = false;

		$result = $this->merge()->merge( 5784, 6092 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_identity_merge_index_failed', $result->get_error_code() );
		$this->assertContains( 'ROLLBACK', $this->control );
		$this->assertNotContains( 'COMMIT', $this->control );
	}

	/**
	 * Merging an account into itself, or naming no account, writes nothing.
	 */
	public function test_it_refuses_a_pair_that_is_not_a_pair(): void {
		$this->assertSame(
			'ffc_identity_merge_same_account',
			(string) $this->merge()->merge( 5784, 5784 )->get_error_code()
		);

		foreach ( array( array( 0, 6092 ), array( 5784, 0 ) ) as $pair ) {
			$this->assertSame(
				'ffc_identity_merge_no_pair',
				(string) $this->merge()->merge( $pair[0], $pair[1] )->get_error_code()
			);
		}

		$this->assertSame( array(), $this->updates );
	}

	// ──────────────────────────────────────────────────────────────────.
	// A shared number that is not a number (#1491).
	// ──────────────────────────────────────────────────────────────────.

	/**
	 * THE MERGE IS REFUSED WHEN THE VALUE THAT PAIRED THE TWO IS INVALID.
	 *
	 * `between()` refuses a disagreement and an ambiguity and never asked whether
	 * a value IS a value, so two accounts holding the same wrong number passed.
	 * That is not weak evidence of one person: it is evidence that one of them
	 * holds a number nobody can hold, and a merge does not come back.
	 */
	public function test_it_refuses_when_the_shared_identifier_fails_its_check_digit(): void {
		$this->given(
			array(
				self::row( 5784, 'cpfA', 'rfShared' ),
				self::row( 6092, 'cpfA', 'rfShared' ),
			)
		);

		$this->verdicts = array(
			'rf' => array( 'rfShared' => IdentityConflictQuery::VERDICT_INVALID ),
		);

		$result = $this->merge()->merge( 5784, 6092 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( 'fails its own check digit', $result->get_error_message() );
		$this->assertStringContainsString( 'Numbers to correct', $result->get_error_message(), 'The refusal names the step that comes first.' );
		$this->assertNotContains( 'COMMIT', $this->control, 'Nothing may be written.' );
	}

	/**
	 * AND IT SAYS THE CORRECTION MAY DISSOLVE THE PAIR, NOT RESOLVE IT.
	 *
	 * The operator's next move depends on this: if the shared number was the
	 * typo, the two accounts were never the same person and there is no merge to
	 * come back for. A refusal that only said "correct it first" would send them
	 * back here expecting to finish.
	 */
	public function test_the_refusal_says_the_pair_may_dissolve(): void {
		$this->given(
			array(
				self::row( 5784, 'cpfA', 'rfShared' ),
				self::row( 6092, 'cpfA', 'rfShared' ),
			)
		);

		$this->verdicts = array(
			'rf' => array( 'rfShared' => IdentityConflictQuery::VERDICT_INVALID ),
		);

		$result = $this->merge()->merge( 5784, 6092 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( 'dissolve', $result->get_error_message() );
	}

	/**
	 * A VALUE NOBODY COULD READ DOES NOT BLOCK THE MERGE.
	 *
	 * The #1071 / #1094 rule at the verb: not having read a value is not
	 * evidence that it is wrong. Refusing here would block every merge on an
	 * install whose encryption key does not match its rows — the ordinary state
	 * of a staging copy, where an operator most needs to rehearse a merge.
	 */
	public function test_an_unreadable_shared_identifier_still_merges(): void {
		$this->given(
			array(
				self::row( 5784, 'cpfA', 'rfShared' ),
				self::row( 6092, 'cpfA', 'rfShared' ),
			)
		);

		$this->verdicts = array(
			'rf' => array( 'rfShared' => IdentityConflictQuery::VERDICT_UNREADABLE ),
		);

		$result = $this->merge()->merge( 5784, 6092 );

		$this->assertIsArray( $result, 'An unreadable verdict is not a refusal.' );
		$this->assertContains( 'COMMIT', $this->control );
	}

	/**
	 * A valid shared identifier merges exactly as before.
	 *
	 * The control for the three above: without it, a refusal caused by the new
	 * question would be indistinguishable from one caused by the harness.
	 */
	public function test_a_valid_shared_identifier_merges(): void {
		$this->given(
			array(
				self::row( 5784, 'cpfA', 'rfShared' ),
				self::row( 6092, 'cpfA', 'rfShared' ),
			)
		);

		$this->verdicts = array(
			'rf' => array( 'rfShared' => IdentityConflictQuery::VERDICT_VALID ),
		);

		$result = $this->merge()->merge( 5784, 6092 );

		$this->assertIsArray( $result );
		$this->assertSame( 6092, $result['emptied'] );
	}

	/**
	 * AN ACCEPTED NUMBER STILL BLOCKS, AND THE REFUSAL STOPS LYING (#1532).
	 *
	 * Accepting a finding as impossible to resolve takes it out of "Numbers to
	 * correct", so a refusal naming that panel would be #1523 returning — a
	 * sentence pointing at a list the finding is not in. What must NOT change is
	 * the verdict: a number nobody can fix is still not evidence that two
	 * accounts are one person, and "unfixable" is not a licence to merge on
	 * evidence known to be bad.
	 */
	public function test_an_accepted_shared_number_refuses_and_names_the_right_panel(): void {
		$this->given(
			array(
				self::row( 5784, 'cpfA', 'rfShared' ),
				self::row( 6092, 'cpfA', 'rfShared' ),
			)
		);

		$this->verdicts = array(
			'rf' => array( 'rfShared' => IdentityConflictQuery::VERDICT_INVALID ),
		);

		$this->accepted = array(
			IdentityAcceptance::key( IdentityQueue::CHECK_DIGITS, 'rf', 'rfShared' ) => array(
				IdentityAcceptance::FIELD_CHECK      => IdentityQueue::CHECK_DIGITS,
				IdentityAcceptance::FIELD_IDENTIFIER => 'rf',
				IdentityAcceptance::FIELD_SUBJECT    => 'rfShared',
				IdentityAcceptance::FIELD_TIER       => IdentityQueue::TIER_ISOLATED,
				IdentityAcceptance::FIELD_REASON     => IdentityAcceptance::REASON_UNIDENTIFIABLE,
			),
		);

		$result = $this->merge()->merge( 5784, 6092 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( 'accepted as impossible to resolve', $result->get_error_message() );
		$this->assertStringContainsString(
			'does not unblock this merge',
			$result->get_error_message(),
			'The refusal must say the verdict is unchanged, or "accepted" reads as "allowed".'
		);
		$this->assertStringNotContainsString(
			'Correct it first, under "Numbers to correct"',
			$result->get_error_message(),
			'The finding has left that panel, so naming it is the #1523 defect again.'
		);
		$this->assertNotContains( 'COMMIT', $this->control, 'Nothing may be written.' );
	}

	/**
	 * An acceptance for the OTHER identifier changes nothing.
	 *
	 * The record is keyed per identifier, so an accepted CPF must not excuse a
	 * refusal about an RF — which is what a lookup ignoring the field would do.
	 */
	public function test_an_acceptance_for_another_identifier_does_not_reword_the_refusal(): void {
		$this->given(
			array(
				self::row( 5784, 'cpfA', 'rfShared' ),
				self::row( 6092, 'cpfA', 'rfShared' ),
			)
		);

		$this->verdicts = array(
			'rf' => array( 'rfShared' => IdentityConflictQuery::VERDICT_INVALID ),
		);

		$this->accepted = array(
			IdentityAcceptance::key( IdentityQueue::CHECK_DIGITS, 'cpf', 'rfShared' ) => array(
				IdentityAcceptance::FIELD_CHECK      => IdentityQueue::CHECK_DIGITS,
				IdentityAcceptance::FIELD_IDENTIFIER => 'cpf',
				IdentityAcceptance::FIELD_SUBJECT    => 'rfShared',
				IdentityAcceptance::FIELD_TIER       => IdentityQueue::TIER_ISOLATED,
			),
		);

		$result = $this->merge()->merge( 5784, 6092 );

		$this->assertStringContainsString( 'Correct it first, under "Numbers to correct"', $result->get_error_message() );
	}
}
