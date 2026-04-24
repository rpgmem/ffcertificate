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

        // Utils alias mock
        $this->utils_mock = Mockery::mock('alias:\FreeFormCertificate\Core\Utils');
        $this->utils_mock->shouldReceive('asset_suffix')->andReturn('.min')->byDefault();
        $this->utils_mock->shouldReceive('enqueue_dark_mode')->byDefault();
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
}
