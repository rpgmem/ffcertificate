<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\API\OperatorCertificatesRestController;
use FreeFormCertificate\Repositories\FormRepository;

/**
 * Tests for OperatorCertificatesRestController (#858): authenticated operator
 * issuance (A1) + PDF (A2).
 *
 * @covers \FreeFormCertificate\API\OperatorCertificatesRestController
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class OperatorCertificatesRestControllerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<int, array<string, mixed>> */
	private array $registered_routes = array();

	/** @var \Mockery\MockInterface&FormRepository */
	private $form_repo;

	/** @var \Mockery\MockInterface */
	private $handler;

	/** @var \Mockery\MockInterface */
	private $rate_limiter;

	/** @var \Mockery\MockInterface */
	private $caps;

	/** @var \Mockery\MockInterface */
	private $generator;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		class_exists( '\\FreeFormCertificate\\API\\OperatorCertificatesRestController' );

		$this->registered_routes = array();
		Functions\when( 'register_rest_route' )->alias(
			function ( $namespace, $route, $args ) {
				$this->registered_routes[] = array(
					'namespace' => $namespace,
					'route'     => $route,
					'args'      => $args,
				);
			}
		);

		Functions\when( '__' )->returnArg();
		Functions\when( 'rest_ensure_response' )->returnArg();
		Functions\when( 'is_wp_error' )->alias( static fn( $t ) => $t instanceof \WP_Error );
		Functions\when( 'is_email' )->alias( static fn( $e ) => (bool) filter_var( $e, FILTER_VALIDATE_EMAIL ) );
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		Functions\when( 'home_url' )->alias( static fn( $p = '' ) => 'https://example.com' . $p );
		Functions\when( 'rest_url' )->alias( static fn( $p = '' ) => 'https://example.com/wp-json/' . ltrim( (string) $p, '/' ) );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_post' )->justReturn( null );

		$this->form_repo = Mockery::mock( FormRepository::class );
		$this->form_repo->shouldReceive( 'getConfig' )->andReturn( array() )->byDefault();
		$this->form_repo->shouldReceive( 'getFields' )->andReturn( array() )->byDefault();

		// SubmissionHandler is instantiated with `new` inside the controller.
		$this->handler = Mockery::mock( 'overload:\FreeFormCertificate\Submissions\SubmissionHandler' );
		$this->handler->shouldReceive( 'process_submission' )->andReturn( 5 )->byDefault();
		$this->handler->shouldReceive( 'get_submission' )->andReturn(
			array(
				'id'      => 5,
				'user_id' => 7,
			)
		)->byDefault();

		$this->generator = Mockery::mock( 'overload:\FreeFormCertificate\Generators\PdfGenerator' );
		$this->generator->shouldReceive( 'generate_pdf_data' )->andReturn( array( 'html' => '<div>cert</div>' ) )->byDefault();

		// Per-user rate limit (the only limiter the operator path uses).
		$this->rate_limiter = Mockery::mock( 'alias:\FreeFormCertificate\Security\RateLimiter' );
		$this->rate_limiter->shouldReceive( 'check_user_limit' )->andReturn( array( 'allowed' => true ) )->byDefault();

		$this->caps = Mockery::mock( 'alias:\FreeFormCertificate\Core\Capabilities' );
		$this->caps->shouldReceive( 'current_user_can_admin_or' )->andReturn( true )->byDefault();

		$sanitizer = Mockery::mock( 'alias:\FreeFormCertificate\Core\DataSanitizer' );
		$sanitizer->shouldReceive( 'recursive_sanitize' )->andReturnUsing( static fn( $d ) => $d )->byDefault();
		$sanitizer->shouldReceive( 'normalize_cpf_rf' )->andReturnUsing( static fn( $v ) => preg_replace( '/[^0-9]/', '', (string) $v ) ?? '' )->byDefault();

		// ActivityLog runs for real but short-circuits on the disabled setting.
		if ( ! class_exists( 'FreeFormCertificate\Settings\SettingsReader', false ) ) {
			$reader = Mockery::mock( 'alias:FreeFormCertificate\Settings\SettingsReader' );
			$reader->shouldReceive( 'activity_log_enabled' )->andReturn( false )->byDefault();
			$reader->shouldReceive( 'activity_log_min_level' )->andReturn( 'info' )->byDefault();
			$reader->shouldReceive( 'activity_log_category_enabled' )->andReturn( true )->byDefault();
		}
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function controller(): OperatorCertificatesRestController {
		return new OperatorCertificatesRestController( 'ffc/v1', $this->form_repo );
	}

	/**
	 * @param array<string, mixed> $json  JSON body params.
	 * @param array<string, mixed> $query Route/query params.
	 */
	private function request( array $json = array(), array $query = array() ): object {
		$req = Mockery::mock( 'WP_REST_Request' );
		$req->shouldReceive( 'get_json_params' )->andReturn( $json )->byDefault();
		$req->shouldReceive( 'get_param' )->andReturnUsing(
			static function ( $key ) use ( $json, $query ) {
				return $query[ $key ] ?? $json[ $key ] ?? null;
			}
		);
		return $req;
	}

	private function publishedForm(): object {
		return (object) array(
			'ID'          => 10,
			'post_type'   => 'ffc_form',
			'post_status' => 'publish',
			'post_title'  => 'Field Form',
		);
	}

	// ------------------------------------------------------------------
	// Routes + permission callbacks
	// ------------------------------------------------------------------

	public function test_register_routes_registers_issue_and_pdf(): void {
		$this->controller()->register_routes();

		$routes = array_column( $this->registered_routes, 'route' );
		$this->assertContains( '/operator/certificates', $routes );
		$this->assertContains( '/operator/certificates/(?P<id>\d+)/pdf', $routes );

		foreach ( $this->registered_routes as $r ) {
			$this->assertSame( 'ffc/v1', $r['namespace'] );
			$this->assertIsCallable( $r['args']['permission_callback'] );
		}
	}

	public function test_permission_issue_requires_capability(): void {
		$this->caps->shouldReceive( 'current_user_can_admin_or' )->with( 'ffc_manage_certificates' )->andReturn( false );
		$this->assertFalse( $this->controller()->permission_issue() );
	}

	public function test_permission_read_pdf_requires_login(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		$this->assertFalse( $this->controller()->permission_read_pdf() );
	}

	// ------------------------------------------------------------------
	// issue_certificate (A1)
	// ------------------------------------------------------------------

	public function test_issue_certificate_happy_path(): void {
		Functions\when( 'get_post' )->justReturn( $this->publishedForm() );

		$resp = $this->controller()->issue_certificate(
			$this->request( array( 'form_id' => 10, 'name' => 'Maria', 'email' => 'maria@example.com' ) )
		);

		$this->assertIsArray( $resp );
		$this->assertTrue( $resp['success'] );
		$this->assertSame( 5, $resp['submission_id'] );
		$this->assertArrayHasKey( 'auth_code', $resp );
		$this->assertStringContainsString( '/operator/certificates/5/pdf', $resp['pdf_endpoint'] );
	}

	public function test_issue_certificate_rejects_empty_body(): void {
		$resp = $this->controller()->issue_certificate( $this->request( array() ) );
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'no_data', $resp->get_error_code() );
	}

	public function test_issue_certificate_form_repo_unavailable(): void {
		Functions\when( 'get_post' )->justReturn( $this->publishedForm() );
		// Constructed without a form repository → the null-repo guard trips.
		$ctrl = new OperatorCertificatesRestController( 'ffc/v1', null );
		$resp = $ctrl->issue_certificate( $this->request( array( 'form_id' => 10 ) ) );
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'form_repo_unavailable', $resp->get_error_code() );
	}

	public function test_issue_certificate_validation_failed_for_missing_required_field(): void {
		Functions\when( 'get_post' )->justReturn( $this->publishedForm() );
		$this->form_repo->shouldReceive( 'getFields' )->andReturn(
			array( array( 'name' => 'documento', 'required' => true, 'label' => 'Documento' ) )
		);

		$resp = $this->controller()->issue_certificate( $this->request( array( 'form_id' => 10, 'name' => 'Maria' ) ) );

		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'validation_failed', $resp->get_error_code() );
	}

	public function test_issue_certificate_wrong_length_cpf_rf(): void {
		Functions\when( 'get_post' )->justReturn( $this->publishedForm() );
		$resp = $this->controller()->issue_certificate(
			$this->request( array( 'form_id' => 10, 'cpf_rf' => '123' ) )
		);
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'invalid_cpf_rf', $resp->get_error_code() );
	}

	public function test_issue_certificate_accepts_valid_cpf(): void {
		Functions\when( 'get_post' )->justReturn( $this->publishedForm() );
		// 52998224725 is a checksum-valid CPF.
		$resp = $this->controller()->issue_certificate(
			$this->request( array( 'form_id' => 10, 'cpf_rf' => '529.982.247-25' ) )
		);
		$this->assertIsArray( $resp );
		$this->assertTrue( $resp['success'] );
	}

	public function test_issue_certificate_bubbles_process_submission_error(): void {
		Functions\when( 'get_post' )->justReturn( $this->publishedForm() );
		$this->handler->shouldReceive( 'process_submission' )->andReturn(
			new \WP_Error( 'dup', 'duplicate' )
		);

		$resp = $this->controller()->issue_certificate( $this->request( array( 'form_id' => 10 ) ) );

		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'dup', $resp->get_error_code() );
	}

	public function test_issue_certificate_wraps_unexpected_exception(): void {
		Functions\when( 'get_post' )->justReturn( $this->publishedForm() );
		$this->handler->shouldReceive( 'process_submission' )->andThrow( new \RuntimeException( 'kaboom' ) );

		$resp = $this->controller()->issue_certificate( $this->request( array( 'form_id' => 10 ) ) );

		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'ffc_internal_error', $resp->get_error_code() );
	}

	public function test_issue_certificate_rejects_missing_form_id(): void {
		$resp = $this->controller()->issue_certificate( $this->request( array( 'name' => 'x' ) ) );
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'missing_form_id', $resp->get_error_code() );
	}

	public function test_issue_certificate_form_not_found(): void {
		Functions\when( 'get_post' )->justReturn( null );
		$resp = $this->controller()->issue_certificate( $this->request( array( 'form_id' => 10 ) ) );
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'form_not_found', $resp->get_error_code() );
	}

	public function test_issue_certificate_form_not_published(): void {
		$draft              = $this->publishedForm();
		$draft->post_status = 'draft';
		Functions\when( 'get_post' )->justReturn( $draft );
		$resp = $this->controller()->issue_certificate( $this->request( array( 'form_id' => 10 ) ) );
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'form_not_published', $resp->get_error_code() );
	}

	public function test_issue_certificate_rate_limited(): void {
		Functions\when( 'get_post' )->justReturn( $this->publishedForm() );
		$this->rate_limiter->shouldReceive( 'check_user_limit' )->andReturn( array( 'allowed' => false, 'message' => 'slow down' ) );

		$resp = $this->controller()->issue_certificate( $this->request( array( 'form_id' => 10 ) ) );
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'rate_limited', $resp->get_error_code() );
	}

	public function test_issue_certificate_invalid_email(): void {
		Functions\when( 'get_post' )->justReturn( $this->publishedForm() );
		$resp = $this->controller()->issue_certificate(
			$this->request( array( 'form_id' => 10, 'email' => 'not-an-email' ) )
		);
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'invalid_email', $resp->get_error_code() );
	}

	public function test_issue_certificate_invalid_cpf(): void {
		Functions\when( 'get_post' )->justReturn( $this->publishedForm() );
		// 11 digits that fail the CPF checksum.
		$resp = $this->controller()->issue_certificate(
			$this->request( array( 'form_id' => 10, 'cpf_rf' => '11111111111' ) )
		);
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'invalid_cpf', $resp->get_error_code() );
	}

	// ------------------------------------------------------------------
	// get_certificate_pdf (A2)
	// ------------------------------------------------------------------

	public function test_pdf_returned_for_capability_holder(): void {
		$resp = $this->controller()->get_certificate_pdf( $this->request( array(), array( 'id' => 5 ) ) );

		$this->assertIsArray( $resp );
		$this->assertTrue( $resp['success'] );
		$this->assertSame( 5, $resp['submission_id'] );
		$this->assertArrayHasKey( 'pdf_data', $resp );
	}

	public function test_pdf_allowed_for_owner_without_capability(): void {
		$this->caps->shouldReceive( 'current_user_can_admin_or' )->andReturn( false );
		// Owner: submission user_id (7) === get_current_user_id() (7).
		$resp = $this->controller()->get_certificate_pdf( $this->request( array(), array( 'id' => 5 ) ) );
		$this->assertIsArray( $resp );
		$this->assertTrue( $resp['success'] );
	}

	public function test_pdf_forbidden_for_non_owner_without_capability(): void {
		$this->caps->shouldReceive( 'current_user_can_admin_or' )->andReturn( false );
		$this->handler->shouldReceive( 'get_submission' )->andReturn( array( 'id' => 5, 'user_id' => 999 ) );

		$resp = $this->controller()->get_certificate_pdf( $this->request( array(), array( 'id' => 5 ) ) );
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'forbidden', $resp->get_error_code() );
	}

	public function test_pdf_not_found(): void {
		$this->handler->shouldReceive( 'get_submission' )->andReturn( null );
		$resp = $this->controller()->get_certificate_pdf( $this->request( array(), array( 'id' => 5 ) ) );
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'certificate_not_found', $resp->get_error_code() );
	}

	public function test_pdf_rejects_invalid_id(): void {
		$resp = $this->controller()->get_certificate_pdf( $this->request( array(), array( 'id' => 0 ) ) );
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'invalid_id', $resp->get_error_code() );
	}

	public function test_pdf_bubbles_generator_error(): void {
		$this->generator->shouldReceive( 'generate_pdf_data' )->andReturn(
			new \WP_Error( 'render_failed', 'boom' )
		);
		$resp = $this->controller()->get_certificate_pdf( $this->request( array(), array( 'id' => 5 ) ) );
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'render_failed', $resp->get_error_code() );
	}

	public function test_pdf_wraps_unexpected_exception(): void {
		$this->handler->shouldReceive( 'get_submission' )->andThrow( new \RuntimeException( 'kaboom' ) );
		$resp = $this->controller()->get_certificate_pdf( $this->request( array(), array( 'id' => 5 ) ) );
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'ffc_internal_error', $resp->get_error_code() );
	}
}
