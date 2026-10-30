<?php
/**
 * GeofenceWindowMessages
 *
 * The three messages a form shows outside its date/time window (#2241):
 * before it opens, after it closes, and outside the daily hours. One
 * message used to serve all three, so "Opens on 10 Nov" still showed after
 * the form had closed.
 *
 * @package FreeFormCertificate\Security
 * @since 6.43.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves the message for each moment and copies the old single message
 * into the three, once.
 */
final class GeofenceWindowMessages {

	/**
	 * Option set once the single message has been copied into the three.
	 */
	public const MIGRATION_FLAG = 'ffc_geofence_window_messages_v1';

	/**
	 * The form's single message before #2241. Still written by the content
	 * shortcode, whose one message covers every moment.
	 */
	public const LEGACY_KEY = 'msg_datetime';

	/**
	 * Config key per moment. The moments are the phases the date/time check
	 * reports, the same ones the per-phase hide modes use (#159).
	 */
	public const KEYS = array(
		'before' => 'msg_before_open',
		'after'  => 'msg_after_close',
		'during' => 'msg_outside_hours',
	);

	/**
	 * The message for one moment.
	 *
	 * A config that carries the moment's key uses it, and its default when
	 * it is empty. A config without it (a form not yet copied, the content
	 * shortcode) uses the single message.
	 *
	 * @param array<string, mixed> $config Geofence config.
	 * @param string               $phase  `before`, `after` or `during`.
	 * @return string Never empty.
	 */
	public static function message( array $config, string $phase ): string {
		$key   = self::KEYS[ $phase ] ?? self::KEYS['before'];
		$value = array_key_exists( $key, $config ) ? $config[ $key ] : ( $config[ self::LEGACY_KEY ] ?? '' );
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		return '' !== $value ? $value : self::default_message( $phase );
	}

	/**
	 * Every moment's message, for the browser.
	 *
	 * @param array<string, mixed> $config Geofence config.
	 * @return array{before: string, after: string, during: string}
	 */
	public static function all( array $config ): array {
		return array(
			'before' => self::message( $config, 'before' ),
			'after'  => self::message( $config, 'after' ),
			'during' => self::message( $config, 'during' ),
		);
	}

	/**
	 * The default for a moment, shown when its message is empty.
	 *
	 * @param string $phase `before`, `after` or `during`.
	 * @return string
	 */
	public static function default_message( string $phase ): string {
		if ( 'after' === $phase ) {
			return __( 'This form is no longer available.', 'ffcertificate' );
		}
		if ( 'during' === $phase ) {
			return __( 'This form is only available during specific hours.', 'ffcertificate' );
		}
		return __( 'This form is not yet available.', 'ffcertificate' );
	}

	/**
	 * A config with the single message copied into the moments it lacks.
	 *
	 * @param array<string, mixed> $config Geofence config.
	 * @return array<string, mixed>|null The new config, or null when there is nothing to copy.
	 */
	public static function split( array $config ): ?array {
		if ( ! array_key_exists( self::LEGACY_KEY, $config ) ) {
			return null;
		}
		$legacy = is_scalar( $config[ self::LEGACY_KEY ] ) ? (string) $config[ self::LEGACY_KEY ] : '';
		foreach ( self::KEYS as $key ) {
			if ( ! array_key_exists( $key, $config ) ) {
				$config[ $key ] = $legacy;
			}
		}
		unset( $config[ self::LEGACY_KEY ] );
		return $config;
	}

	/**
	 * Copy every form's single message into the three moments, once.
	 *
	 * Runs on `init` (see the Loader), so the forms read the same text the
	 * moment it ran; nothing changes until an administrator edits them.
	 */
	public static function maybe_migrate(): void {
		self::migrate();
	}

	/**
	 * The copy itself, flag check included.
	 *
	 * @return int Forms rewritten; 0 once the flag is set.
	 */
	public static function migrate(): int {
		if ( '1' === get_option( self::MIGRATION_FLAG, '' ) ) {
			return 0;
		}

		$ids = get_posts(
			array(
				'post_type'      => 'ffc_form',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		$written = 0;
		foreach ( $ids as $id ) {
			$config = get_post_meta( (int) $id, '_ffc_geofence_config', true );
			if ( ! is_array( $config ) ) {
				continue;
			}
			$split = self::split( $config );
			if ( null !== $split ) {
				update_post_meta( (int) $id, '_ffc_geofence_config', $split );
				++$written;
			}
		}

		update_option( self::MIGRATION_FLAG, '1', true );
		return $written;
	}
}
