<?php
/**
 * DeviceCheck
 *
 * The "Test my device" mode of the verification page (#2040).
 *
 * `[ffc_verification]` shows this instead of its form when the URL carries
 * `?ffc_device_check=1`, so the test lives on a page the site already has,
 * inside its theme, with no route of its own. It lists the checks a form runs
 * before it is used (secure connection, browser, cookies, location, PDF) and
 * `assets/js/ffc-device-check.js` writes each result into the page. Nothing is
 * sent or stored, and the position is never shown: only how precise it is.
 *
 * The verification form links here from its footer, and the test's script
 * and stylesheet load only in this mode.
 *
 * @package FreeFormCertificate\Frontend
 */

declare(strict_types=1);

namespace FreeFormCertificate\Frontend;

use FreeFormCertificate\Core\Button;
use FreeFormCertificate\Core\Icons;
use FreeFormCertificate\Core\RequestInput;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders and wires the device test.
 */
final class DeviceCheck {

	/**
	 * The query argument that switches the verification page to the test.
	 */
	public const QUERY_VAR = 'ffc_device_check';

	/**
	 * The checks, in the order shown, each with its icon.
	 *
	 * @var array<string, string>
	 */
	private const CHECKS = array(
		'secure'     => 'lock',
		'browser'    => 'monitor',
		'cookies'    => 'cookie',
		'storage'    => 'database',
		'captcha'    => 'robot',
		'device'     => 'id-card',
		'clock'      => 'clock',
		'locale'     => 'globe',
		'location'   => 'map-pin',
		'accuracy'   => 'map-pin',
		'pdf'        => 'file',
		'screen'     => 'layout',
		'connection' => 'wifi',
		'theme'      => 'palette',
	);

	/**
	 * Whether this request asks for the test.
	 *
	 * @return bool
	 */
	public static function requested(): bool {
		return '1' === RequestInput::get_get_string( self::QUERY_VAR );
	}

	/**
	 * The address of the test on a verification page.
	 *
	 * @param string $page_url The verification page's address.
	 * @return string
	 */
	public static function url( string $page_url ): string {
		return add_query_arg( self::QUERY_VAR, '1', remove_query_arg( 'token', $page_url ) );
	}

	/**
	 * The checks' labels, keyed as the rows are.
	 *
	 * @return array<string, string>
	 */
	private static function labels(): array {
		return array(
			'secure'     => __( 'Secure connection', 'ffcertificate' ),
			'browser'    => __( 'Browser', 'ffcertificate' ),
			'cookies'    => __( 'Cookies', 'ffcertificate' ),
			'storage'    => __( 'Local storage', 'ffcertificate' ),
			'captcha'    => __( 'Captcha', 'ffcertificate' ),
			'device'     => __( 'Device ID', 'ffcertificate' ),
			'clock'      => __( 'Device clock', 'ffcertificate' ),
			'locale'     => __( 'Time zone and language', 'ffcertificate' ),
			'location'   => __( 'Location permission', 'ffcertificate' ),
			'accuracy'   => __( 'Location precision', 'ffcertificate' ),
			'pdf'        => __( 'PDF download', 'ffcertificate' ),
			'screen'     => __( 'Screen', 'ffcertificate' ),
			'connection' => __( 'Connection', 'ffcertificate' ),
			'theme'      => __( 'Theme', 'ffcertificate' ),
		);
	}

	/**
	 * The test's markup.
	 *
	 * @param string $back_url Where "Back to verification" leads.
	 * @return string
	 */
	public static function render( string $back_url = '' ): string {
		$labels = self::labels();
		$rows   = '';
		foreach ( self::CHECKS as $key => $icon ) {
			$rows .= '<li class="ffc-device-check-row is-info" data-ffc-check="' . esc_attr( $key ) . '">'
				. '<span class="ffc-device-check-label">' . Icons::svg( $icon, 18 ) . esc_html( $labels[ $key ] ) . '</span>'
				. '<span class="ffc-device-check-result">' . esc_html__( 'Checking…', 'ffcertificate' ) . '</span>'
				. '</li>';
		}

		$html  = '<div class="ffc-shortcode ffc-device-check">';
		$html .= '<h2 class="ffc-device-check-title">' . Icons::svg( 'monitor', 24 ) . esc_html__( 'Test my device', 'ffcertificate' ) . '</h2>';
		$html .= '<p class="ffc-device-check-intro">' . esc_html__( 'This page runs the checks a form runs before it is used and shows each result. Nothing is sent or stored, and your position is never shown: only how precise it is.', 'ffcertificate' ) . '</p>';
		$html .= '<noscript><p class="ffc-device-check-noscript">' . esc_html__( 'JavaScript is off in this browser. The forms need it: turn it on and reload this page.', 'ffcertificate' ) . '</p></noscript>';
		$html .= '<ul class="ffc-device-check-list">' . $rows . '</ul>';
		$html .= '<div class="ffc-device-check-actions">';
		$html .= Button::html(
			array(
				'label'    => __( 'Test my location', 'ffcertificate' ),
				'category' => 'primary',
				'icon'     => 'map-pin',
				'class'    => 'ffc-device-check-locate',
			)
		);
		$html .= Button::html(
			array(
				'label' => __( 'Copy the results', 'ffcertificate' ),
				'icon'  => 'copy',
				'class' => 'ffc-device-check-copy',
			)
		);
		$html .= '</div>';
		$html .= '<p class="ffc-device-check-help">' . esc_html__( 'To ask for help, send a screenshot of this page or paste the copied results.', 'ffcertificate' ) . '</p>';
		if ( '' !== $back_url ) {
			$html .= '<p class="ffc-device-check-back"><a href="' . esc_url( $back_url ) . '">' . esc_html__( 'Back to certificate verification', 'ffcertificate' ) . '</a></p>';
		}
		$html .= '</div>';

		return $html;
	}

	/**
	 * The strings the script writes into the rows.
	 *
	 * @return array<string, string>
	 */
	public static function strings(): array {
		return array(
			'secureYes'           => __( 'Yes', 'ffcertificate' ),
			'secureNo'            => __( 'No: the browser gives no location here, and forms that ask for it will stop.', 'ffcertificate' ),
			/* translators: %s: the kind of device (iPhone or iPad, Android, computer). */
			'browserOk'           => __( 'Ordinary browser (%s)', 'ffcertificate' ),
			/* translators: 1: the browser's name and version, such as "Chrome (v. 129.0.6668.81)", 2: the kind of device (iPhone or iPad, Android, computer). */
			'browserNamed'        => __( '%1$s — %2$s', 'ffcertificate' ),
			/* translators: %s: the kind of device (iPhone or iPad, Android, computer), after the app's name when it is known. */
			'browserInApp'        => __( 'An app\'s own browser (%s): the location and downloads often fail. Open the page in the phone\'s browser.', 'ffcertificate' ),
			'cookiesYes'          => __( 'Allowed', 'ffcertificate' ),
			'cookiesNo'           => __( 'Blocked: sending a form may fail.', 'ffcertificate' ),
			'locationGranted'     => __( 'Allowed', 'ffcertificate' ),
			'locationPrompt'      => __( 'Not decided yet: the browser will ask.', 'ffcertificate' ),
			'locationDenied'      => __( 'Blocked: allow it in the browser settings.', 'ffcertificate' ),
			'locationUnknown'     => __( 'The browser does not say until it asks.', 'ffcertificate' ),
			'noGeolocation'       => __( 'This browser cannot give a location.', 'ffcertificate' ),
			'accuracyIdle'        => __( 'Press "Test my location".', 'ffcertificate' ),
			'accuracyReading'     => __( 'Reading your location…', 'ffcertificate' ),
			/* translators: 1: margin of error in metres, 2: seconds the reading took. */
			'accuracyResult'      => __( 'About %1$s m, read in %2$s s', 'ffcertificate' ),
			'accuracyDenied'      => __( 'Refused by the browser or by you.', 'ffcertificate' ),
			'accuracyFailed'      => __( 'Could not be read: move to an open area and try again.', 'ffcertificate' ),
			'accuracyTimeout'     => __( 'Took too long: move to an open area and try again.', 'ffcertificate' ),
			'pdfYes'              => __( 'Supported: this browser can build and save the PDF.', 'ffcertificate' ),
			/* translators: %s: the browser features that are missing. */
			'pdfNo'               => __( 'Not supported: missing %s.', 'ffcertificate' ),
			'copied'              => __( 'Copied', 'ffcertificate' ),
			'copyFailed'          => __( 'Could not copy: take a screenshot instead.', 'ffcertificate' ),
			'deviceIos'           => __( 'iPhone or iPad', 'ffcertificate' ),
			'deviceAndroid'       => __( 'Android', 'ffcertificate' ),
			'deviceDesktop'       => __( 'computer', 'ffcertificate' ),
			'deviceOff'           => __( 'Not used on this site: the device limit is off.', 'ffcertificate' ),
			'deviceNone'          => __( 'Not available in this browser: it needs a secure connection and a recent browser.', 'ffcertificate' ),
			'deviceReading'       => __( 'Reading the device signals…', 'ffcertificate' ),
			/* translators: 1: short device code, 2: signals read, 3: signals the site uses. */
			'deviceStable'        => __( '%1$s · %2$s of %3$s signals · stable', 'ffcertificate' ),
			/* translators: 1: short device code, 2: signals read, 3: signals the site uses, 4: the signals that changed. */
			'deviceUnstable'      => __( '%1$s · %2$s of %3$s signals · changes on each read: %4$s', 'ffcertificate' ),
			'deviceFailed'        => __( 'The device signals could not be read.', 'ffcertificate' ),
			/* translators: 1: the theme shown (Light or Dark), 2: the site's setting, 3: the system preference. */
			'themeResult'         => __( '%1$s (site: %2$s; system: %3$s)', 'ffcertificate' ),
			'themeLight'          => __( 'Light', 'ffcertificate' ),
			'themeDark'           => __( 'Dark', 'ffcertificate' ),
			'themeSiteOff'        => __( 'always light', 'ffcertificate' ),
			'themeSiteOn'         => __( 'always dark', 'ffcertificate' ),
			'themeSiteAuto'       => __( 'follows the system', 'ffcertificate' ),
			'themeSystemDark'     => __( 'dark', 'ffcertificate' ),
			'themeSystemLight'    => __( 'light', 'ffcertificate' ),
			'themeSystemNone'     => __( 'not stated', 'ffcertificate' ),
			'storageYes'          => __( 'Allowed', 'ffcertificate' ),
			'storageNo'           => __( 'Blocked (a private window?): the "already submitted" notice and "Continue anyway" will not be remembered.', 'ffcertificate' ),
			'captchaYes'          => __( 'Supported', 'ffcertificate' ),
			/* translators: %s: the browser features that are missing. */
			'captchaNo'           => __( 'Not supported: missing %s. A form whose captcha is ALTCHA only cannot be sent.', 'ffcertificate' ),
			'clockReading'        => __( 'Comparing with the server…', 'ffcertificate' ),
			/* translators: %s: the difference, such as "2 s". */
			'clockOk'             => __( 'Correct (differs by %s)', 'ffcertificate' ),
			/* translators: %s: how far ahead, such as "7 min". */
			'clockAhead'          => __( 'Ahead by %s: date windows and the captcha may fail. Set the clock to automatic.', 'ffcertificate' ),
			/* translators: %s: how far behind, such as "7 min". */
			'clockBehind'         => __( 'Behind by %s: date windows and the captcha may fail. Set the clock to automatic.', 'ffcertificate' ),
			'clockUnknown'        => __( 'Could not compare with the server.', 'ffcertificate' ),
			/* translators: 1: viewport width × height, 2: screen width × height, 3: zoom factor. */
			'screenResult'        => __( 'Window %1$s · screen %2$s · zoom %3$s×', 'ffcertificate' ),
			/* translators: %s: the connection type the browser reports, such as "4g". */
			'connectionType'      => __( 'Online · %s', 'ffcertificate' ),
			'connectionSaver'     => __( 'data saver on: some scripts may not load', 'ffcertificate' ),
			'connectionOnline'    => __( 'Online · the browser does not say the type', 'ffcertificate' ),
			'connectionOffline'   => __( 'Offline', 'ffcertificate' ),
			/* translators: 1: what is being checked, 2: seconds left. */
			'pendingCountdown'    => __( '%1$s (%2$s s)', 'ffcertificate' ),
			/* translators: %s: the seconds the check was given. */
			'pendingTimeout'      => __( 'No answer after %s s: reload the page to test again.', 'ffcertificate' ),
			'locationChecking'    => __( 'Asking the browser…', 'ffcertificate' ),
			'helpLabel'           => __( 'How to fix it', 'ffcertificate' ),
			'helpSecure'          => __( 'Open the site by its address starting with https://. If that address does not open, tell the site administrator.', 'ffcertificate' ),
			'helpInApp'           => __( 'Open the app\'s menu (⋮ or ···) and choose "Open in browser" or "Open in Safari". Or copy this page\'s address and paste it into Chrome, Safari, Firefox or Edge.', 'ffcertificate' ),
			'helpCookies'         => __( 'Allow cookies for this site in the browser\'s settings (Privacy or Site settings → Cookies), or leave the private (incognito) window.', 'ffcertificate' ),
			'helpStorage'         => __( 'Leave the private (incognito) window, or allow site data for this site in the browser\'s settings.', 'ffcertificate' ),
			'helpCaptcha'         => __( 'Update the browser, or use an up-to-date Chrome, Safari, Firefox or Edge, and open the site by its https:// address.', 'ffcertificate' ),
			'helpDeviceNone'      => __( 'Open the site by its https:// address in an up-to-date browser, and allow its scripts: a content blocker can stop them.', 'ffcertificate' ),
			'helpDeviceUnstable'  => __( 'A privacy setting or extension changes these signals on every read (the fingerprint protection of Brave, Firefox or Safari, for instance). A form with a device limit may then not recognise this device: use a browser without that protection, or ask the site administrator.', 'ffcertificate' ),
			'helpClock'           => __( 'Set the date, time and time zone to automatic in the device\'s settings (Date and time → Set automatically), then reload this page.', 'ffcertificate' ),
			'helpLocationIos'     => __( 'Turn on Settings → Privacy & Security → Location Services, then set the browser (Safari or Chrome) to "While Using the App". In Safari also tap "aA" next to the address → Website Settings → Location → Allow. Reload this page.', 'ffcertificate' ),
			'helpLocationAndroid' => __( 'Turn on the phone\'s Location in the quick settings. Then tap the icon next to the address → Permissions (or Site settings) → Location → Allow, and reload this page.', 'ffcertificate' ),
			'helpLocationDesktop' => __( 'Click the icon next to the address → Site settings (or Permissions) → Location → Allow, and reload this page. On Windows and macOS, the system\'s location services must be on too.', 'ffcertificate' ),
			'helpNoGeolocation'   => __( 'Use an up-to-date Chrome, Safari, Firefox or Edge: this browser cannot give a location.', 'ffcertificate' ),
			'helpAccuracy'        => __( 'Turn on the device\'s location with high accuracy (Google Location Accuracy on Android, Precise Location on iPhone), go outdoors or near a window, wait a few seconds and test again.', 'ffcertificate' ),
			'helpPdf'             => __( 'Update the browser, or use an up-to-date Chrome, Safari, Firefox or Edge.', 'ffcertificate' ),
			'helpOffline'         => __( 'Connect to the internet (Wi-Fi or mobile data) and reload this page.', 'ffcertificate' ),
			'helpDataSaver'       => __( 'Turn off data saver (Lite mode) in the browser\'s or the phone\'s settings, then reload this page.', 'ffcertificate' ),
		);
	}

	/**
	 * Enqueue the test's stylesheet and script. Called by the front-end
	 * enqueuer only when the verification page is in this mode, after it has
	 * enqueued the palette and the pre-flight queue the script reads.
	 *
	 * @return void
	 */
	public static function enqueue_assets(): void {
		$s = \FreeFormCertificate\Core\AssetHelper::asset_suffix();
		wp_enqueue_style( 'ffc-device-check', FFC_PLUGIN_URL . "assets/css/ffc-device-check{$s}.css", array( 'ffc-common' ), FFC_VERSION );
		// The device ID row reads the collector the forms use, when the
		// device limit has loaded it on this page (#2054).
		$deps = array( 'ffc-core', 'ffc-preflight' );
		if ( wp_script_is( 'ffc-device-signals', 'enqueued' ) ) {
			$deps[] = 'ffc-device-signals';
		}
		wp_enqueue_script( 'ffc-device-check', FFC_PLUGIN_URL . "assets/js/ffc-device-check{$s}.js", $deps, FFC_VERSION, true );
		wp_localize_script( 'ffc-device-check', 'ffcDeviceCheck', array( 'strings' => self::strings() ) );
	}
}
