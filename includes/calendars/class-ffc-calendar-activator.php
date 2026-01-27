<?php
declare(strict_types=1);

/**
 * Calendar Activator
 *
 * Creates database tables for calendar and appointment system.
 * Independent from form submissions system as per requirements.
 *
 * Tables created:
 * - wp_ffc_calendars: Calendar definitions with slots and settings
 * - wp_ffc_appointments: Individual appointments/bookings
 * - wp_ffc_blocked_dates: Holidays and specific date blocks
 *
 * @since 4.1.0
 * @version 4.1.0
 */

namespace FreeFormCertificate\Calendars;

if (!defined('ABSPATH')) exit;

class CalendarActivator {

    /**
     * Create all calendar-related tables
     *
     * Called during plugin activation.
     *
     * @return void
     */
    public static function create_tables(): void {
        self::create_calendars_table();
        self::create_appointments_table();
        self::create_blocked_dates_table();
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
        $table_name = $wpdb->prefix . 'ffc_calendars';
        $charset_collate = $wpdb->get_charset_collate();

        // Check if table already exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") == $table_name) {
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

            -- Approval workflow
            requires_approval tinyint(1) DEFAULT 0,

            -- Capacity
            max_appointments_per_slot int unsigned DEFAULT 1,

            -- User restrictions
            require_login tinyint(1) DEFAULT 0,
            allowed_roles longtext DEFAULT NULL COMMENT 'JSON array of role names',

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
        $table_name = $wpdb->prefix . 'ffc_appointments';
        $charset_collate = $wpdb->get_charset_collate();

        // Check if table already exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") == $table_name;

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
        dbDelta($sql);
    }

    /**
     * Migrate appointments table to add cpf_rf columns
     *
     * @return void
     */
    private static function migrate_appointments_table(): void {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_appointments';

        // Check if cpf_rf column exists
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
                $wpdb->query(
                    "ALTER TABLE {$table_name}
                    ADD COLUMN cpf_rf varchar(20) DEFAULT NULL AFTER email_hash,
                    ADD COLUMN cpf_rf_encrypted text DEFAULT NULL AFTER cpf_rf,
                    ADD COLUMN cpf_rf_hash varchar(64) DEFAULT NULL AFTER cpf_rf_encrypted"
                );

                // Add index separately to avoid errors if it already exists
                $index_exists = $wpdb->get_results(
                    "SHOW INDEX FROM {$table_name} WHERE Key_name = 'cpf_rf_hash'"
                );
                if (empty($index_exists)) {
                    $wpdb->query("ALTER TABLE {$table_name} ADD INDEX cpf_rf_hash (cpf_rf_hash)");
                }
            } else {
                // Fallback: add after email column
                $wpdb->query(
                    "ALTER TABLE {$table_name}
                    ADD COLUMN cpf_rf varchar(20) DEFAULT NULL AFTER email,
                    ADD COLUMN cpf_rf_encrypted text DEFAULT NULL AFTER cpf_rf,
                    ADD COLUMN cpf_rf_hash varchar(64) DEFAULT NULL AFTER cpf_rf_encrypted"
                );

                // Add index
                $index_exists = $wpdb->get_results(
                    "SHOW INDEX FROM {$table_name} WHERE Key_name = 'cpf_rf_hash'"
                );
                if (empty($index_exists)) {
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
        $table_name = $wpdb->prefix . 'ffc_appointments';

        // Check if validation_code column exists
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
            $wpdb->query(
                "ALTER TABLE {$table_name}
                ADD COLUMN validation_code varchar(20) DEFAULT NULL AFTER confirmation_token"
            );

            // Add index
            $index_exists = $wpdb->get_results(
                "SHOW INDEX FROM {$table_name} WHERE Key_name = 'validation_code'"
            );
            if (empty($index_exists)) {
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
        $table_name = $wpdb->prefix . 'ffc_appointments';

        // Get appointments without validation codes
        $appointments = $wpdb->get_results(
            "SELECT id FROM {$table_name} WHERE validation_code IS NULL OR validation_code = ''"
        );

        foreach ($appointments as $appointment) {
            // Generate unique validation code
            $validation_code = self::generate_unique_validation_code();

            // Update appointment
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
     * @return string
     */
    private static function generate_unique_validation_code(): string {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_appointments';

        do {
            // Generate code in format XXXX-XXXX-XXXX (12 alphanumeric characters)
            $code = self::generate_random_string(4) . '-' .
                    self::generate_random_string(4) . '-' .
                    self::generate_random_string(4);

            // Check if code already exists
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
     * Generate random alphanumeric string
     *
     * @param int $length
     * @return string
     */
    private static function generate_random_string(int $length = 4): string {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $chars_length = strlen($chars);
        $string = '';

        for ($i = 0; $i < $length; $i++) {
            $string .= $chars[rand(0, $chars_length - 1)];
        }

        return $string;
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

        $table_name = $wpdb->prefix . 'ffc_appointments';

        // Get appointments with unencrypted data
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
        $appointments_table = $wpdb->prefix . 'ffc_appointments';
        $appointments_exists = $wpdb->get_var("SHOW TABLES LIKE '{$appointments_table}'") == $appointments_table;

        if ($appointments_exists) {
            // Run migration to ensure cpf_rf columns exist
            self::migrate_appointments_table();
            // Run migration to ensure validation_code column exists
            self::migrate_appointments_validation_code();
            // Run migration to encrypt all unencrypted data
            self::migrate_encrypt_appointments_data();
        }

        // Migrate calendars table
        $calendars_table = $wpdb->prefix . 'ffc_calendars';
        $calendars_exists = $wpdb->get_var("SHOW TABLES LIKE '{$calendars_table}'") == $calendars_table;

        if ($calendars_exists) {
            // Run migration to ensure minimum_interval_between_bookings column exists
            self::migrate_calendars_table();
        }
    }

    /**
     * Migrate calendars table to add minimum_interval_between_bookings column
     *
     * @return void
     */
    private static function migrate_calendars_table(): void {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_calendars';

        // Check if minimum_interval_between_bookings column exists
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
            $wpdb->query(
                "ALTER TABLE {$table_name}
                ADD COLUMN minimum_interval_between_bookings int unsigned DEFAULT 24 COMMENT 'Minimum hours between user bookings (0 = disabled)' AFTER cancellation_min_hours"
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
        $table_name = $wpdb->prefix . 'ffc_blocked_dates';
        $charset_collate = $wpdb->get_charset_collate();

        // Check if table already exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") == $table_name) {
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
        dbDelta($sql);
    }

    /**
     * Drop all calendar tables (for uninstall)
     *
     * @return void
     */
    public static function drop_tables(): void {
        global $wpdb;

        $tables = array(
            $wpdb->prefix . 'ffc_calendars',
            $wpdb->prefix . 'ffc_appointments',
            $wpdb->prefix . 'ffc_blocked_dates'
        );

        foreach ($tables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS {$table}");
        }
    }
}
