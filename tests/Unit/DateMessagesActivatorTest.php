<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\DateMessages\DateMessagesActivator;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Schema of the date-messages module (#1538).
 *
 * @covers \FreeFormCertificate\DateMessages\DateMessagesActivator
 */
class DateMessagesActivatorTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $wpdb;

	/**
	 * Statements handed to dbDelta().
	 *
	 * @var array<int, string>
	 */
	private array $deltas = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		global $wpdb;
		$wpdb             = Mockery::mock( 'wpdb' )->makePartial();
		$wpdb->prefix     = 'wp_';
		$wpdb->last_error = '';
		$this->wpdb       = $wpdb;
		$this->wpdb->shouldReceive( 'get_charset_collate' )->andReturn( 'DEFAULT CHARSET utf8mb4' );
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn( $q, ...$a ) => $q . '|' . implode( ',', $a ) );

		$upgrade_dir = ABSPATH . 'wp-admin/includes';
		if ( ! is_dir( $upgrade_dir ) ) {
			mkdir( $upgrade_dir, 0755, true );
		}
		if ( ! file_exists( $upgrade_dir . '/upgrade.php' ) ) {
			file_put_contents( $upgrade_dir . '/upgrade.php', "<?php\n// Stub for unit tests.\n" );
		}

		$this->deltas = array();
		Functions\when( 'dbDelta' )->alias(
			function ( $sql ) {
				$this->deltas[] = (string) $sql;
				return array();
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_table_names_follow_the_prefix(): void {
		$this->assertSame( 'wp_ffc_date_message_rules', DateMessagesActivator::rules_table() );
		$this->assertSame( 'wp_ffc_date_message_runs', DateMessagesActivator::runs_table() );
		$this->assertSame( 'wp_ffc_date_message_log', DateMessagesActivator::log_table() );
	}

	public function test_create_tables_creates_the_three_missing_tables(): void {
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( null );

		DateMessagesActivator::create_tables();

		$this->assertCount( 3, $this->deltas );
		$this->assertStringContainsString( 'CREATE TABLE wp_ffc_date_message_rules', $this->deltas[0] );
		$this->assertStringContainsString( 'CREATE TABLE wp_ffc_date_message_runs', $this->deltas[1] );
		$this->assertStringContainsString( 'CREATE TABLE wp_ffc_date_message_log', $this->deltas[2] );
		$this->assertStringContainsString(
			'UNIQUE KEY uq_delivery (rule_id,user_id,occurrence_key,channel)',
			$this->deltas[2],
			'The UNIQUE key is the deduplication.'
		);
	}

	public function test_create_tables_leaves_existing_tables_alone(): void {
		// `SHOW TABLES LIKE` answers with the table it was asked about.
		$this->wpdb->shouldReceive( 'get_var' )->andReturnUsing( static fn( $q ) => substr( (string) $q, (int) strrpos( (string) $q, '|' ) + 1 ) );

		DateMessagesActivator::create_tables();

		$this->assertSame( array(), $this->deltas );
	}

	public function test_maybe_migrate_is_a_no_op_on_the_current_version(): void {
		Functions\when( 'get_option' )->alias(
			static fn( $name ) => DateMessagesActivator::AUDIENCE_IDS_OPTION === $name ? '1' : FFC_VERSION
		);
		Functions\expect( 'update_option' )->never();
		$this->wpdb->shouldReceive( 'get_var' )->never();

		DateMessagesActivator::maybe_migrate();

		$this->assertSame( array(), $this->deltas );
	}

	public function test_maybe_migrate_heals_and_then_records_the_version(): void {
		Functions\when( 'get_option' )->justReturn( '6.0.0' );
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( null );
		Functions\expect( 'update_option' )->once()->with( DateMessagesActivator::SCHEMA_OPTION, FFC_VERSION );

		DateMessagesActivator::maybe_migrate();

		$this->assertCount( 3, $this->deltas );
	}

	// ------------------------------------------------------------------
	// audience_id → audience_ids (#1648)
	// ------------------------------------------------------------------

	public function test_audience_migration_copies_the_single_audience_then_drops_the_column(): void {
		$queries = array();
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( 'wp_ffc_date_message_rules' );
		$this->wpdb->shouldReceive( 'esc_like' )->andReturnArg( 0 );
		// audience_ids is missing, audience_id is still there.
		$this->wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			static fn( $q ) => str_ends_with( (string) $q, ',audience_id' ) ? array( (object) array( 'Field' => 'audience_id' ) ) : array()
		);
		$this->wpdb->shouldReceive( 'query' )->andReturnUsing(
			static function ( $q ) use ( &$queries ) {
				$queries[] = (string) $q;
				return 1;
			}
		);

		DateMessagesActivator::migrate_audience_ids();

		$this->assertCount( 3, $queries );
		$this->assertStringContainsString( 'ADD COLUMN', $queries[0] );
		$this->assertStringContainsString( 'audience_ids', $queries[0] );
		$this->assertStringStartsWith( "UPDATE %i SET audience_ids = CONCAT('[', audience_id, ']')", $queries[1] );
		$this->assertSame( 'ALTER TABLE %i DROP COLUMN audience_id|wp_ffc_date_message_rules', $queries[2], 'The old column goes only after the copy.' );
	}

	public function test_audience_migration_on_a_done_table_only_records_the_marker(): void {
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( 'wp_ffc_date_message_rules' );
		$this->wpdb->shouldReceive( 'esc_like' )->andReturnArg( 0 );
		// audience_ids exists, audience_id is gone.
		$this->wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			static fn( $q ) => str_ends_with( (string) $q, ',audience_ids' ) ? array( (object) array( 'Field' => 'audience_ids' ) ) : array()
		);
		$this->wpdb->shouldReceive( 'query' )->never();
		Functions\expect( 'update_option' )->once()->with( DateMessagesActivator::AUDIENCE_IDS_OPTION, '1' );

		DateMessagesActivator::migrate_audience_ids();
	}

	public function test_audience_migration_records_the_marker_only_once_the_old_column_is_gone(): void {
		$dropped = false;
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( 'wp_ffc_date_message_rules' );
		$this->wpdb->shouldReceive( 'esc_like' )->andReturnArg( 0 );
		$this->wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			static function ( $q ) use ( &$dropped ) {
				if ( str_ends_with( (string) $q, ',audience_ids' ) ) {
					return array( (object) array( 'Field' => 'audience_ids' ) );
				}
				return ! $dropped && str_ends_with( (string) $q, ',audience_id' ) ? array( (object) array( 'Field' => 'audience_id' ) ) : array();
			}
		);
		$this->wpdb->shouldReceive( 'query' )->andReturnUsing(
			static function ( $q ) use ( &$dropped ) {
				if ( str_contains( (string) $q, 'DROP COLUMN' ) ) {
					$dropped = true;
				}
				return 1;
			}
		);
		Functions\expect( 'update_option' )->once()->with( DateMessagesActivator::AUDIENCE_IDS_OPTION, '1' );

		DateMessagesActivator::migrate_audience_ids();
	}

	public function test_a_failed_drop_leaves_the_marker_unset_so_it_retries(): void {
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( 'wp_ffc_date_message_rules' );
		$this->wpdb->shouldReceive( 'esc_like' )->andReturnArg( 0 );
		// Both columns stay: the DROP did not take.
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( array( (object) array( 'Field' => 'x' ) ) );
		$this->wpdb->shouldReceive( 'query' )->andReturn( false );
		Functions\expect( 'update_option' )->never();

		DateMessagesActivator::migrate_audience_ids();
	}

	public function test_the_audience_move_runs_on_an_install_already_at_this_version(): void {
		// The testes case (#1648): `develop` keeps FFC_VERSION, so the schema
		// option already matches and only the move's own marker is missing.
		Functions\when( 'get_option' )->alias(
			static fn( $name ) => DateMessagesActivator::SCHEMA_OPTION === $name ? FFC_VERSION : ''
		);
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( 'wp_ffc_date_message_rules' );
		$this->wpdb->shouldReceive( 'esc_like' )->andReturnArg( 0 );
		$this->wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			static fn( $q ) => str_ends_with( (string) $q, ',audience_ids' ) ? array( (object) array( 'Field' => 'audience_ids' ) ) : array()
		);
		Functions\expect( 'update_option' )->once()->with( DateMessagesActivator::AUDIENCE_IDS_OPTION, '1' );

		DateMessagesActivator::maybe_migrate();

		$this->assertSame( array(), $this->deltas, 'The version gate still holds for the table chain.' );
	}

	public function test_audience_migration_skips_a_missing_table(): void {
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( null );
		$this->wpdb->shouldReceive( 'get_results' )->never();
		$this->wpdb->shouldReceive( 'query' )->never();

		DateMessagesActivator::migrate_audience_ids();
	}
}
