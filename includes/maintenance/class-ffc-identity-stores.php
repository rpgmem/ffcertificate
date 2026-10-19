<?php
/**
 * IdentityStores
 *
 * The tables that carry an identifier beside a `user_id`, and the facts about
 * their schema every identity reader needs (#1925).
 *
 * They were private helpers of `IdentityConflictQuery` while it was the only
 * reader. Splitting it by cohesion gave three more classes that each probe
 * the same stores, and a private helper copied three times is three schema
 * claims to keep in step -- so the probes live here, once.
 *
 * @package FreeFormCertificate\Maintenance
 * @since 6.41.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Maintenance;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schema probes shared by the identity readers.
 */
final class IdentityStores {

	/**
	 * The identifier columns every store shares.
	 *
	 * `email_hash` is not among them on purpose: the index declares no such
	 * column, because `wp_users.user_email` is already uniquely indexed and a
	 * third copy would answer a question WordPress answers (#1313 PR 2).
	 *
	 * @var list<string>
	 */
	public const COLUMNS = array( 'cpf_hash', 'rf_hash' );

	/**
	 * Accounts per statement when asking about a set of them.
	 *
	 * The findings are capped by the caller, so this only ever bounds how wide
	 * ONE `IN` list gets rather than how many accounts are examined.
	 *
	 * @var int
	 */
	public const USERS_PER_STATEMENT = 500;

	/**
	 * The stores that carry an identifier alongside a `user_id`.
	 *
	 * Resolved against the live schema rather than assumed: a site that never
	 * activated recruitment has no candidate table, and a `UNION` naming a
	 * missing table fails the whole query rather than skipping it.
	 *
	 * @return list<string>
	 */
	public static function all(): array {
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
	public static function label( string $table ): string {
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
	 * The stores carrying a ciphertext beside the identifier.
	 *
	 * Probed rather than listed, for the reason {@see self::all()} probes
	 * the tables. `ffc_user_profiles` is the miss here and it is structural,
	 * not incidental: it is the identity index, and it declares `cpf_hash` and
	 * `rf_hash` with no `*_encrypted` column at all.
	 *
	 * @param string $cipher_column Ciphertext column to require.
	 * @return list<string>
	 */
	public static function with_ciphertext( string $cipher_column ): array {
		global $wpdb;

		$out = array();

		if ( '' === $cipher_column ) {
			return $out;
		}

		foreach ( self::all() as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema probe, as in `stores()`.
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, $cipher_column ) );

			if ( $cipher_column === $found ) {
				$out[] = $table;
			}
		}

		return $out;
	}

	/**
	 * The ciphertext column beside an identifier column.
	 *
	 * @param string $column `cpf_hash` / `rf_hash`.
	 * @return string `cpf_encrypted` / `rf_encrypted`, or '' when unknown.
	 */
	public static function ciphertext_column_of( string $column ): string {
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
	public static function field_key_of( string $column ): string {
		return str_replace( '_hash', '', $column );
	}
}
