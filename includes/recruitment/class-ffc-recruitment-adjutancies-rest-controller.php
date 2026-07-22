<?php
/**
 * Recruitment Adjutancies REST Controller
 *
 * Domain controller for the `adjutancies/*`, notice ↔ adjutancy
 * attachment, and `reasons/*` catalog slices of the
 * `ffcertificate/v1/recruitment` namespace. Reasons live alongside
 * adjutancies because both are configuration-style catalog resources
 * shared across notices. Extracted from the original god-object
 * `RecruitmentRestController` (sprint S2 of #141).
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
 * REST controller for the recruitment adjutancies + reasons catalog slice.
 */
final class RecruitmentAdjutanciesRestController {

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

		// ── Adjutancies ─────────────────────────────────────────────────
		register_rest_route(
			$ns,
			$base . '/adjutancies',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_adjutancies' ),
					'permission_callback' => array( $this, 'check_can_view_recruitment' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_adjutancy' ),
					'permission_callback' => array( $this, 'check_admin_cap' ),
					'args'                => array(
						'slug'  => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_title',
						),
						'name'  => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'color' => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		register_rest_route(
			$ns,
			$base . '/adjutancies/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_adjutancy' ),
					'permission_callback' => array( $this, 'check_admin_cap' ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_adjutancy' ),
					'permission_callback' => array( $this, 'check_can_delete_recruitment' ),
				),
			)
		);

		// ── Reasons ─────────────────────────────────────────────────────
		register_rest_route(
			$ns,
			$base . '/reasons',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_reasons' ),
					'permission_callback' => array( $this, 'check_can_manage_reasons' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_reason' ),
					'permission_callback' => array( $this, 'check_can_manage_reasons' ),
					'args'                => array(
						'slug'       => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_title',
						),
						'label'      => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'color'      => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'applies_to' => array(
							'type'     => 'array',
							'required' => false,
						),
					),
				),
			)
		);

		register_rest_route(
			$ns,
			$base . '/reasons/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_reason' ),
					'permission_callback' => array( $this, 'check_can_manage_reasons' ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_reason' ),
					'permission_callback' => array( $this, 'check_can_manage_reasons' ),
				),
			)
		);

		// Notice ↔ Adjutancy attachment (N:N junction).
		// PUT  /notices/{id}/adjutancies/{adjutancy_id} — attach
		// DELETE /notices/{id}/adjutancies/{adjutancy_id} — detach
		// The CSV importer requires every adjutancy referenced in the file
		// to be attached to the target notice via this junction; without
		// this route, admins had no UI/API path to perform the attachment.
		register_rest_route(
			$ns,
			$base . '/notices/(?P<id>\d+)/adjutancies/(?P<adjutancy_id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'attach_notice_adjutancy' ),
					'permission_callback' => array( $this, 'check_admin_cap' ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'detach_notice_adjutancy' ),
					'permission_callback' => array( $this, 'check_admin_cap' ),
				),
			)
		);
	}

	// ─────────────────────────────────────────────────────────────────────
	// Adjutancies
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * GET /adjutancies — list every adjutancy.
	 *
	 * @return \WP_REST_Response
	 */
	public function list_adjutancies(): \WP_REST_Response {
		return new \WP_REST_Response( RecruitmentAdjutancyReader::get_all(), 200 );
	}

	/**
	 * POST /adjutancies — create a new adjutancy.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_adjutancy( \WP_REST_Request $request ) {
		$id = RecruitmentAdjutancyWriter::create(
			(string) $request->get_param( 'slug' ),
			(string) $request->get_param( 'name' ),
			(string) ( $request->get_param( 'color' ) ?? '' )
		);
		if ( false === $id ) {
			return new \WP_Error(
				'recruitment_adjutancy_create_failed',
				__( 'Adjutancy creation failed (duplicate slug?).', 'ffcertificate' ),
				array( 'status' => 409 )
			);
		}
		return new \WP_REST_Response( RecruitmentAdjutancyReader::get_by_id( $id ), 201 );
	}

	/**
	 * PATCH /adjutancies/{id} — update slug or name.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_adjutancy( \WP_REST_Request $request ) {
		$id   = (int) $request->get_param( 'id' );
		$data = array_intersect_key( $request->get_params(), array_flip( array( 'slug', 'name', 'color' ) ) );
		$ok   = RecruitmentAdjutancyWriter::update( $id, $data );
		if ( ! $ok ) {
			return new \WP_Error(
				'recruitment_adjutancy_update_failed',
				'',
				array( 'status' => 400 )
			);
		}
		return new \WP_REST_Response( RecruitmentAdjutancyReader::get_by_id( $id ), 200 );
	}

	/**
	 * DELETE /adjutancies/{id} — gated removal.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_adjutancy( \WP_REST_Request $request ) {
		$result = RecruitmentDeleteService::delete_adjutancy( (int) $request->get_param( 'id' ) );
		if ( ! $result['success'] ) {
			return $this->wp_error_from_envelope_with_blocked( $result, 409 );
		}
		return new \WP_REST_Response( $result, 200 );
	}

	// ─────────────────────────────────────────────────────────────────────
	// Reasons
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * GET /reasons — list every reason in the global catalog.
	 *
	 * @return \WP_REST_Response
	 */
	public function list_reasons(): \WP_REST_Response {
		return new \WP_REST_Response( RecruitmentReasonReader::get_all(), 200 );
	}

	/**
	 * POST /reasons — create a new reason.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_reason( \WP_REST_Request $request ) {
		$applies_raw = $request->get_param( 'applies_to' );
		$applies     = array();
		if ( is_array( $applies_raw ) ) {
			foreach ( $applies_raw as $candidate ) {
				if ( is_string( $candidate ) ) {
					$applies[] = $candidate;
				}
			}
		}

		$id = RecruitmentReasonWriter::create(
			(string) $request->get_param( 'slug' ),
			(string) $request->get_param( 'label' ),
			(string) ( $request->get_param( 'color' ) ?? '' ),
			$applies
		);
		if ( false === $id ) {
			return new \WP_Error(
				'recruitment_reason_create_failed',
				__( 'Reason creation failed (duplicate slug?).', 'ffcertificate' ),
				array( 'status' => 409 )
			);
		}
		return new \WP_REST_Response( RecruitmentReasonReader::get_by_id( $id ), 201 );
	}

	/**
	 * PATCH /reasons/{id} — update slug / label / color / applies_to.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_reason( \WP_REST_Request $request ) {
		$id   = (int) $request->get_param( 'id' );
		$data = array_intersect_key( $request->get_params(), array_flip( array( 'slug', 'label', 'color', 'applies_to' ) ) );
		$ok   = RecruitmentReasonWriter::update( $id, $data );
		if ( ! $ok ) {
			return new \WP_Error(
				'recruitment_reason_update_failed',
				'',
				array( 'status' => 400 )
			);
		}
		return new \WP_REST_Response( RecruitmentReasonReader::get_by_id( $id ), 200 );
	}

	/**
	 * DELETE /reasons/{id} — gated by the references count.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_reason( \WP_REST_Request $request ) {
		$id    = (int) $request->get_param( 'id' );
		$count = RecruitmentReasonReader::count_references( $id );
		if ( $count > 0 ) {
			return new \WP_Error(
				'recruitment_reason_in_use',
				__( 'Cannot delete: this reason is still attached to at least one classification.', 'ffcertificate' ),
				array(
					'status'          => 409,
					'reference_count' => $count,
				)
			);
		}
		$ok = RecruitmentReasonWriter::delete( $id );
		if ( ! $ok ) {
			return new \WP_Error( 'recruitment_reason_delete_failed', '', array( 'status' => 400 ) );
		}
		return new \WP_REST_Response( array( 'success' => true ), 200 );
	}

	// ─────────────────────────────────────────────────────────────────────
	// Notice ↔ Adjutancy junction
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * PUT /notices/{id}/adjutancies/{adjutancy_id} — attach the adjutancy
	 * to the notice via the N:N junction. Idempotent: re-attaching an
	 * already-attached pair returns 200 with `created=false`.
	 *
	 * Both ids must reference existing rows; otherwise returns 404.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function attach_notice_adjutancy( \WP_REST_Request $request ) {
		$notice_id    = (int) $request->get_param( 'id' );
		$adjutancy_id = (int) $request->get_param( 'adjutancy_id' );

		if ( null === RecruitmentNoticeReader::get_by_id( $notice_id ) ) {
			return new \WP_Error( 'recruitment_notice_not_found', 'Notice not found.', array( 'status' => 404 ) );
		}
		if ( null === RecruitmentAdjutancyReader::get_by_id( $adjutancy_id ) ) {
			return new \WP_Error( 'recruitment_adjutancy_not_found', 'Adjutancy not found.', array( 'status' => 404 ) );
		}

		$already = RecruitmentNoticeAdjutancyRepository::is_attached( $notice_id, $adjutancy_id );
		if ( $already ) {
			return new \WP_REST_Response(
				array(
					'notice_id'    => $notice_id,
					'adjutancy_id' => $adjutancy_id,
					'created'      => false,
				),
				200
			);
		}

		$ok = RecruitmentNoticeAdjutancyRepository::attach( $notice_id, $adjutancy_id );
		if ( ! $ok ) {
			return new \WP_Error(
				'recruitment_notice_adjutancy_attach_failed',
				'Failed to attach adjutancy to notice.',
				array( 'status' => 500 )
			);
		}

		return new \WP_REST_Response(
			array(
				'notice_id'    => $notice_id,
				'adjutancy_id' => $adjutancy_id,
				'created'      => true,
			),
			201
		);
	}

	/**
	 * DELETE /notices/{id}/adjutancies/{adjutancy_id} — detach the
	 * adjutancy from the notice. Idempotent: detaching a pair that's
	 * already absent returns 200.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function detach_notice_adjutancy( \WP_REST_Request $request ): \WP_REST_Response {
		$notice_id    = (int) $request->get_param( 'id' );
		$adjutancy_id = (int) $request->get_param( 'adjutancy_id' );

		RecruitmentNoticeAdjutancyRepository::detach( $notice_id, $adjutancy_id );

		return new \WP_REST_Response(
			array(
				'notice_id'    => $notice_id,
				'adjutancy_id' => $adjutancy_id,
				'attached'     => false,
			),
			200
		);
	}
}
