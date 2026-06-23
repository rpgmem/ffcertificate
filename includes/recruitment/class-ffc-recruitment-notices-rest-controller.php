<?php
/**
 * Recruitment Notices REST Controller
 *
 * Domain controller for the `notices/*` slice of the
 * `ffcertificate/v1/recruitment` namespace. Owns notice CRUD plus the
 * notice-status state-machine transition. Extracted from the original
 * god-object `RecruitmentRestController` (sprint S2 of #141).
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.0.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Recruitment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-ffc-recruitment-rest-support-trait.php';

/**
 * REST controller for the recruitment notices slice.
 */
final class RecruitmentNoticesRestController {

	use RecruitmentRestSupport;

	/** Namespace for all admin + candidate-self routes. */
	private const NAMESPACE = 'ffcertificate/v1';

	/** Resource base. */
	private const REST_BASE = 'recruitment';

	/**
	 * Hook callback for `rest_api_init`.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$ns   = self::NAMESPACE;
		$base = '/' . self::REST_BASE;

		// ── Notices ─────────────────────────────────────────────────────
		register_rest_route(
			$ns,
			$base . '/notices',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_notices' ),
					'permission_callback' => array( $this, 'check_admin_cap' ),
					'args'                => array(
						'status' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_notice' ),
					'permission_callback' => array( $this, 'check_admin_cap' ),
					'args'                => array(
						'code' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'name' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		register_rest_route(
			$ns,
			$base . '/notices/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_notice' ),
				'permission_callback' => array( $this, 'check_admin_cap' ),
			)
		);
	}

	// ─────────────────────────────────────────────────────────────────────
	// Notices
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * GET /notices
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function list_notices( \WP_REST_Request $request ): \WP_REST_Response {
		$status  = $request->get_param( 'status' );
		$notices = RecruitmentNoticeReader::get_all( is_string( $status ) && '' !== $status ? $status : null );

		return new \WP_REST_Response( $notices, 200 );
	}

	/**
	 * POST /notices
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_notice( \WP_REST_Request $request ) {
		$code = (string) $request->get_param( 'code' );
		$name = (string) $request->get_param( 'name' );

		$id = RecruitmentNoticeWriter::create( $code, $name );
		if ( false === $id ) {
			return new \WP_Error(
				'recruitment_notice_create_failed',
				__( 'Failed to create notice (duplicate code?).', 'ffcertificate' ),
				array( 'status' => 409 )
			);
		}

		return new \WP_REST_Response( RecruitmentNoticeReader::get_by_id( $id ), 201 );
	}

	/**
	 * PATCH /notices/{id}
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_notice( \WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );

		// Status transitions go through the state machine so all gates and
		// activity-log instrumentation fire correctly.
		$new_status = $request->get_param( 'status' );
		if ( is_string( $new_status ) && '' !== $new_status ) {
			$reason     = $request->get_param( 'reason' );
			$transition = RecruitmentNoticeStateMachine::transition_to(
				$id,
				$new_status,
				is_string( $reason ) ? $reason : null
			);
			if ( ! $transition['success'] ) {
				return $this->wp_error_from_envelope( $transition['errors'], 409 );
			}
		}

		// Plain meta fields go through the repository update.
		$meta = array_intersect_key(
			$request->get_params(),
			array_flip( array( 'name', 'code', 'public_columns_config' ) )
		);
		if ( ! empty( $meta ) ) {
			$ok = RecruitmentNoticeWriter::update( $id, $meta );
			if ( ! $ok ) {
				return new \WP_Error(
					'recruitment_notice_update_failed',
					__( 'Notice update failed.', 'ffcertificate' ),
					array( 'status' => 400 )
				);
			}
		}

		$notice = RecruitmentNoticeReader::get_by_id( $id );
		if ( null === $notice ) {
			return new \WP_Error( 'recruitment_notice_not_found', RecruitmentErrorMessages::translate( 'recruitment_notice_not_found' ), array( 'status' => 404 ) );
		}

		return new \WP_REST_Response( $notice, 200 );
	}
}
