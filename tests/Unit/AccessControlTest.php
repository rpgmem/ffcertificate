<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\UserDashboard\AccessControl;

/**
 * Tests for AccessControl: hook registrations, wp-admin blocking,
 * admin bar visibility, and default settings.
 */
class AccessControlTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // init() — hook registrations
    // ==================================================================

    public function test_init_registers_admin_init_action(): void {
        Functions\expect( 'add_action' )
            ->with(
                'admin_init',
                array( AccessControl::class, 'block_wp_admin' )
            )
            ->once();

        Functions\expect( 'add_filter' )
            ->withAnyArgs()
            ->zeroOrMoreTimes();

        AccessControl::init();
    }

    public function test_init_registers_show_admin_bar_filter(): void {
        Functions\expect( 'add_filter' )
            ->with(
                'show_admin_bar',
                array( AccessControl::class, 'hide_admin_bar' )
            )
            ->once();

        Functions\expect( 'add_action' )
            ->withAnyArgs()
            ->zeroOrMoreTimes();

        AccessControl::init();
    }

    // ==================================================================
    // block_wp_admin() — blocking disabled / early returns
    // ==================================================================

    public function test_block_wp_admin_returns_early_when_blocking_disabled(): void {
        Functions\when( 'get_option' )->justReturn( array() );

        // If redirect were called, the test would fail
        Functions\expect( 'wp_safe_redirect' )->never();

        AccessControl::block_wp_admin();
    }

    public function test_block_wp_admin_returns_early_when_block_wp_admin_is_false(): void {
        Functions\when( 'get_option' )->justReturn( array( 'block_wp_admin' => false ) );

        Functions\expect( 'wp_safe_redirect' )->never();

        AccessControl::block_wp_admin();
    }

    public function test_block_wp_admin_returns_early_for_ajax_requests(): void {
        Functions\when( 'get_option' )->justReturn( array( 'block_wp_admin' => true ) );
        Functions\when( 'wp_doing_ajax' )->justReturn( true );

        Functions\expect( 'wp_safe_redirect' )->never();

        AccessControl::block_wp_admin();
    }

    // ==================================================================
    // block_wp_admin() — admin bypass
    // ==================================================================

    public function test_block_wp_admin_bypasses_admin_users_when_bypass_enabled(): void {
        Functions\when( 'get_option' )->justReturn( array(
            'block_wp_admin'     => true,
            'bypass_for_admins'  => true,
        ) );
        Functions\when( 'wp_doing_ajax' )->justReturn( false );
        Functions\when( 'current_user_can' )->justReturn( true );

        Functions\expect( 'wp_safe_redirect' )->never();

        AccessControl::block_wp_admin();
    }

    // ==================================================================
    // block_wp_admin() — redirects blocked users
    // ==================================================================

    public function test_block_wp_admin_blocks_user_with_ffc_user_role(): void {
        Functions\when( 'get_option' )->justReturn( array(
            'block_wp_admin' => true,
        ) );
        Functions\when( 'wp_doing_ajax' )->justReturn( false );
        Functions\when( 'current_user_can' )->justReturn( false );
        Functions\when( 'home_url' )->justReturn( 'https://example.com' );
        Functions\when( 'add_query_arg' )->justReturn( 'https://example.com?ffc_redirect=access_denied' );

        $user = \Mockery::mock( 'WP_User' );
        $user->roles = array( 'ffc_user' );
        Functions\when( 'wp_get_current_user' )->justReturn( $user );

        // wp_safe_redirect is called right before exit. We throw an exception
        // to simulate the exit statement so the test can verify the redirect
        // was reached without actually terminating the process.
        Functions\expect( 'wp_safe_redirect' )
            ->once()
            ->with( 'https://example.com?ffc_redirect=access_denied' )
            ->andReturnUsing( function () {
                throw new \RuntimeException( 'exit_called' );
            } );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'exit_called' );

        AccessControl::block_wp_admin();
    }

    public function test_block_wp_admin_does_not_block_user_with_mixed_roles(): void {
        Functions\when( 'get_option' )->justReturn( array(
            'block_wp_admin' => true,
        ) );
        Functions\when( 'wp_doing_ajax' )->justReturn( false );
        Functions\when( 'current_user_can' )->justReturn( false );

        $user = \Mockery::mock( 'WP_User' );
        $user->roles = array( 'editor', 'ffc_user' );
        Functions\when( 'wp_get_current_user' )->justReturn( $user );

        Functions\expect( 'wp_safe_redirect' )->never();

        AccessControl::block_wp_admin();
    }

    public function test_block_wp_admin_blocks_user_with_custom_blocked_roles(): void {
        Functions\when( 'get_option' )->justReturn( array(
            'block_wp_admin' => true,
            'blocked_roles'  => array( 'ffc_user', 'subscriber' ),
        ) );
        Functions\when( 'wp_doing_ajax' )->justReturn( false );
        Functions\when( 'current_user_can' )->justReturn( false );
        Functions\when( 'home_url' )->justReturn( 'https://example.com' );
        Functions\when( 'add_query_arg' )->justReturn( 'https://example.com?ffc_redirect=access_denied' );

        $user = \Mockery::mock( 'WP_User' );
        $user->roles = array( 'subscriber' );
        Functions\when( 'wp_get_current_user' )->justReturn( $user );

        Functions\expect( 'wp_safe_redirect' )
            ->once()
            ->andReturnUsing( function () {
                throw new \RuntimeException( 'exit_called' );
            } );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'exit_called' );

        AccessControl::block_wp_admin();
    }

    public function test_block_wp_admin_uses_configured_redirect_url(): void {
        Functions\when( 'get_option' )->justReturn( array(
            'block_wp_admin' => true,
            'redirect_url'   => 'https://example.com/my-dashboard',
        ) );
        Functions\when( 'wp_doing_ajax' )->justReturn( false );
        Functions\when( 'current_user_can' )->justReturn( false );

        Functions\expect( 'add_query_arg' )
            ->once()
            ->with( 'ffc_redirect', 'access_denied', 'https://example.com/my-dashboard' )
            ->andReturn( 'https://example.com/my-dashboard?ffc_redirect=access_denied' );

        $user = \Mockery::mock( 'WP_User' );
        $user->roles = array( 'ffc_user' );
        Functions\when( 'wp_get_current_user' )->justReturn( $user );

        Functions\expect( 'wp_safe_redirect' )
            ->once()
            ->with( 'https://example.com/my-dashboard?ffc_redirect=access_denied' )
            ->andReturnUsing( function () {
                throw new \RuntimeException( 'exit_called' );
            } );

        $this->expectException( \RuntimeException::class );

        AccessControl::block_wp_admin();
    }

    // ==================================================================
    // hide_admin_bar() — allow admin bar setting
    // ==================================================================

    public function test_hide_admin_bar_returns_original_value_when_allow_admin_bar_is_true(): void {
        Functions\when( 'get_option' )->justReturn( array(
            'allow_admin_bar' => true,
        ) );

        $this->assertTrue( AccessControl::hide_admin_bar( true ) );
        $this->assertFalse( AccessControl::hide_admin_bar( false ) );
    }

    // ==================================================================
    // hide_admin_bar() — blocked roles
    // ==================================================================

    public function test_hide_admin_bar_returns_false_for_users_with_all_blocked_roles(): void {
        Functions\when( 'get_option' )->justReturn( array(
            'allow_admin_bar' => false,
            'blocked_roles'   => array( 'ffc_user' ),
        ) );

        $user = \Mockery::mock( 'WP_User' );
        $user->roles = array( 'ffc_user' );
        Functions\when( 'wp_get_current_user' )->justReturn( $user );

        $this->assertFalse( AccessControl::hide_admin_bar( true ) );
    }

    public function test_hide_admin_bar_returns_original_value_for_users_with_mixed_roles(): void {
        Functions\when( 'get_option' )->justReturn( array(
            'allow_admin_bar' => false,
            'blocked_roles'   => array( 'ffc_user' ),
        ) );

        $user = \Mockery::mock( 'WP_User' );
        $user->roles = array( 'editor', 'ffc_user' );
        Functions\when( 'wp_get_current_user' )->justReturn( $user );

        $this->assertTrue( AccessControl::hide_admin_bar( true ) );
    }

    public function test_hide_admin_bar_uses_default_blocked_roles_when_not_configured(): void {
        Functions\when( 'get_option' )->justReturn( array(
            'allow_admin_bar' => false,
        ) );

        $user = \Mockery::mock( 'WP_User' );
        $user->roles = array( 'ffc_user' );
        Functions\when( 'wp_get_current_user' )->justReturn( $user );

        // Default blocked_roles is ['ffc_user'], so the bar should be hidden
        $this->assertFalse( AccessControl::hide_admin_bar( true ) );
    }

    public function test_hide_admin_bar_returns_original_for_non_blocked_user(): void {
        Functions\when( 'get_option' )->justReturn( array(
            'allow_admin_bar' => false,
            'blocked_roles'   => array( 'ffc_user' ),
        ) );

        $user = \Mockery::mock( 'WP_User' );
        $user->roles = array( 'administrator' );
        Functions\when( 'wp_get_current_user' )->justReturn( $user );

        $this->assertTrue( AccessControl::hide_admin_bar( true ) );
    }

    // ==================================================================
    // get_default_settings()
    // ==================================================================

    public function test_get_default_settings_returns_array_with_expected_keys(): void {
        Functions\when( 'home_url' )->justReturn( 'https://example.com' );

        $defaults = AccessControl::get_default_settings();

        $expected_keys = array(
            'block_wp_admin',
            'blocked_roles',
            'redirect_url',
            'redirect_message',
            'allow_admin_bar',
            'bypass_for_admins',
        );

        foreach ( $expected_keys as $key ) {
            $this->assertArrayHasKey( $key, $defaults, "Missing expected key: $key" );
        }

        $this->assertCount( count( $expected_keys ), $defaults );
    }

    public function test_get_default_settings_block_wp_admin_defaults_to_false(): void {
        Functions\when( 'home_url' )->justReturn( 'https://example.com' );

        $defaults = AccessControl::get_default_settings();

        $this->assertFalse( $defaults['block_wp_admin'] );
    }

    public function test_get_default_settings_blocked_roles_defaults_to_ffc_user(): void {
        Functions\when( 'home_url' )->justReturn( 'https://example.com' );

        $defaults = AccessControl::get_default_settings();

        $this->assertSame( array( 'ffc_user' ), $defaults['blocked_roles'] );
    }

    public function test_get_default_settings_allow_admin_bar_defaults_to_false(): void {
        Functions\when( 'home_url' )->justReturn( 'https://example.com' );

        $defaults = AccessControl::get_default_settings();

        $this->assertFalse( $defaults['allow_admin_bar'] );
    }

    public function test_get_default_settings_bypass_for_admins_defaults_to_true(): void {
        Functions\when( 'home_url' )->justReturn( 'https://example.com' );

        $defaults = AccessControl::get_default_settings();

        $this->assertTrue( $defaults['bypass_for_admins'] );
    }

    public function test_get_default_settings_redirect_url_uses_home_url(): void {
        Functions\expect( 'home_url' )
            ->once()
            ->with( '/dashboard' )
            ->andReturn( 'https://example.com/dashboard' );

        $defaults = AccessControl::get_default_settings();

        $this->assertSame( 'https://example.com/dashboard', $defaults['redirect_url'] );
    }
}
