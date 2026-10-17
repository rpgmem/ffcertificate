<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\AdminAjax;
use FreeFormCertificate\Tests\Support\CallLog;

/**
 * Tests for AdminAjax: constructor hook registration and the search_user
 * AJAX handler.
 *
 * @covers \FreeFormCertificate\Admin\AdminAjax
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AdminAjaxTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface Alias mock for Utils */
	private $utils_mock;
	private $caps_mock;

	/** @var Mockery\MockInterface Overload mock for WP_User_Query */
	private $user_query_mock;

	/** @var Mockery\MockInterface */
	private $wpdb;

	/** @var Mockery\MockInterface Alias mock for Encryption */
	private $encryption_mock;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Common WP function stubs
		Functions\when( '__' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'absint' )->alias( function ( $v ) {
			return abs( (int) $v );
		} );
		Functions\when( 'add_action' )->justReturn( true );

		// Stub wp_send_json_error to throw so we can detect "die" behavior
		Functions\when( 'wp_send_json_error' )->alias( function ( $data = null ) {
			$message = is_array( $data ) && isset( $data['message'] ) ? $data['message'] : 'wp_send_json_error';
			throw new \RuntimeException( 'wp_send_json_error: ' . $message );
		} );

		// Stub wp_send_json_success to throw so we can capture success responses
		Functions\when( 'wp_send_json_success' )->alias( function ( $data = null ) {
			throw new AdminAjaxSuccessException( $data );
		} );

		// Default stubs for nonce/permission (pass by default)
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );

		// Utils alias mock — required by check_ajax_permission for 'manage_options'
		$this->utils_mock = Mockery::mock( 'alias:\FreeFormCertificate\Core\Utils' );
		$this->caps_mock  = Mockery::mock( 'alias:\FreeFormCertificate\Core\Capabilities' );
		$ri_mock = Mockery::mock( 'alias:\FreeFormCertificate\Core\RequestInput' );
		$ri_mock->shouldReceive( 'get_get_key' )->andReturnUsing( static fn( $key, $default = '' ) => isset( $_GET[ $key ] ) ? (string) $_GET[ $key ] : $default );
		$ri_mock->shouldReceive( 'get_get_int' )->andReturnUsing( static fn( $key, $default = 0 ) => isset( $_GET[ $key ] ) ? (int) $_GET[ $key ] : $default );
		$ri_mock->shouldReceive( 'has_get' )->andReturnUsing( static fn( $key ) => isset( $_GET[ $key ] ) );
		$this->caps_mock->shouldReceive( 'current_user_can_manage' )->andReturn( true )->byDefault();
		$this->caps_mock->shouldReceive( 'current_user_can_admin_or' )->andReturn( true )->byDefault();
		$this->utils_mock->shouldReceive( 'debug_log' )->byDefault();
		$ri_mock->shouldReceive( 'get_post_string' )->andReturnUsing( function ( $key, $default = '' ) {
			return isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? $_POST[ $key ] : $default;
		} )->byDefault();
		$ri_mock->shouldReceive( 'get_post_int' )->andReturnUsing( function ( $key, $default = 0 ) {
			return isset( $_POST[ $key ] ) ? (int) $_POST[ $key ] : $default;
		} )->byDefault();

		// Encryption alias mock — needed by search_user_by_cpf and the
		// appointment reveal path (decrypt_appointment passes the row through).
		$encryption_mock       = Mockery::mock( 'alias:\FreeFormCertificate\Core\Encryption' );
		$this->encryption_mock = $encryption_mock;
		$encryption_mock->shouldReceive( 'hash' )->andReturn( 'hashed_value' )->byDefault();
		$encryption_mock->shouldReceive( 'decrypt_appointment' )->andReturnUsing(
			function ( $row ) {
				return $row;
			}
		)->byDefault();

		// WP_User_Query overload mock
		$this->user_query_mock = Mockery::mock( 'overload:\WP_User_Query' );
		$this->user_query_mock->shouldReceive( 'get_results' )->andReturn( array() )->byDefault();

		// wp_rand stub — returns varying values for code generation
		Functions\when( 'wp_rand' )->alias( function ( $min, $max ) {
			return mt_rand( $min, $max );
		} );

		// Default stubs
		Functions\when( 'get_post_meta' )->justReturn( array() );
		Functions\when( 'get_userdata' )->justReturn( false );
		Functions\when( 'get_avatar_url' )->justReturn( 'https://example.com/avatar.jpg' );
		// The user picker masks the addresses it lists (#1863).
		Functions\when( 'is_email' )->alias( static fn( $v ) => is_string( $v ) && false !== strpos( $v, '@' ) );
		// reveal_pii passes get_current_user_id() to ActivityLog::log — the arg
		// is evaluated even when the audit no-ops, so it must be defined.
		Functions\when( 'get_current_user_id' )->justReturn( 1 );

		// Global wpdb for search_user_by_cpf
		global $wpdb;
		$wpdb = Mockery::mock( 'wpdb' )->makePartial();
		$wpdb->prefix = 'wp_';
		$wpdb->last_error = '';
		$this->wpdb = $wpdb;
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'QUERY' )->byDefault();
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( null )->byDefault();
	}

	protected function tearDown(): void {
		unset(
			$_POST['nonce'],
			$_POST['quantity'],
			$_POST['form_id'],
			$_POST['search']
		);
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Helper: set up POST data for a valid search_user request.
	 */
	private function setup_valid_search_user( string $term = 'john' ): void {
		$_POST['nonce'] = 'valid_nonce';
		$_POST['search'] = $term;
	}

	// ==================================================================
	// Constructor
	// ==================================================================

	public function test_constructor_registers_ajax_hooks(): void {
		$registered = array();
		Functions\when( 'add_action' )->alias( function ( $hook, $callback ) use ( &$registered ) {
			$registered[] = $hook;
		} );

		$ajax = new AdminAjax();

		$this->assertContains( 'wp_ajax_ffc_search_user', $registered );
	}

	// ==================================================================
	// search_user()
	// ==================================================================

	public function test_search_user_denied_without_certificates_edit_cap(): void {
		// #739 escape closed: the user search backs the `ffc_edit_certificates`
		// submission-edit page, so it is gated on that cap (via admin-or), not
		// the trait's `manage_options` default. A caller lacking the cap and
		// manage_options is denied.
		$this->setup_valid_search_user( 'jane' );
		$this->caps_mock->shouldReceive( 'current_user_can_admin_or' )
			->with( 'ffc_edit_certificates' )->andReturn( false );

		$ajax = new AdminAjax();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Permission denied/' );

		$ajax->search_user();
	}

	public function test_search_user_sends_error_for_short_term(): void {
		$this->setup_valid_search_user( 'a' ); // Only 1 character

		$ajax = new AdminAjax();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/at least 2 characters/' );

		$ajax->search_user();
	}

	public function test_search_user_finds_by_numeric_id(): void {
		$this->setup_valid_search_user( '42' );

		$user = new \WP_User( 42 );
		$user->display_name = 'Found User';
		$user->user_email = 'found@example.com';

		Functions\when( 'get_userdata' )->alias( function ( $id ) use ( $user ) {
			if ( $id === 42 ) {
				return $user;
			}
			return false;
		} );

		$this->user_query_mock->shouldReceive( 'get_results' )->andReturn( array() );

		$ajax = new AdminAjax();

		try {
			$ajax->search_user();
			$this->fail( 'Expected AdminAjaxSuccessException to be thrown' );
		} catch ( AdminAjaxSuccessException $e ) {
			$data = $e->getData();
			$this->assertArrayHasKey( 'users', $data );
			$this->assertCount( 1, $data['users'] );
			$this->assertSame( 42, $data['users'][0]['id'] );
			$this->assertSame( 'Found User', $data['users'][0]['display_name'] );
		}
	}

	public function test_search_user_finds_by_name_email(): void {
		$this->setup_valid_search_user( 'jane' );

		$user = new \WP_User( 55 );
		$user->ID = 55;
		$user->display_name = 'Jane Doe';
		$user->user_email = 'jane@example.com';

		$this->user_query_mock->shouldReceive( 'get_results' )->andReturn( array( $user ) );

		$ajax = new AdminAjax();

		try {
			$ajax->search_user();
			$this->fail( 'Expected AdminAjaxSuccessException to be thrown' );
		} catch ( AdminAjaxSuccessException $e ) {
			$data = $e->getData();
			$this->assertArrayHasKey( 'users', $data );
			$this->assertCount( 1, $data['users'] );
			$this->assertSame( 55, $data['users'][0]['id'] );
			$this->assertSame( 'Jane Doe', $data['users'][0]['display_name'] );
			$this->assertSame( 'j***@example.com', $data['users'][0]['email'], 'The picker lists the address masked (#1863).' );
		}
	}

	public function test_search_user_returns_error_when_none_found(): void {
		$this->setup_valid_search_user( 'nonexistent' );

		// get_userdata returns false (not numeric search anyway)
		Functions\when( 'get_userdata' )->justReturn( false );
		// WP_User_Query returns empty
		$this->user_query_mock->shouldReceive( 'get_results' )->andReturn( array() );
		// CPF search also returns nothing (search term is not numeric enough)

		$ajax = new AdminAjax();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/No users found/' );

		$ajax->search_user();
	}

	public function test_search_user_falls_back_to_cpf_search(): void {
		// CPF with enough digits for the fallback search
		$this->setup_valid_search_user( '51817842080' );

		// get_userdata returns false (numeric but no WP user with that ID)
		Functions\when( 'get_userdata' )->justReturn( false );

		// WP_User_Query returns empty
		$this->user_query_mock->shouldReceive( 'get_results' )->andReturn( array() );

		// CPF search: wpdb returns a user_id, then get_userdata returns a user
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'QUERY' );
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( '77' );

		$cpf_user = new \WP_User( 77 );
		$cpf_user->ID = 77;
		$cpf_user->display_name = 'CPF User';
		$cpf_user->user_email = 'cpf@example.com';

		Functions\when( 'get_userdata' )->alias( function ( $id ) use ( $cpf_user ) {
			if ( $id === 77 ) {
				return $cpf_user;
			}
			return false;
		} );

		$ajax = new AdminAjax();

		try {
			$ajax->search_user();
			$this->fail( 'Expected AdminAjaxSuccessException to be thrown' );
		} catch ( AdminAjaxSuccessException $e ) {
			$data = $e->getData();
			$this->assertArrayHasKey( 'users', $data );
			$this->assertCount( 1, $data['users'] );
			$this->assertSame( 77, $data['users'][0]['id'] );
			$this->assertSame( 'CPF User', $data['users'][0]['display_name'] );
		}
	}

	// ==================================================================
	// reveal_pii (#739 §3.3)
	// ==================================================================

	/**
	 * Alias-mock Core\PiiAccessPolicy with its TIER_* constants mapped so the
	 * handler's production references resolve.
	 *
	 * @return \Mockery\MockInterface
	 */
	private function mock_pii_policy() {
		Mockery::getConfiguration()->setConstantsMap(
			array(
				'FreeFormCertificate\Core\PiiAccessPolicy' => array(
					'TIER_MASKED'   => 'masked',
					'TIER_REVEAL'   => 'reveal',
					'TIER_UNMASKED' => 'unmasked',
				),
			)
		);
		return Mockery::mock( 'alias:FreeFormCertificate\Core\PiiAccessPolicy' );
	}

	/**
	 * Overload-mock SubmissionHandler so get_submission returns a fixed row.
	 *
	 * @param array<string,mixed> $row Row fields.
	 * @return void
	 */
	private function mock_submission( array $row ): void {
		$handler = Mockery::mock( 'overload:\FreeFormCertificate\Submissions\SubmissionHandler' );
		$handler->shouldReceive( 'get_submission' )->andReturn( $row );
	}

	public function test_reveal_pii_rejects_unsupported_field(): void {
		$_POST['nonce']         = 'n';
		$_POST['submission_id'] = '5';
		$_POST['field']         = 'ssn';

		$ajax = new AdminAjax();
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Unsupported field/' );
		$ajax->reveal_pii();
	}

	public function test_reveal_pii_denied_for_masked_tier(): void {
		$_POST['nonce']         = 'n';
		$_POST['submission_id'] = '5';
		$_POST['field']         = 'cpf';

		$this->mock_submission(
			array(
				'cpf'     => '51817842080',
				'user_id' => 9,
			)
		);
		$policy = $this->mock_pii_policy();
		$policy->shouldReceive( 'resolve' )->andReturn( 'masked' );

		$ajax = new AdminAjax();
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/permission to reveal/' );
		$ajax->reveal_pii();
	}

	public function test_reveal_pii_cpf_success_formats_and_audits(): void {
		$_POST['nonce']         = 'n';
		$_POST['submission_id'] = '5';
		$_POST['field']         = 'cpf';

		$this->mock_submission(
			array(
				'cpf'     => '51817842080',
				'user_id' => 9,
			)
		);

		$policy = $this->mock_pii_policy();
		$policy->shouldReceive( 'resolve' )->andReturn( 'reveal' );

		$df = Mockery::mock( 'alias:FreeFormCertificate\Core\DocumentFormatter' );
		$df->shouldReceive( 'format_cpf' )->with( '51817842080' )->andReturn( '123.456.789-01' );

		// The reveal tier calls the real ActivityLog::log; keep the audit log
		// disabled so it early-returns without a DB write (its LEVEL_INFO
		// constant is genuinely defined this way, avoiding the alias-mock
		// constants-map fragility).
		Mockery::mock( 'alias:FreeFormCertificate\Settings\SettingsReader' )
			->shouldReceive( 'activity_log_enabled' )->once()->andReturn( false );

		$ajax = new AdminAjax();
		try {
			$ajax->reveal_pii();
			$this->fail( 'Expected AdminAjaxSuccessException' );
		} catch ( AdminAjaxSuccessException $e ) {
			$data = $e->getData();
			$this->assertSame(
				array(
					'field' => 'cpf',
					'value' => '123.456.789-01',
				),
				$data
			);
		}
	}

	public function test_reveal_pii_unmasked_tier_returns_value_and_audits(): void {
		$_POST['nonce']         = 'n';
		$_POST['submission_id'] = '5';
		$_POST['field']         = 'cpf';

		$this->mock_submission(
			array(
				'cpf'     => '51817842080',
				'user_id' => 9,
			)
		);

		$policy = $this->mock_pii_policy();
		$policy->shouldReceive( 'resolve' )->andReturn( 'unmasked' );

		$df = Mockery::mock( 'alias:FreeFormCertificate\Core\DocumentFormatter' );
		$df->shouldReceive( 'format_cpf' )->andReturn( '123.456.789-01' );

		// Every served reveal reaches the audit (#1655): the unmasked tier
		// calls here from the submission edit page and the appointment detail
		// page, which mask for it.
		// The log is kept switched off, so the call proves the path and
		// honours the switch without a DB write.
		Mockery::mock( 'alias:FreeFormCertificate\Settings\SettingsReader' )
			->shouldReceive( 'activity_log_enabled' )->once()->andReturn( false );

		$ajax = new AdminAjax();
		$this->expectException( AdminAjaxSuccessException::class );
		$ajax->reveal_pii();
	}

	public function test_reveal_pii_ip_returns_the_stored_ip_and_audits(): void {
		$_POST['nonce']         = 'n';
		$_POST['submission_id'] = '5';
		$_POST['field']         = 'ip';

		$this->mock_submission(
			array(
				'user_ip' => '203.0.113.7',
				'user_id' => 9,
			)
		);

		$policy = $this->mock_pii_policy();
		$policy->shouldReceive( 'resolve' )->andReturn( 'reveal' );

		// The IP reveal goes through the same audit as email and CPF (#1760).
		Mockery::mock( 'alias:FreeFormCertificate\Settings\SettingsReader' )
			->shouldReceive( 'activity_log_enabled' )->once()->andReturn( false );

		$ajax = new AdminAjax();
		try {
			$ajax->reveal_pii();
			$this->fail( 'Expected AdminAjaxSuccessException' );
		} catch ( AdminAjaxSuccessException $e ) {
			$this->assertSame(
				array(
					'field' => 'ip',
					'value' => '203.0.113.7',
				),
				$e->getData()
			);
		}
	}

	public function test_reveal_pii_ip_denied_for_masked_tier(): void {
		$_POST['nonce']         = 'n';
		$_POST['submission_id'] = '5';
		$_POST['field']         = 'ip';

		$this->mock_submission(
			array(
				'user_ip' => '203.0.113.7',
				'user_id' => 9,
			)
		);
		$policy = $this->mock_pii_policy();
		$policy->shouldReceive( 'resolve' )->andReturn( 'masked' );

		$ajax = new AdminAjax();
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/permission to reveal/' );
		$ajax->reveal_pii();
	}

	/**
	 * Overload-mock AppointmentRepository so findById returns a fixed row.
	 *
	 * @param array<string,mixed> $row Row fields.
	 * @return void
	 */
	private function mock_appointment( array $row ): void {
		$repo = Mockery::mock( 'overload:\FreeFormCertificate\Repositories\AppointmentRepository' );
		$repo->shouldReceive( 'findById' )->andReturn( $row );
	}

	public function test_reveal_pii_appointment_cpf_reveal_tier_returns_value(): void {
		$_POST['nonce']         = 'n';
		$_POST['type']          = 'appointment';
		$_POST['submission_id'] = '7';
		$_POST['field']         = 'cpf';

		$this->mock_appointment(
			array(
				'cpf'     => '51817842080',
				'user_id' => 3,
			)
		);

		$policy = $this->mock_pii_policy();
		$policy->shouldReceive( 'resolve' )->andReturn( 'reveal' );

		$df = Mockery::mock( 'alias:FreeFormCertificate\Core\DocumentFormatter' );
		$df->shouldReceive( 'format_cpf' )->with( '51817842080' )->andReturn( '123.456.789-01' );

		// Reveal tier audits via the real ActivityLog; keep the log disabled so
		// it early-returns without a DB write.
		Mockery::mock( 'alias:FreeFormCertificate\Settings\SettingsReader' )
			->shouldReceive( 'activity_log_enabled' )->andReturn( false );

		$ajax = new AdminAjax();
		try {
			$ajax->reveal_pii();
			$this->fail( 'Expected AdminAjaxSuccessException' );
		} catch ( AdminAjaxSuccessException $e ) {
			$this->assertSame(
				array(
					'field' => 'cpf',
					'value' => '123.456.789-01',
				),
				$e->getData()
			);
		}
	}

	public function test_reveal_pii_appointment_unmasked_tier_returns_value_and_audits(): void {
		$_POST['nonce']         = 'n';
		$_POST['type']          = 'appointment';
		$_POST['submission_id'] = '7';
		$_POST['field']         = 'email';

		$this->mock_appointment(
			array(
				'email'   => 'alice@example.com',
				'user_id' => 3,
			)
		);

		$policy = $this->mock_pii_policy();
		$policy->shouldReceive( 'resolve' )->andReturn( 'unmasked' );

		// The appointment detail page masks for the unmasked tier too (#1813),
		// so this call is how that tier sees the value, and it is audited.
		Mockery::mock( 'alias:FreeFormCertificate\Settings\SettingsReader' )
			->shouldReceive( 'activity_log_enabled' )->once()->andReturn( false );

		$ajax = new AdminAjax();
		try {
			$ajax->reveal_pii();
			$this->fail( 'Expected AdminAjaxSuccessException' );
		} catch ( AdminAjaxSuccessException $e ) {
			$this->assertSame(
				array(
					'field' => 'email',
					'value' => 'alice@example.com',
				),
				$e->getData()
			);
		}
	}

	public function test_reveal_pii_appointment_masked_tier_denied(): void {
		$_POST['nonce']         = 'n';
		$_POST['type']          = 'appointment';
		$_POST['submission_id'] = '7';
		$_POST['field']         = 'email';

		$this->mock_appointment(
			array(
				'email'   => 'x@y.com',
				'user_id' => 3,
			)
		);

		$policy = $this->mock_pii_policy();
		$policy->shouldReceive( 'resolve' )->andReturn( 'masked' );

		$ajax = new AdminAjax();
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/permission to reveal/' );
		$ajax->reveal_pii();
	}

	// ==================================================================
	// reveal_pii() — reregistration (#1815)
	// ==================================================================

	/**
	 * Alias-mock the reregistration submission reader so get_by_id returns a
	 * fixed row.
	 *
	 * @param object|null $row Submission row.
	 * @return void
	 */
	private function mock_reregistration_submission( ?object $row ): void {
		Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationSubmissionReader' )
			->shouldReceive( 'get_by_id' )->with( 11 )->andReturn( $row );
	}

	public function test_reveal_pii_reregistration_email_comes_from_the_account_and_audits(): void {
		$_POST['nonce']         = 'n';
		$_POST['type']          = 'reregistration';
		$_POST['submission_id'] = '11';
		$_POST['field']         = 'email';

		$this->mock_reregistration_submission(
			(object) array(
				'id'      => '11',
				'user_id' => '3',
				'data'    => null,
			)
		);
		Functions\when( 'get_userdata' )->alias(
			static fn ( $id ) => 3 === $id ? (object) array( 'user_email' => 'maria@example.org' ) : false
		);

		$policy = $this->mock_pii_policy();
		$policy->shouldReceive( 'resolve' )->once()
			->with( 'ffc_view_reregistration_pii', 3 )->andReturn( 'reveal' );

		// The audit runs through the real ActivityLog, switched off so it
		// returns before any write; `once()` is what proves it was reached.
		Mockery::mock( 'alias:FreeFormCertificate\Settings\SettingsReader' )
			->shouldReceive( 'activity_log_enabled' )->once()->andReturn( false );

		$ajax = new AdminAjax();
		try {
			$ajax->reveal_pii();
			$this->fail( 'Expected AdminAjaxSuccessException' );
		} catch ( AdminAjaxSuccessException $e ) {
			$this->assertSame(
				array(
					'field' => 'email',
					'value' => 'maria@example.org',
				),
				$e->getData()
			);
		}
	}

	public function test_reveal_pii_reregistration_decrypts_the_cpf_from_the_data_json(): void {
		$_POST['nonce']         = 'n';
		$_POST['type']          = 'reregistration';
		$_POST['submission_id'] = '11';
		$_POST['field']         = 'cpf';

		$this->mock_reregistration_submission(
			(object) array(
				'id'      => '11',
				'user_id' => '3',
				'data'    => (string) json_encode( array( 'fields' => array( 'cpf' => 'ENVELOPE' ) ) ),
			)
		);
		$this->encryption_mock->shouldReceive( 'looks_like_envelope' )->with( 'ENVELOPE' )->andReturn( true );
		$this->encryption_mock->shouldReceive( 'decrypt' )->once()->with( 'ENVELOPE' )->andReturn( '51817842080' );

		$this->mock_pii_policy()->shouldReceive( 'resolve' )->andReturn( 'reveal' );

		Mockery::mock( 'alias:FreeFormCertificate\Core\DocumentFormatter' )
			->shouldReceive( 'format_cpf' )->with( '51817842080' )->andReturn( '518.178.420-80' );
		Mockery::mock( 'alias:FreeFormCertificate\Settings\SettingsReader' )
			->shouldReceive( 'activity_log_enabled' )->once()->andReturn( false );

		$ajax = new AdminAjax();
		try {
			$ajax->reveal_pii();
			$this->fail( 'Expected AdminAjaxSuccessException' );
		} catch ( AdminAjaxSuccessException $e ) {
			$this->assertSame(
				array(
					'field' => 'cpf',
					'value' => '518.178.420-80',
				),
				$e->getData()
			);
		}
	}

	public function test_reveal_pii_reregistration_masked_tier_denied(): void {
		$_POST['nonce']         = 'n';
		$_POST['type']          = 'reregistration';
		$_POST['submission_id'] = '11';
		$_POST['field']         = 'email';

		$this->mock_reregistration_submission(
			(object) array(
				'id'      => '11',
				'user_id' => '3',
				'data'    => null,
			)
		);
		Functions\when( 'get_userdata' )->justReturn( (object) array( 'user_email' => 'maria@example.org' ) );
		$this->mock_pii_policy()->shouldReceive( 'resolve' )->andReturn( 'masked' );
		Mockery::mock( 'alias:FreeFormCertificate\Settings\SettingsReader' )
			->shouldReceive( 'activity_log_enabled' )->never();

		$ajax = new AdminAjax();
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/permission to reveal/' );
		$ajax->reveal_pii();
	}

	public function test_reveal_pii_reregistration_requires_the_surface_cap(): void {
		$_POST['nonce']         = 'n';
		$_POST['type']          = 'reregistration';
		$_POST['submission_id'] = '11';
		$_POST['field']         = 'email';

		$this->caps_mock->shouldReceive( 'current_user_can_admin_or' )
			->with( 'ffc_view_reregistration' )->andReturn( false );
		Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationSubmissionReader' )
			->shouldReceive( 'get_by_id' )->never();

		$ajax = new AdminAjax();
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/You do not have permission\./' );
		$ajax->reveal_pii();
	}

	public function test_reveal_pii_reregistration_reports_an_unknown_submission(): void {
		$_POST['nonce']         = 'n';
		$_POST['type']          = 'reregistration';
		$_POST['submission_id'] = '11';
		$_POST['field']         = 'email';

		$this->mock_reregistration_submission( null );

		$ajax = new AdminAjax();
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Record not found/' );
		$ajax->reveal_pii();
	}

	// ==================================================================
	// Account emails (#1824): the linked account of a submission and the
	// people on the audience screens.
	// ==================================================================

	/**
	 * Alias-mock the policy and ActivityLog together (one constants map
	 * serves both) and expect exactly one audit row for the reveal.
	 *
	 * @param string $action    Expected audit action.
	 * @param string $field     Expected field_key.
	 * @param int    $object_id Expected object id.
	 * @return \Mockery\MockInterface The policy mock.
	 */
	private function mock_policy_and_audit( string $action, string $field, int $object_id ) {
		Mockery::getConfiguration()->setConstantsMap(
			array(
				'FreeFormCertificate\Core\PiiAccessPolicy' => array(
					'TIER_MASKED'   => 'masked',
					'TIER_REVEAL'   => 'reveal',
					'TIER_UNMASKED' => 'unmasked',
				),
				'FreeFormCertificate\Core\ActivityLog'     => array(
					'LEVEL_INFO' => 'info',
				),
			)
		);
		Functions\when( 'get_current_user_id' )->justReturn( 4 );
		Mockery::mock( 'alias:FreeFormCertificate\Core\ActivityLog' )
			->shouldReceive( 'log' )
			->once()
			->with( $action, 'info', array( 'field_key' => $field ), 4, $object_id );
		return Mockery::mock( 'alias:FreeFormCertificate\Core\PiiAccessPolicy' );
	}

	public function test_reveal_pii_account_email_returns_the_linked_accounts_email_and_audits(): void {
		$_POST['nonce']         = 'n';
		$_POST['submission_id'] = '5';
		$_POST['field']         = 'account_email';

		$this->mock_submission(
			array(
				'email'   => 'typed@x.com',
				'user_id' => 9,
			)
		);
		Functions\when( 'get_userdata' )->alias(
			static fn( $id ) => 9 === $id ? (object) array( 'ID' => 9, 'user_email' => 'account@x.com' ) : false
		);

		$policy = $this->mock_policy_and_audit( 'submission_pii_revealed', 'account_email', 5 );
		// The submission's own gate, with its linked account as owner.
		$policy->shouldReceive( 'resolve' )
			->with( 'ffc_view_certificates_pii', 9 )
			->andReturn( 'reveal' );

		$ajax = new AdminAjax();
		try {
			$ajax->reveal_pii();
			$this->fail( 'Expected AdminAjaxSuccessException' );
		} catch ( AdminAjaxSuccessException $e ) {
			$this->assertSame(
				array(
					'field' => 'account_email',
					'value' => 'account@x.com',
				),
				$e->getData(),
				'the account email, not the email typed on the submission'
			);
		}
	}

	public function test_reveal_pii_account_email_denied_for_masked_tier(): void {
		$_POST['nonce']         = 'n';
		$_POST['submission_id'] = '5';
		$_POST['field']         = 'account_email';

		$this->mock_submission( array( 'user_id' => 9 ) );
		Functions\when( 'get_userdata' )->justReturn( (object) array( 'ID' => 9, 'user_email' => 'account@x.com' ) );
		$policy = $this->mock_pii_policy();
		$policy->shouldReceive( 'resolve' )->andReturn( 'masked' );

		$ajax = new AdminAjax();
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/permission to reveal/' );
		$ajax->reveal_pii();
	}

	public function test_reveal_pii_account_email_of_an_unlinked_submission_has_no_value(): void {
		$_POST['nonce']         = 'n';
		$_POST['submission_id'] = '5';
		$_POST['field']         = 'account_email';

		$this->mock_submission( array( 'email' => 'typed@x.com' ) );
		$policy = $this->mock_pii_policy();
		$policy->shouldReceive( 'resolve' )->andReturn( 'unmasked' );

		$ajax = new AdminAjax();
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/No value to reveal/' );
		$ajax->reveal_pii();
	}

	public function test_reveal_pii_account_email_is_a_submission_field_only(): void {
		$_POST['nonce']         = 'n';
		$_POST['type']          = 'appointment';
		$_POST['submission_id'] = '7';
		$_POST['field']         = 'account_email';

		$ajax = new AdminAjax();
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Unsupported field/' );
		$ajax->reveal_pii();
	}

	/**
	 * The listed-account check of the audience type.
	 *
	 * @param bool $listed Whether the account is on an audience screen.
	 * @return void
	 */
	private function mock_audience_listing( bool $listed ): void {
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceReader' )
			->shouldReceive( 'is_member_of_any' )->andReturn( $listed );
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceScheduleRepository' )
			->shouldReceive( 'has_any_permission' )->andReturn( false );
	}

	public function test_reveal_pii_audience_account_returns_the_email_and_audits_under_its_own_action(): void {
		$_POST['nonce']         = 'n';
		$_POST['type']          = 'audience_account';
		$_POST['submission_id'] = '7';
		$_POST['field']         = 'email';

		$this->caps_mock->shouldReceive( 'current_user_can_admin_or' )->with( 'ffc_view_audiences' )->once()->andReturn( true );
		$this->mock_audience_listing( true );
		Functions\when( 'get_userdata' )->justReturn( (object) array( 'ID' => 7, 'user_email' => 'member@x.com' ) );

		$policy = $this->mock_policy_and_audit( 'audience_account_email_revealed', 'email', 7 );
		// The audience module's own gate (#1863), with the listed account as owner.
		$policy->shouldReceive( 'resolve' )
			->with( 'ffc_view_audiences_pii', 7 )
			->andReturn( 'reveal' );

		$ajax = new AdminAjax();
		try {
			$ajax->reveal_pii();
			$this->fail( 'Expected AdminAjaxSuccessException' );
		} catch ( AdminAjaxSuccessException $e ) {
			$this->assertSame(
				array(
					'field' => 'email',
					'value' => 'member@x.com',
				),
				$e->getData()
			);
		}
	}

	public function test_reveal_pii_audience_account_denied_for_masked_tier(): void {
		$_POST['nonce']         = 'n';
		$_POST['type']          = 'audience_account';
		$_POST['submission_id'] = '7';
		$_POST['field']         = 'email';

		$this->mock_audience_listing( true );
		Functions\when( 'get_userdata' )->justReturn( (object) array( 'ID' => 7, 'user_email' => 'member@x.com' ) );
		$policy = $this->mock_pii_policy();
		$policy->shouldReceive( 'resolve' )->andReturn( 'masked' );

		$ajax = new AdminAjax();
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/permission to reveal/' );
		$ajax->reveal_pii();
	}

	public function test_reveal_pii_audience_account_refuses_an_account_no_audience_screen_lists(): void {
		$_POST['nonce']         = 'n';
		$_POST['type']          = 'audience_account';
		$_POST['submission_id'] = '1';
		$_POST['field']         = 'email';

		$this->mock_audience_listing( false );
		$log = CallLog::record( 'get_userdata', false );

		$ajax = new AdminAjax();
		try {
			$ajax->reveal_pii();
			$this->fail( 'Expected a 404' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'Record not found', $e->getMessage() );
		}
		$this->assertSame( 0, $log->count(), 'the account is not even loaded' );
	}

	public function test_reveal_pii_audience_account_serves_only_the_email(): void {
		$_POST['nonce']         = 'n';
		$_POST['type']          = 'audience_account';
		$_POST['submission_id'] = '7';
		$_POST['field']         = 'cpf';

		$ajax = new AdminAjax();
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Unsupported field/' );
		$ajax->reveal_pii();
	}

	// ==================================================================
	// reveal_pii_page (#1863)
	// ==================================================================

	/**
	 * The policy, and an ActivityLog that records every call.
	 *
	 * @param array<int, array<int, mixed>> $calls Receives each log() call.
	 * @return \Mockery\MockInterface The policy mock.
	 */
	private function mock_policy_and_page_audit( array &$calls ) {
		Mockery::getConfiguration()->setConstantsMap(
			array(
				'FreeFormCertificate\Core\PiiAccessPolicy' => array(
					'TIER_MASKED'   => 'masked',
					'TIER_REVEAL'   => 'reveal',
					'TIER_UNMASKED' => 'unmasked',
				),
				'FreeFormCertificate\Core\ActivityLog'     => array(
					'LEVEL_INFO' => 'info',
				),
			)
		);
		Functions\when( 'get_current_user_id' )->justReturn( 4 );
		Mockery::mock( 'alias:FreeFormCertificate\Core\ActivityLog' )
			->shouldReceive( 'log' )
			->andReturnUsing(
				static function ( ...$args ) use ( &$calls ) {
					$calls[] = $args;
					return true;
				}
			);
		return Mockery::mock( 'alias:FreeFormCertificate\Core\PiiAccessPolicy' );
	}

	/**
	 * Post a page reveal of these items.
	 *
	 * @param array<int, array<string, mixed>> $items Items.
	 * @return void
	 */
	private function post_page( array $items ): void {
		$_POST['nonce'] = 'n';
		$_POST['items'] = (string) wp_json_encode_for_test( $items );
	}

	public function test_reveal_pii_page_registers_its_action(): void {
		$registered = array();
		Functions\when( 'add_action' )->alias(
			static function ( $hook ) use ( &$registered ) {
				$registered[] = $hook;
				return true;
			}
		);

		new AdminAjax();

		$this->assertContains( 'wp_ajax_ffc_reveal_pii_page', $registered );
	}

	public function test_reveal_pii_page_reveals_every_allowed_value_with_one_audit_row(): void {
		$this->post_page(
			array(
				array( 'type' => 'submission', 'id' => 5, 'field' => 'email' ),
				array( 'type' => 'submission', 'id' => 6, 'field' => 'email' ),
			)
		);
		$handler = Mockery::mock( 'overload:\FreeFormCertificate\Submissions\SubmissionHandler' );
		$handler->shouldReceive( 'get_submission' )->andReturnUsing(
			static fn( $id ) => array(
				'email'   => 'p' . $id . '@x.com',
				'user_id' => 90 + $id,
			)
		);

		$calls  = array();
		$policy = $this->mock_policy_and_page_audit( $calls );
		$policy->shouldReceive( 'resolve' )->with( 'ffc_view_certificates_pii', Mockery::any() )->andReturn( 'reveal' );

		$ajax = new AdminAjax();
		try {
			$ajax->reveal_pii_page();
			$this->fail( 'Expected AdminAjaxSuccessException' );
		} catch ( AdminAjaxSuccessException $e ) {
			$this->assertSame(
				array(
					'values' => array(
						array( 'type' => 'submission', 'id' => 5, 'field' => 'email', 'value' => 'p5@x.com' ),
						array( 'type' => 'submission', 'id' => 6, 'field' => 'email', 'value' => 'p6@x.com' ),
					),
				),
				$e->getData()
			);
		}

		$this->assertSame(
			array(
				array(
					'submission_pii_revealed',
					'info',
					array(
						'field_key'  => 'page',
						'fields'     => array( 'email' ),
						'record_ids' => array( 5, 6 ),
					),
					4,
					0,
				),
			),
			$calls,
			'One audit row for the page, naming every record.'
		);
	}

	public function test_reveal_pii_page_skips_what_the_policy_refuses_and_logs_one_row_per_domain(): void {
		$this->post_page(
			array(
				array( 'type' => 'submission', 'id' => 5, 'field' => 'email' ),
				array( 'type' => 'audience_account', 'id' => 7, 'field' => 'email' ),
				array( 'type' => 'submission', 'id' => 6, 'field' => 'ssn' ),
				array( 'type' => 'nope', 'id' => 1, 'field' => 'email' ),
			)
		);
		$this->mock_submission(
			array(
				'email'   => 'p@x.com',
				'user_id' => 9,
			)
		);
		$this->mock_audience_listing( true );
		Functions\when( 'get_userdata' )->justReturn( (object) array( 'ID' => 7, 'user_email' => 'member@x.com' ) );

		$calls  = array();
		$policy = $this->mock_policy_and_page_audit( $calls );
		$policy->shouldReceive( 'resolve' )->with( 'ffc_view_certificates_pii', 9 )->andReturn( 'reveal' );
		$policy->shouldReceive( 'resolve' )->with( 'ffc_view_audiences_pii', 7 )->andReturn( 'masked' );

		$ajax = new AdminAjax();
		try {
			$ajax->reveal_pii_page();
			$this->fail( 'Expected AdminAjaxSuccessException' );
		} catch ( AdminAjaxSuccessException $e ) {
			$data = $e->getData();
			$this->assertCount( 1, $data['values'], 'The audience email is masked for this user, the field and type unknown.' );
			$this->assertSame( 'p@x.com', $data['values'][0]['value'] );
		}

		$this->assertCount( 1, $calls );
		$this->assertSame( 'submission_pii_revealed', $calls[0][0] );
	}

	public function test_reveal_pii_page_skips_a_domain_whose_surface_cap_is_missing(): void {
		$this->post_page( array( array( 'type' => 'submission', 'id' => 5, 'field' => 'email' ) ) );
		$this->caps_mock->shouldReceive( 'current_user_can_admin_or' )->with( 'ffc_view_certificates' )->andReturn( false );
		$log = CallLog::record( 'get_userdata', false );

		$ajax = new AdminAjax();
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/permission to reveal these values/' );
		try {
			$ajax->reveal_pii_page();
		} finally {
			$this->assertSame( 0, $log->count() );
		}
	}

	public function test_reveal_pii_page_refuses_an_empty_request(): void {
		$_POST['nonce'] = 'n';
		$_POST['items'] = 'not json';

		$ajax = new AdminAjax();
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Nothing to reveal/' );
		$ajax->reveal_pii_page();
	}

	public function test_reveal_pii_page_refuses_more_than_a_page(): void {
		$this->post_page( array_fill( 0, 501, array( 'type' => 'submission', 'id' => 5, 'field' => 'email' ) ) );

		$ajax = new AdminAjax();
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Too many values/' );
		$ajax->reveal_pii_page();
	}

	public function test_reveal_pii_page_checks_the_nonce(): void {
		$this->post_page( array( array( 'type' => 'submission', 'id' => 5, 'field' => 'email' ) ) );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$ajax = new AdminAjax();
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Security check failed/' );
		$ajax->reveal_pii_page();
	}

	// ==================================================================
	// New reveal types (#1863)
	// ==================================================================

	public function test_reveal_pii_user_profile_serves_a_sensitive_field_to_whoever_may_edit_the_user(): void {
		$_POST['nonce']         = 'n';
		$_POST['type']          = 'user_profile';
		$_POST['submission_id'] = '12';
		$_POST['field']         = 'cf_3';

		Functions\when( 'current_user_can' )->alias( static fn( $cap, $id = null ) => 'edit_user' === $cap && 12 === $id );
		Functions\when( 'get_userdata' )->justReturn( (object) array( 'ID' => 12 ) );
		Functions\when( 'get_current_user_id' )->justReturn( 4 );
		Mockery::getConfiguration()->setConstantsMap(
			array(
				'FreeFormCertificate\Admin\AdminUserCustomFields' => array(
					'REVEAL_TYPE' => 'user_profile',
					'PII_CAP'     => 'ffc_view_audiences_pii',
				),
				'FreeFormCertificate\Core\PiiAccessPolicy'        => array(
					'TIER_MASKED'   => 'masked',
					'TIER_REVEAL'   => 'reveal',
					'TIER_UNMASKED' => 'unmasked',
				),
				'FreeFormCertificate\Core\ActivityLog'            => array(
					'LEVEL_INFO' => 'info',
				),
			)
		);
		Mockery::mock( 'alias:FreeFormCertificate\Admin\AdminUserCustomFields' )
			->shouldReceive( 'sensitive_values' )->with( 12 )->andReturn( array( 3 => '5181780' ) );
		Mockery::mock( 'alias:FreeFormCertificate\Core\ActivityLog' )
			->shouldReceive( 'log' )->once()
			->with( 'user_profile_pii_revealed', 'info', array( 'field_key' => 'cf_3' ), 4, 12 );
		Mockery::mock( 'alias:FreeFormCertificate\Core\PiiAccessPolicy' )
			->shouldReceive( 'resolve' )->with( 'ffc_view_audiences_pii', 12 )->andReturn( 'reveal' );

		$ajax = new AdminAjax();
		try {
			$ajax->reveal_pii();
			$this->fail( 'Expected AdminAjaxSuccessException' );
		} catch ( AdminAjaxSuccessException $e ) {
			$this->assertSame(
				array(
					'field' => 'cf_3',
					'value' => '5181780',
				),
				$e->getData()
			);
		}
	}

	public function test_reveal_pii_user_profile_refuses_a_user_the_viewer_cannot_edit(): void {
		$_POST['nonce']         = 'n';
		$_POST['type']          = 'user_profile';
		$_POST['submission_id'] = '12';
		$_POST['field']         = 'cf_3';

		Functions\when( 'current_user_can' )->alias( static fn( $cap ) => 'edit_user' !== $cap );

		$ajax = new AdminAjax();
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Record not found/' );
		$ajax->reveal_pii();
	}

	public function test_reveal_pii_user_profile_serves_only_custom_field_keys(): void {
		$_POST['nonce']         = 'n';
		$_POST['type']          = 'user_profile';
		$_POST['submission_id'] = '12';
		$_POST['field']         = 'email';

		$ajax = new AdminAjax();
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Unsupported field/' );
		$ajax->reveal_pii();
	}

	public function test_reveal_pii_date_messages_person_serves_the_name_of_a_listed_person(): void {
		$_POST['nonce']         = 'n';
		$_POST['type']          = 'date_messages_person';
		$_POST['submission_id'] = '15';
		$_POST['field']         = 'name';

		Functions\when( 'get_userdata' )->justReturn( (object) array( 'ID' => 15, 'display_name' => 'Maria da Silva' ) );
		Mockery::getConfiguration()->setConstantsMap(
			array(
				'FreeFormCertificate\DateMessages\DateMessagesAdminPage' => array(
					'REVEAL_TYPE' => 'date_messages_person',
					'VIEW_CAP'    => 'ffc_view_date_messages',
					'PII_CAP'     => 'ffc_view_date_messages_pii',
				),
				'FreeFormCertificate\Core\PiiAccessPolicy'               => array(
					'TIER_MASKED'   => 'masked',
					'TIER_REVEAL'   => 'reveal',
					'TIER_UNMASKED' => 'unmasked',
				),
				'FreeFormCertificate\Core\ActivityLog'                   => array(
					'LEVEL_INFO' => 'info',
				),
			)
		);
		Mockery::mock( 'alias:FreeFormCertificate\DateMessages\DateMessagesAdminPage' )
			->shouldReceive( 'is_listed' )->with( 15 )->andReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 4 );
		Mockery::mock( 'alias:FreeFormCertificate\Core\ActivityLog' )
			->shouldReceive( 'log' )->once()
			->with( 'date_messages_pii_revealed', 'info', array( 'field_key' => 'name' ), 4, 15 );
		Mockery::mock( 'alias:FreeFormCertificate\Core\PiiAccessPolicy' )
			->shouldReceive( 'resolve' )->with( 'ffc_view_date_messages_pii', 15 )->andReturn( 'reveal' );

		$ajax = new AdminAjax();
		try {
			$ajax->reveal_pii();
			$this->fail( 'Expected AdminAjaxSuccessException' );
		} catch ( AdminAjaxSuccessException $e ) {
			$this->assertSame( 'Maria da Silva', $e->getData()['value'] );
		}
	}

	public function test_reveal_pii_date_messages_person_refuses_someone_the_panel_cannot_list(): void {
		$_POST['nonce']         = 'n';
		$_POST['type']          = 'date_messages_person';
		$_POST['submission_id'] = '15';
		$_POST['field']         = 'name';

		Functions\when( 'get_userdata' )->justReturn( (object) array( 'ID' => 15, 'display_name' => 'Maria' ) );
		Mockery::getConfiguration()->setConstantsMap(
			array(
				'FreeFormCertificate\DateMessages\DateMessagesAdminPage' => array(
					'REVEAL_TYPE' => 'date_messages_person',
					'VIEW_CAP'    => 'ffc_view_date_messages',
					'PII_CAP'     => 'ffc_view_date_messages_pii',
				),
			)
		);
		Mockery::mock( 'alias:FreeFormCertificate\DateMessages\DateMessagesAdminPage' )
			->shouldReceive( 'is_listed' )->with( 15 )->andReturn( false );

		$ajax = new AdminAjax();
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Record not found/' );
		$ajax->reveal_pii();
	}
}

/**
 * Custom exception to capture wp_send_json_success data in tests.
 */
class AdminAjaxSuccessException extends \RuntimeException {

	/** @var mixed */
	private $data;

	/**
	 * @param mixed $data The success response data.
	 */
	public function __construct( $data = null ) {
		$this->data = $data;
		parent::__construct( 'wp_send_json_success' );
	}

	/**
	 * @return mixed
	 */
	public function getData() {
		return $this->data;
	}
}

/**
 * JSON for a posted field, without WordPress.
 *
 * @param mixed $value Value.
 * @return string
 */
function wp_json_encode_for_test( $value ): string {
	return (string) json_encode( $value );
}
