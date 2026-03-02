<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\AdminUserColumns;

/**
 * Tests for AdminUserColumns: custom user list columns for certificates,
 * appointments, and user actions (login-as-user link).
 *
 * Covers hook registration, column insertion, rendering (zero/non-zero counts,
 * unknown columns), style enqueueing, and batch-loading cache behaviour.
 *
 * @covers \FreeFormCertificate\Admin\AdminUserColumns
 */
class AdminUserColumnsTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var Mockery\MockInterface */
    private $wpdb;

    /** @var Mockery\MockInterface alias mock for Utils */
    private $utils_mock;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Reset static caches via Reflection
        $this->resetStaticCaches();

        // Mock $wpdb
        global $wpdb;
        $wpdb         = Mockery::mock( 'wpdb' );
        $wpdb->prefix = 'wp_';
        $this->wpdb   = $wpdb;

        $this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () {
            return func_get_args()[0];
        } )->byDefault();

        // Utils alias mock
        $this->utils_mock = Mockery::mock( 'alias:FreeFormCertificate\Core\Utils' );
        $this->utils_mock->shouldReceive( 'get_submissions_table' )
            ->andReturn( 'wp_ffc_submissions' )
            ->byDefault();
        $this->utils_mock->shouldReceive( 'asset_suffix' )
            ->andReturn( '.min' )
            ->byDefault();

        // Common WP stubs
        Functions\when( '__' )->returnArg();
        Functions\when( '_n' )->alias( function ( $single, $plural, $count ) {
            return $count === 1 ? $single : $plural;
        } );
        Functions\when( 'esc_url' )->returnArg();
        Functions\when( 'esc_attr__' )->returnArg();
        Functions\when( 'wp_create_nonce' )->justReturn( 'test_nonce_123' );
        Functions\when( 'add_query_arg' )->alias( function ( $args, $url ) {
            return $url . '?' . http_build_query( $args );
        } );
        Functions\when( 'home_url' )->alias( function ( $path = '' ) {
            return 'https://example.com' . $path;
        } );
        Functions\when( 'get_option' )->justReturn( array() );
    }

    protected function tearDown(): void {
        $this->resetStaticCaches();
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Reset all static caches in AdminUserColumns via Reflection.
     */
    private function resetStaticCaches(): void {
        $ref = new \ReflectionClass( AdminUserColumns::class );
        foreach ( array( 'appointments_table_exists', 'dashboard_url_cache', 'certificate_counts_cache', 'appointment_counts_cache' ) as $prop ) {
            $p = $ref->getProperty( $prop );
            $p->setAccessible( true );
            $p->setValue( null, null );
        }
    }

    // ==================================================================
    // init()
    // ==================================================================

    public function test_init_registers_hooks(): void {
        Functions\expect( 'add_filter' )
            ->once()
            ->with( 'manage_users_columns', array( AdminUserColumns::class, 'add_custom_columns' ) );

        Functions\expect( 'add_filter' )
            ->once()
            ->with( 'manage_users_custom_column', array( AdminUserColumns::class, 'render_custom_column' ), 10, 3 );

        Functions\expect( 'add_action' )
            ->once()
            ->with( 'admin_enqueue_scripts', array( AdminUserColumns::class, 'enqueue_styles' ) );

        AdminUserColumns::init();
    }

    // ==================================================================
    // add_custom_columns()
    // ==================================================================

    public function test_add_custom_columns_inserts_after_posts(): void {
        $columns = array(
            'cb'       => '<input type="checkbox" />',
            'username' => 'Username',
            'email'    => 'Email',
            'role'     => 'Role',
            'posts'    => 'Posts',
        );

        $result = AdminUserColumns::add_custom_columns( $columns );

        $keys = array_keys( $result );
        $posts_index = array_search( 'posts', $keys, true );
        $this->assertSame( 'ffc_certificates', $keys[ $posts_index + 1 ] );
        $this->assertSame( 'ffc_appointments', $keys[ $posts_index + 2 ] );
        $this->assertSame( 'ffc_user_actions', $keys[ $posts_index + 3 ] );
    }

    public function test_add_custom_columns_preserves_existing(): void {
        $columns = array(
            'cb'       => '<input type="checkbox" />',
            'username' => 'Username',
            'posts'    => 'Posts',
            'custom'   => 'Custom Column',
        );

        $result = AdminUserColumns::add_custom_columns( $columns );

        // All original columns must still be present
        $this->assertArrayHasKey( 'cb', $result );
        $this->assertArrayHasKey( 'username', $result );
        $this->assertArrayHasKey( 'posts', $result );
        $this->assertArrayHasKey( 'custom', $result );

        // New columns added
        $this->assertArrayHasKey( 'ffc_certificates', $result );
        $this->assertArrayHasKey( 'ffc_appointments', $result );
        $this->assertArrayHasKey( 'ffc_user_actions', $result );

        // Total count: 4 original + 3 new
        $this->assertCount( 7, $result );
    }

    // ==================================================================
    // render_custom_column() — certificates
    // ==================================================================

    public function test_render_certificates_column_zero_count(): void {
        // Batch load returns no results
        $this->wpdb->shouldReceive( 'get_results' )
            ->once()
            ->andReturn( array() );

        $output = AdminUserColumns::render_custom_column( '', 'ffc_certificates', 42 );

        $this->assertStringContainsString( 'ffc-empty-value', $output );
        $this->assertStringContainsString( '—', $output );
    }

    public function test_render_certificates_column_with_count(): void {
        // Batch load returns count for user 42
        $this->wpdb->shouldReceive( 'get_results' )
            ->once()
            ->andReturn( array(
                array( 'user_id' => '42', 'cnt' => '5' ),
                array( 'user_id' => '99', 'cnt' => '2' ),
            ) );

        $output = AdminUserColumns::render_custom_column( '', 'ffc_certificates', 42 );

        $this->assertStringContainsString( '<strong>5</strong>', $output );
        $this->assertStringContainsString( 'certificates', $output );
    }

    // ==================================================================
    // render_custom_column() — appointments
    // ==================================================================

    public function test_render_appointments_column_zero_count(): void {
        // Certificate batch load (will be triggered first if cache is null)
        $this->wpdb->shouldReceive( 'get_results' )
            ->andReturn( array() )
            ->byDefault();

        // table_exists check — return null (table does not exist)
        $this->wpdb->shouldReceive( 'get_var' )
            ->andReturn( null )
            ->byDefault();

        $output = AdminUserColumns::render_custom_column( '', 'ffc_appointments', 42 );

        $this->assertStringContainsString( 'ffc-empty-value', $output );
        $this->assertStringContainsString( '—', $output );
    }

    public function test_render_appointments_column_with_count(): void {
        $table = 'wp_ffc_self_scheduling_appointments';

        // table_exists returns the table name (table exists)
        $this->wpdb->shouldReceive( 'get_var' )
            ->andReturn( $table )
            ->byDefault();

        // Batch appointment counts
        $this->wpdb->shouldReceive( 'get_results' )
            ->andReturn( array(
                array( 'user_id' => '42', 'cnt' => '3' ),
            ) );

        $output = AdminUserColumns::render_custom_column( '', 'ffc_appointments', 42 );

        $this->assertStringContainsString( '<strong>3</strong>', $output );
        $this->assertStringContainsString( 'appointments', $output );
    }

    // ==================================================================
    // render_custom_column() — user actions
    // ==================================================================

    public function test_render_user_actions_column(): void {
        $output = AdminUserColumns::render_custom_column( '', 'ffc_user_actions', 42 );

        $this->assertStringContainsString( 'ffc-view-as-user', $output );
        $this->assertStringContainsString( 'button', $output );
        $this->assertStringContainsString( 'Login as User', $output );
        $this->assertStringContainsString( 'ffc_view_as_user=42', $output );
        $this->assertStringContainsString( 'test_nonce_123', $output );
    }

    // ==================================================================
    // render_custom_column() — unknown column
    // ==================================================================

    public function test_render_unknown_column_returns_original(): void {
        $output = AdminUserColumns::render_custom_column( 'original_output', 'unknown_col', 42 );

        $this->assertSame( 'original_output', $output );
    }

    // ==================================================================
    // enqueue_styles()
    // ==================================================================

    public function test_enqueue_styles_on_users_page(): void {
        Functions\expect( 'wp_enqueue_style' )
            ->once()
            ->with(
                'ffc-admin',
                Mockery::pattern( '/ffc-admin\.min\.css/' ),
                array(),
                FFC_VERSION
            );

        AdminUserColumns::enqueue_styles( 'users.php' );
    }

    public function test_enqueue_styles_skips_other_pages(): void {
        Functions\expect( 'wp_enqueue_style' )->never();

        AdminUserColumns::enqueue_styles( 'edit.php' );
    }

    // ==================================================================
    // Batch loading — counts loaded once
    // ==================================================================

    public function test_batch_loads_counts_once(): void {
        // Certificate batch: get_results called exactly once
        $this->wpdb->shouldReceive( 'get_results' )
            ->once()
            ->andReturn( array(
                array( 'user_id' => '1', 'cnt' => '10' ),
                array( 'user_id' => '2', 'cnt' => '20' ),
            ) );

        // First call triggers batch load
        $output1 = AdminUserColumns::render_custom_column( '', 'ffc_certificates', 1 );
        $this->assertStringContainsString( '<strong>10</strong>', $output1 );

        // Second call uses cache — no additional DB query
        $output2 = AdminUserColumns::render_custom_column( '', 'ffc_certificates', 2 );
        $this->assertStringContainsString( '<strong>20</strong>', $output2 );

        // Third call for non-existent user returns zero (empty value)
        $output3 = AdminUserColumns::render_custom_column( '', 'ffc_certificates', 999 );
        $this->assertStringContainsString( 'ffc-empty-value', $output3 );
    }
}
