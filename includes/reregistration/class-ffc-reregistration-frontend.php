<?php
declare(strict_types=1);

/**
 * Reregistration Frontend (Coordinator)
 *
 * Thin coordinator that handles AJAX endpoints and delegates to:
 *
 *   ReregistrationFieldOptions   – Form field option data (sexo, estado civil, etc.)
 *   ReregistrationFormRenderer   – Form HTML rendering
 *   ReregistrationDataProcessor  – Data collection, validation, and submission processing
 *
 * @since 4.11.0
 * @version 4.12.8 - Refactored into coordinator + 3 sub-classes
 * @package FreeFormCertificate\Reregistration
 */

namespace FreeFormCertificate\Reregistration;

if (!defined('ABSPATH')) {
    exit;
}

class ReregistrationFrontend {

    /**
     * Initialize AJAX hooks.
     *
     * @return void
     */
    public static function init(): void {
        add_action('wp_ajax_ffc_get_reregistration_form', array(__CLASS__, 'ajax_get_form'));
        add_action('wp_ajax_ffc_submit_reregistration', array(__CLASS__, 'ajax_submit'));
        add_action('wp_ajax_ffc_save_reregistration_draft', array(__CLASS__, 'ajax_save_draft'));
    }

    /**
     * AJAX: Get reregistration form HTML.
     *
     * @return void
     */
    public static function ajax_get_form(): void {
        check_ajax_referer('ffc_reregistration_frontend', 'nonce');

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified via check_ajax_referer() above.
        $reregistration_id = isset($_POST['reregistration_id']) ? absint($_POST['reregistration_id']) : 0;
        $user_id = get_current_user_id();

        if (!$reregistration_id || !$user_id) {
            wp_send_json_error(array('message' => __('Invalid request.', 'ffcertificate')));
        }

        $rereg = ReregistrationRepository::get_by_id($reregistration_id);
        if (!$rereg || $rereg->status !== 'active') {
            wp_send_json_error(array('message' => __('Reregistration not found or not active.', 'ffcertificate')));
        }

        $submission = ReregistrationSubmissionRepository::get_by_reregistration_and_user($reregistration_id, $user_id);
        if (!$submission) {
            wp_send_json_error(array('message' => __('No submission found for this user.', 'ffcertificate')));
        }

        if (in_array($submission->status, array('approved', 'expired'), true)) {
            wp_send_json_error(array('message' => __('This reregistration has already been completed or expired.', 'ffcertificate')));
        }

        $html = ReregistrationFormRenderer::render($rereg, $submission, $user_id);
        wp_send_json_success(array('html' => $html));
    }

    /**
     * AJAX: Submit reregistration.
     *
     * @return void
     */
    public static function ajax_submit(): void {
        check_ajax_referer('ffc_reregistration_frontend', 'nonce');

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified via check_ajax_referer() above.
        $reregistration_id = isset($_POST['reregistration_id']) ? absint($_POST['reregistration_id']) : 0;
        $user_id = get_current_user_id();

        if (!$reregistration_id || !$user_id) {
            wp_send_json_error(array('message' => __('Invalid request.', 'ffcertificate')));
        }

        $rereg = ReregistrationRepository::get_by_id($reregistration_id);
        if (!$rereg || $rereg->status !== 'active') {
            wp_send_json_error(array('message' => __('Reregistration not found or not active.', 'ffcertificate')));
        }

        $submission = ReregistrationSubmissionRepository::get_by_reregistration_and_user($reregistration_id, $user_id);
        if (!$submission) {
            wp_send_json_error(array('message' => __('No submission found.', 'ffcertificate')));
        }

        if (in_array($submission->status, array('approved', 'expired'), true)) {
            wp_send_json_error(array('message' => __('This reregistration has already been completed or expired.', 'ffcertificate')));
        }

        // Collect and validate fields
        $data = ReregistrationDataProcessor::collect_form_data($rereg, $user_id);
        $errors = ReregistrationDataProcessor::validate_submission($data, $rereg, $user_id);

        if (!empty($errors)) {
            wp_send_json_error(array('message' => __('Please fix the errors below.', 'ffcertificate'), 'errors' => $errors));
        }

        // Process submission
        ReregistrationDataProcessor::process_submission($submission, $rereg, $data, $user_id);

        wp_send_json_success(array('message' => __('Reregistration submitted successfully!', 'ffcertificate')));
    }

    /**
     * AJAX: Save draft.
     *
     * @return void
     */
    public static function ajax_save_draft(): void {
        check_ajax_referer('ffc_reregistration_frontend', 'nonce');

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified via check_ajax_referer() above.
        $reregistration_id = isset($_POST['reregistration_id']) ? absint($_POST['reregistration_id']) : 0;
        $user_id = get_current_user_id();

        if (!$reregistration_id || !$user_id) {
            wp_send_json_error(array('message' => __('Invalid request.', 'ffcertificate')));
        }

        $rereg = ReregistrationRepository::get_by_id($reregistration_id);
        if (!$rereg || $rereg->status !== 'active') {
            wp_send_json_error(array('message' => __('Reregistration not active.', 'ffcertificate')));
        }

        $submission = ReregistrationSubmissionRepository::get_by_reregistration_and_user($reregistration_id, $user_id);
        if (!$submission || in_array($submission->status, array('approved', 'expired'), true)) {
            wp_send_json_error(array('message' => __('Cannot save draft.', 'ffcertificate')));
        }

        $data = ReregistrationDataProcessor::collect_form_data($rereg, $user_id);

        ReregistrationSubmissionRepository::update((int) $submission->id, array(
            'data'   => $data,
            'status' => 'in_progress',
        ));

        wp_send_json_success(array('message' => __('Draft saved.', 'ffcertificate')));
    }

    // ------------------------------------------------------------------
    // Backward-compatible delegate methods
    // ------------------------------------------------------------------

    /**
     * Divisão → Setor mapping (delegates to ReregistrationFieldOptions).
     *
     * @return array<string, array<string>>
     */
    public static function get_divisao_setor_map(): array {
        return ReregistrationFieldOptions::get_divisao_setor_map();
    }

    /**
     * Get active reregistrations for a user with submission status.
     *
     * @param int $user_id User ID.
     * @return array<int, array<string, mixed>> Array of reregistration data with submission info.
     */
    public static function get_user_reregistrations(int $user_id): array {
        $active = ReregistrationRepository::get_active_for_user($user_id);
        $result = array();

        foreach ($active as $rereg) {
            $submission = ReregistrationSubmissionRepository::get_by_reregistration_and_user((int) $rereg->id, $user_id);
            $sub_status = $submission ? $submission->status : 'no_submission';

            // Build magic link for submitted/approved submissions
            $magic_link = '';
            if ($submission && in_array($sub_status, array('submitted', 'approved'), true)) {
                $token = ReregistrationSubmissionRepository::ensure_magic_token($submission);
                $magic_link = \FreeFormCertificate\Generators\MagicLinkHelper::generate_magic_link($token);
            }

            $result[] = array(
                'id'             => (int) $rereg->id,
                'title'          => $rereg->title,
                'audience_name'  => $rereg->audience_name ?? '',
                'start_date'     => $rereg->start_date,
                'end_date'       => $rereg->end_date,
                'auto_approve'   => !empty($rereg->auto_approve),
                'submission_status' => $sub_status,
                'submission_id'  => $submission ? (int) $submission->id : 0,
                'can_submit'     => in_array($sub_status, array('pending', 'in_progress', 'rejected'), true),
                'magic_link'     => $magic_link,
            );
        }

        return $result;
    }
}
