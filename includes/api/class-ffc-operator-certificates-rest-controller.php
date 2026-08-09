<?php
/**
 * OperatorCertificatesRestController
 *
 * Authenticated REST surface for a field-operator app (issue #858, #840 Part B).
 * Certificates are issued by an authenticated operator holding a management
 * capability, over WordPress Application Passwords (Basic Auth → wp_set_current_user,
 * so capability checks work with no nonce).
 *
 * Two routes under ffc/v1:
 *   - POST /operator/certificates            — issue a certificate (A1)
 *   - GET  /operator/certificates/{id}/pdf   — fetch a certificate's PDF data (A2)
 *
 * A1 deliberately does NOT apply the public form's anti-anonymous defenses
 * (captcha / geofence / per-IP+email+CPF rate-limit) — those exist to protect an
 * *anonymous* endpoint. Here the caller is authenticated and capability-gated, so
 * the gate itself is the control, plus a generous per-user_id rate-limit as a
 * runaway guard (the per-IP defenses are inappropriate: many field operators
 * legitimately share an office IP, and A4 — authenticated geofencing — is parked).
 * It reuses the same SubmissionHandler + validation the public path uses, so an
 * issued certificate is byte-for-byte a normal submission.
 *
 * @package FreeFormCertificate\API
 * @since 6.18.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\API;

use FreeFormCertificate\Core\Capabilities;
use FreeFormCertificate\Core\DataSanitizer;
use FreeFormCertificate\Core\DocumentFormatter;
use FreeFormCertificate\Repositories\FormRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Operator certificate issuance + PDF REST controller.
 */
class OperatorCertificatesRestController {

	/**
	 * Issuance capability. Admins pass via manage_options (current_user_can_admin_or).
	 */
	private const ISSUE_CAP = 'ffc_manage_certificates';

	/**
	 * Per-user_id rate-limit for issuance — a runaway guard, not friction: an
	 * operator working a queue can legitimately issue hundreds per hour.
	 */
	private const RATE_ACTION   = 'operator_issue_cert';
	private const RATE_PER_HOUR = 300;
	private const RATE_PER_DAY  = 2000;

	/**
	 * REST namespace (e.g. "ffc/v1").
	 *
	 * @var string
	 */
	private string $namespace;

	/**
	 * Form repository (config + fields).
	 *
	 * @var FormRepository|null
	 */
	private ?FormRepository $form_repository;

	/**
	 * Constructor.
	 *
	 * @param string              $namespace       REST namespace.
	 * @param FormRepository|null $form_repository Shared form repository.
	 */
	public function __construct( string $namespace, ?FormRepository $form_repository = null ) {
		$this->namespace       = $namespace;
		$this->form_repository = $form_repository;
	}

	/**
	 * Register the operator routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/operator/certificates',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'issue_certificate' ),
				'permission_callback' => array( $this, 'permission_issue' ),
				'args'                => array(
					'form_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'validate_callback' => static function ( $value ) {
							return is_numeric( $value ) && (int) $value > 0;
						},
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/operator/certificates/(?P<id>\d+)/pdf',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_certificate_pdf' ),
				'permission_callback' => array( $this, 'permission_read_pdf' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'validate_callback' => static function ( $value ) {
							return is_numeric( $value ) && (int) $value > 0;
						},
					),
				),
			)
		);
	}

	/**
	 * Issuance gate: the operator must hold the management capability (or be an
	 * admin). Works over Application Passwords without a nonce.
	 *
	 * @return bool
	 */
	public function permission_issue(): bool {
		return Capabilities::current_user_can_admin_or( self::ISSUE_CAP );
	}

	/**
	 * PDF gate: any logged-in user may reach the route; the handler then enforces
	 * capability OR ownership of the specific certificate.
	 *
	 * @return bool
	 */
	public function permission_read_pdf(): bool {
		return is_user_logged_in();
	}

	/**
	 * POST /operator/certificates — issue a certificate (A1).
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function issue_certificate( $request ) {
		try {
			$params = $request->get_json_params();
			if ( empty( $params ) ) {
				return new \WP_Error( 'no_data', __( 'No data provided in request body', 'ffcertificate' ), array( 'status' => 400 ) );
			}

			$form_id = isset( $params['form_id'] ) ? absint( $params['form_id'] ) : 0;
			if ( $form_id <= 0 ) {
				return new \WP_Error( 'missing_form_id', __( 'A valid form_id is required', 'ffcertificate' ), array( 'status' => 400 ) );
			}
			// form_id is a routing parameter, not a form field — drop it from the data.
			unset( $params['form_id'] );

			$form = get_post( $form_id );
			if ( ! $form || 'ffc_form' !== $form->post_type ) {
				return new \WP_Error( 'form_not_found', __( 'Form not found', 'ffcertificate' ), array( 'status' => 404 ) );
			}
			if ( 'publish' !== $form->post_status ) {
				return new \WP_Error( 'form_not_published', __( 'Form is not published', 'ffcertificate' ), array( 'status' => 403 ) );
			}
			if ( ! $this->form_repository ) {
				return new \WP_Error( 'form_repo_unavailable', __( 'Form repository not available', 'ffcertificate' ), array( 'status' => 500 ) );
			}

			// Per-user_id rate-limit (the authenticated operator). No per-IP / per-email /
			// per-CPF limits and no geofence — those are anti-anonymous defenses that do
			// not fit an authenticated, capability-gated caller (see class docblock).
			$user_id = get_current_user_id();
			if ( class_exists( '\FreeFormCertificate\Security\RateLimiter' ) ) {
				$rate_check = \FreeFormCertificate\Security\RateLimiter::check_user_limit( $user_id, self::RATE_ACTION, self::RATE_PER_HOUR, self::RATE_PER_DAY );
				if ( ! $rate_check['allowed'] ) {
					return new \WP_Error( 'rate_limited', $rate_check['message'] ?? __( 'Too many requests. Please try again later.', 'ffcertificate' ), array( 'status' => 429 ) );
				}
			}

			$form_config = $this->form_repository->getConfig( $form_id );
			$form_fields = $this->form_repository->getFields( $form_id );

			$submission_data = DataSanitizer::recursive_sanitize( $params );

			// Reused validation (same as the public path): required fields, CPF/RF
			// algorithm, email format.
			$validation_errors = $this->validate_required_fields( $submission_data, $form_fields );
			if ( ! empty( $validation_errors ) ) {
				return new \WP_Error(
					'validation_failed',
					__( 'Validation failed', 'ffcertificate' ) . ': ' . implode( ', ', $validation_errors ),
					array(
						'status' => 400,
						'errors' => $validation_errors,
					)
				);
			}

			$cpf_error = $this->validate_cpf_rf( $submission_data );
			if ( is_wp_error( $cpf_error ) ) {
				return $cpf_error;
			}

			if ( ! empty( $submission_data['email'] ) && ! is_email( $submission_data['email'] ) ) {
				return new \WP_Error( 'invalid_email', __( 'Invalid email address', 'ffcertificate' ), array( 'status' => 400 ) );
			}

			if ( ! class_exists( '\FreeFormCertificate\Submissions\SubmissionHandler' ) ) {
				return new \WP_Error( 'handler_not_found', __( 'Submission handler not available', 'ffcertificate' ), array( 'status' => 500 ) );
			}

			$handler    = new \FreeFormCertificate\Submissions\SubmissionHandler();
			$user_email = isset( $submission_data['email'] ) ? sanitize_email( (string) $submission_data['email'] ) : '';
			$result     = $handler->process_submission( $form_id, (string) $form->post_title, $submission_data, $user_email, $form_fields, $form_config );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$submission_id = (int) $result;
			$auth_code     = isset( $submission_data['auth_code'] ) ? (string) $submission_data['auth_code'] : '';

			// Audit the issuance against the operator (falls under the "submissions"
			// activity category).
			if ( class_exists( '\FreeFormCertificate\Core\ActivityLog' ) ) {
				\FreeFormCertificate\Core\ActivityLog::log(
					'operator_certificate_issued',
					\FreeFormCertificate\Core\ActivityLog::LEVEL_INFO,
					array( 'form_id' => $form_id ),
					$user_id,
					$submission_id
				);
			}

			return rest_ensure_response(
				array(
					'success'        => true,
					'submission_id'  => $submission_id,
					'auth_code'      => DocumentFormatter::format_auth_code( $auth_code, DocumentFormatter::PREFIX_CERTIFICATE ),
					'pdf_endpoint'   => rest_url( $this->namespace . '/operator/certificates/' . $submission_id . '/pdf' ),
					'validation_url' => home_url( '/validate-certificate/' ),
					'message'        => __( 'Certificate issued successfully', 'ffcertificate' ),
				)
			);
		} catch ( \Exception $e ) {
			$this->log_rest_error( 'issue_certificate', $e );
			return new \WP_Error( 'ffc_internal_error', __( 'An unexpected error occurred.', 'ffcertificate' ), array( 'status' => 500 ) );
		}
	}

	/**
	 * GET /operator/certificates/{id}/pdf — return a certificate's PDF data (A2).
	 *
	 * Delivers the same `pdf_data` (HTML + assets) payload the web + magic-link
	 * paths use, rendered to PDF client-side — the plugin has no server-side PDF
	 * binary renderer. Gated by the management capability OR ownership of the
	 * specific certificate.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_certificate_pdf( $request ) {
		try {
			$submission_id = absint( $request->get_param( 'id' ) );
			if ( $submission_id <= 0 ) {
				return new \WP_Error( 'invalid_id', __( 'Invalid certificate id', 'ffcertificate' ), array( 'status' => 400 ) );
			}

			if ( ! class_exists( '\FreeFormCertificate\Submissions\SubmissionHandler' ) || ! class_exists( '\FreeFormCertificate\Generators\PdfGenerator' ) ) {
				return new \WP_Error( 'generator_unavailable', __( 'PDF generator not available', 'ffcertificate' ), array( 'status' => 500 ) );
			}

			$handler    = new \FreeFormCertificate\Submissions\SubmissionHandler();
			$submission = $handler->get_submission( $submission_id );
			if ( ! $submission ) {
				return new \WP_Error( 'certificate_not_found', __( 'Certificate not found', 'ffcertificate' ), array( 'status' => 404 ) );
			}

			// Capability OR ownership. Never trust a request-supplied owner id — derive
			// the owner from the stored row and the caller from the session.
			$owner_id = isset( $submission['user_id'] ) ? (int) $submission['user_id'] : 0;
			$can      = Capabilities::current_user_can_admin_or( self::ISSUE_CAP )
				|| ( $owner_id > 0 && get_current_user_id() === $owner_id );
			if ( ! $can ) {
				return new \WP_Error( 'forbidden', __( 'You are not allowed to access this certificate.', 'ffcertificate' ), array( 'status' => 403 ) );
			}

			$generator = new \FreeFormCertificate\Generators\PdfGenerator();
			$pdf_data  = $generator->generate_pdf_data( $submission_id, $handler );
			if ( is_wp_error( $pdf_data ) ) {
				return $pdf_data;
			}

			return rest_ensure_response(
				array(
					'success'       => true,
					'submission_id' => $submission_id,
					'pdf_data'      => $pdf_data,
				)
			);
		} catch ( \Exception $e ) {
			$this->log_rest_error( 'get_certificate_pdf', $e );
			return new \WP_Error( 'ffc_internal_error', __( 'An unexpected error occurred.', 'ffcertificate' ), array( 'status' => 500 ) );
		}
	}

	/**
	 * Validate CPF/RF in the submission data (mirrors the public path's check).
	 * Normalizes into $submission_data['cpf_rf'] in place.
	 *
	 * @param array<string, mixed> $submission_data Submission data (by reference).
	 * @return true|\WP_Error True when valid/absent, WP_Error otherwise.
	 */
	private function validate_cpf_rf( array &$submission_data ) {
		if ( empty( $submission_data['cpf_rf'] ) ) {
			return true;
		}

		$cpf = DataSanitizer::normalize_cpf_rf( (string) $submission_data['cpf_rf'] );

		if ( 11 === strlen( $cpf ) ) {
			if ( ! DocumentFormatter::validate_cpf( $cpf ) ) {
				return new \WP_Error( 'invalid_cpf', __( 'Invalid CPF. Please check the number and try again.', 'ffcertificate' ), array( 'status' => 400 ) );
			}
		} elseif ( 7 === strlen( $cpf ) ) {
			if ( ! DocumentFormatter::validate_rf( $cpf ) ) {
				return new \WP_Error( 'invalid_rf', __( 'Invalid RF. Must contain only numbers.', 'ffcertificate' ), array( 'status' => 400 ) );
			}
		} else {
			return new \WP_Error( 'invalid_cpf_rf', __( 'CPF/RF must be exactly 7 or 11 digits', 'ffcertificate' ), array( 'status' => 400 ) );
		}

		$submission_data['cpf_rf'] = $cpf;
		return true;
	}

	/**
	 * Required-field validation. Mirrors FormRestController::validate_required_fields
	 * so the operator endpoint stays independent of the public controller.
	 *
	 * @param array<string, mixed>             $data   Submission data.
	 * @param array<int, array<string, mixed>> $fields Form fields configuration.
	 * @return array<int, string> Validation error messages.
	 */
	private function validate_required_fields( array $data, array $fields ): array {
		$errors = array();
		if ( empty( $fields ) ) {
			return $errors;
		}

		foreach ( $fields as $field ) {
			if ( empty( $field['required'] ) ) {
				continue;
			}
			$field_name = isset( $field['name'] ) ? (string) $field['name'] : '';
			if ( '' === $field_name || ! isset( $data[ $field_name ] ) || '' === trim( (string) $data[ $field_name ] ) ) {
				$errors[] = isset( $field['label'] ) ? (string) $field['label'] : $field_name;
			}
		}

		return $errors;
	}

	/**
	 * Log a REST error without exposing details to clients.
	 *
	 * @param string     $context Action that caused the error.
	 * @param \Exception $e       The exception.
	 * @return void
	 */
	private function log_rest_error( string $context, \Exception $e ): void {
		if ( class_exists( '\FreeFormCertificate\Core\Utils' ) ) {
			\FreeFormCertificate\Core\Debug::log_rest_api(
				"REST API error: {$context}",
				array(
					'message' => $e->getMessage(),
					'file'    => $e->getFile(),
					'line'    => $e->getLine(),
				)
			);
		}
	}
}
