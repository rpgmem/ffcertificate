<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Frontend\VerificationHandler;
use FreeFormCertificate\Submissions\SubmissionHandler;

/**
 * AJAX-handler coverage for VerificationHandler — handle_verification_ajax()
 * and handle_magic_verification_ajax(). These paths fan out into static
 * collaborators (RateLimiter, SecurityService, ActivityLog, PdfGenerator,
 * FichaGenerator, MagicLinkHelper), so each test runs in its own process
 * with alias/overload mocks; wp_send_json_* are stubbed to throw so the
 * handler short-circuits and we can assert the captured payload.
 *
 * @covers \FreeFormCertificate\Frontend\VerificationHandler
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class VerificationHandlerAjaxTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<string, mixed>|null */
	private $captured_error = null;
	/** @var array<string, mixed>|null */
	private $captured_success = null;
	private $submission_handler;
	private $renderer;
	private VerificationHandler $handler;
	private \ReflectionClass $ref;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		class_exists( '\FreeFormCertificate\Frontend\VerificationHandler' );

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'FreeFormCertificate\Core\sanitize_text_field' )->returnArg();
		Functions\when( 'FreeFormCertificate\Core\wp_unslash' )->returnArg();
		Functions\when( 'is_wp_error' )->alias( static fn( $t ) => $t instanceof \WP_Error );
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( intval( $v ) ) );

		// Repository caching layer (SubmissionRepository / AbstractRepository).
		foreach ( array( '', 'FreeFormCertificate\\Repositories\\', 'FreeFormCertificate\\Core\\' ) as $ns ) {
			Functions\when( $ns . 'wp_cache_get' )->justReturn( false );
			Functions\when( $ns . 'wp_cache_set' )->justReturn( true );
			Functions\when( $ns . 'wp_cache_delete' )->justReturn( true );
		}
		Functions\when( 'apply_filters' )->alias( static function () { $a = func_get_args(); return $a[1] ?? null; } );
		Functions\when( 'do_action' )->justReturn( null );

		$_SERVER['REMOTE_ADDR'] = '8.8.8.8';

		$err = &$this->captured_error;
		Functions\when( 'wp_send_json_error' )->alias( static function ( $p ) use ( &$err ) {
			$err = $p;
			throw new \RuntimeException( 'wp_send_json_error' );
		} );
		$succ = &$this->captured_success;
		Functions\when( 'wp_send_json_success' )->alias( static function ( $p ) use ( &$succ ) {
			$succ = $p;
			throw new \RuntimeException( 'wp_send_json_success' );
		} );

		// Debug is called from verify_by_magic_token.
		Mockery::mock( 'alias:\FreeFormCertificate\Core\Debug' )
			->shouldReceive( 'log_frontend' )->andReturnNull()->byDefault();

		$this->submission_handler = Mockery::mock( SubmissionHandler::class );
		$this->handler            = new VerificationHandler( $this->submission_handler );

		$this->ref      = new \ReflectionClass( VerificationHandler::class );
		$this->renderer = Mockery::mock( '\FreeFormCertificate\Frontend\VerificationResponseRenderer' );
		$prop           = $this->ref->getProperty( 'renderer' );
		$prop->setAccessible( true );
		$prop->setValue( $this->handler, $this->renderer );
	}

	protected function tearDown(): void {
		unset( $_SERVER['REMOTE_ADDR'] );
		$_POST = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	private function rate_limiter_allowed(): \Mockery\MockInterface {
		$rl = Mockery::mock( 'alias:\FreeFormCertificate\Security\RateLimiter' );
		$rl->shouldReceive( 'check_verification' )->andReturn( array( 'allowed' => true ) )->byDefault();
		return $rl;
	}

	// ==================== handle_verification_ajax ====================

	public function test_verification_ajax_rate_limited(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Mockery::mock( 'alias:\FreeFormCertificate\Core\SecurityService' )
			->shouldReceive( 'validate_security_fields' )->andReturn( true );
		$rl = Mockery::mock( 'alias:\FreeFormCertificate\Security\RateLimiter' );
		$rl->shouldReceive( 'check_verification' )->andReturn( array( 'allowed' => false ) );

		$_POST = array( 'nonce' => 'ok', 'ffc_auth_code' => 'CODE12345678' );

		try {
			$this->handler->handle_verification_ajax();
			$this->fail( 'expected halt' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'Too many', $this->captured_error['message'] );
		}
	}

	public function test_verification_ajax_security_fields_fail_refreshes_captcha(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		$svc = Mockery::mock( 'alias:\FreeFormCertificate\Core\SecurityService' );
		$svc->shouldReceive( 'validate_security_fields' )->andReturn( 'Wrong captcha.' );
		$svc->shouldReceive( 'generate_simple_captcha' )->andReturn( array( 'label' => '1+1', 'hash' => 'h1' ) );

		$_POST = array( 'nonce' => 'ok' );

		try {
			$this->handler->handle_verification_ajax();
			$this->fail( 'expected halt' );
		} catch ( \RuntimeException $e ) {
			$this->assertTrue( $this->captured_error['refresh_captcha'] );
			$this->assertSame( '1+1', $this->captured_error['new_label'] );
			$this->assertSame( 'h1', $this->captured_error['new_hash'] );
		}
	}

	public function test_verification_ajax_not_found_refreshes_captcha(): void {
		global $wpdb;
		$wpdb = Mockery::mock( 'wpdb' )->makePartial();
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'Q' );
		$wpdb->shouldReceive( 'get_row' )->andReturn( null );
		$wpdb->shouldReceive( 'get_var' )->andReturn( null );

		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		$svc = Mockery::mock( 'alias:\FreeFormCertificate\Core\SecurityService' );
		$svc->shouldReceive( 'validate_security_fields' )->andReturn( true );
		$svc->shouldReceive( 'generate_simple_captcha' )->andReturn( array( 'label' => '3+3', 'hash' => 'h2' ) );
		$this->rate_limiter_allowed();

		$_POST = array( 'nonce' => 'ok', 'ffc_auth_code' => 'NOPE12345678' );

		try {
			$this->handler->handle_verification_ajax();
			$this->fail( 'expected halt' );
		} catch ( \RuntimeException $e ) {
			$this->assertTrue( $this->captured_error['refresh_captcha'] );
			$this->assertStringContainsString( 'not found', $this->captured_error['message'] );
		}
	}

	public function test_verification_ajax_certificate_success(): void {
		global $wpdb;
		$row = array(
			'id'              => '7',
			'form_id'         => '2',
			'email'           => 'ok@test.com',
			'cpf_rf'          => '',
			'auth_code'       => 'GOOD12345678',
			'data'            => '{}',
			'magic_token'     => 't',
			'submission_date' => 1700000000,
		);
		$wpdb = Mockery::mock( 'wpdb' )->makePartial();
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'Q' );
		$wpdb->shouldReceive( 'get_row' )->andReturn( $row );

		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Mockery::mock( 'alias:\FreeFormCertificate\Core\SecurityService' )
			->shouldReceive( 'validate_security_fields' )->andReturn( true );
		$this->rate_limiter_allowed();
		Mockery::mock( 'alias:\FreeFormCertificate\Core\ActivityLog' )
			->shouldReceive( 'log_data_accessed' )->andReturnNull()->byDefault();

		$this->submission_handler->shouldReceive( 'decrypt_submission_data' )->andReturn( $row );

		// PdfGenerator returns payload (not WP_Error).
		Mockery::mock( 'overload:\FreeFormCertificate\Generators\PdfGenerator' )
			->shouldReceive( 'generate_pdf_data' )->andReturn( array( 'filename' => 'c.pdf' ) );

		$this->renderer->shouldReceive( 'format_verification_response' )->andReturn( '<div>cert</div>' );

		$_POST = array( 'nonce' => 'ok', 'ffc_auth_code' => 'GOOD12345678' );

		try {
			$this->handler->handle_verification_ajax();
			$this->fail( 'expected halt' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'cert', $this->captured_success['html'] );
			$this->assertSame( array( 'filename' => 'c.pdf' ), $this->captured_success['pdf_data'] );
		}
	}

	public function test_verification_ajax_pdf_error_returns_error(): void {
		global $wpdb;
		$row = array(
			'id'              => '8',
			'form_id'         => '2',
			'email'           => 'e@test.com',
			'cpf_rf'          => '',
			'auth_code'       => 'PDFE12345678',
			'data'            => '{}',
			'magic_token'     => '',
			'submission_date' => 1700000000,
		);
		$wpdb = Mockery::mock( 'wpdb' )->makePartial();
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'Q' );
		$wpdb->shouldReceive( 'get_row' )->andReturn( $row );

		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Mockery::mock( 'alias:\FreeFormCertificate\Core\SecurityService' )
			->shouldReceive( 'validate_security_fields' )->andReturn( true );
		$this->rate_limiter_allowed();
		Mockery::mock( 'alias:\FreeFormCertificate\Core\ActivityLog' )
			->shouldReceive( 'log_data_accessed' )->andReturnNull()->byDefault();

		$this->submission_handler->shouldReceive( 'decrypt_submission_data' )->andReturn( $row );

		Mockery::mock( 'overload:\FreeFormCertificate\Generators\PdfGenerator' )
			->shouldReceive( 'generate_pdf_data' )->andReturn( new \WP_Error( 'pdf', 'PDF boom' ) );

		$_POST = array( 'nonce' => 'ok', 'ffc_auth_code' => 'PDFE12345678' );

		try {
			$this->handler->handle_verification_ajax();
			$this->fail( 'expected halt' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'PDF boom', $this->captured_error['message'] );
		}
	}

	public function test_verification_ajax_appointment_success(): void {
		global $wpdb;
		$appointment = array(
			'id'                 => '40',
			'calendar_id'        => '0',
			'name'               => 'Appt Ajax',
			'email'              => 'aa@test.com',
			'cpf'                => '',
			'validation_code'    => 'APPTAJAX1234',
			'appointment_date'   => '2026-09-01',
			'start_time'         => '10:00',
			'end_time'           => '10:30',
			'status'             => 'confirmed',
			'confirmation_token' => 'tok',
			'created_at'         => '2026-08-01',
		);
		$wpdb = Mockery::mock( 'wpdb' )->makePartial();
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'Q' );
		// Certificate lookup null, then appointment findByValidationCode hit.
		$wpdb->shouldReceive( 'get_row' )->andReturn( null, $appointment );

		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Mockery::mock( 'alias:\FreeFormCertificate\Core\SecurityService' )
			->shouldReceive( 'validate_security_fields' )->andReturn( true );
		$this->rate_limiter_allowed();

		Mockery::mock( 'overload:\FreeFormCertificate\Generators\PdfGenerator' );
		$this->renderer->shouldReceive( 'generate_appointment_verification_pdf' )
			->andReturn( array( 'filename' => 'appt.pdf' ) );
		$this->renderer->shouldReceive( 'format_appointment_verification_response' )
			->andReturn( '<div>appt-ajax</div>' );

		$_POST = array( 'nonce' => 'ok', 'ffc_auth_code' => 'APPTAJAX1234' );

		try {
			$this->handler->handle_verification_ajax();
			$this->fail( 'expected halt' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'appt-ajax', $this->captured_success['html'] );
			$this->assertSame( array( 'filename' => 'appt.pdf' ), $this->captured_success['pdf_data'] );
		}
	}

	public function test_verification_ajax_reregistration_success(): void {
		global $wpdb;
		$submission_obj = (object) array(
			'id'                => '41',
			'reregistration_id' => '9',
			'user_id'           => '0',
			'auth_code'         => 'RRAJAX123456',
			'status'            => 'submitted',
			'data'              => '{"fields":{"display_name":"RR Ajax"}}',
			'magic_token'       => '',
			'submitted_at'      => '2026-02-01',
		);
		$rereg_obj = (object) array( 'id' => '9', 'title' => 'RR Ajax Campaign' );

		$wpdb = Mockery::mock( 'wpdb' )->makePartial();
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'Q' );
		// cert null, appointment null, rereg get_by_auth_code hit, rereg get_by_id.
		$wpdb->shouldReceive( 'get_row' )->andReturn( null, null, $submission_obj, $rereg_obj );
		$wpdb->shouldReceive( 'get_col' )->andReturn( array() );
		Functions\when( 'get_userdata' )->justReturn( null );

		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Mockery::mock( 'alias:\FreeFormCertificate\Core\SecurityService' )
			->shouldReceive( 'validate_security_fields' )->andReturn( true );
		$this->rate_limiter_allowed();

		Mockery::mock( 'overload:\FreeFormCertificate\Generators\PdfGenerator' );
		Mockery::mock( 'alias:\FreeFormCertificate\Reregistration\FichaGenerator' )
			->shouldReceive( 'generate_ficha_data' )->andReturn( array( 'filename' => 'ficha.pdf' ) );
		$this->renderer->shouldReceive( 'format_reregistration_verification_response' )
			->andReturn( '<div>rr-ajax</div>' );

		$_POST = array( 'nonce' => 'ok', 'ffc_auth_code' => 'RRAJAX123456' );

		try {
			$this->handler->handle_verification_ajax();
			$this->fail( 'expected halt' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'rr-ajax', $this->captured_success['html'] );
			$this->assertSame( array( 'filename' => 'ficha.pdf' ), $this->captured_success['pdf_data'] );
		}
	}

	// ==================== handle_magic_verification_ajax ====================

	public function test_magic_ajax_rate_limited(): void {
		$rl = Mockery::mock( 'alias:\FreeFormCertificate\Security\RateLimiter' );
		$rl->shouldReceive( 'check_verification' )->andReturn( array( 'allowed' => false ) );

		$_POST = array( 'token' => str_repeat( 'ab', 16 ) );

		try {
			$this->handler->handle_magic_verification_ajax();
			$this->fail( 'expected halt' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'Too many', $this->captured_error['message'] );
		}
	}

	public function test_magic_ajax_empty_token(): void {
		$this->rate_limiter_allowed();
		$_POST = array( 'token' => '' );

		try {
			$this->handler->handle_magic_verification_ajax();
			$this->fail( 'expected halt' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'Invalid token', $this->captured_error['message'] );
		}
	}

	public function test_magic_ajax_invalid_format_returns_not_found(): void {
		$this->rate_limiter_allowed();
		// Non-hex token of correct-ish length → verify_by_magic_token returns
		// invalid_token_format (found=false, error != rate_limited).
		Mockery::mock( 'alias:\FreeFormCertificate\Generators\MagicLinkHelper' )
			->shouldReceive( 'is_valid_token' )->andReturn( false );

		$_POST = array( 'token' => str_repeat( 'z', 32 ) );

		try {
			$this->handler->handle_magic_verification_ajax();
			$this->fail( 'expected halt' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'not found', $this->captured_error['message'] );
		}
	}

	public function test_magic_ajax_propagates_rate_limited_from_verify(): void {
		// check_verification allows the AJAX gate, but verify_by_magic_token's
		// own internal check returns rate_limited (error branch line 700-705).
		$rl = Mockery::mock( 'alias:\FreeFormCertificate\Security\RateLimiter' );
		// First call (AJAX gate) allowed; second (inside verify_by_magic_token) blocked.
		$rl->shouldReceive( 'check_verification' )->andReturn(
			array( 'allowed' => true ),
			array( 'allowed' => false )
		);

		$token = str_repeat( 'cd', 16 );
		$_POST = array( 'token' => $token );

		try {
			$this->handler->handle_magic_verification_ajax();
			$this->fail( 'expected halt' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( '1 minute', $this->captured_error['message'] );
		}
	}

	public function test_magic_ajax_success_certificate(): void {
		$this->rate_limiter_allowed();
		$token = str_repeat( 'ab', 16 );
		Mockery::mock( 'alias:\FreeFormCertificate\Generators\MagicLinkHelper' )
			->shouldReceive( 'is_valid_token' )->andReturn( true );
		Mockery::mock( 'alias:\FreeFormCertificate\Core\ActivityLog' )
			->shouldReceive( 'log_data_accessed' )->andReturnNull()->byDefault();

		$submission = array(
			'id'          => '12',
			'form_id'     => '3',
			'email'       => 'm@test.com',
			'cpf_rf'      => '',
			'auth_code'   => 'MAGI12345678',
			'data'        => '{}',
			'magic_token' => $token,
		);
		$this->submission_handler->shouldReceive( 'get_submission_by_token' )->andReturn( $submission );

		Mockery::mock( 'overload:\FreeFormCertificate\Generators\PdfGenerator' )
			->shouldReceive( 'generate_pdf_data' )->andReturn( array( 'filename' => 'm.pdf' ) );

		$this->renderer->shouldReceive( 'format_verification_response' )->andReturn( '<div>magic-cert</div>' );

		$_POST = array( 'token' => $token );

		try {
			$this->handler->handle_magic_verification_ajax();
			$this->fail( 'expected halt' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'magic-cert', $this->captured_success['html'] );
			$this->assertSame( array( 'filename' => 'm.pdf' ), $this->captured_success['pdf_data'] );
		}
	}
}
