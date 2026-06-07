<?php
/**
 * Canonical date/time formatter for the plugin.
 *
 * Single point of truth for how date and time values are rendered
 * across admin lists, frontend pages, emails, REST responses and
 * PDFs. Reads from the plugin's own `ffc_settings` option (see
 * General → Date Format) instead of WordPress's `get_option('date_format')`
 * so a site can configure the plugin's behaviour independently of
 * the rest of WP.
 *
 * Schema in `ffc_settings`:
 *   - 'date_format'        — required, fed to wp_date()/date_i18n()
 *                            for the date portion. Empty string falls
 *                            back to the plugin default ('d/m/Y' since
 *                            #244; was 'F j, Y' pre-#244 — installs
 *                            that explicitly saved 'F j, Y' keep it).
 *   - 'time_format'        — required, default 'H:i'. 'custom' delegates
 *                            to 'time_format_custom' (#248).
 *   - 'time_format_custom' — only consulted when time_format === 'custom'.
 *   - 'date_format_custom' — only consulted when date_format === 'custom'.
 *   - 'date_format_pdf'    — optional override applied when callers pass
 *                            $context = 'pdf'. Empty inherits date_format.
 *                            When the value is the 'custom' sentinel,
 *                            `date_format_pdf_custom` is consulted instead
 *                            (#248, same idiom as date_format).
 *   - 'date_format_pdf_custom' — only consulted when date_format_pdf === 'custom'.
 *   - 'time_format_pdf'    — same idea for time. 'custom' delegates to
 *                            'time_format_pdf_custom'.
 *   - 'time_format_pdf_custom' — only consulted when time_format_pdf === 'custom'.
 *
 * The plugin previously had no helper — every call site reached for
 * `get_option('date_format')` (WP-wide) or hardcoded a format string,
 * so the `ffc_settings['date_format']` setting was effectively
 * decorative (only the Settings page preview consumed it). #244
 * threads this helper through the user-visible call sites.
 *
 * @package FreeFormCertificate\Core
 * @since   6.5.15
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Canonical date/time formatter.
 */
final class DateFormatter {

	/**
	 * Default date format applied when the option is unset or empty.
	 * Aligned with the typical Brazilian locale; admins who saved a
	 * different value via Settings → General keep their choice.
	 */
	public const DEFAULT_DATE_FORMAT = 'd/m/Y';

	/**
	 * Default time format applied when the option is unset or empty.
	 */
	public const DEFAULT_TIME_FORMAT = 'H:i';

	/**
	 * Format a date.
	 *
	 * @param int|string|null           $timestamp_or_string Unix timestamp,
	 *                                                       date string, or
	 *                                                       null. Strings are
	 *                                                       parsed via
	 *                                                       strtotime(). Null
	 *                                                       / unparseable
	 *                                                       values return ''.
	 * @param string                    $context             'default' or 'pdf'
	 *                                                       ('pdf' reads the
	 *                                                       per-context
	 *                                                       override and
	 *                                                       falls back to the
	 *                                                       default format
	 *                                                       if empty).
	 * @param \DateTimeZone|string|null $tz             Optional timezone
	 *                                                  override; defaults
	 *                                                  to the site
	 *                                                  timezone.
	 * @return string Formatted date, or '' on unparseable input.
	 */
	public static function format_date( $timestamp_or_string, string $context = 'default', $tz = null ): string {
		$ts = self::resolve_timestamp( $timestamp_or_string );
		if ( null === $ts ) {
			return '';
		}
		// `wp_date()` may return false on invalid timezone; coerce defensively.
		$formatted = \wp_date( self::resolve_date_format( $context ), $ts, self::resolve_timezone( $tz ) );
		return false === $formatted ? '' : $formatted;
	}

	/**
	 * Format a time.
	 *
	 * Same parameter contract as {@see format_date()}.
	 *
	 * @param int|string|null           $timestamp_or_string Source value.
	 * @param string                    $context             'default' or 'pdf'.
	 * @param \DateTimeZone|string|null $tz             Optional timezone.
	 * @return string Formatted time, or '' on unparseable input.
	 */
	public static function format_time( $timestamp_or_string, string $context = 'default', $tz = null ): string {
		$ts = self::resolve_timestamp( $timestamp_or_string );
		if ( null === $ts ) {
			return '';
		}
		$formatted = \wp_date( self::resolve_time_format( $context ), $ts, self::resolve_timezone( $tz ) );
		return false === $formatted ? '' : $formatted;
	}

	/**
	 * Format a combined date + time.
	 *
	 * @param int|string|null           $timestamp_or_string Source value.
	 * @param string                    $context             'default' or 'pdf'.
	 * @param string                    $separator           Glue between date
	 *                                                       and time, default
	 *                                                       one space.
	 * @param \DateTimeZone|string|null $tz             Optional timezone.
	 * @return string `<date><separator><time>`, or '' on unparseable input.
	 */
	public static function format_datetime( $timestamp_or_string, string $context = 'default', string $separator = ' ', $tz = null ): string {
		$ts = self::resolve_timestamp( $timestamp_or_string );
		if ( null === $ts ) {
			return '';
		}
		$resolved_tz = self::resolve_timezone( $tz );
		$date        = \wp_date( self::resolve_date_format( $context ), $ts, $resolved_tz );
		$time        = \wp_date( self::resolve_time_format( $context ), $ts, $resolved_tz );
		if ( false === $date || false === $time ) {
			return '';
		}
		return $date . $separator . $time;
	}

	/**
	 * Format a wall-clock DATE string (Category B) in the configured date
	 * format, WITHOUT applying the site timezone.
	 *
	 * Category B values — `appointment_date`, `start_time`/`end_time`, etc.
	 * (see CLAUDE.md "Date / time storage convention") — are stored literally
	 * and mean the same thing regardless of timezone. Passing such a string to
	 * {@see format_date()} mis-renders it: WordPress runs PHP with the default
	 * timezone pinned to UTC, so `strtotime('2026-05-20')` yields the instant
	 * at UTC midnight, and `wp_date()` then re-applies `wp_timezone()` — on a
	 * UTC-3 site a 09:00 wall-clock time displays as 06:00. Pinning the render
	 * timezone to UTC (matching how `strtotime()` parsed it) round-trips the
	 * literal value untouched.
	 *
	 * @param string|null $wallclock Literal DATE string (e.g. 'Y-m-d'),
	 *                               may be empty/null.
	 * @param string      $context   'default' or 'pdf'.
	 * @return string Formatted date, or '' on unparseable input.
	 */
	public static function format_wallclock_date( ?string $wallclock, string $context = 'default' ): string {
		return self::format_date( $wallclock, $context, new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * Format a wall-clock TIME string (Category B) in the configured time
	 * format, WITHOUT applying the site timezone. See
	 * {@see format_wallclock_date()} for the rationale.
	 *
	 * @param string|null $wallclock Literal TIME string (e.g. 'H:i:s'),
	 *                               may be empty/null.
	 * @param string      $context   'default' or 'pdf'.
	 * @return string Formatted time, or '' on unparseable input.
	 */
	public static function format_wallclock_time( ?string $wallclock, string $context = 'default' ): string {
		return self::format_time( $wallclock, $context, new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * Resolve the date format for the requested context.
	 *
	 * @param string $context 'default' or 'pdf'.
	 * @return string The PHP date format string to feed wp_date().
	 */
	public static function resolve_date_format( string $context = 'default' ): string {
		$settings = self::settings();
		$base     = self::pick( $settings, 'date_format', self::DEFAULT_DATE_FORMAT );
		if ( 'custom' === $base ) {
			$custom = self::pick( $settings, 'date_format_custom', '' );
			$base   = '' !== $custom ? $custom : self::DEFAULT_DATE_FORMAT;
		}
		if ( 'pdf' === $context ) {
			$pdf = self::pick( $settings, 'date_format_pdf', '' );
			if ( 'custom' === $pdf ) {
				$pdf = self::pick( $settings, 'date_format_pdf_custom', '' );
			}
			if ( '' !== $pdf ) {
				return self::date_only( $pdf );
			}
		}
		// Pre-#244 installs may have stored a combined value like "d/m/Y H:i"
		// in date_format (the setting was effectively decorative back then).
		// `format_datetime()` would otherwise append `time_format` again and
		// duplicate the time — strip on read.
		return self::date_only( $base );
	}

	/**
	 * Resolve the time format for the requested context.
	 *
	 * @param string $context 'default' or 'pdf'.
	 * @return string The PHP time format string to feed wp_date().
	 */
	public static function resolve_time_format( string $context = 'default' ): string {
		$settings = self::settings();
		$base     = self::pick( $settings, 'time_format', self::DEFAULT_TIME_FORMAT );
		if ( 'custom' === $base ) {
			$custom = self::pick( $settings, 'time_format_custom', '' );
			$base   = '' !== $custom ? $custom : self::DEFAULT_TIME_FORMAT;
		}
		if ( 'pdf' === $context ) {
			$pdf = self::pick( $settings, 'time_format_pdf', '' );
			if ( 'custom' === $pdf ) {
				$pdf = self::pick( $settings, 'time_format_pdf_custom', '' );
			}
			if ( '' !== $pdf ) {
				return $pdf;
			}
		}
		return $base;
	}

	/**
	 * Resolve $timestamp_or_string to a Unix timestamp.
	 *
	 * @param mixed $value Raw input.
	 * @return int|null Timestamp, or null if input couldn't be parsed.
	 */
	private static function resolve_timestamp( $value ): ?int {
		if ( null === $value || '' === $value ) {
			return null;
		}
		if ( is_int( $value ) ) {
			return $value;
		}
		if ( is_numeric( $value ) ) {
			return (int) $value;
		}
		if ( is_string( $value ) ) {
			$ts = strtotime( $value );
			return false === $ts ? null : $ts;
		}
		return null;
	}

	/**
	 * Resolve the timezone argument to something `wp_date()` accepts.
	 *
	 * @param \DateTimeZone|string|null $tz Caller-supplied timezone.
	 * @return \DateTimeZone The resolved timezone (site default when null).
	 */
	private static function resolve_timezone( $tz ): \DateTimeZone {
		if ( $tz instanceof \DateTimeZone ) {
			return $tz;
		}
		if ( is_string( $tz ) && '' !== $tz ) {
			try {
				return new \DateTimeZone( $tz );
			} catch ( \Exception $e ) {
				unset( $e ); // Intentional fall-through to site default below.
			}
		}
		return \wp_timezone();
	}

	/**
	 * Read the plugin's settings option as an array.
	 *
	 * No static caching here — WordPress's options API already caches
	 * `get_option()` in its own object cache per request, so a second
	 * read is essentially free. Avoiding our own static prevents test-
	 * isolation issues (Brain\Monkey resets between tests, but a class-
	 * level static would leak the previous test's value).
	 *
	 * @return array<string, mixed>
	 */
	private static function settings(): array {
		return \FreeFormCertificate\Settings\SettingsReader::all();
	}

	/**
	 * Backward-compat shim — pre-#244 tests called this to drop a
	 * static cache. The helper no longer caches, so the method is a
	 * no-op. Kept on the public surface so existing test files don't
	 * break.
	 */
	public static function flush_cache(): void {
		// Intentional no-op (see settings() rationale).
	}

	/**
	 * Render a `start–end` schedule range as a compact human-readable
	 * string — Category B wall-clock per CLAUDE.md.
	 *
	 * Both inputs are bare HH:MM (or HH:MM:SS) strings WITHOUT timezone
	 * semantics; this helper never reaches for `wp_date()` or
	 * `wp_timezone()` because the value has no instant to translate.
	 * If a future locale needs 12-hour rendering (`8 AM to 7:30 PM`)
	 * the format choice goes through `time_format` in
	 * SettingsReader; for now the output is the PT/EN-friendly
	 * `'8h às 19h30'` / `'8h to 7:30 PM'` shape the issue spec calls
	 * out (#366), which strips the `:00` minute marker.
	 *
	 * Empty / null inputs collapse to empty strings on that side —
	 * a one-sided range renders as just the populated side (e.g.
	 * `'8h'` if only start is set), and both sides empty produce `''`.
	 *
	 * @param string|null $start HH:MM or HH:MM:SS, may be empty/null.
	 * @param string|null $end   HH:MM or HH:MM:SS, may be empty/null.
	 * @return string
	 */
	public static function format_schedule( ?string $start, ?string $end ): string {
		$start_str = self::format_compact_time( $start );
		$end_str   = self::format_compact_time( $end );

		if ( '' === $start_str && '' === $end_str ) {
			return '';
		}
		if ( '' === $end_str ) {
			return $start_str;
		}
		if ( '' === $start_str ) {
			return $end_str;
		}

		return sprintf(
			/* translators: 1: start time HH or HHhMM, 2: end time HH or HHhMM */
			__( '%1$s to %2$s', 'ffcertificate' ),
			$start_str,
			$end_str
		);
	}

	/**
	 * Render the duration of a wall-clock range as an i18n string
	 * (#366). Whole hours collapse to the singular/plural pair
	 * (`'1 hour'` / `'11 hours'`); a fractional range emits the
	 * compact `'%dh %02dmin'` shape (`'11h 30min'`).
	 *
	 * Like {@see format_schedule()} this is pure wall-clock arithmetic
	 * — no `wp_timezone()`, no instant — so a TZ change at the site
	 * level leaves the rendered total untouched. Returns empty when
	 * either side is missing or malformed, when the range is
	 * inverted, or when the delta rounds to zero.
	 *
	 * @param string|null $start HH:MM or HH:MM:SS, may be empty/null.
	 * @param string|null $end   HH:MM or HH:MM:SS, may be empty/null.
	 * @return string
	 */
	public static function format_schedule_total( ?string $start, ?string $end ): string {
		$start_min = self::parse_minutes( $start );
		$end_min   = self::parse_minutes( $end );

		if ( null === $start_min || null === $end_min || $end_min <= $start_min ) {
			return '';
		}

		$total_min = $end_min - $start_min;
		$hours     = intdiv( $total_min, 60 );
		$minutes   = $total_min % 60;

		if ( 0 === $minutes ) {
			return sprintf(
				/* translators: %d: total number of whole hours */
				_n( '%d hour', '%d hours', $hours, 'ffcertificate' ),
				$hours
			);
		}

		return sprintf(
			/* translators: 1: whole hours, 2: zero-padded minutes — e.g. "11h 30min" */
			__( '%1$dh %2$02dmin', 'ffcertificate' ),
			$hours,
			$minutes
		);
	}

	/**
	 * Compact HH:MM(:SS) → string. `'08:00'` → `'8h'`, `'19:30'` →
	 * `'19h30'`. Returns '' on empty / null / malformed input.
	 *
	 * @param string|null $time HH:MM or HH:MM:SS, may be empty/null.
	 */
	private static function format_compact_time( ?string $time ): string {
		if ( null === $time || '' === $time ) {
			return '';
		}
		// Match HH:MM with an optional ':SS' tail; reject anything else.
		if ( 1 !== preg_match( '/^([01]\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/', $time, $m ) ) {
			return '';
		}
		$h  = (int) $m[1];
		$mn = (int) $m[2];
		if ( 0 === $mn ) {
			return $h . 'h';
		}
		return $h . 'h' . sprintf( '%02d', $mn );
	}

	/**
	 * HH:MM(:SS) → total minutes since 00:00, or null when malformed.
	 *
	 * @param string|null $time Wall-clock time string.
	 * @return int|null
	 */
	private static function parse_minutes( ?string $time ): ?int {
		if ( null === $time || '' === $time ) {
			return null;
		}
		if ( 1 !== preg_match( '/^([01]\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/', $time, $m ) ) {
			return null;
		}
		return ( (int) $m[1] ) * 60 + (int) $m[2];
	}

	/**
	 * Pull a key out of $settings with a fallback when missing or empty.
	 *
	 * @param array<string,mixed> $settings Settings array.
	 * @param string              $key      Key to read.
	 * @param string              $default  Fallback when missing/empty.
	 * @return string
	 */
	private static function pick( array $settings, string $key, string $default ): string {
		if ( ! isset( $settings[ $key ] ) ) {
			return $default;
		}
		$value = (string) $settings[ $key ];
		return '' !== $value ? $value : $default;
	}

	/**
	 * Drop PHP time-format characters (a A B g G h H i s u v) from a
	 * `date()` format string so a combined value like "d/m/Y H:i" reduces
	 * to its date portion ("d/m/Y"). Honours backslash escapes — escaped
	 * literals like `\H` keep their `H` rather than being treated as the
	 * hour char. Adjacent whitespace and trailing punctuation that
	 * becomes orphaned after the strip is cleaned up so the result is
	 * presentable on its own.
	 *
	 * Returns the empty string when stripping leaves nothing — callers
	 * decide whether to fall back to a default. See {@see date_only()}
	 * for the variant that applies the plugin default.
	 *
	 * @param string $format Format string straight from settings.
	 * @return string Stripped format, possibly empty.
	 */
	public static function strip_time_chars( string $format ): string {
		$out = '';
		$len = strlen( $format );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $format[ $i ];
			if ( '\\' === $c && $i + 1 < $len ) {
				$out .= $c . $format[ $i + 1 ];
				++$i;
				continue;
			}
			if ( false !== strpos( 'aABgGhHisuv', $c ) ) {
				continue;
			}
			$out .= $c;
		}
		$collapsed = preg_replace( '/\s+/u', ' ', $out );
		$out       = null === $collapsed ? $out : $collapsed;
		return trim( $out, " \t\n\r\0\x0B,:;-" );
	}

	/**
	 * `strip_time_chars()` with the plugin default applied as fallback.
	 * Used by the runtime resolver so format pipelines never see an empty
	 * format. View code that wants to distinguish "valid stripped value"
	 * from "fell back to default" should call {@see strip_time_chars()}
	 * directly instead.
	 *
	 * @param string $format Format string straight from settings.
	 * @return string Date-only format, never empty.
	 */
	private static function date_only( string $format ): string {
		$stripped = self::strip_time_chars( $format );
		return '' === $stripped ? self::DEFAULT_DATE_FORMAT : $stripped;
	}
}
