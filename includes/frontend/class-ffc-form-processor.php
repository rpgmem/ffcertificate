<?php
/**
 * FormProcessor
 * Handles form submission processing, validation, and restriction checks.
 *
 * V2.9.2: Unified PDF generation with FFC_PDF_Generator
 * v2.9.11: Using FFC_Utils for validation and sanitization
 * v2.9.13: Optimized detect_reprint() to use cpf_rf column with fallback
 * v2.10.0: LGPD - Validates consent checkbox (mandatory)
 * v3.3.0: Added strict types and type hints
 * v3.2.0: Migrated to namespace (Phase 2)
 * v4.12.17: Extracted AccessRestrictionChecker and ReprintDetector for SRP compliance.
 *
 * @package FreeFormCertificate\Frontend
 */

declare(strict_types=1);

namespace FreeFormCertificate\Frontend;

use FreeFormCertificate\Core\Utils;

use FreeFormCertificate\Submissions\SubmissionHandler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Processor for form operations.
 */
class FormProcessor {

	/**
	 * Submission handler.
	 *
	 * @var SubmissionHandler
	 */
	private $submission_handler;

	/**
	 * Constructor
	 *
	 * @param SubmissionHandler $submission_handler Submission handler.
	 */
	public function __construct( SubmissionHandler $submission_handler ) {
		$this->submission_handler = $submission_handler;

		// AJAX hooks registered in Frontend::register_hooks() to avoid duplicate registration.
	}

	/**
	 * Handle form submission via AJAX
	 */
	public function handle_submission_ajax(): void {
		// Schedule exception bridge (#366 Sprint 6). Read + verify the
		// hidden-input token Sprint 5 embedded in the form. A valid
		// payload means an operator staged this submission as an
		// exception; downstream we (a) skip the IP rate-limit gate,
		// (b) persist the override TIME columns, (c) emit two audit
		// rows. Done before the IP gate so the operator's bypass takes
		// effect even when the venue's network has saturated the
		// per-IP throttle.
		//
		// The token IS a signed credential (HMAC over the payload + 30
		// min expiry + form_id binding), so verifying it before the WP
		// nonce check is safe — the token is the auth for the exception
		// path, not the nonce.
		$has_exception              = false;
		$schedule_exception_payload = null;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- token verified via HMAC immediately below; form_id sanitized via absint.
		if ( isset( $_POST['ffc_schedule_exception_token'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- HMAC verifies integrity.
			$token_raw = (string) wp_unslash( $_POST['ffc_schedule_exception_token'] );
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$token_form = isset( $_POST['form_id'] ) ? absint( wp_unslash( $_POST['form_id'] ) ) : 0;
			$verified   = \FreeFormCertificate\Frontend\ScheduleExceptionSession::verify_token( $token_raw );
			if ( null !== $verified && $token_form > 0 && (int) ( $verified['form_id'] ?? 0 ) === $token_form ) {
				$has_exception              = true;
				$schedule_exception_payload = $verified;
			}
		}

		// Rate limit by IP — run BEFORE nonce/CAPTCHA to prevent brute-force.
		// and DoS attacks from consuming server resources on expensive checks.
		//
		// Skipped on the exception path: operators handing tablets at a
		// venue routinely concentrate submissions on one outbound IP, and
		// the signed token already binds the bypass to a 30-minute
		// operator-issued window. Per-CPF rate-limits (in
		// `RateLimiter::check_all()` below) still apply normally so a
		// single participant can't replay the token across submissions.
		if ( ! $has_exception && class_exists( '\FreeFormCertificate\Security\RateLimiter' ) ) {
			$user_ip    = \FreeFormCertificate\Core\Utils::get_user_ip();
			$rate_check = \FreeFormCertificate\Security\RateLimiter::check_ip_limit( $user_ip );
			if ( ! $rate_check['allowed'] ) {
				wp_send_json_error(
					array(
						'message'      => $rate_check['message'] ?? __( 'Too many requests. Please wait.', 'ffcertificate' ),
						'rate_limit'   => true,
						'wait_seconds' => $rate_check['wait_seconds'] ?? 0,
					)
				);
			}
		}

		// Verify nonce.
		//
		// 6.6.3 — when the nonce check fails, hand back a fresh nonce
		// keyed to the visitor's current session cookie so the client
		// (FFC.request) can transparently retry once. This works around:
		//
		// - cached HTML carrying another visitor's nonce on shared
		// hosts (cache-bust on assets doesn't help because the page
		// HTML itself is what's cached);
		// - iOS Safari ITP / iCloud Private Relay rotating the session
		// cookie between page render and submit;
		// - ffc-dynamic-fragments silently failing on some networks
		// (the catch in that script swallows XHR errors).
		//
		// Safety: a stale-nonce auto-refresh is not a CSRF weakening.
		// The fresh nonce is bound to the cookie of the request that
		// asks for it; an attacker who can't present a valid cookie
		// can't use the returned nonce. Callers are also guarded
		// against retry loops (options._ffcNonceRetried in ffc-core.js).
		if ( ! wp_verify_nonce( Utils::get_post_string( 'nonce' ), 'ffc_frontend_nonce' ) ) {
			wp_send_json_error(
				array(
					'message'       => __( 'Security check failed. Please refresh the page.', 'ffcertificate' ),
					'refresh_nonce' => true,
					'new_nonce'     => wp_create_nonce( 'ffc_frontend_nonce' ),
				)
			);
		}

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified above via wp_verify_nonce.

		// ===== DEBUG CAPTCHA =====.
		\FreeFormCertificate\Core\Debug::log_form( '===== CAPTCHA DEBUG =====' );
		\FreeFormCertificate\Core\Debug::log_form( 'Answer received', Utils::get_post_string( 'ffc_captcha_ans', 'NOT SET' ) );
		\FreeFormCertificate\Core\Debug::log_form( 'Hash received', Utils::get_post_string( 'ffc_captcha_hash', 'NOT SET' ) );

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- isset() check only; values sanitized inside block.
		if ( isset( $_POST['ffc_captcha_ans'] ) && isset( $_POST['ffc_captcha_hash'] ) ) {
			$test_answer    = trim( Utils::get_post_string( 'ffc_captcha_ans' ) );
			$received_hash  = Utils::get_post_string( 'ffc_captcha_hash' );
			$generated_hash = wp_hash( $test_answer . 'ffc_math_salt' );

			\FreeFormCertificate\Core\Debug::log_form( 'Trimmed answer', $test_answer );
			\FreeFormCertificate\Core\Debug::log_form( 'Generated hash from answer', $generated_hash );
			\FreeFormCertificate\Core\Debug::log_form( 'Hashes match', $generated_hash === $received_hash ? 'YES' : 'NO' );

			// Test with different variations.
			\FreeFormCertificate\Core\Debug::log_form( 'Test with (int)', wp_hash( (int) $test_answer . 'ffc_math_salt' ) );
			\FreeFormCertificate\Core\Debug::log_form( 'Test with (string)', wp_hash( (string) $test_answer . 'ffc_math_salt' ) );
		}
		\FreeFormCertificate\Core\Debug::log_form( '===== END CAPTCHA DEBUG =====' );
		// ===== END DEBUG =====.

		// Validate security fields using FFC_Utils.
		$security_check = \FreeFormCertificate\Core\SecurityService::validate_security_fields( $_POST );
		if ( true !== $security_check ) {
			// Generate new captcha for retry.
			$new_captcha = \FreeFormCertificate\Core\SecurityService::generate_simple_captcha();
			wp_send_json_error(
				array(
					'message'         => $security_check,
					'refresh_captcha' => true,
					'new_label'       => $new_captcha['label'],
					'new_hash'        => $new_captcha['hash'],
				)
			);
		}

		$form_id = isset( $_POST['form_id'] ) ? absint( wp_unslash( $_POST['form_id'] ) ) : 0;
		if ( ! $form_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid Form ID.', 'ffcertificate' ) ) );
		}

		$form_config = get_post_meta( $form_id, '_ffc_form_config', true );
		if ( ! is_array( $form_config ) ) {
			$form_config = array();
		}

		$fields_config = get_post_meta( $form_id, '_ffc_form_fields', true );
		if ( ! $fields_config ) {
			// 6.6.4 Sprint 7 — empathetic rewording. The original
			// "Form configuration not found." reads like a 404 from
			// the database to a non-technical user. This is always an
			// admin-side state (deleted form / unsaved config), never
			// the user's fault — point them at the organizer.
			wp_send_json_error( array( 'message' => __( 'This form is not available right now. Please contact the organizer.', 'ffcertificate' ) ) );
		}

		// 6.6.4 Sprint 4 — cheap presence checks BEFORE the field
		// validation loop. LGPD consent + email presence are O(1)
		// checks (no CPF checksum, no regex per field); running them
		// up front lets the user get both errors in a single response
		// instead of fixing CPF first, then resubmitting, then seeing
		// the LGPD error. The combined errors array on the wp_send_json
		// payload mirrors the existing refresh_captcha shape so the
		// client doesn't need a new code path.
		$preflight_errors = array();

		// LGPD: trivial string compare, no field loop dependency.
		if ( Utils::get_post_string( 'ffc_lgpd_consent' ) !== '1' ) {
			$preflight_errors[] = __( 'You must agree to the Privacy Policy to continue.', 'ffcertificate' );
		}

		// Email presence: peek at $_POST for any field whose admin-
		// configured type is 'email'. Catches the empty-email case
		// without running the full field loop (which does CPF
		// checksum etc.). Multiple email fields are rare but
		// supported: if ANY of them is empty the form was incomplete.
		$email_field_names = array();
		foreach ( $fields_config as $field ) {
			if ( isset( $field['type'], $field['name'] ) && 'email' === $field['type'] ) {
				$email_field_names[] = $field['name'];
			}
		}
		if ( ! empty( $email_field_names ) ) {
			$email_missing = false;
			foreach ( $email_field_names as $email_field ) {
				$raw_email = Utils::get_post_string( $email_field );
				if ( '' === trim( $raw_email ) ) {
					$email_missing = true;
					break;
				}
			}
			if ( $email_missing ) {
				$preflight_errors[] = __( 'Email address is required.', 'ffcertificate' );
			}
		}

		if ( ! empty( $preflight_errors ) ) {
			wp_send_json_error(
				array(
					// Backward-compatible: legacy single-error consumers
					// read `message` (first error). New consumers can
					// read the full `errors` array to surface every
					// missing field at once.
					'message' => $preflight_errors[0],
					'errors'  => $preflight_errors,
				)
			);
		}

		// Process and sanitize form fields using FFC_Utils.
		$submission_data = array();
		$user_email      = '';

		// Name fields that should be normalized (capitalized with lowercase connectives).
		$name_fields = array( 'nome_completo', 'nome', 'name', 'full_name', 'ffc_nome', 'participante' );

		foreach ( $fields_config as $field ) {
			// Skip display-only field types (no user input).
			if ( isset( $field['type'] ) && in_array( $field['type'], array( 'info', 'embed' ), true ) ) {
				continue;
			}

			$name = $field['name'];
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- isset() check only; value unslashed and sanitized below.
			if ( isset( $_POST[ $name ] ) ) {
				$value = \FreeFormCertificate\Core\DataSanitizer::recursive_sanitize( wp_unslash( $_POST[ $name ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via recursive_sanitize().

				// Normalize name fields (proper capitalization with lowercase connectives).
				if ( in_array( $name, $name_fields, true ) && is_string( $value ) && ! empty( $value ) ) {
					$value = \FreeFormCertificate\Core\DataSanitizer::normalize_brazilian_name( $value );
				}

				// Special validation for CPF/RF.
				if ( 'cpf_rf' === $name ) {
					$value = preg_replace( '/\D/', '', $value );

					// Validate length.
					if ( strlen( $value ) !== 7 && strlen( $value ) !== 11 ) {
						wp_send_json_error( array( 'message' => __( 'CPF/RF must be exactly 7 or 11 digits.', 'ffcertificate' ) ) );
					}

					// Validate CPF (11 digits) using official algorithm.
					if ( strlen( $value ) === 11 ) {
						if ( ! \FreeFormCertificate\Core\DocumentFormatter::validate_cpf( $value ) ) {
							wp_send_json_error( array( 'message' => __( 'Invalid CPF. Please check the number and try again.', 'ffcertificate' ) ) );
						}
					}

					// Validate RF (7 digits) - must be numeric.
					if ( strlen( $value ) === 7 ) {
						if ( ! \FreeFormCertificate\Core\DocumentFormatter::validate_rf( $value ) ) {
							wp_send_json_error( array( 'message' => __( 'Invalid RF. Must contain only numbers.', 'ffcertificate' ) ) );
						}
					}
				}

				$submission_data[ $name ] = $value;

				if ( isset( $field['type'] ) && 'email' === $field['type'] ) {
					// Normalize email to lowercase for consistent storage and lookups.
					$user_email = strtolower( sanitize_email( $value ) );
				}
			}
		}

		// Defensive: after the field loop ran, the email field may
		// have failed sanitize_email() despite passing the preflight
		// presence check (e.g. user typed "not an email"). Keep this
		// gate as a fallback so $user_email is never empty downstream.
		if ( empty( $user_email ) ) {
			wp_send_json_error( array( 'message' => __( 'Email address is required.', 'ffcertificate' ) ) );
		}

		// LGPD already validated up-front (Sprint 4 pre-flight); just
		// stamp the consent on the submission data.
		$submission_data['ffc_lgpd_consent'] = '1';

		// Capture restriction fields (password/ticket) from POST.
		$val_password = trim( Utils::get_post_string( 'ffc_password' ) );
		$val_ticket   = strtoupper( trim( Utils::get_post_string( 'ffc_ticket' ) ) );

		$val_cpf = isset( $submission_data['cpf_rf'] ) ? trim( $submission_data['cpf_rf'] ) : '';

		// Step 8.5: Resolve device fingerprint signals + role bypass before
		// the consolidated rate-limit check. The actual N-of-M test runs
		// inside RateLimiter::check_all().
		$device_signals = null;
		$skip_device    = false;
		if ( class_exists( '\FreeFormCertificate\Security\RateLimiter' ) ) {
			$rl_settings         = \FreeFormCertificate\Security\RateLimiter::get_settings();
			$device_globally_on  = ! empty( $rl_settings['device']['enabled'] );
			$device_form_enabled = '1' === (string) get_post_meta( $form_id, '_ffc_device_limit_enabled', true );

			if ( $device_globally_on && $device_form_enabled ) {
				if ( \FreeFormCertificate\Security\RateLimiter::should_bypass_for_manager() ) {
					$skip_device = true;
					\FreeFormCertificate\Security\RateLimiter::log_attempt(
						'device',
						(string) get_current_user_id(),
						'allowed',
						'manager_bypass',
						$form_id
					);
				} else {
                    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified earlier in handle_submission_ajax().
					$raw_signals = isset( $_POST['ffc_device_signals'] ) ? wp_unslash( $_POST['ffc_device_signals'] ) : '';
					if ( is_string( $raw_signals ) && '' !== $raw_signals ) {
						$decoded = json_decode( $raw_signals, true );
						if ( is_array( $decoded ) ) {
							$clean_signals = array();
							foreach ( array( 'cookie', 'ua', 'screen', 'tz', 'concurrency', 'memory', 'canvas', 'audio', 'webgl', 'fonts' ) as $sig_key ) {
								if ( isset( $decoded[ $sig_key ] ) && is_string( $decoded[ $sig_key ] ) && preg_match( '/^[a-f0-9]{64}$/i', $decoded[ $sig_key ] ) ) {
									$clean_signals[ $sig_key ] = strtolower( $decoded[ $sig_key ] );
								}
							}
							if ( ! empty( $clean_signals ) ) {
								$device_signals = $clean_signals;
							}
						}
					}
				}
			}
		}

		// Geofence validation (date/time + geolocation).
		//
		// 6.6.4 Sprint 5 — moved BEFORE the consolidated rate-limit
		// check. Geofence::can_access_form is read-only (single
		// get_post_meta + optional IP geolocation lookup); the
		// rate-limit block, in contrast, calls record_attempt() which
		// writes to the counters. A visitor outside the geofence
		// (e.g. legitimate user on the edge of the allowed radius
		// whose GPS imprecision puts them slightly outside) used to
		// burn rate-limit budget on every fail. Doing the cheaper
		// read-only check first preserves their budget for the next
		// legitimate retry.
		//
		// Cheap → expensive ordering. Rate-limit IP at the very top
		// of the handler (step 1) still catches the DoS case before
		// geofence — geofence isn't a DoS gate, it's an authorization
		// gate.
		if ( class_exists( '\FreeFormCertificate\Security\Geofence' ) ) {
			// Get form geofence config to check if IP validation is enabled.
			$geofence_config    = \FreeFormCertificate\Security\Geofence::get_form_config( $form_id );
			$should_validate_ip = false;

			// Backend validation logic:
			// - Always validate datetime (server-side is authoritative)
			// - Only validate IP geolocation if explicitly enabled.
			// - GPS validation happens on frontend (browser geolocation API)
			// Note: GPS-only mode relies on frontend validation; backend cannot verify GPS.
			if ( $geofence_config && ! empty( $geofence_config['geo_enabled'] ) && ! empty( $geofence_config['geo_ip_enabled'] ) ) {
				$should_validate_ip = true;
			}

			$geofence_check = \FreeFormCertificate\Security\Geofence::can_access_form(
				$form_id,
				array(
					'check_datetime' => true,        // Always validate date/time server-side.
					'check_geo'      => $should_validate_ip, // Only validate IP if explicitly enabled.
				)
			);

			if ( ! $geofence_check['allowed'] ) {
				wp_send_json_error(
					array(
						'message'          => $geofence_check['message'] ?? '',
						'geofence_blocked' => true,
						'reason'           => $geofence_check['reason'] ?? '',
					)
				);
			}
		}

		// Rate Limit Check.
		if ( class_exists( '\FreeFormCertificate\Security\RateLimiter' ) ) {
			$ip    = \FreeFormCertificate\Core\Utils::get_user_ip();
			$email = $user_email;
			$cpf   = $val_cpf;

			// 6.3.10: a successful reprint of an already-issued certificate
			// must not be blocked by the per-device limit — that gate is
			// meant to stop the same device creating multiple FRESH
			// submissions for different CPFs, not to lock the user out of
			// re-downloading their own certificate from the same device.
			// We pre-run ReprintDetector::detect() here (it also runs at
			// the canonical step ~492 for the actual flow); when it
			// signals a reprint, we whitelist the device check via the
			// existing $skip_device flag (same flag that the manager
			// bypass already uses, so RateLimiter::check_all stays
			// untouched).
			if ( ! $skip_device
				&& '' !== $val_cpf
				&& class_exists( '\FreeFormCertificate\Frontend\ReprintDetector' ) ) {
				$reprint_preview = ReprintDetector::detect( $form_id, $val_cpf, $val_ticket );
				if ( ! empty( $reprint_preview['is_reprint'] ) ) {
					$skip_device = true;
				}
			}

			$rate_check = \FreeFormCertificate\Security\RateLimiter::check_all( $ip, $email, $cpf, $form_id, $device_signals, $skip_device );

			if ( ! $rate_check['allowed'] ) {
				wp_send_json_error(
					array(
						'message'      => $rate_check['message'] ?? 'Rate limit exceeded.',
						'rate_limit'   => true,
						'wait_seconds' => $rate_check['wait_seconds'] ?? 0,
					)
				);
			}

			// Record attempt.
			\FreeFormCertificate\Security\RateLimiter::record_attempt( 'ip', $ip, $form_id );
			\FreeFormCertificate\Security\RateLimiter::record_attempt( 'email', $email, $form_id );
			if ( $cpf ) {
				\FreeFormCertificate\Security\RateLimiter::record_attempt( 'cpf', \FreeFormCertificate\Core\DataSanitizer::normalize_cpf_rf( $cpf ), $form_id );
			}

			// Persist device fingerprint hashes once the submission row has
			// been created (so submission_id FK is available). Skipped when
			// the manager bypass fired or when no usable signals arrived.
			if ( ! $skip_device && is_array( $device_signals ) ) {
				$signals_to_record = $device_signals;
				$target_form_id    = $form_id;
				add_action(
					'ffcertificate_after_submission_save',
					static function ( $submission_id, $saved_form_id ) use ( $signals_to_record, $target_form_id ) {
						if ( (int) $saved_form_id !== (int) $target_form_id ) {
							return;
						}
						\FreeFormCertificate\Security\RateLimiter::record_device_signals(
							is_numeric( $submission_id ) ? (int) $submission_id : null,
							(int) $saved_form_id,
							$signals_to_record
						);
					},
					10,
					2
				);
			}
		}

		// Check restrictions (whitelist/denylist/tickets) — delegated to AccessRestrictionChecker.
		$restriction_result = AccessRestrictionChecker::check( $form_config, $val_cpf, $val_ticket, $form_id );

		if ( ! $restriction_result['allowed'] ) {
			wp_send_json_error( array( 'message' => $restriction_result['message'] ) );
		}

		// === Quiz Mode Processing (v4.9.0) ===.
		$is_quiz         = ! empty( $form_config['quiz_enabled'] ) && '1' === $form_config['quiz_enabled'];
		$form_post       = get_post( $form_id );
		$form_post_title = $form_post ? (string) $form_post->post_title : '';
		$is_reprint      = false;

		if ( $is_quiz ) {
			// Calculate quiz score.
			$quiz_score    = $this->calculate_quiz_score( $fields_config, $submission_data );
			$passing_score = absint( $form_config['quiz_passing_score'] ?? 70 );
			$max_attempts  = absint( $form_config['quiz_max_attempts'] ?? 0 );
			$passed        = $quiz_score['percent'] >= $passing_score;

			// Store quiz data in submission.
			$submission_data['_quiz_score']     = $quiz_score['score'];
			$submission_data['_quiz_max_score'] = $quiz_score['max_score'];
			$submission_data['_quiz_percent']   = $quiz_score['percent'];
			$submission_data['_quiz_passed']    = $passed ? '1' : '0';

			// Find existing quiz submission for this CPF + form.
			$existing = $this->find_quiz_submission( $form_id, $val_cpf );

			// If already passed (status=publish), treat as reprint.
			if ( $existing && 'publish' === $existing->status ) {
				$submission_id        = (int) $existing->id;
				$real_submission_date = $existing->submission_date;
				$is_reprint           = true;

				// Surface the stored auth_code for the success card/message.
				if ( ! empty( $existing->auth_code ) && empty( $submission_data['auth_code'] ) ) {
					$submission_data['auth_code'] = $existing->auth_code;
				}
			} else {
				// Count attempts.
				$prev_attempt = 0;
				if ( $existing ) {
					$prev_data = json_decode( $existing->data ?? '{}', true );
					if ( ! is_array( $prev_data ) ) {
						$prev_data = array();
					}
					$prev_attempt = absint( $prev_data['_quiz_attempt'] ?? 0 );
				}
				$attempt_number                   = $prev_attempt + 1;
				$submission_data['_quiz_attempt'] = $attempt_number;

				// Check attempt limit.
				if ( $max_attempts > 0 && $attempt_number > $max_attempts ) {
					wp_send_json_error(
						array(
							'message' => __( 'Maximum quiz attempts reached for this CPF/RF.', 'ffcertificate' ),
							'quiz'    => array( 'attempts_exhausted' => true ),
						)
					);
				}

				// Determine status.
				if ( $passed ) {
					$quiz_status = 'publish';
				} elseif ( $max_attempts > 0 && $attempt_number >= $max_attempts ) {
					$quiz_status = 'quiz_failed';
				} else {
					$quiz_status = 'quiz_in_progress';
				}

				if ( $existing ) {
					// UPDATE existing submission.
					$submission_id = (int) $existing->id;
					$repo          = $this->submission_handler->get_repository();

					// Build updated data JSON.
					$mandatory_keys = array( 'email', 'cpf_rf', 'auth_code', 'ffc_lgpd_consent' );
					$extra_data     = array_diff_key( $submission_data, array_flip( $mandatory_keys ) );
					$data_json_raw  = wp_json_encode( $extra_data );
					$data_json      = $data_json_raw ? $data_json_raw : '{}';

					$update_fields = array(
						'status'          => $quiz_status,
						'submission_date' => current_time( 'mysql' ),
					);
					if ( class_exists( '\FreeFormCertificate\Core\Encryption' ) && \FreeFormCertificate\Core\Encryption::is_configured() ) {
						$update_fields['data_encrypted'] = \FreeFormCertificate\Core\Encryption::encrypt( $data_json );
					} else {
						$update_fields['data'] = $data_json;
					}

					$repo->update( $submission_id, $update_fields );
					$real_submission_date = current_time( 'mysql' );
				} else {
					// INSERT new submission via existing handler.
					$submission_id = $this->submission_handler->process_submission(
						$form_id,
						$form_post_title,
						$submission_data,
						$user_email,
						$fields_config,
						$form_config
					);

					if ( is_wp_error( $submission_id ) ) {
						// 6.6.4 Sprint 7 — wrap the raw repository error
						// with empathetic guidance. The raw message is
						// often a SQL/wpdb string ("Duplicate entry"…)
						// that's actionable only for admins. Surface
						// the original as `code` for support triage and
						// give the user a recovery path.
						wp_send_json_error(
							array(
								'code'    => $submission_id->get_error_code(),
								'message' => __( "We couldn't save your submission. Try again — if the problem persists, contact the organizer.", 'ffcertificate' ),
								'detail'  => $submission_id->get_error_message(),
							)
						);
					}

					// Update status if not publish.
					if ( 'publish' !== $quiz_status ) {
						$this->submission_handler->get_repository()->updateStatus( $submission_id, $quiz_status );
					}

					$real_submission_date = current_time( 'mysql' );
				}

				// If not passed, return quiz feedback (no certificate).
				if ( ! $passed ) {
					$remaining  = ( $max_attempts > 0 ) ? max( 0, $max_attempts - $attempt_number ) : -1;
					$show_score = ( $form_config['quiz_show_score'] ?? '1' ) === '1';

					if ( 'quiz_failed' === $quiz_status ) {
						$msg = $show_score
							/* translators: %d: quiz score percentage */
							? sprintf( __( 'Quiz failed. Score: %d%%. Maximum attempts reached.', 'ffcertificate' ), $quiz_score['percent'] )
							: __( 'Quiz failed. Maximum attempts reached.', 'ffcertificate' );
					} else {
						$msg = $show_score
							/* translators: 1: score percentage, 2: remaining attempts */
							? sprintf( __( 'Score: %1$d%%. You can try again (%2$d attempts remaining).', 'ffcertificate' ), $quiz_score['percent'], $remaining )
							/* translators: %d: number of remaining quiz attempts */
							: sprintf( __( 'Not passed. You can try again (%d attempts remaining).', 'ffcertificate' ), $remaining );

						if ( -1 === $remaining ) {
							$msg = $show_score
								/* translators: %d: quiz score percentage */
								? sprintf( __( 'Score: %d%%. You can try again.', 'ffcertificate' ), $quiz_score['percent'] )
								: __( 'Not passed. You can try again.', 'ffcertificate' );
						}
					}

					wp_send_json_error(
						array(
							'message' => $msg,
							'quiz'    => array(
								'passed'    => false,
								'score'     => $show_score ? $quiz_score['score'] : null,
								'max_score' => $show_score ? $quiz_score['max_score'] : null,
								'percent'   => $show_score ? $quiz_score['percent'] : null,
								'attempt'   => $attempt_number,
								'remaining' => $remaining,
								'status'    => $quiz_status,
							),
						)
					);
				}
			}
		} else {
			// === Normal (non-quiz) flow ===.

			// Detect reprint — delegated to ReprintDetector.
			$reprint_result = ReprintDetector::detect( $form_id, $val_cpf, $val_ticket );
			$is_reprint     = $reprint_result['is_reprint'];

			if ( $is_reprint ) {
				// Reprint - use existing submission ID (convert to int from wpdb string).
				$submission_id        = (int) $reprint_result['id'];
				$real_submission_date = $reprint_result['date'];

				// Surface the existing auth_code so the success card and message can show it.
				if ( isset( $reprint_result['data']['auth_code'] ) && empty( $submission_data['auth_code'] ) ) {
					$submission_data['auth_code'] = $reprint_result['data']['auth_code'];
				}
			} else {
				// New submission - save to database.
				$submission_id = $this->submission_handler->process_submission(
					$form_id,
					$form_post_title,
					$submission_data,
					$user_email,
					$fields_config,
					$form_config
				);

				if ( is_wp_error( $submission_id ) ) {
					// 6.6.4 Sprint 7 — see the quiz branch above for the
					// rationale. Empathetic top-level message; raw repo
					// error preserved under `detail` for admin triage.
					wp_send_json_error(
						array(
							'code'    => $submission_id->get_error_code(),
							'message' => __( "We couldn't save your submission. Try again — if the problem persists, contact the organizer.", 'ffcertificate' ),
							'detail'  => $submission_id->get_error_message(),
						)
					);
				}

				// Get the submission date from the newly created submission.
				$real_submission_date = current_time( 'mysql' );

				// Remove used ticket if applicable — delegated to AccessRestrictionChecker.
				if ( $restriction_result['is_ticket'] && ! empty( $val_ticket ) ) {
					AccessRestrictionChecker::consume_ticket( $form_id, $val_ticket );
				}

				// #366 Sprint 6 — persist the override TIME columns on
				// the freshly inserted row + emit the two audit log
				// entries. Runs only on new submissions (reprint path
				// returns the original row unmodified — exceptions are
				// strictly one-use). The override write is a separate
				// UPDATE rather than threaded into the insert payload
				// so SubmissionHandler's public signature stays
				// unchanged; the brief window where the row carries
				// NULL overrides is benign because the placeholder
				// resolver in Sprint 7 falls back to baseline anyway.
				// `$has_exception` and `$schedule_exception_payload`
				// are correlated above (both set together inside the
				// verify_token branch), so the `null` payload case is
				// already excluded by the boolean — PHPStan tracks the
				// correlation, no defensive null-check needed.
				if ( $has_exception ) {
					$this->persist_schedule_exception(
						(int) $submission_id,
						$form_id,
						(array) $schedule_exception_payload,
						(string) $val_cpf
					);
				}
			}
		}

		// Generate PDF data.
		$pdf_generator = new \FreeFormCertificate\Generators\PdfGenerator();
		$pdf_data      = $pdf_generator->generate_pdf_data(
			$submission_id,
			$this->submission_handler
		);

		if ( is_wp_error( $pdf_data ) ) {
			wp_send_json_error(
				array(
					'code'    => $pdf_data->get_error_code(),
					'message' => $pdf_data->get_error_message(),
				)
			);
		}

		// Success message with HTML response (v2.9.7+).
		// The auth code itself is rendered in the success card by
		// templates/submission-success.php (dedicated "Authentication
		// Code:" row), so we deliberately keep it out of $msg to avoid
		// showing the same code twice on reprint.
		$custom_message = isset( $form_config['success_message'] ) ? trim( $form_config['success_message'] ) : '';
		$msg            = $is_reprint
			? __( 'Certificate previously issued (Reprint).', 'ffcertificate' )
			: ( ! empty( $custom_message ) ? $custom_message : __( 'Success!', 'ffcertificate' ) );

		// Quiz passed message.
		if ( $is_quiz && ! $is_reprint ) {
			$show_score = ( $form_config['quiz_show_score'] ?? '1' ) === '1';
			$msg        = $show_score
				/* translators: %d: quiz score percentage */
				? sprintf( __( 'Congratulations! Score: %d%%. Certificate generated.', 'ffcertificate' ), $quiz_score['percent'] )
				: __( 'Congratulations! Quiz passed. Certificate generated.', 'ffcertificate' );
		}

        // phpcs:enable WordPress.Security.NonceVerification.Missing

		$response = array(
			'message'  => $msg,
			'pdf_data' => $pdf_data,
			'html'     => \FreeFormCertificate\Core\Utils::generate_success_html(
				$submission_data,
				$form_id,
				$real_submission_date,
				$msg,
				(int) $submission_id,
				$this->submission_handler
			),
		);

		// Add quiz data to success response.
		if ( $is_quiz ) {
			$show_score       = ( $form_config['quiz_show_score'] ?? '1' ) === '1';
			$response['quiz'] = array(
				'passed'    => true,
				'score'     => $show_score ? $quiz_score['score'] : null,
				'max_score' => $show_score ? $quiz_score['max_score'] : null,
				'percent'   => $show_score ? $quiz_score['percent'] : null,
			);
		}

		wp_send_json_success( $response );
	}

	/**
	 * Calculate quiz score based on field points
	 *
	 * @param array<int, array<string, mixed>> $fields_config Form fields configuration.
	 * @param array<string, mixed>             $submission_data User's submitted data.
	 * @return array{score: int, max_score: int, percent: int}
	 */
	private function calculate_quiz_score( array $fields_config, array $submission_data ): array {
		$score     = 0;
		$max_score = 0;

		foreach ( $fields_config as $field ) {
			$type       = $field['type'] ?? '';
			$points_str = $field['points'] ?? '';

			// Only radio/select fields with points participate in scoring.
			if ( empty( $points_str ) || ! in_array( $type, array( 'radio', 'select' ), true ) ) {
				continue;
			}

			$options = array_map( 'trim', explode( ',', $field['options'] ?? '' ) );
			$points  = array_map( 'intval', array_map( 'trim', explode( ',', $points_str ) ) );

			// Max score: highest point value for this field.
			$max_score += max( $points );

			// User's answer.
			$name       = $field['name'] ?? '';
			$user_value = isset( $submission_data[ $name ] ) ? trim( (string) $submission_data[ $name ] ) : '';

			// Find matching option index and get its points.
			foreach ( $options as $i => $opt ) {
				if ( trim( $opt ) === $user_value && isset( $points[ $i ] ) ) {
					$score += $points[ $i ];
					break;
				}
			}
		}

		$percent = $max_score > 0 ? (int) round( ( $score / $max_score ) * 100 ) : 0;

		return array(
			'score'     => $score,
			'max_score' => $max_score,
			'percent'   => $percent,
		);
	}

	/**
	 * Find existing quiz submission for a CPF + form combination
	 *
	 * Returns the most recent submission (any quiz status: in_progress, failed, or publish).
	 *
	 * @param int    $form_id Form ID.
	 * @param string $cpf     CPF/RF value.
	 * @return (\stdClass&object{id: numeric-string, status: string, data: string|null, submission_date: string})|null
	 */
	private function find_quiz_submission( int $form_id, string $cpf ): ?object {
		if ( empty( $cpf ) ) {
			return null;
		}

		global $wpdb;
		$table     = \FreeFormCertificate\Core\Utils::get_submissions_table();
		$clean_cpf = \FreeFormCertificate\Core\DataSanitizer::normalize_cpf_rf( $cpf );

		if ( class_exists( '\FreeFormCertificate\Core\Encryption' ) && \FreeFormCertificate\Core\Encryption::is_configured() ) {
			$id_hash     = \FreeFormCertificate\Core\Encryption::hash( $clean_cpf );
			$hash_column = strlen( $clean_cpf ) === 7 ? 'rf_hash' : 'cpf_hash';

			// Search the specific split column based on digit count.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $hash_column is derived from strlen() check, not user input.
			/**
			 * Cast wpdb result to typed shape.
			 *
			 * @var (\stdClass&object{id: numeric-string, status: string, data: string|null, submission_date: string})|null $result
			 */
			$result = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM %i WHERE form_id = %d AND {$hash_column} = %s ORDER BY id DESC LIMIT 1",
					$table,
					$form_id,
					$id_hash
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			return $result;
		}

		return null;
	}

	/**
	 * Persist a verified schedule-exception payload onto an existing
	 * submission row and emit the matching audit pair. Factored out of
	 * {@see handle_submission_ajax()} so the (already large) submission
	 * pipeline keeps its single-responsibility intent while this side
	 * effect lives in one auditable place.
	 *
	 * Two writes:
	 *   1. `$wpdb` UPDATE on `ffc_submissions` setting
	 *      `schedule_start_override` / `schedule_end_override` to the
	 *      verified payload values. Either column stays NULL when the
	 *      operator left that end at baseline.
	 *   2. Two `ActivityLog::log()` rows (#366 Sprint 1 constants):
	 *      `ACTION_SCHEDULE_OVERRIDE_CREATED` carries the full context
	 *      JSON the admin renderer in Sprint 9 will pretty-print —
	 *      form_id, submission_id, participant CPF hash, operator CPF
	 *      hash + masked, the override range, and a `ts` (Category A
	 *      unix UTC int, NOT the activity_log `created_at` DATETIME
	 *      column which is housekeeping per CLAUDE.md). The companion
	 *      `ACTION_OPERATOR_IP_BYPASS` carries the bypassed IP so a
	 *      reviewer can correlate the exception with the venue's
	 *      outbound IP for one-time audits.
	 *
	 * @param int                  $submission_id Newly inserted row.
	 * @param int                  $form_id       Form post id.
	 * @param array<string, mixed> $payload       Verified token payload.
	 * @param string               $participant_cpf_plain Plaintext participant
	 *                                                    CPF digits (already
	 *                                                    validated upstream).
	 *                                                    Used to derive the
	 *                                                    audit `participant_cpf_hash`
	 *                                                    without re-fetching
	 *                                                    the row.
	 */
	private function persist_schedule_exception(
		int $submission_id,
		int $form_id,
		array $payload,
		string $participant_cpf_plain
	): void {
		$start_override = isset( $payload['start'] ) && is_string( $payload['start'] ) ? $payload['start'] : null;
		$end_override   = isset( $payload['end'] ) && is_string( $payload['end'] ) ? $payload['end'] : null;

		$update_data = array();
		if ( null !== $start_override && '' !== $start_override ) {
			$update_data['schedule_start_override'] = $start_override;
		}
		if ( null !== $end_override && '' !== $end_override ) {
			$update_data['schedule_end_override'] = $end_override;
		}
		if ( ! empty( $update_data ) ) {
			$this->submission_handler->get_repository()->update( $submission_id, $update_data );
		}

		if ( ! class_exists( '\FreeFormCertificate\Core\ActivityLog' ) ) {
			return;
		}

		$participant_cpf_hash = '' !== $participant_cpf_plain ? hash( 'sha256', $participant_cpf_plain ) : '';

		// Action tags + level resolve to ActivityLog's class constants
		// (`ACTION_SCHEDULE_OVERRIDE_CREATED`, `ACTION_OPERATOR_IP_BYPASS`,
		// `LEVEL_INFO`). They're inlined as literals here so the
		// Sprint 6 unit tests can spy on the static log() call via a
		// Mockery `alias:` mock without having to preserve the real
		// class's constants (alias mocks define an empty class). The
		// values are pinned by `test_schedule_override_action_constants_are_defined`
		// in ActivityLogTest.
		\FreeFormCertificate\Core\ActivityLog::log(
			'schedule_override_created',
			'info',
			array(
				'form_id'              => $form_id,
				'submission_id'        => $submission_id,
				'participant_cpf_hash' => $participant_cpf_hash,
				'operator_cpf_hash'    => (string) ( $payload['operator_cpf_hash'] ?? '' ),
				'operator_cpf_masked'  => (string) ( $payload['operator_cpf_masked'] ?? '' ),
				'schedule_start_after' => $start_override,
				'schedule_end_after'   => $end_override,
				'ts'                   => time(),
			),
			0,
			$submission_id
		);

		\FreeFormCertificate\Core\ActivityLog::log(
			'operator_ip_bypass',
			'info',
			array(
				'form_id'             => $form_id,
				'submission_id'       => $submission_id,
				'bypassed_ip'         => \FreeFormCertificate\Core\Utils::get_user_ip(),
				'operator_cpf_hash'   => (string) ( $payload['operator_cpf_hash'] ?? '' ),
				'operator_cpf_masked' => (string) ( $payload['operator_cpf_masked'] ?? '' ),
				'ts'                  => time(),
			),
			0,
			$submission_id
		);
	}
}
