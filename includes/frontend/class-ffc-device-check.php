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
		'secure'   => 'lock',
		'browser'  => 'monitor',
		'cookies'  => 'cookie',
		'location' => 'map-pin',
		'accuracy' => 'map-pin',
		'pdf'      => 'file',
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
			'secure'   => __( 'Secure connection', 'ffcertificate' ),
			'browser'  => __( 'Browser', 'ffcertificate' ),
			'cookies'  => __( 'Cookies', 'ffcertificate' ),
			'location' => __( 'Location permission', 'ffcertificate' ),
			'accuracy' => __( 'Location precision', 'ffcertificate' ),
			'pdf'      => __( 'PDF download', 'ffcertificate' ),
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
			'secureYes'       => __( 'Yes', 'ffcertificate' ),
			'secureNo'        => __( 'No: the browser gives no location here, and forms that ask for it will stop.', 'ffcertificate' ),
			/* translators: %s: the kind of device (iPhone or iPad, Android, computer). */
			'browserOk'       => __( 'Ordinary browser (%s)', 'ffcertificate' ),
			/* translators: %s: the kind of device (iPhone or iPad, Android, computer). */
			'browserInApp'    => __( 'An app\'s own browser (%s): the location and downloads often fail. Open the page in the phone\'s browser.', 'ffcertificate' ),
			'cookiesYes'      => __( 'Allowed', 'ffcertificate' ),
			'cookiesNo'       => __( 'Blocked: sending a form may fail.', 'ffcertificate' ),
			'locationGranted' => __( 'Allowed', 'ffcertificate' ),
			'locationPrompt'  => __( 'Not decided yet: the browser will ask.', 'ffcertificate' ),
			'locationDenied'  => __( 'Blocked: allow it in the browser settings.', 'ffcertificate' ),
			'locationUnknown' => __( 'The browser does not say until it asks.', 'ffcertificate' ),
			'noGeolocation'   => __( 'This browser cannot give a location.', 'ffcertificate' ),
			'accuracyIdle'    => __( 'Press "Test my location".', 'ffcertificate' ),
			'accuracyReading' => __( 'Reading your location…', 'ffcertificate' ),
			/* translators: 1: margin of error in metres, 2: seconds the reading took. */
			'accuracyResult'  => __( 'About %1$s m, read in %2$s s', 'ffcertificate' ),
			'accuracyDenied'  => __( 'Refused by the browser or by you.', 'ffcertificate' ),
			'accuracyFailed'  => __( 'Could not be read: move to an open area and try again.', 'ffcertificate' ),
			'accuracyTimeout' => __( 'Took too long: move to an open area and try again.', 'ffcertificate' ),
			'pdfYes'          => __( 'Supported', 'ffcertificate' ),
			/* translators: %s: the browser features that are missing. */
			'pdfNo'           => __( 'Not supported: missing %s.', 'ffcertificate' ),
			'copied'          => __( 'Copied', 'ffcertificate' ),
			'copyFailed'      => __( 'Could not copy: take a screenshot instead.', 'ffcertificate' ),
			'deviceIos'       => __( 'iPhone or iPad', 'ffcertificate' ),
			'deviceAndroid'   => __( 'Android', 'ffcertificate' ),
			'deviceDesktop'   => __( 'computer', 'ffcertificate' ),
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
		wp_enqueue_script( 'ffc-device-check', FFC_PLUGIN_URL . "assets/js/ffc-device-check{$s}.js", array( 'ffc-core', 'ffc-preflight' ), FFC_VERSION, true );
		wp_localize_script( 'ffc-device-check', 'ffcDeviceCheck', array( 'strings' => self::strings() ) );
	}
}
