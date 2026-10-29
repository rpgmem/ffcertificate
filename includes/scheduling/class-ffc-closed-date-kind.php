<?php
/**
 * Closed Date Kind
 *
 * What a closed date is (#2202): a holiday or an optional holiday (in Brazil,
 * a *ponto facultativo*). Both close the calendar the same way; only the label
 * and the colour the public calendars draw differ, so the kind is presentation
 * and never a booking rule.
 *
 * It is shared by the two places a closed date is registered: the global
 * holidays (an option read by both scheduling systems) and an audience
 * calendar's own closed dates (a table).
 *
 * @package FreeFormCertificate\Scheduling
 * @since 6.43.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Scheduling;

use FreeFormCertificate\Core\ContrastColor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The kinds of closed date, their labels and their colours.
 */
final class ClosedDateKind {

	/**
	 * A holiday. The stored default: a row or an option entry written before
	 * #2202 carries no kind and reads as this.
	 */
	public const HOLIDAY = 'holiday';

	/**
	 * An optional holiday (*ponto facultativo*).
	 */
	public const OPTIONAL = 'optional';

	/**
	 * Option holding the light-theme colour of each kind.
	 */
	public const COLORS_OPTION = 'ffc_closed_date_colors';

	/**
	 * Light-theme defaults, chosen so white text clears 4.5:1 on each
	 * (5.33:1 and 5.48:1). The same values are the `:root` tokens in
	 * `ffc-common.css`; `ClosedDateKindTest` holds the two together.
	 */
	public const DEFAULT_COLORS = array(
		self::HOLIDAY  => '#8a6500',
		self::OPTIONAL => '#4f6b8f',
	);

	/**
	 * The CSS custom property each kind's background is read from, and its
	 * text colour after it with `--ffc-text-on-` in front.
	 */
	private const TOKENS = array(
		self::HOLIDAY  => 'holiday',
		self::OPTIONAL => 'optional-holiday',
	);

	/**
	 * Every kind, the default first.
	 *
	 * @return list<string>
	 */
	public static function all(): array {
		return array( self::HOLIDAY, self::OPTIONAL );
	}

	/**
	 * A stored or posted value as a kind; anything unknown, a missing value
	 * included, reads as a holiday.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function normalize( $value ): string {
		return self::OPTIONAL === $value ? self::OPTIONAL : self::HOLIDAY;
	}

	/**
	 * The kind's full name, for the legend, the event list, the admin lists
	 * and the cell's accessible name.
	 *
	 * @param string $kind Kind.
	 * @return string
	 */
	public static function label( string $kind ): string {
		return self::OPTIONAL === self::normalize( $kind )
			? __( 'Optional Holiday', 'ffcertificate' )
			: __( 'Holiday', 'ffcertificate' );
	}

	/**
	 * The kind's name in a calendar day cell, which is narrower than the full
	 * name of an optional holiday in some languages.
	 *
	 * @param string $kind Kind.
	 * @return string
	 */
	public static function short_label( string $kind ): string {
		return self::OPTIONAL === self::normalize( $kind )
			? _x( 'Optional', 'optional holiday, in a calendar day cell', 'ffcertificate' )
			: __( 'Holiday', 'ffcertificate' );
	}

	/**
	 * The labels the calendar scripts need, keyed by kind.
	 *
	 * @return array<string, array{label: string, short: string}>
	 */
	public static function strings(): array {
		$out = array();
		foreach ( self::all() as $kind ) {
			$out[ $kind ] = array(
				'label' => self::label( $kind ),
				'short' => self::short_label( $kind ),
			);
		}
		return $out;
	}

	/**
	 * The kind as a pill, for the admin lists.
	 *
	 * @param string $kind Kind.
	 * @return string
	 */
	public static function pill( string $kind ): string {
		$kind = self::normalize( $kind );
		return '<span class="ffc-closed-date-pill ffc-closed-date-pill-' . esc_attr( $kind ) . '">' . esc_html( self::label( $kind ) ) . '</span>';
	}

	/**
	 * The arguments of the segmented control that picks a kind on the add
	 * forms (`AdminUI::render_segmented()`, which the caller draws).
	 *
	 * @param string $name Field name, also the base id.
	 * @return array<string, mixed>
	 */
	public static function control_args( string $name ): array {
		$options = array();
		foreach ( self::all() as $kind ) {
			$options[ $kind ] = self::label( $kind );
		}
		return array(
			'name'    => $name,
			'id'      => $name,
			'options' => $options,
			'value'   => self::HOLIDAY,
			'legend'  => __( 'Type', 'ffcertificate' ),
			'size'    => 'sm',
		);
	}

	/**
	 * The light-theme colour of each kind: the stored one when it is a hex
	 * colour, else the default.
	 *
	 * @return array<string, string>
	 */
	public static function colors(): array {
		$stored = get_option( self::COLORS_OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$out    = array();
		foreach ( self::DEFAULT_COLORS as $kind => $default ) {
			$value        = isset( $stored[ $kind ] ) ? sanitize_hex_color( (string) $stored[ $kind ] ) : '';
			$out[ $kind ] = $value ? $value : $default;
		}
		return $out;
	}

	/**
	 * Store the colours posted for each kind. A value that is not a hex
	 * colour keeps what was stored, so a cleared field changes nothing.
	 *
	 * @param array<string, mixed> $posted Kind => colour.
	 * @return void
	 */
	public static function save_colors( array $posted ): void {
		$colors = self::colors();
		foreach ( array_keys( self::DEFAULT_COLORS ) as $kind ) {
			$value = isset( $posted[ $kind ] ) ? sanitize_hex_color( (string) $posted[ $kind ] ) : '';
			if ( $value ) {
				$colors[ $kind ] = strtolower( $value );
			}
		}
		update_option( self::COLORS_OPTION, $colors, false );
	}

	/**
	 * The CSS that applies the chosen colours, in the light theme only: the
	 * dark palette is measured by `DarkModeCssTest` and a colour picked for a
	 * white page is not mixed into it. The text on each colour comes from
	 * `ContrastColor::on()`, so no saved colour can make its label illegible.
	 * Empty when every colour is the default, which the stylesheet already
	 * declares.
	 *
	 * @return string
	 */
	public static function inline_css(): string {
		$declarations = '';
		foreach ( self::colors() as $kind => $color ) {
			if ( strtolower( $color ) === self::DEFAULT_COLORS[ $kind ] ) {
				continue;
			}
			$token         = self::TOKENS[ $kind ];
			$declarations .= '--ffc-' . $token . ':' . $color . ';--ffc-text-on-' . $token . ':' . ContrastColor::on( $color ) . ';';
		}
		return '' === $declarations ? '' : ':root:not(.ffc-dark-mode){' . $declarations . '}';
	}

	/**
	 * Attach the colours to a stylesheet the calendar has enqueued.
	 *
	 * @param string $handle Stylesheet handle.
	 * @return void
	 */
	public static function enqueue_colors( string $handle ): void {
		$css = self::inline_css();
		if ( '' !== $css ) {
			wp_add_inline_style( $handle, $css );
		}
	}
}
