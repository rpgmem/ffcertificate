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
			'ffc-terminal-kiosk',
			FFC_PLUGIN_URL . "assets/js/ffc-terminal-kiosk{$suffix}.js",
			array(),
			FFC_VERSION,
			true
		);
		wp_localize_script(
			'ffc-terminal-kiosk',
			'ffcTerminal',
			array(
				'rest'    => esc_url_raw( rest_url( AudienceTerminalRestController::NAMESPACE . '/' . AudienceTerminalRestController::REST_BASE . '/' ) ),
				'strings' => array(
					'storage'  => __( 'This browser cannot keep the terminal\'s pairing. Allow this site to store data, or use another browser.', 'ffcertificate' ),
					'pairing'  => __( 'Pairing…', 'ffcertificate' ),
					'paired'   => __( 'Terminal paired.', 'ffcertificate' ),
					'failed'   => __( 'The terminal could not reach the site. Check the connection and try again.', 'ffcertificate' ),
					/* translators: %s: minutes and seconds left, such as 14:59 */
					'locked'   => __( 'Too many wrong codes. Try again in %s.', 'ffcertificate' ),
					/* translators: %d: number of events */
					'events'   => __( 'Events: %d', 'ffcertificate' ),
					/* translators: %s: date and time */
					'until'    => __( 'Works until %s', 'ffcertificate' ),
					'expired'  => __( 'This terminal\'s pairing has ended. Ask an operator for a new pairing code.', 'ffcertificate' ),
					/* translators: %s: the operator's short name, such as Maria S. */
					'operator' => __( 'Unlocked by %s.', 'ffcertificate' ),
					'ended'    => __( 'Terminal ended. Pair it again with a new code from the app.', 'ffcertificate' ),
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
