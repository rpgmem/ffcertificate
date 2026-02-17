<?php
declare(strict_types=1);

/**
 * Reregistration Submission Actions
 *
 * Handles admin actions on reregistration submissions:
 * approve, reject, return to draft, and bulk operations.
 *
 * @since 4.12.13  Extracted from ReregistrationAdmin
 * @package FreeFormCertificate\Reregistration
 */

namespace FreeFormCertificate\Reregistration;

if (!defined('ABSPATH')) {
    exit;
}

class ReregistrationSubmissionActions {

    /**
     * Handle approve single submission.
     *
     * @return void
     */
    public static function handle_approve(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (!isset($_GET['action']) || $_GET['action'] !== 'approve' || !isset($_GET['sub_id'])) {
            return;
        }

        $sub_id = absint($_GET['sub_id']);
        $rereg_id = isset($_GET['id']) ? absint($_GET['id']) : 0;

        if (!wp_verify_nonce(isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '', 'approve_submission_' . $sub_id)) {
            return;
        }

        ReregistrationSubmissionRepository::approve($sub_id, get_current_user_id());
        wp_safe_redirect(admin_url('admin.php?page=' . ReregistrationAdmin::MENU_SLUG . '&view=submissions&id=' . $rereg_id . '&message=approved'));
        exit;
    }

    /**
     * Handle reject single submission.
     *
     * @return void
     */
    public static function handle_reject(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (!isset($_GET['action']) || $_GET['action'] !== 'reject' || !isset($_GET['sub_id'])) {
            return;
        }

        $sub_id = absint($_GET['sub_id']);
        $rereg_id = isset($_GET['id']) ? absint($_GET['id']) : 0;

        if (!wp_verify_nonce(isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '', 'reject_submission_' . $sub_id)) {
            return;
        }

        ReregistrationSubmissionRepository::reject($sub_id, get_current_user_id());
        wp_safe_redirect(admin_url('admin.php?page=' . ReregistrationAdmin::MENU_SLUG . '&view=submissions&id=' . $rereg_id . '&message=rejected'));
        exit;
    }

    /**
     * Handle return-to-draft single submission.
     *
     * @return void
     */
    public static function handle_return_to_draft(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (!isset($_GET['action']) || $_GET['action'] !== 'return_to_draft' || !isset($_GET['sub_id'])) {
            return;
        }

        $sub_id = absint($_GET['sub_id']);
        $rereg_id = isset($_GET['id']) ? absint($_GET['id']) : 0;

        if (!wp_verify_nonce(isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '', 'return_to_draft_submission_' . $sub_id)) {
            return;
        }

        ReregistrationSubmissionRepository::return_to_draft($sub_id, get_current_user_id());
        wp_safe_redirect(admin_url('admin.php?page=' . ReregistrationAdmin::MENU_SLUG . '&view=submissions&id=' . $rereg_id . '&message=returned_to_draft'));
        exit;
    }

    /**
     * Handle bulk actions on submissions.
     *
     * @return void
     */
    public static function handle_bulk(): void {
        if (!isset($_POST['ffc_action']) || $_POST['ffc_action'] !== 'bulk_submissions') {
            return;
        }

        $rereg_id = isset($_POST['reregistration_id']) ? absint($_POST['reregistration_id']) : 0;
        if (!wp_verify_nonce(isset($_POST['ffc_bulk_nonce']) ? sanitize_text_field(wp_unslash($_POST['ffc_bulk_nonce'])) : '', 'bulk_submissions_' . $rereg_id)) {
            return;
        }

        $action = isset($_POST['bulk_action']) ? sanitize_text_field(wp_unslash($_POST['bulk_action'])) : '';
        $ids = isset($_POST['submission_ids']) ? array_map('absint', (array) $_POST['submission_ids']) : array();

        if (empty($ids) || empty($action)) {
            return;
        }

        if ($action === 'approve') {
            ReregistrationSubmissionRepository::bulk_approve($ids, get_current_user_id());
            wp_safe_redirect(admin_url('admin.php?page=' . ReregistrationAdmin::MENU_SLUG . '&view=submissions&id=' . $rereg_id . '&message=bulk_approved'));
            exit;
        }

        if ($action === 'return_to_draft') {
            ReregistrationSubmissionRepository::bulk_return_to_draft($ids, get_current_user_id());
            wp_safe_redirect(admin_url('admin.php?page=' . ReregistrationAdmin::MENU_SLUG . '&view=submissions&id=' . $rereg_id . '&message=bulk_returned_to_draft'));
            exit;
        }

        if ($action === 'send_reminder') {
            // Collect user IDs from submission IDs
            $user_ids = array();
            foreach ($ids as $sub_id) {
                $sub = ReregistrationSubmissionRepository::get_by_id($sub_id);
                if ($sub) {
                    $user_ids[] = (int) $sub->user_id;
                }
            }
            $sent = ReregistrationEmailHandler::send_reminders($rereg_id, $user_ids);
            wp_safe_redirect(admin_url('admin.php?page=' . ReregistrationAdmin::MENU_SLUG . '&view=submissions&id=' . $rereg_id . '&message=reminders_sent&count=' . $sent));
            exit;
        }
    }
}
