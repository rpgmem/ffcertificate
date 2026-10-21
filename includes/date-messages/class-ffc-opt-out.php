<?php
/**
 * Date-message opt-out.
 *
 * @package FreeFormCertificate\DateMessages
 * @since 6.33.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\DateMessages;

use FreeFormCertificate\UserDashboard\UserManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether a person has chosen not to receive date messages (#1538).
 *
 * Two categories, each its own key in the profile's `preferences` JSON beside
 * the dashboard's other notification toggles. Both keys mean RECEIVE: absent or
 * true is "send", and only an explicit `false` is an opt-out. Everyone receives
 * by default, which is the legitimate-interest basis the module was agreed on,
 * and every message carries the link that flips its own category.
 *
 * - `personal`     -- dated messages (birthdays): the key this module shipped with.
 * - `announcement` -- one-off announcements to an audience (#1949). Kept apart so
 *   a person who turned off birthday greetings still gets a notice they were
 *   asked to receive, and the reverse holds too.
 */
final class OptOut {

	/**
	 * Preference key of the personal category, inside `ffc_user_profiles.preferences`.
	 */
	public const PREFERENCE_KEY = 'notify_date_messages';

	/**
	 * The ids, among those given, that opted out of a category.
	 *
	 * One query per batch over the profile table, not one per person.
	 *
	 * @param array<int, int> $user_ids Candidate ids.
	 * @param string          $category One of OptOutCategory::all().
	 * @return array<int, true> Opted-out id => true.
	 */
	public static function among( array $user_ids, string $category = OptOutCategory::PERSONAL ): array {
		$user_ids = array_values( array_filter( array_map( 'intval', $user_ids ), static fn( int $id ): bool => $id > 0 ) );
		if ( array() === $user_ids ) {
			return array();
		}

		global $wpdb;
		$placeholders = implode( ', ', array_fill( 0, count( $user_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The plugin's own ffc_user_profiles table, which WordPress has no API for; the interpolation is a list of %d placeholders and every id is bound. A send must see the live preference, never a cache.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, preferences FROM %i WHERE user_id IN ({$placeholders})",
				array_merge( array( $wpdb->prefix . 'ffc_user_profiles' ), $user_ids )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$key = OptOutCategory::key( $category );
		$out = array();
		foreach ( (array) $rows as $row ) {
			if ( ! is_array( $row ) || ! is_numeric( $row['user_id'] ?? null ) ) {
				continue;
			}
			if ( self::is_opt_out( $row['preferences'] ?? null, $key ) ) {
				$out[ (int) $row['user_id'] ] = true;
			}
		}
		return $out;
	}

	/**
	 * Whether one person opted out of a category.
	 *
	 * @param int    $user_id  The person.
	 * @param string $category One of OptOutCategory::all().
	 * @return bool
	 */
	public static function is_opted_out( int $user_id, string $category = OptOutCategory::PERSONAL ): bool {
		return isset( self::among( array( $user_id ), $category )[ $user_id ] );
	}

	/**
	 * Record the choice in one category, keeping every other preference.
	 *
	 * @param int    $user_id  The person.
	 * @param bool   $receive  Whether they receive that category.
	 * @param string $category One of OptOutCategory::all().
	 * @return bool
	 */
	public static function set( int $user_id, bool $receive, string $category = OptOutCategory::PERSONAL ): bool {
		// `update_profile()` merges into the stored preferences (#1545), so
		// only this key is sent and every other choice stays as it was.
		return UserManager::update_profile( $user_id, array( 'preferences' => array( OptOutCategory::key( $category ) => $receive ) ) );
	}

	/**
	 * Whether a stored preferences value carries the opt-out under a key.
	 *
	 * @param mixed  $stored JSON string, or null.
	 * @param string $key    Preference key.
	 * @return bool
	 */
	private static function is_opt_out( $stored, string $key ): bool {
		return false === ( self::decode( $stored )[ $key ] ?? true );
	}

	/**
	 * Decode a stored preferences value.
	 *
	 * @param mixed $stored JSON string, or null.
	 * @return array<string, mixed>
	 */
	private static function decode( $stored ): array {
		if ( ! is_string( $stored ) || '' === $stored ) {
			return array();
		}
		$decoded = json_decode( $stored, true );
		return is_array( $decoded ) ? $decoded : array();
	}
}
