<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Brain\Monkey\Actions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\AdminUserCustomFields;

/**
 * Tests for AdminUserCustomFields: hook registration, conditional asset loading,
 * save_section nonce/permission checks, and data persistence.
 *
 * @covers \FreeFormCertificate\Admin\AdminUserCustomFields
 */
class AdminUserCustomFieldsTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var Mockery\MockInterface Alias mock for Utils */
    private $utils_mock;

    /** @var Mockery\MockInterface Alias mock for AudienceRepository */
    private $audience_repo_mock;

    /** @var Mockery\MockInterface Alias mock for CustomFieldRepository */
    private $custom_field_repo_mock;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Common WP function stubs
        Functions\when('__')->returnArg();
        Functions\when('esc_html__')->returnArg();
        Functions\when('esc_html')->returnArg();
        Functions\when('esc_attr')->returnArg();
        Functions\when('wp_kses_post')->returnArg();
        Functions\when('absint')->justReturn(1);
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('wp_unslash')->returnArg();

        // Utils alias mock
        $this->utils_mock = Mockery::mock('alias:\FreeFormCertificate\Core\Utils');
        $this->utils_mock->shouldReceive('asset_suffix')->andReturn('.min')->byDefault();

        // Repository alias mocks
        $this->audience_repo_mock = Mockery::mock('alias:\FreeFormCertificate\Audience\AudienceRepository');
        $this->custom_field_repo_mock = Mockery::mock('alias:\FreeFormCertificate\Reregistration\CustomFieldRepository');
    }

    protected function tearDown(): void {
        $_POST = array();
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // init()
    // ==================================================================

    public function test_init_registers_show_user_profile_action(): void {
        Actions\expectAdded('show_user_profile')
            ->once()
            ->with(
                Mockery::type('array'),
                30
            );

        Actions\expectAdded('edit_user_profile')->once();
        Actions\expectAdded('personal_options_update')->once();
        Actions\expectAdded('edit_user_profile_update')->once();
        Actions\expectAdded('admin_enqueue_scripts')->once();

        AdminUserCustomFields::init();
    }

    public function test_init_registers_all_five_hooks(): void {
        $registered_hooks = [];
        Functions\when('add_action')->alias(function ($hook, $callback, $priority = 10) use (&$registered_hooks) {
            $registered_hooks[] = $hook;
        });

        AdminUserCustomFields::init();

        $this->assertContains('show_user_profile', $registered_hooks);
        $this->assertContains('edit_user_profile', $registered_hooks);
        $this->assertContains('personal_options_update', $registered_hooks);
        $this->assertContains('edit_user_profile_update', $registered_hooks);
        $this->assertContains('admin_enqueue_scripts', $registered_hooks);
        $this->assertCount(5, $registered_hooks);
    }

    // ==================================================================
    // enqueue_assets()
    // ==================================================================

    public function test_enqueue_assets_returns_early_for_non_user_page(): void {
        $style_called = false;
        Functions\when('wp_enqueue_style')->alias(function () use (&$style_called) {
            $style_called = true;
        });

        AdminUserCustomFields::enqueue_assets('edit.php');

        $this->assertFalse($style_called, 'wp_enqueue_style should NOT be called on non-user pages');
    }

    public function test_enqueue_assets_loads_on_user_edit_page(): void {
        $enqueued_styles = [];
        $enqueued_scripts = [];

        Functions\when('wp_enqueue_style')->alias(function ($handle) use (&$enqueued_styles) {
            $enqueued_styles[] = $handle;
        });
        Functions\when('wp_enqueue_script')->alias(function ($handle) use (&$enqueued_scripts) {
            $enqueued_scripts[] = $handle;
        });
        Functions\when('wp_localize_script')->justReturn(true);

        AdminUserCustomFields::enqueue_assets('user-edit.php');

        $this->assertContains('ffc-working-hours', $enqueued_styles);
        $this->assertContains('ffc-custom-fields-admin', $enqueued_styles);
        $this->assertContains('ffc-working-hours', $enqueued_scripts);
    }

    public function test_enqueue_assets_loads_on_profile_page(): void {
        $enqueued_styles = [];

        Functions\when('wp_enqueue_style')->alias(function ($handle) use (&$enqueued_styles) {
            $enqueued_styles[] = $handle;
        });
        Functions\when('wp_enqueue_script')->justReturn(true);
        Functions\when('wp_localize_script')->justReturn(true);

        AdminUserCustomFields::enqueue_assets('profile.php');

        $this->assertContains('ffc-working-hours', $enqueued_styles);
        $this->assertContains('ffc-custom-fields-admin', $enqueued_styles);
    }

    public function test_enqueue_assets_localizes_day_labels(): void {
        $localized_data = null;

        Functions\when('wp_enqueue_style')->justReturn(true);
        Functions\when('wp_enqueue_script')->justReturn(true);
        Functions\when('wp_localize_script')->alias(function ($handle, $var, $data) use (&$localized_data) {
            if ($var === 'ffcWorkingHours') {
                $localized_data = $data;
            }
        });

        AdminUserCustomFields::enqueue_assets('user-edit.php');

        $this->assertNotNull($localized_data);
        $this->assertArrayHasKey('days', $localized_data);
        $this->assertCount(7, $localized_data['days']);
    }

    // ==================================================================
    // save_section() - nonce and permission checks
    // ==================================================================

    public function test_save_section_returns_early_without_nonce(): void {
        // No $_POST nonce set
        $this->custom_field_repo_mock->shouldReceive('save_user_data')->never();

        AdminUserCustomFields::save_section(1);

        // If we reach here without error, the method returned early
        $this->assertTrue(true);
    }

    public function test_save_section_returns_early_with_invalid_nonce(): void {
        $_POST['ffc_user_custom_fields_nonce'] = 'bad_nonce';

        Functions\when('wp_verify_nonce')->justReturn(false);

        $this->custom_field_repo_mock->shouldReceive('save_user_data')->never();

        AdminUserCustomFields::save_section(1);

        $this->assertTrue(true);
    }

    public function test_save_section_returns_early_without_permission(): void {
        $_POST['ffc_user_custom_fields_nonce'] = 'valid_nonce';

        Functions\when('wp_verify_nonce')->justReturn(true);
        Functions\when('current_user_can')->justReturn(false);

        $this->custom_field_repo_mock->shouldReceive('save_user_data')->never();

        AdminUserCustomFields::save_section(1);

        $this->assertTrue(true);
    }

    public function test_save_section_returns_early_when_no_fields_for_user(): void {
        $_POST['ffc_user_custom_fields_nonce'] = 'valid_nonce';

        Functions\when('wp_verify_nonce')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);

        $this->custom_field_repo_mock->shouldReceive('get_all_for_user')->with(42, true)->andReturn([]);
        $this->custom_field_repo_mock->shouldReceive('save_user_data')->never();

        AdminUserCustomFields::save_section(42);

        $this->assertTrue(true);
    }

    public function test_save_section_saves_text_field_data(): void {
        $_POST['ffc_user_custom_fields_nonce'] = 'valid_nonce';

        $field = (object) [
            'id' => 10,
            'field_type' => 'text',
            'field_label' => 'Department',
        ];

        $_POST['ffc_cf_10'] = 'Engineering';

        Functions\when('wp_verify_nonce')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);

        $this->custom_field_repo_mock->shouldReceive('get_all_for_user')
            ->with(5, true)
            ->andReturn([$field]);

        $this->custom_field_repo_mock->shouldReceive('save_user_data')
            ->once()
            ->with(5, Mockery::on(function ($data) {
                return isset($data['field_10']) && $data['field_10'] === 'Engineering';
            }));

        AdminUserCustomFields::save_section(5);
    }

    public function test_save_section_saves_checkbox_field_as_1_when_checked(): void {
        $_POST['ffc_user_custom_fields_nonce'] = 'valid_nonce';

        $field = (object) [
            'id' => 20,
            'field_type' => 'checkbox',
            'field_label' => 'Active',
        ];

        $_POST['ffc_cf_20'] = '1';

        Functions\when('wp_verify_nonce')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);

        $this->custom_field_repo_mock->shouldReceive('get_all_for_user')
            ->with(7, true)
            ->andReturn([$field]);

        $this->custom_field_repo_mock->shouldReceive('save_user_data')
            ->once()
            ->with(7, Mockery::on(function ($data) {
                return isset($data['field_20']) && $data['field_20'] === 1;
            }));

        AdminUserCustomFields::save_section(7);
    }

    public function test_save_section_saves_checkbox_field_as_0_when_unchecked(): void {
        $_POST['ffc_user_custom_fields_nonce'] = 'valid_nonce';

        $field = (object) [
            'id' => 20,
            'field_type' => 'checkbox',
            'field_label' => 'Active',
        ];

        // Checkbox not in POST means unchecked

        Functions\when('wp_verify_nonce')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);

        $this->custom_field_repo_mock->shouldReceive('get_all_for_user')
            ->with(7, true)
            ->andReturn([$field]);

        $this->custom_field_repo_mock->shouldReceive('save_user_data')
            ->once()
            ->with(7, Mockery::on(function ($data) {
                return isset($data['field_20']) && $data['field_20'] === 0;
            }));

        AdminUserCustomFields::save_section(7);
    }

    public function test_save_section_saves_textarea_field_with_sanitize_textarea(): void {
        $_POST['ffc_user_custom_fields_nonce'] = 'valid_nonce';

        $field = (object) [
            'id' => 30,
            'field_type' => 'textarea',
            'field_label' => 'Notes',
        ];

        $_POST['ffc_cf_30'] = "Line 1\nLine 2";

        Functions\when('wp_verify_nonce')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('sanitize_textarea_field')->returnArg();

        $this->custom_field_repo_mock->shouldReceive('get_all_for_user')
            ->with(3, true)
            ->andReturn([$field]);

        $this->custom_field_repo_mock->shouldReceive('save_user_data')
            ->once()
            ->with(3, Mockery::on(function ($data) {
                return isset($data['field_30']) && $data['field_30'] === "Line 1\nLine 2";
            }));

        AdminUserCustomFields::save_section(3);
    }

    public function test_save_section_deduplicates_fields_by_id(): void {
        $_POST['ffc_user_custom_fields_nonce'] = 'valid_nonce';

        // Same field appears twice (shared parent scenario)
        $field = (object) [
            'id' => 50,
            'field_type' => 'text',
            'field_label' => 'Code',
        ];

        $_POST['ffc_cf_50'] = 'ABC';

        Functions\when('wp_verify_nonce')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);

        $this->custom_field_repo_mock->shouldReceive('get_all_for_user')
            ->with(1, true)
            ->andReturn([$field, $field]); // Duplicated

        $this->custom_field_repo_mock->shouldReceive('save_user_data')
            ->once()
            ->with(1, Mockery::on(function ($data) {
                // Should only have one entry despite two fields
                return count($data) === 1 && $data['field_50'] === 'ABC';
            }));

        AdminUserCustomFields::save_section(1);
    }

    // ==================================================================
    // render_section() - early return when no audiences
    // ==================================================================

    public function test_render_section_returns_early_when_no_audiences(): void {
        $user = new \WP_User(10);
        $user->ID = 10;

        $this->audience_repo_mock->shouldReceive('get_user_audiences')
            ->with(10)
            ->andReturn([]);

        ob_start();
        AdminUserCustomFields::render_section($user);
        $output = ob_get_clean();

        $this->assertEmpty($output);
    }
}
