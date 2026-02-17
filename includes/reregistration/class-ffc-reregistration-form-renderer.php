<?php
declare(strict_types=1);

/**
 * Reregistration Form Renderer
 *
 * Renders the user-facing reregistration form HTML:
 * - 6 standard fieldsets (personal data, contacts, schedule, accumulation, union, acknowledgment)
 * - Dynamic custom fields (text, textarea, select, dependent_select, checkbox, number, date, working_hours)
 * - Working hours interactive table component
 *
 * @since 4.12.8  Extracted from ReregistrationFrontend
 * @package FreeFormCertificate\Reregistration
 */

namespace FreeFormCertificate\Reregistration;

use FreeFormCertificate\UserDashboard\UserManager;

if (!defined('ABSPATH')) {
    exit;
}

class ReregistrationFormRenderer {

    /**
     * Render the reregistration form HTML.
     *
     * @param object $rereg      Reregistration object.
     * @param object $submission Submission object.
     * @param int    $user_id    User ID.
     * @return string HTML.
     */
    public static function render(object $rereg, object $submission, int $user_id): string {
        $user = get_userdata($user_id);
        $profile = array();
        if (class_exists('\FreeFormCertificate\UserDashboard\UserManager')) {
            $profile = UserManager::get_profile($user_id);
        }

        // Get custom fields for this audience (including parent hierarchy)
        $custom_fields = CustomFieldRepository::get_by_audience_with_parents((int) $rereg->audience_id, true);

        // Pre-populate from saved draft data, then from profile/user_meta
        $saved_data = $submission->data ? json_decode($submission->data, true) : array();
        $standard = $saved_data['standard_fields'] ?? array();
        $custom_values = $saved_data['custom_fields'] ?? array();

        // Fallback to profile data if no draft
        if (empty($standard)) {
            $standard = self::build_standard_defaults($profile, $user);
        }

        // Fallback to user_meta custom fields if no draft
        if (empty($custom_values)) {
            $user_custom = CustomFieldRepository::get_user_data($user_id);
            foreach ($custom_fields as $cf) {
                $key = 'field_' . $cf->id;
                if (isset($user_custom[$key])) {
                    $custom_values[$key] = $user_custom[$key];
                }
            }
        }

        $end_date = wp_date(get_option('date_format'), strtotime($rereg->end_date));

        // Data for JS
        $divisao_setor_map = ReregistrationFieldOptions::get_divisao_setor_map();

        // Prepare working hours data
        $wh_main_raw = $standard['horario_trabalho'] ?? '';
        $wh_main = is_string($wh_main_raw) && !empty($wh_main_raw) ? json_decode($wh_main_raw, true) : null;
        if (!is_array($wh_main) || empty($wh_main)) {
            $wh_main = ReregistrationFieldOptions::get_default_working_hours();
        }

        $wh_acumulo_raw = $standard['horario_trabalho_acumulo'] ?? '';
        $wh_acumulo = is_string($wh_acumulo_raw) && !empty($wh_acumulo_raw) ? json_decode($wh_acumulo_raw, true) : null;
        if (!is_array($wh_acumulo) || empty($wh_acumulo)) {
            $wh_acumulo = ReregistrationFieldOptions::get_default_working_hours();
        }

        ob_start();
        ?>
        <div class="ffc-rereg-form-container" data-reregistration-id="<?php echo esc_attr($rereg->id); ?>">
            <div class="ffc-rereg-header-bar">
                <div class="ffc-rereg-header-title"><?php echo esc_html__('CITY HALL OF SÃO PAULO / DEPARTMENT OF EDUCATION – SME', 'ffcertificate'); ?></div>
                <div class="ffc-rereg-header-subtitle"><?php echo esc_html__('REGIONAL EDUCATION BOARD SÃO MIGUEL – MP', 'ffcertificate'); ?></div>
            </div>

            <h3><?php echo esc_html($rereg->title); ?></h3>
            <p class="ffc-rereg-deadline">
                <?php
                /* translators: %s: end date */
                echo esc_html(sprintf(__('Deadline: %s', 'ffcertificate'), $end_date));
                ?>
            </p>

            <form id="ffc-rereg-form" novalidate>
                <input type="hidden" name="reregistration_id" value="<?php echo esc_attr($rereg->id); ?>">
                <script type="application/json" id="ffc-divisao-setor-map"><?php echo wp_json_encode($divisao_setor_map); ?></script>

                <?php
                self::render_personal_data_fieldset($standard, $divisao_setor_map);
                self::render_contacts_fieldset($standard, $user);
                self::render_schedule_fieldset($standard, $wh_main);
                self::render_accumulation_fieldset($standard, $wh_acumulo);
                self::render_union_fieldset($standard);
                self::render_acknowledgment_fieldset();
                self::render_custom_fields_fieldset($custom_fields, $custom_values);
                ?>

                <div class="ffc-rereg-actions">
                    <button type="button" class="button ffc-rereg-draft-btn"><?php esc_html_e('Save Draft', 'ffcertificate'); ?></button>
                    <button type="submit" class="button button-primary ffc-rereg-submit-btn"><?php esc_html_e('Submit', 'ffcertificate'); ?></button>
                    <button type="button" class="button ffc-rereg-cancel-btn"><?php esc_html_e('Cancel', 'ffcertificate'); ?></button>
                    <span class="ffc-rereg-status"></span>
                </div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Build default standard field values from profile/user data.
     *
     * @param array<string, mixed> $profile User profile data.
     * @param \WP_User             $user    WordPress user.
     * @return array<string, string>
     */
    private static function build_standard_defaults(array $profile, \WP_User $user): array {
        return array(
            'display_name'        => $profile['display_name'] ?? $user->display_name,
            'sexo'                => $profile['sexo'] ?? '',
            'estado_civil'        => $profile['estado_civil'] ?? '',
            'rf'                  => $profile['rf'] ?? '',
            'vinculo'             => $profile['vinculo'] ?? '',
            'data_nascimento'     => $profile['data_nascimento'] ?? '',
            'cpf'                 => $profile['cpf'] ?? '',
            'rg'                  => $profile['rg'] ?? '',
            'unidade_lotacao'     => $profile['unidade_lotacao'] ?? '',
            'unidade_exercicio'   => $profile['unidade_exercicio'] ?? 'DRE MP',
            'divisao'             => $profile['divisao'] ?? '',
            'setor'               => $profile['setor'] ?? '',
            'endereco'            => $profile['endereco'] ?? '',
            'endereco_numero'     => $profile['endereco_numero'] ?? '',
            'endereco_complemento' => $profile['endereco_complemento'] ?? '',
            'bairro'              => $profile['bairro'] ?? '',
            'cidade'              => $profile['cidade'] ?? 'SÃO PAULO',
            'uf'                  => $profile['uf'] ?? 'SP',
            'cep'                 => $profile['cep'] ?? '',
            'phone'               => $profile['phone'] ?? '',
            'celular'             => $profile['celular'] ?? '',
            'contato_emergencia'  => $profile['contato_emergencia'] ?? '',
            'tel_emergencia'      => $profile['tel_emergencia'] ?? '',
            'email_institucional' => $profile['email_institucional'] ?? $user->user_email,
            'email_particular'    => $profile['email_particular'] ?? '',
            'jornada'             => $profile['jornada'] ?? '',
            'horario_trabalho'    => $profile['horario_trabalho'] ?? '',
            'sindicato'           => $profile['sindicato'] ?? '',
            'acumulo_cargos'      => $profile['acumulo_cargos'] ?? __('I do not hold', 'ffcertificate'),
            'jornada_acumulo'     => $profile['jornada_acumulo'] ?? '',
            'cargo_funcao_acumulo' => $profile['cargo_funcao_acumulo'] ?? '',
            'horario_trabalho_acumulo' => $profile['horario_trabalho_acumulo'] ?? '',
            'department'          => $profile['department'] ?? '',
            'organization'        => $profile['organization'] ?? '',
        );
    }

    /**
     * Render fieldset 1: Personal Data.
     *
     * @param array<string, string>          $standard          Default form field values.
     * @param array<string, array<string, string>> $divisao_setor_map Map of divisions to their sectors.
     */
    private static function render_personal_data_fieldset(array $standard, array $divisao_setor_map): void {
        ?>
        <!-- 1. DADOS PESSOAIS -->
        <fieldset class="ffc-rereg-fieldset">
            <legend><?php echo esc_html__('1. Personal Data', 'ffcertificate'); ?></legend>

            <div class="ffc-rereg-field">
                <label for="ffc_rereg_name"><?php esc_html_e('Name', 'ffcertificate'); ?> <span class="required">*</span></label>
                <input type="text" id="ffc_rereg_name" name="standard_fields[display_name]"
                       value="<?php echo esc_attr($standard['display_name'] ?? ''); ?>" required>
            </div>

            <div class="ffc-rereg-row ffc-rereg-row-3">
                <div class="ffc-rereg-field">
                    <label for="ffc_rereg_sexo"><?php esc_html_e('Sex', 'ffcertificate'); ?> <span class="required">*</span></label>
                    <select id="ffc_rereg_sexo" name="standard_fields[sexo]" required>
                        <option value=""><?php esc_html_e('Select', 'ffcertificate'); ?></option>
                        <?php foreach (ReregistrationFieldOptions::get_sexo_options() as $opt) : ?>
                            <option value="<?php echo esc_attr($opt); ?>" <?php selected($standard['sexo'] ?? '', $opt); ?>><?php echo esc_html($opt); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="ffc-rereg-field">
                    <label for="ffc_rereg_estado_civil"><?php esc_html_e('Marital Status', 'ffcertificate'); ?> <span class="required">*</span></label>
                    <select id="ffc_rereg_estado_civil" name="standard_fields[estado_civil]" required>
                        <option value=""><?php esc_html_e('Select', 'ffcertificate'); ?></option>
                        <?php foreach (ReregistrationFieldOptions::get_estado_civil_options() as $opt) : ?>
                            <option value="<?php echo esc_attr($opt); ?>" <?php selected($standard['estado_civil'] ?? '', $opt); ?>><?php echo esc_html($opt); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="ffc-rereg-field">
                    <label for="ffc_rereg_rf"><?php esc_html_e('RF', 'ffcertificate'); ?></label>
                    <input type="text" id="ffc_rereg_rf" name="standard_fields[rf]"
                           value="<?php echo esc_attr($standard['rf'] ?? ''); ?>" data-mask="rf">
                </div>
            </div>

            <div class="ffc-rereg-row ffc-rereg-row-2">
                <div class="ffc-rereg-field">
                    <label for="ffc_rereg_vinculo"><?php esc_html_e('Employment Bond', 'ffcertificate'); ?></label>
                    <input type="text" id="ffc_rereg_vinculo" name="standard_fields[vinculo]"
                           value="<?php echo esc_attr($standard['vinculo'] ?? ''); ?>" maxlength="2" data-mask="number">
                </div>
                <div class="ffc-rereg-field">
                    <label for="ffc_rereg_data_nascimento"><?php esc_html_e('Date of Birth', 'ffcertificate'); ?> <span class="required">*</span></label>
                    <input type="date" id="ffc_rereg_data_nascimento" name="standard_fields[data_nascimento]"
                           value="<?php echo esc_attr($standard['data_nascimento'] ?? ''); ?>" required>
                </div>
            </div>

            <div class="ffc-rereg-row ffc-rereg-row-2">
                <div class="ffc-rereg-field">
                    <label for="ffc_rereg_cpf"><?php esc_html_e('CPF/CIN', 'ffcertificate'); ?> <span class="required">*</span></label>
                    <input type="text" id="ffc_rereg_cpf" name="standard_fields[cpf]"
                           value="<?php echo esc_attr($standard['cpf'] ?? ''); ?>" data-mask="cpf" required
                           data-format="cpf">
                </div>
                <div class="ffc-rereg-field">
                    <label for="ffc_rereg_rg"><?php esc_html_e('RG', 'ffcertificate'); ?></label>
                    <input type="text" id="ffc_rereg_rg" name="standard_fields[rg]"
                           value="<?php echo esc_attr($standard['rg'] ?? ''); ?>" data-mask="cin">
                </div>
            </div>

            <div class="ffc-rereg-field">
                <label for="ffc_rereg_unidade_lotacao"><?php esc_html_e('Assignment Unit', 'ffcertificate'); ?></label>
                <input type="text" id="ffc_rereg_unidade_lotacao" name="standard_fields[unidade_lotacao]"
                       value="<?php echo esc_attr($standard['unidade_lotacao'] ?? ''); ?>">
            </div>

            <div class="ffc-rereg-row ffc-rereg-row-3">
                <div class="ffc-rereg-field">
                    <label for="ffc_rereg_unidade_exercicio"><?php esc_html_e('Working Unit', 'ffcertificate'); ?></label>
                    <input type="text" id="ffc_rereg_unidade_exercicio" name="standard_fields[unidade_exercicio]"
                           value="<?php echo esc_attr($standard['unidade_exercicio'] ?? 'DRE MP'); ?>">
                </div>
                <div class="ffc-rereg-field">
                    <label for="ffc_rereg_divisao"><?php esc_html_e('Division', 'ffcertificate'); ?> <span class="required">*</span></label>
                    <select id="ffc_rereg_divisao" name="standard_fields[divisao]" required>
                        <option value=""><?php esc_html_e('Select', 'ffcertificate'); ?></option>
                        <?php foreach (array_keys($divisao_setor_map) as $div) : ?>
                            <option value="<?php echo esc_attr($div); ?>" <?php selected($standard['divisao'] ?? '', $div); ?>><?php echo esc_html($div); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="ffc-rereg-field">
                    <label for="ffc_rereg_setor"><?php esc_html_e('Department', 'ffcertificate'); ?> <span class="required">*</span></label>
                    <select id="ffc_rereg_setor" name="standard_fields[setor]" required>
                        <option value=""><?php esc_html_e('Select Division / Location', 'ffcertificate'); ?></option>
                        <?php
                        $selected_divisao = $standard['divisao'] ?? '';
                        if (!empty($selected_divisao) && isset($divisao_setor_map[$selected_divisao])) {
                            foreach ($divisao_setor_map[$selected_divisao] as $setor) {
                                printf(
                                    '<option value="%s" %s>%s</option>',
                                    esc_attr($setor),
                                    selected($standard['setor'] ?? '', $setor, false),
                                    esc_html($setor)
                                );
                            }
                        }
                        ?>
                    </select>
                </div>
            </div>

            <!-- Address -->
            <div class="ffc-rereg-row ffc-rereg-row-addr">
                <div class="ffc-rereg-field ffc-rereg-field-grow">
                    <label for="ffc_rereg_endereco"><?php esc_html_e('Address', 'ffcertificate'); ?></label>
                    <input type="text" id="ffc_rereg_endereco" name="standard_fields[endereco]"
                           value="<?php echo esc_attr($standard['endereco'] ?? ''); ?>">
                </div>
                <div class="ffc-rereg-field ffc-rereg-field-sm">
                    <label for="ffc_rereg_endereco_numero"><?php esc_html_e('No.', 'ffcertificate'); ?></label>
                    <input type="text" id="ffc_rereg_endereco_numero" name="standard_fields[endereco_numero]"
                           value="<?php echo esc_attr($standard['endereco_numero'] ?? ''); ?>">
                </div>
                <div class="ffc-rereg-field ffc-rereg-field-sm">
                    <label for="ffc_rereg_endereco_complemento"><?php esc_html_e('Apt/Suite', 'ffcertificate'); ?></label>
                    <input type="text" id="ffc_rereg_endereco_complemento" name="standard_fields[endereco_complemento]"
                           value="<?php echo esc_attr($standard['endereco_complemento'] ?? ''); ?>">
                </div>
            </div>

            <div class="ffc-rereg-row ffc-rereg-row-4">
                <div class="ffc-rereg-field">
                    <label for="ffc_rereg_bairro"><?php esc_html_e('Neighborhood', 'ffcertificate'); ?></label>
                    <input type="text" id="ffc_rereg_bairro" name="standard_fields[bairro]"
                           value="<?php echo esc_attr($standard['bairro'] ?? ''); ?>">
                </div>
                <div class="ffc-rereg-field">
                    <label for="ffc_rereg_cidade"><?php esc_html_e('City', 'ffcertificate'); ?></label>
                    <input type="text" id="ffc_rereg_cidade" name="standard_fields[cidade]"
                           value="<?php echo esc_attr($standard['cidade'] ?? 'SÃO PAULO'); ?>">
                </div>
                <div class="ffc-rereg-field ffc-rereg-field-sm">
                    <label for="ffc_rereg_uf"><?php esc_html_e('UF', 'ffcertificate'); ?></label>
                    <select id="ffc_rereg_uf" name="standard_fields[uf]">
                        <?php foreach (ReregistrationFieldOptions::get_uf_options() as $uf) : ?>
                            <option value="<?php echo esc_attr($uf); ?>" <?php selected($standard['uf'] ?? 'SP', $uf); ?>><?php echo esc_html($uf); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="ffc-rereg-field">
                    <label for="ffc_rereg_cep"><?php esc_html_e('Zip Code', 'ffcertificate'); ?></label>
                    <input type="text" id="ffc_rereg_cep" name="standard_fields[cep]"
                           value="<?php echo esc_attr($standard['cep'] ?? ''); ?>" data-mask="cep">
                </div>
            </div>
        </fieldset>
        <?php
    }

    /**
     * Render fieldset 2: Contact Information.
     *
     * @param array<string, string> $standard Default form field values.
     * @param \WP_User              $user     WordPress user.
     */
    private static function render_contacts_fieldset(array $standard, \WP_User $user): void {
        ?>
        <!-- 2. CONTATOS -->
        <fieldset class="ffc-rereg-fieldset">
            <legend><?php echo esc_html__('2. Contact Information', 'ffcertificate'); ?></legend>

            <div class="ffc-rereg-row ffc-rereg-row-2">
                <div class="ffc-rereg-field">
                    <label for="ffc_rereg_phone"><?php esc_html_e('Home Phone', 'ffcertificate'); ?></label>
                    <input type="tel" id="ffc_rereg_phone" name="standard_fields[phone]"
                           value="<?php echo esc_attr($standard['phone'] ?? ''); ?>" data-mask="phone">
                </div>
                <div class="ffc-rereg-field">
                    <label for="ffc_rereg_celular"><?php esc_html_e('Cell Phone', 'ffcertificate'); ?> <span class="required">*</span></label>
                    <input type="tel" id="ffc_rereg_celular" name="standard_fields[celular]"
                           value="<?php echo esc_attr($standard['celular'] ?? ''); ?>" data-mask="phone" required>
                </div>
            </div>

            <div class="ffc-rereg-row ffc-rereg-row-2">
                <div class="ffc-rereg-field">
                    <label for="ffc_rereg_contato_emergencia"><?php esc_html_e('Emergency Contact', 'ffcertificate'); ?> <span class="required">*</span></label>
                    <input type="text" id="ffc_rereg_contato_emergencia" name="standard_fields[contato_emergencia]"
                           value="<?php echo esc_attr($standard['contato_emergencia'] ?? ''); ?>" required>
                </div>
                <div class="ffc-rereg-field">
                    <label for="ffc_rereg_tel_emergencia"><?php esc_html_e('Emergency Phone', 'ffcertificate'); ?> <span class="required">*</span></label>
                    <input type="tel" id="ffc_rereg_tel_emergencia" name="standard_fields[tel_emergencia]"
                           value="<?php echo esc_attr($standard['tel_emergencia'] ?? ''); ?>" data-mask="phone" required>
                </div>
            </div>

            <div class="ffc-rereg-field">
                <label for="ffc_rereg_email_inst"><?php esc_html_e('Institutional Email', 'ffcertificate'); ?></label>
                <input type="email" id="ffc_rereg_email_inst" name="standard_fields[email_institucional]"
                       value="<?php echo esc_attr($standard['email_institucional'] ?? $user->user_email); ?>">
            </div>

            <div class="ffc-rereg-field">
                <label for="ffc_rereg_email_part"><?php esc_html_e('Personal Email', 'ffcertificate'); ?></label>
                <input type="email" id="ffc_rereg_email_part" name="standard_fields[email_particular]"
                       value="<?php echo esc_attr($standard['email_particular'] ?? ''); ?>">
            </div>
        </fieldset>
        <?php
    }

    /**
     * Render fieldset 3: Work Schedule / Working Hours.
     *
     * @param array<string, string> $standard Default form field values.
     * @param array<string, mixed>  $wh_main  Working hours data for the primary schedule.
     */
    private static function render_schedule_fieldset(array $standard, array $wh_main): void {
        ?>
        <!-- 3. JORNADA / HORÁRIO DE TRABALHO -->
        <fieldset class="ffc-rereg-fieldset">
            <legend><?php echo esc_html__('3. Work Schedule / Working Hours', 'ffcertificate'); ?></legend>

            <div class="ffc-rereg-field">
                <label for="ffc_rereg_jornada"><?php esc_html_e('Work Schedule', 'ffcertificate'); ?> <span class="required">*</span></label>
                <select id="ffc_rereg_jornada" name="standard_fields[jornada]" required>
                    <option value=""><?php esc_html_e('Select', 'ffcertificate'); ?></option>
                    <?php foreach (ReregistrationFieldOptions::get_jornada_options() as $j) : ?>
                        <option value="<?php echo esc_attr($j); ?>" <?php selected($standard['jornada'] ?? '', $j); ?>><?php echo esc_html($j); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="ffc-rereg-field">
                <label><?php esc_html_e('Working Hours', 'ffcertificate'); ?></label>
                <?php echo self::render_working_hours_field('standard_fields[horario_trabalho]', 'ffc_rereg_horario_trabalho', $wh_main); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </div>
        </fieldset>
        <?php
    }

    /**
     * Render fieldset 4: Position Accumulation.
     *
     * @param array<string, string> $standard   Default form field values.
     * @param array<string, mixed>  $wh_acumulo Working hours data for the accumulation schedule.
     */
    private static function render_accumulation_fieldset(array $standard, array $wh_acumulo): void {
        ?>
        <!-- 4. ACÚMULO DE CARGOS -->
        <fieldset class="ffc-rereg-fieldset">
            <legend><?php echo esc_html__('4. Position Accumulation', 'ffcertificate'); ?></legend>

            <div class="ffc-rereg-field">
                <label for="ffc_rereg_acumulo"><?php esc_html_e('Position Accumulation', 'ffcertificate'); ?></label>
                <select id="ffc_rereg_acumulo" name="standard_fields[acumulo_cargos]">
                    <?php foreach (ReregistrationFieldOptions::get_acumulo_options() as $opt) : ?>
                        <option value="<?php echo esc_attr($opt); ?>" <?php selected($standard['acumulo_cargos'] ?? __('I do not hold', 'ffcertificate'), $opt); ?>><?php echo esc_html($opt); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="ffc-rereg-acumulo-fields" style="<?php echo ($standard['acumulo_cargos'] ?? __('I do not hold', 'ffcertificate')) === __('I hold', 'ffcertificate') ? '' : 'display:none'; ?>">
                <div class="ffc-rereg-row ffc-rereg-row-2">
                    <div class="ffc-rereg-field">
                        <label for="ffc_rereg_jornada_acumulo"><?php esc_html_e('Accumulation Schedule', 'ffcertificate'); ?></label>
                        <select id="ffc_rereg_jornada_acumulo" name="standard_fields[jornada_acumulo]">
                            <option value=""><?php esc_html_e('Select', 'ffcertificate'); ?></option>
                            <?php foreach (ReregistrationFieldOptions::get_jornada_options() as $j) : ?>
                                <option value="<?php echo esc_attr($j); ?>" <?php selected($standard['jornada_acumulo'] ?? '', $j); ?>><?php echo esc_html($j); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="ffc-rereg-field">
                        <label for="ffc_rereg_cargo_funcao_acumulo"><?php esc_html_e('Current Position/Role', 'ffcertificate'); ?></label>
                        <input type="text" id="ffc_rereg_cargo_funcao_acumulo" name="standard_fields[cargo_funcao_acumulo]"
                               value="<?php echo esc_attr($standard['cargo_funcao_acumulo'] ?? ''); ?>">
                    </div>
                </div>

                <div class="ffc-rereg-field" style="margin-top: 16px;">
                    <label><?php esc_html_e('Accumulation Working Hours', 'ffcertificate'); ?></label>
                    <?php echo self::render_working_hours_field('standard_fields[horario_trabalho_acumulo]', 'ffc_rereg_horario_trabalho_acumulo', $wh_acumulo); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </div>
            </div>
        </fieldset>
        <?php
    }

    /**
     * Render fieldset 5: Union.
     *
     * @param array<string, string> $standard Default form field values.
     */
    private static function render_union_fieldset(array $standard): void {
        ?>
        <!-- 5. SINDICATO -->
        <fieldset class="ffc-rereg-fieldset">
            <legend><?php echo esc_html__('5. Union to which I am affiliated and wish to participate in events (only one)', 'ffcertificate'); ?></legend>

            <div class="ffc-rereg-field">
                <label for="ffc_rereg_sindicato"><?php esc_html_e('Union', 'ffcertificate'); ?></label>
                <select id="ffc_rereg_sindicato" name="standard_fields[sindicato]">
                    <option value=""><?php esc_html_e('Select', 'ffcertificate'); ?></option>
                    <?php foreach (ReregistrationFieldOptions::get_sindicato_options() as $opt) : ?>
                        <option value="<?php echo esc_attr($opt); ?>" <?php selected($standard['sindicato'] ?? '', $opt); ?>><?php echo esc_html($opt); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </fieldset>
        <?php
    }

    /**
     * Render fieldset 6: Acknowledgment.
     */
    private static function render_acknowledgment_fieldset(): void {
        ?>
        <!-- 6. TERMO DE CIÊNCIA -->
        <fieldset class="ffc-rereg-fieldset">
            <legend><?php echo esc_html__('6. Acknowledgment', 'ffcertificate'); ?></legend>

            <div class="ffc-rereg-termo-text">
                <p><?php echo esc_html__('I, working at the Regional Education Board of São Miguel – DRE-MP, declare that I am aware of the guidelines for the current year:', 'ffcertificate'); ?></p>
                <ol>
                    <li><strong><?php echo esc_html__('Family Declaration (WEB):', 'ffcertificate'); ?></strong> <?php echo esc_html__('The Family Declaration must be completed during the employee\'s birthday month, through the website:', 'ffcertificate'); ?> <a href="https://www.declaracaofamilia.iprem.prefeitura.sp.gov.br/Login" target="_blank" rel="noopener noreferrer">https://www.declaracaofamilia.iprem.prefeitura.sp.gov.br/Login</a>. <?php echo esc_html__('Afterward, it must be printed and delivered to the Personnel Records Department for filing;', 'ffcertificate'); ?></li>
                    <li><strong><?php echo esc_html__('Transportation Benefit Re-registration:', 'ffcertificate'); ?></strong> <?php echo esc_html__('The same guidelines apply to those entitled to the benefit. The re-registration must be completed during the birthday month, and the employee must complete the Transportation Benefit re-registration BEFORE the annual re-registration (proof of life);', 'ffcertificate'); ?></li>
                    <li><strong><?php echo esc_html__('Annual Re-registration (Proof of Life):', 'ffcertificate'); ?></strong> <?php echo esc_html__('The same guidelines apply. Note that an ID card issued more than 10 years ago will not be accepted, and the employee must obtain a new document before completing the re-registration;', 'ffcertificate'); ?></li>
                    <li><strong><?php echo esc_html__('Asset Declaration (SISPATRI):', 'ffcertificate'); ?></strong> <?php echo esc_html__('The same guidelines apply. It must be completed after the Federal Revenue deadline, from the 1st to the 30th of June, through the website:', 'ffcertificate'); ?> <a href="https://controladoriageralbens.prefeitura.sp.gov.br/PaginasPublicas/login.aspx" target="_blank" rel="noopener noreferrer">https://controladoriageralbens.prefeitura.sp.gov.br/PaginasPublicas/login.aspx</a>;</li>
                    <li><strong><?php echo esc_html__('13th Salary Advance:', 'ffcertificate'); ?></strong> <?php echo esc_html__('The request may be filled out and delivered to the HR Unit from the 1st business day of the year to which the advance refers, regardless of the employee\'s birthday month.', 'ffcertificate'); ?></li>
                    <li><strong><?php echo esc_html__('Submission of Medical/Dental Certificates with Leave Request from 1 (one) day:', 'ffcertificate'); ?></strong> <?php echo esc_html__('We reiterate that any leave request for health treatment (personal or family member) must be immediately reported to the supervisor, with presentation of the medical/dental certificate. Then, the documentation must be delivered to the Personnel Records Department IN PERSON or digitized to the email:', 'ffcertificate'); ?> <a href="mailto:rhvidafuncionaldremp@sme.prefeitura.sp.gov.br">rhvidafuncionaldremp@sme.prefeitura.sp.gov.br</a>. <?php echo esc_html__('Important: The Personnel Records Department and the Supervisor are not responsible for certificates left in the attendance book or in the folder designated exclusively for Schedule Declarations, as well as those delivered outside the legal deadline for scheduling a medical examination, if applicable.', 'ffcertificate'); ?></li>
                </ol>
            </div>
        </fieldset>
        <?php
    }

    /**
     * Render custom fields fieldset (if any).
     *
     * @param array<int, object> $custom_fields List of custom field definitions.
     * @param array<string, mixed> $custom_values Saved values keyed by field key.
     */
    private static function render_custom_fields_fieldset(array $custom_fields, array $custom_values): void {
        if (empty($custom_fields)) {
            return;
        }
        ?>
        <fieldset class="ffc-rereg-fieldset">
            <legend><?php esc_html_e('Additional Information', 'ffcertificate'); ?></legend>

            <?php foreach ($custom_fields as $cf) : ?>
                <?php
                $field_key = 'field_' . $cf->id;
                $field_value = $custom_values[$field_key] ?? '';
                $field_name = 'custom_fields[' . $field_key . ']';
                $field_id = 'ffc_cf_' . $cf->id;
                $is_required = !empty($cf->is_required);
                $rules = $cf->validation_rules ? json_decode($cf->validation_rules, true) : array();
                ?>
                <div class="ffc-rereg-field" data-field-id="<?php echo esc_attr($cf->id); ?>"
                     data-format="<?php echo esc_attr($rules['format'] ?? ''); ?>"
                     data-regex="<?php echo esc_attr($rules['custom_regex'] ?? ''); ?>"
                     data-regex-msg="<?php echo esc_attr($rules['custom_regex_message'] ?? ''); ?>">
                    <label for="<?php echo esc_attr($field_id); ?>">
                        <?php echo esc_html($cf->field_label); ?>
                        <?php if ($is_required) : ?><span class="required">*</span><?php endif; ?>
                    </label>

                    <?php self::render_custom_field($cf, $field_id, $field_name, $field_value, $is_required); ?>

                    <?php if (!empty($cf->help_text)) : ?>
                        <p class="ffc-field-hint"><?php echo esc_html($cf->help_text); ?></p>
                    <?php endif; ?>

                    <span class="ffc-field-error" role="alert"></span>
                </div>
            <?php endforeach; ?>
        </fieldset>
        <?php
    }

    /**
     * Render a single custom field based on type.
     *
     * @param object      $cf           Custom field definition object.
     * @param string      $field_id     HTML element ID.
     * @param string      $field_name   HTML input name attribute.
     * @param string|null $field_value  Current field value.
     * @param bool        $is_required  Whether the field is required.
     */
    private static function render_custom_field(object $cf, string $field_id, string $field_name, ?string $field_value, bool $is_required): void {
        $rules = $cf->validation_rules ? json_decode($cf->validation_rules, true) : array();

        switch ($cf->field_type) {
            case 'textarea':
                printf(
                    '<textarea id="%s" name="%s" rows="3" %s>%s</textarea>',
                    esc_attr($field_id),
                    esc_attr($field_name),
                    $is_required ? 'required' : '',
                    esc_textarea($field_value)
                );
                break;

            case 'select':
                $choices = $cf->choices ? json_decode($cf->choices, true) : array();
                printf('<select id="%s" name="%s" %s>', esc_attr($field_id), esc_attr($field_name), $is_required ? 'required' : '');
                echo '<option value="">' . esc_html__('Select...', 'ffcertificate') . '</option>';
                foreach ($choices as $choice) {
                    printf(
                        '<option value="%s" %s>%s</option>',
                        esc_attr($choice),
                        selected($field_value, $choice, false),
                        esc_html($choice)
                    );
                }
                echo '</select>';
                break;

            case 'dependent_select':
                self::render_dependent_select_field($cf, $field_id, $field_name, $field_value);
                break;

            case 'checkbox':
                printf(
                    '<label class="ffc-checkbox-label"><input type="checkbox" id="%s" name="%s" value="1" %s> %s</label>',
                    esc_attr($field_id),
                    esc_attr($field_name),
                    checked($field_value, '1', false),
                    esc_html($cf->field_label)
                );
                break;

            case 'number':
                printf(
                    '<input type="number" id="%s" name="%s" value="%s" %s>',
                    esc_attr($field_id),
                    esc_attr($field_name),
                    esc_attr($field_value),
                    $is_required ? 'required' : ''
                );
                break;

            case 'date':
                printf(
                    '<input type="date" id="%s" name="%s" value="%s" %s>',
                    esc_attr($field_id),
                    esc_attr($field_name),
                    esc_attr($field_value),
                    $is_required ? 'required' : ''
                );
                break;

            case 'working_hours':
                self::render_custom_working_hours_field($field_id, $field_name, $field_value);
                break;

            default: // text
                $mask = '';
                $format = $rules['format'] ?? '';
                if ($format === 'cpf') {
                    $mask = 'data-mask="cpf"';
                } elseif ($format === 'phone') {
                    $mask = 'data-mask="phone"';
                }
                printf(
                    '<input type="text" id="%s" name="%s" value="%s" %s %s>',
                    esc_attr($field_id),
                    esc_attr($field_name),
                    esc_attr($field_value),
                    $is_required ? 'required' : '',
                    $mask // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- data attributes are safe
                );
                break;
        }
    }

    /**
     * Render a dependent select custom field.
     *
     * @param object      $cf          Custom field definition object.
     * @param string      $field_id    HTML element ID.
     * @param string      $field_name  HTML input name attribute.
     * @param string|null $field_value Current field value (JSON-encoded parent/child pair or null).
     */
    private static function render_dependent_select_field(object $cf, string $field_id, string $field_name, ?string $field_value): void {
        $dep_groups = CustomFieldRepository::get_dependent_choices($cf);
        $dep_options = $cf->field_options;
        if (is_string($dep_options)) {
            $dep_options = json_decode($dep_options, true);
        }
        $parent_label = $dep_options['parent_label'] ?? __('Category', 'ffcertificate');
        $child_label = $dep_options['child_label'] ?? __('Subcategory', 'ffcertificate');
        $dep_val = is_string($field_value) ? json_decode($field_value, true) : $field_value;
        $dep_parent = $dep_val['parent'] ?? '';
        $dep_child = $dep_val['child'] ?? '';
        ?>
        <input type="hidden" id="<?php echo esc_attr($field_id); ?>" name="<?php echo esc_attr($field_name); ?>"
               value="<?php echo esc_attr(wp_json_encode(array('parent' => $dep_parent, 'child' => $dep_child))); ?>">
        <div class="ffc-dependent-select" data-target="<?php echo esc_attr($field_id); ?>">
            <div class="ffc-rereg-row ffc-rereg-row-2">
                <div class="ffc-rereg-field">
                    <label><?php echo esc_html($parent_label); ?></label>
                    <select class="ffc-dep-parent">
                        <option value=""><?php esc_html_e('Select', 'ffcertificate'); ?></option>
                        <?php foreach (array_keys($dep_groups) as $group) : ?>
                            <option value="<?php echo esc_attr($group); ?>" <?php selected($dep_parent, $group); ?>><?php echo esc_html($group); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="ffc-rereg-field">
                    <label><?php echo esc_html($child_label); ?></label>
                    <select class="ffc-dep-child">
                        <option value=""><?php esc_html_e('Select', 'ffcertificate'); ?></option>
                        <?php
                        if (!empty($dep_parent) && isset($dep_groups[$dep_parent])) {
                            foreach ($dep_groups[$dep_parent] as $child) {
                                printf(
                                    '<option value="%s" %s>%s</option>',
                                    esc_attr($child),
                                    selected($dep_child, $child, false),
                                    esc_html($child)
                                );
                            }
                        }
                        ?>
                    </select>
                </div>
            </div>
            <script type="application/json" class="ffc-dep-groups"><?php echo wp_json_encode($dep_groups); ?></script>
        </div>
        <?php
    }

    /**
     * Render a custom working hours field (for custom field type).
     *
     * @param string      $field_id    HTML element ID.
     * @param string      $field_name  HTML input name attribute.
     * @param string|null $field_value Current field value (JSON-encoded working hours array or null).
     */
    private static function render_custom_working_hours_field(string $field_id, string $field_name, ?string $field_value): void {
        $wh_data = is_string($field_value) ? json_decode($field_value, true) : $field_value;
        if (!is_array($wh_data) || empty($wh_data)) {
            $wh_data = array(
                array('day' => 1, 'entry1' => '08:00', 'exit1' => '12:00', 'entry2' => '13:00', 'exit2' => '17:00'),
                array('day' => 2, 'entry1' => '08:00', 'exit1' => '12:00', 'entry2' => '13:00', 'exit2' => '17:00'),
                array('day' => 3, 'entry1' => '08:00', 'exit1' => '12:00', 'entry2' => '13:00', 'exit2' => '17:00'),
                array('day' => 4, 'entry1' => '08:00', 'exit1' => '12:00', 'entry2' => '13:00', 'exit2' => '17:00'),
                array('day' => 5, 'entry1' => '08:00', 'exit1' => '12:00', 'entry2' => '13:00', 'exit2' => '17:00'),
            );
        }
        $days_labels = array(
            0 => __('Sunday', 'ffcertificate'),
            1 => __('Monday', 'ffcertificate'),
            2 => __('Tuesday', 'ffcertificate'),
            3 => __('Wednesday', 'ffcertificate'),
            4 => __('Thursday', 'ffcertificate'),
            5 => __('Friday', 'ffcertificate'),
            6 => __('Saturday', 'ffcertificate'),
        );
        ?>
        <input type="hidden" id="<?php echo esc_attr($field_id); ?>" name="<?php echo esc_attr($field_name); ?>" value="<?php echo esc_attr(wp_json_encode($wh_data)); ?>">
        <div class="ffc-working-hours" data-target="<?php echo esc_attr($field_id); ?>">
            <table class="ffc-wh-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Day', 'ffcertificate'); ?></th>
                        <th><?php esc_html_e('Entry 1', 'ffcertificate'); ?> <span class="required">*</span></th>
                        <th><?php esc_html_e('Exit 1', 'ffcertificate'); ?></th>
                        <th><?php esc_html_e('Entry 2', 'ffcertificate'); ?></th>
                        <th><?php esc_html_e('Exit 2', 'ffcertificate'); ?> <span class="required">*</span></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($wh_data as $wh_entry) : ?>
                    <tr>
                        <td>
                            <select class="ffc-wh-day">
                                <?php foreach ($days_labels as $d_num => $d_name) : ?>
                                    <option value="<?php echo esc_attr($d_num); ?>" <?php selected($wh_entry['day'], $d_num); ?>><?php echo esc_html($d_name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td><input type="time" class="ffc-wh-entry1" value="<?php echo esc_attr($wh_entry['entry1'] ?? ''); ?>" required></td>
                        <td><input type="time" class="ffc-wh-exit1" value="<?php echo esc_attr($wh_entry['exit1'] ?? ''); ?>"></td>
                        <td><input type="time" class="ffc-wh-entry2" value="<?php echo esc_attr($wh_entry['entry2'] ?? ''); ?>"></td>
                        <td><input type="time" class="ffc-wh-exit2" value="<?php echo esc_attr($wh_entry['exit2'] ?? ''); ?>" required></td>
                        <td><button type="button" class="ffc-wh-remove">&times;</button></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <button type="button" class="button ffc-wh-add">+ <?php esc_html_e('Add Day', 'ffcertificate'); ?></button>
        </div>
        <?php
    }

    /**
     * Render a working hours table for a standard field.
     *
     * @param string               $field_name Hidden input name.
     * @param string               $field_id   Hidden input id.
     * @param array<string, mixed> $wh_data    Current working hours data.
     * @return string HTML.
     */
    private static function render_working_hours_field(string $field_name, string $field_id, array $wh_data): string {
        $days_labels = array(
            0 => __('Sunday', 'ffcertificate'),
            1 => __('Monday', 'ffcertificate'),
            2 => __('Tuesday', 'ffcertificate'),
            3 => __('Wednesday', 'ffcertificate'),
            4 => __('Thursday', 'ffcertificate'),
            5 => __('Friday', 'ffcertificate'),
            6 => __('Saturday', 'ffcertificate'),
        );

        ob_start();
        ?>
        <input type="hidden" id="<?php echo esc_attr($field_id); ?>" name="<?php echo esc_attr($field_name); ?>" value="<?php echo esc_attr(wp_json_encode($wh_data)); ?>">
        <div class="ffc-working-hours" data-target="<?php echo esc_attr($field_id); ?>">
            <table class="ffc-wh-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Day', 'ffcertificate'); ?></th>
                        <th><?php esc_html_e('Entry 1', 'ffcertificate'); ?></th>
                        <th><?php esc_html_e('Exit 1', 'ffcertificate'); ?></th>
                        <th><?php esc_html_e('Entry 2', 'ffcertificate'); ?></th>
                        <th><?php esc_html_e('Exit 2', 'ffcertificate'); ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($wh_data as $wh_entry) : ?>
                    <tr>
                        <td>
                            <select class="ffc-wh-day">
                                <?php foreach ($days_labels as $d_num => $d_name) : ?>
                                    <option value="<?php echo esc_attr($d_num); ?>" <?php selected($wh_entry['day'] ?? 0, $d_num); ?>><?php echo esc_html($d_name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td><input type="time" class="ffc-wh-entry1" value="<?php echo esc_attr($wh_entry['entry1'] ?? ''); ?>"></td>
                        <td><input type="time" class="ffc-wh-exit1" value="<?php echo esc_attr($wh_entry['exit1'] ?? ''); ?>"></td>
                        <td><input type="time" class="ffc-wh-entry2" value="<?php echo esc_attr($wh_entry['entry2'] ?? ''); ?>"></td>
                        <td><input type="time" class="ffc-wh-exit2" value="<?php echo esc_attr($wh_entry['exit2'] ?? ''); ?>"></td>
                        <td><button type="button" class="ffc-wh-remove">&times;</button></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <button type="button" class="button ffc-wh-add">+ <?php esc_html_e('Add Day', 'ffcertificate'); ?></button>
        </div>
        <?php
        return ob_get_clean();
    }
}
