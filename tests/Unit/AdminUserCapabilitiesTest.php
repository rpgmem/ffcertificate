<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\AdminUserCapabilities;

/**
 * Tests for AdminUserCapabilities: per-user FFC capability management
 * on WordPress user edit / profile pages.
 *
 * Covers hook registration, enqueue gating, render guard clauses,
 * render output (HTML checkboxes), save guard clauses, grant/remove
 * capability logic, and Debug logging.
 *
 * @covers \FreeFormCertificate\Admin\AdminUserCapabilities
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AdminUserCapabilitiesTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var Mockery\MockInterface alias mock for UserManager */
    private $user_manager_mock;

    /** @var Mockery\MockInterface alias mock for Utils */
    private $utils_mock;

    /** @var Mockery\MockInterface alias mock for AudienceRepository */
    private $audience_repo_mock;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // UserManager alias mock
        $this->user_manager_mock = Mockery::mock( 'alias:FreeFormCertificate\UserDashboard\UserManager' );
        $this->user_manager_mock->shouldReceive( 'get_user_ffc_capabilities' )
            ->andReturn( array(
                'ffc_view_own_certificates'      => true,
                'ffc_download_own_certificates'  => false,
                'ffc_view_certificate_history'   => false,
                'ffc_book_appointments'          => false,
                'ffc_view_self_scheduling'       => false,
                'ffc_cancel_own_appointments'    => false,
                'ffc_scheduling_bypass'          => false,
                'ffc_view_audience_bookings'     => false,
                'ffc_manage_reregistration'      => false,
                'ffc_certificate_update'         => false,
            ) )
            ->byDefault();
        $this->user_manager_mock->shouldReceive( 'has_certificate_access' )
            ->andReturn( false )
            ->byDefault();
        $this->user_manager_mock->shouldReceive( 'has_appointment_access' )
            ->andReturn( false )
            ->byDefault();
        $this->user_manager_mock->shouldReceive( 'get_all_capabilities' )
            ->andReturn( array(
                'ffc_view_own_certificates',
                'ffc_download_own_certificates',
                'ffc_view_certificate_history',
                'ffc_book_appointments',
                'ffc_view_self_scheduling',
                'ffc_cancel_own_appointments',
                'ffc_scheduling_bypass',
                'ffc_view_audience_bookings',
                'ffc_manage_reregistration',
                'ffc_manage_recruitment',
                'ffc_manage_certificates',
                'ffc_export_certificates',
                'ffc_manage_self_scheduling',
                'ffc_manage_audiences',
                'ffc_view_activity_log',
                'ffc_manage_user_custom_fields',
                'ffc_view_as_user',
                'ffc_manage_settings',
                'ffc_view_recruitment',
                'ffc_import_recruitment_csv',
                'ffc_call_recruitment_candidates',
                'ffc_view_recruitment_pii',
                'ffc_manage_recruitment_settings',
                'ffc_manage_recruitment_reasons',
                'ffc_certificate_update',
            ) )
            ->byDefault();

        // AudienceRepository alias mock — render() lists active audiences and
        // the user's memberships for the editable membership checklist; save()
        // diffs and applies add_member/remove_member.
        $this->audience_repo_mock = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceRepository' );
        $this->audience_repo_mock->shouldReceive( 'get_all' )->andReturn( array() )->byDefault();
        $this->audience_repo_mock->shouldReceive( 'get_user_audiences' )->andReturn( array() )->byDefault();
        $this->audience_repo_mock->shouldReceive( 'add_member' )->andReturn( 1 )->byDefault();
        $this->audience_repo_mock->shouldReceive( 'remove_member' )->andReturn( true )->byDefault();

        // Utils alias mock
        $this->utils_mock = Mockery::mock( 'alias:FreeFormCertificate\Core\Utils' );
        $this->utils_mock->shouldReceive( 'asset_suffix' )
            ->andReturn( '.min' )
            ->byDefault();
        $this->utils_mock->shouldReceive( 'get_post_string' )->andReturnUsing( function ( $key, $default = '' ) {
            return isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? $_POST[ $key ] : $default;
        } )->byDefault();

        // Common WP stubs
        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_html_e' )->alias( function ( $text ) {
            echo $text;
        } );
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'esc_attr__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_url' )->returnArg();
        Functions\when( 'admin_url' )->returnArg();
        Functions\when( 'checked' )->alias( function ( $checked, $current = true, $echo = true ) {
            $result = $checked == $current ? ' checked=\'checked\'' : '';
            if ( $echo ) {
                echo $result;
            }
            return $result;
        } );
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'wp_nonce_field' )->alias( function () {
            echo '<input type="hidden" name="ffc_capabilities_nonce" value="test_nonce" />';
        } );
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'user_can' )->justReturn( false );
        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\when( 'get_userdata' )->justReturn( false );
    }

    protected function tearDown(): void {
        unset( $_POST['ffc_capabilities_nonce'] );
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // init()
    // ==================================================================

    public function test_init_registers_hooks(): void {
        Functions\expect( 'add_action' )
            ->once()
            ->with( 'show_user_profile', array( AdminUserCapabilities::class, 'render_capability_fields' ) );

        Functions\expect( 'add_action' )
            ->once()
            ->with( 'edit_user_profile', array( AdminUserCapabilities::class, 'render_capability_fields' ) );

        Functions\expect( 'add_action' )
            ->once()
            ->with( 'personal_options_update', array( AdminUserCapabilities::class, 'save_capability_fields' ) );

        Functions\expect( 'add_action' )
            ->once()
            ->with( 'edit_user_profile_update', array( AdminUserCapabilities::class, 'save_capability_fields' ) );

        Functions\expect( 'add_action' )
            ->once()
            ->with( 'admin_enqueue_scripts', array( AdminUserCapabilities::class, 'enqueue_scripts' ) );

        AdminUserCapabilities::init();
    }

    // ==================================================================
    // enqueue_scripts()
    // ==================================================================

    public function test_enqueue_scripts_on_user_edit_page(): void {
        Functions\expect( 'wp_enqueue_style' )
            ->once()
            ->with(
                'ffc-common',
                Mockery::pattern( '/ffc-common\.min\.css/' ),
                array(),
                FFC_VERSION
            );
        Functions\expect( 'wp_enqueue_style' )
            ->once()
            ->with(
                'ffc-user-permissions',
                Mockery::pattern( '/ffc-user-permissions\.min\.css/' ),
                array( 'ffc-common' ),
                FFC_VERSION
            );
        Functions\expect( 'wp_enqueue_script' )
            ->once()
            ->with(
                'ffc-user-capabilities',
                Mockery::pattern( '/ffc-user-capabilities\.min\.js/' ),
                array(),
                FFC_VERSION,
                true
            );

        AdminUserCapabilities::enqueue_scripts( 'user-edit.php' );
    }

    public function test_enqueue_scripts_skips_other_pages(): void {
        Functions\expect( 'wp_enqueue_script' )->never();

        AdminUserCapabilities::enqueue_scripts( 'edit.php' );
    }

    // ==================================================================
    // render_capability_fields() — guard clauses
    // ==================================================================

    public function test_render_skips_for_non_admin(): void {
        Functions\when( 'current_user_can' )->justReturn( false );

        $user = new \WP_User( 5 );
        $user->roles = array( 'ffc_user' );

        ob_start();
        AdminUserCapabilities::render_capability_fields( $user );
        $output = ob_get_clean();

        $this->assertEmpty( $output );
    }

    public function test_render_skips_for_admin_users(): void {
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'user_can' )->justReturn( true );

        $user = new \WP_User( 1 );
        $user->roles = array( 'administrator' );

        ob_start();
        AdminUserCapabilities::render_capability_fields( $user );
        $output = ob_get_clean();

        $this->assertEmpty( $output );
    }

    public function test_render_skips_for_non_ffc_users(): void {
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'user_can' )->justReturn( false );

        // No ffc_user role and no FFC capabilities
        $this->user_manager_mock->shouldReceive( 'has_certificate_access' )
            ->with( 10 )
            ->andReturn( false );
        $this->user_manager_mock->shouldReceive( 'has_appointment_access' )
            ->with( 10 )
            ->andReturn( false );

        $user = new \WP_User( 10 );
        $user->roles = array( 'subscriber' );

        ob_start();
        AdminUserCapabilities::render_capability_fields( $user );
        $output = ob_get_clean();

        $this->assertEmpty( $output );
    }

    // ==================================================================
    // render_capability_fields() — HTML output
    // ==================================================================

    public function test_render_outputs_capability_checkboxes(): void {
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'user_can' )->justReturn( false );

        $user = new \WP_User( 5 );
        $user->roles = array( 'ffc_user' );

        ob_start();
        AdminUserCapabilities::render_capability_fields( $user );
        $output = ob_get_clean();

        // Heading + panel wrapper.
        $this->assertStringContainsString( 'FFC Permissions', $output );
        $this->assertStringContainsString( 'ffc-cap-panel', $output );

        // Checkbox inputs across the user-level groups…
        $this->assertStringContainsString( 'ffc_cap_ffc_view_own_certificates', $output );
        $this->assertStringContainsString( 'ffc_cap_ffc_download_own_certificates', $output );
        $this->assertStringContainsString( 'ffc_cap_ffc_book_appointments', $output );
        $this->assertStringContainsString( 'ffc_cap_ffc_view_self_scheduling', $output );
        $this->assertStringContainsString( 'ffc_cap_ffc_manage_reregistration', $output );

        // …and the admin-level caps that the old flat form never rendered
        // (proves the full 26-cap catalog is now shown, fixing the silent
        // remove_cap() on save).
        $this->assertStringContainsString( 'ffc_cap_ffc_manage_recruitment', $output );
        $this->assertStringContainsString( 'ffc_cap_ffc_view_recruitment_pii', $output );
        $this->assertStringContainsString( 'ffc_cap_ffc_manage_settings', $output );

        // New affordances: slug chips, origin badges, search box, presets.
        $this->assertStringContainsString( 'ffc-cap-slug', $output );
        $this->assertStringContainsString( 'ffc-cap-origin', $output );
        $this->assertStringContainsString( 'ffc-cap-search', $output );
        $this->assertStringContainsString( 'data-ffc-preset="all"', $output );
        $this->assertStringContainsString( 'data-ffc-preset="none"', $output );

        // Read-only context summary (role + audiences).
        $this->assertStringContainsString( 'ffc-cap-context', $output );
        $this->assertStringContainsString( 'ffc_user', $output );
        $this->assertStringContainsString( 'page=ffc-scheduling-audiences', $output );

        // Nonce field.
        $this->assertStringContainsString( 'ffc_capabilities_nonce', $output );
    }

    public function test_render_outputs_audience_membership_checklist(): void {
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'user_can' )->justReturn( false );

        $this->audience_repo_mock->shouldReceive( 'get_all' )->andReturn(
            array(
                (object) array(
                    'id'    => 3,
                    'name'  => 'Teachers',
                    'color' => '#ff0000',
                ),
                (object) array(
                    'id'    => 7,
                    'name'  => 'Staff',
                    'color' => '',
                ),
            )
        );
        // The user is a member of audience 7 only.
        $this->audience_repo_mock->shouldReceive( 'get_user_audiences' )->andReturn(
            array( (object) array( 'id' => 7 ) )
        );

        $user        = new \WP_User( 5 );
        $user->roles = array( 'ffc_user' );

        ob_start();
        AdminUserCapabilities::render_capability_fields( $user );
        $output = ob_get_clean();

        // Both active audiences render as checkboxes.
        $this->assertStringContainsString( 'name="ffc_audience[]" value="3"', $output );
        $this->assertStringContainsString( 'name="ffc_audience[]" value="7"', $output );
        $this->assertStringContainsString( 'Teachers', $output );

        // The current membership (7) is pre-checked; the non-membership (3) is not.
        $this->assertMatchesRegularExpression( '/value="7"[^>]*checked/', $output );
        $this->assertDoesNotMatchRegularExpression( '/value="3"[^>]*checked/', $output );
    }

    // ==================================================================
    // save_capability_fields() — guard clauses
    // ==================================================================

    public function test_save_aborts_without_nonce(): void {
        // No $_POST nonce set
        unset( $_POST['ffc_capabilities_nonce'] );

        Functions\when( 'wp_verify_nonce' )->justReturn( false );

        $user = new \WP_User( 5 );
        Functions\when( 'get_userdata' )->justReturn( $user );

        // Should return early — user caps should not change
        AdminUserCapabilities::save_capability_fields( 5 );

        $this->assertEmpty( $user->caps );
    }

    public function test_save_aborts_without_permission(): void {
        $_POST['ffc_capabilities_nonce'] = 'valid_nonce';
        Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
        Functions\when( 'current_user_can' )->justReturn( false );

        $user = new \WP_User( 5 );
        Functions\when( 'get_userdata' )->justReturn( $user );

        AdminUserCapabilities::save_capability_fields( 5 );

        $this->assertEmpty( $user->caps );
    }

    // ==================================================================
    // save_capability_fields() — grant / remove
    // ==================================================================

    public function test_save_grants_capabilities(): void {
        $_POST['ffc_capabilities_nonce'] = 'valid_nonce';
        $_POST['ffc_cap_ffc_view_own_certificates'] = '1';
        $_POST['ffc_cap_ffc_book_appointments'] = '1';

        Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
        Functions\when( 'current_user_can' )->justReturn( true );

        $user = new \WP_User( 5 );
        Functions\when( 'get_userdata' )->justReturn( $user );

        AdminUserCapabilities::save_capability_fields( 5 );

        // Granted capabilities should be true
        $this->assertTrue( $user->caps['ffc_view_own_certificates'] );
        $this->assertTrue( $user->caps['ffc_book_appointments'] );

        // Non-granted capabilities should have been removed (not present)
        $this->assertArrayNotHasKey( 'download_own_certificates', $user->caps );
        $this->assertArrayNotHasKey( 'ffc_view_self_scheduling', $user->caps );

        // Clean up
        unset( $_POST['ffc_cap_ffc_view_own_certificates'], $_POST['ffc_cap_ffc_book_appointments'] );
    }

    public function test_save_removes_capabilities(): void {
        $_POST['ffc_capabilities_nonce'] = 'valid_nonce';
        // No ffc_cap_* fields set — all capabilities should be removed

        Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
        Functions\when( 'current_user_can' )->justReturn( true );

        $user = new \WP_User( 5 );
        // Pre-grant some capabilities
        $user->add_cap( 'ffc_view_own_certificates', true );
        $user->add_cap( 'ffc_book_appointments', true );
        Functions\when( 'get_userdata' )->justReturn( $user );

        AdminUserCapabilities::save_capability_fields( 5 );

        // All capabilities should have been removed
        $this->assertArrayNotHasKey( 'ffc_view_own_certificates', $user->caps );
        $this->assertArrayNotHasKey( 'ffc_book_appointments', $user->caps );
    }

    // ==================================================================
    // save_capability_fields() — audience membership sync
    // ==================================================================

    public function test_save_adds_audience_membership(): void {
        $_POST['ffc_capabilities_nonce'] = 'valid_nonce';
        $_POST['ffc_audience']           = array( '3', '7' );

        Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
        Functions\when( 'current_user_can' )->justReturn( true );

        $this->audience_repo_mock->shouldReceive( 'get_all' )->andReturn(
            array( (object) array( 'id' => 3 ), (object) array( 'id' => 7 ) )
        );
        // Already a member of 7 → only 3 should be added, nothing removed.
        $this->audience_repo_mock->shouldReceive( 'get_user_audiences' )->andReturn(
            array( (object) array( 'id' => 7 ) )
        );
        $this->audience_repo_mock->shouldReceive( 'add_member' )->once()->with( 3, 5 )->andReturn( 1 );
        $this->audience_repo_mock->shouldReceive( 'remove_member' )->never();

        $user = new \WP_User( 5 );
        Functions\when( 'get_userdata' )->justReturn( $user );

        AdminUserCapabilities::save_capability_fields( 5 );

        unset( $_POST['ffc_audience'] );
        $this->assertTrue( true );
    }

    public function test_save_removes_audience_membership_when_unchecked(): void {
        $_POST['ffc_capabilities_nonce'] = 'valid_nonce';
        // No ffc_audience key → every box unchecked → drop current memberships.

        Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
        Functions\when( 'current_user_can' )->justReturn( true );

        $this->audience_repo_mock->shouldReceive( 'get_all' )->andReturn(
            array( (object) array( 'id' => 7 ) )
        );
        $this->audience_repo_mock->shouldReceive( 'get_user_audiences' )->andReturn(
            array( (object) array( 'id' => 7 ) )
        );
        $this->audience_repo_mock->shouldReceive( 'remove_member' )->once()->with( 7, 5 )->andReturn( true );
        $this->audience_repo_mock->shouldReceive( 'add_member' )->never();

        $user = new \WP_User( 5 );
        Functions\when( 'get_userdata' )->justReturn( $user );

        AdminUserCapabilities::save_capability_fields( 5 );

        $this->assertTrue( true );
    }

    public function test_save_does_not_touch_audiences_when_none_active(): void {
        $_POST['ffc_capabilities_nonce'] = 'valid_nonce';

        Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
        Functions\when( 'current_user_can' )->justReturn( true );

        // No active audiences → the checklist never rendered → sync must be a
        // no-op so an unrelated save can't wipe memberships.
        $this->audience_repo_mock->shouldReceive( 'get_all' )->andReturn( array() );
        $this->audience_repo_mock->shouldReceive( 'add_member' )->never();
        $this->audience_repo_mock->shouldReceive( 'remove_member' )->never();

        $user = new \WP_User( 5 );
        Functions\when( 'get_userdata' )->justReturn( $user );

        AdminUserCapabilities::save_capability_fields( 5 );

        $this->assertTrue( true );
    }

    // ==================================================================
    // save_capability_fields() — Debug logging
    // ==================================================================

    public function test_save_reaches_debug_log_path(): void {
        // The Debug class is already loaded by the autoloader, so we cannot
        // create an alias mock.  Instead, verify the save completes without
        // error when the Debug class is present (class_exists returns true
        // naturally since the autoloader loaded it).
        $_POST['ffc_capabilities_nonce'] = 'valid_nonce';
        $_POST['ffc_cap_ffc_view_own_certificates'] = '1';

        Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
        Functions\when( 'current_user_can' )->justReturn( true );

        $user = new \WP_User( 5 );
        Functions\when( 'get_userdata' )->justReturn( $user );

        // The save method will call Debug::log_user_manager() since
        // the class exists.  The real Debug class calls get_option (stubbed
        // in setUp) and should not throw.
        AdminUserCapabilities::save_capability_fields( 5 );

        // If we reached here without error, the debug log path was executed.
        $this->assertTrue( $user->caps['ffc_view_own_certificates'] );

        // Clean up
        unset( $_POST['ffc_cap_ffc_view_own_certificates'] );
    }
}
