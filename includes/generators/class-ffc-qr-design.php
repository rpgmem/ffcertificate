<?php
/**
 * QR code visual design (shapes and colours).
 *
 * @package FreeFormCertificate\Generators
 * @since   6.34.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Generators;

use FreeFormCertificate\Core\ContrastColor;
use FreeFormCertificate\Settings\SettingsReader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable QR design: what the modules, the eyes and the colours look like (#1563).
 *
 * Every value is closed: a shape outside its list and a colour that is not
 * `#rrggbb` fall back to the plain default instead of failing, because a
 * design that cannot be drawn must never cost the QR itself. The plain
 * default (black squares on white) renders exactly what the PNG path has
 * always produced, so an install that never opens the QR tab sees no change.
 *
 * The settings keys live in `ffc_settings` and are read through
 * `SettingsReader`, with the literals at the read site so `SettingsDefaultsTest`
 * can compare them with `Settings::get_default_settings()`.
 */
final class QrDesign {

	/** Module shapes. */
	public const DOTS = array( 'square', 'rounded', 'dots', 'fluid', 'diamond' );

	/** Finder-pattern frame shapes. */
	public const EYE_FRAMES = array( 'square', 'rounded', 'circle', 'leaf' );

	/** Finder-pattern ball shapes. */
	public const EYE_BALLS = array( 'square', 'rounded', 'circle', 'diamond' );

	/** Surfaces the global design can be applied to. */
	public const SURFACES = array( 'certificate', 'short_urls' );

	/**
	 * Below this ratio a phone camera starts missing modules; it is a warning,
	 * not a refusal, because a large printed code still scans.
	 */
	public const MIN_CONTRAST = 4.0;

	/**
	 * Module shape.
	 *
	 * @var string
	 */
	public string $dots;

	/**
	 * Finder frame shape.
	 *
	 * @var string
	 */
	public string $eye_frame;

	/**
	 * Finder ball shape.
	 *
	 * @var string
	 */
	public string $eye_ball;

	/**
	 * Module colour (gradient start).
	 *
	 * @var string
	 */
	public string $color;

	/**
	 * Gradient end colour, or '' for a solid fill.
	 *
	 * @var string
	 */
	public string $color_end;

	/**
	 * Background colour.
	 *
	 * @var string
	 */
	public string $background;

	/**
	 * Finder frame colour.
	 *
	 * @var string
	 */
	public string $eye_frame_color;

	/**
	 * Finder ball colour.
	 *
	 * @var string
	 */
	public string $eye_ball_color;

	/**
	 * Build from loose input; every unknown or malformed value takes its default.
	 *
	 * @param array<string, mixed> $input Keys: dots, eye_frame, eye_ball, color,
	 *                                    gradient, color_end, background,
	 *                                    eye_frame_color, eye_ball_color.
	 */
	public function __construct( array $input = array() ) {
		$this->dots            = self::pick( $input['dots'] ?? null, self::DOTS );
		$this->eye_frame       = self::pick( $input['eye_frame'] ?? null, self::EYE_FRAMES );
		$this->eye_ball        = self::pick( $input['eye_ball'] ?? null, self::EYE_BALLS );
		$this->color           = self::hex( $input['color'] ?? null, '#000000' );
		$this->background      = self::hex( $input['background'] ?? null, '#ffffff' );
		$this->eye_frame_color = self::hex( $input['eye_frame_color'] ?? null, $this->color );
		$this->eye_ball_color  = self::hex( $input['eye_ball_color'] ?? null, $this->color );
		$this->color_end       = ! empty( $input['gradient'] ) ? self::hex( $input['color_end'] ?? null, $this->color ) : '';
	}

	/**
	 * The plain design: black squares on white.
	 *
	 * @return self
	 */
	public static function plain(): self {
		return new self();
	}

	/**
	 * The global default design from Settings → QR Code.
	 *
	 * @return self
	 */
	public static function from_settings(): self {
		return new self(
			array(
				'dots'            => SettingsReader::get_string( 'qr_design_dots', 'square' ),
				'eye_frame'       => SettingsReader::get_string( 'qr_design_eye_frame', 'square' ),
				'eye_ball'        => SettingsReader::get_string( 'qr_design_eye_ball', 'square' ),
				'color'           => SettingsReader::get_string( 'qr_design_color', '#000000' ),
				'gradient'        => SettingsReader::get_bool( 'qr_design_gradient' ),
				'color_end'       => SettingsReader::get_string( 'qr_design_color_end', '#2271b1' ),
				'background'      => SettingsReader::get_string( 'qr_design_background', '#ffffff' ),
				'eye_frame_color' => SettingsReader::get_string( 'qr_design_eye_frame_color', '#000000' ),
				'eye_ball_color'  => SettingsReader::get_string( 'qr_design_eye_ball_color', '#000000' ),
			)
		);
	}

	/**
	 * Whether the global design is switched on for a surface.
	 *
	 * @param string $surface One of self::SURFACES.
	 * @return bool
	 */
	public static function applies_to( string $surface ): bool {
		switch ( $surface ) {
			case 'certificate':
				return SettingsReader::get_bool( 'qr_design_on_certificate' );
			case 'short_urls':
				return SettingsReader::get_bool( 'qr_design_on_short_urls' );
			default:
				return false;
		}
	}

	/**
	 * The design to draw a surface with: the global one when applied, plain otherwise.
	 *
	 * @param string $surface One of self::SURFACES.
	 * @return self
	 */
	public static function for_surface( string $surface ): self {
		return self::applies_to( $surface ) ? self::from_settings() : self::plain();
	}

	/**
	 * Normalised values, in the constructor's input shape.
	 *
	 * @return array<string, string|bool>
	 */
	public function to_array(): array {
		return array(
			'dots'            => $this->dots,
			'eye_frame'       => $this->eye_frame,
			'eye_ball'        => $this->eye_ball,
			'color'           => $this->color,
			'gradient'        => '' !== $this->color_end,
			'color_end'       => $this->color_end,
			'background'      => $this->background,
			'eye_frame_color' => $this->eye_frame_color,
			'eye_ball_color'  => $this->eye_ball_color,
		);
	}

	/**
	 * Problems that make the code hard or impossible to scan.
	 *
	 * `inverted` is an error: most readers expect dark modules on a light
	 * ground and fail outright otherwise. `low_contrast` is a warning. Every
	 * foreground colour is checked, because one faint eye is enough to lose
	 * the finder pattern.
	 *
	 * @return array{inverted: bool, low_contrast: bool, min_ratio: float}
	 */
	public function scan_checks(): array {
		$foregrounds = array_filter( array( $this->color, $this->color_end, $this->eye_frame_color, $this->eye_ball_color ) );
		$min_ratio   = 21.0;
		$inverted    = false;

		foreach ( $foregrounds as $fg ) {
			$ratio     = ContrastColor::ratio( $fg, $this->background ) ?? 1.0;
			$min_ratio = min( $min_ratio, $ratio );
			$inverted  = $inverted || ContrastColor::is_lighter( $fg, $this->background );
		}

		return array(
			'inverted'     => $inverted,
			'low_contrast' => $min_ratio < self::MIN_CONTRAST,
			'min_ratio'    => round( $min_ratio, 2 ),
		);
	}

	/**
	 * A value from a closed list, or the list's first entry.
	 *
	 * @param mixed              $value   Candidate.
	 * @param array<int, string> $allowed Allowed values; the first is the default.
	 * @return string
	 */
	private static function pick( $value, array $allowed ): string {
		return is_string( $value ) && in_array( $value, $allowed, true ) ? $value : $allowed[0];
	}

	/**
	 * A lowercase `#rrggbb`, or the fallback.
	 *
	 * @param mixed  $value    Candidate.
	 * @param string $fallback Fallback colour.
	 * @return string
	 */
	public static function hex( $value, string $fallback ): string {
		if ( ! is_string( $value ) ) {
			return $fallback;
		}
		$value = strtolower( trim( $value ) );
		return 1 === preg_match( '/^#[0-9a-f]{6}$/', $value ) ? $value : $fallback;
	}
}
