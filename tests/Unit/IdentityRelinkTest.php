<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Maintenance\IdentityRelink;

/**
 * Moving records to the account they belong to (#1386).
 *
 * The agreement rule carries this class. A move allowed where it should have
 * been refused puts one person's certificate under another person's login, and
 * nothing downstream can tell: every row is well formed afterwards, and the
 * account reads it happily. So each refusal is asserted by driving the exact
 * condition, never by reading the code.
 *
 * @covers \FreeFormCertificate\Maintenance\IdentityRelink
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class IdentityRelinkTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $wpdb;

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
	 * Transaction control statements, in order.
	 *
	 * @var array<int, string>
	 */
	private array $control = array();

	/**
	 * Whether the stubbed identity index accepts a write.
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
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		class_exists( '\FreeFormCertificate\Maintenance\IdentityRelink' );

		$this->rows     = array();
		$this->index    = array();
		$this->updates  = array();
		$this->control  = array();
		$this->index_ok = true;
		$this->refusing = array();

		Functions\when( '__' )->returnArg( 1 );
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

		// Every `ffc_*` table this class knows exists.
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			static function ( $prepared ) {
				return $prepared['args'][0] ?? null;
			}
		);

		// Two statements reach `get_results`: the moving rows, keyed on a hash
		// COLUMN and a value, and the target's own rows, keyed on `user_id`.
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $prepared ) {
				$sql   = (string) ( $prepared['sql'] ?? '' );
				$table = (string) ( $prepared['args'][0] ?? '' );
				$rows  = $this->rows[ $table ] ?? array();

				if ( false !== strpos( $sql, 'user_id = %d' ) ) {
					$owner = (int) ( $prepared['args'][1] ?? 0 );

					return array_values(
						array_filter(
							$rows,
							static function ( $row ) use ( $owner ) {
								return (int) ( $row['user_id'] ?? 0 ) === $owner;
							}
						)
					);
				}

				$column = (string) ( $prepared['args'][1] ?? '' );
				$hash   = (string) ( $prepared['args'][2] ?? '' );

				return array_values(
					array_filter(
						$rows,
						static function ( $row ) use ( $column, $hash ) {
							return ( $row[ $column ] ?? '' ) === $hash;
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
	 * A relink whose index writes can be steered.
	 *
	 * @return IdentityRelink
	 */
	private function relink(): IdentityRelink {
		return new class( $this ) extends IdentityRelink {

			/** @var IdentityRelinkTest */
			private $test;

			/**
			 * Take the case, for the index verdict.
			 *
			 * @param IdentityRelinkTest $test The case.
			 */
			public function __construct( $test ) {
				$this->test = $test;
			}

			/**
			 * The index write, without a repository.
			 *
			 * @param int                   $user_id Account.
			 * @param array<string, string> $data    Columns.
			 * @return bool
			 */
			protected function reindex( int $user_id, array $data ): bool {
				return $this->test->record_index( $user_id, $data );
			}
		};
	}

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
	 * @param string                           $table Unprefixed table.
	 * @param array<int, array<string, mixed>> $rows  The rows.
	 * @return void
	 */
	private function given( string $table, array $rows ): void {
		$this->rows[ 'wp_' . $table ] = $rows;
	}

	/**
	 * One record row.
	 *
	 * @param int    $id    Row id.
	 * @param int    $owner Account.
	 * @param string $cpf   Its cpf_hash.
	 * @param string $rf    Its rf_hash.
	 * @return array<string, mixed>
	 */
	private static function row( int $id, int $owner, string $cpf, string $rf ): array {
		return array(
			'id'       => $id,
			'user_id'  => $owner,
			'cpf_hash' => $cpf,
			'rf_hash'  => $rf,
		);
	}

	/**
	 * THE ORDINARY SHAPE OF A RELINK IS A GAP, AND THAT IS NOT AN ACCIDENT.
	 *
	 * The records carry an identifier that belongs to the target person, and
	 * the target account has no value of that kind yet — so the shared CPF is
	 * what ties them together and the RF is what the account gains. A target
	 * that ALREADY holds the same identifier on its own rows makes the finding
	 * a merge instead, and is refused as ambiguous below.
	 */
	public function test_it_moves_records_that_agree_with_the_target_on_another_identifier(): void {
		$this->given(
			'ffc_submissions',
			array(
				self::row( 1, 398, 'cpfA', 'rfMoving' ),
				self::row( 2, 513, 'cpfA', '' ),
			)
		);

		$result = $this->relink()->relink( 'rfMoving', 513 );

		$this->assertIsArray( $result );
		$this->assertSame( 398, $result['from'] );
		$this->assertContains( 'COMMIT', $this->control );
		$this->assertSame( array( 'user_id' => 513 ), $this->updates[0]['data'] );
		$this->assertSame( array( 'rf_hash' => 'rfMoving' ), $this->updates[0]['where'] );
	}

	/**
	 * The index alone can carry the agreement: an account whose records all
	 * sat under the wrong login still knows who it is.
	 */
	public function test_an_agreement_carried_only_by_the_index_is_enough(): void {
		$this->given(
			'ffc_submissions',
			array( self::row( 1, 398, 'cpfA', 'rfMoving' ) )
		);
		$this->index[513] = array(
			'cpf_hash' => 'cpfA',
			'rf_hash'  => 'rfMoving',
		);

		$result = $this->relink()->relink( 'rfMoving', 513 );

		$this->assertIsArray( $result );
		$this->assertContains( 'COMMIT', $this->control );
	}

	/**
	 * AN ABSENT VALUE IS NOT AGREEMENT.
	 *
	 * The target holds nothing the records also hold, so nothing ties the two
	 * together. An operator who believes they belong together says so by
	 * correcting an identifier first, which leaves a trace; a move on belief
	 * alone leaves none.
	 */
	public function test_it_refuses_a_move_to_an_account_that_shares_nothing(): void {
		$this->given(
			'ffc_submissions',
			array(
				self::row( 1, 398, 'cpfA', 'rfMoving' ),
				self::row( 2, 513, '', '' ),
			)
		);

		$result = $this->relink()->relink( 'rfMoving', 513 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_identity_relink_no_agreement', $result->get_error_code() );
		$this->assertSame( array(), $this->updates );
	}

	/**
	 * THE GAP-FILLING HALF: the target has no RF at all, so it gains the one
	 * the records carry and ends up holding both documents.
	 */
	public function test_a_target_holding_no_value_of_that_kind_gains_it(): void {
		$this->given(
			'ffc_submissions',
			array(
				self::row( 1, 398, 'cpfA', 'rfMoving' ),
				self::row( 2, 513, 'cpfA', '' ),
			)
		);

		$result = $this->relink()->relink( 'rfMoving', 513 );

		$this->assertIsArray( $result );

		$written = array_values(
			array_filter(
				$this->updates,
				static function ( $update ) {
					return 'index' === $update['table'] && 513 === ( $update['where']['user_id'] ?? 0 );
				}
			)
		);

		$this->assertCount( 1, $written );
		$this->assertSame( 'rfMoving', $written[0]['data']['rf_hash'] );
	}

	/**
	 * A DISAGREEMENT REFUSES THE WHOLE MOVE, whatever else matches.
	 *
	 * The CPF ties the two together and the target already holds a DIFFERENT
	 * RF, so completing the move would leave that account holding two — the
	 * very defect this queue exists to resolve, recreated one account along.
	 * The repair comes first.
	 */
	public function test_it_refuses_when_a_second_identifier_disagrees(): void {
		$this->given(
			'ffc_submissions',
			array(
				self::row( 1, 398, 'cpfA', 'rfMoving' ),
				self::row( 2, 513, 'cpfA', 'rfTarget' ),
			)
		);

		$result = $this->relink()->relink( 'rfMoving', 513 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_identity_relink_conflict', $result->get_error_code() );
		$this->assertSame( array(), $this->updates );
	}

	/**
	 * One identifier on two accounts is a MERGE, and a relink refuses it: the
	 * decision there is which account survives, not where records go.
	 */
	public function test_records_the_target_already_shares_are_a_merge_not_a_relink(): void {
		$this->given(
			'ffc_submissions',
			array(
				self::row( 1, 398, 'cpfA', 'rfShared' ),
				self::row( 2, 513, 'cpfA', 'rfShared' ),
			)
		);

		$result = $this->relink()->relink( 'rfShared', 513 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_identity_relink_ambiguous', $result->get_error_code() );
		$this->assertSame( array(), $this->updates );
	}

	/**
	 * Records carrying two different CPFs cannot fill an empty slot: they do
	 * not say which value the account would gain, and picking invents one.
	 */
	public function test_records_that_disagree_with_themselves_cannot_fill_a_gap(): void {
		$this->given(
			'ffc_submissions',
			array(
				self::row( 1, 398, 'cpfA', 'rfMoving' ),
				self::row( 2, 398, 'cpfB', 'rfMoving' ),
				self::row( 3, 513, '', 'rfMoving2' ),
			)
		);
		$this->index[513] = array(
			'cpf_hash' => '',
			'rf_hash'  => 'rfMoving',
		);

		$result = $this->relink()->relink( 'rfMoving', 513 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_identity_relink_conflict', $result->get_error_code() );
	}

	/**
	 * THE INDEX IS READ TOO, NOT ONLY THE ROWS.
	 *
	 * An account can be indexed under a value whose rows have already moved
	 * away. Reading the rows alone would call that an empty slot and fill it,
	 * overwriting an answer that disagrees.
	 */
	public function test_the_target_s_indexed_value_counts_as_held(): void {
		$this->given(
			'ffc_submissions',
			array(
				self::row( 1, 398, 'cpfA', 'rfMoving' ),
			)
		);
		$this->index[513] = array(
			'cpf_hash' => 'cpfB',
			'rf_hash'  => '',
		);

		$result = $this->relink()->relink( 'rfMoving', 513 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame(
			'ffc_identity_relink_conflict',
			$result->get_error_code(),
			'A value the index holds must be compared, or it is silently overwritten.'
		);
	}

	/**
	 * Records split across two accounts cannot be moved by one decision.
	 */
	public function test_it_refuses_records_split_across_two_accounts(): void {
		$this->given(
			'ffc_submissions',
			array(
				self::row( 1, 398, 'cpfA', 'rfMoving' ),
				self::row( 2, 777, 'cpfA', 'rfMoving' ),
			)
		);

		$result = $this->relink()->relink( 'rfMoving', 513 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_identity_relink_ambiguous', $result->get_error_code() );
		$this->assertSame( array(), $this->updates );
	}

	/**
	 * Moving records to where they already are is a result, not a failure.
	 */
	public function test_a_move_to_the_account_that_already_owns_them_is_refused(): void {
		$this->given(
			'ffc_submissions',
			array( self::row( 1, 513, 'cpfA', 'rfMoving' ) )
		);

		$result = $this->relink()->relink( 'rfMoving', 513 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_identity_relink_unchanged', $result->get_error_code() );
		$this->assertSame( array(), $this->updates );
	}

	/**
	 * Nothing carries the identifier any more — somebody else resolved it
	 * between the list and the confirmation.
	 */
	public function test_a_move_of_records_that_are_gone_is_refused(): void {
		$result = $this->relink()->relink( 'rfMoving', 513 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_identity_relink_gone', $result->get_error_code() );
	}

	/**
	 * A store refusing the write rolls the whole move back: half-moved records
	 * are worse than none, because the account reads them as complete.
	 */
	public function test_a_store_refusal_rolls_the_move_back(): void {
		$this->given(
			'ffc_submissions',
			array(
				self::row( 1, 398, 'cpfA', 'rfMoving' ),
				self::row( 2, 513, 'cpfA', '' ),
			)
		);
		$this->refusing = array( 'wp_ffc_submissions' );

		$result = $this->relink()->relink( 'rfMoving', 513 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_identity_relink_failed', $result->get_error_code() );
		$this->assertContains( 'ROLLBACK', $this->control );
		$this->assertNotContains( 'COMMIT', $this->control );
	}

	/**
	 * The index refusing rolls back too: rows and index disagreeing about who
	 * owns a record is the one state neither side can detect afterwards.
	 */
	public function test_an_index_refusal_rolls_the_move_back(): void {
		$this->given(
			'ffc_submissions',
			array(
				self::row( 1, 398, 'cpfA', 'rfMoving' ),
				self::row( 2, 513, 'cpfA', '' ),
			)
		);
		$this->index_ok = false;

		$result = $this->relink()->relink( 'rfMoving', 513 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_identity_relink_index_failed', $result->get_error_code() );
		$this->assertContains( 'ROLLBACK', $this->control );
		$this->assertNotContains( 'COMMIT', $this->control );
	}

	/**
	 * An identifier this class does not move is refused before anything is
	 * read, rather than composing a statement against a column that may exist.
	 */
	public function test_it_refuses_an_identifier_it_does_not_move(): void {
		$result = $this->relink()->relink( 'hash', 513, 0, 'email' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_identity_relink_unknown_field', $result->get_error_code() );
	}

	/**
	 * A move with no account named writes nothing.
	 */
	public function test_it_refuses_a_move_with_no_account( ): void {
		foreach ( array( 0, -1 ) as $target ) {
			$result = $this->relink()->relink( 'rfMoving', $target );

			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 'ffc_identity_relink_no_target', $result->get_error_code() );
		}

		$this->assertSame( array(), $this->updates );
	}

	/**
	 * The origin stops being indexed under what it no longer holds — an index
	 * that answers confidently with a wrong account is worse than an empty one.
	 */
	public function test_the_origin_s_index_entry_is_cleared(): void {
		$this->given(
			'ffc_submissions',
			array(
				self::row( 1, 398, 'cpfA', 'rfMoving' ),
				self::row( 2, 513, 'cpfA', '' ),
			)
		);

		$this->relink()->relink( 'rfMoving', 513 );

		$cleared = array_values(
			array_filter(
				$this->updates,
				static function ( $update ) {
					return 'wp_ffc_user_profiles' === $update['table'];
				}
			)
		);

		$this->assertCount( 1, $cleared );
		$this->assertNull( $cleared[0]['data']['rf_hash'] );
		$this->assertSame( 398, $cleared[0]['where']['user_id'] );
		$this->assertSame(
			'rfMoving',
			$cleared[0]['where']['rf_hash'],
			'Clearing must be conditional on the VALUE, or an unrelated entry is lost.'
		);
	}
}
