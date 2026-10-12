<?php
/**
 * The look of the public screens, set by an administrator.
 *
 * @package FreeFormCertificate\Core
 * @since 6.37.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

use FreeFormCertificate\Settings\SettingsReader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The public design: an accent, borders, hints, errors, the corner radius of
 * the controls, the width of the form cards and the font, applied to every
 * `.ffc-shortcode` surface (forms, verification, scheduling, audiences, the
 * user dashboard, recruitment).
 *
 * It overrides the palette's own tokens rather than adding rules, so every
 * component that already reads `--ffc-primary`, `--ffc-border`,
 * `--ffc-radius-sm` or `--ffc-form-width` follows without being touched.
 *
 * The colours apply to the LIGHT theme only: they are scoped under
 * `:root:not(.ffc-dark-mode)`, so the dark palette, whose pairs
 * `DarkModeCssTest` measures, is never mixed with a colour picked for a white
 * ground. Width, corners and font have no theme and apply in both.
 *
 * Every value is validated here (a `#rrggbb` colour, a bounded integer, a
 * known font) before it reaches the stylesheet, which is what makes the CSS
 * this class prints safe to emit.
 */
final class PublicDesign {

	/**
	 * The look the stylesheet already has: the light palette's own values in
	 * `ffc-common.css`, the `--ffc-radius-sm` step and `--ffc-form-width`.
	 * Switched on with these values, the design changes nothing.
	 */
	public const DEFAULTS = array(
		'accent' => '#2271b1',
		'border' => '#8e8f91',
		'muted'  => '#646970',
		'error'  => '#d63638',
		'radius' => 4,
		'width'  => 600,
		'font'   => 'theme',
	);

	public const RADIUS_MAX = 24;

	public const WIDTH_MIN = 320;

	public const WIDTH_MAX = 1200;

	/**
	 * `theme` inherits the site's font, which is what the screens do today;
	 * `plugin` uses the system font stack of `--ffc-font-family`.
	 */
	public const FONTS = array( 'theme', 'plugin' );

	/**
	 * WCAG SC 1.4.3 for text on the white card.
	 */
	public const MIN_TEXT_CONTRAST = 4.5;

	/**
	 * WCAG SC 1.4.11 for a field border, which is not text.
	 */
	public const MIN_BORDER_CONTRAST = 3.0;

	/**
	 * Settings keys in `ffc_settings`, by field.
	 */
	public const KEYS = array(
		'enabled' => 'public_design',
		'accent'  => 'public_design_accent',
		'border'  => 'public_design_border',
		'muted'   => 'public_design_muted',
		'error'   => 'public_design_error',
		'radius'  => 'public_design_radius',
		'width'   => 'public_design_width',
		'font'    => 'public_design_font',
	);

	/**
	 * Whether the design is switched on.
	 *
	 * @var bool
	 */
	public bool $enabled;

	/**
	 * Buttons, links and focus.
	 *
	 * @var string
	 */
	public string $accent;

	/**
	 * Field borders.
	 *
	 * @var string
	 */
	public string $border;

	/**
	 * Hints and secondary text.
	 *
	 * @var string
	 */
	public string $muted;

	/**
	 * Errors.
	 *
	 * @var string
	 */
	public string $error;

	/**
	 * Corner radius of fields and buttons, px.
	 *
	 * @var int
	 */
	public int $radius;

	/**
	 * Widest a form card grows, px.
	 *
	 * @var int
	 */
	public int $width;

	/**
	 * One of self::FONTS.
	 *
	 * @var string
	 */
	public string $font;

	/**
	 * Normalise raw values. Anything missing or invalid takes the default,
	 * and switching the design off resets every value, so switching it on
	 * again starts from the stylesheet's own look.
	 *
	 * @param array<string, mixed> $raw Field => value (see self::KEYS).
	 */
	public function __construct( array $raw ) {
		$this->enabled = ! empty( $raw['enabled'] );
		$on            = $this->enabled;

		$this->accent = $on ? self::color( $raw['accent'] ?? null, self::DEFAULTS['accent'] ) : self::DEFAULTS['accent'];
		$this->border = $on ? self::color( $raw['border'] ?? null, self::DEFAULTS['border'] ) : self::DEFAULTS['border'];
		$this->muted  = $on ? self::color( $raw['muted'] ?? null, self::DEFAULTS['muted'] ) : self::DEFAULTS['muted'];
		$this->error  = $on ? self::color( $raw['error'] ?? null, self::DEFAULTS['error'] ) : self::DEFAULTS['error'];
		$this->radius = $on ? self::number( $raw['radius'] ?? null, self::DEFAULTS['radius'], 0, self::RADIUS_MAX ) : self::DEFAULTS['radius'];
		$this->width  = $on ? self::number( $raw['width'] ?? null, self::DEFAULTS['width'], self::WIDTH_MIN, self::WIDTH_MAX ) : self::DEFAULTS['width'];
		$font         = is_string( $raw['font'] ?? null ) ? $raw['font'] : '';
		$this->font   = $on && in_array( $font, self::FONTS, true ) ? $font : self::DEFAULTS['font'];
	}

	/**
	 * The design as stored in `ffc_settings`.
	 *
	 * @return self
	 */
	public static function from_settings(): self {
		$raw = array();
		foreach ( self::KEYS as $field => $key ) {
			$raw[ $field ] = SettingsReader::get( $key, 'enabled' === $field ? 0 : self::DEFAULTS[ $field ] );
		}
		return new self( $raw );
	}

	/**
	 * The values to store, keyed by settings key.
	 *
	 * @return array<string, int|string>
	 */
	public function to_settings(): array {
		return array(
			self::KEYS['enabled'] => $this->enabled ? 1 : 0,
			self::KEYS['accent']  => $this->accent,
			self::KEYS['border']  => $this->border,
			self::KEYS['muted']   => $this->muted,
			self::KEYS['error']   => $this->error,
			self::KEYS['radius']  => $this->radius,
			self::KEYS['width']   => $this->width,
			self::KEYS['font']    => $this->font,
		);
	}

	/**
	 * The stylesheet that applies the design; '' when it is off.
	 *
	 * @return string
	 */
	public function css(): string {
		if ( ! $this->enabled ) {
			return '';
		}

		$shape = sprintf(
			'--ffc-form-width:%1$dpx;--ffc-radius-sm:%2$dpx;--ffc-radius-md:%2$dpx;',
			$this->width,
			$this->radius
		);
		if ( 'plugin' === $this->font ) {
			$shape .= 'font-family:var(--ffc-font-family);';
		}

		$text_on = ContrastColor::on( $this->accent );
		$hover   = self::shade( $this->accent, '#ffffff' === $text_on ? '#000000' : '#ffffff', 0.25 );
		$rgb     = self::rgb( $this->accent );

		$colors = sprintf(
			'--ffc-primary:%1$s;--ffc-primary-hover:%2$s;--ffc-primary-light:%3$s;--ffc-text-on-primary:%4$s;--ffc-link:%1$s;--ffc-link-hover:%2$s;--ffc-border-focus:%1$s;--ffc-focus-ring:rgba(%5$d, %6$d, %7$d, 0.75);--ffc-border:%8$s;--ffc-text-muted:%9$s;--ffc-danger:%10$s;',
			$this->accent,
			$hover,
			self::shade( $this->accent, '#ffffff', 0.88 ),
			$text_on,
			$rgb[0],
			$rgb[1],
			$rgb[2],
			$this->border,
			$this->muted,
			$this->error
		);

		return '.ffc-shortcode{' . $shape . '}:root:not(.ffc-dark-mode) .ffc-shortcode{' . $colors . '}';
	}

	/**
	 * The pairs the design draws below WCAG's floors, each with its ratio.
	 * Text on the accent is not listed: `ContrastColor::on()` picks it, and
	 * that choice never falls below 4.5:1.
	 *
	 * @return list<array{pair: string, ratio: float, min: float}>
	 */
	public function weak_contrasts(): array {
		$pairs = array(
			array( 'accent', __( 'links on white', 'ffcertificate' ), self::MIN_TEXT_CONTRAST ),
			array( 'muted', __( 'hints on white', 'ffcertificate' ), self::MIN_TEXT_CONTRAST ),
			array( 'error', __( 'errors on white', 'ffcertificate' ), self::MIN_TEXT_CONTRAST ),
			array( 'border', __( 'field borders on white', 'ffcertificate' ), self::MIN_BORDER_CONTRAST ),
		);

		$weak = array();
		foreach ( $pairs as $pair ) {
			$ratio = (float) ContrastColor::ratio( $this->{ $pair[0] }, '#ffffff' );
			if ( $ratio < $pair[2] ) {
				$weak[] = array(
					'pair'  => $pair[1],
					'ratio' => round( $ratio, 2 ),
					'min'   => $pair[2],
				);
			}
		}
		return $weak;
	}

	/**
	 * Append the design's stylesheet to `ffc-common` on public pages.
	 *
	 * Every public screen loads `ffc-common` (it holds the palette), from
	 * several enqueue sites and sometimes late, in the footer. Hooking the
	 * printed tag, rather than one enqueue site, reaches all of them wherever
	 * the sheet is printed.
	 *
	 * @param string $tag    The `<link>` tag WordPress prints.
	 * @param string $handle The stylesheet handle.
	 * @return string
	 */
	public static function append_to_common_style( string $tag, string $handle ): string {
		if ( 'ffc-common' !== $handle || is_admin() ) {
			return $tag;
		}
		$css = self::from_settings()->css();
		if ( '' === $css ) {
			return $tag;
		}
		return $tag . '<style id="ffc-public-design">' . $css . "</style>\n";
	}

	/**
	 * Register the filter. Priority 10, the default: nothing else in the
	 * plugin filters this tag, so ordering does not matter.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'style_loader_tag', array( self::class, 'append_to_common_style' ), 10, 2 );
	}

	/**
	 * A `#rrggbb` colour, lower case, or the fallback.
	 *
	 * @param mixed  $raw      Posted value.
	 * @param string $fallback Default.
	 * @return string
	 */
	private static function color( $raw, string $fallback ): string {
		$value = is_string( $raw ) ? strtolower( trim( $raw ) ) : '';
		return 1 === preg_match( '/^#[0-9a-f]{6}$/', $value ) ? $value : $fallback;
	}

	/**
	 * A bounded integer. A cleared field posts '', which means "not set": it,
	 * and anything that is not a number, gives the fallback, never the floor.
	 *
	 * @param mixed $raw      Posted value.
	 * @param int   $fallback Default.
	 * @param int   $min      Lower bound.
	 * @param int   $max      Upper bound.
	 * @return int
	 */
	private static function number( $raw, int $fallback, int $min, int $max ): int {
		$value = is_numeric( $raw ) ? (int) $raw : $fallback;
		return min( $max, max( $min, $value ) );
	}

	/**
	 * Mix a colour towards another.
	 *
	 * @param string $hex     `#rrggbb`.
	 * @param string $towards `#rrggbb`.
	 * @param float  $amount  0 keeps the colour, 1 gives `$towards`.
	 * @return string
	 */
	private static function shade( string $hex, string $towards, float $amount ): string {
		$from = self::rgb( $hex );
		$to   = self::rgb( $towards );
		$out  = '#';
		foreach ( array( 0, 1, 2 ) as $i ) {
			$out .= sprintf( '%02x', (int) round( $from[ $i ] + ( $to[ $i ] - $from[ $i ] ) * $amount ) );
		}
		return $out;
	}

	/**
	 * The channels of a validated `#rrggbb` colour.
	 *
	 * @param string $hex `#rrggbb`.
	 * @return array{0: int, 1: int, 2: int}
	 */
	private static function rgb( string $hex ): array {
		return array(
			(int) hexdec( substr( $hex, 1, 2 ) ),
			(int) hexdec( substr( $hex, 3, 2 ) ),
			(int) hexdec( substr( $hex, 5, 2 ) ),
		);
	}
}
