<?php
/**
 * RequestInput
 *
 * Request-input accessors sliced out of the Core\Utils god-utility
 * (#563 Sprint 3, B1 phase 1 / PR 3b): typed, sanitised readers for
 * `$_POST` / `$_GET` values. Nonce verification stays the caller's
 * responsibility (these are read helpers, not security gates).
 *
 * @package FreeFormCertificate\Core
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Typed request-input accessors.
 */
class RequestInput {

	/**
	 * Read + sanitize a `$_POST` array value.
	 *
	 * Returns `$default` when the key is absent or the underlying value
	 * is not an array. Caller is responsible for nonce verification BEFORE
	 * calling this helper. Keys (string or int) are preserved by
	 * `array_map`'s single-callback behavior.
	 *
	 * @since 6.6.1
	 * @param string                  $key     `$_POST` key.
	 * @param array<array-key, mixed> $default Returned when the key is absent or not an array.
	 * @return array<array-key, string|mixed> Sanitized string values; preserves keys.
	 */
	public static function get_post_array( string $key, array $default = array() ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller responsibility.
		if ( ! isset( $_POST[ $key ] ) ) {
			return $default;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller responsibility.
		$raw = wp_unslash( $_POST[ $key ] );
		if ( ! is_array( $raw ) ) {
			return $default;
		}
		return array_map( 'sanitize_text_field', $raw );
	}

	/**
	 * Read + sanitize a `$_POST` string value.
	 *
	 * Returns `$default` when the key is absent or the underlying value
	 * is not a string (e.g. an array). Caller is responsible for nonce
	 * verification BEFORE calling this helper.
	 *
	 * @since 6.6.1
	 * @param string $key     `$_POST` key.
	 * @param string $default Returned when the key is absent/non-string.
	 * @return string Sanitized text-field value.
	 */
	public static function get_post_string( string $key, string $default = '' ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller responsibility.
		if ( ! isset( $_POST[ $key ] ) ) {
			return $default;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller responsibility.
		$raw = wp_unslash( $_POST[ $key ] );
		if ( ! is_string( $raw ) ) {
			return $default;
		}
		return sanitize_text_field( $raw );
	}

	/**
	 * Read + sanitize a `$_GET` string value. Same contract as
	 * {@see self::get_post_string()}, just for `$_GET`.
	 *
	 * @since 6.6.1
	 * @param string $key     `$_GET` key.
	 * @param string $default Returned when the key is absent/non-string.
	 * @return string Sanitized text-field value.
	 */
	public static function get_get_string( string $key, string $default = '' ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Caller responsibility.
		if ( ! isset( $_GET[ $key ] ) ) {
			return $default;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Caller responsibility.
		$raw = wp_unslash( $_GET[ $key ] );
		if ( ! is_string( $raw ) ) {
			return $default;
		}
		return sanitize_text_field( $raw );
	}

	/**
	 * Read a non-negative integer `$_POST` value via `absint()`.
	 * Returns `$default` when the key is absent. Caller is responsible
	 * for nonce verification BEFORE calling this helper.
	 *
	 * @since 6.6.1
	 * @param string $key     `$_POST` key.
	 * @param int    $default Returned when the key is absent.
	 * @return int Non-negative integer.
	 */
	public static function get_post_int( string $key, int $default = 0 ): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller responsibility.
		if ( ! isset( $_POST[ $key ] ) ) {
			return $default;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller responsibility.
		return absint( wp_unslash( $_POST[ $key ] ) );
	}

	/**
	 * Read a checkbox-style `$_POST` bool. `! empty()` semantics:
	 * any non-empty value (`'1'`, `'on'`, etc.) returns true; absence
	 * or empty-string returns false. Caller is responsible for nonce
	 * verification BEFORE calling this helper.
	 *
	 * @since 6.6.1
	 * @param string $key     `$_POST` key.
	 * @param bool   $default Returned when the key is absent.
	 * @return bool
	 */
	public static function get_post_bool( string $key, bool $default = false ): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller responsibility.
		if ( ! isset( $_POST[ $key ] ) ) {
			return $default;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller responsibility.
		return ! empty( $_POST[ $key ] );
	}

	/**
	 * Coerce an already-read raw value to bool using the `1` / `true` / `on` /
	 * `yes` allowlist (case-insensitive); anything else — including an array —
	 * is false.
	 *
	 * This is the stricter sibling of {@see self::get_post_bool()} (which uses
	 * `! empty()` semantics): it is for values that carry an explicit truthy
	 * token, such as a checkbox `value="on"` or a JSON `"true"`, where a bare
	 * non-empty string like `"0"` must read as false. Takes the raw value (not
	 * a `$_POST` key) so it composes with a caller's own read/unslash.
	 *
	 * @since 6.20.0
	 * @param mixed $raw Raw value (already read; may be any type).
	 * @return bool
	 */
	public static function is_truthy( $raw ): bool {
		if ( is_array( $raw ) ) {
			return false;
		}
		return in_array( strtolower( (string) $raw ), array( '1', 'true', 'on', 'yes' ), true );
	}

	/**
	 * Get the client IP address, with proxy / CDN support.
	 *
	 * Thin delegate to {@see ClientIpResolver::resolve()} — the single home for
	 * IP resolution since #899. During phase 1 the resolver's effective strategy
	 * is `legacy`, so this returns exactly what the historical inline header walk
	 * returned (first public, non-reserved IP; `'0.0.0.0'` when none validates).
	 * Reads from `$_SERVER` (the request envelope), which is why the accessor
	 * lives on the request-input reader.
	 *
	 * @since 6.12.0
	 * @return string IP address, or `'0.0.0.0'` when none could be resolved.
	 */
	public static function get_user_ip(): string {
		return ClientIpResolver::resolve();
	}
}
