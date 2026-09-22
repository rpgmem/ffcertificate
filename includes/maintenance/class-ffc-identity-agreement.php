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
 */
class IdentityAgreement {

	/**
	 * The identifiers compared.
	 *
	 * @var array<int, string>
	 */
	public const FIELDS = array( 'rf', 'cpf' );

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
	 * @param array<string, array<int, string>> $records What the moving rows carry.
	 * @param array<string, array<int, string>> $account What the target holds.
	 * @return array{matches: array<int, string>, gaps: array<string, string>, conflicts: array<int, string>}
	 */
	public static function between( array $records, array $account ): array {
		$matches   = array();
		$gaps      = array();
		$conflicts = array();

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
					$conflicts[] = $field;
				}

				continue;
			}

			if ( array() === array_diff( $mine, $theirs ) && 1 === count( $mine ) ) {
				$matches[] = $field;
				continue;
			}

			$conflicts[] = $field;
		}

		return array(
			'matches'   => $matches,
			'gaps'      => $gaps,
			'conflicts' => $conflicts,
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
}
// phpcs:enable WordPress.DB.DirectDatabaseQuery
