<?php
/**
 * Terminal Target Key
 *
 * How a self-service terminal names one thing it checks in (#2890): an
 * attendance target type and an id, written as one value in the terminal's
 * queue, in the pairing code's queue and in the terminal's own state.
 *
 * @package FreeFormCertificate\Core
 * @since   6.52.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds and reads terminal target keys.
 *
 * An audience booking is written as its bare id, a positive integer, which is
 * what every queue stored before a second target type existed. Every other
 * type is written `type:id` (`certificate_form:12`). So no stored queue
 * changes shape and none needs a migration; a bare number is an audience
 * booking by that history, not by preference.
 */
final class TerminalTargetKey {

	/**
	 * The type a bare number names.
	 */
	public const LEGACY_TYPE = 'audience_booking';

	/**
	 * The key of one target.
	 *
	 * @param string $type Target type.
	 * @param int    $id   Id within the type.
	 * @return int|string
	 */
	public static function of( string $type, int $id ) {
		return self::LEGACY_TYPE === $type ? $id : $type . ':' . $id;
	}

	/**
	 * The type and id a key names.
	 *
	 * @param mixed $key A key, as stored or as a request sent it.
	 * @return array{type: string, id: int}|null Null for anything that is not a key.
	 */
	public static function parse( $key ): ?array {
		if ( is_int( $key ) || ( is_string( $key ) && 1 === preg_match( '/^\d+$/', $key ) ) ) {
			$id = (int) $key;
			return $id > 0 ? array(
				'type' => self::LEGACY_TYPE,
				'id'   => $id,
			) : null;
		}
		if ( is_string( $key ) && 1 === preg_match( '/^([a-z][a-z0-9_]{0,40}):(\d+)$/', $key, $match ) && (int) $match[2] > 0 ) {
			return array(
				'type' => $match[1],
				'id'   => (int) $match[2],
			);
		}
		return null;
	}

	/**
	 * A value as a key in its one spelling, or null.
	 *
	 * @param mixed $key Candidate key.
	 * @return int|string|null
	 */
	public static function normalize( $key ) {
		$parts = self::parse( $key );
		return null === $parts ? null : self::of( $parts['type'], $parts['id'] );
	}

	/**
	 * Unique keys, in their one spelling, dropping anything that is not one.
	 *
	 * @param array<mixed> $keys Candidate keys.
	 * @return list<int|string>
	 */
	public static function clean( array $keys ): array {
		$out = array();
		foreach ( $keys as $key ) {
			$key = self::normalize( $key );
			if ( null !== $key && ! in_array( $key, $out, true ) ) {
				$out[] = $key;
			}
		}
		return $out;
	}
}
