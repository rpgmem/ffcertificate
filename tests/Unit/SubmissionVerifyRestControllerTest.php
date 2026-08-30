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
 * RequestInput::get_user_ip() or the rate-limit settings read.
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
		// RequestInput::get_user_ip() sanitizes $_SERVER via Core-namespaced funcs.
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'FreeFormCertificate\Core\sanitize_text_field' )->returnArg();
		Functions\when( 'FreeFormCertificate\Core\wp_unslash' )->returnArg();
		Functions\when( 'is_email' )->alias(
			fn ( $email ) => false !== filter_var( (string) $email, FILTER_VALIDATE_EMAIL )
		);
		// Default the PII tier to MASKED (uid <= 0 short-circuits in
		// PiiAccessPolicy). The admin list/single handlers now resolve a tier
		// (#838 S2); tests that need reveal/unmasked override this per-test.
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
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
	// get_submission() — PII tier (#838 S2)
	// ------------------------------------------------------------------

	/**
	 * Fixture row carrying every PII surface: top-level email/cpf/rf columns
	 * plus a `data` blob mixing a PII key (cpf) with a legitimate non-PII field.
	 *
	 * @return array<string, mixed>
	 */
	private function pii_submission_row(): array {
		return array(
			'id'              => 30,
			'form_id'         => 3,
			'auth_code'       => 'RAWCODE30303',
			'submission_date' => '2030-05-05',
			'status'          => 'publish',
			'user_id'         => 99,
			'data'            => '{"cpf":"11144477735","course":"Math"}',
			'email'           => 'ana@x.com',
			'cpf'             => '11144477735',
			'rf'              => '7654321',
		);
	}

	public function test_get_submission_masks_pii_for_view_tier(): void {
		Functions\when( 'get_post' )->justReturn( (object) array( 'post_title' => 'Cert' ) );
		// uid 5, no manage_options, non-admin role, no PII cap, not the owner
		// (99) → MASKED tier.
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'user_can' )->justReturn( false );
		Functions\when( 'get_user_by' )->justReturn( (object) array( 'roles' => array( 'subscriber' ) ) );

		$repo = Mockery::mock( SubmissionRepository::class );
		$repo->shouldReceive( 'findById' )->once()->andReturn( $this->pii_submission_row() );

		$ctrl    = new SubmissionRestController( 'ffc/v1', $repo );
		$request = Mockery::mock( 'WP_REST_Request' );
		$request->shouldReceive( 'get_param' )->with( 'id' )->andReturn( 30 );

		$result = $ctrl->get_submission( $request );

		// Top-level PII masked, never raw.
		$this->assertStringContainsString( '*', $result['cpf'] );
		$this->assertStringContainsString( '*', $result['rf'] );
		$this->assertStringContainsString( '*', $result['email'] );
		$this->assertNotSame( '11144477735', $result['cpf'] );
		$this->assertNotSame( 'ana@x.com', $result['email'] );
		// PII inside the data blob masked; the non-PII field survives verbatim.
		$this->assertStringContainsString( '*', $result['data']['cpf'] );
		$this->assertNotSame( '11144477735', $result['data']['cpf'] );
		$this->assertSame( 'Math', $result['data']['course'] );
	}

	public function test_get_submission_reveals_plaintext_for_pii_cap_holder(): void {
		Functions\when( 'get_post' )->justReturn( (object) array( 'post_title' => 'Cert' ) );
		// uid 7, holds ffc_view_certificates_pii (not manage_options) → REVEAL.
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'get_user_by' )->justReturn( (object) array( 'roles' => array( 'subscriber' ) ) );
		Functions\when( 'user_can' )->alias( fn ( $uid, $cap ) => 'ffc_view_certificates_pii' === $cap );
		// Activity log stays disabled (get_option → array()), so the reveal-tier
		// audit call no-ops without a DB write; we assert the reveal output.

		$repo = Mockery::mock( SubmissionRepository::class );
		$repo->shouldReceive( 'findById' )->once()->andReturn( $this->pii_submission_row() );

		$ctrl    = new SubmissionRestController( 'ffc/v1', $repo );
		$request = Mockery::mock( 'WP_REST_Request' );
		$request->shouldReceive( 'get_param' )->with( 'id' )->andReturn( 30 );

		$result = $ctrl->get_submission( $request );

		// Reveal tier: plaintext, not masked.
		$this->assertSame( 'ana@x.com', $result['email'] );
		$this->assertStringNotContainsString( '*', $result['cpf'] );
		$this->assertStringContainsString( '111', $result['cpf'] );
		// The data blob is returned raw for the reveal tier.
		$this->assertSame( '11144477735', $result['data']['cpf'] );
		$this->assertSame( 'Math', $result['data']['course'] );
	}

	// ------------------------------------------------------------------
	// verify_certificate()
	// ------------------------------------------------------------------

	public function test_verify_certificate_masks_data_blob(): void {
		Functions\when( 'get_post' )->justReturn( (object) array( 'post_title' => 'Cert Form' ) );

		$repo = Mockery::mock( SubmissionRepository::class );
		$repo->shouldReceive( 'findByAuthCode' )->once()->andReturn(
			array(
				'id'              => 40,
				'form_id'         => 4,
				'status'          => 'publish',
				'submission_date' => '2030-06-06',
				'data'            => '{"cpf":"11144477735","email":"bob@y.com","course":"Chemistry"}',
			)
		);

		$ctrl    = new SubmissionRestController( 'ffc/v1', $repo );
		$request = Mockery::mock( 'WP_REST_Request' );
		$request->shouldReceive( 'get_param' )->with( 'auth_code' )->andReturn( 'CLEANCODE123' );

		$result = $ctrl->verify_certificate( $request );

		$data = $result['certificate']['data'];
		// PII keys inside the public /verify blob are masked; non-PII survives.
		$this->assertStringContainsString( '*', $data['cpf'] );
		$this->assertNotSame( '11144477735', $data['cpf'] );
		$this->assertStringContainsString( '*', $data['email'] );
		$this->assertNotSame( 'bob@y.com', $data['email'] );
		$this->assertSame( 'Chemistry', $data['course'] );
	}

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
		Functions\when( 'is_email' )->alias( function ( $email ) {
			return false !== filter_var( (string) $email, FILTER_VALIDATE_EMAIL );
		} );

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
				// Legacy plaintext RF column (pre-encryption installs).
				'rf'              => '1234567',
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
		// This endpoint is public: email/rf must be masked, not returned raw.
		$this->assertStringContainsString( '@x.com', $result['certificate']['email'] );
		$this->assertStringContainsString( '*', $result['certificate']['email'] );
		$this->assertNotSame( 'bia@x.com', $result['certificate']['email'] );
		$this->assertStringContainsString( '*', $result['certificate']['rf'] );
		$this->assertStringNotContainsString( '1234567', $result['certificate']['rf'] );
	}

	public function test_verify_certificate_rate_limited(): void {
		Mockery::mock( 'alias:\FreeFormCertificate\Security\RateLimiter' )
			->shouldReceive( 'check_verification' )->andReturn( array( 'allowed' => false, 'message' => 'slow' ) );

		$ctrl    = new SubmissionRestController( 'ffc/v1', Mockery::mock( SubmissionRepository::class ) );
		$request = Mockery::mock( 'WP_REST_Request' );
		$request->shouldReceive( 'get_param' )->with( 'auth_code' )->andReturn( 'CODE' );

		$result = $ctrl->verify_certificate( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_rate_limited', $result->get_error_code() );
	}

	public function test_verify_certificate_wraps_exception(): void {
		$repo = Mockery::mock( SubmissionRepository::class );
		$repo->shouldReceive( 'findByAuthCode' )->andThrow( new \RuntimeException( 'boom' ) );

		$ctrl    = new SubmissionRestController( 'ffc/v1', $repo );
		$request = Mockery::mock( 'WP_REST_Request' );
		$request->shouldReceive( 'get_param' )->with( 'auth_code' )->andReturn( 'CODE' );

		$result = $ctrl->verify_certificate( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_internal_error', $result->get_error_code() );
	}
}
