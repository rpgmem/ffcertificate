<?php
/**
 * Geolocation Settings Tab
 *
 * Manages global geolocation and IP geolocation API settings
 *
 * @package FreeFormCertificate\Settings\Tabs
 * @since 3.0.0
 * @version 4.6.16 - Added main_geo_areas (moved from General tab)
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace (Phase 2)
 */

declare(strict_types=1);

namespace FreeFormCertificate\Settings\Tabs;

use FreeFormCertificate\Settings\SettingsTab;
use FreeFormCertificate\Security\GeofenceLocationRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tab Geolocation settings tab.
 */
class TabGeolocation extends SettingsTab {

	/**
	 * Init.
	 */
	protected function init(): void {
		$this->tab_id    = 'geolocation';
		$this->tab_title = __( 'Geolocation', 'ffcertificate' );
		$this->tab_icon  = 'ffc-icon-globe';
		$this->tab_order = 50;

		// Enqueue the preset-toggle script only on this settings tab.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Enqueue admin scripts for the Geolocation settings tab.
	 *
	 * The script hides/shows the per-case fallback table based on the
	 * `When GPS fails` preset combobox and snaps the radios to the
	 * preset's defaults when the admin switches presets.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_scripts( string $hook ): void {
		if ( 'ffc_form_page_ffc-settings' !== $hook ) {
			return;
		}

		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Tab parameter for conditional script loading.
		if ( 'geolocation' !== $active_tab ) {
			return;
		}

		$s = \FreeFormCertificate\Core\Utils::asset_suffix();
		wp_enqueue_script(
			'ffc-geolocation-settings',
			FFC_PLUGIN_URL . "assets/js/ffc-geolocation-settings{$s}.js",
			array( 'jquery' ),
			FFC_VERSION,
			true
		);

		// Pass the preset → cases map to the script so radio snapping
		// stays in sync with PHP without hard-coding it on both sides.
		wp_localize_script(
			'ffc-geolocation-settings',
			'ffcGeolocationSettings',
			array(
				'presetCases' => array(
					'tolerant' => self::preset_to_cases( 'tolerant' ),
					'hybrid'   => self::preset_to_cases( 'hybrid' ),
					'strict'   => self::preset_to_cases( 'strict' ),
				),
			)
		);
	}

	/**
	 * Per-case allow/block keys the frontend honours when GPS fails.
	 *
	 * The presets below derive their cases from this list. New cases get
	 * a default of 'block' (safer side).
	 *
	 * @return array<int, string>
	 */
	public static function gps_fallback_case_keys(): array {
		return array( 'permission_denied', 'no_api', 'position_unavailable', 'timeout', 'safety_timer' );
	}

	/**
	 * Map a preset name to its per-case allow/block matrix.
	 *
	 * Presets:
	 *   - 'tolerant': allow on every failure (legacy 'allow' behaviour).
	 *   - 'hybrid':   allow only when the user explicitly denied access or
	 *                 the browser doesn't expose the geolocation API; block
	 *                 on every technical failure (timeout / unavailable /
	 *                 safety-timer). Default for new installs.
	 *   - 'strict':   block on every failure (legacy 'block' behaviour).
	 *
	 * For the 'custom' preset callers should ignore this helper and read
	 * the persisted gps_fallback_cases directly.
	 *
	 * @param string $preset One of 'tolerant', 'hybrid', 'strict'.
	 * @return array<string, string> case key → 'allow' | 'block'
	 */
	public static function preset_to_cases( string $preset ): array {
		switch ( $preset ) {
			case 'tolerant':
				$allow = self::gps_fallback_case_keys();
				break;
			case 'strict':
				$allow = array();
				break;
			case 'hybrid':
			default:
				$allow = array( 'permission_denied', 'no_api' );
				break;
		}

		$cases = array();
		foreach ( self::gps_fallback_case_keys() as $key ) {
			$cases[ $key ] = in_array( $key, $allow, true ) ? 'allow' : 'block';
		}
		return $cases;
	}

	/**
	 * Derive the preset name from a per-case matrix.
	 *
	 * Returns 'tolerant' / 'hybrid' / 'strict' if the matrix exactly
	 * matches one of those, otherwise 'custom'. Used by the admin UI to
	 * pre-select the right combobox option on page load.
	 *
	 * @param array<string, string> $cases case key → 'allow' | 'block'
	 * @return string
	 */
	public static function cases_to_preset( array $cases ): string {
		foreach ( array( 'tolerant', 'hybrid', 'strict' ) as $preset ) {
			if ( $cases === self::preset_to_cases( $preset ) ) {
				return $preset;
			}
		}
		return 'custom';
	}

	/**
	 * Get default settings
	 *
	 * @return array<string, mixed>
	 */
	private function get_default_settings(): array {
		return array(
			// IP Geolocation API Settings.
			'ip_api_enabled'        => false,
			'ip_api_service'        => 'ip-api', // 'ip-api' or 'ipinfo'
			'ip_api_cascade'        => false, // Use both with fallback.
			'ipinfo_api_key'        => '',
			'ip_cache_enabled'      => true,
			'ip_cache_ttl'          => 600, // 10 minutes in seconds (300-3600)

			// GPS Cache Settings.
			'gps_cache_ttl'         => 600, // 10 minutes in seconds (60-3600)

			// Fallback behavior when API fails.
			'api_fallback'          => 'gps_only', // 'allow', 'block', 'gps_only'

			// Per-case GPS fallback (preset is a UX hint, cases are
			// canonical at runtime). New installs default to 'hybrid';
			// legacy installs are migrated in get_settings().
			'gps_fallback_preset'   => 'hybrid',
			'gps_fallback_cases'    => self::preset_to_cases( 'hybrid' ),

			'both_fail_fallback'    => 'block', // When GPS + IP both fail: 'allow' or 'block'.

			// Admin Bypass (independent of debug mode).
			'admin_bypass_datetime' => false, // Admins bypass datetime restrictions.
			'admin_bypass_geo'      => false, // Admins bypass geolocation restrictions.
		);
	}

	/**
	 * Get current settings
	 *
	 * @return array<string, mixed>
	 */
	private function get_settings(): array {
		$stored = get_option( 'ffc_geolocation_settings', array() );

		// Backward-compat migration: pre-fallback-presets installs stored
		// a single `gps_fallback` string ('allow' | 'block'). Map it to
		// the new preset + cases structure so existing sites keep their
		// behaviour without admin action.
		if ( is_array( $stored ) && isset( $stored['gps_fallback'] ) && ! isset( $stored['gps_fallback_preset'] ) ) {
			$legacy_preset                    = ( 'block' === $stored['gps_fallback'] ) ? 'strict' : 'tolerant';
			$stored['gps_fallback_preset']    = $legacy_preset;
			$stored['gps_fallback_cases']     = self::preset_to_cases( $legacy_preset );
			unset( $stored['gps_fallback'] );
		}

		return wp_parse_args( $stored, $this->get_default_settings() );
	}

	/**
	 * Render tab content
	 */
	public function render(): void {
		// Handle location delete via GET link (before POST check).
		$this->handle_location_delete();

		// Handle form submission.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified below via check_admin_referer.
		if ( $_POST && isset( $_POST['ffc_save_geolocation'] ) ) {
			check_admin_referer( 'ffc_geolocation_nonce' );
			$this->save_settings();
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Geolocation settings saved successfully!', 'ffcertificate' ) . '</p></div>';
		}

		$settings = $this->get_settings();
		include FFC_PLUGIN_DIR . 'includes/settings/views/ffc-tab-geolocation.php';
	}

	/**
	 * Save settings
	 */
	private function save_settings(): void {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in render() via check_admin_referer.
		$ffc_ip_api_service     = sanitize_key( wp_unslash( $_POST['ip_api_service'] ?? '' ) );
		$ffc_api_fallback       = sanitize_key( wp_unslash( $_POST['api_fallback'] ?? '' ) );
		$ffc_both_fail_fallback = sanitize_key( wp_unslash( $_POST['both_fail_fallback'] ?? '' ) );

		// GPS fallback: preset + per-case matrix. For tolerant/hybrid/
		// strict presets the cases snap to the preset defaults; for the
		// 'custom' preset each case is read from POST and validated.
		$preset_raw = sanitize_key( wp_unslash( $_POST['gps_fallback_preset'] ?? '' ) );
		$preset     = in_array( $preset_raw, array( 'tolerant', 'hybrid', 'strict', 'custom' ), true )
			? $preset_raw
			: 'hybrid';
		if ( 'custom' === $preset ) {
			$raw_cases = wp_unslash( $_POST['gps_fallback_cases'] ?? array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value sanitised below.
			if ( ! is_array( $raw_cases ) ) {
				$raw_cases = array();
			}
			$cases = array();
			foreach ( self::gps_fallback_case_keys() as $case_key ) {
				$value             = sanitize_key( $raw_cases[ $case_key ] ?? 'block' );
				$cases[ $case_key ] = in_array( $value, array( 'allow', 'block' ), true ) ? $value : 'block';
			}
		} else {
			$cases = self::preset_to_cases( $preset );
		}

		$settings = array(
			'ip_api_enabled'        => isset( $_POST['ip_api_enabled'] ),
			'ip_api_service'        => in_array( $ffc_ip_api_service, array( 'ip-api', 'ipinfo' ), true )
				? $ffc_ip_api_service
				: 'ip-api',
			'ip_api_cascade'        => isset( $_POST['ip_api_cascade'] ),
			'ipinfo_api_key'        => sanitize_text_field( wp_unslash( $_POST['ipinfo_api_key'] ?? '' ) ),
			'ip_cache_enabled'      => isset( $_POST['ip_cache_enabled'] ),
			'ip_cache_ttl'          => max( 300, min( 3600, absint( wp_unslash( $_POST['ip_cache_ttl'] ?? 600 ) ) ) ),

			'gps_cache_ttl'         => max( 60, min( 3600, absint( wp_unslash( $_POST['gps_cache_ttl'] ?? 600 ) ) ) ),

			'api_fallback'          => in_array( $ffc_api_fallback, array( 'allow', 'block', 'gps_only' ), true )
				? $ffc_api_fallback
				: 'gps_only',
			'gps_fallback_preset'   => $preset,
			'gps_fallback_cases'    => $cases,
			'both_fail_fallback'    => in_array( $ffc_both_fail_fallback, array( 'allow', 'block' ), true )
				? $ffc_both_fail_fallback
				: 'block',

			'admin_bypass_datetime' => isset( $_POST['admin_bypass_datetime'] ),
			'admin_bypass_geo'      => isset( $_POST['admin_bypass_geo'] ),
		);
        // phpcs:enable WordPress.Security.NonceVerification.Missing

		update_option( 'ffc_geolocation_settings', $settings );

		// Handle location CRUD operations.
		$this->save_locations();

		// Log settings change.
		if ( class_exists( '\FreeFormCertificate\Core\ActivityLog' ) ) {
			\FreeFormCertificate\Core\ActivityLog::log_settings_changed( 'geolocation', get_current_user_id() );
		}
	}

	/**
	 * Handle location CRUD operations from POST data.
	 *
	 * Processes new locations, updates to existing locations, and default flag changes.
	 * Nonce is already verified in render() via check_admin_referer.
	 */
	private function save_locations(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in render() via check_admin_referer.

		// Add new location if provided.
		if ( ! empty( $_POST['ffc_location_new'] ) && is_array( $_POST['ffc_location_new'] ) ) {
			$new      = wp_unslash( $_POST['ffc_location_new'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by GeofenceLocationRegistry::save().
			$new_name = trim( sanitize_text_field( $new['name'] ?? '' ) );

			if ( '' !== $new_name ) {
				GeofenceLocationRegistry::save(
					array(
						'name'        => $new_name,
						'lat'         => floatval( $new['lat'] ?? 0 ),
						'lng'         => floatval( $new['lng'] ?? 0 ),
						'radius'      => floatval( $new['radius'] ?? 1000 ),
						'default_gps' => false,
						'default_ip'  => false,
					)
				);
			}
		}

		// Update existing locations.
		if ( ! empty( $_POST['ffc_locations'] ) && is_array( $_POST['ffc_locations'] ) ) {
			$locations      = wp_unslash( $_POST['ffc_locations'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by GeofenceLocationRegistry::save().
			$default_gps_id = sanitize_key( wp_unslash( $_POST['ffc_location_default_gps'] ?? '' ) );
			$default_ip_id  = sanitize_key( wp_unslash( $_POST['ffc_location_default_ip'] ?? '' ) );

			foreach ( $locations as $id => $data ) {
				$id = sanitize_key( $id );

				if ( null === GeofenceLocationRegistry::get_by_id( $id ) ) {
					continue;
				}

				GeofenceLocationRegistry::save(
					array(
						'id'          => $id,
						'name'        => sanitize_text_field( $data['name'] ?? '' ),
						'lat'         => floatval( $data['lat'] ?? 0 ),
						'lng'         => floatval( $data['lng'] ?? 0 ),
						'radius'      => floatval( $data['radius'] ?? 1000 ),
						'default_gps' => ( $id === $default_gps_id ),
						'default_ip'  => ( $id === $default_ip_id ),
					)
				);
			}
		}

		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Handle location deletion via GET link.
	 *
	 * Checks for ffc_delete_location GET parameter with a matching nonce,
	 * deletes the location, and redirects back to the settings page.
	 */
	private function handle_location_delete(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Nonce verified below via wp_verify_nonce.
		if ( empty( $_GET['ffc_delete_location'] ) ) {
			return;
		}

		$id    = sanitize_key( wp_unslash( $_GET['ffc_delete_location'] ) );
		$nonce = sanitize_key( wp_unslash( $_GET['_wpnonce'] ?? '' ) );

		if ( ! wp_verify_nonce( $nonce, 'ffc_delete_location_' . $id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'ffcertificate' ) );
		}

		GeofenceLocationRegistry::delete( $id );

		// Redirect back to remove the query parameters.
		$redirect_url = remove_query_arg( array( 'ffc_delete_location', '_wpnonce' ) );
		wp_safe_redirect( $redirect_url );
		exit;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}
}
