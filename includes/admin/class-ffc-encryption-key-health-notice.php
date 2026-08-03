<?php
/**
 * Global admin notice for unsafe encryption secret material (#839 S7a).
 *
 * When {@see \FreeFormCertificate\Core\Encryption::key_health()} is anything
 * other than `ok` (the WordPress secret keys / FFC decoupling constants are
 * missing, weak, or still the wp-config-sample placeholder), the encryption
 * key and the search-hash salt are predictable. This notice surfaces that,
 * to admins, dismissible — and points at the detailed status panel on
 * Settings → Advanced. It is **non-blocking**: encryption keeps running (see
 * the note on `is_configured()`), the notice only warns.
 *
 * The dismissal is keyed to the current health *signature* (status +
 * key fingerprint), so a worsening state or a key change re-surfaces the
 * warning instead of staying silent forever. The shared hook/dismiss/gate
 * plumbing lives in {@see AbstractDismissibleNotice} (#849).
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
class EncryptionKeyHealthNotice extends AbstractDismissibleNotice {

	const OPTION_DISMISSED = 'ffc_encryption_key_health_dismissed_sig';
	const NONCE_ACTION     = 'ffc_dismiss_encryption_key_health';
	const AJAX_ACTION      = 'ffc_dismiss_encryption_key_health';

	protected static function option_key(): string {
		return self::OPTION_DISMISSED;
	}

	protected static function action(): string {
		return self::AJAX_ACTION;
	}

	protected static function notice_type(): string {
		return 'error';
	}

	protected static function extra_class(): string {
		return 'ffc-encryption-key-health-notice';
	}

	/**
	 * Show only when the active secret material is not healthy.
	 */
	protected static function should_show(): bool {
		return class_exists( '\FreeFormCertificate\Core\Encryption' )
			&& 'ok' !== \FreeFormCertificate\Core\Encryption::key_health();
	}

	/**
	 * Signature = health status + active key fingerprint. A change in either
	 * (worsening, or a key rotation) yields a new signature, so a previously-
	 * dismissed notice re-appears.
	 */
	protected static function dismiss_signature(): string {
		if ( ! class_exists( '\FreeFormCertificate\Core\Encryption' ) ) {
			return '';
		}
		$report = \FreeFormCertificate\Core\Encryption::key_health_report();
		return (string) $report['status'] . ':' . (string) $report['fingerprint'];
	}

	protected static function notice_message(): string {
		$advanced_url = admin_url( 'admin.php?page=ffc-settings&tab=advanced#ffc-encryption-health' );

		$title = '<p><strong>'
			. esc_html__( 'Free Form Certificate — encryption keys are not safe', 'ffcertificate' )
			. '</strong></p>';

		$body = '<p>' . wp_kses(
			sprintf(
				/* translators: %s: link to the encryption key health panel. */
				__( 'Your WordPress secret keys are missing, weak, or still the sample placeholder, so the key that encrypts CPF/RF and e-mail is predictable. Review %s for how to fix it safely (without breaking existing encrypted data).', 'ffcertificate' ),
				'<a href="' . esc_url( $advanced_url ) . '">' . esc_html__( 'Settings → Advanced → Encryption Key Health', 'ffcertificate' ) . '</a>'
			),
			array( 'a' => array( 'href' => array() ) )
		) . '</p>';

		return $title . $body;
	}
}
