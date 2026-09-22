<?php
/**
 * Identity repair
 *
 * Rewrites one stored RF across every store that holds it, for the operator
 * working the queue #1377 delivered. The check digit says a number is wrong
 * and never what the right one is, so the correct value arrives from HR and
 * this class is what writes it (#1368).
 *
 * @package FreeFormCertificate\Maintenance
 * @since 6.28.2
 */

declare(strict_types=1);

namespace FreeFormCertificate\Maintenance;

use FreeFormCertificate\Core\ActivityLog;
use FreeFormCertificate\Core\DocumentFormatter;
use FreeFormCertificate\Core\SensitiveFieldRegistry;
use FreeFormCertificate\Repositories\UserProfileRepository;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Every statement here targets the plugin's own ffc_* tables, for which WordPress exposes no API, and a repair must read and write the live rows: a cached answer would be precisely wrong.
/**
 * Rewrite a stored RF, everywhere it is stored.
 */
class IdentityRepair {

	/**
	 * The identifier this class repairs.
	 *
	 * RF and not CPF on purpose: the queue is the check-digit scan's, and the
	 * CPF path already refuses a mistyped value at the form.
	 */
	public const FIELD = 'rf';

	/**
	 * Store table (unprefixed) => the encryption context that owns its shape.
	 *
	 * The context is what makes this a rewrite rather than a reimplementation:
	 * `SensitiveFieldRegistry::encrypt_fields()` is the single policy source
	 * the forward path uses, so a repair produces the same pair a submission
	 * would. Reimplementing `encrypt()` + `hash()` here would be a second
	 * policy that agrees today and drifts later.
	 *
	 * `ffc_user_profiles` is deliberately absent: it is the identity index and
	 * carries a hash with no ciphertext, so it is updated separately, through
	 * its repository.
	 */
	private const STORES = array(
		'ffc_submissions'                  => SensitiveFieldRegistry::CONTEXT_SUBMISSION,
		'ffc_self_scheduling_appointments' => SensitiveFieldRegistry::CONTEXT_APPOINTMENT,
		'ffc_recruitment_candidate'        => SensitiveFieldRegistry::CONTEXT_RECRUITMENT_CANDIDATE,
	);

	/**
	 * The store that holds each RF exactly once.
	 *
	 * `ffc_recruitment_candidate` declares `UNIQUE KEY uq_rf_hash`, which the
	 * other two stores do not, so it is the one place a consolidation can be
	 * refused by the schema rather than by a decision.
	 *
	 * @since 6.28.3
	 * @var string
	 */
	private const UNIQUE_RF_STORE = 'ffc_recruitment_candidate';

	/**
	 * How many characters of a hash reach the log.
	 *
	 * A prefix identifies the finding across two log lines without being the
	 * hash: `CLAUDE.md` forbids logging raw PII and a full hash of a
	 * seven-digit number is not far from the number, since the space is small
	 * enough to enumerate against a known salt.
	 */
	public const LOG_PREFIX = 12;

	/**
	 * Repair one stored RF.
	 *
	 * RE-EVALUATED, NEVER TRUSTED FROM THE LIST
	 *
	 * The only thing this takes from the caller is the subject hash and the
	 * value HR confirmed -- never the row ids the screen displayed. Between
	 * listing a finding and confirming it the operator went and asked a
	 * person, and rows may have arrived or left in the meantime. So the rows
	 * are resolved here, at write time, from the hash.
	 *
	 * @param string $subject_hash The stored `rf_hash` to replace.
	 * @param string $new_rf       The value HR confirmed.
	 * @param int    $actor        Who confirmed it, for the log.
	 * @return array{hash: string, rows: array<string, int>, account: int}|WP_Error
	 */
	public function repair( string $subject_hash, string $new_rf, int $actor = 0 ): array|WP_Error {
		global $wpdb;

		$subject_hash = trim( $subject_hash );

		if ( '' === $subject_hash ) {
			return new WP_Error(
				'ffc_identity_repair_no_subject',
				__( 'No finding was named.', 'ffcertificate' )
			);
		}

		$normalized = SensitiveFieldRegistry::normalize( self::FIELD, $new_rf );

		if ( ! DocumentFormatter::validate_rf( $normalized ) || ! DocumentFormatter::rf_check_digit_matches( $normalized ) ) {
			return new WP_Error(
				'ffc_identity_repair_invalid',
				__( 'That number does not satisfy the RF check digit, so it cannot be the corrected value either. Confirm it with HR.', 'ffcertificate' )
			);
		}

		$new_hash = SensitiveFieldRegistry::hash_identifier( self::FIELD, $normalized );

		if ( ! is_string( $new_hash ) || '' === $new_hash ) {
			return new WP_Error(
				'ffc_identity_repair_not_configured',
				__( 'Encryption is not configured, so an identifier cannot be rewritten.', 'ffcertificate' )
			);
		}

		if ( $new_hash === $subject_hash ) {
			return new WP_Error(
				'ffc_identity_repair_unchanged',
				__( 'The value stored is already the one you confirmed.', 'ffcertificate' )
			);
		}

		$found = $this->rows_for( $subject_hash );

		// Idempotent by construction: a finding repaired by somebody else
		// between the list and the confirmation resolves to nothing, and that
		// is a result rather than a failure.
		if ( array() === $found['rows'] ) {
			return new WP_Error(
				'ffc_identity_repair_gone',
				__( 'Nothing carries that value any more — it was repaired already. Reload the queue.', 'ffcertificate' )
			);
		}

		// ONE CORRECTION CANNOT SERVE TWO PEOPLE.
		//
		// A finding is a hash, and two people can mistype into the same wrong
		// number. Writing the confirmed value across all of them would put one
		// person's RF on the other's row -- silently, because both rows then
		// hold a value whose check digit is fine.
		if ( count( $found['accounts'] ) > 1 ) {
			return new WP_Error(
				'ffc_identity_repair_ambiguous',
				sprintf(
					/* translators: %d: how many accounts the finding names. */
					__( 'This finding names %d accounts, so one corrected value cannot serve it: the same wrong number was typed by more than one person. Resolve them separately.', 'ffcertificate' ),
					count( $found['accounts'] )
				)
			);
		}

		$account      = $found['accounts'][0] ?? 0;
		$collides     = $this->rows_for( $new_hash );
		$consolidates = false;

		if ( array() !== $collides['rows'] ) {
			// A COLLISION ON THE SAME ACCOUNT IS THE TYPO ITSELF.
			//
			// This refusal used to read "the value already exists", and that
			// is the wrong predicate: in the typo the correct value IS the
			// account's other RF, so it always has rows and the refusal fired
			// on every case production has -- all 32 of them (#1386). What
			// makes a correction a merge is the value belonging to ANOTHER
			// account; the same account holding it twice is one person's own
			// duplicate, which is what a repair consolidates.
			//
			// Strict equality against a single-element list is the whole rule:
			// an unowned colliding row (an unpromoted candidacy) contributes
			// no account, so a collision that names nobody, or names anybody
			// else, still refuses.
			$consolidates = $account > 0 && array( $account ) === $collides['accounts'];

			if ( ! $consolidates ) {
				return new WP_Error(
					'ffc_identity_repair_collision',
					__( 'That value is already stored against another account, so correcting this one would merge two identities rather than fix a typo. Merging is a separate decision.', 'ffcertificate' )
				);
			}

			$unique = $wpdb->prefix . self::UNIQUE_RF_STORE;

			// ONE STORE CANNOT HOLD THE CONSOLIDATION.
			//
			// `ffc_recruitment_candidate` declares `UNIQUE KEY uq_rf_hash`, so
			// where a person's two RFs are BOTH candidacies the rewrite would
			// leave two rows sharing one hash and the database would refuse it
			// -- as a duplicate-key error, which says nothing an operator can
			// act on. Refusing here says what is in the way instead. Resolving
			// it means deciding what becomes of the second candidacy, which is
			// not a repair.
			if ( isset( $found['rows'][ $unique ], $collides['rows'][ $unique ] ) ) {
				return new WP_Error(
					'ffc_identity_repair_unique_store',
					sprintf(
						/* translators: %s: the store that cannot hold two rows with one identifier. */
						__( 'Both identifiers are recorded in %s, which holds each identifier once, so consolidating them there would leave two records claiming one. Decide what becomes of the second record first.', 'ffcertificate' ),
						$unique
					)
				);
			}
		}

		$pair = SensitiveFieldRegistry::encrypt_fields(
			SensitiveFieldRegistry::CONTEXT_SUBMISSION,
			array( self::FIELD => $normalized )
		);

		if ( empty( $pair['rf_encrypted'] ) || empty( $pair['rf_hash'] ) ) {
			return new WP_Error(
				'ffc_identity_repair_not_configured',
				__( 'Encryption is not configured, so an identifier cannot be rewritten.', 'ffcertificate' )
			);
		}

		$written = array();

		// The index and the rows must not be able to disagree, and this writes
		// to as many as four tables. Rolled back as one on any failure --
		// which assumes InnoDB, as every ffc_* table is.
		$wpdb->query( 'START TRANSACTION' );

		foreach ( $found['rows'] as $table => $ids ) {
			$done = $wpdb->update(
				$table,
				array(
					'rf_encrypted' => (string) $pair['rf_encrypted'],
					'rf_hash'      => (string) $pair['rf_hash'],
				),
				array( 'rf_hash' => $subject_hash ),
				array( '%s', '%s' ),
				array( '%s' )
			);

			if ( false === $done ) {
				$wpdb->query( 'ROLLBACK' );

				return new WP_Error(
					'ffc_identity_repair_failed',
					sprintf(
						/* translators: %s: the store whose update failed. */
						__( 'The store %s refused the write, so nothing was changed.', 'ffcertificate' ),
						$table
					)
				);
			}

			$written[ $table ] = count( $ids );
		}

		if ( $account > 0 && ! $this->reindex( $account, (string) $pair['rf_hash'] ) ) {
			$wpdb->query( 'ROLLBACK' );

			return new WP_Error(
				'ffc_identity_repair_index_failed',
				__( 'The rows were rewritten but the identity index refused the same value, so nothing was changed. The two must never disagree.', 'ffcertificate' )
			);
		}

		$wpdb->query( 'COMMIT' );

		// #1367's defect, one account at a time: a record that becomes
		// readable is useless behind an account that cannot read it. The
		// action rather than a direct call because the capability owner is a
		// module this one has no edge to.
		if ( $account > 0 ) {
			do_action( 'ffc_grant_certificate_capabilities', $account );
		}

		ActivityLog::log(
			'identity_rf_repaired',
			ActivityLog::LEVEL_WARNING,
			array(
				// Prefixes, never the hashes and never the value: the log is
				// read by more people than the screen is.
				'from'    => substr( $subject_hash, 0, self::LOG_PREFIX ),
				'to'      => substr( (string) $pair['rf_hash'], 0, self::LOG_PREFIX ),
				'stores'  => $written,
				'account' => $account,
				// A consolidation merged two of one person's own records; a
				// plain repair renamed one. Same action, different blast
				// radius, and the log is where that is legible afterwards.
				'merged'  => $consolidates,
			),
			$actor
		);

		return array(
			'hash'    => (string) $pair['rf_hash'],
			'rows'    => $written,
			'account' => $account,
		);
	}

	/**
	 * Which rows carry a hash, and which accounts they name.
	 *
	 * @param string $hash The `rf_hash` to look for.
	 * @return array{rows: array<string, list<int>>, accounts: list<int>}
	 */
	private function rows_for( string $hash ): array {
		global $wpdb;

		$rows     = array();
		$accounts = array();

		foreach ( array_keys( self::STORES ) as $suffix ) {
			$table = $wpdb->prefix . $suffix;

			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				continue;
			}

			$found = $wpdb->get_results(
				$wpdb->prepare( 'SELECT id, user_id FROM %i WHERE rf_hash = %s', $table, $hash ),
				ARRAY_A
			);

			$ids = array();

			foreach ( (array) $found as $row ) {
				$ids[] = (int) ( $row['id'] ?? 0 );
				$owner = (int) ( $row['user_id'] ?? 0 );

				if ( $owner > 0 && ! in_array( $owner, $accounts, true ) ) {
					$accounts[] = $owner;
				}
			}

			if ( array() !== $ids ) {
				$rows[ $table ] = $ids;
			}
		}

		return array(
			'rows'     => $rows,
			'accounts' => $accounts,
		);
	}

	/**
	 * Point the identity index at the corrected hash.
	 *
	 * OVERWRITING IS THE DECISION HERE, AND THAT IS THE DIFFERENCE.
	 *
	 * `UserCreator::feed_identity_index()` never overwrites a populated
	 * column, deliberately, so the backfill and the forward path cannot
	 * disagree. In a repair the operator has decided against the value the
	 * index holds -- reusing that rule unexamined would make this write
	 * silently do nothing in exactly the case it exists for (#1368).
	 *
	 * @param int    $user_id  The account that owns the rows.
	 * @param string $new_hash The corrected hash.
	 * @return bool
	 */
	protected function reindex( int $user_id, string $new_hash ): bool {
		return ( new UserProfileRepository() )->upsertForUserId(
			$user_id,
			array( 'rf_hash' => $new_hash )
		);
	}
}
// phpcs:enable WordPress.DB.DirectDatabaseQuery
