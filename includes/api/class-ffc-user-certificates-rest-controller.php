<?php
/**
 * User Certificates REST Controller
 *
 * Handles:
 *   GET  /user/certificates        – Current user's certificates
 *   POST /user/certificates/reveal – One certificate's e-mail, in clear (#1897)
 *
 * @package FreeFormCertificate\API
 * @since 4.12.7  Extracted from UserDataRestController
 */

declare(strict_types=1);

namespace FreeFormCertificate\API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API controller for user certificates endpoints.
 */
class UserCertificatesRestController {

	use UserContextTrait;

	/**
	 * The certificates module's reveal capability, asked in "view as user".
	 */
	private const PII_CAP = 'ffc_view_certificates_pii';

	/**
	 * API namespace
	 *
	 * @var non-falsy-string
	 */
	private string $namespace;

	/**
	 * Constructor.
	 *
	 * @param non-falsy-string $namespace Namespace.
	 */
	public function __construct( string $namespace ) {
		$this->namespace = $namespace;
	}

	/**
	 * Register routes
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/user/certificates',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_user_certificates' ),
				'permission_callback' => 'is_user_logged_in',
			)
		);

		// The e-mail of one listed certificate, masked in the list (#1897).
		register_rest_route(
			$this->namespace,
			'/user/certificates/reveal',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'reveal_certificate_email' ),
				'permission_callback' => 'is_user_logged_in',
				'args'                => array(
					'id' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * POST /user/certificates/reveal — the e-mail of one certificate the
	 * dashboard lists masked (#1897).
	 *
	 * The owner reveals their own certificate: the account is always the
	 * current user, the record must be theirs, and no audit row is written.
	 * In "view as user" the administrator reveals the viewed account's
	 * certificate when the PII policy lets them, and one audit row names it.
	 *
	 * @since 6.41.0
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function reveal_certificate_email( $request ) {
		$ctx     = $this->resolve_user_context( $request );
		$view_as = $ctx['is_view_as'];
		$user_id = $view_as ? $ctx['user_id'] : get_current_user_id();
		if ( $user_id <= 0 ) {
			return new \WP_Error( 'not_logged_in', __( 'You must be logged in', 'ffcertificate' ), array( 'status' => 401 ) );
		}

		$denied = new \WP_Error( 'ffc_reveal_denied', __( 'You do not have permission to reveal this field.', 'ffcertificate' ), array( 'status' => 403 ) );

		// Someone who may not view as another user asked for one: refused.
		if ( ! $view_as && null !== $request->get_param( 'viewAsUserId' ) && absint( $request->get_param( 'viewAsUserId' ) ) !== $user_id ) {
			return $denied;
		}

		if ( ! $this->user_has_capability( 'ffc_view_own_certificates', $user_id, $view_as ) ) {
			return $denied;
		}

		if ( \FreeFormCertificate\Core\PiiAccessPolicy::TIER_MASKED === \FreeFormCertificate\Core\PiiAccessPolicy::resolve_on_screen( self::PII_CAP, $user_id ) ) {
			return $denied;
		}

		global $wpdb;
		$id         = absint( $request->get_param( 'id' ) );
		$submission = null;
		if ( $id > 0 ) {
			// The owner is part of the query, so a certificate of anyone
			// else's is not found rather than refused after being read.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One row of the plugin's own submissions table, read once for a reveal.
			$submission = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE id = %d AND user_id = %d',
					\FreeFormCertificate\Repositories\SubmissionRepository::get_submissions_table(),
					$id,
					$user_id
				),
				ARRAY_A
			);
		}
		if ( ! is_array( $submission )
			|| in_array( (string) ( $submission['status'] ?? '' ), \FreeFormCertificate\Core\SubmissionStatus::NOT_A_CERTIFICATE, true ) ) {
			return new \WP_Error( 'ffc_certificate_not_found', __( 'Certificate not found.', 'ffcertificate' ), array( 'status' => 404 ) );
		}

		$email = (string) \FreeFormCertificate\Core\Encryption::decrypt_field( $submission, 'email' );

		if ( $view_as ) {
			\FreeFormCertificate\Core\ActivityLog::log(
				'submission_pii_revealed',
				\FreeFormCertificate\Core\ActivityLog::LEVEL_INFO,
				array(
					'field_key'       => 'email',
					'view_as'         => true,
					'subject_user_id' => $user_id,
				),
				get_current_user_id(),
				$id
			);
		}

		return rest_ensure_response(
			array(
				'id'    => $id,
				'value' => $email,
			)
		);
	}

	/**
	 * GET /user/certificates
	 *
	 * @since 3.1.0
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_user_certificates( $request ) {
		try {
			$ctx     = $this->resolve_user_context( $request );
			$user_id = $ctx['user_id'];

			if ( ! $user_id ) {
				return new \WP_Error(
					'not_logged_in',
					__( 'You must be logged in to view certificates', 'ffcertificate' ),
					array( 'status' => 401 )
				);
			}

			if ( ! $this->user_has_capability( 'ffc_view_own_certificates', $user_id, $ctx['is_view_as'] ) ) {
				return new \WP_Error(
					'capability_denied',
					__( 'You do not have permission to view certificates', 'ffcertificate' ),
					array( 'status' => 403 )
				);
			}

			global $wpdb;

			if ( ! class_exists( '\FreeFormCertificate\Core\Utils' ) ) {
				return new \WP_Error( 'missing_class', __( 'FFC_Utils class not found', 'ffcertificate' ), array( 'status' => 500 ) );
			}

			$table = \FreeFormCertificate\Repositories\SubmissionRepository::get_submissions_table();

			// Trash and a quiz attempt that did not pass are not certificates (#1744).
			$hidden = \FreeFormCertificate\Core\SubmissionStatus::NOT_A_CERTIFICATE;
			// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The IN list is `%s` repeated once per hidden status, so the sniff cannot count it.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Joins the plugin's own submissions table to wp_posts for one user's REST payload; the response is built once per request.
			$submissions = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT s.*, p.post_title as form_title
                 FROM %i s
                 LEFT JOIN %i p ON s.form_id = p.ID
                 WHERE s.user_id = %d
                 AND s.status NOT IN (' . implode( ', ', array_fill( 0, count( $hidden ), '%s' ) ) . ')
                 ORDER BY s.submission_date DESC',
					$table,
					$wpdb->posts,
					$user_id,
					...$hidden
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

			// Check per-capability permissions for the target user.
			$can_download     = $this->user_has_capability( 'ffc_download_own_certificates', $user_id, $ctx['is_view_as'] );
			$can_view_history = $this->user_has_capability( 'ffc_view_own_certificate_history', $user_id, $ctx['is_view_as'] );

			$certificates = array();

			foreach ( $submissions as $submission ) {
				$email_plain   = \FreeFormCertificate\Core\Encryption::decrypt_field( $submission, 'email' );
				$email_display = ( '' !== $email_plain ) ? \FreeFormCertificate\Core\DocumentFormatter::mask_email( $email_plain ) : '';

				$magic_link = '';
				if ( ! empty( $submission['magic_token'] ) ) {
					$magic_link = \FreeFormCertificate\Generators\MagicLinkHelper::generate_magic_link( $submission['magic_token'] );
				}

				$auth_code_formatted = '';
				if ( ! empty( $submission['auth_code'] ) ) {
					$auth_code_formatted = \FreeFormCertificate\Core\DocumentFormatter::format_auth_code( $submission['auth_code'], \FreeFormCertificate\Core\DocumentFormatter::PREFIX_CERTIFICATE );
				}

				// `submission_date` is unix UTC int since 6.6.0 (#249 sub-escopo a).
				$submission_ts  = (int) ( $submission['submission_date'] ?? 0 );
				$date_formatted = $submission_ts > 0 ? \FreeFormCertificate\Core\DateFormatter::format_date( $submission_ts ) : '';

				$certificates[] = array(
					'id'                  => (int) ( $submission['id'] ?? 0 ),
					'form_id'             => (int) ( $submission['form_id'] ?? 0 ),
					'form_title'          => $submission['form_title'] ?? __( 'Unknown Form', 'ffcertificate' ),
					'submission_date'     => $date_formatted,
					'submission_date_raw' => $submission_ts,
					'consent_given'       => ! empty( $submission['consent_given'] ),
					'email'               => $email_display,
					'auth_code'           => $auth_code_formatted,
					'magic_link'          => $can_download ? $magic_link : '',
					'pdf_url'             => $can_download ? $magic_link : '',
				);
			}

			// When view_certificate_history is disabled, keep only the most recent per form.
			if ( ! $can_view_history && ! empty( $certificates ) ) {
				$seen_forms = array();
				$filtered   = array();
				foreach ( $certificates as $cert ) {
					if ( ! isset( $seen_forms[ $cert['form_id'] ] ) ) {
						$seen_forms[ $cert['form_id'] ] = true;
						$filtered[]                     = $cert;
					}
				}
				$certificates = $filtered;
			}

			return rest_ensure_response(
				array(
					'certificates' => $certificates,
					'total'        => count( $certificates ),
					// Each e-mail is masked; whether the reader may reveal it
					// through /user/certificates/reveal (#1897).
					'can_reveal'   => \FreeFormCertificate\Core\PiiAccessPolicy::TIER_MASKED !== \FreeFormCertificate\Core\PiiAccessPolicy::resolve_on_screen( self::PII_CAP, $user_id ),
				)
			);

		} catch ( \Exception $e ) {
			if ( class_exists( '\FreeFormCertificate\Core\Utils' ) ) {
				\FreeFormCertificate\Core\Debug::log_rest_api(
					'get_user_certificates error',
					array(
						'message' => $e->getMessage(),
						'file'    => $e->getFile(),
						'line'    => $e->getLine(),
						'trace'   => $e->getTraceAsString(),
					)
				);
			}
			return new \WP_Error(
				'get_certificates_error',
				__( 'An unexpected error occurred.', 'ffcertificate' ),
				array( 'status' => 500 )
			);
		}
	}
}
