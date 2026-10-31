<?php
/**
 * User Reregistrations REST Controller
 *
 * Handles:
 *   GET /user/reregistrations              – Current user's reregistration submissions
 *   GET /user/reregistrations/{id}/answers – One submission's answers, read-only
 *   GET /user/reregistrations/{id}/record  – One approved submission's Record PDF data
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
	 * The module reveal cap, which decides who may see the answers unmasked.
	 */
	private const PII_CAP = 'ffc_view_reregistration_pii';

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
			'/user/reregistrations',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_user_reregistrations' ),
				'permission_callback' => array( $this, 'rest_permission' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/user/reregistrations/(?P<id>\\d+)/answers',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_submission_answers' ),
				'permission_callback' => array( $this, 'rest_permission' ),
				'args'                => array(
					'id'     => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'reveal' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/user/reregistrations/(?P<id>\\d+)/record',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_submission_record' ),
				'permission_callback' => array( $this, 'rest_permission' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * GET /user/reregistrations/{id}/record
	 *
	 * The Record PDF data of one of the user's own APPROVED submissions
	 * (#2242). This is the only way a member downloads the Record: public
	 * verification confirms it without the PDF, and the approval e-mail leads
	 * here through the dashboard. An administrator in "view as user" is staff,
	 * not the owner, so the download is audited.
	 *
	 * @since 6.43.0
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_submission_record( $request ) {
		$ctx     = $this->resolve_user_context( $request );
		$user_id = $ctx['user_id'];
		if ( $user_id <= 0 ) {
			return new \WP_Error( 'not_logged_in', __( 'You must be logged in', 'ffcertificate' ), array( 'status' => 401 ) );
		}

		$submission = \FreeFormCertificate\Reregistration\ReregistrationSubmissionReader::get_by_id( absint( $request->get_param( 'id' ) ) );
		// Another person's submission, or one not approved, answers the same
		// as a missing one, so the endpoint does not confirm which ids exist.
		if ( ! $submission || (int) $submission->user_id !== $user_id || 'approved' !== $submission->status ) {
			return new \WP_Error( 'ffc_submission_not_found', __( 'Submission not found.', 'ffcertificate' ), array( 'status' => 404 ) );
		}

		$record = \FreeFormCertificate\Reregistration\RecordGenerator::generate_record_data( (int) $submission->id );
		if ( empty( $record ) ) {
			return new \WP_Error( 'ffc_record_failed', __( 'Could not generate record.', 'ffcertificate' ), array( 'status' => 500 ) );
		}

		if ( $ctx['is_view_as'] ) {
			\FreeFormCertificate\Core\ActivityLog::log(
				'reregistration_record_downloaded',
				\FreeFormCertificate\Core\ActivityLog::LEVEL_INFO,
				array(
					'reregistration_id' => (int) $submission->reregistration_id,
					'subject_user_id'   => $user_id,
					'via'               => 'view_as',
				),
				get_current_user_id(),
				(int) $submission->id
			);
		}

		return rest_ensure_response( array( 'pdf_data' => $record ) );
	}

	/**
	 * GET /user/reregistrations/{id}/answers
	 *
	 * The answers of one of the user's own submissions, so one that can no
	 * longer be edited can still be read. Personal data arrives masked; the
	 * owner reveals their own without an audit row, and an administrator in
	 * "view as user" reveals it audited, like every other dashboard reveal
	 * (#1863, #1897).
	 *
	 * @since 6.43.0
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_submission_answers( $request ) {
		$ctx     = $this->resolve_user_context( $request );
		$user_id = $ctx['user_id'];
		if ( $user_id <= 0 ) {
			return new \WP_Error( 'not_logged_in', __( 'You must be logged in', 'ffcertificate' ), array( 'status' => 401 ) );
		}

		$submission = \FreeFormCertificate\Reregistration\ReregistrationSubmissionReader::get_by_id( absint( $request->get_param( 'id' ) ) );
		// Another person's submission answers the same as a missing one, so
		// the endpoint does not confirm which ids exist.
		if ( ! $submission || (int) $submission->user_id !== $user_id || empty( $submission->submitted_at ) ) {
			return new \WP_Error( 'ffc_submission_not_found', __( 'Submission not found.', 'ffcertificate' ), array( 'status' => 404 ) );
		}

		$tier       = \FreeFormCertificate\Core\PiiAccessPolicy::resolve_on_screen( self::PII_CAP, $user_id );
		$can_reveal = \FreeFormCertificate\Core\PiiAccessPolicy::TIER_MASKED !== $tier;
		$reveal     = (bool) $request->get_param( 'reveal' );
		if ( $reveal && ! $can_reveal ) {
			return new \WP_Error( 'ffc_reveal_denied', __( 'You do not have permission to reveal this field.', 'ffcertificate' ), array( 'status' => 403 ) );
		}

		$answers = \FreeFormCertificate\Reregistration\ReregistrationSubmissionAnswers::build( $submission, $reveal );
		if ( null === $answers ) {
			return new \WP_Error( 'ffc_submission_not_found', __( 'Submission not found.', 'ffcertificate' ), array( 'status' => 404 ) );
		}

		if ( $reveal && $ctx['is_view_as'] ) {
			\FreeFormCertificate\Core\ActivityLog::log(
				'reregistration_pii_revealed',
				\FreeFormCertificate\Core\ActivityLog::LEVEL_INFO,
				array(
					'view_as'         => true,
					'subject_user_id' => $user_id,
				),
				get_current_user_id(),
				(int) $submission->id
			);
		}

		return rest_ensure_response(
			array(
				'submission_id' => (int) $submission->id,
				'rows'          => $answers['rows'],
				'masked'        => $answers['has_masked'],
				'can_reveal'    => $can_reveal && $answers['has_masked'],
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

			if ( ! class_exists( '\FreeFormCertificate\Reregistration\ReregistrationSubmissionReader' ) ) {
				return rest_ensure_response(
					array(
						'reregistrations' => array(),
						'total'           => 0,
					)
				);
			}

			$submissions = \FreeFormCertificate\Reregistration\ReregistrationSubmissionReader::get_all_by_user( $user_id );

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

				// The Record is downloadable only while the submission is
				// approved (#2242): the code exists only then, and a row returned
				// to draft keeps its code hidden until it is approved again. An
				// approved row stays `approved` when its campaign ends.
				$can_download = 'approved' === $sub->status && ! empty( $sub->auth_code );
				$is_active    = 'active' === $sub->reregistration_status;
				$can_submit   = $is_active
					&& in_array( $sub->status, \FreeFormCertificate\Reregistration\ReregistrationSubmissionReader::OPEN_STATUSES, true )
					&& \FreeFormCertificate\Reregistration\ReregistrationWindow::is_open( (string) $sub->start_date, (string) $sub->end_date );

				$formatted[] = array(
					'submission_id'         => (int) $sub->id,
					'reregistration_id'     => (int) $sub->reregistration_id,
					'title'                 => $sub->reregistration_title ?? '',
					'status'                => $sub->status,
					'status_label'          => $status_labels[ $sub->status ] ?? $sub->status,
					'reregistration_status' => $sub->reregistration_status,
					'start_date'            => $sub->start_date,
					'end_date'              => $sub->end_date,
					// Wall-clock (Category B): formatted in UTC so the stored value
					// prints as written instead of being shifted by the site zone.
					'start_date_formatted'  => ( false !== $start_ts ) ? \FreeFormCertificate\Core\DateFormatter::format_date( $start_ts, 'default', 'UTC' ) : $sub->start_date,
					'end_date_formatted'    => ( false !== $end_ts ) ? \FreeFormCertificate\Core\DateFormatter::format_date( $end_ts, 'default', 'UTC' ) : $sub->end_date,
					'submitted_at'          => $submitted_at,
					'days_left'             => $is_active ? \FreeFormCertificate\Reregistration\ReregistrationWindow::days_left( (string) $sub->end_date ) : 0,
					'can_download'          => $can_download,
					'can_submit'            => $can_submit,
					'can_view_answers'      => ! empty( $sub->submitted_at ),
					'is_active'             => $is_active,
					'auth_code'             => $can_download
						? \FreeFormCertificate\Core\DocumentFormatter::format_auth_code( $sub->auth_code, \FreeFormCertificate\Core\DocumentFormatter::PREFIX_REREGISTRATION )
						: '',
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
