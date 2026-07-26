<?php
/**
 * SourceRegistry
 *
 * The type → source lookup that lets the single {@see BatchedExportDispatcher}
 * route an AJAX export request to the right {@see BatchedExportSourceInterface}
 * without the dispatcher (or the Core module) knowing any concrete source.
 *
 * Dependency inversion: Core owns the interface, the engine, the dispatcher and
 * this registry; the concrete sources live in the feature modules and register a
 * factory here at bootstrap (`SourceRegistry::register('submissions', fn() =>
 * new SubmissionsExportSource( … ) )`). The factory is a closure so the source
 * (and its repository) is built lazily, only for the request that actually
 * dispatches that type — and so registering never touches `$wpdb`. Because the
 * closure is defined in the feature module, Core still references no feature
 * class → the `Core → feature` edge stays absent. (Issue #772.)
 *
 * @package FreeFormCertificate\Core
 * @since   6.17.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static registry of batched-export source factories, keyed by `type()`.
 */
class SourceRegistry {

	/**
	 * Registered factories, keyed by source type. Each is expected to return a
	 * {@see BatchedExportSourceInterface}; {@see self::get()} enforces that at
	 * call time.
	 *
	 * @var array<string, callable>
	 */
	private static array $factories = array();

	/**
	 * Register (or replace) the factory for a source type.
	 *
	 * @param string   $type    Stable source id (matches the source's `type()`).
	 * @param callable $factory Lazily builds the source (returns a BatchedExportSourceInterface).
	 * @return void
	 */
	public static function register( string $type, callable $factory ): void {
		self::$factories[ $type ] = $factory;
	}

	/**
	 * Whether a factory is registered for a type.
	 *
	 * @param string $type Source type.
	 * @return bool
	 */
	public static function has( string $type ): bool {
		return isset( self::$factories[ $type ] );
	}

	/**
	 * Build the source for a type, or null when unknown / mis-built.
	 *
	 * @param string $type Source type.
	 * @return BatchedExportSourceInterface|null
	 */
	public static function get( string $type ): ?BatchedExportSourceInterface {
		if ( ! isset( self::$factories[ $type ] ) ) {
			return null;
		}
		$source = ( self::$factories[ $type ] )();
		return $source instanceof BatchedExportSourceInterface ? $source : null;
	}

	/**
	 * Registered type ids (for diagnostics / tests).
	 *
	 * @return array<int, string>
	 */
	public static function types(): array {
		return array_keys( self::$factories );
	}

	/**
	 * Drop every registration. Test-only seam.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$factories = array();
	}
}
