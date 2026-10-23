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
 * @package FreeFormCertificate\Frontend
 */

declare(strict_types=1);

namespace FreeFormCertificate\Frontend;

use FreeFormCertificate\Core\Button;
use FreeFormCertificate\Core\Notice;

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
}
