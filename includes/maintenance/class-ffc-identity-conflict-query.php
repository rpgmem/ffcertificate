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
 * @package FreeFormCertificate\Maintenance
 * @since 6.26.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Maintenance;

use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Core\SensitiveFieldRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only cross-store identity questions.
 */
class IdentityConflictQuery {

	/**
	 * The identifier columns every store shares.
	 *
	 * `email_hash` is not among them on purpose: the index declares no such
	 * column, because `wp_users.user_email` is already uniquely indexed and a
	 * third copy would answer a question WordPress answers (#1313 PR 2).
	 *
	 * @var list<string>
	 */
	private const COLUMNS = array( 'cpf_hash', 'rf_hash' );

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
	 * than two identifiers usually reports -- see {@see self::shape()} on why
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
	 * How bad each shape is, for reducing a set of pairs to one verdict.
	 *
	 * @var array<string, int>
	 */
	private const SHAPE_SEVERITY = array(
		self::SHAPE_SAME_AFTER_CANONICALISATION => 0,
		self::SHAPE_LEADING_ZEROS               => 1,
		self::SHAPE_TRANSPOSITION               => 2,
		self::SHAPE_SINGLE_DIGIT_EDIT           => 3,
		self::SHAPE_UNRELATED                   => 4,
	);

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
	 * Where each store records that something happened.
	 *
	 * A map and not a probe, unlike {@see self::stores()}, because nothing in
	 * a schema says which of several timestamps means "was used" -- the
	 * appointment table alone carries seven. The column each entry names is
	 * still probed before it is read, so a store that loses it degrades to
	 * "no activity here" instead of failing the whole audit.
	 *
	 * @var array<string, string>
	 */
	private const ACTIVITY_COLUMNS = array(
		'ffc_submissions'                  => 'submission_date',
		'ffc_self_scheduling_appointments' => 'created_at',
		'ffc_recruitment_candidate'        => 'created_at',
		'ffc_user_profiles'                => 'updated_at',
	);

	/**
	 * The stores whose activity column is a unix instant rather than a DATETIME.
	 *
	 * **This split is the whole reason the comparison is not done in SQL.**
	 * `submission_date` is a Category A instant -- unix UTC seconds, written
	 * by `time()`. The other three are the housekeeping DATETIMEs that
	 * `CLAUDE.md`'s date convention carves out, written through
	 * `current_time( 'mysql' )` and therefore already in the SITE's timezone.
	 *
	 * `UNIX_TIMESTAMP()` would reinterpret those DATETIMEs in the MySQL
	 * server's timezone, which is not necessarily WordPress's, so a single
	 * `MAX()` across the union would silently skew three stores against one.
	 * Both categories render to the same site-local calendar date, so the
	 * comparison happens on `Y-m-d` strings instead: chronological, because
	 * that format sorts lexicographically, and with no timezone arithmetic
	 * anywhere.
	 *
	 * A list and not a map: the only thing an entry carries is membership, so
	 * a `=> true` beside each name would be a value that says nothing -- and
	 * PHPStan is right to call the `$is_unix &&` it invites a condition that
	 * is always true.
	 *
	 * @var array<int, string>
	 */
	private const ACTIVITY_UNIX_STORES = array( 'ffc_submissions' );

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
	 * The stores that carry an identifier alongside a `user_id`.
	 *
	 * Resolved against the live schema rather than assumed: a site that never
	 * activated recruitment has no candidate table, and a `UNION` naming a
	 * missing table fails the whole query rather than skipping it.
	 *
	 * @return list<string>
	 */
	private function stores(): array {
		global $wpdb;

		$out = array();

		foreach ( array(
			'ffc_submissions',
			'ffc_self_scheduling_appointments',
			'ffc_recruitment_candidate',
			'ffc_user_profiles',
		) as $suffix ) {
			$table = $wpdb->prefix . $suffix;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema probe: the answer must reflect the live schema, so a cached one would be precisely wrong.
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
				$out[] = $table;
			}
		}

		return $out;
	}

	/**
	 * A store's name without the site's table prefix, for a report a person
	 * reads. `wp_ffc_submissions` becomes `submissions`.
	 *
	 * @param string $table Full table name.
	 * @return string
	 */
	private function label( string $table ): string {
		global $wpdb;

		$short = $table;
		if ( 0 === strpos( $short, $wpdb->prefix ) ) {
			$short = substr( $short, strlen( $wpdb->prefix ) );
		}
		if ( 0 === strpos( $short, 'ffc_' ) ) {
			$short = substr( $short, 4 );
		}

		return $short;
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
		$stores = $this->stores();
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
			$values[] = $this->label( $table );
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
		return $this->with_shape_verdict(
			$this->with_email_verdict( $this->grouped( 'user_id', 'h', self::ALIAS_IDENTITY_COUNT, max( 1, $limit ) ) )
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

		foreach ( self::COLUMNS as $column ) {
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
	 * What is true of each account a finding names: does it exist, and how
	 * much data hangs off it.
	 *
	 * ONE STATEMENT PER QUESTION, NOT FIVE PER ACCOUNT
	 *
	 * The obvious shape -- ask `wp_users` once per id, then `COUNT(*)` once
	 * per id per store -- is 5 statements an account, or 360 for the 72 the
	 * first production export named. Both questions are set questions, so
	 * both are asked once: existence through `get_users()` bounded by
	 * `include`, and the counts as one `UNION ALL` grouping by account and
	 * store. That second one is the same union the rest of this class already
	 * builds, widened to count rows instead of pairing identifiers.
	 *
	 * EXISTENCE GOES THROUGH THE WP API, AND `blog_id` IS WHY IT IS NOT SQL
	 *
	 * A hand-rolled `SELECT ID FROM wp_users` is what `PhpcsSuppressionTest`
	 * refuses in a file carrying a `DirectDatabaseQuery` disable, and it is
	 * right to: the sniff has something real to say here, because core owns
	 * that table. `get_users()` answers the same question -- and `blog_id => 0`
	 * is load-bearing rather than tidy, since the default scopes the query to
	 * users holding a role on the CURRENT site, which on multisite would
	 * report a live account as deleted.
	 *
	 * The counts are of EVERY row the account owns in a store, not only the
	 * rows carrying the identifier the finding is about. The question they
	 * answer is "what would a merge move, or a deletion destroy", and that is
	 * all of it.
	 *
	 * An account is reported `missing` until `wp_users` says otherwise, so a
	 * query that returns nothing reads as "no account found" rather than
	 * silently leaving every status blank -- an empty result must never read
	 * as clean.
	 *
	 * @param array<int, mixed> $user_ids Accounts a finding names; non-positive values are dropped.
	 * @return array<int, array{status: string, rows: array<string, int>, activity: string}> Account id -> facts.
	 */
	public function account_facts( array $user_ids ): array {
		global $wpdb;

		$wanted = array();
		foreach ( $user_ids as $candidate ) {
			$id = is_numeric( $candidate ) ? (int) $candidate : 0;
			if ( $id > 0 ) {
				$wanted[ $id ] = true;
			}
		}

		$ids = array_keys( $wanted );
		if ( array() === $ids ) {
			return array();
		}

		$out = array();
		foreach ( $ids as $id ) {
			$out[ $id ] = array(
				'status'   => self::STATUS_MISSING,
				'rows'     => array(),
				'activity' => '',
			);
		}

		$found = get_users(
			array(
				'include'     => $ids,
				'fields'      => 'ID',
				'blog_id'     => 0,
				'number'      => count( $ids ),
				'count_total' => false,
			)
		);

		foreach ( (array) $found as $id ) {
			$id = is_numeric( $id ) ? (int) $id : 0;
			if ( isset( $out[ $id ] ) ) {
				$out[ $id ]['status'] = self::STATUS_EXISTS;
			}
		}

		$stores = $this->stores();

		if ( array() === $stores ) {
			return $out;
		}

		foreach ( $this->activity_per_account( $ids ) as $id => $date ) {
			if ( isset( $out[ $id ] ) ) {
				$out[ $id ]['activity'] = $date;
			}
		}

		foreach ( array_chunk( $ids, self::USERS_PER_STATEMENT ) as $chunk ) {
			$slots = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );

			$parts  = array();
			$values = array();

			foreach ( $stores as $table ) {
				$parts[]  = "SELECT user_id, %s AS src, COUNT(*) AS n FROM %i WHERE user_id IN ({$slots}) GROUP BY user_id";
				$values[] = $this->label( $table );
				$values[] = $table;
				foreach ( $chunk as $user_id ) {
					$values[] = (int) $user_id;
				}
			}

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The fragment is this class's own literal, repeated once per table it resolved itself through `stores()`; `$slots` is `%d` placeholders counted off `$chunk`. These are the plugin's own `ffc_*` tables, for which WordPress exposes no API, and an audit read must reflect the live rows.
			$counts = $wpdb->get_results( $wpdb->prepare( implode( ' UNION ALL ', $parts ), ...$values ), ARRAY_A );
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			foreach ( (array) $counts as $row ) {
				$row   = (array) $row;
				$id    = isset( $row['user_id'] ) && is_numeric( $row['user_id'] ) ? (int) $row['user_id'] : 0;
				$src   = isset( $row['src'] ) && is_string( $row['src'] ) ? $row['src'] : '';
				$total = isset( $row['n'] ) && is_numeric( $row['n'] ) ? (int) $row['n'] : 0;

				if ( isset( $out[ $id ] ) && '' !== $src && $total > 0 ) {
					$out[ $id ]['rows'][ $src ] = $total;
				}
			}
		}

		return $out;
	}

	/**
	 * The most recent calendar date each account was active on, anywhere.
	 *
	 * One `MAX()` per store, then the latest of those per account. The
	 * comparison is on `Y-m-d` strings rather than on timestamps, for the
	 * reason {@see self::ACTIVITY_UNIX_STORES} records: the stores disagree
	 * about how a moment is stored, and only the rendered site-local date is
	 * a like-for-like value across all four.
	 *
	 * A store missing its mapped column is skipped rather than fatal, and an
	 * account with nothing anywhere is simply absent from the result -- which
	 * {@see self::account_facts()} leaves as the empty string it seeded.
	 *
	 * @param array<int, int> $user_ids Accounts to ask about.
	 * @return array<int, string> Account id -> `Y-m-d`.
	 */
	private function activity_per_account( array $user_ids ): array {
		global $wpdb;

		$out    = array();
		$stores = $this->stores_with_activity();

		if ( array() === $stores || array() === $user_ids ) {
			return $out;
		}

		foreach ( array_chunk( $user_ids, self::USERS_PER_STATEMENT ) as $chunk ) {
			$slots  = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );
			$parts  = array();
			$values = array();

			foreach ( $stores as $table => $column ) {
				$parts[]  = "SELECT user_id, %s AS src, MAX(%i) AS last_seen FROM %i WHERE user_id IN ({$slots}) GROUP BY user_id";
				$values[] = (string) $table;
				$values[] = $column;
				$values[] = (string) $table;
				foreach ( $chunk as $user_id ) {
					$values[] = (int) $user_id;
				}
			}

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The fragment is this class's own literal, repeated once per table it resolved itself; `$slots` is `%d` placeholders counted off `$chunk`, never request data. These are the plugin's own `ffc_*` tables, and an audit read must reflect the live rows.
			$rows = $wpdb->get_results( $wpdb->prepare( implode( ' UNION ALL ', $parts ), ...$values ), ARRAY_A );
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			foreach ( (array) $rows as $row ) {
				$row = (array) $row;
				$id  = isset( $row['user_id'] ) && is_numeric( $row['user_id'] ) ? (int) $row['user_id'] : 0;
				$src = isset( $row['src'] ) && is_string( $row['src'] ) ? $row['src'] : '';
				// Two arms where its siblings need one, and the reason is the
				// union itself: `MAX()` over a bigint branch and a DATETIME branch
				// can come back as either, depending on the driver. What must not
				// happen is casting the `mixed` a row offset yields without
				// establishing its type first -- which is what the level-9 gate
				// caught here, and what every other read in this class already does.
				$last = ( isset( $row['last_seen'] ) && ( is_string( $row['last_seen'] ) || is_int( $row['last_seen'] ) ) )
					? (string) $row['last_seen']
					: '';
				$date = self::activity_date( $src, $last );

				// `Y-m-d` sorts lexicographically, so the later string is the
				// later date and no parsing is needed to compare them.
				if ( $id > 0 && '' !== $date && ( ! isset( $out[ $id ] ) || $date > $out[ $id ] ) ) {
					$out[ $id ] = $date;
				}
			}
		}

		return $out;
	}

	/**
	 * The stores whose activity column this install actually has.
	 *
	 * @return array<string, string> Prefixed table -> column.
	 */
	private function stores_with_activity(): array {
		global $wpdb;

		$out = array();

		foreach ( $this->stores() as $table ) {
			$column = '';

			foreach ( self::ACTIVITY_COLUMNS as $suffix => $candidate ) {
				if ( substr( $table, -strlen( $suffix ) ) === $suffix ) {
					$column = $candidate;
					break;
				}
			}

			if ( '' === $column ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema probe, as in `stores()`.
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, $column ) );

			if ( $column === $found ) {
				$out[ $table ] = $column;
			}
		}

		return $out;
	}

	/**
	 * One store's stored value as the site-local calendar date it means.
	 *
	 * The two shapes are not interchangeable and the difference is not
	 * cosmetic: a unix instant has to be rendered through the site timezone
	 * to become a date, while a housekeeping DATETIME was written in that
	 * timezone already and only needs its date half.
	 *
	 * `wp_date()` rather than `DateFormatter::format_date()` on purpose. This
	 * is a machine column -- it is compared, sorted and filtered in a CSV --
	 * so it needs the stable `Y-m-d` that the site's display format is not,
	 * the same documented-reason carve-out `CLAUDE.md` grants an iCal
	 * `DTSTAMP`. The screen shows the identical string, which is what keeps
	 * the file and the table corroborating each other.
	 *
	 * @param string $table Prefixed table the value came from.
	 * @param string $value Stored value, as the driver returned it.
	 * @return string `Y-m-d`, or '' when there is nothing to report.
	 */
	private static function activity_date( string $table, string $value ): string {
		if ( '' === $value ) {
			return '';
		}

		foreach ( self::ACTIVITY_UNIX_STORES as $suffix ) {
			if ( substr( $table, -strlen( $suffix ) ) === $suffix ) {
				$stamp = (int) $value;

				return ( $stamp > 0 && function_exists( 'wp_date' ) )
					? (string) wp_date( 'Y-m-d', $stamp )
					: '';
			}
		}

		// A DATETIME the site's own timezone already wrote. Anything that is
		// not one -- a zero date, a driver oddity -- reports nothing rather
		// than a date nobody can trace back to a row.
		return ( 1 === preg_match( '/^(\d{4}-\d{2}-\d{2})/', $value, $match ) && '0000-00-00' !== $match[1] )
			? $match[1]
			: '';
	}

	/**
	 * One account's per-store counts as the string the report carries.
	 *
	 * `submissions:12,user_profiles:1`, or an empty string when the account
	 * owns no row in any store -- which is itself a finding, since an account
	 * named by this audit is named BECAUSE rows point at it. An empty value
	 * there means every such row sits in a store {@see self::stores()} does
	 * not resolve on this install.
	 *
	 * @param array<string, int> $rows Store label -> row count.
	 * @return string
	 */
	public static function format_account_rows( array $rows ): string {
		$parts = array();

		foreach ( $rows as $store => $total ) {
			$parts[] = $store . self::ACCOUNT_ROWS_COUNT . (string) (int) $total;
		}

		return implode( self::ACCOUNT_ROWS_SEPARATOR, $parts );
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

		foreach ( self::COLUMNS as $column ) {
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
	 * The column holding the hashed address, where a store has one.
	 *
	 * @var string
	 */
	private const COLUMN_EMAIL_HASH = 'email_hash';

	/**
	 * Accounts per statement when asking about a set of them.
	 *
	 * The findings are capped by the caller, so this only ever bounds how wide
	 * ONE `IN` list gets rather than how many accounts are examined.
	 *
	 * @var int
	 */
	private const USERS_PER_STATEMENT = 500;

	/**
	 * Annotate each finding with what the addresses say about it.
	 *
	 * See {@see self::COLUMN_EMAIL_VERDICT}. This runs over the accounts the
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
			$total    = $row[ self::ALIAS_IDENTITY_COUNT ] ?? 0;
			$expected = is_numeric( $total ) ? (int) $total : 0;

			$rows[ $index ][ self::COLUMN_EMAIL_VERDICT ] = self::verdict(
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

		return ( is_string( $column ) && in_array( $column, self::COLUMNS, true ) ) ? $column : '';
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

		foreach ( array_chunk( $user_ids, self::USERS_PER_STATEMENT ) as $chunk ) {
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
	 * Probed rather than listed, for the reason {@see self::stores()} probes
	 * the tables: a list written here is a claim about a schema this file does
	 * not own, and it goes stale in silence. `ffc_user_profiles` is the
	 * ordinary miss -- it is the identity index and stores no address.
	 *
	 * @return list<string>
	 */
	private function stores_with_addresses(): array {
		global $wpdb;

		$out = array();

		foreach ( $this->stores() as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema probe, as in `stores()`.
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
			return self::VERDICT_UNKNOWN;
		}

		$groups_per_address = array();

		foreach ( $by_hash as $addresses ) {
			foreach ( array_unique( $addresses ) as $address ) {
				$groups_per_address[ $address ] = ( $groups_per_address[ $address ] ?? 0 ) + 1;
			}
		}

		foreach ( $groups_per_address as $groups ) {
			if ( $groups > 1 ) {
				return self::VERDICT_SHARED_EMAIL;
			}
		}

		return self::VERDICT_DISTINCT_EMAILS;
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
			$total    = $row[ self::ALIAS_IDENTITY_COUNT ] ?? 0;
			$expected = is_numeric( $total ) ? (int) $total : 0;

			$rows[ $index ][ self::COLUMN_SHAPE_VERDICT ] = ( '' === $column || $user < 1 )
				? ''
				: self::shape(
					$this->plaintexts( $seen[ $column ][ $user ] ?? array() ),
					self::field_key_of( $column ),
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

		$cipher_column = self::ciphertext_column_of( $column );
		$stores        = $this->stores_with_ciphertext( $cipher_column );
		$out           = array();

		if ( array() === $stores || array() === $user_ids || '' === $cipher_column ) {
			return $out;
		}

		foreach ( array_chunk( $user_ids, self::USERS_PER_STATEMENT ) as $chunk ) {
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
	 * The stores carrying a ciphertext beside the identifier.
	 *
	 * Probed rather than listed, for the reason {@see self::stores()} probes
	 * the tables. `ffc_user_profiles` is the miss here and it is structural,
	 * not incidental: it is the identity index, and it declares `cpf_hash` and
	 * `rf_hash` with no `*_encrypted` column at all.
	 *
	 * @param string $cipher_column Ciphertext column to require.
	 * @return list<string>
	 */
	private function stores_with_ciphertext( string $cipher_column ): array {
		global $wpdb;

		$out = array();

		if ( '' === $cipher_column ) {
			return $out;
		}

		foreach ( $this->stores() as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema probe, as in `stores()`.
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, $cipher_column ) );

			if ( $cipher_column === $found ) {
				$out[] = $table;
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
			return self::SHAPE_NOT_DECRYPTABLE;
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
			return self::SHAPE_SAME_AFTER_CANONICALISATION;
		}

		$bare_left  = ltrim( $left, '0' );
		$bare_right = ltrim( $right, '0' );

		if ( '' !== $bare_left && $bare_left === $bare_right ) {
			return self::SHAPE_LEADING_ZEROS;
		}

		if ( strlen( $left ) === strlen( $right ) ) {
			$positions = array();

			for ( $i = 0, $len = strlen( $left ); $i < $len; $i++ ) {
				if ( $left[ $i ] !== $right[ $i ] ) {
					$positions[] = $i;
				}
			}

			if ( 1 === count( $positions ) ) {
				return self::SHAPE_SINGLE_DIGIT_EDIT;
			}

			if (
				2 === count( $positions )
				&& 1 === $positions[1] - $positions[0]
				&& $left[ $positions[0] ] === $right[ $positions[1] ]
				&& $left[ $positions[1] ] === $right[ $positions[0] ]
			) {
				return self::SHAPE_TRANSPOSITION;
			}

			return self::SHAPE_UNRELATED;
		}

		if ( 1 === abs( strlen( $left ) - strlen( $right ) ) ) {
			$longer  = strlen( $left ) > strlen( $right ) ? $left : $right;
			$shorter = strlen( $left ) > strlen( $right ) ? $right : $left;

			if ( self::is_one_deletion( $longer, $shorter ) ) {
				return self::SHAPE_SINGLE_DIGIT_EDIT;
			}
		}

		return self::SHAPE_UNRELATED;
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

	/**
	 * The ciphertext column beside an identifier column.
	 *
	 * @param string $column `cpf_hash` / `rf_hash`.
	 * @return string `cpf_encrypted` / `rf_encrypted`, or '' when unknown.
	 */
	private static function ciphertext_column_of( string $column ): string {
		return in_array( $column, self::COLUMNS, true )
			? str_replace( '_hash', '_encrypted', $column )
			: '';
	}

	/**
	 * The logical field key an identifier column carries.
	 *
	 * Both map to the same normalizer today, so this is about being right
	 * rather than about behaviour: `SensitiveFieldRegistry` owns which key
	 * gets which canonical form, and hard-coding one here would be a claim
	 * about a map this file does not own.
	 *
	 * @param string $column `cpf_hash` / `rf_hash`.
	 * @return string `cpf` / `rf`.
	 */
	private static function field_key_of( string $column ): string {
		return str_replace( '_hash', '', $column );
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
