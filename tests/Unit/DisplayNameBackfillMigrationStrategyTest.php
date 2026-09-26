<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Migrations\Strategies\DisplayNameBackfillMigrationStrategy;

/**
 * Naming the accounts a candidacy promotion created without one (#1480).
 *
 * WHAT THESE TESTS ARE FOR, in order of what would hurt.
 *
 * Termination first. This card cannot borrow the capability card's
 * cursor-free shape, because an account whose records name nobody is examined,
 * left alone, and still matches the predicate -- so without a cursor it would
 * be re-selected on every batch forever. That is not hypothetical: the
 * capability card processed 1,848 records over ten accounts by counting what it
 * SELECTED rather than what it CHANGED (#1378). The `$wpdb` double below is
 * cursor-aware for exactly that reason: a double that answered the same ids
 * whatever the cursor said would make the termination assertion theatre.
 *
 * Then the source order, because it is a cost decision with a measurement
 * behind it: the candidacy's `name` is a plain column serving 6,976 of the
 * 6,977 affected accounts, and the submission answers are encrypted and serve
 * 2.
 *
 * @covers \FreeFormCertificate\Migrations\Strategies\DisplayNameBackfillMigrationStrategy
 */
class DisplayNameBackfillMigrationStrategyTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Accounts matching the predicate, in id order.
	 *
	 * @var array<int, int>
	 */
	private array $nameless = array();

	/**
	 * Account id => the name on its most recent candidacy.
	 *
	 * @var array<int, string>
	 */
	private array $candidacy = array();

	/**
	 * Account id => rows of `data` / `data_encrypted`, newest first.
	 *
	 * @var array<int, array<int, array<string, string>>>
	 */
	private array $submissions = array();

	/**
	 * Ciphertext => plaintext.
	 *
	 * @var array<string, string>
	 */
	private array $plain = array();

	/**
	 * What the strategy wrote: account id => name.
	 *
	 * @var array<int, string>
	 */
	private array $written = array();

	/**
	 * The stored cursor, as the option would hold it.
	 *
	 * @var array<string, mixed>
	 */
	private array $state = array();

	/**
	 * Statements the strategy issued.
	 *
	 * @var array<int, string>
	 */
	private array $statements = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->nameless    = array();
		$this->candidacy   = array();
		$this->submissions = array();
		$this->plain       = array();
		$this->written     = array();
		$this->state       = array();
		$this->statements  = array();

		global $wpdb;
		$wpdb           = Mockery::mock( 'wpdb' )->makePartial();
		$wpdb->prefix   = 'wp_';
		$wpdb->users    = 'wp_users';
		$wpdb->usermeta = 'wp_usermeta';

		// The statement travels with its values, because the double has to
		// answer DIFFERENTLY depending on the cursor that was bound.
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( $sql, ...$args ) {
				$this->statements[] = (string) $sql;

				return array( 'sql' => (string) $sql, 'args' => $args );
			}
		);

		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( $prepared ) {
				$sql  = (string) ( $prepared['sql'] ?? '' );
				$args = (array) ( $prepared['args'] ?? array() );

				if ( false !== strpos( $sql, 'SELECT name FROM' ) ) {
					return $this->candidacy[ (int) ( $args[1] ?? 0 ) ] ?? '';
				}

				// The COUNT, whose first bound value is the cursor.
				return count( $this->beyond( (int) ( $args[0] ?? 0 ) ) );
			}
		);

		$wpdb->shouldReceive( 'get_col' )->andReturnUsing(
			function ( $prepared ) {
				$args  = (array) ( $prepared['args'] ?? array() );
				$limit = (int) ( $args[3] ?? 100 );

				return array_slice( $this->beyond( (int) ( $args[0] ?? 0 ) ), 0, $limit );
			}
		);

		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $prepared ) {
				$args = (array) ( $prepared['args'] ?? array() );

				return $this->submissions[ (int) ( $args[1] ?? 0 ) ] ?? array();
			}
		);

		// THE GLOBAL ONE ONLY, never `FreeFormCertificate\Migrations\Strategies\__`.
		//
		// Stubbing the namespaced name CREATES it, and from then on every
		// unqualified `__()` inside that namespace stops falling back to the
		// global -- so four sibling strategy tests that stub only the global
		// started failing with `"__" is not defined nor mocked`, pointing at
		// files this change never touched. The blast radius `CLAUDE.md` records,
		// walked into by copying a sibling's `setUp()`: that one can afford the
		// namespaced stub because it runs in a separate process.
		//
		// PHP's own fallback resolves the unqualified call to the global, so the
		// namespaced stub was never needed here.
		Functions\when( '__' )->returnArg();

		Functions\when( 'get_option' )->alias(
			function ( $name, $default_value = false ) {
				return array() === $this->state ? $default_value : $this->state;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->state = (array) $value;

				return true;
			}
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Accounts matching the predicate beyond one cursor.
	 *
	 * @param int $after The cursor.
	 * @return array<int, int>
	 */
	private function beyond( int $after ): array {
		return array_values(
			array_filter(
				$this->nameless,
				static fn( $id ): bool => $id > $after
			)
		);
	}

	/**
	 * A strategy whose write and decrypt are steerable.
	 *
	 * @return DisplayNameBackfillMigrationStrategy
	 */
	private function strategy(): DisplayNameBackfillMigrationStrategy {
		return new class( $this ) extends DisplayNameBackfillMigrationStrategy {

			/** @var DisplayNameBackfillMigrationStrategyTest */
			private $test;

			/**
			 * @param DisplayNameBackfillMigrationStrategyTest $test The case.
			 */
			public function __construct( $test ) {
				$this->test = $test;
			}

			/**
			 * @param int    $user_id The account.
			 * @param string $name    The name.
			 * @return void
			 */
			protected function write_name( int $user_id, string $name ): void {
				$this->test->record_write( $user_id, $name );
			}

			/**
			 * @param string $cipher Stored ciphertext.
			 * @return string|null
			 */
			protected function decrypt( string $cipher ): ?string {
				return $this->test->plaintext_of( $cipher );
			}
		};
	}

	/**
	 * Record a write the strategy made.
	 *
	 * @param int    $user_id The account.
	 * @param string $name    The name.
	 * @return void
	 */
	public function record_write( int $user_id, string $name ): void {
		$this->written[ $user_id ] = $name;
	}

	/**
	 * What a ciphertext decrypts to.
	 *
	 * @param string $cipher Stored ciphertext.
	 * @return string|null
	 */
	public function plaintext_of( string $cipher ): ?string {
		return $this->plain[ $cipher ] ?? null;
	}

	/**
	 * THE TEST THIS CARD EXISTS FOR.
	 *
	 * An account whose records name nobody is examined, left alone, and still
	 * matches the predicate. The cursor is what stops it coming back: the
	 * second batch must return NOTHING, where a cursor-free card would hand
	 * back the same account and keep doing so.
	 */
	public function test_an_account_that_cannot_be_named_is_not_examined_twice(): void {
		$this->nameless = array( 500 );

		$first = $this->strategy()->execute( 'display_name_backfill', array( 'batch_size' => 10 ) );

		$this->assertSame( 1, $first['processed'], 'It examined the account.' );
		$this->assertSame( array(), $this->written, 'There was no name to write.' );

		$second = $this->strategy()->execute( 'display_name_backfill', array( 'batch_size' => 10 ) );

		$this->assertSame( 0, $second['processed'], 'The cursor moved past it, so the card stops.' );
	}

	/**
	 * And the status agrees: the same account reads as complete afterwards,
	 * because "complete" here measures examined rather than named.
	 */
	public function test_complete_measures_examined_and_not_named(): void {
		$this->nameless = array( 500 );

		$this->strategy()->execute( 'display_name_backfill', array( 'batch_size' => 10 ) );

		$status = $this->strategy()->calculate_status( 'display_name_backfill', array() );

		$this->assertSame( 0, $status['pending'] );
		$this->assertTrue( $status['is_complete'] );
		$this->assertSame( array(), $this->written, 'Complete, and nobody was named.' );
	}

	/**
	 * The candidacy's name is written, which is the case that serves 6,976 of
	 * the 6,977 affected accounts.
	 */
	public function test_it_writes_the_name_from_the_candidacy(): void {
		$this->nameless  = array( 500 );
		$this->candidacy = array( 500 => 'Clarice Fontes Miranda' );

		$out = $this->strategy()->execute( 'display_name_backfill', array( 'batch_size' => 10 ) );

		$this->assertSame( array( 500 => 'Clarice Fontes Miranda' ), $this->written );
		$this->assertSame( 1, $out['processed'] );
	}

	/**
	 * THE ORDER IS A COST DECISION AND IT IS ASSERTED.
	 *
	 * The candidacy is a plain column and the answers are encrypted. An
	 * account carrying both must not cause a decrypt, so the submission read
	 * never happens when the column answered.
	 */
	public function test_the_candidacy_wins_and_no_ciphertext_is_read(): void {
		$this->nameless    = array( 500 );
		$this->candidacy   = array( 500 => 'From The Column' );
		$this->submissions = array(
			500 => array( array( 'data' => '', 'data_encrypted' => 'cipherA' ) ),
		);
		$this->plain       = array( 'cipherA' => '{"nome_completo":"From The Answers"}' );

		$this->strategy()->execute( 'display_name_backfill', array( 'batch_size' => 10 ) );

		$this->assertSame( array( 500 => 'From The Column' ), $this->written );
	}

	/**
	 * A blank candidacy name falls through to the answers: `NOT NULL` is not a
	 * promise that somebody typed something.
	 */
	public function test_a_blank_candidacy_name_falls_through_to_the_answers(): void {
		$this->nameless    = array( 500 );
		$this->candidacy   = array( 500 => '   ' );
		$this->submissions = array(
			500 => array( array( 'data' => '', 'data_encrypted' => 'cipherA' ) ),
		);
		$this->plain       = array( 'cipherA' => '{"participante":"From The Answers"}' );

		$this->strategy()->execute( 'display_name_backfill', array( 'batch_size' => 10 ) );

		$this->assertSame( array( 500 => 'From The Answers' ), $this->written );
	}

	/**
	 * The batch reports examined and named as two numbers, because they are
	 * two facts and one total would hide the accounts it could not help.
	 */
	public function test_the_message_separates_examined_from_named(): void {
		$this->nameless  = array( 500, 501, 502 );
		$this->candidacy = array( 501 => 'Only This One' );

		$out = $this->strategy()->execute( 'display_name_backfill', array( 'batch_size' => 10 ) );

		$this->assertSame( 3, $out['processed'] );
		$this->assertStringContainsString( '3', $out['message'] );
		$this->assertStringContainsString( '1', $out['message'] );
		$this->assertCount( 1, $this->written );
	}

	/**
	 * The cursor advances across batches rather than restarting, so a second
	 * batch reads the NEXT accounts.
	 */
	public function test_the_cursor_carries_between_batches(): void {
		$this->nameless  = array( 10, 20, 30, 40 );
		$this->candidacy = array( 10 => 'A', 20 => 'B', 30 => 'C', 40 => 'D' );

		$first = $this->strategy()->execute( 'display_name_backfill', array( 'batch_size' => 2 ) );
		$this->assertSame( 2, $first['processed'] );
		$this->assertSame( array( 10 => 'A', 20 => 'B' ), $this->written );

		$second = $this->strategy()->execute( 'display_name_backfill', array( 'batch_size' => 2 ) );
		$this->assertSame( 2, $second['processed'] );
		$this->assertSame( array( 10 => 'A', 20 => 'B', 30 => 'C', 40 => 'D' ), $this->written );

		$this->assertSame( 0, $this->strategy()->execute( 'display_name_backfill', array( 'batch_size' => 2 ) )['processed'] );
	}

	/**
	 * A batch size a filter mangled must not report completion having done
	 * nothing: `(int) 'x'` is 0, and a limit of 0 reads no rows (#1060).
	 */
	public function test_an_unusable_batch_size_falls_back_rather_than_reading_nothing(): void {
		$this->nameless  = array( 10 );
		$this->candidacy = array( 10 => 'A' );

		$out = $this->strategy()->execute( 'display_name_backfill', array( 'batch_size' => 'x' ) );

		$this->assertSame( 1, $out['processed'] );
	}

	/**
	 * THE PREDICATE'S TWO CONDITIONS REACH THE STATEMENT.
	 *
	 * Presence only, and said so: this proves the guard is in the SQL, never
	 * that the server applies it the way the docblock claims. What it stops is
	 * the condition being dropped in a rewrite -- `first_name` being empty is
	 * the whole reason this card does not touch an account somebody named.
	 */
	public function test_the_statement_carries_both_halves_of_the_predicate(): void {
		$this->strategy()->calculate_status( 'display_name_backfill', array() );

		$sql = implode( "\n", $this->statements );

		$this->assertStringContainsString( 'display_name = u.user_login', $sql );
		$this->assertStringContainsString( "meta_key = 'first_name'", $sql );
		$this->assertStringContainsString( "fn.meta_value = ''", $sql );
	}

	/**
	 * It never refuses to run: there is nothing it depends on.
	 */
	public function test_it_can_always_run(): void {
		$this->assertTrue( $this->strategy()->can_run( 'display_name_backfill', array() ) );
		$this->assertSame( 'display_name_backfill', $this->strategy()->get_name() );
	}
}
