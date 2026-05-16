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
 *   - 'time_format'        — required, default 'H:i'.
 *   - 'date_format_custom' — only consulted when date_format === 'custom'.
 *   - 'date_format_pdf'    — optional override applied when callers pass
 *                            $context = 'pdf'. Empty inherits date_format.
 *   - 'time_format_pdf'    — same idea for time.
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
		if ( 'pdf' === $context ) {
			$pdf = self::pick( $settings, 'time_format_pdf', '' );
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
		$value = \get_option( 'ffc_settings', array() );
		return is_array( $value ) ? $value : array();
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
