<?php
/**
 * AttendanceTargetRegistry
 *
 * The type → target lookup behind {@see Attendance::record()}. Core owns the
 * interface and this registry; each module that records attendance registers
 * a factory from its loader, so Core references no feature class and the
 * `Core → feature` edge stays absent — the {@see SourceRegistry} pattern of
 * the CSV export (#772, #2740).
 *
 * @package FreeFormCertificate\Core
 * @since   6.46.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static registry of attendance target factories, keyed by type.
 */
class AttendanceTargetRegistry {

	/**
	 * Registered factories. Each returns an {@see AttendanceTargetInterface};
	 * {@see self::get()} checks that when it builds one.
	 *
	 * @var array<string, callable>
	 */
	private static array $factories = array();

	/**
	 * Register (or replace) the factory for a target type.
	 *
	 * @param string   $type    Stable type id (matches the target's `type()`).
	 * @param callable $factory Lazily builds the target.
	 * @return void
	 */
	public static function register( string $type, callable $factory ): void {
		self::$factories[ $type ] = $factory;
	}

	/**
	 * Whether a type is registered.
	 *
	 * @param string $type Target type.
	 * @return bool
	 */
	public static function has( string $type ): bool {
		return isset( self::$factories[ $type ] );
	}

	/**
	 * Build the target for a type, or null when it is unknown, mis-built, or
	 * built under another type than the one it was registered for.
	 *
	 * @param string $type Target type.
	 * @return AttendanceTargetInterface|null
	 */
	public static function get( string $type ): ?AttendanceTargetInterface {
		if ( ! isset( self::$factories[ $type ] ) ) {
			return null;
		}
		$target = ( self::$factories[ $type ] )();
		return $target instanceof AttendanceTargetInterface && $target->type() === $type ? $target : null;
	}

	/**
	 * Registered type ids.
	 *
	 * @return list<string>
	 */
	public static function types(): array {
		return array_keys( self::$factories );
	}

	/**
	 * Forget every registration (tests).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$factories = array();
	}
}
