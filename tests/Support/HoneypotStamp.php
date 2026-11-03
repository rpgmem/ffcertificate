<?php
/**
 * A valid honeypot render-time stamp, for tests (#2339).
 *
 * A request that reaches the security gate must carry a signed render time
 * older than `Honeypot::MIN_SECONDS`, as the rendered form gives a person
 * who took a few seconds to fill it in. The signer derives its key from
 * `wp_salt()`, which the calling test stubs.
 *
 * @package FreeFormCertificate\Tests\Support
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Support;

use FreeFormCertificate\Core\Captcha\ChallengeSigner;
use FreeFormCertificate\Core\Honeypot;

/**
 * Builds the stamp field a rendered form carries.
 */
final class HoneypotStamp {

	/**
	 * The stamp field, rendered `$age` seconds ago.
	 *
	 * @param int $age Seconds since the form was rendered.
	 * @return array<string, string>
	 */
	public static function fields( int $age = 10 ): array {
		return array( Honeypot::STAMP_FIELD => self::value( $age ) );
	}

	/**
	 * The stamp's value, rendered `$age` seconds ago.
	 *
	 * @param int $age Seconds since the form was rendered.
	 * @return string
	 */
	public static function value( int $age = 10 ): string {
		$rendered = time() - $age;
		return $rendered . '.' . ChallengeSigner::sign( 'hp|' . $rendered );
	}
}
