<?php
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
 * @package FreeFormCertificate\Reregistration
 * @since 5.0.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Reregistration;

use FreeFormCertificate\Audience\AudienceReader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reregistration Standard Fields Seeder.
 */
class ReregistrationStandardFieldsSeeder {

	/**
	 * Register WordPress hooks used by the seeder.
	 *
	 * Auto-seeds standard fields whenever a new audience is created.
	 *
	 * @since 5.0.0
	 * @return void
	 */
	public static function register(): void {
		add_action( 'ffc_audience_created', array( __CLASS__, 'on_audience_created' ), 10, 2 );
		// `admin_init` rather than the activator's `plugins_loaded` chain: the
		// new label is a translated string, and translations are loaded by
		// then. Default priority -- nothing else depends on the order.
		add_action( 'admin_init', array( __CLASS__, 'maybe_relabel_union' ) );
		// Same hook and reason as the union relabel (#2225).
		add_action( 'admin_init', array( __CLASS__, 'maybe_update_schedule_fields' ) );
		// Same hook and reason again: the new field's label is translated.
		add_action( 'admin_init', array( __CLASS__, 'maybe_add_edu_email_field' ) );
		// Same hook, so the seeded rows match the definition from one request on.
		add_action( 'admin_init', array( __CLASS__, 'maybe_validate_rf_fields' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_validate_cep_fields' ) );
		// Same hook: the form must ask for the CEP before the address it fills.
		add_action( 'admin_init', array( __CLASS__, 'maybe_move_cep_before_address' ) );
	}

	/**
	 * Option flagging that the union relabel has run.
	 */
	public const UNION_RELABEL_OPTION = 'ffc_union_label_relabelled';

	/**
	 * Relabel the `sindicato` fields seeded while "Union" translated as
	 * "Estado" (#1209 fixed the translation, not the rows).
	 *
	 * A field label is stored, so the fix to the catalogue reached only the
	 * audiences created after it. Only rows still carrying that exact label
	 * change: one an operator renamed is a deliberate choice and stays. Runs
	 * once; the flag is written even when nothing matched.
	 *
	 * @return void
	 */
	public static function maybe_relabel_union(): void {
		if ( get_option( self::UNION_RELABEL_OPTION ) ) {
			return;
		}

		$label = __( 'Union', 'ffcertificate' );
		foreach ( CustomFieldReader::standard_field_ids_with_label( 'sindicato', array( 'Estado' ) ) as $id ) {
			CustomFieldWriter::update( $id, array( 'field_label' => $label ) );
		}

		update_option( self::UNION_RELABEL_OPTION, '1', false );
	}

	/**
	 * Option flagging that the schedule-fields update has run.
	 */
	public const SCHEDULE_FIELDS_OPTION = 'ffc_schedule_fields_updated';

	/**
	 * The labels and the jornada options #2225 changed, applied to the rows
	 * seeded before it: `vinculo`, `jornada` and `horario_trabalho` are
	 * relabelled, and `jornada` / `jornada_acumulo` take the current options.
	 * A row is touched only while it still carries what was shipped -- the
	 * English source or its former pt_BR translation for a label, the former
	 * list for the options -- so a field an operator edited stays as it is.
	 * Runs once; the flag is written even when nothing matched.
	 *
	 * @return void
	 */
	public static function maybe_update_schedule_fields(): void {
		if ( get_option( self::SCHEDULE_FIELDS_OPTION ) ) {
			return;
		}

		$relabel = array(
			'vinculo'          => array( __( 'Employment Bond', 'ffcertificate' ), array( 'Contrato de Trabalho' ) ),
			'jornada'          => array( __( 'Workload or Category', 'ffcertificate' ), array( 'Work Schedule', 'Horário de Trabalho' ) ),
			'horario_trabalho' => array( __( 'Work Hours', 'ffcertificate' ), array( 'Working Hours', 'Horários de Funcionamento' ) ),
		);
		foreach ( $relabel as $field_key => $labels ) {
			$old = array_values( array_diff( $labels[1], array( $labels[0] ) ) );
			foreach ( CustomFieldReader::standard_field_ids_with_label( $field_key, $old ) as $id ) {
				CustomFieldWriter::update( $id, array( 'field_label' => $labels[0] ) );
			}
		}

		$current = ReregistrationFieldOptions::get_work_schedule_options();
		foreach ( array( 'jornada', 'jornada_acumulo' ) as $field_key ) {
			foreach ( CustomFieldReader::standard_field_options( $field_key ) as $id => $raw ) {
				$options = json_decode( $raw, true );
				if ( ! is_array( $options ) || ( $options['choices'] ?? null ) !== ReregistrationFieldOptions::LEGACY_WORK_SCHEDULE_OPTIONS ) {
					continue;
				}
				$options['choices'] = $current;
				CustomFieldWriter::update( $id, array( 'field_options' => $options ) );
			}
		}

		update_option( self::SCHEDULE_FIELDS_OPTION, '1', false );
	}

	/**
	 * Option flagging that the @edu e-mail field has been added.
	 */
	public const EDU_EMAIL_FIELD_OPTION = 'ffc_edu_email_field_added';

	/**
	 * Add the `email_institucional_edu` field to every audience that already
	 * carries the standard fields.
	 *
	 * `seed_for_audience()` runs when an audience is created, so a field added
	 * to the definition after that reaches no existing audience on its own.
	 * The new row takes the `sort_order` of the audience's @sme field and,
	 * having the higher id, lands right after it. An audience that already
	 * has the key is left alone. Runs once; the flag is written even when
	 * nothing matched.
	 *
	 * @return void
	 */
	public static function maybe_add_edu_email_field(): void {
		if ( get_option( self::EDU_EMAIL_FIELD_OPTION ) ) {
			return;
		}

		$definition = null;
		foreach ( self::get_standard_fields_definition() as $def ) {
			if ( 'email_institucional_edu' === $def['field_key'] ) {
				$definition = $def;
				break;
			}
		}

		if ( null !== $definition ) {
			foreach ( CustomFieldReader::standard_field_positions( 'email_institucional' ) as $audience_id => $sort_order ) {
				if ( isset( CustomFieldReader::existing_field_keys_for_audience( $audience_id )['email_institucional_edu'] ) ) {
					continue;
				}
				CustomFieldWriter::insert_row(
					array(
						'audience_id'       => $audience_id,
						'field_key'         => $definition['field_key'],
						'field_label'       => $definition['field_label'],
						'field_type'        => $definition['field_type'],
						'field_group'       => $definition['field_group'],
						'field_source'      => 'standard',
						'field_profile_key' => null,
						'field_mask'        => $definition['mask'],
						'is_sensitive'      => 0,
						'field_options'     => null,
						'validation_rules'  => (string) wp_json_encode( $definition['validation'] ),
						'sort_order'        => $sort_order,
						'is_required'       => 0,
						'is_active'         => 1,
					)
				);
			}
		}

		update_option( self::EDU_EMAIL_FIELD_OPTION, '1', false );
	}

	/**
	 * Option flagging that the seeded RF fields validate as an RF.
	 */
	public const RF_VALIDATION_OPTION = 'ffc_rf_field_validated';

	/**
	 * Give the seeded `rf` fields the RF format check (#2227).
	 *
	 * The field carried only an input mask, so a value of any length reached
	 * the record. A row an operator gave rules of its own keeps them; only one
	 * with none takes the format. Runs once; the flag is written even when
	 * nothing matched.
	 *
	 * @return void
	 */
	public static function maybe_validate_rf_fields(): void {
		if ( get_option( self::RF_VALIDATION_OPTION ) ) {
			return;
		}

		foreach ( CustomFieldReader::standard_field_ids_without_rules( 'rf' ) as $id ) {
			CustomFieldWriter::update( $id, array( 'validation_rules' => array( 'format' => 'rf' ) ) );
		}

		update_option( self::RF_VALIDATION_OPTION, '1', false );
	}

	/**
	 * Option flagging that the seeded CEP fields validate as a CEP.
	 */
	public const CEP_VALIDATION_OPTION = 'ffc_cep_field_validated';

	/**
	 * Give the seeded `cep` fields the offline CEP check (#2233).
	 *
	 * Same shape as {@see self::maybe_validate_rf_fields()}: a row an operator
	 * gave rules of its own keeps them; runs once, and the flag is written
	 * even when nothing matched.
	 *
	 * @return void
	 */
	public static function maybe_validate_cep_fields(): void {
		if ( get_option( self::CEP_VALIDATION_OPTION ) ) {
			return;
		}

		foreach ( CustomFieldReader::standard_field_ids_without_rules( 'cep' ) as $id ) {
			CustomFieldWriter::update( $id, array( 'validation_rules' => array( 'format' => 'cep' ) ) );
		}

		update_option( self::CEP_VALIDATION_OPTION, '1', false );
	}

	/**
	 * Option flagging that the CEP was moved before the address.
	 */
	public const CEP_ORDER_OPTION = 'ffc_cep_before_address';

	/**
	 * Put the `cep` field right before `endereco` in every audience (#2234).
	 *
	 * The address lookup fills the address from the CEP, so the CEP has to be
	 * typed first; it used to be seeded last in the contact group. Every
	 * audience that has both fields is reordered -- one an administrator
	 * reordered by hand included, by the maintainer's decision -- and an
	 * audience where `cep` already precedes `endereco` is left alone. Only the
	 * CEP moves: the fields between the two shift down by one, and the
	 * audience's sort orders are renumbered 0..n-1 in their existing order so
	 * a tie cannot hide the move. Runs once; the Record PDF is unaffected,
	 * because its template places each value by placeholder.
	 *
	 * @return void
	 */
	public static function maybe_move_cep_before_address(): void {
		if ( get_option( self::CEP_ORDER_OPTION ) ) {
			return;
		}

		foreach ( array_keys( CustomFieldReader::standard_field_positions( 'cep' ) ) as $audience_id ) {
			self::move_cep_before_address( (int) $audience_id );
		}

		update_option( self::CEP_ORDER_OPTION, '1', false );
	}

	/**
	 * Reorder one audience; true when anything moved.
	 *
	 * @param int $audience_id Audience ID.
	 * @return bool
	 */
	private static function move_cep_before_address( int $audience_id ): bool {
		$fields = CustomFieldReader::get_by_audience( $audience_id, false );
		$keys   = array_map( static fn( $field ) => (string) $field->field_key, $fields );

		$cep     = array_search( 'cep', $keys, true );
		$address = array_search( 'endereco', $keys, true );
		if ( false === $cep || false === $address || $cep < $address ) {
			return false;
		}

		$cep_field = $fields[ $cep ];
		array_splice( $fields, $cep, 1 );
		array_splice( $fields, $address, 0, array( $cep_field ) );

		foreach ( $fields as $index => $field ) {
			if ( (int) $field->sort_order !== $index ) {
				CustomFieldWriter::update( (int) $field->id, array( 'sort_order' => $index ) );
			}
		}

		return true;
	}

	/**
	 * Hook handler for `ffc_audience_created`.
	 *
	 * @since 5.0.0
	 * @param int                  $audience_id New audience ID.
	 * @param array<string, mixed> $data        Audience creation data.
	 * @return void
	 */
	public static function on_audience_created( int $audience_id, array $data = array() ): void {
		unset( $data ); // unused.
		if ( $audience_id <= 0 ) {
			return;
		}
		self::seed_for_audience( $audience_id );
	}

	/**
	 * Field groups used for the standard fields.
	 */
	public const GROUP_PERSONAL       = 'personal';
	public const GROUP_CONTACT        = 'contact';
	public const GROUP_SCHEDULE       = 'schedule';
	public const GROUP_ACCUMULATION   = 'accumulation';
	public const GROUP_UNION          = 'union';
	public const GROUP_ACKNOWLEDGMENT = 'acknowledgment';

	/**
	 * Profile key of every standard field that declares one.
	 *
	 * The same pairs `get_standard_fields_definition()` carries, without its
	 * translated labels: `ReregistrationActivator::maybe_migrate()` fills the
	 * key on rows seeded before a definition gained it, and that runs on
	 * `plugins_loaded`, before translations may load, so it must not call
	 * `__()` (#1538). `ReregistrationStandardFieldsSeederTest` fails when the
	 * two disagree.
	 *
	 * @var array<string, string>
	 */
	public const PROFILE_KEYS = array(
		'display_name'    => 'display_name',
		'rf'              => 'rf',
		'data_nascimento' => 'birth_date',
		'cpf'             => 'cpf',
		'rg'              => 'rg',
		'divisao_setor'   => 'divisao_setor',
		'phone'           => 'phone',
		'celular'         => 'celular',
		'jornada'         => 'jornada',
	);

	/**
	 * Get the ordered list of groups with translated labels.
	 *
	 * @return array<string, string>
	 */
	public static function get_group_labels(): array {
		return array(
			self::GROUP_PERSONAL       => __( 'Personal Data', 'ffcertificate' ),
			self::GROUP_CONTACT        => __( 'Contact Information', 'ffcertificate' ),
			self::GROUP_SCHEDULE       => __( 'Work Schedule', 'ffcertificate' ),
			self::GROUP_ACCUMULATION   => __( 'Position Accumulation', 'ffcertificate' ),
			self::GROUP_UNION          => __( 'Union', 'ffcertificate' ),
			self::GROUP_ACKNOWLEDGMENT => __( 'Acknowledgment', 'ffcertificate' ),
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
		$divisao_map = ReregistrationFieldOptions::get_default_division_sector_map();

		return array(
			// ───── Personal Data ─────.
			array(
				'field_key'    => 'display_name',
				'field_label'  => __( 'Name', 'ffcertificate' ),
				'field_type'   => 'text',
				'field_group'  => self::GROUP_PERSONAL,
				'profile_key'  => 'display_name',
				'is_sensitive' => 0,
				'mask'         => null,
				'required'     => 1,
				'options'      => null,
				'validation'   => null,
			),
			array(
				'field_key'    => 'sexo',
				'field_label'  => __( 'Sex', 'ffcertificate' ),
				'field_type'   => 'select',
				'field_group'  => self::GROUP_PERSONAL,
				'profile_key'  => null,
				'is_sensitive' => 0,
				'mask'         => null,
				'required'     => 1,
				'options'      => array( 'choices' => ReregistrationFieldOptions::get_gender_options() ),
				'validation'   => null,
			),
			array(
				'field_key'    => 'estado_civil',
				'field_label'  => __( 'Marital Status', 'ffcertificate' ),
				'field_type'   => 'select',
				'field_group'  => self::GROUP_PERSONAL,
				'profile_key'  => null,
				'is_sensitive' => 0,
				'mask'         => null,
				'required'     => 1,
				'options'      => array( 'choices' => ReregistrationFieldOptions::get_marital_status_options() ),
				'validation'   => null,
			),
			array(
				'field_key'    => 'rf',
				'field_label'  => __( 'Functional Registry (RF)', 'ffcertificate' ),
				'field_type'   => 'text',
				'field_group'  => self::GROUP_PERSONAL,
				'profile_key'  => 'rf',
				'is_sensitive' => 1,
				'mask'         => 'rf',
				'required'     => 1,
				'options'      => null,
				'validation'   => array( 'format' => 'rf' ),
			),
			array(
				'field_key'    => 'vinculo',
				'field_label'  => __( 'Employment Bond', 'ffcertificate' ),
				'field_type'   => 'text',
				'field_group'  => self::GROUP_PERSONAL,
				'profile_key'  => null,
				'is_sensitive' => 0,
				'mask'         => 'number',
				'required'     => 0,
				'options'      => array( 'maxlength' => 2 ),
				'validation'   => null,
			),
			array(
				'field_key'    => 'data_nascimento',
				'field_label'  => __( 'Date of Birth', 'ffcertificate' ),
				'field_type'   => 'date',
				'field_group'  => self::GROUP_PERSONAL,
				'profile_key'  => 'birth_date',
				'is_sensitive' => 0,
				'mask'         => null,
				'required'     => 1,
				'options'      => null,
				'validation'   => null,
			),
			array(
				'field_key'    => 'cpf',
				'field_label'  => __( 'CPF/CIN', 'ffcertificate' ),
				'field_type'   => 'text',
				'field_group'  => self::GROUP_PERSONAL,
				'profile_key'  => 'cpf',
				'is_sensitive' => 1,
				'mask'         => 'cpf',
				'required'     => 1,
				'options'      => null,
				'validation'   => array( 'format' => 'cpf' ),
			),
			array(
				'field_key'    => 'rg',
				'field_label'  => __( 'RG', 'ffcertificate' ),
				'field_type'   => 'text',
				'field_group'  => self::GROUP_PERSONAL,
				'profile_key'  => 'rg',
				'is_sensitive' => 1,
				'mask'         => 'cin',
				'required'     => 0,
				'options'      => null,
				'validation'   => null,
			),
			array(
				'field_key'    => 'unidade_lotacao',
				'field_label'  => __( 'Assignment Unit', 'ffcertificate' ),
				'field_type'   => 'text',
				'field_group'  => self::GROUP_PERSONAL,
				'profile_key'  => null,
				'is_sensitive' => 0,
				'mask'         => null,
				'required'     => 0,
				'options'      => null,
				'validation'   => null,
			),
			array(
				'field_key'    => 'unidade_exercicio',
				'field_label'  => __( 'Working Unit', 'ffcertificate' ),
				'field_type'   => 'text',
				'field_group'  => self::GROUP_PERSONAL,
				'profile_key'  => null,
				'is_sensitive' => 0,
				'mask'         => null,
				'required'     => 0,
				'options'      => array( 'default' => 'DRE MP' ),
				'validation'   => null,
			),
			array(
				'field_key'    => 'divisao_setor',
				'field_label'  => __( 'Division / Department', 'ffcertificate' ),
				'field_type'   => 'dependent_select',
				'field_group'  => self::GROUP_PERSONAL,
				'profile_key'  => 'divisao_setor',
				'is_sensitive' => 0,
				'mask'         => null,
				'required'     => 1,
				'options'      => array(
					'groups'       => $divisao_map,
					'parent_label' => __( 'Division', 'ffcertificate' ),
					'child_label'  => __( 'Department', 'ffcertificate' ),
				),
				'validation'   => null,
			),

			// ───── Contact Information ─────.
			array(
				'field_key'    => 'cep',
				'field_label'  => __( 'Zip Code', 'ffcertificate' ),
				'field_type'   => 'text',
				'field_group'  => self::GROUP_CONTACT,
				'profile_key'  => null,
				'is_sensitive' => 0,
				'mask'         => 'cep',
				'required'     => 0,
				'options'      => null,
				'validation'   => array( 'format' => 'cep' ),
			),
			array(
				'field_key'    => 'endereco',
				'field_label'  => __( 'Address', 'ffcertificate' ),
				'field_type'   => 'text',
				'field_group'  => self::GROUP_CONTACT,
				'profile_key'  => null,
				'is_sensitive' => 0,
				'mask'         => null,
				'required'     => 1,
				'options'      => null,
				'validation'   => null,
			),
			array(
				'field_key'    => 'endereco_numero',
				'field_label'  => __( 'Number', 'ffcertificate' ),
				'field_type'   => 'text',
				'field_group'  => self::GROUP_CONTACT,
				'profile_key'  => null,
				'is_sensitive' => 0,
				'mask'         => null,
				'required'     => 1,
				'options'      => null,
				'validation'   => null,
			),
			array(
				'field_key'    => 'endereco_complemento',
				'field_label'  => __( 'Address Complement', 'ffcertificate' ),
				'field_type'   => 'text',
				'field_group'  => self::GROUP_CONTACT,
				'profile_key'  => null,
				'is_sensitive' => 0,
				'mask'         => null,
				'required'     => 0,
				'options'      => null,
				'validation'   => null,
			),
			array(
				'field_key'    => 'bairro',
				'field_label'  => __( 'Neighborhood', 'ffcertificate' ),
				'field_type'   => 'text',
				'field_group'  => self::GROUP_CONTACT,
				'profile_key'  => null,
				'is_sensitive' => 0,
				'mask'         => null,
				'required'     => 0,
				'options'      => null,
				'validation'   => null,
			),
			array(
				'field_key'    => 'cidade',
				'field_label'  => __( 'City', 'ffcertificate' ),
				'field_type'   => 'text',
				'field_group'  => self::GROUP_CONTACT,
				'profile_key'  => null,
				'is_sensitive' => 0,
				'mask'         => null,
				'required'     => 0,
				'options'      => array( 'default' => 'SÃO PAULO' ),
				'validation'   => null,
			),
			array(
				'field_key'    => 'uf',
				'field_label'  => __( 'State', 'ffcertificate' ),
				'field_type'   => 'select',
				'field_group'  => self::GROUP_CONTACT,
				'profile_key'  => null,
				'is_sensitive' => 0,
				'mask'         => null,
				'required'     => 0,
				'options'      => array(
					'choices' => ReregistrationFieldOptions::get_state_options(),
					'default' => 'SP',
				),
				'validation'   => null,
			),
			array(
				'field_key'    => 'phone',
				'field_label'  => __( 'Home Phone', 'ffcertificate' ),
				'field_type'   => 'text',
				'field_group'  => self::GROUP_CONTACT,
				'profile_key'  => 'phone',
				'is_sensitive' => 0,
				'mask'         => 'phone',
				'required'     => 0,
				'options'      => null,
				'validation'   => array( 'format' => 'phone' ),
			),
			array(
				'field_key'    => 'celular',
				'field_label'  => __( 'Cell Phone', 'ffcertificate' ),
				'field_type'   => 'text',
				'field_group'  => self::GROUP_CONTACT,
				'profile_key'  => 'celular',
				'is_sensitive' => 0,
				'mask'         => 'phone',
				'required'     => 1,
				'options'      => null,
				'validation'   => array( 'format' => 'phone' ),
			),
			array(
				'field_key'    => 'contato_emergencia',
				'field_label'  => __( 'Emergency Contact Name', 'ffcertificate' ),
				'field_type'   => 'text',
				'field_group'  => self::GROUP_CONTACT,
				'profile_key'  => null,
				'is_sensitive' => 0,
				'mask'         => null,
				'required'     => 1,
				'options'      => null,
				'validation'   => null,
			),
			array(
				'field_key'    => 'tel_emergencia',
				'field_label'  => __( 'Emergency Contact Phone', 'ffcertificate' ),
				'field_type'   => 'text',
				'field_group'  => self::GROUP_CONTACT,
				'profile_key'  => null,
				'is_sensitive' => 0,
				'mask'         => 'phone',
				'required'     => 1,
				'options'      => null,
				'validation'   => array( 'format' => 'phone' ),
			),
			array(
				'field_key'    => 'email_institucional',
				'field_label'  => __( 'Institutional Email (@sme)', 'ffcertificate' ),
				'field_type'   => 'text',
				'field_group'  => self::GROUP_CONTACT,
				'profile_key'  => null,
				'is_sensitive' => 0,
				'mask'         => 'email',
				'required'     => 1,
				'options'      => null,
				'validation'   => array( 'format' => 'email' ),
			),
			array(
				'field_key'    => 'email_institucional_edu',
				'field_label'  => __( 'Institutional Email (@edu)', 'ffcertificate' ),
				'field_type'   => 'text',
				'field_group'  => self::GROUP_CONTACT,
				'profile_key'  => null,
				'is_sensitive' => 0,
				'mask'         => 'email',
				'required'     => 0,
				'options'      => null,
				'validation'   => array( 'format' => 'email' ),
			),
			array(
				'field_key'    => 'email_particular',
				'field_label'  => __( 'Personal Email', 'ffcertificate' ),
				'field_type'   => 'text',
				'field_group'  => self::GROUP_CONTACT,
				'profile_key'  => null,
				'is_sensitive' => 0,
				'mask'         => 'email',
				'required'     => 0,
				'options'      => null,
				'validation'   => array( 'format' => 'email' ),
			),

			// ───── Schedule ─────.
			array(
				'field_key'    => 'jornada',
				'field_label'  => __( 'Workload or Category', 'ffcertificate' ),
				'field_type'   => 'select',
				'field_group'  => self::GROUP_SCHEDULE,
				'profile_key'  => 'jornada',
				'is_sensitive' => 0,
				'mask'         => null,
				'required'     => 1,
				'options'      => array( 'choices' => ReregistrationFieldOptions::get_work_schedule_options() ),
				'validation'   => null,
			),
			array(
				'field_key'    => 'horario_trabalho',
				'field_label'  => __( 'Work Hours', 'ffcertificate' ),
				'field_type'   => 'working_hours',
				'field_group'  => self::GROUP_SCHEDULE,
				'profile_key'  => null,
				'is_sensitive' => 0,
				'mask'         => null,
				'required'     => 0,
				'options'      => null,
				'validation'   => null,
			),

			// ───── Union ─────.
			array(
				'field_key'    => 'sindicato',
				'field_label'  => __( 'Union', 'ffcertificate' ),
				'field_type'   => 'select',
				'field_group'  => self::GROUP_UNION,
				'profile_key'  => null,
				'is_sensitive' => 0,
				'mask'         => null,
				'required'     => 1,
				'options'      => array( 'choices' => ReregistrationFieldOptions::get_union_options() ),
				'validation'   => null,
			),

			// ───── Position Accumulation ─────.
			array(
				'field_key'    => 'acumulo_cargos',
				'field_label'  => __( 'Position Accumulation', 'ffcertificate' ),
				'field_type'   => 'select',
				'field_group'  => self::GROUP_ACCUMULATION,
				'profile_key'  => null,
				'is_sensitive' => 0,
				'mask'         => null,
				'required'     => 0,
				'options'      => array( 'choices' => ReregistrationFieldOptions::get_dual_post_options() ),
				'validation'   => null,
			),
			array(
				'field_key'    => 'jornada_acumulo',
				'field_label'  => __( 'Accumulation Schedule', 'ffcertificate' ),
				'field_type'   => 'select',
				'field_group'  => self::GROUP_ACCUMULATION,
				'profile_key'  => null,
				'is_sensitive' => 0,
				'mask'         => null,
				'required'     => 0,
				'options'      => array( 'choices' => ReregistrationFieldOptions::get_work_schedule_options() ),
				'validation'   => null,
			),
			array(
				'field_key'    => 'cargo_funcao_acumulo',
				'field_label'  => __( 'Current Position/Role', 'ffcertificate' ),
				'field_type'   => 'text',
				'field_group'  => self::GROUP_ACCUMULATION,
				'profile_key'  => null,
				'is_sensitive' => 0,
				'mask'         => null,
				'required'     => 0,
				'options'      => null,
				'validation'   => null,
			),
			array(
				'field_key'    => 'horario_trabalho_acumulo',
				'field_label'  => __( 'Accumulation Working Hours', 'ffcertificate' ),
				'field_type'   => 'working_hours',
				'field_group'  => self::GROUP_ACCUMULATION,
				'profile_key'  => null,
				'is_sensitive' => 0,
				'mask'         => null,
				'required'     => 0,
				'options'      => null,
				'validation'   => null,
			),

			// ───── Acknowledgment ─────.
			// Display-only rich-text block (no user input). The notice HTML
			// lives in field_options['html']; admins edit it per-audience in
			// the Custom Fields editor and "Replicate to children" propagates
			// it like any other standard field's options.
			array(
				'field_key'    => 'acknowledgment',
				'field_label'  => __( 'Acknowledgment', 'ffcertificate' ),
				'field_type'   => 'acknowledgment',
				'field_group'  => self::GROUP_ACKNOWLEDGMENT,
				'profile_key'  => null,
				'is_sensitive' => 0,
				'mask'         => null,
				'required'     => 0,
				'options'      => array( 'html' => ReregistrationFieldOptions::get_default_acknowledgment_html() ),
				'validation'   => null,
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
	public static function seed_for_audience( int $audience_id ): int {
		if ( $audience_id <= 0 ) {
			return 0;
		}

		$existing_keys = CustomFieldReader::existing_field_keys_for_audience( $audience_id );

		$definitions = self::get_standard_fields_definition();
		$inserted    = 0;

		foreach ( $definitions as $index => $def ) {
			if ( isset( $existing_keys[ $def['field_key'] ] ) ) {
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
				'is_sensitive'      => (int) ( $def['is_sensitive'] ?? 0 ),
				'field_options'     => null !== $def['options'] ? wp_json_encode( $def['options'] ) : null,
				'validation_rules'  => null !== $def['validation'] ? wp_json_encode( $def['validation'] ) : null,
				'sort_order'        => $index,
				'is_required'       => (int) ( $def['required'] ?? 0 ),
				'is_active'         => 1,
			);

			$result = CustomFieldWriter::insert_row( $insert_data );

			if ( false !== $result ) {
				++$inserted;
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
		if ( ! class_exists( '\FreeFormCertificate\Audience\AudienceReader' ) ) {
			return 0;
		}

		$ids = \FreeFormCertificate\Audience\AudienceReader::get_all_ids();
		if ( empty( $ids ) ) {
			return 0;
		}

		$total = 0;
		foreach ( $ids as $aud_id ) {
			$total += self::seed_for_audience( $aud_id );
		}

		return $total;
	}

	/**
	 * Replicate a parent audience's standard-field option lists down to all
	 * its descendants (children + grandchildren — the 3-level hierarchy).
	 *
	 * Copies `field_options` for every STANDARD field on the parent that has
	 * options set (e.g. `divisao_setor` groups, `sindicato` / `jornada`
	 * choices) onto each descendant's matching field (by `field_key`). This
	 * is the "Replicate to children" action — an explicit, deliberate push
	 * that overwrites the descendants' current option lists. Manual
	 * per-descendant fine-tuning survives until the next replicate.
	 *
	 * Custom (non-standard) fields are not touched — they're audience-local
	 * by definition.
	 *
	 * @param int $parent_audience_id Audience whose options are the source.
	 * @return int Number of descendant field rows updated.
	 */
	public static function replicate_field_options_to_descendants( int $parent_audience_id ): int {
		if ( $parent_audience_id <= 0 ) {
			return 0;
		}

		// Snapshot the parent's standard-field options, keyed by field_key.
		$parent_options = array();
		foreach ( CustomFieldReader::get_by_audience( $parent_audience_id, false ) as $pf ) {
			if ( 'standard' === ( $pf->field_source ?? '' ) && null !== $pf->field_options ) {
				$decoded = json_decode( (string) $pf->field_options, true );
				if ( is_array( $decoded ) ) {
					$parent_options[ (string) $pf->field_key ] = $decoded;
				}
			}
		}

		if ( empty( $parent_options ) ) {
			return 0;
		}

		$updated = 0;
		foreach ( AudienceReader::get_descendant_ids( $parent_audience_id ) as $aud_id ) {
			foreach ( $parent_options as $field_key => $options ) {
				$field = CustomFieldReader::get_by_key( (int) $aud_id, $field_key );
				if ( null === $field ) {
					continue;
				}
				if ( CustomFieldWriter::update( (int) $field->id, array( 'field_options' => $options ) ) ) {
					++$updated;
				}
			}
		}

		return $updated;
	}
}
