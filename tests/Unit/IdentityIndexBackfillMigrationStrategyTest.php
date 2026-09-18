<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Migrations\Strategies\IdentityIndexBackfillMigrationStrategy;

/**
 * The card that projects linked identifiers into the index (#1313 PR 8).
 *
 * WHAT IS WORTH PINNING HERE
 *
 * Three decisions, each of which is a judgement the SQL could quietly reverse:
 * it fills an empty column and never picks between two, it never overwrites a
 * column that already answers, and it refuses to run before the
 * canonicalisation card is done. The last one has no second chance -- a hash
 * copied too early lands in a profile row with no ciphertext to repair it from
 * -- so the gate is asserted on both sides and re-asserted on `execute()`,
 * which is the path that writes.
 *
 * THE REPOSITORY IS AN `overload:` DOUBLE, NOT AN `alias:`
 *
 * The strategy does `new UserProfileRepository()` and calls INSTANCE methods.
 * `alias:` intercepts statics only, so it reports the method as not existing
 * -- the same distinction #1321 recorded from the other side. What this file
 * measures is WHICH columns the strategy decides to write, so the double
 * records them and the repository's own behaviour stays its own tests'
 * subject. The writes are captured and asserted on rather than pinned with
 * `->once()`, because an `overload:` expectation is only verified once an
 * instance is built and would pass on a call that never happened.
 *
 * @covers \FreeFormCertificate\Migrations\Strategies\IdentityIndexBackfillMigrationStrategy
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class IdentityIndexBackfillMigrationStrategyTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<int, array<string, string|null>> Profile rows, keyed by user id. */
	private array $profiles = array();

	/** @var list<array{user_id: int, index: array<string, string>}> Index writes the strategy asked for. */
	private array $writes = array();

	/** @var array<int, array<string, list<string>>> Hashes each user carries, per column. */
	private array $source = array();

	/** @var array<string, mixed> */
	private array $options = array();

	/** What the canonicalisation card reports as pending. */
	private int $canonicalisation_pending = 0;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		class_exists( '\FreeFormCertificate\Migrations\Strategies\IdentityIndexBackfillMigrationStrategy' );

		$this->profiles                 = array();
		$this->writes                   = array();
		$this->source                   = array();
		$this->options                  = array();
		$this->canonicalisation_pending = 0;

		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' )->makePartial();
		$wpdb->prefix = 'wp_';

		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( $sql, ...$a ): string {
				$values = ( 1 === count( $a ) && is_array( $a[0] ) ) ? $a[0] : $a;
				foreach ( $values as $v ) {
					$sql = preg_replace( '/%[ids]/', (string) $v, (string) $sql, 1 );
				}
				return (string) $sql;
			}
		)->byDefault();

		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( $sql ) {
				$sql = (string) $sql;

				if ( str_contains( $sql, 'SHOW TABLES LIKE' ) ) {
					// Only submissions and the index exist in this harness, so
					// the union is built from one source and the assertions
					// below are about the decision, not about which table.
					foreach ( array( 'wp_ffc_submissions', 'wp_ffc_user_profiles' ) as $known ) {
						if ( str_contains( $sql, $known ) ) {
							return $known;
						}
					}
					return null;
				}

				if ( str_contains( $sql, 'COUNT(*)' ) ) {
					return (string) count( $this->users_after( $this->cursor_in( $sql ) ) );
				}

				return 0;
			}
		)->byDefault();

		$wpdb->shouldReceive( 'get_col' )->andReturnUsing(
			function ( $sql ) {
				$sql = (string) $sql;

				if ( str_contains( $sql, 'SELECT DISTINCT user_id' ) ) {
					return array_map( 'strval', $this->users_after( $this->cursor_in( $sql ) ) );
				}

				// The per-user hash probe: `SELECT DISTINCT h FROM ( … ) u LIMIT 2`.
				if ( 1 === preg_match( '/user_id = (\d+)/', $sql, $m ) ) {
					$column = str_contains( $sql, 'rf_hash' ) ? 'rf_hash' : 'cpf_hash';
					$all    = $this->source[ (int) $m[1] ][ $column ] ?? array();

					return array_slice( $all, 0, 2 );
				}

				return array();
			}
		)->byDefault();

		Functions\when( '__' )->returnArg();
		Functions\when( '_x' )->returnArg();
		Functions\when( 'is_wp_error' )->alias( static fn( $t ) => $t instanceof \WP_Error );
		Functions\when( 'get_option' )->alias(
			function ( $key, $default_value = false ) {
				return $this->options[ $key ] ?? $default_value;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) {
				$this->options[ $key ] = $value;
				return true;
			}
		);

		if ( ! class_exists( 'FreeFormCertificate\Migrations\Strategies\WP_Error' ) ) {
			class_alias( 'WP_Error', 'FreeFormCertificate\Migrations\Strategies\WP_Error' );
		}

		$repository = Mockery::mock( 'overload:FreeFormCertificate\Repositories\UserProfileRepository' );
		$repository->shouldReceive( 'findByUserId' )->andReturnUsing(
			fn( $user_id ) => $this->profiles[ (int) $user_id ] ?? null
		)->byDefault();
		$repository->shouldReceive( 'upsertForUserId' )->andReturnUsing(
			function ( $user_id, $index ) {
				$this->writes[] = array(
					'user_id' => (int) $user_id,
					'index'   => $index,
				);
				return true;
			}
		)->byDefault();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The strategy, with only the cross-card question stubbed.
	 */
	private function strategy(): IdentityIndexBackfillMigrationStrategy {
		$pending = &$this->canonicalisation_pending;

		return new class( $pending ) extends IdentityIndexBackfillMigrationStrategy {
			/** @var int */
			private int $pending;

			/**
			 * @param int $pending What the canonicalisation card reports.
			 */
			public function __construct( int $pending ) {
				$this->pending = $pending;
			}

			/**
			 * @return int
			 */
			protected function canonicalisation_pending(): int {
				return $this->pending;
			}
		};
	}

	/**
	 * Users carrying a hash, beyond a cursor.
	 *
	 * @param int $after Cursor.
	 * @return list<int>
	 */
	private function users_after( int $after ): array {
		$out = array();
		foreach ( array_keys( $this->source ) as $user_id ) {
			if ( $user_id > $after ) {
				$out[] = (int) $user_id;
			}
		}
		sort( $out );

		return $out;
	}

	/**
	 * The cursor the statement carries.
	 *
	 * @param string $sql Interpolated statement.
	 * @return int
	 */
	private function cursor_in( string $sql ): int {
		return 1 === preg_match( '/user_id > (\d+)/', $sql, $m ) ? (int) $m[1] : 0;
	}

	// =====================================================================
	// The gate
	// =====================================================================

	public function test_it_refuses_while_the_canonicalisation_card_has_pending_rows(): void {
		$this->canonicalisation_pending = 12;

		$result = $this->strategy()->can_run( '', array() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'canonicalisation_pending', $result->get_error_code() );
	}

	public function test_it_runs_once_the_canonicalisation_card_is_done(): void {
		$this->canonicalisation_pending = 0;

		$this->assertTrue( $this->strategy()->can_run( '', array() ) );
	}

	/**
	 * The gate is re-read by the path that WRITES.
	 *
	 * `MigrationStatusCalculator::execute()` checks `can_run()` first, so this
	 * looks redundant — and it is exactly the redundancy that matters: a caller
	 * reaching the strategy another way would otherwise copy pre-canonical
	 * hashes into rows nothing can repair afterwards.
	 */
	public function test_execute_refuses_too_and_writes_nothing(): void {
		$this->canonicalisation_pending = 3;
		$this->source                   = array( 7 => array( 'cpf_hash' => array( 'aaa' ) ) );

		$result = $this->strategy()->execute( '', array(), 0 );

		$this->assertFalse( $result['success'] );
		$this->assertSame( array(), $this->writes, 'A refused run still wrote to the index.' );
	}

	/**
	 * An unreadable answer from the other card is a refusal, not a zero.
	 *
	 * This exercises the REAL `canonicalisation_pending()`, which every other
	 * test replaces through the seam. The number it reads decides whether a
	 * write that cannot be undone is allowed, and the sibling's status array is
	 * `array<string, mixed>` — so a shape that is not numeric means the
	 * question was not answered, which is not the same as "nothing pending".
	 * Level 9 is what forced the value to be checked rather than cast; failing
	 * CLOSED is the decision that check then had to make, and this is what
	 * stops a later edit from quietly turning it into `return 0`.
	 */
	public function test_an_unreadable_pending_count_refuses_rather_than_assuming_zero(): void {
		Mockery::mock( 'overload:FreeFormCertificate\Migrations\Strategies\IdentityNormalizationMigrationStrategy' )
			->shouldReceive( 'calculate_status' )
			->andReturn( array( 'pending' => 'not a number' ) );

		$result = ( new IdentityIndexBackfillMigrationStrategy() )->can_run( '', array() );

		$this->assertInstanceOf( \WP_Error::class, $result, 'An unreadable pending count was treated as "nothing pending", which opens the gate this card exists to keep shut.' );
		$this->assertSame( 'canonicalisation_pending', $result->get_error_code() );
	}

	// =====================================================================
	// The decision
	// =====================================================================

	public function test_one_identifier_fills_the_empty_column(): void {
		$this->source = array( 7 => array( 'cpf_hash' => array( 'the-cpf-hash' ) ) );

		$result = $this->strategy()->execute( '', array(), 0 );

		$this->assertTrue( $result['success'] );
		$this->assertCount( 1, $this->writes );
		$this->assertSame( 7, $this->writes[0]['user_id'] );
		$this->assertSame( array( 'cpf_hash' => 'the-cpf-hash' ), $this->writes[0]['index'] );
	}

	/**
	 * TWO IDENTIFIERS ARE A CONFLICT, NOT A TIE TO BREAK.
	 *
	 * One account carrying two different CPFs is the thing #1313 wants counted
	 * before anyone designs a merge policy. Writing either one would look like
	 * an answer and would destroy the only evidence that the question exists.
	 */
	public function test_two_identifiers_write_nothing_and_are_reported(): void {
		$this->source = array( 8 => array( 'cpf_hash' => array( 'one-hash', 'another-hash' ) ) );

		$result = $this->strategy()->execute( '', array(), 0 );

		$this->assertTrue( $result['success'] );
		$this->assertSame( array(), $this->writes, 'The card picked one of two conflicting identifiers instead of leaving the conflict visible.' );
		$this->assertStringContainsString( '1', $result['message'] );
	}

	/**
	 * A column that already answers is never overwritten.
	 *
	 * The forward path (`UserCreator::feed_identity_index()`) states exactly
	 * this; a backfill that disagreed would make the index depend on which of
	 * the two ran last.
	 */
	public function test_it_never_overwrites_a_column_that_already_holds_a_hash(): void {
		$this->source   = array( 9 => array( 'cpf_hash' => array( 'from-the-module-table' ) ) );
		$this->profiles = array(
			9 => array(
				'user_id'  => 9,
				'cpf_hash' => 'already-in-the-index',
				'rf_hash'  => null,
			),
		);

		$this->strategy()->execute( '', array(), 0 );

		$this->assertSame( array(), $this->writes, 'A column that already held a hash was overwritten by the backfill.' );
	}

	public function test_it_fills_the_empty_column_of_a_partially_filled_row(): void {
		$this->source   = array(
			10 => array(
				'cpf_hash' => array( 'cpf-from-module' ),
				'rf_hash'  => array( 'rf-from-module' ),
			),
		);
		$this->profiles = array(
			10 => array(
				'user_id'  => 10,
				'cpf_hash' => 'already-there',
				'rf_hash'  => null,
			),
		);

		$this->strategy()->execute( '', array(), 0 );

		$this->assertCount( 1, $this->writes );
		$this->assertSame(
			array( 'rf_hash' => 'rf-from-module' ),
			$this->writes[0]['index'],
			'The write should carry only the column that was empty.'
		);
	}

	// =====================================================================
	// Progress
	// =====================================================================

	public function test_the_cursor_advances_so_a_second_run_resumes(): void {
		$this->source = array(
			3 => array( 'cpf_hash' => array( 'a' ) ),
			4 => array( 'cpf_hash' => array( 'b' ) ),
		);

		$this->strategy()->execute( '', array(), 0 );

		$this->assertSame( 4, $this->options['ffc_identity_index_backfill_state']['cursor'] );
	}

	public function test_an_empty_batch_completes_the_card(): void {
		$this->source = array();

		$result = $this->strategy()->execute( '', array(), 0 );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 0, $result['processed'] );
		$this->assertTrue( $this->strategy()->calculate_status( '', array() )['is_complete'] );
	}

	public function test_status_reports_the_walk_rather_than_the_content(): void {
		$this->source = array(
			1 => array( 'cpf_hash' => array( 'a' ) ),
			2 => array( 'cpf_hash' => array( 'b' ) ),
			3 => array( 'cpf_hash' => array( 'c' ) ),
		);

		$status = $this->strategy()->calculate_status( '', array() );

		$this->assertSame( 3, $status['total'] );
		$this->assertSame( 3, $status['pending'] );
		$this->assertFalse( $status['is_complete'] );
	}
}
