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
		'pending',
		'in_progress',
		'rejected',
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
	 *  - a user added to an audience **after** the campaign went active, since
	 *    seeding is a one-shot on that transition;
	 *  - a submission an administrator deleted;
	 *  - a member of a **child** audience of one attached to the campaign —
	 *    `get_active_for_audience()` walks UP to parents, so the campaign is
	 *    visible to them, while seeding reads `get_members()` without
	 *    `include_children`, so they are never seeded. That asymmetry is
	 *    systematic in any hierarchy and is tracked apart; creating on demand
	 *    covers it without touching the seeding side.
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

		if ( in_array( $submission->status, array( 'approved', 'expired' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'This reregistration has already been completed or expired.', 'ffcertificate' ) ) );
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

		if ( in_array( $submission->status, array( 'approved', 'expired' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'This reregistration has already been completed or expired.', 'ffcertificate' ) ) );
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

		// Process submission.
		ReregistrationDataProcessor::process_submission( $submission, $rereg, $data, $user_id );

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
		if ( ! $submission || in_array( $submission->status, array( 'approved', 'expired' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Cannot save draft.', 'ffcertificate' ) ) );
		}

		$data = ReregistrationDataProcessor::collect_form_data( $rereg );

		ReregistrationSubmissionWriter::update(
			(int) $submission->id,
			array(
				'data'   => $data,
				'status' => 'in_progress',
			)
		);

		wp_send_json_success( array( 'message' => __( 'Draft saved.', 'ffcertificate' ) ) );
	}

	// ------------------------------------------------------------------.
	// Public static helpers used by REST controllers + shortcodes.
	// ------------------------------------------------------------------.

	/**
	 * Get active reregistrations for a user with submission status.
	 *
	 * Aggregates `ReregistrationRepository`, `ReregistrationSubmissionReader`,
	 * and `MagicLinkHelper` into a flat array consumed by the dashboard
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

			// Build magic link for submitted/approved submissions.
			$magic_link = '';
			if ( $submission && in_array( $sub_status, array( 'submitted', 'approved' ), true ) ) {
				$token      = ReregistrationSubmissionWriter::ensure_magic_token( $submission );
				$magic_link = \FreeFormCertificate\Generators\MagicLinkHelper::generate_magic_link( $token );
			}

			$result[] = array(
				'id'                => (int) $rereg->id,
				'title'             => $rereg->title,
				'audience_name'     => $rereg->audience_name ?? '',
				'start_date'        => $rereg->start_date,
				'end_date'          => $rereg->end_date,
				'auto_approve'      => ! empty( $rereg->auto_approve ),
				'submission_status' => $sub_status,
				'submission_id'     => $submission ? (int) $submission->id : 0,
				'can_submit'        => in_array( $sub_status, self::SUBMITTABLE_STATUSES, true ),
				'magic_link'        => $magic_link,
			);
		}

		return $result;
	}
}
