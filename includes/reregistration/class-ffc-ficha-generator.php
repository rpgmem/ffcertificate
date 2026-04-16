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

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FichaGenerator {

	/**
	 * Generate ficha data for a submission.
	 *
	 * @param int $submission_id Submission ID.
	 * @return array<string, mixed>|null Null on failure.
	 */
	public static function generate_ficha_data( int $submission_id ): ?array {
		$submission = ReregistrationSubmissionRepository::get_by_id( $submission_id );
		if ( ! $submission ) {
			return null;
		}

		$rereg = ReregistrationRepository::get_by_id( (int) $submission->reregistration_id );
		if ( ! $rereg ) {
			return null;
		}

		$user = get_userdata( (int) $submission->user_id );
		if ( ! $user ) {
			return null;
		}

		// Get submission data (unified dynamic shape).
		$sub_data   = $submission->data ? json_decode( $submission->data, true ) : array();
		$raw_values = is_array( $sub_data['fields'] ?? null ) ? $sub_data['fields'] : array();

		// Get field definitions for all audiences linked to this reregistration.
		$all_fields = self::get_custom_fields_for_reregistration( $rereg );

		// Decrypt sensitive values and split by field_source.
		$decrypted_values = self::decrypt_field_values( $all_fields, $raw_values );
		$standard_fields  = array();
		$custom_fields    = array();
		foreach ( $all_fields as $field ) {
			$src = isset( $field->field_source ) ? (string) $field->field_source : 'custom';
			if ( $src === 'standard' ) {
				$standard_fields[] = $field;
			} else {
				$custom_fields[] = $field;
			}
		}

		// Status labels (centralized in SubmissionRepository)
		$status_labels = ReregistrationSubmissionRepository::get_status_labels();

		// Date formatting
		$date_format = get_option( 'date_format' );
		$time_format = get_option( 'time_format' );

		$submitted_at = '';
		if ( ! empty( $submission->submitted_at ) ) {
			$submitted_at = date_i18n( $date_format . ' ' . $time_format, strtotime( $submission->submitted_at ) );
		}

		// Check if user has acúmulo de cargos
		$acumulo_value = $decrypted_values['acumulo_cargos'] ?? __( 'I do not hold', 'ffcertificate' );
		$has_acumulo   = $acumulo_value === __( 'I hold', 'ffcertificate' );

		// Build template variables: framework keys + every standard field by field_key.
		$variables = array(
			'reregistration_title' => $rereg->title,
			'audience_name'        => $rereg->audience_name ?? '',
			'submission_status'    => $status_labels[ $submission->status ] ?? $submission->status,
			'submitted_at'         => $submitted_at,
			'email'                => $user->user_email,
			'site_name'            => get_bloginfo( 'name' ),
			'generation_date'      => wp_date( $date_format . ' ' . $time_format ),
		);

		foreach ( $standard_fields as $field ) {
			$key   = (string) $field->field_key;
			$value = $decrypted_values[ $key ] ?? '';

			// Hide accumulation-related fields unless the user declared they hold another job.
			if ( ! $has_acumulo && in_array( $key, array( 'jornada_acumulo', 'cargo_funcao_acumulo', 'horario_trabalho_acumulo' ), true ) ) {
				$variables[ $key ] = '';
				continue;
			}

			$variables[ $key ] = self::format_field_value( $field, $value );
		}

		// Sensible defaults for framework-level keys.
		if ( empty( $variables['display_name'] ) ) {
			$variables['display_name'] = $user->display_name;
		}
		if ( empty( $variables['email_institucional'] ) ) {
			$variables['email_institucional'] = $user->user_email;
		}

		/**
		 * Filters ficha template variables before HTML generation.
		 *
		 * @since 4.11.0
		 * @param array  $variables     Template variables.
		 * @param int    $submission_id Submission ID.
		 * @param object $submission    Submission object.
		 * @param object $rereg         Reregistration object.
		 */
		$variables = apply_filters( 'ffcertificate_ficha_data', $variables, $submission_id, $submission, $rereg );

		// Build custom fields section HTML (only non-standard fields).
		$custom_section = self::build_custom_fields_section( $custom_fields, $decrypted_values );

		// Load template
		$template = self::load_template();

		// Replace placeholders
		foreach ( $variables as $key => $value ) {
			if ( is_array( $value ) ) {
				$value = implode( ', ', $value );
			}
			$template = str_replace( '{{' . $key . '}}', wp_kses( $value, \FreeFormCertificate\Core\Utils::get_allowed_html_tags() ), $template );
		}

		// Replace custom fields section
		$template = str_replace( '{{custom_fields_section}}', $custom_section, $template );

		// Fix relative URLs
		$site_url = untrailingslashit( get_home_url() );
		$template = preg_replace( '/(src|href|background)=["\']\/([^"\']+)["\']/i', '$1="' . $site_url . '/$2"', $template );

		/**
		 * Filters the generated ficha HTML.
		 *
		 * @since 4.11.0
		 * @param string $template      Generated HTML.
		 * @param array  $variables     Template variables.
		 * @param int    $submission_id Submission ID.
		 */
		$html = apply_filters( 'ffcertificate_ficha_html', $template, $variables, $submission_id );

		// Generate filename
		$safe_title = sanitize_file_name( $rereg->title );
		if ( empty( $safe_title ) ) {
			$safe_title = __( 'Record', 'ffcertificate' );
		}
		$safe_name = sanitize_file_name( $user->display_name );
		$filename  = 'Ficha_' . $safe_title . '_' . $safe_name . '.pdf';

		/**
		 * Filters the ficha PDF filename.
		 *
		 * @since 4.11.0
		 * @param string $filename      Generated filename.
		 * @param int    $submission_id Submission ID.
		 * @param object $submission    Submission object.
		 */
		$filename = apply_filters( 'ffcertificate_ficha_filename', $filename, $submission_id, $submission );

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
	private static function format_working_hours( string $json_or_empty ): string {
		if ( empty( $json_or_empty ) || $json_or_empty === '[]' ) {
			return '';
		}
		$wh = json_decode( $json_or_empty, true );
		if ( ! is_array( $wh ) || empty( $wh ) ) {
			return '';
		}
		$days_map = array(
			0 => __( 'Sun', 'ffcertificate' ),
			1 => __( 'Mon', 'ffcertificate' ),
			2 => __( 'Tue', 'ffcertificate' ),
			3 => __( 'Wed', 'ffcertificate' ),
			4 => __( 'Thu', 'ffcertificate' ),
			5 => __( 'Fri', 'ffcertificate' ),
			6 => __( 'Sat', 'ffcertificate' ),
		);

		$cell  = 'style="padding:1px 4px;border:1px solid #ccc;text-align:center;font-size:7.5pt"';
		$hcell = 'style="padding:1px 4px;border:1px solid #ccc;text-align:center;font-size:7.5pt;font-weight:bold;background:#f0f4f8;color:#000"';

		$html  = '<table style="width:100%;border-collapse:collapse;margin-top:2px" role="presentation">';
		$html .= '<tr>';
		$html .= '<th ' . $hcell . '>' . esc_html__( 'Day', 'ffcertificate' ) . '</th>';
		$html .= '<th ' . $hcell . '>' . esc_html__( 'Entry', 'ffcertificate' ) . '</th>';
		$html .= '<th ' . $hcell . '>' . esc_html__( 'Lunch Out', 'ffcertificate' ) . '</th>';
		$html .= '<th ' . $hcell . '>' . esc_html__( 'Lunch In', 'ffcertificate' ) . '</th>';
		$html .= '<th ' . $hcell . '>' . esc_html__( 'Exit', 'ffcertificate' ) . '</th>';
		$html .= '</tr>';

		foreach ( $wh as $entry ) {
			$day = $days_map[ $entry['day'] ?? 0 ] ?? '';
			$e1  = $entry['entry1'] ?? '';
			$x1  = $entry['exit1'] ?? '';
			$e2  = $entry['entry2'] ?? '';
			$x2  = $entry['exit2'] ?? '';
			if ( empty( $e1 ) && empty( $x2 ) ) {
				continue;
			}
			$html .= '<tr>';
			$html .= '<td ' . $cell . '>' . esc_html( $day ) . '</td>';
			$html .= '<td ' . $cell . '>' . esc_html( $e1 ) . '</td>';
			$html .= '<td ' . $cell . '>' . esc_html( $x1 ) . '</td>';
			$html .= '<td ' . $cell . '>' . esc_html( $e2 ) . '</td>';
			$html .= '<td ' . $cell . '>' . esc_html( $x2 ) . '</td>';
			$html .= '</tr>';
		}

		$html .= '</table>';
		return $html;
	}

	/**
	 * Build the "Additional Information" HTML section for custom (non-standard) fields.
	 *
	 * @param array<int, object>   $custom_fields    Field definitions.
	 * @param array<string, mixed> $decrypted_values field_key => plain value map.
	 * @return string HTML section.
	 */
	private static function build_custom_fields_section( array $custom_fields, array $decrypted_values ): string {
		if ( empty( $custom_fields ) ) {
			return '';
		}

		$html  = '<div style="margin-bottom: 6px">';
		$html .= '<div style="font-size: 9pt;font-weight: bold;color: #000;text-transform: uppercase;letter-spacing: 1px;padding-bottom: 2px;border-bottom: 1px solid #e8e8e8;margin-bottom: 3px">';
		$html .= esc_html__( 'Additional Information', 'ffcertificate' );
		$html .= '</div>';
		$html .= '<table style="width: 100%;border-collapse: collapse;font-size: 8pt" role="presentation">';

		foreach ( $custom_fields as $field ) {
			$key       = (string) $field->field_key;
			$raw_value = $decrypted_values[ $key ] ?? '';
			$display   = self::format_field_value( $field, $raw_value );

			$html .= '<tr>';
			$html .= '<td style="padding: 2px 0;font-weight: bold;color: #666;width: 120px;vertical-align: top">' . esc_html( (string) $field->field_label ) . ':</td>';
			$html .= '<td style="padding: 2px 0;color: #222">' . wp_kses_post( (string) $display ) . '</td>';
			$html .= '</tr>';
		}

		$html .= '</table></div>';

		return $html;
	}

	/**
	 * Decrypt sensitive fields in a value map.
	 *
	 * Fields with is_sensitive=1 are persisted as AES-256-CBC ciphertext in
	 * the submission JSON. Ficha rendering needs the plaintext value.
	 *
	 * @param array<int, object>   $fields Field definitions.
	 * @param array<string, mixed> $values field_key => persisted value (may be encrypted).
	 * @return array<string, mixed> field_key => plaintext value.
	 */
	public static function decrypt_field_values( array $fields, array $values ): array {
		$decrypted = $values;

		if ( ! class_exists( '\FreeFormCertificate\Core\Encryption' ) ) {
			return $decrypted;
		}

		foreach ( $fields as $field ) {
			if ( empty( $field->is_sensitive ) ) {
				continue;
			}
			$key = (string) $field->field_key;
			if ( ! isset( $decrypted[ $key ] ) || $decrypted[ $key ] === '' || ! is_string( $decrypted[ $key ] ) ) {
				continue;
			}
			$plain = \FreeFormCertificate\Core\Encryption::decrypt( $decrypted[ $key ] );
			if ( $plain !== null ) {
				$decrypted[ $key ] = $plain;
			}
		}

		return $decrypted;
	}

	/**
	 * Format a single field value for PDF display.
	 *
	 * @param object $field Field definition.
	 * @param mixed  $value Plain value.
	 * @return string Display-ready string (may contain safe HTML for working_hours).
	 */
	public static function format_field_value( object $field, $value ): string {
		switch ( (string) $field->field_type ) {
			case 'checkbox':
				return $value === '1' || $value === 1 || $value === true
					? __( 'Yes', 'ffcertificate' )
					: __( 'No', 'ffcertificate' );

			case 'dependent_select':
				$dep = is_string( $value ) ? json_decode( $value, true ) : $value;
				if ( is_array( $dep ) ) {
					$parent = (string) ( $dep['parent'] ?? '' );
					$child  = (string) ( $dep['child'] ?? '' );
					return trim( $parent . ' - ' . $child, ' -' );
				}
				return '';

			case 'working_hours':
				return self::format_working_hours( is_string( $value ) ? $value : (string) wp_json_encode( $value ) );

			default:
				if ( is_array( $value ) ) {
					return implode( ', ', array_map( 'strval', $value ) );
				}
				return is_scalar( $value ) ? (string) $value : '';
		}
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
		$template_file = apply_filters( 'ffcertificate_ficha_template_file', $template_file );

		if ( file_exists( $template_file ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local template file.
			$template = file_get_contents( $template_file );
			if ( ! empty( $template ) ) {
				return $template;
			}
		}

		// Fallback: minimal template
		return '<div style="width:794px;height:1123px;padding:60px;box-sizing:border-box;font-family:Arial,sans-serif">'
			. '<h1 style="text-align:center;color:#0073aa">' . esc_html__( 'Reregistration Record', 'ffcertificate' ) . '</h1>'
			. '<p><strong>' . esc_html__( 'Name:', 'ffcertificate' ) . '</strong> {{display_name}}</p>'
			. '<p><strong>' . esc_html__( 'Email:', 'ffcertificate' ) . '</strong> {{email}}</p>'
			. '<p><strong>' . esc_html__( 'Campaign:', 'ffcertificate' ) . '</strong> {{reregistration_title}}</p>'
			. '<p><strong>' . esc_html__( 'Status:', 'ffcertificate' ) . '</strong> {{submission_status}}</p>'
			. '{{custom_fields_section}}'
			. '</div>';
	}

	/**
	 * Get custom fields for all audiences linked to a reregistration.
	 *
	 * @param object $rereg Reregistration object.
	 * @return array<object>
	 */
	public static function get_custom_fields_for_reregistration( object $rereg ): array {
		$audience_ids = ReregistrationRepository::get_audience_ids( (int) $rereg->id );
		$all_fields   = array();
		$seen         = array();

		foreach ( $audience_ids as $aud_id ) {
			$fields = CustomFieldRepository::get_by_audience_with_parents( (int) $aud_id, true );
			foreach ( $fields as $field ) {
				if ( ! isset( $seen[ (int) $field->id ] ) ) {
					$seen[ (int) $field->id ] = true;
					$all_fields[]             = $field;
				}
			}
		}

		return $all_fields;
	}
}
