<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\SubmissionStatus;
use FreeFormCertificate\Integrations\EmailHandler;

/**
 * Tests for EmailHandler: SMTP configuration, user email sending, and admin notifications.
 *
 * Note: send_wp_user_notification() context logic is covered by EmailHandlerContextTest.
 *
 * @covers \FreeFormCertificate\Integrations\EmailHandler
 */
class EmailHandlerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<int, array{to: string, subject: string, body: string}> Captured emails */
	private array $sent_emails = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// The staff notification links to the submissions screen (#1863).
		Functions\when( 'admin_url' )->alias( static fn( $path = '' ) => 'https://example.com/wp-admin/' . $path );

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_kses' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'wpautop' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'wp_date' )->alias( function ( $format, $ts = null ) {
			return gmdate( (string) $format, $ts ?? 0 );
		} );
		Functions\when( 'wp_timezone' )->alias( function () {
			return new \DateTimeZone( 'UTC' );
		} );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'do_action' )->justReturn();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		// #673: EmailService::send derives a text/plain alternative for HTML
		// messages — stub the WP glue that derivation touches.
		Functions\when( 'remove_action' )->justReturn( true );
		Functions\when( 'wp_strip_all_tags' )->alias(
			static function ( $s ) {
				return trim( (string) strip_tags( (string) $s ) );
			}
		);
		Functions\when( 'get_bloginfo' )->justReturn( 'Test Site' );
		// MailQueue::is_active() also reads the network plugin list (#1758).
		Functions\when( 'get_site_option' )->justReturn( array() );
		// Default for the shared chrome's footer tokens (some tests override).
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'site_url' )->alias( function ( $path = '' ) {
			return 'https://example.com' . $path;
		} );
		Functions\when( 'untrailingslashit' )->returnArg();
		Functions\when( 'absint' )->alias( function ( $val ) {
			return abs( (int) $val );
		} );

		// Capture wp_mail calls
		$this->sent_emails = array();
		$emails = &$this->sent_emails;
		Functions\when( 'wp_mail' )->alias( function ( $to, $subject, $body ) use ( &$emails ) {
			$emails[] = array( 'to' => $to, 'subject' => $subject, 'body' => $body );
			return true;
		} );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ==================================================================
	// Constructor
	// ==================================================================

	public function test_constructor_registers_hooks(): void {
		// add_action is stubbed in setUp — verify constructor completes
		$handler = new EmailHandler();
		$this->assertInstanceOf( EmailHandler::class, $handler );
	}

	// ==================================================================
	// configure_custom_smtp() — custom mode
	// ==================================================================

	public function test_configure_custom_smtp_sets_phpmailer_when_custom_mode(): void {
		Functions\when( 'get_option' )->justReturn( array(
			'smtp_mode'       => 'custom',
			'smtp_host'       => 'smtp.example.com',
			'smtp_port'       => '465',
			'smtp_user'       => 'user@example.com',
			'smtp_pass'       => 'secret',
			'smtp_secure'     => 'ssl',
			'smtp_from_email' => 'noreply@example.com',
			'smtp_from_name'  => 'My App',
		) );

		$phpmailer = Mockery::mock( 'PHPMailer\PHPMailer\PHPMailer' );
		$phpmailer->shouldReceive( 'isSMTP' )->once();

		// PHPMailer uses magic properties
		$phpmailer->Host       = '';
		$phpmailer->SMTPAuth   = false;
		$phpmailer->Port       = 0;
		$phpmailer->Username   = '';
		$phpmailer->Password   = '';
		$phpmailer->SMTPSecure = '';
		$phpmailer->From       = '';
		$phpmailer->FromName   = '';

		$handler = new EmailHandler();
		$handler->configure_custom_smtp( $phpmailer );

		$this->assertSame( 'smtp.example.com', $phpmailer->Host );
		$this->assertTrue( $phpmailer->SMTPAuth );
		$this->assertSame( 465, $phpmailer->Port );
		$this->assertSame( 'user@example.com', $phpmailer->Username );
		$this->assertSame( 'secret', $phpmailer->Password );
		$this->assertSame( 'ssl', $phpmailer->SMTPSecure );
		$this->assertSame( 'noreply@example.com', $phpmailer->From );
		$this->assertSame( 'My App', $phpmailer->FromName );
	}

	public function test_configure_custom_smtp_leaves_phpmailer_alone_while_total_mail_queue_is_active(): void {
		// Total Mail Queue manages delivery (#1758): WP default is used, and the
		// stored custom SMTP applies again once it is deactivated.
		Functions\when( 'get_option' )->alias(
			static function ( $key ) {
				if ( 'active_plugins' === $key ) {
					return array( 'total-mail-queue/total-mail-queue.php' );
				}
				return array(
					'smtp_mode' => 'custom',
					'smtp_host' => 'smtp.example.com',
				);
			}
		);

		$phpmailer = Mockery::mock( 'PHPMailer\PHPMailer\PHPMailer' );
		$phpmailer->shouldNotReceive( 'isSMTP' );
		$phpmailer->Host = '';

		$handler = new EmailHandler();
		$handler->configure_custom_smtp( $phpmailer );

		$this->assertSame( '', $phpmailer->Host );
	}

	// ==================================================================
	// configure_custom_smtp() — non-custom mode (no-op)
	// ==================================================================

	public function test_configure_custom_smtp_does_nothing_when_not_custom(): void {
		Functions\when( 'get_option' )->justReturn( array( 'smtp_mode' => 'default' ) );

		$phpmailer = Mockery::mock( 'PHPMailer\PHPMailer\PHPMailer' );
		$phpmailer->shouldNotReceive( 'isSMTP' );
		$phpmailer->Host = '';

		$handler = new EmailHandler();
		$handler->configure_custom_smtp( $phpmailer );

		$this->assertSame( '', $phpmailer->Host );
	}

	// ==================================================================
	// configure_custom_smtp() — no settings at all
	// ==================================================================

	public function test_configure_custom_smtp_skips_when_no_smtp_mode(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$phpmailer = Mockery::mock( 'PHPMailer\PHPMailer\PHPMailer' );
		$phpmailer->shouldNotReceive( 'isSMTP' );

		$handler = new EmailHandler();
		$handler->configure_custom_smtp( $phpmailer );
	}

	// ==================================================================
	// async_process_submission() — sends user email when enabled
	// ==================================================================

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_async_sends_user_email_when_enabled(): void {
		Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
			if ( $key === 'admin_email' ) {
				return 'admin@example.com';
			}
			return $default;
		} );
		Functions\when( 'is_email' )->justReturn( true );

		// DocumentFormatter is autoloaded (has real PREFIX_CERTIFICATE constant)
		// Utils and MagicLinkHelper need alias mocks
		$utilsMock = Mockery::mock( 'alias:\FreeFormCertificate\Core\Utils' );
		$utilsMock->shouldReceive( 'format_auth_code' )->andReturn( 'CERT-ABC123' );
		$utilsMock->shouldReceive( 'format_document' )->andReturnArg( 0 );
		Mockery::mock( 'alias:\FreeFormCertificate\Core\HtmlPolicy' )->shouldReceive( 'get_allowed_html_tags' )->andReturn( array() );
		$utilsMock->shouldReceive( 'debug_log' )->andReturn();

		$magicMock = Mockery::mock( 'alias:\FreeFormCertificate\Generators\MagicLinkHelper' );
		$magicMock->shouldReceive( 'generate_magic_link' )
			->with( 'tok123' )
			->andReturn( 'https://example.com/magic/tok123' );

		$handler = new EmailHandler();
		$handler->async_process_submission(
			1,
			10,
			'Test Certificate',
			array( 'auth_code' => 'ABC123', 'name' => 'John' ),
			'user@example.com',
			array(),
			array( 'send_user_email' => 1, 'email_admin' => 'admin@example.com' ),
			'tok123'
		);

		// At least 1 email sent (user email)
		$this->assertGreaterThanOrEqual( 1, count( $this->sent_emails ) );
		$this->assertSame( 'user@example.com', $this->sent_emails[0]['to'] );
	}

	public function test_user_email_substitutes_placeholders_and_runs_dsl(): void {
		Functions\when( 'is_email' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( false ); // verification_page_id 0 → home_url fallback
		Functions\when( 'home_url' )->alias( fn( $p = '' ) => 'https://ex' . (string) $p );
		Functions\when( 'trailingslashit' )->alias( fn( $u ) => rtrim( (string) $u, '/' ) . '/' );

		$handler = new EmailHandler();
		$handler->async_process_submission(
			1,
			10,
			'My Form',
			array( 'auth_code' => 'ABC123', 'name' => 'Ana' ),
			'ana@example.com',
			array(),
			array(
				'send_user_email' => '1',
				'email_subject'   => 'Ready: {{form_title}}',
				// Encoded braces on the DSL token exercise the TinyMCE-encoding
				// tolerance; the scalar tokens are raw.
				'email_body'      => '<p>Hi {{name}} on {{date}}</p><p>%7B%7Bvalidation_url link:m>"Download"%7D%7D</p>',
			),
			'tok9'
		);

		$this->assertNotEmpty( $this->sent_emails );
		$body    = $this->sent_emails[0]['body'];
		$subject = $this->sent_emails[0]['subject'];

		$this->assertSame( 'Ready: My Form', $subject, 'subject {{form_title}} substituted' );
		$this->assertStringContainsString( 'Hi Ana on', $body, '{{name}} substituted' );
		$this->assertStringContainsString( '#token=tok9', $body, 'DSL rendered the magic download link' );
		$this->assertStringContainsString( 'Download', $body, 'custom link text preserved' );
		$this->assertStringNotContainsString( '{{name}}', $body, 'no literal scalar token leaks' );
		$this->assertStringNotContainsString( '%7B%7B', $body, 'encoded braces normalised' );
		$this->assertStringNotContainsString( '{{validation_url', $body, 'DSL token consumed' );
	}

	public function test_user_email_on_the_global_tracks_the_hub_override(): void {
		// A form with no per-form subject/body is on the Global — the send path
		// resolves the effective global (the SMTP email-body-hub override), #964.
		Functions\when( 'is_email' )->justReturn( true );
		Functions\when( 'home_url' )->alias( fn( $p = '' ) => 'https://ex' . (string) $p );
		Functions\when( 'trailingslashit' )->alias( fn( $u ) => rtrim( (string) $u, '/' ) . '/' );
		Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
			if ( 'ffc_email_bodies' === $key ) {
				return array(
					'certificate-user' => array(
						'subject' => 'Global subject for {{form_title}}',
						'body'    => '<p>Global hub body for {{name}}.</p>',
					),
				);
			}
			return $default;
		} );

		$handler = new EmailHandler();
		$handler->async_process_submission(
			1,
			10,
			'My Form',
			array( 'auth_code' => 'ABC123', 'name' => 'Ana' ),
			'ana@example.com',
			array(),
			array( 'send_user_email' => '1' ), // No per-form subject/body → Global.
			'tok9'
		);

		$this->assertNotEmpty( $this->sent_emails );
		$this->assertSame( 'Global subject for My Form', $this->sent_emails[0]['subject'], 'global subject applied + token substituted' );
		$this->assertStringContainsString( 'Global hub body for Ana.', $this->sent_emails[0]['body'], 'global body applied + token substituted' );
	}

	// ==================================================================
	// async_process_submission() — skips user email when disabled
	// ==================================================================

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_async_skips_user_email_when_disabled(): void {
		Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
			if ( $key === 'admin_email' ) {
				return 'admin@example.com';
			}
			return $default;
		} );
		Functions\when( 'is_email' )->justReturn( true );

		$utilsMock = Mockery::mock( 'alias:\FreeFormCertificate\Core\Utils' );
		$utilsMock->shouldReceive( 'format_auth_code' )->andReturn( '' );
		$utilsMock->shouldReceive( 'format_document' )->andReturnArg( 0 );
		Mockery::mock( 'alias:\FreeFormCertificate\Core\HtmlPolicy' )->shouldReceive( 'get_allowed_html_tags' )->andReturn( array() );
		$utilsMock->shouldReceive( 'debug_log' )->andReturn();

		$handler = new EmailHandler();
		$handler->async_process_submission(
			1,
			10,
			'Test Certificate',
			array( 'name' => 'John' ),
			'user@example.com',
			array(),
			array( 'send_user_email' => 0, 'send_admin_email' => '1', 'email_admin' => 'admin@example.com' ),
			''
		);

		// Only admin notification sent (no user email)
		$this->assertCount( 1, $this->sent_emails );
		$this->assertSame( 'admin@example.com', $this->sent_emails[0]['to'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_admin_notification_masks_the_personal_fields(): void {
		// #1863: CPF, RF and e-mail reach staff masked, with a link to the
		// screen where they are revealed; other fields stay as typed.
		Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
			return 'admin_email' === $key ? 'admin@example.com' : $default;
		} );
		Functions\when( 'is_email' )->alias( static fn( $v ) => is_string( $v ) && 1 === substr_count( $v, '@' ) );
		Mockery::mock( 'alias:\FreeFormCertificate\Core\HtmlPolicy' )->shouldReceive( 'get_allowed_html_tags' )->andReturn( array() );

		$handler = new EmailHandler();
		$handler->async_process_submission(
			1,
			10,
			'Test Certificate',
			array(
				'name'  => 'John',
				'cpf'   => '51817842080',
				'email' => 'john@example.com',
			),
			'john@example.com',
			array(),
			array( 'send_user_email' => 0, 'send_admin_email' => '1', 'email_admin' => 'admin@example.com' ),
			''
		);

		$this->assertCount( 1, $this->sent_emails );
		$body = $this->sent_emails[0]['body'];
		$this->assertStringContainsString( 'John', $body );
		$this->assertStringContainsString( '518.***.***-80', $body );
		$this->assertStringNotContainsString( '51817842080', $body );
		$this->assertStringContainsString( 'j***@example.com', $body );
		$this->assertStringNotContainsString( 'john@example.com', $body );
		$this->assertStringContainsString( 'page=ffc-submissions', $body );
	}

	public function test_async_skips_admin_notification_when_not_opted_in(): void {
		Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
			return 'admin_email' === $key ? 'admin@example.com' : $default;
		} );
		Functions\when( 'is_email' )->justReturn( true );

		$handler = new EmailHandler();
		$handler->async_process_submission(
			1,
			10,
			'Test Certificate',
			array( 'name' => 'John' ),
			'user@example.com',
			array(),
			// Neither user nor admin email opted in → nothing sent (default off).
			array( 'email_admin' => 'admin@example.com' ),
			''
		);

		$this->assertCount( 0, $this->sent_emails, 'admin notification must be opt-in (send_admin_email)' );
	}

	// ==================================================================
	// async_process_submission() — global email disable blocks all
	// ==================================================================

	public function test_async_sends_nothing_when_emails_globally_disabled(): void {
		Functions\when( 'get_option' )->justReturn( array( 'disable_all_emails' => 1 ) );

		$handler = new EmailHandler();
		$handler->async_process_submission(
			1,
			10,
			'Test',
			array(),
			'user@example.com',
			array(),
			array( 'send_user_email' => 1, 'email_admin' => 'admin@example.com' ),
			'tok'
		);

		$this->assertCount( 0, $this->sent_emails );
	}

	// ==================================================================
	// configure_custom_smtp() — from_email without from_name uses bloginfo
	// ==================================================================

	public function test_configure_custom_smtp_uses_bloginfo_for_from_name(): void {
		Functions\when( 'get_option' )->justReturn( array(
			'smtp_mode'       => 'custom',
			'smtp_host'       => 'smtp.test.com',
			'smtp_from_email' => 'noreply@test.com',
			// smtp_from_name intentionally missing
		) );

		$phpmailer = Mockery::mock( 'PHPMailer\PHPMailer\PHPMailer' );
		$phpmailer->shouldReceive( 'isSMTP' )->once();
		$phpmailer->Host       = '';
		$phpmailer->SMTPAuth   = false;
		$phpmailer->Port       = 0;
		$phpmailer->Username   = '';
		$phpmailer->Password   = '';
		$phpmailer->SMTPSecure = '';
		$phpmailer->From       = '';
		$phpmailer->FromName   = '';

		$handler = new EmailHandler();
		$handler->configure_custom_smtp( $phpmailer );

		$this->assertSame( 'noreply@test.com', $phpmailer->From );
		$this->assertSame( 'Test Site', $phpmailer->FromName );
	}

	// ==================================================================
	// configure_custom_smtp() — defaults for missing fields
	// ==================================================================

	public function test_configure_custom_smtp_uses_defaults_for_missing_fields(): void {
		Functions\when( 'get_option' )->justReturn( array(
			'smtp_mode' => 'custom',
			// All other fields intentionally missing
		) );

		$phpmailer = Mockery::mock( 'PHPMailer\PHPMailer\PHPMailer' );
		$phpmailer->shouldReceive( 'isSMTP' )->once();
		$phpmailer->Host       = '';
		$phpmailer->SMTPAuth   = false;
		$phpmailer->Port       = 0;
		$phpmailer->Username   = '';
		$phpmailer->Password   = '';
		$phpmailer->SMTPSecure = '';
		$phpmailer->From       = '';
		$phpmailer->FromName   = '';

		$handler = new EmailHandler();
		$handler->configure_custom_smtp( $phpmailer );

		$this->assertSame( '', $phpmailer->Host );
		$this->assertTrue( $phpmailer->SMTPAuth );
		$this->assertSame( 587, $phpmailer->Port );
		$this->assertSame( '', $phpmailer->Username );
		$this->assertSame( '', $phpmailer->Password );
		$this->assertSame( 'tls', $phpmailer->SMTPSecure );
	}

	// ==================================================================
	// resend_link() — the operator's manual resend (#1945)
	// ==================================================================

	/**
	 * A handler whose stored row is the given submission, or none. The database is
	 * not needed: the row is what fetch_submission() would have decrypted.
	 *
	 * @param array<string, mixed>|null $submission Stored row, or null when absent.
	 */
	private function handler_with_row( ?array $submission ): EmailHandler {
		return new class( $submission ) extends EmailHandler {
			/** @var array<string, mixed>|null */
			private ?array $row;

			/** @param array<string, mixed>|null $row Stored row. */
			public function __construct( ?array $row ) {
				parent::__construct();
				$this->row = $row;
			}

			protected function fetch_submission( int $submission_id ): ?array {
				return $this->row;
			}
		};
	}

	/** Stores the mocks every link e-mail needs, as the async test sets them up. */
	private function stub_link_mail( array $form_config ): void {
		Functions\when( 'get_option' )->alias( static fn ( $key, $default = false ) => 'admin_email' === $key ? 'admin@example.com' : $default );
		Functions\when( 'is_email' )->justReturn( true );
		// The form's own body has no {{validation_url}}, so the link helper is never
		// reached and no alias mock is needed: a PHP class can be aliased only once
		// per process, and the automatic-send tests above already claim it.
		Functions\when( 'get_post_meta' )->justReturn(
			array_merge( array( 'email_subject' => 'Your certificate', 'email_body' => 'Hello {{name}}' ), $form_config )
		);
		Functions\when( 'get_the_title' )->justReturn( 'Test Certificate' );
	}

	/** A stored submission that can be resent: an address, a link token and the data. */
	private function resendable_row( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'          => 77,
				'form_id'     => 10,
				'email'       => 'user@example.com',
				'magic_token' => 'tok123',
				'auth_code'   => 'ABC123',
				'name'        => 'John',
				'status'      => 'publish',
			),
			$overrides
		);
	}

	public function test_resend_reports_a_submission_that_no_longer_exists(): void {
		$result = $this->handler_with_row( null )->resend_link( 77 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_link_not_found', $result->get_error_code() );
		$this->assertSame( array(), $this->sent_emails );
	}

	public function test_resend_refuses_a_quiz_attempt_that_did_not_pass(): void {
		$result = $this->handler_with_row( $this->resendable_row( array( 'status' => SubmissionStatus::QUIZ_FAILED ) ) )->resend_link( 77 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_link_not_passed', $result->get_error_code() );
		$this->assertSame( array(), $this->sent_emails );
	}

	public function test_resend_refuses_a_submission_in_the_trash(): void {
		$result = $this->handler_with_row( $this->resendable_row( array( 'status' => SubmissionStatus::TRASH ) ) )->resend_link( 77 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_link_trashed', $result->get_error_code() );
		$this->assertSame( array(), $this->sent_emails );
	}

	public function test_resend_refuses_a_submission_with_no_address(): void {
		$result = $this->handler_with_row( $this->resendable_row( array( 'email' => '' ) ) )->resend_link( 77 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_link_no_email', $result->get_error_code() );
	}

	public function test_resend_refuses_a_submission_with_no_link_token(): void {
		$result = $this->handler_with_row( $this->resendable_row( array( 'magic_token' => '' ) ) )->resend_link( 77 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_link_no_token', $result->get_error_code() );
	}

	public function test_resend_mails_the_stored_address_and_records_the_link(): void {
		$this->stub_link_mail( array( 'send_user_email' => 0 ) );
		$recorded = array();
		Functions\when( 'do_action' )->alias( static function ( $hook, ...$args ) use ( &$recorded ): void {
			$recorded[] = array( $hook, $args );
		} );

		$result = $this->handler_with_row( $this->resendable_row() )->resend_link( 77 );

		$this->assertTrue( $result );
		$this->assertCount( 1, $this->sent_emails );
		$this->assertSame( 'user@example.com', $this->sent_emails[0]['to'] );
		$this->assertSame( array( array( 'ffcertificate_link_sent', array( 77 ) ) ), $recorded );
	}

	public function test_resend_sends_only_the_user_mail_even_when_the_staff_notice_is_on(): void {
		$this->stub_link_mail( array( 'send_admin_email' => 1, 'email_admin' => 'staff@example.com' ) );

		$this->handler_with_row( $this->resendable_row() )->resend_link( 77 );

		$this->assertCount( 1, $this->sent_emails, 'The staff notice must not go out again on a resend.' );
		$this->assertSame( 'user@example.com', $this->sent_emails[0]['to'] );
	}

	public function test_resend_reports_when_the_mailer_did_not_take_the_mail(): void {
		$this->stub_link_mail( array() );
		Functions\when( 'wp_mail' )->justReturn( false );

		$result = $this->handler_with_row( $this->resendable_row() )->resend_link( 77 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_link_not_sent', $result->get_error_code() );
	}

	/**
	 * A handler whose stored rows are keyed by submission ID; an ID with no row
	 * stands for a submission that was deleted.
	 *
	 * @param array<int, array<string, mixed>> $rows Stored rows by submission ID.
	 */
	private function handler_with_rows( array $rows ): EmailHandler {
		return new class( $rows ) extends EmailHandler {
			/** @var array<int, array<string, mixed>> */
			private array $rows;

			/** @param array<int, array<string, mixed>> $rows Stored rows by ID. */
			public function __construct( array $rows ) {
				parent::__construct();
				$this->rows = $rows;
			}

			protected function fetch_submission( int $submission_id ): ?array {
				return $this->rows[ $submission_id ] ?? null;
			}
		};
	}

	public function test_batch_resend_sends_only_the_links_never_sent_and_counts_each_outcome(): void {
		$this->stub_link_mail( array() );
		$handler = $this->handler_with_rows(
			array(
				1 => $this->resendable_row( array( 'id' => 1 ) ),
				2 => $this->resendable_row( array( 'id' => 2, 'email' => 'other@example.com', 'link_sent_at' => 1700000000 ) ),
				3 => $this->resendable_row( array( 'id' => 3, 'email' => '' ) ),
			)
		);

		$counts = $handler->resend_unsent_links( array( 1, 2, 3, 404 ) );

		$this->assertSame(
			array(
				'sent'         => 1,
				'already_sent' => 1,
				'skipped'      => 2,
				'failed'       => 0,
			),
			$counts
		);
		$this->assertCount( 1, $this->sent_emails, 'Only the submission that had never been sent receives a mail.' );
		$this->assertSame( 'user@example.com', $this->sent_emails[0]['to'] );
	}

	public function test_batch_resend_counts_a_link_the_mailer_refused_as_failed(): void {
		$this->stub_link_mail( array() );
		Functions\when( 'wp_mail' )->justReturn( false );
		$handler = $this->handler_with_rows( array( 1 => $this->resendable_row( array( 'id' => 1 ) ) ) );

		$counts = $handler->resend_unsent_links( array( 1 ) );

		$this->assertSame( 1, $counts['failed'] );
		$this->assertSame( 0, $counts['sent'] );
	}
}
