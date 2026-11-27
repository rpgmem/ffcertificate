<?php
/**
 * CertificatesAppScreen
 *
 * The Certificates screen of the installable app (#2781): one search field,
 * the newest certificate as a featured card and the rest as compact rows.
 * It reads the same `user/certificates` route as the dashboard, so the
 * rules about who may download what stay in one place.
 *
 * @package FreeFormCertificate\Shortcodes
 * @since   6.50.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Shortcodes;

use FreeFormCertificate\Core\AssetHelper;
use FreeFormCertificate\Frontend\CertificateCompletionLink;
use FreeFormCertificate\Frontend\FormInvite;
use FreeFormCertificate\Repositories\SubmissionRepository;
use FreeFormCertificate\Settings\SettingsReader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Draws the app's Certificates screen; the dashboard outside the app keeps
 * its own table.
 */
final class CertificatesAppScreen {

	/**
	 * How long a certificate counts as new, in days.
	 */
	public const NEW_DAYS = 7;

	/**
	 * User meta holding when the person last opened the screen (unix time),
	 * which the badge counts from.
	 */
	public const SEEN_META = 'ffc_app_certificates_seen';

	/**
	 * The most the badge counts before it reads "9+".
	 */
	private const BADGE_MAX = 9;

	/**
	 * The badge on the Certificates item of the app's bar (#2893): how many
	 * certificates were issued since the person last opened the screen,
	 * never older than NEW_DAYS, so a first visit or a long absence does
	 * not count a whole history. '' when there is none.
	 *
	 * @param int|null $user_id Person; the signed-in one when null.
	 * @return string
	 */
	public static function badge( ?int $user_id = null ): string {
		$user_id = $user_id ?? get_current_user_id();
		if ( $user_id <= 0 || ! SettingsReader::module_enabled( 'certificates' ) || ! user_can( $user_id, 'ffc_view_own_certificates' ) ) {
			return '';
		}
		$seen  = get_user_meta( $user_id, self::SEEN_META, true );
		$since = max( is_numeric( $seen ) ? (int) $seen : 0, time() - self::NEW_DAYS * DAY_IN_SECONDS );
		$count = ( new SubmissionRepository() )->count_certificates_for_user_since( $user_id, $since );
		if ( $count <= 0 ) {
			return '';
		}
		return $count > self::BADGE_MAX ? self::BADGE_MAX . '+' : (string) $count;
	}

	/**
	 * The screen's markup, with its sheet and script enqueued.
	 *
	 * @return string
	 */
	public static function render(): string {
		AssetHelper::enqueue_common_style();
		AssetHelper::enqueue_dark_mode();

		$suffix = AssetHelper::asset_suffix();
		wp_enqueue_style(
			'ffc-app-certificates',
			FFC_PLUGIN_URL . "assets/css/ffc-app-certificates{$suffix}.css",
			array( 'ffc-common' ),
			FFC_VERSION
		);
		wp_enqueue_script(
			'ffc-app-certificates',
			FFC_PLUGIN_URL . "assets/js/ffc-app-certificates{$suffix}.js",
			array(),
			FFC_VERSION,
			true
		);
		wp_localize_script(
			'ffc-app-certificates',
			'ffcAppCertificates',
			array(
				'rest'    => esc_url_raw( rest_url( 'ffc/v1/user/certificates' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'newDays' => self::NEW_DAYS,
				'strings' => array(
					'loading'  => __( 'Loading your certificates…', 'ffcertificate' ),
					'failed'   => __( 'Your certificates could not be loaded. Check the connection and try again.', 'ffcertificate' ),
					'empty'    => __( 'You have no certificate yet.', 'ffcertificate' ),
					'noMatch'  => __( 'No certificate matches the search.', 'ffcertificate' ),
					'new'      => __( 'New', 'ffcertificate' ),
					'valid'    => __( 'Valid', 'ffcertificate' ),
					/* translators: %s: the date the certificate was issued */
					'issued'   => __( 'Issued on %s', 'ffcertificate' ),
					/* translators: %s: the certificate's authentication code */
					'code'     => __( 'Code %s', 'ffcertificate' ),
					'open'     => __( 'Open PDF', 'ffcertificate' ),
					'noAccess' => __( 'Downloading is not available for this account.', 'ffcertificate' ),
				),
			)
		);

		// Opening the screen is seeing what is new: the badge counts from here.
		if ( is_user_logged_in() ) {
			update_user_meta( get_current_user_id(), self::SEEN_META, time() );
		}

		$user_id = is_user_logged_in() ? get_current_user_id() : 0;

		// Asked to complete one: the form opens here, so a form used only at
		// the terminal, with no page of its own, can still be finished (#2936).
		$requested = $user_id > 0 ? CertificateCompletionLink::requested( $user_id, time() ) : null;
		if ( null !== $requested ) {
			return self::render_completion( $requested );
		}

		// Certificates waiting on profile data after a terminal reading
		// (#2893): drawn here, so a lost e-mail does not lose them. Each
		// button opens the form on this screen, whatever the e-mail links to.
		$to_complete = array();
		if ( $user_id > 0 ) {
			$here = remove_query_arg( array( CertificateCompletionLink::SCREEN_VAR, FormInvite::QUERY_VAR ) );
			foreach ( CertificateCompletionLink::pending_for_user( $user_id, time() ) as $item ) {
				$item['url']   = CertificateCompletionLink::screen_link( $here, $item['form_id'], $user_id );
				$to_complete[] = $item;
			}
		}

		ob_start();
		include FFC_PLUGIN_DIR . 'templates/public/app/certificates.php';
		return (string) ob_get_clean();
	}

	/**
	 * The form a person completes inside the app, or why it does not open.
	 *
	 * The `[ffc_form]` shortcode reads the person's code from the address,
	 * fills the form from the profile and lifts its hours, as on the form's
	 * page; it enqueues its own scripts as it renders.
	 *
	 * @param array{form_id: int, error: string, code: string} $requested What the screen was asked to open.
	 * @return string
	 */
	private static function render_completion( array $requested ): string {
		$ffc_back  = remove_query_arg( array( CertificateCompletionLink::SCREEN_VAR, FormInvite::QUERY_VAR ) );
		$ffc_error = $requested['error'];
		$ffc_code  = $requested['code'];
		$ffc_title = get_the_title( $requested['form_id'] );
		$ffc_form  = '' === $ffc_error ? do_shortcode( sprintf( '[ffc_form id="%d"]', $requested['form_id'] ) ) : '';

		ob_start();
		include FFC_PLUGIN_DIR . 'templates/public/app/certificate-complete.php';
		return (string) ob_get_clean();
	}
}
