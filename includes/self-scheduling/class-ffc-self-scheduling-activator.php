<?php
declare(strict_types=1);

/**
 * Self-Scheduling Activator
 *
 * Creates database tables for self-scheduling (user books for themselves) system.
 * Independent from form submissions system as per requirements.
 *
 * Tables created:
 * - wp_ffc_self_scheduling_calendars: Calendar definitions with slots and settings
 * - wp_ffc_self_scheduling_appointments: Individual appointments/bookings
 * - wp_ffc_self_scheduling_blocked_dates: Holidays and specific date blocks
 *
 * @since 4.1.0
 * @version 4.5.0 - Renamed from CalendarActivator to SelfSchedulingActivator
 */

namespace FreeFormCertificate\SelfScheduling;

if (!defined('ABSPATH')) exit;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange

class SelfSchedulingActivator {

    /**
     * Create all self-scheduling-related tables
     *
     * Called during plugin activation.
     *
     * @return void
     */
    public static function create_tables(): void {
        self::create_calendars_table();
        self::create_appointments_table();
        self::create_blocked_dates_table();
        self::add_composite_indexes();
        self::ensure_unique_validation_code_index();
    }

    /**
     * Create calendars table
     *
     * Stores calendar configurations with time slots and settings.
     *
     * @return void
     */
    private static function create_calendars_table(): void {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_self_scheduling_calendars';
        $charset_collate = $wpdb->get_charset_collate();

        // Check if table already exists
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        if ($wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) == $table_name) {
            return;
        }

        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            post_id bigint(20) unsigned NOT NULL COMMENT 'Reference to wp_posts (CPT)',
            title varchar(255) NOT NULL,
            description text DEFAULT NULL,

            -- Time slot configuration
            slot_duration int unsigned DEFAULT 30 COMMENT 'Duration in minutes',
            slot_interval int unsigned DEFAULT 0 COMMENT 'Break between slots in minutes',
            slots_per_day int unsigned DEFAULT 0 COMMENT '0 = unlimited',

            -- Working hours (JSON: [{day: 0-6, start: '09:00', end: '17:00'}])
            working_hours longtext DEFAULT NULL,

            -- Booking window
            advance_booking_min int unsigned DEFAULT 0 COMMENT 'Minimum hours in advance',
            advance_booking_max int unsigned DEFAULT 30 COMMENT 'Maximum days in advance',

            -- Cancellation policy
            allow_cancellation tinyint(1) DEFAULT 1,
            cancellation_min_hours int unsigned DEFAULT 24 COMMENT 'Minimum hours before appointment',

            -- Minimum interval between bookings
            minimum_interval_between_bookings int unsigned DEFAULT 24 COMMENT 'Minimum hours between user bookings (0 = disabled)',

            -- Approval workflow
            requires_approval tinyint(1) DEFAULT 0,

            -- Capacity
            max_appointments_per_slot int unsigned DEFAULT 1,

            -- Visibility & access control
            visibility enum('public','private') DEFAULT 'public' COMMENT 'Calendar visibility: public or private',
            scheduling_visibility enum('public','private') DEFAULT 'public' COMMENT 'Booking access: public or private',

            -- Email notifications (JSON config)
            email_config longtext DEFAULT NULL,

            -- Status
            status varchar(20) DEFAULT 'active' COMMENT 'active, inactive, archived',

            -- Metadata
            created_at datetime NOT NULL,
            created_by bigint(20) unsigned DEFAULT NULL,
            updated_at datetime DEFAULT NULL,
            updated_by bigint(20) unsigned DEFAULT NULL,

            PRIMARY KEY (id),
            KEY post_id (post_id),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
        dbDelta($sql);
    }

    /**
     * Create appointments table
     *
     * Stores individual appointment bookings.
     *
     * @return void
     */
    private static function create_appointments_table(): void {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_self_scheduling_appointments';
        $charset_collate = $wpdb->get_charset_collate();

        // Check if table already exists
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) == $table_name;

        if ($table_exists) {
            // Run migration to add cpf_rf columns if they don't exist
            self::migrate_appointments_table();
            return;
        }

        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            calendar_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned DEFAULT NULL COMMENT 'WordPress user (if logged in)',

            -- Appointment details
            appointment_date date NOT NULL,
            start_time time NOT NULL,
            end_time time NOT NULL,

            -- Contact information (for non-logged users or additional info)
            name varchar(255) DEFAULT NULL,
            email varchar(255) DEFAULT NULL,
            email_encrypted text DEFAULT NULL,
            email_hash varchar(64) DEFAULT NULL,
            cpf_rf varchar(20) DEFAULT NULL,
            cpf_rf_encrypted text DEFAULT NULL,
            cpf_rf_hash varchar(64) DEFAULT NULL,
            phone varchar(50) DEFAULT NULL,
            phone_encrypted text DEFAULT NULL,

            -- Additional data (JSON: custom fields)
            custom_data longtext DEFAULT NULL,
            custom_data_encrypted longtext DEFAULT NULL,

            -- Notes
            user_notes text DEFAULT NULL,
            admin_notes text DEFAULT NULL,

            -- Status workflow
            status varchar(20) DEFAULT 'pending' COMMENT 'pending, confirmed, cancelled, completed, no_show',

            -- Approval (if calendar requires approval)
            approved_at datetime DEFAULT NULL,
            approved_by bigint(20) unsigned DEFAULT NULL,

            -- Cancellation tracking
            cancelled_at datetime DEFAULT NULL,
            cancelled_by bigint(20) unsigned DEFAULT NULL,
            cancellation_reason text DEFAULT NULL,

            -- Verification token for guest users
            confirmation_token varchar(64) DEFAULT NULL,

            -- Validation code (user-friendly code for verification, like certificates)
            validation_code varchar(20) DEFAULT NULL,

            -- LGPD Consent
            consent_given tinyint(1) DEFAULT 0,
            consent_date datetime DEFAULT NULL,
            consent_ip varchar(45) DEFAULT NULL,
            consent_text text DEFAULT NULL,

            -- Metadata
            user_ip varchar(45) DEFAULT NULL,
            user_ip_encrypted text DEFAULT NULL,
            user_agent varchar(255) DEFAULT NULL,

            created_at datetime NOT NULL,
            updated_at datetime DEFAULT NULL,

            -- Reminder sent tracking
            reminder_sent_at datetime DEFAULT NULL,

            PRIMARY KEY (id),
            KEY calendar_id (calendar_id),
            KEY user_id (user_id),
            KEY appointment_date (appointment_date),
            KEY status (status),
            KEY email (email),
            KEY email_hash (email_hash),
            KEY cpf_rf_hash (cpf_rf_hash),
            KEY confirmation_token (confirmation_token),
            KEY validation_code (validation_code),
            KEY idx_calendar_date (calendar_id, appointment_date),
            KEY idx_calendar_datetime (calendar_id, appointment_date, start_time)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
        dbDelta($sql);
    }

    /**
     * Migrate appointments table to add cpf_rf columns
     *
     * @return void
     */
    private static function migrate_appointments_table(): void {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_self_scheduling_appointments';

        // Check if cpf_rf column exists
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $column_exists = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'cpf_rf'",
                DB_NAME,
                $table_name
            )
        );

        if (empty($column_exists)) {
            // Check if email_hash column exists to determine where to add new columns
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $email_hash_exists = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                    WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'email_hash'",
                    DB_NAME,
                    $table_name
                )
            );

            if (!empty($email_hash_exists)) {
                // Add cpf_rf columns after email_hash
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
                $wpdb->query(
                    "ALTER TABLE {$table_name}
                    ADD COLUMN cpf_rf varchar(20) DEFAULT NULL AFTER email_hash,
                    ADD COLUMN cpf_rf_encrypted text DEFAULT NULL AFTER cpf_rf,
                    ADD COLUMN cpf_rf_hash varchar(64) DEFAULT NULL AFTER cpf_rf_encrypted"
                );

                // Add index separately to avoid errors if it already exists
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
                $index_exists = $wpdb->get_results(
                    "SHOW INDEX FROM {$table_name} WHERE Key_name = 'cpf_rf_hash'"
                );
                if (empty($index_exists)) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
                    $wpdb->query("ALTER TABLE {$table_name} ADD INDEX cpf_rf_hash (cpf_rf_hash)");
                }
            } else {
                // Fallback: add after email column
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
                $wpdb->query(
                    "ALTER TABLE {$table_name}
                    ADD COLUMN cpf_rf varchar(20) DEFAULT NULL AFTER email,
                    ADD COLUMN cpf_rf_encrypted text DEFAULT NULL AFTER cpf_rf,
                    ADD COLUMN cpf_rf_hash varchar(64) DEFAULT NULL AFTER cpf_rf_encrypted"
                );

                // Add index
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
                $index_exists = $wpdb->get_results(
                    "SHOW INDEX FROM {$table_name} WHERE Key_name = 'cpf_rf_hash'"
                );
                if (empty($index_exists)) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
                    $wpdb->query("ALTER TABLE {$table_name} ADD INDEX cpf_rf_hash (cpf_rf_hash)");
                }
            }
        }
    }

    /**
     * Migrate appointments table to add validation_code column
     *
     * @return void
     */
    private static function migrate_appointments_validation_code(): void {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_self_scheduling_appointments';

        // Check if validation_code column exists
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $column_exists = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'validation_code'",
                DB_NAME,
                $table_name
            )
        );

        if (empty($column_exists)) {
            // Add validation_code column after confirmation_token
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->query(
                "ALTER TABLE {$table_name}
                ADD COLUMN validation_code varchar(20) DEFAULT NULL AFTER confirmation_token"
            );

            // Add index
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $index_exists = $wpdb->get_results(
                "SHOW INDEX FROM {$table_name} WHERE Key_name = 'validation_code'"
            );
            if (empty($index_exists)) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
                $wpdb->query("ALTER TABLE {$table_name} ADD INDEX validation_code (validation_code)");
            }

            // Generate validation codes for existing appointments
            self::generate_validation_codes_for_existing_appointments();
        }
    }

    /**
     * Generate validation codes for existing appointments that don't have one
     *
     * @return void
     */
    private static function generate_validation_codes_for_existing_appointments(): void {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_self_scheduling_appointments';

        // Get appointments without validation codes
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $appointments = $wpdb->get_results(
            "SELECT id FROM {$table_name} WHERE validation_code IS NULL OR validation_code = ''"
        );

        foreach ($appointments as $appointment) {
            // Generate unique validation code
            $validation_code = self::generate_unique_validation_code();

            // Update appointment
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->update(
                $table_name,
                array('validation_code' => $validation_code),
                array('id' => $appointment->id),
                array('%s'),
                array('%d')
            );
        }
    }

    /**
     * Generate unique validation code
     *
     * Generates a 12-character alphanumeric code (stored without hyphens).
     * Use Utils::format_auth_code() to display with hyphens (XXXX-XXXX-XXXX).
     *
     * @return string 12-character code without hyphens
     */
    private static function generate_unique_validation_code(): string {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_self_scheduling_appointments';

        do {
            // Generate 12 alphanumeric characters (stored clean, without hyphens)
            $code = \FreeFormCertificate\Core\Utils::generate_random_string(12);

            // Check if code already exists
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $existing = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$table_name} WHERE validation_code = %s",
                    $code
                )
            );
        } while ($existing);

        return $code;
    }

    /**
     * Encrypt all unencrypted appointment data
     *
     * @return void
     */
    private static function migrate_encrypt_appointments_data(): void {
        global $wpdb;

        // Check if encryption is configured
        if (!class_exists('\FreeFormCertificate\Core\Encryption') ||
            !\FreeFormCertificate\Core\Encryption::is_configured()) {
            return;
        }

        $table_name = $wpdb->prefix . 'ffc_self_scheduling_appointments';

        // Get appointments with unencrypted data
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $appointments = $wpdb->get_results(
            "SELECT id, email, cpf_rf, phone, user_ip
             FROM {$table_name}
             WHERE (email IS NOT NULL AND email != '' AND (email_encrypted IS NULL OR email_encrypted = ''))
                OR (cpf_rf IS NOT NULL AND cpf_rf != '' AND (cpf_rf_encrypted IS NULL OR cpf_rf_encrypted = ''))
                OR (phone IS NOT NULL AND phone != '' AND (phone_encrypted IS NULL OR phone_encrypted = ''))
                OR (user_ip IS NOT NULL AND user_ip != '' AND (user_ip_encrypted IS NULL OR user_ip_encrypted = ''))",
            ARRAY_A
        );

        foreach ($appointments as $appointment) {
            $update_data = array();
            $update_format = array();

            // Encrypt email
            if (!empty($appointment['email'])) {
                $encrypted = \FreeFormCertificate\Core\Encryption::encrypt($appointment['email']);
                if ($encrypted) {
                    $update_data['email_encrypted'] = $encrypted;
                    $update_data['email_hash'] = hash('sha256', strtolower(trim($appointment['email'])));
                    // Clear plain text
                    $update_data['email'] = null;
                    $update_format[] = '%s';
                    $update_format[] = '%s';
                    $update_format[] = '%s';
                }
            }

            // Encrypt CPF/RF
            if (!empty($appointment['cpf_rf'])) {
                $encrypted = \FreeFormCertificate\Core\Encryption::encrypt($appointment['cpf_rf']);
                if ($encrypted) {
                    $update_data['cpf_rf_encrypted'] = $encrypted;
                    $update_data['cpf_rf_hash'] = hash('sha256', preg_replace('/[^0-9]/', '', $appointment['cpf_rf']));
                    // Clear plain text
                    $update_data['cpf_rf'] = null;
                    $update_format[] = '%s';
                    $update_format[] = '%s';
                    $update_format[] = '%s';
                }
            }

            // Encrypt phone
            if (!empty($appointment['phone'])) {
                $encrypted = \FreeFormCertificate\Core\Encryption::encrypt($appointment['phone']);
                if ($encrypted) {
                    $update_data['phone_encrypted'] = $encrypted;
                    // Clear plain text
                    $update_data['phone'] = null;
                    $update_format[] = '%s';
                    $update_format[] = '%s';
                }
            }

            // Encrypt user IP
            if (!empty($appointment['user_ip'])) {
                $encrypted = \FreeFormCertificate\Core\Encryption::encrypt($appointment['user_ip']);
                if ($encrypted) {
                    $update_data['user_ip_encrypted'] = $encrypted;
                    // Clear plain text
                    $update_data['user_ip'] = null;
                    $update_format[] = '%s';
                    $update_format[] = '%s';
                }
            }

            // Update appointment if we have data to encrypt
            if (!empty($update_data)) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
                $wpdb->update(
                    $table_name,
                    $update_data,
                    array('id' => $appointment['id']),
                    $update_format,
                    array('%d')
                );
            }
        }
    }

    /**
     * Run migrations on plugin load
     * This ensures migrations run even if plugin wasn't re-activated
     *
     * @return void
     */
    public static function maybe_migrate(): void {
        global $wpdb;

        // Migrate appointments table
        $appointments_table = $wpdb->prefix . 'ffc_self_scheduling_appointments';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $appointments_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $appointments_table ) ) == $appointments_table;

        if ($appointments_exists) {
            // Run migration to ensure cpf_rf columns exist
            self::migrate_appointments_table();
            // Run migration to ensure validation_code column exists
            self::migrate_appointments_validation_code();
            // Run migration to encrypt all unencrypted data
            self::migrate_encrypt_appointments_data();
        }

        // Migrate calendars table
        $calendars_table = $wpdb->prefix . 'ffc_self_scheduling_calendars';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $calendars_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $calendars_table ) ) == $calendars_table;

        if ($calendars_exists) {
            // Run migration to ensure minimum_interval_between_bookings column exists
            self::migrate_calendars_table();
            // Run migration to add visibility columns (replacing require_login/allowed_roles)
            self::migrate_visibility_columns();
            // Run migration to add business hours restriction columns
            self::migrate_business_hours_restriction_columns();
        }
    }

    /**
     * Migrate calendars table to add minimum_interval_between_bookings column
     *
     * @return void
     */
    private static function migrate_calendars_table(): void {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_self_scheduling_calendars';

        // Check if minimum_interval_between_bookings column exists
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $column_exists = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'minimum_interval_between_bookings'",
                DB_NAME,
                $table_name
            )
        );

        if (empty($column_exists)) {
            // Add minimum_interval_between_bookings column after cancellation_min_hours
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->query(
                "ALTER TABLE {$table_name}
                ADD COLUMN minimum_interval_between_bookings int unsigned DEFAULT 24 COMMENT 'Minimum hours between user bookings (0 = disabled)' AFTER cancellation_min_hours"
            );
        }
    }

    /**
     * Migrate calendars table to add visibility columns
     *
     * Replaces require_login and allowed_roles with visibility and scheduling_visibility.
     * Migration: require_login=1 → private/private, require_login=0 → public/public.
     *
     * @since 4.7.0
     * @return void
     */
    private static function migrate_visibility_columns(): void {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_self_scheduling_calendars';

        // Check if visibility column already exists
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $column_exists = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'visibility'",
                DB_NAME,
                $table_name
            )
        );

        if (!empty($column_exists)) {
            return;
        }

        // Check if require_login column exists (old schema)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $require_login_exists = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'require_login'",
                DB_NAME,
                $table_name
            )
        );

        // Add new columns
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->query(
            "ALTER TABLE {$table_name}
            ADD COLUMN visibility enum('public','private') DEFAULT 'public' COMMENT 'Calendar visibility: public or private' AFTER max_appointments_per_slot,
            ADD COLUMN scheduling_visibility enum('public','private') DEFAULT 'public' COMMENT 'Booking access: public or private' AFTER visibility"
        );

        // Migrate data from require_login if the old column exists
        if (!empty($require_login_exists)) {
            // require_login=1 → private/private
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->query(
                "UPDATE {$table_name}
                SET visibility = 'private', scheduling_visibility = 'private'
                WHERE require_login = 1"
            );

            // require_login=0 → public/public (already default, but be explicit)
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->query(
                "UPDATE {$table_name}
                SET visibility = 'public', scheduling_visibility = 'public'
                WHERE require_login = 0"
            );

            // Drop old columns
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->query(
                "ALTER TABLE {$table_name}
                DROP COLUMN require_login,
                DROP COLUMN allowed_roles"
            );
        }
    }

    /**
     * Migrate calendars table to add business hours restriction columns
     *
     * Adds restrict_viewing_to_hours and restrict_booking_to_hours toggles
     * that allow restricting calendar access to configured working hours only.
     *
     * @since 4.7.0
     * @return void
     */
    private static function migrate_business_hours_restriction_columns(): void {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_self_scheduling_calendars';

        // Check if restrict_viewing_to_hours column already exists
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $column_exists = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'restrict_viewing_to_hours'",
                DB_NAME,
                $table_name
            )
        );

        if (empty($column_exists)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->query(
                "ALTER TABLE {$table_name}
                ADD COLUMN restrict_viewing_to_hours tinyint(1) DEFAULT 0 COMMENT 'Restrict viewing to working hours only' AFTER scheduling_visibility,
                ADD COLUMN restrict_booking_to_hours tinyint(1) DEFAULT 0 COMMENT 'Restrict booking to working hours only' AFTER restrict_viewing_to_hours"
            );
        }
    }

    /**
     * Create blocked dates table
     *
     * Stores holidays and specific date/time blocks per calendar.
     *
     * @return void
     */
    private static function create_blocked_dates_table(): void {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_self_scheduling_blocked_dates';
        $charset_collate = $wpdb->get_charset_collate();

        // Check if table already exists
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        if ($wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) == $table_name) {
            return;
        }

        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            calendar_id bigint(20) unsigned DEFAULT NULL COMMENT 'NULL = applies to all calendars',

            -- Block type
            block_type varchar(20) DEFAULT 'full_day' COMMENT 'full_day, time_range, recurring',

            -- Date range
            start_date date NOT NULL,
            end_date date DEFAULT NULL COMMENT 'For multi-day blocks',

            -- Time range (for partial day blocks)
            start_time time DEFAULT NULL,
            end_time time DEFAULT NULL,

            -- Recurring pattern (JSON: {type: 'weekly', days: [0,6], etc})
            recurring_pattern longtext DEFAULT NULL,

            -- Description
            reason varchar(255) DEFAULT NULL COMMENT 'Holiday, maintenance, etc',

            -- Metadata
            created_at datetime NOT NULL,
            created_by bigint(20) unsigned DEFAULT NULL,

            PRIMARY KEY (id),
            KEY calendar_id (calendar_id),
            KEY start_date (start_date),
            KEY block_type (block_type),
            KEY idx_calendar_daterange (calendar_id, start_date, end_date)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
        dbDelta($sql);
    }

    /**
     * Drop all self-scheduling tables (for uninstall)
     *
     * @return void
     */
    public static function drop_tables(): void {
        global $wpdb;

        $tables = array(
            $wpdb->prefix . 'ffc_self_scheduling_calendars',
            $wpdb->prefix . 'ffc_self_scheduling_appointments',
            $wpdb->prefix . 'ffc_self_scheduling_blocked_dates'
        );

        foreach ($tables as $table) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->query("DROP TABLE IF EXISTS {$table}");
        }
    }

    /**
     * Add composite indexes for common query patterns.
     *
     * @since 4.6.2
     */
    private static function add_composite_indexes(): void {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_self_scheduling_appointments';

        $indexes = [
            'idx_calendar_status_date' => '(calendar_id, status, appointment_date)',
            'idx_user_status'          => '(user_id, status)',
        ];

        foreach ( $indexes as $index_name => $columns ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $exists = $wpdb->get_results( "SHOW INDEX FROM {$table_name} WHERE Key_name = '{$index_name}'" );
            if ( empty( $exists ) ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
                $wpdb->query( "ALTER TABLE {$table_name} ADD INDEX {$index_name} {$columns}" );
            }
        }
    }

    /**
     * Ensure validation_code has a UNIQUE index (prevents race condition duplicates).
     *
     * Upgrades the existing non-unique KEY to UNIQUE KEY.
     *
     * @since 4.6.10
     */
    private static function ensure_unique_validation_code_index(): void {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_self_scheduling_appointments';

        // Check if validation_code index exists and whether it's already unique
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $indexes = $wpdb->get_results( "SHOW INDEX FROM {$table_name} WHERE Key_name = 'validation_code'" );

        if ( ! empty( $indexes ) ) {
            // Check if Non_unique = 0 (already unique)
            if ( (int) $indexes[0]->Non_unique === 0 ) {
                return; // Already unique
            }

            // Drop the non-unique index first
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->query( "ALTER TABLE {$table_name} DROP INDEX validation_code" );
        }

        // Add UNIQUE index
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->query( "ALTER TABLE {$table_name} ADD UNIQUE KEY validation_code (validation_code)" );
    }
}
