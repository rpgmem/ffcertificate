<?php
/**
 * User Reregistrations REST Controller
 *
 * Handles:
 *   GET /user/reregistrations – Current user's reregistration submissions
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
 * REST API controller for user reregistrations endpoints.
 */
class UserReregistrationsRestController {

	use UserContextTrait;

	/**
	 * API namespace
	 *
	 * @var string
	 */
	private string $namespace;

	/**
	 * Constructor.
	 *
	 * @param string $namespace Namespace.
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
			'/user/reregistrations',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_user_reregistrations' ),
				'permission_callback' => 'is_user_logged_in',
			)
		);
	}

	/**
	 * GET /user/reregistrations
	 *
	 * Lists active reregistrations for the current user with submission status.
	 *
	 * @since 4.11.0
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_user_reregistrations( $request ) {
		try {
			$ctx     = $this->resolve_user_context( $request );
			$user_id = $ctx['user_id'];

			if ( ! $user_id ) {
				return new \WP_Error( 'not_logged_in', __( 'You must be logged in', 'ffcertificate' ), array( 'status' => 401 ) );
			}

			if ( ! class_exists( '\FreeFormCertificate\Reregistration\ReregistrationSubmissionRepository' ) ) {
				return rest_ensure_response(
					array(
						'reregistrations' => array(),
						'total'           => 0,
					)
				);
			}

			$submissions = \FreeFormCertificate\Reregistration\ReregistrationSubmissionRepository::get_all_by_user( $user_id );

			$status_labels = array(
				'pending'     => __( 'Pending', 'ffcertificate' ),
				'in_progress' => __( 'In Progress', 'ffcertificate' ),
				'submitted'   => __( 'Submitted — Pending Review', 'ffcertificate' ),
				'approved'    => __( 'Approved', 'ffcertificate' ),
				'rejected'    => __( 'Rejected', 'ffcertificate' ),
				'expired'     => __( 'Expired', 'ffcertificate' ),
			);

			$formatted = array();
			foreach ( $submissions as $sub ) {
				$start_ts     = strtotime( $sub->start_date );
				$end_ts       = strtotime( $sub->end_date );
				$submitted_at = '';
				if ( ! empty( $sub->submitted_at ) ) {
					// `submitted_at` is unix UTC int since 6.6.0 (#249 sub-escopo b).
					$submitted_at = \FreeFormCertificate\Core\DateFormatter::format_datetime( (int) $sub->submitted_at );
				}

				// 6.7.2 — Download right is gated by the presence of an
				// `auth_code`, not by the submission's CURRENT status.
				// AuthCodeService writes the code at the transition into
				// `submitted` / `approved`; the code is canonical proof
				// the ficha was generated. When the parent campaign
				// expires later, the submission row's status flips to
				// `expired` but the participant should still be able to
				// download the ficha they earned. Rejected submissions
				// (which never get an auth_code) and still-pending /
				// in-progress drafts (likewise) stay non-downloadable
				// automatically — no extra status check needed.
				$can_download = ! empty( $sub->auth_code );
				$is_active    = 'active' === $sub->reregistration_status;
				$can_submit   = $is_active && in_array( $sub->status, array( 'pending', 'in_progress', 'rejected' ), true );

				// Build magic link for direct verification.
				$magic_link = '';
				if ( $can_download ) {
					$token      = \FreeFormCertificate\Reregistration\ReregistrationSubmissionRepository::ensure_magic_token( $sub );
					$magic_link = \FreeFormCertificate\Generators\MagicLinkHelper::generate_magic_link( $token );
				}

				$formatted[] = array(
					'submission_id'         => (int) $sub->id,
					'reregistration_id'     => (int) $sub->reregistration_id,
					'title'                 => $sub->reregistration_title ?? '',
					'status'                => $sub->status,
					'status_label'          => $status_labels[ $sub->status ] ?? $sub->status,
					'reregistration_status' => $sub->reregistration_status,
					'start_date'            => $sub->start_date,
					'end_date'              => $sub->end_date,
					'start_date_formatted'  => ( false !== $start_ts ) ? \FreeFormCertificate\Core\DateFormatter::format_date( $start_ts ) : $sub->start_date,
					'end_date_formatted'    => ( false !== $end_ts ) ? \FreeFormCertificate\Core\DateFormatter::format_date( $end_ts ) : $sub->end_date,
					'submitted_at'          => $submitted_at,
					'days_left'             => $is_active ? max( 0, (int) ( ( $end_ts - time() ) / 86400 ) ) : 0,
					'can_download'          => $can_download,
					'can_submit'            => $can_submit,
					'is_active'             => $is_active,
					'auth_code'             => ! empty( $sub->auth_code )
						? \FreeFormCertificate\Core\DocumentFormatter::format_auth_code( $sub->auth_code, \FreeFormCertificate\Core\DocumentFormatter::PREFIX_REREGISTRATION )
						: '',
					'magic_link'            => $magic_link,
				);
			}

			return rest_ensure_response(
				array(
					'reregistrations' => $formatted,
					'total'           => count( $formatted ),
				)
			);

		} catch ( \Exception $e ) {
			return new \WP_Error( 'reregistrations_error', __( 'Error loading reregistrations', 'ffcertificate' ), array( 'status' => 500 ) );
		}
	}
}
