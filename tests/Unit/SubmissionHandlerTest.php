<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Submissions\SubmissionHandler;
use FreeFormCertificate\Core\Encryption;

/**
 * Tests for SubmissionHandler: submission creation, encryption flow,
 * ticket_hash population, token handling, bulk operations.
 *
 * Note: WP crypto constants are defined in bootstrap.php, so
 * Encryption::is_configured() returns true — the encryption path
 * is always active.
 * @runClassInSeparateProcess
 * @preserveGlobalState disabled
 * @covers \FreeFormCertificate\Submissions\SubmissionHandler
 * @covers \FreeFormCertificate\Submissions\SubmissionLifecycleService
 */
class SubmissionHandlerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private SubmissionHandler $handler;
	private $mockRepo;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// pcov does not record lines for files first autoloaded mid-test-method,
		// so the handler + lifecycle service coverage would attribute to nothing.
		// Preload both classes here so pcov attributes their lines to this test.
		class_exists( '\\FreeFormCertificate\\Submissions\\SubmissionHandler' );
		class_exists( '\\FreeFormCertificate\\Submissions\\SubmissionLifecycleService' );

		// Mock global $wpdb
		global $wpdb;
		$wpdb = Mockery::mock( 'wpdb' )->makePartial();
		$wpdb->prefix = 'wp_';
		$wpdb->last_error = '';
		// Allow auth-code uniqueness check & orphan-linking calls
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'QUERY' );
		$wpdb->shouldReceive( 'get_var' )->andReturn( null );
		$wpdb->shouldReceive( 'query' )->andReturn( 0 );
		$wpdb->shouldReceive( 'get_results' )->andReturn( array() );

		// Common WP stubs
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		// Reached since 6.26.0: the certificate path resolves through the DUAL
		// entry point, which feeds `ffc_user_profiles` and so invalidates that
		// repository's cache group (#1313 PR 7).
		Functions\when( 'wp_cache_flush' )->justReturn( true );
		Functions\when( 'wp_cache_add_global_groups' )->justReturn( null );
		Functions\when( 'current_time' )->justReturn( '2026-02-17 12:00:00' );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( '__' )->returnArg();
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'get_option' )->justReturn( 0 );
		Functions\when( 'absint' )->alias( function( $val ) { return abs( intval( $val ) ); } );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->alias( function( $key ) { return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', $key ) ); } );
		Functions\when( 'wp_rand' )->alias( function( $min = 0, $max = null ) { return rand( $min, $max ?? getrandmax() ); } );
		Functions\when( 'is_wp_error' )->alias( function( $thing ) { return $thing instanceof \WP_Error; } );
		Functions\when( 'apply_filters' )->alias( function() { $args = func_get_args(); return $args[1] ?? null; } );
		Functions\when( 'get_user_by' )->justReturn( false );
		Functions\when( 'get_userdata' )->justReturn( null );
		Functions\when( 'wp_generate_password' )->justReturn( 'TestPass123!' );
		Functions\when( 'sanitize_user' )->returnArg();
		Functions\when( 'remove_accents' )->returnArg();
		Functions\when( 'username_exists' )->justReturn( false );
		Functions\when( 'wp_create_user' )->justReturn( 100 );
		Functions\when( 'wp_update_user' )->justReturn( 100 );
		Functions\when( 'update_user_meta' )->justReturn( true );
		Functions\when( 'wp_new_user_notification' )->justReturn( null );

		// Namespaced stubs: prevent "is not defined" errors when Sprint 27 tests run first.
		// Core namespace (Debug, ActivityLog use get_option/get_current_user_id).
		Functions\when( 'FreeFormCertificate\Core\get_option' )->alias( function ( $key, $default = false ) {
			return \get_option( $key, $default );
		} );
		Functions\when( 'FreeFormCertificate\Core\get_current_user_id' )->alias( function () {
			return \get_current_user_id();
		} );

		$this->handler = new SubmissionHandler();

		// Replace the private repository with a mock via reflection
		$this->mockRepo = Mockery::mock( 'FreeFormCertificate\Repositories\SubmissionRepository' );
		$ref = new \ReflectionClass( $this->handler );
		$prop = $ref->getProperty( 'repository' );
		$prop->setAccessible( true );
		$prop->setValue( $this->handler, $this->mockRepo );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// get_repository()
	// ------------------------------------------------------------------

	public function test_get_repository_returns_repository_instance(): void {
		$repo = $this->handler->get_repository();
		$this->assertSame( $this->mockRepo, $repo );
	}

	// ------------------------------------------------------------------
	// process_submission() - basic flow
	// ------------------------------------------------------------------

	public function test_process_submission_returns_id_on_success(): void {
		$this->mockRepo->shouldReceive( 'insert' )
			->once()
			->andReturn( 42 );

		$data = array( 'name' => 'Test User', 'email' => 'test@example.com' );
		$result = $this->handler->process_submission(
			1,
			'Test Form',
			$data,
			'test@example.com',
			array(),
			array()
		);

		$this->assertSame( 42, $result );
	}

	/**
	 * The schedule carries ONE integer, and the internal hook (#1248).
	 *
	 * Eight arguments used to travel, among them the whole `$submission_data`.
	 * The schedule lives in the `cron` option, which is autoloaded: while the
	 * event was pending, every request to the site would load and unserialize
	 * that.
	 */
	public function test_process_submission_schedules_only_the_submission_id(): void {
		$this->mockRepo->shouldReceive( 'insert' )->once()->andReturn( 77 );

		$captured = null;
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( $ts, $hook, $args ) use ( &$captured ) {
				$captured = array( 'hook' => $hook, 'args' => $args );
				return true;
			}
		);

		$data = array( 'name' => 'Test User', 'email' => 'test@example.com' );
		$this->handler->process_submission( 3, 'Test Form', $data, 'test@example.com', array(), array( 'send_user_email' => '1' ) );

		$this->assertNotNull( $captured, 'the async email/notification hook must be scheduled' );
		$this->assertSame( SubmissionHandler::ASYNC_PIPELINE_HOOK, $captured['hook'] );
		$this->assertSame( array( 77 ), $captured['args'], 'The payload is the id, and nothing else.' );
	}

	/**
	 * The payload carries neither PII nor the certificate's access credential.
	 *
	 * It is the half of #1248 that is not about performance. Twenty lines before
	 * this scheduling, `save_submission()` itself ENCRYPTS email, CPF, RF and the
	 * extra data to store them; the old payload wrote the originals in plaintext
	 * into `wp_options`, next to the `magic_token` -- which is the whole
	 * authentication behind `?token=...`.
	 *
	 * The assertion is about the SERIALISED form on purpose: that is how the
	 * value reaches the database, and a substring search finds the data wherever
	 * it is nested.
	 */
	public function test_the_scheduled_payload_carries_neither_pii_nor_the_magic_token(): void {
		$this->mockRepo->shouldReceive( 'insert' )->once()->andReturn( 77 );

		$captured = null;
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( $ts, $hook, $args ) use ( &$captured ) {
				$captured = $args;
				return true;
			}
		);

		$data = array(
			'name'   => 'John Doe',
			'cpf_rf' => '123.456.789-00',
			'ticket' => 'ABC123',
		);
		$this->handler->process_submission( 3, 'Test Form', $data, 'secret@example.com', array(), array( 'send_user_email' => '1' ) );

		$serialized = serialize( $captured );

		$this->assertStringNotContainsString( 'secret@example.com', $serialized );
		$this->assertStringNotContainsString( '123.456.789-00', $serialized );
		$this->assertStringNotContainsString( '12345678900', $serialized );
		$this->assertStringNotContainsString( 'John Doe', $serialized );
		$this->assertStringNotContainsString( 'ABC123', $serialized );
		$this->assertDoesNotMatchRegularExpression(
			'/[0-9a-f]{32}/',
			$serialized,
			'The magic_token is 32 hex characters; no value of that shape may be in the payload.'
		);
	}

	public function test_process_submission_returns_wp_error_on_insert_failure(): void {
		$this->mockRepo->shouldReceive( 'insert' )
			->once()
			->andReturn( false );

		$data = array( 'name' => 'Test User' );
		$result = $this->handler->process_submission(
			1,
			'Test Form',
			$data,
			'test@example.com',
			array(),
			array()
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_process_submission_insert_data_includes_core_fields(): void {
		$captured = null;
		$this->mockRepo->shouldReceive( 'insert' )
			->once()
			->withArgs( function( $data ) use ( &$captured ) {
				$captured = $data;
				return true;
			} )
			->andReturn( 10 );

		$data = array( 'name' => 'Alice', 'email' => 'alice@test.com', 'cpf_rf' => '12345678901' );
		$this->handler->process_submission( 5, 'My Form', $data, 'alice@test.com', array(), array() );

		$this->assertSame( 5, $captured['form_id'] );
		$this->assertSame( 'publish', $captured['status'] );
		$this->assertNotEmpty( $captured['auth_code'] );
		$this->assertNotEmpty( $captured['magic_token'] );
		// `submission_date` is unix UTC int since 6.6.0 (#249 sub-escopo a) —
		// the handler now calls `time()` directly, which can't be mocked via
		// Brain\Monkey. Asserting on the shape (int, recent-ish) is enough.
		$this->assertIsInt( $captured['submission_date'] );
		$this->assertGreaterThan( time() - 10, $captured['submission_date'] );
	}

	public function test_process_submission_encrypts_email_when_configured(): void {
		$captured = null;
		$this->mockRepo->shouldReceive( 'insert' )
			->once()
			->withArgs( function( $data ) use ( &$captured ) {
				$captured = $data;
				return true;
			} )
			->andReturn( 10 );

		$data = array( 'name' => 'Carol', 'email' => 'carol@test.com' );
		$this->handler->process_submission( 1, 'Form', $data, 'carol@test.com', array(), array() );

		// With encryption on (constants defined in bootstrap), plaintext column
		// is not written; encrypted/hash fields are populated.
		$this->assertArrayNotHasKey( 'email', $captured );
		$this->assertNotNull( $captured['email_encrypted'] );
		$this->assertNotNull( $captured['email_hash'] );
		$this->assertSame( 64, strlen( $captured['email_hash'] ) );
	}

	public function test_process_submission_populates_ticket_hash_when_ticket_present(): void {
		$captured = null;
		$this->mockRepo->shouldReceive( 'insert' )
			->once()
			->withArgs( function( $data ) use ( &$captured ) {
				$captured = $data;
				return true;
			} )
			->andReturn( 10 );

		$data = array( 'name' => 'Dave', 'email' => 'dave@test.com', 'ticket' => 'ABC123' );
		$this->handler->process_submission( 1, 'Form', $data, 'dave@test.com', array(), array() );

		$this->assertNotNull( $captured['ticket_hash'] );
		$this->assertSame( 64, strlen( $captured['ticket_hash'] ) );
	}

	public function test_process_submission_ticket_hash_null_without_ticket(): void {
		$captured = null;
		$this->mockRepo->shouldReceive( 'insert' )
			->once()
			->withArgs( function( $data ) use ( &$captured ) {
				$captured = $data;
				return true;
			} )
			->andReturn( 10 );

		$data = array( 'name' => 'Eve', 'email' => 'eve@test.com' );
		$this->handler->process_submission( 1, 'Form', $data, 'eve@test.com', array(), array() );

		$this->assertNull( $captured['ticket_hash'] );
	}

	public function test_process_submission_consent_fields_set_when_given(): void {
		$captured = null;
		$this->mockRepo->shouldReceive( 'insert' )
			->once()
			->withArgs( function( $data ) use ( &$captured ) {
				$captured = $data;
				return true;
			} )
			->andReturn( 10 );

		$data = array( 'name' => 'Consent User', 'ffc_lgpd_consent' => '1' );
		$this->handler->process_submission( 1, 'Form', $data, 'c@test.com', array(), array() );

		$this->assertSame( 1, $captured['consent_given'] );
		// `consent_date` is unix UTC int since 6.6.0 (#249 sub-escopo d) and
		// sourced from `time()` directly (not mockable).
		$this->assertIsInt( $captured['consent_date'] );
		$this->assertGreaterThan( time() - 10, $captured['consent_date'] );
	}

	public function test_process_submission_data_field_encrypted(): void {
		$captured = null;
		$this->mockRepo->shouldReceive( 'insert' )
			->once()
			->withArgs( function( $data ) use ( &$captured ) {
				$captured = $data;
				return true;
			} )
			->andReturn( 10 );

		$data = array( 'name' => 'Encrypted User' );
		$this->handler->process_submission( 1, 'Form', $data, 'e@test.com', array(), array() );

		// With encryption on, the plaintext data column is null and data_encrypted is set
		$this->assertNull( $captured['data'] );
		$this->assertNotNull( $captured['data_encrypted'] );
	}

	// ------------------------------------------------------------------
	// get_submission()
	// ------------------------------------------------------------------

	public function test_get_submission_returns_decrypted_data(): void {
		$this->mockRepo->shouldReceive( 'findById' )
			->with( 5 )
			->once()
			->andReturn( array(
				'id'               => 5,
				'email'            => 'plain@test.com',
				'email_encrypted'  => null,
				'cpf_rf'           => '12345678901',
				'cpf_rf_encrypted' => null,
				'user_ip'          => '127.0.0.1',
				'user_ip_encrypted'=> null,
				'data'             => '{"name":"Test"}',
				'data_encrypted'   => null,
			) );

		$result = $this->handler->get_submission( 5 );

		$this->assertIsArray( $result );
		$this->assertSame( 'plain@test.com', $result['email'] );
	}

	public function test_get_submission_returns_null_for_missing(): void {
		$this->mockRepo->shouldReceive( 'findById' )
			->with( 999 )
			->once()
			->andReturn( null );

		$result = $this->handler->get_submission( 999 );
		$this->assertNull( $result );
	}

	// ------------------------------------------------------------------
	// get_submission_by_token()
	// ------------------------------------------------------------------

	public function test_get_submission_by_token_validates_length(): void {
		// Token too short — should return null without hitting repo
		$result = $this->handler->get_submission_by_token( 'short' );
		$this->assertNull( $result );
	}

	public function test_get_submission_by_token_finds_valid_token(): void {
		$token = str_repeat( 'a1', 16 ); // 32-char hex
		$this->mockRepo->shouldReceive( 'findByToken' )
			->with( $token )
			->once()
			->andReturn( array(
				'id' => 7, 'email' => 'tok@test.com', 'email_encrypted' => null,
				'cpf_rf' => null, 'cpf_rf_encrypted' => null,
				'user_ip' => null, 'user_ip_encrypted' => null,
				'data' => '{}', 'data_encrypted' => null,
			) );

		$result = $this->handler->get_submission_by_token( $token );
		$this->assertIsArray( $result );
		$this->assertSame( 7, $result['id'] );
	}

	// ------------------------------------------------------------------
	// trash / restore / delete
	// ------------------------------------------------------------------

	public function test_trash_submission_calls_update_status(): void {
		$this->mockRepo->shouldReceive( 'updateStatus' )
			->with( 3, 'trash' )
			->once()
			->andReturn( 1 );

		$result = $this->handler->trash_submission( 3 );
		$this->assertTrue( $result );
	}

	public function test_restore_submission_calls_update_status(): void {
		$this->mockRepo->shouldReceive( 'updateStatus' )
			->with( 3, 'publish' )
			->once()
			->andReturn( 1 );

		$result = $this->handler->restore_submission( 3 );
		$this->assertTrue( $result );
	}

	public function test_delete_submission_calls_repo_delete(): void {
		$this->mockRepo->shouldReceive( 'delete' )
			->with( 8 )
			->once()
			->andReturn( 1 );

		$result = $this->handler->delete_submission( 8 );
		$this->assertTrue( $result );
	}

	// ------------------------------------------------------------------
	// Bulk operations
	// ------------------------------------------------------------------

	public function test_bulk_trash_disables_logging_during_operation(): void {
		$this->mockRepo->shouldReceive( 'bulkUpdateStatus' )
			->with( array( 1, 2, 3 ), 'trash' )
			->once()
			->andReturn( 3 );

		$result = $this->handler->bulk_trash_submissions( array( 1, 2, 3 ) );
		$this->assertSame( 3, $result );
	}

	public function test_bulk_restore_submissions(): void {
		$this->mockRepo->shouldReceive( 'bulkUpdateStatus' )
			->with( array( 4, 5 ), 'publish' )
			->once()
			->andReturn( 2 );

		$result = $this->handler->bulk_restore_submissions( array( 4, 5 ) );
		$this->assertSame( 2, $result );
	}

	public function test_bulk_delete_submissions(): void {
		$this->mockRepo->shouldReceive( 'bulkDelete' )
			->with( array( 6, 7 ) )
			->once()
			->andReturn( 2 );

		$result = $this->handler->bulk_delete_submissions( array( 6, 7 ) );
		$this->assertSame( 2, $result );
	}

	// ------------------------------------------------------------------
	// ensure_magic_token()
	// ------------------------------------------------------------------

	public function test_ensure_magic_token_returns_existing_token(): void {
		$this->mockRepo->shouldReceive( 'findById' )
			->with( 10 )
			->once()
			->andReturn( array( 'magic_token' => 'abcdef1234567890abcdef1234567890' ) );

		$token = $this->handler->ensure_magic_token( 10 );
		$this->assertSame( 'abcdef1234567890abcdef1234567890', $token );
	}

	public function test_ensure_magic_token_generates_new_when_missing(): void {
		$this->mockRepo->shouldReceive( 'findById' )
			->with( 11 )
			->once()
			->andReturn( array( 'magic_token' => null ) );

		$this->mockRepo->shouldReceive( 'update' )
			->once()
			->andReturn( 1 );

		$token = $this->handler->ensure_magic_token( 11 );
		$this->assertSame( 32, strlen( $token ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $token );
	}

	public function test_ensure_magic_token_returns_empty_when_not_found(): void {
		$this->mockRepo->shouldReceive( 'findById' )
			->with( 999 )
			->once()
			->andReturn( null );

		$token = $this->handler->ensure_magic_token( 999 );
		$this->assertSame( '', $token );
	}

	// ------------------------------------------------------------------
	// update_submission()
	// ------------------------------------------------------------------

	public function test_update_submission_encrypts_email(): void {
		$this->mockRepo->shouldReceive( 'updateWithEditTracking' )
			->once()
			->withArgs( function( $id, $data ) {
				return $id === 1
					&& ! array_key_exists( 'email', $data )
					&& ! empty( $data['email_encrypted'] )
					&& ! empty( $data['email_hash'] )
					&& strlen( $data['email_hash'] ) === 64;
			} )
			->andReturn( 1 );

		$result = $this->handler->update_submission( 1, 'new@example.com', array( 'name' => 'Updated' ) );
		$this->assertTrue( $result );
	}

	public function test_update_submission_encrypts_data(): void {
		$captured = null;
		$this->mockRepo->shouldReceive( 'updateWithEditTracking' )
			->once()
			->withArgs( function( $id, $data ) use ( &$captured ) {
				$captured = $data;
				return true;
			} )
			->andReturn( 1 );

		$this->handler->update_submission( 1, 'u@test.com', array( 'field1' => 'value1' ) );

		$this->assertNull( $captured['data'] );
		$this->assertNotNull( $captured['data_encrypted'] );
	}

	public function test_update_submission_strips_edit_tracking_from_data(): void {
		$captured = null;
		$this->mockRepo->shouldReceive( 'updateWithEditTracking' )
			->once()
			->withArgs( function( $id, $data ) use ( &$captured ) {
				$captured = $data;
				return true;
			} )
			->andReturn( 1 );

		$clean_data = array(
			'name'      => 'Test',
			'is_edited' => true,
			'edited_at' => '2025-01-01',
		);
		$this->handler->update_submission( 1, 'u@test.com', $clean_data );

		// The encrypted data should NOT contain is_edited or edited_at
		$decrypted_json = \FreeFormCertificate\Core\Encryption::decrypt( $captured['data_encrypted'] );
		$decoded = json_decode( $decrypted_json, true );
		$this->assertArrayNotHasKey( 'is_edited', $decoded );
		$this->assertArrayNotHasKey( 'edited_at', $decoded );
		$this->assertSame( 'Test', $decoded['name'] );
	}

	public function test_update_submission_returns_false_on_repo_failure(): void {
		$this->mockRepo->shouldReceive( 'updateWithEditTracking' )
			->once()
			->andReturn( false );

		$result = $this->handler->update_submission( 1, 'fail@test.com', array() );
		$this->assertFalse( $result );
	}

	// ------------------------------------------------------------------
	// update_user_link()
	// ------------------------------------------------------------------

	public function test_update_user_link_sets_user_id(): void {
		$captured = null;
		$this->mockRepo->shouldReceive( 'update' )
			->once()
			->withArgs( function( $id, $data ) use ( &$captured ) {
				$captured = $data;
				return $id === 10;
			} )
			->andReturn( 1 );

		$result = $this->handler->update_user_link( 10, 42 );
		$this->assertTrue( $result );
		$this->assertSame( 42, $captured['user_id'] );
	}

	public function test_update_user_link_unlinks_with_null(): void {
		$captured = null;
		$this->mockRepo->shouldReceive( 'update' )
			->once()
			->withArgs( function( $id, $data ) use ( &$captured ) {
				$captured = $data;
				return true;
			} )
			->andReturn( 1 );

		$result = $this->handler->update_user_link( 10, null );
		$this->assertTrue( $result );
		$this->assertNull( $captured['user_id'] );
	}

	public function test_update_user_link_returns_false_on_failure(): void {
		$this->mockRepo->shouldReceive( 'update' )
			->once()
			->andReturn( false );

		$result = $this->handler->update_user_link( 10, 42 );
		$this->assertFalse( $result );
	}

	// ------------------------------------------------------------------
	// decrypt_submission_data()
	// ------------------------------------------------------------------

	public function test_decrypt_submission_data_plaintext_passthrough(): void {
		$submission = array(
			'email_encrypted'  => null,
			'cpf_encrypted'    => null,
			'rf_encrypted'     => null,
			'user_ip_encrypted'=> null,
			'data'             => '{"name":"Alice"}',
			'data_encrypted'   => null,
		);

		$result = $this->handler->decrypt_submission_data( $submission );

		$this->assertSame( '', $result['email'] );
		$this->assertSame( '', $result['cpf_rf'] );
		$this->assertSame( '', $result['user_ip'] );
		$this->assertSame( '{"name":"Alice"}', $result['data'] );
	}

	public function test_decrypt_submission_data_with_encrypted_fields(): void {
		$enc_email = \FreeFormCertificate\Core\Encryption::encrypt( 'enc@test.com' );
		$enc_cpf   = \FreeFormCertificate\Core\Encryption::encrypt( '99988877766' );
		$enc_ip    = \FreeFormCertificate\Core\Encryption::encrypt( '10.0.0.1' );
		$enc_data  = \FreeFormCertificate\Core\Encryption::encrypt( '{"score":100}' );

		$submission = array(
			'email_encrypted'  => $enc_email,
			'cpf_encrypted'    => $enc_cpf,
			'rf_encrypted'     => null,
			'user_ip_encrypted'=> $enc_ip,
			'data'             => null,
			'data_encrypted'   => $enc_data,
		);

		$result = $this->handler->decrypt_submission_data( $submission );

		$this->assertSame( 'enc@test.com', $result['email'] );
		$this->assertSame( '99988877766', $result['cpf_rf'] );
		$this->assertSame( '10.0.0.1', $result['user_ip'] );
		$this->assertSame( '{"score":100}', $result['data'] );
	}

	// ------------------------------------------------------------------
	// trash / restore / delete — failure paths
	// ------------------------------------------------------------------

	public function test_trash_submission_returns_false_on_failure(): void {
		$this->mockRepo->shouldReceive( 'updateStatus' )
			->with( 99, 'trash' )
			->once()
			->andReturn( false );

		$result = $this->handler->trash_submission( 99 );
		$this->assertFalse( $result );
	}

	public function test_restore_submission_returns_false_on_failure(): void {
		$this->mockRepo->shouldReceive( 'updateStatus' )
			->with( 99, 'publish' )
			->once()
			->andReturn( false );

		$result = $this->handler->restore_submission( 99 );
		$this->assertFalse( $result );
	}

	public function test_delete_submission_returns_false_on_failure(): void {
		$this->mockRepo->shouldReceive( 'delete' )
			->with( 99 )
			->once()
			->andReturn( false );

		$result = $this->handler->delete_submission( 99 );
		$this->assertFalse( $result );
	}

	// ------------------------------------------------------------------
	// Bulk operations — empty array guards
	// ------------------------------------------------------------------

	public function test_bulk_trash_empty_array_returns_zero(): void {
		$result = $this->handler->bulk_trash_submissions( array() );
		$this->assertSame( 0, $result );
	}

	public function test_bulk_restore_empty_array_returns_zero(): void {
		$result = $this->handler->bulk_restore_submissions( array() );
		$this->assertSame( 0, $result );
	}

	public function test_bulk_delete_empty_array_returns_zero(): void {
		$result = $this->handler->bulk_delete_submissions( array() );
		$this->assertSame( 0, $result );
	}

	// ------------------------------------------------------------------
	// get_submission_by_token() — edge cases
	// ------------------------------------------------------------------

	public function test_get_submission_by_token_non_hex_returns_null(): void {
		// Contains non-hex chars — after cleaning, length != 32
		$result = $this->handler->get_submission_by_token( 'ZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZ' );
		$this->assertNull( $result );
	}

	public function test_get_submission_by_token_not_found_returns_null(): void {
		$token = str_repeat( 'ab', 16 ); // 32-char hex
		$this->mockRepo->shouldReceive( 'findByToken' )
			->with( $token )
			->once()
			->andReturn( null );

		$result = $this->handler->get_submission_by_token( $token );
		$this->assertNull( $result );
	}

	// ------------------------------------------------------------------
	// process_submission() — additional branches
	// ------------------------------------------------------------------

	public function test_process_submission_consent_absent_sets_zero(): void {
		$captured = null;
		$this->mockRepo->shouldReceive( 'insert' )
			->once()
			->withArgs( function( $data ) use ( &$captured ) {
				$captured = $data;
				return true;
			} )
			->andReturn( 20 );

		$data = array( 'name' => 'No Consent' );
		$this->handler->process_submission( 1, 'Form', $data, 'nc@test.com', array(), array() );

		$this->assertSame( 0, $captured['consent_given'] );
		$this->assertNull( $captured['consent_date'] );
	}

	/**
	 * The certificate path resolves through the DUAL entry point (#1313 PR 7).
	 *
	 * WHAT THIS PINS THAT THE OLD CALL COULD NOT
	 *
	 * The legacy entry point chose the column from a separate
	 * `$identifier_type` argument, so the value and the type it was declared to
	 * be could disagree. Here the POSITION is the type, and the hash in it must
	 * be the one the row is written with -- the same `cpf_hash` this very
	 * submission stores, not a second hash of the same value.
	 *
	 * Both directions are asserted: a mutation swapping the two arguments
	 * passes any test that only looks at one.
	 *
	 * `@runInSeparateProcess` so the `alias:` mock on `UserManager` cannot
	 * reach the rest of this class, which drives the real one.
	 *
	 * @dataProvider dual_argument_provider
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @param string $typed    The single `cpf_rf` field as the visitor types it.
	 * @param int    $slot     Argument index expected to carry the hash.
	 * @param string $column   The row column that must hold the same hash.
	 */
	public function test_the_resolver_receives_the_hash_in_the_argument_its_kind_names( string $typed, int $slot, string $column ): void {
		$captured = null;
		$this->mockRepo->shouldReceive( 'insert' )
			->once()
			->withArgs( function( $data ) use ( &$captured ) {
				$captured = $data;
				return true;
			} )
			->andReturn( 31 );

		$seen = array();
		Mockery::mock( 'alias:FreeFormCertificate\UserDashboard\UserManager' )
			->shouldReceive( 'get_or_create_user_dual' )
			->andReturnUsing(
				static function ( ...$args ) use ( &$seen ) {
					$seen = $args;
					return 7;
				}
			);

		// The third argument is by reference, so it must be a variable.
		$data = array( 'name' => 'X', 'cpf_rf' => $typed );
		$this->handler->process_submission( 1, 'Form', $data, 'x@test.com', array(), array() );

		$this->assertNotSame( array(), $seen, 'The resolver was never called, so nothing about its arguments was measured.' );

		$other = 0 === $slot ? 1 : 0;

		$this->assertSame(
			$captured[ $column ],
			$seen[ $slot ],
			"The resolver was handed a different hash from the one written to {$column}, so a lookup for this person cannot find this row."
		);
		$this->assertNull(
			$seen[ $other ],
			"The other identifier argument is not null for '{$typed}', so the resolver searches a column this value was never written to."
		);
	}

	/**
	 * @return array<string, array{0: string, 1: int, 2: string}>
	 */
	public static function dual_argument_provider(): array {
		return array(
			'a CPF goes in the first argument' => array( '123.456.789-01', 0, 'cpf_hash' ),
			'an RF goes in the second'         => array( '123.456-7', 1, 'rf_hash' ),
		);
	}

	public function test_process_submission_cleans_cpf_rf(): void {
		$captured = null;
		$this->mockRepo->shouldReceive( 'insert' )
			->once()
			->withArgs( function( $data ) use ( &$captured ) {
				$captured = $data;
				return true;
			} )
			->andReturn( 30 );

		$data = array( 'name' => 'CPF User', 'cpf_rf' => '123.456.789-01' );
		$this->handler->process_submission( 1, 'Form', $data, 'cpf@test.com', array(), array() );

		// Legacy cpf_rf columns no longer written; split columns used instead
		$this->assertArrayNotHasKey( 'cpf_rf', $captured );
		$this->assertArrayNotHasKey( 'cpf_rf_encrypted', $captured );
		$this->assertArrayNotHasKey( 'cpf_rf_hash', $captured );

		// Split cpf_hash is populated (11 digits = CPF)
		$this->assertNotNull( $captured['cpf_hash'] );
		$this->assertSame( 64, strlen( $captured['cpf_hash'] ) );
		$this->assertNotNull( $captured['cpf_encrypted'] );
		$this->assertNull( $captured['rf_hash'] );
	}

	public function test_process_submission_pre_populated_auth_code(): void {
		$captured = null;
		$this->mockRepo->shouldReceive( 'insert' )
			->once()
			->withArgs( function( $data ) use ( &$captured ) {
				$captured = $data;
				return true;
			} )
			->andReturn( 40 );

		$data = array( 'name' => 'Auth User', 'auth_code' => 'MY-CUSTOM-CODE' );
		$this->handler->process_submission( 1, 'Form', $data, 'a@test.com', array(), array() );

		// Should use the provided auth code (cleaned) instead of generating a new one
		$this->assertSame( 'MYCUSTOMCODE', $captured['auth_code'] );
	}

	// ==================================================================
	// dispatch_async_pipeline() — #1248's rehydration
	// ==================================================================

	/**
	 * Builds a submission row with the fields genuinely encrypted.
	 *
	 * The crypto constants live in `bootstrap.php`, so
	 * `Encryption::is_configured()` is true and the path exercised is the real
	 * one -- not a double that would return plaintext and hide a column error.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 * @return array<string, mixed>
	 */
	private function encryptedRow( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'              => 77,
				'form_id'         => 3,
				'auth_code'       => 'ABCD1234EFGH',
				'magic_token'     => str_repeat( 'a', 32 ),
				'consent_given'   => 1,
				'email_encrypted' => Encryption::encrypt( 'person@example.com' ),
				'cpf_encrypted'   => Encryption::encrypt( '12345678900' ),
				'rf_encrypted'    => null,
				'data_encrypted'  => Encryption::encrypt( '{"name":"John Doe","ticket":"ABC123"}' ),
				'user_ip_encrypted' => null,
			),
			$overrides
		);
	}

	/**
	 * Captura o `do_action` do gancho publico.
	 *
	 * @return array<int, mixed>|null
	 */
	private function capturedPipelineArgs( int $submission_id = 77 ): ?array {
		$captured = null;
		Functions\when( 'do_action' )->alias(
			function () use ( &$captured ) {
				$args = func_get_args();
				if ( SubmissionHandler::PIPELINE_HOOK === $args[0] ) {
					$captured = array_slice( $args, 1 );
				}
				return null;
			}
		);

		$this->handler->dispatch_async_pipeline( $submission_id );

		return $captured;
	}

	/**
	 * The PUBLIC hook still receives the same eight arguments.
	 *
	 * It is the assertion that holds compatibility up: a third party's
	 * `add_action( 'ffcertificate_process_submission_hook', ..., 10, 8 )` must not
	 * notice that the cron payload shrank. If the POSITIONS slip, the external
	 * listener receives swapped data with no error at all -- which is why each
	 * one is checked, and not just the count.
	 */
	public function test_dispatch_async_pipeline_fires_the_public_hook_with_all_eight_arguments(): void {
		$this->mockRepo->shouldReceive( 'findById' )->once()->with( 77 )->andReturn( $this->encryptedRow() );

		Functions\when( 'get_the_title' )->justReturn( 'Sample Form' );
		Functions\when( 'get_post_meta' )->alias(
			function ( $post_id, $key ) {
				return '_ffc_form_fields' === $key
					? array( array( 'key' => 'name' ) )
					: array( 'send_user_email' => '1' );
			}
		);

		$args = $this->capturedPipelineArgs();

		$this->assertIsArray( $args, 'The public hook has to be fired.' );
		$this->assertCount( 8, $args );

		$this->assertSame( 77, $args[0], 'submission_id' );
		$this->assertSame( 3, $args[1], 'form_id' );
		$this->assertSame( 'Sample Form', $args[2], 'form_title' );
		$this->assertIsArray( $args[3], 'submission_data' );
		$this->assertSame( 'person@example.com', $args[4], 'user_email' );
		$this->assertSame( array( array( 'key' => 'name' ) ), $args[5], 'fields_config' );
		$this->assertSame( array( 'send_user_email' => '1' ), $args[6], 'form_config' );
		$this->assertSame( str_repeat( 'a', 32 ), $args[7], 'magic_token' );
	}

	/**
	 * The rebuild groups: identity, form fields, code, consent.
	 *
	 * The original POST order is not recoverable -- the database separated the
	 * four mandatory keys from the rest and did not keep their positions. This
	 * assertion pins the CHOSEN order, because it is what the admin notification
	 * draws, one line per key.
	 *
	 * The form fields keep their order among themselves, which came from the JSON.
	 */
	public function test_the_rebuilt_submission_data_groups_identity_then_form_fields(): void {
		$this->mockRepo->shouldReceive( 'findById' )->once()->andReturn( $this->encryptedRow() );

		Functions\when( 'get_the_title' )->justReturn( '' );
		Functions\when( 'get_post_meta' )->justReturn( array() );

		$args = $this->capturedPipelineArgs();

		$this->assertSame(
			array( 'email', 'cpf_rf', 'name', 'ticket', 'auth_code', 'ffc_lgpd_consent' ),
			array_keys( $args[3] )
		);
		$this->assertSame( '12345678900', $args[3]['cpf_rf'] );
		$this->assertSame( 'John Doe', $args[3]['name'] );
		$this->assertSame( 'ABCD1234EFGH', $args[3]['auth_code'] );
		$this->assertSame( '1', $args[3]['ffc_lgpd_consent'] );
	}

	/**
	 * With no consent stored, the key does not enter.
	 *
	 * The database stores a boolean, so an `ffc_lgpd_consent` sent as `'0'` is
	 * indistinguishable from one not sent -- and the row disappears from the
	 * notification's table. A documented trade-off, not an accident.
	 */
	public function test_a_refused_consent_leaves_no_row(): void {
		$this->mockRepo->shouldReceive( 'findById' )->once()->andReturn(
			$this->encryptedRow( array( 'consent_given' => 0 ) )
		);

		Functions\when( 'get_the_title' )->justReturn( '' );
		Functions\when( 'get_post_meta' )->justReturn( array() );

		$args = $this->capturedPipelineArgs();

		$this->assertArrayNotHasKey( 'ffc_lgpd_consent', $args[3] );
	}

	/**
	 * Row deleted between the scheduling and the dispatch: silence, not a fatal.
	 *
	 * The window is ~1 second, but wp-cron only runs when a visitor arrives -- on
	 * a quiet site it is as long as the interval between two visits.
	 */
	public function test_dispatch_async_pipeline_stays_silent_when_the_row_is_gone(): void {
		$this->mockRepo->shouldReceive( 'findById' )->once()->andReturn( null );

		$this->assertNull( $this->capturedPipelineArgs() );
	}

	/**
	 * An invalid id does not even query the database.
	 */
	public function test_dispatch_async_pipeline_ignores_a_non_positive_id(): void {
		$this->mockRepo->shouldNotReceive( 'findById' );

		$this->assertNull( $this->capturedPipelineArgs( 0 ) );
	}

	/**
	 * BOTH NORMALISATIONS ARE INVISIBLE -- and this test is the measurement.
	 *
	 * The database stores `cpf_rf` as digits only and `auth_code` as
	 * alphanumerics only, while the visitor may have typed `123.456.789-00`. The
	 * legitimate doubt was whether the admin notification would change content.
	 *
	 * It does not, and not by luck: the only two consumers of those values clean
	 * their INPUT before formatting -- `format_document()` with
	 * `preg_replace('/\D/','')` and `format_auth_code()` with `/[^A-Z0-9]/i`.
	 * Raw and normalised converge on the same output.
	 *
	 * The assertion lives here, and not in a `DocumentFormatter` test, because
	 * what it protects is the rehydration: if one day those formatters stop
	 * cleaning their input, it is #1248 that starts changing the email.
	 */
	public function test_the_two_normalizations_are_invisible_to_the_formatters(): void {
		$this->assertSame(
			\FreeFormCertificate\Core\DocumentFormatter::format_document( '123.456.789-00' ),
			\FreeFormCertificate\Core\DocumentFormatter::format_document( '12345678900' ),
			'If this diverges, the rehydration starts changing the CPF in the admin notification.'
		);
		$this->assertSame(
			\FreeFormCertificate\Core\DocumentFormatter::format_auth_code( 'abcd-1234-efgh', 'CERT' ),
			\FreeFormCertificate\Core\DocumentFormatter::format_auth_code( 'ABCD1234EFGH', 'CERT' ),
			'Likewise for the authentication code.'
		);
	}

}
