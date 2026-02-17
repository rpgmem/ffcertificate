<?php
declare(strict_types=1);

/**
 * Static Repository Trait
 *
 * Provides centralized database access for static repository classes.
 * Replaces repeated `global $wpdb` declarations with a single `db()` method,
 * enabling easier testing via method override in subclasses.
 *
 * @since 4.12.27
 * @package FreeFormCertificate\Core
 */

namespace FreeFormCertificate\Core;

if (!defined('ABSPATH')) exit;

trait StaticRepositoryTrait {

    /**
     * Get the WordPress database object.
     *
     * Centralizes `global $wpdb` access for static repository methods.
     * Can be overridden in test subclasses to inject a mock.
     *
     * @return \wpdb
     */
    protected static function db(): \wpdb {
        global $wpdb;
        return $wpdb;
    }

    /**
     * Cache group for this repository. Override in using class.
     *
     * @return string
     */
    protected static function cache_group(): string {
        return 'ffc_default';
    }

    /**
     * Default cache expiration in seconds. Override if needed.
     *
     * @return int
     */
    protected static function cache_ttl(): int {
        return 3600;
    }

    /**
     * Get a value from the object cache.
     *
     * @param string $key Cache key.
     * @return mixed Cached value, or false if not found.
     */
    protected static function cache_get( string $key ) {
        return wp_cache_get( $key, static::cache_group() );
    }

    /**
     * Set a value in the object cache.
     *
     * @param string $key   Cache key.
     * @param mixed  $value Value to cache.
     * @param int    $ttl   Optional. TTL in seconds. Default uses cache_ttl().
     * @return bool
     */
    protected static function cache_set( string $key, $value, int $ttl = 0 ): bool {
        return wp_cache_set( $key, $value, static::cache_group(), $ttl ?: static::cache_ttl() );
    }

    /**
     * Delete a value from the object cache.
     *
     * @param string $key Cache key.
     * @return bool
     */
    protected static function cache_delete( string $key ): bool {
        return wp_cache_delete( $key, static::cache_group() );
    }
}
