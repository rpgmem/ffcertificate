<?php
/**
 * One-shot admin notice nudging admins of pre-6.3.2 installs to bump
 * their device fingerprint match threshold from 5 to 7 after the v6.3.2
 * upgrade added 4 new signals (plugins, permissions, mediaqueries, math).
 *
 * The bump default for fresh installs already moved from 5 to 7 in
 * RateLimiter::get_settings() defaults; existing installs keep their
 * persisted value so we don't change behaviour unilaterally. This notice
 * surfaces the suggestion once, dismissable, and only on sites that:
 *   - actively use the device limit (device.enabled === true)
 *   - still have the legacy default (match_threshold === 5)
 *
 * @package FreeFormCertificate\Admin
 * @since   6.3.2
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders + persists a dismissable admin notice for the v6.3.2 device
 * threshold default change.
 */
class DeviceThresholdUpgradeNotice {

	const OPTION_DISMISSED = 'ffc_device_threshold_v632_notice_dismissed';
	const NONCE_ACTION     = 'ffc_dismiss_device_threshold_v632';
	const AJAX_ACTION      = 'ffc_dismiss_device_threshold_v632';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'admin_notices', array( self::class, 'maybe_render' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( self::class, 'ajax_dismiss' ) );
	}

	/**
	 * Render the notice when all gating conditions are met.
	 */
	public static function maybe_render(): void {
		if ( ! self::should_render() ) {
			return;
		}

		$rate_limit_url = admin_url( 'edit.php?post_type=ffc_form&page=ffc-settings&tab=rate_limit' );
		$nonce          = wp_create_nonce( self::NONCE_ACTION );
		?>
		<div class="notice notice-info is-dismissible ffc-device-threshold-notice"
			data-ffc-action="<?php echo esc_attr( self::AJAX_ACTION ); ?>"
			data-ffc-nonce="<?php echo esc_attr( $nonce ); ?>">
			<p>
				<strong><?php esc_html_e( 'Free Form Certificate v6.3.2', 'ffcertificate' ); ?></strong>
				—
				<?php
				echo wp_kses(
					sprintf(
						/* translators: %s: link to the rate-limit settings tab */
						__( 'Four new device fingerprint signals are now active (plugins, permissions, media queries, math precision). Consider raising the match threshold from 5 to 7 in %s to maintain the same false-positive ratio against the larger 13-signal palette.', 'ffcertificate' ),
						'<a href="' . esc_url( $rate_limit_url ) . '">' . esc_html__( 'Settings → Rate Limit → Device Fingerprint', 'ffcertificate' ) . '</a>'
					),
					array( 'a' => array( 'href' => array() ) )
				);
				?>
			</p>
		</div>
		<script>
		(function () {
			var notice = document.querySelector( '.ffc-device-threshold-notice' );
			if ( ! notice ) { return; }
			notice.addEventListener( 'click', function ( e ) {
				if ( ! e.target.classList.contains( 'notice-dismiss' ) ) { return; }
				var action = notice.getAttribute( 'data-ffc-action' );
				var nonce = notice.getAttribute( 'data-ffc-nonce' );
				var body = 'action=' + encodeURIComponent( action ) + '&_ajax_nonce=' + encodeURIComponent( nonce );
				if ( typeof window.fetch === 'function' ) {
					window.fetch( ajaxurl, {
						method: 'POST',
						credentials: 'same-origin',
						headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
						body: body
					} );
				} else if ( window.XMLHttpRequest ) {
					var xhr = new XMLHttpRequest();
					xhr.open( 'POST', ajaxurl, true );
					xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );
					xhr.send( body );
				}
			} );
		}());
		</script>
		<?php
	}

	/**
	 * Dismiss endpoint.
	 */
	public static function ajax_dismiss(): void {
		check_ajax_referer( self::NONCE_ACTION );
		if ( ! current_user_can( 'manage_options' )
			&& ! (
				class_exists( '\FreeFormCertificate\Core\Utils' )
				&& \FreeFormCertificate\Core\Utils::current_user_can_admin_or( 'ffc_manage_settings' )
			)
		) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
		update_option( self::OPTION_DISMISSED, '1', true );
		wp_send_json_success();
	}

	/**
	 * Decide whether to render the notice on the current admin request.
	 */
	private static function should_render(): bool {
		// Already dismissed.
		$dismissed = get_option( self::OPTION_DISMISSED, '' );
		if ( '1' === ( is_scalar( $dismissed ) ? (string) $dismissed : '' ) ) {
			return false;
		}

		// Only show to admins / Certificate Managers.
		$can_manage = current_user_can( 'manage_options' )
			|| (
				class_exists( '\FreeFormCertificate\Core\Utils' )
				&& \FreeFormCertificate\Core\Utils::current_user_can_admin_or( 'ffc_manage_settings' )
			);
		if ( ! $can_manage ) {
			return false;
		}

		if ( ! class_exists( '\FreeFormCertificate\Security\RateLimiter' ) ) {
			return false;
		}

		$device = \FreeFormCertificate\Security\RateLimiter::get_settings()['device'] ?? array();
		if ( empty( $device['enabled'] ) ) {
			return false;
		}

		// Only nudge sites that still hold the legacy default; sites that
		// already moved to 7 (or any other value) have made an active choice.
		return 5 === (int) ( $device['match_threshold'] ?? 0 );
	}
}
