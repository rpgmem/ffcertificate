<?php
/**
 * Recruitment REST Controller
 *
 * Single entry point for every admin REST route under the
 * `ffcertificate/v1/recruitment` namespace plus the candidate-self
 * `/me/recruitment` endpoint. Every callback is a thin dispatcher: it
 * sanitizes/validates input, delegates to the corresponding service or
 * repository, and returns a `\WP_REST_Response` or `\WP_Error`.
 *
 * Authorization model:
 *
 *   - Admin endpoints: `permission_callback` checks
 *     `current_user_can('ffc_manage_recruitment')`. The cap is granted to
 *     the `administrator` role on activation (sprint 3) and to the
 *     dedicated `ffc_recruitment_manager` role.
 *   - Candidate-self endpoint (`GET /me/recruitment`): just
 *     `is_user_logged_in()`. The query joins `candidate.user_id =
 *     current_user_id` so users only ever see their own data.
 *   - Public endpoints: NONE. The public shortcode (sprint 11) renders
 *     server-side; deliberately no public REST so we avoid the
 *     enumeration vector.
 *
 * Error contract: every failure returns a `\WP_Error` with a stable
 * `recruitment_*` code, an HTTP status (400/403/404/409/500), and an
 * optional `data` payload (e.g. `blocked_by` reference counts from the
 * delete service).
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.0.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Recruitment;

use FreeFormCertificate\Core\DocumentFormatter;
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Core\SensitiveFieldRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST controller for the recruitment module.
 *
 * @phpstan-import-type CandidateRow from RecruitmentCandidateRepository
 */
final class RecruitmentRestController {

	/** Namespace for all admin + candidate-self routes. */
	private const NAMESPACE = 'ffcertificate/v1';

	/** Resource base. */
	private const REST_BASE = 'recruitment';

	/** Admin capability gating every admin route. */
	private const ADMIN_CAP = 'ffc_manage_recruitment';

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

		register_rest_route(
			$ns,
			$base . '/notices/(?P<id>\d+)/classifications',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_classifications' ),
				'permission_callback' => array( $this, 'check_admin_cap' ),
				'args'                => array(
					'list_type'    => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'adjutancy_id' => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			$ns,
			$base . '/notices/(?P<id>\d+)/import',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'import_csv' ),
				'permission_callback' => array( $this, 'check_admin_cap' ),
			)
		);

		register_rest_route(
			$ns,
			$base . '/notices/(?P<id>\d+)/promote-preview',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'promote_preview' ),
				'permission_callback' => array( $this, 'check_admin_cap' ),
				'args'                => array(
					'mode' => array(
						'type'              => 'string',
						'required'          => true,
						'enum'              => array( 'snapshot', 'definitive_import' ),
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// ── Classifications ─────────────────────────────────────────────
		register_rest_route(
			$ns,
			$base . '/classifications/(?P<id>\d+)/call',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'call_classification' ),
				'permission_callback' => array( $this, 'check_admin_cap' ),
				'args'                => array(
					'date_to_assume'      => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'time_to_assume'      => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'out_of_order_reason' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'notes'               => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			$ns,
			$base . '/classifications/bulk-call',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'bulk_call_classifications' ),
				'permission_callback' => array( $this, 'check_admin_cap' ),
			)
		);

		register_rest_route(
			$ns,
			$base . '/classifications/(?P<id>\d+)/status',
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'change_classification_status' ),
				'permission_callback' => array( $this, 'check_admin_cap' ),
				'args'                => array(
					'status' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'reason' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			$ns,
			$base . '/classifications/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_classification' ),
				'permission_callback' => array( $this, 'check_admin_cap' ),
			)
		);

		register_rest_route(
			$ns,
			$base . '/classifications/(?P<id>\d+)/calls/(?P<call_id>\d+)/cancel',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'cancel_call' ),
				'permission_callback' => array( $this, 'check_admin_cap' ),
				'args'                => array(
					'reason' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// ── Adjutancies ─────────────────────────────────────────────────
		register_rest_route(
			$ns,
			$base . '/adjutancies',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_adjutancies' ),
					'permission_callback' => array( $this, 'check_admin_cap' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_adjutancy' ),
					'permission_callback' => array( $this, 'check_admin_cap' ),
					'args'                => array(
						'slug' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_title',
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
					'permission_callback' => array( $this, 'check_admin_cap' ),
				),
			)
		);

		// ── Candidates ──────────────────────────────────────────────────
		register_rest_route(
			$ns,
			$base . '/candidates',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_candidates' ),
				'permission_callback' => array( $this, 'check_admin_cap' ),
				'args'                => array(
					'search'       => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'cpf'          => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'rf'           => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'notice_id'    => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'adjutancy_id' => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			$ns,
			$base . '/candidates/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_candidate' ),
					'permission_callback' => array( $this, 'check_admin_cap' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_candidate' ),
					'permission_callback' => array( $this, 'check_admin_cap' ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_candidate' ),
					'permission_callback' => array( $this, 'check_admin_cap' ),
				),
			)
		);

		// ── Candidate-self dashboard ────────────────────────────────────
		register_rest_route(
			$ns,
			$base . '/me/recruitment',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_my_recruitment' ),
				'permission_callback' => array( $this, 'check_logged_in' ),
			)
		);
	}

	// ─────────────────────────────────────────────────────────────────────
	// Permission callbacks
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * Permission gate for every admin endpoint.
	 *
	 * @return bool
	 */
	public function check_admin_cap(): bool {
		return current_user_can( self::ADMIN_CAP );
	}

	/**
	 * Permission gate for the candidate-self endpoint.
	 *
	 * @return bool
	 */
	public function check_logged_in(): bool {
		return is_user_logged_in();
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
		$notices = RecruitmentNoticeRepository::get_all( is_string( $status ) && '' !== $status ? $status : null );

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

		$id = RecruitmentNoticeRepository::create( $code, $name );
		if ( false === $id ) {
			return new \WP_Error(
				'recruitment_notice_create_failed',
				__( 'Failed to create notice (duplicate code?).', 'ffcertificate' ),
				array( 'status' => 409 )
			);
		}

		return new \WP_REST_Response( RecruitmentNoticeRepository::get_by_id( $id ), 201 );
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
			$ok = RecruitmentNoticeRepository::update( $id, $meta );
			if ( ! $ok ) {
				return new \WP_Error(
					'recruitment_notice_update_failed',
					__( 'Notice update failed.', 'ffcertificate' ),
					array( 'status' => 400 )
				);
			}
		}

		$notice = RecruitmentNoticeRepository::get_by_id( $id );
		if ( null === $notice ) {
			return new \WP_Error( 'recruitment_notice_not_found', '', array( 'status' => 404 ) );
		}

		return new \WP_REST_Response( $notice, 200 );
	}

	/**
	 * GET /notices/{id}/classifications
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function list_classifications( \WP_REST_Request $request ): \WP_REST_Response {
		$id           = (int) $request->get_param( 'id' );
		$list_type    = $request->get_param( 'list_type' );
		$adjutancy_id = $request->get_param( 'adjutancy_id' );

		$rows = RecruitmentClassificationRepository::get_for_notice(
			$id,
			is_string( $list_type ) && '' !== $list_type ? $list_type : null,
			is_int( $adjutancy_id ) && $adjutancy_id > 0 ? $adjutancy_id : null
		);

		return new \WP_REST_Response( $rows, 200 );
	}

	/**
	 * POST /notices/{id}/import
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function import_csv( \WP_REST_Request $request ) {
		$id    = (int) $request->get_param( 'id' );
		$files = $request->get_file_params();

		if ( ! isset( $files['csv_file']['tmp_name'] ) || ! is_string( $files['csv_file']['tmp_name'] ) ) {
			return new \WP_Error(
				'recruitment_csv_file_missing',
				__( 'CSV file is required.', 'ffcertificate' ),
				array( 'status' => 400 )
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local upload tmp file; wp_remote_get is for HTTP only.
		$content = file_get_contents( $files['csv_file']['tmp_name'] );
		if ( false === $content ) {
			return new \WP_Error(
				'recruitment_csv_file_unreadable',
				__( 'Could not read CSV file.', 'ffcertificate' ),
				array( 'status' => 400 )
			);
		}

		$result = RecruitmentCsvImporter::import_preview( $id, $content );

		if ( ! $result['success'] ) {
			return $this->wp_error_from_envelope( $result['errors'], 400 );
		}

		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * POST /notices/{id}/promote-preview
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function promote_preview( \WP_REST_Request $request ) {
		$id   = (int) $request->get_param( 'id' );
		$mode = (string) $request->get_param( 'mode' );

		if ( 'snapshot' === $mode ) {
			$result = RecruitmentPromotionService::snapshot_to_definitive( $id );
			if ( ! $result['success'] ) {
				return $this->wp_error_from_envelope( $result['errors'], 409 );
			}
			return new \WP_REST_Response( $result, 200 );
		}

		// definitive_import mode requires an uploaded CSV.
		$files = $request->get_file_params();
		if ( ! isset( $files['csv_file']['tmp_name'] ) || ! is_string( $files['csv_file']['tmp_name'] ) ) {
			return new \WP_Error(
				'recruitment_csv_file_missing',
				__( 'CSV file is required for definitive_import mode.', 'ffcertificate' ),
				array( 'status' => 400 )
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local upload tmp file; wp_remote_get is for HTTP only.
		$content = file_get_contents( $files['csv_file']['tmp_name'] );
		if ( false === $content ) {
			return new \WP_Error(
				'recruitment_csv_file_unreadable',
				__( 'Could not read CSV file.', 'ffcertificate' ),
				array( 'status' => 400 )
			);
		}

		$result = RecruitmentCsvImporter::import_definitive( $id, $content );
		if ( ! $result['success'] ) {
			return $this->wp_error_from_envelope( $result['errors'], 400 );
		}

		// Flip status to active after a successful definitive_import.
		$transition = RecruitmentNoticeStateMachine::transition_to( $id, 'active' );
		if ( ! $transition['success'] ) {
			return $this->wp_error_from_envelope( $transition['errors'], 409 );
		}

		RecruitmentActivityLogger::notice_promoted( $id, 'definitive_import', 0 );

		return new \WP_REST_Response( $result, 200 );
	}

	// ─────────────────────────────────────────────────────────────────────
	// Classifications
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * POST /classifications/{id}/call
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function call_classification( \WP_REST_Request $request ) {
		$id           = (int) $request->get_param( 'id' );
		$reason_raw   = (string) ( $request->get_param( 'out_of_order_reason' ) ?? '' );
		$notes_raw    = (string) ( $request->get_param( 'notes' ) ?? '' );
		$reason_param = '' === $reason_raw ? null : $reason_raw;
		$notes_param  = '' === $notes_raw ? null : $notes_raw;

		$result = RecruitmentCallService::call_single(
			$id,
			(string) $request->get_param( 'date_to_assume' ),
			(string) $request->get_param( 'time_to_assume' ),
			get_current_user_id(),
			$reason_param,
			$notes_param
		);

		if ( ! $result['success'] ) {
			return $this->wp_error_from_envelope( $result['errors'], 409 );
		}

		return new \WP_REST_Response( $result, 201 );
	}

	/**
	 * POST /classifications/bulk-call
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function bulk_call_classifications( \WP_REST_Request $request ) {
		$ids_raw = $request->get_param( 'classification_ids' );
		if ( ! is_array( $ids_raw ) || empty( $ids_raw ) ) {
			return new \WP_Error(
				'recruitment_bulk_call_empty_id_list',
				'',
				array( 'status' => 400 )
			);
		}

		$ids = array_values( array_filter( array_map( 'intval', $ids_raw ) ) );

		$reasons_raw = $request->get_param( 'out_of_order_reasons' );
		$reasons     = is_array( $reasons_raw ) ? array_map( 'sanitize_text_field', $reasons_raw ) : array();

		$notes_raw   = (string) ( $request->get_param( 'notes' ) ?? '' );
		$notes_param = '' === $notes_raw ? null : $notes_raw;

		$result = RecruitmentCallService::call_bulk(
			$ids,
			(string) $request->get_param( 'date_to_assume' ),
			(string) $request->get_param( 'time_to_assume' ),
			get_current_user_id(),
			$reasons,
			$notes_param
		);

		if ( ! $result['success'] ) {
			return $this->wp_error_from_envelope( $result['errors'], 409 );
		}

		return new \WP_REST_Response( $result, 201 );
	}

	/**
	 * PATCH /classifications/{id}/status
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function change_classification_status( \WP_REST_Request $request ) {
		$id     = (int) $request->get_param( 'id' );
		$reason = $request->get_param( 'reason' );
		$result = RecruitmentClassificationStateMachine::transition_to(
			$id,
			(string) $request->get_param( 'status' ),
			is_string( $reason ) ? $reason : null
		);
		if ( ! $result['success'] ) {
			return $this->wp_error_from_envelope( $result['errors'], 409 );
		}

		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * DELETE /classifications/{id}
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_classification( \WP_REST_Request $request ) {
		$id     = (int) $request->get_param( 'id' );
		$result = RecruitmentDeleteService::delete_classification( $id );
		if ( ! $result['success'] ) {
			return $this->wp_error_from_envelope_with_blocked( $result, 409 );
		}
		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * POST /classifications/{id}/calls/{call_id}/cancel
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function cancel_call( \WP_REST_Request $request ) {
		$call_id = (int) $request->get_param( 'call_id' );
		$result  = RecruitmentCallService::cancel_call(
			$call_id,
			(string) $request->get_param( 'reason' ),
			get_current_user_id()
		);
		if ( ! $result['success'] ) {
			return $this->wp_error_from_envelope( $result['errors'], 409 );
		}
		return new \WP_REST_Response( $result, 200 );
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
		return new \WP_REST_Response( RecruitmentAdjutancyRepository::get_all(), 200 );
	}

	/**
	 * POST /adjutancies — create a new adjutancy.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_adjutancy( \WP_REST_Request $request ) {
		$id = RecruitmentAdjutancyRepository::create(
			(string) $request->get_param( 'slug' ),
			(string) $request->get_param( 'name' )
		);
		if ( false === $id ) {
			return new \WP_Error(
				'recruitment_adjutancy_create_failed',
				__( 'Adjutancy creation failed (duplicate slug?).', 'ffcertificate' ),
				array( 'status' => 409 )
			);
		}
		return new \WP_REST_Response( RecruitmentAdjutancyRepository::get_by_id( $id ), 201 );
	}

	/**
	 * PATCH /adjutancies/{id} — update slug or name.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_adjutancy( \WP_REST_Request $request ) {
		$id   = (int) $request->get_param( 'id' );
		$data = array_intersect_key( $request->get_params(), array_flip( array( 'slug', 'name' ) ) );
		$ok   = RecruitmentAdjutancyRepository::update( $id, $data );
		if ( ! $ok ) {
			return new \WP_Error(
				'recruitment_adjutancy_update_failed',
				'',
				array( 'status' => 400 )
			);
		}
		return new \WP_REST_Response( RecruitmentAdjutancyRepository::get_by_id( $id ), 200 );
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
	// Candidates
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * GET /candidates — filter by cpf/rf/name/notice/adjutancy.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function list_candidates( \WP_REST_Request $request ) {
		// CPF/RF are passed as plaintext digits; hash here for the lookup.
		$cpf = $request->get_param( 'cpf' );
		if ( is_string( $cpf ) && '' !== $cpf ) {
			$cpf_digits = preg_replace( '/[^0-9]/', '', $cpf ) ?? '';
			if ( '' !== $cpf_digits ) {
				$candidate = RecruitmentCandidateRepository::get_by_cpf_hash( (string) Encryption::hash( $cpf_digits ) );
				return new \WP_REST_Response( null === $candidate ? array() : array( $this->shape_candidate_admin( $candidate ) ), 200 );
			}
		}

		$rf = $request->get_param( 'rf' );
		if ( is_string( $rf ) && '' !== $rf ) {
			$rf_digits = preg_replace( '/[^0-9]/', '', $rf ) ?? '';
			if ( '' !== $rf_digits ) {
				$candidate = RecruitmentCandidateRepository::get_by_rf_hash( (string) Encryption::hash( $rf_digits ) );
				return new \WP_REST_Response( null === $candidate ? array() : array( $this->shape_candidate_admin( $candidate ) ), 200 );
			}
		}

		// Generic listing not yet implemented in repository (out of scope for
		// sprint 9.1 MVP — search by name will land alongside the admin UI).
		return new \WP_Error(
			'recruitment_candidate_list_requires_filter',
			__( 'Candidate listing requires a cpf or rf filter for now.', 'ffcertificate' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * GET /candidates/{id} — admin view with decrypted fields.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_candidate( \WP_REST_Request $request ) {
		$id        = (int) $request->get_param( 'id' );
		$candidate = RecruitmentCandidateRepository::get_by_id( $id );
		if ( null === $candidate ) {
			return new \WP_Error( 'recruitment_candidate_not_found', '', array( 'status' => 404 ) );
		}
		return new \WP_REST_Response( $this->shape_candidate_admin( $candidate ), 200 );
	}

	/**
	 * PATCH /candidates/{id} — re-encrypts on cpf/rf/email change.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_candidate( \WP_REST_Request $request ) {
		$id   = (int) $request->get_param( 'id' );
		$data = $request->get_params();

		$update = array_intersect_key( $data, array_flip( array( 'name', 'phone', 'notes' ) ) );

		// Encrypt cpf/rf/email via the SensitiveFieldRegistry if supplied.
		$plaintexts = array();
		foreach ( array( 'cpf', 'rf', 'email' ) as $key ) {
			if ( isset( $data[ $key ] ) && is_string( $data[ $key ] ) && '' !== trim( $data[ $key ] ) ) {
				$value = trim( $data[ $key ] );
				if ( 'email' === $key ) {
					$value = strtolower( $value );
				} else {
					$value = preg_replace( '/[^0-9]/', '', $value ) ?? '';
				}
				$plaintexts[ $key ] = $value;
			}
		}

		if ( ! empty( $plaintexts ) ) {
			$encrypted = SensitiveFieldRegistry::encrypt_fields(
				SensitiveFieldRegistry::CONTEXT_RECRUITMENT_CANDIDATE,
				$plaintexts
			);
			$update    = array_merge( $update, $encrypted );
		}

		if ( empty( $update ) ) {
			return new \WP_Error(
				'recruitment_candidate_update_no_writable_fields',
				'',
				array( 'status' => 400 )
			);
		}

		$ok = RecruitmentCandidateRepository::update( $id, $update );
		if ( ! $ok ) {
			return new \WP_Error(
				'recruitment_candidate_update_failed',
				'',
				array( 'status' => 409 )
			);
		}

		$candidate = RecruitmentCandidateRepository::get_by_id( $id );
		return new \WP_REST_Response(
			null === $candidate ? null : $this->shape_candidate_admin( $candidate ),
			200
		);
	}

	/**
	 * DELETE /candidates/{id} — gated hard-delete (zero classifications).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_candidate( \WP_REST_Request $request ) {
		$result = RecruitmentDeleteService::delete_candidate( (int) $request->get_param( 'id' ) );
		if ( ! $result['success'] ) {
			return $this->wp_error_from_envelope_with_blocked( $result, 409 );
		}
		return new \WP_REST_Response( $result, 200 );
	}

	// ─────────────────────────────────────────────────────────────────────
	// Candidate-self dashboard
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * GET /me/recruitment.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_my_recruitment(): \WP_REST_Response {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return new \WP_REST_Response( array(), 200 );
		}

		$candidates = RecruitmentCandidateRepository::get_by_user_id( $user_id );
		if ( empty( $candidates ) ) {
			return new \WP_REST_Response( array(), 200 );
		}

		// Group classifications by notice for the §9.2 grouped payload.
		$out = array();
		foreach ( $candidates as $candidate ) {
			$candidate_id    = (int) $candidate->id;
			$classifications = RecruitmentClassificationRepository::get_for_candidate( $candidate_id );

			foreach ( $classifications as $cls ) {
				$notice_id = (int) $cls->notice_id;
				$notice    = RecruitmentNoticeRepository::get_by_id( $notice_id );
				if ( null === $notice || 'draft' === $notice->status ) {
					continue; // Draft notices are never exposed.
				}

				if ( ! isset( $out[ $notice_id ] ) ) {
					$out[ $notice_id ] = array(
						'notice'          => array(
							'id'           => $notice_id,
							'code'         => $notice->code,
							'name'         => $notice->name,
							'status'       => $notice->status,
							'was_reopened' => '1' === $notice->was_reopened,
						),
						'classifications' => array(),
					);
				}

				$out[ $notice_id ]['classifications'][] = array(
					'id'        => (int) $cls->id,
					'list_type' => $cls->list_type,
					'rank'      => (int) $cls->rank,
					'score'     => $cls->score,
					'status'    => $cls->status,
					'calls'     => RecruitmentCallRepository::get_history_for_classification( (int) $cls->id ),
				);
			}
		}

		return new \WP_REST_Response( array_values( $out ), 200 );
	}

	// ─────────────────────────────────────────────────────────────────────
	// Helpers
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * Decrypt sensitive fields for admin-side candidate responses.
	 *
	 * @param object $candidate Candidate row (CandidateRow shape).
	 * @phpstan-param CandidateRow $candidate
	 * @return array<string, mixed>
	 */
	private function shape_candidate_admin( object $candidate ): array {
		$decrypt = static function ( $value ) {
			if ( ! is_string( $value ) || '' === $value ) {
				return null;
			}
			$plain = Encryption::decrypt( $value );
			return null === $plain ? null : $plain;
		};

		$mask_or_plain_email = function ( ?string $plain ): ?string {
			return null === $plain ? null : DocumentFormatter::mask_email( $plain );
		};

		return array(
			'id'           => (int) $candidate->id,
			'user_id'      => null === $candidate->user_id ? null : (int) $candidate->user_id,
			'name'         => $candidate->name,
			'cpf'          => $decrypt( $candidate->cpf_encrypted ),
			'rf'           => $decrypt( $candidate->rf_encrypted ),
			'email'        => $decrypt( $candidate->email_encrypted ),
			'email_masked' => $mask_or_plain_email( $decrypt( $candidate->email_encrypted ) ),
			'phone'        => $candidate->phone,
			'notes'        => $candidate->notes,
			'is_pcd'       => RecruitmentPcdHasher::verify( $candidate->pcd_hash, (int) $candidate->id ),
			'created_at'   => $candidate->created_at,
			'updated_at'   => $candidate->updated_at,
		);
	}

	/**
	 * Convert an envelope-style errors array into a `\WP_Error`.
	 *
	 * @param array<int, string> $errors  Error codes.
	 * @param int                $status  HTTP status code.
	 * @return \WP_Error
	 */
	private function wp_error_from_envelope( array $errors, int $status ): \WP_Error {
		$code = $errors[0] ?? 'recruitment_error';
		return new \WP_Error(
			$code,
			$code,
			array(
				'status' => $status,
				'errors' => $errors,
			)
		);
	}

	/**
	 * Same as `wp_error_from_envelope` but additionally surfaces a
	 * `blocked_by` reference-count map (returned by the delete service).
	 *
	 * @param array{success: bool, errors: list<string>, blocked_by?: array<string, int>} $envelope Service-result envelope.
	 * @param int                                                                         $status   HTTP status code.
	 * @return \WP_Error
	 */
	private function wp_error_from_envelope_with_blocked( array $envelope, int $status ): \WP_Error {
		$first = $envelope['errors'][0] ?? 'recruitment_error';
		$data  = array(
			'status' => $status,
			'errors' => $envelope['errors'],
		);
		if ( isset( $envelope['blocked_by'] ) ) {
			$data['blocked_by'] = $envelope['blocked_by'];
		}
		return new \WP_Error( $first, $first, $data );
	}
}
