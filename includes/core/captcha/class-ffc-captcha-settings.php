<?php
/**
 * CaptchaSettings
 *
 * One place that knows what a captcha setting may be. Every consumer — the
 * settings form's sanitiser, the widget renderer, the challenge issuer — reads
 * the same bounds and the same allowed values from here, so a value that
 * survives a save is a value the runtime can use.
 *
 * This is the shape #993 argues every setting should have. It is built here
 * rather than plugin-wide because these keys arrive with a guardrail already:
 * two of them can make a public form unusable if they are wrong, and the
 * bounds have to exist somewhere regardless.
 *
 * @package FreeFormCertificate\Core\Captcha
 * @since 6.23.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core\Captcha;

use FreeFormCertificate\Settings\RateLimitSettingsReader;
use FreeFormCertificate\Settings\SettingsReader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Allowed values and bounds for the captcha settings.
 */
class CaptchaSettings {

	/**
	 * Lowest work factor an administrator may set.
	 *
	 * Below this the proof of work stops being a cost worth paying for: at
	 * 10k the expected 5k hashes are milliseconds even on a phone, which is
	 * the floor at which the challenge still means something rather than a
	 * value chosen for comfort.
	 *
	 * @var int
	 */
	public const COMPLEXITY_MIN = 10000;

	/**
	 * Highest work factor an administrator may set.
	 *
	 * The widget gives up after 90 seconds. A million keeps the worst case
	 * around a few seconds on a slow device, well inside that — the ceiling
	 * exists so a typo cannot leave visitors grinding into a timeout they
	 * have no way to diagnose.
	 *
	 * @var int
	 */
	public const COMPLEXITY_MAX = 1000000;

	/**
	 * Shortest challenge lifetime, in seconds.
	 *
	 * @var int
	 */
	public const TTL_MIN = 120;

	/**
	 * Longest challenge lifetime, in seconds.
	 *
	 * @var int
	 */
	public const TTL_MAX = 3600;

	/**
	 * Challenges one address may mint per window when unconfigured.
	 *
	 * Enough for a visitor to render a form and retry a few times. It is the
	 * value the endpoint enforced as a private constant before #1111 made it
	 * configurable, so an install that never touches the field keeps exactly
	 * the behaviour it had.
	 *
	 * @var int
	 */
	public const MINT_CAP_DEFAULT = 60;

	/**
	 * Lowest issuing cap an administrator may set.
	 *
	 * Zero, and it means "no cap" rather than "refuse everyone" — the same
	 * convention the per-endpoint read limits already use. It is the escape
	 * hatch for heavy institutional NAT, where any per-address number is
	 * wrong by construction: hundreds of people share one address, so the
	 * cap stops being a ceiling on farming and becomes a ceiling on the
	 * building. What still bounds a farmer there is the challenge TTL plus
	 * the per-CPF and per-IP limits on submission — a stockpile of solved
	 * challenges expires long before the submission rate can spend it.
	 *
	 * @var int
	 */
	public const MINT_CAP_MIN = 0;

	/**
	 * Highest issuing cap an administrator may set.
	 *
	 * Above this, "unlimited" is the honest setting and the number is just
	 * theatre. The ceiling exists so a typo lands somewhere the transient
	 * counter still means something, not to express a security opinion.
	 *
	 * @var int
	 */
	public const MINT_CAP_MAX = 10000;

	/**
	 * Length of the issuing window when unconfigured, in seconds.
	 *
	 * Ten minutes — the value the endpoint carried as a private constant
	 * before #1111, so an install that never touches the field keeps the
	 * behaviour it had.
	 *
	 * @var int
	 */
	public const MINT_WINDOW_DEFAULT = 600;

	/**
	 * Shortest issuing window an administrator may set, in seconds.
	 *
	 * One second, which is the bound against a value that is not a duration
	 * at all — not a floor expressing an opinion about what is safe. It was
	 * 60 for one release and that was the wrong kind of bound: these are
	 * fixed windows, so the counter resets on the boundary and a window under
	 * a minute makes the throttle mostly decorative — a farmer simply paces
	 * its requests across boundaries. That is a recommendation the field
	 * states (#1111 follow-up), not a range the code refuses, matching how
	 * every other limit on the Rate Limit tab treats the administrator.
	 *
	 * The reason the floor could not simply be lowered on its own: a cleared
	 * number field arrives as the empty string and `(int) ''` is 0, so with a
	 * floor of 1 an accidental clear would have written a one-second window.
	 * Both write paths now refuse an empty value rather than clamp it — see
	 * `TabRateLimit::save_settings()` and `SettingsAjaxEndpoint::handle()`.
	 *
	 * @var int
	 */
	public const MINT_WINDOW_MIN = 1;

	/**
	 * Longest issuing window an administrator may set, in seconds.
	 *
	 * The window is also the transient's lifetime, and it is the worst case
	 * a legitimate visitor waits after tripping the cap. An hour is already
	 * long enough that the right lever for a busy site is the cap, not a
	 * longer window; further out it reads as a lockout.
	 *
	 * @var int
	 */
	public const MINT_WINDOW_MAX = 3600;

	/**
	 * Widget presentations the element accepts for `type`.
	 *
	 * @return array<int, string>
	 */
	public static function types(): array {
		return array( 'checkbox', 'switch' );
	}

	/**
	 * Values the element accepts for `auto`.
	 *
	 * @return array<int, string>
	 */
	public static function auto_modes(): array {
		return array( 'off', 'onfocus', 'onload', 'onsubmit' );
	}

	/**
	 * Clamp a work factor into the allowed range.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public static function clamp_complexity( $value ): int {
		return self::clamp( $value, self::COMPLEXITY_MIN, self::COMPLEXITY_MAX, 200000 );
	}

	/**
	 * Clamp a challenge lifetime into the allowed range.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public static function clamp_ttl( $value ): int {
		return self::clamp( $value, self::TTL_MIN, self::TTL_MAX, 600 );
	}

	/**
	 * Clamp a challenge-issuing cap into the allowed range.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public static function clamp_mint_cap( $value ): int {
		return self::clamp( $value, self::MINT_CAP_MIN, self::MINT_CAP_MAX, self::MINT_CAP_DEFAULT );
	}

	/**
	 * Clamp an issuing window into the allowed range.
	 *
	 * @param mixed $value Raw value, in seconds.
	 * @return int
	 */
	public static function clamp_mint_window( $value ): int {
		return self::clamp( $value, self::MINT_WINDOW_MIN, self::MINT_WINDOW_MAX, self::MINT_WINDOW_DEFAULT );
	}

	/**
	 * The configured work factor, already bounded.
	 *
	 * Read through here rather than from the option directly, so a value
	 * written before a bound moved — or by anything other than the settings
	 * form — still lands inside the range the widget can cope with.
	 *
	 * @return int
	 */
	public static function complexity(): int {
		return self::clamp_complexity( SettingsReader::get( 'captcha_altcha_complexity', 200000 ) );
	}

	/**
	 * The configured challenge lifetime, already bounded.
	 *
	 * @return int
	 */
	public static function ttl(): int {
		return self::clamp_ttl( SettingsReader::get( 'captcha_altcha_ttl', 600 ) );
	}

	/**
	 * The configured challenge-issuing cap, already bounded.
	 *
	 * Read from the rate-limit option rather than `ffc_settings`, because
	 * that is where it is edited: the Rate Limit tab groups by *what is
	 * limited*, and this is limited per IP, next to the submission limit an
	 * administrator preparing a busy event already raises. The bounds still
	 * live here with the rest of the captcha guardrail, so both sides of the
	 * value — where it is stored and what it may be — have one owner each.
	 *
	 * @return int Challenges per window; 0 means no cap.
	 */
	public static function mint_cap(): int {
		$ip = RateLimitSettingsReader::ip();

		return self::clamp_mint_cap( $ip['captcha_max_per_window'] ?? self::MINT_CAP_DEFAULT );
	}

	/**
	 * The configured issuing window, already bounded.
	 *
	 * Stored beside the cap it applies to — a cap without its window is half
	 * a setting, and an administrator who raises one and cannot see the
	 * other is guessing. Changing it mid-flight only moves callers into a
	 * fresh bucket, because the window length is part of the transient key.
	 *
	 * @return int Window length in seconds.
	 */
	public static function mint_window(): int {
		$ip = RateLimitSettingsReader::ip();

		return self::clamp_mint_window( $ip['captcha_window_seconds'] ?? self::MINT_WINDOW_DEFAULT );
	}

	/**
	 * The configured widget attributes, each already validated.
	 *
	 * Two attributes the element accepts are deliberately NOT configurable,
	 * because neither works in an inline placement — verified against the
	 * bundle's own CSS rather than assumed:
	 *
	 * `display` — `bar` is `position: fixed; bottom: -100%` and `floating` is
	 * `display: none; left/top: -100%`. Both are parked off-screen by design
	 * and only surface once the widget is activated in an anchored,
	 * submit-driven flow. Rendered inline they simply vanish, which is what a
	 * "Layout" selector offering them produced. Only `standard` applies here,
	 * and a one-item selector is noise.
	 *
	 * `theme` — the bundle contains no `[theme=…]`, `:host(`,
	 * `prefers-color-scheme` or `color-scheme` rule at all. The attribute is
	 * written to the host and nothing consumes it; theming is done entirely
	 * through `--altcha-*` custom properties, which `ffc-frontend.css` now
	 * maps onto the plugin's own tokens. That makes the widget follow the
	 * site's light/dark automatically — a better answer than a selector, and
	 * the only one that could have worked.
	 *
	 * @return array{type: string, auto: string}
	 */
	public static function widget_attributes(): array {
		return array(
			'type' => self::one_of( SettingsReader::get( 'captcha_altcha_type', 'checkbox' ), self::types(), 'checkbox' ),
			'auto' => self::one_of( SettingsReader::get( 'captcha_altcha_auto', 'off' ), self::auto_modes(), 'off' ),
		);
	}

	/**
	 * The configured widget configuration object.
	 *
	 * These are not attributes — the 3.x element accepts nine, and these are
	 * not among them, so they travel as JSON in `configuration`. Writing them
	 * as attributes fails silently, which is why they are assembled in one
	 * place rather than at the template.
	 *
	 * @return array<string, mixed>
	 */
	public static function widget_configuration(): array {
		return array(
			'hideLogo'                  => (bool) SettingsReader::get( 'captcha_altcha_hide_logo', 0 ),
			'hideFooter'                => (bool) SettingsReader::get( 'captcha_altcha_hide_footer', 0 ),

			/*
			 * Not configurable, and deliberately so.
			 *
			 * `humanInteractionSignature` defaults to true upstream and
			 * collects pointer and keyboard timings. These are public-sector
			 * forms under the LGPD; the proof of work already carries the
			 * anti-automation load, so the extra behavioural signal is not
			 * worth its privacy cost — and an administrator toggling it back
			 * on would change what the site has to disclose without being
			 * told so.
			 */
			'humanInteractionSignature' => false,
			'setCookie'                 => null,
		);
	}

	/**
	 * Return a value when it is allowed, else the fallback.
	 *
	 * @param mixed              $value    Raw value.
	 * @param array<int, string> $allowed Allowed values.
	 * @param string             $fallback Value to use otherwise.
	 * @return string
	 */
	public static function one_of( $value, array $allowed, string $fallback ): string {
		$value = is_scalar( $value ) ? (string) $value : '';

		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}

	/**
	 * Clamp an integer, falling back when the input is not numeric at all.
	 *
	 * @param mixed $value    Raw value.
	 * @param int   $min      Lower bound.
	 * @param int   $max      Upper bound.
	 * @param int   $fallback Value for non-numeric input.
	 * @return int
	 */
	private static function clamp( $value, int $min, int $max, int $fallback ): int {
		if ( ! is_numeric( $value ) ) {
			return $fallback;
		}

		return max( $min, min( $max, (int) $value ) );
	}
}
