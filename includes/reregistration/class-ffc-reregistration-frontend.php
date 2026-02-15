<?php
declare(strict_types=1);

/**
 * Reregistration Frontend
 *
 * Handles the user-facing reregistration form:
 * - AJAX endpoints for loading, saving draft, and submitting
 * - Server-side validation (CPF, email, phone, regex)
 * - Submission processing (save data, update profiles, auto-approve)
 *
 * @since 4.11.0
 * @package FreeFormCertificate\Reregistration
 */

namespace FreeFormCertificate\Reregistration;

use FreeFormCertificate\UserDashboard\UserManager;

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
        add_action('wp_ajax_ffc_download_ficha', array(__CLASS__, 'ajax_download_ficha'));
    }

    /**
     * AJAX: Get reregistration form HTML.
     *
     * @return void
     */
    public static function ajax_get_form(): void {
        check_ajax_referer('ffc_reregistration_frontend', 'nonce');

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

        $html = self::render_form($rereg, $submission, $user_id);
        wp_send_json_success(array('html' => $html));
    }

    /**
     * AJAX: Submit reregistration.
     *
     * @return void
     */
    public static function ajax_submit(): void {
        check_ajax_referer('ffc_reregistration_frontend', 'nonce');

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
        $data = self::collect_form_data($rereg, $user_id);
        $errors = self::validate_submission($data, $rereg, $user_id);

        if (!empty($errors)) {
            wp_send_json_error(array('message' => __('Please fix the errors below.', 'ffcertificate'), 'errors' => $errors));
        }

        // Process submission
        self::process_submission($submission, $rereg, $data, $user_id);

        wp_send_json_success(array('message' => __('Reregistration submitted successfully!', 'ffcertificate')));
    }

    /**
     * AJAX: Save draft.
     *
     * @return void
     */
    public static function ajax_save_draft(): void {
        check_ajax_referer('ffc_reregistration_frontend', 'nonce');

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

        $data = self::collect_form_data($rereg, $user_id);

        ReregistrationSubmissionRepository::update((int) $submission->id, array(
            'data'   => $data,
            'status' => 'in_progress',
        ));

        wp_send_json_success(array('message' => __('Draft saved.', 'ffcertificate')));
    }

    /**
     * AJAX: Generate ficha PDF data for the current user's submission.
     *
     * @return void
     */
    public static function ajax_download_ficha(): void {
        check_ajax_referer('ffc_reregistration_frontend', 'nonce');

        $submission_id = isset($_POST['submission_id']) ? absint($_POST['submission_id']) : 0;
        $user_id = get_current_user_id();

        if (!$submission_id || !$user_id) {
            wp_send_json_error(array('message' => __('Invalid request.', 'ffcertificate')));
        }

        // Verify this submission belongs to the current user
        $submission = ReregistrationSubmissionRepository::get_by_id($submission_id);
        if (!$submission || (int) $submission->user_id !== $user_id) {
            wp_send_json_error(array('message' => __('Submission not found.', 'ffcertificate')));
        }

        if (!in_array($submission->status, array('submitted', 'approved'), true)) {
            wp_send_json_error(array('message' => __('Ficha not available for this submission.', 'ffcertificate')));
        }

        $ficha_data = FichaGenerator::generate_ficha_data($submission_id);
        if (!$ficha_data) {
            wp_send_json_error(array('message' => __('Could not generate ficha.', 'ffcertificate')));
        }

        wp_send_json_success(array('pdf_data' => $ficha_data));
    }

    /**
     * Divisão → Setor mapping for DRE São Miguel MP.
     *
     * @return array<string, array<string>>
     */
    public static function get_divisao_setor_map(): array {
        return array(
            'DRE - Gabinete'   => array('Assessoria', 'Diretor Regional'),
            'DRE - DIAF'       => array(
                'Adiantamento', 'Alimentação', 'Almoxarifado', 'Apoio', 'Assessoria',
                'Bens', 'Compras / Aquisições', 'Concessionárias', 'Contabilidade',
                'Contratos', 'Demanda', 'Diretor(a)', 'Expediente', 'Gestão Documental',
                'Jurídico', 'NTIC', 'Parcerias', 'Prédios', 'Protocolo', 'PTRF',
                'TEG', 'Terceirizadas',
            ),
            'DRE - DIAFRH'     => array(
                'Adicional', 'Aposentadoria', 'Atribuição', 'Averbação', 'CAAC',
                'Cadastro', 'Certidão de Tempo', 'Diretor(a)', 'Evolução Funcional',
                'Pagamento', 'Posse', 'Probatório', 'Readaptação', 'Rede Somos',
                'Vida Funcional',
            ),
            'DRE - DICEU'      => array('Assessoria', 'DICEU', 'Diretor(a)'),
            'DRE - DIPED'      => array('Assessoria', 'CEFAI', 'DIPED', 'Diretor(a)', 'Estágios', 'NAAPA'),
            'DRE - Supervisão' => array('Assessoria', 'Diretor(a)', 'Supervisão'),
            'ESCOLA - Gestão'      => array('Assistente de Direção', 'Direção'),
            'ESCOLA - Pedagógico'  => array('Coordenação Pedagógica', 'Professor(a)'),
            'ESCOLA - Quadro de Apoio' => array('ATE'),
        );
    }

    /**
     * Sexo options.
     *
     * @return array<string>
     */
    private static function get_sexo_options(): array {
        return array(
            'Feminino',
            'Masculino',
            'Não desejo informar',
        );
    }

    /**
     * Estado civil options.
     *
     * @return array<string>
     */
    private static function get_estado_civil_options(): array {
        return array(
            'Casado(a)',
            'Divorciado(a)',
            'Separado(a) judicialmente',
            'Solteiro(a)',
            'União estável',
            'Viúvo(a)',
        );
    }

    /**
     * Sindicato options.
     *
     * @return array<string>
     */
    private static function get_sindicato_options(): array {
        return array(
            'NENHUM SINDICATO',
            'APROFEM',
            'SINPEEM',
            'SINESP',
            'SINDISEP',
            'OUTROS',
        );
    }

    /**
     * Jornada options.
     *
     * @return array<string>
     */
    private static function get_jornada_options(): array {
        return array(
            'JB.30',
            'JBD.30',
            'JEIF.40',
            'JB.20',
        );
    }

    /**
     * Acúmulo de cargos options.
     *
     * @return array<string>
     */
    private static function get_acumulo_options(): array {
        return array(
            'Não Possuo',
            'Proventos (Holerite Anexo)',
            'Possuo',
        );
    }

    /**
     * Default working hours data (Mon–Fri).
     *
     * @return array
     */
    private static function get_default_working_hours(): array {
        return array(
            array('day' => 1, 'entry1' => '', 'exit1' => '', 'entry2' => '', 'exit2' => ''),
            array('day' => 2, 'entry1' => '', 'exit1' => '', 'entry2' => '', 'exit2' => ''),
            array('day' => 3, 'entry1' => '', 'exit1' => '', 'entry2' => '', 'exit2' => ''),
            array('day' => 4, 'entry1' => '', 'exit1' => '', 'entry2' => '', 'exit2' => ''),
            array('day' => 5, 'entry1' => '', 'exit1' => '', 'entry2' => '', 'exit2' => ''),
        );
    }

    /**
     * Render a working hours table for a standard field.
     *
     * @param string $field_name  Hidden input name (e.g. standard_fields[horario_trabalho]).
     * @param string $field_id    Hidden input id.
     * @param array  $wh_data     Current working hours data.
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
                        <th><?php esc_html_e('Dia', 'ffcertificate'); ?></th>
                        <th><?php esc_html_e('Entrada 1', 'ffcertificate'); ?></th>
                        <th><?php esc_html_e('Saída 1', 'ffcertificate'); ?></th>
                        <th><?php esc_html_e('Entrada 2', 'ffcertificate'); ?></th>
                        <th><?php esc_html_e('Saída 2', 'ffcertificate'); ?></th>
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
            <button type="button" class="button ffc-wh-add">+ <?php esc_html_e('Adicionar Dia', 'ffcertificate'); ?></button>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * UF (state) options.
     *
     * @return array<string>
     */
    private static function get_uf_options(): array {
        return array(
            'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO',
            'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI',
            'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO',
        );
    }

    /**
     * Render the reregistration form HTML.
     *
     * @param object $rereg      Reregistration object.
     * @param object $submission Submission object.
     * @param int    $user_id    User ID.
     * @return string HTML.
     */
    private static function render_form(object $rereg, object $submission, int $user_id): string {
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
            $standard = array(
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
                'acumulo_cargos'      => $profile['acumulo_cargos'] ?? 'Não Possuo',
                'jornada_acumulo'     => $profile['jornada_acumulo'] ?? '',
                'cargo_funcao_acumulo' => $profile['cargo_funcao_acumulo'] ?? '',
                'horario_trabalho_acumulo' => $profile['horario_trabalho_acumulo'] ?? '',
                'department'          => $profile['department'] ?? '',
                'organization'        => $profile['organization'] ?? '',
            );
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
        $divisao_setor_map = self::get_divisao_setor_map();

        ob_start();
        ?>
        <div class="ffc-rereg-form-container" data-reregistration-id="<?php echo esc_attr($rereg->id); ?>">
            <div class="ffc-rereg-header-bar">
                <div class="ffc-rereg-header-title"><?php echo esc_html__('PREFEITURA DE SÃO PAULO / SECRETARIA DE EDUCAÇÃO – SME', 'ffcertificate'); ?></div>
                <div class="ffc-rereg-header-subtitle"><?php echo esc_html__('DIRETORIA REGIONAL DE EDUCAÇÃO SÃO MIGUEL – MP', 'ffcertificate'); ?></div>
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

                <!-- 1. DADOS PESSOAIS -->
                <fieldset class="ffc-rereg-fieldset">
                    <legend><?php echo esc_html__('1. Dados Pessoais', 'ffcertificate'); ?></legend>

                    <div class="ffc-rereg-field">
                        <label for="ffc_rereg_name"><?php esc_html_e('Nome', 'ffcertificate'); ?> <span class="required">*</span></label>
                        <input type="text" id="ffc_rereg_name" name="standard_fields[display_name]"
                               value="<?php echo esc_attr($standard['display_name'] ?? ''); ?>" required>
                    </div>

                    <div class="ffc-rereg-row ffc-rereg-row-3">
                        <div class="ffc-rereg-field">
                            <label for="ffc_rereg_sexo"><?php esc_html_e('Sexo', 'ffcertificate'); ?> <span class="required">*</span></label>
                            <select id="ffc_rereg_sexo" name="standard_fields[sexo]" required>
                                <option value=""><?php esc_html_e('Selecione', 'ffcertificate'); ?></option>
                                <?php foreach (self::get_sexo_options() as $opt) : ?>
                                    <option value="<?php echo esc_attr($opt); ?>" <?php selected($standard['sexo'] ?? '', $opt); ?>><?php echo esc_html($opt); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="ffc-rereg-field">
                            <label for="ffc_rereg_estado_civil"><?php esc_html_e('Estado Civil', 'ffcertificate'); ?> <span class="required">*</span></label>
                            <select id="ffc_rereg_estado_civil" name="standard_fields[estado_civil]" required>
                                <option value=""><?php esc_html_e('Selecione', 'ffcertificate'); ?></option>
                                <?php foreach (self::get_estado_civil_options() as $opt) : ?>
                                    <option value="<?php echo esc_attr($opt); ?>" <?php selected($standard['estado_civil'] ?? '', $opt); ?>><?php echo esc_html($opt); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="ffc-rereg-field">
                            <label for="ffc_rereg_rf"><?php esc_html_e('RF', 'ffcertificate'); ?></label>
                            <input type="text" id="ffc_rereg_rf" name="standard_fields[rf]"
                                   value="<?php echo esc_attr($standard['rf'] ?? ''); ?>" data-mask="number">
                        </div>
                    </div>

                    <div class="ffc-rereg-row ffc-rereg-row-2">
                        <div class="ffc-rereg-field">
                            <label for="ffc_rereg_vinculo"><?php esc_html_e('Vínculo', 'ffcertificate'); ?></label>
                            <input type="text" id="ffc_rereg_vinculo" name="standard_fields[vinculo]"
                                   value="<?php echo esc_attr($standard['vinculo'] ?? ''); ?>">
                        </div>
                        <div class="ffc-rereg-field">
                            <label for="ffc_rereg_data_nascimento"><?php esc_html_e('Data de Nascimento', 'ffcertificate'); ?> <span class="required">*</span></label>
                            <input type="date" id="ffc_rereg_data_nascimento" name="standard_fields[data_nascimento]"
                                   value="<?php echo esc_attr($standard['data_nascimento'] ?? ''); ?>" required>
                        </div>
                    </div>

                    <div class="ffc-rereg-row ffc-rereg-row-2">
                        <div class="ffc-rereg-field">
                            <label for="ffc_rereg_cpf"><?php esc_html_e('CPF', 'ffcertificate'); ?> <span class="required">*</span></label>
                            <input type="text" id="ffc_rereg_cpf" name="standard_fields[cpf]"
                                   value="<?php echo esc_attr($standard['cpf'] ?? ''); ?>" data-mask="cpf" required
                                   data-format="cpf">
                        </div>
                        <div class="ffc-rereg-field">
                            <label for="ffc_rereg_rg"><?php esc_html_e('CIN', 'ffcertificate'); ?></label>
                            <input type="text" id="ffc_rereg_rg" name="standard_fields[rg]"
                                   value="<?php echo esc_attr($standard['rg'] ?? ''); ?>" data-mask="cin">
                        </div>
                    </div>

                    <div class="ffc-rereg-field">
                        <label for="ffc_rereg_unidade_lotacao"><?php esc_html_e('Unidade de Lotação', 'ffcertificate'); ?></label>
                        <input type="text" id="ffc_rereg_unidade_lotacao" name="standard_fields[unidade_lotacao]"
                               value="<?php echo esc_attr($standard['unidade_lotacao'] ?? ''); ?>">
                    </div>

                    <div class="ffc-rereg-row ffc-rereg-row-3">
                        <div class="ffc-rereg-field">
                            <label for="ffc_rereg_unidade_exercicio"><?php esc_html_e('Unidade de Exercício', 'ffcertificate'); ?></label>
                            <input type="text" id="ffc_rereg_unidade_exercicio" name="standard_fields[unidade_exercicio]"
                                   value="<?php echo esc_attr($standard['unidade_exercicio'] ?? 'DRE MP'); ?>">
                        </div>
                        <div class="ffc-rereg-field">
                            <label for="ffc_rereg_divisao"><?php esc_html_e('Divisão', 'ffcertificate'); ?> <span class="required">*</span></label>
                            <select id="ffc_rereg_divisao" name="standard_fields[divisao]" required>
                                <option value=""><?php esc_html_e('Selecione', 'ffcertificate'); ?></option>
                                <?php foreach (array_keys($divisao_setor_map) as $div) : ?>
                                    <option value="<?php echo esc_attr($div); ?>" <?php selected($standard['divisao'] ?? '', $div); ?>><?php echo esc_html($div); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="ffc-rereg-field">
                            <label for="ffc_rereg_setor"><?php esc_html_e('Setor', 'ffcertificate'); ?> <span class="required">*</span></label>
                            <select id="ffc_rereg_setor" name="standard_fields[setor]" required>
                                <option value=""><?php esc_html_e('Selecione Divisão / Local', 'ffcertificate'); ?></option>
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
                            <label for="ffc_rereg_endereco"><?php esc_html_e('Endereço', 'ffcertificate'); ?></label>
                            <input type="text" id="ffc_rereg_endereco" name="standard_fields[endereco]"
                                   value="<?php echo esc_attr($standard['endereco'] ?? ''); ?>">
                        </div>
                        <div class="ffc-rereg-field ffc-rereg-field-sm">
                            <label for="ffc_rereg_endereco_numero"><?php esc_html_e('Nº', 'ffcertificate'); ?></label>
                            <input type="text" id="ffc_rereg_endereco_numero" name="standard_fields[endereco_numero]"
                                   value="<?php echo esc_attr($standard['endereco_numero'] ?? ''); ?>">
                        </div>
                        <div class="ffc-rereg-field ffc-rereg-field-sm">
                            <label for="ffc_rereg_endereco_complemento"><?php esc_html_e('Compl.', 'ffcertificate'); ?></label>
                            <input type="text" id="ffc_rereg_endereco_complemento" name="standard_fields[endereco_complemento]"
                                   value="<?php echo esc_attr($standard['endereco_complemento'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="ffc-rereg-row ffc-rereg-row-4">
                        <div class="ffc-rereg-field">
                            <label for="ffc_rereg_bairro"><?php esc_html_e('Bairro', 'ffcertificate'); ?></label>
                            <input type="text" id="ffc_rereg_bairro" name="standard_fields[bairro]"
                                   value="<?php echo esc_attr($standard['bairro'] ?? ''); ?>">
                        </div>
                        <div class="ffc-rereg-field">
                            <label for="ffc_rereg_cidade"><?php esc_html_e('Cidade', 'ffcertificate'); ?></label>
                            <input type="text" id="ffc_rereg_cidade" name="standard_fields[cidade]"
                                   value="<?php echo esc_attr($standard['cidade'] ?? 'SÃO PAULO'); ?>">
                        </div>
                        <div class="ffc-rereg-field ffc-rereg-field-sm">
                            <label for="ffc_rereg_uf"><?php esc_html_e('UF', 'ffcertificate'); ?></label>
                            <select id="ffc_rereg_uf" name="standard_fields[uf]">
                                <?php foreach (self::get_uf_options() as $uf) : ?>
                                    <option value="<?php echo esc_attr($uf); ?>" <?php selected($standard['uf'] ?? 'SP', $uf); ?>><?php echo esc_html($uf); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="ffc-rereg-field">
                            <label for="ffc_rereg_cep"><?php esc_html_e('CEP', 'ffcertificate'); ?></label>
                            <input type="text" id="ffc_rereg_cep" name="standard_fields[cep]"
                                   value="<?php echo esc_attr($standard['cep'] ?? ''); ?>" data-mask="cep">
                        </div>
                    </div>
                </fieldset>

                <!-- 2. CONTATOS -->
                <fieldset class="ffc-rereg-fieldset">
                    <legend><?php echo esc_html__('2. Contatos', 'ffcertificate'); ?></legend>

                    <div class="ffc-rereg-row ffc-rereg-row-2">
                        <div class="ffc-rereg-field">
                            <label for="ffc_rereg_phone"><?php esc_html_e('Telefone Residencial', 'ffcertificate'); ?></label>
                            <input type="tel" id="ffc_rereg_phone" name="standard_fields[phone]"
                                   value="<?php echo esc_attr($standard['phone'] ?? ''); ?>" data-mask="phone">
                        </div>
                        <div class="ffc-rereg-field">
                            <label for="ffc_rereg_celular"><?php esc_html_e('Tel. Celular', 'ffcertificate'); ?> <span class="required">*</span></label>
                            <input type="tel" id="ffc_rereg_celular" name="standard_fields[celular]"
                                   value="<?php echo esc_attr($standard['celular'] ?? ''); ?>" data-mask="phone" required>
                        </div>
                    </div>

                    <div class="ffc-rereg-row ffc-rereg-row-2">
                        <div class="ffc-rereg-field">
                            <label for="ffc_rereg_contato_emergencia"><?php esc_html_e('Contato Emergência', 'ffcertificate'); ?> <span class="required">*</span></label>
                            <input type="text" id="ffc_rereg_contato_emergencia" name="standard_fields[contato_emergencia]"
                                   value="<?php echo esc_attr($standard['contato_emergencia'] ?? ''); ?>" required>
                        </div>
                        <div class="ffc-rereg-field">
                            <label for="ffc_rereg_tel_emergencia"><?php esc_html_e('Tel. Emergência', 'ffcertificate'); ?> <span class="required">*</span></label>
                            <input type="tel" id="ffc_rereg_tel_emergencia" name="standard_fields[tel_emergencia]"
                                   value="<?php echo esc_attr($standard['tel_emergencia'] ?? ''); ?>" data-mask="phone" required>
                        </div>
                    </div>

                    <div class="ffc-rereg-field">
                        <label for="ffc_rereg_email_inst"><?php esc_html_e('E-mail Institucional', 'ffcertificate'); ?></label>
                        <input type="email" id="ffc_rereg_email_inst" name="standard_fields[email_institucional]"
                               value="<?php echo esc_attr($standard['email_institucional'] ?? $user->user_email); ?>">
                    </div>

                    <div class="ffc-rereg-field">
                        <label for="ffc_rereg_email_part"><?php esc_html_e('E-mail Particular', 'ffcertificate'); ?></label>
                        <input type="email" id="ffc_rereg_email_part" name="standard_fields[email_particular]"
                               value="<?php echo esc_attr($standard['email_particular'] ?? ''); ?>">
                    </div>
                </fieldset>

                <?php
                // Prepare working hours data
                $wh_main_raw = $standard['horario_trabalho'] ?? '';
                $wh_main = is_string($wh_main_raw) && !empty($wh_main_raw) ? json_decode($wh_main_raw, true) : null;
                if (!is_array($wh_main) || empty($wh_main)) {
                    $wh_main = self::get_default_working_hours();
                }

                $wh_acumulo_raw = $standard['horario_trabalho_acumulo'] ?? '';
                $wh_acumulo = is_string($wh_acumulo_raw) && !empty($wh_acumulo_raw) ? json_decode($wh_acumulo_raw, true) : null;
                if (!is_array($wh_acumulo) || empty($wh_acumulo)) {
                    $wh_acumulo = self::get_default_working_hours();
                }
                ?>

                <!-- 3. JORNADA / HORÁRIO DE TRABALHO -->
                <fieldset class="ffc-rereg-fieldset">
                    <legend><?php echo esc_html__('3. Jornada / Horário de Trabalho', 'ffcertificate'); ?></legend>

                    <div class="ffc-rereg-field">
                        <label for="ffc_rereg_jornada"><?php esc_html_e('Jornada', 'ffcertificate'); ?> <span class="required">*</span></label>
                        <select id="ffc_rereg_jornada" name="standard_fields[jornada]" required>
                            <option value=""><?php esc_html_e('Selecione', 'ffcertificate'); ?></option>
                            <?php foreach (self::get_jornada_options() as $j) : ?>
                                <option value="<?php echo esc_attr($j); ?>" <?php selected($standard['jornada'] ?? '', $j); ?>><?php echo esc_html($j); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="ffc-rereg-field">
                        <label><?php esc_html_e('Horário de Trabalho', 'ffcertificate'); ?></label>
                        <?php echo self::render_working_hours_field('standard_fields[horario_trabalho]', 'ffc_rereg_horario_trabalho', $wh_main); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    </div>
                </fieldset>

                <!-- 4. ACÚMULO DE CARGOS -->
                <fieldset class="ffc-rereg-fieldset">
                    <legend><?php echo esc_html__('4. Acúmulo de Cargos', 'ffcertificate'); ?></legend>

                    <div class="ffc-rereg-field">
                        <label for="ffc_rereg_acumulo"><?php esc_html_e('Acúmulo de Cargo', 'ffcertificate'); ?></label>
                        <select id="ffc_rereg_acumulo" name="standard_fields[acumulo_cargos]">
                            <?php foreach (self::get_acumulo_options() as $opt) : ?>
                                <option value="<?php echo esc_attr($opt); ?>" <?php selected($standard['acumulo_cargos'] ?? 'Não Possuo', $opt); ?>><?php echo esc_html($opt); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="ffc-rereg-acumulo-fields" style="<?php echo ($standard['acumulo_cargos'] ?? 'Não Possuo') === 'Possuo' ? '' : 'display:none'; ?>">
                        <div class="ffc-rereg-row ffc-rereg-row-2">
                            <div class="ffc-rereg-field">
                                <label for="ffc_rereg_jornada_acumulo"><?php esc_html_e('Jornada do Acúmulo', 'ffcertificate'); ?></label>
                                <select id="ffc_rereg_jornada_acumulo" name="standard_fields[jornada_acumulo]">
                                    <option value=""><?php esc_html_e('Selecione', 'ffcertificate'); ?></option>
                                    <?php foreach (self::get_jornada_options() as $j) : ?>
                                        <option value="<?php echo esc_attr($j); ?>" <?php selected($standard['jornada_acumulo'] ?? '', $j); ?>><?php echo esc_html($j); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="ffc-rereg-field">
                                <label for="ffc_rereg_cargo_funcao_acumulo"><?php esc_html_e('Cargo/Função exercido atualmente', 'ffcertificate'); ?></label>
                                <input type="text" id="ffc_rereg_cargo_funcao_acumulo" name="standard_fields[cargo_funcao_acumulo]"
                                       value="<?php echo esc_attr($standard['cargo_funcao_acumulo'] ?? ''); ?>">
                            </div>
                        </div>

                        <div class="ffc-rereg-field" style="margin-top: 16px;">
                            <label><?php esc_html_e('Horário de Trabalho do Acúmulo', 'ffcertificate'); ?></label>
                            <?php echo self::render_working_hours_field('standard_fields[horario_trabalho_acumulo]', 'ffc_rereg_horario_trabalho_acumulo', $wh_acumulo); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        </div>
                    </div>
                </fieldset>

                <!-- 5. SINDICATO -->
                <fieldset class="ffc-rereg-fieldset">
                    <legend><?php echo esc_html__('5. Sindicato', 'ffcertificate'); ?></legend>

                    <div class="ffc-rereg-field">
                        <label for="ffc_rereg_sindicato"><?php esc_html_e('Sindicato', 'ffcertificate'); ?></label>
                        <select id="ffc_rereg_sindicato" name="standard_fields[sindicato]">
                            <option value=""><?php esc_html_e('Selecione', 'ffcertificate'); ?></option>
                            <?php foreach (self::get_sindicato_options() as $opt) : ?>
                                <option value="<?php echo esc_attr($opt); ?>" <?php selected($standard['sindicato'] ?? '', $opt); ?>><?php echo esc_html($opt); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </fieldset>

                <!-- 6. TERMO DE CIÊNCIA -->
                <fieldset class="ffc-rereg-fieldset">
                    <legend><?php echo esc_html__('6. Termo de Ciência', 'ffcertificate'); ?></legend>

                    <div class="ffc-rereg-termo-text">
                        <p><?php echo esc_html__('Declaro estar ciente das seguintes obrigações:', 'ffcertificate'); ?></p>
                        <ul>
                            <li><?php echo esc_html__('Declarar os bens e rendas em Declaração de Família, conforme legislação vigente.', 'ffcertificate'); ?></li>
                            <li><?php echo esc_html__('Efetuar o recadastramento do vale-transporte conforme normativa vigente.', 'ffcertificate'); ?></li>
                            <li><?php echo esc_html__('Realizar o recadastramento anual obrigatório.', 'ffcertificate'); ?></li>
                            <li><?php echo esc_html__('Manter atualizada a declaração de bens e valores.', 'ffcertificate'); ?></li>
                            <li><?php echo esc_html__('Comunicar antecipadamente sobre adiantamento de 13º salário.', 'ffcertificate'); ?></li>
                            <li><?php echo esc_html__('Apresentar atestados médicos conforme normativa vigente.', 'ffcertificate'); ?></li>
                        </ul>
                    </div>
                </fieldset>

                <?php if (!empty($custom_fields)) : ?>
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

                            <?php
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
                                                    <option value=""><?php esc_html_e('Selecione', 'ffcertificate'); ?></option>
                                                    <?php foreach (array_keys($dep_groups) as $group) : ?>
                                                        <option value="<?php echo esc_attr($group); ?>" <?php selected($dep_parent, $group); ?>><?php echo esc_html($group); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="ffc-rereg-field">
                                                <label><?php echo esc_html($child_label); ?></label>
                                                <select class="ffc-dep-child">
                                                    <option value=""><?php esc_html_e('Selecione', 'ffcertificate'); ?></option>
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

                            if (!empty($cf->help_text)) {
                                printf('<p class="ffc-field-hint">%s</p>', esc_html($cf->help_text));
                            }
                            ?>

                            <span class="ffc-field-error" role="alert"></span>
                        </div>
                    <?php endforeach; ?>
                </fieldset>
                <?php endif; ?>

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
     * Sanitize a working hours JSON string.
     *
     * @param string $raw Raw JSON input.
     * @return string Sanitized JSON.
     */
    private static function sanitize_working_hours(string $raw): string {
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
     * @return array Structured data.
     */
    private static function collect_form_data(object $rereg, int $user_id): array {
        $standard = array();
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
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
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
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
     * @param array  $data    Collected data.
     * @param object $rereg   Reregistration.
     * @param int    $user_id User ID.
     * @return array Errors keyed by field name.
     */
    private static function validate_submission(array $data, object $rereg, int $user_id): array {
        $errors = array();

        $s = $data['standard_fields'];

        // Required standard fields
        if (empty($s['display_name'])) {
            $errors['standard_fields[display_name]'] = __('Nome é obrigatório.', 'ffcertificate');
        }
        if (empty($s['sexo'])) {
            $errors['standard_fields[sexo]'] = __('Sexo é obrigatório.', 'ffcertificate');
        }
        if (empty($s['estado_civil'])) {
            $errors['standard_fields[estado_civil]'] = __('Estado Civil é obrigatório.', 'ffcertificate');
        }
        if (empty($s['data_nascimento'])) {
            $errors['standard_fields[data_nascimento]'] = __('Data de Nascimento é obrigatória.', 'ffcertificate');
        }
        if (empty($s['divisao'])) {
            $errors['standard_fields[divisao]'] = __('Divisão é obrigatória.', 'ffcertificate');
        }
        if (empty($s['setor'])) {
            $errors['standard_fields[setor]'] = __('Setor é obrigatório.', 'ffcertificate');
        }
        if (empty($s['jornada'])) {
            $errors['standard_fields[jornada]'] = __('Jornada é obrigatória.', 'ffcertificate');
        }
        if (empty($s['celular'])) {
            $errors['standard_fields[celular]'] = __('Tel. Celular é obrigatório.', 'ffcertificate');
        }
        if (empty($s['contato_emergencia'])) {
            $errors['standard_fields[contato_emergencia]'] = __('Contato de Emergência é obrigatório.', 'ffcertificate');
        }
        if (empty($s['tel_emergencia'])) {
            $errors['standard_fields[tel_emergencia]'] = __('Tel. Emergência é obrigatório.', 'ffcertificate');
        }

        // CPF validation (required)
        if (empty($s['cpf'])) {
            $errors['standard_fields[cpf]'] = __('CPF é obrigatório.', 'ffcertificate');
        } elseif (!\FreeFormCertificate\Core\Utils::validate_cpf($s['cpf'])) {
            $errors['standard_fields[cpf]'] = __('CPF inválido.', 'ffcertificate');
        }

        // Phone format validation (if provided)
        $phone = $s['phone'] ?? '';
        if (!empty($phone) && !\FreeFormCertificate\Core\Utils::validate_phone($phone)) {
            $errors['standard_fields[phone]'] = __('Telefone residencial inválido.', 'ffcertificate');
        }

        // Celular format validation
        $celular = $s['celular'] ?? '';
        if (!empty($celular) && !\FreeFormCertificate\Core\Utils::validate_phone($celular)) {
            $errors['standard_fields[celular]'] = __('Tel. Celular inválido.', 'ffcertificate');
        }

        // Emergency phone validation
        $tel_emerg = $s['tel_emergencia'] ?? '';
        if (!empty($tel_emerg) && !\FreeFormCertificate\Core\Utils::validate_phone($tel_emerg)) {
            $errors['standard_fields[tel_emergencia]'] = __('Tel. Emergência inválido.', 'ffcertificate');
        }

        // Divisão/Setor consistency validation
        if (!empty($s['divisao']) && !empty($s['setor'])) {
            $map = self::get_divisao_setor_map();
            if (isset($map[$s['divisao']]) && !in_array($s['setor'], $map[$s['divisao']], true)) {
                $errors['standard_fields[setor]'] = __('Setor inválido para a Divisão selecionada.', 'ffcertificate');
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
                // Ensure regex has delimiters
                if ($regex[0] !== '/') {
                    $regex = '/' . $regex . '/';
                }
                if (!@preg_match($regex, $value)) {
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
     * @param array  $data       Validated data.
     * @param int    $user_id    User ID.
     * @return void
     */
    private static function process_submission(object $submission, object $rereg, array $data, int $user_id): void {
        // Determine final status
        $new_status = !empty($rereg->auto_approve) ? 'approved' : 'submitted';

        // Build update data (single query)
        $update_data = array(
            'data'         => $data,
            'status'       => $new_status,
            'submitted_at' => current_time('mysql'),
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
                $user_id,
                array(
                    'reregistration_id' => $rereg->id,
                    'submission_id'     => $submission->id,
                    'status'            => $new_status,
                )
            );
        }
    }

    /**
     * Get active reregistrations for a user with submission status.
     *
     * @param int $user_id User ID.
     * @return array Array of reregistration data with submission info.
     */
    public static function get_user_reregistrations(int $user_id): array {
        $active = ReregistrationRepository::get_active_for_user($user_id);
        $result = array();

        foreach ($active as $rereg) {
            $submission = ReregistrationSubmissionRepository::get_by_reregistration_and_user((int) $rereg->id, $user_id);
            $sub_status = $submission ? $submission->status : 'no_submission';

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
            );
        }

        return $result;
    }
}
