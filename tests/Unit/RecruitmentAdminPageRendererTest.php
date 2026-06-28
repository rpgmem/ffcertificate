<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentAdminPageRenderer;
use FreeFormCertificate\Recruitment\RecruitmentAdminPage;
use FreeFormCertificate\Recruitment\RecruitmentSettings;
use FreeFormCertificate\Recruitment\RecruitmentNoticeReader;
use FreeFormCertificate\Core\Capabilities;

/**
 * Render smoke-tests for RecruitmentAdminPageRenderer — each public render
 * method is invoked with stubbed collaborators (readers, settings, list
 * tables, capability gates) so the renderer's own data-prep + template
 * `include` lines execute. The included templates/admin/recruitment/admin-page
 * partials are out of coverage scope; the goal is to drive the renderer's
 * in-scope statements without a fatal.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentAdminPageRenderer
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class RecruitmentAdminPageRendererTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Define the collaborator stubs (constants + static gates) ONLY now, in
		// this isolated test process — never at suite-discovery time. Declaring
		// them at file scope would poison the real recruitment classes for every
		// other test in the suite (see the fixture's docblock, #563).
		require_once __DIR__ . '/../fixtures/recruitment-admin-page-renderer-stubs.php';

		// pcov coverage-attribution: preload so statements register vs the floor.
		class_exists( '\FreeFormCertificate\Recruitment\RecruitmentAdminPageRenderer' );

		// Reset stub flags to the all-open defaults each test.
		RecruitmentAdminPage::$view_settings = true;
		RecruitmentAdminPage::$view_reasons  = true;
		RecruitmentAdminPage::$edit_reasons  = true;
		RecruitmentAdminPage::$edit_settings = true;
		RecruitmentNoticeReader::$rows       = array();
		RecruitmentSettings::$values         = $this->settingsFixture();
		Capabilities::$admin_or              = true;

		// WP escaping / i18n stubs.
		Functions\when( '__' )->returnArg();
		Functions\when( '_x' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( static function ( $s ) { echo $s; } );
		Functions\when( 'esc_attr_e' )->alias( static function ( $s ) { echo $s; } );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_textarea' )->returnArg();
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
		Functions\when( 'current_user_can' )->justReturn( true );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @return string captured renderer output */
	private function capture( callable $fn ): string {
		ob_start();
		$fn();
		return (string) ob_get_clean();
	}

	public function test_render_tabs_includes_all_tabs_when_caps_open(): void {
		$out = $this->capture( static fn() => RecruitmentAdminPageRenderer::render_tabs( 'notices' ) );

		$this->assertStringContainsString( 'ffc-settings-tabs__nav', $out );
		$this->assertStringContainsString( 'Settings', $out );
		$this->assertStringContainsString( 'Reasons', $out );
		$this->assertStringContainsString( 'is-active', $out );
	}

	public function test_render_tabs_hides_settings_and_reasons_without_view_caps(): void {
		RecruitmentAdminPage::$view_settings = false;
		RecruitmentAdminPage::$view_reasons  = false;

		$out = $this->capture( static fn() => RecruitmentAdminPageRenderer::render_tabs( 'notices' ) );

		$this->assertStringContainsString( 'Notices', $out );
		$this->assertStringNotContainsString( 'Settings', $out );
		$this->assertStringNotContainsString( 'Reasons', $out );
	}

	public function test_render_notices_tab_with_empty_state(): void {
		RecruitmentNoticeReader::$rows = array();

		$out = $this->capture( static fn() => RecruitmentAdminPageRenderer::render_notices_tab() );

		$this->assertStringContainsString( 'Welcome to Recruitment', $out );
		$this->assertStringContainsString( 'ffc-create-notice', $out );
		$this->assertStringContainsString( 'Available REST endpoints', $out );
	}

	public function test_render_notices_tab_without_empty_state_when_notices_exist(): void {
		RecruitmentNoticeReader::$rows = array( (object) array( 'id' => 1, 'status' => 'draft' ) );

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

	public function test_render_flash_notice_unknown_key_renders_nothing(): void {
		$out = $this->capture( static fn() => RecruitmentAdminPageRenderer::render_flash_notice( 'totally-unknown' ) );
		$this->assertSame( '', $out );
	}

	public function test_render_adjutancies_tab(): void {
		$out = $this->capture( static fn() => RecruitmentAdminPageRenderer::render_adjutancies_tab() );

		$this->assertStringContainsString( 'Adjutancies', $out );
		$this->assertStringContainsString( 'ffc-create-adjutancy', $out );
	}

	public function test_render_reasons_tab(): void {
		$out = $this->capture( static fn() => RecruitmentAdminPageRenderer::render_reasons_tab() );

		$this->assertStringContainsString( 'Reasons', $out );
		$this->assertStringContainsString( 'ffc-create-reason', $out );
	}

	public function test_render_create_reason_form_skipped_without_edit_cap(): void {
		RecruitmentAdminPage::$edit_reasons = false;

		$out = $this->capture( static fn() => RecruitmentAdminPageRenderer::render_create_reason_form() );
		$this->assertSame( '', $out );
	}

	public function test_render_candidates_tab_with_import_section(): void {
		RecruitmentNoticeReader::$rows = array(
			(object) array( 'id' => 1, 'code' => 'E1', 'name' => 'N1', 'status' => 'draft' ),
			(object) array( 'id' => 2, 'code' => 'E2', 'name' => 'N2', 'status' => 'preliminary' ),
			(object) array( 'id' => 3, 'code' => 'E3', 'name' => 'N3', 'status' => 'closed' ),
		);

		$out = $this->capture( static fn() => RecruitmentAdminPageRenderer::render_candidates_tab() );

		$this->assertStringContainsString( 'Import candidates (CSV)', $out );
		$this->assertStringContainsString( 'ffc-recruitment-candidates-import', $out );
	}

	public function test_render_candidates_tab_without_import_cap(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$out = $this->capture( static fn() => RecruitmentAdminPageRenderer::render_candidates_tab() );

		$this->assertStringContainsString( 'imported per-notice via CSV', $out );
		// The standalone import form is not rendered (the cap is denied);
		// only the inline guidance <p> mentioning it remains.
		$this->assertStringNotContainsString( 'ffc-recruitment-candidates-import', $out );
	}

	public function test_render_candidates_csv_import_section_empty(): void {
		RecruitmentNoticeReader::$rows = array( (object) array( 'id' => 9, 'status' => 'closed' ) );

		$out = $this->capture( static fn() => RecruitmentAdminPageRenderer::render_candidates_csv_import_section() );

		$this->assertStringContainsString( 'No notices in `draft` or `preliminary`', $out );
	}

	public function test_render_settings_tab(): void {
		$out = $this->capture( static fn() => RecruitmentAdminPageRenderer::render_settings_tab() );

		$this->assertStringContainsString( 'Email template', $out );
		$this->assertStringContainsString( 'Status badge colors', $out );
		$this->assertStringContainsString( '<button>save</button>', $out );
	}

	public function test_render_settings_tab_read_only(): void {
		RecruitmentAdminPage::$edit_settings = false;

		$out = $this->capture( static fn() => RecruitmentAdminPageRenderer::render_settings_tab() );

		$this->assertStringContainsString( 'Read-only', $out );
		$this->assertStringContainsString( 'fieldset disabled', $out );
		$this->assertStringNotContainsString( '<button>save</button>', $out );
	}

	public function test_render_create_notice_form_skipped_without_cap(): void {
		Capabilities::$admin_or = false;

		$out = $this->capture( static fn() => RecruitmentAdminPageRenderer::render_create_notice_form() );
		$this->assertSame( '', $out );
	}

	public function test_render_create_adjutancy_form_skipped_without_cap(): void {
		Capabilities::$admin_or = false;

		$out = $this->capture( static fn() => RecruitmentAdminPageRenderer::render_create_adjutancy_form() );
		$this->assertSame( '', $out );
	}

	public function test_render_rest_pointer(): void {
		$out = $this->capture( static fn() => RecruitmentAdminPageRenderer::render_rest_pointer() );
		$this->assertStringContainsString( 'Available REST endpoints', $out );
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
			'audit_pii_reveals'                       => true,
		);
	}
}
