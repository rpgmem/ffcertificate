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

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Enqueue the auto-save infrastructure when this tab is active — powers the
	 * `shadow_logging` `.ffc-toggle` switch.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_scripts( string $hook ): void {
		if ( 'toplevel_page_ffc-settings' !== $hook ) {
			return;
		}
		if ( ! $this->is_active() ) {
			return;
		}
		$this->enqueue_autosave_infra();
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
	 *   authoritative:string, legacy:string, secure:string, mode:string,
	 *   forged_legacy:string, forged_secure:string, forged_sentinel:string
	 * }
	 */
	private function diagnostics(): array {
		$remote = $this->server_value( 'REMOTE_ADDR' );

		// CF-Ray is display-only evidence (not an IP): its presence corroborates
		// that a Cloudflare edge injected the request (#920).
		$header_keys = array(
			'X-Forwarded-For'  => 'HTTP_X_FORWARDED_FOR',
			'CF-Connecting-IP' => 'HTTP_CF_CONNECTING_IP',
			'CF-Ray'           => 'HTTP_CF_RAY',
			'X-Real-IP'        => 'HTTP_X_REAL_IP',
			'Client-IP'        => 'HTTP_CLIENT_IP',
			'X-Forwarded'      => 'HTTP_X_FORWARDED',
			'Forwarded'        => 'HTTP_FORWARDED',
		);

		$headers = array();
		foreach ( $header_keys as $label => $server_key ) {
			$headers[ $label ] = $this->server_value( $server_key );
		}

		$verdict = ClientIpResolver::classify();

		list( $forged_legacy, $forged_secure, $sentinel ) = $this->injection_test();

		return array(
			'remote'          => $remote,
			'verdict'         => $verdict,
			'headers'         => $headers,
			'authoritative'   => $this->authoritative_header( $verdict, $headers ),
			'legacy'          => ClientIpResolver::resolve_legacy(),
			'secure'          => ClientIpResolver::resolve_secure(),
			'mode'            => ClientIpResolver::mode(),
			'forged_legacy'   => $forged_legacy,
			'forged_secure'   => $forged_secure,
			'forged_sentinel' => $sentinel,
		);
	}

	/**
	 * Which received header the secure strategy treats as authoritative for the
	 * current verdict — the "✓ autoritativa" marker in the headers table. Empty
	 * when no forwarded header is trusted (direct connection).
	 *
	 * @param string                $verdict Environment verdict.
	 * @param array<string, string> $headers Received header label → value map.
	 * @return string Header label, or '' when none is authoritative.
	 */
	private function authoritative_header( string $verdict, array $headers ): string {
		$cf_verdicts = array(
			ClientIpResolver::VERDICT_CLOUDFLARE,
			ClientIpResolver::VERDICT_CLOUDFLARE_VIA_PROXY,
		);
		if ( in_array( $verdict, $cf_verdicts, true ) && '' !== ( $headers['CF-Connecting-IP'] ?? '' ) ) {
			return 'CF-Connecting-IP';
		}
		if ( ClientIpResolver::VERDICT_TRUSTED_PROXY === $verdict && '' !== ( $headers['X-Forwarded-For'] ?? '' ) ) {
			return 'X-Forwarded-For';
		}
		return '';
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
			case ClientIpResolver::VERDICT_CLOUDFLARE_VIA_PROXY:
				return __( 'Cloudflare detected (behind your host\'s proxy) — CF-Connecting-IP is authoritative.', 'ffcertificate' );
			case ClientIpResolver::VERDICT_TRUSTED_PROXY:
				return __( 'Behind a configured trusted proxy — X-Forwarded-For is honoured.', 'ffcertificate' );
			default:
				return __( 'Direct connection — REMOTE_ADDR is already the client.', 'ffcertificate' );
		}
	}

	/**
	 * CSS status class for a verdict badge — greens for a trusted CDN/proxy
	 * topology, amber for a plain direct connection (spoofable in legacy mode).
	 *
	 * @param string $verdict Environment verdict.
	 * @return string One of the shared `ffc-text-*` helper classes.
	 */
	private function verdict_status_class( string $verdict ): string {
		switch ( $verdict ) {
			case ClientIpResolver::VERDICT_CLOUDFLARE:
			case ClientIpResolver::VERDICT_CLOUDFLARE_VIA_PROXY:
			case ClientIpResolver::VERDICT_TRUSTED_PROXY:
				return 'ffc-text-success ffc-icon-checkmark';
			default:
				return 'ffc-text-warning ffc-icon-warning';
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

		$verdict = (string) $d['verdict'];

		echo '<table class="form-table" role="presentation"><tbody>';

		$this->render_field_row(
			esc_html__( 'REMOTE_ADDR (TCP peer)', 'ffcertificate' ),
			'<code>' . esc_html( $this->mask_ip( (string) $d['remote'] ) ) . '</code> '
			. '<span class="description">' . esc_html( $this->peer_note( $verdict ) ) . '</span>'
		);

		$this->render_field_row(
			esc_html__( 'Environment verdict', 'ffcertificate' ),
			'<span class="' . esc_attr( $this->verdict_status_class( $verdict ) ) . '"><strong>'
			. esc_html( $this->verdict_label( $verdict ) ) . '</strong></span>'
			. $this->verdict_context( $verdict )
		);

		echo '</tbody></table>';

		$this->render_headers_table( $d );
		$this->render_strategy_comparison_table( $d );
		$this->render_cloudflare_cache_status();
	}

	/**
	 * Short parenthetical describing the TCP peer, next to REMOTE_ADDR.
	 *
	 * @param string $verdict Environment verdict.
	 * @return string Plain-text note.
	 */
	private function peer_note( string $verdict ): string {
		switch ( $verdict ) {
			case ClientIpResolver::VERDICT_CLOUDFLARE:
				return __( '(a Cloudflare edge address)', 'ffcertificate' );
			case ClientIpResolver::VERDICT_CLOUDFLARE_VIA_PROXY:
				return __( '(your host\'s reverse proxy — a private/reserved address)', 'ffcertificate' );
			case ClientIpResolver::VERDICT_TRUSTED_PROXY:
				return __( '(a configured trusted proxy)', 'ffcertificate' );
			default:
				return __( '(connecting directly to this server)', 'ffcertificate' );
		}
	}

	/**
	 * Contextual guidance shown right under the verdict badge. For the
	 * Cloudflare-behind-host-proxy topology it explains that secure mode already
	 * trusts CF automatically (so the misleading "put Cloudflare in front" guide
	 * stays hidden), with the manual custom-CIDR route as an advanced fallback.
	 *
	 * @param string $verdict Environment verdict.
	 * @return string Escaped HTML (empty for verdicts needing no note).
	 */
	private function verdict_context( string $verdict ): string {
		if ( ClientIpResolver::VERDICT_CLOUDFLARE_VIA_PROXY !== $verdict ) {
			return '';
		}
		return '<p class="description">' . esc_html__(
			'Cloudflare is in front of this site, reaching PHP through your host\'s reverse proxy. With the Secure strategy the real client IP is taken from CF-Connecting-IP automatically — no extra configuration is needed. (Advanced: to also trust a non-Cloudflare proxy, add its address under Custom below.)',
			'ffcertificate'
		) . '</p>';
	}

	/**
	 * (#920 ③) Received proxy headers with a Situation column — absent /
	 * present / authoritative — so the admin sees at a glance which header the
	 * secure strategy actually trusts.
	 *
	 * @param array<string, mixed> $d Diagnostics.
	 */
	private function render_headers_table( array $d ): void {
		$authoritative = (string) $d['authoritative'];

		$rows = '';
		foreach ( (array) $d['headers'] as $label => $value ) {
			$label = (string) $label;
			$value = (string) $value;
			if ( '' === $value ) {
				$shown     = '<span class="description">—</span>';
				$situation = '<span class="description">' . esc_html__( 'absent', 'ffcertificate' ) . '</span>';
			} else {
				$masked = $this->mask_ip( $value );
				// Non-IP header (a list, or the CF-Ray token): show a safe prefix.
				$display = '—' === $masked ? mb_substr( $value, 0, 60 ) : $masked;
				$shown   = '<code>' . esc_html( $display ) . '</code>';
				if ( $label === $authoritative ) {
					$situation = '<span class="ffc-text-success ffc-icon-checkmark">'
						. esc_html__( '✓ authoritative', 'ffcertificate' ) . '</span>';
				} else {
					$situation = esc_html__( 'present', 'ffcertificate' );
				}
			}
			$rows .= '<tr><td><code>' . esc_html( $label ) . '</code></td><td>' . $shown . '</td><td>' . $situation . '</td></tr>';
		}

		echo '<table class="widefat striped" style="max-width:640px;margin:8px 0 4px">';
		echo '<thead><tr>'
			. '<th>' . esc_html__( 'Header', 'ffcertificate' ) . '</th>'
			. '<th>' . esc_html__( 'Value received', 'ffcertificate' ) . '</th>'
			. '<th>' . esc_html__( 'Situation', 'ffcertificate' ) . '</th>'
			. '</tr></thead>';
		echo '<tbody>' . $rows . '</tbody></table>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $rows assembled from esc_html()/esc_attr() parts above.
	}

	/**
	 * (#920 ②) Unified Legacy × Secure comparison — folds the former "resolved
	 * IP by strategy" and "header-injection self-test" blocks into one table:
	 * a row for the real request and a row for a forged X-Forwarded-For.
	 *
	 * @param array<string, mixed> $d Diagnostics.
	 */
	private function render_strategy_comparison_table( array $d ): void {
		$leaks  = ( (string) $d['forged_legacy'] === (string) $d['forged_sentinel'] );
		$immune = ( (string) $d['forged_secure'] !== (string) $d['forged_sentinel'] );

		$leak_chip = $leaks
			? ' <span class="ffc-text-warning ffc-icon-warning">' . esc_html__( 'trusts the forgery', 'ffcertificate' ) . '</span>'
			: '';
		$safe_chip = $immune
			? ' <span class="ffc-text-success ffc-icon-checkmark">' . esc_html__( 'ignores it', 'ffcertificate' ) . '</span>'
			: '';

		$forged_label = sprintf(
			/* translators: %s: forged sentinel IP address */
			esc_html__( 'With a forged X-Forwarded-For (%s)', 'ffcertificate' ),
			'<code>' . esc_html( (string) $d['forged_sentinel'] ) . '</code>'
		);

		echo '<table class="widefat striped" style="max-width:640px;margin:14px 0 4px">';
		echo '<thead><tr>'
			. '<th>' . esc_html__( 'Scenario', 'ffcertificate' ) . '</th>'
			. '<th>' . esc_html__( 'Legacy →', 'ffcertificate' ) . '</th>'
			. '<th>' . esc_html__( 'Secure →', 'ffcertificate' ) . '</th>'
			. '</tr></thead><tbody>';

		$current_row = '<tr><td>' . esc_html__( 'Current request (real headers)', 'ffcertificate' ) . '</td>'
			. '<td><code>' . esc_html( $this->mask_ip( (string) $d['legacy'] ) ) . '</code></td>'
			. '<td><code>' . esc_html( $this->mask_ip( (string) $d['secure'] ) ) . '</code></td></tr>';

		$forged_row = '<tr><td>' . $forged_label . '</td>'
			. '<td><code>' . esc_html( $this->mask_ip( (string) $d['forged_legacy'] ) ) . '</code>' . $leak_chip . '</td>'
			. '<td><code>' . esc_html( $this->mask_ip( (string) $d['forged_secure'] ) ) . '</code>' . $safe_chip . '</td></tr>';

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rows assembled from esc_html()/esc_html__() parts above.
		echo $current_row . $forged_row . '</tbody></table>';

		$mode_secure = ClientIpResolver::MODE_SECURE === $d['mode'];
		$mode_badge  = $mode_secure
			? '<span class="ffc-text-success ffc-icon-checkmark">' . esc_html__( 'Effective strategy: Secure', 'ffcertificate' ) . '</span>'
			: '<span class="ffc-text-warning ffc-icon-warning">' . esc_html__( 'Effective strategy: Legacy', 'ffcertificate' ) . '</span>';

		$explain = ( $leaks && $immune )
			? esc_html__( 'A forged X-Forwarded-For would fool the Legacy strategy (rate-limit, geofence, activity log). Secure discards it.', 'ffcertificate' )
			: esc_html__( 'Secure does not trust the forged header for this request.', 'ffcertificate' );

		echo '<p class="description">' . $mode_badge . ' — ' . $explain . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- both parts pre-escaped above.
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
		// (#920 ①) Steer admins away from the common mistake of pasting the
		// Cloudflare ranges here — they are detected and refreshed automatically.
		echo '<div class="notice notice-warning inline" style="margin:8px 0 0;max-width:560px"><p>';
		echo '<strong>' . esc_html__( 'Do not paste Cloudflare ranges here.', 'ffcertificate' ) . '</strong> ';
		echo esc_html__( 'They are already detected and kept up to date automatically (official ips-v4/ips-v6 lists, daily refresh) via the Auto or Cloudflare modes. This field is only for your own reverse proxy — nginx, HAProxy or another CDN.', 'ffcertificate' );
		echo '</p></div>';
		echo '</td></tr>';

		// Shadow logging — a `.ffc-toggle` with autosave (allowlisted key
		// `shadow_logging`). Kept a NAMED field in this form so the form Save
		// also writes its current state: autosave + form-save stay consistent,
		// never clobber (the settings-autosave invariant, see CLAUDE.md).
		echo '<tr><th scope="row">' . esc_html__( 'Shadow-divergence logging', 'ffcertificate' ) . '</th><td>';
		\FreeFormCertificate\Admin\AdminUI::render_toggle(
			array(
				'name'    => 'ffc_ip_shadow_logging',
				'id'      => 'ffc_ip_shadow_logging',
				'checked' => $shadow,
				'label'   => __( 'Log (with a hashed IP) when legacy and secure would disagree — measure the impact before switching.', 'ffcertificate' ),
				'data'    => array( 'ffc-autosave-key' => 'shadow_logging' ),
			)
		);
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
