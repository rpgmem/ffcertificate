<?php
/**
 * IdentityCheckDigitScan
 *
 * Judges stored identifiers against their own check digits (#1345, #1486).
 *
 * The only identity check that is not about an account: it reads each stored
 * value on its own, so it sees a person who mistyped once on their only row.
 * It was the largest block of `IdentityConflictQuery` and shared nothing with
 * the grouped account checks but the store probes, which now live in
 * `IdentityStores` (#1925).
 *
 * STATEFUL ON PURPOSE: `scan_coverage()` describes the last scan THIS object
 * ran, so a caller reading it must hold the instance that scanned.
 *
 * @package FreeFormCertificate\Maintenance
 * @since 6.41.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Maintenance;

use FreeFormCertificate\Core\Encryption;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The check-digit scan and per-hash verdicts.
 *
 * @phpstan-import-type ScanCoverage from IdentityConflictQuery
 */
class IdentityCheckDigitScan {

	/**
	 * The one entry of {@see IdentityStores::COLUMNS} the check-digit scan reads.
	 *
	 * Named rather than indexed off that list, which is ordered for the
	 * checks that walk BOTH columns: `IdentityStores::COLUMNS[1]` would be a claim
	 * about an order nothing asserts.
	 *
	 * @since 6.28.2
	 * @var string
	 */
	private const COLUMN_RF_HASH = 'rf_hash';

	/**
	 * How many distinct identifiers ONE scan will decrypt.
	 *
	 * Bounded by DISTINCT values rather than by rows, because
	 * `Encryption::encrypt()` uses a random IV and one hash answers for every
	 * row sharing it -- so a store of tens of thousands of rows presents a few
	 * thousand values, and the cost this bounds is a decryption per value.
	 *
	 * It is ONE cap applied PER COLUMN, not a budget split between them, since
	 * the two columns are scanned in separate statements.
	 *
	 * IT IS A REAL CEILING, NOT A FORMALITY, and the earlier wording here
	 * called the number `generous` on the strength of the RF population alone.
	 * #1486 measured both columns: the larger sits at roughly two thirds of
	 * this cap, so it is CPF that decides when truncation begins and it does
	 * not need the install to double. The figures live in that issue rather
	 * than here, because a count of enrolments moves with every intake and
	 * nothing in this file would notice -- what is worth stating is that the
	 * headroom is one column's growth, not an order of magnitude.
	 *
	 * RAISING IT WAITS FOR THE TRUNCATION ROW, not for this arithmetic. That
	 * signal fires on a real install, and that is the trigger the
	 * project's own parked-decision criterion asks for; a number chosen against
	 * a forecast is a guess whatever it costs. The cost is known to be small --
	 * the #1486 census read every distinct value of both columns in about half
	 * a second -- so when the trigger comes the decision is cheap. It is simply
	 * not due yet.
	 *
	 * WHAT A TRUNCATED SCAN READ IS AN ARBITRARY SLICE. The `LIMIT` rides an
	 * `ORDER BY` on the HASH, so the values it keeps are neither the oldest nor
	 * the newest -- there is no reading of the report under which the unread
	 * remainder is `the recent ones`. {@see IdentityConflictQuery::COLUMN_SCAN_TRUNCATED} is
	 * what says the reading was partial; raising this number is what makes it
	 * whole again.
	 *
	 * @since 6.28.2
	 * @var int
	 */
	private const CHECK_DIGIT_SCAN_LIMIT = 20000;

	/**
	 * Separator between row ids of one store.
	 *
	 * Deliberately NOT {@see IdentityConflictQuery::RELATED_SEPARATOR}: that one separates the
	 * per-store chunks, and reusing it would make `submissions:1,2` and two
	 * stores indistinguishable once joined.
	 *
	 * @since 6.28.2
	 * @var string
	 */
	private const ROW_ID_SEPARATOR = ',';

	/**
	 * Separator between a store's name and its row ids.
	 *
	 * @since 6.28.2
	 * @var string
	 */
	private const STORE_ID_SEPARATOR = ':';

	/**
	 * The columns a check-digit scan walks, in the order it walks them.
	 *
	 * RF FIRST, WHICH IS NOT COSMETIC. Where the joined list is trimmed to the
	 * caller's limit the survivors are its head, and RF is the population that
	 * has been measured and worked; putting it behind a CPF population of
	 * unknown size could push known work off a screen an operator is mid-way
	 * through.
	 *
	 * STATED HERE rather than read off `IdentityStores::COLUMNS`, whose own comment says
	 * in as many words that indexing it is "a claim about an order nothing
	 * asserts": it is ordered `cpf_hash, rf_hash` for the checks that walk
	 * both, a different purpose that would silently invert this decision.
	 *
	 * @since 6.31.0
	 * @var list<string>
	 */
	private const CHECK_DIGIT_COLUMNS = array( 'rf_hash', 'cpf_hash' );

	/**
	 * Every check-digit failure, from both identifier columns (#1486).
	 *
	 * THE COLUMNS ARE SCANNED SEPARATELY AND THE FINDINGS ARE ONE LIST.
	 *
	 * Separately because each needs its own decryption pass, its own store
	 * probe and its own coverage record; one list because they are one panel
	 * and one operator task, under a heading -- "Numbers to correct" -- that was
	 * already field-neutral.
	 *
	 * ASKING EACH COLUMN FOR THE FULL `$limit` IS DELIBERATE, AND SO IS THE
	 * TRIM. Halving it per column would silently shrink what an operator sees
	 * of RF to make room for a population nobody has counted yet, and a
	 * caller's limit is a statement about the LIST, not about a column. So each
	 * column is asked for the whole of it and the joined list is cut back --
	 * which makes the list reach a caller's cap sooner, and a caller that
	 * reports its caps then says so, rather than the shortfall going unsaid.
	 *
	 * IT LIVES HERE BECAUSE TWO CALLERS NEEDED IT. `IdentityQueue` and
	 * `SubmissionLinkAuditor` both scan for these findings, so the order and
	 * the trim would otherwise be decided twice and could disagree -- and the
	 * per-column coverage this leaves behind is read from this same object.
	 *
	 * @since 6.31.0
	 * @param int $limit How many findings the caller wants.
	 * @return array<int, array<string, mixed>>
	 */
	public function check_digit_failures_of_both( int $limit = 50 ): array {
		$out = array();

		foreach ( self::CHECK_DIGIT_COLUMNS as $column ) {
			foreach ( $this->check_digit_failures( $column, $limit ) as $row ) {
				$out[] = $row;
			}
		}

		return array_slice( $out, 0, max( 1, $limit ) );
	}

	/**
	 * Stored identifiers whose check digit disagrees with the rest of them.
	 *
	 * THE ONLY CHECK HERE THAT IS NOT ABOUT AN ACCOUNT
	 *
	 * Every other check groups rows by a person and reports an account that
	 * has accumulated two identifiers. This one asks something narrower and
	 * wider at once: is each stored value internally consistent? Narrower,
	 * because it judges one value on its own; wider, because a person who
	 * mistyped **once, on their only submission** is invisible to every other
	 * check by construction and visible to this one. Measured on production the
	 * difference is 16 ambiguous RF findings against roughly 79 wrong values
	 * (#1345); the CPF side of that comparison has never been measured, which
	 * is the first thing #1486 asks for.
	 *
	 * IT DELIBERATELY DOES NOT FILTER ON `user_id`, AND THAT IS THE POINT
	 *
	 * {@see IdentityConflictQuery::pairs()} carries `user_id IS NOT NULL AND user_id <> 0`,
	 * correctly, because the checks built on it ask about accounts. Here that
	 * filter would drop exactly the population most likely to be wrong: a
	 * recruitment candidacy is not linked to a WP user until promotion, and
	 * that store holds thousands of rows against the appointment store's one.
	 * So the scan reads unlinked rows too, and a finding with no account is
	 * ordinary rather than a defect -- which is why it carries row ids.
	 *
	 * WHY THIS CANNOT BE SQL
	 *
	 * Both identifiers are encrypted at rest, so the check digit is unreadable
	 * to the database. Each distinct value is decrypted through {@see self::decrypt()},
	 * the same seam the shape verdict uses, and nothing but a verdict leaves
	 * this method. Cost is bounded by DISTINCT hashes rather than by rows:
	 * `Encryption::encrypt()` uses a random IV, so a value submitted four
	 * hundred times has four hundred ciphertexts and one hash, and one
	 * decryption answers for all of them.
	 *
	 * IT SCANS THE COLUMN IT IS ASKED FOR, AND CPF IS NOT A SPECIAL CASE (#1486)
	 *
	 * This opened with `$column` fixed to `rf_hash`, which made a stored CPF
	 * that fails its own check digit INVISIBLE -- while every other tier of
	 * the screen already branched on the field, and `well_formed()` had always
	 * taken one. The generalisation is a parameter rather than a second method
	 * because nothing else here was RF-specific: `ciphertext_column_of()` and
	 * `field_key_of()` were already generic, and `IdentityStores::COLUMNS` is the
	 * authority on which columns exist.
	 *
	 * The COLUMN is the parameter, not the field, because `identifier_column`
	 * is the vocabulary these findings already speak -- every row carries one,
	 * and the screen labels itself from it.
	 *
	 * @since 6.28.2
	 * @param string $column Identifier column to scan; one of `IdentityStores::COLUMNS`.
	 * @param int    $limit  Sample size.
	 * @return array<int, array<string, mixed>>
	 */
	public function check_digit_failures( string $column = self::COLUMN_RF_HASH, int $limit = 50 ): array {
		global $wpdb;

		$limit  = max( 1, $limit );
		$cipher = in_array( $column, IdentityStores::COLUMNS, true ) ? IdentityStores::ciphertext_column_of( $column ) : '';
		$stores = '' === $cipher ? array() : $this->stores_for_check_digit_scan( $cipher );
		$out    = array();

		// Reset first, and PER COLUMN: a caller reading coverage after a scan
		// that bailed must see this call's zero, never the previous call's
		// numbers -- and never the OTHER column's, which is why this is keyed
		// rather than replaced. A healthy RF scan summed with a CPF scan that
		// read nothing reports that everything was read.
		$this->scan_coverage[ $column ] = array(
			'stores'     => count( $stores ),
			'examined'   => 0,
			'unreadable' => 0,
		);

		if ( array() === $stores ) {
			return $out;
		}

		$parts  = array();
		$values = array();

		foreach ( $stores as $table ) {
			$parts[] = "SELECT %i AS h, MIN(%i) AS c, %s AS src, COUNT(*) AS n,
                               GROUP_CONCAT(DISTINCT user_id ORDER BY user_id SEPARATOR '" . IdentityConflictQuery::RELATED_SEPARATOR . "') AS users,
                               GROUP_CONCAT(id ORDER BY id SEPARATOR '" . self::ROW_ID_SEPARATOR . "') AS ids
                          FROM %i
                         WHERE %i IS NOT NULL AND %i <> '' AND %i IS NOT NULL AND %i <> ''
                      GROUP BY %i";

			$values[] = $column;
			$values[] = $cipher;
			$values[] = IdentityStores::label( $table );
			$values[] = $table;
			$values[] = $column;
			$values[] = $column;
			$values[] = $cipher;
			$values[] = $cipher;
			$values[] = $column;
		}

		$union = implode( ' UNION ALL ', $parts );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The union is this method's own literal, repeated once per table it resolved by probing the live schema; every identifier travels as a placeholder and none of it is request data. An audit read must reflect the live rows, never a cache.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT p.h AS subject, MIN(p.c) AS c, SUM(p.n) AS ' . IdentityConflictQuery::ALIAS_ROW_COUNT . ",
                        GROUP_CONCAT(DISTINCT p.src ORDER BY p.src SEPARATOR '" . IdentityConflictQuery::RELATED_SEPARATOR . "') AS stores,
                        GROUP_CONCAT(p.users SEPARATOR '" . IdentityConflictQuery::RELATED_SEPARATOR . "') AS related,
                        GROUP_CONCAT(CONCAT(p.src, '" . self::STORE_ID_SEPARATOR . "', p.ids) ORDER BY p.src SEPARATOR '" . IdentityConflictQuery::RELATED_SEPARATOR . "') AS " . IdentityConflictQuery::COLUMN_ROW_IDS . ",
                        %s AS identifier_column
                   FROM ({$union}) AS p
               GROUP BY p.h
               ORDER BY p.h ASC
                  LIMIT %d",
				// IN PLACEHOLDER ORDER, NEVER IN COMPOSITION ORDER
				//
				// `prepare()` substitutes in the order the placeholders appear
				// in the STRING, so `$column` comes first: its `%s` is written
				// above `FROM ({$union})` even though the union was composed
				// first. Appending it after the union's values instead shifted
				// every placeholder by one, `MIN(%i)` took the store's label as
				// an identifier, and the server rejected the whole statement --
				// which an audit read cannot see, because a rejected query and
				// a clean install both answer with no rows (#1384).
				...array_merge( array( $column ), $values, array( self::CHECK_DIGIT_SCAN_LIMIT ) )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$rows       = (array) $rows;
		$scanned    = count( $rows );
		$unreadable = 0;

		foreach ( $rows as $row ) {
			$row    = (array) $row;
			$stored = isset( $row['c'] ) && is_string( $row['c'] ) ? $row['c'] : '';
			$plain  = '' === $stored ? null : $this->decrypt( $stored );

			// A value that cannot be read is NOT a failure. The mirror of
			// `SHAPE_NOT_DECRYPTABLE`, and for its reason: not having read a
			// value is not evidence that it is wrong, and this finding's
			// action is to contact a person about their own number.
			if ( ! is_string( $plain ) ) {
				++$unreadable;
				continue;
			}

			// THE SAME RULE, ASKED IN THE SAME PLACE AS EVERYWHERE ELSE (#1491).
			//
			// This called `DocumentFormatter::rf_check_digit_matches()` directly,
			// which made it a THIRD site expressing the rule -- found while
			// unifying the other two. It now takes the field the scan resolved,
			// which is what #1486 said it would: `well_formed()` had always
			// branched on it, so making the scan field-aware needed nothing
			// here but passing the answer through.
			if ( IdentityRepair::well_formed( IdentityStores::field_key_of( $column ), $plain ) ) {
				continue;
			}

			unset( $row['c'] );

			$row[ IdentityConflictQuery::COLUMN_RELATED ] = self::unique_related( $row[ IdentityConflictQuery::COLUMN_RELATED ] ?? '' );

			$out[] = self::flag_truncated_row_ids( $row );

			if ( count( $out ) >= $limit ) {
				break;
			}
		}

		// ONE WRITE, NOT AN INCREMENT PER ROW.
		//
		// Counted into locals and assigned once, which reads better and is also
		// the only form that keeps the declared shape: `++$this->x[ $c ]['k']`
		// widens the inner array to `non-empty-array<'examined'|'stores'|
		// 'unreadable', int>` as far as PHPStan is concerned, so level 8 refuses
		// the assignment against the exact shape the property declares.
		$this->scan_coverage[ $column ] = array(
			'stores'     => count( $stores ),
			'examined'   => $scanned,
			'unreadable' => $unreadable,
		);

		// A ROW OF ITS OWN, NEVER A COLUMN ON THE FINDINGS
		//
		// The obvious shape is a flag beside each failure, and it has a hole
		// that only appeared when a test tried to reach it: a scan that stops
		// at its cap having found nothing emits no findings, so a flag riding
		// on them says nothing and the report reads CLEAN while values went
		// unexamined. That is the exact failure `#1295` argues against. As its
		// own row the signal does not depend on a failure existing, and it is
		// counted as a finding because it is one -- there is something for an
		// operator to do about it.
		if ( $scanned >= self::CHECK_DIGIT_SCAN_LIMIT ) {
			$out[] = array(
				'identifier_column'                  => $column,
				IdentityConflictQuery::COLUMN_STORES => implode( IdentityConflictQuery::RELATED_SEPARATOR, array_map( array( IdentityStores::class, 'label' ), $stores ) ),
				IdentityConflictQuery::COLUMN_SCAN_TRUNCATED => true,
			);
		}

		return $out;
	}

	/**
	 * What the last {@see self::check_digit_failures()} call actually read.
	 *
	 * A SCAN THAT READ NOTHING MUST NOT RENDER AS CLEAN.
	 *
	 * That method returns failures, so it returns none in three unrelated
	 * states: no store carries the three columns it needs, nothing it read
	 * could be decrypted, and every value genuinely satisfies its digit. Only
	 * the third justifies telling an operator the data is fine -- and the
	 * first two are the ordinary state of a staging site whose encryption key
	 * does not match the data it holds.
	 *
	 * Kept beside the scan rather than returned from it, because the auditor
	 * counts every returned row as a FINDING: a coverage row would read as one
	 * permanent open item on an install with nothing wrong. The truncation
	 * signal IS a row for the opposite reason -- there is something to do
	 * about it.
	 *
	 * KEYED BY COLUMN, AND SUMMING THESE WOULD DEFEAT THE RULE ABOVE (#1486).
	 *
	 * Two columns are scanned now, and one number per counter cannot describe
	 * both. Worked through: an RF scan reading 5,000 values cleanly summed with
	 * a CPF scan that read NOTHING gives `stores > 0`, `examined = 5000`,
	 * `unreadable = 0` -- which the screen renders as "The scan read the data".
	 * The healthy half would vouch for the half that never ran, in the one
	 * place built to stop exactly that.
	 *
	 * @since 6.28.2
	 * @var ScanCoverage
	 */
	private array $scan_coverage = array();

	/**
	 * What each check-digit scan read, keyed by the column it scanned.
	 *
	 * `examined` counts DISTINCT hashes, not rows -- the scan groups before
	 * decrypting, which is what bounds its cost. `unreadable` is how many of
	 * those could not be decrypted; equal to `examined` means the key does not
	 * open this data, and an empty failure list says nothing at all.
	 *
	 * A column that was never scanned is ABSENT rather than zeroed, because a
	 * zeroed record is indistinguishable from a scan that found no store --
	 * and the two mean different things to a reader deciding whether the
	 * screen has an opinion about CPF at all.
	 *
	 * @since 6.28.2
	 * @return ScanCoverage
	 */
	public function scan_coverage(): array {
		return $this->scan_coverage;
	}

	/**
	 * Judge each of these identifiers, one verdict per hash.
	 *
	 * WHY THE FAILURE LIST CANNOT TIER A FINDING
	 *
	 * {@see self::check_digit_failures()} returns FAILURES. Asking it
	 * whether an account's other identifier is fine means reading absence from
	 * its list as a pass -- and absence there also means the value was never
	 * examined, because the scan is capped, because no store carries it with a
	 * ciphertext, or because nothing decrypted. That is the #1071 / #1094 rule
	 * exactly: an empty result must never read as clean.
	 *
	 * So this answers per hash, and EVERY hash asked about gets an entry.
	 * `absent` and `unreadable` are verdicts with names rather than gaps in a
	 * map, which is what lets a caller tier a finding honestly: one identifier
	 * valid and one invalid is mechanical; anything else is a decision.
	 *
	 * Generic over the identifier from the start, because the CPF check is the
	 * same question under a different rule -- `validate_cpf()` verifies two
	 * check digits where `rf_check_digit_matches()` verifies one.
	 *
	 * @since 6.28.3
	 * @param string             $column `cpf_hash` or `rf_hash`.
	 * @param array<int, string> $hashes The hashes to judge.
	 * @return array<string, string> Hash => one of the `VERDICT_*` constants.
	 */
	public function check_digit_verdicts( string $column, array $hashes ): array {
		global $wpdb;

		$wanted = array();

		foreach ( $hashes as $hash ) {
			$hash = trim( (string) $hash );

			if ( '' !== $hash && ! in_array( $hash, $wanted, true ) ) {
				$wanted[] = $hash;
			}
		}

		if ( array() === $wanted || ! in_array( $column, IdentityStores::COLUMNS, true ) ) {
			return array();
		}

		// Absent until a store answers otherwise -- never a missing key.
		$out = array_fill_keys( $wanted, IdentityConflictQuery::VERDICT_ABSENT );

		$cipher = IdentityStores::ciphertext_column_of( $column );
		$stores = $this->stores_for_check_digit_scan( $cipher );

		if ( array() === $stores || '' === $cipher ) {
			return $out;
		}

		$field  = IdentityStores::field_key_of( $column );
		$blanks = implode( ',', array_fill( 0, count( $wanted ), '%s' ) );

		foreach ( $stores as $table ) {
			// IN PLACEHOLDER ORDER, NEVER IN COMPOSITION ORDER (#1384).
			$values = array_merge(
				array( $column, $cipher, $table, $column ),
				$wanted,
				array( $column )
			);

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The only interpolation is this method's own run of `%s` placeholders, one per hash; every identifier and every value travels as a placeholder. An audit read must reflect the live rows.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT %i AS h, MIN(%i) AS c
                       FROM %i
                      WHERE %i IN ({$blanks})
                   GROUP BY %i",
					...$values
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			foreach ( (array) $rows as $row ) {
				$row  = (array) $row;
				$hash = isset( $row['h'] ) && is_string( $row['h'] ) ? $row['h'] : '';

				if ( '' === $hash || ! isset( $out[ $hash ] ) ) {
					continue;
				}

				// A store that already answered VALID is not re-judged: the
				// value is one value, and a second store holds the same hash
				// with its own ciphertext of it.
				if ( IdentityConflictQuery::VERDICT_VALID === $out[ $hash ] || IdentityConflictQuery::VERDICT_INVALID === $out[ $hash ] ) {
					continue;
				}

				$stored = isset( $row['c'] ) && is_string( $row['c'] ) ? $row['c'] : '';
				$plain  = '' === $stored ? null : $this->decrypt( $stored );

				if ( ! is_string( $plain ) ) {
					$out[ $hash ] = IdentityConflictQuery::VERDICT_UNREADABLE;
					continue;
				}

				$out[ $hash ] = self::satisfies_check_digits( $field, $plain )
					? IdentityConflictQuery::VERDICT_VALID
					: IdentityConflictQuery::VERDICT_INVALID;
			}
		}

		return $out;
	}

	/**
	 * Whether a plaintext identifier satisfies its own check digits.
	 *
	 * ONE IMPLEMENTATION, BECAUSE TWO HAD ALREADY DRIFTED (#1491).
	 *
	 * This branched on the field itself, with a docblock giving the same reason
	 * `IdentityRepair::well_formed()` gives in nearly the same words -- and the
	 * two bodies did not match: that one also called `validate_rf()`, which
	 * reads the `ffc_validate_rf_check_digit` setting. Two implementations of
	 * one rule, agreeing in prose and differing in their DEPENDENCIES, which is
	 * the sharper half of the cost.
	 *
	 * So the decision lives in one place and this asks it. `well_formed()` is
	 * already that place -- it is `public` and its own docblock declares itself
	 * the shared home, with `IdentityAdoption` as the second consumer.
	 *
	 * DEPENDING ON THE WRITE-SIDE CLASS IS NOT A LAYERING SLIP. `well_formed()`
	 * is a pure static predicate with no I/O and no state, so this is a reader
	 * borrowing an expression rather than reaching into a writer. Both classes
	 * sit in `Maintenance`, so the module-boundary baseline does not move.
	 *
	 * @since 6.28.3
	 * @since 6.31.0 Delegates rather than branching a second time.
	 * @param string $field `cpf` or `rf`.
	 * @param string $plain The identifier as stored.
	 * @return bool
	 */
	private static function satisfies_check_digits( string $field, string $plain ): bool {
		return IdentityRepair::well_formed( $field, $plain );
	}

	/**
	 * The stores this scan can read the asked-for identifier out of.
	 *
	 * Three columns are required and each exclusion is structural rather than
	 * incidental. The hash column is what groups the value; the ciphertext
	 * column is the only way to see the digits at all, which is why
	 * `ffc_user_profiles` --
	 * the identity index, hashes and nothing else -- can never be scanned;
	 * and `id` is what a finding without an account has instead of one.
	 *
	 * Probed, never listed, for the reason {@see IdentityStores::all()} probes: a
	 * list would be a claim about a schema this class does not own.
	 *
	 * @since 6.28.2
	 * @param string $cipher_column Ciphertext column to require.
	 * @return list<string>
	 */
	private function stores_for_check_digit_scan( string $cipher_column ): array {
		global $wpdb;

		$out = array();

		foreach ( IdentityStores::with_ciphertext( $cipher_column ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema probe, as in `IdentityStores::with_ciphertext()`: the answer must reflect the live schema, so a cached one would be precisely wrong.
			if ( 'id' === $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'id' ) ) ) {
				$out[] = $table;
			}
		}

		return $out;
	}

	/**
	 * Collapse the per-store account lists into one, without repeats.
	 *
	 * The outer `GROUP_CONCAT` joins each store's own list, so a person whose
	 * account carries the value in two stores is named twice. `DISTINCT` cannot
	 * do it there -- it would deduplicate the LISTS, not the ids inside them.
	 *
	 * @since 6.28.2
	 * @param mixed $related Joined account ids, as the statement returned them.
	 * @return string
	 */
	private static function unique_related( $related ): string {
		if ( ! is_string( $related ) || '' === $related ) {
			return '';
		}

		$ids = array_filter(
			array_unique( explode( IdentityConflictQuery::RELATED_SEPARATOR, $related ) ),
			static fn( $id ) => '' !== $id
		);

		sort( $ids, SORT_NUMERIC );

		return implode( IdentityConflictQuery::RELATED_SEPARATOR, $ids );
	}

	/**
	 * Mark a finding whose row-id list is shorter than its own row count.
	 *
	 * `GROUP_CONCAT` truncates at `group_concat_max_len` in silence while
	 * `COUNT(*)` does not, so their disagreement is the only signal that
	 * exists -- the same reasoning as {@see IdentityConflictQuery::flag_truncated_list()}, over
	 * a different pair of columns. A partial list of rows to go and fix reads
	 * exactly like a complete one otherwise.
	 *
	 * @since 6.28.2
	 * @param array<string, mixed> $row One finding.
	 * @return array<string, mixed>
	 */
	private static function flag_truncated_row_ids( array $row ): array {
		$joined = $row[ IdentityConflictQuery::COLUMN_ROW_IDS ] ?? '';
		$listed = 0;

		if ( is_string( $joined ) && '' !== $joined ) {
			foreach ( explode( IdentityConflictQuery::RELATED_SEPARATOR, $joined ) as $chunk ) {
				$ids     = strstr( $chunk, self::STORE_ID_SEPARATOR );
				$listed += ( false === $ids ) ? 0 : count( explode( self::ROW_ID_SEPARATOR, substr( $ids, 1 ) ) );
			}
		}

		$total   = $row[ IdentityConflictQuery::ALIAS_ROW_COUNT ] ?? 0;
		$counted = is_numeric( $total ) ? (int) $total : 0;

		$row[ IdentityConflictQuery::COLUMN_ROW_IDS_TRUNCATED ] = ( $listed !== $counted );

		return $row;
	}

	/**
	 * Unpack {@see IdentityConflictQuery::COLUMN_ROW_IDS} into store label => list of row ids.
	 *
	 * The inverse of what `check_digit_failures()` packs, and it lives
	 * beside that method for the reason: the class that writes a wire format
	 * is the one that can still read it after a separator changes. Both
	 * separators it needs are private, which is the mechanical half of the
	 * same argument.
	 *
	 * The format is `label:1,2,3|label:4`. A store LABEL contains neither
	 * separator -- {@see IdentityStores::label()} maps a table name to a bare word --
	 * which is what keeps it unambiguous.
	 *
	 * A value this cannot read yields an empty array rather than a partial
	 * one: these ids are what an operator opens, so half a list is worse
	 * than none.
	 *
	 * @since 6.28.2
	 * @param mixed $raw Packed value as a finding carries it.
	 * @return array<string, list<int>>
	 */
	public static function parse_row_ids( $raw ): array {
		$out = array();

		if ( ! is_string( $raw ) || '' === $raw ) {
			return $out;
		}

		foreach ( explode( IdentityConflictQuery::RELATED_SEPARATOR, $raw ) as $chunk ) {
			$at = strpos( $chunk, self::STORE_ID_SEPARATOR );

			if ( false === $at ) {
				continue;
			}

			$store = substr( $chunk, 0, $at );
			$ids   = array();

			foreach ( explode( self::ROW_ID_SEPARATOR, substr( $chunk, $at + 1 ) ) as $id ) {
				$id = trim( $id );

				if ( '' !== $id && ctype_digit( $id ) ) {
					$ids[] = (int) $id;
				}
			}

			if ( '' === $store || array() === $ids ) {
				continue;
			}

			$out[ $store ] = isset( $out[ $store ] ) ? array_merge( $out[ $store ], $ids ) : $ids;
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
	 * hide a key-rotation break. The scan does not swallow it: a null is
	 * counted as `unreadable` in the coverage record, never as a pass.
	 *
	 * The same seam `IdentityFindingVerdicts` keeps, and kept twice on
	 * purpose (#1925): each class's tests drive it without a key, and a shared
	 * static could not be overridden.
	 *
	 * @param string $cipher Stored ciphertext.
	 * @return string|null Plaintext, or null when it cannot be read.
	 */
	protected function decrypt( string $cipher ): ?string {
		return class_exists( Encryption::class ) ? Encryption::decrypt( $cipher ) : null;
	}
}
