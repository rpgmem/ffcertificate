<?php
/**
 * The real ActivityLog's write buffer, for tests that observe what was logged.
 *
 * ActivityLog::log() buffers each entry and writes the buffer on shutdown, so
 * a test can run the real class and read the buffer instead of replacing the
 * class with an alias mock, which costs the test class a process per test
 * (#2117). reset() clears the static state and marks the shutdown hook as
 * registered, so a log() call never reaches add_action().
 *
 * The WordPress functions log() reaches are stubbed by stub_functions();
 * `get_option( 'ffc_settings' )` stays the test's own stub, because the test
 * decides whether logging is on (ENABLED_SETTINGS turns it on at every level
 * and category).
 *
 * @package FreeFormCertificate\Tests\Support
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Support;

use Brain\Monkey\Functions;
use FreeFormCertificate\Core\ActivityLog;
use FreeFormCertificate\Core\Encryption;

/**
 * Reads and resets the real ActivityLog buffer.
 */
final class ActivityLogBuffer {

	/** SensitiveFieldRegistry's cache key for the admin-configured sensitive fields (private there). */
	private const DYNAMIC_CACHE_KEY = 'ffc_sensitive_field_keys_dynamic';

	/** The `ffc_settings` keys that turn the activity log on. */
	public const ENABLED_SETTINGS = array( 'enable_activity_log' => 1 );

	/**
	 * Clear the buffer and the flags, with the shutdown hook marked as registered.
	 */
	public static function reset(): void {
		self::set( 'write_buffer', array() );
		self::set( 'shutdown_registered', true );
		self::set( 'logging_disabled', false );
	}

	/**
	 * Stub the WordPress functions ActivityLog::log() reaches, other than get_option().
	 *
	 * The dynamic sensitive-key cache answers with an empty list, so the
	 * registry never reads the custom-fields table.
	 */
	public static function stub_functions(): void {
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'current_time' )->justReturn( '2026-10-28 10:00:00' );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_get' )->alias(
			static fn( $key ) => self::DYNAMIC_CACHE_KEY === $key ? array() : false
		);
	}

	/**
	 * The buffered rows, as log() built them.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function entries(): array {
		$ref = new \ReflectionProperty( ActivityLog::class, 'write_buffer' );
		$ref->setAccessible( true );
		return $ref->getValue();
	}

	/**
	 * Each buffered row's action and decoded context, decrypted when log() encrypted it.
	 *
	 * @return array<int, array{action: string, level: string, context: mixed}>
	 */
	public static function contexts(): array {
		$out = array();
		foreach ( self::entries() as $row ) {
			$json  = null !== $row['context_encrypted']
				? Encryption::decrypt( (string) $row['context_encrypted'] )
				: $row['context'];
			$out[] = array(
				'action'  => (string) $row['action'],
				'level'   => (string) $row['level'],
				'context' => json_decode( (string) $json, true ),
			);
		}
		return $out;
	}

	/**
	 * Set one private static property of ActivityLog.
	 *
	 * @param string $name  Property name.
	 * @param mixed  $value Value.
	 */
	private static function set( string $name, $value ): void {
		$ref = new \ReflectionProperty( ActivityLog::class, $name );
		$ref->setAccessible( true );
		$ref->setValue( null, $value );
	}
}
