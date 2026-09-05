<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Frontend\DynamicFragments;

/**
 * Tests for DynamicFragments: AJAX endpoint that returns fresh captcha/nonce data
 * for pages served from full-page cache.
 *
 * @covers \FreeFormCertificate\Frontend\DynamicFragments
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class DynamicFragmentsTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<int, array{type: string, data: mixed}> Captured JSON responses */
	private array $json_responses = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'add_action' )->justReturn( true );

		$this->json_responses = array();
		$responses = &$this->json_responses;

		Functions\when( 'wp_send_json_success' )->alias( function ( $data = null ) use ( &$responses ) {
			$responses[] = array( 'type' => 'success', 'data' => $data );
			throw new \RuntimeException( 'wp_send_json_success' );
		} );

		Functions\when( 'wp_send_json_error' )->alias( function ( $data = null ) use ( &$responses ) {
			$responses[] = array( 'type' => 'error', 'data' => $data );
			throw new \RuntimeException( 'wp_send_json_error' );
		} );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Helper: call handle() and catch the RuntimeException from JSON mock.
	 */
	private function callHandle( DynamicFragments $fragments ): void {
		try {
			$fragments->handle();
		} catch ( \RuntimeException $e ) {
			// Expected
		}
	}

	/**
	 * Alias-mock the captcha resolver so the endpoint's own wiring is what is
	 * under test.
	 *
	 * The endpoint must not know what a challenge looks like — since #1053 PR2
	 * it forwards whatever the configured strategy issues, so the assertions
	 * below use a payload no real provider emits. Mocking
	 * `SecurityService::generate_simple_captcha()` instead would pass just as
	 * well with the endpoint calling the math challenge directly, which is
	 * exactly the bypass this replaces.
	 *
	 * @param array<string, mixed> ...$payloads One per expected call, in order.
	 */
	private function mockProvider( array ...$payloads ): void {
		$provider    = Mockery::mock( '\FreeFormCertificate\Core\Captcha\CaptchaProviderInterface' );
		$expectation = $provider->shouldReceive( 'challenge_payload' );

		if ( 1 === count( $payloads ) ) {
			$expectation->andReturn( $payloads[0] );
		} else {
			$expectation->andReturnValues( $payloads );
		}

		$resolver = Mockery::mock( 'alias:\FreeFormCertificate\Core\Captcha\CaptchaProvider' );
		$resolver->shouldReceive( 'resolve' )->andReturn( $provider );
	}

	// ==================================================================
	// Constructor
	// ==================================================================

	public function test_constructor_registers_ajax_hooks(): void {
		// add_action is stubbed in setUp — verify constructor completes
		$fragments = new DynamicFragments();
		$this->assertInstanceOf( DynamicFragments::class, $fragments );
	}

	// ==================================================================
	// handle() — anonymous user
	// ==================================================================

	public function test_handle_returns_captcha_and_nonces_for_anonymous(): void {
		$this->mockProvider( array( 'provider' => 'math', 'new_label' => '3 + 4', 'new_hash' => 'abc123' ) );

		Functions\when( 'wp_create_nonce' )->alias( function ( $action ) {
			return 'nonce_' . $action;
		} );
		Functions\when( 'is_user_logged_in' )->justReturn( false );

		$fragments = new DynamicFragments();
		$this->callHandle( $fragments );

		$this->assertCount( 1, $this->json_responses );
		$data = $this->json_responses[0]['data'];
		$this->assertSame( 'success', $this->json_responses[0]['type'] );

		// Captcha
		$this->assertSame(
			array( 'provider' => 'math', 'new_label' => '3 + 4', 'new_hash' => 'abc123' ),
			$data['captcha'],
			'The provider payload must be forwarded verbatim — the endpoint does not reshape it.'
		);

		// Nonces
		$this->assertSame( 'nonce_ffc_frontend_nonce', $data['nonces']['ffc_frontend_nonce'] );
		$this->assertSame( 'nonce_ffc_self_scheduling_nonce', $data['nonces']['ffc_self_scheduling_nonce'] );

		// No user data for anonymous
		$this->assertArrayNotHasKey( 'user', $data );
	}

	// ==================================================================
	// handle() — per-form challenges
	// ==================================================================

	public function test_handle_issues_one_challenge_per_form_when_several_are_posted(): void {
		// Two forms on one page must never share a token (#1056), so the
		// endpoint asks the provider once per form and keys the answers by
		// form id. Distinct payloads here are what prove they are separate
		// calls rather than one reused value.
		$this->mockProvider(
			array( 'provider' => 'math', 'new_label' => 'a', 'new_hash' => 'a-hash' ),
			array( 'provider' => 'math', 'new_label' => 'b', 'new_hash' => 'b-hash' ),
			array( 'provider' => 'math', 'new_label' => 'c', 'new_hash' => 'c-hash' )
		);

		Functions\when( 'wp_create_nonce' )->justReturn( 'n' );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
		Functions\when( 'wp_unslash' )->returnArg();
		// The same `form_ids` also drive the geofence branch further down.
		Functions\when( 'get_post_meta' )->justReturn( '' );

		$_POST['form_ids'] = array( '7', '8' );

		$fragments = new DynamicFragments();
		$this->callHandle( $fragments );

		unset( $_POST['form_ids'] );

		$data = $this->json_responses[0]['data'];

		$this->assertArrayHasKey( 'captchas', $data );
		$this->assertSame( array( 7, 8 ), array_keys( $data['captchas'] ) );
		$this->assertNotSame(
			$data['captchas'][7]['new_hash'],
			$data['captchas'][8]['new_hash'],
			'Each form must get its own token, or the second form submits one the first already spent.'
		);
	}

	public function test_handle_omits_per_form_challenges_for_a_single_form(): void {
		// One form needs no per-form branch — the default challenge already
		// lands on it, and a `captchas` map of one would be a second token
		// for the same block.
		$this->mockProvider( array( 'provider' => 'math', 'new_label' => 'a', 'new_hash' => 'a-hash' ) );

		Functions\when( 'wp_create_nonce' )->justReturn( 'n' );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
		Functions\when( 'wp_unslash' )->returnArg();
		// The same `form_ids` also drive the geofence branch further down.
		Functions\when( 'get_post_meta' )->justReturn( '' );

		$_POST['form_ids'] = array( '7' );

		$fragments = new DynamicFragments();
		$this->callHandle( $fragments );

		unset( $_POST['form_ids'] );

		$this->assertArrayNotHasKey( 'captchas', $this->json_responses[0]['data'] );
	}

	// ==================================================================
	// handle() — logged-in user
	// ==================================================================

	public function test_handle_includes_user_data_when_logged_in(): void {
		$this->mockProvider( array( 'provider' => 'math', 'new_label' => '5 + 2', 'new_hash' => 'def456' ) );

		Functions\when( 'wp_create_nonce' )->justReturn( 'fresh_nonce' );
		Functions\when( 'is_user_logged_in' )->justReturn( true );

		$user = (object) array(
			'display_name' => 'Maria Silva',
			'user_email'   => 'maria@example.com',
		);
		Functions\when( 'wp_get_current_user' )->justReturn( $user );

		$fragments = new DynamicFragments();
		$this->callHandle( $fragments );

		$data = $this->json_responses[0]['data'];

		// User data included
		$this->assertArrayHasKey( 'user', $data );
		$this->assertSame( 'Maria Silva', $data['user']['name'] );
		$this->assertSame( 'maria@example.com', $data['user']['email'] );
	}

	// ==================================================================
	// handle() — captcha data is always present
	// ==================================================================

	public function test_handle_always_returns_both_nonce_keys(): void {
		$this->mockProvider( array( 'provider' => 'math', 'new_label' => '1 + 1', 'new_hash' => 'h' ) );

		Functions\when( 'wp_create_nonce' )->justReturn( 'n' );
		Functions\when( 'is_user_logged_in' )->justReturn( false );

		$fragments = new DynamicFragments();
		$this->callHandle( $fragments );

		$nonces = $this->json_responses[0]['data']['nonces'];
		$this->assertArrayHasKey( 'ffc_frontend_nonce', $nonces );
		$this->assertArrayHasKey( 'ffc_self_scheduling_nonce', $nonces );
	}

	// ==================================================================
	// handle() — public CSV download nonce
	// ==================================================================

	public function test_handle_includes_public_csv_download_nonce(): void {
		$this->mockProvider( array( 'provider' => 'math', 'new_label' => 'x', 'new_hash' => 'y' ) );

		Functions\when( 'wp_create_nonce' )->alias( function ( $action ) {
			return 'nonce_' . $action;
		} );
		Functions\when( 'is_user_logged_in' )->justReturn( false );

		$fragments = new DynamicFragments();
		$this->callHandle( $fragments );

		$nonces = $this->json_responses[0]['data']['nonces'];
		$this->assertArrayHasKey( 'ffc_public_csv_download', $nonces );
		$this->assertSame( 'nonce_ffc_public_csv_download', $nonces['ffc_public_csv_download'] );
	}

	// ==================================================================
	// handle() — ffc_audience nonces (wp_rest + ffc_search_users)
	// ==================================================================

	public function test_handle_includes_audience_nonces(): void {
		$this->mockProvider( array( 'provider' => 'math', 'new_label' => 'x', 'new_hash' => 'y' ) );

		Functions\when( 'wp_create_nonce' )->alias( function ( $action ) {
			return 'nonce_' . $action;
		} );
		Functions\when( 'is_user_logged_in' )->justReturn( false );

		$fragments = new DynamicFragments();
		$this->callHandle( $fragments );

		$nonces = $this->json_responses[0]['data']['nonces'];
		$this->assertArrayHasKey( 'wp_rest', $nonces );
		$this->assertArrayHasKey( 'ffc_search_users', $nonces );
		$this->assertSame( 'nonce_wp_rest', $nonces['wp_rest'] );
		$this->assertSame( 'nonce_ffc_search_users', $nonces['ffc_search_users'] );
	}
}
