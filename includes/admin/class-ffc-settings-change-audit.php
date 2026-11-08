<?php
/**
 * SettingsChangeAudit
 *
 * Records which settings changed outside the Danger Zone (#2340): one
 * `settings_changed` entry per save, naming the option and the keys that
 * moved. It is a secondary activity-log item, off by default.
 *
 * Only the key names are written, never the values: an option can hold a
 * secret (the SMTP password, an API key), and the log is read by more people
 * than the settings are. A Danger Zone key is left out here because
 * {@see DangerzoneSettingsAudit} records it, with its values, as an audit item.
 *
 * @package FreeFormCertificate\Admin
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

use FreeFormCertificate\Core\ActivityLog;
use FreeFormCertificate\Core\ActivityLogItems;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes one entry per save naming the settings it changed.
 */
final class SettingsChangeAudit {

	/**
	 * Action written for each save.
	 */
	public const ACTION = 'settings_changed';

	/**
	 * Record the top-level keys of an option that differ between two copies.
	 *
	 * @param string               $option Option name.
	 * @param array<string, mixed> $before Option as stored.
	 * @param array<string, mixed> $after  Option about to be written.
	 * @return bool Whether an entry was written.
	 */
	public static function record( string $option, array $before, array $after ): bool {
		$keys = array();
		foreach ( array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) ) as $key ) {
			$key = (string) $key;
			if ( ( $before[ $key ] ?? null ) != ( $after[ $key ] ?? null ) ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- '1' and 1 are one stored value; a type change alone is no edit.
				$keys[] = $key;
			}
		}

		return self::record_keys( $option, $keys );
	}

	/**
	 * Record a save that changed the named keys of an option.
	 *
	 * @param string             $option Option name.
	 * @param array<int, string> $keys   Keys that changed.
	 * @return bool Whether an entry was written.
	 */
	public static function record_keys( string $option, array $keys ): bool {
		if ( 'ffc_settings' === $option ) {
			$excluded = array_merge( SettingsAjaxEndpoint::dangerzone_settings(), array( ActivityLogItems::MIGRATED_KEY ) );
			$keys     = array_diff( $keys, $excluded );
		}
		$keys = array_values( array_unique( $keys ) );
		if ( array() === $keys ) {
			return false;
		}
		sort( $keys );

		return ActivityLog::log(
			self::ACTION,
			ActivityLog::LEVEL_INFO,
			array(
				'option' => $option,
				'keys'   => $keys,
			),
			get_current_user_id()
		);
	}
}
