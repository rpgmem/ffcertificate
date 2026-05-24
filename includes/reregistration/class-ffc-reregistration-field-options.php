<?php
/**
 * Reregistration Field Options
 *
 * Data provider for reregistration form field options:
 * - Gender, marital status, union, work schedule, position accumulation
 * - Division → Department mapping (DRE São Miguel MP org structure)
 * - Brazilian state abbreviations (UF)
 * - Default working hours template
 *
 * @package FreeFormCertificate\Reregistration
 * @since 4.12.8  Extracted from ReregistrationFrontend
 */

declare(strict_types=1);

namespace FreeFormCertificate\Reregistration;

use FreeFormCertificate\Settings\SettingsReader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reregistration Field Options.
 */
class ReregistrationFieldOptions {

	/**
	 * Divisão → Setor mapping.
	 *
	 * Reads the admin-editable map from `ffc_settings['divisao_setor_map']`
	 * (managed via Settings → Reregistration). Falls back to the hardcoded
	 * DRE São Miguel MP default when the option is absent or malformed —
	 * which is the state on a fresh install before the activator seed runs,
	 * and the safety net if the stored value is ever cleared.
	 *
	 * @return array<string, array<string>>
	 */
	public static function get_divisao_setor_map(): array {
		$map = SettingsReader::divisao_setor_map();
		return ( null !== $map && array() !== $map ) ? $map : self::get_default_divisao_setor_map();
	}

	/**
	 * Hardcoded Divisão → Setor default (DRE São Miguel MP org structure).
	 *
	 * Source of truth for the activator seed and the runtime fallback. Edit
	 * here only to change the shipped default; live installs override it via
	 * the admin-editable option.
	 *
	 * @return array<string, array<string>>
	 */
	public static function get_default_divisao_setor_map(): array {
		return array(
			'DRE - Gabinete'           => array( 'Assessoria', 'Diretor Regional' ),
			'DRE - DIAF'               => array(
				'Adiantamento',
				'Alimentação',
				'Almoxarifado',
				'Apoio',
				'Assessoria',
				'Bens',
				'Compras / Aquisições',
				'Concessionárias',
				'Contabilidade',
				'Contratos',
				'Demanda',
				'Diretor(a)',
				'Expediente',
				'Gestão Documental',
				'Jurídico',
				'NTIC',
				'Parcerias',
				'Prédios',
				'Protocolo',
				'PTRF',
				'TEG',
				'Terceirizadas',
			),
			'DRE - DIAFRH'             => array(
				'Adicional',
				'Aposentadoria',
				'Atribuição',
				'Averbação',
				'CAAC',
				'Cadastro',
				'Certidão de Tempo',
				'Diretor(a)',
				'Evolução Funcional',
				'Pagamento',
				'Posse',
				'Probatório',
				'Readaptação',
				'Rede Somos',
				'Vida Funcional',
			),
			'DRE - DICEU'              => array( 'Assessoria', 'DICEU', 'Diretor(a)' ),
			'DRE - DIPED'              => array( 'Assessoria', 'CEFAI', 'DIPED', 'Diretor(a)', 'Estágios', 'NAAPA' ),
			'DRE - Supervisão'         => array( 'Assessoria', 'Diretor(a)', 'Supervisão' ),
			'ESCOLA - Gestão'          => array( 'Assistente de Direção', 'Direção' ),
			'ESCOLA - Pedagógico'      => array( 'Coordenação Pedagógica', 'Professor(a)' ),
			'ESCOLA - Quadro de Apoio' => array( 'ATE' ),
		);
	}

	/**
	 * Sexo options.
	 *
	 * @return array<string>
	 */
	public static function get_sexo_options(): array {
		return array(
			__( 'Female', 'ffcertificate' ),
			__( 'Male', 'ffcertificate' ),
			__( 'Prefer not to say', 'ffcertificate' ),
		);
	}

	/**
	 * Estado civil options.
	 *
	 * @return array<string>
	 */
	public static function get_estado_civil_options(): array {
		return array(
			__( 'Married', 'ffcertificate' ),
			__( 'Divorced', 'ffcertificate' ),
			__( 'Legally separated', 'ffcertificate' ),
			__( 'Single', 'ffcertificate' ),
			__( 'Domestic partnership', 'ffcertificate' ),
			__( 'Widowed', 'ffcertificate' ),
		);
	}

	/**
	 * Sindicato options.
	 *
	 * @return array<string>
	 */
	public static function get_sindicato_options(): array {
		return array(
			__( 'NO UNION', 'ffcertificate' ),
			'APROFEM',
			'SINPEEM',
			'SINESP',
			'SINDISEP',
			__( 'OTHER', 'ffcertificate' ),
		);
	}

	/**
	 * Jornada options.
	 *
	 * @return array<string>
	 */
	public static function get_jornada_options(): array {
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
	public static function get_acumulo_options(): array {
		return array(
			__( 'I do not hold', 'ffcertificate' ),
			__( 'Pension (Payslip Attached)', 'ffcertificate' ),
			__( 'I hold', 'ffcertificate' ),
		);
	}

	/**
	 * UF (state) options.
	 *
	 * @return array<string>
	 */
	public static function get_uf_options(): array {
		return array(
			'AC',
			'AL',
			'AP',
			'AM',
			'BA',
			'CE',
			'DF',
			'ES',
			'GO',
			'MA',
			'MT',
			'MS',
			'MG',
			'PA',
			'PB',
			'PR',
			'PE',
			'PI',
			'RJ',
			'RN',
			'RS',
			'RO',
			'RR',
			'SC',
			'SP',
			'SE',
			'TO',
		);
	}

	/**
	 * Default working hours data (Mon–Fri).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_default_working_hours(): array {
		return array(
			array(
				'day'    => 1,
				'entry1' => '',
				'exit1'  => '',
				'entry2' => '',
				'exit2'  => '',
			),
			array(
				'day'    => 2,
				'entry1' => '',
				'exit1'  => '',
				'entry2' => '',
				'exit2'  => '',
			),
			array(
				'day'    => 3,
				'entry1' => '',
				'exit1'  => '',
				'entry2' => '',
				'exit2'  => '',
			),
			array(
				'day'    => 4,
				'entry1' => '',
				'exit1'  => '',
				'entry2' => '',
				'exit2'  => '',
			),
			array(
				'day'    => 5,
				'entry1' => '',
				'exit1'  => '',
				'entry2' => '',
				'exit2'  => '',
			),
		);
	}
}
