<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Brain\Monkey\Actions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\AdminAssetsManager;

/**
 * Tests for AdminAssetsManager: register hooks, page detection logic,
 * conditional asset loading, and enqueue calls.
 *
 * @covers \FreeFormCertificate\Admin\AdminAssetsManager
 * @covers \FreeFormCertificate\Admin\AdminConditionalAssets
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AdminAssetsManagerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface Alias mock for Utils */
	private $utils_mock;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// pcov does not record lines for files first autoloaded mid-test-method,
		// so the extracted conditional-assets class's coverage would attribute to
		// nothing. Preload the class here so pcov attributes its lines to this test.
		class_exists( '\\FreeFormCertificate\\Admin\\AdminConditionalAssets' );

		// Common WP function stubs
		Functions\when('__')->returnArg();
		Functions\when('esc_html__')->returnArg();
		Functions\when('esc_html')->returnArg();
		Functions\when('esc_attr')->returnArg();
		Functions\when('esc_attr__')->returnArg();
		Functions\when('wp_kses_post')->returnArg();
		Functions\when('absint')->justReturn(1);
		Functions\when('sanitize_text_field')->returnArg();
		Functions\when('wp_unslash')->returnArg();
		Functions\when('sanitize_key')->returnArg();

		// The localization payload now eagerly builds the certificate
		// preview sample map (CertificatePreviewSamples::get_map()), which
		// routes dates through DateFormatter and reads the blog name.
		Functions\when('get_option')->justReturn(array());
		Functions\when('get_bloginfo')->justReturn('Test Site');
		Functions\when('wp_date')->justReturn('01/01/2026');
		Functions\when('wp_timezone')->alias(static fn() => new \DateTimeZone('UTC'));

		// The localization payload now lists the certificate-template pool
		// (#865) via CertTemplateReader::list_for_editor() → get_posts().
		// Default to an empty pool so the discover helper takes its legacy
		// glob fallback (empty in the test dir); the dedicated pool test
		// overrides get_posts to exercise the id-keyed branch.
		Functions\when('get_posts')->justReturn(array());

		// Utils alias mock
		$this->utils_mock = Mockery::mock('alias:\FreeFormCertificate\Core\AssetHelper');
		$ri_mock = Mockery::mock( 'alias:\FreeFormCertificate\Core\RequestInput' );
		$ri_mock->shouldReceive( 'get_get_key' )->andReturnUsing( static fn( $key, $default = '' ) => isset( $_GET[ $key ] ) ? (string) $_GET[ $key ] : $default );
		$ri_mock->shouldReceive( 'get_get_int' )->andReturnUsing( static fn( $key, $default = 0 ) => isset( $_GET[ $key ] ) ? (int) $_GET[ $key ] : $default );
		$ri_mock->shouldReceive( 'has_get' )->andReturnUsing( static fn( $key ) => isset( $_GET[ $key ] ) );
		$this->utils_mock->shouldReceive('asset_suffix')->andReturn('.min')->byDefault();
		$this->utils_mock->shouldReceive('enqueue_dark_mode')->byDefault();
		$ri_mock->shouldReceive('get_get_string')->andReturnUsing( function ( $key, $default = '' ) {
			return isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ? $_GET[ $key ] : $default;
		} )->byDefault();
	}

	protected function tearDown(): void {
		unset($_GET['page'], $_GET['action']);
		Monkey\tearDown();
		parent::tearDown();
	}

	// ==================================================================
	// register()
	// ==================================================================

	public function test_register_adds_admin_enqueue_scripts_action(): void {
		Actions\expectAdded('admin_enqueue_scripts')
			->once();

		$manager = new AdminAssetsManager();
		$manager->register();
	}

	// ==================================================================
	// enqueue_admin_assets() - non-FFC pages
	// ==================================================================

	public function test_enqueue_admin_assets_returns_early_for_non_ffc_page(): void {
		global $post_type;
		$post_type = 'post';
		unset($_GET['page']);

		// If it proceeds, it would call wp_enqueue_media which we should NOT see
		$media_called = false;
		Functions\when('wp_enqueue_media')->alias(function () use (&$media_called) {
			$media_called = true;
		});

		$manager = new AdminAssetsManager();
		$manager->enqueue_admin_assets('edit.php');

		$this->assertFalse($media_called, 'wp_enqueue_media should NOT be called for non-FFC pages');
	}

	// ==================================================================
	// enqueue_admin_assets() - FFC post type page
	// ==================================================================

	public function test_enqueue_admin_assets_loads_for_ffc_form_post_type(): void {
		global $post_type;
		$post_type = 'ffc_form';

		$enqueued_scripts = [];
		$enqueued_styles = [];

		Functions\when('wp_enqueue_media')->justReturn(true);
		Functions\when('wp_enqueue_script')->alias(function ($handle) use (&$enqueued_scripts) {
			$enqueued_scripts[] = $handle;
		});
		Functions\when('wp_enqueue_style')->alias(function ($handle) use (&$enqueued_styles) {
			$enqueued_styles[] = $handle;
		});
		Functions\when('wp_localize_script')->justReturn(true);
		Functions\when('admin_url')->justReturn('https://example.com/wp-admin/admin-ajax.php');
		Functions\when('wp_create_nonce')->justReturn('test_nonce');

		$manager = new AdminAssetsManager();
		$manager->enqueue_admin_assets('post.php');

		// Core scripts should be enqueued
		$this->assertContains('ffc-core', $enqueued_scripts);
		$this->assertContains('ffc-admin-field-builder', $enqueued_scripts);
		$this->assertContains('ffc-admin-pdf', $enqueued_scripts);
		$this->assertContains('ffc-admin-js', $enqueued_scripts);
	}

	public function test_enqueue_admin_assets_loads_css_for_ffc_form_post_type(): void {
		global $post_type;
		$post_type = 'ffc_form';

		$enqueued_styles = [];

		Functions\when('wp_enqueue_media')->justReturn(true);
		Functions\when('wp_enqueue_script')->justReturn(true);
		Functions\when('wp_enqueue_style')->alias(function ($handle) use (&$enqueued_styles) {
			$enqueued_styles[] = $handle;
		});
		Functions\when('wp_localize_script')->justReturn(true);
		Functions\when('admin_url')->justReturn('https://example.com/wp-admin/admin-ajax.php');
		Functions\when('wp_create_nonce')->justReturn('test_nonce');

		$manager = new AdminAssetsManager();
		$manager->enqueue_admin_assets('post.php');

		// CSS assets should be enqueued
		$this->assertContains('ffc-pdf-core', $enqueued_styles);
		$this->assertContains('ffc-common', $enqueued_styles);
		$this->assertContains('ffc-admin-utilities', $enqueued_styles);
		$this->assertContains('ffc-admin-css', $enqueued_styles);
		$this->assertContains('ffc-admin-submissions-css', $enqueued_styles);
	}

	// ==================================================================
	// Localized template catalog — DB pool (#865)
	// ==================================================================

	public function test_localized_templates_come_from_db_pool_keyed_by_id(): void {
		global $post_type;
		$post_type = 'ffc_form';

		// Two visible pool templates: one shipped default, one user template.
		$default_post             = new \WP_Post();
		$default_post->ID         = 10;
		$default_post->post_title = 'Certificate model 1';
		$user_post                = new \WP_Post();
		$user_post->ID            = 20;
		$user_post->post_title    = 'My layout';
		Functions\when('get_posts')->justReturn(array($default_post, $user_post));
		Functions\when('get_post_meta')->alias(static function ($id, $key) {
			// list_for_editor() reads META_IS_DEFAULT per post; only id 10 is a default.
			return (10 === $id) ? '1' : '';
		});

		$captured = null;
		Functions\when('wp_enqueue_media')->justReturn(true);
		Functions\when('wp_enqueue_script')->justReturn(true);
		Functions\when('wp_enqueue_style')->justReturn(true);
		Functions\when('admin_url')->justReturn('https://example.com/wp-admin/admin-ajax.php');
		Functions\when('wp_create_nonce')->justReturn('test_nonce');
		Functions\when('wp_localize_script')->alias(static function ($handle, $name, $data) use (&$captured) {
			if ('ffc_ajax' === $name) {
				$captured = $data;
			}
			return true;
		});

		$manager = new AdminAssetsManager();
		$manager->enqueue_admin_assets('post.php');

		$this->assertIsArray($captured);
		$this->assertArrayHasKey('templates', $captured);
		$templates = $captured['templates'];
		$this->assertCount(2, $templates);
		// Defaults first, addressed by post id; `file` is empty for pool
		// entries (the JS posts template_id, not the legacy filename).
		$this->assertSame(10, $templates[0]['id']);
		$this->assertTrue($templates[0]['is_default']);
		$this->assertSame('', $templates[0]['file']);
		$this->assertSame(20, $templates[1]['id']);
		$this->assertFalse($templates[1]['is_default']);
	}

	// ==================================================================
	// enqueue_admin_assets() - FFC menu page
	// ==================================================================

	public function test_enqueue_admin_assets_loads_for_ffc_menu_page(): void {
		global $post_type;
		$post_type = 'page'; // Not ffc_form, but page param has ffc-

		$_GET['page'] = 'ffc-submissions';

		$media_called = false;
		Functions\when('wp_enqueue_media')->alias(function () use (&$media_called) {
			$media_called = true;
		});
		Functions\when('wp_enqueue_script')->justReturn(true);
		Functions\when('wp_enqueue_style')->justReturn(true);
		Functions\when('wp_localize_script')->justReturn(true);
		Functions\when('admin_url')->justReturn('https://example.com/wp-admin/admin-ajax.php');
		Functions\when('wp_create_nonce')->justReturn('test_nonce');

		$manager = new AdminAssetsManager();
		$manager->enqueue_admin_assets('admin.php');

		$this->assertTrue($media_called, 'wp_enqueue_media should be called for FFC menu pages');
	}

	// ==================================================================
	// Conditional assets - Settings page
	// ==================================================================

	public function test_enqueue_admin_assets_loads_settings_css_on_settings_page(): void {
		global $post_type;
		$post_type = 'ffc_form';

		$_GET['page'] = 'ffc-settings';

		$enqueued_styles = [];
		$enqueued_scripts = [];

		Functions\when('wp_enqueue_media')->justReturn(true);
		Functions\when('wp_enqueue_script')->alias(function ($handle) use (&$enqueued_scripts) {
			$enqueued_scripts[] = $handle;
		});
		Functions\when('wp_enqueue_style')->alias(function ($handle) use (&$enqueued_styles) {
			$enqueued_styles[] = $handle;
		});
		Functions\when('wp_localize_script')->justReturn(true);
		Functions\when('admin_url')->justReturn('https://example.com/wp-admin/admin-ajax.php');
		Functions\when('wp_create_nonce')->justReturn('test_nonce');

		$manager = new AdminAssetsManager();
		$manager->enqueue_admin_assets('admin.php');

		$this->assertContains('ffc-admin-settings', $enqueued_styles);
		$this->assertContains('ffc-admin-migrations', $enqueued_scripts);
	}

	// ==================================================================
	// Conditional assets - Submission edit page
	// ==================================================================

	public function test_enqueue_admin_assets_loads_edit_assets_on_submission_edit(): void {
		global $post_type;
		$post_type = 'ffc_form';

		$_GET['page'] = 'ffc-submissions';
		$_GET['action'] = 'edit';

		$enqueued_styles = [];
		$enqueued_scripts = [];

		Functions\when('wp_enqueue_media')->justReturn(true);
		Functions\when('wp_enqueue_script')->alias(function ($handle) use (&$enqueued_scripts) {
			$enqueued_scripts[] = $handle;
		});
		Functions\when('wp_enqueue_style')->alias(function ($handle) use (&$enqueued_styles) {
			$enqueued_styles[] = $handle;
		});
		Functions\when('wp_localize_script')->justReturn(true);
		Functions\when('admin_url')->justReturn('https://example.com/wp-admin/admin-ajax.php');
		Functions\when('wp_create_nonce')->justReturn('test_nonce');

		$manager = new AdminAssetsManager();
		$manager->enqueue_admin_assets('admin.php');

		$this->assertContains('ffc-admin-submission-edit', $enqueued_styles);
		$this->assertContains('ffc-admin-submission-edit', $enqueued_scripts);
	}

	public function test_enqueue_admin_assets_does_not_load_edit_assets_on_list_page(): void {
		global $post_type;
		$post_type = 'ffc_form';

		$_GET['page'] = 'ffc-submissions';
		// No 'action' = 'edit'

		$enqueued_styles = [];

		Functions\when('wp_enqueue_media')->justReturn(true);
		Functions\when('wp_enqueue_script')->justReturn(true);
		Functions\when('wp_enqueue_style')->alias(function ($handle) use (&$enqueued_styles) {
			$enqueued_styles[] = $handle;
		});
		Functions\when('wp_localize_script')->justReturn(true);
		Functions\when('admin_url')->justReturn('https://example.com/wp-admin/admin-ajax.php');
		Functions\when('wp_create_nonce')->justReturn('test_nonce');

		$manager = new AdminAssetsManager();
		$manager->enqueue_admin_assets('admin.php');

		$this->assertNotContains('ffc-admin-submission-edit', $enqueued_styles);
	}

	// ==================================================================
	// Dark mode and core module
	// ==================================================================

	public function test_enqueue_admin_assets_calls_dark_mode(): void {
		global $post_type;
		$post_type = 'ffc_form';

		$this->utils_mock->shouldReceive('enqueue_dark_mode')->once();

		Functions\when('wp_enqueue_media')->justReturn(true);
		Functions\when('wp_enqueue_script')->justReturn(true);
		Functions\when('wp_enqueue_style')->justReturn(true);
		Functions\when('wp_localize_script')->justReturn(true);
		Functions\when('admin_url')->justReturn('https://example.com/wp-admin/admin-ajax.php');
		Functions\when('wp_create_nonce')->justReturn('test_nonce');

		$manager = new AdminAssetsManager();
		$manager->enqueue_admin_assets('post.php');
	}

	public function test_enqueue_admin_assets_uses_asset_suffix(): void {
		global $post_type;
		$post_type = 'ffc_form';

		$this->utils_mock->shouldReceive('asset_suffix')->andReturn('');

		$script_urls = [];
		Functions\when('wp_enqueue_media')->justReturn(true);
		Functions\when('wp_enqueue_script')->alias(function ($handle, $src = '') use (&$script_urls) {
			if ($src) {
				$script_urls[$handle] = $src;
			}
		});
		Functions\when('wp_enqueue_style')->justReturn(true);
		Functions\when('wp_localize_script')->justReturn(true);
		Functions\when('admin_url')->justReturn('https://example.com/wp-admin/admin-ajax.php');
		Functions\when('wp_create_nonce')->justReturn('test_nonce');

		$manager = new AdminAssetsManager();
		$manager->enqueue_admin_assets('post.php');

		// With empty suffix, URLs should NOT contain .min
		foreach ($script_urls as $handle => $url) {
			$this->assertStringNotContainsString('.min', $url, "Script $handle URL should not contain .min when suffix is empty");
		}
	}

	// ==================================================================
	// resolve_code_editor_theme() — static
	// ==================================================================

	public function test_resolve_code_editor_theme_defaults_to_dark_on_fresh_install(): void {
		Functions\when('get_option')->justReturn(array());

		$this->assertSame('dark', AdminAssetsManager::resolve_code_editor_theme());
	}

	public function test_resolve_code_editor_theme_returns_light_when_configured(): void {
		Functions\when('get_option')->justReturn(array('code_editor_theme' => 'light'));

		$this->assertSame('light', AdminAssetsManager::resolve_code_editor_theme());
	}

	public function test_resolve_code_editor_theme_returns_dark_when_configured(): void {
		Functions\when('get_option')->justReturn(array('code_editor_theme' => 'dark'));

		$this->assertSame('dark', AdminAssetsManager::resolve_code_editor_theme());
	}

	public function test_resolve_code_editor_theme_auto_follows_dark_mode_on(): void {
		Functions\when('get_option')->justReturn(array(
			'code_editor_theme' => 'auto',
			'dark_mode'         => 'on',
		));

		$this->assertSame('dark', AdminAssetsManager::resolve_code_editor_theme());
	}

	public function test_resolve_code_editor_theme_auto_with_dark_mode_off_resolves_light(): void {
		Functions\when('get_option')->justReturn(array(
			'code_editor_theme' => 'auto',
			'dark_mode'         => 'off',
		));

		$this->assertSame('light', AdminAssetsManager::resolve_code_editor_theme());
	}

	public function test_resolve_code_editor_theme_auto_with_dark_mode_auto_resolves_light(): void {
		// Client-side OS-prefers-dark cannot be evaluated at enqueue time; we fall back to light.
		Functions\when('get_option')->justReturn(array(
			'code_editor_theme' => 'auto',
			'dark_mode'         => 'auto',
		));

		$this->assertSame('light', AdminAssetsManager::resolve_code_editor_theme());
	}

	public function test_resolve_code_editor_theme_invalid_stored_value_treated_as_auto(): void {
		// Any non-'light'/'dark' stored value falls into the auto branch.
		Functions\when('get_option')->justReturn(array(
			'code_editor_theme' => 'bogus',
			'dark_mode'         => 'on',
		));

		$this->assertSame('dark', AdminAssetsManager::resolve_code_editor_theme());
	}

	public function test_resolve_code_editor_theme_corrupt_settings_option(): void {
		// get_option returns non-array (e.g. a string) — treat as empty and default to dark.
		Functions\when('get_option')->justReturn('corrupt');

		$this->assertSame('dark', AdminAssetsManager::resolve_code_editor_theme());
	}

	// ==================================================================
	// enqueue_admin_base_styles() / enqueue_code_editor_for() — static,
	// shared by the form editor and the cert-template CPT edit screen.
	// ==================================================================

	public function test_enqueue_admin_base_styles_enqueues_the_chain(): void {
		$styles = array();
		Functions\when('wp_enqueue_style')->alias(
			static function ($handle) use (&$styles) {
				$styles[] = $handle;
				return true;
			}
		);

		AdminAssetsManager::enqueue_admin_base_styles();

		foreach (array('ffc-pdf-core', 'ffc-common', 'ffc-admin-utilities', 'ffc-admin-css') as $handle) {
			$this->assertContains($handle, $styles);
		}
	}

	public function test_enqueue_code_editor_for_localizes_textarea_and_readonly(): void {
		Functions\when('wp_enqueue_style')->justReturn(true);
		Functions\when('get_option')->justReturn(array('code_editor_theme' => 'light'));

		$cm_config = null;
		Functions\when('wp_enqueue_code_editor')->alias(
			static function ($args) use (&$cm_config) {
				$cm_config = $args['codemirror'] ?? array();
				return array('codemirror' => $cm_config);
			}
		);
		$scripts = array();
		Functions\when('wp_enqueue_script')->alias(
			static function ($handle) use (&$scripts) {
				$scripts[] = $handle;
				return true;
			}
		);
		$localized = null;
		Functions\when('wp_localize_script')->alias(
			static function ($handle, $obj, $data) use (&$localized) {
				$localized = $data;
				return true;
			}
		);
		Functions\when('admin_url')->returnArg();

		AdminAssetsManager::enqueue_code_editor_for('ffc_template_html', true);

		$this->assertContains('ffc-admin-code-editor', $scripts);
		$this->assertSame('ffc_template_html', $localized['textareaId']);
		$this->assertTrue($localized['enabled']);
		$this->assertSame('nocursor', $cm_config['readOnly'], 'read-only editor for shipped defaults');
	}

	public function test_enqueue_code_editor_for_omits_readonly_for_editable(): void {
		Functions\when('wp_enqueue_style')->justReturn(true);
		Functions\when('get_option')->justReturn(array('code_editor_theme' => 'light'));

		$cm_config = null;
		Functions\when('wp_enqueue_code_editor')->alias(
			static function ($args) use (&$cm_config) {
				$cm_config = $args['codemirror'] ?? array();
				return array('codemirror' => $cm_config);
			}
		);
		Functions\when('wp_enqueue_script')->justReturn(true);
		$localized = null;
		Functions\when('wp_localize_script')->alias(
			static function ($handle, $obj, $data) use (&$localized) {
				$localized = $data;
				return true;
			}
		);
		Functions\when('admin_url')->returnArg();

		AdminAssetsManager::enqueue_code_editor_for('ffc_pdf_layout');

		$this->assertArrayNotHasKey('readOnly', $cm_config);
		$this->assertSame('ffc_pdf_layout', $localized['textareaId']);
	}

	// ==================================================================
	// enqueue_image_tools() / image_tool_strings() (#865)
	// ==================================================================

	public function test_image_tool_strings_exposes_the_media_handler_keys(): void {
		$strings = AdminAssetsManager::image_tool_strings();

		foreach (
			array(
				'wpMediaNotAvailable',
				'chooseBackgroundImage',
				'useThisImage',
				'backgroundImageSelected',
				'insertImageTitle',
				'insertImageButton',
				'htmlTextareaNotFound',
				'imageInserted',
			) as $key
		) {
			$this->assertArrayHasKey($key, $strings);
		}
	}

	public function test_enqueue_image_tools_loads_pdf_module_media_and_localizes(): void {
		$media_called = false;
		Functions\when('wp_enqueue_media')->alias(function () use (&$media_called) {
			$media_called = true;
		});
		$scripts = array();
		Functions\when('wp_enqueue_script')->alias(function ($handle) use (&$scripts) {
			$scripts[] = $handle;
		});
		Functions\when('admin_url')->justReturn('https://example.com/wp-admin/admin-ajax.php');
		Functions\when('wp_create_nonce')->justReturn('test_nonce');
		$localized = array();
		Functions\when('wp_localize_script')->alias(function ($handle, $name, $data) use (&$localized) {
			$localized[$name] = $data;
		});

		AdminAssetsManager::enqueue_image_tools();

		$this->assertTrue($media_called, 'wp.media must be loaded for the picker');
		$this->assertContains('ffc-core', $scripts);
		$this->assertContains('ffc-admin-pdf', $scripts);
		$this->assertArrayHasKey('ffc_ajax', $localized);
		$this->assertSame('test_nonce', $localized['ffc_ajax']['nonce']);
		$this->assertArrayHasKey('chooseBackgroundImage', $localized['ffc_ajax']['strings']);
	}
}
