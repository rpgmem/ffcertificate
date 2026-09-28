<?php
/**
 * Identity agreement
 *
 * The rule that decides whether two sets of identifiers describe one person,
 * shared by every verb of the identity queue that moves records (#1386).
 *
 * EXTRACTED FROM TWO USERS, NEVER ON SPEC
 *
 * It lived inside {@see IdentityRelink} while that was the only verb applying
 * it. The merge applies the same rule to a different pair -- two ACCOUNTS
 * rather than a set of rows and an account -- and a second copy would be two
 * rules that agree today and drift the first time one is corrected. What it
 * decides is whether one person's record lands under another person's login,
 * so drift there is invisible and permanent.
 *
 * @package FreeFormCertificate\Maintenance
 * @since 6.28.3
 */

declare(strict_types=1);

namespace FreeFormCertificate\Maintenance;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Every statement here targets the plugin's own ffc_* tables, for which WordPress exposes no API, and the answer must reflect the live rows: a cached one would be precisely wrong.
/**
 * Decide whether two sets of identifiers describe the same person.
 *
 * THE VERDICT'S SHAPE IS DECLARED ONCE HERE (#1491).
 *
 * It was spelled out inline in five places -- the same long literal in
 * `between()`, twice in `IdentityRelink` and twice in `IdentityMerge` -- and
 * `with_unusable()` would have made six. Five copies of a shape is five chances
 * for one to drift from the others, and PHPStan can only check a consumer
 * against the shape that consumer declares.
 *
 * @phpstan-type Agreement array{matches: array<int, string>, gaps: array<string, string>, conflicts: array<int, string>, reasons: array<string, string>}
 */
class IdentityAgreement {

	/**
	 * The identifiers compared.
	 *
	 * @var array<int, string>
	 */
	public const FIELDS = array( 'rf', 'cpf' );

	/**
	 * The two sides carry values for the identifier and share none of them.
	 *
	 * @var string
	 */
	public const REASON_DISAGREEMENT = 'disagreement';

	/**
	 * The moving side carries MORE THAN ONE value for the identifier.
	 *
	 * A refusal, but not a disagreement -- and telling an operator otherwise
	 * sends them looking for something that is not there (#1477). The two
	 * sides may well share a value: what blocks the move is the extra one,
	 * which nothing has explained and which the target would inherit.
	 *
	 * The fix is on the multi-valued side and on another panel: resolve that
	 * account's own duplicate numbers first, then the move needs no
	 * exception. Correcting a value on the target cannot help.
	 *
	 * @var string
	 */
	public const REASON_AMBIGUOUS = 'ambiguous';

	/**
	 * The two sides share a value, and that value is not a value.
	 *
	 * A NUMBER THAT CANNOT BE ANYONE'S IS NOT EVIDENCE OF ONE PERSON (#1491).
	 *
	 * The other two reasons are about how the sides DISAGREE. This one is about
	 * them agreeing on something that fails its own check digit -- which is not
	 * weak evidence that the accounts are one person, it is evidence that at
	 * least one of them holds a wrong number. Merging on it makes one person out
	 * of two, and a merge does not come back.
	 *
	 * Correcting the number can DISSOLVE the finding rather than resolve it: the
	 * two accounts may never have shared anything, and the pairing was the typo.
	 * So the refusal points at the correction rather than at a judgement, and
	 * needs no acknowledgement -- unlike #1478's, there is a way through.
	 *
	 * @var string
	 */
	public const REASON_UNUSABLE = 'unusable';

	/**
	 * Stores carrying an identifier beside a `user_id`.
	 *
	 * `ffc_user_profiles` is absent because it is the identity index rather
	 * than a record store; it is read separately in {@see self::held_by()}.
	 *
	 * @var array<int, string>
	 */
	private const STORES = array(
		'ffc_submissions',
		'ffc_self_scheduling_appointments',
		'ffc_recruitment_candidate',
	);

	/**
	 * Compare what the records carry against what the account holds.
	 *
	 * Three outcomes per identifier, and the difference between the last two
	 * is the whole rule: equal is a MATCH, the account holding nothing is a
	 * GAP it will gain, and two different values are a CONFLICT. An identifier
	 * neither side carries is none of the three -- absence on both sides says
	 * nothing about whether these are the same person.
	 *
	 * A CONFLICT CARRIES ITS REASON, BECAUSE THERE ARE TWO AND THEY ARE FIXED
	 * ON DIFFERENT SCREENS (#1477).
	 *
	 * `conflicts` remains the one authoritative list of what blocks: a caller
	 * that reads only it refuses exactly what it refused before, which matters
	 * because what this decides is whether one person's records land under
	 * another person's login. `reasons` adds nothing to the decision and only
	 * says WHICH branch refused -- the two sides sharing no value at all
	 * (`REASON_DISAGREEMENT`, where correcting a value is the fix), or the
	 * moving side carrying more than one (`REASON_AMBIGUOUS`, where there may
	 * be no disagreement whatsoever and the fix is elsewhere).
	 *
	 * @param array<string, array<int, string>> $records What the moving rows carry.
	 * @param array<string, array<int, string>> $account What the target holds.
	 * @return Agreement
	 */
	public static function between( array $records, array $account ): array {
		$matches   = array();
		$gaps      = array();
		$conflicts = array();
		$reasons   = array();

		foreach ( self::FIELDS as $field ) {
			$mine   = $records[ $field ] ?? array();
			$theirs = $account[ $field ] ?? array();

			if ( array() === $mine ) {
				continue;
			}

			if ( array() === $theirs ) {
				// Only a single, unambiguous value can fill a gap: records
				// carrying two different CPFs do not tell the account which
				// one it gains, and picking would invent an answer.
				if ( 1 === count( $mine ) ) {
					$gaps[ $field ] = $mine[0];
				} else {
					$conflicts[]       = $field;
					$reasons[ $field ] = self::REASON_AMBIGUOUS;
				}

				continue;
			}

			if ( array() === array_diff( $mine, $theirs ) && 1 === count( $mine ) ) {
				$matches[] = $field;
				continue;
			}

			$conflicts[] = $field;

			// SHARING A VALUE AND STILL BEING REFUSED IS THE COMMON CASE, AND
			// IT IS NOT A DISAGREEMENT.
			//
			// An intersection means the two sides agree about a number and the
			// moving side carries another one besides. Read as a disagreement
			// -- which is how this was reported until #1477 -- it sends the
			// operator to compare two values that are identical.
			$reasons[ $field ] = array() === array_intersect( $mine, $theirs )
				? self::REASON_DISAGREEMENT
				: self::REASON_AMBIGUOUS;
		}

		return array(
			'matches'   => $matches,
			'gaps'      => $gaps,
			'conflicts' => $conflicts,
			'reasons'   => $reasons,
		);
	}

	/**
	 * Every identifier an account holds, across its records and its index.
	 *
	 * BOTH, AND NOT ONLY THE INDEX.
	 *
	 * The index has one slot per identifier and is populated by a backfill
	 * that deliberately leaves a slot EMPTY when the account carries more than
	 * one distinct value for it. So an account that looks empty in the index
	 * may be exactly the account that holds two -- reading the index alone
	 * would call that a gap to fill, and fill it.
	 *
	 * @param int $user_id The account.
	 * @return array<string, array<int, string>>
	 */
	public static function held_by( int $user_id ): array {
		global $wpdb;

		$out = array();

		foreach ( self::STORES as $suffix ) {
			$table = $wpdb->prefix . $suffix;

			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				continue;
			}

			$found = $wpdb->get_results(
				$wpdb->prepare( 'SELECT cpf_hash, rf_hash FROM %i WHERE user_id = %d', $table, $user_id ),
				ARRAY_A
			);

			foreach ( (array) $found as $row ) {
				self::collect( $out, (array) $row );
			}
		}

		$profiles = $wpdb->prefix . 'ffc_user_profiles';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $profiles ) ) === $profiles ) {
			$indexed = $wpdb->get_row(
				$wpdb->prepare( 'SELECT cpf_hash, rf_hash FROM %i WHERE user_id = %d', $profiles, $user_id ),
				ARRAY_A
			);

			if ( is_array( $indexed ) ) {
				self::collect( $out, $indexed );
			}
		}

		return $out;
	}

	/**
	 * Add one row's identifier hashes to a per-field register, without repeats.
	 *
	 * IT TAKES ANY ROW, AND THAT IS THE HONEST SIGNATURE.
	 *
	 * A row arrives from `$wpdb` as `mixed` and reaches this as a plain array,
	 * so requiring `array<string, mixed>` claimed a key type nothing proves --
	 * which level 9 reports, correctly, at the one call site that passes a
	 * `get_row()` result straight through. The method already reads
	 * defensively: it asks for the two keys it knows and ignores everything
	 * else, so what it needs is a row, not a shape.
	 *
	 * @param array<string, array<int, string>> $into The register.
	 * @param array<mixed, mixed>               $row  The row, however it arrived.
	 * @return void
	 */
	public static function collect( array &$into, array $row ): void {
		foreach ( self::FIELDS as $field ) {
			$value = $row[ $field . '_hash' ] ?? '';

			if ( ! is_string( $value ) || '' === $value ) {
				continue;
			}

			if ( ! isset( $into[ $field ] ) ) {
				$into[ $field ] = array();
			}

			if ( ! in_array( $value, $into[ $field ], true ) ) {
				$into[ $field ][] = $value;
			}
		}
	}

	/**
	 * Move any agreed-on identifier whose value fails its own check digit.
	 *
	 * PURE ON PURPOSE, WHICH IS WHY IT TAKES VERDICTS RATHER THAN FETCHING THEM.
	 *
	 * {@see self::between()} compares HASHES: `held_by()` selects `cpf_hash` and
	 * `rf_hash`, and nothing decrypts -- which is what makes it cheap. A hash has
	 * no check digit, so the judgement cannot be made here; and it must not be
	 * made by reading values into this class either, because then every
	 * comparison would decrypt.
	 *
	 * `IdentityConflictQuery::check_digit_verdicts()` already answers per HASH,
	 * decrypting internally. So the verb fetches and this decides, which leaves
	 * this method testable with no database and keeps the I/O where the verb is
	 * already doing I/O.
	 *
	 * A FIELD THAT MOVES LEAVES `matches`, AND THAT IS NOT BOOKKEEPING. Callers
	 * read `matches` as *these accounts agree about something*, and an unusable
	 * value is the opposite: it is why they must not be merged yet. Left in both
	 * lists, a verb that consults `matches` first would proceed on the very value
	 * that blocks it.
	 *
	 * `VERDICT_UNREADABLE` DELIBERATELY DOES NOT REFUSE. Not having read a value
	 * is not evidence that it is wrong -- the rule
	 * `IdentityConflictQuery::rf_check_digit_failures()` already states for its
	 * own scan, and the #1071 / #1094 rule generally. Refusing there would make a
	 * mismatched encryption key look like a data defect and block every merge on
	 * an install whose key does not match its rows.
	 *
	 * @since 6.31.0
	 * @param array<string, mixed>                 $agreement What {@see self::between()} returned.
	 * @phpstan-param Agreement $agreement
	 * @param array<string, array<int, string>>    $records   Field => the moving side's hashes.
	 * @param array<string, array<string, string>> $verdicts  Field => hash => a `VERDICT_*`.
	 * @return Agreement The agreement, with unusable fields moved.
	 */
	public static function with_unusable( array $agreement, array $records, array $verdicts ): array {
		// NO DEFENSIVE `is_array()` HERE, BECAUSE THE TYPE IS THE GUARANTEE.
		//
		// This opened with three of them and PHPStan at level 9 reported each as
		// `will always evaluate to true` once `Agreement` was declared. A guard
		// against a state the type forbids is noise that reads as caution, and it
		// hides the one place a real check belongs: `$records` is a separate
		// argument the caller assembles, so its lookup keeps its fallback.
		$conflicts = $agreement['conflicts'];
		$reasons   = $agreement['reasons'];
		$kept      = array();

		foreach ( $agreement['matches'] as $field ) {
			$held = $records[ $field ] ?? array();
			$hash = (string) ( $held[0] ?? '' );
			$said = (string) ( $verdicts[ $field ][ $hash ] ?? '' );

			if ( '' !== $hash && IdentityConflictQuery::VERDICT_INVALID === $said ) {
				$conflicts[]       = $field;
				$reasons[ $field ] = self::REASON_UNUSABLE;

				continue;
			}

			$kept[] = $field;
		}

		$agreement['matches']   = $kept;
		$agreement['conflicts'] = $conflicts;
		$agreement['reasons']   = $reasons;

		return $agreement;
	}
}
// phpcs:enable WordPress.DB.DirectDatabaseQuery
