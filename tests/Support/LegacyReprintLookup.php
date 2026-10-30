<?php
/**
 * The duplicate lookups as they stood before #2241, kept as a parity oracle.
 *
 * @package FreeFormCertificate\Tests
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Support;

/**
 * A copy of the two lookups `SubmissionReader::findForReprint()` replaced:
 * `ReprintDetector::detect()` and `SubmissionPersister::find_quiz_submission()`,
 * as they read on `develop` before #2241. `ReprintLookupParityTest` runs the
 * same scenarios through these and through the Reader and demands the same
 * row.
 *
 * The one edit: `Encryption::is_configured()` became the `$encrypted`
 * argument, so both modes run in one process. Nothing here is product code;
 * do not "fix" it, since its value is that it does what the old code did.
 */
final class LegacyReprintLookup {

	/**
	 * `ReprintDetector::detect()`'s lookup, returning the row it found.
	 *
	 * @param int    $form_id    Form ID.
	 * @param string $val_cpf    CPF/RF value.
	 * @param string $val_ticket Ticket value.
	 * @param bool   $encrypted  What `Encryption::is_configured()` answered.
	 * @return object|null
	 */
	public static function reprint( int $form_id, string $val_cpf, string $val_ticket, bool $encrypted ): ?object {
		global $wpdb;
		$table_name          = 'wp_ffc_submissions';
		$existing_submission = null;

		if ( ! empty( $val_ticket ) ) {
			if ( $encrypted ) {
				$ticket_hash         = \FreeFormCertificate\Core\SensitiveFieldRegistry::hash_identifier( 'ticket', $val_ticket );
				$existing_submission = $wpdb->get_row(
					$wpdb->prepare(
						'SELECT * FROM %i WHERE form_id = %d AND ticket_hash = %s ORDER BY id DESC LIMIT 1',
						$table_name,
						$form_id,
						$ticket_hash
					)
				);
			}

			if ( ! $existing_submission ) {
				$like_query          = '%' . $wpdb->esc_like( '"ticket":"' . $val_ticket . '"' ) . '%';
				$existing_submission = $wpdb->get_row(
					$wpdb->prepare(
						'SELECT * FROM %i WHERE form_id = %d AND data LIKE %s ORDER BY id DESC LIMIT 1',
						$table_name,
						$form_id,
						$like_query
					)
				);
			}
		} elseif ( ! empty( $val_cpf ) ) {
			$clean_cpf = \FreeFormCertificate\Core\DataSanitizer::normalize_cpf_rf( $val_cpf );

			if ( $encrypted ) {
				$id_hash     = \FreeFormCertificate\Core\SensitiveFieldRegistry::hash_identifier( 'cpf', $clean_cpf );
				$hash_column = 'rf' === \FreeFormCertificate\Core\DataSanitizer::classify_cpf_rf( $clean_cpf ) ? 'rf_hash' : 'cpf_hash';

				$existing_submission = $wpdb->get_row(
					$wpdb->prepare(
						'SELECT * FROM %i WHERE form_id = %d AND %i = %s ORDER BY id DESC LIMIT 1',
						$table_name,
						$form_id,
						$hash_column,
						$id_hash
					)
				);
			}
		}

		return $existing_submission ? $existing_submission : null;
	}

	/**
	 * `SubmissionPersister::find_quiz_submission()`'s lookup.
	 *
	 * @param int    $form_id   Form ID.
	 * @param string $cpf       CPF/RF value.
	 * @param bool   $encrypted What `Encryption::is_configured()` answered.
	 * @return object|null
	 */
	public static function quiz( int $form_id, string $cpf, bool $encrypted ): ?object {
		if ( empty( $cpf ) ) {
			return null;
		}

		global $wpdb;
		$clean_cpf = \FreeFormCertificate\Core\DataSanitizer::normalize_cpf_rf( $cpf );

		if ( $encrypted ) {
			$id_hash     = \FreeFormCertificate\Core\SensitiveFieldRegistry::hash_identifier( 'cpf', $clean_cpf );
			$hash_column = 'rf' === \FreeFormCertificate\Core\DataSanitizer::classify_cpf_rf( $clean_cpf ) ? 'rf_hash' : 'cpf_hash';

			return $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM %i WHERE form_id = %d AND {$hash_column} = %s ORDER BY id DESC LIMIT 1",
					'wp_ffc_submissions',
					$form_id,
					$id_hash
				)
			);
		}

		return null;
	}
}
