<?php
/**
 * Human sentences for the reregistration import's error codes (#1214).
 *
 * The staging service answers in machine codes — `rereg_import_no_identifier`,
 * `rereg_import_already_submitted:approved` — because they are a stable
 * contract its tests pin and its `error` column stores. The operator reads a
 * screen, so something has to turn one into the other, and this is it.
 *
 * **Why the codes were not simply replaced with sentences at the source:** the
 * `error` column is written by the validate phase and read by the promote and
 * commit phases, and a stored sentence is a stored translation — it would
 * freeze whichever language the operator who ran validate was using, and a
 * second operator finishing the job would read it in that one. The code stays
 * in the database; the sentence is produced per request, in the reader's
 * locale.
 *
 * @package FreeFormCertificate\Reregistration
 * @since   6.26.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Reregistration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns an import error code into something an operator can act on.
 */
final class ReregistrationImportMessages {

	/**
	 * Render a job-level failure code.
	 *
	 * These are the ones that stop a phase outright, so there is no line to
	 * name — the whole file is refused.
	 *
	 * @param string $code Code from the staging service's `errors` list.
	 * @return string Translated sentence.
	 */
	public static function job_error( string $code ): string {
		list( $base, $detail ) = self::split( $code );

		switch ( $base ) {
			case 'rereg_import_audience_not_in_campaign':
				return __( 'That audience does not belong to this campaign.', 'ffcertificate' );

			case 'rereg_import_csv_empty':
				return __( 'The file has no header row.', 'ffcertificate' );

			case 'rereg_import_csv_has_no_rows':
				return __( 'The file has a header but no data rows.', 'ffcertificate' );

			case 'rereg_import_no_column_matched':
				return __( 'None of the columns match a field of this audience. Check that the header carries the field names or their labels.', 'ffcertificate' );

			case 'rereg_import_required_column_absent':
				return '' !== $detail
					/* translators: %s: comma-separated list of field names. */
					? sprintf( __( 'The file has no column for these required fields: %s.', 'ffcertificate' ), $detail )
					: __( 'The file is missing a column for a required field.', 'ffcertificate' );

			case 'rereg_import_job_not_found':
				return __( 'This import is no longer available. It may have expired — start again.', 'ffcertificate' );

			case 'rereg_import_job_has_no_rows':
				return __( 'This import has no staged rows.', 'ffcertificate' );

			case 'rereg_import_campaign_not_found':
				return __( 'The campaign no longer exists.', 'ffcertificate' );

			case 'rereg_import_job_invalid_state_for_promote':
				return __( 'This import cannot be applied: it has not been validated, or it was blocked.', 'ffcertificate' );

			case 'rereg_import_job_invalid_state_for_commit':
				return __( 'This import cannot be finished yet: it has not been applied.', 'ffcertificate' );

			case 'rereg_import_job_not_finished':
				return __( 'Some rows have not been applied yet.', 'ffcertificate' );

			case 'rereg_import_job_insert_failed':
			case 'rereg_import_staging_insert_failed':
				return __( 'The file could not be staged. Please try again.', 'ffcertificate' );

			case 'rereg_import_user_resolution_failed':
				return self::with_line(
					__( 'The account for this row could not be resolved or created.', 'ffcertificate' ),
					$detail
				);

			case 'rereg_import_submission_seed_failed':
				return self::with_line(
					__( 'This row has no record to write into and one could not be created.', 'ffcertificate' ),
					$detail
				);

			case 'rereg_import_not_your_job':
				return __( 'This import was started by another user.', 'ffcertificate' );

			default:
				return self::unknown( $code );
		}
	}

	/**
	 * Render a per-row failure from the validation report.
	 *
	 * @param int    $line  Line in the file, as the operator's spreadsheet
	 *                      numbers it.
	 * @param string $code  Code stored on the staged row.
	 * @return string Translated sentence, prefixed with the line.
	 */
	public static function row_error( int $line, string $code ): string {
		list( $base, $detail ) = self::split( $code );

		switch ( $base ) {
			case 'rereg_import_no_identifier':
				$body = __( 'No CPF, RF or e-mail, so there is nobody to match or create.', 'ffcertificate' );
				break;

			case 'rereg_import_duplicate_identity':
				$body = '' !== $detail
					/* translators: %s: the earlier line number in the file. */
					? sprintf( __( 'Resolves to the same account as line %s. A shared mailbox does this — give each person their own address, or their CPF/RF.', 'ffcertificate' ), $detail )
					: __( 'Resolves to the same account as an earlier row.', 'ffcertificate' );
				break;

			case 'rereg_import_already_submitted':
				$body = __( 'This person has already submitted; their own answers are kept.', 'ffcertificate' );
				break;

			default:
				// Anything else is a field-level `WP_Error` code from
				// `CustomFieldReader::validate_field_value()`, stored as
				// `<code>:<field_key>`. Naming the field is the actionable
				// half, and it is the half a generic fallback would lose.
				$body = '' !== $detail
					/* translators: %s: the field key as the CSV header spells it. */
					? sprintf( __( 'The value in column "%s" is not valid for that field.', 'ffcertificate' ), $detail )
					: self::unknown( $code );
				break;
		}

		return self::with_line( $body, (string) $line );
	}

	/**
	 * Split `code:detail` into its two halves.
	 *
	 * A field code can itself contain no colon, and a detail never does, so one
	 * split from the LEFT is exact — `explode( ':', $code, 2 )`. Splitting from
	 * the right would break `rereg_import_already_submitted:approved` the day a
	 * status gains a colon, which is not a bet worth taking for no gain.
	 *
	 * @param string $code Raw code.
	 * @return array{0: string, 1: string} Base and detail; detail is '' when absent.
	 */
	private static function split( string $code ): array {
		$parts = explode( ':', $code, 2 );

		return array( $parts[0], $parts[1] ?? '' );
	}

	/**
	 * Prefix a sentence with the file line it belongs to.
	 *
	 * @param string $body Sentence.
	 * @param string $line Line number, or '' when there is none.
	 * @return string
	 */
	private static function with_line( string $body, string $line ): string {
		if ( '' === $line || ! is_numeric( $line ) ) {
			return $body;
		}

		return sprintf(
			/* translators: 1: line number in the uploaded file, 2: what is wrong with it. */
			__( 'Line %1$d: %2$s', 'ffcertificate' ),
			(int) $line,
			$body
		);
	}

	/**
	 * Last resort for a code this table does not know.
	 *
	 * It shows the code rather than swallowing it: an operator who can quote
	 * the exact string gets help faster than one who read "an error occurred",
	 * and a code reaching here is a gap in this table worth reporting.
	 *
	 * @param string $code Raw code.
	 * @return string
	 */
	private static function unknown( string $code ): string {
		return sprintf(
			/* translators: %s: raw error code, shown so it can be reported. */
			__( 'Unexpected error (%s).', 'ffcertificate' ),
			$code
		);
	}
}
