<?php
/**
 * Working-hours row sanitizer.
 *
 * @package FreeFormCertificate\Core
 * @since   6.24.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The one place that decides whether a `working_hours` row is usable (#1128).
 *
 * A `working_hours` value is a JSON array of day rows, carried in an
 * `<input type="hidden">` and built by `assets/js/ffc-working-hours.js`. That
 * transport is why this class exists: a hidden input is **barred from HTML
 * constraint validation**, so the field's own `is_required` flag — which #1120
 * made real for every scalar field type — can never fire for this one. The
 * `required` attributes on the two visible `<input type="time">` cells are the
 * only client-side gate, they carry no `name` (the JSON travels instead), and
 * `FFC.setRequiredWithin()` deliberately strips them while a section is
 * collapsed so an invisible field cannot jam the save. Every one of those is
 * correct on its own; together they leave the server as the only guard, and
 * until #1128 there wasn't one.
 *
 * **The rule, confirmed by the maintainer:** the first shift's entry
 * (`entry1`) and the last shift's exit (`exit2`) are required; the middle
 * (`exit1`, `entry2` — the lunch break) is optional. It had never been written
 * down anywhere, which is why `exit2` being required while `exit1` is not read
 * as arbitrary in the markup.
 *
 * Two shapes of incomplete row, treated differently on purpose:
 *
 *   - **No times at all** — dropped in silence. "I do not work this day" is a
 *     legitimate intention, and a day with no row already says it.
 *   - **Some times, missing a required one** — the row is dropped and the day
 *     is *reported*, so the caller can tell the operator which day to redo.
 *     Dropping just the bad row rather than reverting the whole field keeps
 *     the other rows edited in the same save; the worst case stays "an edit
 *     that did not take, announced", never a value silently erased — the
 *     principle #1120 set for the scalar types.
 *
 * **Deliberately not validated here: the time format.** The cells are
 * `<input type="time">`, so a browser sends `HH:MM`; a hand-rolled POST can
 * send anything and this class will store it. That is the pre-existing
 * behaviour and widening to a format check is a separate decision — noted so
 * the omission reads as a choice rather than an oversight.
 */
final class WorkingHours {

	/**
	 * Row keys that must carry a value for the row to be usable.
	 *
	 * @var array<int, string>
	 */
	public const REQUIRED_KEYS = array( 'entry1', 'exit2' );

	/**
	 * Row keys that may be empty (the optional middle shift).
	 *
	 * @var array<int, string>
	 */
	public const OPTIONAL_KEYS = array( 'exit1', 'entry2' );

	/**
	 * Sanitize a raw working-hours JSON string.
	 *
	 * @param string $raw Raw JSON as posted.
	 * @return array{json: string, incomplete: array<int, array{day: int, missing: array<int, string>}>}
	 *         `json` is the canonical JSON to store — always a valid array,
	 *         `'[]'` when the input is unusable. `incomplete` lists the rows
	 *         dropped for missing a required time, in input order; a row with
	 *         no times at all is dropped without appearing here.
	 */
	public static function sanitize( string $raw ): array {
		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) ) {
			return array(
				'json'       => '[]',
				'incomplete' => array(),
			);
		}

		$rows       = array();
		$incomplete = array();

		foreach ( $decoded as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['day'] ) ) {
				continue;
			}

			$times = array();
			foreach ( array_merge( self::REQUIRED_KEYS, self::OPTIONAL_KEYS ) as $key ) {
				$value         = $entry[ $key ] ?? '';
				$times[ $key ] = is_scalar( $value ) ? sanitize_text_field( trim( (string) $value ) ) : '';
			}

			// A row with no time at all is "I do not work this day" — drop it
			// without reporting, since there is nothing for the operator to fix.
			if ( '' === implode( '', $times ) ) {
				continue;
			}

			$missing = array();
			foreach ( self::REQUIRED_KEYS as $key ) {
				if ( '' === $times[ $key ] ) {
					$missing[] = $key;
				}
			}

			if ( ! empty( $missing ) ) {
				$incomplete[] = array(
					'day'     => absint( $entry['day'] ),
					'missing' => $missing,
				);
				continue;
			}

			$rows[] = array_merge( array( 'day' => absint( $entry['day'] ) ), $times );
		}

		return array(
			'json'       => (string) wp_json_encode( $rows ),
			'incomplete' => $incomplete,
		);
	}

	/**
	 * Human-readable label for a weekday index.
	 *
	 * Kept beside the sanitizer because both callers need the same wording for
	 * the same index, and `wp_locale` is the one source that already knows it.
	 *
	 * @param int $day Weekday index, 0 = Sunday.
	 * @return string Localized weekday name, or the raw index when out of range.
	 */
	public static function day_label( int $day ): string {
		global $wp_locale;

		if ( $day < 0 || $day > 6 || ! isset( $wp_locale ) || ! is_object( $wp_locale ) || ! method_exists( $wp_locale, 'get_weekday' ) ) {
			return (string) $day;
		}

		return (string) $wp_locale->get_weekday( $day );
	}

	/**
	 * Label for a required row key, for the "what is missing" notice.
	 *
	 * @param string $key Row key.
	 * @return string
	 */
	public static function key_label( string $key ): string {
		$labels = array(
			'entry1' => __( 'Entry 1', 'ffcertificate' ),
			'exit1'  => __( 'Exit 1', 'ffcertificate' ),
			'entry2' => __( 'Entry 2', 'ffcertificate' ),
			'exit2'  => __( 'Exit 2', 'ffcertificate' ),
		);

		return $labels[ $key ] ?? $key;
	}
}
