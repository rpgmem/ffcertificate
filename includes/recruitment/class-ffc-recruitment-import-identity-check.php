<?php
/**
 * Recruitment Import Identity Check
 *
 * Compares the rows of an import with the candidates already stored, before
 * anything is written (#2245). Candidates are global: a row is matched to a
 * stored candidate by CPF, then by RF, and the classification it creates
 * belongs to that candidate in every notice. So a row that matches a stored
 * candidate but names somebody else is not a correction to apply — it is a
 * question for the operator.
 *
 * Two outcomes:
 *
 * - **Conflict.** The row's CPF belongs to one candidate and its RF to
 *   another. No import can be right about both, so the file is refused with
 *   the line named.
 * - **Mismatch.** The row matches one candidate, but its name, CPF, RF or
 *   e-mail differs from what is stored. The operator must acknowledge it;
 *   the import then attaches the row to the stored candidate and keeps the
 *   stored identity, which {@see CandidatePersister} never overwrites.
 *
 * Shared by the single-request import and the batched one, so both paths
 * judge a file the same way. Only hashes and names are compared; nothing is
 * decrypted, and the report names fields, never values.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.44.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Recruitment;

use FreeFormCertificate\Core\DataSanitizer;
use FreeFormCertificate\Core\SensitiveFieldRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pre-import comparison of CSV rows with stored candidates.
 *
 * @phpstan-type IdentityInput array{line: int, cpf: string, rf: string, email: string, name: string}
 * @phpstan-type Mismatch array{line: int, candidate_id: int, fields: list<string>}
 * @phpstan-type IdentityReport array{conflicts: list<string>, mismatches: list<Mismatch>}
 */
final class RecruitmentImportIdentityCheck {

	/**
	 * Error code returned while mismatches wait for the operator.
	 */
	public const MISMATCH_CODE = 'recruitment_import_identity_mismatch';

	/**
	 * Compare every row with the stored candidates.
	 *
	 * @param array $rows Rows of the file, plaintext.
	 * @phpstan-param list<IdentityInput> $rows
	 * @return IdentityReport
	 */
	public static function check( array $rows ): array {
		$hashed = array();
		foreach ( $rows as $row ) {
			$email    = DataSanitizer::normalize_email( $row['email'] );
			$hashed[] = array(
				'line'  => $row['line'],
				'cpf'   => (string) SensitiveFieldRegistry::hash_identifier( 'cpf', trim( $row['cpf'] ) ),
				'rf'    => (string) SensitiveFieldRegistry::hash_identifier( 'rf', trim( $row['rf'] ) ),
				'email' => (string) SensitiveFieldRegistry::hash_identifier( 'email', $email ),
				'name'  => self::name_key( $row['name'] ),
			);
		}

		$by_cpf = RecruitmentCandidateReader::get_identities_by_hash( 'cpf_hash', array_column( $hashed, 'cpf' ) );
		$by_rf  = RecruitmentCandidateReader::get_identities_by_hash( 'rf_hash', array_column( $hashed, 'rf' ) );

		$conflicts  = array();
		$mismatches = array();

		foreach ( $hashed as $row ) {
			$cpf_match = '' !== $row['cpf'] ? ( $by_cpf[ $row['cpf'] ] ?? null ) : null;
			$rf_match  = '' !== $row['rf'] ? ( $by_rf[ $row['rf'] ] ?? null ) : null;

			if ( null !== $cpf_match && null !== $rf_match && (int) $cpf_match->id !== (int) $rf_match->id ) {
				$conflicts[] = CsvValidator::line_error( $row['line'], 'recruitment_csv_identity_conflict' );
				continue;
			}

			$stored = $cpf_match ?? $rf_match;
			if ( null === $stored ) {
				continue;
			}

			$fields = array();
			if ( '' !== $row['name'] && self::name_key( (string) $stored->name ) !== $row['name'] ) {
				$fields[] = 'name';
			}
			if ( '' !== $row['cpf'] && null !== $stored->cpf_hash && '' !== $stored->cpf_hash && $stored->cpf_hash !== $row['cpf'] ) {
				$fields[] = 'cpf';
			}
			if ( '' !== $row['rf'] && null !== $stored->rf_hash && '' !== $stored->rf_hash && $stored->rf_hash !== $row['rf'] ) {
				$fields[] = 'rf';
			}
			if ( '' !== $row['email'] && null !== $stored->email_hash && '' !== $stored->email_hash && $stored->email_hash !== $row['email'] ) {
				$fields[] = 'email';
			}

			if ( ! empty( $fields ) ) {
				$mismatches[] = array(
					'line'         => $row['line'],
					'candidate_id' => (int) $stored->id,
					'fields'       => $fields,
				);
			}
		}

		return array(
			'conflicts'  => $conflicts,
			'mismatches' => $mismatches,
		);
	}

	/**
	 * One translated sentence per mismatch, for the operator to read.
	 *
	 * @param array $mismatches Mismatches from {@see check()}.
	 * @phpstan-param list<Mismatch> $mismatches
	 * @return list<string>
	 */
	public static function messages( array $mismatches ): array {
		$labels = array(
			'name'  => __( 'name', 'ffcertificate' ),
			'cpf'   => __( 'CPF', 'ffcertificate' ),
			'rf'    => __( 'RF', 'ffcertificate' ),
			'email' => __( 'e-mail', 'ffcertificate' ),
		);

		$out = array();
		foreach ( $mismatches as $mismatch ) {
			$names = array();
			foreach ( $mismatch['fields'] as $field ) {
				$names[] = $labels[ $field ] ?? $field;
			}
			$out[] = sprintf(
				/* translators: 1: CSV line number, 2: comma-separated field names, 3: candidate ID */
				__( 'Line %1$d differs from candidate #%3$d in: %2$s. The stored data is kept.', 'ffcertificate' ),
				$mismatch['line'],
				implode( ', ', $names ),
				$mismatch['candidate_id']
			);
		}
		return $out;
	}

	/**
	 * Comparison key of a name: case, accents and spacing do not count.
	 *
	 * @param string $name Name as typed.
	 * @return string
	 */
	private static function name_key( string $name ): string {
		$collapsed = preg_replace( '/\s+/u', ' ', trim( $name ) );
		return mb_strtolower( remove_accents( is_string( $collapsed ) ? $collapsed : trim( $name ) ), 'UTF-8' );
	}
}
