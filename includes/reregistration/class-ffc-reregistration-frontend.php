<?php
/**
 * Reregistration Frontend (Coordinator)
 *
 * Thin coordinator that handles AJAX endpoints and delegates to:
 *
 *   ReregistrationFieldOptions   – Form field option data (sexo, estado civil, etc.)
 *   ReregistrationFormRenderer   – Form HTML rendering
 *   ReregistrationDataProcessor  – Data collection, validation, and submission processing
 *
 * @package FreeFormCertificate\Reregistration
 * @since 4.11.0
 * @version 4.12.8 - Refactored into coordinator + 3 sub-classes
 */

declare(strict_types=1);

namespace FreeFormCertificate\Reregistration;

use FreeFormCertificate\Core\RequestInput;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reregistration Frontend.
 *
 * @phpstan-import-type ReregistrationSubmissionRow from ReregistrationSubmissionReader
 * @phpstan-import-type ReregistrationRow from ReregistrationRepository
 */
class ReregistrationFrontend {

	/**
	 * Synthetic status for a user with no submission row for a campaign.
	 *
	 * Two real states produce it, and both must be able to submit (#1125): a
	 * user added to the audience *after* the campaign went active (the seeding
	 * is a one-shot on that transition, so they never get a row), and a
	 * submission an administrator deleted, which the maintainer decided must
	 * return the user to the start rather than lock them out.
	 */
	public const STATUS_NO_SUBMISSION = 'no_submission';

	/**
	 * Statuses from which the user may fill the form.
	 *
	 * `no_submission` belongs here and was the omission behind #1125: it is not
	 * a state the user has *left*, it is the state before they begin.
	 *
	 * @var array<int, string>
	 */
	public const SUBMITTABLE_STATUSES = array(
		self::STATUS_NO_SUBMISSION,
		...ReregistrationSubmissionReader::OPEN_STATUSES,
	);

	/**
	 * Initialize AJAX hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'wp_ajax_ffc_get_reregistration_form', array( __CLASS__, 'ajax_get_form' ) );
		add_action( 'wp_ajax_ffc_submit_reregistration', array( __CLASS__, 'ajax_submit' ) );
		add_action( 'wp_ajax_ffc_save_reregistration_draft', array( __CLASS__, 'ajax_save_draft' ) );
		add_action( 'wp_ajax_ffc_import_previous_reregistration', array( __CLASS__, 'ajax_import_previous' ) );
		add_action( 'wp_ajax_ffc_retract_reregistration', array( __CLASS__, 'ajax_retract' ) );
	}

	/**
	 * The user's submission row for a campaign, created on demand.
	 *
	 * **`no_submission` was submittable in name only.** #1125 put it in
	 * `SUBMITTABLE_STATUSES`, so `can_submit` is true and the dashboard draws
	 * the button — and then all three AJAX handlers refused, because each
	 * required a row to exist and none created one. The button was a dead end
	 * by construction for the exact state the constant declares submittable.
	 *
	 * Three populations reach it, not the two the constant's docblock names:
	 *
	 *  - a user added to an audience **after** the campaign went active, until
	 *    the next daily run seeds them (#2242);
	 *  - a submission an administrator deleted;
	 *  - a member of a **child** audience added after the activation — the
	 *    seeding includes child audiences, but only from the moment it runs.
	 *    Creating on demand covers the gap until the daily run does.
	 *
	 * Entitlement is not re-derived: it asks the **same query that decided the
	 * user could see the campaign at all** (`get_active_for_user`), so no new
	 * authorization surface is introduced and the two can never disagree.
	 *
	 * The table carries `UNIQUE KEY (reregistration_id, user_id)`, so two
	 * concurrent opens cannot duplicate — the loser's INSERT fails and the
	 * re-read below returns the winner's row.
	 *
	 * @param int $reregistration_id Campaign id.
	 * @param int $user_id           Current user.
	 * @return ReregistrationSubmissionRow|null The row, or null when the user is not entitled.
	 */
	private static function submission_for( int $reregistration_id, int $user_id ): ?object {
		$submission = ReregistrationSubmissionReader::get_by_reregistration_and_user( $reregistration_id, $user_id );
		if ( $submission ) {
			return $submission;
		}

		if ( ! self::user_may_reregister( $reregistration_id, $user_id ) ) {
			return null;
		}

		ReregistrationSubmissionWriter::create(
			array(
				'reregistration_id' => $reregistration_id,
				'user_id'           => $user_id,
				'status'            => 'pending',
			)
		);

		return ReregistrationSubmissionReader::get_by_reregistration_and_user( $reregistration_id, $user_id );
	}

	/**
	 * Whether this campaign is one the user is entitled to fill.
	 *
	 * Reuses the visibility query rather than re-deriving membership: it is
	 * what `get_user_reregistrations()` lists and therefore what put the
	 * button on the screen. Deriving it a second way is how the two halves
	 * of #1125 came to disagree in the first place.
	 *
	 * @param int $reregistration_id Campaign id.
	 * @param int $user_id           Current user.
	 * @return bool
	 */
	private static function user_may_reregister( int $reregistration_id, int $user_id ): bool {
		foreach ( ReregistrationRepository::get_active_for_user( $user_id ) as $rereg ) {
			if ( (int) $rereg->id === $reregistration_id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Why the member may not open, save or submit the form now, or null.
	 *
	 * One gate for the three handlers (#2242): the submission must be in
	 * {@see ReregistrationSubmissionReader::OPEN_STATUSES}, and the site's
	 * clock inside the campaign window. Before, the handlers refused only
	 * `approved` and `expired`, so a `submitted` row could be pulled back into
	 * a draft, and the window held only after the daily run expired the
	 * campaign.
	 *
	 * @param object $rereg      Campaign.
	 * @phpstan-param ReregistrationRow $rereg
	 * @param object $submission Submission row.
	 * @phpstan-param ReregistrationSubmissionRow $submission
	 * @return string|null
	 */
	private static function refusal( object $rereg, object $submission ): ?string {
		if ( ! in_array( (string) $submission->status, ReregistrationSubmissionReader::OPEN_STATUSES, true ) ) {
			return in_array( (string) $submission->status, array( 'approved', 'expired' ), true )
				? __( 'This reregistration has already been completed or expired.', 'ffcertificate' )
				: self::already_submitted_message();
		}

		$state = ReregistrationWindow::state( (string) $rereg->start_date, (string) $rereg->end_date );
		if ( ReregistrationWindow::OPEN !== $state ) {
			return ReregistrationWindow::closed_message( $state );
		}

		return null;
	}

	/**
	 * The message for a submission that already went in.
	 *
	 * @return string
	 */
	private static function already_submitted_message(): string {
		return __( 'This reregistration was already submitted and is awaiting review.', 'ffcertificate' );
	}

	/**
	 * AJAX: Get reregistration form HTML.
	 *
	 * @return void
	 */
	public static function ajax_get_form(): void {
		check_ajax_referer( 'ffc_reregistration_frontend', 'nonce' );

		$reregistration_id = isset( $_POST['reregistration_id'] ) ? absint( $_POST['reregistration_id'] ) : 0;
		$user_id           = get_current_user_id();

		if ( ! $reregistration_id || ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'ffcertificate' ) ) );
		}

		$rereg = ReregistrationRepository::get_by_id( $reregistration_id );
		if ( ! $rereg || 'active' !== $rereg->status ) {
			wp_send_json_error( array( 'message' => __( 'Reregistration not found or not active.', 'ffcertificate' ) ) );
		}

		$submission = self::submission_for( $reregistration_id, $user_id );
		if ( ! $submission ) {
			wp_send_json_error( array( 'message' => __( 'You are not part of this reregistration.', 'ffcertificate' ) ) );
		}

		$refusal = self::refusal( $rereg, $submission );
		if ( null !== $refusal ) {
			wp_send_json_error( array( 'message' => $refusal ) );
		}

		$html = ReregistrationFormRenderer::render( $rereg, $submission, $user_id );
		wp_send_json_success( array( 'html' => $html ) );
	}

	/**
	 * AJAX: brings back the values of the user's last APPROVED submission.
	 *
	 * It only runs when the participant ASKS -- the offer is a notice on the
	 * form, and without the click nothing is fetched. That is on purpose: data
	 * from a previous cycle accepted without checking is a stale reregistration
	 * wearing a new one's face, and whoever asked knows they asked.
	 *
	 * **It returns PII in clear text**, so the authorization is the same as
	 * `ajax_get_form()`'s and is worth re-reading: a nonce, the user derived
	 * from `get_current_user_id()` -- never from the request -- and the SOURCE
	 * submission looked up BY that user, not by an id the client sends. There
	 * is no way to ask for somebody else's history.
	 *
	 * Only fields that exist in the CURRENT campaign come back: whatever does
	 * not match is ignored, with no conversion and no warning.
	 *
	 * @since 6.25.0
	 * @return void
	 */
	public static function ajax_import_previous(): void {
		check_ajax_referer( 'ffc_reregistration_frontend', 'nonce' );

		// Read through `RequestInput`, not by a direct cast over the
		// superglobal: the helper guards the scalar, so a posted array reads as
		// 0 instead of becoming the number 1 (#1087). This file's three older
		// reads are in `RequestInputCastTest`'s baseline; this one need not
		// enter it.
		$reregistration_id = RequestInput::get_post_int( 'reregistration_id' );
		$user_id           = get_current_user_id();

		if ( ! $reregistration_id || ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'ffcertificate' ) ) );
		}

		$rereg = ReregistrationRepository::get_by_id( $reregistration_id );
		if ( ! $rereg || 'active' !== $rereg->status ) {
			wp_send_json_error( array( 'message' => __( 'Reregistration not found or not active.', 'ffcertificate' ) ) );
		}

		// The same gate as the form's: no row in this campaign, no import.
		if ( ! self::submission_for( $reregistration_id, $user_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not part of this reregistration.', 'ffcertificate' ) ) );
		}

		$source = ReregistrationSubmissionReader::get_latest_approved_for_user( $user_id, $reregistration_id );
		if ( ! $source ) {
			wp_send_json_error( array( 'message' => __( 'No previous approved reregistration to import.', 'ffcertificate' ) ) );
		}

		$saved  = $source->data ? json_decode( (string) $source->data, true ) : array();
		$values = is_array( $saved['fields'] ?? null ) ? $saved['fields'] : array();

		// The sensitive ones come encrypted, as #1210 established. The fields
		// used for DECRYPTION are the SOURCE campaign's -- that is where the
		// `is_sensitive` flag which governed the write lives.
		$source_rereg = ReregistrationRepository::get_by_id( (int) $source->reregistration_id );
		if ( $source_rereg ) {
			$values = RecordGenerator::decrypt_field_values(
				RecordGenerator::get_custom_fields_for_reregistration( $source_rereg ),
				$values
			);
		}

		// Intersection with the current campaign. A key that does not exist here is ignored.
		$current_keys = array();
		foreach ( RecordGenerator::get_custom_fields_for_reregistration( $rereg ) as $field ) {
			$current_keys[ (string) $field->field_key ] = true;
		}

		$fields = array();
		foreach ( $values as $key => $value ) {
			if ( isset( $current_keys[ (string) $key ] ) && '' !== $value && null !== $value ) {
				$fields[ (string) $key ] = $value;
			}
		}

		wp_send_json_success(
			array(
				'fields' => $fields,
				'source' => array( 'title' => (string) ( $source->reregistration_title ?? '' ) ),
			)
		);
	}

	/**
	 * AJAX: Submit reregistration.
	 *
	 * @return void
	 */
	public static function ajax_submit(): void {
		check_ajax_referer( 'ffc_reregistration_frontend', 'nonce' );

		// Honeypot check (defense-in-depth — form already requires login).
		if ( ! empty( $_POST['ffc_honeypot_trap'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid submission.', 'ffcertificate' ) ) );
		}

		$reregistration_id = isset( $_POST['reregistration_id'] ) ? absint( $_POST['reregistration_id'] ) : 0;
		$user_id           = get_current_user_id();

		if ( ! $reregistration_id || ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'ffcertificate' ) ) );
		}

		$rereg = ReregistrationRepository::get_by_id( $reregistration_id );
		if ( ! $rereg || 'active' !== $rereg->status ) {
			wp_send_json_error( array( 'message' => __( 'Reregistration not found or not active.', 'ffcertificate' ) ) );
		}

		$submission = self::submission_for( $reregistration_id, $user_id );
		if ( ! $submission ) {
			wp_send_json_error( array( 'message' => __( 'You are not part of this reregistration.', 'ffcertificate' ) ) );
		}

		$refusal = self::refusal( $rereg, $submission );
		if ( null !== $refusal ) {
			wp_send_json_error( array( 'message' => $refusal ) );
		}

		// Collect and validate fields.
		$data   = ReregistrationDataProcessor::collect_form_data( $rereg );
		$errors = ReregistrationDataProcessor::validate_submission( $data, $rereg, $user_id );

		if ( ! empty( $errors ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Please fix the errors below.', 'ffcertificate' ),
					'errors'  => $errors,
				)
			);
		}

		// The write tests the status in the same statement, so the second of
		// two concurrent submits finds the row already submitted and stops
		// here: one state change, one confirmation e-mail.
		if ( ! ReregistrationDataProcessor::process_submission( $submission, $rereg, $data, $user_id ) ) {
			wp_send_json_error( array( 'message' => self::already_submitted_message() ) );
		}

		wp_send_json_success( array( 'message' => __( 'Reregistration submitted successfully!', 'ffcertificate' ) ) );
	}

	/**
	 * AJAX: Save draft.
	 *
	 * @return void
	 */
	public static function ajax_save_draft(): void {
		check_ajax_referer( 'ffc_reregistration_frontend', 'nonce' );

		// Honeypot check (defense-in-depth).
		if ( ! empty( $_POST['ffc_honeypot_trap'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid submission.', 'ffcertificate' ) ) );
		}

		$reregistration_id = isset( $_POST['reregistration_id'] ) ? absint( $_POST['reregistration_id'] ) : 0;
		$user_id           = get_current_user_id();

		if ( ! $reregistration_id || ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'ffcertificate' ) ) );
		}

		$rereg = ReregistrationRepository::get_by_id( $reregistration_id );
		if ( ! $rereg || 'active' !== $rereg->status ) {
			wp_send_json_error( array( 'message' => __( 'Reregistration not active.', 'ffcertificate' ) ) );
		}

		$submission = self::submission_for( $reregistration_id, $user_id );
		if ( ! $submission ) {
			wp_send_json_error( array( 'message' => __( 'Cannot save draft.', 'ffcertificate' ) ) );
		}

		$refusal = self::refusal( $rereg, $submission );
		if ( null !== $refusal ) {
			wp_send_json_error( array( 'message' => $refusal ) );
		}

		$data = ReregistrationDataProcessor::collect_form_data( $rereg );

		if ( ! ReregistrationDataProcessor::save_draft( $submission, $rereg, $data ) ) {
			wp_send_json_error( array( 'message' => self::already_submitted_message() ) );
		}

		wp_send_json_success( array( 'message' => __( 'Draft saved.', 'ffcertificate' ) ) );
	}

	/**
	 * Whether the member may take back a submission (#2242).
	 *
	 * Only one nobody reviewed yet (`submitted`), and only while the campaign
	 * window is open, so the member can still submit it again. The caller
	 * answers for the campaign being active.
	 *
	 * @param object      $rereg      Campaign.
	 * @phpstan-param ReregistrationRow $rereg
	 * @param object|null $submission Submission row, or null.
	 * @phpstan-param ReregistrationSubmissionRow|null $submission
	 * @return bool
	 */
	public static function can_retract( object $rereg, ?object $submission ): bool {
		return $submission
			&& 'submitted' === (string) $submission->status
			&& ReregistrationWindow::is_open( (string) $rereg->start_date, (string) $rereg->end_date );
	}

	/**
	 * AJAX: the member takes back a submission awaiting review (#2242).
	 *
	 * The row goes back to draft with its answers and leaves the review queue.
	 * The dashboard asks for confirmation before calling this.
	 *
	 * @return void
	 */
	public static function ajax_retract(): void {
		check_ajax_referer( 'ffc_reregistration_frontend', 'nonce' );

		$reregistration_id = isset( $_POST['reregistration_id'] ) ? absint( $_POST['reregistration_id'] ) : 0;
		$user_id           = get_current_user_id();

		if ( ! $reregistration_id || ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'ffcertificate' ) ) );
		}

		$rereg      = ReregistrationRepository::get_by_id( $reregistration_id );
		$submission = ReregistrationSubmissionReader::get_by_reregistration_and_user( $reregistration_id, $user_id );
		if ( ! $rereg || ! $submission || 'active' !== $rereg->status || ! self::can_retract( $rereg, $submission ) || ! ReregistrationSubmissionWriter::retract( (int) $submission->id ) ) {
			wp_send_json_error( array( 'message' => __( 'This submission can no longer be retracted: it was already reviewed or the campaign is closed.', 'ffcertificate' ) ) );
		}

		if ( class_exists( '\FreeFormCertificate\Core\ActivityLog' ) ) {
			\FreeFormCertificate\Core\ActivityLog::log(
				'reregistration_submission_retracted',
				\FreeFormCertificate\Core\ActivityLog::LEVEL_INFO,
				array(
					'reregistration_id' => $reregistration_id,
					'submission_id'     => (int) $submission->id,
				),
				$user_id
			);
		}

		wp_send_json_success( array( 'message' => __( 'Submission retracted. It is a draft again: edit it and submit it before the deadline.', 'ffcertificate' ) ) );
	}

	// ------------------------------------------------------------------.
	// Public static helpers used by REST controllers + shortcodes.
	// ------------------------------------------------------------------.

	/**
	 * Get active reregistrations for a user with submission status.
	 *
	 * Aggregates `ReregistrationRepository`, `ReregistrationSubmissionReader`,
	 * into a flat array consumed by the dashboard
	 * shortcode, the REST `/user-data/reregistrations` endpoint, and the
	 * user-summary REST endpoint. Not a delegate — owns the join logic.
	 *
	 * @param int $user_id User ID.
	 * @return array<int, array<string, mixed>> Array of reregistration data with submission info.
	 */
	public static function get_user_reregistrations( int $user_id ): array {
		$active = ReregistrationRepository::get_active_for_user( $user_id );
		$result = array();

		foreach ( $active as $rereg ) {
			$submission = ReregistrationSubmissionReader::get_by_reregistration_and_user( (int) $rereg->id, $user_id );
			$sub_status = $submission ? $submission->status : self::STATUS_NO_SUBMISSION;

			$result[] = array(
				'id'                => (int) $rereg->id,
				'title'             => $rereg->title,
				'audience_name'     => $rereg->audience_name ?? '',
				'start_date'        => $rereg->start_date,
				'end_date'          => $rereg->end_date,
				'auto_approve'      => ! empty( $rereg->auto_approve ),
				'submission_status' => $sub_status,
				'submission_id'     => $submission ? (int) $submission->id : 0,
				'can_submit'        => in_array( $sub_status, self::SUBMITTABLE_STATUSES, true ) && ReregistrationWindow::is_open( (string) $rereg->start_date, (string) $rereg->end_date ),
				// The Record downloads, signed in, only while approved (#2242).
				'can_download'      => $submission && 'approved' === $sub_status && ! empty( $submission->auth_code ),
				'can_retract'       => self::can_retract( $rereg, $submission ),
				// The reviewer's reason for a rejection or a return to draft
				// (#2242); '' otherwise.
				'review_reason'     => $submission ? self::review_reason( $submission ) : '',
				// Approval instant, unix UTC (category A); null when the
				// submission was never reviewed. The dashboard counts the
				// approved banner's window from it (#1887).
				'reviewed_at'       => ( $submission && ! empty( $submission->reviewed_at ) ) ? (int) $submission->reviewed_at : null,
			);
		}

		return $result;
	}

	/**
	 * The reason the member reads on the banner, or ''.
	 *
	 * `notes` also holds "Auto-approved" on an approved row, so only a
	 * rejected or returned row shows it: a returned row is the only draft
	 * that carries notes.
	 *
	 * @param object $submission Submission row.
	 * @phpstan-param ReregistrationSubmissionRow $submission
	 * @return string
	 */
	private static function review_reason( object $submission ): string {
		if ( ! in_array( (string) $submission->status, array( 'rejected', 'in_progress' ), true ) ) {
			return '';
		}
		return is_string( $submission->notes ?? null ) ? trim( $submission->notes ) : '';
	}

	/**
	 * Most expired campaigns {@see self::get_recently_expired_approved()} reads.
	 */
	public const EXPIRED_APPROVED_LIMIT = 20;

	/**
	 * Expired campaigns whose approved banner is still inside its window (#1887).
	 *
	 * The dashboard keeps an approved member's banner after the daily run has
	 * expired the campaign, until `rereg_approved_banner_days` days after the
	 * later of the end and the approval. Rows have the shape of
	 * {@see self::get_user_reregistrations()}, always `approved` and never
	 * submittable, so the renderer can only draw them as the approved banner.
	 * Kept apart from that method on purpose: its other consumers (the REST
	 * summary) count active campaigns, and must not start counting these.
	 *
	 * With the window at 0 ("never hide") there is no date bound, only the
	 * user, the approved status and {@see self::EXPIRED_APPROVED_LIMIT}.
	 *
	 * @param int $user_id User ID.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_recently_expired_approved( int $user_id ): array {
		$days = \FreeFormCertificate\Core\ReregistrationBannerWindow::days();
		$now  = time();
		$tz   = wp_timezone();

		$end_since      = null;
		$reviewed_since = 0;
		if ( $days > 0 ) {
			// One day of slack: the exact cut-off is decided per row below,
			// so the query only has to keep every row that might qualify.
			$span           = $days + 1;
			$end_since      = ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( $tz )->sub( new \DateInterval( 'P' . $span . 'D' ) )->format( 'Y-m-d 00:00:00' );
			$reviewed_since = $now - ( $span * 86400 );
		}

		$campaigns = ReregistrationRepository::get_expired_with_approved_submission( $user_id, $end_since, $reviewed_since, self::EXPIRED_APPROVED_LIMIT );
		$result    = array();

		foreach ( $campaigns as $rereg ) {
			$submission = ReregistrationSubmissionReader::get_by_reregistration_and_user( (int) $rereg->id, $user_id );
			if ( ! $submission || 'approved' !== $submission->status ) {
				continue;
			}

			$reviewed_at = ! empty( $submission->reviewed_at ) ? (int) $submission->reviewed_at : null;
			if ( \FreeFormCertificate\Core\ReregistrationBannerWindow::approved_banner_expired( (string) $rereg->end_date, $reviewed_at, $days, $now, $tz ) ) {
				continue;
			}

			$result[] = array(
				'id'                => (int) $rereg->id,
				'title'             => $rereg->title,
				'audience_name'     => $rereg->audience_name ?? '',
				'start_date'        => $rereg->start_date,
				'end_date'          => $rereg->end_date,
				'auto_approve'      => ! empty( $rereg->auto_approve ),
				'submission_status' => 'approved',
				'submission_id'     => (int) $submission->id,
				'can_submit'        => false,
				'can_download'      => ! empty( $submission->auth_code ),
				'can_retract'       => false,
				'review_reason'     => '',
				'reviewed_at'       => $reviewed_at,
			);
		}

		return $result;
	}
}
