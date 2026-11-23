<?php
/**
 * Audience Terminal Page
 *
 * The check-in terminal's own page (#2744): what a tablet at an event's
 * entrance opens.
 *
 * @package FreeFormCertificate\Audience
 * @since 6.50.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

use FreeFormCertificate\Core\AssetHelper;
use FreeFormCertificate\Settings\SettingsReader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `/?ffc_terminal=1`: a page of its own, outside the site's theme and the
 * app, because a terminal never signs in with anyone's account.
 *
 * It starts on "Waiting for pairing": the operator holds the pairing QR code
 * to the terminal's reader, or types the code. The terminal keeps the token
 * it receives in this browser, and then reads presence codes (#2745).
 *
 * The page builds its own document but loads its sheets and scripts through
 * their registered handles, so the asset guards see them like any other.
 */
final class AudienceTerminalPage {

	/**
	 * The query variable that opens the page.
	 */
	public const QUERY_VAR = 'ffc_terminal';

	/**
	 * The option that lets a terminal read codes with its own camera (#2747).
	 */
	public const CAMERA_OPTION = 'ffc_aud_terminal_camera';

	/**
	 * Whether a terminal may read codes with its own camera. Off unless an
	 * administrator turned it on: the wedge reader is the recommended mode.
	 *
	 * @return bool
	 */
	public static function camera_allowed(): bool {
		return '1' === (string) get_option( self::CAMERA_OPTION, '0' );
	}

	/**
	 * Hook the page in.
	 *
	 * The page renders on `template_redirect` at priority 1, ahead of the
	 * canonical redirect at 10, so `/?ffc_terminal=1` is never rewritten
	 * before it is answered.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'query_vars', array( self::class, 'query_vars' ) );
		add_action( 'template_redirect', array( self::class, 'maybe_render' ), 1 );
	}

	/**
	 * Register the query variable.
	 *
	 * @param mixed $vars Query variables.
	 * @return mixed
	 */
	public static function query_vars( $vars ) {
		if ( is_array( $vars ) ) {
			$vars[] = self::QUERY_VAR;
		}
		return $vars;
	}

	/**
	 * The page's address.
	 *
	 * @return string
	 */
	public static function url(): string {
		return add_query_arg( self::QUERY_VAR, '1', home_url( '/' ) );
	}

	/**
	 * Render the page when it was asked for.
	 *
	 * @return void
	 */
	public static function maybe_render(): void {
		if ( '' === (string) get_query_var( self::QUERY_VAR ) ) {
			return;
		}
		if ( ! SettingsReader::module_enabled( 'audiences' ) ) {
			return;
		}
		self::send_headers();
		echo self::document(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- document() escapes every value it prints.
		exit;
	}

	/**
	 * No cache may keep the page: it hands a terminal its credential.
	 *
	 * @return void
	 */
	private static function send_headers(): void {
		if ( headers_sent() ) {
			return;
		}
		nocache_headers();
		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Referrer-Policy: no-referrer' );
	}

	/**
	 * The whole document.
	 *
	 * @return string
	 */
	public static function document(): string {
		self::enqueue();

		ob_start();
		echo '<!DOCTYPE html><html ' . get_language_attributes() . self::root_class() . '><head>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_language_attributes() returns a safe attribute string; root_class() returns one of two literals.
		echo '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
		echo '<meta name="robots" content="noindex,nofollow">';
		echo '<title>' . esc_html__( 'Check-in terminal', 'ffcertificate' ) . ' — ' . esc_html( get_bloginfo( 'name' ) ) . '</title>';
		wp_print_styles( array( 'ffc-terminal-kiosk' ) );
		wp_print_scripts( array( 'ffc-dark-mode' ) );
		echo '</head><body class="ffc-shortcode ffc-terminal-page">';
		include FFC_PLUGIN_DIR . 'templates/public/terminal/kiosk.php';
		wp_print_scripts( array( 'ffc-terminal-kiosk' ) );
		echo '</body></html>';
		return (string) ob_get_clean();
	}

	/**
	 * Register the page's sheet and script, and what the script needs.
	 *
	 * @return void
	 */
	private static function enqueue(): void {
		AssetHelper::enqueue_common_style();
		AssetHelper::enqueue_dark_mode();

		$suffix = AssetHelper::asset_suffix();
		wp_enqueue_style(
			'ffc-terminal-kiosk',
			FFC_PLUGIN_URL . "assets/css/ffc-terminal-kiosk{$suffix}.css",
			array( 'ffc-common' ),
			FFC_VERSION
		);
		wp_enqueue_script(
			'ffc-presence-verifier',
			FFC_PLUGIN_URL . "assets/js/ffc-presence-verifier{$suffix}.js",
			array(),
			FFC_VERSION,
			true
		);
		wp_enqueue_script(
			'ffc-terminal-kiosk',
			FFC_PLUGIN_URL . "assets/js/ffc-terminal-kiosk{$suffix}.js",
			array( 'ffc-presence-verifier' ),
			FFC_VERSION,
			true
		);
		wp_localize_script(
			'ffc-terminal-kiosk',
			'ffcTerminal',
			array(
				'rest'     => esc_url_raw( rest_url( AudienceTerminalRestController::NAMESPACE . '/' . AudienceTerminalRestController::REST_BASE . '/' ) ),
				'presence' => esc_url_raw( rest_url( AudiencePresenceRestController::NAMESPACE . '/' . AudiencePresenceRestController::REST_BASE . '/' ) ),
				'camera'   => self::camera_allowed(),
				'strings'  => array(
					'storage'       => __( 'This browser cannot keep the terminal\'s pairing. Allow this site to store data, or use another browser.', 'ffcertificate' ),
					'pairing'       => __( 'Pairing…', 'ffcertificate' ),
					'paired'        => __( 'Terminal paired.', 'ffcertificate' ),
					'failed'        => __( 'The terminal could not reach the site. Check the connection and try again.', 'ffcertificate' ),
					/* translators: %s: minutes and seconds left, such as 14:59 */
					'locked'        => __( 'Too many wrong codes. Try again in %s.', 'ffcertificate' ),
					/* translators: %d: number of events */
					'events'        => __( 'Events: %d', 'ffcertificate' ),
					/* translators: %s: date and time */
					'until'         => __( 'Works until %s', 'ffcertificate' ),
					'expired'       => __( 'This terminal\'s pairing has ended. Ask an operator for a new pairing code.', 'ffcertificate' ),
					/* translators: %s: the operator's short name, such as Maria S. */
					'operator'      => __( 'Unlocked by %s.', 'ffcertificate' ),
					'ended'         => __( 'Terminal ended. Pair it again with a new code from the app.', 'ffcertificate' ),
					'queued'        => __( 'Saved. It is sent to the site when the connection returns.', 'ffcertificate' ),
					/* translators: Offline: this person's reading for this event is already waiting to be sent. */
					'alreadyQueued' => __( 'Already saved on this terminal.', 'ffcertificate' ),
					/* translators: %d: number of readings waiting to be sent */
					'queue'         => __( 'Readings waiting to be sent: %d', 'ffcertificate' ),
					/* translators: %d: number of readings sent */
					'sent'          => __( 'Readings sent: %d', 'ffcertificate' ),
					'nowChecking'   => __( 'Checking in now', 'ffcertificate' ),
					'nextEvent'     => __( 'Next event', 'ffcertificate' ),
					/* translators: %s: time check-in closes */
					'openUntil'     => __( 'Check-in open until %s', 'ffcertificate' ),
					'notOpen'       => __( 'Check-in opens when the organiser opens it.', 'ffcertificate' ),
					/* translators: %s: date and time check-in opens */
					'opensAt'       => __( 'Check-in opens at %s', 'ffcertificate' ),
					'noEvents'      => __( 'No more events on this terminal.', 'ffcertificate' ),
					'camera'        => array(
						'ready'       => __( 'The browser asks for permission to use the camera. Allow it, then hold the presence code inside the frame.', 'ffcertificate' ),
						'reading'     => __( 'Hold the presence code inside the frame.', 'ffcertificate' ),
						'insecure'    => __( 'The camera needs this page to be served over HTTPS. Use a reader connected to the device.', 'ffcertificate' ),
						'unsupported' => __( 'This browser cannot read QR codes with the camera. Use a reader connected to the device.', 'ffcertificate' ),
						'denied'      => __( 'The camera was not allowed. Allow it in the browser\'s site settings, or use a reader connected to the device.', 'ffcertificate' ),
						'failed'      => __( 'The camera could not be started. Use a reader connected to the device.', 'ffcertificate' ),
					),
					'offline'       => array(
						'invalid'                          => __( 'This is not a valid presence code. Show the code on the phone again.', 'ffcertificate' ),
						'ffc_presence_code_stale'          => __( 'This presence code is old. Show the code on the phone again.', 'ffcertificate' ),
						'ffc_presence_code_future'         => __( 'This presence code is dated in the future. Check the phone\'s clock.', 'ffcertificate' ),
						'ffc_presence_code_revoked'        => __( 'This phone was replaced or revoked. Activate the new one in the app.', 'ffcertificate' ),
						'ffc_presence_attestation_expired' => __( 'This presence code has expired. Open the app while online to renew it.', 'ffcertificate' ),
						'ffc_presence_ticket_expired'      => __( 'The event ticket in this presence code has expired.', 'ffcertificate' ),
						'ffc_presence_ticket_mismatch'     => __( 'The event ticket in this presence code is for another person or event.', 'ffcertificate' ),
						'ffc_terminal_other_event'         => __( 'This code is for an event this terminal does not check in. Choose this event on the phone, or use the terminal at its entrance.', 'ffcertificate' ),
						'full'                             => __( 'This terminal cannot keep more readings offline. Connect it to the internet to send them.', 'ffcertificate' ),
					),
				),
			)
		);
	}

	/**
	 * The dark theme's class on the root element, when the site chose it, so
	 * the first paint is already right; the dark-mode script printed in the
	 * head handles Auto, as on every other page.
	 *
	 * @return string
	 */
	private static function root_class(): string {
		return 'on' === (string) SettingsReader::get( 'dark_mode', 'off' ) ? ' class="ffc-dark-mode"' : '';
	}
}
