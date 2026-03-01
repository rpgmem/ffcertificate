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
     * @return array<string, mixed>|null Null on failure.
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
        $custom_fields = self::get_custom_fields_for_reregistration($rereg);

        // Status labels (centralized in SubmissionRepository)
        $status_labels = ReregistrationSubmissionRepository::get_status_labels();

        // Date formatting
        $date_format = get_option('date_format');
        $time_format = get_option('time_format');

        $submitted_at = '';
        if (!empty($submission->submitted_at)) {
            $submitted_at = date_i18n($date_format . ' ' . $time_format, strtotime($submission->submitted_at));
        }

        // Check if user has acúmulo de cargos
        $acumulo_value = $standard['acumulo_cargos'] ?? __('I do not hold', 'ffcertificate');
        $has_acumulo   = $acumulo_value === __('I hold', 'ffcertificate');

        // Build template variables
        $variables = array(
            'reregistration_title' => $rereg->title,
            'audience_name'        => $rereg->audience_name ?? '',
            'submission_status'    => $status_labels[$submission->status] ?? $submission->status,
            'submitted_at'         => $submitted_at,
            'display_name'         => $standard['display_name'] ?? $user->display_name,
            'email'                => $user->user_email,
            'sexo'                 => $standard['sexo'] ?? '',
            'estado_civil'         => $standard['estado_civil'] ?? '',
            'rf'                   => $standard['rf'] ?? '',
            'vinculo'              => $standard['vinculo'] ?? '',
            'data_nascimento'      => $standard['data_nascimento'] ?? '',
            'cpf'                  => $standard['cpf'] ?? '',
            'rg'                   => $standard['rg'] ?? '',
            'unidade_lotacao'      => $standard['unidade_lotacao'] ?? '',
            'unidade_exercicio'    => $standard['unidade_exercicio'] ?? '',
            'divisao'              => $standard['divisao'] ?? '',
            'setor'                => $standard['setor'] ?? '',
            'endereco'             => $standard['endereco'] ?? '',
            'endereco_numero'      => $standard['endereco_numero'] ?? '',
            'endereco_complemento' => $standard['endereco_complemento'] ?? '',
            'bairro'               => $standard['bairro'] ?? '',
            'cidade'               => $standard['cidade'] ?? '',
            'uf'                   => $standard['uf'] ?? '',
            'cep'                  => $standard['cep'] ?? '',
            'phone'                => $standard['phone'] ?? '',
            'celular'              => $standard['celular'] ?? '',
            'contato_emergencia'   => $standard['contato_emergencia'] ?? '',
            'tel_emergencia'       => $standard['tel_emergencia'] ?? '',
            'email_institucional'  => $standard['email_institucional'] ?? $user->user_email,
            'email_particular'     => $standard['email_particular'] ?? '',
            'jornada'              => $standard['jornada'] ?? '',
            'horario_trabalho'     => self::format_working_hours($standard['horario_trabalho'] ?? ''),
            'sindicato'            => $standard['sindicato'] ?? '',
            'acumulo_cargos'       => $standard['acumulo_cargos'] ?? __('I do not hold', 'ffcertificate'),
            'jornada_acumulo'      => $has_acumulo ? ($standard['jornada_acumulo'] ?? '') : '',
            'cargo_funcao_acumulo' => $has_acumulo ? ($standard['cargo_funcao_acumulo'] ?? '') : '',
            'horario_trabalho_acumulo' => $has_acumulo ? self::format_working_hours($standard['horario_trabalho_acumulo'] ?? '') : '',
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
            $safe_title = __('Record', 'ffcertificate');
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
            'html'        => $html,
            'filename'    => $filename,
            'orientation' => 'portrait',
            'user'        => array(
                'id'    => (int) $submission->user_id,
                'name'  => $variables['display_name'],
                'email' => $variables['email'],
            ),
            'type'        => 'ficha',
        );
    }

    /**
     * Format working hours JSON into a readable HTML table for ficha.
     *
     * @param string $json_or_empty Working hours JSON or empty string.
     * @return string Formatted HTML table or empty.
     */
    private static function format_working_hours(string $json_or_empty): string {
        if (empty($json_or_empty) || $json_or_empty === '[]') {
            return '';
        }
        $wh = json_decode($json_or_empty, true);
        if (!is_array($wh) || empty($wh)) {
            return '';
        }
        $days_map = array(
            0 => __('Sun', 'ffcertificate'), 1 => __('Mon', 'ffcertificate'), 2 => __('Tue', 'ffcertificate'),
            3 => __('Wed', 'ffcertificate'), 4 => __('Thu', 'ffcertificate'), 5 => __('Fri', 'ffcertificate'), 6 => __('Sat', 'ffcertificate'),
        );

        $cell  = 'style="padding:1px 4px;border:1px solid #ccc;text-align:center;font-size:7.5pt"';
        $hcell = 'style="padding:1px 4px;border:1px solid #ccc;text-align:center;font-size:7.5pt;font-weight:bold;background:#f0f4f8;color:#000"';

        $html  = '<table style="width:100%;border-collapse:collapse;margin-top:2px" role="presentation">';
        $html .= '<tr>';
        $html .= '<th ' . $hcell . '>' . esc_html__('Day', 'ffcertificate') . '</th>';
        $html .= '<th ' . $hcell . '>' . esc_html__('Entry', 'ffcertificate') . '</th>';
        $html .= '<th ' . $hcell . '>' . esc_html__('Lunch Out', 'ffcertificate') . '</th>';
        $html .= '<th ' . $hcell . '>' . esc_html__('Lunch In', 'ffcertificate') . '</th>';
        $html .= '<th ' . $hcell . '>' . esc_html__('Exit', 'ffcertificate') . '</th>';
        $html .= '</tr>';

        foreach ($wh as $entry) {
            $day = $days_map[$entry['day'] ?? 0] ?? '';
            $e1  = $entry['entry1'] ?? '';
            $x1  = $entry['exit1'] ?? '';
            $e2  = $entry['entry2'] ?? '';
            $x2  = $entry['exit2'] ?? '';
            if (empty($e1) && empty($x2)) {
                continue;
            }
            $html .= '<tr>';
            $html .= '<td ' . $cell . '>' . esc_html($day) . '</td>';
            $html .= '<td ' . $cell . '>' . esc_html($e1) . '</td>';
            $html .= '<td ' . $cell . '>' . esc_html($x1) . '</td>';
            $html .= '<td ' . $cell . '>' . esc_html($e2) . '</td>';
            $html .= '<td ' . $cell . '>' . esc_html($x2) . '</td>';
            $html .= '</tr>';
        }

        $html .= '</table>';
        return $html;
    }

    /**
     * Build custom fields section HTML for the template.
     *
     * @param array<int, mixed> $custom_fields  Custom field definitions.
     * @param array<string, mixed> $custom_values  Custom field values keyed by field_X.
     * @return string HTML section.
     */
    private static function build_custom_fields_section(array $custom_fields, array $custom_values): string {
        if (empty($custom_fields)) {
            return '';
        }

        $html = '<div style="margin-bottom: 6px">';
        $html .= '<div style="font-size: 9pt;font-weight: bold;color: #000;text-transform: uppercase;letter-spacing: 1px;padding-bottom: 2px;border-bottom: 1px solid #e8e8e8;margin-bottom: 3px">';
        $html .= esc_html__('Additional Information', 'ffcertificate');
        $html .= '</div>';
        $html .= '<table style="width: 100%;border-collapse: collapse;font-size: 8pt" role="presentation">';

        foreach ($custom_fields as $cf) {
            $key = 'field_' . $cf->id;
            $value = $custom_values[$key] ?? '';

            // Format checkbox values
            if ($cf->field_type === 'checkbox') {
                $value = $value === '1' ? __('Yes', 'ffcertificate') : __('No', 'ffcertificate');
            }

            // Format dependent_select values
            if ($cf->field_type === 'dependent_select') {
                $dep = is_string($value) ? json_decode($value, true) : $value;
                if (is_array($dep)) {
                    $value = trim(($dep['parent'] ?? '') . ' - ' . ($dep['child'] ?? ''), ' -');
                }
            }

            // Format working_hours values
            if ($cf->field_type === 'working_hours') {
                $wh = is_string($value) ? json_decode($value, true) : $value;
                if (is_array($wh) && !empty($wh)) {
                    $days_map = array(
                        0 => __('Sun', 'ffcertificate'), 1 => __('Mon', 'ffcertificate'),
                        2 => __('Tue', 'ffcertificate'), 3 => __('Wed', 'ffcertificate'),
                        4 => __('Thu', 'ffcertificate'), 5 => __('Fri', 'ffcertificate'),
                        6 => __('Sat', 'ffcertificate'),
                    );
                    $lines = array();
                    foreach ($wh as $entry) {
                        $day = $days_map[$entry['day'] ?? 0] ?? '';
                        $times = ($entry['entry1'] ?? '') . '-' . ($entry['exit1'] ?? '') . ' / ' . ($entry['entry2'] ?? '') . '-' . ($entry['exit2'] ?? '');
                        $lines[] = $day . ': ' . $times;
                    }
                    $value = implode('; ', $lines);
                }
            }

            $html .= '<tr>';
            $html .= '<td style="padding: 2px 0;font-weight: bold;color: #666;width: 120px;vertical-align: top">' . esc_html($cf->field_label) . ':</td>';
            $html .= '<td style="padding: 2px 0;color: #222">' . esc_html($value) . '</td>';
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

    /**
     * Get custom fields for all audiences linked to a reregistration.
     *
     * @param object $rereg Reregistration object.
     * @return array<object>
     */
    private static function get_custom_fields_for_reregistration(object $rereg): array {
        $audience_ids = ReregistrationRepository::get_audience_ids((int) $rereg->id);
        $all_fields = array();
        $seen = array();

        foreach ($audience_ids as $aud_id) {
            $fields = CustomFieldRepository::get_by_audience_with_parents((int) $aud_id, true);
            foreach ($fields as $field) {
                if (!isset($seen[(int) $field->id])) {
                    $seen[(int) $field->id] = true;
                    $all_fields[] = $field;
                }
            }
        }

        return $all_fields;
    }
}
