<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Reregistration\ReregistrationFrontend;

/**
 * Thrown by wp_send_json_error stub to halt execution (simulates die()).
 */
class FrontendJsonErrorException extends \RuntimeException {
    /** @var array<string, mixed> */
    public array $payload;

    public function __construct(array $payload = array()) {
        $this->payload = $payload;
        parent::__construct($payload['message'] ?? 'wp_send_json_error');
    }
}

/**
 * Thrown by wp_send_json_success stub to halt execution (simulates die()).
 */
class FrontendJsonSuccessException extends \RuntimeException {
    /** @var array<string, mixed> */
    public array $payload;

    public function __construct(array $payload = array()) {
        $this->payload = $payload;
        parent::__construct('wp_send_json_success');
    }
}

/**
 * Tests for ReregistrationFrontend: init hook registration,
 * AJAX get_form / submit / save_draft guards and delegation,
 * and backward-compatible delegate methods.
 *
 * @covers \FreeFormCertificate\Reregistration\ReregistrationFrontend
 */
class ReregistrationFrontendTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var array<string, array{0: string, 1: array}> Captured add_action calls */
    private array $registered_actions = array();

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Translation stubs
        Functions\when('__')->returnArg();
        Functions\when('esc_html__')->returnArg();
        Functions\when('esc_html')->returnArg();
        Functions\when('esc_attr')->returnArg();
        Functions\when('wp_kses_post')->returnArg();

        // Default WP stubs
        Functions\when('absint')->alias(function ($val) {
            return abs(intval($val));
        });
        Functions\when('sanitize_text_field')->alias('trim');
        Functions\when('wp_unslash')->returnArg();
        Functions\when('check_ajax_referer')->justReturn(true);

        // Capture add_action
        $actions = &$this->registered_actions;
        Functions\when('add_action')->alias(function ($hook, $callback) use (&$actions) {
            $actions[$hook] = $callback;
            return true;
        });

        // JSON response stubs — throw to simulate die()
        Functions\when('wp_send_json_error')->alias(function ($data = null) {
            throw new FrontendJsonErrorException(is_array($data) ? $data : array());
        });

        Functions\when('wp_send_json_success')->alias(function ($data = null) {
            throw new FrontendJsonSuccessException(is_array($data) ? $data : array());
        });

        // Mock $wpdb for repository calls
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturnUsing(function () {
            return func_get_args()[0];
        })->byDefault();
        $wpdb->shouldReceive('get_row')->andReturn(null)->byDefault();
        $wpdb->shouldReceive('get_results')->andReturn(array())->byDefault();
        $wpdb->shouldReceive('get_col')->andReturn(array())->byDefault();
        $wpdb->shouldReceive('update')->andReturn(1)->byDefault();

        // Cache stubs
        Functions\when('wp_cache_get')->justReturn(false);
        Functions\when('wp_cache_set')->justReturn(true);
        Functions\when('wp_cache_delete')->justReturn(true);

        $this->registered_actions = array();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
        unset($_POST['reregistration_id'], $_POST['nonce']);
    }

    // ==================================================================
    // init()
    // ==================================================================

    public function test_init_registers_three_ajax_actions(): void {
        ReregistrationFrontend::init();

        $this->assertArrayHasKey('wp_ajax_ffc_get_reregistration_form', $this->registered_actions);
        $this->assertArrayHasKey('wp_ajax_ffc_submit_reregistration', $this->registered_actions);
        $this->assertArrayHasKey('wp_ajax_ffc_save_reregistration_draft', $this->registered_actions);
    }

    public function test_init_callbacks_point_to_class_methods(): void {
        ReregistrationFrontend::init();

        $get_form_cb = $this->registered_actions['wp_ajax_ffc_get_reregistration_form'];
        $this->assertIsArray($get_form_cb);
        $this->assertSame(ReregistrationFrontend::class, $get_form_cb[0]);
        $this->assertSame('ajax_get_form', $get_form_cb[1]);
    }

    // ==================================================================
    // ajax_get_form() — early-exit guards
    // ==================================================================

    public function test_ajax_get_form_errors_when_no_reregistration_id(): void {
        Functions\when('get_current_user_id')->justReturn(1);

        $_POST['reregistration_id'] = 0;

        $ex = null;
        try {
            ReregistrationFrontend::ajax_get_form();
        } catch (FrontendJsonErrorException $e) {
            $ex = $e;
        }

        $this->assertNotNull($ex);
        $this->assertStringContainsString('Invalid request', $ex->payload['message']);
    }

    public function test_ajax_get_form_errors_when_no_user(): void {
        Functions\when('get_current_user_id')->justReturn(0);

        $_POST['reregistration_id'] = 5;

        $ex = null;
        try {
            ReregistrationFrontend::ajax_get_form();
        } catch (FrontendJsonErrorException $e) {
            $ex = $e;
        }

        $this->assertNotNull($ex);
        $this->assertStringContainsString('Invalid request', $ex->payload['message']);
    }

    public function test_ajax_get_form_errors_when_rereg_not_found(): void {
        Functions\when('get_current_user_id')->justReturn(1);

        $_POST['reregistration_id'] = 99;

        // wpdb->get_row returns null (no reregistration found)
        $ex = null;
        try {
            ReregistrationFrontend::ajax_get_form();
        } catch (FrontendJsonErrorException $e) {
            $ex = $e;
        }

        $this->assertNotNull($ex);
        $this->assertStringContainsString('not found or not active', $ex->payload['message']);
    }

    public function test_ajax_get_form_errors_when_no_submission_found(): void {
        Functions\when('get_current_user_id')->justReturn(1);

        $_POST['reregistration_id'] = 1;

        global $wpdb;
        $rereg = (object) array('id' => 1, 'status' => 'active', 'title' => 'Test');
        // First get_row: repo::get_by_id -> active rereg
        // Second get_row: submission repo -> null
        $wpdb->shouldReceive('get_row')
            ->andReturn($rereg, null);

        $ex = null;
        try {
            ReregistrationFrontend::ajax_get_form();
        } catch (FrontendJsonErrorException $e) {
            $ex = $e;
        }

        $this->assertNotNull($ex);
        $this->assertStringContainsString('No submission found', $ex->payload['message']);
    }

    public function test_ajax_get_form_errors_when_submission_already_approved(): void {
        Functions\when('get_current_user_id')->justReturn(1);

        $_POST['reregistration_id'] = 1;

        global $wpdb;
        $rereg = (object) array('id' => 1, 'status' => 'active', 'title' => 'Test');
        $submission = (object) array('id' => 10, 'status' => 'approved', 'user_id' => 1);
        $wpdb->shouldReceive('get_row')
            ->andReturn($rereg, $submission);

        $ex = null;
        try {
            ReregistrationFrontend::ajax_get_form();
        } catch (FrontendJsonErrorException $e) {
            $ex = $e;
        }

        $this->assertNotNull($ex);
        $this->assertStringContainsString('already been completed or expired', $ex->payload['message']);
    }

    public function test_ajax_get_form_errors_when_submission_expired(): void {
        Functions\when('get_current_user_id')->justReturn(1);

        $_POST['reregistration_id'] = 1;

        global $wpdb;
        $rereg = (object) array('id' => 1, 'status' => 'active', 'title' => 'Test');
        $submission = (object) array('id' => 10, 'status' => 'expired', 'user_id' => 1);
        $wpdb->shouldReceive('get_row')
            ->andReturn($rereg, $submission);

        $ex = null;
        try {
            ReregistrationFrontend::ajax_get_form();
        } catch (FrontendJsonErrorException $e) {
            $ex = $e;
        }

        $this->assertNotNull($ex);
        $this->assertStringContainsString('already been completed or expired', $ex->payload['message']);
    }

    // ==================================================================
    // ajax_submit() — early-exit guards
    // ==================================================================

    public function test_ajax_submit_errors_when_no_reregistration_id(): void {
        Functions\when('get_current_user_id')->justReturn(1);

        $_POST['reregistration_id'] = 0;

        $ex = null;
        try {
            ReregistrationFrontend::ajax_submit();
        } catch (FrontendJsonErrorException $e) {
            $ex = $e;
        }

        $this->assertNotNull($ex);
        $this->assertStringContainsString('Invalid request', $ex->payload['message']);
    }

    public function test_ajax_submit_errors_when_rereg_not_found(): void {
        Functions\when('get_current_user_id')->justReturn(1);

        $_POST['reregistration_id'] = 99;

        $ex = null;
        try {
            ReregistrationFrontend::ajax_submit();
        } catch (FrontendJsonErrorException $e) {
            $ex = $e;
        }

        $this->assertNotNull($ex);
        $this->assertStringContainsString('not found or not active', $ex->payload['message']);
    }

    public function test_ajax_submit_errors_when_submission_already_approved(): void {
        Functions\when('get_current_user_id')->justReturn(1);

        $_POST['reregistration_id'] = 1;

        global $wpdb;
        $rereg = (object) array('id' => 1, 'status' => 'active', 'title' => 'Test');
        $submission = (object) array('id' => 10, 'status' => 'approved', 'user_id' => 1);
        $wpdb->shouldReceive('get_row')
            ->andReturn($rereg, $submission);

        $ex = null;
        try {
            ReregistrationFrontend::ajax_submit();
        } catch (FrontendJsonErrorException $e) {
            $ex = $e;
        }

        $this->assertNotNull($ex);
        $this->assertStringContainsString('already been completed or expired', $ex->payload['message']);
    }

    // ==================================================================
    // ajax_save_draft() — early-exit guards
    // ==================================================================

    public function test_ajax_save_draft_errors_when_no_reregistration_id(): void {
        Functions\when('get_current_user_id')->justReturn(1);

        $_POST['reregistration_id'] = 0;

        $ex = null;
        try {
            ReregistrationFrontend::ajax_save_draft();
        } catch (FrontendJsonErrorException $e) {
            $ex = $e;
        }

        $this->assertNotNull($ex);
        $this->assertStringContainsString('Invalid request', $ex->payload['message']);
    }

    public function test_ajax_save_draft_errors_when_rereg_not_active(): void {
        Functions\when('get_current_user_id')->justReturn(1);

        $_POST['reregistration_id'] = 1;

        global $wpdb;
        $rereg = (object) array('id' => 1, 'status' => 'closed', 'title' => 'Test');
        $wpdb->shouldReceive('get_row')->andReturn($rereg);

        $ex = null;
        try {
            ReregistrationFrontend::ajax_save_draft();
        } catch (FrontendJsonErrorException $e) {
            $ex = $e;
        }

        $this->assertNotNull($ex);
        $this->assertStringContainsString('not active', $ex->payload['message']);
    }

    public function test_ajax_save_draft_errors_when_submission_approved(): void {
        Functions\when('get_current_user_id')->justReturn(1);

        $_POST['reregistration_id'] = 1;

        global $wpdb;
        $rereg = (object) array('id' => 1, 'status' => 'active', 'title' => 'Test');
        $submission = (object) array('id' => 10, 'status' => 'approved', 'user_id' => 1);
        $wpdb->shouldReceive('get_row')
            ->andReturn($rereg, $submission);

        $ex = null;
        try {
            ReregistrationFrontend::ajax_save_draft();
        } catch (FrontendJsonErrorException $e) {
            $ex = $e;
        }

        $this->assertNotNull($ex);
        $this->assertStringContainsString('Cannot save draft', $ex->payload['message']);
    }

    // ==================================================================
    // get_user_reregistrations() — returns structured array
    // ==================================================================

    public function test_get_user_reregistrations_returns_empty_when_no_active(): void {
        global $wpdb;
        $wpdb->shouldReceive('get_results')->andReturn(array());

        $result = ReregistrationFrontend::get_user_reregistrations(1);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_get_user_reregistrations_maps_can_submit_for_pending(): void {
        global $wpdb;

        $rereg = (object) array(
            'id'            => 5,
            'title'         => 'Campaign 2026',
            'audience_name' => 'Teachers',
            'start_date'    => '2026-01-01',
            'end_date'      => '2026-06-30',
            'auto_approve'  => 0,
        );

        // First get_results: get_active_for_user -> campaigns
        $wpdb->shouldReceive('get_results')->andReturn(array($rereg));

        $submission = (object) array('id' => 20, 'status' => 'pending', 'user_id' => 1, 'magic_token' => '');
        $wpdb->shouldReceive('get_row')
            ->andReturn($submission);

        $result = ReregistrationFrontend::get_user_reregistrations(1);

        $this->assertCount(1, $result);
        $this->assertSame(5, $result[0]['id']);
        $this->assertSame('pending', $result[0]['submission_status']);
        $this->assertTrue($result[0]['can_submit']);
        $this->assertSame('', $result[0]['magic_link']);
    }

    // ==================================================================
    // get_divisao_setor_map() — delegates to ReregistrationFieldOptions
    // ==================================================================

    public function test_get_divisao_setor_map_returns_non_empty_array(): void {
        $map = ReregistrationFrontend::get_divisao_setor_map();

        $this->assertIsArray($map);
        $this->assertNotEmpty($map);
    }
}
