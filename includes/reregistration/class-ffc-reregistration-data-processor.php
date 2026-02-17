<?php
declare(strict_types=1);

/**
 * Reregistration Data Processor
 *
 * Handles the data pipeline for reregistration form submissions:
 * - Collecting and sanitizing form data from POST
 * - Validating standard and custom fields
 * - Processing validated submissions (save, update profile, email, log)
 *
 * @since 4.12.8  Extracted from ReregistrationFrontend
 * @package FreeFormCertificate\Reregistration
 */

namespace FreeFormCertificate\Reregistration;

use FreeFormCertificate\UserDashboard\UserManager;

if (!defined('ABSPATH')) {
    exit;
}

class ReregistrationDataProcessor {

    /**
     * Sanitize a working hours JSON string.
     *
     * @param string $raw Raw JSON input.
     * @return string Sanitized JSON.
     */
    public static function sanitize_working_hours(string $raw): string {
        $wh = json_decode($raw, true);
        if (!is_array($wh)) {
            return '[]';
        }
        $sanitized = array();
        foreach ($wh as $entry) {
            if (is_array($entry) && isset($entry['day'])) {
                $sanitized[] = array(
                    'day'    => absint($entry['day']),
                    'entry1' => sanitize_text_field($entry['entry1'] ?? ''),
                    'exit1'  => sanitize_text_field($entry['exit1'] ?? ''),
                    'entry2' => sanitize_text_field($entry['entry2'] ?? ''),
                    'exit2'  => sanitize_text_field($entry['exit2'] ?? ''),
                );
            }
        }
        return wp_json_encode($sanitized);
    }

    /**
     * Collect form data from POST.
     *
     * @param object $rereg   Reregistration object.
     * @param int    $user_id User ID.
     * @return array<string, mixed> Structured data.
     */
    public static function collect_form_data(object $rereg, int $user_id): array {
        $standard = array();
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- Nonce verified in AJAX handler; sanitized per-field below.
        $raw_standard = isset($_POST['standard_fields']) ? (array) wp_unslash($_POST['standard_fields']) : array();

        $allowed_standard = array(
            'display_name', 'sexo', 'estado_civil', 'rf', 'vinculo',
            'data_nascimento', 'cpf', 'rg',
            'unidade_lotacao', 'unidade_exercicio', 'divisao', 'setor',
            'endereco', 'endereco_numero', 'endereco_complemento',
            'bairro', 'cidade', 'uf', 'cep',
            'phone', 'celular', 'contato_emergencia', 'tel_emergencia',
            'email_institucional', 'email_particular',
            'jornada', 'horario_trabalho',
            'sindicato', 'acumulo_cargos', 'jornada_acumulo', 'cargo_funcao_acumulo',
            'horario_trabalho_acumulo',
            'department', 'organization',
        );
        // Working hours fields need JSON sanitization
        $wh_fields = array('horario_trabalho', 'horario_trabalho_acumulo');
        foreach ($allowed_standard as $key) {
            if (in_array($key, $wh_fields, true)) {
                $standard[$key] = self::sanitize_working_hours($raw_standard[$key] ?? '');
            } else {
                $standard[$key] = isset($raw_standard[$key]) ? sanitize_text_field($raw_standard[$key]) : '';
            }
        }

        $custom = array();
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- Nonce verified in AJAX handler; sanitized per-field below.
        $raw_custom = isset($_POST['custom_fields']) ? (array) wp_unslash($_POST['custom_fields']) : array();

        $fields = CustomFieldRepository::get_by_audience_with_parents((int) $rereg->audience_id, true);
        foreach ($fields as $cf) {
            $key = 'field_' . $cf->id;
            if (isset($raw_custom[$key])) {
                if ($cf->field_type === 'working_hours') {
                    // Sanitize JSON: decode, validate structure, re-encode
                    $wh = json_decode($raw_custom[$key], true);
                    if (is_array($wh)) {
                        $sanitized = array();
                        foreach ($wh as $entry) {
                            if (is_array($entry) && isset($entry['day'], $entry['entry1'], $entry['exit2'])) {
                                $sanitized[] = array(
                                    'day'    => absint($entry['day']),
                                    'entry1' => sanitize_text_field($entry['entry1']),
                                    'exit1'  => sanitize_text_field($entry['exit1'] ?? ''),
                                    'entry2' => sanitize_text_field($entry['entry2'] ?? ''),
                                    'exit2'  => sanitize_text_field($entry['exit2']),
                                );
                            }
                        }
                        $custom[$key] = wp_json_encode($sanitized);
                    } else {
                        $custom[$key] = '[]';
                    }
                } elseif ($cf->field_type === 'dependent_select') {
                    // Sanitize JSON: decode, validate structure, re-encode
                    $dep = json_decode($raw_custom[$key], true);
                    if (is_array($dep) && isset($dep['parent'], $dep['child'])) {
                        $custom[$key] = wp_json_encode(array(
                            'parent' => sanitize_text_field($dep['parent']),
                            'child'  => sanitize_text_field($dep['child']),
                        ));
                    } else {
                        $custom[$key] = wp_json_encode(array('parent' => '', 'child' => ''));
                    }
                } elseif ($cf->field_type === 'textarea') {
                    $custom[$key] = sanitize_textarea_field($raw_custom[$key]);
                } elseif ($cf->field_type === 'number') {
                    $custom[$key] = is_numeric($raw_custom[$key]) ? $raw_custom[$key] : '';
                } else {
                    $custom[$key] = sanitize_text_field($raw_custom[$key]);
                }
            } else {
                $custom[$key] = '';
            }
        }

        return array(
            'standard_fields' => $standard,
            'custom_fields'   => $custom,
        );
    }

    /**
     * Validate submission data.
     *
     * @param array<string, mixed>  $data    Collected data.
     * @param object $rereg   Reregistration.
     * @param int    $user_id User ID.
     * @return array<string, string> Errors keyed by field name.
     */
    public static function validate_submission(array $data, object $rereg, int $user_id): array {
        $errors = array();

        $s = $data['standard_fields'];

        // Required standard fields
        if (empty($s['display_name'])) {
            $errors['standard_fields[display_name]'] = __('Name is required.', 'ffcertificate');
        }
        if (empty($s['sexo'])) {
            $errors['standard_fields[sexo]'] = __('Sex is required.', 'ffcertificate');
        }
        if (empty($s['estado_civil'])) {
            $errors['standard_fields[estado_civil]'] = __('Marital status is required.', 'ffcertificate');
        }
        if (empty($s['data_nascimento'])) {
            $errors['standard_fields[data_nascimento]'] = __('Date of birth is required.', 'ffcertificate');
        }
        if (empty($s['divisao'])) {
            $errors['standard_fields[divisao]'] = __('Division is required.', 'ffcertificate');
        }
        if (empty($s['setor'])) {
            $errors['standard_fields[setor]'] = __('Department is required.', 'ffcertificate');
        }
        if (empty($s['jornada'])) {
            $errors['standard_fields[jornada]'] = __('Work schedule is required.', 'ffcertificate');
        }
        if (empty($s['celular'])) {
            $errors['standard_fields[celular]'] = __('Cell phone is required.', 'ffcertificate');
        }
        if (empty($s['contato_emergencia'])) {
            $errors['standard_fields[contato_emergencia]'] = __('Emergency contact is required.', 'ffcertificate');
        }
        if (empty($s['tel_emergencia'])) {
            $errors['standard_fields[tel_emergencia]'] = __('Emergency phone is required.', 'ffcertificate');
        }

        // CPF validation (required)
        if (empty($s['cpf'])) {
            $errors['standard_fields[cpf]'] = __('CPF is required.', 'ffcertificate');
        } elseif (!\FreeFormCertificate\Core\Utils::validate_cpf($s['cpf'])) {
            $errors['standard_fields[cpf]'] = __('Invalid CPF.', 'ffcertificate');
        }

        // Phone format validation (if provided)
        $phone = $s['phone'] ?? '';
        if (!empty($phone) && !\FreeFormCertificate\Core\Utils::validate_phone($phone)) {
            $errors['standard_fields[phone]'] = __('Invalid home phone.', 'ffcertificate');
        }

        // Celular format validation
        $celular = $s['celular'] ?? '';
        if (!empty($celular) && !\FreeFormCertificate\Core\Utils::validate_phone($celular)) {
            $errors['standard_fields[celular]'] = __('Invalid cell phone.', 'ffcertificate');
        }

        // Emergency phone validation
        $tel_emerg = $s['tel_emergencia'] ?? '';
        if (!empty($tel_emerg) && !\FreeFormCertificate\Core\Utils::validate_phone($tel_emerg)) {
            $errors['standard_fields[tel_emergencia]'] = __('Invalid emergency phone.', 'ffcertificate');
        }

        // Division/Department consistency validation
        if (!empty($s['divisao']) && !empty($s['setor'])) {
            $map = ReregistrationFieldOptions::get_divisao_setor_map();
            if (isset($map[$s['divisao']]) && !in_array($s['setor'], $map[$s['divisao']], true)) {
                $errors['standard_fields[setor]'] = __('Invalid department for the selected division.', 'ffcertificate');
            }
        }

        // Custom fields validation
        $fields = CustomFieldRepository::get_by_audience_with_parents((int) $rereg->audience_id, true);
        foreach ($fields as $cf) {
            $key = 'field_' . $cf->id;
            $value = $data['custom_fields'][$key] ?? '';
            $name = 'custom_fields[' . $key . ']';

            // Required check
            if (!empty($cf->is_required) && ($value === '' || $value === null)) {
                /* translators: %s: field label */
                $errors[$name] = sprintf(__('%s is required.', 'ffcertificate'), $cf->field_label);
                continue;
            }

            if ($value === '' || $value === null) {
                continue;
            }

            // Format validation
            $rules = $cf->validation_rules ? json_decode($cf->validation_rules, true) : array();
            $format = $rules['format'] ?? '';

            if ($format === 'cpf') {
                if (!\FreeFormCertificate\Core\Utils::validate_cpf($value)) {
                    /* translators: %s: field label */
                    $errors[$name] = sprintf(__('%s is not a valid CPF.', 'ffcertificate'), $cf->field_label);
                }
            } elseif ($format === 'email') {
                if (!is_email($value)) {
                    /* translators: %s: field label */
                    $errors[$name] = sprintf(__('%s is not a valid email.', 'ffcertificate'), $cf->field_label);
                }
            } elseif ($format === 'phone') {
                if (!\FreeFormCertificate\Core\Utils::validate_phone($value)) {
                    /* translators: %s: field label */
                    $errors[$name] = sprintf(__('%s is not a valid phone number.', 'ffcertificate'), $cf->field_label);
                }
            } elseif ($format === 'custom_regex' && !empty($rules['custom_regex'])) {
                $regex = $rules['custom_regex'];
                // Ensure regex has delimiters — use ~ to avoid conflicts with / in patterns
                if ($regex[0] !== '/' && $regex[0] !== '~' && $regex[0] !== '#') {
                    $regex = '~' . $regex . '~';
                }
                // Validate pattern before using it
                if (@preg_match($regex, '') === false) {
                    continue; // Invalid regex — skip validation
                }
                if (!preg_match($regex, $value)) {
                    /* translators: %s: field label */
                    $msg = !empty($rules['custom_regex_message']) ? $rules['custom_regex_message'] : sprintf(__('%s has an invalid format.', 'ffcertificate'), $cf->field_label);
                    $errors[$name] = $msg;
                }
            }
        }

        return $errors;
    }

    /**
     * Process a validated submission.
     *
     * @param object $submission Submission record.
     * @param object $rereg      Reregistration.
     * @param array<string, mixed>  $data       Validated data.
     * @param int    $user_id    User ID.
     * @return void
     */
    public static function process_submission(object $submission, object $rereg, array $data, int $user_id): void {
        // Determine final status
        $new_status = !empty($rereg->auto_approve) ? 'approved' : 'submitted';

        // Generate globally unique auth code and magic token
        $auth_code   = \FreeFormCertificate\Core\Utils::generate_globally_unique_auth_code();
        $magic_token = bin2hex(random_bytes(32));

        // Build update data (single query)
        $update_data = array(
            'data'         => $data,
            'status'       => $new_status,
            'submitted_at' => current_time('mysql'),
            'auth_code'    => $auth_code,
            'magic_token'  => $magic_token,
        );

        // If auto-approved, include reviewed fields in the same update
        if ($new_status === 'approved') {
            $update_data['reviewed_at'] = current_time('mysql');
            $update_data['reviewed_by'] = 0;
            $update_data['notes']       = __('Auto-approved', 'ffcertificate');
        }

        ReregistrationSubmissionRepository::update((int) $submission->id, $update_data);

        // Update user profile with standard fields
        $standard = $data['standard_fields'];
        if (class_exists('\FreeFormCertificate\UserDashboard\UserManager')) {
            UserManager::update_profile($user_id, array(
                'display_name'  => $standard['display_name'],
                'phone'         => $standard['phone'],
                'celular'       => $standard['celular'] ?? '',
                'department'    => $standard['department'],
                'organization'  => $standard['organization'],
                'cpf'           => $standard['cpf'] ?? '',
                'rg'            => $standard['rg'] ?? '',
                'divisao'       => $standard['divisao'] ?? '',
                'setor'         => $standard['setor'] ?? '',
                'jornada'       => $standard['jornada'] ?? '',
            ));
        }

        // Update custom fields user meta
        $custom = $data['custom_fields'];
        if (!empty($custom)) {
            $existing = CustomFieldRepository::get_user_data($user_id);
            $merged = array_merge($existing, $custom);
            CustomFieldRepository::save_user_data($user_id, $merged);
        }

        // Send confirmation email
        ReregistrationEmailHandler::send_confirmation((int) $submission->id);

        // Activity log
        if (class_exists('\FreeFormCertificate\Core\ActivityLog')) {
            \FreeFormCertificate\Core\ActivityLog::log(
                'reregistration_submitted',
                \FreeFormCertificate\Core\ActivityLog::LEVEL_INFO,
                array(
                    'reregistration_id' => $rereg->id,
                    'submission_id'     => $submission->id,
                    'status'            => $new_status,
                ),
                $user_id,
                (int) $submission->id
            );
        }
    }
}
