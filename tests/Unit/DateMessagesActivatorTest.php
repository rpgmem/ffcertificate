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
		Functions\when( 'get_option' )->justReturn( FFC_VERSION );
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
}
