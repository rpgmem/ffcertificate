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
 * v3.2.0: Migrated to namespace
 * v4.6.8: Extracted rendering to VerificationResponseRenderer (M7 refactoring)
 * 6.43.0: One lookup class per kind (certificate, appointment, reregistration);
 *         this class keeps the routing, the rate limit and the responses (#2241)
 *
 * @package FreeFormCertificate\Frontend
 */

declare(strict_types=1);

namespace FreeFormCertificate\Frontend;

use FreeFormCertificate\Core\Utils;
use FreeFormCertificate\Core\RequestInput;

use FreeFormCertificate\Submissions\SubmissionHandler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handler for verification operations.
 */
class VerificationHandler {

	/**
	 * Submission handler.
	 *
	 * @var SubmissionHandler|null
	 */
	private $submission_handler;
	/**
	 * Renderer.
	 *
	 * @var VerificationResponseRenderer
	 */
	private VerificationResponseRenderer $renderer;

	/**
	 * Certificate lookup.
	 *
	 * @var CertificateVerificationLookup
	 */
	private CertificateVerificationLookup $certificates;

	/**
	 * Appointment lookup.
	 *
	 * @var AppointmentVerificationLookup
	 */
	private AppointmentVerificationLookup $appointments;

	/**
	 * Reregistration lookup.
	 *
	 * @var ReregistrationVerificationLookup
	 */
	private ReregistrationVerificationLookup $reregistrations;

	/**
	 * Constructor
	 *
	 * @param SubmissionHandler|null $submission_handler Submission handler dependency.
	 */
	public function __construct( ?SubmissionHandler $submission_handler = null ) {
		$this->submission_handler = $submission_handler;
		$this->renderer           = new VerificationResponseRenderer();
		$this->certificates       = new CertificateVerificationLookup( fn(): SubmissionHandler => $this->submission_handler() );
		$this->appointments       = new AppointmentVerificationLookup();
		$this->reregistrations    = new ReregistrationVerificationLookup();
	}

	/**
	 * Resolve the submission handler, instantiating a fresh one if none was injected.
	 */
	private function submission_handler(): SubmissionHandler {
		if ( null === $this->submission_handler ) {
			$this->submission_handler = new SubmissionHandler();
		}
		return $this->submission_handler;
	}

	/**
	 * Search for a document by its code.
	 *
	 * Supports virtual prefix routing (C/R/A): the kind the prefix names is
	 * searched first, then the others. Without a prefix, certificates come
	 * first, then appointments, then reregistrations.
	 *
	 * @param string $auth_code Auth code.
	 * @since 5.0.0 Added prefix-based routing.
	 * @return array<string, mixed>
	 */
	private function search_certificate( string $auth_code ): array {
		$parsed     = \FreeFormCertificate\Core\DocumentFormatter::parse_prefixed_code( $auth_code );
		$clean_code = $parsed['code'];
		$prefix     = $parsed['prefix'];

		if ( empty( $clean_code ) ) {
			return VerificationResult::not_found();
		}

		if ( \FreeFormCertificate\Core\DocumentFormatter::PREFIX_APPOINTMENT === $prefix ) {
			// A = Appointment first, then certificates, then reregistrations.
			$appointment_result = $this->appointments->by_code( $clean_code );
			if ( $appointment_result['found'] ) {
				return $appointment_result;
			}
			$submission = $this->certificates->by_code( $clean_code );
			if ( ! $submission ) {
				return $this->reregistrations->by_code( $clean_code );
			}
		} elseif ( \FreeFormCertificate\Core\DocumentFormatter::PREFIX_REREGISTRATION === $prefix ) {
			// R = Reregistration first, then certificates, then appointments.
			$rereg_result = $this->reregistrations->by_code( $clean_code );
			if ( $rereg_result['found'] ) {
				return $rereg_result;
			}
			$submission = $this->certificates->by_code( $clean_code );
			if ( ! $submission ) {
				return $this->appointments->by_code( $clean_code );
			}
		} else {
			// C prefix or no prefix: certificates, then appointments, then reregistrations.
			$submission = $this->certificates->by_code( $clean_code );
			if ( ! $submission ) {
				$appointment_result = $this->appointments->by_code( $clean_code );
				if ( $appointment_result['found'] ) {
					return $appointment_result;
				}
				return $this->reregistrations->by_code( $clean_code );
			}
		}

		return $this->certificates->code_result( $submission );
	}

	/**
	 * What the page says about a revoked certificate.
	 *
	 * A certificate trashed before the date was recorded is said to be revoked
	 * with no date.
	 *
	 * @param int $revoked_at Unix UTC seconds, or 0 when unknown.
	 * @return string
	 */
	public static function revoked_message( int $revoked_at ): string {
		if ( $revoked_at <= 0 ) {
			return __( 'This certificate was revoked.', 'ffcertificate' );
		}
		return sprintf(
			/* translators: %s: the date the certificate was revoked */
			__( 'This certificate was revoked on %s.', 'ffcertificate' ),
			\FreeFormCertificate\Core\DateFormatter::format_date( $revoked_at )
		);
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
		\FreeFormCertificate\Core\Debug::log_frontend(
			'Magic token verification started',
			array(
				'token_preview' => substr( $token, 0, 8 ) . '...',
				'token_length'  => strlen( $token ),
			)
		);

		// Rate limiting check — must run BEFORE format validation to prevent.
		// attackers from probing token formats without being throttled.
		$user_ip    = \FreeFormCertificate\Core\RequestInput::get_user_ip();
		$rate_check = \FreeFormCertificate\Security\RateLimiter::check_verification( $user_ip );
		if ( ! $rate_check['allowed'] ) {
			\FreeFormCertificate\Core\Debug::log_frontend( 'Magic token rate limited' );
			return array(
				'found'       => false,
				'submission'  => null,
				'data'        => array(),
				'error'       => 'rate_limited',
				'message'     => $rate_check['message'],
				'magic_token' => '',
			);
		}

		if ( ! \FreeFormCertificate\Generators\MagicLinkHelper::is_valid_token( $token ) ) {
			\FreeFormCertificate\Core\Debug::log_frontend( 'Magic token invalid format' );
			return array(
				'found'       => false,
				'submission'  => null,
				'data'        => array(),
				'error'       => 'invalid_token_format',
				'magic_token' => '',
			);
		}

		// A quiz attempt that did not pass has a token but is not a
		// certificate (#1744).
		$submission = $this->certificates->by_token( $token );
		if ( null !== $submission && CertificateVerificationLookup::is_revoked( $submission ) ) {
			return CertificateVerificationLookup::revoked_result( $submission ) + array( 'magic_token' => '' );
		}

		\FreeFormCertificate\Core\Debug::log_frontend(
			'Magic token lookup result',
			array(
				'found'         => ! empty( $submission ),
				'submission_id' => $submission ? $submission['id'] : null,
			)
		);

		// Not a certificate: an appointment, then a reregistration.
		if ( ! $submission ) {
			$appointment_result = $this->appointments->by_token( $token );
			if ( $appointment_result['found'] ) {
				return $appointment_result;
			}

			$rereg_result = $this->reregistrations->by_token( $token );
			if ( $rereg_result['found'] ) {
				return $rereg_result;
			}

			return VerificationResult::not_found() + array( 'magic_token' => '' );
		}

		return $this->certificates->token_result( $submission, $token );
	}

	/**
	 * Verify certificate (used by shortcode fallback - non-AJAX)
	 * Returns array with 'success' (bool), 'html' (string), 'message' (string)
	 *
	 * @param string $auth_code Auth code.
	 * @return array<string, mixed>
	 */
	public function verify_certificate( string $auth_code ): array {
		// Throttle the no-JS fallback with the same verification rate-limit pool
		// as the AJAX handlers (#839 S11) — before any lookup, so auth-code
		// probing is throttled regardless of which entry point is used. The
		// AJAX paths (handle_verification_ajax / verify_by_magic_token) already
		// gate here; this fallback was the one hole.
		$user_ip    = \FreeFormCertificate\Core\RequestInput::get_user_ip();
		$rate_check = \FreeFormCertificate\Security\RateLimiter::check_verification( $user_ip );
		if ( ! $rate_check['allowed'] ) {
			return array(
				'success' => false,
				'html'    => '',
				'message' => $rate_check['message'],
			);
		}

		$result = $this->search_certificate( $auth_code );
		if ( $result['found'] && isset( $result['submission']->id ) ) {
			// Log access for LGPD compliance.
			if ( class_exists( '\\FreeFormCertificate\\Core\\ActivityLog' ) ) {
				\FreeFormCertificate\Core\ActivityLog::log_data_accessed(
					(int) $result['submission']->id,
					array(
						'method'    => 'manual_verification',
						'auth_code' => substr( $auth_code, 0, 4 ) . '...',
						'ip'        => \FreeFormCertificate\Core\RequestInput::get_user_ip(),
					)
				);
			}
		}

		if ( ! empty( $result['revoked'] ) ) {
			return array(
				'success' => false,
				'html'    => '',
				'message' => self::revoked_message( (int) $result['revoked_at'] ),
			);
		}

		if ( ! $result['found'] ) {
			return array(
				'success' => false,
				'html'    => '',
				'message' => __( 'Document not found or invalid code.', 'ffcertificate' ),
			);
		}

		$html = $this->response_html( $result );

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

		$token = RequestInput::get_post_string( 'token' );

		// The rate limit is counted once, by verify_by_magic_token(), before
		// it reads the token: checking here too spent two attempts of the
		// address's budget on every open (#2241).
		if ( empty( $token ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid token.', 'ffcertificate' ) ) );
		}

		// Verify by magic token.
		$result = $this->verify_by_magic_token( $token );

		if ( isset( $result['error'] ) && 'rate_limited' === $result['error'] ) {
			wp_send_json_error(
				array(
					'message' => '' !== ( $result['message'] ?? '' ) ? $result['message'] : __( 'Too many verification attempts. Please try again later.', 'ffcertificate' ),
				)
			);
		}

		if ( ! empty( $result['revoked'] ) ) {
			wp_send_json_error(
				array(
					'message' => self::revoked_message( (int) $result['revoked_at'] ),
					'revoked' => true,
				)
			);
		}

		if ( ! $result['found'] ) {
			wp_send_json_error(
				array(
					'message' => __( 'Document not found or invalid link.', 'ffcertificate' ),
				)
			);
		}

		$pdf_data = $this->pdf_data( $result );

		if ( is_wp_error( $pdf_data ) ) {
			wp_send_json_error( array( 'message' => $pdf_data->get_error_message() ) );
		}

		$html = $this->response_html( $result );

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
		// 6.6.3 — explicit wp_verify_nonce with stale-nonce auto-recovery.
		// Was check_ajax_referer( 'ffc_frontend_nonce', 'nonce' ), which
		// wp_die()s with a 403 on stale-nonce — leaving cached-HTML / iOS
		// Safari visitors stuck. Same root causes as the form-submission
		// path covered in form-processor.php: cached page nonces from
		// another visitor, Safari ITP / Private Relay rotating cookies,
		// ffc-dynamic-fragments silently failing to refresh. The fresh
		// nonce is keyed to the current session cookie, so FFC.request
		// can transparently retry once via its existing refresh_nonce
		// branch.
		if ( ! wp_verify_nonce( RequestInput::get_post_string( 'nonce' ), 'ffc_frontend_nonce' ) ) {
			wp_send_json_error(
				array(
					'message'       => __( 'Security check failed. Please refresh the page.', 'ffcertificate' ),
					'refresh_nonce' => true,
					'new_nonce'     => wp_create_nonce( 'ffc_frontend_nonce' ),
				)
			);
		}

		// Validate honeypot + captcha via centralised service.
		$security_check = \FreeFormCertificate\Core\SecurityService::validate_security_fields( $_POST );
		if ( true !== $security_check ) {
			wp_send_json_error(
				\FreeFormCertificate\Core\SecurityService::with_fresh_challenge(
					array( 'message' => $security_check )
				)
			);
		}

		$user_ip    = \FreeFormCertificate\Core\RequestInput::get_user_ip();
		$rate_check = \FreeFormCertificate\Security\RateLimiter::check_verification( $user_ip );
		if ( ! $rate_check['allowed'] ) {
			wp_send_json_error(
				\FreeFormCertificate\Core\SecurityService::with_fresh_challenge(
					array(
						'message' => __( 'Too many verification attempts. Please try again later.', 'ffcertificate' ),
					)
				)
			);
		}

		$auth_code = RequestInput::get_post_string( 'ffc_auth_code' );
		$result    = $this->search_certificate( $auth_code );

		if ( ! empty( $result['revoked'] ) ) {
			wp_send_json_error(
				\FreeFormCertificate\Core\SecurityService::with_fresh_challenge(
					array(
						'message' => self::revoked_message( (int) $result['revoked_at'] ),
						'revoked' => true,
					)
				)
			);
		}

		if ( ! $result['found'] ) {
			wp_send_json_error(
				\FreeFormCertificate\Core\SecurityService::with_fresh_challenge(
					array( 'message' => __( 'Document not found or invalid code.', 'ffcertificate' ) )
				)
			);
		}

		$pdf_data = $this->pdf_data( $result );

		if ( is_wp_error( $pdf_data ) ) {
			wp_send_json_error( \FreeFormCertificate\Core\SecurityService::with_fresh_challenge( array( 'message' => $pdf_data->get_error_message() ) ) );
		}

		$html = $this->response_html( $result );

		wp_send_json_success(
			array(
				'html'          => $html,
				'submission_id' => $result['submission']->id,
				'pdf_data'      => $pdf_data,
			)
		);
	}

	/**
	 * The PDF data for a found document, by kind.
	 *
	 * @param array<string, mixed> $result A found result.
	 * @return array<string, mixed>|\WP_Error|null
	 */
	private function pdf_data( array $result ) {
		$type = $result['type'] ?? '';

		if ( 'appointment' === $type && ! empty( $result['appointment'] ) ) {
			return $this->renderer->generate_appointment_verification_pdf( $result, new \FreeFormCertificate\Generators\PdfGenerator() );
		}
		if ( 'reregistration' === $type ) {
			return \FreeFormCertificate\Reregistration\RecordGenerator::generate_record_data( (int) $result['reregistration']['submission_id'] );
		}
		return ( new \FreeFormCertificate\Generators\PdfGenerator() )->generate_pdf_data(
			(int) $result['submission']->id,
			$this->submission_handler()
		);
	}

	/**
	 * The verification page's HTML for a found document, by kind.
	 *
	 * @param array<string, mixed> $result A found result.
	 * @return string
	 */
	private function response_html( array $result ): string {
		$type = $result['type'] ?? '';

		if ( 'appointment' === $type ) {
			return $this->renderer->format_appointment_verification_response( $result );
		}
		if ( 'reregistration' === $type ) {
			return $this->renderer->format_reregistration_verification_response( $result );
		}
		return $this->renderer->format_verification_response( $result['submission'], $result['data'], true );
	}
}
