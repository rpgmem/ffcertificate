<?php
declare(strict_types=1);

/**
 * Reregistration Standard Fields Seeder
 *
 * Seeds the ~30 "standard" reregistration fields (personal data, contacts,
 * schedule, accumulation, union) as rows in wp_ffc_custom_fields per audience.
 *
 * With this, ALL fields displayed in the reregistration form live in the
 * same table, so the admin can relabel, reorder, activate/deactivate and
 * even encrypt them from the existing custom fields UI.
 *
 * Source marker: field_source = 'standard' (cannot be deleted, only
 * deactivated). Fields created by admins have field_source = 'custom'.
 *
 * @since 4.13.0
 * @package FreeFormCertificate\Reregistration
 */

namespace FreeFormCertificate\Reregistration;

use FreeFormCertificate\Audience\AudienceRepository;

if (!defined('ABSPATH')) {
    exit;
}

class ReregistrationStandardFieldsSeeder {

    /**
     * Register WordPress hooks used by the seeder.
     *
     * Auto-seeds standard fields whenever a new audience is created.
     *
     * @since 4.13.0
     * @return void
     */
    public static function register(): void {
        add_action('ffc_audience_created', array(__CLASS__, 'on_audience_created'), 10, 2);
    }

    /**
     * Hook handler for `ffc_audience_created`.
     *
     * @since 4.13.0
     * @param int   $audience_id New audience ID.
     * @param array $data        Audience creation data.
     * @return void
     */
    public static function on_audience_created(int $audience_id, array $data = array()): void {
        unset($data); // unused
        if ($audience_id <= 0) {
            return;
        }
        self::seed_for_audience($audience_id);
    }

    /**
     * Field groups used for the standard fields.
     */
    public const GROUP_PERSONAL     = 'personal';
    public const GROUP_CONTACT      = 'contact';
    public const GROUP_SCHEDULE     = 'schedule';
    public const GROUP_ACCUMULATION = 'accumulation';
    public const GROUP_UNION        = 'union';

    /**
     * Get the ordered list of groups with translated labels.
     *
     * @return array<string, string>
     */
    public static function get_group_labels(): array {
        return array(
            self::GROUP_PERSONAL     => __('Personal Data', 'ffcertificate'),
            self::GROUP_CONTACT      => __('Contact Information', 'ffcertificate'),
            self::GROUP_SCHEDULE     => __('Work Schedule', 'ffcertificate'),
            self::GROUP_ACCUMULATION => __('Position Accumulation', 'ffcertificate'),
            self::GROUP_UNION        => __('Union', 'ffcertificate'),
        );
    }

    /**
     * Returns the definitions for the standard reregistration fields.
     *
     * Order here determines sort_order within each group.
     *
     * Keys:
     * - field_key:      unique identifier (matches historical hardcoded name)
     * - field_label:    human-readable label
     * - field_type:     input type (text, select, date, working_hours, dependent_select…)
     * - field_group:    section in the form
     * - profile_key:    target key in wp_ffc_user_profiles / user meta (null = don't sync)
     * - is_sensitive:   1 if value must be AES-encrypted before storing
     * - mask:           client-side mask/format hint (cpf, cin, rf, phone, cep, number, email)
     * - required:       default is_required value
     * - options:        field_options JSON (null or array)
     * - validation:     validation_rules JSON (null or array)
     *
     * @return array<int, array<string, mixed>>
     */
    public static function get_standard_fields_definition(): array {
        $divisao_map = ReregistrationFieldOptions::get_divisao_setor_map();

        return array(
            // ───── Personal Data ─────
            array(
                'field_key'   => 'display_name',
                'field_label' => __('Name', 'ffcertificate'),
                'field_type'  => 'text',
                'field_group' => self::GROUP_PERSONAL,
                'profile_key' => 'display_name',
                'is_sensitive'=> 0,
                'mask'        => null,
                'required'    => 1,
                'options'     => null,
                'validation'  => null,
            ),
            array(
                'field_key'   => 'sexo',
                'field_label' => __('Sex', 'ffcertificate'),
                'field_type'  => 'select',
                'field_group' => self::GROUP_PERSONAL,
                'profile_key' => null,
                'is_sensitive'=> 0,
                'mask'        => null,
                'required'    => 1,
                'options'     => array('choices' => ReregistrationFieldOptions::get_sexo_options()),
                'validation'  => null,
            ),
            array(
                'field_key'   => 'estado_civil',
                'field_label' => __('Marital Status', 'ffcertificate'),
                'field_type'  => 'select',
                'field_group' => self::GROUP_PERSONAL,
                'profile_key' => null,
                'is_sensitive'=> 0,
                'mask'        => null,
                'required'    => 1,
                'options'     => array('choices' => ReregistrationFieldOptions::get_estado_civil_options()),
                'validation'  => null,
            ),
            array(
                'field_key'   => 'rf',
                'field_label' => __('RF', 'ffcertificate'),
                'field_type'  => 'text',
                'field_group' => self::GROUP_PERSONAL,
                'profile_key' => 'rf',
                'is_sensitive'=> 1,
                'mask'        => 'rf',
                'required'    => 0,
                'options'     => null,
                'validation'  => null,
            ),
            array(
                'field_key'   => 'vinculo',
                'field_label' => __('Employment Bond', 'ffcertificate'),
                'field_type'  => 'text',
                'field_group' => self::GROUP_PERSONAL,
                'profile_key' => null,
                'is_sensitive'=> 0,
                'mask'        => 'number',
                'required'    => 0,
                'options'     => array('maxlength' => 2),
                'validation'  => null,
            ),
            array(
                'field_key'   => 'data_nascimento',
                'field_label' => __('Date of Birth', 'ffcertificate'),
                'field_type'  => 'date',
                'field_group' => self::GROUP_PERSONAL,
                'profile_key' => null,
                'is_sensitive'=> 0,
                'mask'        => null,
                'required'    => 1,
                'options'     => null,
                'validation'  => null,
            ),
            array(
                'field_key'   => 'cpf',
                'field_label' => __('CPF/CIN', 'ffcertificate'),
                'field_type'  => 'text',
                'field_group' => self::GROUP_PERSONAL,
                'profile_key' => 'cpf',
                'is_sensitive'=> 1,
                'mask'        => 'cpf',
                'required'    => 1,
                'options'     => null,
                'validation'  => array('format' => 'cpf'),
            ),
            array(
                'field_key'   => 'rg',
                'field_label' => __('RG', 'ffcertificate'),
                'field_type'  => 'text',
                'field_group' => self::GROUP_PERSONAL,
                'profile_key' => 'rg',
                'is_sensitive'=> 1,
                'mask'        => 'cin',
                'required'    => 0,
                'options'     => null,
                'validation'  => null,
            ),
            array(
                'field_key'   => 'unidade_lotacao',
                'field_label' => __('Assignment Unit', 'ffcertificate'),
                'field_type'  => 'text',
                'field_group' => self::GROUP_PERSONAL,
                'profile_key' => null,
                'is_sensitive'=> 0,
                'mask'        => null,
                'required'    => 0,
                'options'     => null,
                'validation'  => null,
            ),
            array(
                'field_key'   => 'unidade_exercicio',
                'field_label' => __('Working Unit', 'ffcertificate'),
                'field_type'  => 'text',
                'field_group' => self::GROUP_PERSONAL,
                'profile_key' => null,
                'is_sensitive'=> 0,
                'mask'        => null,
                'required'    => 0,
                'options'     => array('default' => 'DRE MP'),
                'validation'  => null,
            ),
            array(
                'field_key'   => 'divisao_setor',
                'field_label' => __('Division / Department', 'ffcertificate'),
                'field_type'  => 'dependent_select',
                'field_group' => self::GROUP_PERSONAL,
                'profile_key' => 'divisao_setor',
                'is_sensitive'=> 0,
                'mask'        => null,
                'required'    => 1,
                'options'     => array(
                    'groups'       => $divisao_map,
                    'parent_label' => __('Division', 'ffcertificate'),
                    'child_label'  => __('Department', 'ffcertificate'),
                ),
                'validation'  => null,
            ),

            // ───── Contact Information ─────
            array(
                'field_key'   => 'endereco',
                'field_label' => __('Address', 'ffcertificate'),
                'field_type'  => 'text',
                'field_group' => self::GROUP_CONTACT,
                'profile_key' => null,
                'is_sensitive'=> 0,
                'mask'        => null,
                'required'    => 0,
                'options'     => null,
                'validation'  => null,
            ),
            array(
                'field_key'   => 'endereco_numero',
                'field_label' => __('Number', 'ffcertificate'),
                'field_type'  => 'text',
                'field_group' => self::GROUP_CONTACT,
                'profile_key' => null,
                'is_sensitive'=> 0,
                'mask'        => null,
                'required'    => 0,
                'options'     => null,
                'validation'  => null,
            ),
            array(
                'field_key'   => 'endereco_complemento',
                'field_label' => __('Apt/Suite', 'ffcertificate'),
                'field_type'  => 'text',
                'field_group' => self::GROUP_CONTACT,
                'profile_key' => null,
                'is_sensitive'=> 0,
                'mask'        => null,
                'required'    => 0,
                'options'     => null,
                'validation'  => null,
            ),
            array(
                'field_key'   => 'bairro',
                'field_label' => __('Neighborhood', 'ffcertificate'),
                'field_type'  => 'text',
                'field_group' => self::GROUP_CONTACT,
                'profile_key' => null,
                'is_sensitive'=> 0,
                'mask'        => null,
                'required'    => 0,
                'options'     => null,
                'validation'  => null,
            ),
            array(
                'field_key'   => 'cidade',
                'field_label' => __('City', 'ffcertificate'),
                'field_type'  => 'text',
                'field_group' => self::GROUP_CONTACT,
                'profile_key' => null,
                'is_sensitive'=> 0,
                'mask'        => null,
                'required'    => 0,
                'options'     => array('default' => 'SÃO PAULO'),
                'validation'  => null,
            ),
            array(
                'field_key'   => 'uf',
                'field_label' => __('State', 'ffcertificate'),
                'field_type'  => 'select',
                'field_group' => self::GROUP_CONTACT,
                'profile_key' => null,
                'is_sensitive'=> 0,
                'mask'        => null,
                'required'    => 0,
                'options'     => array(
                    'choices' => ReregistrationFieldOptions::get_uf_options(),
                    'default' => 'SP',
                ),
                'validation'  => null,
            ),
            array(
                'field_key'   => 'cep',
                'field_label' => __('Zip Code', 'ffcertificate'),
                'field_type'  => 'text',
                'field_group' => self::GROUP_CONTACT,
                'profile_key' => null,
                'is_sensitive'=> 0,
                'mask'        => 'cep',
                'required'    => 0,
                'options'     => null,
                'validation'  => null,
            ),
            array(
                'field_key'   => 'phone',
                'field_label' => __('Home Phone', 'ffcertificate'),
                'field_type'  => 'text',
                'field_group' => self::GROUP_CONTACT,
                'profile_key' => 'phone',
                'is_sensitive'=> 0,
                'mask'        => 'phone',
                'required'    => 0,
                'options'     => null,
                'validation'  => array('format' => 'phone'),
            ),
            array(
                'field_key'   => 'celular',
                'field_label' => __('Cell Phone', 'ffcertificate'),
                'field_type'  => 'text',
                'field_group' => self::GROUP_CONTACT,
                'profile_key' => 'celular',
                'is_sensitive'=> 0,
                'mask'        => 'phone',
                'required'    => 1,
                'options'     => null,
                'validation'  => array('format' => 'phone'),
            ),
            array(
                'field_key'   => 'contato_emergencia',
                'field_label' => __('Emergency Contact', 'ffcertificate'),
                'field_type'  => 'text',
                'field_group' => self::GROUP_CONTACT,
                'profile_key' => null,
                'is_sensitive'=> 0,
                'mask'        => null,
                'required'    => 1,
                'options'     => null,
                'validation'  => null,
            ),
            array(
                'field_key'   => 'tel_emergencia',
                'field_label' => __('Emergency Phone', 'ffcertificate'),
                'field_type'  => 'text',
                'field_group' => self::GROUP_CONTACT,
                'profile_key' => null,
                'is_sensitive'=> 0,
                'mask'        => 'phone',
                'required'    => 1,
                'options'     => null,
                'validation'  => array('format' => 'phone'),
            ),
            array(
                'field_key'   => 'email_institucional',
                'field_label' => __('Institutional Email', 'ffcertificate'),
                'field_type'  => 'text',
                'field_group' => self::GROUP_CONTACT,
                'profile_key' => null,
                'is_sensitive'=> 0,
                'mask'        => 'email',
                'required'    => 0,
                'options'     => null,
                'validation'  => array('format' => 'email'),
            ),
            array(
                'field_key'   => 'email_particular',
                'field_label' => __('Personal Email', 'ffcertificate'),
                'field_type'  => 'text',
                'field_group' => self::GROUP_CONTACT,
                'profile_key' => null,
                'is_sensitive'=> 0,
                'mask'        => 'email',
                'required'    => 0,
                'options'     => null,
                'validation'  => array('format' => 'email'),
            ),

            // ───── Schedule ─────
            array(
                'field_key'   => 'jornada',
                'field_label' => __('Work Schedule', 'ffcertificate'),
                'field_type'  => 'select',
                'field_group' => self::GROUP_SCHEDULE,
                'profile_key' => 'jornada',
                'is_sensitive'=> 0,
                'mask'        => null,
                'required'    => 1,
                'options'     => array('choices' => ReregistrationFieldOptions::get_jornada_options()),
                'validation'  => null,
            ),
            array(
                'field_key'   => 'horario_trabalho',
                'field_label' => __('Working Hours', 'ffcertificate'),
                'field_type'  => 'working_hours',
                'field_group' => self::GROUP_SCHEDULE,
                'profile_key' => null,
                'is_sensitive'=> 0,
                'mask'        => null,
                'required'    => 0,
                'options'     => null,
                'validation'  => null,
            ),

            // ───── Union ─────
            array(
                'field_key'   => 'sindicato',
                'field_label' => __('Union', 'ffcertificate'),
                'field_type'  => 'select',
                'field_group' => self::GROUP_UNION,
                'profile_key' => null,
                'is_sensitive'=> 0,
                'mask'        => null,
                'required'    => 0,
                'options'     => array('choices' => ReregistrationFieldOptions::get_sindicato_options()),
                'validation'  => null,
            ),

            // ───── Position Accumulation ─────
            array(
                'field_key'   => 'acumulo_cargos',
                'field_label' => __('Position Accumulation', 'ffcertificate'),
                'field_type'  => 'select',
                'field_group' => self::GROUP_ACCUMULATION,
                'profile_key' => null,
                'is_sensitive'=> 0,
                'mask'        => null,
                'required'    => 0,
                'options'     => array('choices' => ReregistrationFieldOptions::get_acumulo_options()),
                'validation'  => null,
            ),
            array(
                'field_key'   => 'jornada_acumulo',
                'field_label' => __('Accumulation Schedule', 'ffcertificate'),
                'field_type'  => 'select',
                'field_group' => self::GROUP_ACCUMULATION,
                'profile_key' => null,
                'is_sensitive'=> 0,
                'mask'        => null,
                'required'    => 0,
                'options'     => array('choices' => ReregistrationFieldOptions::get_jornada_options()),
                'validation'  => null,
            ),
            array(
                'field_key'   => 'cargo_funcao_acumulo',
                'field_label' => __('Current Position/Role', 'ffcertificate'),
                'field_type'  => 'text',
                'field_group' => self::GROUP_ACCUMULATION,
                'profile_key' => null,
                'is_sensitive'=> 0,
                'mask'        => null,
                'required'    => 0,
                'options'     => null,
                'validation'  => null,
            ),
            array(
                'field_key'   => 'horario_trabalho_acumulo',
                'field_label' => __('Accumulation Working Hours', 'ffcertificate'),
                'field_type'  => 'working_hours',
                'field_group' => self::GROUP_ACCUMULATION,
                'profile_key' => null,
                'is_sensitive'=> 0,
                'mask'        => null,
                'required'    => 0,
                'options'     => null,
                'validation'  => null,
            ),
        );
    }

    /**
     * Seed standard fields for a single audience.
     *
     * Idempotent: only inserts fields whose field_key does not already exist
     * for the given audience. Returns the number of fields inserted.
     *
     * @param int $audience_id Audience ID.
     * @return int Number of fields inserted.
     */
    public static function seed_for_audience(int $audience_id): int {
        if ($audience_id <= 0) {
            return 0;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ffc_custom_fields';

        // Get existing field_keys for this audience to avoid duplicates.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $existing_keys = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT field_key FROM %i WHERE audience_id = %d",
                $table,
                $audience_id
            )
        );

        $existing_keys = is_array($existing_keys) ? array_flip($existing_keys) : array();

        $definitions = self::get_standard_fields_definition();
        $inserted    = 0;

        foreach ($definitions as $index => $def) {
            if (isset($existing_keys[$def['field_key']])) {
                continue;
            }

            $insert_data = array(
                'audience_id'       => $audience_id,
                'field_key'         => $def['field_key'],
                'field_label'       => $def['field_label'],
                'field_type'        => $def['field_type'],
                'field_group'       => $def['field_group'],
                'field_source'      => 'standard',
                'field_profile_key' => $def['profile_key'],
                'field_mask'        => $def['mask'],
                'is_sensitive'      => (int) ($def['is_sensitive'] ?? 0),
                'field_options'     => $def['options'] !== null ? wp_json_encode($def['options']) : null,
                'validation_rules'  => $def['validation'] !== null ? wp_json_encode($def['validation']) : null,
                'sort_order'        => $index,
                'is_required'       => (int) ($def['required'] ?? 0),
                'is_active'         => 1,
            );

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $result = $wpdb->insert(
                $table,
                $insert_data,
                array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%d')
            );

            if ($result) {
                $inserted++;
            }
        }

        return $inserted;
    }

    /**
     * Seed standard fields for all existing audiences.
     *
     * @return int Total number of fields inserted across audiences.
     */
    public static function seed_all_existing_audiences(): int {
        if (!class_exists('\FreeFormCertificate\Audience\AudienceRepository')) {
            return 0;
        }

        global $wpdb;
        $audience_table = $wpdb->prefix . 'ffc_audiences';

        // Get all audience IDs directly to avoid status filters.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $ids = $wpdb->get_col(
            $wpdb->prepare("SELECT id FROM %i", $audience_table)
        );

        if (empty($ids)) {
            return 0;
        }

        $total = 0;
        foreach ($ids as $aud_id) {
            $total += self::seed_for_audience((int) $aud_id);
        }

        return $total;
    }
}
