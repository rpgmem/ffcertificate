<?php
/**
 * FormEditorSaveHandler
 *
 * Handles saving and validation of form data.
 * Extracted from FFC_Form_Editor class to follow Single Responsibility Principle.
 *
 * @package FreeFormCertificate\Admin
 * @since 3.1.1 (Extracted from FFC_Form_Editor)
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace (Phase 2)
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handler for form editor save operations.
 */
class FormEditorSaveHandler {

	/**
	 * Saves all form data and configurations
	 *
	 * @param int $post_id The post ID.
	 */
	public function save_form_data( int $post_id ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! isset( $_POST['ffc_form_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ffc_form_nonce'] ) ), 'ffc_save_form_data' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// 1. Save Form Fields.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- isset()/is_array() existence and type checks only.
		if ( isset( $_POST['ffc_fields'] ) && is_array( $_POST['ffc_fields'] ) ) {
			$clean_fields = array();
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each field sanitized individually below.
			foreach ( wp_unslash( $_POST['ffc_fields'] ) as $index => $field ) {
				if ( 'TEMPLATE' === $index || ( empty( $field['label'] ) && empty( $field['name'] ) && empty( $field['content'] ) && empty( $field['embed_url'] ) ) ) {
					continue;
				}

				$clean_fields[] = array(
					'label'     => sanitize_text_field( $field['label'] ),
					'name'      => sanitize_key( $field['name'] ),
					'type'      => sanitize_key( $field['type'] ),
					'required'  => isset( $field['required'] ) ? '1' : '',
					'options'   => sanitize_text_field( isset( $field['options'] ) ? $field['options'] : '' ),
					'content'   => wp_kses_post( isset( $field['content'] ) ? $field['content'] : '' ),
					'embed_url' => esc_url_raw( isset( $field['embed_url'] ) ? $field['embed_url'] : '' ),
					'points'    => sanitize_text_field( isset( $field['points'] ) ? $field['points'] : '' ),
				);
			}
			update_post_meta( $post_id, '_ffc_form_fields', $clean_fields );
		} else {
			update_post_meta( $post_id, '_ffc_form_fields', array() );
		}

		// 2. Save Configurations.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- isset() existence check only.
		if ( isset( $_POST['ffc_config'] ) ) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each field sanitized individually below.
			$config       = wp_unslash( $_POST['ffc_config'] );
			$allowed_html = \FreeFormCertificate\Core\Utils::get_allowed_html_tags();

			$clean_config               = array();
			$clean_config['pdf_layout'] = wp_kses( (string) ( $config['pdf_layout'] ?? '' ), $allowed_html );
			// Email body is authored in the teeny wp_editor; wp_kses_post() is the
			// canonical WordPress allowlist for post-like rich content and already
			// strips scripts/forms while keeping the formatting users expect.
			$clean_config['email_body'] = wp_kses_post( (string) ( $config['email_body'] ?? '' ) );
			$clean_config['bg_image']   = esc_url_raw( (string) ( $config['bg_image'] ?? '' ) );

			$clean_config['enable_restriction'] = sanitize_key( (string) ( $config['enable_restriction'] ?? '' ) );
			$clean_config['send_user_email']    = sanitize_key( (string) ( $config['send_user_email'] ?? '' ) );
			$clean_config['email_subject']      = sanitize_text_field( (string) ( $config['email_subject'] ?? '' ) );

			// Restrictions (checkboxes).
			$clean_config['restrictions'] = array(
				'password'  => isset( $config['restrictions']['password'] ) ? '1' : '0',
				'allowlist' => isset( $config['restrictions']['allowlist'] ) ? '1' : '0',
				'denylist'  => isset( $config['restrictions']['denylist'] ) ? '1' : '0',
				'ticket'    => isset( $config['restrictions']['ticket'] ) ? '1' : '0',
			);

			$clean_config['allowed_users_list']   = sanitize_textarea_field( (string) ( $config['allowed_users_list'] ?? '' ) );
			$clean_config['denied_users_list']    = sanitize_textarea_field( (string) ( $config['denied_users_list'] ?? '' ) );
			$clean_config['validation_code']      = sanitize_text_field( (string) ( $config['validation_code'] ?? '' ) );
			$clean_config['generated_codes_list'] = sanitize_textarea_field( (string) ( $config['generated_codes_list'] ?? '' ) );

			// Quiz / Evaluation Mode.
			$clean_config['quiz_enabled']       = isset( $config['quiz_enabled'] ) ? '1' : '0';
			$clean_config['quiz_passing_score'] = absint( $config['quiz_passing_score'] ?? 70 );
			$clean_config['quiz_max_attempts']  = absint( $config['quiz_max_attempts'] ?? 0 );
			$clean_config['quiz_show_score']    = isset( $config['quiz_show_score'] ) ? '1' : '0';
			$clean_config['quiz_show_correct']  = isset( $config['quiz_show_correct'] ) ? '1' : '0';

			// Tag Validation: Ensure the user didn't remove critical tags.
			$missing_tags = array();
			if ( strpos( $clean_config['pdf_layout'], '{{auth_code}}' ) === false ) {
				$missing_tags[] = '{{auth_code}}';
			}
			if ( strpos( $clean_config['pdf_layout'], '{{name}}' ) === false && strpos( $clean_config['pdf_layout'], '{{nome}}' ) === false ) {
				$missing_tags[] = '{{name}}';
			}
			if ( strpos( $clean_config['pdf_layout'], '{{cpf_rf}}' ) === false ) {
				$missing_tags[] = '{{cpf_rf}}';
			}

			if ( ! empty( $missing_tags ) ) {
				set_transient( 'ffc_save_error_' . get_current_user_id(), $missing_tags, 45 );
			}

			$current_config = get_post_meta( $post_id, '_ffc_form_config', true );
			if ( ! is_array( $current_config ) ) {
				$current_config = array();
			}

			update_post_meta( $post_id, '_ffc_form_config', array_merge( $current_config, $clean_config ) );
		}

		// 3. Save Geofence Configuration.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- isset() existence check only.
		if ( isset( $_POST['ffc_geofence'] ) ) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each field sanitized individually below.
			$geofence = wp_unslash( $_POST['ffc_geofence'] );

			// Per-phase datetime hide modes (#159 S1). Each new key falls back to
			// the legacy single `datetime_hide_mode` POSTed value while the UI is
			// being migrated, so a save under the old single-dropdown UI still
			// produces a valid three-key payload. The legacy key is no longer
			// persisted on save.
			$legacy_hide_mode = isset( $geofence['datetime_hide_mode'] )
				? sanitize_key( $geofence['datetime_hide_mode'] )
				: 'message';

			$hide_mode_before = sanitize_key( $geofence['datetime_hide_mode_before'] ?? $legacy_hide_mode );
			$hide_mode_during = sanitize_key( $geofence['datetime_hide_mode_during'] ?? $legacy_hide_mode );
			$hide_mode_after  = sanitize_key( $geofence['datetime_hide_mode_after'] ?? $legacy_hide_mode );

			$clean_geofence = array(
				// DateTime settings.
				'datetime_enabled'          => isset( $geofence['datetime_enabled'] ) ? '1' : '0',
				'date_start'                => ! empty( $geofence['date_start'] ) ? sanitize_text_field( $geofence['date_start'] ) : '',
				'date_end'                  => ! empty( $geofence['date_end'] ) ? sanitize_text_field( $geofence['date_end'] ) : '',
				'time_start'                => ! empty( $geofence['time_start'] ) ? sanitize_text_field( $geofence['time_start'] ) : '',
				'time_end'                  => ! empty( $geofence['time_end'] ) ? sanitize_text_field( $geofence['time_end'] ) : '',
				'time_mode'                 => sanitize_key( $geofence['time_mode'] ?? 'daily' ),
				'datetime_hide_mode_before' => $hide_mode_before,
				'datetime_hide_mode_during' => $hide_mode_during,
				'datetime_hide_mode_after'  => $hide_mode_after,
				'msg_datetime'              => sanitize_textarea_field( $geofence['msg_datetime'] ?? '' ),

				// Geolocation settings.
				'geo_enabled'               => isset( $geofence['geo_enabled'] ) ? '1' : '0',
				'geo_gps_enabled'           => isset( $geofence['geo_gps_enabled'] ) ? '1' : '0',
				'geo_ip_enabled'            => isset( $geofence['geo_ip_enabled'] ) ? '1' : '0',
				'geo_area_source'           => in_array( ( $geofence['geo_area_source'] ?? 'custom' ), array( 'locations', 'custom' ), true ) ? $geofence['geo_area_source'] : 'custom',
				'geo_area_location_ids'     => array_map( 'sanitize_key', (array) ( $geofence['geo_area_location_ids'] ?? array() ) ),
				'geo_areas'                 => sanitize_textarea_field( $geofence['geo_areas'] ?? '' ),
				'geo_ip_areas_permissive'   => isset( $geofence['geo_ip_areas_permissive'] ) ? '1' : '0',
				'geo_ip_area_source'        => in_array( ( $geofence['geo_ip_area_source'] ?? 'custom' ), array( 'locations', 'custom' ), true ) ? $geofence['geo_ip_area_source'] : 'custom',
				'geo_ip_area_location_ids'  => array_map( 'sanitize_key', (array) ( $geofence['geo_ip_area_location_ids'] ?? array() ) ),
				'geo_ip_areas'              => sanitize_textarea_field( $geofence['geo_ip_areas'] ?? '' ),
				'geo_gps_ip_logic'          => sanitize_key( $geofence['geo_gps_ip_logic'] ?? 'or' ),
				'geo_hide_mode'             => sanitize_key( $geofence['geo_hide_mode'] ?? 'message' ),
				'msg_geo_blocked'           => sanitize_textarea_field( $geofence['msg_geo_blocked'] ?? '' ),
				'msg_geo_error'             => sanitize_textarea_field( $geofence['msg_geo_error'] ?? '' ),
			);

			// Validate geolocation configuration. Surface errors via transient,
			// but do not abort — independent sections (CSV download, device
			// fingerprint limit) must still persist their own changes.
			$validation_errors = $this->validate_geofence_config( $clean_geofence );
			if ( ! empty( $validation_errors ) ) {
				set_transient( 'ffc_geofence_error_' . get_current_user_id(), $validation_errors, 45 );
			} else {
				update_post_meta( $post_id, '_ffc_geofence_config', $clean_geofence );
				// Public visibility of date_start/date_end (the form's
				// availability window) flows through page caches —
				// invalidate so the public CSV download page + the
				// rendered form page pick up the change immediately.
				\FreeFormCertificate\Submissions\FormCache::clear_form_cache( $post_id );
				\FreeFormCertificate\Submissions\FormCache::purge_external_caches( $post_id, 'geofence_changed' );
			}
		}

		// 4. Save Public CSV Download configuration.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- isset() existence check; values sanitized below.
		if ( isset( $_POST['ffc_csv_public'] ) && is_array( $_POST['ffc_csv_public'] ) ) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each key sanitized individually.
			$public_raw = wp_unslash( $_POST['ffc_csv_public'] );

			$previous_enabled = (string) get_post_meta( $post_id, '_ffc_csv_public_enabled', true );
			$enabled          = ! empty( $public_raw['enabled'] ) ? '1' : '0';
			update_post_meta( $post_id, '_ffc_csv_public_enabled', $enabled );

			// When the master toggle is off, the sub-fields are rendered
			// disabled so the browser does not include them in the POST.
			// Skip the rest of the block to preserve the persisted limit /
			// hash / mode / whitelist instead of overwriting them with the
			// "no fields submitted" defaults.
			if ( '1' === $enabled ) {
				// Limit: positive integer ≥ 1. Fall back to settings default (min 1).
				$limit = isset( $public_raw['limit'] ) ? absint( $public_raw['limit'] ) : 0;
				if ( $limit < 1 ) {
					$settings      = get_option( 'ffc_settings', array() );
					$default_limit = ( is_array( $settings ) && isset( $settings['public_csv_default_limit'] ) )
						? (int) $settings['public_csv_default_limit']
						: 1;
					$limit         = $default_limit > 0 ? $default_limit : 1;
				}
				update_post_meta( $post_id, '_ffc_csv_public_limit', $limit );

				// Hash handling: auto-generate on first enable, or regenerate on request.
				$current_hash   = (string) get_post_meta( $post_id, '_ffc_csv_public_hash', true );
				$regenerate     = ! empty( $public_raw['regenerate_hash'] );
				$needs_new_hash = $regenerate || '' === $current_hash;

				if ( $needs_new_hash ) {
					try {
						$new_hash = bin2hex( random_bytes( 16 ) );
					} catch ( \Exception $e ) {
						$new_hash = wp_generate_password( 32, false, false );
					}
					update_post_meta( $post_id, '_ffc_csv_public_hash', $new_hash );
				}

				// Counter reset (explicit opt-in).
				if ( ! empty( $public_raw['reset_counter'] ) ) {
					update_post_meta( $post_id, '_ffc_csv_public_count', 0 );
				}

				// CPF gate mode for the public download. On the very first
				// enable transition (toggle just flipped 0→1) and the user
				// left the dropdown at the 'none' default, upgrade to 'audit'
				// so newly-enabled public downloads always log who pulled
				// them. On subsequent saves, respect the user's explicit
				// choice — including 'none' if they actively pick it.
				$valid_modes = array( 'none', 'audit', 'participants', 'owner', 'whitelist' );
				$mode_raw    = isset( $public_raw['cpf_mode'] ) ? sanitize_key( (string) $public_raw['cpf_mode'] ) : 'none';
				if ( ! in_array( $mode_raw, $valid_modes, true ) ) {
					$mode_raw = 'none';
				}
				if ( '1' !== $previous_enabled && 'none' === $mode_raw ) {
					$mode_raw = 'audit';
				}
				update_post_meta( $post_id, '_ffc_csv_public_cpf_mode', $mode_raw );

				// Whitelist textarea is rendered only when the persisted mode
				// is already 'whitelist'. When the user is in the process of
				// switching modes via the dropdown, the textarea is absent
				// from the POST — preserve the existing list instead of
				// silently deleting it.
				if ( array_key_exists( 'cpf_whitelist', $public_raw ) ) {
					$wl_raw = sanitize_textarea_field( (string) $public_raw['cpf_whitelist'] );
					if ( '' !== trim( $wl_raw ) ) {
						$cleaned_lines = array();
						$lines         = preg_split( '/[\r\n,]+/', $wl_raw );
						$lines         = is_array( $lines ) ? $lines : array();
						foreach ( $lines as $line ) {
							$digits = preg_replace( '/\D/', '', (string) $line );
							if ( is_string( $digits ) && 11 === strlen( $digits ) ) {
								$cleaned_lines[ $digits ] = $digits;
							}
						}
						update_post_meta( $post_id, '_ffc_csv_public_cpf_whitelist', implode( "\n", array_values( $cleaned_lines ) ) );
					} else {
						delete_post_meta( $post_id, '_ffc_csv_public_cpf_whitelist' );
					}
				}
			}
		}

		// 5. Save Device Fingerprint Limit override.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- isset() existence check; values sanitized below.
		if ( isset( $_POST['ffc_device_limit'] ) && is_array( $_POST['ffc_device_limit'] ) ) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each key sanitized individually.
			$device_raw = wp_unslash( $_POST['ffc_device_limit'] );

			$device_enabled = ! empty( $device_raw['enabled'] ) ? '1' : '0';
			update_post_meta( $post_id, '_ffc_device_limit_enabled', $device_enabled );

			// When the master toggle is off the sub-fields render disabled
			// and never make it into the POST. Skip the rest of the block to
			// preserve the persisted max / threshold / message instead of
			// silently deleting them.
			if ( '1' === $device_enabled ) {
				// Max submissions: when the user enables the metabox without
				// providing a value, default to 2 (per UX spec). This is a
				// hard default — not an inherit-from-global — because the
				// vast majority of forms want the same conservative limit.
				$max_raw = isset( $device_raw['max'] ) ? trim( (string) $device_raw['max'] ) : '';
				if ( '' === $max_raw ) {
					update_post_meta( $post_id, '_ffc_device_limit_max', 2 );
				} else {
					update_post_meta( $post_id, '_ffc_device_limit_max', max( 1, absint( $max_raw ) ) );
				}

				// Threshold and message keep the inherit-from-global semantic:
				// empty value deletes the meta so RateLimiter::get_settings()
				// supplies the global default at read time.
				$thr_raw = isset( $device_raw['threshold'] ) ? trim( (string) $device_raw['threshold'] ) : '';
				if ( '' === $thr_raw ) {
					delete_post_meta( $post_id, '_ffc_device_match_threshold' );
				} else {
					update_post_meta( $post_id, '_ffc_device_match_threshold', max( 3, min( 12, absint( $thr_raw ) ) ) );
				}

				$msg_raw = isset( $device_raw['message'] ) ? sanitize_textarea_field( (string) $device_raw['message'] ) : '';
				if ( '' === $msg_raw ) {
					delete_post_meta( $post_id, '_ffc_device_limit_message' );
				} else {
					update_post_meta( $post_id, '_ffc_device_limit_message', $msg_raw );
				}
			}
		}
	}

	/**
	 * Validates geofence configuration
	 *
	 * @param array<string, mixed> $config Geofence configuration.
	 * @return array<int, string> Array of validation errors (empty if valid)
	 */
	private function validate_geofence_config( array $config ): array {
		$errors = array();

		$gps_source = $config['geo_area_source'] ?? 'custom';
		$ip_source  = $config['geo_ip_area_source'] ?? 'custom';

		// Check if GPS is enabled but areas/locations are empty.
		if ( '1' === $config['geo_gps_enabled'] ) {
			if ( 'locations' === $gps_source ) {
				if ( empty( $config['geo_area_location_ids'] ) ) {
					$errors[] = __( 'GPS Geolocation is enabled but no locations are selected.', 'ffcertificate' );
				}
			} elseif ( '' === trim( $config['geo_areas'] ) ) {
				$errors[] = __( 'GPS Geolocation is enabled but no allowed areas are defined.', 'ffcertificate' );
			}
		}

		// Check if IP is enabled with independent areas but areas/locations are empty.
		if ( '1' === $config['geo_ip_enabled'] && '1' === $config['geo_ip_areas_permissive'] ) {
			if ( 'locations' === $ip_source ) {
				if ( empty( $config['geo_ip_area_location_ids'] ) ) {
					$errors[] = __( 'IP Geolocation is enabled with independent areas but no locations are selected.', 'ffcertificate' );
				}
			} elseif ( '' === trim( $config['geo_ip_areas'] ) ) {
				$errors[] = __( 'IP Geolocation is enabled with independent areas but no IP areas are defined.', 'ffcertificate' );
			}
		}

		// Validate datetime order (#159 S2). Date/time order checks live in
		// `Geofence::analyze_datetime_order()` so the metabox renderer can
		// reuse the same map to paint `ffc-input-invalid` on the offending
		// inputs without duplicating the rules.
		$datetime_errors = \FreeFormCertificate\Security\Geofence::analyze_datetime_order( $config );
		if ( ! empty( $datetime_errors ) ) {
			// Dedupe — the helper repeats the same message across paired
			// fields (e.g. both `date_start` and `date_end`); the operator
			// only needs to see each rule once in the admin notice.
			$errors = array_merge( $errors, array_values( array_unique( array_values( $datetime_errors ) ) ) );
		}

		// Validate GPS areas format.
		if ( '1' === $config['geo_gps_enabled'] && 'custom' === $gps_source && '' !== trim( $config['geo_areas'] ) ) {
			$gps_errors = $this->validate_areas_format( $config['geo_areas'], 'GPS' );
			$errors     = array_merge( $errors, $gps_errors );
		}

		// Validate IP areas format (if using independent areas).
		if ( '1' === $config['geo_ip_enabled'] && '1' === $config['geo_ip_areas_permissive'] && 'custom' === $ip_source && '' !== trim( $config['geo_ip_areas'] ) ) {
			$ip_errors = $this->validate_areas_format( $config['geo_ip_areas'], 'IP' );
			$errors    = array_merge( $errors, $ip_errors );
		}

		return $errors;
	}

	/**
	 * Validates area format (latitude, longitude, radius)
	 *
	 * @param string $areas_text Areas text (one per line).
	 * @param string $type Type of area (GPS or IP) for error messages.
	 * @return array<int, string> Array of validation errors
	 */
	private function validate_areas_format( string $areas_text, string $type ): array {
		$errors      = array();
		$lines       = array_filter( array_map( 'trim', explode( "\n", $areas_text ) ) );
		$line_number = 0;

		foreach ( $lines as $line ) {
			++$line_number;

			// Check format: lat,lng,radius.
			if ( ! preg_match( '/^-?\d+(\.\d+)?\s*,\s*-?\d+(\.\d+)?\s*,\s*\d+(\.\d+)?$/', $line ) ) {
				$errors[] = sprintf(
					/* translators: 1: Area type (GPS/IP), 2: Line number */
					__( '%1$s Area line %2$d: Invalid format. Use: latitude, longitude, radius', 'ffcertificate' ),
					$type,
					$line_number
				);
				continue;
			}

			// Parse values.
			$parts  = array_map( 'trim', explode( ',', $line ) );
			$lat    = floatval( $parts[0] );
			$lng    = floatval( $parts[1] );
			$radius = floatval( $parts[2] );

			// Validate latitude range.
			if ( $lat < -90 || $lat > 90 ) {
				$errors[] = sprintf(
					/* translators: 1: Area type (GPS/IP), 2: Line number, 3: Latitude value */
					__( '%1$s Area line %2$d: Invalid latitude %3$s (must be between -90 and 90)', 'ffcertificate' ),
					$type,
					$line_number,
					$lat
				);
			}

			// Validate longitude range.
			if ( $lng < -180 || $lng > 180 ) {
				$errors[] = sprintf(
					/* translators: 1: Area type (GPS/IP), 2: Line number, 3: Longitude value */
					__( '%1$s Area line %2$d: Invalid longitude %3$s (must be between -180 and 180)', 'ffcertificate' ),
					$type,
					$line_number,
					$lng
				);
			}

			// Validate radius.
			if ( $radius <= 0 ) {
				$errors[] = sprintf(
					/* translators: 1: Area type (GPS/IP), 2: Line number */
					__( '%1$s Area line %2$d: Radius must be greater than 0', 'ffcertificate' ),
					$type,
					$line_number
				);
			}
		}

		return $errors;
	}

	/**
	 * Displays validation warnings after saving
	 */
	public function display_save_errors(): void {
		// Display PDF layout errors.
		$error_tags = get_transient( 'ffc_save_error_' . get_current_user_id() );
		if ( $error_tags ) {
			delete_transient( 'ffc_save_error_' . get_current_user_id() );
			?>
			<div class="notice notice-error is-dismissible">
				<p><strong><?php esc_html_e( 'Warning! Missing required tags in PDF Layout:', 'ffcertificate' ); ?></strong> <code><?php echo esc_html( implode( ', ', $error_tags ) ); ?></code>.</p>
			</div>
			<?php
		}

		// Display geofence validation errors.
		$geofence_errors = get_transient( 'ffc_geofence_error_' . get_current_user_id() );
		if ( $geofence_errors ) {
			delete_transient( 'ffc_geofence_error_' . get_current_user_id() );
			?>
			<div class="notice notice-error is-dismissible">
				<p><strong><?php esc_html_e( 'Geolocation Configuration Error:', 'ffcertificate' ); ?></strong></p>
				<ul class="ffc-list-disc ffc-ml-20">
					<?php foreach ( $geofence_errors as $error ) : ?>
						<li><?php echo esc_html( $error ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php
		}
	}
}
