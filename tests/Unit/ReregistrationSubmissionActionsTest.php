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
 * Each handler checks $_GET/$_POST params and a nonce before writing. These
 * tests drive every guard and assert that the write did NOT happen.
 *
 * WHY THAT ASSERTION AND NOT ANOTHER (#1030). Until this pass they ended in
 * `assertTrue( true )` — they called the handler and declared success for not
 * throwing. Delete the nonce check from handle_approve() and every one of them
 * still passed, while the submission was approved. A guard test that does not
 * observe the guarded effect reports coverage it does not have.
 *
 * What each handler does once its guards pass is call
 * ReregistrationSubmissionWriter and then wp_safe_redirect(), and it reads
 * get_current_user_id() only to hand to that writer — so neither of those calls
 * happening is the observable proof that the guard held. Both are asserted
 * through Brain\Monkey rather than by alias-mocking the writer: an alias mock
 * must be installed before the class is autoloaded, which no single test file
 * can guarantee across the suite.
 *
 * The two expectations are repeated inline in every test rather than factored
 * into a helper. AssertionCoverageTest reads each test method's own body, and a
 * helper call is invisible to it — a test whose assertion cannot be seen is the
 * exact thing that guard exists to catch, so hiding them would trade one kind
 * of false negative for another.
 *
 * WHAT THIS DOES NOT COVER. The capability check. It lives one level up, in
 * ReregistrationAdmin::handle_actions(), which gates all four handlers on
 * `current_user_can( self::CAPABILITY )` before delegating — so asserting it
 * here would be testing something this class does not do. That belongs to
 * ReregistrationAdminTest.
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
		Functions\when('sanitize_text_field')->alias('trim');
		Functions\when('wp_unslash')->returnArg();
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
		Functions\expect('get_current_user_id')->never();
		Functions\expect('wp_safe_redirect')->never();
		ReregistrationSubmissionActions::handle_approve();
	}

	public function test_handle_approve_returns_early_when_action_is_not_approve(): void {
		$_GET['action'] = 'reject';
		$_GET['sub_id'] = '10';
		Functions\expect('get_current_user_id')->never();
		Functions\expect('wp_safe_redirect')->never();
		ReregistrationSubmissionActions::handle_approve();
	}

	public function test_handle_approve_returns_early_when_sub_id_missing(): void {
		$_GET['action'] = 'approve';
		// sub_id intentionally omitted.
		Functions\expect('get_current_user_id')->never();
		Functions\expect('wp_safe_redirect')->never();
		ReregistrationSubmissionActions::handle_approve();
	}

	public function test_handle_approve_returns_early_when_nonce_invalid(): void {
		$_GET['action']   = 'approve';
		$_GET['sub_id']   = '10';
		$_GET['id']       = '5';
		$_GET['_wpnonce'] = 'bad-nonce';

		Functions\when('wp_verify_nonce')->justReturn(false);

		Functions\expect('get_current_user_id')->never();
		Functions\expect('wp_safe_redirect')->never();
		ReregistrationSubmissionActions::handle_approve();
	}

	public function test_handle_approve_returns_early_when_nonce_key_missing(): void {
		$_GET['action'] = 'approve';
		$_GET['sub_id'] = '10';
		$_GET['id']     = '5';
		// _wpnonce key not set at all.

		Functions\when('wp_verify_nonce')->justReturn(false);

		Functions\expect('get_current_user_id')->never();
		Functions\expect('wp_safe_redirect')->never();
		ReregistrationSubmissionActions::handle_approve();
	}

	// ==================================================================
	// handle_reject()
	// ==================================================================

	public function test_handle_reject_returns_early_when_get_is_empty(): void {
		$_GET = array();
		Functions\expect('get_current_user_id')->never();
		Functions\expect('wp_safe_redirect')->never();
		ReregistrationSubmissionActions::handle_reject();
	}

	public function test_handle_reject_returns_early_when_action_is_not_reject(): void {
		$_GET['action'] = 'approve';
		$_GET['sub_id'] = '10';
		Functions\expect('get_current_user_id')->never();
		Functions\expect('wp_safe_redirect')->never();
		ReregistrationSubmissionActions::handle_reject();
	}

	public function test_handle_reject_returns_early_when_sub_id_missing(): void {
		$_GET['action'] = 'reject';
		Functions\expect('get_current_user_id')->never();
		Functions\expect('wp_safe_redirect')->never();
		ReregistrationSubmissionActions::handle_reject();
	}

	public function test_handle_reject_returns_early_when_nonce_invalid(): void {
		$_GET['action']   = 'reject';
		$_GET['sub_id']   = '10';
		$_GET['id']       = '5';
		$_GET['_wpnonce'] = 'bad-nonce';

		Functions\when('wp_verify_nonce')->justReturn(false);

		Functions\expect('get_current_user_id')->never();
		Functions\expect('wp_safe_redirect')->never();
		ReregistrationSubmissionActions::handle_reject();
	}

	// ==================================================================
	// handle_return_to_draft()
	// ==================================================================

	public function test_handle_return_to_draft_returns_early_when_get_is_empty(): void {
		$_GET = array();
		Functions\expect('get_current_user_id')->never();
		Functions\expect('wp_safe_redirect')->never();
		ReregistrationSubmissionActions::handle_return_to_draft();
	}

	public function test_handle_return_to_draft_returns_early_when_action_wrong(): void {
		$_GET['action'] = 'approve';
		$_GET['sub_id'] = '10';
		Functions\expect('get_current_user_id')->never();
		Functions\expect('wp_safe_redirect')->never();
		ReregistrationSubmissionActions::handle_return_to_draft();
	}

	public function test_handle_return_to_draft_returns_early_when_sub_id_missing(): void {
		$_GET['action'] = 'return_to_draft';
		Functions\expect('get_current_user_id')->never();
		Functions\expect('wp_safe_redirect')->never();
		ReregistrationSubmissionActions::handle_return_to_draft();
	}

	public function test_handle_return_to_draft_returns_early_when_nonce_invalid(): void {
		$_GET['action']   = 'return_to_draft';
		$_GET['sub_id']   = '10';
		$_GET['id']       = '5';
		$_GET['_wpnonce'] = 'bad-nonce';

		Functions\when('wp_verify_nonce')->justReturn(false);

		Functions\expect('get_current_user_id')->never();
		Functions\expect('wp_safe_redirect')->never();
		ReregistrationSubmissionActions::handle_return_to_draft();
	}

	// ==================================================================
	// handle_bulk()
	// ==================================================================

	public function test_handle_bulk_returns_early_when_post_is_empty(): void {
		$_POST = array();
		Functions\expect('get_current_user_id')->never();
		Functions\expect('wp_safe_redirect')->never();
		ReregistrationSubmissionActions::handle_bulk();
	}

	public function test_handle_bulk_returns_early_when_ffc_action_wrong(): void {
		$_POST['ffc_action'] = 'something_else';
		Functions\expect('get_current_user_id')->never();
		Functions\expect('wp_safe_redirect')->never();
		ReregistrationSubmissionActions::handle_bulk();
	}

	public function test_handle_bulk_returns_early_when_nonce_invalid(): void {
		$_POST['ffc_action']        = 'bulk_submissions';
		$_POST['reregistration_id'] = '5';
		$_POST['ffc_bulk_nonce']    = 'bad-nonce';

		Functions\when('wp_verify_nonce')->justReturn(false);

		Functions\expect('get_current_user_id')->never();
		Functions\expect('wp_safe_redirect')->never();
		ReregistrationSubmissionActions::handle_bulk();
	}

	public function test_handle_bulk_returns_early_when_ids_empty(): void {
		$_POST['ffc_action']        = 'bulk_submissions';
		$_POST['reregistration_id'] = '5';
		$_POST['ffc_bulk_nonce']    = 'good-nonce';
		$_POST['bulk_action']       = 'approve';
		$_POST['submission_ids']    = array();

		Functions\when('wp_verify_nonce')->justReturn(1);

		Functions\expect('get_current_user_id')->never();
		Functions\expect('wp_safe_redirect')->never();
		ReregistrationSubmissionActions::handle_bulk();
	}

	public function test_handle_bulk_returns_early_when_action_empty(): void {
		$_POST['ffc_action']        = 'bulk_submissions';
		$_POST['reregistration_id'] = '5';
		$_POST['ffc_bulk_nonce']    = 'good-nonce';
		$_POST['bulk_action']       = '';
		$_POST['submission_ids']    = array(1, 2);

		Functions\when('wp_verify_nonce')->justReturn(1);

		Functions\expect('get_current_user_id')->never();
		Functions\expect('wp_safe_redirect')->never();
		ReregistrationSubmissionActions::handle_bulk();
	}

	public function test_handle_bulk_returns_early_when_both_ids_and_action_empty(): void {
		$_POST['ffc_action']        = 'bulk_submissions';
		$_POST['reregistration_id'] = '5';
		$_POST['ffc_bulk_nonce']    = 'good-nonce';
		$_POST['bulk_action']       = '';
		$_POST['submission_ids']    = array();

		Functions\when('wp_verify_nonce')->justReturn(1);

		Functions\expect('get_current_user_id')->never();
		Functions\expect('wp_safe_redirect')->never();
		ReregistrationSubmissionActions::handle_bulk();
	}
}
