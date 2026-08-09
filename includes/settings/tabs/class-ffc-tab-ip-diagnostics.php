<?php
/**
 * IP Diagnostics Settings Tab.
 *
 * Phase 2 of the client-IP consolidation (#901 / #899): gives the admin
 * read-only visibility into how the current request's IP is being resolved,
 * a config surface for the trusted-proxy model, and a Cloudflare setup guide
 * when no CDN is detected. The effective strategy stays `legacy` unless the
 * admin opts into `secure` here — the phase-3 flip is a separate change.
 *
 * @package FreeFormCertificate\Settings\Tabs
 * @since   6.19.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Settings\Tabs;

use FreeFormCertificate\Settings\SettingsTab;
use FreeFormCertificate\Settings\IpDiagnosticsSettingsReader;
use FreeFormCertificate\Core\ClientIpResolver;
use FreeFormCertificate\Core\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "IP Diagnostics" settings tab.
 */
class TabIpDiagnostics extends SettingsTab {

	/** Option key owned by this tab (mirrors IpDiagnosticsSettingsReader). */
	private const OPTION_KEY = IpDiagnosticsSettingsReader::OPTION_KEY;

	/**
	 * Cloudflare CIDR cache option — read by literal key on purpose: reaching
	 * into the Integrations class that owns it would add a Settings→Integrations
	 * module edge for a mere status read. The key is stable (also referenced in
	 * uninstall.php).
	 */
	private const CF_CACHE_OPTION = 'ffc_cloudflare_cidr_cache';

	/**
	 * Init.
	 */
	protected function init(): void {
		$this->tab_id    = 'ip_diagnostics';
		$this->tab_title = __( 'IP Diagnostics', 'ffcertificate' );
		$this->tab_icon  = 'ffc-icon-shield';
		$this->tab_order = 45;
	}

	/**
	 * Render the tab: process a save, then the diagnostic, config and guide.
	 */
	public function render(): void {
		$can_edit = Capabilities::current_user_can_admin_or( 'ffc_manage_settings' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified below via check_admin_referer.
		if ( $can_edit && isset( $_POST['ffc_save_ip_diagnostics'] ) ) {
			check_admin_referer( 'ffc_ip_diagnostics_nonce' );
			if ( isset( $_POST['ffc_ip_refresh_cloudflare'] ) ) {
				/**
				 * Fired to request an immediate Cloudflare CIDR refresh. The
				 * Integrations refresh class listens for this; using the action
				 * (not a class call) keeps this tab free of a module edge.
				 */
				// Literal hook string (not a constant) so WPCS sees the ffc_
				// prefix; firing the action instead of calling the Integrations
				// class keeps this tab free of a Settings→Integrations edge.
				// Matches CloudflareCidrRefresh::REFRESH_ACTION.
				do_action( 'ffc_cloudflare_cidr_refresh_now' );
				$this->render_notice( __( 'Cloudflare IP ranges refresh requested.', 'ffcertificate' ), 'success' );
			} else {
				$this->save_settings();
				$this->render_notice( __( 'IP resolution settings saved.', 'ffcertificate' ), 'success' );
			}
		}

		$diag = $this->diagnostics();

		echo '<div class="ffc-ip-diagnostics">';
		$this->render_recommendation();
		$this->render_diagnostics_section( $diag );
		$this->render_config_form();
		if ( ClientIpResolver::VERDICT_DIRECT === $diag['verdict'] ) {
			$this->render_cloudflare_guide();
		}
		echo '</div>';
	}

	/**
	 * Short, prominent recommendation to adopt the secure strategy + Cloudflare.
	 *
	 * Shown while the effective strategy is still `legacy` (the default). This
	 * is a RECOMMENDATION only: #902's default flip is deliberately NOT forced
	 * (it is gated on shadow-mode telemetry) — we nudge the admin here instead.
	 */
	private function render_recommendation(): void {
		if ( IpDiagnosticsSettingsReader::STRATEGY_SECURE === IpDiagnosticsSettingsReader::strategy() ) {
			return;
		}

		echo '<div class="notice notice-warning inline"><p>';
		echo '<strong>' . esc_html__( 'Recommended:', 'ffcertificate' ) . '</strong> ';
		echo esc_html__( 'Switch the effective strategy to Secure (trusted-proxy) below, so forged headers can no longer spoof rate-limiting, geofencing or the activity log. Ideally, also put Cloudflare in front of this site for an unspoofable client IP (free plan). The current default (Legacy) trusts client-supplied headers.', 'ffcertificate' );
		echo '</p></div>';
	}

	/**
	 * Persist the config into this tab's own option (never `ffc_settings`).
	 */
	private function save_settings(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in render() via check_admin_referer.
		$strategy = isset( $_POST['ffc_ip_strategy'] )
			? sanitize_key( wp_unslash( $_POST['ffc_ip_strategy'] ) )
			: IpDiagnosticsSettingsReader::STRATEGY_LEGACY;
		if ( ! in_array( $strategy, array( IpDiagnosticsSettingsReader::STRATEGY_LEGACY, IpDiagnosticsSettingsReader::STRATEGY_SECURE ), true ) ) {
			$strategy = IpDiagnosticsSettingsReader::STRATEGY_LEGACY;
		}

		$proxy_mode = isset( $_POST['ffc_ip_trusted_proxy_mode'] )
			? sanitize_key( wp_unslash( $_POST['ffc_ip_trusted_proxy_mode'] ) )
			: IpDiagnosticsSettingsReader::PROXY_AUTO;

		$valid_modes = array(
			IpDiagnosticsSettingsReader::PROXY_AUTO,
			IpDiagnosticsSettingsReader::PROXY_CLOUDFLARE,
			IpDiagnosticsSettingsReader::PROXY_CUSTOM,
			IpDiagnosticsSettingsReader::PROXY_DIRECT,
		);
		if ( ! in_array( $proxy_mode, $valid_modes, true ) ) {
			$proxy_mode = IpDiagnosticsSettingsReader::PROXY_AUTO;
		}

		$custom_raw = isset( $_POST['ffc_ip_custom_proxies'] )
			? sanitize_textarea_field( wp_unslash( $_POST['ffc_ip_custom_proxies'] ) )
			: '';

		$custom = $this->sanitize_cidr_list( $custom_raw );

		$settings = array(
			'strategy'           => $strategy,
			'trusted_proxy_mode' => $proxy_mode,
			'custom_proxies'     => implode( "\n", $custom ),
			'shadow_logging'     => isset( $_POST['ffc_ip_shadow_logging'] ) ? 1 : 0,
		);

		update_option( self::OPTION_KEY, $settings, false );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Keep only well-formed CIDR / literal-IP entries from a textarea blob.
	 *
	 * @param string $raw Newline/comma-separated user input.
	 * @return array<int, string>
	 */
	private function sanitize_cidr_list( string $raw ): array {
		$parts = preg_split( '/[\r\n,]+/', $raw );
		if ( ! is_array( $parts ) ) {
			$parts = array();
		}
		$out = array();
		foreach ( $parts as $part ) {
			$part = trim( (string) $part );
			if ( '' !== $part && $this->is_valid_cidr( $part ) ) {
				$out[] = $part;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Shape-validate a CIDR / literal IP.
	 *
	 * @param string $cidr Candidate entry.
	 */
	private function is_valid_cidr( string $cidr ): bool {
		if ( strpos( $cidr, '/' ) === false ) {
			return (bool) filter_var( $cidr, FILTER_VALIDATE_IP );
		}
		list( $ip, $bits ) = explode( '/', $cidr, 2 );
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) || ! ctype_digit( $bits ) ) {
			return false;
		}
		$max = filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ? 128 : 32;
		return (int) $bits >= 0 && (int) $bits <= $max;
	}

	/**
	 * Gather everything the read-only diagnostic renders for THIS request.
	 *
	 * @return array{
	 *   remote:string, verdict:string, headers:array<string,string>,
	 *   legacy:string, secure:string, mode:string,
	 *   forged_legacy:string, forged_secure:string, forged_sentinel:string
	 * }
	 */
	private function diagnostics(): array {
		$remote = $this->server_value( 'REMOTE_ADDR' );

		$header_keys = array(
			'X-Forwarded-For'  => 'HTTP_X_FORWARDED_FOR',
			'CF-Connecting-IP' => 'HTTP_CF_CONNECTING_IP',
			'X-Real-IP'        => 'HTTP_X_REAL_IP',
			'Client-IP'        => 'HTTP_CLIENT_IP',
			'X-Forwarded'      => 'HTTP_X_FORWARDED',
			'Forwarded'        => 'HTTP_FORWARDED',
		);

		$headers = array();
		foreach ( $header_keys as $label => $server_key ) {
			$headers[ $label ] = $this->server_value( $server_key );
		}

		list( $forged_legacy, $forged_secure, $sentinel ) = $this->injection_test();

		return array(
			'remote'          => $remote,
			'verdict'         => ClientIpResolver::classify(),
			'headers'         => $headers,
			'legacy'          => ClientIpResolver::resolve_legacy(),
			'secure'          => ClientIpResolver::resolve_secure(),
			'mode'            => ClientIpResolver::mode(),
			'forged_legacy'   => $forged_legacy,
			'forged_secure'   => $forged_secure,
			'forged_sentinel' => $sentinel,
		);
	}

	/**
	 * Automated header-injection probe: temporarily forge an `X-Forwarded-For`
	 * (a public TEST-NET-3 address, RFC 5737) and observe what each strategy
	 * would return. `legacy` echoes the forged value (spoofable); `secure`
	 * ignores it unless the peer is a trusted proxy. `$_SERVER` is restored
	 * immediately.
	 *
	 * @return array{0:string,1:string,2:string} [legacy_result, secure_result, sentinel].
	 */
	private function injection_test(): array {
		$sentinel = '203.0.113.7';
		$had      = array_key_exists( 'HTTP_X_FORWARDED_FOR', $_SERVER );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Captured verbatim only to restore the exact original after the probe; never used as data.
		$prev = $had ? $_SERVER['HTTP_X_FORWARDED_FOR'] : null;

		$_SERVER['HTTP_X_FORWARDED_FOR'] = $sentinel;

		$legacy = ClientIpResolver::resolve_legacy();
		$secure = ClientIpResolver::resolve_secure();

		if ( $had ) {
			$_SERVER['HTTP_X_FORWARDED_FOR'] = $prev;
		} else {
			unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
		}

		return array( $legacy, $secure, $sentinel );
	}

	/**
	 * Read + unslash + sanitize a `$_SERVER` string (display only).
	 *
	 * @param string $key `$_SERVER` key.
	 * @return string Sanitized value, or '' when absent.
	 */
	private function server_value( string $key ): string {
		if ( ! isset( $_SERVER[ $key ] ) ) {
			return '';
		}
		$raw = wp_unslash( $_SERVER[ $key ] );
		return is_string( $raw ) ? sanitize_text_field( $raw ) : '';
	}

	/**
	 * Mask an IP unless the viewer holds full admin (own-request IP, low risk,
	 * but the tab respects the "mask unless PII cap" convention).
	 *
	 * @param string $ip IP address (or '' / non-IP).
	 * @return string Display string.
	 */
	private function mask_ip( string $ip ): string {
		if ( '' === $ip ) {
			return '—';
		}
		if ( current_user_can( 'manage_options' ) ) {
			return $ip;
		}
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$p = explode( '.', $ip );
			return $p[0] . '.' . $p[1] . '.' . $p[2] . '.xxx';
		}
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			return implode( ':', array_slice( explode( ':', $ip ), 0, 3 ) ) . ':…';
		}
		return '—';
	}

	/**
	 * Human label for an environment verdict.
	 *
	 * @param string $verdict One of ClientIpResolver::VERDICT_*.
	 * @return string
	 */
	private function verdict_label( string $verdict ): string {
		switch ( $verdict ) {
			case ClientIpResolver::VERDICT_CLOUDFLARE:
				return __( 'Cloudflare detected — CF-Connecting-IP is authoritative.', 'ffcertificate' );
			case ClientIpResolver::VERDICT_TRUSTED_PROXY:
				return __( 'Behind a configured trusted proxy — X-Forwarded-For is honoured.', 'ffcertificate' );
			default:
				return __( 'Direct connection — REMOTE_ADDR is already the client.', 'ffcertificate' );
		}
	}

	/**
	 * Render the read-only diagnostic block.
	 *
	 * @param array<string, mixed> $d Diagnostics from {@see self::diagnostics()}.
	 */
	private function render_diagnostics_section( array $d ): void {
		$this->render_section_header(
			__( 'Current request diagnosis', 'ffcertificate' ),
			__( 'How this very request would be resolved. IPs are masked unless you are a full administrator.', 'ffcertificate' )
		);

		echo '<table class="form-table" role="presentation"><tbody>';

		$this->render_field_row(
			esc_html__( 'REMOTE_ADDR (TCP peer)', 'ffcertificate' ),
			'<code>' . esc_html( $this->mask_ip( (string) $d['remote'] ) ) . '</code>'
		);

		$this->render_field_row(
			esc_html__( 'Environment verdict', 'ffcertificate' ),
			'<strong>' . esc_html( $this->verdict_label( (string) $d['verdict'] ) ) . '</strong>'
		);

		$rows = '';
		foreach ( (array) $d['headers'] as $label => $value ) {
			$shown = '' === $value ? '—' : $this->mask_ip( $value );
			if ( '—' === $shown && '' !== $value ) {
				// Present but not a bare IP (e.g. a list): show it masked-safe.
				$shown = esc_html( mb_substr( $value, 0, 60 ) );
			} else {
				$shown = esc_html( $shown );
			}
			$rows .= '<tr><td><code>' . esc_html( $label ) . '</code></td><td><code>' . $shown . '</code></td></tr>';
		}
		$this->render_field_row(
			esc_html__( 'Proxy headers received', 'ffcertificate' ),
			'<table class="widefat striped" style="max-width:520px"><tbody>' . $rows . '</tbody></table>'
		);

		$mode_note = ClientIpResolver::MODE_SECURE === $d['mode']
			? esc_html__( '(secure is currently effective)', 'ffcertificate' )
			: esc_html__( '(legacy is currently effective)', 'ffcertificate' );
		$this->render_field_row(
			esc_html__( 'Resolved IP by strategy', 'ffcertificate' ),
			'<code>legacy → ' . esc_html( $this->mask_ip( (string) $d['legacy'] ) ) . '</code><br>'
			. '<code>secure → ' . esc_html( $this->mask_ip( (string) $d['secure'] ) ) . '</code> '
			. '<em>' . $mode_note . '</em>'
		);

		$leaks   = ( (string) $d['forged_legacy'] === (string) $d['forged_sentinel'] );
		$immune  = ( (string) $d['forged_secure'] !== (string) $d['forged_sentinel'] );
		$verdict = $leaks && $immune
			? __( '⚠️ The legacy strategy would trust a forged X-Forwarded-For; the secure strategy ignores it.', 'ffcertificate' )
			: __( 'The secure strategy does not trust the forged header for this request.', 'ffcertificate' );
		$this->render_field_row(
			esc_html__( 'Header-injection self-test', 'ffcertificate' ),
			'<p>' . sprintf(
				/* translators: %s: forged sentinel IP */
				esc_html__( 'With a forged %s:', 'ffcertificate' ),
				'<code>X-Forwarded-For: ' . esc_html( (string) $d['forged_sentinel'] ) . '</code>'
			) . '</p>'
			. '<code>legacy → ' . esc_html( $this->mask_ip( (string) $d['forged_legacy'] ) ) . '</code><br>'
			. '<code>secure → ' . esc_html( $this->mask_ip( (string) $d['forged_secure'] ) ) . '</code>'
			. '<p class="description">' . esc_html( $verdict ) . '</p>'
		);

		echo '</tbody></table>';

		$this->render_cloudflare_cache_status();
	}

	/**
	 * Render the freshness of the cron-refreshed Cloudflare CIDR list.
	 */
	private function render_cloudflare_cache_status(): void {
		$cache   = get_option( self::CF_CACHE_OPTION, null );
		$count   = ( is_array( $cache ) && isset( $cache['count'] ) ) ? (int) $cache['count'] : 0;
		$updated = ( is_array( $cache ) && isset( $cache['updated'] ) ) ? (int) $cache['updated'] : 0;

		if ( $updated > 0 ) {
			$when = sprintf(
				/* translators: %s: human time-diff */
				esc_html__( 'refreshed %s ago', 'ffcertificate' ),
				esc_html( human_time_diff( $updated ) )
			);
			$msg = sprintf(
				/* translators: 1: range count, 2: "refreshed X ago" */
				esc_html__( 'Cloudflare ranges: %1$d entries (%2$s).', 'ffcertificate' ),
				$count,
				$when
			);
		} else {
			$msg = esc_html__( 'Cloudflare ranges: using the bundled fallback list (daily refresh not run yet).', 'ffcertificate' );
		}

		echo '<p class="description">' . esc_html( $msg ) . '</p>';
	}

	/**
	 * Render the strategy + trusted-proxy configuration form.
	 */
	private function render_config_form(): void {
		$strategy   = IpDiagnosticsSettingsReader::strategy();
		$proxy_mode = IpDiagnosticsSettingsReader::trusted_proxy_mode();
		$custom     = implode( "\n", IpDiagnosticsSettingsReader::custom_proxies() );
		$shadow     = IpDiagnosticsSettingsReader::shadow_logging_enabled();

		$this->render_section_header(
			__( 'Resolution strategy', 'ffcertificate' ),
			__( 'Legacy stays effective by default (no behaviour change). Opt into secure to apply the trusted-proxy model now; otherwise it becomes the default in a later release.', 'ffcertificate' )
		);

		echo '<form method="post" action="">';
		wp_nonce_field( 'ffc_ip_diagnostics_nonce' );
		echo '<table class="form-table" role="presentation"><tbody>';

		// Effective strategy. Inputs are echoed directly (never through
		// render_field_row, whose wp_kses_post strips form controls).
		echo '<tr><th scope="row">' . esc_html__( 'Effective strategy', 'ffcertificate' ) . '</th><td>';
		$this->echo_radio( 'ffc_ip_strategy', IpDiagnosticsSettingsReader::STRATEGY_LEGACY, $strategy, __( 'Legacy (current behaviour, spoofable) — recommended until a trusted proxy is configured', 'ffcertificate' ) );
		echo '<br>';
		$this->echo_radio( 'ffc_ip_strategy', IpDiagnosticsSettingsReader::STRATEGY_SECURE, $strategy, __( 'Secure (trusted-proxy model)', 'ffcertificate' ) );
		echo '</td></tr>';

		// Trusted-proxy configuration.
		echo '<tr><th scope="row">' . esc_html__( 'Trusted-proxy configuration', 'ffcertificate' ) . '</th><td>';
		$this->echo_radio( 'ffc_ip_trusted_proxy_mode', IpDiagnosticsSettingsReader::PROXY_AUTO, $proxy_mode, __( 'Auto — detect Cloudflare / known proxy, else use REMOTE_ADDR (recommended)', 'ffcertificate' ) );
		echo '<br>';
		$this->echo_radio( 'ffc_ip_trusted_proxy_mode', IpDiagnosticsSettingsReader::PROXY_CLOUDFLARE, $proxy_mode, __( 'Cloudflare — trust the Cloudflare edge ranges', 'ffcertificate' ) );
		echo '<br>';
		$this->echo_radio( 'ffc_ip_trusted_proxy_mode', IpDiagnosticsSettingsReader::PROXY_CUSTOM, $proxy_mode, __( 'Custom — trust the proxy CIDRs below', 'ffcertificate' ) );
		echo '<br>';
		$this->echo_radio( 'ffc_ip_trusted_proxy_mode', IpDiagnosticsSettingsReader::PROXY_DIRECT, $proxy_mode, __( 'Direct — ignore every forwarded header', 'ffcertificate' ) );
		echo '<p class="description">' . esc_html__( 'Applies when the secure strategy is effective.', 'ffcertificate' ) . '</p>';
		echo '</td></tr>';

		// Custom CIDRs.
		echo '<tr><th scope="row">' . esc_html__( 'Custom proxy CIDRs', 'ffcertificate' ) . '</th><td>';
		echo '<textarea name="ffc_ip_custom_proxies" rows="4" cols="40" class="large-text code" placeholder="10.0.0.0/8">' . esc_textarea( $custom ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'One CIDR or IP per line. Used only with the Custom mode above.', 'ffcertificate' ) . '</p>';
		echo '</td></tr>';

		// Shadow logging.
		echo '<tr><th scope="row">' . esc_html__( 'Shadow-divergence logging', 'ffcertificate' ) . '</th><td>';
		echo '<label><input type="checkbox" name="ffc_ip_shadow_logging" value="1"' . checked( $shadow, true, false ) . '> '
			. esc_html__( 'Log (with a hashed IP) when legacy and secure would disagree — measure the impact before switching.', 'ffcertificate' ) . '</label>';
		echo '</td></tr>';

		echo '</tbody></table>';

		submit_button( __( 'Save IP settings', 'ffcertificate' ), 'primary', 'ffc_save_ip_diagnostics', false );
		echo ' ';
		submit_button( __( 'Refresh Cloudflare ranges now', 'ffcertificate' ), 'secondary', 'ffc_ip_refresh_cloudflare', false );
		echo '</form>';
	}

	/**
	 * Echo one radio input + label with inline escaping (form controls must be
	 * emitted directly — render_field_row's wp_kses_post would strip them).
	 *
	 * @param string $name    Field name.
	 * @param string $value   This option's value.
	 * @param string $current Currently-selected value.
	 * @param string $label   Human label.
	 */
	private function echo_radio( string $name, string $value, string $current, string $label ): void {
		echo '<label><input type="radio" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"'
			. checked( $value, $current, false ) . '> ' . esc_html( $label ) . '</label>';
	}

	/**
	 * Render the "how to put Cloudflare in front" guide (verdict = direct).
	 */
	private function render_cloudflare_guide(): void {
		$this->render_section_header(
			__( 'Optional: put Cloudflare in front of this site', 'ffcertificate' ),
			__( 'No CDN was detected. Cloudflare is optional — the Auto/Direct mode plus device fingerprinting already protects you — but it gives an unspoofable client IP for free.', 'ffcertificate' )
		);

		echo '<div class="ffc-cf-guide">';
		echo '<p>' . esc_html__( 'Why it helps: an unforgeable CF-Connecting-IP header, plus Bot Fight Mode and Turnstile, on the free plan.', 'ffcertificate' ) . '</p>';
		echo '<ol>';
		echo '<li>' . esc_html__( 'Create a free Cloudflare account and add this site.', 'ffcertificate' ) . '</li>';
		echo '<li>' . esc_html__( 'Point your domain\'s nameservers to the ones Cloudflare gives you (at your registrar).', 'ffcertificate' ) . '</li>';
		echo '<li>' . esc_html__( 'Enable the proxy (orange cloud) for the site record.', 'ffcertificate' ) . '</li>';
		echo '<li>' . esc_html__( 'Done — the real client IP now reaches PHP via CF-Connecting-IP, and this tab will report "Cloudflare detected".', 'ffcertificate' ) . '</li>';
		echo '</ol>';
		printf(
			'<p><a href="%s" target="_blank" rel="noopener noreferrer">%s</a></p>',
			esc_url( 'https://developers.cloudflare.com/fundamentals/setup/' ),
			esc_html__( 'Official Cloudflare setup guide', 'ffcertificate' )
		);
		echo '<p class="description">' . esc_html__( 'Note: TLS-fingerprint (JA3/JA4) headers are Enterprise-only and out of scope for shared hosting.', 'ffcertificate' ) . '</p>';
		echo '</div>';
	}
}
