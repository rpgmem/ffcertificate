<?php
/**
 * Rate Limit Settings Tab
 *
 * @package FreeFormCertificate\Settings\Tabs
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace (Phase 2)
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
		if ( 'ffc_form_page_ffc-settings' !== $hook ) {
			return;
		}
		if ( ! $this->is_active() ) {
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
				'enabled'          => true,
				'max_per_hour'     => 5,
				'max_per_day'      => 20,
				'cooldown_seconds' => 60,
				'apply_to'         => 'all',
				'message'          => __( 'Limit reached. Please wait {time}.', 'ffcertificate' ),
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
		$stored = get_option( 'ffc_rate_limit_settings', array() );
		return is_array( $stored ) ? array_replace_recursive( $defaults, $stored ) : $defaults;
	}

	/**
	 * Render.
	 */
	public function render(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified below via check_admin_referer.
		if ( $_POST && isset( $_POST['ffc_save_rate_limit'] ) ) {
			check_admin_referer( 'ffc_rate_limit_nonce' );
			$this->save_settings();
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved!', 'ffcertificate' ) . '</p></div>';
		}

		$settings = $this->get_settings();
		include FFC_PLUGIN_DIR . 'includes/settings/views/ffc-tab-rate-limit.php';
	}

	/**
	 * Save settings.
	 */
	private function save_settings(): void {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in render() via check_admin_referer.
		$settings = array(
			'ip'        => array(
				'enabled'          => isset( $_POST['ip_enabled'] ),
				'max_per_hour'     => absint( wp_unslash( $_POST['ip_max_per_hour'] ?? 5 ) ),
				'max_per_day'      => absint( wp_unslash( $_POST['ip_max_per_day'] ?? 20 ) ),
				'cooldown_seconds' => absint( wp_unslash( $_POST['ip_cooldown_seconds'] ?? 60 ) ),
				'apply_to'         => \FreeFormCertificate\Core\Utils::get_post_string( 'ip_apply_to', 'all' ),
				'message'          => sanitize_textarea_field( wp_unslash( $_POST['ip_message'] ?? '' ) ),
			),
			'email'     => array(
				'enabled'        => isset( $_POST['email_enabled'] ),
				'max_per_day'    => absint( wp_unslash( $_POST['email_max_per_day'] ?? 3 ) ),
				'max_per_week'   => absint( wp_unslash( $_POST['email_max_per_week'] ?? 10 ) ),
				'max_per_month'  => absint( wp_unslash( $_POST['email_max_per_month'] ?? 30 ) ),
				'wait_hours'     => absint( wp_unslash( $_POST['email_wait_hours'] ?? 24 ) ),
				'apply_to'       => \FreeFormCertificate\Core\Utils::get_post_string( 'email_apply_to', 'all' ),
				'message'        => sanitize_textarea_field( wp_unslash( $_POST['email_message'] ?? '' ) ),
				'check_database' => isset( $_POST['email_check_database'] ),
			),
			'cpf'       => array(
				'enabled'         => isset( $_POST['cpf_enabled'] ),
				'max_per_month'   => absint( wp_unslash( $_POST['cpf_max_per_month'] ?? 5 ) ),
				'max_per_year'    => absint( wp_unslash( $_POST['cpf_max_per_year'] ?? 50 ) ),
				'block_threshold' => absint( wp_unslash( $_POST['cpf_block_threshold'] ?? 3 ) ),
				'block_hours'     => absint( wp_unslash( $_POST['cpf_block_hours'] ?? 1 ) ),
				'block_duration'  => absint( wp_unslash( $_POST['cpf_block_duration'] ?? 24 ) ),
				'apply_to'        => \FreeFormCertificate\Core\Utils::get_post_string( 'cpf_apply_to', 'all' ),
				'message'         => sanitize_textarea_field( wp_unslash( $_POST['cpf_message'] ?? '' ) ),
				'check_database'  => isset( $_POST['cpf_check_database'] ),
			),
			'global'    => array(
				'enabled'        => isset( $_POST['global_enabled'] ),
				'max_per_minute' => absint( wp_unslash( $_POST['global_max_per_minute'] ?? 100 ) ),
				'max_per_hour'   => absint( wp_unslash( $_POST['global_max_per_hour'] ?? 1000 ) ),
				'message'        => sanitize_textarea_field( wp_unslash( $_POST['global_message'] ?? '' ) ),
			),
			'read'      => array(
				'respect_whitelist' => isset( $_POST['read_respect_whitelist'] ),
				'bypass_logged_in'  => isset( $_POST['read_bypass_logged_in'] ),
				'message'           => sanitize_textarea_field( wp_unslash( $_POST['read_message'] ?? '' ) ),
				'endpoints'         => $this->parse_read_endpoints_post(),
			),
			'device'    => array(
				'enabled'                   => isset( $_POST['device_enabled'] ),
				'max_per_form'              => max( 1, absint( wp_unslash( $_POST['device_max_per_form'] ?? 1 ) ) ),
				'match_threshold'           => max( 3, min( 12, absint( wp_unslash( $_POST['device_match_threshold'] ?? 7 ) ) ) ),
				'match_strong_min'          => max( 0, min( 6, absint( wp_unslash( $_POST['device_match_strong_min'] ?? 2 ) ) ) ),
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
				'retention_days'            => max( 1, absint( wp_unslash( $_POST['device_retention_days'] ?? 90 ) ) ),
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
				'retention_days' => absint( wp_unslash( $_POST['logging_retention_days'] ?? 30 ) ),
				'max_logs'       => absint( wp_unslash( $_POST['logging_max_logs'] ?? 10000 ) ),
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
	 * Parse the per-endpoint read-rate-limit POST fields into the
	 * `endpoints` sub-array shape `get_settings()` documents. Keys
	 * are the known endpoint identifiers — anything else POST'd is
	 * silently ignored (defence in depth against a tampered form).
	 *
	 * Each endpoint stores `{enabled, max_per_minute, max_per_hour}`.
	 * `max_per_minute` / `max_per_hour` accept `0` (= "no per-window
	 * cap on this axis"); the checker treats `<=0` as "skip this gate".
	 *
	 * @since 6.6.2
	 * @return array<string, array{enabled: bool, max_per_minute: int, max_per_hour: int}>
	 */
	private function parse_read_endpoints_post(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified by save_settings() caller.
		$known = array( 'calendar_slots', 'calendar_list', 'calendar_detail' );
		$out   = array();
		foreach ( $known as $key ) {
			$out[ $key ] = array(
				'enabled'        => isset( $_POST[ 'read_endpoint_' . $key . '_enabled' ] ),
				'max_per_minute' => absint( wp_unslash( $_POST[ 'read_endpoint_' . $key . '_max_per_minute' ] ?? 0 ) ),
				'max_per_hour'   => absint( wp_unslash( $_POST[ 'read_endpoint_' . $key . '_max_per_hour' ] ?? 0 ) ),
			);
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		return $out;
	}
}
