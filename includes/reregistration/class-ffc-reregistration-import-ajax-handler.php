<?php
/**
 * Request boundary for the reregistration CSV import (#1214, sprint 5).
 *
 * Four actions, one per phase of {@see ReregistrationImportStagingService}:
 * `start` takes the upload, `validate` produces the report the operator reads,
 * `promote` writes one batch and is called until it says it is done, `commit`
 * drops the staged cleartext.
 *
 * **`wp_ajax_`, not REST, and that is the host module's convention rather than
 * a preference.** Recruitment's batched import is REST because its module
 * already had a REST controller; this module has five `wp_ajax_` handlers and
 * no routes. Following it also buys a guard: `AjaxWiringTest` cross-checks every
 * `wp_ajax_*` registration against the action names the client asks for, in both
 * directions, and a REST route is invisible to it.
 *
 * **It is a class of its own rather than four more methods on
 * {@see ReregistrationAjaxHandler}** because that one is fixed to
 * `ffc_manage_reregistration`, and importing is a separate tier
 * (`ffc_import_reregistration`, #1214 sprint 1) — one class holding two
 * capability tiers is how a handler ends up checking the wrong one.
 *
 * @package FreeFormCertificate\Reregistration
 * @since   6.26.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Reregistration;

use FreeFormCertificate\Core\Capabilities;
use FreeFormCertificate\Core\RequestInput;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AJAX endpoints for the reregistration CSV import.
 */
final class ReregistrationImportAjaxHandler {

	/**
	 * Capability every phase requires.
	 */
	public const CAPABILITY = 'ffc_import_reregistration';

	/**
	 * Nonce action shared by the four phases.
	 */
	public const NONCE_ACTION = 'ffc_rereg_import';

	/**
	 * Largest upload accepted, in bytes.
	 *
	 * 10 MB, the recruitment importer's figure. It is a guard against a
	 * mis-selected file rather than a capacity limit — the rows go to the
	 * database and the batching is what handles size — so it is deliberately
	 * generous and deliberately not configurable.
	 */
	private const MAX_UPLOAD_BYTES = 10485760;

	/**
	 * Rows promoted per request.
	 *
	 * The service clamps to 10..100 anyway; 25 is chosen against the slowest
	 * row this import has, which writes a submission through
	 * `process_submission()` and may create a WordPress user.
	 */
	private const PROMOTE_BATCH_SIZE = 25;

	/**
	 * Register the four actions.
	 *
	 * Called from {@see ReregistrationAdmin::init()}, which already runs on
	 * every admin request — `admin-ajax.php` included, which `admin_menu` is
	 * not (the #772 lesson about registering an export source too late).
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_ajax_ffc_rereg_import_start', array( $this, 'ajax_start' ) );
		add_action( 'wp_ajax_ffc_rereg_import_validate', array( $this, 'ajax_validate' ) );
		add_action( 'wp_ajax_ffc_rereg_import_promote', array( $this, 'ajax_promote' ) );
		add_action( 'wp_ajax_ffc_rereg_import_commit', array( $this, 'ajax_commit' ) );
	}

	/**
	 * Phase 1 — take the upload and stage it.
	 *
	 * @return void
	 */
	public function ajax_start(): void {
		$this->authorize();

		$reregistration_id = RequestInput::get_post_int( 'rereg_id' );
		$audience_id       = RequestInput::get_post_int( 'audience_id' );

		if ( $reregistration_id <= 0 || $audience_id <= 0 ) {
			$this->fail( __( 'Choose a campaign and an audience.', 'ffcertificate' ) );
		}

		$content = $this->read_upload();

		$result = ReregistrationImportStagingService::ingest_job(
			$reregistration_id,
			$audience_id,
			$content,
			get_current_user_id()
		);

		if ( ! $result['ok'] ) {
			$this->fail_with_codes( $result['errors'], $result['missing_required'] ?? array() );
		}

		wp_send_json_success(
			array(
				'jobId'   => $result['job_id'],
				'total'   => $result['total'],
				'ignored' => $result['ignored'],
			)
		);
	}

	/**
	 * Phase 2 — validate, and hand back the report.
	 *
	 * @return void
	 */
	public function ajax_validate(): void {
		$this->authorize();
		$job_id = $this->own_job();

		$result = ReregistrationImportStagingService::validate_job( $job_id );

		if ( ! isset( $result['status'] ) ) {
			// The refusal shape: `{ok: false, errors: […]}` with no report.
			$this->fail_with_codes( $result['errors'] ?? array() );
		}

		$failures = array();
		foreach ( $result['failures'] as $failure ) {
			$failures[] = ReregistrationImportMessages::row_error( (int) $failure['line'], (string) $failure['error'] );
		}

		wp_send_json_success(
			array(
				'ok'       => (bool) $result['ok'],
				'status'   => (string) $result['status'],
				'total'    => (int) $result['total'],
				'ready'    => (int) $result['ready'],
				'skipped'  => (int) $result['skipped'],
				'failed'   => (int) $result['failed'],
				'failures' => $failures,
			)
		);
	}

	/**
	 * Phase 3 — write one batch.
	 *
	 * @return void
	 */
	public function ajax_promote(): void {
		$this->authorize();
		$job_id = $this->own_job();

		$result = ReregistrationImportStagingService::promote_batch( $job_id, self::PROMOTE_BATCH_SIZE );

		if ( ! $result['ok'] ) {
			$this->fail_with_codes( $result['errors'] );
		}

		wp_send_json_success(
			array(
				'processed' => (int) $result['processed'],
				'total'     => (int) $result['total'],
				'done'      => (bool) $result['done'],
			)
		);
	}

	/**
	 * Phase 4 — drop the staged cleartext and report.
	 *
	 * @return void
	 */
	public function ajax_commit(): void {
		$this->authorize();
		$job_id = $this->own_job();

		$result = ReregistrationImportStagingService::commit_job( $job_id );

		if ( ! $result['ok'] ) {
			$this->fail_with_codes( $result['errors'] );
		}

		wp_send_json_success(
			array(
				'promoted' => (int) $result['promoted'],
				'skipped'  => (int) $result['skipped'],
			)
		);
	}

	/**
	 * Nonce plus capability, on every phase.
	 *
	 * @return void
	 */
	private function authorize(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! Capabilities::current_user_can_admin_or( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ffcertificate' ) ), 403 );
		}
	}

	/**
	 * The job id from the request, once it is established it belongs to the
	 * caller.
	 *
	 * **The capability is not the whole check.** Every operator who can import
	 * holds the same capability, so without this a second one could drive a
	 * job somebody else staged — validate it, promote it, or commit it away
	 * mid-review. The job id is a random UUID, which makes that impractical to
	 * do by accident and is exactly why it is not a defence: the fence is what
	 * makes it impossible on purpose. The staging row carries the `user_id`
	 * `ingest_job()` wrote for precisely this.
	 *
	 * @return string Job id, guaranteed to exist and to be the caller's.
	 */
	private function own_job(): string {
		$job_id = RequestInput::get_post_string( 'job_id' );

		if ( '' === $job_id ) {
			$this->fail_with_codes( array( 'rereg_import_job_not_found' ) );
		}

		$job = ReregistrationImportStagingService::get_job( $job_id );
		if ( null === $job ) {
			$this->fail_with_codes( array( 'rereg_import_job_not_found' ) );
		}

		if ( get_current_user_id() !== (int) $job->user_id ) {
			$this->fail_with_codes( array( 'rereg_import_not_your_job' ) );
		}

		return $job_id;
	}

	/**
	 * Read the uploaded CSV, or answer why it cannot be read.
	 *
	 * **Nothing of ours touches the disk here, and that is a property of the
	 * design rather than an omission.** #1214's plan budgeted for a PII temp
	 * file under `wp_upload_dir()` with an `.htaccess`, a random name and a
	 * cleanup cron, the way the batched *export* does — but the staging phase
	 * reads the bytes straight into the database, so the only file involved is
	 * PHP's own upload temp, which PHP unlinks when the request ends. The
	 * cleartext that does persist lives in the staging table, with the TTL and
	 * the sweep that already cover it. The nginx caveat that comes with an
	 * `.htaccess` does not apply either.
	 *
	 * @return string Raw CSV bytes.
	 */
	private function read_upload(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The nonce is verified by authorize(), which every phase runs before reaching here; the sniff cannot follow it across a method. Each member of the array is type-checked and validated below, and none of them is echoed or reaches SQL.
		$file = isset( $_FILES['csv_file'] ) && is_array( $_FILES['csv_file'] ) ? $_FILES['csv_file'] : array();

		$tmp_name = isset( $file['tmp_name'] ) && is_string( $file['tmp_name'] ) ? $file['tmp_name'] : '';
		if ( '' === $tmp_name || ! is_uploaded_file( $tmp_name ) ) {
			// `is_uploaded_file()` is what stops a crafted `tmp_name` naming a
			// path on the server and this method reading it back out.
			$this->fail( __( 'Choose a CSV file to import.', 'ffcertificate' ) );
		}

		$name = isset( $file['name'] ) && is_string( $file['name'] ) ? $file['name'] : '';
		$ext  = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'csv', 'txt' ), true ) ) {
			$this->fail( __( 'Only .csv files are accepted.', 'ffcertificate' ) );
		}

		$size = isset( $file['size'] ) && is_numeric( $file['size'] ) ? (int) $file['size'] : 0;
		if ( $size <= 0 ) {
			$on_disk = filesize( $tmp_name );
			$size    = false !== $on_disk ? $on_disk : 0;
		}
		if ( $size > self::MAX_UPLOAD_BYTES ) {
			$this->fail(
				sprintf(
					/* translators: %s: human-readable maximum upload size (e.g. "10 MB"). */
					__( 'The file is too large (max %s).', 'ffcertificate' ),
					size_format( self::MAX_UPLOAD_BYTES )
				)
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local upload temp file, already proven to be one by is_uploaded_file() above; WP_Filesystem is for paths the site owns.
		$content = file_get_contents( $tmp_name );
		if ( false === $content || '' === $content ) {
			$this->fail( __( 'The file could not be read, or it is empty.', 'ffcertificate' ) );
		}

		return (string) $content;
	}

	/**
	 * Answer with a plain message.
	 *
	 * @param string $message Translated sentence.
	 * @return never
	 */
	private function fail( string $message ): void {
		wp_send_json_error( array( 'message' => $message ), 400 );
	}

	/**
	 * Answer with the service's codes, rendered.
	 *
	 * The first code becomes the headline and the rest ride along, because a
	 * phase reports one reason in practice and a list on screen with one entry
	 * reads worse than a sentence.
	 *
	 * @param array<int, string> $codes            Codes from the service.
	 * @param array<int, string> $missing_required Field keys with no column, when the code is the one about them.
	 * @return never
	 */
	private function fail_with_codes( array $codes, array $missing_required = array() ): void {
		$first = isset( $codes[0] ) ? (string) $codes[0] : '';

		if ( 'rereg_import_required_column_absent' === $first && array() !== $missing_required ) {
			// The service returns the field list beside the code rather than
			// inside it; joining here is what makes the presenter's detail
			// branch reachable, and naming the columns is the whole value of
			// this particular refusal.
			$first .= ':' . implode( ', ', array_map( 'strval', $missing_required ) );
		}

		$messages = array();
		foreach ( $codes as $code ) {
			$messages[] = ReregistrationImportMessages::job_error( (string) $code );
		}

		wp_send_json_error(
			array(
				'message'  => ReregistrationImportMessages::job_error( $first ),
				'messages' => $messages,
			),
			400
		);
	}
}
