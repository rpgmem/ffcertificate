<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\API\SubmissionRestController;
use FreeFormCertificate\Repositories\SubmissionRepository;

/**
 * Handler-path tests for SubmissionRestController: get_submissions(),
 * get_submission() happy path, and verify_certificate(). These exercise the
 * real static collaborators (Encryption / DocumentFormatter / Utils /
 * RateLimiter); process isolation gives each test a clean function table so a
 * leaked global/namespaced mock from an earlier same-process test can't poison
 * Utils::get_user_ip() or the rate-limit settings read.
 *
 * @covers \FreeFormCertificate\API\SubmissionRestController
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class SubmissionVerifyRestControllerTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'rest_ensure_response' )->alias( fn ( $data ) => $data );
        // RateLimiter::check_verification → RateLimitChecker::get_settings()
        // reads get_option(); empty settings ⇒ "allowed" with no DB access.
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\when( 'wp_cache_get' )->justReturn( false );
        Functions\when( 'wp_cache_set' )->justReturn( true );
        Functions\when( 'wp_parse_args' )->alias(
            fn ( $args, $defaults = array() ) => array_merge( $defaults, is_array( $args ) ? $args : array() )
        );
        // Utils::get_user_ip() sanitizes $_SERVER via Core-namespaced funcs.
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'FreeFormCertificate\Core\sanitize_text_field' )->returnArg();
        Functions\when( 'FreeFormCertificate\Core\wp_unslash' )->returnArg();
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
    }

    protected function tearDown(): void {
        $_SERVER = array();
        Monkey\tearDown();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // get_submissions()
    // ------------------------------------------------------------------

    public function test_get_submissions_maps_items(): void {
        $repo = Mockery::mock( SubmissionRepository::class );
        $repo->shouldReceive( 'findPaginated' )->once()->andReturn(
            array(
                'items' => array(
                    array(
                        'id'              => 3,
                        'form_id'         => 9,
                        'auth_code'       => 'RAWCODE12345',
                        'submission_date' => '2030-01-01',
                        'status'          => 'publish',
                        'data'            => '{"name":"Ana"}',
                    ),
                ),
                'total' => 1,
                'pages' => 1,
            )
        );

        $ctrl    = new SubmissionRestController( 'ffc/v1', $repo );
        $request = Mockery::mock( 'WP_REST_Request' );
        $request->shouldReceive( 'get_param' )->with( 'page' )->andReturn( 1 );
        $request->shouldReceive( 'get_param' )->with( 'per_page' )->andReturn( 20 );
        $request->shouldReceive( 'get_param' )->with( 'status' )->andReturn( 'publish' );
        $request->shouldReceive( 'get_param' )->with( 'search' )->andReturn( '' );

        $result = $ctrl->get_submissions( $request );

        $this->assertIsArray( $result );
        $this->assertSame( 1, $result['total'] );
        $this->assertSame( 3, $result['items'][0]['id'] );
        $this->assertSame( 'Ana', $result['items'][0]['data']['name'] );
    }

    // ------------------------------------------------------------------
    // get_submission() happy path
    // ------------------------------------------------------------------

    public function test_get_submission_happy_path(): void {
        Functions\when( 'get_post' )->justReturn( (object) array( 'post_title' => 'My Form' ) );

        $repo = Mockery::mock( SubmissionRepository::class );
        $repo->shouldReceive( 'findById' )->once()->andReturn(
            array(
                'id'              => 11,
                'form_id'         => 2,
                'auth_code'       => 'RAWCODE99999',
                'submission_date' => '2030-02-02',
                'status'          => 'publish',
                'data'            => '{"city":"SP"}',
            )
        );

        $ctrl    = new SubmissionRestController( 'ffc/v1', $repo );
        $request = Mockery::mock( 'WP_REST_Request' );
        $request->shouldReceive( 'get_param' )->with( 'id' )->andReturn( 11 );

        $result = $ctrl->get_submission( $request );

        $this->assertIsArray( $result );
        $this->assertSame( 11, $result['id'] );
        $this->assertSame( 'My Form', $result['form_title'] );
        $this->assertSame( 'SP', $result['data']['city'] );
    }

    // ------------------------------------------------------------------
    // verify_certificate()
    // ------------------------------------------------------------------

    public function test_verify_certificate_returns_error_without_repo(): void {
        $ctrl    = new SubmissionRestController( 'ffc/v1', null );
        $request = Mockery::mock( 'WP_REST_Request' );
        $request->shouldReceive( 'get_param' )->with( 'auth_code' )->andReturn( 'ABCD-EFGH-IJKL' );

        $result = $ctrl->verify_certificate( $request );
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'repository_not_found', $result->get_error_code() );
    }

    public function test_verify_certificate_not_found(): void {
        $repo = Mockery::mock( SubmissionRepository::class );
        $repo->shouldReceive( 'findByAuthCode' )->once()->andReturn( null );

        $ctrl    = new SubmissionRestController( 'ffc/v1', $repo );
        $request = Mockery::mock( 'WP_REST_Request' );
        $request->shouldReceive( 'get_param' )->with( 'auth_code' )->andReturn( 'CLEANCODE123' );

        $result = $ctrl->verify_certificate( $request );
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'certificate_not_found', $result->get_error_code() );
    }

    public function test_verify_certificate_rejects_trashed(): void {
        $repo = Mockery::mock( SubmissionRepository::class );
        $repo->shouldReceive( 'findByAuthCode' )->once()->andReturn(
            array( 'id' => 1, 'form_id' => 1, 'status' => 'trash' )
        );

        $ctrl    = new SubmissionRestController( 'ffc/v1', $repo );
        $request = Mockery::mock( 'WP_REST_Request' );
        $request->shouldReceive( 'get_param' )->with( 'auth_code' )->andReturn( 'CLEANCODE123' );

        $result = $ctrl->verify_certificate( $request );
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'certificate_deleted', $result->get_error_code() );
    }

    public function test_verify_certificate_happy_path(): void {
        Functions\when( 'get_post' )->justReturn( (object) array( 'post_title' => 'Cert Form' ) );

        $repo = Mockery::mock( SubmissionRepository::class );
        $repo->shouldReceive( 'findByAuthCode' )->once()->andReturn(
            array(
                'id'              => 22,
                'form_id'         => 4,
                'status'          => 'publish',
                'submission_date' => '2030-03-03',
                'data'            => '{"name":"Bia"}',
                'email'           => 'bia@x.com',
                'cpf_rf'          => '11144477735',
            )
        );

        $ctrl    = new SubmissionRestController( 'ffc/v1', $repo );
        $request = Mockery::mock( 'WP_REST_Request' );
        $request->shouldReceive( 'get_param' )->with( 'auth_code' )->andReturn( 'CLEANCODE123' );

        $result = $ctrl->verify_certificate( $request );

        $this->assertIsArray( $result );
        $this->assertTrue( $result['valid'] );
        $this->assertSame( 22, $result['certificate']['id'] );
        $this->assertSame( 'Cert Form', $result['certificate']['form_title'] );
        $this->assertSame( 'bia@x.com', $result['certificate']['email'] );
    }
}
