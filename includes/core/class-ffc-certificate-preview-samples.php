<?php
/**
 * Certificate Preview Sample Data
 *
 * Canonical placeholder → sample-value map used to render every
 * certificate preview. This is the single source of truth: the map is
 * surfaced to both preview paths — the admin form-editor preview
 * (localized as `ffc_ajax.previewSamples`) and the public CSV-download
 * preview (in the `ajax_cert_preview` payload) — so neither browser-side
 * preview re-declares its own list or drifts from the placeholders the
 * real generators (`PdfGenerator`, `FichaGenerator`, appointment receipts)
 * actually fill.
 *
 * Live-editable values that PHP can't know at enqueue time — the form
 * title and custom builder field names — are overlaid client-side;
 * everything else originates here.
 *
 * @package FreeFormCertificate\Core
 * @since   6.7.8
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides the canonical placeholder sample map for the preview.
 */
class CertificatePreviewSamples {

	/**
	 * Build the placeholder → sample-value map.
	 *
	 * Keys mirror every `{{placeholder}}` the bundled HTML templates use
	 * (certificate, declaration, internship, ficha / atestado, appointment
	 * receipt) plus the system placeholders the generators inject. Two
	 * placeholder families are intentionally absent because the JS handles
	 * them specially: `{{qr_code…}}` (rendered as a placeholder SVG) and
	 * `{{validation_url…}}` (rendered as a sample link).
	 *
	 * When `$form_id` is provided the `schedule` / `schedule_total` slots
	 * are resolved from that form's geofence config (Class Schedule first,
	 * then Time Range) instead of the hardcoded fallback — mirrors
	 * {@see PdfGenerator::resolve_effective_schedule()} so the preview
	 * reflects what the real PDF would render for a submission on this
	 * form. Forms without either time range fall back to the sample
	 * `08:00 – 17:30` shown in the variables docs.
	 *
	 * @param int|null $form_id Optional form post ID for per-form schedule lookup.
	 * @return array<string, string> Placeholder name (no braces) → sample value.
	 */
	public static function get_map( ?int $form_id = null ): array {
		$now      = time();
		$today    = DateFormatter::format_date( $now, 'pdf' );
		$now_full = DateFormatter::format_datetime( $now, 'pdf' );

		list( $schedule, $schedule_total ) = self::resolve_schedule_sample( $form_id );

		return array(
			// Identity.
			'name'                     => 'João da Silva',
			'display_name'             => 'João da Silva',
			'email'                    => 'joao.silva@example.com',
			'email_institucional'      => 'joao.silva@sme.prefeitura.sp.gov.br',
			'email_particular'         => 'joao.silva@gmail.com',
			'cpf'                      => '123.456.789-00',
			'cpf_rf'                   => '123.456.789-00',
			'rf'                       => '1234567-8',
			'rg'                       => '12.345.678-9',
			'data_nascimento'          => $today,
			'sexo'                     => 'Masculino',
			'estado_civil'             => 'Solteiro(a)',

			// Contact.
			'phone'                    => '(11) 98765-4321',
			'celular'                  => '(11) 98765-4321',
			'contato_emergencia'       => 'Maria da Silva',
			'tel_emergencia'           => '(11) 91234-5678',

			// Address.
			'endereco'                 => 'Rua Exemplo',
			'endereco_numero'          => '123',
			'endereco_complemento'     => 'Apto 45',
			'bairro'                   => 'Centro',
			'cidade'                   => 'São Paulo',
			'uf'                       => 'SP',
			'cep'                      => '01001-000',
			'main_address'             => 'Rua Exemplo, 123 - Centro, São Paulo/SP',

			// Employment / school context (ficha / atestado).
			'vinculo'                  => 'Efetivo',
			'cargo_funcao_acumulo'     => 'Professor de Educação Básica',
			'acumulo_cargos'           => 'Não',
			'jornada'                  => 'JEIF (40h)',
			'jornada_acumulo'          => 'J-30',
			'horario_trabalho'         => '08:00 às 17:00',
			'horario_trabalho_acumulo' => '18:00 às 22:00',
			'unidade_lotacao'          => 'EMEF Exemplo',
			'unidade_exercicio'        => 'EMEF Exemplo',
			'divisao'                  => 'DRE Exemplo',
			'setor'                    => 'Setor Pedagógico',
			'sindicato'                => 'SINPEEM',
			'program'                  => 'Programa de Exemplo',

			// Authentication / validation.
			'auth_code'                => 'A1B2-C3D4-E5F6',
			'validation_code'          => 'V1W2-X3Y4-Z5A6',
			'magic_token'              => 'abc123def456ghi789jkl012',
			'ticket'                   => 'TK01-AB2C-3D4E',

			// Form / submission metadata.
			'form_title'               => 'Título do Certificado',
			'site_name'                => get_bloginfo( 'name' ),
			'submission_id'            => '1234',
			'submission_date'          => $today,
			'submitted_at'             => $now_full,
			'created_at'               => $now_full,
			'fill_date'                => $today,
			'print_date'               => $today,
			'date'                     => $today,
			'reference_year'           => (string) (int) gmdate( 'Y', $now ),
			'status'                   => 'Confirmado',

			// Appointment receipt.
			'calendar_title'           => 'Atendimento de Exemplo',
			'appointment_date'         => $today,
			'appointment_time'         => '09:00',

			// Effective wall-clock schedule (resolved by PdfGenerator via
			// per-submission "Schedule Exception" → form-level Class
			// Schedule → form Time Range — see #366 Sprint 7). When a
			// form ID is passed to get_map(), self::resolve_schedule_sample()
			// reads the same Class/Time configuration the generator does,
			// so the preview reflects the operator's actual setting instead
			// of a static value (#bug-schedule). Sample fallback matches
			// the §2 docs row.
			'schedule'                 => $schedule,
			'schedule_total'           => $schedule_total,
		);
	}

	/**
	 * Look up `{{schedule}}` / `{{schedule_total}}` sample values from the
	 * given form's geofence config — the same chain
	 * {@see PdfGenerator::resolve_effective_schedule()} uses on real PDF
	 * renders. Falls back to the docs-row sample when no form ID is
	 * passed or the form has no time range configured at all.
	 *
	 * @param int|null $form_id Form post ID, or null to skip the lookup.
	 * @return array{0: string, 1: string} [schedule, schedule_total].
	 */
	private static function resolve_schedule_sample( ?int $form_id ): array {
		if ( null === $form_id || $form_id <= 0 ) {
			return array( '08:00 – 17:30', '9h 30min' );
		}

		$geofence = get_post_meta( $form_id, '_ffc_geofence_config', true );
		if ( ! is_array( $geofence ) ) {
			return array( '08:00 – 17:30', '9h 30min' );
		}

		$start = '';
		if ( ! empty( $geofence['class_time_start'] ) ) {
			$start = (string) $geofence['class_time_start'];
		} elseif ( ! empty( $geofence['time_start'] ) ) {
			$start = (string) $geofence['time_start'];
		}

		$end = '';
		if ( ! empty( $geofence['class_time_end'] ) ) {
			$end = (string) $geofence['class_time_end'];
		} elseif ( ! empty( $geofence['time_end'] ) ) {
			$end = (string) $geofence['time_end'];
		}

		if ( '' === $start || '' === $end ) {
			return array( '08:00 – 17:30', '9h 30min' );
		}

		return array(
			DateFormatter::format_schedule( $start, $end ),
			DateFormatter::format_schedule_total( $start, $end ),
		);
	}
}
