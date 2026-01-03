<?php
/**
 * FFC_Activator
 * 
 * Plugin activation logic
 * 
 * v2.9.15: Simplified - Uses Migration Manager for data migrations
 * v2.9.16: ADDED - Creates /valid page for magic links
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FFC_Activator {

    /**
     * Plugin activation hook
     */
    public static function activate() {
        self::create_submissions_table();
        self::add_columns();  // ✅ Unified column creation
        self::create_verification_page();  // ✅ v2.9.16: NEW!
        self::run_migrations();  // ✅ Uses Migration Manager
        flush_rewrite_rules();
    }

    /**
     * Create submissions table
     */
    private static function create_submissions_table() {
        global $wpdb;
        $table_name = FFC_Utils::get_submissions_table();
        $charset_collate = $wpdb->get_charset_collate();

        // Check if table already exists
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table_name}'" ) == $table_name ) {
            return; // Table exists, skip creation
        }

        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            form_id bigint(20) unsigned NOT NULL,
            submission_date datetime NOT NULL,
            data longtext NOT NULL,
            user_ip varchar(100) DEFAULT NULL,
            email varchar(255) DEFAULT NULL,
            status varchar(20) DEFAULT 'publish',
            magic_token varchar(32) DEFAULT NULL,
            cpf_rf varchar(20) DEFAULT NULL,
            auth_code varchar(20) DEFAULT NULL,
            PRIMARY KEY (id),
            KEY form_id (form_id),
            KEY status (status),
            KEY email (email),
            KEY magic_token (magic_token),
            KEY cpf_rf (cpf_rf),
            KEY auth_code (auth_code),
            KEY idx_form_cpf (form_id, cpf_rf)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * ✅ Add missing columns to existing table (unified method)
     * 
     * Adds all necessary columns if they don't exist
     * Idempotent - safe to run multiple times
     * 
     * NO DEBUG OUTPUT - Silent activation to prevent "headers already sent" errors
     * 
     * @since 2.9.15 Unified column creation
     */
    private static function add_columns() {
        global $wpdb;
        $table_name = FFC_Utils::get_submissions_table();

        // ✅ Column definitions
        $columns = array(
            'magic_token' => array(
                'type' => 'VARCHAR(32) DEFAULT NULL',
                'after' => 'status',
                'index' => 'magic_token'
            ),
            'cpf_rf' => array(
                'type' => 'VARCHAR(20) DEFAULT NULL',
                'after' => 'magic_token',
                'index' => 'cpf_rf'
            ),
            'auth_code' => array(
                'type' => 'VARCHAR(20) DEFAULT NULL',
                'after' => 'cpf_rf',
                'index' => 'auth_code'
            )
        );

        foreach ( $columns as $column_name => $config ) {
            // Check if column exists
            $exists = $wpdb->get_results( $wpdb->prepare(
                "SHOW COLUMNS FROM {$table_name} LIKE %s",
                $column_name
            ) );

            if ( ! empty( $exists ) ) {
                continue; // Column exists, skip
            }

            // Add column
            $after = isset( $config['after'] ) ? "AFTER {$config['after']}" : '';
            $wpdb->query( "ALTER TABLE {$table_name} ADD COLUMN {$column_name} {$config['type']} {$after}" );

            // Add index if specified
            if ( isset( $config['index'] ) ) {
                $index_name = "idx_{$config['index']}";
                $wpdb->query( "ALTER TABLE {$table_name} ADD INDEX {$index_name} ({$column_name})" );
            }
            
            // ✅ REMOVED debug_log - causes "unexpected output" errors during activation
        }

        // Add composite index for form_id + cpf_rf (if not exists)
        $composite_index = $wpdb->get_results(
            "SHOW INDEX FROM {$table_name} WHERE Key_name = 'idx_form_cpf'"
        );

        if ( empty( $composite_index ) ) {
            $wpdb->query( "ALTER TABLE {$table_name} ADD INDEX idx_form_cpf (form_id, cpf_rf)" );
        }
    }

    /**
     * ✅ Create verification page for magic links
     * 
     * Creates a page at /valid with the verification shortcode.
     * This page is used for:
     * - Magic link certificate downloads
     * - Manual verification
     * - Admin PDF downloads
     * 
     * Features:
     * - Checks if page already exists (by slug)
     * - Prevents duplicate creation
     * - Sets proper page attributes
     * - Stores page ID for future reference
     * 
     * @since 2.9.16
     */
    private static function create_verification_page() {
        // Check if page already exists by slug
        $existing_page = get_page_by_path( 'valid' );
        
        if ( $existing_page ) {
            // Page exists, store ID and return
            update_option( 'ffc_verification_page_id', $existing_page->ID );
            return;
        }

        // Create verification page
        $page_data = array(
            'post_title'     => __( 'Certificate Verification', 'ffc' ),
            'post_content'   => '[ffc_verification]',
            'post_status'    => 'publish',
            'post_type'      => 'page',
            'post_name'      => 'valid',
            'post_author'    => 1,
            'comment_status' => 'closed',
            'ping_status'    => 'closed'
        );

        $page_id = wp_insert_post( $page_data );

        if ( $page_id && ! is_wp_error( $page_id ) ) {
            // Store page ID for future reference
            update_option( 'ffc_verification_page_id', $page_id );
            
            // Mark page as plugin-managed (helps prevent accidental deletion)
            update_post_meta( $page_id, '_ffc_managed_page', '1' );
        }
    }

    /**
     * ✅ Run data migrations using Migration Manager
     * 
     * Instead of duplicating migration logic here, we use the centralized
     * Migration Manager which has all migrations properly implemented.
     * 
     * This method:
     * 1. Loads Migration Manager
     * 2. Runs each available migration once
     * 3. Marks migrations as completed
     * 
     * NO DEBUG OUTPUT - Silent to prevent "headers already sent" errors
     * 
     * @since 2.9.15 Uses Migration Manager
     */
    private static function run_migrations() {
        // Load Migration Manager
        if ( ! class_exists( 'FFC_Migration_Manager' ) ) {
            $migration_file = dirname( __FILE__ ) . '/class-ffc-migration-manager.php';
            if ( file_exists( $migration_file ) ) {
                require_once $migration_file;
            } else {
                // ✅ Silent failure - no output during activation
                return;
            }
        }

        $migration_manager = new FFC_Migration_Manager();
        $migrations = $migration_manager->get_migrations();

        // ✅ Safety check: Ensure migrations is an array
        if ( ! is_array( $migrations ) || empty( $migrations ) ) {
            // No migrations available, exit silently
            return;
        }

        // ✅ Run each migration that hasn't been completed
        foreach ( $migrations as $key => $migration ) {
            // Check if migration is available (column exists)
            if ( ! $migration_manager->can_run_migration( $key ) ) {
                continue;
            }

            // Check if already completed (via option flag)
            $option_key = "ffc_migration_{$key}_completed";
            if ( get_option( $option_key, false ) ) {
                continue; // Already done
            }

            // Run migration (batch 0 = process all)
            $result = $migration_manager->run_migration( $key, 0 );

            if ( is_wp_error( $result ) ) {
                // ✅ Silent error - no output during activation
                continue;
            }

            // Mark as completed if no more records to process
            if ( isset( $result['has_more'] ) && ! $result['has_more'] ) {
                update_option( $option_key, true );
            }
        }
        
        // ✅ No debug output - activation must be silent
    }

    /**
     * ✅ DEPRECATED METHODS
     * 
     * These methods are kept for backward compatibility but are no longer used.
     * They redirect to the new unified methods or Migration Manager.
     */

    /**
     * @deprecated 2.9.15 Use add_columns() instead
     */
    private static function add_magic_token_column() {
        self::add_columns();
    }

    /**
     * @deprecated 2.9.15 Use add_columns() instead
     */
    private static function add_cpf_rf_column() {
        self::add_columns();
    }

    /**
     * @deprecated 2.9.15 Use add_columns() instead
     */
    private static function add_auth_code_column() {
        self::add_columns();
    }

    /**
     * @deprecated 2.9.15 Use run_migrations() with Migration Manager
     */
    private static function migrate_cpf_rf_data() {
        self::run_migrations();
    }

    /**
     * @deprecated 2.9.15 Use run_migrations() with Migration Manager
     */
    private static function migrate_auth_code_data() {
        self::run_migrations();
    }

    /**
     * @deprecated 2.9.15 Use run_migrations() with Migration Manager
     */
    private static function generate_missing_magic_tokens() {
        self::run_migrations();
    }
}