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
		$value     = self::sanitize_value( $raw_value, $entry['type'] ?? 'bool' );

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
	 * @param mixed  $raw  Raw POST value (already wp_unslash'd).
	 * @param string $type Allowlist entry type — currently only 'bool'.
	 * @return mixed Sanitised value.
	 */
	public static function sanitize_value( $raw, string $type ) {
		switch ( $type ) {
			case 'bool':
				// Accept '1', 'true', 'on' as truthy; everything else false.
				if ( is_array( $raw ) ) {
					return false;
				}
				$str = strtolower( (string) $raw );
				return in_array( $str, array( '1', 'true', 'on', 'yes' ), true );
			default:
				return null;
		}
	}
}
