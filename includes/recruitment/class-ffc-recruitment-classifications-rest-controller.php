<?php
/**
 * Recruitment Classifications REST Controller
 *
 * Domain controller for the `classifications/*` slice of the
 * `ffcertificate/v1/recruitment` namespace, plus the CSV-import and
 * promote-preview routes that operate on a notice's classification
 * lists. Extracted from the original god-object
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
 * REST controller for the recruitment classifications slice.
 */
final class RecruitmentClassificationsRestController {

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
				'permission_callback' => array( $this, 'check_can_import_csv' ),
			)
		);

		// Batched import flow — staging-tables flow (V10).
		//
		// The admin edit page POSTs to /import-job/start (multipart,
		// the CSV travels once) → /import-job/validate (no body) →
		// /import-job/batch (JSON, loops until done) → /import-job/commit.
		//
		// Staging lives in ffc_recruitment_import_jobs +
		// ffc_recruitment_import_staging; the canonical schema is only
		// touched by the swap inside commit. See
		// `RecruitmentCsvImporter::ingest_job()` for the design notes.
		register_rest_route(
			$ns,
			$base . '/notices/(?P<id>\d+)/import-job/start',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'import_job_start' ),
				'permission_callback' => array( $this, 'check_can_import_csv' ),
			)
		);
		register_rest_route(
			$ns,
			$base . '/notices/(?P<id>\d+)/import-job/validate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'import_job_validate' ),
				'permission_callback' => array( $this, 'check_can_import_csv' ),
				'args'                => array(
					'job_id' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
		register_rest_route(
			$ns,
			$base . '/notices/(?P<id>\d+)/import-job/batch',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'import_job_batch' ),
				'permission_callback' => array( $this, 'check_can_import_csv' ),
				'args'                => array(
					'job_id' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'size'   => array(
						'type'    => 'integer',
						'default' => RecruitmentCsvImporter::BATCH_SIZE_DEFAULT,
					),
				),
			)
		);
		register_rest_route(
			$ns,
			$base . '/notices/(?P<id>\d+)/import-job/commit',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'import_job_commit' ),
				'permission_callback' => array( $this, 'check_can_import_csv' ),
				'args'                => array(
					'job_id' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			$ns,
			$base . '/notices/(?P<id>\d+)/promote-preview',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'promote_preview' ),
				'permission_callback' => array( $this, 'check_can_import_csv' ),
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
				'permission_callback' => array( $this, 'check_can_call_candidates' ),
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
				'permission_callback' => array( $this, 'check_can_call_candidates' ),
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
			$base . '/classifications/(?P<id>\d+)/preview-status',
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'change_classification_preview_status' ),
				'permission_callback' => array( $this, 'check_admin_cap' ),
				'args'                => array(
					'preview_status'    => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'preview_reason_id' => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			$ns,
			$base . '/classifications/(?P<id>\d+)/adjutancy',
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'change_classification_adjutancy' ),
				'permission_callback' => array( $this, 'check_admin_cap' ),
				'args'                => array(
					'adjutancy_id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
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
	 * POST /notices/{id}/import-job/start
	 *
	 * Multipart upload — same envelope as /import. Reads the CSV file
	 * once, parses + validates it, stages a job, returns { job_id, total }.
	 * The batched flow (start → loop batch → commit) replaces the single
	 * /import call when the operator's CSV is large enough to risk a
	 * gateway timeout.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function import_job_start( \WP_REST_Request $request ) {
		$id    = (int) $request->get_param( 'id' );
		$files = $request->get_file_params();

		if ( ! isset( $files['csv_file']['tmp_name'] ) || ! is_string( $files['csv_file']['tmp_name'] ) ) {
			return new \WP_Error(
				'recruitment_csv_file_missing',
				__( 'CSV file is required.', 'ffcertificate' ),
				array( 'status' => 400 )
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local upload tmp file.
		$content = file_get_contents( $files['csv_file']['tmp_name'] );
		if ( false === $content ) {
			return new \WP_Error(
				'recruitment_csv_file_unreadable',
				__( 'Could not read CSV file.', 'ffcertificate' ),
				array( 'status' => 400 )
			);
		}

		$result = RecruitmentCsvImporter::ingest_job( $id, $content, 'preview' );
		if ( ! $result['ok'] ) {
			return $this->wp_error_from_envelope( $result['errors'], 400 );
		}

		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * POST /notices/{id}/import-job/validate
	 *
	 * Phase 2 — runs the SQL `GROUP BY` validation passes against the
	 * staged rows. Returns the per-line error list (possibly empty).
	 * The job's status moves to `validated` on a clean pass or
	 * `invalid` when errors come back; staging is preserved either way.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function import_job_validate( \WP_REST_Request $request ) {
		$job_id = (string) $request->get_param( 'job_id' );

		$result = RecruitmentCsvImporter::validate_job( $job_id );
		if ( ! $result['ok'] ) {
			return $this->wp_error_from_envelope( $result['errors'], 400 );
		}

		// Even on a clean validate we return 200 with `errors: []` so
		// the JS-side can branch on length without an extra HTTP code.
		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * POST /notices/{id}/import-job/batch
	 *
	 * Phase 3 — promotes the next `size` staged rows through
	 * upsert_candidate(), persisting the resolved candidate_id back on
	 * the staging row. Loops until `done: true`.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function import_job_batch( \WP_REST_Request $request ) {
		$job_id = (string) $request->get_param( 'job_id' );
		$size   = (int) $request->get_param( 'size' );
		if ( $size <= 0 ) {
			$size = RecruitmentCsvImporter::BATCH_SIZE_DEFAULT;
		}

		$result = RecruitmentCsvImporter::promote_batch( $job_id, $size );
		if ( ! $result['ok'] ) {
			return $this->wp_error_from_envelope( $result['errors'], 400 );
		}

		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * POST /notices/{id}/import-job/commit
	 *
	 * Phase 4 — atomic swap of the staged classifications into the
	 * canonical `ffc_recruitment_classification` table. One short
	 * transaction; rollback on failure preserves both the prior live
	 * list and the staging rows for a retry.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function import_job_commit( \WP_REST_Request $request ) {
		$job_id = (string) $request->get_param( 'job_id' );

		$result = RecruitmentCsvImporter::commit_job_v2( $job_id );
		if ( ! $result['ok'] ) {
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

		// Flip status to definitive after a successful definitive_import.
		$transition = RecruitmentNoticeStateMachine::transition_to( $id, 'definitive' );
		if ( ! $transition['success'] ) {
			return $this->wp_error_from_envelope( $transition['errors'], 409 );
		}

		RecruitmentActivityLogger::notice_promoted( $id, 'definitive_import', 0 );

		return new \WP_REST_Response( $result, 200 );
	}

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
	 * PATCH /classifications/{id}/preview-status — set the preliminary
	 * list's visual status (and optional reason). Visual-only — never
	 * touches the §5.2 state machine on the definitive list.
	 *
	 * Validates:
	 *   - the classification exists and is `list_type='preview'`
	 *   - `preview_status` is a known enum value
	 *   - the reason (if any) exists in the catalog AND its `applies_to`
	 *     covers the requested status
	 *   - if the per-status `preview_reason_required_*` flag is set in
	 *     Settings, the reason must be supplied
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function change_classification_preview_status( \WP_REST_Request $request ) {
		$id             = (int) $request->get_param( 'id' );
		$preview_status = (string) $request->get_param( 'preview_status' );
		$reason_id_raw  = $request->get_param( 'preview_reason_id' );
		$reason_id      = is_numeric( $reason_id_raw ) ? (int) $reason_id_raw : 0;

		$cls = RecruitmentClassificationRepository::get_by_id( $id );
		if ( null === $cls ) {
			return new \WP_Error( 'recruitment_classification_not_found', RecruitmentErrorMessages::translate( 'recruitment_classification_not_found' ), array( 'status' => 404 ) );
		}
		if ( 'preview' !== (string) $cls->list_type ) {
			return new \WP_Error( 'recruitment_preview_status_only_on_preview_list', __( 'Preliminary status can only be set on classifications in the preview list.', 'ffcertificate' ), array( 'status' => 409 ) );
		}

		$valid_statuses = array( 'empty', 'denied', 'granted', 'appeal_denied', 'appeal_granted' );
		if ( ! in_array( $preview_status, $valid_statuses, true ) ) {
			return new \WP_Error( 'recruitment_preview_status_invalid', RecruitmentErrorMessages::translate( 'recruitment_preview_status_invalid' ), array( 'status' => 400 ) );
		}

		// 'empty' clears any pre-existing reason — operators reset by
		// flipping the dropdown back to "no decision".
		if ( 'empty' === $preview_status ) {
			$reason_id = 0;
		}

		$settings = RecruitmentSettings::all();
		if ( 'empty' !== $preview_status ) {
			$required_key = 'preview_reason_required_' . $preview_status;
			$required     = ! empty( $settings[ $required_key ] );
			if ( $required && $reason_id <= 0 ) {
				return new \WP_Error( 'recruitment_preview_reason_required', __( 'A reason is required for this preliminary status.', 'ffcertificate' ), array( 'status' => 400 ) );
			}
		}

		if ( $reason_id > 0 ) {
			$reason = RecruitmentReasonRepository::get_by_id( $reason_id );
			if ( null === $reason ) {
				return new \WP_Error( 'recruitment_preview_reason_not_found', RecruitmentErrorMessages::translate( 'recruitment_preview_reason_not_found' ), array( 'status' => 404 ) );
			}
			$applies = RecruitmentReasonRepository::decode_applies_to( (string) ( $reason->applies_to ?? '' ) );
			if ( ! in_array( $preview_status, $applies, true ) ) {
				return new \WP_Error( 'recruitment_preview_reason_status_mismatch', __( 'This reason cannot be used with the chosen preliminary status.', 'ffcertificate' ), array( 'status' => 400 ) );
			}
		}

		$ok = RecruitmentClassificationRepository::set_preview_status( $id, $preview_status, $reason_id > 0 ? $reason_id : null );
		if ( ! $ok ) {
			return new \WP_Error( 'recruitment_preview_status_update_failed', RecruitmentErrorMessages::translate( 'recruitment_preview_status_update_failed' ), array( 'status' => 400 ) );
		}

		return new \WP_REST_Response( RecruitmentClassificationRepository::get_by_id( $id ), 200 );
	}

	/**
	 * PATCH /classifications/{id}/adjutancy — swap a classification's
	 * adjutancy_id. Issue #331 "Edit estendido".
	 *
	 * Validates:
	 *   - the classification exists
	 *   - the new adjutancy exists AND is attached to the classification's
	 *     notice via the `notice_adjutancy` junction (otherwise we'd
	 *     orphan the classification from the notice's effective set)
	 *   - the new adjutancy differs from the current one (no-op detected
	 *     upfront so we don't emit a misleading audit row)
	 *
	 * Surfaces failures as WP_Error with 4xx + a stable code so the
	 * frontend can branch on the reason.
	 *
	 * @since 6.6.2
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function change_classification_adjutancy( \WP_REST_Request $request ) {
		$id               = (int) $request->get_param( 'id' );
		$new_adjutancy_id = (int) $request->get_param( 'adjutancy_id' );

		if ( $new_adjutancy_id <= 0 ) {
			return new \WP_Error( 'ffc_invalid_adjutancy', __( 'A valid adjutancy_id is required.', 'ffcertificate' ), array( 'status' => 400 ) );
		}

		$cls = RecruitmentClassificationRepository::get_by_id( $id );
		if ( null === $cls ) {
			return new \WP_Error( 'ffc_classification_not_found', __( 'Classification not found.', 'ffcertificate' ), array( 'status' => 404 ) );
		}

		$current_adjutancy = (int) $cls->adjutancy_id;
		if ( $current_adjutancy === $new_adjutancy_id ) {
			return new \WP_Error( 'ffc_classification_adjutancy_unchanged', __( 'New adjutancy is the same as the current one.', 'ffcertificate' ), array( 'status' => 409 ) );
		}

		$notice_id = (int) $cls->notice_id;
		if ( ! RecruitmentNoticeAdjutancyRepository::is_attached( $notice_id, $new_adjutancy_id ) ) {
			return new \WP_Error(
				'ffc_classification_adjutancy_not_attached_to_notice',
				__( 'The selected adjutancy is not attached to this notice.', 'ffcertificate' ),
				array( 'status' => 409 )
			);
		}

		if ( ! RecruitmentClassificationRepository::set_adjutancy( $id, $new_adjutancy_id ) ) {
			return new \WP_Error( 'ffc_classification_adjutancy_update_failed', __( 'Could not update the classification.', 'ffcertificate' ), array( 'status' => 500 ) );
		}

		RecruitmentActivityLogger::classification_adjutancy_changed( $id, $current_adjutancy, $new_adjutancy_id );

		return new \WP_REST_Response(
			array(
				'success'           => true,
				'classification_id' => $id,
				'from'              => $current_adjutancy,
				'to'                => $new_adjutancy_id,
			),
			200
		);
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
}
