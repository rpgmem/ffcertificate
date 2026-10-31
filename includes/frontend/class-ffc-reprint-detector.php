<?php
/**
 * ReprintDetector
 *
 * Detects an existing submission for a reprint, by ticket or CPF/RF. The
 * lookup is the repository's (#2241); this class turns its row into the
 * result the submission stages read.
 *
 * @package FreeFormCertificate\Frontend
 * @since 4.12.17
 */

declare(strict_types=1);

namespace FreeFormCertificate\Frontend;

use FreeFormCertificate\Frontend\Submission\SubmissionContext;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reprint Detector.
 *
 * The result shape is declared here rather than left as
 * `array<string, mixed>`, because every consumer reads its keys and none of
 * them could be checked while the values were `mixed` (#1060). `id` keeps the
 * `numeric-string` that `$wpdb` hands back on the reprint path and the literal
 * `0` on the other; `date` is unix UTC seconds since 6.6.0 (#249).
 *
 * @phpstan-type ReprintResult array{
 *     is_reprint: bool,
 *     data: array<string, mixed>,
 *     id: numeric-string|int,
 *     email: string,
 *     date: int,
 *     user_id: int,
 *     status: string,
 *     revoked_at: int
 * }
 */
class ReprintDetector {

	/**
	 * Check for an existing submission (reprint detection).
	 *
	 * The lookup itself lives in the repository (#2241), shared with the quiz
	 * attempt count and the spent-ticket check.
	 *
	 * @param int    $form_id    Form ID.
	 * @param string $val_cpf    CPF/RF value.
	 * @param string $val_ticket Ticket value.
	 * @return ReprintResult
	 */
	public static function detect( int $form_id, string $val_cpf, string $val_ticket ): array {
		$row = ( new \FreeFormCertificate\Repositories\SubmissionRepository() )->findForReprint( $form_id, $val_cpf, $val_ticket );
		return self::result( $row );
	}

	/**
	 * The stored submission for a submission's form, CPF/RF and ticket,
	 * looked up once per submission and kept on its context (#2241).
	 *
	 * The rate-limit guard, the spent-ticket check and the persister used to
	 * run the same query two or three times per submission; each now reads
	 * the row the first of them fetched. The values it is keyed on are fixed
	 * by the field sanitizer before any of them runs.
	 *
	 * @param SubmissionContext $ctx Submission context.
	 * @return object|null
	 */
	public static function row_for( SubmissionContext $ctx ): ?object {
		if ( false === $ctx->existing_row ) {
			$ctx->existing_row = ( new \FreeFormCertificate\Repositories\SubmissionRepository() )->findForReprint( $ctx->form_id, $ctx->val_cpf, $ctx->val_ticket );
		}
		return is_object( $ctx->existing_row ) ? $ctx->existing_row : null;
	}

	/**
	 * The reprint result for a submission, from its single lookup.
	 *
	 * @param SubmissionContext $ctx Submission context.
	 * @return ReprintResult
	 */
	public static function for_context( SubmissionContext $ctx ): array {
		return self::result( self::row_for( $ctx ) );
	}

	/**
	 * The result for a looked-up row, or the "not a reprint" shape.
	 *
	 * @param object|null $row Stored row.
	 * @return ReprintResult
	 */
	private static function result( ?object $row ): array {
		if ( null !== $row ) {
			/**
			 * A submissions row as `$wpdb` returns it.
			 *
			 * @var \stdClass&object{id: numeric-string, submission_date: numeric-string|int, email_encrypted?: string|null, data?: string|null, user_id?: numeric-string|null, status?: string|null, revoked_at?: numeric-string|null} $row
			 */
			return self::build_reprint_result( $row );
		}

		return array(
			'is_reprint' => false,
			'data'       => array(),
			'id'         => 0,
			'email'      => '',
			// 0, not '': the reprint branch returns unix seconds here, and a
			// contract whose type depends on the branch cannot be checked.
			// Only the persister reads this key, and only when is_reprint.
			'date'       => 0,
			'user_id'    => 0,
			'status'     => '',
			'revoked_at' => 0,
		);
	}

	/**
	 * Build reprint result from a database row
	 *
	 * @param object $existing_submission Database row.
	 * @phpstan-param \stdClass&object{id: numeric-string, submission_date: numeric-string|int, email_encrypted?: string|null, data?: string|null, user_id?: numeric-string|null, status?: string|null, revoked_at?: numeric-string|null} $existing_submission
	 * @return ReprintResult
	 */
	private static function build_reprint_result( object $existing_submission ): array {
		// Ensure data is not null before json_decode (strict types requirement).
		$data_json = $existing_submission->data ?? '';

		// Only decode if we have actual data (not null, not empty string).
		if ( ! empty( $data_json ) ) {
			$decoded_data = json_decode( $data_json, true );
			if ( ! is_array( $decoded_data ) ) {
				$decoded_data = json_decode( wp_unslash( $data_json ), true );
			}
		} else {
			$decoded_data = null;
		}

		// If still not an array, initialize empty.
		if ( ! is_array( $decoded_data ) ) {
			$decoded_data = array();
		}

		// Ensure required column fields are included.
		if ( ! isset( $decoded_data['auth_code'] ) && ! empty( $existing_submission->auth_code ) ) {
			$decoded_data['auth_code'] = $existing_submission->auth_code;
		}

		// Populate email from encrypted column.
		$email = '';
		if ( ! empty( $existing_submission->email_encrypted ) && class_exists( '\FreeFormCertificate\Core\Encryption' ) ) {
			$email = \FreeFormCertificate\Core\Encryption::decrypt( $existing_submission->email_encrypted ) ?? '';
		}
		if ( ! isset( $decoded_data['email'] ) && ! empty( $email ) ) {
			$decoded_data['email'] = $email;
		}

		return array(
			'is_reprint' => true,
			'data'       => $decoded_data,
			'id'         => $existing_submission->id,
			'email'      => $email,
			// `submission_date` is unix UTC int since 6.6.0 (#249 sub-escopo a).
			'date'       => (int) $existing_submission->submission_date,
			// Who owns it, and whether it was revoked (#2241): a reprint is
			// handed over on screen only to its logged-in owner, and never
			// once the certificate is in the trash.
			'user_id'    => (int) ( $existing_submission->user_id ?? 0 ),
			'status'     => (string) ( $existing_submission->status ?? '' ),
			'revoked_at' => (int) ( $existing_submission->revoked_at ?? 0 ),
		);
	}
}
