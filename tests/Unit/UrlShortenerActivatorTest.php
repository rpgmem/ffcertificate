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
        $wpdb = Mockery::mock( 'wpdb' );
        $wpdb->prefix = 'wp_';
        $wpdb->last_error = '';

        $this->wpdb = $wpdb;

        // Ensure the upgrade.php stub file exists for require_once in create_tables()
        if ( ! defined( 'ABSPATH' ) ) {
            define( 'ABSPATH', '/tmp/wordpress/' );
        }
        $upgrade_dir = ABSPATH . 'wp-admin/includes';
        if ( ! is_dir( $upgrade_dir ) ) {
            mkdir( $upgrade_dir, 0755, true );
        }
        $upgrade_file = $upgrade_dir . '/upgrade.php';
        if ( ! file_exists( $upgrade_file ) ) {
            file_put_contents( $upgrade_file, "<?php\n// Stub for unit tests.\n" );
        }
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
        $this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SHOW TABLES LIKE "wp_ffc_short_urls"' );
        $this->wpdb->shouldReceive( 'get_var' )->andReturn( 'wp_ffc_short_urls' );

        // dbDelta should NOT be called
        Functions\when( 'dbDelta' )->alias( function () {
            throw new \RuntimeException( 'dbDelta should not be called' );
        } );

        UrlShortenerActivator::create_tables();

        // If we get here, table_exists returned true and create was skipped
        $this->assertTrue( true );
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

        $delta_called = false;
        Functions\when( 'dbDelta' )->alias( function () use ( &$delta_called ) {
            $delta_called = true;
        } );

        UrlShortenerActivator::maybe_migrate();

        $this->assertTrue( $delta_called );
    }

    public function test_maybe_migrate_skips_if_table_exists(): void {
        $this->wpdb->shouldReceive( 'prepare' )->andReturn( 'QUERY' );
        $this->wpdb->shouldReceive( 'get_var' )->andReturn( 'wp_ffc_short_urls' );

        $delta_called = false;
        Functions\when( 'dbDelta' )->alias( function () use ( &$delta_called ) {
            $delta_called = true;
        } );

        UrlShortenerActivator::maybe_migrate();

        $this->assertFalse( $delta_called );
    }
}
