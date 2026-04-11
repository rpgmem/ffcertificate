<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\API\UserCertificatesRestController;

/**
 * Tests for UserCertificatesRestController: route registration, permission checks,
 * and get_user_certificates success/error paths.
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class UserCertificatesRestControllerTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var array Captured route registrations */
    private array $registered_routes = [];

    /** @var object Mock $wpdb */
    private $wpdb;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        $this->registered_routes = [];

        Functions\when( 'register_rest_route' )->alias( function( $namespace, $route, $args ) {
            $this->registered_routes[] = array(
                'namespace' => $namespace,
                'route'     => $route,
                'args'      => $args,
            );
        });

        Functions\when( '__' )->returnArg();
        Functions\when( 'absint' )->alias( function( $val ) { return abs( intval( $val ) ); } );
        Functions\when( 'rest_ensure_response' )->alias( function( $data ) { return $data; } );
        Functions\when( 'is_wp_error' )->alias( function( $thing ) { return $thing instanceof \WP_Error; } );
        Functions\when( 'get_current_user_id' )->justReturn( 0 );
        Functions\when( 'current_user_can' )->justReturn( false );
        Functions\when( 'user_can' )->justReturn( false );
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\when( 'date_i18n' )->alias( function( $format, $timestamp = false ) {
            return date( $format, $timestamp ?: time() );
        });

        // Alias mocks for static-only dependencies
        $utils_mock = Mockery::mock( 'alias:\FreeFormCertificate\Core\Utils' );
        $utils_mock->shouldReceive( 'get_submissions_table' )->andReturn( 'wp_ffc_submissions' )->byDefault();
        $utils_mock->shouldReceive( 'mask_email' )->andReturnUsing( function( $email ) {
            return 'j***@example.com';
        })->byDefault();
        $utils_mock->shouldReceive( 'format_auth_code' )->andReturnUsing( function( $code, $prefix = '' ) {
            return $prefix ? $prefix . '-' . $code : $code;
        })->byDefault();
        $utils_mock->shouldReceive( 'debug_log' )->byDefault();

        $encryption_mock = Mockery::mock( 'alias:\FreeFormCertificate\Core\Encryption' );
        $encryption_mock->shouldReceive( 'decrypt_field' )->andReturn( 'john@example.com' )->byDefault();

        $magic_link_mock = Mockery::mock( 'alias:\FreeFormCertificate\Generators\MagicLinkHelper' );
        $magic_link_mock->shouldReceive( 'generate_magic_link' )->andReturn( 'https://example.com/magic/abc123' )->byDefault();

        // Global $wpdb mock
        $this->wpdb = Mockery::mock( 'wpdb' );
        $this->wpdb->prefix = 'wp_';
        $this->wpdb->posts = 'wp_posts';
        $this->wpdb->shouldReceive( 'prepare' )->andReturn( '' )->byDefault();
        $this->wpdb->shouldReceive( 'get_results' )->andReturn( array() )->byDefault();
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        unset( $GLOBALS['wpdb'] );
        parent::tearDown();
    }

    /**
     * Helper: create a mock WP_REST_Request with given params.
     */
    private function make_request( array $params = [] ): object {
        $request = Mockery::mock( 'WP_REST_Request' );
        $request->shouldReceive( 'get_param' )->andReturnUsing( function( $key ) use ( $params ) {
            return $params[ $key ] ?? null;
        });
        return $request;
    }

    // ------------------------------------------------------------------
    // Route registration
    // ------------------------------------------------------------------

    public function test_register_routes_creates_one_endpoint(): void {
        $ctrl = new UserCertificatesRestController( 'ffc/v1' );
        $ctrl->register_routes();

        $this->assertCount( 1, $this->registered_routes );
    }

    public function test_certificates_route_path(): void {
        $ctrl = new UserCertificatesRestController( 'ffc/v1' );
        $ctrl->register_routes();

        $this->assertSame( '/user/certificates', $this->registered_routes[0]['route'] );
    }

    public function test_certificates_route_requires_authentication(): void {
        $ctrl = new UserCertificatesRestController( 'ffc/v1' );
        $ctrl->register_routes();

        $this->assertSame(
            'is_user_logged_in',
            $this->registered_routes[0]['args']['permission_callback']
        );
    }

    public function test_certificates_route_uses_correct_namespace(): void {
        $ctrl = new UserCertificatesRestController( 'ffc/v1' );
        $ctrl->register_routes();

        $this->assertSame( 'ffc/v1', $this->registered_routes[0]['namespace'] );
    }

    // ------------------------------------------------------------------
    // get_user_certificates — error paths
    // ------------------------------------------------------------------

    public function test_get_user_certificates_returns_error_when_not_logged_in(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 0 );
        Functions\when( 'current_user_can' )->justReturn( false );

        $ctrl    = new UserCertificatesRestController( 'ffc/v1' );
        $request = $this->make_request();
        $result  = $ctrl->get_user_certificates( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'not_logged_in', $result->get_error_code() );
    }

    public function test_get_user_certificates_returns_error_when_capability_denied(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 5 );
        Functions\when( 'current_user_can' )->justReturn( false );
        Functions\when( 'user_can' )->justReturn( false );

        $ctrl    = new UserCertificatesRestController( 'ffc/v1' );
        $request = $this->make_request();
        $result  = $ctrl->get_user_certificates( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'capability_denied', $result->get_error_code() );
    }

    // ------------------------------------------------------------------
    // get_user_certificates — success paths
    // ------------------------------------------------------------------

    public function test_get_user_certificates_returns_empty_when_no_submissions(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 5 );
        Functions\when( 'current_user_can' )->justReturn( true );

        $this->wpdb->shouldReceive( 'get_results' )->andReturn( array() );

        $ctrl    = new UserCertificatesRestController( 'ffc/v1' );
        $request = $this->make_request();
        $result  = $ctrl->get_user_certificates( $request );

        $this->assertIsArray( $result );
        $this->assertSame( 0, $result['total'] );
        $this->assertEmpty( $result['certificates'] );
    }

    public function test_get_user_certificates_returns_formatted_certificate_data(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 5 );
        Functions\when( 'current_user_can' )->justReturn( true );

        $submissions = array(
            array(
                'id'              => '10',
                'form_id'         => '3',
                'form_title'      => 'Test Form',
                'submission_date' => '2025-06-15 10:30:00',
                'consent_given'   => '1',
                'email'           => 'encrypted_email',
                'auth_code'       => 'ABCDEFGHIJKL',
                'magic_token'     => 'token123',
            ),
        );

        $this->wpdb->shouldReceive( 'get_results' )->andReturn( $submissions );

        $ctrl    = new UserCertificatesRestController( 'ffc/v1' );
        $request = $this->make_request();
        $result  = $ctrl->get_user_certificates( $request );

        $this->assertIsArray( $result );
        $this->assertSame( 1, $result['total'] );
        $this->assertCount( 1, $result['certificates'] );

        $cert = $result['certificates'][0];
        $this->assertSame( 10, $cert['id'] );
        $this->assertSame( 3, $cert['form_id'] );
        $this->assertSame( 'Test Form', $cert['form_title'] );
        $this->assertTrue( $cert['consent_given'] );
        $this->assertSame( 'j***@example.com', $cert['email'] );
        $this->assertNotEmpty( $cert['auth_code'] );
        $this->assertNotEmpty( $cert['magic_link'] );
        $this->assertNotEmpty( $cert['pdf_url'] );
    }

    public function test_get_user_certificates_hides_magic_link_when_download_denied(): void {
        // current_user_can returns true for 'view_own_certificates' but false for 'download_own_certificates'
        Functions\when( 'get_current_user_id' )->justReturn( 5 );
        Functions\when( 'current_user_can' )->alias( function( $cap ) {
            if ( $cap === 'manage_options' ) {
                return false;
            }
            if ( $cap === 'view_own_certificates' ) {
                return true;
            }
            // deny download_own_certificates, view_certificate_history
            return false;
        });

        $submissions = array(
            array(
                'id'              => '10',
                'form_id'         => '3',
                'form_title'      => 'Test Form',
                'submission_date' => '2025-06-15 10:30:00',
                'consent_given'   => '1',
                'email'           => 'encrypted_email',
                'auth_code'       => 'ABCDEFGHIJKL',
                'magic_token'     => 'token123',
            ),
        );

        $this->wpdb->shouldReceive( 'get_results' )->andReturn( $submissions );

        $ctrl    = new UserCertificatesRestController( 'ffc/v1' );
        $request = $this->make_request();
        $result  = $ctrl->get_user_certificates( $request );

        $this->assertIsArray( $result );
        $cert = $result['certificates'][0];
        $this->assertSame( '', $cert['magic_link'] );
        $this->assertSame( '', $cert['pdf_url'] );
    }

    public function test_get_user_certificates_filters_to_most_recent_per_form_when_history_denied(): void {
        // Allow view_own_certificates and download, deny view_certificate_history
        Functions\when( 'get_current_user_id' )->justReturn( 5 );
        Functions\when( 'current_user_can' )->alias( function( $cap ) {
            if ( $cap === 'manage_options' ) {
                return false;
            }
            if ( $cap === 'view_own_certificates' || $cap === 'download_own_certificates' ) {
                return true;
            }
            // deny view_certificate_history
            return false;
        });

        $submissions = array(
            array(
                'id'              => '20',
                'form_id'         => '3',
                'form_title'      => 'Form A',
                'submission_date' => '2025-07-01 10:00:00',
                'consent_given'   => '1',
                'email'           => 'enc',
                'auth_code'       => 'AAAAAAAAAAAA',
                'magic_token'     => 't1',
            ),
            array(
                'id'              => '15',
                'form_id'         => '3',
                'form_title'      => 'Form A',
                'submission_date' => '2025-06-01 10:00:00',
                'consent_given'   => '1',
                'email'           => 'enc',
                'auth_code'       => 'BBBBBBBBBBBB',
                'magic_token'     => 't2',
            ),
            array(
                'id'              => '25',
                'form_id'         => '5',
                'form_title'      => 'Form B',
                'submission_date' => '2025-08-01 10:00:00',
                'consent_given'   => '0',
                'email'           => 'enc',
                'auth_code'       => 'CCCCCCCCCCCC',
                'magic_token'     => 't3',
            ),
        );

        $this->wpdb->shouldReceive( 'get_results' )->andReturn( $submissions );

        $ctrl    = new UserCertificatesRestController( 'ffc/v1' );
        $request = $this->make_request();
        $result  = $ctrl->get_user_certificates( $request );

        $this->assertIsArray( $result );
        // Should keep only the first certificate per form_id (most recent because sorted DESC)
        $this->assertSame( 2, $result['total'] );
        $this->assertSame( 20, $result['certificates'][0]['id'] ); // Form A (first occurrence)
        $this->assertSame( 25, $result['certificates'][1]['id'] ); // Form B
    }

    public function test_get_user_certificates_returns_all_when_history_allowed(): void {
        // Allow all capabilities
        Functions\when( 'get_current_user_id' )->justReturn( 5 );
        Functions\when( 'current_user_can' )->justReturn( true );

        $submissions = array(
            array(
                'id'              => '20',
                'form_id'         => '3',
                'form_title'      => 'Form A',
                'submission_date' => '2025-07-01 10:00:00',
                'consent_given'   => '1',
                'email'           => 'enc',
                'auth_code'       => 'AAAAAAAAAAAA',
                'magic_token'     => 't1',
            ),
            array(
                'id'              => '15',
                'form_id'         => '3',
                'form_title'      => 'Form A',
                'submission_date' => '2025-06-01 10:00:00',
                'consent_given'   => '1',
                'email'           => 'enc',
                'auth_code'       => 'BBBBBBBBBBBB',
                'magic_token'     => 't2',
            ),
        );

        $this->wpdb->shouldReceive( 'get_results' )->andReturn( $submissions );

        $ctrl    = new UserCertificatesRestController( 'ffc/v1' );
        $request = $this->make_request();
        $result  = $ctrl->get_user_certificates( $request );

        $this->assertIsArray( $result );
        $this->assertSame( 2, $result['total'] );
    }
}
