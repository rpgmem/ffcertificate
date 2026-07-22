<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\UserDashboard\CapabilityManager;
use FreeFormCertificate\UserDashboard\CapabilityMigrator;
use FreeFormCertificate\UserDashboard\RoleRegistrar;

/**
 * Tests for CapabilityManager: constants, grant, revoke, access checks, role management.
 */
class CapabilityManagerTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // #673: EmailService::send derives a text/plain alternative for HTML
        // messages — stub the WP glue that derivation touches.
        Functions\when( 'wp_strip_all_tags' )->alias(
            static function ( $s ) {
                return trim( (string) strip_tags( (string) $s ) );
            }
        );
        Functions\when( 'add_action' )->justReturn( true );
        Functions\when( 'remove_action' )->justReturn( true );
        Functions\when( 'apply_filters' )->returnArg( 2 );

        Functions\when( '__' )->returnArg();
        Functions\when( 'get_option' )->justReturn( 0 );
        Functions\when( 'absint' )->alias( function( $val ) { return abs( intval( $val ) ); } );
        Functions\when( 'get_current_user_id' )->justReturn( 0 );
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'sanitize_key' )->alias( function( $key ) { return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', $key ) ); } );
        Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Constants
    // ------------------------------------------------------------------

    public function test_context_constants_are_strings(): void {
        $this->assertSame( 'certificate', CapabilityManager::CONTEXT_CERTIFICATE );
        $this->assertSame( 'appointment', CapabilityManager::CONTEXT_APPOINTMENT );
        $this->assertSame( 'audience', CapabilityManager::CONTEXT_AUDIENCE );
    }

    public function test_certificate_capabilities_contains_namespaced_caps(): void {
        $caps = CapabilityManager::CERTIFICATE_CAPABILITIES;
        $this->assertContains( 'ffc_view_own_certificates', $caps );
        $this->assertContains( 'ffc_download_own_certificates', $caps );
        $this->assertContains( 'ffc_view_own_certificate_history', $caps );
        $this->assertCount( 3, $caps );
    }

    public function test_certificate_capabilities_no_longer_contains_legacy_names(): void {
        $caps = CapabilityManager::CERTIFICATE_CAPABILITIES;
        $this->assertNotContains( 'view_own_certificates', $caps );
        $this->assertNotContains( 'download_own_certificates', $caps );
        $this->assertNotContains( 'view_certificate_history', $caps );
    }

    public function test_legacy_cap_renames_returns_3_pairs(): void {
        $renames = CapabilityMigrator::legacy_cap_renames();
        $this->assertCount( 3, $renames );
        $this->assertSame( 'ffc_view_own_certificates', $renames['view_own_certificates'] );
        $this->assertSame( 'ffc_download_own_certificates', $renames['download_own_certificates'] );
        $this->assertSame( 'ffc_view_own_certificate_history', $renames['view_certificate_history'] );
    }

    public function test_appointment_capabilities_contains_expected_caps(): void {
        $caps = CapabilityManager::APPOINTMENT_CAPABILITIES;
        $this->assertContains( 'ffc_book_own_appointments', $caps );
        $this->assertContains( 'ffc_view_own_appointments', $caps );
        $this->assertContains( 'ffc_cancel_own_appointments', $caps );
        $this->assertCount( 3, $caps );
    }

    public function test_audience_capabilities_contains_expected_caps(): void {
        $caps = CapabilityManager::AUDIENCE_CAPABILITIES;
        $this->assertContains( 'ffc_view_own_audience_bookings', $caps );
        $this->assertCount( 1, $caps );
    }

    public function test_admin_capabilities_contains_pre_6_2_caps(): void {
        $caps = CapabilityManager::ADMIN_CAPABILITIES;
        $this->assertContains( 'ffc_bypass_appointments', $caps );
        $this->assertContains( 'ffc_manage_reregistration', $caps );
        $this->assertContains( 'ffc_manage_recruitment', $caps );
    }

    public function test_admin_capabilities_contains_6_2_module_caps(): void {
        $caps = CapabilityManager::ADMIN_CAPABILITIES;
        $this->assertContains( 'ffc_manage_certificates', $caps );
        $this->assertContains( 'ffc_export_certificates', $caps );
        $this->assertContains( 'ffc_manage_appointments', $caps );
        $this->assertContains( 'ffc_manage_audiences', $caps );
        $this->assertContains( 'ffc_view_activity_log', $caps );
        $this->assertContains( 'ffc_manage_custom_fields', $caps );
        $this->assertContains( 'ffc_view_as_user', $caps );
        $this->assertContains( 'ffc_manage_settings', $caps );
    }

    public function test_admin_capabilities_contains_6_2_recruitment_granular_caps(): void {
        $caps = CapabilityManager::ADMIN_CAPABILITIES;
        $this->assertContains( 'ffc_view_recruitment', $caps );
        $this->assertContains( 'ffc_import_recruitment', $caps );
        $this->assertContains( 'ffc_call_recruitment', $caps );
        $this->assertContains( 'ffc_view_recruitment_pii', $caps );
        $this->assertContains( 'ffc_manage_recruitment_settings', $caps );
        $this->assertContains( 'ffc_manage_recruitment_reasons', $caps );
    }

    public function test_admin_capabilities_contains_reactivated_certificate_update(): void {
        $this->assertContains( 'ffc_edit_certificates', CapabilityManager::ADMIN_CAPABILITIES );
    }

    public function test_admin_capabilities_contains_rest_api_caps(): void {
        // 6.4.1: REST-API authentication capability for external
        // integrators using Application Passwords. Pinned by a test so
        // future contributors know it's grant-on-activation territory
        // and not a leftover placeholder.
        $this->assertContains( 'ffc_view_forms_api', CapabilityManager::ADMIN_CAPABILITIES );
    }

    public function test_future_capabilities_constant_is_empty_in_6_2(): void {
        // Both placeholders retired in 6.2.0:
        //   - ffc_reregistration: removed (audience-targeting already covers).
        //   - ffc_edit_certificates: promoted to ADMIN_CAPABILITIES.
        $this->assertSame( array(), CapabilityManager::FUTURE_CAPABILITIES );
    }

    public function test_admin_capabilities_does_not_contain_removed_ffc_reregistration(): void {
        $this->assertNotContains( 'ffc_reregistration', CapabilityManager::ADMIN_CAPABILITIES );
        $this->assertNotContains( 'ffc_reregistration', CapabilityManager::get_all_capabilities() );
    }

    // ------------------------------------------------------------------
    // 6.2.0 module-manager + recruitment-tier roles
    // ------------------------------------------------------------------

    public function test_register_module_roles_creates_all_expected_slugs(): void {
        $created = array();
        Functions\when( 'get_role' )->justReturn( null );
        Functions\when( 'add_role' )->alias(
            static function ( string $slug, string $label, array $caps ) use ( &$created ): bool {
                $created[ $slug ] = array( 'label' => $label, 'caps' => $caps );
                return true;
            }
        );

        RoleRegistrar::register_module_roles();

        $expected_slugs = array(
            'ffc_administrator',
            'ffc_certificate_manager',
            'ffc_appointments_manager',
            'ffc_audience_manager',
            'ffc_reregistration_manager',
            'ffc_readonly',
            'ffc_recruitment_auditor',
            'ffc_recruitment_operator',
            'ffc_recruitment_admin',
        );
        foreach ( $expected_slugs as $slug ) {
            $this->assertArrayHasKey( $slug, $created, "Missing role: {$slug}" );
            $this->assertTrue( $created[ $slug ]['caps']['read'], "Role {$slug} must include read cap" );
        }
    }

    public function test_ffc_administrator_aggregates_every_capability_without_manage_options(): void {
        $created = array();
        Functions\when( 'get_role' )->justReturn( null );
        Functions\when( 'add_role' )->alias(
            static function ( string $slug, string $label, array $caps ) use ( &$created ): bool {
                $created[ $slug ] = $caps;
                return true;
            }
        );

        RoleRegistrar::register_module_roles();

        $admin = $created['ffc_administrator'];

        // Carries `read` plus EVERY FFC capability (admin surface + the
        // end-user self-service `own_` caps) — the GAP F aggregator.
        $this->assertTrue( $admin['read'] );
        foreach ( CapabilityManager::get_all_capabilities() as $cap ) {
            $this->assertTrue( $admin[ $cap ] ?? null, "ffc_administrator must grant {$cap}" );
        }

        // Spot-check representative caps from each tier, including the GAP E
        // destructive caps and the most sensitive admin caps.
        foreach ( array(
            'ffc_view_own_certificates',
            'ffc_manage_certificates',
            'ffc_delete_certificates',
            'ffc_delete_recruitment',
            'ffc_view_recruitment_pii',
            'ffc_view_as_user',
            'ffc_manage_settings',
        ) as $cap ) {
            $this->assertTrue( $admin[ $cap ] ?? null, "ffc_administrator must grant {$cap}" );
        }

        // But NOT WordPress super-admin — the whole point is full FFC admin
        // without `manage_options`.
        $this->assertArrayNotHasKey( 'manage_options', $admin );
    }

    public function test_register_module_roles_grants_caps_per_role(): void {
        $created = array();
        Functions\when( 'get_role' )->justReturn( null );
        Functions\when( 'add_role' )->alias(
            static function ( string $slug, string $label, array $caps ) use ( &$created ): bool {
                $created[ $slug ] = $caps;
                return true;
            }
        );

        RoleRegistrar::register_module_roles();

        // Spot-check a few of the cap maps.
        $this->assertTrue( $created['ffc_certificate_manager']['ffc_manage_certificates'] );
        $this->assertTrue( $created['ffc_certificate_manager']['ffc_export_certificates'] );
        $this->assertTrue( $created['ffc_certificate_manager']['ffc_edit_certificates'] );

        $this->assertTrue( $created['ffc_recruitment_auditor']['ffc_view_recruitment'] );
        $this->assertArrayNotHasKey( 'ffc_call_recruitment', $created['ffc_recruitment_auditor'] );

        $this->assertTrue( $created['ffc_recruitment_operator']['ffc_view_recruitment'] );
        $this->assertTrue( $created['ffc_recruitment_operator']['ffc_call_recruitment'] );
        $this->assertArrayNotHasKey( 'ffc_import_recruitment', $created['ffc_recruitment_operator'] );

        $this->assertTrue( $created['ffc_recruitment_admin']['ffc_manage_recruitment_settings'] );
        $this->assertTrue( $created['ffc_recruitment_admin']['ffc_manage_recruitment_reasons'] );
        $this->assertTrue( $created['ffc_recruitment_admin']['ffc_view_recruitment_pii'] );

        // ffc_readonly is the cross-module read-only auditor (GAP D): sees every
        // module read-only…
        $operator = $created['ffc_readonly'];
        foreach ( array(
            'ffc_view_certificates',
            'ffc_view_appointments',
            'ffc_view_audiences',
            'ffc_view_reregistration',
            'ffc_view_custom_fields',
            'ffc_view_activity_log',
            'ffc_view_recruitment',
            'ffc_view_recruitment_settings',
            'ffc_view_recruitment_reasons',
            'ffc_view_url_shortener',
        ) as $view_cap ) {
            $this->assertTrue( $operator[ $view_cap ], "ffc_readonly must grant {$view_cap}" );
        }
        // …but never write caps, the Settings page, raw PII, the REST
        // integrator cap, or impersonation.
        foreach ( array(
            'ffc_manage_certificates',
            'ffc_manage_audiences',
            'ffc_view_settings',
            'ffc_view_recruitment_pii',
            'ffc_view_forms_api',
            'ffc_view_as_user',
        ) as $forbidden ) {
            $this->assertArrayNotHasKey( $forbidden, $operator, "ffc_readonly must NOT grant {$forbidden}" );
        }
    }

    // ------------------------------------------------------------------
    // get_all_capabilities()
    // ------------------------------------------------------------------

    public function test_get_all_capabilities_returns_merged_list(): void {
        $all = CapabilityManager::get_all_capabilities();

        $expected_count = count( CapabilityManager::CERTIFICATE_CAPABILITIES )
            + count( CapabilityManager::APPOINTMENT_CAPABILITIES )
            + count( CapabilityManager::AUDIENCE_CAPABILITIES )
            + count( CapabilityManager::ADMIN_CAPABILITIES )
            + count( CapabilityManager::FUTURE_CAPABILITIES );

        $this->assertCount( $expected_count, $all );
        $this->assertContains( 'ffc_view_own_certificates', $all );
        $this->assertContains( 'ffc_book_own_appointments', $all );
        $this->assertContains( 'ffc_view_own_audience_bookings', $all );
        $this->assertContains( 'ffc_bypass_appointments', $all );
        $this->assertContains( 'ffc_edit_certificates', $all );
    }

    // ------------------------------------------------------------------
    // grant_context_capabilities() — dispatch
    // ------------------------------------------------------------------

    public function test_grant_context_capabilities_delegates_to_certificate(): void {
        $mock_user = Mockery::mock( 'WP_User' );
        $mock_user->shouldReceive( 'has_cap' )->andReturn( false );
        $mock_user->shouldReceive( 'add_cap' )->times( 3 ); // 3 certificate caps
        $mock_user->ID = 10;
        $mock_user->user_email = '';
        $mock_user->display_name = 'Test';

        Functions\when( 'get_userdata' )->justReturn( $mock_user );

        CapabilityManager::grant_context_capabilities( 10, 'certificate' );
    }

    public function test_grant_context_capabilities_delegates_to_appointment(): void {
        $mock_user = Mockery::mock( 'WP_User' );
        $mock_user->shouldReceive( 'has_cap' )->andReturn( false );
        $mock_user->shouldReceive( 'add_cap' )->times( 3 ); // 3 appointment caps
        $mock_user->ID = 20;
        $mock_user->user_email = '';
        $mock_user->display_name = 'Test';

        Functions\when( 'get_userdata' )->justReturn( $mock_user );

        CapabilityManager::grant_context_capabilities( 20, 'appointment' );
    }

    public function test_grant_context_capabilities_delegates_to_audience(): void {
        $mock_user = Mockery::mock( 'WP_User' );
        $mock_user->shouldReceive( 'has_cap' )->andReturn( false );
        $mock_user->shouldReceive( 'add_cap' )->times( 1 ); // 1 audience cap
        $mock_user->ID = 30;
        $mock_user->user_email = '';
        $mock_user->display_name = 'Test';

        Functions\when( 'get_userdata' )->justReturn( $mock_user );

        CapabilityManager::grant_context_capabilities( 30, 'audience' );
    }

    public function test_grant_context_sends_chromed_access_email_when_enabled(): void {
        $mock_user = Mockery::mock( 'WP_User' );
        $mock_user->shouldReceive( 'has_cap' )->andReturn( false );
        $mock_user->shouldReceive( 'add_cap' );
        $mock_user->ID = 40;
        $mock_user->user_email = 'grantee@example.com';
        $mock_user->display_name = 'Grantee';

        Functions\when( 'get_userdata' )->justReturn( $mock_user );
        Functions\when( 'get_option' )->alias(
            static function ( $key, $default = false ) {
                if ( 'ffc_settings' === $key ) {
                    return array( 'notify_capability_grant' => true );
                }
                if ( 'blogname' === $key ) {
                    return 'My Site';
                }
                if ( 'ffc_dashboard_page_id' === $key ) {
                    return 5;
                }
                if ( 'ffc_email_template' === $key ) {
                    return array();
                }
                return $default;
            }
        );
        Functions\when( 'wp_specialchars_decode' )->returnArg();
        Functions\when( 'get_permalink' )->justReturn( 'https://my.site/dashboard' );
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'esc_url' )->returnArg();
        Functions\when( 'wp_kses_post' )->returnArg();
        Functions\when( 'get_bloginfo' )->justReturn( 'My Site' );
        Functions\when( 'home_url' )->justReturn( 'https://my.site' );
        Functions\when( 'wp_date' )->justReturn( '2026' );
        Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );

        $captured = null;
        Functions\when( 'wp_mail' )->alias(
            static function ( $to, $subj, $body ) use ( &$captured ) {
                $captured = compact( 'to', 'subj', 'body' );
                return true;
            }
        );

        CapabilityManager::grant_context_capabilities( 40, 'certificate' );

        $this->assertNotNull( $captured, 'access-granted email should be sent' );
        $this->assertSame( 'grantee@example.com', $captured['to'] );
        $this->assertStringContainsString( 'Hello Grantee,', $captured['body'] );
        // Wrapped in the shared configurable chrome.
        $this->assertStringContainsString( '<!DOCTYPE html>', $captured['body'] );
    }

    // ------------------------------------------------------------------
    // grant_*_capabilities() — skips if user already has cap
    // ------------------------------------------------------------------

    public function test_grant_certificate_capabilities_skips_existing_caps(): void {
        $mock_user = Mockery::mock( 'WP_User' );
        $mock_user->shouldReceive( 'has_cap' )->andReturn( true ); // all caps already exist
        $mock_user->shouldReceive( 'add_cap' )->never();
        $mock_user->ID = 10;
        $mock_user->user_email = '';
        $mock_user->display_name = 'Test';

        Functions\when( 'get_userdata' )->justReturn( $mock_user );

        CapabilityManager::grant_certificate_capabilities( 10 );
    }

    public function test_grant_capabilities_does_nothing_for_invalid_user(): void {
        Functions\expect( 'get_userdata' )->times( 3 )->with( 999 )->andReturn( false );

        CapabilityManager::grant_certificate_capabilities( 999 );
        CapabilityManager::grant_appointment_capabilities( 999 );
        CapabilityManager::grant_audience_capabilities( 999 );
    }

    // ------------------------------------------------------------------
    // has_certificate_access()
    // ------------------------------------------------------------------

    public function test_has_certificate_access_returns_true_for_admin(): void {
        $mock_user = Mockery::mock( 'WP_User' );
        $mock_user->shouldReceive( 'has_cap' )->andReturn( false );

        Functions\when( 'get_userdata' )->justReturn( $mock_user );
        Functions\when( 'user_can' )->alias( function( $uid, $cap ) {
            return $cap === 'manage_options';
        } );

        $this->assertTrue( CapabilityManager::has_certificate_access( 1 ) );
    }

    public function test_has_certificate_access_returns_true_when_user_has_cap(): void {
        $mock_user = Mockery::mock( 'WP_User' );
        $mock_user->shouldReceive( 'has_cap' )->with( 'ffc_view_own_certificates' )->andReturn( true );

        Functions\when( 'get_userdata' )->justReturn( $mock_user );
        Functions\when( 'user_can' )->justReturn( false );

        $this->assertTrue( CapabilityManager::has_certificate_access( 5 ) );
    }

    public function test_has_certificate_access_returns_false_without_caps(): void {
        $mock_user = Mockery::mock( 'WP_User' );
        $mock_user->shouldReceive( 'has_cap' )->andReturn( false );

        Functions\when( 'get_userdata' )->justReturn( $mock_user );
        Functions\when( 'user_can' )->justReturn( false );

        $this->assertFalse( CapabilityManager::has_certificate_access( 5 ) );
    }

    public function test_has_certificate_access_returns_false_for_invalid_user(): void {
        Functions\when( 'get_userdata' )->justReturn( false );

        $this->assertFalse( CapabilityManager::has_certificate_access( 999 ) );
    }

    // ------------------------------------------------------------------
    // has_appointment_access()
    // ------------------------------------------------------------------

    public function test_has_appointment_access_returns_true_for_admin(): void {
        $mock_user = Mockery::mock( 'WP_User' );
        $mock_user->shouldReceive( 'has_cap' )->andReturn( false );

        Functions\when( 'get_userdata' )->justReturn( $mock_user );
        Functions\when( 'user_can' )->alias( function( $uid, $cap ) {
            return $cap === 'manage_options';
        } );

        $this->assertTrue( CapabilityManager::has_appointment_access( 1 ) );
    }

    public function test_has_appointment_access_returns_true_when_user_has_cap(): void {
        $mock_user = Mockery::mock( 'WP_User' );
        $mock_user->shouldReceive( 'has_cap' )->with( 'ffc_book_own_appointments' )->andReturn( true );

        Functions\when( 'get_userdata' )->justReturn( $mock_user );
        Functions\when( 'user_can' )->justReturn( false );

        $this->assertTrue( CapabilityManager::has_appointment_access( 5 ) );
    }

    public function test_has_appointment_access_returns_false_without_caps(): void {
        $mock_user = Mockery::mock( 'WP_User' );
        $mock_user->shouldReceive( 'has_cap' )->andReturn( false );

        Functions\when( 'get_userdata' )->justReturn( $mock_user );
        Functions\when( 'user_can' )->justReturn( false );

        $this->assertFalse( CapabilityManager::has_appointment_access( 5 ) );
    }

    // ------------------------------------------------------------------
    // get_user_ffc_capabilities()
    // ------------------------------------------------------------------

    public function test_get_user_ffc_capabilities_returns_map(): void {
        $mock_user = Mockery::mock( 'WP_User' );
        $mock_user->shouldReceive( 'has_cap' )->with( 'ffc_view_own_certificates' )->andReturn( true );
        $mock_user->shouldReceive( 'has_cap' )->andReturn( false ); // everything else

        Functions\when( 'get_userdata' )->justReturn( $mock_user );

        $caps = CapabilityManager::get_user_ffc_capabilities( 5 );

        $this->assertIsArray( $caps );
        $this->assertTrue( $caps['ffc_view_own_certificates'] );
        $this->assertFalse( $caps['ffc_book_own_appointments'] );
    }

    public function test_get_user_ffc_capabilities_returns_empty_for_invalid_user(): void {
        Functions\when( 'get_userdata' )->justReturn( false );

        $this->assertSame( array(), CapabilityManager::get_user_ffc_capabilities( 999 ) );
    }

    // ------------------------------------------------------------------
    // set_user_capability()
    // ------------------------------------------------------------------

    public function test_set_user_capability_grants_valid_cap(): void {
        $mock_user = Mockery::mock( 'WP_User' );
        $mock_user->shouldReceive( 'add_cap' )->with( 'ffc_view_own_certificates', true )->once();

        Functions\when( 'get_userdata' )->justReturn( $mock_user );

        $result = CapabilityManager::set_user_capability( 5, 'ffc_view_own_certificates', true );
        $this->assertTrue( $result );
    }

    public function test_set_user_capability_revokes_valid_cap(): void {
        $mock_user = Mockery::mock( 'WP_User' );
        $mock_user->shouldReceive( 'add_cap' )->with( 'ffc_book_own_appointments', false )->once();

        Functions\when( 'get_userdata' )->justReturn( $mock_user );

        $result = CapabilityManager::set_user_capability( 5, 'ffc_book_own_appointments', false );
        $this->assertTrue( $result );
    }

    public function test_set_user_capability_rejects_unknown_cap(): void {
        $mock_user = Mockery::mock( 'WP_User' );
        Functions\when( 'get_userdata' )->justReturn( $mock_user );

        $result = CapabilityManager::set_user_capability( 5, 'nonexistent_cap', true );
        $this->assertFalse( $result );
    }

    public function test_set_user_capability_returns_false_for_invalid_user(): void {
        Functions\when( 'get_userdata' )->justReturn( false );

        $result = CapabilityManager::set_user_capability( 999, 'ffc_view_own_certificates', true );
        $this->assertFalse( $result );
    }

    // ------------------------------------------------------------------
    // reset_user_ffc_capabilities()
    // ------------------------------------------------------------------

    public function test_reset_user_ffc_capabilities_revokes_all(): void {
        $all_count = count( CapabilityManager::get_all_capabilities() );

        $mock_user = Mockery::mock( 'WP_User' );
        $mock_user->shouldReceive( 'add_cap' )->times( $all_count );

        Functions\when( 'get_userdata' )->justReturn( $mock_user );

        CapabilityManager::reset_user_ffc_capabilities( 5 );
    }

    public function test_reset_user_ffc_capabilities_does_nothing_for_invalid_user(): void {
        Functions\expect( 'get_userdata' )->once()->with( 999 )->andReturn( false );

        CapabilityManager::reset_user_ffc_capabilities( 999 );
    }

    // ------------------------------------------------------------------
    // register_role() / remove_role()
    // ------------------------------------------------------------------

    public function test_register_role_creates_new_role_when_not_exists(): void {
        Functions\when( 'get_role' )->justReturn( null );

        $captured_caps = null;
        Functions\when( 'add_role' )->alias( function( $slug, $name, $caps ) use ( &$captured_caps ) {
            $captured_caps = $caps;
        } );

        RoleRegistrar::register_role();

        $this->assertNotNull( $captured_caps );
        $this->assertTrue( $captured_caps['read'] );
        // FFC caps are intentionally absent (issue #86): an explicit `=> false`
        // here would break multi-role users (admin + ffc_end_user) by overriding
        // admin-granted `true` via array_merge in WP cap resolution.
        $this->assertArrayNotHasKey( 'ffc_view_own_certificates', $captured_caps );
        $this->assertArrayNotHasKey( 'ffc_book_own_appointments', $captured_caps );
        $this->assertArrayNotHasKey( 'ffc_manage_recruitment', $captured_caps );
    }

    public function test_register_role_upgrades_existing_role_strips_legacy_false_caps(): void {
        // Pre-6.0.3 the role was saved with FFC caps as `=> false`; upgrade_role()
        // strips them so multi-role users (admin + ffc_end_user) can resolve to true
        // via the admin role.
        $mock_role               = Mockery::mock( 'WP_Role' );
        $mock_role->capabilities = array(
            'read'                   => true,
            'ffc_view_own_certificates'  => false,
            'ffc_manage_recruitment' => false,
        );

        $removed = array();
        $mock_role->shouldReceive( 'remove_cap' )
            ->atLeast()->once()
            ->andReturnUsing( function ( $cap ) use ( &$removed ) {
                $removed[] = $cap;
            } );

        Functions\when( 'get_role' )->justReturn( $mock_role );

        RoleRegistrar::register_role();

        $this->assertContains( 'ffc_view_own_certificates', $removed );
        $this->assertContains( 'ffc_manage_recruitment', $removed );
    }

    public function test_register_role_upgrade_does_not_touch_user_meta_true_grants(): void {
        // If a previous run left a cap as `=> true` on the role (manual admin
        // edit, etc.), upgrade_role() must NOT remove it — only `=> false`
        // entries get stripped.
        $mock_role               = Mockery::mock( 'WP_Role' );
        $mock_role->capabilities = array(
            'read'                  => true,
            'ffc_view_own_certificates' => true,
        );

        $mock_role->shouldNotReceive( 'remove_cap' );

        Functions\when( 'get_role' )->justReturn( $mock_role );

        RoleRegistrar::register_role();
    }

    public function test_remove_role_calls_wp_remove_role(): void {
        $removed = false;
        Functions\when( 'remove_role' )->alias( function( $role ) use ( &$removed ) {
            $removed = ( $role === 'ffc_end_user' );
        } );

        RoleRegistrar::remove_role();
        $this->assertTrue( $removed );
    }

    public function test_taxonomy_cap_renames_maps_old_slugs_to_standard(): void {
        $map = CapabilityMigrator::taxonomy_cap_renames();

        // The self_scheduling -> own_appointments pair reverses the 4.5.0 rename.
        $this->assertSame( 'ffc_view_own_appointments', $map['ffc_view_self_scheduling'] );
        $this->assertSame( 'ffc_manage_appointments', $map['ffc_manage_self_scheduling'] );
        $this->assertSame( 'ffc_edit_certificates', $map['ffc_certificate_update'] );
        $this->assertSame( 'ffc_manage_custom_fields', $map['ffc_manage_user_custom_fields'] );
        $this->assertSame( 'ffc_import_recruitment', $map['ffc_import_recruitment_csv'] );
        $this->assertSame( 'ffc_view_forms_api', $map['ffc_read_forms_api'] );
        $this->assertCount( 10, $map );
        // No identity entries (a botched rename map would no-op silently).
        foreach ( $map as $old => $new ) {
            $this->assertNotSame( $old, $new, "rename pair must not be identity: {$old}" );
        }
    }

    public function test_migrate_taxonomy_renames_rewrites_user_and_role(): void {
        Functions\when( 'get_users' )->justReturn( array( 1 ) );

        // User holds an old-named cap; expect it rewritten to the new slug.
        $user        = Mockery::mock( 'WP_User' );
        $user->caps  = array( 'ffc_view_self_scheduling' => true );
        $user_added  = array();
        $user_removed = array();
        $user->shouldReceive( 'add_cap' )->andReturnUsing(
            function ( $cap, $val = true ) use ( &$user_added ) {
                $user_added[ $cap ] = $val;
            }
        );
        $user->shouldReceive( 'remove_cap' )->andReturnUsing(
            function ( $cap ) use ( &$user_removed ) {
                $user_removed[] = $cap;
            }
        );
        Functions\when( 'get_userdata' )->justReturn( $user );

        // One role (administrator) holds an old-named cap too.
        $role               = Mockery::mock( 'WP_Role' );
        $role->capabilities = array( 'ffc_manage_self_scheduling' => true );
        $role_added         = array();
        $role_removed       = array();
        $role->shouldReceive( 'add_cap' )->andReturnUsing(
            function ( $cap, $val = true ) use ( &$role_added ) {
                $role_added[ $cap ] = $val;
            }
        );
        $role->shouldReceive( 'remove_cap' )->andReturnUsing(
            function ( $cap ) use ( &$role_removed ) {
                $role_removed[] = $cap;
            }
        );
        $wp_roles        = Mockery::mock();
        $wp_roles->roles = array( 'administrator' => array() );
        Functions\when( 'wp_roles' )->justReturn( $wp_roles );
        Functions\when( 'get_role' )->justReturn( $role );

        CapabilityMigrator::migrate_taxonomy_renames();

        $this->assertArrayHasKey( 'ffc_view_own_appointments', $user_added );
        $this->assertContains( 'ffc_view_self_scheduling', $user_removed );
        $this->assertArrayHasKey( 'ffc_manage_appointments', $role_added );
        $this->assertContains( 'ffc_manage_self_scheduling', $role_removed );
    }

    // ------------------------------------------------------------------
    // GAP E — destructive ffc_delete_* tier
    // ------------------------------------------------------------------

    public function test_admin_capabilities_contains_delete_tier(): void {
        foreach ( array(
            'ffc_delete_certificates',
            'ffc_delete_appointments',
            'ffc_delete_audiences',
            'ffc_delete_reregistration',
            'ffc_delete_custom_fields',
            'ffc_delete_recruitment',
            'ffc_delete_url_shortener',
        ) as $cap ) {
            $this->assertContains( $cap, CapabilityManager::ADMIN_CAPABILITIES, "ADMIN_CAPABILITIES must contain {$cap}" );
        }
    }

    public function test_delete_cap_grant_map_pairs_each_manage_to_its_delete(): void {
        $map = CapabilityMigrator::delete_cap_grant_map();
        $this->assertCount( 7, $map );
        $this->assertSame( 'ffc_delete_certificates', $map['ffc_manage_certificates'] );
        $this->assertSame( 'ffc_delete_recruitment', $map['ffc_manage_recruitment'] );
        $this->assertSame( 'ffc_delete_url_shortener', $map['ffc_manage_url_shortener'] );
        // Every key is a real manage cap and every value a real delete cap.
        foreach ( $map as $manage => $delete ) {
            $this->assertStringStartsWith( 'ffc_manage_', $manage );
            $this->assertContains( $delete, CapabilityManager::ADMIN_CAPABILITIES, "{$delete} must be a registered cap" );
        }
    }

    public function test_module_roles_grant_matching_delete_caps_but_operator_excluded(): void {
        $created = array();
        Functions\when( 'get_role' )->justReturn( null );
        Functions\when( 'add_role' )->alias(
            static function ( string $slug, string $label, array $caps ) use ( &$created ): bool {
                $created[ $slug ] = $caps;
                return true;
            }
        );

        RoleRegistrar::register_module_roles();

        // Each manager role carries the delete cap of the domain it manages.
        $this->assertTrue( $created['ffc_certificate_manager']['ffc_delete_certificates'] );
        $this->assertTrue( $created['ffc_appointments_manager']['ffc_delete_appointments'] );
        $this->assertTrue( $created['ffc_audience_manager']['ffc_delete_audiences'] );
        $this->assertTrue( $created['ffc_reregistration_manager']['ffc_delete_reregistration'] );
        $this->assertTrue( $created['ffc_recruitment_admin']['ffc_delete_recruitment'] );

        // The read-only operator never receives a delete cap.
        foreach ( CapabilityMigrator::delete_cap_grant_map() as $delete_cap ) {
            $this->assertArrayNotHasKey( $delete_cap, $created['ffc_readonly'], "ffc_readonly must NOT grant {$delete_cap}" );
        }
    }

    public function test_migrate_delete_caps_grant_seeds_delete_onto_manage_holders(): void {
        Functions\when( 'get_users' )->justReturn( array( 1 ) );

        // User holds a manage cap but not its delete cap → expect delete seeded.
        $user        = Mockery::mock( 'WP_User' );
        $user->caps  = array( 'ffc_manage_certificates' => true );
        $user_added  = array();
        $user->shouldReceive( 'add_cap' )->andReturnUsing(
            function ( $cap, $val = true ) use ( &$user_added ) {
                $user_added[ $cap ] = $val;
            }
        );
        $user->shouldReceive( 'remove_cap' )->never();
        Functions\when( 'get_userdata' )->justReturn( $user );

        // Role holds the recruitment manage cap → expect ffc_delete_recruitment.
        $role               = Mockery::mock( 'WP_Role' );
        $role->capabilities = array( 'ffc_manage_recruitment' => true );
        $role_added         = array();
        $role->shouldReceive( 'add_cap' )->andReturnUsing(
            function ( $cap, $val = true ) use ( &$role_added ) {
                $role_added[ $cap ] = $val;
            }
        );
        $wp_roles        = Mockery::mock();
        $wp_roles->roles = array( 'administrator' => array() );
        Functions\when( 'wp_roles' )->justReturn( $wp_roles );
        Functions\when( 'get_role' )->justReturn( $role );

        CapabilityMigrator::migrate_delete_caps_grant();

        // Seeded the matching delete cap, never touched the manage cap.
        $this->assertTrue( $user_added['ffc_delete_certificates'] ?? null );
        $this->assertArrayNotHasKey( 'ffc_delete_recruitment', $user_added );
        $this->assertTrue( $role_added['ffc_delete_recruitment'] ?? null );
        $this->assertArrayNotHasKey( 'ffc_delete_certificates', $role_added );
    }

    public function test_admin_capabilities_contains_settings_sub_caps(): void {
        foreach ( array(
            'ffc_manage_settings_smtp',
            'ffc_manage_settings_dangerzone',
        ) as $cap ) {
            $this->assertContains( $cap, CapabilityManager::ADMIN_CAPABILITIES, "ADMIN_CAPABILITIES must contain {$cap}" );
        }
    }

    public function test_settings_split_cap_grant_map_pairs_manage_to_sub_caps(): void {
        $map = CapabilityMigrator::settings_split_cap_grant_map();
        $this->assertArrayHasKey( 'ffc_manage_settings', $map );
        $this->assertSame(
            array( 'ffc_manage_settings_smtp', 'ffc_manage_settings_dangerzone' ),
            $map['ffc_manage_settings']
        );
        foreach ( $map['ffc_manage_settings'] as $sub ) {
            $this->assertContains( $sub, CapabilityManager::ADMIN_CAPABILITIES, "{$sub} must be a registered cap" );
        }
    }

    public function test_migrate_settings_split_caps_grant_seeds_sub_caps_onto_manage_holders(): void {
        Functions\when( 'get_users' )->justReturn( array( 1 ) );

        // User holds ffc_manage_settings but neither sub-cap → expect both seeded.
        $user       = Mockery::mock( 'WP_User' );
        $user->caps = array( 'ffc_manage_settings' => true );
        $user_added = array();
        $user->shouldReceive( 'add_cap' )->andReturnUsing(
            function ( $cap, $val = true ) use ( &$user_added ) {
                $user_added[ $cap ] = $val;
            }
        );
        $user->shouldReceive( 'remove_cap' )->never();
        Functions\when( 'get_userdata' )->justReturn( $user );

        // Role also holds the manage cap → expect both sub-caps on the role too.
        $role               = Mockery::mock( 'WP_Role' );
        $role->capabilities = array( 'ffc_manage_settings' => true );
        $role_added         = array();
        $role->shouldReceive( 'add_cap' )->andReturnUsing(
            function ( $cap, $val = true ) use ( &$role_added ) {
                $role_added[ $cap ] = $val;
            }
        );
        $wp_roles        = Mockery::mock();
        $wp_roles->roles = array( 'administrator' => array() );
        Functions\when( 'wp_roles' )->justReturn( $wp_roles );
        Functions\when( 'get_role' )->justReturn( $role );

        CapabilityMigrator::migrate_settings_split_caps_grant();

        $this->assertTrue( $user_added['ffc_manage_settings_smtp'] ?? null );
        $this->assertTrue( $user_added['ffc_manage_settings_dangerzone'] ?? null );
        $this->assertTrue( $role_added['ffc_manage_settings_smtp'] ?? null );
        $this->assertTrue( $role_added['ffc_manage_settings_dangerzone'] ?? null );
    }

    public function test_admin_capabilities_contains_export_tier(): void {
        foreach ( array(
            'ffc_export_appointments',
            'ffc_export_reregistration',
            'ffc_export_audiences',
        ) as $cap ) {
            $this->assertContains( $cap, CapabilityManager::ADMIN_CAPABILITIES, "ADMIN_CAPABILITIES must contain {$cap}" );
        }
        // Certificates keeps its long-standing standalone export cap.
        $this->assertContains( 'ffc_export_certificates', CapabilityManager::ADMIN_CAPABILITIES );
    }

    public function test_admin_capabilities_contains_activity_log_export(): void {
        $this->assertContains( 'ffc_export_activity_log', CapabilityManager::ADMIN_CAPABILITIES );
    }

    public function test_activity_log_export_cap_grant_map_pairs_view_to_export(): void {
        $map = CapabilityMigrator::activity_log_export_cap_grant_map();
        $this->assertSame( array( 'ffc_view_activity_log' => 'ffc_export_activity_log' ), $map );
        foreach ( $map as $view => $export ) {
            $this->assertContains( $view, CapabilityManager::ADMIN_CAPABILITIES );
            $this->assertContains( $export, CapabilityManager::ADMIN_CAPABILITIES );
        }
    }

    public function test_migrate_activity_log_export_cap_grant_seeds_export_onto_view_holders(): void {
        Functions\when( 'get_users' )->justReturn( array( 1 ) );

        // User holds the view cap but not the export cap → expect export seeded.
        $user       = Mockery::mock( 'WP_User' );
        $user->caps = array( 'ffc_view_activity_log' => true );
        $user_added = array();
        $user->shouldReceive( 'add_cap' )->andReturnUsing(
            function ( $cap, $val = true ) use ( &$user_added ) {
                $user_added[ $cap ] = $val;
            }
        );
        $user->shouldReceive( 'remove_cap' )->never();
        Functions\when( 'get_userdata' )->justReturn( $user );

        // Role also holds the view cap → expect the export cap on the role too.
        $role               = Mockery::mock( 'WP_Role' );
        $role->capabilities = array( 'ffc_view_activity_log' => true );
        $role_added         = array();
        $role->shouldReceive( 'add_cap' )->andReturnUsing(
            function ( $cap, $val = true ) use ( &$role_added ) {
                $role_added[ $cap ] = $val;
            }
        );
        $wp_roles        = Mockery::mock();
        $wp_roles->roles = array( 'administrator' => array() );
        Functions\when( 'wp_roles' )->justReturn( $wp_roles );
        Functions\when( 'get_role' )->justReturn( $role );

        CapabilityMigrator::migrate_activity_log_export_cap_grant();

        $this->assertTrue( $user_added['ffc_export_activity_log'] ?? null );
        $this->assertTrue( $role_added['ffc_export_activity_log'] ?? null );
    }

    public function test_export_cap_grant_map_pairs_each_manage_to_its_export(): void {
        $map = CapabilityMigrator::export_cap_grant_map();
        $this->assertCount( 3, $map );
        $this->assertSame( 'ffc_export_appointments', $map['ffc_manage_appointments'] );
        $this->assertSame( 'ffc_export_reregistration', $map['ffc_manage_reregistration'] );
        $this->assertSame( 'ffc_export_audiences', $map['ffc_manage_audiences'] );
        // Certificates is intentionally absent — ffc_export_certificates is a
        // standalone cap never seeded from ffc_manage_certificates.
        $this->assertArrayNotHasKey( 'ffc_manage_certificates', $map );
        // Every key is a real manage cap and every value a registered export cap.
        foreach ( $map as $manage => $export ) {
            $this->assertStringStartsWith( 'ffc_manage_', $manage );
            $this->assertStringStartsWith( 'ffc_export_', $export );
            $this->assertContains( $export, CapabilityManager::ADMIN_CAPABILITIES, "{$export} must be a registered cap" );
        }
    }

    public function test_module_roles_grant_matching_export_caps_but_operator_excluded(): void {
        $created = array();
        Functions\when( 'get_role' )->justReturn( null );
        Functions\when( 'add_role' )->alias(
            static function ( string $slug, string $label, array $caps ) use ( &$created ): bool {
                $created[ $slug ] = $caps;
                return true;
            }
        );

        RoleRegistrar::register_module_roles();

        // Each manager role carries the export cap of the domain it manages.
        $this->assertTrue( $created['ffc_appointments_manager']['ffc_export_appointments'] );
        $this->assertTrue( $created['ffc_audience_manager']['ffc_export_audiences'] );
        $this->assertTrue( $created['ffc_reregistration_manager']['ffc_export_reregistration'] );
        // Certificate manager keeps the standalone certificate export cap.
        $this->assertTrue( $created['ffc_certificate_manager']['ffc_export_certificates'] );

        // The Self-Scheduling Manager manages appointments only — it must NOT
        // carry the cross-domain certificate export cap (audit cleanup): that
        // belongs to the Certificate Manager / Administrator.
        $this->assertArrayNotHasKey( 'ffc_export_certificates', $created['ffc_appointments_manager'] );

        // The read-only operator never receives a granular export cap.
        foreach ( CapabilityMigrator::export_cap_grant_map() as $export_cap ) {
            $this->assertArrayNotHasKey( $export_cap, $created['ffc_readonly'], "ffc_readonly must NOT grant {$export_cap}" );
        }
        $this->assertArrayNotHasKey( 'ffc_export_certificates', $created['ffc_readonly'] );
    }

    public function test_migrate_export_caps_grant_seeds_export_onto_manage_holders(): void {
        Functions\when( 'get_users' )->justReturn( array( 1 ) );

        // User holds a manage cap but not its export cap → expect export seeded.
        $user       = Mockery::mock( 'WP_User' );
        $user->caps = array( 'ffc_manage_appointments' => true );
        $user_added = array();
        $user->shouldReceive( 'add_cap' )->andReturnUsing(
            function ( $cap, $val = true ) use ( &$user_added ) {
                $user_added[ $cap ] = $val;
            }
        );
        $user->shouldReceive( 'remove_cap' )->never();
        Functions\when( 'get_userdata' )->justReturn( $user );

        // Role holds the audiences manage cap → expect ffc_export_audiences.
        $role               = Mockery::mock( 'WP_Role' );
        $role->capabilities = array( 'ffc_manage_audiences' => true );
        $role_added         = array();
        $role->shouldReceive( 'add_cap' )->andReturnUsing(
            function ( $cap, $val = true ) use ( &$role_added ) {
                $role_added[ $cap ] = $val;
            }
        );
        $wp_roles        = Mockery::mock();
        $wp_roles->roles = array( 'administrator' => array() );
        Functions\when( 'wp_roles' )->justReturn( $wp_roles );
        Functions\when( 'get_role' )->justReturn( $role );

        CapabilityMigrator::migrate_export_caps_grant();

        // Seeded the matching export cap, never touched the manage cap.
        $this->assertTrue( $user_added['ffc_export_appointments'] ?? null );
        $this->assertArrayNotHasKey( 'ffc_export_audiences', $user_added );
        $this->assertTrue( $role_added['ffc_export_audiences'] ?? null );
        $this->assertArrayNotHasKey( 'ffc_export_appointments', $role_added );
    }

    public function test_admin_capabilities_contains_import_tier(): void {
        // ffc_import_audiences is new (GAP H); ffc_import_recruitment predates
        // it but is now strictly enforced.
        $this->assertContains( 'ffc_import_audiences', CapabilityManager::ADMIN_CAPABILITIES );
        $this->assertContains( 'ffc_import_recruitment', CapabilityManager::ADMIN_CAPABILITIES );
    }

    public function test_import_cap_grant_map_pairs_each_manage_to_its_import(): void {
        $map = CapabilityMigrator::import_cap_grant_map();
        $this->assertCount( 2, $map );
        $this->assertSame( 'ffc_import_audiences', $map['ffc_manage_audiences'] );
        $this->assertSame( 'ffc_import_recruitment', $map['ffc_manage_recruitment'] );
        foreach ( $map as $manage => $import ) {
            $this->assertStringStartsWith( 'ffc_manage_', $manage );
            $this->assertStringStartsWith( 'ffc_import_', $import );
            $this->assertContains( $import, CapabilityManager::ADMIN_CAPABILITIES, "{$import} must be a registered cap" );
        }
    }

    public function test_module_roles_grant_matching_import_caps_but_operator_excluded(): void {
        $created = array();
        Functions\when( 'get_role' )->justReturn( null );
        Functions\when( 'add_role' )->alias(
            static function ( string $slug, string $label, array $caps ) use ( &$created ): bool {
                $created[ $slug ] = $caps;
                return true;
            }
        );

        RoleRegistrar::register_module_roles();

        // Audience manager carries the new audiences import cap; the recruitment
        // admin already carries the recruitment import cap.
        $this->assertTrue( $created['ffc_audience_manager']['ffc_import_audiences'] );
        $this->assertTrue( $created['ffc_recruitment_admin']['ffc_import_recruitment'] );

        // The read-only operator never receives a granular import cap.
        foreach ( CapabilityMigrator::import_cap_grant_map() as $import_cap ) {
            $this->assertArrayNotHasKey( $import_cap, $created['ffc_readonly'], "ffc_readonly must NOT grant {$import_cap}" );
        }
    }

    public function test_migrate_import_caps_grant_seeds_import_onto_manage_holders(): void {
        Functions\when( 'get_users' )->justReturn( array( 1 ) );

        // User holds the audiences manage cap → expect ffc_import_audiences.
        $user       = Mockery::mock( 'WP_User' );
        $user->caps = array( 'ffc_manage_audiences' => true );
        $user_added = array();
        $user->shouldReceive( 'add_cap' )->andReturnUsing(
            function ( $cap, $val = true ) use ( &$user_added ) {
                $user_added[ $cap ] = $val;
            }
        );
        $user->shouldReceive( 'remove_cap' )->never();
        Functions\when( 'get_userdata' )->justReturn( $user );

        // Role holds the recruitment manage cap → expect ffc_import_recruitment
        // seeded (preserves umbrella-reliant custom roles after the tightening).
        $role               = Mockery::mock( 'WP_Role' );
        $role->capabilities = array( 'ffc_manage_recruitment' => true );
        $role_added         = array();
        $role->shouldReceive( 'add_cap' )->andReturnUsing(
            function ( $cap, $val = true ) use ( &$role_added ) {
                $role_added[ $cap ] = $val;
            }
        );
        $wp_roles        = Mockery::mock();
        $wp_roles->roles = array( 'administrator' => array() );
        Functions\when( 'wp_roles' )->justReturn( $wp_roles );
        Functions\when( 'get_role' )->justReturn( $role );

        CapabilityMigrator::migrate_import_caps_grant();

        // Seeded the matching import cap, never touched the manage cap.
        $this->assertTrue( $user_added['ffc_import_audiences'] ?? null );
        $this->assertArrayNotHasKey( 'ffc_import_recruitment', $user_added );
        $this->assertTrue( $role_added['ffc_import_recruitment'] ?? null );
        $this->assertArrayNotHasKey( 'ffc_import_audiences', $role_added );
    }

    // ------------------------------------------------------------------
    // GAP I — recruitment reasons 3-state tier
    // ------------------------------------------------------------------

    public function test_reasons_cap_grant_map_pairs_view_and_manage_sources(): void {
        $map = CapabilityMigrator::reasons_cap_grant_map();
        $this->assertCount( 2, $map );
        // Read tier seeded from page-view; edit tier from the umbrella.
        $this->assertSame( 'ffc_view_recruitment_reasons', $map['ffc_view_recruitment'] );
        $this->assertSame( 'ffc_manage_recruitment_reasons', $map['ffc_manage_recruitment'] );
        foreach ( $map as $reasons_cap ) {
            $this->assertContains( $reasons_cap, CapabilityManager::ADMIN_CAPABILITIES, "{$reasons_cap} must be a registered cap" );
        }
    }

    public function test_module_roles_grant_reasons_view_cap_to_recruitment_viewers(): void {
        $created = array();
        Functions\when( 'get_role' )->justReturn( null );
        Functions\when( 'add_role' )->alias(
            static function ( string $slug, string $label, array $caps ) use ( &$created ): bool {
                $created[ $slug ] = $caps;
                return true;
            }
        );

        RoleRegistrar::register_module_roles();

        // Read-only recruitment roles see the Reasons tab → carry the view cap.
        $this->assertTrue( $created['ffc_recruitment_auditor']['ffc_view_recruitment_reasons'] );
        $this->assertTrue( $created['ffc_recruitment_operator']['ffc_view_recruitment_reasons'] );
        // The auditor is read-only — it must NOT carry the manage tier.
        $this->assertArrayNotHasKey( 'ffc_manage_recruitment_reasons', $created['ffc_recruitment_auditor'] );
        // The Recruitment Admin carries both reasons caps.
        $this->assertTrue( $created['ffc_recruitment_admin']['ffc_view_recruitment_reasons'] );
        $this->assertTrue( $created['ffc_recruitment_admin']['ffc_manage_recruitment_reasons'] );
    }

    public function test_migrate_reasons_caps_grant_seeds_pair_onto_source_holders(): void {
        Functions\when( 'get_users' )->justReturn( array( 1 ) );

        // User holds the page-view cap → expect the reasons view cap seeded.
        $user       = Mockery::mock( 'WP_User' );
        $user->caps = array( 'ffc_view_recruitment' => true );
        $user_added = array();
        $user->shouldReceive( 'add_cap' )->andReturnUsing(
            function ( $cap, $val = true ) use ( &$user_added ) {
                $user_added[ $cap ] = $val;
            }
        );
        $user->shouldReceive( 'remove_cap' )->never();
        Functions\when( 'get_userdata' )->justReturn( $user );

        // Role holds the umbrella → expect the reasons manage cap seeded.
        $role               = Mockery::mock( 'WP_Role' );
        $role->capabilities = array( 'ffc_manage_recruitment' => true );
        $role_added         = array();
        $role->shouldReceive( 'add_cap' )->andReturnUsing(
            function ( $cap, $val = true ) use ( &$role_added ) {
                $role_added[ $cap ] = $val;
            }
        );
        $wp_roles        = Mockery::mock();
        $wp_roles->roles = array( 'administrator' => array() );
        Functions\when( 'wp_roles' )->justReturn( $wp_roles );
        Functions\when( 'get_role' )->justReturn( $role );

        CapabilityMigrator::migrate_reasons_caps_grant();

        // Seeded the matching reasons cap, never touched the source cap.
        $this->assertTrue( $user_added['ffc_view_recruitment_reasons'] ?? null );
        $this->assertArrayNotHasKey( 'ffc_manage_recruitment_reasons', $user_added );
        $this->assertTrue( $role_added['ffc_manage_recruitment_reasons'] ?? null );
        $this->assertArrayNotHasKey( 'ffc_view_recruitment_reasons', $role_added );
    }
}
