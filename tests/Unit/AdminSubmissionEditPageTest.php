<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\AdminSubmissionEditPage;

/**
 * Behavior tests for AdminSubmissionEditPage::render() and ::handle_save().
 *
 * The static collaborators (Capabilities, DateFormatter, Encryption,
 * DocumentFormatter, MagicLinkHelper, HtmlPolicy, DataSanitizer,
 * RequestInput, Debug) are autoloaded statics, so they're replaced with
 * Mockery alias mocks; this forces process isolation (see the
 * annotations below) so the aliases don't leak across tests.
 *
 * The terminal wp_die()/wp_safe_redirect()/exit are turned into marker
 * exceptions so each branch is observable; SubmissionHandler is a normal
 * (non-alias) Mockery mock injected via the constructor.
 *
 * @covers \FreeFormCertificate\Admin\AdminSubmissionEditPage
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AdminSubmissionEditPageTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var \Mockery\MockInterface */
	private $handler;

	/** @var \Mockery\MockInterface */
	private $caps;

	/** @var \Mockery\MockInterface */
	private $ri;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		class_exists( '\\FreeFormCertificate\\Admin\\AdminSubmissionEditPage' );

		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', '/tmp/' );
		}

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_textarea' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'sanitize_key' )->alias( static fn ( $k ) => (string) $k );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'absint' )->alias( static fn ( $v ) => (int) $v );
		Functions\when( 'esc_html_e' )->alias( static function ( $text ) { echo $text; } );
		Functions\when( 'esc_attr_e' )->alias( static function ( $text ) { echo $text; } );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce123' );
		Functions\when( 'admin_url' )->returnArg();
		Functions\when( 'get_post_meta' )->justReturn( array() );
		Functions\when( 'get_userdata' )->justReturn( false );
		Functions\when( 'get_avatar' )->justReturn( '<img>' );
		Functions\when( 'get_edit_user_link' )->justReturn( 'edit-user' );
		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'wp_die' )->alias(
			static function ( $msg = '' ) {
				throw new \RuntimeException( 'WP_DIE:' . $msg );
			}
		);
		Functions\when( 'wp_safe_redirect' )->alias(
			static function ( $url ) {
				throw new \RuntimeException( 'REDIRECT:' . $url );
			}
		);

		$this->handler = Mockery::mock( 'FreeFormCertificate\Submissions\SubmissionHandler' );

		// Static collaborator alias mocks (sensible defaults; overridable per test).
		$this->caps = Mockery::mock( 'alias:FreeFormCertificate\Core\Capabilities' );
		$this->caps->shouldReceive( 'current_user_can_admin_or' )->andReturn( true )->byDefault();

		$df = Mockery::mock( 'alias:FreeFormCertificate\Core\DateFormatter' );
		$df->shouldReceive( 'format_datetime' )->andReturn( '2026-01-01 10:00' )->byDefault();

		$enc = Mockery::mock( 'alias:FreeFormCertificate\Core\Encryption' );
		$enc->shouldReceive( 'decrypt_field' )->andReturn( '' )->byDefault();

		// DocumentFormatter is a pure-PHP autoloaded class (real constant +
		// static helpers, no WP deps) — use it as-is rather than alias-mocking.

		$mlh = Mockery::mock( 'alias:FreeFormCertificate\Generators\MagicLinkHelper' );
		$mlh->shouldReceive( 'get_magic_link_html' )->andReturn( '<a>link</a>' )->byDefault();

		$html = Mockery::mock( 'alias:FreeFormCertificate\Core\HtmlPolicy' );
		$html->shouldReceive( 'get_allowed_html_tags' )->andReturn( array() )->byDefault();

		$san = Mockery::mock( 'alias:FreeFormCertificate\Core\DataSanitizer' );
		$san->shouldReceive( 'normalize_brazilian_name' )->andReturnUsing( static fn ( $v ) => 'NORM:' . $v )->byDefault();

		$this->ri = Mockery::mock( 'alias:FreeFormCertificate\Core\RequestInput' );
		$this->ri->shouldReceive( 'get_post_string' )->andReturn( '__keep__' )->byDefault();

		$dbg = Mockery::mock( 'alias:FreeFormCertificate\Core\Debug' );
		$dbg->shouldReceive( 'log_admin' )->andReturnNull()->byDefault();

		// wp_kses passthrough (depends on HtmlPolicy alias being live).
		Functions\when( 'wp_kses' )->alias( static fn ( $v, $allowed = array() ) => $v );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		unset(
			$_POST['ffc_save_edit'],
			$_POST['submission_id'],
			$_POST['user_email'],
			$_POST['data'],
			$_POST['linked_user_id']
		);
		parent::tearDown();
	}

	private function page(): AdminSubmissionEditPage {
		return new AdminSubmissionEditPage( $this->handler );
	}

	private function capture( callable $fn ): string {
		try {
			$fn();
		} catch ( \RuntimeException $e ) {
			return $e->getMessage();
		}
		return '';
	}

	/**
	 * Full submission fixture (object, as wpdb returns).
	 *
	 * @param array<string,mixed> $overrides Field overrides.
	 * @return object
	 */
	private function submission_row( array $overrides = array() ): object {
		$base = array(
			'id'                => 42,
			'form_id'           => 5,
			'data'              => wp_json_encode_local(
				array(
					'nome_completo' => 'joão silva',
					'curso'         => 'PHP',
					'tags'          => array( 'a', 'b' ),
					'auth_code'     => 'XYZ',
					'is_edited'     => 1,
					'edited_at'     => 123,
				)
			),
			'submission_date'   => 1700000000,
			'status'            => 'publish',
			'magic_token'       => 'TOKEN',
			'user_ip'           => '1.2.3.4',
			'user_ip_encrypted' => 1,
			'user_id'           => 0,
			'consent_given'     => 1,
			'consent_date'      => 1700000000,
			'email'             => 'foo@bar.com',
			'email_encrypted'   => 1,
			'cpf_rf'            => '12345678900',
			'rf'                => '',
			'cpf_encrypted'     => 1,
			'auth_code'         => 'AUTH123',
			'edited_at'         => 1700000000,
			'edited_by'         => 7,
		);
		return (object) array_merge( $base, $overrides );
	}

	// ==================================================================
	// Constructor
	// ==================================================================

	public function test_constructor_creates_instance(): void {
		$this->assertInstanceOf( AdminSubmissionEditPage::class, $this->page() );
	}

	// ==================================================================
	// render() — permission gate
	// ==================================================================

	public function test_render_shows_error_without_permission(): void {
		$this->caps->shouldReceive( 'current_user_can_admin_or' )->andReturn( false );

		ob_start();
		$this->page()->render( 1 );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'do not have permission', $output );
	}

	// ==================================================================
	// render() — not found
	// ==================================================================

	public function test_render_shows_error_for_invalid_submission(): void {
		$this->handler->shouldReceive( 'get_submission' )->with( 999 )->andReturn( null );

		ob_start();
		$this->page()->render( 999 );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'not found', $output );
	}

	// ==================================================================
	// render() — full render
	// ==================================================================

	public function test_render_full_outputs_all_sections(): void {
		$this->handler->shouldReceive( 'get_submission' )->with( 42 )->andReturn( $this->submission_row() );

		ob_start();
		$this->page()->render( 42 );
		$html = (string) ob_get_clean();

		// Title + form scaffolding.
		$this->assertStringContainsString( 'Edit Submission', $html );
		$this->assertStringContainsString( 'ffc-edit-submission-form', $html );
		$this->assertStringContainsString( 'name="submission_id"', $html );
		// System info section.
		$this->assertStringContainsString( 'System Information', $html );
		$this->assertStringContainsString( 'Magic Link Token', $html );
		$this->assertStringContainsString( '<a>link</a>', $html );
		// Encrypted IP notice.
		$this->assertStringContainsString( 'User IP', $html );
		// Edited warning (edited_at set, edited_by resolves to "ID: 7" because get_userdata=false).
		$this->assertStringContainsString( 'ffc-edited-notice', $html );
		// Consent section.
		$this->assertStringContainsString( 'LGPD Consent Status', $html );
		$this->assertStringContainsString( 'Consent given', $html );
		// Participant data.
		$this->assertStringContainsString( 'Participant Data', $html );
		$this->assertStringContainsString( 'name="user_email"', $html );
		// Real DocumentFormatter: 11-digit CPF formatted, auth code prefixed.
		$this->assertStringContainsString( '123.456.789-00', $html );
		$this->assertStringContainsString( 'C-AUTH123', $html );
		// Dynamic fields (label fallback to key; protected auth_code field).
		$this->assertStringContainsString( 'name="data[nome_completo]"', $html );
		$this->assertStringContainsString( 'name="data[curso]"', $html );
		// Array value joined.
		$this->assertStringContainsString( 'a, b', $html );
		// is_edited / edited_at JSON keys skipped.
		$this->assertStringNotContainsString( 'name="data[is_edited]"', $html );
	}

	public function test_render_uses_field_labels_and_linked_user(): void {
		$row = $this->submission_row(
			array(
				'user_id'     => 9,
				'magic_token' => '',
				'user_ip'     => '',
			)
		);
		$this->handler->shouldReceive( 'get_submission' )->with( 42 )->andReturn( $row );

		Functions\when( 'get_userdata' )->alias(
			static function ( $id ) {
				if ( 9 === (int) $id ) {
					return (object) array(
						'display_name' => 'Jane Doe',
						'user_email'   => 'jane@x.com',
					);
				}
				return false;
			}
		);
		Functions\when( 'get_post_meta' )->justReturn(
			array(
				array( 'name' => 'curso', 'label' => 'Curso Label' ),
			)
		);

		ob_start();
		$this->page()->render( 42 );
		$html = (string) ob_get_clean();

		// Custom field label used.
		$this->assertStringContainsString( 'Curso Label', $html );
		// Linked user branch.
		$this->assertStringContainsString( 'Jane Doe', $html );
		$this->assertStringContainsString( 'Unlink User', $html );
		// Magic-token-empty branch.
		$this->assertStringContainsString( 'created before magic links', $html );
	}

	public function test_render_no_consent_and_rf_label(): void {
		$row = $this->submission_row(
			array(
				'consent_given'   => 0,
				'consent_date'    => 0,
				'edited_at'       => 0,
				'rf'              => '99999',
				'rf_encrypted'    => 1,
				'cpf_encrypted'   => 0,
				'auth_code'       => '',
				'email_encrypted' => 0,
			)
		);
		$this->handler->shouldReceive( 'get_submission' )->with( 42 )->andReturn( $row );

		ob_start();
		$this->page()->render( 42 );
		$html = (string) ob_get_clean();

		// No-consent branch.
		$this->assertStringContainsString( 'No consent recorded', $html );
		// RF label (because rf is non-empty).
		$this->assertStringContainsString( 'RF', $html );
		// No edited notice when edited_at empty.
		$this->assertStringNotContainsString( 'ffc-edited-notice', $html );
		// Auth code row omitted (auth_code empty).
		$this->assertStringNotContainsString( 'Auth Code', $html );
	}

	public function test_render_handles_empty_json_data(): void {
		$row = $this->submission_row( array( 'data' => 'not-json' ) );
		$this->handler->shouldReceive( 'get_submission' )->with( 42 )->andReturn( $row );

		ob_start();
		$this->page()->render( 42 );
		$html = (string) ob_get_clean();

		// Still renders without dynamic fields.
		$this->assertStringContainsString( 'Participant Data', $html );
		$this->assertStringNotContainsString( 'name="data[', $html );
	}

	// ==================================================================
	// handle_save()
	// ==================================================================

	public function test_handle_save_does_nothing_without_post(): void {
		unset( $_POST['ffc_save_edit'] );
		$this->handler->shouldNotReceive( 'update_submission' );
		$this->page()->handle_save();
		$this->assertTrue( true );
	}

	public function test_handle_save_dies_without_permission(): void {
		$_POST['ffc_save_edit'] = '1';
		$this->caps->shouldReceive( 'current_user_can_admin_or' )->andReturn( false );

		$msg = $this->capture( fn () => $this->page()->handle_save() );
		$this->assertStringStartsWith( 'WP_DIE:', $msg );
	}

	public function test_handle_save_returns_on_bad_nonce(): void {
		$_POST['ffc_save_edit'] = '1';
		Functions\when( 'check_admin_referer' )->justReturn( false );

		$this->handler->shouldNotReceive( 'update_submission' );
		$this->page()->handle_save();
		$this->assertTrue( true );
	}

	public function test_handle_save_happy_path_updates_and_redirects(): void {
		$_POST['ffc_save_edit']  = '1';
		$_POST['submission_id']  = '42';
		$_POST['user_email']     = 'NEW@Bar.COM';
		$_POST['data']           = array(
			'nome_completo' => 'joão silva',
			'curso'         => 'PHP',
		);

		$this->handler->shouldReceive( 'update_submission' )
			->once()
			->with(
				42,
				'new@bar.com',
				Mockery::on(
					static function ( $data ) {
						return isset( $data['nome_completo'] )
							&& 'NORM:joão silva' === $data['nome_completo']
							&& 'PHP' === $data['curso'];
					}
				)
			);

		$msg = $this->capture( fn () => $this->page()->handle_save() );
		$this->assertStringStartsWith( 'REDIRECT:', $msg );
		$this->assertStringContainsString( 'msg=updated', $msg );
	}

	public function test_handle_save_invalid_id_passes_zero(): void {
		$_POST['ffc_save_edit'] = '1';
		// No submission_id -> 0.
		$this->handler->shouldReceive( 'update_submission' )->once()->with( 0, '', array() );

		$msg = $this->capture( fn () => $this->page()->handle_save() );
		$this->assertStringStartsWith( 'REDIRECT:', $msg );
	}

	public function test_handle_save_links_valid_user(): void {
		$_POST['ffc_save_edit'] = '1';
		$_POST['submission_id'] = '42';

		$this->ri->shouldReceive( 'get_post_string' )->andReturn( '15' );
		Functions\when( 'get_userdata' )->justReturn( (object) array( 'ID' => 15 ) );

		$this->handler->shouldReceive( 'update_submission' )->once();
		$this->handler->shouldReceive( 'update_user_link' )->once()->with( 42, 15 );

		$msg = $this->capture( fn () => $this->page()->handle_save() );
		$this->assertStringStartsWith( 'REDIRECT:', $msg );
	}

	public function test_handle_save_unlinks_user(): void {
		$_POST['ffc_save_edit'] = '1';
		$_POST['submission_id'] = '42';

		$this->ri->shouldReceive( 'get_post_string' )->andReturn( '' );

		$this->handler->shouldReceive( 'update_submission' )->once();
		$this->handler->shouldReceive( 'update_user_link' )->once()->with( 42, null );

		$msg = $this->capture( fn () => $this->page()->handle_save() );
		$this->assertStringStartsWith( 'REDIRECT:', $msg );
	}

	public function test_handle_save_skips_link_for_invalid_user(): void {
		$_POST['ffc_save_edit'] = '1';
		$_POST['submission_id'] = '42';

		$this->ri->shouldReceive( 'get_post_string' )->andReturn( '888' );
		// get_userdata(888) -> false (default stub) means invalid.
		Functions\when( 'get_userdata' )->justReturn( false );

		$this->handler->shouldReceive( 'update_submission' )->once();
		$this->handler->shouldNotReceive( 'update_user_link' );

		$msg = $this->capture( fn () => $this->page()->handle_save() );
		$this->assertStringStartsWith( 'REDIRECT:', $msg );
	}
}

/**
 * Local JSON encoder for the fixture (avoids depending on WP's wp_json_encode
 * being stubbed, since the fixture is built in PHP-land before render runs).
 *
 * @param array<string,mixed> $data Data to encode.
 * @return string
 */
function wp_json_encode_local( array $data ): string {
	return (string) json_encode( $data );
}
