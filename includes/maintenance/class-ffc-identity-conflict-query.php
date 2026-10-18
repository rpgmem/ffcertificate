<?php
/**
 * IdentityConflictQuery
 *
 * The questions about identity that belong to no single module (#1313 PR 10).
 *
 * WHY THIS IS NOT ON A READER
 *
 * `SubmissionReader` answers them for `ffc_submissions`, and that is right:
 * they are questions about its own rows. But "does one person hold two
 * accounts" is not a submissions question, an appointments question or a
 * recruitment question -- it is only visible ACROSS them, and putting it on any
 * one reader would make that reader know about the other two. So it lives with
 * the auditor that reports it.
 *
 * WHY THE AUDITOR IS WHERE THE NUMBER COMES FROM
 *
 * #1313's backfill fills an empty index column and deliberately never picks
 * between two different hashes, because that is a conflict to count before
 * anyone designs a merge policy -- and it keeps no counter of its own, since a
 * parallel counter beside an existing signal is the indirection the
 * `cpf_rf_encrypted` precedent rejects. This is that existing signal, widened.
 *
 * EVERY QUERY HERE IS READ-ONLY, AND THAT IS STRUCTURAL
 *
 * The tool that uses it declares `is_actionable() === false`. Re-linking
 * identity records automatically is the decision nobody has made yet; the
 * report exists so a person can make it.
 *
 * WHAT MOVED OUT, AND WHY IT IS NOT BEHIND A FACADE (#1925)
 *
 * This class held the check-digit scan, the per-account facts and the
 * verdict annotation too, and grew to the largest file in `includes/`. They
 * share nothing but the store probes, so each is its own class --
 * `IdentityCheckDigitScan`, `IdentityAccountFacts`, `IdentityFindingVerdicts`,
 * with the probes in `IdentityStores` -- and callers ask them directly. What
 * stays is the grouped account checks and the vocabulary of a finding: every
 * column, alias and verdict constant a finding row carries is declared here,
 * whichever class writes it, so a consumer reading a finding names one class.
 *
 * @package FreeFormCertificate\Maintenance
 * @since 6.26.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Maintenance;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only cross-store identity questions. *
 * THE COVERAGE SHAPE IS DECLARED ONCE HERE (#1486).
 *
 * It was spelled out inline at eleven sites across five files the moment the
 * record became keyed, which is the same duplication `IdentityAgreement` had to
 * undo in #1491 -- and PHPStan can only check a consumer against the shape that
 * consumer declares, so eleven copies are eleven chances for one to drift.
 *
 * @phpstan-type ScanCoverage array<string, array{stores: int, examined: int, unreadable: int}>
 */
class IdentityConflictQuery {

	/**
	 * The count column `shared_identities()` returns.
	 *
	 * A CONSTANT rather than a literal because a consumer has to read it by
	 * name, and a consumer reading a name this class does not emit gets an
	 * empty column with nothing to say it is empty. That shipped: the export
	 * looked for `identifier_count` while this class emitted `identity_count`,
	 * and the test that should have caught it supplied the wrong name too --
	 * asserting against the value the test itself provides, which is the shape
	 * `AssertionCoverageTest` exists for and which no static checker sees.
	 *
	 * @var string
	 */
	public const ALIAS_USER_COUNT = 'user_count';

	/**
	 * The count column `multiple_identities()` returns. See above.
	 *
	 * @var string
	 */
	public const ALIAS_IDENTITY_COUNT = 'identity_count';

	/**
	 * The count column `check_digit_failures()` returns: how many stored
	 * rows carry the value, across every store.
	 *
	 * @since 6.28.2
	 * @var string
	 */
	public const ALIAS_ROW_COUNT = 'row_count';

	/**
	 * The column naming the rows to go and look at, as `store:id,id`.
	 *
	 * SELF-DESCRIBING RATHER THAN POSITIONAL, UNLIKE ITS NEIGHBOURS
	 *
	 * `account_status` and the columns beside it align positionally against
	 * `user_ids`, which works because every account has exactly one of each
	 * fact. Rows do not: one store can hold six and another one, so a
	 * positional list would need padding nobody could read. Each chunk names
	 * its own store instead.
	 *
	 * It exists because this is the one check whose finding may name NO
	 * account at all -- an unpromoted candidacy has `user_id` NULL -- and
	 * "which row" is then the only handle an operator has.
	 *
	 * @since 6.28.2
	 * @var string
	 */
	public const COLUMN_ROW_IDS = 'row_ids';

	/**
	 * Whether the row-id list above is shorter than the row count beside it.
	 *
	 * @since 6.28.2
	 * @var string
	 */
	public const COLUMN_ROW_IDS_TRUNCATED = 'row_ids_truncated';

	/**
	 * Whether the scan itself stopped at its cap rather than at the data.
	 *
	 * A cap that is reached must be REPORTED or a partial list reads as a
	 * complete one -- the rule the identity audit's synchronous export was
	 * argued from (#1295). Distinct from `truncated` on the check, which says
	 * the FINDINGS were cut: this one says values went unexamined, so a
	 * failure may exist that nothing here has looked at.
	 *
	 * It rides a row of ITS OWN rather than a column on the findings, and
	 * {@see IdentityCheckDigitScan::check_digit_failures()} records why: a capped scan that
	 * found nothing has no finding to carry a flag, and would report clean.
	 *
	 * @since 6.28.2
	 * @var string
	 */
	public const COLUMN_SCAN_TRUNCATED = 'scan_truncated';

	/**
	 * The column naming which stores an identifier was found in.
	 *
	 * @var string
	 */
	public const COLUMN_STORES = 'stores';

	/**
	 * The column carrying the values the count above only COUNTED.
	 *
	 * `grouped()` aliases whatever it grouped BY as `subject` and aggregates
	 * the other half away, so each direction used to destroy exactly what the
	 * other half needs: the shared checks said an identifier belongs to two
	 * accounts without naming them, and the multiple checks said an account
	 * holds two identifiers without naming those. Neither is a lead anybody
	 * can act on, which is the property this class's own note claims for the
	 * report (#1344).
	 *
	 * @var string
	 */
	public const COLUMN_RELATED = 'related';

	/**
	 * Set when `related` holds FEWER values than the count beside it.
	 *
	 * `GROUP_CONCAT` stops at `group_concat_max_len` -- 1024 bytes by default
	 * -- and drops the tail WITHOUT an error, so a short list reads exactly
	 * like a complete one. At 65 bytes per hash that is 15 identifiers, and
	 * production already holds an account with 11: the headroom is four, not a
	 * theoretical margin. The count is aggregated separately and is never
	 * truncated, so comparing the two costs nothing and is the only way to see
	 * it happen. Never count as clean what was not fully read.
	 *
	 * @var string
	 */
	public const COLUMN_RELATED_TRUNCATED = 'related_truncated';

	/**
	 * The column carrying what the addresses say about a multi-identifier
	 * account.
	 *
	 * A person holds one CPF and one RF, so an account carrying two of either
	 * is never history -- it is two people, one mistyped value, or one value
	 * written two ways. The first is a live exposure (the dashboard lists by
	 * `user_id`); the other two are not. Nothing in the hashes tells them
	 * apart, because a hash is one-way.
	 *
	 * The addresses do, and without a key: `email_hash` is stored beside the
	 * identifier on every store that has one. Two identifiers used from the
	 * same address are one person typing; two identifiers each with their own
	 * address are two people (#1345).
	 *
	 * @var string
	 */
	public const COLUMN_EMAIL_VERDICT = 'email_verdict';

	/**
	 * An address appears under more than one of the account's identifiers.
	 *
	 * One person, so the rows are theirs and detaching them would take their
	 * own records away. Which of the two remaining readings it is -- a typo or
	 * a spelling the canonicaliser missed -- no hash can say; that needs the
	 * values themselves.
	 *
	 * @var string
	 */
	public const VERDICT_SHARED_EMAIL = 'shared_email';

	/**
	 * Every identifier has its own address and none is shared.
	 *
	 * Two people under one account, which is the reading that exposes one
	 * person's records to the other.
	 *
	 * @var string
	 */
	public const VERDICT_DISTINCT_EMAILS = 'distinct_emails';

	/**
	 * At least one identifier carries no address anywhere.
	 *
	 * Deliberately NOT folded into `distinct_emails`: absence of an address is
	 * not evidence of a second person, and a verdict that said so would send
	 * an operator to detach rows on nothing at all. `user_profiles` is the
	 * ordinary cause -- it is the identity index and stores no address.
	 *
	 * @var string
	 */
	public const VERDICT_UNKNOWN = 'unknown';

	/**
	 * The column saying HOW an account's two identifiers differ.
	 *
	 * `email_verdict` separates reading 1 (two people) from readings 2 and 3;
	 * it cannot separate those two from each other, because that needs the
	 * values and a hash is one-way. Measured on production, **71 of the 73
	 * findings are `shared_email`** -- one person under two spellings -- so
	 * this column is what the remaining 97% turns on (#1345).
	 *
	 * WHY THE ISSUE'S OWN VERDICT IS NOT ENOUGH, AND IS KEPT ANYWAY.
	 * #1345 specifies "decrypt, re-canonicalise, emit
	 * `same_after_canonicalisation` / `different`". But the canonical form of
	 * a CPF or an RF is `preg_replace( '/[^0-9]/', '', … )` -- the digits and
	 * nothing else -- and `IdentityNormalizationMigrationStrategy` (#1313 PR 3)
	 * has already rewritten every row of all four stores this audit reads.
	 * While that card reads 0 pending, re-canonicalising a stored identifier is
	 * the identity function, so `same_after_canonicalisation` cannot occur.
	 * It is still emitted, and is exactly the value that would prove the
	 * premise wrong: a row reporting it means the card is not complete, and
	 * that is worth learning from a column rather than from a surprise.
	 *
	 * So the useful question is the one past it -- two distinct digit strings
	 * on one person: how do they differ? That separates a typo, which leaves
	 * the rows linked, from a spelling rule the canonicaliser does not have.
	 *
	 * @var string
	 */
	public const COLUMN_SHAPE_VERDICT = 'identifier_shape';

	/**
	 * The two values differ, and canonicalising them makes them one.
	 *
	 * Reading 3, and predicted never to fire -- see the column's own note.
	 * Kept because a verdict that cannot occur is the cheapest possible test
	 * of the assumption that says so.
	 *
	 * @var string
	 */
	public const SHAPE_SAME_AFTER_CANONICALISATION = 'same_after_canonicalisation';

	/**
	 * The digits agree once leading zeros are dropped.
	 *
	 * **A canonicalisation gap the current rules genuinely have.** `normalize`
	 * strips non-digits and stops, so `0123456` and `123456` hash differently;
	 * worse, `DataSanitizer::classify()` routes by `strlen() === 7`, so an RF
	 * written with a leading zero is eight characters and is filed as a CPF.
	 * One person, two columns. This is reading 3 in the shape it can actually
	 * take, and the fix is in the canonicaliser, not in the account.
	 *
	 * @var string
	 */
	public const SHAPE_LEADING_ZEROS = 'differs_by_leading_zeros';

	/**
	 * Two adjacent digits are swapped.
	 *
	 * Reading 2 -- a typo. The rows are the account holder's own.
	 *
	 * @var string
	 */
	public const SHAPE_TRANSPOSITION = 'transposition';

	/**
	 * One digit is different, dropped or added.
	 *
	 * Reading 2. This is the shape the issue predicts should dominate the RF
	 * conflicts, because `validate_rf()` checks only `strlen === 7 &&
	 * is_numeric` -- so a mistyped RF passes validation almost always, while a
	 * mistyped CPF is rejected by its check digits ~99 times in 100.
	 *
	 * @var string
	 */
	public const SHAPE_SINGLE_DIGIT_EDIT = 'single_digit_edit';

	/**
	 * The values are not near-misses of each other.
	 *
	 * With `shared_email` beside it this is the shared-institutional-mailbox
	 * case: the premise *an address identifies a person* does not hold, and
	 * the account needs reading by hand. It is also what an account of more
	 * than two identifiers usually reports -- see {@see IdentityFindingVerdicts::shape()} on why
	 * the set verdict is the WORST pair and not the best.
	 *
	 * @var string
	 */
	public const SHAPE_UNRELATED = 'unrelated';

	/**
	 * At least one identifier has no ciphertext to read.
	 *
	 * The mirror of `VERDICT_UNKNOWN`, and for a mirror-image reason:
	 * `ffc_user_profiles` is the identity index and stores `cpf_hash` /
	 * `rf_hash` with **no `*_encrypted` sibling**, so a hash reachable only
	 * through it cannot be recovered from anything stored. Deliberately not
	 * folded into `unrelated`: not having read a value is not evidence that it
	 * differs, and the one irreversible mistake in this arc is acting on a
	 * classification that was never made.
	 *
	 * Predicted absent on production, and falsifiably so: the `stores` column
	 * of all 73 findings reads `submissions`, `recruitment_candidate` or both,
	 * and never `user_profiles`.
	 *
	 * @var string
	 */
	public const SHAPE_NOT_DECRYPTABLE = 'not_decryptable';

	/**
	 * The column saying whether each account a finding names still EXISTS.
	 *
	 * Every check here reads `user_id` off the plugin's own stores and none of
	 * them has ever joined `wp_users`, so a reported id was only ever "a number
	 * written in an FFC row". An operator told that account 9129 holds two CPFs
	 * could not tell whether to merge it, repair it or delete a row pointing at
	 * somebody who was deleted years ago -- which is the same "a lead you
	 * cannot act on is not a lead" that #1344 fixed for the identifiers
	 * (#1354).
	 *
	 * Positional, like every list column here: the nth value answers for the
	 * nth account the finding names.
	 *
	 * @var string
	 */
	public const COLUMN_ACCOUNT_STATUS = 'account_status';

	/**
	 * A `wp_users` row exists for that id.
	 *
	 * @var string
	 */
	public const STATUS_EXISTS = 'exists';

	/**
	 * No `wp_users` row exists for that id.
	 *
	 * Structurally possible on exactly one of the four stores:
	 * `MigrationForeignKeys` installs `user_id -> wp_users.ID` on submissions,
	 * appointments and the profile index, and deliberately not on
	 * `ffc_recruitment_candidate` (a candidate is not a user until promotion).
	 * So a missing account is either a candidacy row or evidence that the
	 * foreign-key migration never completed on this install -- which is why
	 * the export reports the live constraint state beside these values rather
	 * than leaving the reader to guess between the two.
	 *
	 * @var string
	 */
	public const STATUS_MISSING = 'missing';

	/**
	 * The column saying how much data hangs off each account, per store.
	 *
	 * What separates *merge this pair* from *delete this orphan row* from
	 * *leave it alone*, and the reason it ships in the same release as the
	 * status rather than after it: the install this diagnoses is production,
	 * where the only way to ask a second question is another release.
	 *
	 * @var string
	 */
	public const COLUMN_ACCOUNT_ROWS = 'account_rows';

	/**
	 * What separates one account's per-store counts from the next account's.
	 *
	 * A different character from {@see self::RELATED_SEPARATOR} on purpose:
	 * the value is a list of lists, and reusing one separator for both levels
	 * makes it unparseable.
	 *
	 * @var string
	 */
	public const ACCOUNT_ROWS_SEPARATOR = ',';

	/**
	 * What separates an account id from its per-store counts.
	 *
	 * @var string
	 */
	public const ACCOUNT_ROWS_ASSIGN = '=';

	/**
	 * What separates a store's label from its count.
	 *
	 * @var string
	 */
	public const ACCOUNT_ROWS_COUNT = ':';

	/**
	 * The column saying WHEN each account a finding names was last used.
	 *
	 * Merging two accounts that share an identifier means choosing which one
	 * survives, and #1346 is explicit that the choice is **by use, never by
	 * id**: the newer account is typically the accidental duplicate, created
	 * precisely because the resolver failed to match, while the older one
	 * holds the history and the password the person knows.
	 *
	 * **It reports last ACTIVITY, not last login, and that is the stronger
	 * signal rather than a substitute for a weaker one.** WordPress core does
	 * not record a last login and neither does this plugin -- so no amount of
	 * new instrumentation could answer it for the pairs that already exist,
	 * which are historical. What the question actually asks is which account
	 * holds the person's records, and a login that left no record does not
	 * answer that while a submission does.
	 *
	 * Positional against `account_status` and `account_rows`, in the order the
	 * finding names the accounts, so the three columns read across.
	 *
	 * @var string
	 */
	public const COLUMN_ACCOUNT_ACTIVITY = 'account_activity';

	/**
	 * What separates the values inside `related` and `stores`.
	 *
	 * Safe for both payloads by construction: a hex hash and a decimal id can
	 * contain neither this character nor any other delimiter.
	 *
	 * @var string
	 */
	public const RELATED_SEPARATOR = '|';

	/**
	 * The verdicts a multiple-identity finding carries, as a seam a test can
	 * replace.
	 *
	 * The shape verdict decrypts, and a test drives it without a key by
	 * overriding `IdentityFindingVerdicts::decrypt()`; this is where it hands
	 * that double in.
	 *
	 * @return IdentityFindingVerdicts
	 */
	protected function verdicts(): IdentityFindingVerdicts {
		return new IdentityFindingVerdicts();
	}

	/**
	 * Every `(user_id, hash, src)` triple the plugin holds, as one derived
	 * table. `src` is the store's short label, so a finding can name where the
	 * rows are rather than only that they disagree.
	 *
	 * @param string $column Identifier column.
	 * @return array{sql: string, values: list<mixed>}|null Null when no store exists.
	 */
	private function pairs( string $column ): ?array {
		$stores = IdentityStores::all();
		if ( array() === $stores ) {
			return null;
		}

		$parts  = array();
		$values = array();

		foreach ( $stores as $table ) {
			// The table name travels WITH the pair, so a finding can say where
			// to look. Without it the union answers "this person has two CPFs"
			// and leaves the operator to search four screens for the rows --
			// the same "a lead you cannot act on is not a lead" that made the
			// export necessary in the first place (#1295).
			$parts[]  = "SELECT user_id, %i AS h, %s AS src FROM %i WHERE user_id IS NOT NULL AND user_id <> 0 AND %i IS NOT NULL AND %i <> ''";
			$values[] = $column;
			$values[] = IdentityStores::label( $table );
			$values[] = $table;
			$values[] = $column;
			$values[] = $column;
		}

		return array(
			'sql'    => implode( ' UNION ', $parts ),
			'values' => $values,
		);
	}

	/**
	 * How many accounts hold more than one identifier of the same kind.
	 *
	 * The same population `multiple_identities()` lists, counted rather than
	 * sampled -- and it is deliberately THIS class that counts it. A second
	 * definition of "conflict" living in the backfill strategy is the parallel
	 * counter `CLAUDE.md` records as redundant indirection: the card and the
	 * identity screen would then be free to disagree about what they are each
	 * naming, with nothing to reconcile them.
	 *
	 * COUNTED ACROSS THE TWO COLUMNS, NOT SUMMED PER COLUMN.
	 *
	 * An account can be in conflict on its CPF *and* on its RF -- #1368 names
	 * one, `438`, and calls it the only account whose two verdicts disagree.
	 * Summing a per-column count reports it twice, which is a number an
	 * operator cannot reconcile against a queue that lists it once. The
	 * `UNION` of the two subject lists dedupes, and the count is over that.
	 *
	 * UNCOUNTABLE IS NULL, NEVER ZERO. When no store carries either column
	 * there is nothing to count and also nothing known -- the same distinction
	 * `scan_coverage()` exists to keep, and the reason a caller must not
	 * render this as "no conflicts".
	 *
	 * @return int|null Accounts in conflict, or null when nothing could be read.
	 */
	public function multiple_identities_count(): ?int {
		global $wpdb;

		$parts  = array();
		$values = array();

		foreach ( IdentityStores::COLUMNS as $column ) {
			$pairs = $this->pairs( $column );
			if ( null === $pairs ) {
				continue;
			}

			$parts[] = "SELECT user_id AS subject FROM ({$pairs['sql']}) AS p GROUP BY user_id HAVING COUNT(DISTINCT h) > 1";
			$values  = array_merge( $values, $pairs['values'] );
		}

		if ( array() === $parts ) {
			return null;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- As in `grouped()`: the fragments are this class's own literals over the union `pairs()` built, and nothing here is request data. A card reporting outstanding work must reflect the live tables.
		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(DISTINCT subject) FROM (' . implode( ' UNION ', $parts ) . ') AS c',
				...$values
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		// A statement the server refused answers null, which is not a count
		// either -- the caller must be able to tell it from a real zero.
		return is_numeric( $found ) ? (int) $found : null;
	}

	/**
	 * One identifier held by more than one user, anywhere.
	 *
	 * The sharpest reading of "two accounts, one person". It is what #1313's
	 * acceptance criteria ask to be counted, and what a merge policy would have
	 * to be designed against.
	 *
	 * @param int $limit Sample size.
	 * @return array<int, array<string, mixed>>
	 */
	public function shared_identities( int $limit = 50 ): array {
		return $this->grouped( 'h', 'user_id', self::ALIAS_USER_COUNT, max( 1, $limit ) );
	}

	/**
	 * One user holding more than one identifier of the same kind, anywhere.
	 *
	 * The mirror image, and a different defect: not two accounts for one
	 * person, but one account that has accumulated two people -- or one person
	 * whose CPF was typed differently twice before #1313's canonicalisation.
	 *
	 * @param int $limit Sample size.
	 * @return array<int, array<string, mixed>>
	 */
	public function multiple_identities( int $limit = 50 ): array {
		return $this->verdicts()->annotate(
			$this->grouped( 'user_id', 'h', self::ALIAS_IDENTITY_COUNT, max( 1, $limit ) )
		);
	}

	/**
	 * Linked identifiers the index does not carry.
	 *
	 * What #1313's backfill exists to empty, reported for a real install: until
	 * this reads zero, resolving a person still falls through to scanning the
	 * module tables.
	 *
	 * `HAVING COUNT(DISTINCT p.h) = 1` IS THE WHOLE POINT, AND IT WAS MISSING
	 *
	 * The backfill leaves a column empty when the user carries more than one
	 * distinct hash for it, on purpose -- picking would destroy the evidence
	 * that the conflict exists. Without this clause every such account is ALSO
	 * reported here, so the check could never reach zero and read as a failure
	 * to an operator who had just run the backfill to completion. It did, on
	 * the first production run (#1333).
	 *
	 * Narrowed to what the backfill COULD have resolved, zero means what it was
	 * always supposed to mean, and this check becomes disjoint from
	 * `multiple_identities()` rather than a second voice for the same rows.
	 *
	 * @param int $limit Sample size.
	 * @return array<int, array<string, mixed>>
	 */
	public function unindexed_links( int $limit = 50 ): array {
		global $wpdb;

		$limit    = max( 1, $limit );
		$profiles = $wpdb->prefix . 'ffc_user_profiles';
		$out      = array();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema probe, as above.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $profiles ) ) !== $profiles ) {
			return $out;
		}

		foreach ( IdentityStores::COLUMNS as $column ) {
			$pairs = $this->pairs( $column );
			if ( null === $pairs ) {
				continue;
			}

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The union is built from the hard-coded fragment in `pairs()`, once per table this class resolved itself, with its placeholders and values filled in the same loop. An audit read must reflect the live rows, never a cache.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT p.user_id,
                            GROUP_CONCAT(DISTINCT p.src ORDER BY p.src SEPARATOR '|') AS stores,
                            %s AS identifier_column
                     FROM ({$pairs['sql']}) AS p
                     LEFT JOIN %i AS idx ON idx.user_id = p.user_id
                     WHERE idx.user_id IS NULL OR idx.%i IS NULL OR idx.%i = ''
                     GROUP BY p.user_id
                     HAVING COUNT(DISTINCT p.h) = 1
                     ORDER BY p.user_id ASC
                     LIMIT %d",
					...array_merge( array( $column ), $pairs['values'], array( $profiles, $column, $column, $limit ) )
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			foreach ( (array) $rows as $row ) {
				$out[] = (array) $row;
			}
		}

		return array_slice( $out, 0, $limit );
	}

	/**
	 * The identifier decrypted and satisfied its own check digits.
	 *
	 * @since 6.28.3
	 * @var string
	 */
	public const VERDICT_VALID = 'valid';

	/**
	 * The identifier decrypted and did NOT satisfy them.
	 *
	 * @since 6.28.3
	 * @var string
	 */
	public const VERDICT_INVALID = 'invalid';

	/**
	 * The identifier was found and could not be decrypted.
	 *
	 * Distinct from invalid on purpose, and for the reason
	 * `SHAPE_NOT_DECRYPTABLE` is distinct: not having read a value is not
	 * evidence that it is wrong.
	 *
	 * @since 6.28.3
	 * @var string
	 */
	public const VERDICT_UNREADABLE = 'unreadable';

	/**
	 * No store carries that hash with a ciphertext beside it.
	 *
	 * @since 6.28.3
	 * @var string
	 */
	public const VERDICT_ABSENT = 'absent';

	/**
	 * Unpack a `|`-separated list of account ids.
	 *
	 * A finding naming NO account is ordinary for the check-digit scan rather
	 * than a defect -- a recruitment candidacy carries no `user_id` until
	 * promotion -- so an empty list here is an answer, never a failure.
	 *
	 * @since 6.28.2
	 * @param mixed $raw Packed value as a finding carries it.
	 * @return list<int>
	 */
	public static function parse_accounts( $raw ): array {
		$out = array();

		if ( ! is_string( $raw ) || '' === $raw ) {
			return $out;
		}

		foreach ( explode( self::RELATED_SEPARATOR, $raw ) as $value ) {
			$value = trim( $value );

			if ( '' === $value || ! ctype_digit( $value ) ) {
				continue;
			}

			if ( ! in_array( (int) $value, $out, true ) ) {
				$out[] = (int) $value;
			}
		}

		return $out;
	}

	/**
	 * Group the `(user_id, hash)` pairs one way and report the collisions.
	 *
	 * @param string $group_by   Column to group by, `h` or `user_id`.
	 * @param string $count_over Column whose distinct values are counted.
	 * @param string $alias      Name of the count in the result.
	 * @param int    $limit      Sample size.
	 * @return array<int, array<string, mixed>>
	 */
	private function grouped( string $group_by, string $count_over, string $alias, int $limit ): array {
		global $wpdb;

		$out = array();

		foreach ( IdentityStores::COLUMNS as $column ) {
			$pairs = $this->pairs( $column );
			if ( null === $pairs ) {
				continue;
			}

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- As in `unindexed_links()`: `$group_by`, `$count_over` and `$alias` are this class's own literals, never request data, and the union comes from `pairs()`.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT {$group_by} AS subject, COUNT(DISTINCT {$count_over}) AS {$alias},
                            GROUP_CONCAT(DISTINCT {$count_over} ORDER BY {$count_over} SEPARATOR '|') AS related,
                            GROUP_CONCAT(DISTINCT p.src ORDER BY p.src SEPARATOR '|') AS stores,
                            %s AS identifier_column
                     FROM ({$pairs['sql']}) AS p
                     GROUP BY {$group_by}
                     HAVING COUNT(DISTINCT {$count_over}) > 1
                     ORDER BY {$alias} DESC
                     LIMIT %d",
					...array_merge( array( $column ), $pairs['values'], array( $limit ) )
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			foreach ( (array) $rows as $row ) {
				$out[] = self::flag_truncated_list( (array) $row, $alias );
			}
		}

		return array_slice( $out, 0, $limit );
	}

	/**
	 * Mark a row whose `related` list is shorter than its own count.
	 *
	 * See {@see self::COLUMN_RELATED_TRUNCATED}: the concatenation truncates in
	 * silence and the count does not, so their disagreement is the only signal
	 * that exists. The row is kept -- dropping it would hide the finding
	 * entirely -- and the flag is what stops a partial list from being read as
	 * the whole one.
	 *
	 * @param array<string, mixed> $row   One grouped row.
	 * @param string               $alias Name of the count column on that row.
	 * @return array<string, mixed>
	 */
	private static function flag_truncated_list( array $row, string $alias ): array {
		$joined  = $row[ self::COLUMN_RELATED ] ?? '';
		$related = is_string( $joined ) ? $joined : '';
		$listed  = '' === $related ? 0 : count( explode( self::RELATED_SEPARATOR, $related ) );

		$total   = $row[ $alias ] ?? 0;
		$counted = is_numeric( $total ) ? (int) $total : 0;

		$row[ self::COLUMN_RELATED_TRUNCATED ] = ( $listed !== $counted );

		return $row;
	}
}
