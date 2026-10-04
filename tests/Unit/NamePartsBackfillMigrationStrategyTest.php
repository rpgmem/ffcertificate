<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Migrations\Strategies\NamePartsBackfillMigrationStrategy;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Splitting full names stored whole in `first_name` (#1552).
 *
 * The `$wpdb` double is cursor-aware and keeps a one-word name in the
 * predicate after it is processed, exactly as the real SQL does, so the
 * termination test measures the cursor (#1378).
 *
 * @covers \FreeFormCertificate\Migrations\Strategies\NamePartsBackfillMigrationStrategy
 */
class NamePartsBackfillMigrationStrategyTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Candidate rows by user id.
	 *
	 * @var array<int, array<string, string>>
	 */
	private array $rows = array();

	/**
	 * Writes: user id => [first, last].
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	private array $written = array();

	/**
	 * Stored cursor option.
	 *
	 * @var array<string, mixed>
	 */
	private array $state = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		global $wpdb;
		$wpdb           = Mockery::mock( 'wpdb' )->makePartial();
		$wpdb->prefix   = 'wp_';
		$wpdb->users    = 'wp_users';
		$wpdb->usermeta = 'wp_usermeta';

		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static fn( $sql, ...$args ) => array( 'sql' => (string) $sql, 'args' => $args )
		);
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			fn( $prepared ) => (string) count( $this->beyond( (int) $prepared['args'][1] ) )
		);
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $prepared ) {
				$this->assertStringContainsString( 'INNER JOIN %i p', $prepared['sql'] );
				$this->assertSame( 'wp_ffc_user_profiles', $prepared['args'][0] );
				return array_slice( $this->beyond( (int) $prepared['args'][1] ), 0, (int) $prepared['args'][2] );
			}
		);

		Functions\when( '__' )->returnArg();
		Functions\when( 'get_option' )->alias( fn( $name, $default = false ) => 'ffc_name_parts_backfill_state' === $name ? $this->state : $default );
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->state = $value;
				return true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Rows still matching the predicate beyond a cursor.
	 *
	 * @param int $after Cursor.
	 * @return array<int, array<string, string>>
	 */
	private function beyond( int $after ): array {
		$out = array();
		foreach ( $this->rows as $id => $row ) {
			if ( $id <= $after ) {
				continue;
			}
			// A split name with a last name leaves the predicate.
			if ( isset( $this->written[ $id ] ) && '' !== $this->written[ $id ][1] ) {
				continue;
			}
			$out[] = $row + array( 'user_id' => (string) $id );
		}
		return $out;
	}

	/**
	 * A candidate row.
	 *
	 * @param int    $id      User id.
	 * @param string $profile Profile name.
	 * @param string $display WordPress display name.
	 * @param string $first   Stored first name.
	 * @return void
	 */
	private function account( int $id, string $profile, string $display, string $first ): void {
		$this->rows[ $id ] = array(
			'profile_name' => $profile,
			'display_name' => $display,
			'login'        => 'user' . $id,
			'email'        => 'user' . $id . '@example.org',
			'first_name'   => $first,
		);
	}

	/**
	 * A strategy whose write is recorded.
	 *
	 * @return NamePartsBackfillMigrationStrategy
	 */
	private function strategy(): NamePartsBackfillMigrationStrategy {
		$written = &$this->written;
		return new class( $written ) extends NamePartsBackfillMigrationStrategy {

			/** @var array<int, array{0: string, 1: string}> */
			private array $sink;

			/**
			 * @param array<int, array{0: string, 1: string}> $sink Write log.
			 */
			public function __construct( array &$sink ) {
				$this->sink = &$sink;
			}

			/**
			 * @param int    $user_id Account.
			 * @param string $first   First name.
			 * @param string $last    Last name.
			 * @return void
			 */
			protected function write_parts( int $user_id, string $first, string $last ): void {
				$this->sink[ $user_id ] = array( $first, $last );
			}
		};
	}

	public function test_the_whole_name_in_first_name_is_split_and_counted(): void {
		$this->account( 3, 'Maria da Silva', 'Maria da Silva', 'Maria da Silva' );
		$this->account( 5, '', 'João Souza', '' );

		$result = $this->strategy()->execute( 'name_parts_backfill', array( 'batch_size' => 10 ) );

		$this->assertSame( array( 3 => array( 'Maria', 'da Silva' ), 5 => array( 'João', 'Souza' ) ), $this->written );
		$this->assertSame( 2, $result['processed'] );
	}

	public function test_a_one_word_name_already_in_place_is_examined_not_counted_and_the_card_completes(): void {
		$this->account( 3, 'Cher', 'Cher', 'Cher' );
		$this->account( 4, 'Ana Lima', 'Ana Lima', 'Ana Lima' );

		$strategy = $this->strategy();
		$result   = $strategy->execute( 'name_parts_backfill', array( 'batch_size' => 10 ) );

		$this->assertSame( 1, $result['processed'], 'Only the account that changed counts.' );
		$this->assertArrayNotHasKey( 3, $this->written );
		$this->assertTrue( $strategy->calculate_status( 'name_parts_backfill', array() )['is_complete'] );
	}

	public function test_the_name_skips_the_login_and_address_wordpress_falls_back_to(): void {
		$base = array( 'login' => 'mlogin', 'email' => 'm@example.org', 'first_name' => '' );

		$this->assertSame( 'Maria Silva', NamePartsBackfillMigrationStrategy::name_of( $base + array( 'user_id' => 1, 'profile_name' => 'mlogin', 'display_name' => 'Maria  Silva' ) ) );
		$this->assertSame( 'Ana Lima', NamePartsBackfillMigrationStrategy::name_of( $base + array( 'user_id' => 1, 'profile_name' => 'Ana Lima', 'display_name' => 'Other' ) ) );
		$this->assertSame( '', NamePartsBackfillMigrationStrategy::name_of( $base + array( 'user_id' => 1, 'profile_name' => '', 'display_name' => 'm@example.org' ) ) );
	}

	public function test_an_account_with_no_usable_name_is_left_alone(): void {
		$this->rows[9] = array(
			'profile_name' => '',
			'display_name' => 'user9',
			'login'        => 'user9',
			'email'        => 'user9@example.org',
			'first_name'   => '',
		);

		$result = $this->strategy()->execute( 'name_parts_backfill', array( 'batch_size' => 10 ) );

		$this->assertSame( 0, $result['processed'] );
		$this->assertSame( array(), $this->written );
		$this->assertSame( array( 'cursor' => 9 ), $this->state );
	}

	public function test_an_empty_population_is_complete(): void {
		$strategy = $this->strategy();

		$this->assertTrue( $strategy->calculate_status( 'name_parts_backfill', array() )['is_complete'] );
		$this->assertSame( 0, $strategy->execute( 'name_parts_backfill', array() )['processed'] );
		$this->assertSame( 'name_parts_backfill', $strategy->get_name() );
	}
}
