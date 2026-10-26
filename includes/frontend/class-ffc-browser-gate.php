<?php
/**
 * BrowserGate
 *
 * What a certificate form says when the browser cannot run it (#2030).
 *
 * The form is submitted over AJAX and its PDF is drawn in the browser, so a
 * browser that does not run the plugin's scripts cannot issue a certificate.
 * Two cases are covered here, both printed with the form:
 *
 * - JavaScript is off: a `<noscript>` notice says so. Without it a form with
 *   a date or location restriction stays hidden by CSS with nothing on screen,
 *   and an ordinary one fails on submit with no explanation.
 * - A script did not load or did not finish (a content blocker, a dropped
 *   request, a failed captcha and nonce refresh): the boot watchdog in
 *   `assets/js/ffc-preflight.js` clones the `<template>` below into the form
 *   when the form has not reported ready in time. A `<template>` is inert, so
 *   the notice costs nothing on the page that loads normally, and its text is
 *   translated here rather than shipped to a script that may be the one that
 *   failed.
 *
 * It also carries what the visitor pre-flight queue needs on every form
 * (#2030): the minimal configuration of a form with no date or location
 * restriction, so the queue runs on it too, the strings of the browser and
 * HTTPS steps, and the two switches the steps read.
 *
 * @package FreeFormCertificate\Frontend
 */

declare(strict_types=1);

namespace FreeFormCertificate\Frontend;

use FreeFormCertificate\Core\Button;
use FreeFormCertificate\Core\Captcha\CaptchaProvider;
use FreeFormCertificate\Core\Notice;
use FreeFormCertificate\Security\Geofence;
use FreeFormCertificate\Settings\SettingsReader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Notices for a browser that cannot run the certificate form.
 */
class BrowserGate {

	/**
	 * Notice hook of the "JavaScript is off" message.
	 *
	 * @var string
	 */
	public const NOSCRIPT_HOOK = 'noscript';

	/**
	 * Notice hook of the "the form did not load" message.
	 *
	 * @var string
	 */
	public const BOOT_HOOK = 'boot-failed';

	/**
	 * The markup a certificate form carries for a browser that cannot run it.
	 *
	 * @return string `<noscript>` notice followed by the boot-failure template.
	 */
	public static function form_markup(): string {
		return '<noscript>' . self::noscript_notice() . '</noscript>'
			. '<template data-ffc-boot-notice>' . self::boot_notice() . '</template>';
	}

	/**
	 * The notice shown when JavaScript is off.
	 *
	 * @return string Notice markup.
	 */
	public static function noscript_notice(): string {
		return Notice::render(
			'warning',
			'warning',
			__( 'This form needs JavaScript, which is turned off in this browser. Turn it on, or open this page in another browser, to get your certificate.', 'ffcertificate' ),
			__( 'JavaScript is off', 'ffcertificate' ),
			array( 'hook' => self::NOSCRIPT_HOOK )
		);
	}

	/**
	 * The notice shown when the form's scripts did not load in time.
	 *
	 * @return string Notice markup with a "Reload the page" button.
	 */
	public static function boot_notice(): string {
		return Notice::render(
			'warning',
			'warning',
			__( 'Part of this form did not load, so it cannot be sent yet. Reload the page. If this message comes back, turn off any content blocker for this site or open the page in another browser.', 'ffcertificate' ),
			__( 'The form did not load completely', 'ffcertificate' ),
			array(
				'hook'    => self::BOOT_HOOK,
				'role'    => 'alert',
				'actions' => Button::html(
					array(
						'label'    => __( 'Reload the page', 'ffcertificate' ),
						'category' => 'primary',
						'icon'     => 'sync',
						'class'    => 'ffc-boot-reload',
					)
				),
			)
		);
	}

	/**
	 * The pre-flight configuration of a form with no date or location
	 * restriction: both checks off, so the queue runs only the steps every
	 * form takes (browser and cookies). An administrator under bypass skips
	 * those, as on a restricted form, without the bypass notices: there is
	 * no restriction to tell them about.
	 *
	 * @param int $form_id Form ID.
	 * @return array<string, mixed>
	 */
	public static function form_config( int $form_id ): array {
		return array(
			'formId'      => $form_id,
			'adminBypass' => Geofence::should_bypass_datetime() || Geofence::should_bypass_geo(),
			'bypassInfo'  => null,
			'datetime'    => array( 'enabled' => false ),
			'geo'         => array( 'enabled' => false ),
		);
	}

	/**
	 * The switches the pre-flight steps read, for the `_global` block of
	 * the geofence configuration.
	 *
	 * @return array{browserCheck: bool, altchaOnly: bool}
	 */
	public static function settings(): array {
		return array(
			'browserCheck' => SettingsReader::browser_check_enabled(),
			// The ALTCHA widget refuses to run outside a secure context. The
			// composite mode is not included: its math half works anywhere.
			'altchaOnly'   => 'altcha' === CaptchaProvider::resolve()->id(),
		);
	}

	/**
	 * The strings of the browser and HTTPS steps.
	 *
	 * @return array<string, string>
	 */
	public static function strings(): array {
		return array(
			'secureRequired'       => __( 'This form requires a secure connection (HTTPS). Please contact the site administrator.', 'ffcertificate' ),
			'browsersAndroid'      => __( 'Chrome, Firefox, Edge, Opera or Samsung Internet', 'ffcertificate' ),
			'browsersIos'          => __( 'Safari, Chrome, Firefox or Edge', 'ffcertificate' ),
			'browsersOther'        => __( 'Chrome, Firefox, Edge or Safari', 'ffcertificate' ),
			'inAppTitle'           => __( 'Open this page in your browser', 'ffcertificate' ),
			/* translators: %s: a list of browsers, such as "Safari, Chrome, Firefox or Edge". */
			'inAppBody'            => __( 'You are viewing this page inside an app, where your location and the certificate download often fail. Open it in %s.', 'ffcertificate' ),
			'inAppHowIos'          => __( 'Tap the menu (••• or the share icon) and choose "Open in Safari" or "Open in browser".', 'ffcertificate' ),
			'inAppHowAndroid'      => __( 'Or tap the menu (⋮) and choose "Open in browser".', 'ffcertificate' ),
			'openInChrome'         => __( 'Open in Chrome', 'ffcertificate' ),
			'continueAnyway'       => __( 'Continue anyway', 'ffcertificate' ),
			'browserFeaturesTitle' => __( 'This browser cannot run the form', 'ffcertificate' ),
			/* translators: %s: a list of browsers, such as "Safari, Chrome, Firefox or Edge". */
			'browserFeaturesBody'  => __( 'This browser is missing features the form needs to issue your certificate. Open this page in %s.', 'ffcertificate' ),
		);
	}

	/**
	 * What a page outside a certificate form hands the pre-flight queue
	 * (#2040): the browser-check switch and the step strings, with the
	 * page's own wording over the form's where they differ.
	 *
	 * @param array<string, string> $strings Strings that replace the form's.
	 * @return array{browserCheck: bool, strings: array<string, string>}
	 */
	public static function script_config( array $strings = array() ): array {
		return array(
			'browserCheck' => SettingsReader::browser_check_enabled(),
			'strings'      => array_merge( self::strings(), $strings ),
		);
	}

	/**
	 * What the request helper (`FFC.request`, in `ffc-core`) reads on a page
	 * that loads no certificate form: the AJAX URL and the front-end nonce
	 * the pre-flight telemetry is checked against. Localized onto
	 * `ffc-core` as `ffc_ajax`, so the helper finds it at load.
	 *
	 * @return array{ajax_url: string, nonce: string}
	 */
	public static function request_config(): array {
		return array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'ffc_frontend_nonce' ),
		);
	}
}
