<?php
/**
 * Global admin notice for unsafe encryption secret material (#839 S7a).
 *
 * When {@see \FreeFormCertificate\Core\Encryption::key_health()} is anything
 * other than `ok` (the WordPress secret keys / FFC decoupling constants are
 * missing, weak, or still the wp-config-sample placeholder), the encryption
 * key and the search-hash salt are predictable. This notice surfaces that once,
 * to admins, dismissible — and points at the detailed status panel on
 * Settings → Advanced. It is **non-blocking**: encryption keeps running (see
 * the note on `is_configured()`), the notice only warns.
 *
 * The dismissal is keyed to the current health *signature* (status +
 * key fingerprint), so a worsening state or a key change re-surfaces the
 * warning instead of staying silent forever.
 *
 * @package FreeFormCertificate\Admin
 * @since   6.18.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders + persists the dismissable encryption key-health warning.
 */
class EncryptionKeyHealthNotice {

	const OPTION_DISMISSED = 'ffc_encryption_key_health_dismissed_sig';
	const NONCE_ACTION     = 'ffc_dismiss_encryption_key_health';
	const AJAX_ACTION      = 'ffc_dismiss_encryption_key_health';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'admin_notices', array( self::class, 'maybe_render' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( self::class, 'ajax_dismiss' ) );
	}

	/**
	 * The health signature the dismissal is keyed to: status + active key
	 * fingerprint. A change in either (worsening, or a key rotation) yields a
	 * new signature, so a previously-dismissed notice re-appears.
	 */
	private static function current_signature(): string {
		if ( ! class_exists( '\FreeFormCertificate\Core\Encryption' ) ) {
			return '';
		}
		$report = \FreeFormCertificate\Core\Encryption::key_health_report();
		return (string) $report['status'] . ':' . (string) $report['fingerprint'];
	}

	/**
	 * Render the notice when the keys are unsafe and it has not been dismissed
	 * for the current health signature.
	 */
	public static function maybe_render(): void {
		if ( ! self::should_render() ) {
			return;
		}

		$advanced_url = admin_url( 'admin.php?page=ffc-settings&tab=advanced#ffc-encryption-health' );
		$nonce        = wp_create_nonce( self::NONCE_ACTION );
		?>
		<div class="notice notice-error is-dismissible ffc-js-dismiss-notice ffc-encryption-key-health-notice"
			data-ffc-action="<?php echo esc_attr( self::AJAX_ACTION ); ?>"
			data-ffc-nonce="<?php echo esc_attr( $nonce ); ?>">
			<p>
				<strong><?php esc_html_e( 'Free Form Certificate — encryption keys are not safe', 'ffcertificate' ); ?></strong>
			</p>
			<p>
				<?php
				echo wp_kses(
					sprintf(
						/* translators: %s: link to the encryption key health panel. */
						__( 'Your WordPress secret keys are missing, weak, or still the sample placeholder, so the key that encrypts CPF/RF and e-mail is predictable. Review %s for how to fix it safely (without breaking existing encrypted data).', 'ffcertificate' ),
						'<a href="' . esc_url( $advanced_url ) . '">' . esc_html__( 'Settings → Advanced → Encryption Key Health', 'ffcertificate' ) . '</a>'
					),
					array( 'a' => array( 'href' => array() ) )
				);
				?>
			</p>
		</div>
		<?php
		$s = \FreeFormCertificate\Core\AssetHelper::asset_suffix();
		wp_enqueue_script(
			'ffc-dismissible-notice',
			FFC_PLUGIN_URL . "assets/js/ffc-dismissible-notice{$s}.js",
			array(),
			FFC_VERSION,
			true
		);
	}

	/**
	 * Dismiss endpoint — records the current health signature so only this exact
	 * state stays hidden.
	 */
	public static function ajax_dismiss(): void {
		check_ajax_referer( self::NONCE_ACTION );
		if ( ! self::user_can_manage() ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
		update_option( self::OPTION_DISMISSED, self::current_signature(), false );
		wp_send_json_success();
	}

	/**
	 * Decide whether to render the notice on the current admin request.
	 */
	private static function should_render(): bool {
		if ( ! class_exists( '\FreeFormCertificate\Core\Encryption' ) ) {
			return false;
		}

		// Healthy keys → nothing to warn about.
		if ( 'ok' === \FreeFormCertificate\Core\Encryption::key_health() ) {
			return false;
		}

		if ( ! self::user_can_manage() ) {
			return false;
		}

		// Dismissed for this exact health signature (status + fingerprint)?
		$dismissed = get_option( self::OPTION_DISMISSED, '' );
		$dismissed = is_scalar( $dismissed ) ? (string) $dismissed : '';
		return self::current_signature() !== $dismissed;
	}

	/**
	 * WP admin or an FFC settings manager.
	 */
	private static function user_can_manage(): bool {
		return current_user_can( 'manage_options' )
			|| (
				class_exists( '\FreeFormCertificate\Core\Capabilities' )
				&& \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_settings' )
			);
	}
}
