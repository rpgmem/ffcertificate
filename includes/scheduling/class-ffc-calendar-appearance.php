<?php
/**
 * Calendar Appearance
 *
 * The colour and the names of each state a public calendar draws (#2215):
 * an available day, a booked one, a holiday, an optional holiday and a
 * closed day. Both scheduling systems read it, so the personal and the
 * audience calendars look and read the same.
 *
 * A colour applies to the light theme only, and the text on it comes from
 * `ContrastColor::on()`. A name left empty is the translated default, so a
 * site that never opens the card keeps following the plugin's translation.
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
 * Colours, legend names, day-cell names and the closed-label switch.
 */
final class CalendarAppearance {

	/**
	 * A day that can be booked.
	 */
	public const AVAILABLE = 'available';

	/**
	 * A day that already has bookings.
	 */
	public const BOOKED = 'booked';

	/**
	 * A day with no opening hours.
	 */
	public const CLOSED = 'closed';

	/**
	 * Post meta holding a personal calendar's own names (#2223). An audience
	 * calendar keeps them in its `appearance_names` column.
	 */
	public const NAMES_META = '_ffc_self_scheduling_names';

	/**
	 * A calendar's closed-label choice (#2223): follow the general switch,
	 * or show or hide the name on that calendar only.
	 */
	public const SHOW_INHERIT = 'inherit';

	/**
	 * See {@see self::SHOW_INHERIT}.
	 */
	public const SHOW = 'show';

	/**
	 * See {@see self::SHOW_INHERIT}.
	 */
	public const HIDE = 'hide';

	/**
	 * Option holding the colours, the names and the switch.
	 */
	public const OPTION = 'ffc_calendar_appearance';

	/**
	 * Light-theme defaults: the values of the tokens each state is drawn
	 * with in `ffc-common.css` (`CalendarAppearanceTest` holds them
	 * together), so a colour equal to its default prints nothing.
	 */
	public const DEFAULT_COLORS = array(
		self::AVAILABLE          => '#00801e',
		self::BOOKED             => '#2271b1',
		ClosedDateKind::HOLIDAY  => '#8a6500',
		ClosedDateKind::OPTIONAL => '#4f6b8f',
		self::CLOSED             => '#f6f7f7',
	);

	/**
	 * The states whose day cell carries a name of its own. A booked day
	 * shows the calendar's booking label and an available day no text.
	 */
	public const CELL_STATES = array( ClosedDateKind::HOLIDAY, ClosedDateKind::OPTIONAL, self::CLOSED );

	/**
	 * The booked day's badge reads "N <name>", in the singular for one
	 * booking and the plural otherwise (#2221).
	 */
	public const BOOKING_SINGULAR = 'booking_singular';

	/**
	 * See {@see self::BOOKING_SINGULAR}.
	 */
	public const BOOKING_PLURAL = 'booking_plural';

	/**
	 * Every name a day cell can carry: an available day's (none by default),
	 * the closed states' and the booking badge's two forms.
	 */
	public const CELL_NAMES = array( self::AVAILABLE, ClosedDateKind::HOLIDAY, ClosedDateKind::OPTIONAL, self::CLOSED, self::BOOKING_SINGULAR, self::BOOKING_PLURAL );

	/**
	 * Every state, in the order the legend and the card list them.
	 *
	 * @return list<string>
	 */
	public static function states(): array {
		return array_keys( self::DEFAULT_COLORS );
	}

	/**
	 * The translated default legend name of a state.
	 *
	 * @param string $state State.
	 * @return string
	 */
	public static function default_legend( string $state ): string {
		switch ( $state ) {
			case self::AVAILABLE:
				return __( 'Available', 'ffcertificate' );
			case self::BOOKED:
				return __( 'Booked', 'ffcertificate' );
			case ClosedDateKind::OPTIONAL:
				return __( 'Optional Holiday', 'ffcertificate' );
			case self::CLOSED:
				return __( 'Closed', 'ffcertificate' );
			default:
				return __( 'Holiday', 'ffcertificate' );
		}
	}

	/**
	 * The translated default day-cell name of a state, which is narrower than
	 * the legend name of an optional holiday in some languages.
	 *
	 * @param string $state State.
	 * @return string
	 */
	public static function default_cell( string $state ): string {
		switch ( $state ) {
			case self::AVAILABLE:
				// An available day carries no text unless the card names it (#2221).
				return '';
			case ClosedDateKind::OPTIONAL:
				return _x( 'Optional', 'optional holiday, in a calendar day cell', 'ffcertificate' );
			case self::BOOKING_SINGULAR:
				return __( 'booking', 'ffcertificate' );
			case self::BOOKING_PLURAL:
				return __( 'bookings', 'ffcertificate' );
			default:
				return self::default_legend( $state );
		}
	}

	/**
	 * The stored settings, every value read safely.
	 *
	 * @return array{colors: array<string, string>, legend: array<string, string>, cell: array<string, string>, show_closed_label: bool}
	 */
	public static function stored(): array {
		$raw = get_option( self::OPTION, array() );
		$raw = is_array( $raw ) ? $raw : array();

		$colors = array();
		$legend = array();
		$cell   = array();
		foreach ( self::DEFAULT_COLORS as $state => $default ) {
			$value            = isset( $raw['colors'][ $state ] ) ? sanitize_hex_color( (string) $raw['colors'][ $state ] ) : '';
			$colors[ $state ] = $value ? strtolower( $value ) : $default;
			$legend[ $state ] = isset( $raw['legend'][ $state ] ) && is_string( $raw['legend'][ $state ] ) ? $raw['legend'][ $state ] : '';
		}
		foreach ( self::CELL_NAMES as $state ) {
			$cell[ $state ] = isset( $raw['cell'][ $state ] ) && is_string( $raw['cell'][ $state ] ) ? $raw['cell'][ $state ] : '';
		}

		return array(
			'colors'            => $colors,
			'legend'            => $legend,
			'cell'              => $cell,
			'show_closed_label' => ! array_key_exists( 'show_closed_label', $raw ) || (bool) $raw['show_closed_label'],
		);
	}

	/**
	 * The light-theme colour of each state.
	 *
	 * @return array<string, string>
	 */
	public static function colors(): array {
		return self::stored()['colors'];
	}

	/**
	 * A state's legend name: the calendar's, else the administrator's, else
	 * the default.
	 *
	 * @param string               $state    State.
	 * @param array<string, mixed> $override A calendar's own names (#2223), from {@see self::clean_override()}.
	 * @return string
	 */
	public static function legend( string $state, array $override = array() ): string {
		$own = $override['legend'][ $state ] ?? '';
		if ( is_string( $own ) && '' !== $own ) {
			return $own;
		}
		$custom = self::stored()['legend'][ $state ] ?? '';
		return '' !== $custom ? $custom : self::default_legend( $state );
	}

	/**
	 * A state's day-cell name: the calendar's, else the administrator's,
	 * else the default.
	 *
	 * @param string               $state    State.
	 * @param array<string, mixed> $override A calendar's own names (#2223), from {@see self::clean_override()}.
	 * @return string
	 */
	public static function cell( string $state, array $override = array() ): string {
		$own = $override['cell'][ $state ] ?? '';
		if ( is_string( $own ) && '' !== $own ) {
			return $own;
		}
		$custom = self::stored()['cell'][ $state ] ?? '';
		return '' !== $custom ? $custom : self::default_cell( $state );
	}

	/**
	 * Whether a closed day's cell carries its name: the calendar's choice
	 * when it made one, else the general switch.
	 *
	 * @param array<string, mixed> $override A calendar's own names (#2223).
	 * @return bool
	 */
	public static function show_closed_label( array $override = array() ): bool {
		$own = $override['show_closed_label'] ?? self::SHOW_INHERIT;
		if ( self::SHOW === $own ) {
			return true;
		}
		if ( self::HIDE === $own ) {
			return false;
		}
		return self::stored()['show_closed_label'];
	}

	/**
	 * A calendar's own names (#2223), read safely from what was stored: a
	 * decoded array or its JSON. Only non-empty names of known states are
	 * kept, so an empty field always inherits.
	 *
	 * @param mixed $raw Stored value.
	 * @return array{legend: array<string, string>, cell: array<string, string>, show_closed_label: string}
	 */
	public static function clean_override( $raw ): array {
		if ( is_string( $raw ) && '' !== $raw ) {
			$raw = json_decode( $raw, true );
		}
		$raw = is_array( $raw ) ? $raw : array();

		$out = array(
			'legend'            => array(),
			'cell'              => array(),
			'show_closed_label' => self::SHOW_INHERIT,
		);
		foreach ( self::states() as $state ) {
			$value = isset( $raw['legend'][ $state ] ) ? self::clean_name( $raw['legend'][ $state ] ) : '';
			if ( '' !== $value ) {
				$out['legend'][ $state ] = $value;
			}
		}
		foreach ( self::CELL_NAMES as $state ) {
			$value = isset( $raw['cell'][ $state ] ) ? self::clean_name( $raw['cell'][ $state ] ) : '';
			if ( '' !== $value ) {
				$out['cell'][ $state ] = $value;
			}
		}
		$show = $raw['show_closed_label'] ?? self::SHOW_INHERIT;
		if ( in_array( $show, array( self::SHOW, self::HIDE ), true ) ) {
			$out['show_closed_label'] = $show;
		}
		return $out;
	}

	/**
	 * Whether a calendar's names change anything, so an untouched block is
	 * stored as nothing.
	 *
	 * @param array<string, mixed> $override From {@see self::clean_override()}.
	 * @return bool
	 */
	public static function override_is_empty( array $override ): bool {
		return empty( $override['legend'] ) && empty( $override['cell'] )
			&& self::SHOW_INHERIT === ( $override['show_closed_label'] ?? self::SHOW_INHERIT );
	}

	/**
	 * A calendar's names as a column value: JSON, or NULL when they change
	 * nothing, so an untouched calendar stores no override.
	 *
	 * @param mixed $raw Posted names, a stored array or a JSON string.
	 * @return string|null
	 */
	public static function encode_override( $raw ): ?string {
		$override = self::clean_override( $raw );
		if ( self::override_is_empty( $override ) ) {
			return null;
		}
		$json = wp_json_encode( $override );
		return is_string( $json ) ? $json : null;
	}

	/**
	 * Several calendars' names as one, for a page that shows them together:
	 * the first non-empty name wins per state, and the first explicit
	 * closed-label choice — the rule the audience booking label already
	 * follows.
	 *
	 * @param array<int, array<string, mixed>> $overrides From {@see self::clean_override()}, in display order.
	 * @return array{legend: array<string, string>, cell: array<string, string>, show_closed_label: string}
	 */
	public static function merge_overrides( array $overrides ): array {
		$out = array(
			'legend'            => array(),
			'cell'              => array(),
			'show_closed_label' => self::SHOW_INHERIT,
		);
		foreach ( $overrides as $override ) {
			foreach ( array( 'legend', 'cell' ) as $part ) {
				foreach ( (array) ( $override[ $part ] ?? array() ) as $state => $name ) {
					if ( ! isset( $out[ $part ][ $state ] ) && is_string( $name ) && '' !== $name ) {
						$out[ $part ][ $state ] = $name;
					}
				}
			}
			$show = $override['show_closed_label'] ?? self::SHOW_INHERIT;
			if ( self::SHOW_INHERIT === $out['show_closed_label'] && in_array( $show, array( self::SHOW, self::HIDE ), true ) ) {
				$out['show_closed_label'] = $show;
			}
		}
		return $out;
	}

	/**
	 * Store what the card posted. A colour that is not a hex colour keeps the
	 * stored one, so a cleared picker changes nothing; a name is plain text
	 * and an empty one returns the state to its default.
	 *
	 * @param array<string, mixed> $colors            State => colour.
	 * @param array<string, mixed> $legend            State => legend name.
	 * @param array<string, mixed> $cell              State => day-cell name.
	 * @param bool                 $show_closed_label Whether a closed cell carries its name.
	 * @return void
	 */
	public static function save( array $colors, array $legend, array $cell, bool $show_closed_label ): void {
		$current = self::stored();
		$out     = array(
			'colors'            => $current['colors'],
			'legend'            => array(),
			'cell'              => array(),
			'show_closed_label' => $show_closed_label,
		);
		foreach ( self::states() as $state ) {
			$value = isset( $colors[ $state ] ) ? sanitize_hex_color( (string) $colors[ $state ] ) : '';
			if ( $value ) {
				$out['colors'][ $state ] = strtolower( $value );
			}
			$out['legend'][ $state ] = isset( $legend[ $state ] ) ? self::clean_name( $legend[ $state ] ) : '';
		}
		foreach ( self::CELL_NAMES as $state ) {
			$out['cell'][ $state ] = isset( $cell[ $state ] ) ? self::clean_name( $cell[ $state ] ) : '';
		}
		update_option( self::OPTION, $out, false );
	}

	/**
	 * Return every state to its default colour and name, the label switch on.
	 *
	 * @return void
	 */
	public static function reset(): void {
		delete_option( self::OPTION );
	}

	/**
	 * A posted name as stored: one line of plain text, at most 40 characters.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function clean_name( $value ): string {
		$value = trim( sanitize_text_field( is_scalar( $value ) ? (string) $value : '' ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, 40 ) : substr( $value, 0, 40 );
	}

	/**
	 * The contrast of the available colour on a white day, which is where
	 * its border and its day number are drawn. Below 4.5:1 the card warns.
	 *
	 * @param string $color Hex colour.
	 * @return float|null Null when the colour cannot be read.
	 */
	public static function available_contrast( string $color ): ?float {
		return ContrastColor::ratio( $color, '#ffffff' );
	}

	/**
	 * The labels the calendar scripts need, a calendar's own names applied.
	 *
	 * @param array<string, mixed> $override A calendar's own names (#2223), from {@see self::clean_override()}.
	 * @return array{available: string, booked: string, holiday: string, closed: string, closedCell: string, availableCell: string, booking: string, bookings: string, showClosedLabel: bool, closedDateKinds: array<string, array{label: string, short: string}>}
	 */
	public static function strings( array $override = array() ): array {
		$kinds = array();
		foreach ( ClosedDateKind::all() as $kind ) {
			$kinds[ $kind ] = array(
				'label' => self::legend( $kind, $override ),
				'short' => self::cell( $kind, $override ),
			);
		}
		return array(
			'available'       => self::legend( self::AVAILABLE, $override ),
			'booked'          => self::legend( self::BOOKED, $override ),
			'holiday'         => self::legend( ClosedDateKind::HOLIDAY, $override ),
			'closed'          => self::legend( self::CLOSED, $override ),
			'closedCell'      => self::cell( self::CLOSED, $override ),
			'availableCell'   => self::cell( self::AVAILABLE, $override ),
			'booking'         => self::cell( self::BOOKING_SINGULAR, $override ),
			'bookings'        => self::cell( self::BOOKING_PLURAL, $override ),
			'showClosedLabel' => self::show_closed_label( $override ),
			'closedDateKinds' => $kinds,
		);
	}

	/**
	 * The CSS that applies the chosen colours, in the light theme only: the
	 * dark palette is measured by `DarkModeCssTest` and a colour picked for a
	 * white page is not mixed into it. Empty when every colour is the
	 * default, which the stylesheet already declares.
	 *
	 * @return string
	 */
	public static function inline_css(): string {
		$declarations = '';
		foreach ( self::colors() as $state => $color ) {
			if ( self::DEFAULT_COLORS[ $state ] === $color ) {
				continue;
			}
			$declarations .= self::declarations( $state, $color );
		}
		return '' === $declarations ? '' : ':root:not(.ffc-dark-mode){' . $declarations . '}';
	}

	/**
	 * The custom properties one state's colour sets.
	 *
	 * @param string $state State.
	 * @param string $color Hex colour.
	 * @return string
	 */
	private static function declarations( string $state, string $color ): string {
		switch ( $state ) {
			case self::AVAILABLE:
				// Border, day number, legend dot and the hover ring.
				return '--ffc-cal-available:' . $color . ';--ffc-cal-available-ring:' . $color . ';';
			case self::CLOSED:
				// The cell's name and the legend dot, which the default draws apart.
				return '--ffc-cal-closed:' . $color . ';--ffc-text-on-cal-closed:' . ContrastColor::on( $color ) . ';--ffc-cal-closed-dot:' . $color . ';';
			case self::BOOKED:
				$token = 'cal-booked';
				break;
			case ClosedDateKind::OPTIONAL:
				$token = 'optional-holiday';
				break;
			default:
				$token = 'holiday';
		}
		return '--ffc-' . $token . ':' . $color . ';--ffc-text-on-' . $token . ':' . ContrastColor::on( $color ) . ';';
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
