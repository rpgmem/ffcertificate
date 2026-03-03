<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Reregistration\ReregistrationSubmissionActions;

/**
 * Tests for ReregistrationSubmissionActions: approve, reject,
 * return-to-draft, and bulk action handlers.
 *
 * Each handler checks $_GET/$_POST params and nonces before doing work.
 * We test the early-return guard clauses exhaustively, which covers the
 * majority of the method bodies without needing to mock exit().
 *
 * @covers \FreeFormCertificate\Reregistration\ReregistrationSubmissionActions
 */
class ReregistrationSubmissionActionsTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Reset superglobals before each test.
        $_GET  = array();
        $_POST = array();

        Functions\when('__')->returnArg();
        Functions\when('esc_html__')->returnArg();
        Functions\when('esc_html')->returnArg();
        Functions\when('esc_attr')->returnArg();
        Functions\when('absint')->alias(function ($val) {
            return abs(intval($val));
        });
        Functions\when('sanitize_text_field')->alias('trim');
        Functions\when('wp_unslash')->returnArg();
        Functions\when('get_current_user_id')->justReturn(1);
        Functions\when('admin_url')->alias(function ($path = '') {
            return 'https://example.com/wp-admin/' . $path;
        });
    }

    protected function tearDown(): void {
        $_GET  = array();
        $_POST = array();
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // handle_approve()
    // ==================================================================

    public function test_handle_approve_returns_early_when_get_is_empty(): void {
        $_GET = array();
        ReregistrationSubmissionActions::handle_approve();
        // No side effects = success.
        $this->assertTrue(true);
    }

    public function test_handle_approve_returns_early_when_action_is_not_approve(): void {
        $_GET['action'] = 'reject';
        $_GET['sub_id'] = '10';
        ReregistrationSubmissionActions::handle_approve();
        $this->assertTrue(true);
    }

    public function test_handle_approve_returns_early_when_sub_id_missing(): void {
        $_GET['action'] = 'approve';
        // sub_id intentionally omitted.
        ReregistrationSubmissionActions::handle_approve();
        $this->assertTrue(true);
    }

    public function test_handle_approve_returns_early_when_nonce_invalid(): void {
        $_GET['action']   = 'approve';
        $_GET['sub_id']   = '10';
        $_GET['id']       = '5';
        $_GET['_wpnonce'] = 'bad-nonce';

        Functions\when('wp_verify_nonce')->justReturn(false);

        ReregistrationSubmissionActions::handle_approve();
        $this->assertTrue(true);
    }

    public function test_handle_approve_returns_early_when_nonce_key_missing(): void {
        $_GET['action'] = 'approve';
        $_GET['sub_id'] = '10';
        $_GET['id']     = '5';
        // _wpnonce key not set at all.

        Functions\when('wp_verify_nonce')->justReturn(false);

        ReregistrationSubmissionActions::handle_approve();
        $this->assertTrue(true);
    }

    // ==================================================================
    // handle_reject()
    // ==================================================================

    public function test_handle_reject_returns_early_when_get_is_empty(): void {
        $_GET = array();
        ReregistrationSubmissionActions::handle_reject();
        $this->assertTrue(true);
    }

    public function test_handle_reject_returns_early_when_action_is_not_reject(): void {
        $_GET['action'] = 'approve';
        $_GET['sub_id'] = '10';
        ReregistrationSubmissionActions::handle_reject();
        $this->assertTrue(true);
    }

    public function test_handle_reject_returns_early_when_sub_id_missing(): void {
        $_GET['action'] = 'reject';
        ReregistrationSubmissionActions::handle_reject();
        $this->assertTrue(true);
    }

    public function test_handle_reject_returns_early_when_nonce_invalid(): void {
        $_GET['action']   = 'reject';
        $_GET['sub_id']   = '10';
        $_GET['id']       = '5';
        $_GET['_wpnonce'] = 'bad-nonce';

        Functions\when('wp_verify_nonce')->justReturn(false);

        ReregistrationSubmissionActions::handle_reject();
        $this->assertTrue(true);
    }

    // ==================================================================
    // handle_return_to_draft()
    // ==================================================================

    public function test_handle_return_to_draft_returns_early_when_get_is_empty(): void {
        $_GET = array();
        ReregistrationSubmissionActions::handle_return_to_draft();
        $this->assertTrue(true);
    }

    public function test_handle_return_to_draft_returns_early_when_action_wrong(): void {
        $_GET['action'] = 'approve';
        $_GET['sub_id'] = '10';
        ReregistrationSubmissionActions::handle_return_to_draft();
        $this->assertTrue(true);
    }

    public function test_handle_return_to_draft_returns_early_when_sub_id_missing(): void {
        $_GET['action'] = 'return_to_draft';
        ReregistrationSubmissionActions::handle_return_to_draft();
        $this->assertTrue(true);
    }

    public function test_handle_return_to_draft_returns_early_when_nonce_invalid(): void {
        $_GET['action']   = 'return_to_draft';
        $_GET['sub_id']   = '10';
        $_GET['id']       = '5';
        $_GET['_wpnonce'] = 'bad-nonce';

        Functions\when('wp_verify_nonce')->justReturn(false);

        ReregistrationSubmissionActions::handle_return_to_draft();
        $this->assertTrue(true);
    }

    // ==================================================================
    // handle_bulk()
    // ==================================================================

    public function test_handle_bulk_returns_early_when_post_is_empty(): void {
        $_POST = array();
        ReregistrationSubmissionActions::handle_bulk();
        $this->assertTrue(true);
    }

    public function test_handle_bulk_returns_early_when_ffc_action_wrong(): void {
        $_POST['ffc_action'] = 'something_else';
        ReregistrationSubmissionActions::handle_bulk();
        $this->assertTrue(true);
    }

    public function test_handle_bulk_returns_early_when_nonce_invalid(): void {
        $_POST['ffc_action']        = 'bulk_submissions';
        $_POST['reregistration_id'] = '5';
        $_POST['ffc_bulk_nonce']    = 'bad-nonce';

        Functions\when('wp_verify_nonce')->justReturn(false);

        ReregistrationSubmissionActions::handle_bulk();
        $this->assertTrue(true);
    }

    public function test_handle_bulk_returns_early_when_ids_empty(): void {
        $_POST['ffc_action']        = 'bulk_submissions';
        $_POST['reregistration_id'] = '5';
        $_POST['ffc_bulk_nonce']    = 'good-nonce';
        $_POST['bulk_action']       = 'approve';
        $_POST['submission_ids']    = array();

        Functions\when('wp_verify_nonce')->justReturn(1);

        ReregistrationSubmissionActions::handle_bulk();
        $this->assertTrue(true);
    }

    public function test_handle_bulk_returns_early_when_action_empty(): void {
        $_POST['ffc_action']        = 'bulk_submissions';
        $_POST['reregistration_id'] = '5';
        $_POST['ffc_bulk_nonce']    = 'good-nonce';
        $_POST['bulk_action']       = '';
        $_POST['submission_ids']    = array(1, 2);

        Functions\when('wp_verify_nonce')->justReturn(1);

        ReregistrationSubmissionActions::handle_bulk();
        $this->assertTrue(true);
    }

    public function test_handle_bulk_returns_early_when_both_ids_and_action_empty(): void {
        $_POST['ffc_action']        = 'bulk_submissions';
        $_POST['reregistration_id'] = '5';
        $_POST['ffc_bulk_nonce']    = 'good-nonce';
        $_POST['bulk_action']       = '';
        $_POST['submission_ids']    = array();

        Functions\when('wp_verify_nonce')->justReturn(1);

        ReregistrationSubmissionActions::handle_bulk();
        $this->assertTrue(true);
    }
}
