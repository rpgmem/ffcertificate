<?php
/**
 * Generic admin AJAX endpoint for incremental settings updates.
 *
 * Lets the admin save a single allowlisted setting without the
 * round-trip of the full settings-page form POST. Used by the auto-save
 * widget (`FFC.Admin.autoSaveField`) for boolean toggles like
 * `admin_bypass_datetime` / `admin_bypass_geo` and for per-row CRUD on
 * the geofence locations table.
 *
 * Security:
 *   - nonce verified against the action name (matches the FFC.request
 *     helper which passes `nonce: window.ffc_ajax.nonce`).
 *   - capability gated on `manage_options` (matches the settings page).
 *   - keys live in a hardcoded allowlist; no arbitrary option writes.
 *   - each allowlisted key has its own value type + sanitisation.
 *
 * @package FreeFormCertificate\Admin
 * @since 6.5.4
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generic AJAX endpoint for allowlisted settings updates.
 */
class SettingsAjaxEndpoint {

	/**
	 * AJAX action name (matches the JS-side FFC.request call).
	 */
	public const AJAX_ACTION = 'ffc_update_setting';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( self::class, 'handle' ) );
	}

	/**
	 * Allowlist of writable keys for this endpoint.
	 *
	 * Each entry resolves to:
	 *   - 'option' — the WP option name that stores the parent array
	 *                ('ffc_settings', 'ffc_geolocation_settings', etc.).
	 *   - 'path'   — optional array<int,string> of nested keys inside
	 *                the option array. When omitted the key is treated
	 *                as a flat top-level slot (i.e. `path = [ $key ]`).
	 *                Use this for tabs that store settings nested by
	 *                group, e.g. ffc_rate_limit_settings → ip → enabled.
	 *   - 'type'   — value type: 'bool' for checkbox-like toggles.
	 *   - 'cap'    — required capability (default 'manage_options').
	 *
	 * Adding a new auto-saveable field is a single-line append here.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function allowlist(): array {
		$bool_settings = array(
			// Cache tab (ffc_settings).
			'cache_enabled',
			'cache_auto_warm',
			'qr_cache_enabled',
			// SMTP tab.
			'disable_all_emails',
			// Advanced tab — activity log master switch + per-module debug.
			'enable_activity_log',
			'debug_pdf_generator',
			'debug_email_handler',
			'debug_form_processor',
			'debug_encryption',
			'debug_geofence',
			'debug_user_manager',
			'debug_rest_api',
			'debug_migrations',
			'debug_activity_log',
			'debug_frontend',
			'debug_admin',
			'debug_self_scheduling',
			'debug_audience',
			'debug_qrcode',
			// 6.6.4 follow-up (#361 Sprint 1).
			'debug_browser_env',
		);

		$allowlist = array(
			'admin_bypass_datetime' => array(
				'option' => 'ffc_geolocation_settings',
				'type'   => 'bool',
				'cap'    => 'manage_options',
			),
			'admin_bypass_geo'      => array(
				'option' => 'ffc_geolocation_settings',
				'type'   => 'bool',
				'cap'    => 'manage_options',
			),
		);

		foreach ( $bool_settings as $key ) {
			$allowlist[ $key ] = array(
				'option' => 'ffc_settings',
				'type'   => 'bool',
				'cap'    => 'manage_options',
			);
		}

		// Rate-limit tab stores feature toggles nested by group inside
		// ffc_rate_limit_settings. The JS-side key matches the form
		// input `name` for legibility; the `path` walks the array.
		$rate_limit_paths = array(
			'ip_enabled'                       => array( 'ip', 'enabled' ),
			'email_enabled'                    => array( 'email', 'enabled' ),
			'email_check_database'             => array( 'email', 'check_database' ),
			'cpf_enabled'                      => array( 'cpf', 'enabled' ),
			'cpf_check_database'               => array( 'cpf', 'check_database' ),
			'global_enabled'                   => array( 'global', 'enabled' ),
			'device_enabled'                   => array( 'device', 'enabled' ),
			'device_bypass_logged_in_managers' => array( 'device', 'bypass_logged_in_managers' ),
			'device_log_blocks'                => array( 'device', 'log_blocks' ),
		);

		foreach ( $rate_limit_paths as $key => $path ) {
			$allowlist[ $key ] = array(
				'option' => 'ffc_rate_limit_settings',
				'path'   => $path,
				'type'   => 'bool',
				'cap'    => 'manage_options',
			);
		}

		// Non-boolean ffc_settings fields. Each tuple is
		// [ type, extra ] — extra may carry min/max (for int) or
		// as=url/multiline_text (for string).
		$flat_typed = array(
			// Cache tab.
			'cache_expiration'            => array(
				'int',
				array(
					'min' => 60,
					'max' => 604800,
				),
			),
			// Advanced tab.
			'activity_log_retention_days' => array(
				'int',
				array(
					'min' => 0,
					'max' => 3650,
				),
			),
			'public_csv_sync_max_rows'    => array(
				'int',
				array(
					'min' => 100,
					'max' => 50000,
				),
			),
			'public_csv_default_limit'    => array(
				'int',
				array(
					'min' => 1,
					'max' => 1000,
				),
			),
			'code_editor_theme'           => array( 'string', array() ),
			// General tab.
			'dark_mode'                   => array( 'string', array() ),
			'cleanup_days'                => array(
				'int',
				array(
					'min' => 0,
					'max' => 3650,
				),
			),
			'date_format'                 => array( 'string', array() ),
			'date_format_custom'          => array( 'string', array() ),
			// #244 — time format + per-context PDF overrides.
			// `*_pdf_custom` companions (#248) carry the user-typed
			// format when `*_format_pdf === 'custom'`.
			'time_format'                 => array( 'string', array() ),
			'time_format_custom'          => array( 'string', array() ),
			'date_format_pdf'             => array( 'string', array() ),
			'date_format_pdf_custom'      => array( 'string', array() ),
			'time_format_pdf'             => array( 'string', array() ),
			'time_format_pdf_custom'      => array( 'string', array() ),
			'main_address'                => array( 'string', array() ),
			'csv_download_page_url'       => array( 'string', array( 'as' => 'url' ) ),
			'qr_default_size'             => array(
				'int',
				array(
					'min' => 100,
					'max' => 500,
				),
			),
			'qr_default_margin'           => array(
				'int',
				array(
					'min' => 0,
					'max' => 10,
				),
			),
			'qr_default_error_level'      => array( 'string', array() ),
		);

		foreach ( $flat_typed as $key => list( $type, $extra ) ) {
			$allowlist[ $key ] = array_merge(
				array(
					'option' => 'ffc_settings',
					'type'   => $type,
					'cap'    => 'manage_options',
				),
				$extra
			);
		}

		// Rate-limit nested non-boolean fields.
		$rate_limit_typed = array(
			'ip_max_per_hour'        => array(
				array( 'ip', 'max_per_hour' ),
				'int',
				array(
					'min' => 1,
					'max' => 1000,
				),
			),
			'ip_max_per_day'         => array(
				array( 'ip', 'max_per_day' ),
				'int',
				array(
					'min' => 1,
					'max' => 10000,
				),
			),
			'ip_cooldown_seconds'    => array(
				array( 'ip', 'cooldown_seconds' ),
				'int',
				array(
					'min' => 1,
					'max' => 3600,
				),
			),
			'ip_apply_to'            => array( array( 'ip', 'apply_to' ), 'string', array() ),
			'ip_message'             => array( array( 'ip', 'message' ), 'string', array( 'as' => 'multiline_text' ) ),
			'email_max_per_day'      => array( array( 'email', 'max_per_day' ), 'int', array( 'min' => 1 ) ),
			'email_max_per_week'     => array( array( 'email', 'max_per_week' ), 'int', array( 'min' => 1 ) ),
			'email_max_per_month'    => array( array( 'email', 'max_per_month' ), 'int', array( 'min' => 1 ) ),
			'email_message'          => array( array( 'email', 'message' ), 'string', array( 'as' => 'multiline_text' ) ),
			'cpf_max_per_month'      => array( array( 'cpf', 'max_per_month' ), 'int', array( 'min' => 1 ) ),
			'cpf_max_per_year'       => array( array( 'cpf', 'max_per_year' ), 'int', array( 'min' => 1 ) ),
			'cpf_block_threshold'    => array( array( 'cpf', 'block_threshold' ), 'int', array( 'min' => 1 ) ),
			'cpf_block_hours'        => array( array( 'cpf', 'block_hours' ), 'int', array( 'min' => 1 ) ),
			'cpf_block_duration'     => array( array( 'cpf', 'block_duration' ), 'int', array( 'min' => 1 ) ),
			'cpf_message'            => array( array( 'cpf', 'message' ), 'string', array( 'as' => 'multiline_text' ) ),
			'global_max_per_minute'  => array( array( 'global', 'max_per_minute' ), 'int', array( 'min' => 1 ) ),
			'global_max_per_hour'    => array( array( 'global', 'max_per_hour' ), 'int', array( 'min' => 1 ) ),
			'global_message'         => array( array( 'global', 'message' ), 'string', array( 'as' => 'multiline_text' ) ),
			'device_max_per_form'    => array(
				array( 'device', 'max_per_form' ),
				'int',
				array(
					'min' => 1,
					'max' => 100,
				),
			),
			'device_match_threshold' => array(
				array( 'device', 'match_threshold' ),
				'int',
				array(
					'min' => 3,
					'max' => 12,
				),
			),
			'device_retention_days'  => array(
				array( 'device', 'retention_days' ),
				'int',
				array(
					'min' => 1,
					'max' => 3650,
				),
			),
			'device_message'         => array( array( 'device', 'message' ), 'string', array( 'as' => 'multiline_text' ) ),
			'logging_retention_days' => array( array( 'logging', 'retention_days' ), 'int', array( 'min' => 1 ) ),
			'logging_max_logs'       => array( array( 'logging', 'max_logs' ), 'int', array( 'min' => 100 ) ),
		);

		foreach ( $rate_limit_typed as $key => list( $path, $type, $extra ) ) {
			$allowlist[ $key ] = array_merge(
				array(
					'option' => 'ffc_rate_limit_settings',
					'path'   => $path,
					'type'   => $type,
					'cap'    => 'manage_options',
				),
				$extra
			);
		}

		return $allowlist;
	}

	/**
	 * Handle the AJAX request.
	 *
	 * Responds with `wp_send_json_success` on success and
	 * `wp_send_json_error` on any guard failure. The frontend
	 * `FFC.request` helper translates these into resolved / rejected
	 * promises with the supplied `message` surfacing in the rejection.
	 */
	public static function handle(): void {
		// Nonce — FFC.request passes window.ffc_ajax.nonce, which is
		// the same nonce admin pages use; check against the AJAX action.
		check_ajax_referer( self::AJAX_ACTION, 'nonce' );

		$key = isset( $_POST['key'] ) ? sanitize_key( wp_unslash( $_POST['key'] ) ) : '';
		if ( '' === $key ) {
			wp_send_json_error( array( 'message' => __( 'Missing setting key.', 'ffcertificate' ) ), 400 );
		}

		$allowlist = self::allowlist();
		if ( ! isset( $allowlist[ $key ] ) ) {
			wp_send_json_error( array( 'message' => __( 'This setting is not exposed for incremental updates.', 'ffcertificate' ) ), 403 );
		}

		$entry = $allowlist[ $key ];
		$cap   = $entry['cap'] ?? 'manage_options';
		if ( ! current_user_can( $cap ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to change this setting.', 'ffcertificate' ) ), 403 );
		}

		$raw_value = wp_unslash( $_POST['value'] ?? '' );
		$value     = self::sanitize_value( $raw_value, $entry['type'] ?? 'bool', $entry );

		$option_name = $entry['option'];
		$option      = get_option( $option_name, array() );
		if ( ! is_array( $option ) ) {
			$option = array();
		}

		$path = isset( $entry['path'] ) && is_array( $entry['path'] ) && ! empty( $entry['path'] )
			? $entry['path']
			: array( $key );
		self::set_nested( $option, $path, $value );

		update_option( $option_name, $option );

		wp_send_json_success(
			array(
				'key'   => $key,
				'value' => $value,
			)
		);
	}

	/**
	 * Write $value into $arr at the location described by $path,
	 * creating intermediate associative arrays as needed.
	 *
	 * @param array<string,mixed> $arr   Reference to the parent array.
	 * @param array<int,string>   $path  Ordered list of keys to walk.
	 * @param mixed               $value Value to set at the leaf.
	 */
	private static function set_nested( array &$arr, array $path, $value ): void {
		$cursor = &$arr;
		$last   = array_pop( $path );
		foreach ( $path as $segment ) {
			if ( ! isset( $cursor[ $segment ] ) || ! is_array( $cursor[ $segment ] ) ) {
				$cursor[ $segment ] = array();
			}
			$cursor = &$cursor[ $segment ];
		}
		$cursor[ $last ] = $value;
	}

	/**
	 * Sanitise a value according to its declared type.
	 *
	 * Supported types:
	 *   - 'bool'   — accepts '1' / 'true' / 'on' / 'yes' as true.
	 *   - 'int'    — casts to int; if `$entry['min']` / `$entry['max']`
	 *                are set the value is clamped to that range.
	 *   - 'string' — defaults to `sanitize_text_field`. The `$entry['as']`
	 *                key can switch behaviour: 'url' → `esc_url_raw`,
	 *                'multiline_text' → `sanitize_textarea_field`.
	 *
	 * @param mixed                $raw   Raw POST value (already wp_unslash'd).
	 * @param string               $type  Allowlist entry type.
	 * @param array<string, mixed> $entry Full allowlist entry — used to read optional
	 *                                    `min` / `max` / `as` modifiers. Defaults
	 *                                    to an empty array so the legacy two-arg
	 *                                    callers in tests keep working.
	 * @return mixed Sanitised value or null when the type is unknown.
	 */
	public static function sanitize_value( $raw, string $type, array $entry = array() ) {
		switch ( $type ) {
			case 'bool':
				if ( is_array( $raw ) ) {
					return false;
				}
				$str = strtolower( (string) $raw );
				return in_array( $str, array( '1', 'true', 'on', 'yes' ), true );

			case 'int':
				if ( is_array( $raw ) ) {
					return 0;
				}
				$int = (int) $raw;
				if ( isset( $entry['min'] ) && $int < (int) $entry['min'] ) {
					$int = (int) $entry['min'];
				}
				if ( isset( $entry['max'] ) && $int > (int) $entry['max'] ) {
					$int = (int) $entry['max'];
				}
				return $int;

			case 'string':
				if ( is_array( $raw ) ) {
					return '';
				}
				$as = $entry['as'] ?? 'text';
				if ( 'url' === $as ) {
					return esc_url_raw( (string) $raw );
				}
				if ( 'multiline_text' === $as ) {
					return sanitize_textarea_field( (string) $raw );
				}
				return sanitize_text_field( (string) $raw );

			default:
				return null;
		}
	}
}
