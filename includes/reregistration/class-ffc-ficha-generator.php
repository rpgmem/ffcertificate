<?php
declare(strict_types=1);

/**
 * Ficha Generator
 *
 * Generates reregistration ficha (data sheet) PDF data.
 * Uses the same HTML→canvas→PDF pipeline as certificates.
 *
 * @since 4.11.0
 * @package FreeFormCertificate\Reregistration
 */

namespace FreeFormCertificate\Reregistration;

if (!defined('ABSPATH')) {
    exit;
}

class FichaGenerator {

    /**
     * Generate ficha data for a submission.
     *
     * @param int $submission_id Submission ID.
     * @return array{html: string, filename: string, user: array}|null Null on failure.
     */
    public static function generate_ficha_data(int $submission_id): ?array {
        $submission = ReregistrationSubmissionRepository::get_by_id($submission_id);
        if (!$submission) {
            return null;
        }

        $rereg = ReregistrationRepository::get_by_id((int) $submission->reregistration_id);
        if (!$rereg) {
            return null;
        }

        $user = get_userdata((int) $submission->user_id);
        if (!$user) {
            return null;
        }

        // Get submission data
        $sub_data = $submission->data ? json_decode($submission->data, true) : array();
        $standard = $sub_data['standard_fields'] ?? array();
        $custom_values = $sub_data['custom_fields'] ?? array();

        // Get custom field definitions
        $custom_fields = CustomFieldRepository::get_by_audience_with_parents((int) $rereg->audience_id, true);

        // Status labels (centralized in SubmissionRepository)
        $status_labels = ReregistrationSubmissionRepository::get_status_labels();

        // Date formatting
        $date_format = get_option('date_format');
        $time_format = get_option('time_format');

        $submitted_at = '';
        if (!empty($submission->submitted_at)) {
            $submitted_at = date_i18n($date_format . ' ' . $time_format, strtotime($submission->submitted_at));
        }

        // Build template variables
        $variables = array(
            'reregistration_title' => $rereg->title,
            'audience_name'        => $rereg->audience_name ?? '',
            'submission_status'    => $status_labels[$submission->status] ?? $submission->status,
            'submitted_at'         => $submitted_at,
            'display_name'         => $standard['display_name'] ?? $user->display_name,
            'email'                => $user->user_email,
            'phone'                => $standard['phone'] ?? '',
            'department'           => $standard['department'] ?? '',
            'organization'         => $standard['organization'] ?? '',
            'site_name'            => get_bloginfo('name'),
            'generation_date'      => wp_date($date_format . ' ' . $time_format),
        );

        /**
         * Filters ficha template variables before HTML generation.
         *
         * @since 4.11.0
         * @param array  $variables     Template variables.
         * @param int    $submission_id Submission ID.
         * @param object $submission    Submission object.
         * @param object $rereg         Reregistration object.
         */
        $variables = apply_filters('ffcertificate_ficha_data', $variables, $submission_id, $submission, $rereg);

        // Build custom fields section HTML
        $custom_section = self::build_custom_fields_section($custom_fields, $custom_values);

        // Load template
        $template = self::load_template();

        // Replace placeholders
        foreach ($variables as $key => $value) {
            if (is_array($value)) {
                $value = implode(', ', $value);
            }
            $template = str_replace('{{' . $key . '}}', wp_kses($value, \FreeFormCertificate\Core\Utils::get_allowed_html_tags()), $template);
        }

        // Replace custom fields section
        $template = str_replace('{{custom_fields_section}}', $custom_section, $template);

        // Fix relative URLs
        $site_url = untrailingslashit(get_home_url());
        $template = preg_replace('/(src|href|background)=["\']\/([^"\']+)["\']/i', '$1="' . $site_url . '/$2"', $template);

        /**
         * Filters the generated ficha HTML.
         *
         * @since 4.11.0
         * @param string $template      Generated HTML.
         * @param array  $variables     Template variables.
         * @param int    $submission_id Submission ID.
         */
        $html = apply_filters('ffcertificate_ficha_html', $template, $variables, $submission_id);

        // Generate filename
        $safe_title = sanitize_file_name($rereg->title);
        if (empty($safe_title)) {
            $safe_title = 'Ficha';
        }
        $safe_name = sanitize_file_name($user->display_name);
        $filename = 'Ficha_' . $safe_title . '_' . $safe_name . '.pdf';

        /**
         * Filters the ficha PDF filename.
         *
         * @since 4.11.0
         * @param string $filename      Generated filename.
         * @param int    $submission_id Submission ID.
         * @param object $submission    Submission object.
         */
        $filename = apply_filters('ffcertificate_ficha_filename', $filename, $submission_id, $submission);

        return array(
            'html'     => $html,
            'filename' => $filename,
            'user'     => array(
                'id'    => (int) $submission->user_id,
                'name'  => $variables['display_name'],
                'email' => $variables['email'],
            ),
            'type'     => 'ficha',
        );
    }

    /**
     * Build custom fields section HTML for the template.
     *
     * @param array $custom_fields  Custom field definitions.
     * @param array $custom_values  Custom field values keyed by field_X.
     * @return string HTML section.
     */
    private static function build_custom_fields_section(array $custom_fields, array $custom_values): string {
        if (empty($custom_fields)) {
            return '';
        }

        $html = '<div style="margin-bottom: 18px">';
        $html .= '<div style="font-size: 11pt;font-weight: bold;color: #0073aa;text-transform: uppercase;letter-spacing: 1px;padding-bottom: 6px;border-bottom: 2px solid #e8e8e8;margin-bottom: 10px">';
        $html .= esc_html__('Additional Information', 'ffcertificate');
        $html .= '</div>';
        $html .= '<table style="width: 100%;border-collapse: collapse;font-size: 10.5pt" role="presentation">';

        foreach ($custom_fields as $cf) {
            $key = 'field_' . $cf->id;
            $value = $custom_values[$key] ?? '';

            // Format checkbox values
            if ($cf->field_type === 'checkbox') {
                $value = $value === '1' ? __('Yes', 'ffcertificate') : __('No', 'ffcertificate');
            }

            $html .= '<tr>';
            $html .= '<td style="padding: 5px 0;font-weight: bold;color: #666;width: 150px;vertical-align: top">' . esc_html($cf->field_label) . ':</td>';
            $html .= '<td style="padding: 5px 0;color: #222">' . esc_html($value) . '</td>';
            $html .= '</tr>';
        }

        $html .= '</table></div>';

        return $html;
    }

    /**
     * Load the ficha HTML template.
     *
     * @return string HTML template with placeholders.
     */
    private static function load_template(): string {
        $template_file = FFC_PLUGIN_DIR . 'html/default_ficha_template.html';

        /**
         * Filters the ficha template file path.
         *
         * @since 4.11.0
         * @param string $template_file Template file path.
         */
        $template_file = apply_filters('ffcertificate_ficha_template_file', $template_file);

        if (file_exists($template_file)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local template file.
            $template = file_get_contents($template_file);
            if (!empty($template)) {
                return $template;
            }
        }

        // Fallback: minimal template
        return '<div style="width:794px;height:1123px;padding:60px;box-sizing:border-box;font-family:Arial,sans-serif">'
            . '<h1 style="text-align:center;color:#0073aa">' . esc_html__('Reregistration Record', 'ffcertificate') . '</h1>'
            . '<p><strong>' . esc_html__('Name:', 'ffcertificate') . '</strong> {{display_name}}</p>'
            . '<p><strong>' . esc_html__('Email:', 'ffcertificate') . '</strong> {{email}}</p>'
            . '<p><strong>' . esc_html__('Campaign:', 'ffcertificate') . '</strong> {{reregistration_title}}</p>'
            . '<p><strong>' . esc_html__('Status:', 'ffcertificate') . '</strong> {{submission_status}}</p>'
            . '{{custom_fields_section}}'
            . '</div>';
    }
}
