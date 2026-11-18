<?php
/**
 * SigningKeyHandler
 *
 * Generates the first certificate signing key, or rotates to a new one,
 * from the "Integrity signature" card in Settings → Certificates (#2639).
 *
 * @package FreeFormCertificate\Admin
 * @since 6.45.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

use FreeFormCertificate\Core\ActivityLog;
use FreeFormCertificate\Core\Capabilities;
use FreeFormCertificate\Core\SigningKeys;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The admin-post route behind the signing key card.
 *
 * Generating and rotating need the Danger Zone capability: a new key changes
 * what every certificate issued from then on is signed with. Nothing is ever
 * deleted here; a rotated key is retired and keeps verifying what it signed.
 */
final class SigningKeyHandler {

	/** The `admin_post_*` action. */
	public const ACTION = 'ffc_signing_key';

	/** The capability that gates the route. */
	public const CAP = 'ffc_manage_settings_dangerzone';

	/** Activity log action written on every generated key. */
	public const LOG_GENERATED = 'signing_key_generated';

	/** The SettingsNotice area of the result. */
	public const NOTICE_AREA = 'signing_key';

	/**
	 * Register the request hook.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Whether the current user may generate or rotate a key.
	 */
	public static function current_user_can(): bool {
		return Capabilities::current_user_can_admin_or( self::CAP );
	}

	/**
	 * Generate or rotate, then return to the card.
	 *
	 * @return void
	 */
	public function handle(): void {
		check_admin_referer( self::ACTION );
		if ( ! self::current_user_can() ) {
			wp_die( esc_html__( 'You do not have permission to manage the signing key.', 'ffcertificate' ), '', array( 'response' => 403 ) );
		}

		$back = admin_url( 'admin.php?page=ffc-settings&tab=certificates' );
		$url  = self::run( $back );

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * The work of the route, apart from the request plumbing.
	 *
	 * @param string $back URL of the card.
	 * @return string The URL to return to, with the result notice.
	 */
	public static function run( string $back ): string {
		$previous = SigningKeys::active_id();
		$id       = SigningKeys::rotate();
		if ( null === $id ) {
			return SettingsNotice::add( $back, self::NOTICE_AREA, 'error', __( 'The signing key could not be generated: this server cannot create Ed25519 keys.', 'ffcertificate' ) );
		}

		ActivityLog::log(
			self::LOG_GENERATED,
			ActivityLog::LEVEL_INFO,
			array(
				'key'      => $id,
				'previous' => $previous,
			),
			get_current_user_id()
		);

		$message = '' === $previous
			/* translators: %s: key id, such as k1 */
			? sprintf( __( 'Signing key %s generated. Forms with a signed QR mode now sign every certificate they issue.', 'ffcertificate' ), $id )
			/* translators: 1: new key id; 2: previous key id */
			: sprintf( __( 'Signing key %1$s is now active. Key %2$s is retired and still verifies the certificates it signed.', 'ffcertificate' ), $id, $previous );
		return SettingsNotice::add( $back, self::NOTICE_AREA, 'success', $message );
	}
}
