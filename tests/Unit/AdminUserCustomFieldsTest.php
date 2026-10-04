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
 * Class-level process isolation is required because this test uses
 * Mockery alias mocks for AudienceRepository and CustomFieldRepository.
 * Other tests in the suite trigger autoloading of those classes
 * (CustomFieldRepository now depends on AudienceRepository), which
 * makes subsequent `alias:` mocks fail with "class already exists".
 *
 * @covers \FreeFormCertificate\Admin\AdminUserCustomFields
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AdminUserCustomFieldsTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface Alias mock for Utils */
	private $utils_mock;

	/** @var Mockery\MockInterface Alias mock for AudienceRepository */
	private $audience_repo_mock;

	/** @var Mockery\MockInterface Alias mock for CustomFieldReader */
	private $custom_field_repo_mock;

	/** @var Mockery\MockInterface Alias mock for CustomFieldWriter */
	private $custom_field_writer_mock;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		class_exists('\\FreeFormCertificate\\Admin\\AdminUserCustomFields');

		// Common WP function stubs
		Functions\when('__')->returnArg();
		Functions\when('esc_html__')->returnArg();
		Functions\when('esc_html')->returnArg();
		Functions\when('esc_attr')->returnArg();
		Functions\when('wp_kses_post')->returnArg();
		Functions\when('absint')->justReturn(1);
		Functions\when('sanitize_text_field')->returnArg();
		Functions\when('wp_unslash')->returnArg();
		Functions\when('FreeFormCertificate\Core\sanitize_text_field')->returnArg();
		Functions\when('FreeFormCertificate\Core\wp_unslash')->returnArg();

		// Utils alias mock
		$this->utils_mock = Mockery::mock('alias:\FreeFormCertificate\Core\AssetHelper');
		$ri_mock = Mockery::mock( 'alias:\FreeFormCertificate\Core\RequestInput' );
		$ri_mock->shouldReceive( 'get_get_key' )->andReturnUsing( static fn( $key, $default = '' ) => isset( $_GET[ $key ] ) ? (string) $_GET[ $key ] : $default );
		$ri_mock->shouldReceive( 'get_get_int' )->andReturnUsing( static fn( $key, $default = 0 ) => isset( $_GET[ $key ] ) ? (int) $_GET[ $key ] : $default );
		$ri_mock->shouldReceive( 'has_get' )->andReturnUsing( static fn( $key ) => isset( $_GET[ $key ] ) );
		$ri_mock->shouldReceive( 'get_post_string' )->andReturnUsing( function ( $key, $default = '' ) {
			return isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? $_POST[ $key ] : $default;
		} )->byDefault();
		$this->utils_mock->shouldReceive('asset_suffix')->andReturn('.min')->byDefault();
		// enqueue_common_style() puts the token palette on the screen (#1126 B).
		$this->utils_mock->shouldReceive('enqueue_common_style')->byDefault();

		// Repository alias mocks
		$this->audience_repo_mock = Mockery::mock('alias:FreeFormCertificate\Audience\AudienceReader');
		$this->custom_field_repo_mock = Mockery::mock('alias:\FreeFormCertificate\Reregistration\CustomFieldReader');
		$this->custom_field_writer_mock = Mockery::mock('alias:\FreeFormCertificate\Reregistration\CustomFieldWriter');
		// save_section() reads the stored values so it can keep one when a
		// required field arrives empty (#1120). Most tests do not care what
		// is stored; the ones that do override this.
		$this->custom_field_repo_mock->shouldReceive('get_user_data')->andReturn([])->byDefault();
		// render_section() reads the profile values of every profile-mapped
		// field the user has (#1538); with none mapped it never reaches
		// UserManager.
		$this->custom_field_repo_mock->shouldReceive('get_all_for_user')->andReturn([])->byDefault();
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

	public function test_init_registers_all_six_hooks(): void {
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
		// #1120 — the notice that says which required fields kept their value.
		$this->assertContains('admin_notices', $registered_hooks);
		$this->assertCount(6, $registered_hooks);
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
		Functions\when('wp_verify_nonce')->justReturn(false);
		$this->custom_field_writer_mock->shouldReceive('save_user_data')->never();

		AdminUserCustomFields::save_section(1);

		// If we reach here without error, the method returned early
		$this->assertTrue(true);
	}

	public function test_save_section_returns_early_with_invalid_nonce(): void {
		$_POST['ffc_user_custom_fields_nonce'] = 'bad_nonce';

		Functions\when('wp_verify_nonce')->justReturn(false);

		$this->custom_field_writer_mock->shouldReceive('save_user_data')->never();

		AdminUserCustomFields::save_section(1);

		$this->assertTrue(true);
	}

	public function test_save_section_returns_early_without_permission(): void {
		$_POST['ffc_user_custom_fields_nonce'] = 'valid_nonce';

		Functions\when('wp_verify_nonce')->justReturn(true);
		Functions\when('current_user_can')->justReturn(false);

		$this->custom_field_writer_mock->shouldReceive('save_user_data')->never();

		AdminUserCustomFields::save_section(1);

		$this->assertTrue(true);
	}

	public function test_save_section_returns_early_when_no_fields_for_user(): void {
		$_POST['ffc_user_custom_fields_nonce'] = 'valid_nonce';

		Functions\when('wp_verify_nonce')->justReturn(true);
		Functions\when('current_user_can')->justReturn(true);

		$this->custom_field_repo_mock->shouldReceive('get_all_for_user')->with(42, true)->andReturn([]);
		$this->custom_field_writer_mock->shouldReceive('save_user_data')->never();

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

		$this->custom_field_writer_mock->shouldReceive('save_user_data')
			->once()
			->with(5, Mockery::on(function ($data) {
				return isset($data['field_10']) && $data['field_10'] === 'Engineering';
			}));

		AdminUserCustomFields::save_section(5);
	}

	/**
	 * A profile-mapped field is written to the profile, not the snapshot
	 * (#1538): the reregistration form reads it from there, and the snapshot
	 * copy was one nothing read -- in plaintext for the sensitive fields.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_save_section_writes_profile_mapped_fields_to_the_profile(): void {
		$_POST['ffc_user_custom_fields_nonce'] = 'valid_nonce';
		$_POST['ffc_cf_10']                    = 'Engineering';
		$_POST['ffc_cf_11']                    = '1990-05-20';
		$_POST['ffc_cf_12']                    = '51817842080';

		$plain     = (object) array( 'id' => 10, 'field_type' => 'text', 'field_label' => 'Department' );
		$birth     = (object) array( 'id' => 11, 'field_type' => 'date', 'field_label' => 'Date of Birth', 'field_profile_key' => 'birth_date', 'is_sensitive' => 0 );
		$sensitive = (object) array( 'id' => 12, 'field_type' => 'text', 'field_label' => 'CPF', 'field_profile_key' => 'cpf', 'is_sensitive' => 1 );

		Functions\when('wp_verify_nonce')->justReturn(true);
		Functions\when('current_user_can')->justReturn(true);

		$this->custom_field_repo_mock->shouldReceive('get_all_for_user')->with(5, true)->andReturn([$plain, $birth, $sensitive]);

		$this->custom_field_writer_mock->shouldReceive('save_user_data')
			->once()
			->with(5, array( 'field_10' => 'Engineering' ));

		$manager = Mockery::mock('alias:\FreeFormCertificate\UserDashboard\UserManager');
		$manager->shouldReceive('get_extended_profile')->andReturn(array());
		$manager->shouldReceive('update_extended_profile')
			->once()
			->with(5, array( 'birth_date' => '1990-05-20', 'cpf' => '51817842080' ), array( 'cpf' ))
			->andReturn(true);

		AdminUserCustomFields::save_section(5);
	}

	/**
	 * A required profile-mapped field submitted empty keeps its value: the
	 * empty string never reaches the profile, so nothing is erased (#1120).
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_save_section_keeps_a_required_profile_field_submitted_empty(): void {
		$_POST['ffc_user_custom_fields_nonce'] = 'valid_nonce';
		$_POST['ffc_cf_11']                    = '';

		$birth = (object) array( 'id' => 11, 'field_type' => 'date', 'field_label' => 'Date of Birth', 'field_profile_key' => 'birth_date', 'is_sensitive' => 0, 'is_required' => 1 );

		Functions\when('wp_verify_nonce')->justReturn(true);
		Functions\when('current_user_can')->justReturn(true);
		Functions\when('get_current_user_id')->justReturn(1);
		Functions\when('set_transient')->justReturn(true);

		$this->custom_field_repo_mock->shouldReceive('get_all_for_user')->with(5, true)->andReturn([$birth]);
		$this->custom_field_writer_mock->shouldReceive('save_user_data')->once()->with(5, array());

		$manager = Mockery::mock('alias:\FreeFormCertificate\UserDashboard\UserManager');
		$manager->shouldReceive('get_extended_profile')->andReturn(array( 'birth_date' => '1990-05-20' ));
		$manager->shouldReceive('update_extended_profile')->never();

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

		$this->custom_field_writer_mock->shouldReceive('save_user_data')
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

		$this->custom_field_writer_mock->shouldReceive('save_user_data')
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

		$this->custom_field_writer_mock->shouldReceive('save_user_data')
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

		$this->custom_field_writer_mock->shouldReceive('save_user_data')
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

	public function test_render_section_returns_when_audiences_have_no_fields(): void {
		$user     = new \WP_User(10);
		$user->ID = 10;

		$audience       = (object) ['id' => 1, 'name' => 'Doctors', 'color' => '#fff'];

		$this->audience_repo_mock->shouldReceive('get_user_audiences')
			->with(10)->andReturn([$audience]);
		$this->custom_field_repo_mock->shouldReceive('get_user_data')
			->with(10)->andReturn([]);
		// No fields -> the audience section is skipped (continue branch).
		$this->custom_field_repo_mock->shouldReceive('get_by_audience_with_parents')
			->with(1, true)->andReturn([]);

		Functions\when('esc_html_e')->alias(function ($t) { echo $t; });
		Functions\when('wp_nonce_field')->justReturn('');

		ob_start();
		AdminUserCustomFields::render_section($user);
		$output = ob_get_clean();

		// Heading rendered, but no field section markup.
		$this->assertStringContainsString('FFC Custom Data', $output);
		$this->assertStringNotContainsString('ffc-cf-section-1', $output);
	}

	public function test_render_section_renders_all_field_types(): void {
		$user     = new \WP_User(10);
		$user->ID = 10;

		$audience = (object) ['id' => 1, 'name' => 'Doctors', 'color' => '#abcdef'];

		$text_field = (object) [
			'id' => 11, 'field_key' => 'key_11', 'field_label' => 'Department', 'field_type' => 'text',
			'is_required' => 1, 'source_audience_id' => 1, 'source_audience_name' => 'Doctors',
			'field_options' => ['help_text' => 'Pick one'],
		];
		$textarea_field = (object) [
			'id' => 12, 'field_key' => 'key_12', 'field_label' => 'Bio', 'field_type' => 'textarea',
			'is_required' => 0, 'source_audience_id' => 1, 'source_audience_name' => 'Doctors',
			'field_options' => '',
		];
		$select_field = (object) [
			'id' => 13, 'field_key' => 'key_13', 'field_label' => 'Shift', 'field_type' => 'select',
			'is_required' => 0, 'source_audience_id' => 2, 'source_audience_name' => 'Parent Aud',
			'field_options' => '',
		];
		$checkbox_field = (object) [
			'id' => 14, 'field_key' => 'key_14', 'field_label' => 'Active', 'field_type' => 'checkbox',
			'is_required' => 0, 'source_audience_id' => 1, 'source_audience_name' => 'Doctors',
			'field_options' => '',
		];
		$number_field = (object) [
			'id' => 15, 'field_key' => 'key_15', 'field_label' => 'Age', 'field_type' => 'number',
			'is_required' => 0, 'source_audience_id' => 1, 'source_audience_name' => 'Doctors',
			'field_options' => '',
		];
		$date_field = (object) [
			'id' => 16, 'field_key' => 'key_16', 'field_label' => 'Start', 'field_type' => 'date',
			'is_required' => 0, 'source_audience_id' => 1, 'source_audience_name' => 'Doctors',
			'field_options' => '',
		];
		$wh_field = (object) [
			'id' => 17, 'field_key' => 'key_17', 'field_label' => 'Hours', 'field_type' => 'working_hours',
			'is_required' => 0, 'source_audience_id' => 1, 'source_audience_name' => 'Doctors',
			'field_options' => '',
		];

		$fields = [
			$text_field, $textarea_field, $select_field, $checkbox_field,
			$number_field, $date_field, $wh_field,
		];

		$this->audience_repo_mock->shouldReceive('get_user_audiences')
			->with(10)->andReturn([$audience]);
		$this->custom_field_repo_mock->shouldReceive('get_user_data')
			->with(10)->andReturn([
				'field_11' => 'Cardiology',
				'field_17' => json_encode([['day' => 1, 'entry1' => '08:00', 'exit2' => '17:00']]),
			]);
		$this->custom_field_repo_mock->shouldReceive('get_by_audience_with_parents')
			->with(1, true)->andReturn($fields);
		$this->custom_field_repo_mock->shouldReceive('get_field_choices')
			->andReturn(['Morning', 'Night']);

		Functions\when('esc_html_e')->alias(function ($t) { echo $t; });
		Functions\when('esc_textarea')->returnArg();
		Functions\when('wp_nonce_field')->justReturn('');
		Functions\when('selected')->justReturn('');
		Functions\when('checked')->justReturn('');
		Functions\when('esc_html_e')->alias(static function ($text) { echo $text; });
		Functions\when('wp_json_encode')->alias(static fn($v) => json_encode($v));

		ob_start();
		AdminUserCustomFields::render_section($user);
		$output = ob_get_clean();

		$this->assertStringContainsString('FFC Custom Data', $output);
		$this->assertStringContainsString('ffc-cf-section-1', $output);
		// Text input + value.
		$this->assertStringContainsString('ffc_cf_11', $output);
		$this->assertStringContainsString('Cardiology', $output);
		// Required marker.
		$this->assertStringContainsString('required', $output);
		// Help text.
		$this->assertStringContainsString('Pick one', $output);
		// Textarea.
		$this->assertStringContainsString('<textarea', $output);
		// Select with choices.
		$this->assertStringContainsString('<select', $output);
		$this->assertStringContainsString('Morning', $output);
		// Inherited marker (source_audience_id != audience id).
		$this->assertStringContainsString('Inherited from', $output);
		// Checkbox.
		$this->assertStringContainsString('type="checkbox"', $output);
		// Number.
		$this->assertStringContainsString('type="number"', $output);
		// Date.
		$this->assertStringContainsString('type="date"', $output);
		// Working hours table.
		$this->assertStringContainsString('ffc-working-hours', $output);
		$this->assertStringContainsString('ffc-wh-table', $output);
	}

	public function test_render_section_deduplicates_shared_fields(): void {
		$user     = new \WP_User(10);
		$user->ID = 10;

		$audience = (object) ['id' => 1, 'name' => 'Doctors', 'color' => '#fff'];

		$field = (object) [
			'id' => 50, 'field_key' => 'key_50', 'field_label' => 'Code', 'field_type' => 'text',
			'is_required' => 0, 'source_audience_id' => 1, 'source_audience_name' => 'Doctors',
			'field_options' => '',
		];

		$this->audience_repo_mock->shouldReceive('get_user_audiences')
			->with(10)->andReturn([$audience]);
		$this->custom_field_repo_mock->shouldReceive('get_user_data')
			->with(10)->andReturn([]);
		// Same field returned twice -> second occurrence is skipped.
		$this->custom_field_repo_mock->shouldReceive('get_by_audience_with_parents')
			->with(1, true)->andReturn([$field, $field]);

		Functions\when('esc_html_e')->alias(function ($t) { echo $t; });
		Functions\when('wp_nonce_field')->justReturn('');
		Functions\when('selected')->justReturn('');

		ob_start();
		AdminUserCustomFields::render_section($user);
		$output = ob_get_clean();

		// The input name appears exactly once despite the duplicate field.
		$this->assertSame(1, substr_count($output, 'name="ffc_cf_50"'));
	}

	// ==================================================================
	// save_section() - working_hours branch
	// ==================================================================

	public function test_save_section_saves_working_hours_field(): void {
		$_POST['ffc_user_custom_fields_nonce'] = 'valid_nonce';

		$field = (object) [
			'id'         => 60,
			'field_type' => 'working_hours',
			'field_label' => 'Hours',
		];

		$_POST['ffc_cf_60'] = json_encode([
			['day' => 1, 'entry1' => '08:00', 'exit1' => '12:00', 'entry2' => '13:00', 'exit2' => '17:00'],
			// Invalid entry (missing keys) is dropped.
			['day' => 2, 'entry1' => '08:00'],
			'not-an-array',
		]);

		Functions\when('wp_verify_nonce')->justReturn(true);
		Functions\when('current_user_can')->justReturn(true);
		Functions\when('wp_json_encode')->alias(static fn($v) => json_encode($v));

		// Since #1128 the half-filled row is not only dropped, it is reported,
		// so the save writes the incomplete-rows transient on this path.
		$reported = null;
		Functions\when('get_current_user_id')->justReturn(1);
		Functions\when('set_transient')->alias(function ($key, $value) use (&$reported) {
			if (str_starts_with($key, 'ffc_cf_wh_incomplete_')) {
				$reported = $value;
			}
			return true;
		});

		$this->custom_field_repo_mock->shouldReceive('get_all_for_user')
			->with(8, true)->andReturn([$field]);

		$this->custom_field_writer_mock->shouldReceive('save_user_data')
			->once()
			->with(8, Mockery::on(function ($data) {
				$decoded = json_decode($data['field_60'], true);
				// Only the one fully-formed entry survives sanitization.
				return is_array($decoded)
					&& count($decoded) === 1
					&& $decoded[0]['day'] === 1
					&& $decoded[0]['entry1'] === '08:00'
					&& $decoded[0]['exit2'] === '17:00';
			}));

		AdminUserCustomFields::save_section(8);

		// The dropped row is announced, naming the day and what was missing —
		// dropping it in silence is the half of #1128 that was never the fix.
		$this->assertIsArray($reported);
		$this->assertCount(1, $reported);
		$this->assertSame(['exit2'], $reported[0]['missing']);
		$this->assertSame('Hours', $reported[0]['label']);
		// Not asserting the day here on purpose: this class stubs `absint` to
		// justReturn(1), so a day assertion would be checking the stub, not the
		// code. The day is covered in WorkingHoursTest, which stubs it faithfully.
	}

	public function test_save_section_working_hours_invalid_json_stores_empty_array(): void {
		$_POST['ffc_user_custom_fields_nonce'] = 'valid_nonce';

		$field = (object) [
			'id'         => 61,
			'field_type' => 'working_hours',
			'field_label' => 'Hours',
		];

		$_POST['ffc_cf_61'] = 'not valid json {{{';

		Functions\when('wp_verify_nonce')->justReturn(true);
		Functions\when('current_user_can')->justReturn(true);
		Functions\when('wp_json_encode')->alias(static fn($v) => json_encode($v));

		$this->custom_field_repo_mock->shouldReceive('get_all_for_user')
			->with(9, true)->andReturn([$field]);

		$this->custom_field_writer_mock->shouldReceive('save_user_data')
			->once()
			->with(9, Mockery::on(function ($data) {
				return $data['field_61'] === '[]';
			}));

		AdminUserCustomFields::save_section(9);
	}

	// ==================================================================
	// #1120 — a required field arriving empty keeps its stored value
	// ==================================================================

	/**
	 * Build the POST + mocks for one required text field and run save_section.
	 *
	 * @param string $posted Value the browser sent.
	 * @param string $stored Value already on the profile.
	 * @return array{0: array<string, mixed>, 1: array<int, string>} Saved data, and the labels the notice would name.
	 */
	private function save_required_text( string $posted, string $stored ): array {
		$_POST['ffc_user_custom_fields_nonce'] = 'valid_nonce';
		$_POST['ffc_cf_77']                    = $posted;

		Functions\when('wp_verify_nonce')->justReturn(true);
		Functions\when('current_user_can')->justReturn(true);
		Functions\when('get_current_user_id')->justReturn(3);

		$kept = array();
		Functions\when('set_transient')->alias(function ($key, $value) use (&$kept) {
			$kept = $value;
			return true;
		});

		$field = (object) [
			'id'          => 77,
			'field_type'  => 'text',
			'field_label' => 'Registration number',
			'is_required' => 1,
		];

		$this->custom_field_repo_mock->shouldReceive('get_all_for_user')
			->with(5, true)->andReturn([$field]);
		$this->custom_field_repo_mock->shouldReceive('get_user_data')
			->with(5)->andReturn(['field_77' => $stored]);

		$saved = array();
		$this->custom_field_writer_mock->shouldReceive('save_user_data')
			->once()
			->with(5, Mockery::on(function ($data) use (&$saved) {
				$saved = $data;
				return true;
			}));

		AdminUserCustomFields::save_section(5);

		return [$saved, $kept];
	}

	/**
	 * The `required` attribute stops this in the browser, but the browser is
	 * not the guard: a direct POST reaches save_section() all the same, and
	 * before #1120 it overwrote the stored value with the empty string.
	 */
	public function test_save_section_keeps_the_stored_value_when_a_required_field_is_empty(): void {
		[$saved, $kept] = $this->save_required_text('', '1234567');

		$this->assertSame('1234567', $saved['field_77']);
		$this->assertSame(['Registration number'], $kept, 'The operator has to be told the edit did not take.');
	}

	public function test_save_section_still_writes_a_supplied_value_on_a_required_field(): void {
		[$saved, $kept] = $this->save_required_text('7654321', '1234567');

		$this->assertSame('7654321', $saved['field_77']);
		$this->assertSame([], $kept);
	}

	/**
	 * Nothing to preserve means nothing to announce — a required field that
	 * was already empty stays empty, and the notice would be noise.
	 */
	public function test_save_section_does_not_announce_a_required_field_that_was_already_empty(): void {
		[$saved, $kept] = $this->save_required_text('', '');

		$this->assertSame('', $saved['field_77']);
		$this->assertSame([], $kept);
	}

	/**
	 * An optional field keeps the old behaviour: clearing it clears it.
	 */
	public function test_save_section_still_clears_an_optional_field(): void {
		$_POST['ffc_user_custom_fields_nonce'] = 'valid_nonce';
		$_POST['ffc_cf_78']                    = '';

		Functions\when('wp_verify_nonce')->justReturn(true);
		Functions\when('current_user_can')->justReturn(true);

		$field = (object) [
			'id'          => 78,
			'field_type'  => 'text',
			'field_label' => 'Nickname',
			'is_required' => 0,
		];

		$this->custom_field_repo_mock->shouldReceive('get_all_for_user')
			->with(5, true)->andReturn([$field]);
		$this->custom_field_repo_mock->shouldReceive('get_user_data')
			->with(5)->andReturn(['field_78' => 'Bob']);

		$this->custom_field_writer_mock->shouldReceive('save_user_data')
			->once()
			->with(5, Mockery::on(static fn($data) => '' === $data['field_78']));

		AdminUserCustomFields::save_section(5);
	}

	// ==================================================================
	// #1120 — render_field_input() emits `required` from the flag
	// ==================================================================

	/**
	 * Render one field and return its markup.
	 *
	 * @param string $type     Field type.
	 * @param bool   $required Whether the definition marks it required.
	 * @return string
	 */
	private function render_input( string $type, bool $required ): string {
		$field = (object) [
			'id'            => 1,
			'field_type'    => $type,
			'field_label'   => 'Label',
			'is_required'   => $required ? 1 : 0,
			'field_options' => '',
		];

		$this->custom_field_repo_mock->shouldReceive('get_field_choices')->andReturn(['a', 'b'])->byDefault();
		Functions\when('selected')->justReturn('');
		Functions\when('checked')->justReturn('');
		Functions\when('esc_html_e')->alias(static function ($text) { echo $text; });
		Functions\when('esc_textarea')->returnArg();
		Functions\when('wp_json_encode')->alias(static fn($v) => json_encode($v));

		$ref = new \ReflectionMethod(AdminUserCustomFields::class, 'render_field_input');
		$ref->setAccessible(true);

		ob_start();
		$ref->invokeArgs(null, [$field, 'ffc_cf_1', '']);

		return (string) ob_get_clean();
	}

	/**
	 * Until #1120 the flag drew an asterisk and nothing else — no input
	 * emitted the attribute and save_section() never checked it, so the
	 * screen promised something neither end enforced.
	 *
	 * @dataProvider requirable_types
	 * @param string $type Field type that must honour the flag.
	 */
	public function test_render_field_input_emits_required_for_a_required_field( string $type ): void {
		$this->assertStringContainsString('required', $this->render_input($type, true));
	}

	/**
	 * @dataProvider requirable_types
	 * @param string $type Field type that must not invent the attribute.
	 */
	public function test_render_field_input_omits_required_for_an_optional_field( string $type ): void {
		$this->assertStringNotContainsString('required', $this->render_input($type, false));
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function requirable_types(): array {
		return [
			'text'     => ['text'],
			'textarea' => ['textarea'],
			'number'   => ['number'],
			'date'     => ['date'],
			'select'   => ['select'],
		];
	}

	/**
	 * A required checkbox would have to be *ticked* to satisfy the browser,
	 * which is a different promise from "fill this in" and would change what
	 * existing profiles may save. The reregistration renderer draws the
	 * asterisk and skips the attribute for the same reason.
	 */
	public function test_render_field_input_never_marks_a_checkbox_required(): void {
		$html = $this->render_input('checkbox', true);

		$this->assertStringContainsString('type="checkbox"', $html);
		$this->assertStringNotContainsString('required', $html);
	}

	/**
	 * The working-hours value posts through a hidden input, which is barred
	 * from constraint validation outright; the two time inputs inside are
	 * what enforce it, and they predate #1120.
	 */
	public function test_render_field_input_does_not_mark_the_working_hours_carrier_required(): void {
		$html = $this->render_input('working_hours', true);

		$this->assertStringContainsString('type="hidden"', $html);
		$this->assertDoesNotMatchRegularExpression('/<input type="hidden"[^>]*required/', $html);
	}

	// ==================================================================
	// Masks, formats and dependent selects (the reregistration behaviours)
	// ==================================================================

	/**
	 * The mask comes from the field's own `field_mask`, and falls back to
	 * the validation format -- the reregistration form's rule, so a field
	 * masks alike on both screens.
	 */
	public function test_mask_for_prefers_the_field_mask_then_the_format(): void {
		$masked  = (object) [ 'field_mask' => 'cpf', 'validation_rules' => '{"format":"phone"}' ];
		$format  = (object) [ 'field_mask' => null, 'validation_rules' => '{"format":"phone"}' ];
		$neither = (object) [ 'field_mask' => '', 'validation_rules' => null ];

		$this->assertSame( 'cpf', AdminUserCustomFields::mask_for( $masked ) );
		$this->assertSame( 'phone', AdminUserCustomFields::mask_for( $format ) );
		$this->assertSame( '', AdminUserCustomFields::mask_for( $neither ) );
	}

	/**
	 * CPF, RF, RG and the phones carry a `data-mask` the shared script reads,
	 * and every row names its field key and format, which is what the
	 * dual-post toggle and the format check select by.
	 */
	public function test_render_section_emits_the_mask_the_key_and_the_format(): void {
		$user     = new \WP_User(10);
		$user->ID = 10;
		$audience = (object) [ 'id' => 1, 'name' => 'Staff', 'color' => '#fff' ];

		$cpf = (object) [
			'id' => 21, 'field_key' => 'cpf', 'field_label' => 'CPF', 'field_type' => 'text',
			'field_mask' => 'cpf', 'validation_rules' => '{"format":"cpf"}',
			'is_required' => 0, 'source_audience_id' => 1, 'source_audience_name' => 'Staff', 'field_options' => '',
		];
		$phone = (object) [
			'id' => 22, 'field_key' => 'celular', 'field_label' => 'Mobile', 'field_type' => 'text',
			'field_mask' => null, 'validation_rules' => '{"format":"phone"}',
			'is_required' => 0, 'source_audience_id' => 1, 'source_audience_name' => 'Staff', 'field_options' => '',
		];

		$this->audience_repo_mock->shouldReceive('get_user_audiences')->with(10)->andReturn([$audience]);
		$this->custom_field_repo_mock->shouldReceive('get_by_audience_with_parents')->with(1, true)->andReturn([$cpf, $phone]);
		Functions\when('esc_html_e')->alias(static function ($t) { echo $t; });
		Functions\when('wp_nonce_field')->justReturn('');

		ob_start();
		AdminUserCustomFields::render_section($user);
		$output = (string) ob_get_clean();

		$this->assertStringContainsString('id="ffc-user-custom-fields"', $output);
		$this->assertMatchesRegularExpression('/<tr data-field-key="cpf"\s+data-format="cpf"/', $output);
		$this->assertMatchesRegularExpression('/name="ffc_cf_21"[^>]*data-mask="cpf"/', $output);
		$this->assertMatchesRegularExpression('/<tr data-field-key="celular"\s+data-format="phone"/', $output);
		$this->assertMatchesRegularExpression('/name="ffc_cf_22"[^>]*data-mask="phone"/', $output);
	}

	/**
	 * "Division / Department" was drawn as a raw text box holding the
	 * stored JSON; it now draws the reregistration form's own cascade.
	 */
	public function test_render_field_input_draws_a_dependent_select_as_the_cascade(): void {
		$field = (object) [
			'id' => 30, 'field_key' => 'divisao_setor', 'field_label' => 'Division / Department',
			'field_type' => 'dependent_select', 'is_required' => 1,
			'field_options' => '{"parent_label":"Division","child_label":"Department"}',
		];
		$this->custom_field_repo_mock->shouldReceive('get_dependent_choices')->andReturn([
			'DIPED' => ['Sector A', 'Sector B'],
			'DRE'   => ['Sector C'],
		]);
		Functions\when('esc_html_e')->alias(static function ($t) { echo $t; });
		Functions\when('selected')->alias(static fn($a, $b, $echo = true) => (string) $a === (string) $b ? ' selected' : '');
		Functions\when('wp_json_encode')->alias(static fn($v) => json_encode($v));

		$ref = new \ReflectionMethod(AdminUserCustomFields::class, 'render_field_input');
		$ref->setAccessible(true);
		ob_start();
		$ref->invokeArgs(null, [$field, 'ffc_cf_30', '{"parent":"DIPED","child":"Sector B"}']);
		$html = (string) ob_get_clean();

		$this->assertStringContainsString('class="ffc-dependent-select" data-target="ffc_cf_30"', $html);
		$this->assertStringContainsString('name="ffc_cf_30"', $html);
		$this->assertStringContainsString('Division', $html);
		$this->assertStringContainsString('<option value="Sector B"  selected>', $html);
		$this->assertStringContainsString('ffc-dep-groups', $html);
		$this->assertStringNotContainsString('type="text"', $html);
	}

	/**
	 * The posted pair is decoded, checked against the field's choices and
	 * re-encoded from the two validated strings.
	 */
	public function test_clean_dependent_select_accepts_only_a_listed_pair(): void {
		$field = (object) [
			'id' => 30, 'field_label' => 'Division / Department', 'field_type' => 'dependent_select',
			'is_required' => 0, 'field_options' => '',
		];
		$this->custom_field_repo_mock->shouldReceive('get_dependent_choices')->andReturn([ 'DIPED' => ['Sector A'] ]);
		$this->custom_field_repo_mock->shouldReceive('get_validation_rules')->andReturn([]);
		Functions\when('wp_json_encode')->alias(static fn($v) => json_encode($v));

		$this->assertSame(
			'{"parent":"DIPED","child":"Sector A"}',
			AdminUserCustomFields::clean_dependent_select( $field, ' {"child":"Sector A","parent":"DIPED","extra":"x"} ' )
		);
		$this->assertSame( '', AdminUserCustomFields::clean_dependent_select( $field, '{"parent":"","child":""}' ) );
		$this->assertSame( '', AdminUserCustomFields::clean_dependent_select( $field, 'not json' ) );
		$this->assertNull( AdminUserCustomFields::clean_dependent_select( $field, '{"parent":"DIPED","child":"Elsewhere"}' ) );
		$this->assertNull( AdminUserCustomFields::clean_dependent_select( $field, '{"parent":"Nowhere","child":"Sector A"}' ) );
		$this->assertNull( AdminUserCustomFields::clean_dependent_select( $field, '{"parent":"DIPED","child":""}' ) );
	}

	/**
	 * `divisao_setor` is profile-mapped, so a valid pair goes to the profile
	 * as canonical JSON and never into the snapshot.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_save_section_writes_a_dependent_select_to_the_profile(): void {
		$_POST['ffc_user_custom_fields_nonce'] = 'valid_nonce';
		$_POST['ffc_cf_30']                    = '{"parent":"DIPED","child":"Sector A"}';

		$field = (object) [
			'id' => 30, 'field_type' => 'dependent_select', 'field_label' => 'Division / Department',
			'field_profile_key' => 'divisao_setor', 'is_sensitive' => 0, 'is_required' => 1, 'field_options' => '',
		];

		Functions\when('wp_verify_nonce')->justReturn(true);
		Functions\when('current_user_can')->justReturn(true);
		Functions\when('wp_json_encode')->alias(static fn($v) => json_encode($v));

		$this->custom_field_repo_mock->shouldReceive('get_all_for_user')->with(5, true)->andReturn([$field]);
		$this->custom_field_repo_mock->shouldReceive('get_dependent_choices')->andReturn([ 'DIPED' => ['Sector A'] ]);
		$this->custom_field_repo_mock->shouldReceive('get_validation_rules')->andReturn([]);
		$this->custom_field_writer_mock->shouldReceive('save_user_data')->once()->with(5, array());

		$manager = Mockery::mock('alias:\FreeFormCertificate\UserDashboard\UserManager');
		$manager->shouldReceive('get_extended_profile')->andReturn(array());
		$manager->shouldReceive('update_extended_profile')
			->once()
			->with(5, array( 'divisao_setor' => '{"parent":"DIPED","child":"Sector A"}' ), array())
			->andReturn(true);

		AdminUserCustomFields::save_section(5);
	}

	/**
	 * A pair outside the choices keeps the stored value and is announced,
	 * instead of being written as posted.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_save_section_keeps_the_stored_pair_when_the_posted_one_is_invalid(): void {
		$_POST['ffc_user_custom_fields_nonce'] = 'valid_nonce';
		$_POST['ffc_cf_30']                    = '{"parent":"DIPED","child":"<script>"}';

		$field = (object) [
			'id' => 30, 'field_type' => 'dependent_select', 'field_label' => 'Division / Department',
			'is_required' => 0, 'field_options' => '',
		];

		Functions\when('wp_verify_nonce')->justReturn(true);
		Functions\when('current_user_can')->justReturn(true);
		Functions\when('get_current_user_id')->justReturn(1);
		Functions\when('wp_json_encode')->alias(static fn($v) => json_encode($v));

		$transients = array();
		Functions\when('set_transient')->alias(function ($key, $value) use (&$transients) {
			$transients[ $key ] = $value;
			return true;
		});

		$this->custom_field_repo_mock->shouldReceive('get_all_for_user')->with(5, true)->andReturn([$field]);
		$this->custom_field_repo_mock->shouldReceive('get_user_data')->andReturn([ 'field_30' => '{"parent":"DIPED","child":"Sector A"}' ]);
		$this->custom_field_repo_mock->shouldReceive('get_dependent_choices')->andReturn([ 'DIPED' => ['Sector A'] ]);
		$this->custom_field_repo_mock->shouldReceive('get_validation_rules')->andReturn([]);
		$this->custom_field_writer_mock->shouldReceive('save_user_data')
			->once()
			->with(5, array( 'field_30' => '{"parent":"DIPED","child":"Sector A"}' ));

		AdminUserCustomFields::save_section(5);

		$this->assertSame( array( 'Division / Department' ), $transients['ffc_cf_required_kept_1'] ?? null );
	}

	/**
	 * The shared behaviours are enqueued with their strings, and the value
	 * that reveals the accumulation fields is the translated "I hold" the
	 * field stores.
	 */
	public function test_enqueue_assets_loads_the_shared_field_behaviours(): void {
		$scripts  = array();
		$localize = array();
		Functions\when('wp_enqueue_style')->justReturn(true);
		Functions\when('wp_enqueue_script')->alias(function ($handle, $src = '', $deps = array()) use (&$scripts) {
			$scripts[ $handle ] = $deps;
		});
		Functions\when('wp_localize_script')->alias(function ($handle, $name, $data) use (&$localize) {
			$localize[ $name ] = $data;
		});

		AdminUserCustomFields::enqueue_assets('user-edit.php');

		$this->assertSame( array( 'jquery', 'ffc-core' ), $scripts['ffc-field-behaviours'] ?? null );
		$this->assertSame( array( 'jquery', 'ffc-field-behaviours' ), $scripts['ffc-admin-user-fields'] ?? null );
		$this->assertSame( 'I hold', $localize['ffcAdminUserFields']['strings']['dualPostShowValue'] ?? null );
	}
}
