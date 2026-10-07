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
	public const DOTS = array( 'square', 'rounded', 'dots', 'fluid', 'diamond', 'star', 'cross', 'heart', 'x' );

	/** Finder-pattern frame shapes. */
	public const EYE_FRAMES = array( 'square', 'rounded', 'circle', 'leaf', 'dotted', 'corner', 'cut' );

	/**
	 * Finder-pattern ball shapes.
	 *
	 * A ball must keep the solid 3x3 core a reader's finder scan crosses as
	 * a 1:1:3:1:1 run. A 3x3 grid of dots and vertical or horizontal bars were
	 * tried for #1570 and left out: zxing could not find the corner markers
	 * at 300 or 1000 px, with or without a logo.
	 */
	public const EYE_BALLS = array( 'square', 'rounded', 'circle', 'diamond', 'star', 'cross', 'flower' );

	/** Frames drawn around the code. */
	public const FRAMES = array( 'none', 'banner', 'badge', 'bubble', 'pill', 'speech', 'circle', 'brackets', 'double' );

	/** Icons a frame can put beside its caption (#1570); names of QrIcons. */
	public const FRAME_ICONS = array( 'scan', 'none', 'globe', 'url', 'wifi', 'phone' );

	/** Frames that print their caption in the frame colour on the paper. */
	private const CAPTION_ON_PAPER = array( 'badge', 'speech', 'circle', 'brackets' );

	/** Longest frame caption, in characters. */
	public const FRAME_TEXT_MAX = 24;

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
	 * Logo as a `data:` URI, or '' for none.
	 *
	 * @var string
	 */
	public string $logo;

	/**
	 * Frame around the code.
	 *
	 * @var string
	 */
	public string $frame;

	/**
	 * Frame caption, plain text.
	 *
	 * @var string
	 */
	public string $frame_text;

	/**
	 * Icon beside the caption, for the frames that draw one (#1570).
	 *
	 * @var string
	 */
	public string $frame_icon;

	/**
	 * Whether the background is left transparent (#1570).
	 *
	 * @var bool
	 */
	public bool $transparent;

	/**
	 * Frame colour.
	 *
	 * @var string
	 */
	public string $frame_color;

	/**
	 * Build from loose input; every unknown or malformed value takes its default.
	 *
	 * @param array<string, mixed> $input Keys: dots, eye_frame, eye_ball, color,
	 *                                    gradient, color_end, background,
	 *                                    eye_frame_color, eye_ball_color, logo,
	 *                                    frame, frame_text, frame_color,
	 *                                    frame_icon, transparent.
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
		$this->logo            = self::logo( $input['logo'] ?? null );
		$this->frame           = self::pick( $input['frame'] ?? null, self::FRAMES );
		$this->frame_text      = self::caption( $input['frame_text'] ?? null );
		$this->frame_color     = self::hex( $input['frame_color'] ?? null, '#1d2327' );
		$this->frame_icon      = self::pick( $input['frame_icon'] ?? null, self::FRAME_ICONS );
		$this->transparent     = ! empty( $input['transparent'] );
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
				'logo'            => QrLogo::data_uri( SettingsReader::get_int( 'qr_design_logo_id', 0 ) ),
				'frame'           => SettingsReader::get_string( 'qr_design_frame', 'none' ),
				'frame_text'      => SettingsReader::get_string( 'qr_design_frame_text', '' ),
				'frame_color'     => SettingsReader::get_string( 'qr_design_frame_color', '#1d2327' ),
				'frame_icon'      => SettingsReader::get_string( 'qr_design_frame_icon', 'scan' ),
				'transparent'     => SettingsReader::get_bool( 'qr_design_transparent' ),
			)
		);
	}

	/**
	 * What a design form starts from, beyond the design itself: the stored
	 * logo id (the design only holds its embedded image), the gradient switch
	 * and its end colour -- kept while the switch is off, so turning it back
	 * on restores the colour -- and the generation defaults.
	 *
	 * Shared by Settings → QR Code and the manual generator, which start from
	 * the same values.
	 *
	 * @return array{logo_id: int, gradient: bool, color_end: string, margin: int, error_level: string}
	 */
	public static function form_state(): array {
		$level = strtoupper( SettingsReader::get_string( 'qr_default_error_level', 'M' ) );

		return array(
			'logo_id'     => SettingsReader::get_int( 'qr_design_logo_id', 0 ),
			'gradient'    => SettingsReader::get_bool( 'qr_design_gradient' ),
			'color_end'   => self::hex( SettingsReader::get_string( 'qr_design_color_end', '#2271b1' ), '#2271b1' ),
			'margin'      => max( 0, min( 10, SettingsReader::get_int( 'qr_default_margin', 2 ) ) ),
			'error_level' => in_array( $level, array( 'L', 'M', 'Q', 'H' ), true ) ? $level : 'M',
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
			'logo'            => $this->logo,
			'frame'           => $this->frame,
			'frame_text'      => $this->frame_text,
			'frame_color'     => $this->frame_color,
			'frame_icon'      => $this->frame_icon,
			'transparent'     => $this->transparent,
		);
	}

	/**
	 * Error correction the code needs: H under a logo, which hides modules
	 * that only the redundancy can rebuild; the requested level otherwise.
	 *
	 * @param string $requested L, M, Q or H.
	 * @return string
	 */
	public function error_level( string $requested ): string {
		return '' !== $this->logo ? 'H' : $requested;
	}

	/**
	 * Problems that make the code hard or impossible to scan.
	 *
	 * `inverted` is an error: most readers expect dark modules on a light
	 * ground and fail outright otherwise. `low_contrast` is a warning. Every
	 * foreground colour is checked, because one faint eye is enough to lose
	 * the finder pattern.
	 *
	 * @return array{inverted: bool, low_contrast: bool, min_ratio: float, caption_contrast: bool, transparent: bool}
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

		// Some frames print their caption in the frame colour on the paper;
		// the others pick a readable caption colour themselves.
		$caption_ratio = ! $this->transparent && in_array( $this->frame, self::CAPTION_ON_PAPER, true ) && '' !== $this->frame_text
			? ( ContrastColor::ratio( $this->frame_color, $this->background ) ?? 1.0 )
			: 21.0;

		// On a transparent ground the real background is wherever the code is
		// printed, so no ratio can be measured: the check becomes a standing
		// warning instead of a number that would mean nothing.
		return array(
			'inverted'         => ! $this->transparent && $inverted,
			'low_contrast'     => ! $this->transparent && $min_ratio < self::MIN_CONTRAST,
			'min_ratio'        => round( $min_ratio, 2 ),
			'caption_contrast' => $caption_ratio >= 4.5,
			'transparent'      => $this->transparent,
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
	 * A logo `data:` URI of an accepted raster type, or ''.
	 *
	 * @param mixed $value Candidate.
	 * @return string
	 */
	private static function logo( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		return 1 === preg_match( '#^data:image/(?:png|jpeg|webp|gif);base64,[A-Za-z0-9+/]+=*$#', $value ) ? $value : '';
	}

	/**
	 * A plain-text caption of at most FRAME_TEXT_MAX characters.
	 *
	 * @param mixed $value Candidate.
	 * @return string
	 */
	private static function caption( $value ): string {
		if ( ! is_string( $value ) || '' === $value ) {
			return '';
		}
		$text = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $value ) ) );
		return mb_substr( $text, 0, self::FRAME_TEXT_MAX );
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
