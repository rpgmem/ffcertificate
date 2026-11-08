<?php
/**
 * CaptchaContext
 *
 * What a captcha challenge is issued for: one certificate form, or one public
 * action. The context is part of what the challenge's signature covers, so a
 * proof solved on one form is refused on another (#2339). Before, a solved
 * challenge was good once on any form of the site, so one solve could be
 * carried to whichever form was the more valuable to submit to.
 *
 * The context is never read from the request that presents the proof: the
 * verifier names the context it expects, from what the request acts on. Only
 * issuing reads it from the client (the cached-page refresh and the ALTCHA
 * endpoint), and issuing a challenge for any context is harmless — what the
 * binding prevents is spending it somewhere else.
 *
 * @package FreeFormCertificate\Core\Captcha
 * @since 6.44.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core\Captcha;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Names of the contexts a challenge can be bound to.
 */
final class CaptchaContext {

	/**
	 * The certificate verification page.
	 *
	 * @var string
	 */
	public const VERIFY = 'verify';

	/**
	 * The public CSV download: its info screen, its download and the batched
	 * export it starts are one flow, so they share one context.
	 *
	 * @var string
	 */
	public const PUBLIC_CSV = 'public_csv';

	/**
	 * The public CSV screen's operator actions: open early, postpone the
	 * close and the schedule exception (#2341). Apart from the download's
	 * context, so a proof solved for the download never authorises a change
	 * to the form, and the download spending its proof leaves the actions'.
	 *
	 * @var string
	 */
	public const PUBLIC_CSV_ACTION = 'public_csv_action';

	/**
	 * The personal-calendar booking form.
	 *
	 * @var string
	 */
	public const BOOKING = 'booking';

	/**
	 * The context of one certificate form.
	 *
	 * @param int $form_id Form post id.
	 * @return string Context name.
	 */
	public static function form( int $form_id ): string {
		return 'form:' . max( 0, $form_id );
	}

	/**
	 * A context name received from a client, or '' when it is not one.
	 *
	 * '' is itself a context, bound like any other, so an unknown name falls
	 * to a challenge that no verifier asks for rather than to an unbound one.
	 * The shape also keeps `|` and `=` out, which the signed payloads use as
	 * separators.
	 *
	 * @param mixed $raw Value from the request.
	 * @return string Context name, or ''.
	 */
	public static function sanitize( $raw ): string {
		if ( ! is_string( $raw ) ) {
			return '';
		}

		$raw = trim( $raw );
		if ( in_array( $raw, array( self::VERIFY, self::PUBLIC_CSV, self::PUBLIC_CSV_ACTION, self::BOOKING ), true ) ) {
			return $raw;
		}

		return 1 === preg_match( '/^form:[1-9]\d{0,19}$/', $raw ) ? $raw : '';
	}
}
