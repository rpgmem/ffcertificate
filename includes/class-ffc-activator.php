<?php
declare(strict_types=1);

/**
 * Activator v3.0.1
 * Added: edited_at and edited_by columns
 *
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace (Phase 2)
 */

namespace FreeFormCertificate;

if (!defined('ABSPATH')) exit;

class Activator {

    use \FreeFormCertificate\Core\DatabaseHelperTrait;

    public static function activate(): void {
        self::create_submissions_table();
        self::create_activity_log_table();
        self::add_columns();
        self::create_verification_page();

        if (class_exists('\FreeFormCertificate\Security\RateLimitActivator')) {
            \FreeFormCertificate\Security\RateLimitActivator::create_tables();
        }

        self::register_user_role();
        self::create_dashboard_page();
        self::create_user_profiles_table();
        self::create_custom_fields_table();
        self::create_reregistrations_table();
        self::create_reregistration_submissions_table();
        self::add_reregistration_submissions_columns();
        self::upgrade_auth_code_unique_constraints();

        if (class_exists('\FreeFormCertificate\Migrations\MigrationSelfSchedulingTables')) {
            \FreeFormCertificate\Migrations\MigrationSelfSchedulingTables::run();
        }

        if (class_exists('\FreeFormCertificate\Migrations\MigrationRenameCapabilities')) {
            \FreeFormCertificate\Migrations\MigrationRenameCapabilities::run();
        }

        if (class_exists('\FreeFormCertificate\Migrations\MigrationCustomFieldsTables')) {
            \FreeFormCertificate\Migrations\MigrationCustomFieldsTables::run();
        }

        if (class_exists('\FreeFormCertificate\SelfScheduling\SelfSchedulingActivator')) {
            \FreeFormCertificate\SelfScheduling\SelfSchedulingActivator::create_tables();
        }

        if (class_exists('\FreeFormCertificate\Audience\AudienceActivator')) {
            \FreeFormCertificate\Audience\AudienceActivator::create_tables();
        }

        self::add_composite_indexes();
        self::add_foreign_keys();
        self::run_migrations();

        // Clean up legacy cron hooks from pre-4.6.15 versions
        wp_clear_scheduled_hook( 'ffc_daily_cleanup_hook' );
        wp_clear_scheduled_hook( 'ffc_process_submission_hook' );
        wp_clear_scheduled_hook( 'ffc_warm_cache_hook' );

        // Schedule daily cleanup cron
        if ( ! wp_next_scheduled( 'ffcertificate_daily_cleanup_hook' ) ) {
            wp_schedule_event( time(), 'daily', 'ffcertificate_daily_cleanup_hook' );
        }

        flush_rewrite_rules();
    }

    private static function create_submissions_table(): void {
        global $wpdb;
        $table_name = \FreeFormCertificate\Core\Utils::get_submissions_table();
        $charset_collate = $wpdb->get_charset_collate();

        if (self::table_exists($table_name)) {
            return;
        }

        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            form_id bigint(20) unsigned NOT NULL,
            submission_date datetime NOT NULL,
            data longtext NULL,
            user_ip varchar(100) NULL,
            email varchar(255) NULL,
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
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
        dbDelta($sql);
    }

    private static function add_columns(): void {
        global $wpdb;
        $table_name = \FreeFormCertificate\Core\Utils::get_submissions_table();

        $columns = array(
            'user_id' => array('type' => 'BIGINT(20) UNSIGNED DEFAULT NULL', 'after' => 'form_id', 'index' => 'user_id'),
            'magic_token' => array('type' => 'VARCHAR(32) DEFAULT NULL', 'after' => 'status', 'index' => 'magic_token'),
            'cpf_rf' => array('type' => 'VARCHAR(20) DEFAULT NULL', 'after' => 'magic_token', 'index' => 'cpf_rf'),
            'auth_code' => array('type' => 'VARCHAR(20) DEFAULT NULL', 'after' => 'cpf_rf', 'index' => 'auth_code'),
            'email_encrypted' => array('type' => 'TEXT NULL DEFAULT NULL', 'after' => 'auth_code'),
            'email_hash' => array('type' => 'VARCHAR(64) NULL DEFAULT NULL', 'after' => 'email_encrypted', 'index' => 'email_hash'),
            'cpf_rf_encrypted' => array('type' => 'TEXT NULL DEFAULT NULL', 'after' => 'email_hash'),
            'cpf_rf_hash' => array('type' => 'VARCHAR(64) NULL DEFAULT NULL', 'after' => 'cpf_rf_encrypted', 'index' => 'cpf_rf_hash'),
            'ticket_hash' => array('type' => 'VARCHAR(64) NULL DEFAULT NULL', 'after' => 'cpf_rf_hash', 'index' => 'ticket_hash'),
            'user_ip_encrypted' => array('type' => 'TEXT NULL DEFAULT NULL', 'after' => 'ticket_hash'),
            'data_encrypted' => array('type' => 'LONGTEXT NULL DEFAULT NULL', 'after' => 'user_ip_encrypted'),
            'consent_given' => array('type' => 'TINYINT(1) DEFAULT 0', 'after' => 'data_encrypted'),
            'consent_date' => array('type' => 'DATETIME DEFAULT NULL', 'after' => 'consent_given'),
            'consent_ip' => array('type' => 'VARCHAR(45) DEFAULT NULL', 'after' => 'consent_date'),
            'consent_text' => array('type' => 'TEXT DEFAULT NULL', 'after' => 'consent_ip'),
            'qr_code_cache' => array('type' => 'LONGTEXT DEFAULT NULL', 'after' => 'consent_text'),
            'edited_at' => array('type' => 'DATETIME NULL DEFAULT NULL', 'after' => 'qr_code_cache'),
            'edited_by' => array('type' => 'BIGINT(20) UNSIGNED NULL DEFAULT NULL', 'after' => 'edited_at')
        );

        self::add_columns_if_missing($table_name, $columns);
        self::add_index_if_missing($table_name, 'idx_form_cpf', '(form_id, cpf_rf)');
    }

    /**
     * Add composite indexes for common query patterns.
     *
     * @since 4.6.2
     */
    private static function add_composite_indexes(): void {
        $table_name = \FreeFormCertificate\Core\Utils::get_submissions_table();

        self::add_indexes_if_missing($table_name, [
            'idx_form_status'           => '(form_id, status)',
            'idx_status_submission_date' => '(status, submission_date)',
            'idx_email_hash_form_id'    => '(email_hash, form_id)',
            'idx_form_ticket_hash'      => '(form_id, ticket_hash)',
        ]);
    }

    /**
     * Add FOREIGN KEY constraints for referential integrity
     *
     * @since 4.9.7
     */
    private static function add_foreign_keys(): void {
        if (class_exists('\FreeFormCertificate\Migrations\MigrationForeignKeys')) {
            \FreeFormCertificate\Migrations\MigrationForeignKeys::run();
        }
    }

    private static function create_activity_log_table(): void {
        // Delegate to ActivityLog::create_table() to avoid schema mismatch (v4.6.9)
        if ( class_exists( '\FreeFormCertificate\Core\ActivityLog' ) ) {
            \FreeFormCertificate\Core\ActivityLog::create_table();
        }
    }

    private static function create_verification_page(): void {
        $existing_page = get_page_by_path('valid');

        if ($existing_page) {
            update_option('ffc_verification_page_id', $existing_page->ID);
            return;
        }

        $page_data = array(
            'post_title'     => 'Certificate Verification',
            'post_content'   => '[ffc_verification]',
            'post_status'    => 'publish',
            'post_type'      => 'page',
            'post_name'      => 'valid',
            'post_author'    => 1,
            'comment_status' => 'closed',
            'ping_status'    => 'closed'
        );

        $page_id = wp_insert_post($page_data);

        if ($page_id && !is_wp_error($page_id)) {
            update_option('ffc_verification_page_id', $page_id);
            update_post_meta($page_id, '_ffc_managed_page', '1');
        }
    }

    private static function run_migrations(): void {
        if (!class_exists('\FreeFormCertificate\Migrations\MigrationManager')) {
            $migration_file = dirname(__FILE__) . '/class-ffc-migration-manager.php';
            if (file_exists($migration_file)) {
                require_once $migration_file;
            } else {
                return;
            }
        }

        $migration_manager = new \FreeFormCertificate\Migrations\MigrationManager();
        $migrations = $migration_manager->get_migrations();

        if (!is_array($migrations) || empty($migrations)) {
            return;
        }

        // Migrations that should NOT run automatically during activation
        // (they require existing data or should be run manually by admin)
        $skip_on_activation = array('user_link', 'cleanup_unencrypted', 'data_cleanup');

        foreach ($migrations as $key => $migration) {
            // Skip migrations that should be run manually
            if (in_array($key, $skip_on_activation)) {
                continue;
            }

            if (!$migration_manager->can_run_migration($key)) continue;

            $option_key = "ffc_migration_{$key}_completed";
            if (get_option($option_key, false)) continue;

            $result = $migration_manager->run_migration($key, 0);

            if (is_wp_error($result)) continue;

            if (isset($result['has_more']) && !$result['has_more']) {
                update_option($option_key, true);
            }
        }
    }

    /**
     * Register ffc_user role
     *
     * @since 3.1.0
     */
    private static function register_user_role(): void {
        // Load User Manager if not already loaded
        if (!class_exists('\FreeFormCertificate\UserDashboard\UserManager')) {
            $user_manager_file = FFC_PLUGIN_DIR . 'includes/user-dashboard/class-ffc-user-manager.php';
            if (file_exists($user_manager_file)) {
                require_once $user_manager_file;
            }
        }

        if (class_exists('\FreeFormCertificate\UserDashboard\UserManager')) {
            \FreeFormCertificate\UserDashboard\UserManager::register_role();

            // Grant admin-level FFC capabilities to the administrator role
            $admin_role = get_role('administrator');
            if ($admin_role) {
                foreach (\FreeFormCertificate\UserDashboard\UserManager::ADMIN_CAPABILITIES as $cap) {
                    $admin_role->add_cap($cap, true);
                }
            }
        }
    }

    /**
     * Create user profiles table
     *
     * @since 4.9.4
     */
    private static function create_user_profiles_table(): void {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_user_profiles';
        $charset_collate = $wpdb->get_charset_collate();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name)) == $table_name) {
            return;
        }

        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            display_name varchar(250) DEFAULT '',
            phone varchar(50) DEFAULT '',
            department varchar(250) DEFAULT '',
            organization varchar(250) DEFAULT '',
            notes text DEFAULT NULL,
            preferences json DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_user_id (user_id)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
        dbDelta($sql);
    }

    /**
     * Create custom fields table
     *
     * Stores field definitions for audience-specific custom fields.
     * Field data for each user is stored as JSON in wp_usermeta.
     *
     * @since 4.11.0
     */
    private static function create_custom_fields_table(): void {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_custom_fields';
        $charset_collate = $wpdb->get_charset_collate();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name)) == $table_name) {
            return;
        }

        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            audience_id bigint(20) unsigned NOT NULL,
            field_key varchar(100) NOT NULL,
            field_label varchar(250) NOT NULL,
            field_type varchar(50) NOT NULL DEFAULT 'text',
            field_options json DEFAULT NULL,
            validation_rules json DEFAULT NULL,
            sort_order int(11) NOT NULL DEFAULT 0,
            is_required tinyint(1) NOT NULL DEFAULT 0,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_audience_id (audience_id),
            KEY idx_field_key (field_key),
            KEY idx_sort_order (audience_id, sort_order)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
        dbDelta($sql);
    }

    /**
     * Create reregistrations table
     *
     * Stores reregistration campaigns linked to audiences.
     *
     * @since 4.11.0
     */
    private static function create_reregistrations_table(): void {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_reregistrations';
        $charset_collate = $wpdb->get_charset_collate();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name)) == $table_name) {
            return;
        }

        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            title varchar(250) NOT NULL,
            audience_id bigint(20) unsigned NOT NULL,
            start_date datetime NOT NULL,
            end_date datetime NOT NULL,
            auto_approve tinyint(1) NOT NULL DEFAULT 0,
            email_invitation_enabled tinyint(1) NOT NULL DEFAULT 0,
            email_reminder_enabled tinyint(1) NOT NULL DEFAULT 0,
            email_confirmation_enabled tinyint(1) NOT NULL DEFAULT 0,
            reminder_days int(11) NOT NULL DEFAULT 7,
            status varchar(20) NOT NULL DEFAULT 'draft',
            created_by bigint(20) unsigned NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_audience_id (audience_id),
            KEY idx_status (status),
            KEY idx_dates (start_date, end_date)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
        dbDelta($sql);
    }

    /**
     * Create reregistration submissions table
     *
     * Stores individual user responses to reregistration campaigns.
     *
     * @since 4.11.0
     */
    private static function create_reregistration_submissions_table(): void {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_reregistration_submissions';
        $charset_collate = $wpdb->get_charset_collate();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name)) == $table_name) {
            return;
        }

        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            reregistration_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            data json DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            submitted_at datetime DEFAULT NULL,
            reviewed_at datetime DEFAULT NULL,
            reviewed_by bigint(20) unsigned DEFAULT NULL,
            notes text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_reregistration_user (reregistration_id, user_id),
            KEY idx_user_id (user_id),
            KEY idx_status (status)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
        dbDelta($sql);
    }

    /**
     * Add auth_code column to reregistration submissions table for existing installs.
     *
     * @since 4.12.0
     */
    private static function add_reregistration_submissions_columns(): void {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_reregistration_submissions';

        if (!self::table_exists($table_name)) {
            return;
        }

        self::add_columns_if_missing($table_name, array(
            'auth_code' => array(
                'type'  => 'VARCHAR(20) DEFAULT NULL',
                'after' => 'status',
                'index' => 'auth_code',
            ),
            'magic_token' => array(
                'type'  => 'VARCHAR(64) DEFAULT NULL',
                'after' => 'auth_code',
                'index' => 'magic_token',
            ),
        ));
    }

    /**
     * Upgrade auth_code indexes to UNIQUE constraints across all tables.
     *
     * Prevents cross-table code collisions by ensuring each auth_code
     * is unique within its own table. Combined with the centralized
     * generate_globally_unique_auth_code() this guarantees global uniqueness.
     *
     * Safe to run multiple times (idempotent).
     *
     * @since 4.12.0
     */
    private static function upgrade_auth_code_unique_constraints(): void {
        global $wpdb;

        $tables = array(
            $wpdb->prefix . 'ffc_submissions'                    => 'auth_code',
            $wpdb->prefix . 'ffc_reregistration_submissions'     => 'auth_code',
        );

        foreach ( $tables as $table => $column ) {
            if ( ! self::table_exists( $table ) ) {
                continue;
            }

            // Check if a UNIQUE index already exists on this column
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $indexes = $wpdb->get_results( $wpdb->prepare( "SHOW INDEX FROM %i WHERE Column_name = %s", $table, $column ) );
            $has_unique = false;
            $old_index_names = array();

            foreach ( $indexes as $idx ) {
                if ( (int) $idx->Non_unique === 0 ) {
                    $has_unique = true;
                } else {
                    $old_index_names[] = $idx->Key_name;
                }
            }

            if ( $has_unique ) {
                continue;
            }

            // Remove duplicate auth_codes (keep the most recent) before adding constraint
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->query(
                "DELETE t1 FROM {$table} t1
                 INNER JOIN {$table} t2
                 WHERE t1.{$column} = t2.{$column}
                   AND t1.{$column} IS NOT NULL
                   AND t1.{$column} != ''
                   AND t1.id < t2.id"
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

            // Drop old non-unique indexes
            foreach ( array_unique( $old_index_names ) as $name ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
                $wpdb->query( "ALTER TABLE {$table} DROP INDEX {$name}" );
            }

            // Add UNIQUE constraint
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->query( "ALTER TABLE {$table} ADD UNIQUE INDEX uq_{$column} ({$column})" );
        }
    }

    /**
     * Create dashboard page
     *
     * @since 3.1.0
     */
    private static function create_dashboard_page(): void {
        $existing_page = get_page_by_path('dashboard');

        if ($existing_page) {
            update_option('ffc_dashboard_page_id', $existing_page->ID);
            return;
        }

        $page_data = array(
            'post_title'     => 'My Dashboard',
            'post_content'   => '[user_dashboard_personal]',
            'post_status'    => 'publish',
            'post_type'      => 'page',
            'post_name'      => 'dashboard',
            'post_author'    => 1,
            'comment_status' => 'closed',
            'ping_status'    => 'closed'
        );

        $page_id = wp_insert_post($page_data);

        if ($page_id && !is_wp_error($page_id)) {
            update_option('ffc_dashboard_page_id', $page_id);
            update_post_meta($page_id, '_ffc_managed_page', '1');
        }
    }
}
