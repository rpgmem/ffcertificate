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

    public static function activate(): void {
        self::create_submissions_table();
        self::create_activity_log_table();
        self::add_columns();
        self::create_verification_page();

        // ✅ v2.10.0: Create Rate Limit tables
        if (class_exists('\FreeFormCertificate\Security\RateLimitActivator')) {
            \FreeFormCertificate\Security\RateLimitActivator::create_tables();
        }

        // ✅ v3.1.0: Register ffc_user role
        self::register_user_role();

        // ✅ v3.1.0: Create dashboard page
        self::create_dashboard_page();

        // ✅ v4.1.0: Create Calendar tables
        if (class_exists('\FreeFormCertificate\Calendars\CalendarActivator')) {
            \FreeFormCertificate\Calendars\CalendarActivator::create_tables();
        }

        self::run_migrations();
        flush_rewrite_rules();
    }

    private static function create_submissions_table(): void {
        global $wpdb;
        $table_name = \FreeFormCertificate\Core\Utils::get_submissions_table();
        $charset_collate = $wpdb->get_charset_collate();

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") == $table_name) {
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
            'user_ip_encrypted' => array('type' => 'TEXT NULL DEFAULT NULL', 'after' => 'cpf_rf_hash'),
            'data_encrypted' => array('type' => 'LONGTEXT NULL DEFAULT NULL', 'after' => 'user_ip_encrypted'),
            'consent_given' => array('type' => 'TINYINT(1) DEFAULT 0', 'after' => 'data_encrypted'),
            'consent_date' => array('type' => 'DATETIME DEFAULT NULL', 'after' => 'consent_given'),
            'consent_ip' => array('type' => 'VARCHAR(45) DEFAULT NULL', 'after' => 'consent_date'),
            'consent_text' => array('type' => 'TEXT DEFAULT NULL', 'after' => 'consent_ip'),
            'qr_code_cache' => array('type' => 'LONGTEXT DEFAULT NULL', 'after' => 'consent_text'),
            'edited_at' => array('type' => 'DATETIME NULL DEFAULT NULL', 'after' => 'qr_code_cache'),
            'edited_by' => array('type' => 'BIGINT(20) UNSIGNED NULL DEFAULT NULL', 'after' => 'edited_at')
        );

        foreach ($columns as $column_name => $config) {
            $exists = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM {$table_name} LIKE %s", $column_name));
            if (!empty($exists)) continue;

            $after = isset($config['after']) ? "AFTER {$config['after']}" : '';
            $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN {$column_name} {$config['type']} {$after}");

            if (isset($config['index'])) {
                $index_exists = $wpdb->get_results("SHOW INDEX FROM {$table_name} WHERE Key_name = 'idx_{$config['index']}'");
                if (empty($index_exists)) {
                    $wpdb->query("ALTER TABLE {$table_name} ADD INDEX idx_{$config['index']} ({$column_name})");
                }
            }
        }

        $composite_index = $wpdb->get_results("SHOW INDEX FROM {$table_name} WHERE Key_name = 'idx_form_cpf'");
        if (empty($composite_index)) {
            $wpdb->query("ALTER TABLE {$table_name} ADD INDEX idx_form_cpf (form_id, cpf_rf)");
        }
    }

    private static function create_activity_log_table(): void {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_activity_log';
        $charset_collate = $wpdb->get_charset_collate();

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") == $table_name) {
            return;
        }

        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            submission_id bigint(20) unsigned NOT NULL,
            action_type varchar(50) NOT NULL,
            action_details longtext DEFAULT NULL,
            user_id bigint(20) unsigned DEFAULT NULL,
            user_ip varchar(45) DEFAULT NULL,
            user_agent varchar(255) DEFAULT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY submission_id (submission_id),
            KEY action_type (action_type),
            KEY created_at (created_at),
            KEY user_id (user_id)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    private static function create_verification_page(): void {
        $existing_page = get_page_by_path('valid');

        if ($existing_page) {
            update_option('ffc_verification_page_id', $existing_page->ID);
            return;
        }

        $page_data = array(
            'post_title'     => __('Certificate Verification', 'ffc'),
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
            'post_title'     => __('My Dashboard', 'ffc'),
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
