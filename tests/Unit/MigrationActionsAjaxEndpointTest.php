<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\MigrationActionsAjaxEndpoint;
use FreeFormCertificate\Migrations\Strategies\MigrationStrategyInterface;

/**
 * Tests for the migrations JSON-batch endpoint introduced in 6.5.7.
 *
 * The real chain runs: Capabilities over a stubbed `current_user_can`, and
 * MigrationManager, MigrationRegistry and MigrationStatusCalculator. The
 * migration under test is registered through the plugin's own extension
 * filters (`ffcertificate_migrations_registry` for the card,
 * `ffcertificate_migration_strategies` for its strategy), and its strategy is
 * a plain interface double whose answers each test chooses. The built-in
 * strategies the calculator constructs only read `$wpdb->prefix`; the
 * `$wpdb` double answers nothing else, so any query fails the test.
 *
 * @covers \FreeFormCertificate\Admin\MigrationActionsAjaxEndpoint
 */
class MigrationActionsAjaxEndpointTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const KEY = 'my_migration';

	/** @var list<string> Every capability `current_user_can()` was asked about. */
	private array $asked_caps = array();

	/** @var list<string> Capabilities the current user holds. */
	private array $held_caps = array();

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	/** @var array<string, mixed> The `$_POST` an earlier test left, restored afterwards. */
	private array $post_backup = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->had_wpdb = array_key_exists( 'wpdb', $GLOBALS );
		if ( $this->had_wpdb ) {
			$this->wpdb_backup = $GLOBALS['wpdb'];
		}
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$GLOBALS['wpdb'] = $wpdb;

		$this->post_backup = $_POST ?? array();

		Functions\when( '__' )->returnArg();
		Functions\when( 'sanitize_key' )->alias( function ( $key ) {
			return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
		} );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'is_wp_error' )->alias( function ( $v ) { return $v instanceof \WP_Error; } );
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'current_user_can' )->alias(
			function ( string $cap ): bool {
				$this->asked_caps[] = $cap;
				return in_array( $cap, $this->held_caps, true );
			}
		);

		Functions\when( 'wp_send_json_error' )->alias( function ( $data = null ) {
			$msg = is_array( $data ) && isset( $data['message'] ) ? $data['message'] : 'error';
			throw new \RuntimeException( 'json_error: ' . $msg );
		} );
		Functions\when( 'wp_send_json_success' )->alias( function ( $data = null ) {
			throw new \RuntimeException( 'json_success: ' . json_encode( $data ) );
		} );
	}

	protected function tearDown(): void {
		$_POST = $this->post_backup;
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Grant the migration-execution capability the endpoint gates on.
	 */
	private function grant_dangerzone(): void {
		$this->held_caps = array( 'ffc_manage_settings_dangerzone' );
	}

	/**
	 * Register the test migration and its strategy through the plugin's filters.
	 *
	 * @param MigrationStrategyInterface $strategy Strategy the calculator hands the key to.
	 */
	private function register_migration( MigrationStrategyInterface $strategy ): void {
		Filters\expectApplied( 'ffcertificate_migrations_registry' )->andReturnUsing(
			static function ( array $migrations ): array {
				$migrations[ self::KEY ] = array(
					'name'       => 'Test migration',
					'batch_size' => 100,
				);
				return $migrations;
			}
		);
		Filters\expectApplied( 'ffcertificate_migration_strategies' )->andReturnUsing(
			static function ( array $strategies ) use ( $strategy ): array {
				$strategies[ self::KEY ] = $strategy;
				return $strategies;
			}
		);
	}

	/**
	 * A strategy that runs one batch and reports the given status.
	 *
	 * @param int                  $processed Rows the batch processed.
	 * @param array<string, mixed> $status    What calculate_status() answers.
	 * @return MigrationStrategyInterface
	 */
	private function strategy_with( int $processed, array $status, array $extra = array() ): MigrationStrategyInterface {
		$strategy = Mockery::mock( MigrationStrategyInterface::class );
		$strategy->shouldReceive( 'can_run' )->with( self::KEY, Mockery::type( 'array' ) )->andReturn( true );
		$strategy->shouldReceive( 'execute' )->once()->with( self::KEY, Mockery::type( 'array' ), 0 )->andReturn( array_merge( array( 'processed' => $processed ), $extra ) );
		$strategy->shouldReceive( 'calculate_status' )->with( self::KEY, Mockery::type( 'array' ) )->andReturn( $status );
		return $strategy;
	}

	// ==================================================================
	// Guards
	// ==================================================================

	public function test_rejects_when_user_lacks_capability(): void {
		// #739 (B): migration execution is gated on the dangerzone sub-cap,
		// matching the no-JS handlers — not the broader ffc_manage_settings.
		$this->held_caps = array( 'ffc_manage_settings' );

		try {
			MigrationActionsAjaxEndpoint::handle();
			$this->fail( 'Expected wp_send_json_error to short-circuit' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'do not have permission', $e->getMessage() );
		}

		$this->assertContains( 'ffc_manage_settings_dangerzone', $this->asked_caps, 'The gate asks for the dangerzone sub-capability.' );
	}

	public function test_rejects_missing_migration_key(): void {
		$this->grant_dangerzone();

		$_POST = array( 'nonce' => 'x' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Missing migration key' );
		MigrationActionsAjaxEndpoint::handle();
	}

	public function test_rejects_unknown_migration(): void {
		$this->grant_dangerzone();

		// The real registry has no migration under this key.
		$_POST = array( 'nonce' => 'x', 'migration_key' => 'unknown' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Unknown migration' );
		MigrationActionsAjaxEndpoint::handle();
	}

	public function test_run_migration_error_is_surfaced(): void {
		$this->grant_dangerzone();

		// The calculator asks can_run() before executing; a refusal there is
		// the WP_Error run_migration() hands back.
		$strategy = Mockery::mock( MigrationStrategyInterface::class );
		$strategy->shouldReceive( 'can_run' )->andReturn( new \WP_Error( 'fail', 'Batch failed' ) );
		$strategy->shouldNotReceive( 'execute' );
		$this->register_migration( $strategy );

		$_POST = array( 'nonce' => 'x', 'migration_key' => self::KEY );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Batch failed' );
		MigrationActionsAjaxEndpoint::handle();
	}

	// ==================================================================
	// Happy path
	// ==================================================================

	public function test_happy_path_returns_status_snapshot(): void {
		$this->grant_dangerzone();
		$this->register_migration(
			$this->strategy_with(
				100,
				array(
					'total'       => 5432,
					'migrated'    => 200,
					'pending'     => 5232,
					'percent'     => 3.68,
					'is_complete' => false,
				)
			)
		);

		$_POST = array( 'nonce' => 'x', 'migration_key' => self::KEY );

		try {
			MigrationActionsAjaxEndpoint::handle();
			$this->fail( 'Expected wp_send_json_success to short-circuit' );
		} catch ( \RuntimeException $e ) {
			$msg = $e->getMessage();
			$this->assertStringStartsWith( 'json_success', $msg );
			$this->assertStringContainsString( '"processed":100', $msg );
			$this->assertStringContainsString( '"total":5432', $msg );
			$this->assertStringContainsString( '"migrated":200', $msg );
			$this->assertStringContainsString( '"pending":5232', $msg );
			$this->assertStringContainsString( '"is_complete":false', $msg );
		}
	}

	public function test_completion_flag_propagates(): void {
		$this->grant_dangerzone();
		$this->register_migration(
			$this->strategy_with(
				32,
				array(
					'total'       => 5432,
					'migrated'    => 5432,
					'pending'     => 0,
					'percent'     => 100.0,
					'is_complete' => true,
				)
			)
		);

		$_POST = array( 'nonce' => 'x', 'migration_key' => self::KEY );

		try {
			MigrationActionsAjaxEndpoint::handle();
			$this->fail( 'Expected wp_send_json_success to short-circuit' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( '"is_complete":true', $e->getMessage() );
			$this->assertStringContainsString( '"processed":32', $e->getMessage() );
		}
	}

	/**
	 * Run the endpoint and return the decoded success payload.
	 *
	 * @return array<string, mixed>
	 */
	private function payload(): array {
		$_POST = array( 'nonce' => 'x', 'migration_key' => self::KEY );
		try {
			MigrationActionsAjaxEndpoint::handle();
			$this->fail( 'Expected wp_send_json_success to short-circuit' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringStartsWith( 'json_success', $e->getMessage() );
			$json = (string) substr( $e->getMessage(), (int) strpos( $e->getMessage(), '{' ) );
			return (array) json_decode( $json, true );
		}
		return array();
	}

	/**
	 * The runner needs to know when to stop short of completion (#2338): a
	 * batch that changed nothing has no more to give, and its errors travel
	 * with the answer.
	 */
	public function test_a_batch_that_changed_nothing_has_no_more_and_carries_its_errors(): void {
		$this->grant_dangerzone();
		$this->register_migration(
			$this->strategy_with(
				0,
				array( 'total' => 10, 'migrated' => 4, 'pending' => 6, 'percent' => 40.0, 'is_complete' => false ),
				array( 'success' => false, 'errors' => array( 'Row 3 failed', '', array( 'x' ) ) )
			)
		);

		$data = $this->payload();

		$this->assertFalse( $data['has_more'] );
		$this->assertFalse( $data['success'] );
		$this->assertSame( array( 'Row 3 failed' ), $data['errors'] );
	}

	public function test_the_strategys_own_has_more_wins_and_errors_are_capped(): void {
		$this->grant_dangerzone();
		Functions\when( '_n' )->alias( static fn( $single, $plural, $number ) => 1 === (int) $number ? $single : $plural );
		$errors = array();
		for ( $i = 1; $i <= 25; $i++ ) {
			$errors[] = 'Row ' . $i;
		}
		$this->register_migration(
			$this->strategy_with(
				5,
				array( 'total' => 10, 'migrated' => 5, 'pending' => 5, 'percent' => 50.0, 'is_complete' => false ),
				array( 'has_more' => false, 'errors' => $errors )
			)
		);

		$data = $this->payload();

		$this->assertFalse( $data['has_more'] );
		$this->assertCount( 21, $data['errors'] );
		$this->assertSame( 'Row 20', $data['errors'][19] );
	}

	public function test_a_failure_without_a_list_reports_its_message(): void {
		$this->grant_dangerzone();
		$this->register_migration(
			$this->strategy_with(
				0,
				array( 'total' => 1, 'migrated' => 0, 'pending' => 1, 'percent' => 0.0, 'is_complete' => false ),
				array( 'success' => false, 'message' => 'Keys are not configured.' )
			)
		);

		$this->assertSame( array( 'Keys are not configured.' ), $this->payload()['errors'] );
	}

	public function test_a_completed_migration_has_no_more(): void {
		$this->grant_dangerzone();
		$this->register_migration(
			$this->strategy_with(
				3,
				array( 'total' => 3, 'migrated' => 3, 'pending' => 0, 'percent' => 100.0, 'is_complete' => true ),
				array( 'has_more' => true )
			)
		);

		$this->assertFalse( $this->payload()['has_more'] );
	}
}
