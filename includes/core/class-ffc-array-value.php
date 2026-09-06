<?php
/**
 * ArrayValue
 *
 * Reads a scalar out of an array whose values are genuinely unknown — a
 * decoded JSON body, a form's stored configuration, a token payload — and
 * returns a usable default when the value is not one.
 *
 * The idiom this replaces is `(string) ( $data['key'] ?? '' )`, which is
 * everywhere and is wrong in the same way each time: a cast does not check
 * anything. Given an array it produces the literal `'Array'` and a PHP
 * notice; given an object without `__toString` it is a fatal. Both outcomes
 * are silent enough to reach production, which is how a filter that matches
 * nothing ships as a filter that works (#1060).
 *
 * This is NOT for request input — `Core\RequestInput` owns `$_POST`/`$_GET`,
 * including the unslashing those need — and NOT for database rows, whose
 * columns are known from the `CREATE TABLE` and belong in a declared row
 * shape. It is for the third case: data the plugin reads back from a store
 * that keeps no types at all.
 *
 * @package FreeFormCertificate\Core
 * @since 6.23.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scalar reads over an untyped array.
 */
final class ArrayValue {

	/**
	 * Read a string.
	 *
	 * A non-scalar value yields the default rather than `'Array'`: a caller
	 * asking for a string has no use for the word Array, and binding it into
	 * a query or writing it to a column is worse than having nothing.
	 *
	 * @param array<array-key, mixed> $data    Source array.
	 * @param array-key               $key     Key to read.
	 * @param string                  $default Returned when the key is absent or not scalar.
	 * @return string
	 */
	public static function string( array $data, $key, string $default = '' ): string {
		$value = $data[ $key ] ?? null;

		return is_scalar( $value ) ? (string) $value : $default;
	}

	/**
	 * Read an integer.
	 *
	 * Only a numeric value counts. `(int)` on an arbitrary string yields 0,
	 * which is indistinguishable from a real zero and has written rows
	 * pointing at record 0 more than once.
	 *
	 * @param array<array-key, mixed> $data    Source array.
	 * @param array-key               $key     Key to read.
	 * @param int                     $default Returned when the key is absent or not numeric.
	 * @return int
	 */
	public static function int( array $data, $key, int $default = 0 ): int {
		$value = $data[ $key ] ?? null;

		return is_numeric( $value ) ? (int) $value : $default;
	}

	/**
	 * Read a boolean, the way HTML forms and JSON both spell one.
	 *
	 * `'0'`, `'false'`, `''` and `0` are false; everything else scalar is
	 * true. A non-scalar yields the default.
	 *
	 * @param array<array-key, mixed> $data    Source array.
	 * @param array-key               $key     Key to read.
	 * @param bool                    $default Returned when the key is absent or not scalar.
	 * @return bool
	 */
	public static function bool( array $data, $key, bool $default = false ): bool {
		$value = $data[ $key ] ?? null;

		if ( ! is_scalar( $value ) ) {
			return $default;
		}

		return ! in_array( strtolower( (string) $value ), array( '', '0', 'false' ), true );
	}

	/**
	 * Read a nested array.
	 *
	 * @param array<array-key, mixed> $data Source array.
	 * @param array-key               $key  Key to read.
	 * @return array<array-key, mixed> Empty when the key is absent or not an array.
	 */
	public static function array( array $data, $key ): array {
		$value = $data[ $key ] ?? null;

		return is_array( $value ) ? $value : array();
	}
}
