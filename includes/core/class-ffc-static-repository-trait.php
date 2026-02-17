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
}
