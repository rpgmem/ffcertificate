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
 * ALL THREE STORES, THE THIRD BY DECODING ITS ANSWERS (#1684).
 *
 * `ffc_recruitment_candidate` and `ffc_self_scheduling_appointments` each
 * declare a plain `name` column. `ffc_submissions` does not: its name lives
 * inside the answers JSON under a per-form `field_key`, encrypted under
 * `data_encrypted` on current installs. This class used to skip it, so a
 * finding whose rows were all submissions had no name -- and that is exactly
 * the shared-mailbox panel, where one login submitted for several people and
 * the operator has to tell them apart to move each number to its owner. It
 * now reads that store the way `IdentitySplit::proposal()` already does:
 * ciphertext first, then the plaintext copy, through `SubmitterName`.
 *
 * Decoding costs a decryption per row, so submissions are read up to
 * {@see self::SUBMISSION_ROWS} rows per hash: enough to find every spelling
 * one person's name takes, and bounded on the account that submitted
 * thousands. Nothing found is still reported as "not recorded", never as an
 * empty string: the #1071 rule, which is that an absence must never render
 * as an answer.
 *
 * @package FreeFormCertificate\Maintenance
 * @since   6.28.4
 */

declare(strict_types=1);

namespace FreeFormCertificate\Maintenance;

use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Core\SubmitterName;

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
	 * `ffc_submissions` is read separately, for the reason the class docblock
	 * gives.
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
	 * How many submissions are decoded per hash.
	 *
	 * @since 6.38.0
	 * @var int
	 */
	public const SUBMISSION_ROWS = 20;

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

		$submissions = $wpdb->prefix . 'ffc_submissions';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $submissions ) ) === $submissions ) {
			$readable = true;

			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT data_encrypted, data FROM %i WHERE %i = %s ORDER BY id DESC LIMIT %d',
					$submissions,
					$column,
					trim( $hash ),
					self::SUBMISSION_ROWS
				),
				ARRAY_A
			);

			foreach ( (array) $rows as $row ) {
				$name = trim( $this->submitted_name( (array) $row ) );

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

	/**
	 * The name a submission's answers carry, or an empty string.
	 *
	 * THE ENCRYPTED COPY FIRST, because it is the one kept current: the
	 * plaintext `data` column is what installs held before the answers were
	 * encrypted. The same order as `IdentitySplit::name_in()`.
	 *
	 * @since 6.38.0
	 * @param array<string, mixed> $row A submission's `data_encrypted` and `data`.
	 * @return string
	 */
	private function submitted_name( array $row ): string {
		$cipher = is_string( $row['data_encrypted'] ?? null ) ? $row['data_encrypted'] : '';

		if ( '' !== $cipher ) {
			$plain   = $this->decrypt( $cipher );
			$answers = null === $plain ? null : json_decode( $plain, true );

			if ( is_array( $answers ) ) {
				return SubmitterName::from( $answers );
			}
		}

		$answers = json_decode( is_string( $row['data'] ?? null ) ? $row['data'] : '', true );

		return is_array( $answers ) ? SubmitterName::from( $answers ) : '';
	}

	/**
	 * Decryption as a seam, so the names can be read without a key.
	 *
	 * @since 6.38.0
	 * @param string $cipher The stored ciphertext.
	 * @return string|null
	 */
	protected function decrypt( string $cipher ): ?string {
		return class_exists( Encryption::class ) ? Encryption::decrypt( $cipher ) : null;
	}
}
// phpcs:enable WordPress.DB.DirectDatabaseQuery
