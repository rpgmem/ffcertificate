<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\UrlShortener\UrlShortenerActivator;

/**
 * Tests for UrlShortenerActivator: table name generation,
 * idempotent table creation, and migration.
 *
 * @covers \FreeFormCertificate\UrlShortener\UrlShortenerActivator
 */
class UrlShortenerActivatorTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		global $wpdb;
		$wpdb = Mockery::mock( 'wpdb' )->makePartial();
		$wpdb->prefix = 'wp_';
		$wpdb->last_error = '';

		$this->wpdb = $wpdb;

		// ABSPATH and the upgrade.php create_tables() requires come from the
		// bootstrap (tests/Support/UpgradeStub.php, #1803).

		// This activator's chain became guarded by `FFC_VERSION` (#1231), so it
		// reads and writes a version option. The value returned here is
		// DIFFERENT from `FFC_VERSION` on purpose: these tests exercise the
		// chain's body, which only runs when the guard does not match.
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( 'update_option' )->justReturn( true );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ==================================================================
	// get_table_name()
	// ==================================================================

	public function test_get_table_name_uses_prefix(): void {
		$this->assertSame( 'wp_ffc_short_urls', UrlShortenerActivator::get_table_name() );
	}

	public function test_get_table_name_custom_prefix(): void {
		global $wpdb;
		$wpdb->prefix = 'custom_';

		$this->assertSame( 'custom_ffc_short_urls', UrlShortenerActivator::get_table_name() );

		// Restore
		$wpdb->prefix = 'wp_';
	}

	// ==================================================================
	// create_tables()
	// ==================================================================

	public function test_create_tables_skips_if_table_exists(): void {
		$this->wpdb->shouldReceive( 'get_charset_collate' )->andReturn( 'DEFAULT CHARSET utf8mb4' );
		$this->every_table_exists();

		$delta_called = false;
		Functions\when( 'dbDelta' )->alias( function () use ( &$delta_called ) {
			$delta_called = true;
		} );

		UrlShortenerActivator::create_tables();

		$this->assertFalse( $delta_called, 'dbDelta should not be called when table already exists' );
	}

	public function test_create_tables_calls_db_delta_when_missing(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'QUERY' );
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( null );
		$this->wpdb->shouldReceive( 'get_charset_collate' )->andReturn( 'DEFAULT CHARSET utf8mb4' );

		$delta_called = false;
		$delta_sql    = '';
		Functions\when( 'dbDelta' )->alias( function ( $sql ) use ( &$delta_called, &$delta_sql ) {
			$delta_called = true;
			$delta_sql    = $sql;
		} );

		UrlShortenerActivator::create_tables();

		$this->assertTrue( $delta_called );
		$this->assertStringContainsString( 'ffc_short_urls', $delta_sql );
		$this->assertStringNotContainsString( 'ffc_short_url_retired', $delta_sql, 'The last statement is the links table; the retired one runs first.' );
		$this->assertStringContainsString( 'short_code', $delta_sql );
		$this->assertStringContainsString( 'target_url', $delta_sql );
		$this->assertStringContainsString( 'click_count', $delta_sql );
		$this->assertStringContainsString( 'PRIMARY KEY', $delta_sql );
	}

	// ==================================================================
	// maybe_migrate()
	// ==================================================================

	public function test_maybe_migrate_creates_table_if_missing(): void {
		// First call: table_exists returns false
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'QUERY' );
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( null );
		$this->wpdb->shouldReceive( 'get_charset_collate' )->andReturn( 'DEFAULT CHARSET utf8mb4' );
		// maybe_migrate() also calls add_column_if_missing() which uses
		// DatabaseHelperTrait::column_exists() → $wpdb->get_results().
		// Return empty array so the column is considered missing and then
		// stub the resulting ALTER TABLE via $wpdb->query.
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( array() );
		$this->wpdb->shouldReceive( 'query' )->andReturn( 1 );

		$delta_called = false;
		Functions\when( 'dbDelta' )->alias( function () use ( &$delta_called ) {
			$delta_called = true;
		} );

		UrlShortenerActivator::maybe_migrate();

		$this->assertTrue( $delta_called );
	}

	public function test_maybe_migrate_skips_if_table_exists(): void {
		$this->every_table_exists();
		// add_column_if_missing() runs even when table exists.
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( array( (object) array( 'Field' => 'qr_cache' ) ) );
		$this->wpdb->shouldReceive( 'query' )->andReturn( 1 );

		$delta_called = false;
		Functions\when( 'dbDelta' )->alias( function () use ( &$delta_called ) {
			$delta_called = true;
		} );

		UrlShortenerActivator::maybe_migrate();

		$this->assertFalse( $delta_called );
	}

	// ==================================================================
	// The retired codes table (#2337)
	// ==================================================================

	public function test_the_retired_table_is_created_on_an_install_that_has_the_links_table(): void {
		// An upgraded install: the links table exists, the retired one does not.
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn( $q, ...$args ) => (string) ( $args[0] ?? $q ) );
		$this->wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			static fn( $table ) => 'wp_ffc_short_urls' === $table ? $table : null
		);
		$this->wpdb->shouldReceive( 'get_charset_collate' )->andReturn( 'DEFAULT CHARSET utf8mb4' );
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( array( (object) array( 'Field' => 'qr_cache' ) ) );

		$statements = array();
		Functions\when( 'dbDelta' )->alias(
			static function ( $sql ) use ( &$statements ) {
				$statements[] = $sql;
			}
		);

		UrlShortenerActivator::maybe_migrate();

		$this->assertCount( 1, $statements );
		$this->assertStringContainsString( 'CREATE TABLE wp_ffc_short_url_retired', $statements[0] );
		$this->assertStringContainsString( 'UNIQUE KEY idx_short_code (short_code)', $statements[0] );
		$this->assertStringContainsString( 'retired_at bigint(20) unsigned NOT NULL', $statements[0] );
	}

	public function test_get_retired_table_name_uses_prefix(): void {
		$this->assertSame( 'wp_ffc_short_url_retired', UrlShortenerActivator::get_retired_table_name() );
	}

	/**
	 * Every `SHOW TABLES LIKE` answers with the table it asked about.
	 */
	private function every_table_exists(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn( $q, ...$args ) => (string) ( $args[0] ?? $q ) );
		$this->wpdb->shouldReceive( 'get_var' )->andReturnUsing( static fn( $table ) => $table );
	}
}
