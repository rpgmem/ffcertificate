<?php
/**
 * IdentityFindingVerdicts
 *
 * What the addresses and the decrypted values say about an account that holds
 * more than one identifier of a kind (#1345, #1925).
 *
 * `IdentityConflictQuery::multiple_identities()` finds the accounts; this
 * annotates each finding with the two verdicts the queue tiers on. It runs
 * over the findings the scan ALREADY flagged, so its cost is the finding
 * count and not the table size.
 *
 * @package FreeFormCertificate\Maintenance
 * @since 6.41.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Maintenance;

use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Core\SensitiveFieldRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The email and shape verdicts on multiple-identity findings.
 */
class IdentityFindingVerdicts {

	/**
	 * Annotate each finding with both verdicts.
	 *
	 * Email first, then shape: the order the columns were added in, and the
	 * order a reader of the finding meets them.
	 *
	 * @param array<int, array<string, mixed>> $rows Findings from `IdentityConflictQuery::multiple_identities()`.
	 * @return array<int, array<string, mixed>>
	 */
	public function annotate( array $rows ): array {
		return $this->with_shape_verdict( $this->with_email_verdict( $rows ) );
	}

	/**
	 * The column holding the hashed address, where a store has one.
	 *
	 * @var string
	 */
	private const COLUMN_EMAIL_HASH = 'email_hash';

	/**
	 * How bad each shape is, for reducing a set of pairs to one verdict.
	 *
	 * @var array<string, int>
	 */
	private const SHAPE_SEVERITY = array(
		IdentityConflictQuery::SHAPE_SAME_AFTER_CANONICALISATION => 0,
		IdentityConflictQuery::SHAPE_LEADING_ZEROS     => 1,
		IdentityConflictQuery::SHAPE_TRANSPOSITION     => 2,
		IdentityConflictQuery::SHAPE_SINGLE_DIGIT_EDIT => 3,
		IdentityConflictQuery::SHAPE_UNRELATED         => 4,
	);

	/**
	 * Annotate each finding with what the addresses say about it.
	 *
	 * See {@see IdentityConflictQuery::COLUMN_EMAIL_VERDICT}. This runs over the accounts the
	 * scan ALREADY flagged rather than over every account, so its cost is the
	 * finding count and not the table size.
	 *
	 * @param array<int, array<string, mixed>> $rows Findings from `grouped()`.
	 * @return array<int, array<string, mixed>>
	 */
	private function with_email_verdict( array $rows ): array {
		$wanted = array();

		foreach ( $rows as $row ) {
			$column = self::read_column( $row );
			$user   = self::read_subject_user( $row );

			if ( '' !== $column && $user > 0 ) {
				$wanted[ $column ][ $user ] = true;
			}
		}

		$seen = array();
		foreach ( $wanted as $column => $users ) {
			$seen[ $column ] = $this->addresses_per_identifier( (string) $column, array_keys( $users ) );
		}

		foreach ( $rows as $index => $row ) {
			$column   = self::read_column( $row );
			$user     = self::read_subject_user( $row );
			$total    = $row[ IdentityConflictQuery::ALIAS_IDENTITY_COUNT ] ?? 0;
			$expected = is_numeric( $total ) ? (int) $total : 0;

			$rows[ $index ][ IdentityConflictQuery::COLUMN_EMAIL_VERDICT ] = self::verdict(
				$seen[ $column ][ $user ] ?? array(),
				$expected
			);
		}

		return $rows;
	}

	/**
	 * The identifier column a finding is about, or an empty string.
	 *
	 * @param array<string, mixed> $row One finding.
	 * @return string
	 */
	private static function read_column( array $row ): string {
		$column = $row['identifier_column'] ?? '';

		return ( is_string( $column ) && in_array( $column, IdentityStores::COLUMNS, true ) ) ? $column : '';
	}

	/**
	 * The account a finding is about, or 0 when its subject is not one.
	 *
	 * @param array<string, mixed> $row One finding.
	 * @return int
	 */
	private static function read_subject_user( array $row ): int {
		$subject = $row['subject'] ?? null;

		return is_numeric( $subject ) ? (int) $subject : 0;
	}

	/**
	 * Which hashed addresses were used with each of an account's identifiers.
	 *
	 * `SELECT DISTINCT` because the answer is a SET: a person with four
	 * hundred submissions is one address repeated, and grouping in PHP over
	 * four hundred rows to reach the same two values is work the server can
	 * skip entirely.
	 *
	 * A row whose address is missing or empty is left out, so an identifier
	 * with no address at all simply has no group -- which is what
	 * {@see self::verdict()} reads as `unknown` rather than as evidence.
	 *
	 * @param string          $column   Identifier column.
	 * @param array<int, int> $user_ids Accounts to ask about.
	 * @return array<int, array<string, array<int, string>>> user id → hash → addresses.
	 */
	private function addresses_per_identifier( string $column, array $user_ids ): array {
		global $wpdb;

		$stores = $this->stores_with_addresses();
		$out    = array();

		if ( array() === $stores || array() === $user_ids ) {
			return $out;
		}

		foreach ( array_chunk( $user_ids, IdentityStores::USERS_PER_STATEMENT ) as $chunk ) {
			$parts  = array();
			$values = array();
			$slots  = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );

			foreach ( $stores as $table ) {
				$parts[]  = "SELECT DISTINCT user_id, %i AS h, %i AS e FROM %i WHERE user_id IN ({$slots}) AND %i IS NOT NULL AND %i <> '' AND %i IS NOT NULL AND %i <> ''";
				$values[] = $column;
				$values[] = self::COLUMN_EMAIL_HASH;
				$values[] = $table;
				foreach ( $chunk as $user_id ) {
					$values[] = (int) $user_id;
				}
				$values[] = $column;
				$values[] = $column;
				$values[] = self::COLUMN_EMAIL_HASH;
				$values[] = self::COLUMN_EMAIL_HASH;
			}

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The fragment is this class's own literal, repeated once per table it resolved itself; `$slots` is `%d` placeholders counted off `$chunk`, never request data. An audit read must reflect the live rows, never a cache.
			$rows = $wpdb->get_results( $wpdb->prepare( implode( ' UNION ', $parts ), ...$values ), ARRAY_A );
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			foreach ( (array) $rows as $row ) {
				$row  = (array) $row;
				$user = isset( $row['user_id'] ) && is_numeric( $row['user_id'] ) ? (int) $row['user_id'] : 0;
				$hash = isset( $row['h'] ) && is_string( $row['h'] ) ? $row['h'] : '';
				$mail = isset( $row['e'] ) && is_string( $row['e'] ) ? $row['e'] : '';

				if ( $user > 0 && '' !== $hash && '' !== $mail ) {
					$out[ $user ][ $hash ][] = $mail;
				}
			}
		}

		return $out;
	}

	/**
	 * The stores that carry a hashed address beside the identifier.
	 *
	 * Probed rather than listed, for the reason {@see IdentityStores::all()} probes
	 * the tables: a list written here is a claim about a schema this file does
	 * not own, and it goes stale in silence. `ffc_user_profiles` is the
	 * ordinary miss -- it is the identity index and stores no address.
	 *
	 * @return list<string>
	 */
	private function stores_with_addresses(): array {
		global $wpdb;

		$out = array();

		foreach ( IdentityStores::all() as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema probe, as in `IdentityStores::all()`.
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, self::COLUMN_EMAIL_HASH ) );

			if ( self::COLUMN_EMAIL_HASH === $found ) {
				$out[] = $table;
			}
		}

		return $out;
	}

	/**
	 * What one account's addresses say about its identifiers.
	 *
	 * The question is deliberately "is any address SHARED", not "are the sets
	 * equal": a person who changed address between two submissions still has
	 * the old one under both identifiers if they are really one person's, and
	 * demanding equality would call that two people.
	 *
	 * @param array<string, array<int, string>> $by_hash  Hash → addresses used with it.
	 * @param int                               $expected How many identifiers the finding counted.
	 * @return string One of the `VERDICT_*` constants.
	 */
	private static function verdict( array $by_hash, int $expected ): string {
		// Fewer groups than identifiers means at least one identifier has no
		// address on any row, so there is nothing to compare it against.
		if ( $expected < 2 || count( $by_hash ) < $expected ) {
			return IdentityConflictQuery::VERDICT_UNKNOWN;
		}

		$groups_per_address = array();

		foreach ( $by_hash as $addresses ) {
			foreach ( array_unique( $addresses ) as $address ) {
				$groups_per_address[ $address ] = ( $groups_per_address[ $address ] ?? 0 ) + 1;
			}
		}

		foreach ( $groups_per_address as $groups ) {
			if ( $groups > 1 ) {
				return IdentityConflictQuery::VERDICT_SHARED_EMAIL;
			}
		}

		return IdentityConflictQuery::VERDICT_DISTINCT_EMAILS;
	}

	/**
	 * Annotate each finding with how its account's identifiers differ.
	 *
	 * Mirrors {@see self::with_email_verdict()} in shape and in cost: it runs
	 * over the accounts the scan ALREADY flagged, so the work is the finding
	 * count and not the table size -- roughly two decryptions per finding.
	 *
	 * @param array<int, array<string, mixed>> $rows Findings from `grouped()`.
	 * @return array<int, array<string, mixed>>
	 */
	private function with_shape_verdict( array $rows ): array {
		$wanted = array();

		foreach ( $rows as $row ) {
			$column = self::read_column( $row );
			$user   = self::read_subject_user( $row );

			if ( '' !== $column && $user > 0 ) {
				$wanted[ $column ][ $user ] = true;
			}
		}

		$seen = array();
		foreach ( $wanted as $column => $users ) {
			$seen[ $column ] = $this->ciphertexts_per_identifier( (string) $column, array_keys( $users ) );
		}

		foreach ( $rows as $index => $row ) {
			$column   = self::read_column( $row );
			$user     = self::read_subject_user( $row );
			$total    = $row[ IdentityConflictQuery::ALIAS_IDENTITY_COUNT ] ?? 0;
			$expected = is_numeric( $total ) ? (int) $total : 0;

			$rows[ $index ][ IdentityConflictQuery::COLUMN_SHAPE_VERDICT ] = ( '' === $column || $user < 1 )
				? ''
				: self::shape(
					$this->plaintexts( $seen[ $column ][ $user ] ?? array() ),
					IdentityStores::field_key_of( $column ),
					$expected
				);
		}

		return $rows;
	}

	/**
	 * One ciphertext per distinct identifier an account holds.
	 *
	 * The ciphertext is aliased `c` and the address union aliases its own `e`.
	 * They are two different reads over the same tables with the same shape,
	 * so an alias they shared would make them tellable apart only by a longer
	 * look at the fragment -- which is exactly what a test double dispatching
	 * on the SQL has to do.
	 *
	 * **`MIN()`, not `DISTINCT`.** `Encryption::encrypt()` uses a random IV, so
	 * every row's ciphertext is unique even for one value -- a `SELECT DISTINCT`
	 * over the ciphertext would return a row per submission and hand a person
	 * with four hundred of them four hundred identical plaintexts to decrypt.
	 * Grouping by the hash and taking any one member is sound precisely because
	 * they all decrypt to the same string: that is what sharing a hash means.
	 *
	 * @param string          $column   Identifier column (`cpf_hash` / `rf_hash`).
	 * @param array<int, int> $user_ids Accounts to ask about.
	 * @return array<int, array<string, string>> user id → hash → one ciphertext.
	 */
	private function ciphertexts_per_identifier( string $column, array $user_ids ): array {
		global $wpdb;

		$cipher_column = IdentityStores::ciphertext_column_of( $column );
		$stores        = IdentityStores::with_ciphertext( $cipher_column );
		$out           = array();

		if ( array() === $stores || array() === $user_ids || '' === $cipher_column ) {
			return $out;
		}

		foreach ( array_chunk( $user_ids, IdentityStores::USERS_PER_STATEMENT ) as $chunk ) {
			$parts  = array();
			$values = array();
			$slots  = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );

			foreach ( $stores as $table ) {
				$parts[]  = "SELECT user_id, %i AS h, MIN(%i) AS c FROM %i WHERE user_id IN ({$slots}) AND %i IS NOT NULL AND %i <> '' AND %i IS NOT NULL AND %i <> '' GROUP BY user_id, %i";
				$values[] = $column;
				$values[] = $cipher_column;
				$values[] = $table;
				foreach ( $chunk as $user_id ) {
					$values[] = (int) $user_id;
				}
				$values[] = $column;
				$values[] = $column;
				$values[] = $cipher_column;
				$values[] = $cipher_column;
				$values[] = $column;
			}

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The fragment is this class's own literal, repeated once per table it resolved itself; `$slots` is `%d` placeholders counted off `$chunk`, never request data. An audit read must reflect the live rows, never a cache.
			$rows = $wpdb->get_results( $wpdb->prepare( implode( ' UNION ', $parts ), ...$values ), ARRAY_A );
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			foreach ( (array) $rows as $row ) {
				$row    = (array) $row;
				$user   = isset( $row['user_id'] ) && is_numeric( $row['user_id'] ) ? (int) $row['user_id'] : 0;
				$hash   = isset( $row['h'] ) && is_string( $row['h'] ) ? $row['h'] : '';
				$cipher = isset( $row['c'] ) && is_string( $row['c'] ) ? $row['c'] : '';

				// The UNION can offer the same hash from two stores; either
				// member decrypts to the same value, so the first wins.
				if ( $user > 0 && '' !== $hash && '' !== $cipher && ! isset( $out[ $user ][ $hash ] ) ) {
					$out[ $user ][ $hash ] = $cipher;
				}
			}
		}

		return $out;
	}

	/**
	 * Decrypt each ciphertext, dropping the ones that cannot be read.
	 *
	 * The count is what carries the failure: {@see self::shape()} compares it
	 * against how many identifiers the finding counted, so a value that would
	 * not decrypt becomes `not_decryptable` rather than silently shrinking the
	 * set and letting the rest look conclusive.
	 *
	 * @param array<string, string> $by_hash Hash → ciphertext.
	 * @return list<string> Plaintexts, as stored.
	 */
	private function plaintexts( array $by_hash ): array {
		$out = array();

		foreach ( $by_hash as $cipher ) {
			$plain = $this->decrypt( $cipher );

			if ( is_string( $plain ) && '' !== $plain ) {
				$out[] = $plain;
			}
		}

		return $out;
	}

	/**
	 * Read one stored identifier.
	 *
	 * **The only place in this class that touches the key**, and its own
	 * method for that reason rather than for the test's: everything around it
	 * compares categories, and keeping the one call that can produce a
	 * plaintext at a named boundary is what makes "emits a verdict, never a
	 * value" (#1345) checkable by reading instead of by trusting.
	 *
	 * `Encryption::decrypt()` answers null on a malformed envelope, an HMAC
	 * mismatch or a key that no longer opens the row, and reports the failure
	 * to the activity log itself -- so a caller that swallowed the null would
	 * hide a key-rotation break. {@see self::shape()} does not swallow it: it
	 * counts what came back against what the finding expected and says
	 * `not_decryptable`.
	 *
	 * @param string $cipher Stored ciphertext.
	 * @return string|null Plaintext, or null when it cannot be read.
	 */
	protected function decrypt( string $cipher ): ?string {
		return class_exists( Encryption::class ) ? Encryption::decrypt( $cipher ) : null;
	}

	/**
	 * How an account's identifiers differ, as one verdict for the set.
	 *
	 * **The set verdict is the WORST pair, never the best.** The question an
	 * operator asks is "can I treat these as one person's typos?", and one
	 * unrelated value in the set answers no however many near-misses sit
	 * beside it. Reporting the closest relation would read as reassurance
	 * about a set that contains something the classifier could not explain --
	 * the same reason `unknown` was never folded into `distinct_emails`.
	 * `438` is the case in hand: eleven RFs, which no typo story covers.
	 *
	 * @param array<int, string> $plaintexts Identifiers as stored, one per hash.
	 * @param string             $field_key  `cpf` or `rf`, for the canonical form.
	 * @param int                $expected   How many identifiers the finding counted.
	 * @return string One of the `SHAPE_*` constants, or '' when not applicable.
	 */
	private static function shape( array $plaintexts, string $field_key, int $expected ): string {
		if ( $expected < 2 ) {
			return '';
		}

		if ( count( $plaintexts ) < $expected ) {
			return IdentityConflictQuery::SHAPE_NOT_DECRYPTABLE;
		}

		// Re-indexed so the pair walk below indexes a list rather than
		// whatever keys the caller happened to hand over.
		$values = array_values( $plaintexts );
		$worst  = null;
		$count  = count( $values );

		for ( $i = 0; $i < $count; $i++ ) {
			for ( $j = $i + 1; $j < $count; $j++ ) {
				$relation = self::relation( $values[ $i ], $values[ $j ], $field_key );

				if ( null === $worst || self::SHAPE_SEVERITY[ $relation ] > self::SHAPE_SEVERITY[ $worst ] ) {
					$worst = $relation;
				}
			}
		}

		return $worst ?? '';
	}

	/**
	 * How one pair of identifiers differs.
	 *
	 * Returns a CATEGORY and never a value, a length or a position: a position
	 * plus a distance would narrow the unseen half of the pair to a handful of
	 * candidates, which is the leak this whole pass exists to avoid (#1345).
	 *
	 * Order matters. The leading-zero test runs BEFORE the single-deletion
	 * test, because `0123456` against `123456` satisfies both and only one of
	 * the two names a rule the canonicaliser is missing.
	 *
	 * @param string $a         One identifier as stored.
	 * @param string $b         The other.
	 * @param string $field_key `cpf` or `rf`.
	 * @return string One of the `SHAPE_*` constants.
	 */
	private static function relation( string $a, string $b, string $field_key ): string {
		$left  = class_exists( SensitiveFieldRegistry::class ) ? SensitiveFieldRegistry::normalize( $field_key, $a ) : $a;
		$right = class_exists( SensitiveFieldRegistry::class ) ? SensitiveFieldRegistry::normalize( $field_key, $b ) : $b;

		if ( $left === $right ) {
			return IdentityConflictQuery::SHAPE_SAME_AFTER_CANONICALISATION;
		}

		$bare_left  = ltrim( $left, '0' );
		$bare_right = ltrim( $right, '0' );

		if ( '' !== $bare_left && $bare_left === $bare_right ) {
			return IdentityConflictQuery::SHAPE_LEADING_ZEROS;
		}

		if ( strlen( $left ) === strlen( $right ) ) {
			$positions = array();

			for ( $i = 0, $len = strlen( $left ); $i < $len; $i++ ) {
				if ( $left[ $i ] !== $right[ $i ] ) {
					$positions[] = $i;
				}
			}

			if ( 1 === count( $positions ) ) {
				return IdentityConflictQuery::SHAPE_SINGLE_DIGIT_EDIT;
			}

			if (
				2 === count( $positions )
				&& 1 === $positions[1] - $positions[0]
				&& $left[ $positions[0] ] === $right[ $positions[1] ]
				&& $left[ $positions[1] ] === $right[ $positions[0] ]
			) {
				return IdentityConflictQuery::SHAPE_TRANSPOSITION;
			}

			return IdentityConflictQuery::SHAPE_UNRELATED;
		}

		if ( 1 === abs( strlen( $left ) - strlen( $right ) ) ) {
			$longer  = strlen( $left ) > strlen( $right ) ? $left : $right;
			$shorter = strlen( $left ) > strlen( $right ) ? $right : $left;

			if ( self::is_one_deletion( $longer, $shorter ) ) {
				return IdentityConflictQuery::SHAPE_SINGLE_DIGIT_EDIT;
			}
		}

		return IdentityConflictQuery::SHAPE_UNRELATED;
	}

	/**
	 * Whether removing one character from `$longer` yields `$shorter`.
	 *
	 * @param string $longer  The longer string.
	 * @param string $shorter The shorter one, exactly one character less.
	 * @return bool
	 */
	private static function is_one_deletion( string $longer, string $shorter ): bool {
		for ( $i = 0, $len = strlen( $longer ); $i < $len; $i++ ) {
			if ( substr( $longer, 0, $i ) . substr( $longer, $i + 1 ) === $shorter ) {
				return true;
			}
		}

		return false;
	}
}
