<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentAdminPageRenderer;
use FreeFormCertificate\Recruitment\RecruitmentSettings;

/**
 * Render tests for RecruitmentAdminPageRenderer, against its real
 * collaborators: the capability gates read `current_user_can()`, the readers
 * and list tables read a `$wpdb` double, the settings read `get_option()`,
 * and the markup is the real `AdminUI`'s.
 *
 * They used stand-in classes for all eleven collaborators until #2091, and
 * those stand-ins occupied the class table for the rest of the process, so
 * the class had to run every test in a process of its own. Against the real
 * classes it runs in the shared one, and the gates the tests flip are the
 * ones that ship.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentAdminPageRenderer
 */
class RecruitmentAdminPageRendererTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Capabilities the current user lacks; every other one is held.
	 *
	 * @var array<int, string>
	 */
	private array $denied = array();

	/**
	 * Rows the notices table answers with.
	 *
	 * @var array<int, object>
	 */
	private array $notices = array();

	/**
	 * The `$wpdb` the test replaced, restored in tearDown().
	 *
	 * @var mixed
	 */
	private $previous_wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
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

		// The readers and list tables query $wpdb. The double names the table
		// in the prepared query, so it can answer the notices table alone.
		global $wpdb;
		$this->previous_wpdb = $wpdb;
		$this->denied        = array();
		$this->notices       = array();
		$notices             = &$this->notices;
		$wpdb                = new class( $notices ) extends \wpdb {
			/** @var array<int, object> */
			private array $notice_rows;

			/** @param array<int, object> $notice_rows Rows of the notices table. */
			public function __construct( array &$notice_rows ) {
				$this->prefix      = 'wp_';
				$this->notice_rows = &$notice_rows;
			}

			public function prepare( $query, ...$args ) {
				return $query . ' -- ' . (string) json_encode( $args );
			}

			public function get_results( $query = null, $output = OBJECT ) {
				return false !== strpos( (string) $query, 'ffc_recruitment_notice' ) ? $this->notice_rows : array();
			}

			public function get_var( $query = null, $x = 0, $y = 0 ) {
				return 0;
			}
		};

		// WP escaping / i18n stubs.
		Functions\when( '__' )->returnArg();
		Functions\when( '_x' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( static function ( $s ) { echo $s; } );
		Functions\when( 'esc_attr_e' )->alias( static function ( $s ) { echo $s; } );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_textarea' )->returnArg();
		// The email body now renders via wp_editor (TinyMCE).
		Functions\when( 'wp_editor' )->alias(
			static function ( $content, $id ) {
				echo '<textarea id="' . $id . '">' . $content . '</textarea>';
			}
		);
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();

		// WP admin / form helpers.
		Functions\when( 'admin_url' )->alias( static fn( $p = '' ) => 'https://example.com/wp-admin/' . $p );
		Functions\when( 'add_query_arg' )->justReturn( 'https://example.com/wp-admin/admin.php?x=1' );
		Functions\when( 'wp_nonce_url' )->returnArg();
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'settings_fields' )->justReturn( '' );
		Functions\when( 'submit_button' )->alias( static function () { echo '<button>save</button>'; } );
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'checked' )->justReturn( '' );
		$denied = &$this->denied;
		Functions\when( 'current_user_can' )->alias(
			static function ( $cap ) use ( &$denied ): bool {
				return ! in_array( $cap, $denied, true );
			}
		);
		// The recruitment settings come from their option; every other option
		// is empty, which keeps the Email Model's disabled notice silent.
		$settings = $this->settingsFixture();
		Functions\when( 'get_option' )->alias(
			static fn( $name, $fallback = false ) => RecruitmentSettings::OPTION_NAME === $name ? $settings : array()
		);
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		// LabelSorter takes the WordPress branch only when get_locale() exists, and
		// whether it does depends on what ran earlier in the process: choose it here.
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
	}

	/**
	 * Takes capabilities away from the current user, `manage_options` with
	 * them: an administrator passes every gate whatever the granular one.
	 *
	 * @param string ...$caps Capabilities to deny.
	 */
	private function deny( string ...$caps ): void {
		$this->denied = array_merge( $this->denied, array( 'manage_options' ), $caps );
	}

	/**
	 * A notices-table row with every column the list table reads.
	 *
	 * @param int    $id     Notice id.
	 * @param string $status Notice status.
	 */
	private function notice( int $id, string $status ): object {
		return (object) array(
			'id'           => $id,
			'code'         => 'E' . $id,
			'name'         => 'N' . $id,
			'status'       => $status,
			'was_reopened' => '0',
			'created_at'   => '2026-10-01 10:00:00',
		);
	}

	protected function tearDown(): void {
		global $wpdb;
		$wpdb = $this->previous_wpdb;
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @return string captured renderer output */
	private function capture( callable $fn ): string {
		ob_start();
		$fn();
		return (string) ob_get_clean();
	}

	public function test_render_header_puts_the_page_create_action_beside_the_title(): void {
		$out = $this->capture( static fn() => RecruitmentAdminPageRenderer::render_header( 'notices' ) );

		$this->assertStringStartsWith( '<h1 class="wp-heading-inline">Notices</h1>', $out );
		$this->assertStringContainsString( 'data-ffc-dialog-open="ffc-new-notice-dialog"', $out );
		$this->assertStringContainsString( 'New notice', $out );
		$this->assertStringEndsWith( '<hr class="wp-header-end">', $out );

		$out = $this->capture( static fn() => RecruitmentAdminPageRenderer::render_header( 'adjutancies' ) );
		$this->assertStringContainsString( 'data-ffc-dialog-open="ffc-new-adjutancy-dialog"', $out );

		$out = $this->capture( static fn() => RecruitmentAdminPageRenderer::render_header( 'candidates' ) );
		$this->assertStringNotContainsString( 'data-ffc-dialog-open', $out, 'candidates are imported, not created one by one' );
	}

	public function test_render_header_offers_nothing_to_create_without_the_capability(): void {
		$this->deny( 'ffc_manage_recruitment', 'ffc_manage_recruitment_reasons' );

		foreach ( array( 'dashboard', 'notices', 'adjutancies', 'reasons' ) as $tab ) {
			$out = $this->capture( static fn() => RecruitmentAdminPageRenderer::render_header( $tab ) );
			$this->assertStringNotContainsString( 'data-ffc-dialog-open', $out, $tab );
		}
	}

	public function test_render_notices_tab_with_empty_state(): void {
		$this->notices = array();

		$out = $this->capture( static fn() => RecruitmentAdminPageRenderer::render_notices_tab() );

		$this->assertStringContainsString( 'Welcome to Recruitment', $out );
		$this->assertStringContainsString( 'ffc-create-notice', $out );
		$this->assertStringContainsString( 'Available REST endpoints', $out );
	}

	public function test_render_notices_tab_without_empty_state_when_notices_exist(): void {
		$this->notices = array( $this->notice( 1, 'draft' ) );

		$out = $this->capture( static fn() => RecruitmentAdminPageRenderer::render_notices_tab() );

		$this->assertStringNotContainsString( 'Welcome to Recruitment', $out );
		$this->assertStringContainsString( 'ffc-create-notice', $out );
	}

	public function test_render_notices_empty_state(): void {
		$out = $this->capture( static fn() => RecruitmentAdminPageRenderer::render_notices_empty_state() );
		$this->assertStringContainsString( 'Welcome to Recruitment', $out );
	}

	public function test_render_flash_notice_known_success_key(): void {
		$out = $this->capture( static fn() => RecruitmentAdminPageRenderer::render_flash_notice( 'saved' ) );
		$this->assertStringContainsString( 'notice-success', $out );
		$this->assertStringContainsString( 'Saved.', $out );
	}

	public function test_render_flash_notice_known_error_key(): void {
		$out = $this->capture( static fn() => RecruitmentAdminPageRenderer::render_flash_notice( 'save-failed' ) );
		$this->assertStringContainsString( 'notice-error', $out );
	}

	public function test_render_flash_notice_says_why_a_delete_was_refused(): void {
		$out = $this->capture( static fn() => RecruitmentAdminPageRenderer::render_flash_notice( 'adjutancy-in-use' ) );
		$this->assertStringContainsString( 'was not deleted', $out );
		$out = $this->capture( static fn() => RecruitmentAdminPageRenderer::render_flash_notice( 'reason-in-use' ) );
		$this->assertStringContainsString( 'was not deleted', $out );
	}

	public function test_render_flash_notice_unknown_key_renders_nothing(): void {
		$out = $this->capture( static fn() => RecruitmentAdminPageRenderer::render_flash_notice( 'totally-unknown' ) );
		$this->assertSame( '', $out );
	}

	public function test_render_adjutancies_tab(): void {
		$out = $this->capture( static fn() => RecruitmentAdminPageRenderer::render_adjutancies_tab() );

		$this->assertStringContainsString( '<input type="hidden" name="tab" value="adjutancies">', $out, 'the search keeps the page' );
		$this->assertStringContainsString( 'placeholder="Slug or name"', $out );
		$this->assertStringContainsString( 'ffc-create-adjutancy', $out );
	}

	public function test_render_reasons_tab(): void {
		$out = $this->capture( static fn() => RecruitmentAdminPageRenderer::render_reasons_tab() );

		$this->assertStringContainsString( '<input type="hidden" name="tab" value="reasons">', $out, 'the search keeps the page' );
		$this->assertStringContainsString( 'Global catalog', $out );
		$this->assertStringContainsString( 'ffc-create-reason', $out );
	}

	public function test_render_create_reason_form_skipped_without_edit_cap(): void {
		$this->deny( 'ffc_manage_recruitment_reasons' );

		$out = $this->capture( static fn() => RecruitmentAdminPageRenderer::render_create_reason_form() );
		$this->assertSame( '', $out );
	}

	public function test_render_candidates_tab_with_import_section(): void {
		$this->notices = array(
			$this->notice( 1, 'draft' ),
			$this->notice( 2, 'preliminary' ),
			$this->notice( 3, 'closed' ),
		);

		$out = $this->capture( static fn() => RecruitmentAdminPageRenderer::render_candidates_tab() );

		$this->assertStringContainsString( 'Import candidates (CSV)', $out );
		$this->assertStringContainsString( 'ffc-recruitment-candidates-import', $out );
	}

	public function test_render_candidates_tab_without_import_cap(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$out = $this->capture( static fn() => RecruitmentAdminPageRenderer::render_candidates_tab() );

		$this->assertStringContainsString( 'Candidates are imported per notice', $out );
		// The standalone import form is not rendered (the cap is denied);
		// only the inline guidance <p> mentioning it remains.
		$this->assertStringNotContainsString( 'ffc-recruitment-candidates-import', $out );
	}

	public function test_render_candidates_csv_import_section_empty(): void {
		$this->notices = array( $this->notice( 9, 'closed' ) );

		$out = $this->capture( static fn() => RecruitmentAdminPageRenderer::render_candidates_csv_import_section() );

		$this->assertStringContainsString( 'No notices in `draft` or `preliminary`', $out );
	}

	public function test_render_settings_tab(): void {
		// Tags what passes through esc_attr__() so the placeholders prove they are translated (#1873).
		Functions\when( 'esc_attr__' )->alias( static fn( $s ) => 'i18n:' . $s );

		$out = $this->capture( static fn() => RecruitmentAdminPageRenderer::render_settings_tab() );

		$this->assertStringContainsString( 'placeholder="i18n:(falls back to wp_mail default)"', $out );
		$this->assertStringContainsString( 'placeholder="i18n:(falls back to site name)"', $out );

		$this->assertStringContainsString( 'Email template', $out );
		$this->assertStringContainsString( 'Status badge colors', $out );
		$this->assertStringContainsString( '<p class="submit"><button type="submit" class="ffc-btn ffc-btn-primary ffc-icon-save" name="submit" value="Save Changes">Save Changes</button>', $out );
		// Send-mode + From fields stay; the subject/body editor moved to the hub
		// (#964), replaced by a deep-link to Settings → Email texts (#976 split).
		$this->assertStringContainsString( 'name="ffc_recruitment_settings[email_mode]"', $out );
		$this->assertStringContainsString( 'name="ffc_recruitment_settings[email_from_address]"', $out );
		$this->assertStringNotContainsString( 'id="ffc_rs_body"', $out );
		$this->assertStringNotContainsString( 'ffc-email-restore-default', $out );
		$this->assertStringContainsString( 'page=ffc-settings&tab=email_texts', $out );
	}

	public function test_render_settings_tab_read_only(): void {
		$this->deny( 'ffc_manage_recruitment_settings' );

		$out = $this->capture( static fn() => RecruitmentAdminPageRenderer::render_settings_tab() );

		$this->assertStringContainsString( 'Read-only', $out );
		$this->assertStringContainsString( '<fieldset disabled class="ffc-readonly-lock">', $out );
		$this->assertStringNotContainsString( '<p class="submit">', $out );
	}

	public function test_render_create_notice_form_skipped_without_cap(): void {
		$this->deny( 'ffc_manage_recruitment' );

		$out = $this->capture( static fn() => RecruitmentAdminPageRenderer::render_create_notice_form() );
		$this->assertSame( '', $out );
	}

	public function test_render_create_adjutancy_form_skipped_without_cap(): void {
		$this->deny( 'ffc_manage_recruitment' );

		$out = $this->capture( static fn() => RecruitmentAdminPageRenderer::render_create_adjutancy_form() );
		$this->assertSame( '', $out );
	}

	public function test_render_rest_pointer(): void {
		$out = $this->capture( static fn() => RecruitmentAdminPageRenderer::render_rest_pointer() );
		$this->assertStringContainsString( 'Available REST endpoints', $out );
	}

	/**
	 * Each section page's renderer.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function tab_screens(): array {
		return array(
			'notices'     => array( 'render_notices_tab' ),
			'adjutancies' => array( 'render_adjutancies_tab' ),
			'reasons'     => array( 'render_reasons_tab' ),
			'candidates'  => array( 'render_candidates_tab' ),
			'settings'    => array( 'render_settings_tab' ),
		);
	}

	/**
	 * A section page closes every box it opens: the list is full width, no
	 * card around it (#2486).
	 *
	 * @dataProvider tab_screens
	 */
	public function test_each_section_page_closes_every_box_it_opens( string $method ): void {
		$this->notices = array( $this->notice( 1, 'draft' ) );

		$out = $this->capture( static fn() => RecruitmentAdminPageRenderer::$method() );

		$this->assertSame( substr_count( $out, '<div' ), substr_count( $out, '</div>' ), 'every box the screen opens is closed' );
		$this->assertStringNotContainsString( 'ffc-settings-tabs', $out, 'no vertical menu around the section' );
	}

	public function test_create_forms_open_in_dialogs_and_the_import_stays_a_card(): void {
		$this->notices = array( $this->notice( 1, 'draft' ) );

		foreach (
			array(
				'render_notices_tab'     => array( 'ffc-new-notice-dialog', 'ffc-create-notice', 'notices' ),
				'render_adjutancies_tab' => array( 'ffc-new-adjutancy-dialog', 'ffc-create-adjutancy', 'adjutancies' ),
				'render_reasons_tab'     => array( 'ffc-new-reason-dialog', 'ffc-create-reason', 'reasons' ),
			) as $method => $dialog
		) {
			$out = $this->capture( static fn() => RecruitmentAdminPageRenderer::$method() );

			$this->assertMatchesRegularExpression( '/<div class="ffc-confirm-modal-overlay ffc-create-dialog" id="' . $dialog[0] . '" role="dialog" aria-modal="true" aria-labelledby="' . $dialog[0] . '-title" hidden>/', $out, $method );
			$this->assertStringContainsString( '<form id="' . $dialog[1] . '" method="post" data-ffc-create-endpoint="' . $dialog[2] . '"', $out, $method );
			$this->assertStringContainsString( 'ffc-create-dialog__error', $out, $method . ': a refusal is shown inside the dialog' );
			$this->assertStringNotContainsString( 'Create new', $out, $method . ': the end-of-page form is gone' );
		}

		$out = $this->capture( static fn() => RecruitmentAdminPageRenderer::render_candidates_tab() );
		$this->assertStringContainsString( '<div class="card"><h2 class="ffc-icon-upload">Import candidates (CSV)</h2>', $out );
	}

	public function test_new_notice_suggests_the_next_code_and_opens_the_new_draft(): void {
		// Listed newest first by date, but created in one second: the
		// highest id is the latest, wherever the list puts it.
		$this->notices          = array( $this->notice( 3, 'draft' ), $this->notice( 7, 'draft' ), $this->notice( 5, 'draft' ) );
		$this->notices[0]->code = 'EDT-0003/2026';
		$this->notices[1]->code = 'EDT-0005/2026';
		$this->notices[2]->code = 'EDT-0004/2026';

		$out = $this->capture( static fn() => RecruitmentAdminPageRenderer::render_create_notice_form() );

		$this->assertStringContainsString( 'name="code" type="text" class="regular-text" required value="EDT-0006/2026"', $out );
		$this->assertStringContainsString( 'Suggested from the latest notice, EDT-0005/2026. You can change it.', $out, 'the hint names where the suggestion came from' );
		$this->assertStringContainsString( 'data-ffc-create-open="', $out );
		$this->assertStringContainsString( 'Create and open', $out );
	}

	public function test_new_notice_suggests_nothing_without_a_notice_to_count_from(): void {
		$this->notices = array();

		$out = $this->capture( static fn() => RecruitmentAdminPageRenderer::render_create_notice_form() );

		$this->assertStringContainsString( 'name="code" type="text" class="regular-text" required value=""', $out );
		$this->assertStringNotContainsString( 'Suggested from the latest notice', $out );
	}

	public function test_no_tab_keeps_the_old_postbox_or_a_loose_heading(): void {
		$this->notices = array( $this->notice( 1, 'draft' ) );

		foreach ( $this->tab_screens() as $screen ) {
			$method = $screen[0];
			$out    = $this->capture( static fn() => RecruitmentAdminPageRenderer::$method() );
			$this->assertStringNotContainsString( 'postbox', $out, $method );
			$this->assertStringNotContainsString( '<h2>', $out, $method . ': every section heading names its icon' );
		}
	}

	/**
	 * Full settings fixture covering every key the settings-tab template reads.
	 *
	 * @return array<string,mixed>
	 */
	private function settingsFixture(): array {
		return array(
			'email_subject'                          => 'Sub',
			'email_from_address'                     => 'a@b.c',
			'email_from_name'                        => 'From',
			'email_body_html'                        => '<p>Body</p>',
			'email_mode'                             => 'always',
			'public_cache_seconds'                   => 60,
			'public_rate_limit_per_minute'           => 30,
			'public_default_page_size'               => 50,
			'status_color_empty'                     => '#fff',
			'status_color_called'                     => '#aaa',
			'status_color_hired'                      => '#0f0',
			'status_color_not_shown'                  => '#f00',
			'status_color_withdrew'                   => '#f0a',
			'preview_color_empty'                     => '#111',
			'preview_color_denied'                    => '#222',
			'preview_color_granted'                   => '#333',
			'preview_color_appeal_denied'             => '#444',
			'preview_color_appeal_granted'            => '#555',
			'preview_reason_required_denied'          => true,
			'preview_reason_required_granted'         => false,
			'preview_reason_required_appeal_denied'   => true,
			'preview_reason_required_appeal_granted'  => false,
			'subscription_color_pcd'                  => '#666',
			'subscription_color_geral'                => '#777',
			'notice_status_color_draft'               => '#ccc',
			'notice_status_color_preliminary'         => '#ff0',
			'notice_status_color_definitive'          => '#0f0',
			'notice_status_color_closed'              => '#999',
		);
	}
}
