<?php
/**
 * Submission REST Controller
 *
 * Handles submission-related REST API endpoints:
 *   GET  /submissions      – List submissions (admin)
 *   GET  /submissions/{id} – Get single submission (admin)
 *   POST /verify           – Verify certificate by auth code
 *
 * @package FreeFormCertificate\API
 * @since 4.6.1
 */

declare(strict_types=1);

namespace FreeFormCertificate\API;

use FreeFormCertificate\Repositories\SubmissionRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API controller for submission endpoints.
 */
class SubmissionRestController {

	/**
	 * API namespace
	 *
	 * @var non-falsy-string
	 */
	private string $namespace;

	/**
	 * Submission repository
	 *
	 * @var SubmissionRepository|null
	 */
	private ?SubmissionRepository $submission_repository;

	/**
	 * Constructor
	 *
	 * @param non-falsy-string          $namespace             API namespace.
	 * @param SubmissionRepository|null $submission_repository Submission repository instance.
	 */
	public function __construct( string $namespace, ?SubmissionRepository $submission_repository ) {
		$this->namespace             = $namespace;
		$this->submission_repository = $submission_repository;
	}

	/**
	 * Register routes
	 */
	public function register_routes(): void {
		// GET /submissions - List submissions (admin only).
		register_rest_route(
			$this->namespace,
			'/submissions',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_submissions' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
				'args'                => array(
					'page'     => array(
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
					'per_page' => array(
						'default'           => 20,
						'sanitize_callback' => 'absint',
					),
					'status'   => array(
						'default'           => 'publish',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'search'   => array(
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// GET /submissions/{id} - Get single submission (admin only).
		register_rest_route(
			$this->namespace,
			'/submissions/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_submission' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
				'args'                => array(
					'id' => array(
						'validate_callback' => function ( $param ) {
							return is_numeric( $param );
						},
					),
				),
			)
		);

		// POST /verify - Verify certificate by auth code.
		//
		// Public-by-design — the certificate verification page
		// (`/validate-certificate/`) lets anyone confirm a cert by typing
		// in the auth code printed on it. Auth-gating this would defeat
		// the verification feature's whole point. The handler defends
		// with: auth-code length validation, hash_equals comparison
		// (hardened in #140), and the verification-specific rate-limit
		// pool (RateLimiter::check_verification) which throttles
		// brute-force attempts before format validation runs. See #139.
		register_rest_route(
			$this->namespace,
			'/verify',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'verify_certificate' ),
				// phpcs:ignore -- public-by-design: certificate verification flow; see route docblock above and #139.
				'permission_callback' => '__return_true',
				'args'                => array(
					'auth_code' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => function ( $param ) {
							return strlen( $param ) >= 12;
						},
					),
				),
			)
		);
	}

	/**
	 * GET /submissions
	 * List submissions with pagination (admin only)
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_submissions( $request ) {
		try {
			if ( ! $this->submission_repository ) {
				return new \WP_Error(
					'repository_not_found',
					'Submission repository not available',
					array( 'status' => 500 )
				);
			}

			$page     = $request->get_param( 'page' );
			$per_page = $request->get_param( 'per_page' );
			$status   = $request->get_param( 'status' );
			$search   = $request->get_param( 'search' );

			$args = array(
				'page'     => $page,
				'per_page' => $per_page,
				'status'   => $status,
				'search'   => $search,
				'orderby'  => 'id',
				'order'    => 'DESC',
			);

			$result = $this->submission_repository->findPaginated( $args );

			$submissions = array();
			foreach ( $result['items'] as $item ) {
				$data = array();
				if ( ! empty( $item['data'] ) ) {
					$data = json_decode( $item['data'], true );
				}
				// Decrypt encrypted data field when plaintext is empty.
				$data = $this->decrypt_submission_data( $item, is_array( $data ) ? $data : array() );

				// Decrypt individual fields, falling back to plaintext columns.
				$email = \FreeFormCertificate\Core\Encryption::decrypt_field( $item, 'email' );
				$cpf   = \FreeFormCertificate\Core\Encryption::decrypt_field( $item, 'cpf' );
				$rf    = \FreeFormCertificate\Core\Encryption::decrypt_field( $item, 'rf' );

				$owner = isset( $item['user_id'] ) ? (int) $item['user_id'] : null;
				$pii   = $this->shape_submission_pii( $email, $cpf, $rf, $data, (int) $item['id'], $owner );

				$submissions[] = array(
					'id'              => (int) $item['id'],
					'form_id'         => (int) $item['form_id'],
					'auth_code'       => \FreeFormCertificate\Core\DocumentFormatter::format_auth_code( $item['auth_code'], \FreeFormCertificate\Core\DocumentFormatter::PREFIX_CERTIFICATE ),
					'submission_date' => $item['submission_date'],
					'status'          => $item['status'],
					'email'           => $pii['email'],
					'cpf'             => $pii['cpf'],
					'rf'              => $pii['rf'],
					'data'            => $pii['data'],
				);
			}

			$response = array(
				'items'        => $submissions,
				'total'        => $result['total'],
				'pages'        => $result['pages'],
				'current_page' => $page,
				'per_page'     => $per_page,
			);

			return rest_ensure_response( $response );

		} catch ( \Exception $e ) {
			$this->log_rest_error( 'get_submissions', $e );
			return new \WP_Error(
				'ffc_internal_error',
				__( 'An unexpected error occurred.', 'ffcertificate' ),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * GET /submissions/{id}
	 * Get single submission (admin only)
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_submission( $request ) {
		try {
			$submission_id = $request->get_param( 'id' );

			if ( ! $this->submission_repository ) {
				return new \WP_Error(
					'repository_not_found',
					'Submission repository not available',
					array( 'status' => 500 )
				);
			}

			$submission = $this->submission_repository->findById( $submission_id );

			if ( ! $submission ) {
				return new \WP_Error(
					'submission_not_found',
					'Submission not found',
					array( 'status' => 404 )
				);
			}

			$data = array();
			if ( ! empty( $submission['data'] ) ) {
				$data = json_decode( $submission['data'], true );
			}

			// Decrypt if encrypted.
			$data = $this->decrypt_submission_data( $submission, $data );

			$form       = get_post( (int) $submission['form_id'] );
			$form_title = $form ? $form->post_title : 'Unknown Form';

			// Decrypt individual fields, falling back to plaintext columns.
			$email = \FreeFormCertificate\Core\Encryption::decrypt_field( $submission, 'email' );
			$cpf   = \FreeFormCertificate\Core\Encryption::decrypt_field( $submission, 'cpf' );
			$rf    = \FreeFormCertificate\Core\Encryption::decrypt_field( $submission, 'rf' );

			$owner = isset( $submission['user_id'] ) ? (int) $submission['user_id'] : null;
			$pii   = $this->shape_submission_pii( $email, $cpf, $rf, $data, (int) $submission['id'], $owner );

			$response = array(
				'id'              => (int) $submission['id'],
				'form_id'         => (int) $submission['form_id'],
				'form_title'      => $form_title,
				'auth_code'       => \FreeFormCertificate\Core\DocumentFormatter::format_auth_code( $submission['auth_code'], \FreeFormCertificate\Core\DocumentFormatter::PREFIX_CERTIFICATE ),
				'submission_date' => $submission['submission_date'],
				'status'          => $submission['status'],
				'email'           => $pii['email'],
				'cpf'             => $pii['cpf'],
				'rf'              => $pii['rf'],
				'data'            => $pii['data'],
			);

			return rest_ensure_response( $response );

		} catch ( \Exception $e ) {
			$this->log_rest_error( 'get_submission', $e );
			return new \WP_Error(
				'ffc_internal_error',
				__( 'An unexpected error occurred.', 'ffcertificate' ),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * POST /verify
	 * Verify certificate by auth code
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function verify_certificate( $request ) {
		try {
			// Rate limit by IP — mirrors the AJAX verification handler.
			if ( class_exists( '\FreeFormCertificate\Security\RateLimiter' ) ) {
				$ip         = \FreeFormCertificate\Core\RequestInput::get_user_ip();
				$rate_check = \FreeFormCertificate\Security\RateLimiter::check_verification( $ip );
				if ( empty( $rate_check['allowed'] ) ) {
					return new \WP_Error(
						'ffc_rate_limited',
						$rate_check['message'] ?? __( 'Too many requests. Please wait.', 'ffcertificate' ),
						array( 'status' => 429 )
					);
				}
			}

			$auth_code = $request->get_param( 'auth_code' );
			$auth_code = \FreeFormCertificate\Core\DocumentFormatter::clean_auth_code( $auth_code );

			if ( ! $this->submission_repository ) {
				return new \WP_Error(
					'repository_not_found',
					'Submission repository not available',
					array( 'status' => 500 )
				);
			}

			$submission = $this->submission_repository->findByAuthCode( $auth_code );

			if ( ! $submission ) {
				return new \WP_Error(
					'certificate_not_found',
					'Certificate not found. Please check the authentication code.',
					array( 'status' => 404 )
				);
			}

			if ( isset( $submission['status'] ) && 'trash' === $submission['status'] ) {
				return new \WP_Error(
					'certificate_deleted',
					'This certificate has been deleted.',
					array( 'status' => 410 )
				);
			}

			$data = array();
			if ( ! empty( $submission['data'] ) ) {
				$data = json_decode( $submission['data'], true );
			}

			$data = $this->decrypt_submission_data( $submission, $data );

			$form       = get_post( (int) $submission['form_id'] );
			$form_title = $form ? $form->post_title : 'Unknown Form';

			$response = array(
				'valid'       => true,
				'auth_code'   => \FreeFormCertificate\Core\DocumentFormatter::format_auth_code( $auth_code, \FreeFormCertificate\Core\DocumentFormatter::PREFIX_CERTIFICATE ),
				'certificate' => array(
					'id'              => (int) $submission['id'],
					'form_id'         => (int) $submission['form_id'],
					'form_title'      => $form_title,
					'submission_date' => $submission['submission_date'],
					'status'          => $submission['status'],
					// #838 S3 — this endpoint is public: the decrypted `data` blob
					// must be masked per-key (cpf/rf/rg/email) before returning it,
					// matching the /valid page renderer. Skipping this leaked the
					// full form-collected PII to any caller with a valid auth code.
					'data'            => $this->mask_data_pii( $data ),
				),
				'message'     => __( 'Certificate is valid and authentic.', 'ffcertificate' ),
			);

			// This endpoint is public (permission_callback __return_true); mask
			// every PII field before returning it, matching the /valid page
			// renderer. email/rf are only populated from legacy plaintext
			// columns on pre-encryption installs, but must be masked there too.
			if ( ! empty( $submission['email'] ) ) {
				$response['certificate']['email'] = \FreeFormCertificate\Core\DocumentFormatter::mask_email( $submission['email'] );
			}

			if ( ! empty( $submission['cpf_rf'] ) ) {
				$response['certificate']['cpf_rf'] = \FreeFormCertificate\Core\DocumentFormatter::mask_cpf( $submission['cpf_rf'] );
			}

			if ( ! empty( $submission['cpf'] ) ) {
				$response['certificate']['cpf'] = \FreeFormCertificate\Core\DocumentFormatter::mask_cpf( $submission['cpf'] );
			}

			if ( ! empty( $submission['rf'] ) ) {
				$response['certificate']['rf'] = \FreeFormCertificate\Core\DocumentFormatter::mask_rf( $submission['rf'] );
			}

			return rest_ensure_response( $response );

		} catch ( \Exception $e ) {
			$this->log_rest_error( 'verify_certificate', $e );
			return new \WP_Error(
				'ffc_internal_error',
				__( 'An unexpected error occurred.', 'ffcertificate' ),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Check admin permission for the read-only submission REST routes.
	 *
	 * 3-state model: viewing the admin submission data needs the certificates
	 * view cap (manage holders carry it too; WP admins always pass).
	 */
	public function check_admin_permission(): bool {
		return \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_view_certificates' );
	}

	/**
	 * Shape a submission's PII fields (email / cpf / rf / data) by the caller's
	 * access tier, mirroring the wp-admin submission screens (#838 S2).
	 *
	 * The surface gate ({@see check_admin_permission()}) is the read-only `view`
	 * tier, which is PII-blind by design — so the plaintext PII is masked by
	 * default and revealed only for the unmasked (`ffc_certificates_admin` role /
	 * super-admin) and reveal (`ffc_view_certificates_pii` cap) tiers. The reveal
	 * tier is audited per record, exactly as the admin reveal endpoint does, so
	 * the REST surface can no longer bulk-extract certificate PII without a trail.
	 *
	 * @param string|null          $email         Decrypted e-mail, or null/empty.
	 * @param string|null          $cpf           Decrypted CPF, or null/empty.
	 * @param string|null          $rf            Decrypted RF, or null/empty.
	 * @param array<string, mixed> $data          Decrypted `data` blob.
	 * @param int                  $submission_id Submission ID (audit object id).
	 * @param int|null             $owner         Owner user id (self-view clause).
	 * @return array{email: ?string, cpf: ?string, rf: ?string, data: array<string, mixed>}
	 */
	private function shape_submission_pii( ?string $email, ?string $cpf, ?string $rf, array $data, int $submission_id, ?int $owner ): array {
		$tier = \FreeFormCertificate\Core\PiiAccessPolicy::resolve(
			'ffc_view_certificates_pii',
			'ffc_certificates_admin',
			$owner
		);

		if ( \FreeFormCertificate\Core\PiiAccessPolicy::TIER_MASKED === $tier ) {
			return array(
				'email' => ! empty( $email ) ? \FreeFormCertificate\Core\DocumentFormatter::mask_email( $email ) : null,
				'cpf'   => ! empty( $cpf ) ? \FreeFormCertificate\Core\DocumentFormatter::mask_cpf( $cpf ) : null,
				'rf'    => ! empty( $rf ) ? \FreeFormCertificate\Core\DocumentFormatter::mask_rf( $rf ) : null,
				'data'  => $this->mask_data_pii( $data ),
			);
		}

		// Reveal / unmasked tier — plaintext. Audit only the `reveal` tier; the
		// unmasked `_admin` / super-admin tier is exempt, matching reveal_pii().
		if ( \FreeFormCertificate\Core\PiiAccessPolicy::TIER_REVEAL === $tier ) {
			\FreeFormCertificate\Core\ActivityLog::log(
				'submission_pii_revealed',
				\FreeFormCertificate\Core\ActivityLog::LEVEL_INFO,
				array( 'field_key' => 'rest_api' ),
				get_current_user_id(),
				$submission_id
			);
		}

		return array(
			'email' => ! empty( $email ) ? $email : null,
			'cpf'   => ! empty( $cpf ) ? \FreeFormCertificate\Core\DocumentFormatter::format_document( $cpf ) : null,
			'rf'    => ! empty( $rf ) ? \FreeFormCertificate\Core\DocumentFormatter::format_document( $rf ) : null,
			'data'  => $data,
		);
	}

	/**
	 * Mask every PII key inside a decrypted `data` blob (#838 S2 / S3), reusing
	 * the shared per-key masker so this path and the `/valid` page renderer stay
	 * in lockstep. Non-PII fields (course, hours, …) pass through untouched.
	 *
	 * @param array<string, mixed> $data Decrypted data blob.
	 * @return array<string, mixed> Blob with cpf/cpf_rf/rg/rf/email keys masked.
	 */
	private function mask_data_pii( array $data ): array {
		$masked = array();
		foreach ( $data as $key => $value ) {
			$masked[ $key ] = \FreeFormCertificate\Core\DocumentFormatter::mask_field_value( (string) $key, $value );
		}
		return $masked;
	}

	/**
	 * Decrypt submission data if encrypted
	 *
	 * @since 3.2.0
	 * @param array<string, mixed> $submission Submission data array.
	 * @param array<string, mixed> $data Existing data array to merge into.
	 * @return array<string, mixed> Merged data array with decrypted values
	 */
	private function decrypt_submission_data( array $submission, array $data = array() ): array {
		if ( empty( $submission['data_encrypted'] ) ) {
			return $data;
		}

		if ( ! class_exists( '\FreeFormCertificate\Core\Encryption' ) ) {
			return $data;
		}

		// Encryption::decrypt never throws; it returns null on failure and
		// already logs the failure via ActivityLog. An explicit null-check
		// keeps json_decode from receiving null (deprecated in PHP 8.1+)
		// and makes the fallback-to-$data path obvious.
		$decrypted = \FreeFormCertificate\Core\Encryption::decrypt( $submission['data_encrypted'] );
		if ( null === $decrypted ) {
			return $data;
		}

		$decrypted_data = json_decode( $decrypted, true );
		if ( is_array( $decrypted_data ) ) {
			return array_merge( $data, $decrypted_data );
		}

		return $data;
	}

	/**
	 * Log REST API error without exposing details to clients.
	 *
	 * @since 4.6.6
	 * @param string     $context Action that caused the error.
	 * @param \Exception $e       The exception.
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
