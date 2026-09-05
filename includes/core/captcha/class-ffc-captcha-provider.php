<?php
/**
 * CaptchaProvider
 *
 * Resolves the configured captcha strategy. Every render, verify and retry
 * site goes through here, so adding a strategy touches this class and nothing
 * else.
 *
 * @package FreeFormCertificate\Core\Captcha
 * @since 6.23.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core\Captcha;

use FreeFormCertificate\Settings\SettingsReader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Captcha strategy resolver.
 */
class CaptchaProvider {

	/**
	 * Settings key naming the active strategy.
	 *
	 * Not declared in `Settings::get_default_settings()` yet — the admin
	 * surface arrives in the next step. Until then the default below is the
	 * only value in play, so an existing install keeps the math challenge on
	 * upgrade without anything being written.
	 *
	 * @var string
	 */
	public const SETTING_KEY = 'captcha_provider';

	/**
	 * Memoised instance, keyed by provider id.
	 *
	 * @var array<string, CaptchaProviderInterface>
	 */
	private static array $instances = array();

	/**
	 * The strategy for this request.
	 *
	 * An unrecognised setting value falls back to the math challenge rather
	 * than failing closed: a typo in an option must not take the public forms
	 * down, and math is the strategy with the fewest runtime requirements.
	 *
	 * @return CaptchaProviderInterface
	 */
	public static function resolve(): CaptchaProviderInterface {
		$configured = MathCaptcha::ID;

		if ( class_exists( SettingsReader::class ) ) {
			$configured = (string) SettingsReader::get( self::SETTING_KEY, MathCaptcha::ID );
		}

		return self::make( self::supports( $configured ) ? $configured : MathCaptcha::ID );
	}

	/**
	 * Whether an id names a known strategy.
	 *
	 * @param string $id Provider id.
	 * @return bool
	 */
	public static function supports( string $id ): bool {
		return in_array( $id, self::available(), true );
	}

	/**
	 * Every registered strategy id.
	 *
	 * @return array<int, string>
	 */
	public static function available(): array {
		return array( MathCaptcha::ID, AltchaCaptcha::ID, CompositeCaptcha::ID );
	}

	/**
	 * Reset the memoised instances.
	 *
	 * Test seam: the resolver is static, so a test that changes the setting
	 * would otherwise keep the strategy resolved by an earlier one.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$instances = array();
	}

	/**
	 * Build (or return) the strategy for an id.
	 *
	 * @param string $id Known provider id.
	 * @return CaptchaProviderInterface
	 */
	private static function make( string $id ): CaptchaProviderInterface {
		if ( ! isset( self::$instances[ $id ] ) ) {
			switch ( $id ) {
				case AltchaCaptcha::ID:
					self::$instances[ $id ] = new AltchaCaptcha();
					break;
				case CompositeCaptcha::ID:
					self::$instances[ $id ] = new CompositeCaptcha();
					break;
				default:
					self::$instances[ $id ] = new MathCaptcha();
			}
		}

		return self::$instances[ $id ];
	}

	/**
	 * Whether the configured strategy needs the ALTCHA widget on the page.
	 *
	 * Asked by the enqueue site, so a site on the math challenge never ships
	 * 111 KB it cannot use.
	 *
	 * @return bool
	 */
	public static function needs_altcha_widget(): bool {
		$id = self::resolve()->id();

		return AltchaCaptcha::ID === $id || CompositeCaptcha::ID === $id;
	}
}
