<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Migrations\Strategies\CertificateCapabilityBackfillMigrationStrategy;

/**
 * Tests for CertificateCapabilityBackfillMigrationStrategy: grant the
 * certificate capabilities to accounts that own a certificate and cannot
 * read it (#1345).
 *
 * @covers \FreeFormCertificate\Migrations\Strategies\CertificateCapabilityBackfillMigrationStrategy
 * @runClassInSeparateProcess
 * @preserveGlobalState disabled
 */
class CertificateCapabilityBackfillMigrationStrategyTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var \Mockery\MockInterface */
	private $wpdb;

	private CertificateCapabilityBackfillMigrationStrategy $strategy;

	/** @var array<int, string> Statements the strategy issued. */
	private array $statements = array();

	/** @var array<int, array<int, mixed>> Values it bound to them. */
	private array $bound = array();

	/** @var array<int, int> Accounts whose caps were written. */
	private array $granted = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		class_exists( '\\FreeFormCertificate\\Migrations\\Strategies\\CertificateCapabilityBackfillMigrationStrategy' );

		global $wpdb;
		$wpdb             = Mockery::mock( 'wpdb' )->makePartial();
		$wpdb->prefix     = 'wp_';
		$wpdb->usermeta   = 'wp_usermeta';
		$wpdb->last_error = '';

		// `prepare()` records the statement AND the values, because two of the
		// assertions below are about what travelled as a placeholder rather
		// than about the text of the SQL.
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( $sql, ...$args ) {
				$this->statements[] = (string) $sql;
				$this->bound[]      = ( 1 === count( $args ) && is_array( $args[0] ) ) ? $args[0] : $args;
				return 'SQL';
			}
		);
		$wpdb->shouldReceive( 'get_blog_prefix' )->andReturn( 'wp_' );
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing(
			static fn( $text ) => addcslashes( (string) $text, '_%\\' )
		);
		$wpdb->shouldReceive( 'get_var' )->andReturn( 0 )->byDefault();
		$wpdb->shouldReceive( 'get_col' )->andReturn( array() )->byDefault();
		$this->wpdb = $wpdb;

		Functions\when( '__' )->returnArg();
		Functions\when( '_n' )->alias(
			static fn( $single, $plural, $number ) => ( 1 === (int) $number ) ? $single : $plural
		);
		Functions\when( 'FreeFormCertificate\Migrations\Strategies\__' )->returnArg();
		Functions\when( 'FreeFormCertificate\Migrations\Strategies\_n' )->alias(
			static fn( $single, $plural, $number ) => ( 1 === (int) $number ) ? $single : $plural
		);

		// The grant writes an activity-log line, which reaches WordPress in the
		// Core namespace. Stubbed HERE rather than in the bootstrap because a
		// namespaced stub is created process-wide and stops an unqualified
		// call inside that namespace falling back to the global one -- this
		// class runs in its own process, which is what contains that.
		Functions\when( 'FreeFormCertificate\Core\get_current_user_id' )->justReturn( 0 );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'FreeFormCertificate\Core\get_option' )->justReturn( array() );
		Functions\when( 'FreeFormCertificate\Settings\get_option' )->justReturn( array() );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_json_encode' )->alias( static fn( $v ) => json_encode( $v ) );

		// The grant's only observable without a WordPress user table.
		Functions\when( 'get_userdata' )->alias(
			function ( $user_id ) {
				$user     = Mockery::mock( 'WP_User' );
				$user->ID = (int) $user_id;
				$user->shouldReceive( 'has_cap' )->andReturn( false );
				$user->shouldReceive( 'add_cap' )->andReturnUsing(
					function () use ( $user_id ) {
						$this->granted[] = (int) $user_id;
					}
				);
				return $user;
			}
		);

		$this->strategy = new CertificateCapabilityBackfillMigrationStrategy();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The card MEASURES rather than latching, which is what makes "complete"
	 * mean the data says so. #1345 records the opposite case: the
	 * identity-index card short-circuits on a one-way boolean and reported
	 * 100% while 72 accounts were missing from the index.
	 */
	public function test_pending_is_read_from_the_data_every_time(): void {
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( 40 );
		$this->wpdb->shouldReceive( 'get_col' )->andReturn( array( '7', '9', '11' ) );

		$status = $this->strategy->calculate_status( 'certificate_capability_backfill', array() );

		$this->assertSame( 40, $status['total'] );
		$this->assertSame( 3, $status['pending'] );
		$this->assertSame( 37, $status['migrated'] );
		$this->assertFalse( $status['is_complete'] );
	}

	public function test_no_affected_account_reads_as_complete(): void {
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( 40 );
		$this->wpdb->shouldReceive( 'get_col' )->andReturn( array() );

		$status = $this->strategy->calculate_status( 'certificate_capability_backfill', array() );

		$this->assertSame( 0, $status['pending'] );
		$this->assertTrue( $status['is_complete'] );
		$this->assertSame( 100.0, $status['percent'] );
	}

	/**
	 * An install with no certificates at all is complete, not a division by
	 * zero — and not 0%, which would read as work nobody can ever do.
	 */
	public function test_an_install_with_no_certificates_is_complete(): void {
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( 0 );

		$status = $this->strategy->calculate_status( 'certificate_capability_backfill', array() );

		$this->assertSame( 0, $status['total'] );
		$this->assertSame( 100.0, $status['percent'] );
		$this->assertTrue( $status['is_complete'] );
	}

	public function test_a_batch_grants_to_every_account_it_read(): void {
		$this->wpdb->shouldReceive( 'get_col' )->andReturn( array( '7', '9' ) );

		$result = $this->strategy->execute( 'certificate_capability_backfill', array( 'batch_size' => 100 ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 2, $result['processed'] );
		$this->assertSame( array( 7, 9 ), array_values( array_unique( $this->granted ) ) );
	}

	public function test_a_batch_with_nothing_left_grants_nothing(): void {
		$result = $this->strategy->execute( 'certificate_capability_backfill', array( 'batch_size' => 100 ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 0, $result['processed'] );
		$this->assertSame( array(), $this->granted );
	}

	/**
	 * `(int) 'x'` is 0, and a batch of 0 reports the migration complete having
	 * processed nothing -- the #1060 trap, reachable here because the config
	 * is read after `ffcertificate_migrations_registry` has run.
	 *
	 * The two bad values are bad in DIFFERENT ways and the code answers them
	 * differently on purpose, which is why they are asserted apart: a numeric
	 * zero is a value somebody wrote and it floors at one, while a
	 * non-numeric is not a batch size at all and falls back to the default.
	 * Asserting one figure for both would have passed while measuring
	 * neither -- the first version of this test did exactly that.
	 */
	public function test_a_zero_batch_size_floors_at_one(): void {
		$this->wpdb->shouldReceive( 'get_col' )->andReturn( array( '7' ) );

		$this->strategy->execute( 'certificate_capability_backfill', array( 'batch_size' => 0 ) );

		$this->assertContains( 1, $this->limits_bound(), 'A batch of zero would report completion having done nothing.' );
	}

	public function test_a_non_numeric_batch_size_falls_back_to_the_default(): void {
		$this->wpdb->shouldReceive( 'get_col' )->andReturn( array( '7' ) );

		$this->strategy->execute( 'certificate_capability_backfill', array( 'batch_size' => 'x' ) );

		$this->assertContains( 100, $this->limits_bound() );
	}

	/**
	 * The last value bound to each statement, which is the `LIMIT` on the one
	 * that carries it.
	 *
	 * @return array<int, mixed>
	 */
	private function limits_bound(): array {
		$out = array();

		foreach ( $this->bound as $values ) {
			if ( array() !== $values ) {
				$out[] = end( $values );
			}
		}

		return $out;
	}

	/**
	 * `_` is a LIKE wildcard and `ffc_view_own_certificates` carries four of
	 * them, so an unescaped term matches strings that are not the capability.
	 */
	public function test_the_capability_term_is_escaped_for_like(): void {
		$this->strategy->calculate_status( 'certificate_capability_backfill', array() );

		$terms = array();
		foreach ( $this->bound as $values ) {
			foreach ( $values as $value ) {
				if ( is_string( $value ) && false !== strpos( $value, 'ffc' ) && false !== strpos( $value, 'certificates' ) ) {
					$terms[] = $value;
				}
			}
		}

		$this->assertNotEmpty( $terms, 'No capability term was bound at all, so this proves nothing.' );
		$this->assertContains( '%ffc\\_view\\_own\\_certificates%', $terms );
	}

	/**
	 * The population is every owner, with no role carve-out: anybody who owns
	 * a certificate should be able to read it, and every exclusion is a row
	 * the count would have to explain away.
	 */
	public function test_the_scan_excludes_no_role(): void {
		$this->strategy->calculate_status( 'certificate_capability_backfill', array() );

		$this->assertNotEmpty( $this->statements, 'No statement ran at all, so this proves nothing.' );

		foreach ( $this->statements as $sql ) {
			$this->assertStringNotContainsString( 'administrator', $sql );
		}
	}

	public function test_it_can_always_run(): void {
		$this->assertTrue( $this->strategy->can_run( 'certificate_capability_backfill', array() ) );
	}

	public function test_the_name_matches_its_registry_key(): void {
		$this->assertSame( 'certificate_capability_backfill', $this->strategy->get_name() );
	}
}
