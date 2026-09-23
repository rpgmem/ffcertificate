<?php
/**
 * Names the records carry
 *
 * Who a finding is about, read from the RECORDS rather than from an account
 * (#1397 sprint 4).
 *
 * THE ISOLATED TIER HAS NO ACCOUNT TO ASK.
 *
 * A check-digit failure that no account-side finding explains is often a
 * recruitment candidacy, which carries no WordPress user until it is promoted
 * — so `get_userdata()` answers nothing and the operator is left confirming a
 * number with HR without being able to say whose. The stores hold the name
 * themselves, and that is what this reads.
 *
 * TWO OF THE THREE STORES, AND SAYING SO IS THE POINT.
 *
 * `ffc_recruitment_candidate` and `ffc_self_scheduling_appointments` each
 * declare a plain `name` column. `ffc_submissions` does NOT: its name lives
 * inside the `data` JSON under a per-install `field_key`, and the encrypted
 * copy under `data_encrypted` — so reading it means decoding a blob whose
 * shape differs per form. This class does not do that, and a finding whose
 * rows are all submissions therefore has no name here. That is reported as
 * "not recorded", never as an empty string: the #1071 rule, which is that an
 * absence must never render as an answer.
 *
 * @package FreeFormCertificate\Maintenance
 * @since   6.28.4
 */

declare(strict_types=1);

namespace FreeFormCertificate\Maintenance;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Every statement here targets the plugin's own ffc_* tables, for which WordPress exposes no API, and the answer must reflect the live rows: a cached one would name the person a repair has already moved.
/**
 * Read the names the records themselves carry.
 */
class IdentityRecordNames {

	/**
	 * Stores carrying a plain `name` beside an identifier hash.
	 *
	 * `ffc_submissions` is absent for the reason the class docblock gives.
	 *
	 * @var array<int, string>
	 */
	private const STORES = array(
		'ffc_recruitment_candidate',
		'ffc_self_scheduling_appointments',
	);

	/**
	 * How many distinct names are reported for one finding.
	 *
	 * More than one is ordinary — the same person's name spelled two ways
	 * across two stores — and more than a handful says the finding names more
	 * than one person, which is what the ambiguity refusals exist for. The cap
	 * keeps a pathological row set from filling the screen; a finding that
	 * reaches it is reported as capped rather than trimmed in silence.
	 *
	 * @var int
	 */
	public const LIMIT = 4;

	/**
	 * Every distinct name recorded beside one identifier hash.
	 *
	 * @param string $hash  The stored hash.
	 * @param string $field `rf` or `cpf`.
	 * @return array{names: array<int, string>, capped: bool, readable: bool}
	 */
	public function for_hash( string $hash, string $field = 'rf' ): array {
		global $wpdb;

		$blank = array(
			'names'    => array(),
			'capped'   => false,
			// False when no store this can read was even present, which is a
			// different thing from "nobody is named" and must not render the
			// same way.
			'readable' => false,
		);

		if ( ! in_array( $field, IdentityAgreement::FIELDS, true ) || '' === trim( $hash ) ) {
			return $blank;
		}

		$column   = $field . '_hash';
		$names    = array();
		$readable = false;

		foreach ( self::STORES as $suffix ) {
			$table = $wpdb->prefix . $suffix;

			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				continue;
			}

			$readable = true;

			$found = $wpdb->get_col(
				$wpdb->prepare(
					'SELECT name FROM %i WHERE %i = %s AND name IS NOT NULL AND name <> %s',
					$table,
					$column,
					trim( $hash ),
					''
				)
			);

			foreach ( (array) $found as $name ) {
				$name = trim( (string) $name );

				if ( '' !== $name && ! in_array( $name, $names, true ) ) {
					$names[] = $name;
				}
			}
		}

		return array(
			'names'    => array_slice( $names, 0, self::LIMIT ),
			'capped'   => count( $names ) > self::LIMIT,
			'readable' => $readable,
		);
	}
}
// phpcs:enable WordPress.DB.DirectDatabaseQuery
