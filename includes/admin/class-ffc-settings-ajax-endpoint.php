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
 *   - capability gated on `ffc_manage_settings` (matches the settings page).
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
	 *   - 'cap'    — required capability (default 'ffc_manage_settings').
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
			'send_wp_user_email_submission',
			'send_wp_user_email_appointment',
			'send_wp_user_email_csv_import',
			'send_wp_user_email_migration',
			// URL Shortener tab.
			'url_shortener_enabled',
			'url_shortener_auto_create',
			// Data Migrations tab — URL cleanup criteria (persisted so the
			// last-used selection survives the preview/delete round-trip).
			'url_cleanup_orphaned',
			'url_cleanup_never_clicked',
			'url_cleanup_trashed',
			// Advanced tab — Danger Zone "reset ID counter" default state.
			'dangerzone_reset_counter_default',
			// Audience CSV import — "create users if they don't exist" default.
			'audience_csv_create_users_default',
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
			// Activity-log granular filter — per-category enables.
			'activity_log_cat_submissions',
			'activity_log_cat_scheduling',
			'activity_log_cat_public_access',
			'activity_log_cat_users',
			'activity_log_cat_recruitment',
			'activity_log_cat_migrations',
			'activity_log_cat_system',
			// Danger Zone opt-in: when on, uninstall.php removes ALL plugin
			// data (tables, options, CPT posts, roles, caps). Default OFF
			// matches the WooCommerce / EDD / Yoast convention so plugin
			// deletion never wipes data unintentionally.
			'delete_data_on_uninstall',
		);

		$allowlist = array(
			'admin_bypass_datetime'         => array(
				'option' => 'ffc_geolocation_settings',
				'type'   => 'bool',
				'cap'    => 'ffc_manage_settings',
			),
			'admin_bypass_geo'              => array(
				'option' => 'ffc_geolocation_settings',
				'type'   => 'bool',
				'cap'    => 'ffc_manage_settings',
			),
			'ip_api_enabled'                => array(
				'option' => 'ffc_geolocation_settings',
				'type'   => 'bool',
				'cap'    => 'ffc_manage_settings',
			),
			'ip_api_cascade'                => array(
				'option' => 'ffc_geolocation_settings',
				'type'   => 'bool',
				'cap'    => 'ffc_manage_settings',
			),
			'ip_cache_enabled'              => array(
				'option' => 'ffc_geolocation_settings',
				'type'   => 'bool',
				'cap'    => 'ffc_manage_settings',
			),
			// User Access tab — stored in its own option (ffc_user_access_settings)
			// with flat keys. The JS-side key is prefixed `user_access_*` so it
			// doesn't collide with same-named keys in ffc_settings.
			'user_access_block_wp_admin'    => array(
				'option' => 'ffc_user_access_settings',
				'path'   => array( 'block_wp_admin' ),
				'type'   => 'bool',
				'cap'    => 'ffc_manage_settings',
			),
			'user_access_bypass_for_admins' => array(
				'option' => 'ffc_user_access_settings',
				'path'   => array( 'bypass_for_admins' ),
				'type'   => 'bool',
				'cap'    => 'ffc_manage_settings',
			),
			'user_access_allow_admin_bar'   => array(
				'option' => 'ffc_user_access_settings',
				'path'   => array( 'allow_admin_bar' ),
				'type'   => 'bool',
				'cap'    => 'ffc_manage_settings',
			),
			// SMTP semantic rework — the on-disk slot is `disable_all_emails`
			// (kept for compatibility) but the UI now exposes the inverted
			// "Ativar envios de e-mails" toggle. `invert: true` flips the
			// bool at write time so on=true → disable_all_emails=false.
			'emails_enabled'                => array(
				'option' => 'ffc_settings',
				'path'   => array( 'disable_all_emails' ),
				'type'   => 'bool',
				'invert' => true,
				'cap'    => 'ffc_manage_settings',
			),
		);

		// Recruitment Settings tab (`page=ffc-recruitment&tab=settings`) —
		// stored in its own option (`ffc_recruitment_settings`) and gated
		// by the recruitment-specific cap. Keys are prefixed `recruitment_*`
		// to avoid colliding with same-named slots in other options.
		$recruitment_bools = array(
			'preview_reason_required_denied',
			'preview_reason_required_granted',
			'preview_reason_required_appeal_denied',
			'preview_reason_required_appeal_granted',
			'audit_pii_reveals',
		);
		foreach ( $recruitment_bools as $field ) {
			$allowlist[ 'recruitment_' . $field ] = array(
				'option' => 'ffc_recruitment_settings',
				'path'   => array( $field ),
				'type'   => 'bool',
				'cap'    => 'ffc_manage_recruitment',
			);
		}

		foreach ( $bool_settings as $key ) {
			$allowlist[ $key ] = array(
				'option' => 'ffc_settings',
				'type'   => 'bool',
				'cap'    => 'ffc_manage_settings',
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
			// Read-endpoint group (#259).
			'read_respect_whitelist'           => array( 'read', 'respect_whitelist' ),
			'read_bypass_logged_in'            => array( 'read', 'bypass_logged_in' ),
			// Whitelist / Blacklist card UI-visibility toggles. The flag
			// only collapses the card on the settings page — the lists
			// continue to apply at runtime when populated.
			'whitelist_enabled'                => array( 'whitelist', 'enabled' ),
			'blacklist_enabled'                => array( 'blacklist', 'enabled' ),
			// Logging group.
			'logging_enabled'                  => array( 'logging', 'enabled' ),
			'logging_log_allowed'              => array( 'logging', 'log_allowed' ),
			'logging_log_blocked'              => array( 'logging', 'log_blocked' ),
			// UI / display group.
			'ui_show_remaining'                => array( 'ui', 'show_remaining' ),
			'ui_show_wait_time'                => array( 'ui', 'show_wait_time' ),
			'ui_countdown_timer'               => array( 'ui', 'countdown_timer' ),
		);

		// Per-endpoint enable toggles under `read.endpoints.<ep>.enabled`.
		// Keep the endpoint list aligned with TabRateLimit::$defaults['read']['endpoints'].
		foreach ( array( 'calendar_slots', 'calendar_list', 'calendar_detail' ) as $ffc_ep ) {
			$rate_limit_paths[ 'read_endpoint_' . $ffc_ep . '_enabled' ] = array( 'read', 'endpoints', $ffc_ep, 'enabled' );
		}

		foreach ( $rate_limit_paths as $key => $path ) {
			$allowlist[ $key ] = array(
				'option' => 'ffc_rate_limit_settings',
				'path'   => $path,
				'type'   => 'bool',
				'cap'    => 'ffc_manage_settings',
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
			// Data Migrations tab — "never clicked … days ago" grace window.
			'url_cleanup_days'            => array(
				'int',
				array(
					'min' => 1,
					'max' => 3650,
				),
			),
			// Activity-log granular filter — minimum level. Validated again on
			// read by SettingsReader::activity_log_min_level().
			'activity_log_min_level'      => array(
				'string',
				array(),
			),
			// Newline/comma list of required {{tags}} for PDF layouts —
			// read by SettingsReader::required_certificate_tags().
			'required_certificate_tags'   => array(
				'string',
				array( 'as' => 'multiline_text' ),
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
					'cap'    => 'ffc_manage_settings',
				),
				$extra
			);
		}

		// Rate-limit nested non-boolean fields.
		$rate_limit_typed = array(
			'ip_max_per_hour'         => array(
				array( 'ip', 'max_per_hour' ),
				'int',
				array(
					'min' => 1,
					'max' => 1000,
				),
			),
			'ip_max_per_day'          => array(
				array( 'ip', 'max_per_day' ),
				'int',
				array(
					'min' => 1,
					'max' => 10000,
				),
			),
			'ip_cooldown_seconds'     => array(
				array( 'ip', 'cooldown_seconds' ),
				'int',
				array(
					'min' => 1,
					'max' => 3600,
				),
			),
			'ip_apply_to'             => array( array( 'ip', 'apply_to' ), 'string', array() ),
			'ip_message'              => array( array( 'ip', 'message' ), 'string', array( 'as' => 'multiline_text' ) ),
			'email_max_per_day'       => array( array( 'email', 'max_per_day' ), 'int', array( 'min' => 1 ) ),
			'email_max_per_week'      => array( array( 'email', 'max_per_week' ), 'int', array( 'min' => 1 ) ),
			'email_max_per_month'     => array( array( 'email', 'max_per_month' ), 'int', array( 'min' => 1 ) ),
			'email_message'           => array( array( 'email', 'message' ), 'string', array( 'as' => 'multiline_text' ) ),
			'cpf_max_per_month'       => array( array( 'cpf', 'max_per_month' ), 'int', array( 'min' => 1 ) ),
			'cpf_max_per_year'        => array( array( 'cpf', 'max_per_year' ), 'int', array( 'min' => 1 ) ),
			'cpf_block_threshold'     => array( array( 'cpf', 'block_threshold' ), 'int', array( 'min' => 1 ) ),
			'cpf_block_hours'         => array( array( 'cpf', 'block_hours' ), 'int', array( 'min' => 1 ) ),
			'cpf_block_duration'      => array( array( 'cpf', 'block_duration' ), 'int', array( 'min' => 1 ) ),
			'cpf_message'             => array( array( 'cpf', 'message' ), 'string', array( 'as' => 'multiline_text' ) ),
			'global_max_per_minute'   => array( array( 'global', 'max_per_minute' ), 'int', array( 'min' => 1 ) ),
			'global_max_per_hour'     => array( array( 'global', 'max_per_hour' ), 'int', array( 'min' => 1 ) ),
			'global_message'          => array( array( 'global', 'message' ), 'string', array( 'as' => 'multiline_text' ) ),
			'device_max_per_form'     => array(
				array( 'device', 'max_per_form' ),
				'int',
				array(
					'min' => 1,
					'max' => 100,
				),
			),
			'device_match_threshold'  => array(
				array( 'device', 'match_threshold' ),
				'int',
				array(
					'min' => 3,
					'max' => 12,
				),
			),
			'device_match_strong_min' => array(
				array( 'device', 'match_strong_min' ),
				'int',
				array(
					'min' => 0,
					'max' => 6,
				),
			),
			'device_retention_days'   => array(
				array( 'device', 'retention_days' ),
				'int',
				array(
					'min' => 1,
					'max' => 3650,
				),
			),
			'device_message'          => array( array( 'device', 'message' ), 'string', array( 'as' => 'multiline_text' ) ),
			'logging_retention_days'  => array( array( 'logging', 'retention_days' ), 'int', array( 'min' => 1 ) ),
			'logging_max_logs'        => array( array( 'logging', 'max_logs' ), 'int', array( 'min' => 100 ) ),
			'read_message'            => array( array( 'read', 'message' ), 'string', array( 'as' => 'multiline_text' ) ),
		);

		// Per-endpoint numeric thresholds under read.endpoints.<ep>.{max_per_minute,max_per_hour}.
		foreach ( array( 'calendar_slots', 'calendar_list', 'calendar_detail' ) as $ffc_ep ) {
			$rate_limit_typed[ 'read_endpoint_' . $ffc_ep . '_max_per_minute' ] = array(
				array( 'read', 'endpoints', $ffc_ep, 'max_per_minute' ),
				'int',
				array( 'min' => 0 ),
			);
			$rate_limit_typed[ 'read_endpoint_' . $ffc_ep . '_max_per_hour' ]   = array(
				array( 'read', 'endpoints', $ffc_ep, 'max_per_hour' ),
				'int',
				array( 'min' => 0 ),
			);
		}

		foreach ( $rate_limit_typed as $key => list( $path, $type, $extra ) ) {
			$allowlist[ $key ] = array_merge(
				array(
					'option' => 'ffc_rate_limit_settings',
					'path'   => $path,
					'type'   => $type,
					'cap'    => 'ffc_manage_settings',
				),
				$extra
			);
		}

		// `device.signals_enabled` — multi-select stored as `string[]`. The
		// `options` whitelist mirrors the registry in TabRateLimit::save_settings()
		// so a tampered POST can't store unsupported signal names.
		$allowlist['device_signals_enabled'] = array(
			'option'  => 'ffc_rate_limit_settings',
			'path'    => array( 'device', 'signals_enabled' ),
			'type'    => 'string[]',
			'options' => array( 'cookie', 'ua', 'screen', 'tz', 'concurrency', 'memory', 'canvas', 'audio', 'webgl', 'fonts', 'plugins', 'permissions', 'mediaqueries', 'math' ),
			'cap'     => 'ffc_manage_settings',
		);

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
		$cap   = $entry['cap'] ?? 'ffc_manage_settings';
		if ( ! current_user_can( $cap ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to change this setting.', 'ffcertificate' ) ), 403 );
		}

		$raw_value = wp_unslash( $_POST['value'] ?? '' );
		$value     = self::sanitize_value( $raw_value, $entry['type'] ?? 'bool', $entry );

		// Optional bool inversion — the SMTP tab's "Ativar envios" toggle is
		// stored on disk as `disable_all_emails` for historical reasons, so the
		// UI flips the semantics. Only meaningful for bool entries.
		if ( ! empty( $entry['invert'] ) && is_bool( $value ) ) {
			$value = ! $value;
		}

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

			case 'string[]':
				// Empty selection arrives as '' (jQuery omits empty arrays
				// from $.param). Treat missing as empty list, not invalid.
				if ( '' === $raw ) {
					$raw = array();
				}
				if ( ! is_array( $raw ) ) {
					return array();
				}
				$values = array_map( 'sanitize_key', array_map( 'strval', $raw ) );
				$values = array_values(
					array_unique(
						array_filter(
							$values,
							static fn( string $v ): bool => '' !== $v
						)
					)
				);
				if ( ! empty( $entry['options'] ) && is_array( $entry['options'] ) ) {
					// Intersect with the registered allowlist so a client can't
					// inject signal names the server doesn't understand.
					$values = array_values( array_intersect( $entry['options'], $values ) );
				}
				return $values;

			default:
				return null;
		}
	}
}
