<?php
/**
 * DangerzoneSettingsAudit
 *
 * Records every change to a setting that deletes or suppresses data (#2340):
 * submission auto-delete and its window, the uninstall wipe, the activity
 * log's switch, retention and record cap, and its audit items. Those are the
 * keys {@see SettingsAjaxEndpoint::dangerzone_settings()} names, so the two
 * writers that gate them also report them.
 *
 * Each change is one `dangerzone_setting_changed` entry naming the setting,
 * the value before and the value after. The entries are flushed before the
 * caller writes the option, so switching the log off is recorded while the
 * log is still on.
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
 * Writes an audit entry per changed Danger Zone setting.
 */
final class DangerzoneSettingsAudit {

	/**
	 * Action written for each change.
	 */
	public const ACTION = 'dangerzone_setting_changed';

	/**
	 * Record what changed between two copies of `ffc_settings`, before the
	 * caller writes the second one.
	 *
	 * A key missing from a copy reads as its declared default, so the first
	 * save of a tab, which writes every key it shows, records only the keys
	 * whose value really moves.
	 *
	 * @param array<string, mixed> $before Settings as stored.
	 * @param array<string, mixed> $after  Settings about to be written.
	 * @return int Entries written.
	 */
	public static function record( array $before, array $after ): int {
		$defaults = self::defaults();
		$written  = 0;
		foreach ( SettingsAjaxEndpoint::dangerzone_settings() as $key ) {
			$from = self::normalize( array_key_exists( $key, $before ) ? $before[ $key ] : ( $defaults[ $key ] ?? null ) );
			$to   = self::normalize( array_key_exists( $key, $after ) ? $after[ $key ] : ( $defaults[ $key ] ?? null ) );
			if ( $from === $to ) {
				continue;
			}
			$logged = ActivityLog::log(
				self::ACTION,
				ActivityLog::LEVEL_WARNING,
				array(
					'setting' => $key,
					'from'    => $from,
					'to'      => $to,
				),
				get_current_user_id()
			);
			if ( $logged ) {
				++$written;
			}
		}
		if ( $written > 0 ) {
			ActivityLog::flush_buffer();
		}

		return $written;
	}

	/**
	 * The declared default of every Danger Zone setting.
	 *
	 * Mirrors `Settings::get_default_settings()` for these keys; the test
	 * that covers this class compares the two, so they cannot drift.
	 *
	 * @return array<string, int>
	 */
	public static function defaults(): array {
		$defaults = array(
			'cleanup_enabled'             => 0,
			'cleanup_days'                => 365,
			'delete_data_on_uninstall'    => 0,
			'enable_activity_log'         => 1,
			'activity_log_retention_days' => 90,
			'activity_log_max_records'    => 100000,
		);
		foreach ( ActivityLogItems::keys_of( ActivityLogItems::GROUP_AUDIT ) as $item ) {
			$defaults[ ActivityLogItems::setting_key( $item ) ] = ActivityLogItems::default_for( $item ) ? 1 : 0;
		}

		return $defaults;
	}

	/**
	 * A stored value as an int, so `true`, `'1'` and `1` compare equal.
	 *
	 * @param mixed $value Stored value.
	 * @return int|null
	 */
	private static function normalize( $value ): ?int {
		if ( null === $value ) {
			return null;
		}
		if ( is_bool( $value ) ) {
			return $value ? 1 : 0;
		}

		return is_numeric( $value ) ? (int) $value : ( empty( $value ) ? 0 : 1 );
	}
}
