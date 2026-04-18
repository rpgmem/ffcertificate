<?php
/**
 * VerificationHandler
 * Handles certificate verification and authenticity checks.
 *
 * V2.8.0: Added magic link verification with rate limiting
 * v2.9.0: Added QR Code support in verification
 * v2.9.2: Unified PDF generation with FFC_PDF_Generator, removed prepare_pdf_data()
 * v2.10.0: ENCRYPTION - Auto-decryption via Submission Handler + Access logging
 * v3.3.0: Added strict types and type hints
 * v3.2.0: Migrated to namespace (Phase 2)
 * v4.6.8: Extracted rendering to VerificationResponseRenderer (M7 refactoring)
 *
 * @package FreeFormCertificate\Frontend
 */

declare(strict_types=1);

namespace FreeFormCertificate\Frontend;

use FreeFormCertificate\Submissions\SubmissionHandler;
use FreeFormCertificate\Repositories\SubmissionRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handler for verification operations.
 */
class VerificationHandler {

	/** @var SubmissionHandler|null */
	private $submission_handler;
	private VerificationResponseRenderer $renderer;

	/**
	 * Constructor
	 *
	 * @param SubmissionHandler|null $submission_handler Submission handler dependency.
	 */
	public function __construct( ?SubmissionHandler $submission_handler = null ) {
		$this->submission_handler = $submission_handler;
		$this->renderer           = new VerificationResponseRenderer();
	}

	/**
	 * Search for certificate by authentication code
	 *
	 * V2.9.15: RECONSTRUÇÃO - Combina colunas + JSON
	 */
	/**
	 * Search for certificate - uses Repository.
	 *
	 * Supports virtual prefix routing (C/R/A) for faster lookups.
	 * When a prefix is detected, the corresponding table is searched first,
	 * with fallback to other tables. Without prefix, all tables are searched
	 * sequentially (backwards compatible).
	 *
	 * @since 4.13.0 Added prefix-based routing.
	 * @return array<string, mixed>
	 */
	private function search_certificate( string $auth_code ): array {
		$parsed     = \FreeFormCertificate\Core\DocumentFormatter::parse_prefixed_code( $auth_code );
		$clean_code = $parsed['code'];
		$prefix     = $parsed['prefix'];

		if ( empty( $clean_code ) ) {
			return array(
				'found'      => false,
				'submission' => null,
				'data'       => array(),
			);
		}

		$repository = new SubmissionRepository();

		// Prefix-based routing: search the hinted table first.
		if ( \FreeFormCertificate\Core\DocumentFormatter::PREFIX_APPOINTMENT === $prefix ) {
			// A = Appointment first.
			$appointment_result = $this->search_appointment_by_code( $clean_code );
			if ( $appointment_result['found'] ) {
				return $appointment_result;
			}
			// Fallback: certificates, then reregistrations.
			$submission = $repository->findByAuthCode( $clean_code );
			if ( ! $submission ) {
				return $this->search_reregistration_by_code( $clean_code );
			}
		} elseif ( \FreeFormCertificate\Core\DocumentFormatter::PREFIX_REREGISTRATION === $prefix ) {
			// R = Reregistration first.
			$rereg_result = $this->search_reregistration_by_code( $clean_code );
			if ( $rereg_result['found'] ) {
				return $rereg_result;
			}
			// Fallback: certificates, then appointments.
			$submission = $repository->findByAuthCode( $clean_code );
			if ( ! $submission ) {
				return $this->search_appointment_by_code( $clean_code );
			}
		} else {
			// C prefix or no prefix: certificates first (original behavior).
			$submission = $repository->findByAuthCode( $clean_code );

			// Fallback: search appointments by validation_code.
			if ( ! $submission ) {
				$appointment_result = $this->search_appointment_by_code( $clean_code );
				if ( $appointment_result['found'] ) {
					return $appointment_result;
				}

				// Fallback: search reregistration submissions by auth_code.
				return $this->search_reregistration_by_code( $clean_code );
			}
		}

		// Decrypt.
		$submission = $this->submission_handler->decrypt_submission_data( $submission );

		// Rebuild data.
		$data = array(
			'email' => $submission['email'],
		);

		if ( ! empty( $submission['auth_code'] ) ) {
			$data['auth_code'] = $submission['auth_code'];
		}

		if ( ! empty( $submission['cpf_rf'] ) ) {
			$data['cpf_rf'] = $submission['cpf_rf'];
		}
		if ( ! empty( $submission['cpf'] ) ) {
			$data['cpf'] = $submission['cpf'];
		}
		if ( ! empty( $submission['rf'] ) ) {
			$data['rf'] = $submission['rf'];
		}

		$extra_data = json_decode( $submission['data'], true );
		if ( ! is_array( $extra_data ) ) {
			$extra_data = json_decode( wp_unslash( $submission['data'] ), true );
		}

		if ( is_array( $extra_data ) && ! empty( $extra_data ) ) {
			$data = array_merge( $extra_data, $data );
		}

		return array(
			'found'      => true,
			'submission' => (object) $submission,
			'data'       => $data,
		);
	}

	/**
	 * Search for appointment by validation code
	 *
	 * @since 4.2.0
	 * @param string $code Cleaned validation code (no hyphens).
	 * @return array<string, mixed> Result array with 'found', 'submission', 'data', 'type'
	 */
	private function search_appointment_by_code( string $code ): array {
		if ( ! class_exists( '\\FreeFormCertificate\\Repositories\\AppointmentRepository' ) ) {
			return array(
				'found'      => false,
				'submission' => null,
				'data'       => array(),
			);
		}

		$repo        = new \FreeFormCertificate\Repositories\AppointmentRepository();
		$appointment = $repo->findByValidationCode( $code );

		if ( ! $appointment ) {
			return array(
				'found'      => false,
				'submission' => null,
				'data'       => array(),
			);
		}

		return $this->build_appointment_result( $appointment );
	}

	/**
	 * Search for appointment by confirmation token (magic link)
	 *
	 * @since 4.2.0
	 * @param string $token Confirmation token (64 hex chars).
	 * @return array<string, mixed> Result array with 'found', 'submission', 'data', 'type'
	 */
	private function search_appointment_by_token( string $token ): array {
		if ( ! class_exists( '\\FreeFormCertificate\\Repositories\\AppointmentRepository' ) ) {
			return array(
				'found'      => false,
				'submission' => null,
				'data'       => array(),
			);
		}

		$repo        = new \FreeFormCertificate\Repositories\AppointmentRepository();
		$appointment = $repo->findByConfirmationToken( $token );

		if ( ! $appointment ) {
			return array(
				'found'      => false,
				'submission' => null,
				'data'       => array(),
			);
		}

		return $this->build_appointment_result( $appointment );
	}

	/**
	 * Build result array from appointment data
	 *
	 * @since 4.2.0
	 * @param array<string, mixed> $appointment Appointment data from database.
	 * @return array<string, mixed> Standardized result array
	 */
	private function build_appointment_result( array $appointment ): array {
		// Decrypt sensitive fields.
		$email = \FreeFormCertificate\Core\Encryption::decrypt_field( $appointment, 'email' );

		// Decrypt split cpf/rf columns.
		$cpf_val = \FreeFormCertificate\Core\Encryption::decrypt_field( $appointment, 'cpf' );
		$rf_val  = \FreeFormCertificate\Core\Encryption::decrypt_field( $appointment, 'rf' );
		// @deprecated legacy cpf_rf fallback — remove in next major version.
		$cpf_rf = ! empty( $cpf_val ) ? $cpf_val : ( ! empty( $rf_val ) ? $rf_val : \FreeFormCertificate\Core\Encryption::decrypt_field( $appointment, 'cpf_rf' ) );

		// Build data array.
		$data = array(
			'name'             => $appointment['name'] ?? '',
			'email'            => $email,
			'cpf_rf'           => $cpf_rf,
			'auth_code'        => $appointment['validation_code'] ?? '',
			'calendar_title'   => '',
			'appointment_date' => $appointment['appointment_date'] ?? '',
			'start_time'       => $appointment['start_time'] ?? '',
			'end_time'         => $appointment['end_time'] ?? '',
			'status'           => $appointment['status'] ?? 'pending',
			'magic_token'      => $appointment['confirmation_token'] ?? '',
		);

		// Get calendar title.
		if ( ! empty( $appointment['calendar_id'] ) ) {
			$calendar_repo = new \FreeFormCertificate\Repositories\CalendarRepository();
			$calendar      = $calendar_repo->findById( (int) $appointment['calendar_id'] );
			if ( $calendar ) {
				$data['calendar_title'] = $calendar['title'] ?? '';
			}
		}

		// Build a pseudo-submission object for compatibility with format methods.
		$pseudo_submission = array(
			'id'              => $appointment['id'],
			'form_id'         => 0,
			'submission_date' => $appointment['created_at'] ?? '',
			'auth_code'       => $appointment['validation_code'] ?? '',
			'email'           => $email,
			'cpf_rf'          => $cpf_rf,
			'data'            => '{}',
			'magic_token'     => $appointment['confirmation_token'] ?? '',
		);

		return array(
			'found'       => true,
			'submission'  => (object) $pseudo_submission,
			'data'        => $data,
			'type'        => 'appointment',
			'appointment' => $appointment,
			'magic_token' => $appointment['confirmation_token'] ?? '',
		);
	}

	/**
	 * Search for reregistration submission by auth_code.
	 *
	 * @since 4.12.0
	 * @param string $code Cleaned auth code (no hyphens, uppercase).
	 * @return array<string, mixed> Result array with 'found', 'submission', 'data', 'type'.
	 */
	private function search_reregistration_by_code( string $code ): array {
		if ( ! class_exists( '\\FreeFormCertificate\\Reregistration\\ReregistrationSubmissionRepository' ) ) {
			return array(
				'found'      => false,
				'submission' => null,
				'data'       => array(),
			);
		}

		$submission = \FreeFormCertificate\Reregistration\ReregistrationSubmissionRepository::get_by_auth_code( $code );
		if ( ! $submission ) {
			return array(
				'found'      => false,
				'submission' => null,
				'data'       => array(),
			);
		}

		$rereg = \FreeFormCertificate\Reregistration\ReregistrationRepository::get_by_id( (int) $submission->reregistration_id );
		$user  = get_userdata( (int) $submission->user_id );

		$fields = $this->decode_submission_fields( $submission, $rereg );

		$status_labels = \FreeFormCertificate\Reregistration\ReregistrationSubmissionRepository::get_status_labels();

		return array(
			'found'          => true,
			'submission'     => $submission,
			'data'           => $fields,
			'type'           => 'reregistration',
			'reregistration' => array(
				'title'         => $rereg ? $rereg->title : '',
				'display_name'  => $fields['display_name'] ?? ( $user ? $user->display_name : '' ),
				'cpf'           => $fields['cpf'] ?? '',
				'email'         => $user ? $user->user_email : '',
				'status'        => $submission->status,
				'status_label'  => $status_labels[ $submission->status ] ?? $submission->status,
				'auth_code'     => $submission->auth_code,
				'submitted_at'  => $submission->submitted_at ?? '',
				'submission_id' => (int) $submission->id,
			),
		);
	}

	/**
	 * Search for reregistration submission by magic_token.
	 *
	 * @since 4.12.0
	 * @param string $token Magic token (64 hex chars).
	 * @return array<string, mixed> Result array with 'found', 'submission', 'data', 'type'.
	 */
	private function search_reregistration_by_magic_token( string $token ): array {
		if ( ! class_exists( '\\FreeFormCertificate\\Reregistration\\ReregistrationSubmissionRepository' ) ) {
			return array(
				'found'      => false,
				'submission' => null,
				'data'       => array(),
			);
		}

		$submission = \FreeFormCertificate\Reregistration\ReregistrationSubmissionRepository::get_by_magic_token( $token );
		if ( ! $submission ) {
			return array(
				'found'      => false,
				'submission' => null,
				'data'       => array(),
			);
		}

		// Reuse the same result builder as auth_code verification.
		$rereg = \FreeFormCertificate\Reregistration\ReregistrationRepository::get_by_id( (int) $submission->reregistration_id );
		$user  = get_userdata( (int) $submission->user_id );

		$fields = $this->decode_submission_fields( $submission, $rereg );

		$status_labels = \FreeFormCertificate\Reregistration\ReregistrationSubmissionRepository::get_status_labels();

		return array(
			'found'          => true,
			'submission'     => $submission,
			'data'           => $fields,
			'type'           => 'reregistration',
			'magic_token'    => $submission->magic_token,
			'reregistration' => array(
				'title'         => $rereg ? $rereg->title : '',
				'display_name'  => $fields['display_name'] ?? ( $user ? $user->display_name : '' ),
				'cpf'           => $fields['cpf'] ?? '',
				'email'         => $user ? $user->user_email : '',
				'status'        => $submission->status,
				'status_label'  => $status_labels[ $submission->status ] ?? $submission->status,
				'auth_code'     => $submission->auth_code,
				'submitted_at'  => $submission->submitted_at ?? '',
				'submission_id' => (int) $submission->id,
			),
		);
	}

	/**
	 * Decode a reregistration submission's stored fields, decrypting any
	 * values whose field definition is marked as sensitive.
	 *
	 * @since 4.13.0
	 * @param object      $submission Submission row.
	 * @param object|null $rereg      Reregistration row.
	 * @return array<string, mixed> field_key => plain value.
	 */
	private function decode_submission_fields( object $submission, $rereg ): array {
		$sub_data = $submission->data ? json_decode( $submission->data, true ) : array();
		$values   = is_array( $sub_data['fields'] ?? null ) ? $sub_data['fields'] : array();

		if ( ! $rereg || ! class_exists( '\\FreeFormCertificate\\Reregistration\\CustomFieldRepository' ) ) {
			return $values;
		}

		if ( ! class_exists( '\\FreeFormCertificate\\Core\\Encryption' ) ) {
			return $values;
		}

		// Gather all active fields for the reregistration's audiences and.
		// decrypt sensitive values in place.
		$audience_ids = \FreeFormCertificate\Reregistration\ReregistrationRepository::get_audience_ids( (int) $rereg->id );
		$seen         = array();
		foreach ( $audience_ids as $aud_id ) {
			$fields = \FreeFormCertificate\Reregistration\CustomFieldRepository::get_by_audience_with_parents( (int) $aud_id, true );
			foreach ( $fields as $field ) {
				if ( isset( $seen[ (int) $field->id ] ) ) {
					continue;
				}
				$seen[ (int) $field->id ] = true;

				if ( empty( $field->is_sensitive ) ) {
					continue;
				}
				$key = (string) $field->field_key;
				if ( ! isset( $values[ $key ] ) || '' === $values[ $key ] || ! is_string( $values[ $key ] ) ) {
					continue;
				}
				$plain = \FreeFormCertificate\Core\Encryption::decrypt( $values[ $key ] );
				if ( null !== $plain ) {
					$values[ $key ] = $plain;
				}
			}
		}

		return $values;
	}

	/**
	 * Verify certificate by magic token
	 *
	 * This method bypasses captcha/honeypot validation and is used
	 * when accessing certificates via magic links.
	 *
	 * @since 2.8.0 Magic Links feature
	 * @param string $token Magic token (32 hex characters).
	 * @return array<string, mixed> Result array with 'found', 'submission', 'data', 'magic_token'
	 */
	public function verify_by_magic_token( string $token ): array {
		// Debug logging.
		\FreeFormCertificate\Core\Utils::debug_log(
			'Magic token verification started',
			array(
				'token_preview' => substr( $token, 0, 8 ) . '...',
				'token_length'  => strlen( $token ),
			)
		);

		// Rate limiting check — must run BEFORE format validation to prevent.
		// attackers from probing token formats without being throttled.
		$user_ip    = \FreeFormCertificate\Core\Utils::get_user_ip();
		$rate_check = \FreeFormCertificate\Security\RateLimiter::check_verification( $user_ip );
		if ( ! $rate_check['allowed'] ) {
			\FreeFormCertificate\Core\Utils::debug_log( 'Magic token rate limited' );
			return array(
				'found'       => false,
				'submission'  => null,
				'data'        => array(),
				'error'       => 'rate_limited',
				'magic_token' => '',
			);
		}

		if ( ! \FreeFormCertificate\Generators\MagicLinkHelper::is_valid_token( $token ) ) {
			\FreeFormCertificate\Core\Utils::debug_log( 'Magic token invalid format' );
			return array(
				'found'       => false,
				'submission'  => null,
				'data'        => array(),
				'error'       => 'invalid_token_format',
				'magic_token' => '',
			);
		}

		// Get submission by token.
		$submission = $this->submission_handler->get_submission_by_token( $token );

		\FreeFormCertificate\Core\Utils::debug_log(
			'Magic token lookup result',
			array(
				'found'         => ! empty( $submission ),
				'submission_id' => $submission ? $submission['id'] : null,
			)
		);

		// Fallback: search appointments by confirmation_token.
		if ( ! $submission ) {
			$appointment_result = $this->search_appointment_by_token( $token );
			if ( $appointment_result['found'] ) {
				return $appointment_result;
			}

			// Fallback: search reregistration submissions by magic_token.
			$rereg_result = $this->search_reregistration_by_magic_token( $token );
			if ( $rereg_result['found'] ) {
				return $rereg_result;
			}

			return array(
				'found'       => false,
				'submission'  => null,
				'data'        => array(),
				'magic_token' => '',
			);
		}

		// v2.10.0: Log access for LGPD compliance.
		if ( class_exists( '\\FreeFormCertificate\\Core\\ActivityLog' ) ) {
			\FreeFormCertificate\Core\ActivityLog::log_data_accessed(
				(int) $submission['id'],  // Convert to int (wpdb returns strings).
				array(
					'method' => 'magic_link',
					'token'  => substr( $token, 0, 8 ) . '...',
					'ip'     => \FreeFormCertificate\Core\Utils::get_user_ip(),
				)
			);
		}

		// v2.9.16: REBUILD complete data (columns + JSON)
		// v2.10.0: NOTE - Automatic decryption.
		// Data already comes decrypted from Submission Handler.
		// The get_submission_by_token() method calls decrypt_submission_data()
		// internally, so the fields email, cpf_rf, user_ip, data are already.
		// in plain text here. No need to decrypt again.

		// Step 1: Required fields from columns.
		$data = array();

		if ( ! empty( $submission['email'] ) ) {
			$data['email'] = $submission['email'];
		}

		if ( ! empty( $submission['auth_code'] ) ) {
			$data['auth_code'] = $submission['auth_code'];
		}

		if ( ! empty( $submission['cpf_rf'] ) ) {
			$data['cpf_rf'] = $submission['cpf_rf'];
		}

		// Passo 2: Campos extras do JSON.
		$extra_data = json_decode( $submission['data'], true );
		if ( ! is_array( $extra_data ) ) {
			$extra_data = json_decode( wp_unslash( $submission['data'] ), true );
		}

		// Step 3: Merge (columns have priority over JSON).
		if ( ! empty( $extra_data ) ) {
			$data = array_merge( $extra_data, $data );
		}

		// Ensure magic_token exists (fallback for old submissions).
		$magic_token = $submission['magic_token'];
		if ( empty( $magic_token ) ) {
			$magic_token = $this->submission_handler->ensure_magic_token( (int) $submission['id'] );
		}

		return array(
			'found'       => true,
			'submission'  => (object) $submission,
			'data'        => $data,
			'magic_token' => $magic_token,
		);
	}

	/**
	 * Verify certificate (used by shortcode fallback - non-AJAX)
	 * Returns array with 'success' (bool), 'html' (string), 'message' (string)
	 *
	 * @return array<string, mixed>
	 */
	public function verify_certificate( string $auth_code ): array {
		$result = $this->search_certificate( $auth_code );
		if ( $result['found'] && isset( $result['submission']->id ) ) {
			// Log access for LGPD compliance.
			if ( class_exists( '\\FreeFormCertificate\\Core\\ActivityLog' ) ) {
				\FreeFormCertificate\Core\ActivityLog::log_data_accessed(
					(int) $result['submission']->id,
					array(
						'method'    => 'manual_verification',
						'auth_code' => substr( $auth_code, 0, 4 ) . '...',
						'ip'        => \FreeFormCertificate\Core\Utils::get_user_ip(),
					)
				);
			}
		}

		if ( ! $result['found'] ) {
			return array(
				'success' => false,
				'html'    => '',
				'message' => __( 'Document not found or invalid code.', 'ffcertificate' ),
			);
		}

		if ( ! empty( $result['type'] ) && 'appointment' === $result['type'] ) {
			$html = $this->renderer->format_appointment_verification_response( $result );
		} elseif ( ! empty( $result['type'] ) && 'reregistration' === $result['type'] ) {
			$html = $this->renderer->format_reregistration_verification_response( $result );
		} else {
			$html = $this->renderer->format_verification_response( $result['submission'], $result['data'], true );
		}

		return array(
			'success' => true,
			'html'    => $html,
			'message' => '',
		);
	}

	/**
	 * Handle magic link verification via AJAX
	 *
	 * This endpoint bypasses security checks (captcha/honeypot) as the
	 * magic token itself provides authentication.
	 *
	 * @since 2.8.0 Magic Links feature
	 */
	public function handle_magic_verification_ajax(): void {
		// No nonce check - magic token is the authentication.
		// No captcha - token proves legitimacy.

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Magic token authentication; no nonce needed for this public endpoint.
		$token   = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		$user_ip = \FreeFormCertificate\Core\Utils::get_user_ip();

		$rate_check = \FreeFormCertificate\Security\RateLimiter::check_verification( $user_ip );
		if ( ! $rate_check['allowed'] ) {
			wp_send_json_error(
				array(
					'message' => __( 'Too many verification attempts. Please try again later.', 'ffcertificate' ),
				)
			);
		}

		if ( empty( $token ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid token.', 'ffcertificate' ) ) );
		}

		// Verify by magic token.
		$result = $this->verify_by_magic_token( $token );

		if ( isset( $result['error'] ) && 'rate_limited' === $result['error'] ) {
			wp_send_json_error(
				array(
					'message' => __( 'Too many attempts. Please try again in 1 minute.', 'ffcertificate' ),
				)
			);
		}

		if ( ! $result['found'] ) {
			wp_send_json_error(
				array(
					'message' => '❌ ' . __( 'Document not found or invalid link.', 'ffcertificate' ),
				)
			);
		}

		$pdf_generator = new \FreeFormCertificate\Generators\PdfGenerator();

		// Check if this is an appointment result.
		if ( ! empty( $result['type'] ) && 'appointment' === $result['type'] && ! empty( $result['appointment'] ) ) {
			$pdf_data = $this->renderer->generate_appointment_verification_pdf( $result, $pdf_generator );
		} elseif ( ! empty( $result['type'] ) && 'reregistration' === $result['type'] ) {
			$pdf_data = \FreeFormCertificate\Reregistration\FichaGenerator::generate_ficha_data( (int) $result['reregistration']['submission_id'] );
		} else {
			// Certificate: use standard PDF generator.
			$pdf_data = $pdf_generator->generate_pdf_data(
				(int) $result['submission']->id,
				$this->submission_handler
			);
		}

		if ( is_wp_error( $pdf_data ) ) {
			wp_send_json_error( array( 'message' => $pdf_data->get_error_message() ) );
		}

		// Format response HTML with download button.
		if ( ! empty( $result['type'] ) && 'appointment' === $result['type'] ) {
			$html = $this->renderer->format_appointment_verification_response( $result );
		} elseif ( ! empty( $result['type'] ) && 'reregistration' === $result['type'] ) {
			$html = $this->renderer->format_reregistration_verification_response( $result );
		} else {
			$html = $this->renderer->format_verification_response(
				$result['submission'],
				$result['data'],
				true
			);
		}

		wp_send_json_success(
			array(
				'html'          => $html,
				'submission_id' => $result['submission']->id,
				'pdf_data'      => $pdf_data,
			)
		);
	}

	/**
	 * Handle certificate verification via AJAX (manual - with captcha)
	 */
	public function handle_verification_ajax(): void {
		check_ajax_referer( 'ffc_frontend_nonce', 'nonce' );

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified above via check_ajax_referer.

		// Validate honeypot + captcha via centralised service.
		$security_check = \FreeFormCertificate\Core\SecurityService::validate_security_fields( $_POST );
		if ( true !== $security_check ) {
			$new_captcha = \FreeFormCertificate\Core\Utils::generate_simple_captcha();
			wp_send_json_error(
				array(
					'message'         => $security_check,
					'refresh_captcha' => true,
					'new_label'       => $new_captcha['label'],
					'new_hash'        => $new_captcha['hash'],
				)
			);
		}

		$user_ip    = \FreeFormCertificate\Core\Utils::get_user_ip();
		$rate_check = \FreeFormCertificate\Security\RateLimiter::check_verification( $user_ip );
		if ( ! $rate_check['allowed'] ) {
			wp_send_json_error(
				array(
					'message' => __( 'Too many verification attempts. Please try again later.', 'ffcertificate' ),
				)
			);
		}

		$auth_code = isset( $_POST['ffc_auth_code'] ) ? sanitize_text_field( wp_unslash( $_POST['ffc_auth_code'] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing
		$result = $this->search_certificate( $auth_code );

		if ( ! $result['found'] ) {
			$new_captcha = \FreeFormCertificate\Core\Utils::generate_simple_captcha();
			wp_send_json_error(
				array(
					'message'         => '❌ ' . __( 'Document not found or invalid code.', 'ffcertificate' ),
					'refresh_captcha' => true,
					'new_label'       => $new_captcha['label'],
					'new_hash'        => $new_captcha['hash'],
				)
			);
		}

		$pdf_generator = new \FreeFormCertificate\Generators\PdfGenerator();

		// Check if this is an appointment result.
		if ( ! empty( $result['type'] ) && 'appointment' === $result['type'] && ! empty( $result['appointment'] ) ) {
			$pdf_data = $this->renderer->generate_appointment_verification_pdf( $result, $pdf_generator );
		} elseif ( ! empty( $result['type'] ) && 'reregistration' === $result['type'] ) {
			$pdf_data = \FreeFormCertificate\Reregistration\FichaGenerator::generate_ficha_data( (int) $result['reregistration']['submission_id'] );
		} else {
			// Certificate: use standard PDF generator.
			$pdf_data = $pdf_generator->generate_pdf_data(
				(int) $result['submission']->id,
				$this->submission_handler
			);
		}

		if ( is_wp_error( $pdf_data ) ) {
			wp_send_json_error( array( 'message' => $pdf_data->get_error_message() ) );
		}

		if ( ! empty( $result['type'] ) && 'appointment' === $result['type'] ) {
			$html = $this->renderer->format_appointment_verification_response( $result );
		} elseif ( ! empty( $result['type'] ) && 'reregistration' === $result['type'] ) {
			$html = $this->renderer->format_reregistration_verification_response( $result );
		} else {
			$html = $this->renderer->format_verification_response(
				$result['submission'],
				$result['data'],
				true
			);
		}

		wp_send_json_success(
			array(
				'html'          => $html,
				'submission_id' => $result['submission']->id,
				'pdf_data'      => $pdf_data,
			)
		);
	}
}
