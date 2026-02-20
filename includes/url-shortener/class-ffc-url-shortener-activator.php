<?php
declare(strict_types=1);

/**
 * URL Shortener Activator
 *
 * Creates and migrates the ffc_short_urls database table.
 *
 * @since 5.1.0
 * @package FreeFormCertificate\UrlShortener
 */

namespace FreeFormCertificate\UrlShortener;

use FreeFormCertificate\Core\DatabaseHelperTrait;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class UrlShortenerActivator {

    use DatabaseHelperTrait;

    /**
     * Get the short URLs table name.
     *
     * @return string
     */
    public static function get_table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'ffc_short_urls';
    }

    /**
     * Create the short URLs table.
     */
    public static function create_tables(): void {
        global $wpdb;

        $table_name      = self::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        if ( self::table_exists( $table_name ) ) {
            return;
        }

        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            short_code varchar(10) NOT NULL,
            target_url text NOT NULL,
            post_id bigint(20) unsigned DEFAULT NULL,
            title varchar(255) DEFAULT '',
            click_count bigint(20) unsigned DEFAULT 0,
            created_by bigint(20) unsigned DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            status varchar(20) DEFAULT 'active',
            PRIMARY KEY (id),
            UNIQUE KEY idx_short_code (short_code),
            KEY idx_post_id (post_id),
            KEY idx_status (status)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Run on plugins_loaded to handle schema updates.
     */
    public static function maybe_migrate(): void {
        $table_name = self::get_table_name();

        if ( ! self::table_exists( $table_name ) ) {
            self::create_tables();
        }
    }
}
