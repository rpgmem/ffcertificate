<?php
/**
 * Orphaned records
 *
 * Records carrying an identifier and no account, across every store that has
 * one (#1397 sprint 6).
 *
 * WIDER THAN THE QUERY THAT EXISTED, IN THREE DIRECTIONS.
 *
 * `SubmissionReader::find_unlinked_with_matching_identity()` finds a real and
 * useful subset — and only a subset: submissions only, CPF only, and, by
 * construction, only those whose CPF already appears on a LINKED submission.
 * An orphan nobody can be matched to can never be returned by it, and that is
 * the population an operator most needs to see: it is the one that needs an
 * account opened rather than a link made.
 *
 * So this reads all three stores, both identifiers, and reports the accounts
 * that hold each identifier as EVIDENCE rather than as a filter.
 *
 * AN ERASED RECORD CANNOT APPEAR HERE, AND THAT IS WHY ADOPTION IS SAFE.
 *
 * `UserCleanup` nulls `user_id` on account deletion, so an orphan may be
 * "never had an account" or "the account was deleted", indistinguishable by
 * that column alone. But `PrivacyErasers` — the WordPress GDPR eraser, which
 * is the path a subject's erasure request takes — nulls `cpf_hash` and
 * `rf_hash` along with the ciphertexts. A record erased at the subject's
 * request therefore has no hash left to match and cannot surface in any query
 * keyed on one. Linking an orphan to an account can never resurrect a link
 * somebody asked to have removed.
 *
 * @package FreeFormCertificate\Maintenance
 * @since   6.28.4
 */

declare(strict_types=1);

namespace FreeFormCertificate\Maintenance;

use FreeFormCertificate\Repositories\UserProfileRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Every statement here targets the plugin's own ffc_* tables, for which WordPress exposes no API, and the answer must reflect the live rows: a cached one would offer an orphan a later adoption has already claimed.
/**
 * Find the records that carry an identifier and name no account.
 *
 * @phpstan-type OrphanFinding array{key: string, field: string, hash: string, stores: array<string, array<int, int>>, rows: int, has: array<string, bool>, names: array<int, string>, accounts: array<int, int>}
 */
class IdentityOrphanQuery {

	/**
	 * Stores carrying identifiers beside a nullable `user_id`.
	 *
	 * `ffc_user_profiles` is absent: it is the identity index, whose rows are
	 * an account's by definition, so it has no orphans to find.
	 *
	 * @var array<int, string>
	 */
	private const STORES = array(
		'ffc_submissions',
		'ffc_self_scheduling_appointments',
		'ffc_recruitment_candidate',
	);

	/**
	 * Stores that also record a name, for saying whose a finding is.
	 *
	 * @var array<int, string>
	 */
	private const NAMED = array(
		'ffc_self_scheduling_appointments',
		'ffc_recruitment_candidate',
	);

	/**
	 * The identifiers a finding is grouped by, in the order tried.
	 *
	 * CPF first because it is the one the resolver's first branch reads and
	 * the one an operator can validate on the spot. E-mail is NOT a grouping
	 * key: two people share an institutional mailbox often enough that the
	 * audit already treats the address as unsound above n = 2, and grouping on
	 * it would build one finding out of several people.
	 *
	 * @var array<int, string>
	 */
	public const KEYS = array( 'cpf', 'rf' );

	/**
	 * How many findings are returned.
	 *
	 * A cap the caller may raise, and one this reports hitting rather than
	 * trimming in silence — the rule the queue's own per-check cap follows.
	 *
	 * @var int
	 */
	public const LIMIT = 100;

	/**
	 * Whether the last read filled its page.
	 *
	 * @var bool
	 */
	private bool $capped = false;

	/**
	 * Every orphaned record, grouped by the identifier it carries.
	 *
	 * @param int $limit How many findings to return.
	 * @return array<int, array<string, mixed>>
	 * @phpstan-return array<int, OrphanFinding>
	 */
	public function orphans( int $limit = self::LIMIT ): array {
		global $wpdb;

		$limit        = max( 1, $limit );
		$this->capped = false;

		/**
		 * The register, keyed by `<field>|<hash>` so a person's rows in three
		 * stores fold into one finding.
		 *
		 * @var array<string, OrphanFinding> $findings
		 */
		$findings = array();

		foreach ( self::STORES as $suffix ) {
			$table = $wpdb->prefix . $suffix;

			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				continue;
			}

			// A ROW WITH NO IDENTIFIER AT ALL IS NOT AN ORPHAN THIS CAN HELP.
			//
			// There is nothing to match it on and nothing to type: it is a
			// record whose subject is unknown, which is a different problem
			// from a record whose account is missing. Excluded here rather
			// than rendered as a finding with no action.
			//
			// TWO LITERALS RATHER THAN ONE COMPOSED STATEMENT, because
			// `ffc_submissions` has no `name` column at all -- selecting it
			// there is an error, not an empty value -- and a statement built
			// by concatenation is one WPCS cannot check and a reader has to
			// assemble in their head. The reads are otherwise identical.
			//
			// The per-store page is deliberately wider than `$limit`: the
			// grouping below collapses a person's several rows into one
			// finding, so a page of findings needs more rows than findings.
			if ( in_array( $suffix, self::NAMED, true ) ) {
				$found = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT id, cpf_hash, rf_hash, email_hash, name FROM %i WHERE ( user_id IS NULL OR user_id = 0 ) AND ( ( cpf_hash IS NOT NULL AND cpf_hash <> '' ) OR ( rf_hash IS NOT NULL AND rf_hash <> '' ) ) ORDER BY id DESC LIMIT %d",
						$table,
						$limit * 10
					),
					ARRAY_A
				);
			} else {
				$found = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT id, cpf_hash, rf_hash, email_hash FROM %i WHERE ( user_id IS NULL OR user_id = 0 ) AND ( ( cpf_hash IS NOT NULL AND cpf_hash <> '' ) OR ( rf_hash IS NOT NULL AND rf_hash <> '' ) ) ORDER BY id DESC LIMIT %d",
						$table,
						$limit * 10
					),
					ARRAY_A
				);
			}

			foreach ( (array) $found as $row ) {
				$this->absorb( $findings, $table, (array) $row );
			}
		}

		$this->capped = count( $findings ) > $limit;
		$findings     = array_slice( array_values( $findings ), 0, $limit );

		foreach ( $findings as $i => $finding ) {
			$findings[ $i ]['accounts'] = $this->accounts_holding( $finding );
		}

		return $findings;
	}

	/**
	 * Whether the last read returned more findings than it could show.
	 *
	 * @return bool
	 */
	public function capped(): bool {
		return $this->capped;
	}

	/**
	 * Fold one row into the finding for the identifier it carries.
	 *
	 * @param array<string, mixed> $findings The register, keyed.
	 * @param string               $table    The prefixed store.
	 * @param array<mixed, mixed>  $row      The row, however it arrived.
	 * @phpstan-param array<string, OrphanFinding> $findings
	 * @return void
	 */
	private function absorb( array &$findings, string $table, array $row ): void {
		$field = '';
		$hash  = '';

		foreach ( self::KEYS as $candidate ) {
			$value = (string) ( $row[ $candidate . '_hash' ] ?? '' );

			if ( '' !== $value ) {
				$field = $candidate;
				$hash  = $value;
				break;
			}
		}

		if ( '' === $hash ) {
			return;
		}

		$key = $field . '|' . $hash;

		if ( ! isset( $findings[ $key ] ) ) {
			$findings[ $key ] = array(
				'key'      => $key,
				'field'    => $field,
				'hash'     => $hash,
				'stores'   => array(),
				'rows'     => 0,
				'has'      => array(
					'cpf'   => false,
					'rf'    => false,
					'email' => false,
				),
				'names'    => array(),
				'accounts' => array(),
			);
		}

		$findings[ $key ]['stores'][ $table ][] = (int) ( $row['id'] ?? 0 );
		++$findings[ $key ]['rows'];

		// WHAT THE PERSON HAS IS THE UNION OVER THEIR ROWS, NOT ONE ROW'S.
		//
		// A candidacy may carry an e-mail its submission does not, and the
		// account that would be opened needs all three from wherever they are.
		// Reading one row would report a gap that is not there.
		foreach ( array( 'cpf', 'rf', 'email' ) as $kind ) {
			if ( '' !== (string) ( $row[ $kind . '_hash' ] ?? '' ) ) {
				$findings[ $key ]['has'][ $kind ] = true;
			}
		}

		$name = trim( (string) ( $row['name'] ?? '' ) );

		if ( '' !== $name && ! in_array( $name, $findings[ $key ]['names'], true ) ) {
			$findings[ $key ]['names'][] = $name;
		}
	}

	/**
	 * Which accounts already hold this finding's identifier.
	 *
	 * EVIDENCE, NEVER A FILTER.
	 *
	 * The query this widens returned only orphans whose identifier already sat
	 * on a linked row, which silently dropped every orphan that needs an
	 * ACCOUNT rather than a link. Here an empty list is a finding in its own
	 * right and the screen says which it is.
	 *
	 * @param array{field: string, hash: string} $finding The finding.
	 * @return array<int, int>
	 */
	private function accounts_holding( array $finding ): array {
		return $this->profiles()->findUserIdsByHash( $finding['field'] . '_hash', $finding['hash'] );
	}

	/**
	 * The identity index, as a seam a test can stand in for.
	 *
	 * @return UserProfileRepository
	 */
	protected function profiles(): UserProfileRepository {
		return new UserProfileRepository();
	}
}
// phpcs:enable WordPress.DB.DirectDatabaseQuery
