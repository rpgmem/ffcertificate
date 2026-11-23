<?php
/**
 * Audience Presence Agent
 *
 * The platform and browser families of the phone that activated presence
 * (#2781), read from its User-Agent at enrolment.
 *
 * @package FreeFormCertificate\Audience
 * @since 6.50.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Only the two families are kept, never the User-Agent itself: "Android ·
 * Chrome" is what the Activate presence screen and the admin list show, and
 * it says nothing a version string or a device model would.
 */
final class AudiencePresenceAgent {

	/**
	 * Platform families, first match wins: iPadOS and Android tablets name
	 * a desktop too, so the mobile ones are tried first.
	 *
	 * @var array<string, string>
	 */
	private const PLATFORMS = array(
		'/\b(iPhone|iPad|iPod)\b/'     => 'iOS',
		'/\bAndroid\b/'                => 'Android',
		'/\bCrOS\b/'                   => 'ChromeOS',
		'/\bWindows\b/'                => 'Windows',
		'/\bMac OS X\b|\bMacintosh\b/' => 'macOS',
		'/\bLinux\b/'                  => 'Linux',
	);

	/**
	 * Browser families, first match wins: Edge, Opera and Samsung Internet
	 * all announce Chrome, and Chrome announces Safari.
	 *
	 * @var array<string, string>
	 */
	private const BROWSERS = array(
		'/\bEdg(e|A|iOS)?\//'     => 'Edge',
		'/\bOPR\/|\bOpera\b/'     => 'Opera',
		'/\bSamsungBrowser\//'    => 'Samsung Internet',
		'/\bFirefox\/|\bFxiOS\//' => 'Firefox',
		'/\bChrome\/|\bCriOS\//'  => 'Chrome',
		'/\bSafari\//'            => 'Safari',
	);

	/**
	 * The two families a User-Agent names, '' for one it does not.
	 *
	 * @param string $agent User-Agent.
	 * @return array{platform: string, browser: string}
	 */
	public static function describe( string $agent ): array {
		$agent = substr( $agent, 0, 512 );
		return array(
			'platform' => self::first( self::PLATFORMS, $agent ),
			'browser'  => self::first( self::BROWSERS, $agent ),
		);
	}

	/**
	 * "Android · Chrome", or the one family known, or ''.
	 *
	 * @param string $platform Platform family.
	 * @param string $browser  Browser family.
	 * @return string
	 */
	public static function label( string $platform, string $browser ): string {
		return implode( ' · ', array_filter( array( $platform, $browser ), static fn( string $part ): bool => '' !== $part ) );
	}

	/**
	 * The family of the first pattern that matches.
	 *
	 * @param array<string, string> $families Pattern to family.
	 * @param string                $agent    User-Agent.
	 * @return string
	 */
	private static function first( array $families, string $agent ): string {
		foreach ( $families as $pattern => $family ) {
			if ( 1 === preg_match( $pattern, $agent ) ) {
				return $family;
			}
		}
		return '';
	}
}
