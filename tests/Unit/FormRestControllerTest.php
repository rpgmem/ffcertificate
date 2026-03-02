<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\API\FormRestController;
use FreeFormCertificate\Repositories\FormRepository;

/**
 * Tests for FormRestController: route registration, get_forms,
 * get_form, and submit_form endpoints.
 */
class FormRestControllerTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var array Captured route registrations */
    private array $registered_routes = [];

    /** @var \Mockery\MockInterface&FormRepository Mock for FormRepository (injected) */
    private $form_repo_mock;

    /** @var \Mockery\MockInterface Mock for SubmissionHandler (overload) */
    private $submission_handler_mock;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        $this->registered_routes = [];

        // Capture register_rest_route calls
        Functions\when( 'register_rest_route' )->alias( function( $namespace, $route, $args ) {
            $this->registered_routes[] = array(
                'namespace' => $namespace,
                'route'     => $route,
                'args'      => $args,
            );
        });

        Functions\when( '__' )->returnArg();
        Functions\when( 'rest_ensure_response' )->alias( function( $data ) { return $data; } );
        Functions\when( 'is_wp_error' )->alias( function( $thing ) { return $thing instanceof \WP_Error; } );
        Functions\when( 'is_email' )->alias( function( $email ) { return (bool) filter_var( $email, FILTER_VALIDATE_EMAIL ); } );
        Functions\when( 'get_post' )->justReturn( null );
        Functions\when( 'get_permalink' )->justReturn( 'https://example.com/form/1' );
        Functions\when( 'sanitize_email' )->returnArg();
        Functions\when( 'home_url' )->alias( function( $path = '' ) { return 'https://example.com' . $path; } );

        // Injected FormRepository mock
        $this->form_repo_mock = Mockery::mock( FormRepository::class );
        $this->form_repo_mock->shouldReceive( 'findPublished' )->andReturn( [] )->byDefault();
        $this->form_repo_mock->shouldReceive( 'getConfig' )->andReturn( array() )->byDefault();
        $this->form_repo_mock->shouldReceive( 'getFields' )->andReturn( array() )->byDefault();
        $this->form_repo_mock->shouldReceive( 'getBackground' )->andReturn( '' )->byDefault();

        // Overload mock for SubmissionHandler (instantiated with new)
        $this->submission_handler_mock = Mockery::mock( 'overload:\FreeFormCertificate\Submissions\SubmissionHandler' );
        $this->submission_handler_mock->shouldReceive( 'process_submission' )->andReturn( 1 )->byDefault();

        // Alias mocks for static-only classes
        $utils_mock = Mockery::mock( 'alias:\FreeFormCertificate\Core\Utils' );
        $utils_mock->shouldReceive( 'recursive_sanitize' )->andReturnUsing( function( $data ) { return $data; } )->byDefault();
        $utils_mock->shouldReceive( 'validate_cpf' )->andReturn( true )->byDefault();
        $utils_mock->shouldReceive( 'validate_rf' )->andReturn( true )->byDefault();
        $utils_mock->shouldReceive( 'get_user_ip' )->andReturn( '127.0.0.1' )->byDefault();
        $utils_mock->shouldReceive( 'format_auth_code' )->andReturnUsing( function( $code, $prefix = '' ) {
            return $prefix ? $prefix . '-' . $code : $code;
        })->byDefault();
        $utils_mock->shouldReceive( 'debug_log' )->byDefault();

        $geofence_mock = Mockery::mock( 'alias:\FreeFormCertificate\Security\Geofence' );
        $geofence_mock->shouldReceive( 'get_form_config' )->andReturn( null )->byDefault();
        $geofence_mock->shouldReceive( 'can_access_form' )->andReturn( array( 'allowed' => true ) )->byDefault();

        $rate_limiter_mock = Mockery::mock( 'alias:\FreeFormCertificate\Security\RateLimiter' );
        $rate_limiter_mock->shouldReceive( 'check_ip_limit' )->andReturn( array( 'allowed' => true ) )->byDefault();
        $rate_limiter_mock->shouldReceive( 'check_email_limit' )->andReturn( array( 'allowed' => true ) )->byDefault();
        $rate_limiter_mock->shouldReceive( 'check_cpf_limit' )->andReturn( array( 'allowed' => true ) )->byDefault();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Helper: create a mock WP_REST_Request with given params.
     */
    private function make_request( array $params = [], array $json_params = [] ): object {
        $req = Mockery::mock( 'WP_REST_Request' );
        $req->shouldReceive( 'get_param' )->andReturnUsing( function( $key ) use ( $params ) {
            return $params[ $key ] ?? null;
        });
        $req->shouldReceive( 'get_json_params' )->andReturn( $json_params );
        return $req;
    }

    /**
     * Helper: create a mock WP_Post object.
     */
    private function make_post( int $id, string $type = 'ffc_form', string $status = 'publish' ): object {
        $post = new \stdClass();
        $post->ID            = $id;
        $post->post_type     = $type;
        $post->post_status   = $status;
        $post->post_title    = 'Test Form';
        $post->post_date     = '2026-01-01 00:00:00';
        $post->post_modified = '2026-01-15 12:00:00';
        return $post;
    }

    // ------------------------------------------------------------------
    // Route registration
    // ------------------------------------------------------------------

    public function test_register_routes_creates_three_endpoints(): void {
        $ctrl = new FormRestController( 'ffc/v1', $this->form_repo_mock );
        $ctrl->register_routes();

        $this->assertCount( 3, $this->registered_routes );
    }

    public function test_forms_list_route_is_public(): void {
        $ctrl = new FormRestController( 'ffc/v1', $this->form_repo_mock );
        $ctrl->register_routes();

        $route = $this->registered_routes[0];
        $this->assertSame( '/forms', $route['route'] );
        $this->assertSame( '__return_true', $route['args']['permission_callback'] );
    }

    public function test_form_single_route_is_public(): void {
        $ctrl = new FormRestController( 'ffc/v1', $this->form_repo_mock );
        $ctrl->register_routes();

        $route = $this->registered_routes[1];
        $this->assertStringContainsString( 'forms', $route['route'] );
        $this->assertSame( '__return_true', $route['args']['permission_callback'] );
    }

    public function test_form_submit_route_is_public(): void {
        $ctrl = new FormRestController( 'ffc/v1', $this->form_repo_mock );
        $ctrl->register_routes();

        $route = $this->registered_routes[2];
        $this->assertStringContainsString( 'submit', $route['route'] );
        $this->assertSame( '__return_true', $route['args']['permission_callback'] );
    }

    // ------------------------------------------------------------------
    // get_forms()
    // ------------------------------------------------------------------

    public function test_get_forms_returns_error_when_repository_null(): void {
        $ctrl = new FormRestController( 'ffc/v1', null );
        $request = $this->make_request();
        $result = $ctrl->get_forms( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'repository_not_found', $result->get_error_code() );
    }

    public function test_get_forms_returns_forms_list_on_success(): void {
        $post1 = $this->make_post( 1 );
        $post1->post_title = 'Form A';
        $post2 = $this->make_post( 2 );
        $post2->post_title = 'Form B';

        $this->form_repo_mock->shouldReceive( 'findPublished' )->once()->andReturn( array( $post1, $post2 ) );
        $this->form_repo_mock->shouldReceive( 'getConfig' )->with( 1 )->andReturn( array( 'theme' => 'default' ) );
        $this->form_repo_mock->shouldReceive( 'getConfig' )->with( 2 )->andReturn( array( 'theme' => 'dark' ) );

        $ctrl = new FormRestController( 'ffc/v1', $this->form_repo_mock );
        $request = $this->make_request( array( 'limit' => -1 ) );
        $result = $ctrl->get_forms( $request );

        $this->assertIsArray( $result );
        $this->assertCount( 2, $result );
        $this->assertSame( 1, $result[0]['id'] );
        $this->assertSame( 'Form A', $result[0]['title'] );
        $this->assertSame( 2, $result[1]['id'] );
    }

    // ------------------------------------------------------------------
    // get_form()
    // ------------------------------------------------------------------

    public function test_get_form_returns_error_when_not_found(): void {
        Functions\when( 'get_post' )->justReturn( null );

        $ctrl = new FormRestController( 'ffc/v1', $this->form_repo_mock );
        $request = $this->make_request( array( 'id' => 99 ) );
        $result = $ctrl->get_form( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'form_not_found', $result->get_error_code() );
    }

    public function test_get_form_returns_error_when_wrong_post_type(): void {
        $post = $this->make_post( 5, 'post' );
        Functions\when( 'get_post' )->justReturn( $post );

        $ctrl = new FormRestController( 'ffc/v1', $this->form_repo_mock );
        $request = $this->make_request( array( 'id' => 5 ) );
        $result = $ctrl->get_form( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'form_not_found', $result->get_error_code() );
    }

    public function test_get_form_returns_error_when_not_published(): void {
        $post = $this->make_post( 5, 'ffc_form', 'draft' );
        Functions\when( 'get_post' )->justReturn( $post );

        $ctrl = new FormRestController( 'ffc/v1', $this->form_repo_mock );
        $request = $this->make_request( array( 'id' => 5 ) );
        $result = $ctrl->get_form( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'form_not_published', $result->get_error_code() );
    }

    public function test_get_form_returns_full_data_on_success(): void {
        $post = $this->make_post( 3 );
        $post->post_title = 'My Form';
        Functions\when( 'get_post' )->justReturn( $post );

        $this->form_repo_mock->shouldReceive( 'getConfig' )->with( 3 )->andReturn( array( 'theme' => 'light' ) );
        $this->form_repo_mock->shouldReceive( 'getFields' )->with( 3 )->andReturn( array(
            array( 'name' => 'full_name', 'type' => 'text', 'required' => true ),
        ));
        $this->form_repo_mock->shouldReceive( 'getBackground' )->with( 3 )->andReturn( 'bg.jpg' );

        $ctrl = new FormRestController( 'ffc/v1', $this->form_repo_mock );
        $request = $this->make_request( array( 'id' => 3 ) );
        $result = $ctrl->get_form( $request );

        $this->assertIsArray( $result );
        $this->assertSame( 3, $result['id'] );
        $this->assertSame( 'My Form', $result['title'] );
        $this->assertArrayHasKey( 'config', $result );
        $this->assertArrayHasKey( 'fields', $result );
        $this->assertArrayHasKey( 'background', $result );
    }

    // ------------------------------------------------------------------
    // submit_form()
    // ------------------------------------------------------------------

    public function test_submit_form_returns_error_when_params_empty(): void {
        $ctrl = new FormRestController( 'ffc/v1', $this->form_repo_mock );
        $request = $this->make_request( array( 'id' => 1 ), [] );
        $result = $ctrl->submit_form( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'no_data', $result->get_error_code() );
    }

    public function test_submit_form_returns_error_when_form_not_found(): void {
        Functions\when( 'get_post' )->justReturn( null );

        $ctrl = new FormRestController( 'ffc/v1', $this->form_repo_mock );
        $request = $this->make_request(
            array( 'id' => 99 ),
            array( 'full_name' => 'John' )
        );
        $result = $ctrl->submit_form( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'form_not_found', $result->get_error_code() );
    }

    public function test_submit_form_returns_error_when_form_not_published(): void {
        $post = $this->make_post( 1, 'ffc_form', 'draft' );
        Functions\when( 'get_post' )->justReturn( $post );

        $ctrl = new FormRestController( 'ffc/v1', $this->form_repo_mock );
        $request = $this->make_request(
            array( 'id' => 1 ),
            array( 'full_name' => 'John' )
        );
        $result = $ctrl->submit_form( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'form_not_published', $result->get_error_code() );
    }

    public function test_submit_form_returns_error_when_required_fields_missing(): void {
        $post = $this->make_post( 1 );
        Functions\when( 'get_post' )->justReturn( $post );

        $this->form_repo_mock->shouldReceive( 'getFields' )->with( 1 )->andReturn( array(
            array( 'name' => 'full_name', 'label' => 'Full Name', 'required' => true ),
        ));

        $ctrl = new FormRestController( 'ffc/v1', $this->form_repo_mock );
        $request = $this->make_request(
            array( 'id' => 1 ),
            array( 'other_field' => 'value' )
        );
        $result = $ctrl->submit_form( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'validation_failed', $result->get_error_code() );
    }

    public function test_submit_form_returns_error_for_invalid_cpf(): void {
        $post = $this->make_post( 1 );
        Functions\when( 'get_post' )->justReturn( $post );

        $this->form_repo_mock->shouldReceive( 'getFields' )->with( 1 )->andReturn( array() );

        // Make validate_cpf return false to trigger the invalid CPF error
        $utils = Mockery::fetchMock( \FreeFormCertificate\Core\Utils::class );
        $utils->shouldReceive( 'validate_cpf' )->with( '12345678901' )->andReturn( false );

        $ctrl = new FormRestController( 'ffc/v1', $this->form_repo_mock );
        $request = $this->make_request(
            array( 'id' => 1 ),
            array( 'cpf_rf' => '123.456.789-01' )
        );
        $result = $ctrl->submit_form( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'invalid_cpf', $result->get_error_code() );
    }

    public function test_submit_form_returns_error_for_invalid_email(): void {
        $post = $this->make_post( 1 );
        Functions\when( 'get_post' )->justReturn( $post );

        $this->form_repo_mock->shouldReceive( 'getFields' )->with( 1 )->andReturn( array() );

        $ctrl = new FormRestController( 'ffc/v1', $this->form_repo_mock );
        $request = $this->make_request(
            array( 'id' => 1 ),
            array( 'email' => 'not-valid-email' )
        );
        $result = $ctrl->submit_form( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'invalid_email', $result->get_error_code() );
    }

    public function test_submit_form_success_returns_response(): void {
        $post = $this->make_post( 1 );
        $post->post_title = 'Certificate Form';
        Functions\when( 'get_post' )->justReturn( $post );

        $this->form_repo_mock->shouldReceive( 'getConfig' )->with( 1 )->andReturn( array() );
        $this->form_repo_mock->shouldReceive( 'getFields' )->with( 1 )->andReturn( array() );

        $this->submission_handler_mock->shouldReceive( 'process_submission' )
            ->once()
            ->andReturn( 42 );

        $ctrl = new FormRestController( 'ffc/v1', $this->form_repo_mock );
        $request = $this->make_request(
            array( 'id' => 1 ),
            array(
                'full_name' => 'John Doe',
                'email'     => 'john@example.com',
                'auth_code' => 'ABCDEFGHIJKL',
            )
        );
        $result = $ctrl->submit_form( $request );

        $this->assertIsArray( $result );
        $this->assertTrue( $result['success'] );
        $this->assertSame( 42, $result['submission_id'] );
        $this->assertArrayHasKey( 'auth_code', $result );
        $this->assertArrayHasKey( 'validation_url', $result );
        $this->assertSame( 'https://example.com/validate-certificate/', $result['validation_url'] );
    }
}
