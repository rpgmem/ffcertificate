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

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The kinds of closed date. Their names and colours are set with the other
 * calendar states in `CalendarAppearance` (#2215).
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
		return CalendarAppearance::legend( self::normalize( $kind ) );
	}

	/**
	 * The kind's name in a calendar day cell, which is narrower than the full
	 * name of an optional holiday in some languages.
	 *
	 * @param string $kind Kind.
	 * @return string
	 */
	public static function short_label( string $kind ): string {
		return CalendarAppearance::cell( self::normalize( $kind ) );
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
}
