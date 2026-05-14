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
	 *   - 'option'   — the WP option name that stores the parent array
	 *                   ('ffc_geolocation_settings', etc.). Sub-key is the
	 *                   array key inside it.
	 *   - 'type'     — value type: 'bool' for checkbox-like toggles.
	 *   - 'cap'      — required capability (default 'manage_options').
	 *
	 * Adding a new auto-saveable field is a single-line append here.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function allowlist(): array {
		return array(
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
		$option[ $key ] = $value;
		update_option( $option_name, $option );

		wp_send_json_success(
			array(
				'key'   => $key,
				'value' => $value,
			)
		);
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
