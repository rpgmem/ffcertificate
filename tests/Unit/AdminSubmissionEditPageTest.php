<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\AdminSubmissionEditPage;
use FreeFormCertificate\Admin\AnswerCorrectionHandler;

/**
 * Behavior tests for AdminSubmissionEditPage::render() and ::handle_save().
 *
 * The static collaborators (Capabilities, DateFormatter, DocumentFormatter,
 * MagicLinkHelper, DataSanitizer, RequestInput, Debug, PiiAccessPolicy) are
 * the real classes, reading WordPress through global Brain\Monkey stubs: the
 * capability decision is `current_user_can()`, the magic link is built from
 * `home_url()`, and the link field is read from `$_POST`.
 *
 * The terminal wp_die()/wp_safe_redirect()/exit are turned into marker
 * exceptions so each branch is observable; SubmissionHandler is a normal
 * Mockery mock injected via the constructor, so the page never reaches
 * storage.
 *
 * @covers \FreeFormCertificate\Admin\AdminSubmissionEditPage
 */
class AdminSubmissionEditPageTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var \Mockery\MockInterface */
	private $handler;

	/**
	 * Capabilities `current_user_can()` refuses; every other one is granted.
	 *
	 * @var list<string>
	 */
	private array $denied = array();

	/** @var array<string, mixed> `$_POST` as an earlier test left it. */
	private array $post_backup = array();

	/** @var array<string, mixed> `$_GET` as an earlier test left it. */
	private array $get_backup = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->post_backup = $_POST ?? array();
		$this->get_backup  = $_GET ?? array();
		$this->denied      = array();
		Functions\when( 'wp_admin_notice' )->alias(
			static function ( $message, $args = array() ) {
				$ffc_type = isset( $args['type'] ) ? $args['type'] : 'info';
				$ffc_cls  = 'notice notice-' . $ffc_type;
				if ( ! empty( $args['dismissible'] ) ) { $ffc_cls .= ' is-dismissible'; }
				if ( ! empty( $args['additional_classes'] ) ) { $ffc_cls .= ' ' . implode( ' ', $args['additional_classes'] ); }
				$ffc_wrap = ! array_key_exists( 'paragraph_wrap', $args ) || $args['paragraph_wrap'];
				echo '<div class="' . $ffc_cls . '">' . ( $ffc_wrap ? '<p>' . $message . '</p>' : $message ) . '</div>';
			}
		);
		class_exists( '\\FreeFormCertificate\\Admin\\AdminSubmissionEditPage' );

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
		Functions\when( 'add_query_arg' )->alias( static fn( $args, $url ) => $url . '?' . http_build_query( $args ) );
		Functions\when( 'wp_nonce_url' )->alias( static fn( $url, $action ) => $url . '&_wpnonce=' . $action );
		Functions\when( 'get_post_meta' )->justReturn( array() );
		Functions\when( 'get_userdata' )->justReturn( false );
		Functions\when( 'get_avatar' )->justReturn( '<img>' );
		Functions\when( 'get_edit_user_link' )->justReturn( 'edit-user' );
		// #739 §3.3 — the participant block resolves Core\PiiAccessPolicy; stub
		// the WP user funcs so it resolves to the UNMASKED tier (super-admin).
		// Since #1655 that tier is masked on this screen too, behind Reveal.
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'is_email' )->alias( static fn( $e ) => false !== filter_var( (string) $e, FILTER_VALIDATE_EMAIL ) ? $e : false );
		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'get_user_by' )->justReturn( false );
		Functions\when( 'check_admin_referer' )->justReturn( true );
		// The real Capabilities::current_user_can_admin_or() asks
		// manage_options first, then the granular capability.
		Functions\when( 'current_user_can' )->alias( fn( $cap ) => ! in_array( $cap, $this->denied, true ) );
		// SettingsReader, Debug and MagicLinkHelper read options; every read
		// answers its own default, so debug is off and the magic link falls
		// back to home_url( '/valid/' ).
		Functions\when( 'get_option' )->alias( static fn( $key, $default = false ) => $default );
		Functions\when( 'home_url' )->alias( static fn( $path = '' ) => 'https://example.org' . $path );
		Functions\when( 'wp_timezone' )->alias( static fn() => new \DateTimeZone( 'UTC' ) );
		Functions\when( 'wp_date' )->alias( static fn( $format, $ts = null ) => gmdate( (string) $format, (int) $ts ) );
		Functions\when( 'wp_kses' )->alias( static fn ( $v, $allowed = array() ) => $v );
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
		// The save reads the stored answers back to carry the grading records (#1732).
		$this->handler->shouldReceive( 'get_submission' )->andReturn( null )->byDefault();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		$_POST = $this->post_backup;
		$_GET  = $this->get_backup;
		parent::tearDown();
	}

	/**
	 * JSON for the fixture's `data` column, as the database stores it.
	 *
	 * @param array<string,mixed> $data Data to encode.
	 * @return string
	 */
	private static function json( array $data ): string {
		return (string) json_encode( $data );
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
			'data'              => self::json(
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
			'cpf_rf'            => '51817842080',
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
		$this->denied = array( 'manage_options', 'ffc_edit_certificates' );

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
		// Magic link card in the side panel (#1614): the link is masked behind an
		// audited Reveal and the certificate opens through the audited route (#2241).
		$this->assertStringContainsString( 'class="ffc-edit-layout"', $html );
		$this->assertStringNotContainsString( '#token=TOKEN', $html, 'The bearer link is never in the page.' );
		$this->assertStringContainsString( 'class="ffc-pii-value ffc-magic-link" data-field="magic_link"', $html );
		$this->assertStringContainsString( 'data-field="magic_link"', $html );
		$this->assertStringContainsString( 'href="admin-post.php?action=ffc_open_certificate&submission_id=42&_wpnonce=ffc_open_certificate_42" class="ffc-btn ffc-btn-neutral ffc-icon-external" target="_blank" rel="noopener">Open certificate</a>', $html );
		$this->assertStringNotContainsString( 'style="display', $html, 'visibility is the shared class, never an inline style' );
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
		// Real DocumentFormatter: the CPF and email are masked even for an
		// administrator (#1655); the auth code is prefixed.
		$this->assertStringNotContainsString( '518.178.420-80', $html );
		$this->assertStringNotContainsString( '51817842080', $html );
		$this->assertStringNotContainsString( 'foo@bar.com', $html );
		$this->assertStringContainsString( 'C-AUTH123', $html );
		// Array value joined.
		$this->assertStringContainsString( 'a, b', $html );
		// Answers are locked (#1977): they read as text and are never posted,
		// since the save keeps what is stored.
		$this->assertStringNotContainsString( 'ffc-edit-data-', $html );
		$this->assertStringNotContainsString( 'name="data[', $html );
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
				array(
					'name'  => 'curso',
					'label' => 'Curso Label',
				),
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

	// ==================================================================
	// render() — the save marker and the user-link field
	// ==================================================================

	/**
	 * The regression test for the unlink that never worked.
	 *
	 * The marker `handle_save()` gates on used to be the submit button's `name`,
	 * and a submit button contributes its name/value only when the browser
	 * ACTIVATES it. The unlink control calls `form.submit()`, which activates
	 * nothing -- so the POST arrived without the marker and the handler returned
	 * on its first line.
	 *
	 * This asserts at the level the defect lived at: the MARKUP. Every existing
	 * `handle_save()` test sets `$_POST['ffc_save_edit']` by hand, so all of them
	 * passed throughout -- they supplied the one precondition the real unlink
	 * path could not meet.
	 */
	public function test_render_carries_the_save_marker_as_a_field_not_a_button_name(): void {
		$this->handler->shouldReceive( 'get_submission' )->with( 42 )->andReturn( $this->submission_row() );
		Functions\when( 'get_post_meta' )->justReturn( array() );

		ob_start();
		$this->page()->render( 42 );
		$html = (string) ob_get_clean();

		// It is a field of the form, so ANY submit path carries it.
		$this->assertMatchesRegularExpression(
			'/<input[^>]+type="hidden"[^>]+name="ffc_save_edit"/',
			$html,
			'The save marker must be a hidden field. As the submit button\'s name it is absent from any programmatic submit, which is how unlinking silently did nothing.'
		);

		// And it is not ALSO the button's name, which would be two sources for
		// one fact and would let the field be dropped without anything failing.
		$this->assertDoesNotMatchRegularExpression(
			'/<button[^>]+name="ffc_save_edit"/',
			$html,
			'The submit button must not carry the marker as well: one fact, one place.'
		);
	}

	/**
	 * The link field defaults to `__keep__` whether or not a user is linked.
	 *
	 * When nothing was linked it used to default to `''`, which the handler reads
	 * as "unlink" -- so every ordinary save of an unlinked submission called
	 * `update_user_link( id, null )`, bumping `edited_at`/`edited_by` and writing
	 * a `user_unlinked` activity entry against a submission that never had a
	 * user. Nothing failed, so nothing reported it.
	 */
	public function test_render_defaults_the_user_link_field_to_keep_when_unlinked(): void {
		$this->handler->shouldReceive( 'get_submission' )->with( 42 )->andReturn( $this->submission_row( array( 'user_id' => 0 ) ) );
		Functions\when( 'get_userdata' )->justReturn( false );
		Functions\when( 'get_post_meta' )->justReturn( array() );

		ob_start();
		$this->page()->render( 42 );
		$html = (string) ob_get_clean();

		$this->assertMatchesRegularExpression(
			'/<input[^>]+name="linked_user_id"[^>]+value="__keep__"/',
			$html,
			'An unlinked submission must still default to "change nothing", or saving it unlinks a user it never had.'
		);

		// Exactly one input carries the name: two would let the later one win in
		// the POST, silently, depending on markup order.
		$this->assertSame(
			1,
			preg_match_all( '/name="linked_user_id"/', $html ),
			'The decision is one field with three states; a second input of the same name makes the POST order-dependent.'
		);
	}

	/**
	 * A linked submission offers relinking without unlinking first.
	 *
	 * The search block used to render only in the unlinked state, so moving a
	 * submission to the right account meant unlink, save, search, save -- and the
	 * first of those four steps was the one that did not work.
	 */
	public function test_render_offers_relink_when_a_user_is_linked(): void {
		$this->handler->shouldReceive( 'get_submission' )->with( 42 )->andReturn( $this->submission_row( array( 'user_id' => 9 ) ) );
		Functions\when( 'get_userdata' )->justReturn(
			(object) array(
				'display_name' => 'Jane Doe',
				'user_email'   => 'jane@x.com',
			)
		);
		Functions\when( 'get_post_meta' )->justReturn( array() );

		ob_start();
		$this->page()->render( 42 );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Unlink User', $html );
		$this->assertStringContainsString( 'ffc-relink-user-btn', $html );
		// The search form is present, so the reveal has something to reveal.
		$this->assertStringContainsString( 'ffc-search-user-btn', $html );
		$this->assertStringContainsString( 'ffc-user-search-input', $html );
	}

	/**
	 * @return array<string, array{0: list<string>, 1: list<string>, 2: bool}>
	 */
	public static function account_email_tiers(): array {
		return array(
			'WordPress admin (reveal)'                     => array( array( 'manage_options' ), array(), true ),
			// The role unmasks nothing on its own since #1863.
			'certificates admin role without the cap'      => array( array(), array( 'ffc_certificates_admin' ), false ),
			'certificates admin role with its cap (reveal)' => array( array( 'ffc_view_certificates_pii' ), array( 'ffc_certificates_admin' ), true ),
			'certificates PII cap (reveal)'                => array( array( 'ffc_view_certificates_pii' ), array(), true ),
			'no PII access (masked)'                       => array( array( 'ffc_view_certificates' ), array(), false ),
		);
	}

	/**
	 * The linked account's email is masked at every tier, behind the same
	 * gate and Reveal button as the submission's own values (#1824). It used
	 * to print in clear for every viewer of the screen.
	 *
	 * @dataProvider account_email_tiers
	 *
	 * @param list<string> $caps   Viewer caps.
	 * @param list<string> $roles  Viewer roles.
	 * @param bool         $button Whether a Reveal button is drawn.
	 */
	public function test_render_masks_the_linked_account_email_at_every_tier( array $caps, array $roles, bool $button ): void {
		Functions\when( 'user_can' )->alias( static fn( $uid, $cap ) => in_array( $cap, $caps, true ) );
		Functions\when( 'get_user_by' )->justReturn( (object) array( 'roles' => $roles ) );
		$this->handler->shouldReceive( 'get_submission' )->with( 42 )->andReturn( $this->submission_row( array( 'user_id' => 9 ) ) );
		Functions\when( 'get_userdata' )->alias(
			static fn( $id ) => 9 === (int) $id ? (object) array( 'display_name' => 'Jane Doe', 'user_email' => 'jane@x.com' ) : false
		);

		ob_start();
		$this->page()->render( 42 );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Jane Doe', $html );
		$this->assertStringNotContainsString( 'jane@x.com', $html, 'no tier receives the clear address' );
		$this->assertStringContainsString( '<span class="ffc-user-email"><span class="ffc-pii-value" data-field="account_email">j***@x.com</span></span>', $html );
		// The Reveal sits in the actions row below the card, before View Profile.
		$actions = (string) strstr( $html, '<div class="ffc-linked-user-actions">' );
		$this->assertStringContainsString( 'View Profile', $actions );
		$this->assertSame( $button, false !== strpos( $actions, 'data-field="account_email"' ) );
		$this->assertSame(
			$button ? 1 : 0,
			substr_count( $html, '<button type="button" class="ffc-btn ffc-btn-neutral ffc-icon-eye ffc-reveal-pii" data-field="account_email" data-submission-id="42" data-nonce="nonce123">Reveal</button>' )
		);
	}

	public function test_render_masks_cpf_and_email_for_an_administrator_and_offers_reveal(): void {
		$this->handler->shouldReceive( 'get_submission' )->with( 42 )->andReturn( $this->submission_row() );

		ob_start();
		$this->page()->render( 42 );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'value="f***@bar.com"', $html );
		$this->assertMatchesRegularExpression( '/<input type="email" name="user_email"[^>]*data-ffc-pii-editable="1" disabled required>/', $html, 'the masked email is not posted until revealed' );
		$this->assertStringContainsString( 'data-field="email"', $html );
		$this->assertStringContainsString( 'data-field="cpf"', $html );
		// Email, CPF, the IP under System Information and under the consent, and
		// the certificate's magic link (#2241).
		$this->assertSame( 5, substr_count( $html, 'class="ffc-btn ffc-btn-neutral ffc-icon-eye ffc-reveal-pii"' ) );
		$this->assertStringContainsString( 'data-submission-id="42"', $html );
		$this->assertStringContainsString( 'Reveal the email to edit it.', $html );
	}

	public function test_render_puts_each_reveal_button_on_its_value_row(): void {
		$this->handler->shouldReceive( 'get_submission' )->with( 42 )->andReturn( $this->submission_row() );

		ob_start();
		$this->page()->render( 42 );
		$html = (string) ob_get_clean();

		// The email and the CPF: the masked value and its button share one row (#1760).
		$this->assertMatchesRegularExpression( '#<span class="ffc-pii-row">\s*<input type="email" name="user_email"[^>]*>\s*<button type="button" class="ffc-btn ffc-btn-neutral ffc-icon-eye ffc-reveal-pii" data-field="email"#', $html );
		$this->assertMatchesRegularExpression( '#<span class="ffc-pii-row"><input type="text" value="518\.\*\*\*\.\*\*\*-80"[^>]*data-ffc-pii-field="cpf"[^>]*><button type="button" class="ffc-btn ffc-btn-neutral ffc-icon-eye ffc-reveal-pii" data-field="cpf"#', $html );
	}

	public function test_render_masks_both_ip_displays_behind_reveal(): void {
		$this->handler->shouldReceive( 'get_submission' )->with( 42 )->andReturn( $this->submission_row( array( 'user_ip' => '203.0.113.7' ) ) );

		ob_start();
		$this->page()->render( 42 );
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( '203.0.113.7', $html, 'the clear IP never sits in the page' );
		$this->assertSame( 2, substr_count( $html, 'value="203.0.***.***"' ), 'System Information and the consent both show the masked IP' );
		$this->assertSame( 2, preg_match_all( '#data-ffc-pii-field="ip" readonly aria-label="[^"]+"><button type="button" class="ffc-btn ffc-btn-neutral ffc-icon-eye ffc-reveal-pii" data-field="ip"#', $html ) );
		$this->assertStringContainsString( 'This IP is encrypted in the database.', $html );
	}

	public function test_render_without_the_pii_capability_shows_the_ip_masked_with_no_reveal(): void {
		Functions\when( 'user_can' )->justReturn( false );
		$this->handler->shouldReceive( 'get_submission' )->with( 42 )->andReturn( $this->submission_row( array( 'user_ip' => '2001:db8::1' ) ) );

		ob_start();
		$this->page()->render( 42 );
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( '2001:db8::1', $html );
		$this->assertSame( 2, substr_count( $html, 'value="2001:db8:0:0:****:****:****:****"' ) );
		$this->assertStringNotContainsString( 'data-field="ip"', $html );
	}

	public function test_render_draws_every_record_section_as_the_shared_section(): void {
		$this->handler->shouldReceive( 'get_submission' )->with( 42 )->andReturn( $this->submission_row() );

		ob_start();
		$this->page()->render( 42 );
		$html = (string) ob_get_clean();

		// Every section starts closed: the administrator opens the one they need.
		$this->assertSame( 0, substr_count( $html, '<details class="ffc-section" open data-ffc-section>' ) );
		$this->assertSame( 4, substr_count( $html, '<details class="ffc-section" data-ffc-section>' ) );
		foreach ( array( 'Participant Data', 'Form answers', 'System Information', 'LGPD Consent Status' ) as $title ) {
			$this->assertMatchesRegularExpression( '#<span class="ffc-section__icon"><svg[^>]*>.*?</svg></span><span class="ffc-section__text"><span class="ffc-section__title">' . preg_quote( $title, '#' ) . '</span>#s', $html, $title . ' has the section icon heading' );
		}
		// The record sections no longer use the card heading; only the side panel does.
		$this->assertStringNotContainsString( 'ffc-edit-card__title ffc-icon-info', $html );
		$this->assertStringNotContainsString( 'ffc-edit-card__title ffc-icon-list', $html );
		$this->assertSame( 1, substr_count( $html, 'ffc-edit-card__title ffc-icon-user' ), 'only the Linked User card keeps the user icon card' );
	}

	public function test_render_without_the_pii_capability_masks_and_offers_no_reveal(): void {
		Functions\when( 'user_can' )->justReturn( false );
		$this->handler->shouldReceive( 'get_submission' )->with( 42 )->andReturn( $this->submission_row() );

		ob_start();
		$this->page()->render( 42 );
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( '518.178.420-80', $html );
		$this->assertStringNotContainsString( 'foo@bar.com', $html );
		$this->assertStringContainsString( 'value="f***@bar.com"', $html );
		$this->assertStringNotContainsString( 'ffc-reveal-pii', $html );
		$this->assertStringNotContainsString( 'Reveal the email to edit it.', $html );
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
		$this->denied = array( 'manage_options', 'ffc_edit_certificates' );

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
		$stored                  = array(
			'nome_completo' => 'João Silva',
			'curso'         => 'PHP',
		);
		$this->handler->shouldReceive( 'get_submission' )->with( 42 )->andReturn( (array) $this->submission_row( array( 'data' => self::json( $stored ) ) ) );

		$this->handler->shouldReceive( 'update_submission' )->once()->with( 42, 'new@bar.com', $stored );

		$msg = $this->capture( fn () => $this->page()->handle_save() );
		$this->assertStringStartsWith( 'REDIRECT:', $msg );
		$this->assertStringContainsString( 'msg=updated', $msg );
	}

	public function test_handle_save_keeps_the_email_when_it_was_not_revealed(): void {
		// A masked email input is disabled, so the browser does not post it;
		// an empty email reaches the handler, which leaves the column alone.
		$_POST['ffc_save_edit'] = '1';
		$_POST['submission_id'] = '42';
		$_POST['data']          = array( 'curso' => 'PHP' );

		$this->handler->shouldReceive( 'update_submission' )->once()->with( 42, '', Mockery::type( 'array' ) );

		$msg = $this->capture( fn () => $this->page()->handle_save() );
		$this->assertStringStartsWith( 'REDIRECT:', $msg );
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

		$_POST['linked_user_id'] = '15';
		Functions\when( 'get_userdata' )->justReturn( (object) array( 'ID' => 15 ) );

		$this->handler->shouldReceive( 'update_submission' )->once();
		$this->handler->shouldReceive( 'update_user_link' )->once()->with( 42, 15 );

		$msg = $this->capture( fn () => $this->page()->handle_save() );
		$this->assertStringStartsWith( 'REDIRECT:', $msg );
	}

	public function test_handle_save_unlinks_user(): void {
		$_POST['ffc_save_edit'] = '1';
		$_POST['submission_id'] = '42';

		$_POST['linked_user_id'] = '';

		$this->handler->shouldReceive( 'update_submission' )->once();
		$this->handler->shouldReceive( 'update_user_link' )->once()->with( 42, null );

		$msg = $this->capture( fn () => $this->page()->handle_save() );
		$this->assertStringStartsWith( 'REDIRECT:', $msg );
	}

	/**
	 * #2241 — changing the owner hands the certificate to an account of the
	 * editor's choosing, so an editor without the manage capability cannot.
	 */
	public function test_handle_save_ignores_a_link_change_without_the_manage_capability(): void {
		$this->denied            = array( 'manage_options', 'ffc_manage_certificates' );
		$_POST['ffc_save_edit']  = '1';
		$_POST['submission_id']  = '42';
		$_POST['linked_user_id'] = '15';
		Functions\when( 'get_userdata' )->justReturn( (object) array( 'ID' => 15 ) );

		$this->handler->shouldReceive( 'update_submission' )->once();
		$this->handler->shouldNotReceive( 'update_user_link' );

		$msg = $this->capture( fn () => $this->page()->handle_save() );
		$this->assertStringStartsWith( 'REDIRECT:', $msg );
	}

	public function test_render_offers_no_relink_without_the_manage_capability(): void {
		$this->denied = array( 'manage_options', 'ffc_manage_certificates' );
		Functions\when( 'get_userdata' )->justReturn(
			(object) array(
				'ID'           => 7,
				'display_name' => 'Ana',
				'user_email'   => 'ana@example.test',
			)
		);
		Functions\when( 'get_avatar' )->justReturn( '' );
		Functions\when( 'get_edit_user_link' )->justReturn( 'user-edit.php?user_id=7' );
		$this->handler->shouldReceive( 'get_submission' )->with( 42 )->andReturn( $this->submission_row( array( 'user_id' => 7 ) ) );

		ob_start();
		$this->page()->render( 42 );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Ana', $html );
		$this->assertStringNotContainsString( 'ffc-unlink-user-btn', $html );
		$this->assertStringNotContainsString( 'ffc-relink-user-btn', $html );
		$this->assertStringNotContainsString( 'ffc-user-search-input', $html );
	}

	public function test_render_hides_the_magic_link_from_a_user_who_cannot_reveal(): void {
		$this->denied = array( 'manage_options', 'ffc_view_certificates_pii' );
		Functions\when( 'user_can' )->alias( fn( $uid, $cap ) => ! in_array( $cap, $this->denied, true ) );
		$this->handler->shouldReceive( 'get_submission' )->with( 42 )->andReturn( $this->submission_row() );

		ob_start();
		$this->page()->render( 42 );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Opening the certificate or its link needs permission to reveal personal data.', $html );
		$this->assertStringNotContainsString( 'ffc_open_certificate', $html );
		$this->assertStringNotContainsString( 'data-field="magic_link"', $html );
	}

	public function test_handle_save_skips_link_for_invalid_user(): void {
		$_POST['ffc_save_edit'] = '1';
		$_POST['submission_id'] = '42';

		$_POST['linked_user_id'] = '888';
		// get_userdata(888) -> false (default stub) means invalid.
		Functions\when( 'get_userdata' )->justReturn( false );

		$this->handler->shouldReceive( 'update_submission' )->once();
		$this->handler->shouldNotReceive( 'update_user_link' );

		$msg = $this->capture( fn () => $this->page()->handle_save() );
		$this->assertStringStartsWith( 'REDIRECT:', $msg );
	}
	/**
	 * #1731 — the window-leave count reads as a labelled fact, not an answer
	 * to edit, and is still posted so a save keeps it.
	 */
	public function test_render_shows_the_window_leave_count_as_a_protected_fact(): void {
		$row = $this->submission_row( array( 'data' => self::json( array( '_quiz_focus_losses' => 3 ) ) ) );
		$this->handler->shouldReceive( 'get_submission' )->with( 42 )->andReturn( $row );

		ob_start();
		$this->page()->render( 42 );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<dt class="ffc-facts__label">Times the quiz window was left</dt>', $html );
		$this->assertStringNotContainsString( 'name="data[_quiz_focus_losses]"', $html );
		$this->assertStringNotContainsString( 'type="text" id="ffc-edit-data-_quiz_focus_losses"', $html );
	}

	/**
	 * #1732 — the grading records are lists a text field would flatten, so
	 * the form neither shows nor posts them, and a save carries them over.
	 */
	public function test_handle_save_carries_the_grading_records_over(): void {
		$_POST['ffc_save_edit'] = '1';
		$_POST['submission_id'] = '42';
		$stored                 = array(
			'curso'         => 'Old',
			'_quiz_pending' => array( 'essay' ),
			'_quiz_grades'  => array( 'q1' => 2 ),
		);
		$this->handler->shouldReceive( 'get_submission' )->with( 42 )->andReturn( (array) $this->submission_row( array( 'data' => self::json( $stored ) ) ) );

		$this->handler->shouldReceive( 'update_submission' )->once()->with( 42, '', $stored );

		$this->assertStringStartsWith( 'REDIRECT:', $this->capture( fn () => $this->page()->handle_save() ) );
	}

	/**
	 * #1977 — the answers are locked, so a crafted post cannot rewrite them:
	 * the save writes back what is stored and ignores the posted answers.
	 */
	public function test_handle_save_ignores_posted_answers(): void {
		$_POST['ffc_save_edit'] = '1';
		$_POST['submission_id'] = '42';
		$_POST['data']          = array(
			'curso'     => 'Changed',
			'_injected' => 'x',
		);
		$stored                 = array(
			'curso'     => 'PHP',
			'is_edited' => '1',
		);
		$this->handler->shouldReceive( 'get_submission' )->with( 42 )->andReturn( (array) $this->submission_row( array( 'data' => self::json( $stored ) ) ) );

		$this->handler->shouldReceive( 'update_submission' )->once()->with( 42, '', array( 'curso' => 'PHP' ) );

		$this->assertStringStartsWith( 'REDIRECT:', $this->capture( fn () => $this->page()->handle_save() ) );
	}

	public function test_render_shows_the_grading_card_for_an_attempt_awaiting_grading(): void {
		Functions\when( 'get_post_meta' )->alias(
			static function ( $id, $key ) {
				if ( '_ffc_form_fields' === $key ) {
					return array(
						array(
							'type'        => 'textarea',
							'name'        => 'essay',
							'label'       => 'Explain why',
							'quiz_scored' => '1',
							'quiz_text'   => 'manual',
							'quiz_points' => '4',
						),
					);
				}
				return array( 'quiz_passing_score' => '70' );
			}
		);
		$data = array(
			'essay'           => 'Because <b>so</b>.',
			'_quiz_score'     => 6,
			'_quiz_max_score' => 10,
			'_quiz_pending'   => array( 'essay' ),
		);
		$this->handler->shouldReceive( 'get_submission' )->with( 42 )->andReturn(
			$this->submission_row(
				array(
					'status' => 'quiz_pending',
					'data'   => self::json( $data ),
				)
			)
		);

		ob_start();
		$this->page()->render( 42 );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Grade open answers', $html );
		$this->assertStringContainsString( 'earned 6 of 10 points. The passing score is 70%.', $html );
		$this->assertStringContainsString( 'name="ffc_grade[essay]" form="ffc-grade-form" value="" min="0" max="4" step="1" required', $html );
		$this->assertStringContainsString( 'data-confirm="Save the grades and send the result to the participant? This decides the attempt."', $html, 'Sending the result asks first (#2241).' );
		$this->assertStringNotContainsString( 'ffc_grade_reason', $html, 'A first grade needs no reason.' );
		$this->assertStringContainsString( '<form id="ffc-grade-form" method="POST" action="admin-post.php">', $html );
		$this->assertStringContainsString( 'name="action" value="ffc_grade_submission"', $html );
		$this->assertSame( 1, substr_count( $html, '<form id="ffc-grade-form"' ), 'The grading form is not nested in the edit form.' );
		$this->assertGreaterThan( strpos( $html, '</form>' ), strpos( $html, '<form id="ffc-grade-form"' ), 'It is printed after the edit form closes.' );
		$this->assertStringNotContainsString( 'name="data[_quiz_pending]"', $html );
	}

	/**
	 * #2241 — an attempt a person graded shows its grades filled in, to
	 * correct, with a required reason.
	 */
	public function test_render_offers_to_change_the_grades_of_a_graded_attempt(): void {
		Functions\when( 'get_post_meta' )->alias(
			static function ( $id, $key ) {
				if ( '_ffc_form_fields' === $key ) {
					return array(
						array(
							'type'        => 'textarea',
							'name'        => 'essay',
							'label'       => 'Explain why',
							'quiz_scored' => '1',
							'quiz_text'   => 'manual',
							'quiz_points' => '4',
						),
					);
				}
				return array( 'quiz_passing_score' => '70' );
			}
		);
		$data = array(
			'essay'         => 'Because.',
			'_quiz_percent' => 90,
			'_quiz_grades'  => array( 'essay' => 3 ),
		);
		$this->handler->shouldReceive( 'get_submission' )->with( 42 )->andReturn( $this->submission_row( array( 'data' => self::json( $data ) ) ) );

		ob_start();
		$this->page()->render( 42 );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Graded answers', $html );
		$this->assertStringContainsString( 'The attempt scored 90%. The passing score is 70%.', $html );
		$this->assertStringContainsString( 'name="ffc_grade[essay]" form="ffc-grade-form" value="3" min="0" max="4"', $html );
		$this->assertStringContainsString( 'name="ffc_grade_reason" form="ffc-grade-form" rows="3" class="large-text" required minlength="10"', $html );
		$this->assertStringContainsString( 'Change grades', $html );
		$this->assertStringContainsString( 'ffc-btn-caution', $html );
		$this->assertStringContainsString( 'data-confirm="Change the grades?', $html );
	}

	public function test_render_hides_the_grading_card_from_a_user_who_cannot_grade(): void {
		$this->denied = array( 'manage_options', 'ffc_grade_certificates' );
		$this->handler->shouldReceive( 'get_submission' )->with( 42 )->andReturn( $this->submission_row( array( 'status' => 'quiz_pending' ) ) );

		ob_start();
		$this->page()->render( 42 );
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'ffc-grade-form', $html );
	}

	public function test_render_has_no_grading_card_once_the_attempt_is_decided(): void {
		$this->handler->shouldReceive( 'get_submission' )->with( 42 )->andReturn( $this->submission_row() );

		ob_start();
		$this->page()->render( 42 );
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'ffc-grade-form', $html );
	}

	public function test_render_offers_a_manager_the_answer_correction(): void {
		$data = array(
			'name'                                     => 'Ana Silv',
			'auth_code'                                => 'ABCD1234EFGH',
			AnswerCorrectionHandler::CORRECTED_KEY     => array( 'name' ),
		);
		$this->handler->shouldReceive( 'get_submission' )->with( 42 )->andReturn( $this->submission_row( array( 'data' => self::json( $data ) ) ) );

		ob_start();
		$this->page()->render( 42 );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Correct an answer', $html );
		$this->assertStringContainsString( '<option value="name">', $html );
		$this->assertStringNotContainsString( '<option value="auth_code">', $html, 'The certificate\'s identity is not offered.' );
		$this->assertStringContainsString( 'name="ffc_correct_reason" form="ffc-correct-form" rows="2" class="large-text" required minlength="10"', $html );
		$this->assertStringContainsString( 'value="ffc_correct_answer"', $html );
		$this->assertStringContainsString( 'Corrected after issue; see the activity log.', $html );
		$this->assertStringNotContainsString( '_corrected_fields', $html, 'The marker list is not shown as an answer.' );
	}

	public function test_render_hides_the_answer_correction_from_an_operator(): void {
		$this->denied = array( 'manage_options', 'ffc_manage_certificates' );
		$this->handler->shouldReceive( 'get_submission' )->with( 42 )->andReturn( $this->submission_row( array( 'data' => self::json( array( 'name' => 'Ana' ) ) ) ) );

		ob_start();
		$this->page()->render( 42 );
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'ffc-correct-form', $html );
	}

	public function test_render_reads_protected_fields_as_text(): void {
		$this->handler->shouldReceive( 'get_submission' )->with( 42 )->andReturn( $this->submission_row() );

		ob_start();
		$this->page()->render( 42 );
		$html = (string) ob_get_clean();

		// The save keeps the stored data, so a protected field is shown, not posted.
		$this->assertMatchesRegularExpression( '#<dd class="ffc-facts__value">[^<]*<span class="description ffc-edit-note">Protected internal field.</span></dd>#', $html );
		$this->assertStringNotContainsString( 'name="data[auth_code]"', $html );
		$this->assertStringNotContainsString( 'readonly>', preg_replace( '/data-ffc-pii-field="[a-z]+" readonly/', '', $html ), 'only the PII value, which the reveal script writes into, stays an input' );
		$this->assertStringContainsString( '<details class="ffc-section" data-ffc-section>', $html, 'consent is the shared section, closed' );
	}
}

