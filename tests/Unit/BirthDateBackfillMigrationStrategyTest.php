<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Migrations\Strategies\BirthDateBackfillMigrationStrategy;

/**
 * Copying stored birth dates onto the canonical profile field (#1538).
 *
 * Termination comes first, for the reason the display-name card's tests give:
 * an account whose stored answers hold no date is examined, left alone, and
 * still matches the predicate. The `$wpdb` double is cursor-aware so the
 * termination assertion measures the cursor rather than a double that answers
 * the same ids forever.
 *
 * @covers \FreeFormCertificate\Migrations\Strategies\BirthDateBackfillMigrationStrategy
 */
class BirthDateBackfillMigrationStrategyTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Accounts matching the predicate, in id order.
	 *
	 * @var array<int, int>
	 */
	private array $candidates = array();

	/**
	 * Account id => submission `data` JSON strings, newest first.
	 *
	 * @var array<int, array<int, string>>
	 */
	private array $submissions = array();

	/**
	 * Account id => the `ffc_custom_fields_data` snapshot.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $snapshots = array();

	/**
	 * Ids of the `data_nascimento` field rows.
	 *
	 * @var array<int, int>
	 */
	private array $field_ids = array();

	/**
	 * Ciphertext => plaintext.
	 *
	 * @var array<string, string>
	 */
	private array $plain = array();

	/**
	 * What the strategy wrote: account id => ISO date.
	 *
	 * @var array<int, string>
	 */
	private array $written = array();

	/**
	 * The stored cursor option.
	 *
	 * @var array<string, mixed>
	 */
	private array $state = array();

	/**
	 * Transients by name.
	 *
	 * @var array<string, mixed>
	 */
	private array $transients = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->candidates  = array();
		$this->submissions = array();
		$this->snapshots   = array();
		$this->field_ids   = array();
		$this->plain       = array();
		$this->written     = array();
		$this->state       = array();
		$this->transients  = array();

		global $wpdb;
		$wpdb           = Mockery::mock( 'wpdb' )->makePartial();
		$wpdb->prefix   = 'wp_';
		$wpdb->users    = 'wp_users';
		$wpdb->usermeta = 'wp_usermeta';

		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( $sql, ...$args ) {
				return array(
					'sql'  => (string) $sql,
					'args' => $args,
				);
			}
		);

		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( $prepared ) {
				$after = (int) ( $prepared['args'][0] ?? 0 );
				return (string) count( $this->beyond( $after ) );
			}
		);

		$wpdb->shouldReceive( 'get_col' )->andReturnUsing(
			function ( $prepared ) {
				$sql  = (string) $prepared['sql'];
				$args = (array) $prepared['args'];

				if ( false !== strpos( $sql, 'SELECT data FROM' ) ) {
					return $this->submissions[ (int) $args[1] ] ?? array();
				}
				if ( false !== strpos( $sql, 'WHERE field_key = %s' ) ) {
					return array_map( 'strval', $this->field_ids );
				}

				$after = (int) $args[0];
				$limit = (int) end( $args );
				return array_map( 'strval', array_slice( $this->beyond( $after ), 0, $limit ) );
			}
		);

		Functions\when( '__' )->returnArg();
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				return 'ffc_birth_date_backfill_state' === $name ? $this->state : $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				if ( 'ffc_birth_date_backfill_state' === $name ) {
					$this->state = $value;
				}
				return true;
			}
		);
		Functions\when( 'get_transient' )->alias(
			function ( $name ) {
				return $this->transients[ $name ] ?? false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( $name, $value ) {
				$this->transients[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( $name ) {
				unset( $this->transients[ $name ] );
				return true;
			}
		);
		Functions\when( 'get_user_meta' )->alias(
			function ( $user_id, $key ) {
				return 'ffc_custom_fields_data' === $key ? ( $this->snapshots[ $user_id ] ?? '' ) : '';
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Candidates the predicate still holds beyond a cursor. A filled account
	 * leaves the predicate, exactly as the real NOT EXISTS clause makes it.
	 *
	 * @param int $after Cursor.
	 * @return array<int, int>
	 */
	private function beyond( int $after ): array {
		return array_values(
			array_filter(
				$this->candidates,
				fn( $id ): bool => $id > $after && ! isset( $this->written[ $id ] )
			)
		);
	}

	/**
	 * A strategy whose write and decrypt are steerable.
	 *
	 * @return BirthDateBackfillMigrationStrategy
	 */
	private function strategy(): BirthDateBackfillMigrationStrategy {
		return new class( $this ) extends BirthDateBackfillMigrationStrategy {

			/** @var BirthDateBackfillMigrationStrategyTest */
			private $test;

			/**
			 * @param BirthDateBackfillMigrationStrategyTest $test The case.
			 */
			public function __construct( $test ) {
				$this->test = $test;
			}

			/**
			 * @param int    $user_id The account.
			 * @param string $iso     Canonical date.
			 * @return bool
			 */
			protected function write_birth_date( int $user_id, string $iso ): bool {
				return $this->test->record_write( $user_id, $iso );
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
	 * @param string $iso     Canonical date.
	 * @return bool
	 */
	public function record_write( int $user_id, string $iso ): bool {
		$this->written[ $user_id ] = $iso;
		return true;
	}

	/**
	 * Decrypt through the case's table.
	 *
	 * @param string $cipher Ciphertext.
	 * @return string|null
	 */
	public function plaintext_of( string $cipher ): ?string {
		return $this->plain[ $cipher ] ?? null;
	}

	/**
	 * Run batches until the card reports complete, failing on a loop.
	 *
	 * @return int Batches run.
	 */
	private function run_to_completion(): int {
		$strategy = $this->strategy();
		for ( $i = 1; $i <= 20; $i++ ) {
			$strategy->execute( 'birth_date_backfill', array( 'batch_size' => 2 ) );
			if ( $strategy->calculate_status( 'birth_date_backfill', array() )['is_complete'] ) {
				return $i;
			}
		}
		$this->fail( 'The card never reported complete -- the #1378 loop.' );
	}

	public function test_the_newest_submission_wins_over_older_ones_and_the_snapshot(): void {
		$this->candidates      = array( 5 );
		$this->field_ids       = array( 11 );
		$this->submissions[5]  = array(
			(string) json_encode( array( 'fields' => array( 'data_nascimento' => '1991-01-02' ) ) ),
			(string) json_encode( array( 'fields' => array( 'data_nascimento' => '1980-07-08' ) ) ),
		);
		$this->snapshots[5]    = array( 'field_11' => '1970-03-04' );

		$this->run_to_completion();

		$this->assertSame( array( 5 => '1991-01-02' ), $this->written );
	}

	public function test_the_snapshot_is_read_when_no_submission_carries_a_date_and_the_newest_field_wins(): void {
		$this->candidates     = array( 5 );
		$this->field_ids      = array( 11, 40 );
		$this->submissions[5] = array( (string) json_encode( array( 'fields' => array( 'sexo' => 'F' ) ) ) );
		$this->snapshots[5]   = array(
			'field_11' => '1970-03-04',
			'field_40' => '20/05/1990',
		);

		$this->run_to_completion();

		$this->assertSame( array( 5 => '1990-05-20' ), $this->written, 'The field seeded last answers, in canonical form.' );
	}

	public function test_a_value_encrypted_because_the_field_was_flagged_sensitive_is_decrypted(): void {
		$this->candidates     = array( 5 );
		$this->field_ids      = array( 11 );
		$this->plain['CIPHER'] = '1985-12-31';
		$this->submissions[5] = array( (string) json_encode( array( 'fields' => array( 'data_nascimento' => 'CIPHER' ) ) ) );

		$this->run_to_completion();

		$this->assertSame( array( 5 => '1985-12-31' ), $this->written );
	}

	/**
	 * The termination property: an account with nothing usable is examined
	 * once, and the card still completes.
	 */
	public function test_accounts_without_a_usable_date_do_not_stop_the_card_from_completing(): void {
		$this->candidates = array( 3, 5, 7, 9, 11 );
		$this->field_ids  = array( 11 );

		$this->submissions[5] = array( (string) json_encode( array( 'fields' => array( 'data_nascimento' => 'garbage' ) ) ) );
		$this->snapshots[9]   = array( 'field_11' => '1999-09-09' );

		$batches = $this->run_to_completion();

		$this->assertSame( array( 9 => '1999-09-09' ), $this->written );
		$this->assertLessThanOrEqual( 3, $batches, 'Five candidates at two per batch must finish in three batches.' );
	}

	public function test_execute_counts_what_it_filled_not_what_it_selected(): void {
		$this->candidates     = array( 3, 5 );
		$this->field_ids      = array( 11 );
		$this->snapshots[5]   = array( 'field_11' => '1999-09-09' );

		$result = $this->strategy()->execute( 'birth_date_backfill', array( 'batch_size' => 10 ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['processed'], 'A batch that examined two and filled one reports one.' );
	}

	public function test_an_empty_population_is_complete_and_a_batch_does_nothing(): void {
		$strategy = $this->strategy();

		$status = $strategy->calculate_status( 'birth_date_backfill', array() );
		$result = $strategy->execute( 'birth_date_backfill', array() );

		$this->assertTrue( $status['is_complete'] );
		$this->assertSame( 100.0, (float) $status['percent'] );
		$this->assertSame( 0, $result['processed'] );
		$this->assertSame( array(), $this->written );
	}

	public function test_the_name_is_the_registry_key(): void {
		$this->assertSame( 'birth_date_backfill', $this->strategy()->get_name() );
		$this->assertTrue( $this->strategy()->can_run( 'birth_date_backfill', array() ) );
	}

	/**
	 * The Date Messages screen reads the card's own pending count, cached so
	 * a page load does not walk `wp_users` every time.
	 */
	public function test_pending_accounts_is_the_cards_pending_and_is_cached(): void {
		$this->candidates = array( 3, 5, 7 );
		$this->state      = array( 'cursor' => 3 );

		$this->assertSame( 2, BirthDateBackfillMigrationStrategy::pending_accounts() );
		$this->assertSame( 2, $this->transients[ BirthDateBackfillMigrationStrategy::PENDING_TRANSIENT ] ?? null );

		// Served from the cache: a change underneath is not seen until a batch clears it.
		$this->candidates = array();
		$this->assertSame( 2, BirthDateBackfillMigrationStrategy::pending_accounts() );
	}

	public function test_every_batch_clears_the_cached_pending_count(): void {
		$this->candidates = array( 3, 5 );
		$this->transients[ BirthDateBackfillMigrationStrategy::PENDING_TRANSIENT ] = 2;

		$this->strategy()->execute( 'birth_date_backfill', array( 'batch_size' => 10 ) );

		$this->assertArrayNotHasKey( BirthDateBackfillMigrationStrategy::PENDING_TRANSIENT, $this->transients );
		$this->assertSame( 0, BirthDateBackfillMigrationStrategy::pending_accounts() );
	}

	public function test_an_empty_batch_clears_the_cache_too(): void {
		$this->transients[ BirthDateBackfillMigrationStrategy::PENDING_TRANSIENT ] = 4;

		$this->strategy()->execute( 'birth_date_backfill', array() );

		$this->assertArrayNotHasKey( BirthDateBackfillMigrationStrategy::PENDING_TRANSIENT, $this->transients );
	}
}
