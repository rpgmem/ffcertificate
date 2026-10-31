<?php
/**
 * AnswerCorrectionHandler
 *
 * Corrects one answer of an issued certificate (#2241). The answers are
 * locked on the edit screen (#1977), and a resubmission returns the stored
 * row, so a typo in a certificate had no way out but a permanent delete.
 * This is the one audited way to change an answer: a manager names the
 * field, the new value and why, and the change goes to the activity log.
 *
 * The identity of the certificate stays out of reach: CPF/RF and e-mail
 * carry hashes and indexes and belong to identity resolution, and the auth
 * code, ticket and fill date are what make a certificate verifiable.
 *
 * @package FreeFormCertificate\Admin
 * @since 6.43.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

use FreeFormCertificate\Core\ArrayValue;
use FreeFormCertificate\Core\RequestInput;
use FreeFormCertificate\Submissions\SubmissionHandler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the "Correct answer" request from the submission edit screen.
 */
class AnswerCorrectionHandler {

	/**
	 * The admin-post action.
	 */
	public const ACTION = 'ffc_correct_answer';

	/**
	 * Who may correct an answer, besides an administrator.
	 */
	public const CAPABILITY = 'ffc_manage_certificates';

	/**
	 * Data key listing the answers that were corrected, so the edit screen
	 * can say so beside each one. A flat list of field names.
	 */
	public const CORRECTED_KEY = '_corrected_fields';

	/**
	 * Keys that are never corrected here: the identity and the integrity of
	 * the certificate.
	 */
	private const LOCKED_KEYS = array( 'email', 'user_email', 'cpf_rf', 'cpf', 'rf', 'auth_code', 'ticket', 'fill_date', 'magic_token' );

	/**
	 * Submission handler.
	 *
	 * @var SubmissionHandler
	 */
	private SubmissionHandler $submission_handler;

	/**
	 * Constructor.
	 *
	 * @param SubmissionHandler $handler Submission handler.
	 */
	public function __construct( SubmissionHandler $handler ) {
		$this->submission_handler = $handler;
	}

	/**
	 * Register the admin-post handler.
	 *
	 * `admin_post_*` is the hook WordPress fires for a logged-in form POST to
	 * admin-post.php; the default priority is enough, nothing else listens.
	 */
	public function init(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * The nonce action for one submission.
	 *
	 * @param int $submission_id Submission ID.
	 * @return string
	 */
	public static function nonce_action( int $submission_id ): string {
		return self::ACTION . '_' . $submission_id;
	}

	/**
	 * Whether the current user may correct answers.
	 *
	 * @return bool
	 */
	public static function current_user_can_correct(): bool {
		return \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( self::CAPABILITY );
	}

	/**
	 * Whether a key is an answer this action may change.
	 *
	 * @param string $key Data key.
	 * @return bool
	 */
	public static function is_correctable( string $key ): bool {
		return '' !== $key && '_' !== $key[0] && ! in_array( $key, self::LOCKED_KEYS, true );
	}

	/**
	 * Handle the POST and redirect with the outcome.
	 */
	public function handle(): void {
		$id = RequestInput::get_post_int( 'submission_id' );
		check_admin_referer( self::nonce_action( $id ) );

		if ( ! self::current_user_can_correct() ) {
			wp_die( esc_html__( 'You do not have permission to correct answers.', 'ffcertificate' ), '', array( 'response' => 403 ) );
		}

		$msg = $this->correct(
			$id,
			sanitize_key( RequestInput::get_post_string( 'ffc_correct_field' ) ),
			RequestInput::get_post_string( 'ffc_correct_value' ),
			RequestInput::get_post_string( 'ffc_correct_reason' )
		);
		wp_safe_redirect( admin_url( 'edit.php?post_type=ffc_form&page=ffc-submissions&msg=' . rawurlencode( $msg ) ) );
		exit;
	}

	/**
	 * Correct one answer.
	 *
	 * @param int    $id     Submission ID.
	 * @param string $field  Data key of the answer.
	 * @param string $value  New value, as typed.
	 * @param string $reason Why it changes.
	 * @return string The outcome, a `msg` key the submissions screen reads.
	 */
	public function correct( int $id, string $field, string $value, string $reason ): string {
		$sub  = $this->submission_handler->get_submission( $id );
		$data = is_array( $sub ) ? json_decode( is_string( $sub['data'] ?? null ) ? $sub['data'] : '', true ) : null;
		if ( ! is_array( $sub ) || ! is_array( $data ) ) {
			return 'answer_not_found';
		}

		if ( ! self::is_correctable( $field ) || ! array_key_exists( $field, $data ) || is_array( $data[ $field ] ) ) {
			return 'answer_not_correctable';
		}

		$reason = trim( sanitize_textarea_field( $reason ) );
		if ( mb_strlen( $reason ) < QuizGradingHandler::MIN_REASON_LENGTH ) {
			return 'answer_no_reason';
		}

		$old   = is_scalar( $data[ $field ] ) ? (string) $data[ $field ] : '';
		$value = trim( sanitize_textarea_field( $value ) );
		if ( $value === $old ) {
			return 'answer_unchanged';
		}

		$data[ $field ]              = $value;
		$corrected                   = isset( $data[ self::CORRECTED_KEY ] ) && is_array( $data[ self::CORRECTED_KEY ] ) ? $data[ self::CORRECTED_KEY ] : array();
		$data[ self::CORRECTED_KEY ] = array_values( array_unique( array_merge( $corrected, array( $field ) ) ) );
		unset( $data['is_edited'], $data['edited_at'] );

		// Only the data changes: an empty e-mail leaves the stored one alone.
		if ( ! $this->submission_handler->update_submission( $id, '', $data ) ) {
			return 'answer_not_saved';
		}
		SubmissionHandler::forget_cached_lookups( $sub );

		// Personal values are logged masked, the way every staff surface shows them.
		\FreeFormCertificate\Core\ActivityLog::log(
			'submission_answer_corrected',
			\FreeFormCertificate\Core\ActivityLog::LEVEL_INFO,
			array(
				'form_id'   => ArrayValue::int( $sub, 'form_id', 0 ),
				'field'     => $field,
				'reason'    => $reason,
				'old_value' => \FreeFormCertificate\Core\DocumentFormatter::mask_field_value( $field, $old ),
				'new_value' => \FreeFormCertificate\Core\DocumentFormatter::mask_field_value( $field, $value ),
			),
			get_current_user_id(),
			$id
		);

		return 'answer_corrected';
	}
}
