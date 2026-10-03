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
 * Stored in the profile's `preferences` JSON beside the dashboard's other
 * notification toggles, under a key that means RECEIVE: absent or true is
 * "send", and only an explicit `false` is an opt-out. Everyone receives by
 * default, which is the legitimate-interest basis the module was agreed on,
 * and every message carries the link that flips it.
 */
final class OptOut {

	/**
	 * Preference key inside `ffc_user_profiles.preferences`.
	 */
	public const PREFERENCE_KEY = 'notify_date_messages';

	/**
	 * The ids, among those given, that opted out.
	 *
	 * One query per batch over the profile table, not one per person.
	 *
	 * @param array<int, int> $user_ids Candidate ids.
	 * @return array<int, true> Opted-out id => true.
	 */
	public static function among( array $user_ids ): array {
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

		$out = array();
		foreach ( (array) $rows as $row ) {
			if ( ! is_array( $row ) || ! is_numeric( $row['user_id'] ?? null ) ) {
				continue;
			}
			if ( self::is_opt_out( $row['preferences'] ?? null ) ) {
				$out[ (int) $row['user_id'] ] = true;
			}
		}
		return $out;
	}

	/**
	 * Whether one person opted out.
	 *
	 * @param int $user_id The person.
	 * @return bool
	 */
	public static function is_opted_out( int $user_id ): bool {
		return isset( self::among( array( $user_id ) )[ $user_id ] );
	}

	/**
	 * Record the choice, keeping every other preference.
	 *
	 * @param int  $user_id The person.
	 * @param bool $receive Whether they receive date messages.
	 * @return bool
	 */
	public static function set( int $user_id, bool $receive ): bool {
		$profile = UserManager::get_profile( $user_id );
		$prefs   = self::decode( $profile['preferences'] ?? null );

		$prefs[ self::PREFERENCE_KEY ] = $receive;

		return UserManager::update_profile( $user_id, array( 'preferences' => $prefs ) );
	}

	/**
	 * Whether a stored preferences value carries the opt-out.
	 *
	 * @param mixed $stored JSON string, or null.
	 * @return bool
	 */
	private static function is_opt_out( $stored ): bool {
		return false === ( self::decode( $stored )[ self::PREFERENCE_KEY ] ?? true );
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
