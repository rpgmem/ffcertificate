<?php
/**
 * Rate Limit Settings Tab
 *
 * @package FreeFormCertificate\Settings\Tabs
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace
 */

declare(strict_types=1);

namespace FreeFormCertificate\Settings\Tabs;

use FreeFormCertificate\Settings\SettingsTab;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tab Rate Limit settings tab.
 */
class TabRateLimit extends SettingsTab {

	/**
	 * Init.
	 */
	protected function init(): void {
		$this->tab_id    = 'rate_limit';
		$this->tab_group = 'security';
		$this->tab_title = __( 'Rate Limit', 'ffcertificate' );
		$this->tab_icon  = 'ffc-icon-shield';
		$this->tab_order = 40;

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Enqueue auto-save infrastructure when this tab is active. Powers
	 * the `.ffc-toggle` switches for the per-group feature flags
	 * (ip/email/cpf/global/device "enabled" + their satellites).
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_scripts( string $hook ): void {
		if ( ! $this->should_enqueue_on( $hook ) ) {
			return;
		}
		$this->enqueue_autosave_infra();
	}

	/**
	 * Get settings.
	 *
	 * @return array<string, mixed>
	 */
	private function get_settings(): array {
		$defaults = array(
			'ip'        => array(
				'enabled'                => true,
				'max_per_hour'           => 5,
				'max_per_day'            => 20,
				'cooldown_seconds'       => 60,
				'apply_to'               => 'all',
				'message'                => __( 'Limit reached. Please wait {time}.', 'ffcertificate' ),
				// Challenges the ALTCHA endpoint issues per address per
				// window (#1111). Lives in the IP group because that is
				// what it limits, and next to the submission cap an
				// administrator raises for the same NAT reason. 0 = no cap.
				'captcha_max_per_window' => \FreeFormCertificate\Core\Captcha\CaptchaSettings::MINT_CAP_DEFAULT,
				'captcha_window_seconds' => \FreeFormCertificate\Core\Captcha\CaptchaSettings::MINT_WINDOW_DEFAULT,
			),
			'email'     => array(
				'enabled'        => true,
				'max_per_day'    => 3,
				'max_per_week'   => 10,
				'max_per_month'  => 30,
				'wait_hours'     => 24,
				'apply_to'       => 'all',
				'message'        => __( 'You already have {count} certificates.', 'ffcertificate' ),
				'check_database' => true,
			),
			'cpf'       => array(
				'enabled'         => false,
				'max_per_month'   => 5,
				'max_per_year'    => 50,
				'block_threshold' => 3,
				'block_hours'     => 1,
				'block_duration'  => 24,
				'apply_to'        => 'all',
				'message'         => __( 'CPF/RF limit reached.', 'ffcertificate' ),
				'check_database'  => true,
			),
			'global'    => array(
				'enabled'        => false,
				'max_per_minute' => 100,
				'max_per_hour'   => 1000,
				'message'        => __( 'System unavailable.', 'ffcertificate' ),
			),
			'device'    => array(
				'enabled'                   => false,
				'max_per_form'              => 1,
				'match_threshold'           => 7,
				'match_strong_min'          => 2,
				'signals_enabled'           => array( 'cookie', 'ua', 'screen', 'tz', 'concurrency', 'memory', 'canvas', 'audio', 'webgl', 'fonts', 'plugins', 'permissions', 'mediaqueries', 'math' ),
				'bypass_logged_in_managers' => true,
				'bypass_whitelist_signals'  => array(),
				'message'                   => __( 'Multiple submissions detected from this device. Please contact the organizer.', 'ffcertificate' ),
				'retention_days'            => 90,
				'log_blocks'                => true,
			),
			'whitelist' => array(
				// UI visibility flag — when false the rate-limit settings
				// page collapses the Whitelist card to declutter; the
				// lists themselves still apply at runtime if populated.
				// Defaults true so existing installs see no UI change.
				'enabled'       => true,
				'ips'           => array(),
				'emails'        => array(),
				'email_domains' => array(),
				'cpfs'          => array(),
			),
			'blacklist' => array(
				// UI visibility flag — see whitelist['enabled'] above.
				'enabled'       => true,
				'ips'           => array(),
				'emails'        => array(),
				'email_domains' => array(),
				'cpfs'          => array(),
			),
			'logging'   => array(
				'enabled'        => true,
				'log_allowed'    => false,
				'log_blocked'    => true,
				'retention_days' => 30,
				'max_logs'       => 10000,
			),
			'ui'        => array(
				'show_remaining'  => true,
				'show_wait_time'  => true,
				'countdown_timer' => true,
			),
			'read'      => array(
				'respect_whitelist' => true,
				'bypass_logged_in'  => true,
				'message'           => __( 'Too many requests. Please wait {time}.', 'ffcertificate' ),
				// Per-endpoint thresholds (#259). Keys match the
				// `endpoint_key` strings the ReadRateLimitGuardTrait
				// passes through; defaults below are calibrated for the
				// 3 calendar GETs but new endpoints can append their
				// own sub-array following the same shape.
				'endpoints'         => array(
					'calendar_slots'  => array(
						'enabled'        => true,
						'max_per_minute' => 60,
						'max_per_hour'   => 1000,
					),
					'calendar_list'   => array(
						'enabled'        => true,
						'max_per_minute' => 30,
						'max_per_hour'   => 500,
					),
					'calendar_detail' => array(
						'enabled'        => true,
						'max_per_minute' => 60,
						'max_per_hour'   => 1000,
					),
				),
			),
		);
		// wp_parse_args only merges TOP-LEVEL keys; with the nested
		// {ip,email,cpf,global,read,device,whitelist,blacklist,logging,ui}
		// groups the stored array shadows entire sub-arrays when a
		// pre-existing install was saved before a new field was added
		// (e.g. legacy `ip => ['enabled' => true]` would wipe out
		// max_per_hour / max_per_day / cooldown_seconds / message). Use
		// array_replace_recursive so missing leaves fall through to the
		// defaults without losing operator-customised values.
		$stored = get_option( \FreeFormCertificate\Settings\RateLimitSettingsReader::OPTION_KEY, array() );
		return is_array( $stored ) ? array_replace_recursive( $defaults, $stored ) : $defaults;
	}

	/**
	 * Render.
	 */
	public function render(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified below via check_admin_referer.
		if ( $_POST && isset( $_POST['ffc_save_rate_limit'] ) ) {
			check_admin_referer( 'ffc_rate_limit_nonce' );
			// The Settings page opens on `ffc_view_settings`; mutating requires
			// `ffc_manage_settings`. The parent's disabled <fieldset> is a UI
			// affordance only — a view-only user can still POST the nonce
			// directly, so gate the save here (matches every sibling path).
			if ( \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_settings' ) ) {
				$this->save_settings();
				wp_admin_notice(
					esc_html__( 'Settings saved!', 'ffcertificate' ),
					array( 'type' => 'success' )
				);
			}
		}

		$settings = $this->get_settings();
		include FFC_PLUGIN_DIR . 'includes/settings/views/ffc-tab-rate-limit.php';
	}

	/**
	 * Save settings.
	 */
	private function save_settings(): void {
		// Read before rebuilding: a numeric field the browser sent empty — or
		// one this tab has no field for at all — must fall back to what is
		// already stored, not to a bound or to the declared default (see
		// post_int_or_current()).
		$stored = \FreeFormCertificate\Settings\RateLimitSettingsReader::all();

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in render() via check_admin_referer.
		$settings = array(
			'ip'        => array(
				'enabled'                => isset( $_POST['ip_enabled'] ),
				'max_per_hour'           => $this->post_int_or_current( 'ip_max_per_hour', $this->stored_int( $stored, 'ip', 'max_per_hour', 5 ), 1 ),
				'max_per_day'            => $this->post_int_or_current( 'ip_max_per_day', $this->stored_int( $stored, 'ip', 'max_per_day', 20 ), 1 ),
				'cooldown_seconds'       => $this->post_int_or_current( 'ip_cooldown_seconds', $this->stored_int( $stored, 'ip', 'cooldown_seconds', 60 ), 1 ),
				'apply_to'               => \FreeFormCertificate\Core\RequestInput::get_post_string( 'ip_apply_to', 'all' ),
				'message'                => sanitize_textarea_field( wp_unslash( $_POST['ip_message'] ?? '' ) ),
				'captcha_max_per_window' => \FreeFormCertificate\Core\Captcha\CaptchaSettings::clamp_mint_cap(
					$this->post_int_or_current(
						'ip_captcha_max_per_window',
						$this->stored_int( $stored, 'ip', 'captcha_max_per_window', \FreeFormCertificate\Core\Captcha\CaptchaSettings::MINT_CAP_DEFAULT )
					)
				),
				'captcha_window_seconds' => \FreeFormCertificate\Core\Captcha\CaptchaSettings::clamp_mint_window(
					$this->post_int_or_current(
						'ip_captcha_window_seconds',
						$this->stored_int( $stored, 'ip', 'captcha_window_seconds', \FreeFormCertificate\Core\Captcha\CaptchaSettings::MINT_WINDOW_DEFAULT )
					)
				),
			),
			'email'     => array(
				'enabled'        => isset( $_POST['email_enabled'] ),
				'max_per_day'    => $this->post_int_or_current( 'email_max_per_day', $this->stored_int( $stored, 'email', 'max_per_day', 3 ), 1 ),
				'max_per_week'   => $this->post_int_or_current( 'email_max_per_week', $this->stored_int( $stored, 'email', 'max_per_week', 10 ), 1 ),
				'max_per_month'  => $this->post_int_or_current( 'email_max_per_month', $this->stored_int( $stored, 'email', 'max_per_month', 30 ), 1 ),
				// No field renders this one, so the POST key is always absent
				// and the old read reset it to 24 on every Save (#1114).
				'wait_hours'     => $this->post_int_or_current( 'email_wait_hours', $this->stored_int( $stored, 'email', 'wait_hours', 24 ), 1 ),
				'apply_to'       => \FreeFormCertificate\Core\RequestInput::get_post_string( 'email_apply_to', 'all' ),
				'message'        => sanitize_textarea_field( wp_unslash( $_POST['email_message'] ?? '' ) ),
				'check_database' => isset( $_POST['email_check_database'] ),
			),
			'cpf'       => array(
				'enabled'         => isset( $_POST['cpf_enabled'] ),
				'max_per_month'   => $this->post_int_or_current( 'cpf_max_per_month', $this->stored_int( $stored, 'cpf', 'max_per_month', 5 ), 1 ),
				'max_per_year'    => $this->post_int_or_current( 'cpf_max_per_year', $this->stored_int( $stored, 'cpf', 'max_per_year', 50 ), 1 ),
				'block_threshold' => $this->post_int_or_current( 'cpf_block_threshold', $this->stored_int( $stored, 'cpf', 'block_threshold', 3 ), 1 ),
				'block_hours'     => $this->post_int_or_current( 'cpf_block_hours', $this->stored_int( $stored, 'cpf', 'block_hours', 1 ), 1 ),
				'block_duration'  => $this->post_int_or_current( 'cpf_block_duration', $this->stored_int( $stored, 'cpf', 'block_duration', 24 ), 1 ),
				'apply_to'        => \FreeFormCertificate\Core\RequestInput::get_post_string( 'cpf_apply_to', 'all' ),
				'message'         => sanitize_textarea_field( wp_unslash( $_POST['cpf_message'] ?? '' ) ),
				'check_database'  => isset( $_POST['cpf_check_database'] ),
			),
			'global'    => array(
				'enabled'        => isset( $_POST['global_enabled'] ),
				'max_per_minute' => $this->post_int_or_current( 'global_max_per_minute', $this->stored_int( $stored, 'global', 'max_per_minute', 100 ), 1 ),
				'max_per_hour'   => $this->post_int_or_current( 'global_max_per_hour', $this->stored_int( $stored, 'global', 'max_per_hour', 1000 ), 1 ),
				'message'        => sanitize_textarea_field( wp_unslash( $_POST['global_message'] ?? '' ) ),
			),
			'read'      => array(
				'respect_whitelist' => isset( $_POST['read_respect_whitelist'] ),
				'bypass_logged_in'  => isset( $_POST['read_bypass_logged_in'] ),
				'message'           => sanitize_textarea_field( wp_unslash( $_POST['read_message'] ?? '' ) ),
				'endpoints'         => $this->parse_read_endpoints_post( $stored ),
			),
			'device'    => array(
				'enabled'                   => isset( $_POST['device_enabled'] ),
				'max_per_form'              => $this->post_int_or_current( 'device_max_per_form', $this->stored_int( $stored, 'device', 'max_per_form', 1 ), 1 ),
				'match_threshold'           => min( 12, $this->post_int_or_current( 'device_match_threshold', $this->stored_int( $stored, 'device', 'match_threshold', 7 ), 3 ) ),
				'match_strong_min'          => min( 6, $this->post_int_or_current( 'device_match_strong_min', $this->stored_int( $stored, 'device', 'match_strong_min', 2 ), 0 ) ),
				'signals_enabled'           => isset( $_POST['device_signals_enabled'] ) && is_array( $_POST['device_signals_enabled'] )
					? array_values(
						array_intersect(
							array( 'cookie', 'ua', 'screen', 'tz', 'concurrency', 'memory', 'canvas', 'audio', 'webgl', 'fonts', 'plugins', 'permissions', 'mediaqueries', 'math' ),
							array_map( 'sanitize_key', wp_unslash( $_POST['device_signals_enabled'] ) )
						)
					)
					: array(),
				'bypass_logged_in_managers' => isset( $_POST['device_bypass_logged_in_managers'] ),
				'bypass_whitelist_signals'  => array_filter(
					array_map(
						static function ( $v ) {
							$v = (string) preg_replace( '/[^a-f0-9]/i', '', trim( (string) $v ) );
							return ( 64 === strlen( $v ) ) ? strtolower( $v ) : '';
						},
						explode( "\n", sanitize_textarea_field( wp_unslash( $_POST['device_bypass_whitelist_signals'] ?? '' ) ) )
					)
				),
				'message'                   => sanitize_textarea_field( wp_unslash( $_POST['device_message'] ?? '' ) ),
				'retention_days'            => $this->post_int_or_current( 'device_retention_days', $this->stored_int( $stored, 'device', 'retention_days', 90 ), 1 ),
				'log_blocks'                => isset( $_POST['device_log_blocks'] ),
			),
			'whitelist' => array(
				'enabled'       => isset( $_POST['whitelist_enabled'] ),
				'ips'           => array_filter( array_map( 'trim', explode( "\n", sanitize_textarea_field( wp_unslash( $_POST['whitelist_ips'] ?? '' ) ) ) ) ),
				'emails'        => array_filter( array_map( 'trim', explode( "\n", sanitize_textarea_field( wp_unslash( $_POST['whitelist_emails'] ?? '' ) ) ) ) ),
				'email_domains' => array_filter( array_map( 'trim', explode( "\n", sanitize_textarea_field( wp_unslash( $_POST['whitelist_email_domains'] ?? '' ) ) ) ) ),
				'cpfs'          => array_filter( array_map( 'trim', explode( "\n", sanitize_textarea_field( wp_unslash( $_POST['whitelist_cpfs'] ?? '' ) ) ) ) ),
			),
			'blacklist' => array(
				'enabled'       => isset( $_POST['blacklist_enabled'] ),
				'ips'           => array_filter( array_map( 'trim', explode( "\n", sanitize_textarea_field( wp_unslash( $_POST['blacklist_ips'] ?? '' ) ) ) ) ),
				'emails'        => array_filter( array_map( 'trim', explode( "\n", sanitize_textarea_field( wp_unslash( $_POST['blacklist_emails'] ?? '' ) ) ) ) ),
				'email_domains' => array_filter( array_map( 'trim', explode( "\n", sanitize_textarea_field( wp_unslash( $_POST['blacklist_email_domains'] ?? '' ) ) ) ) ),
				'cpfs'          => array_filter( array_map( 'trim', explode( "\n", sanitize_textarea_field( wp_unslash( $_POST['blacklist_cpfs'] ?? '' ) ) ) ) ),
			),
			'logging'   => array(
				'enabled'        => isset( $_POST['logging_enabled'] ),
				'log_allowed'    => isset( $_POST['logging_log_allowed'] ),
				'log_blocked'    => isset( $_POST['logging_log_blocked'] ),
				'retention_days' => $this->post_int_or_current( 'logging_retention_days', $this->stored_int( $stored, 'logging', 'retention_days', 30 ), 1 ),
				'max_logs'       => $this->post_int_or_current( 'logging_max_logs', $this->stored_int( $stored, 'logging', 'max_logs', 10000 ), 100 ),
			),
			'ui'        => array(
				'show_remaining'  => isset( $_POST['ui_show_remaining'] ),
				'show_wait_time'  => isset( $_POST['ui_show_wait_time'] ),
				'countdown_timer' => isset( $_POST['ui_countdown_timer'] ),
			),
		);
        // phpcs:enable WordPress.Security.NonceVerification.Missing

		update_option( 'ffc_rate_limit_settings', $settings );
	}

	/**
	 * Read a POST integer, treating an empty field as "unchanged".
	 *
	 * A cleared `<input type="number">` posts the empty string, and `absint()`
	 * turns that into `0` — which every bounded field then clamps to its own
	 * floor. The result is a value the administrator never chose, written
	 * silently: clearing the captcha window would have stored one second, and
	 * clearing the cap would have stored "no cap" (#1111 follow-up). An empty
	 * field means the value was not supplied, so the stored one stands.
	 *
	 * An **absent** key is the same case and matters on its own: `wait_hours`
	 * has no field on this tab, so the rebuild-save was overwriting whatever
	 * an operator had configured with the hardcoded default on every Save
	 * (#1114). Reading the stored value fixes that too.
	 *
	 * `$min` is the floor for the fields where `0` is not a value anyone
	 * means. It is not a second guess at the default: on this tab a zero
	 * *count* is read by the checkers as "the limit is already reached", so
	 * `ip.max_per_hour = 0` blocks every submission from every address rather
	 * than lifting the limit — `$hc >= 0` is true on the first request. The
	 * floors here mirror the `min` each field already declares in the view,
	 * so the form and the save agree instead of only the browser enforcing
	 * it. Leave it at `0` where zero is documented to mean "no cap on this
	 * axis" (the read endpoints, the captcha issuing cap).
	 *
	 * @since 6.24.0
	 * @param string $key     `$_POST` key.
	 * @param int    $current Value already stored for this setting.
	 * @param int    $min     Lowest value the field accepts; 0 leaves it unclamped.
	 * @return int
	 */
	private function post_int_or_current( string $key, int $current, int $min = 0 ): int {
		if ( '' === \FreeFormCertificate\Core\RequestInput::get_post_string( $key, '' ) ) {
			return max( $min, $current );
		}

		return max( $min, \FreeFormCertificate\Core\RequestInput::get_post_int( $key, $current ) );
	}

	/**
	 * Read a stored number out of the settings array being rebuilt over.
	 *
	 * The fallback a cleared or absent field lands on has to be what is
	 * already saved, not the declared default — otherwise Save silently
	 * resets the setting instead of leaving it alone. Non-numeric residue in
	 * the option falls through to the default rather than being cast.
	 *
	 * @since 6.24.0
	 * @param array<string, mixed> $stored  The whole stored option.
	 * @param string               $group   Settings group (`ip`, `email`, …).
	 * @param string               $key     Key inside the group.
	 * @param int                  $default Value for a key never saved before.
	 * @return int
	 */
	private function stored_int( array $stored, string $group, string $key, int $default ): int {
		$value = $stored[ $group ][ $key ] ?? null;

		return is_numeric( $value ) ? (int) $value : $default;
	}

	/**
	 * Parse the per-endpoint read-rate-limit POST fields into the
	 * `endpoints` sub-array shape `get_settings()` documents. Keys
	 * are the known endpoint identifiers — anything else POST'd is
	 * silently ignored (defence in depth against a tampered form).
	 *
	 * Each endpoint stores `{enabled, max_per_minute, max_per_hour}`.
	 * `max_per_minute` / `max_per_hour` accept `0` (= "no per-window
	 * cap on this axis"); the checker treats `<=0` as "skip this gate".
	 *
	 * These are the fields where `0` genuinely is a value, so they take no
	 * floor — but they still have to tell a **typed** zero from a **cleared**
	 * field, or clearing one would silently lift the cap it was meant to
	 * adjust (#1114). That distinction is exactly what `post_int_or_current()`
	 * makes, which is why the stored option is threaded through here.
	 *
	 * @since 6.6.2
	 * @param array<string, mixed> $stored The stored option being rebuilt over.
	 * @return array<string, array{enabled: bool, max_per_minute: int, max_per_hour: int}>
	 */
	private function parse_read_endpoints_post( array $stored ): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified by save_settings() caller.
		$known     = array( 'calendar_slots', 'calendar_list', 'calendar_detail' );
		$endpoints = $stored['read']['endpoints'] ?? array();
		$endpoints = is_array( $endpoints ) ? $endpoints : array();
		$out       = array();
		foreach ( $known as $key ) {
			$saved = array( 'read' => is_array( $endpoints[ $key ] ?? null ) ? $endpoints[ $key ] : array() );

			$out[ $key ] = array(
				'enabled'        => isset( $_POST[ 'read_endpoint_' . $key . '_enabled' ] ),
				'max_per_minute' => $this->post_int_or_current(
					'read_endpoint_' . $key . '_max_per_minute',
					$this->stored_int( $saved, 'read', 'max_per_minute', 0 )
				),
				'max_per_hour'   => $this->post_int_or_current(
					'read_endpoint_' . $key . '_max_per_hour',
					$this->stored_int( $saved, 'read', 'max_per_hour', 0 )
				),
			);
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		return $out;
	}
}
