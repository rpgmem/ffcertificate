<?php
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
 * @package FreeFormCertificate\SelfScheduling
 * @since 4.1.0
 * @version 4.5.0 - Renamed from CalendarActivator to SelfSchedulingActivator
 */

declare(strict_types=1);

namespace FreeFormCertificate\SelfScheduling;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin activation tasks for self scheduling.
 */
class SelfSchedulingActivator {

	use \FreeFormCertificate\Core\DatabaseHelperTrait;

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
		$table_name      = $wpdb->prefix . 'ffc_self_scheduling_calendars';
		$charset_collate = $wpdb->get_charset_collate();

		// Check if table already exists.
		if ( self::table_exists( $table_name ) ) {
			return;
		}

		// Column groups. Kept out of the statement because dbDelta() reads every
		// line of the field list as a column definition — see ActivatorSqlTest,
		// which fails on a comment, a blank line or an interior semicolon inside a
		// CREATE literal (#994, #997).
		// - Scheduling mode. 'regular' = weekly working hours, 'custom' = explicit date/time blocks (#941).
		// - Time slot configuration.
		// - Working hours (JSON: [{day: 0-6, start: '09:00', end: '17:00'}]).
		// - Custom blocks (JSON: [{date:'Y-m-d', start:'H:i', end:'H:i', capacity:int, label?:string}]) — used when schedule_type='custom' (#941).
		// - Booking window.
		// - Cancellation policy.
		// - Minimum interval between bookings.
		// - Approval workflow.
		// - Capacity.
		// - Waitlist (#941 phase 2): when a slot/block is full, allow joining a queue.
		// - Per-user block cap (#941 phase 3). Custom mode only, max distinct blocks one user may hold (0 = disabled).
		// - Visibility & access control.
		// - Email notifications (JSON config).
		// - Status.
		// - Metadata.
		$sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            post_id bigint(20) unsigned NOT NULL COMMENT 'Reference to wp_posts (CPT)',
            title varchar(255) NOT NULL,
            description text DEFAULT NULL,
            schedule_type enum('regular','custom') NOT NULL DEFAULT 'regular' COMMENT 'regular = weekly working_hours, custom = explicit custom_slots',
            slot_duration int unsigned DEFAULT 30 COMMENT 'Duration in minutes',
            slot_interval int unsigned DEFAULT 0 COMMENT 'Break between slots in minutes',
            slots_per_day int unsigned DEFAULT 0 COMMENT '0 = unlimited',
            working_hours longtext DEFAULT NULL,
            custom_slots longtext DEFAULT NULL,
            advance_booking_min int unsigned DEFAULT 0 COMMENT 'Minimum hours in advance',
            advance_booking_max int unsigned DEFAULT 30 COMMENT 'Maximum days in advance',
            allow_cancellation tinyint(1) DEFAULT 1,
            cancellation_min_hours int unsigned DEFAULT 24 COMMENT 'Minimum hours before appointment',
            minimum_interval_between_bookings int unsigned DEFAULT 24 COMMENT 'Minimum hours between user bookings (0 = disabled)',
            requires_approval tinyint(1) DEFAULT 0,
            max_appointments_per_slot int unsigned DEFAULT 1,
            waitlist_enabled tinyint(1) DEFAULT 0 COMMENT '1 = full slots offer a waitlist instead of rejecting',
            waitlist_capacity int unsigned DEFAULT 0 COMMENT 'Max queue length per slot (0 = unlimited)',
            max_blocks_per_user int unsigned DEFAULT 0 COMMENT 'Custom mode: max blocks a single user may book in this calendar (0 = disabled)',
            visibility enum('public','private') DEFAULT 'public' COMMENT 'Calendar visibility: public or private',
            scheduling_visibility enum('public','private') DEFAULT 'public' COMMENT 'Booking access: public or private',
            email_config longtext DEFAULT NULL,
            status varchar(20) DEFAULT 'active' COMMENT 'active, inactive, archived',
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
		dbDelta( $sql );
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
		$table_name      = $wpdb->prefix . 'ffc_self_scheduling_appointments';
		$charset_collate = $wpdb->get_charset_collate();

		// Check if table already exists.
		if ( self::table_exists( $table_name ) ) {
			// Run migration to add cpf_rf columns if they don't exist.
			self::migrate_appointments_table();
			return;
		}

		// Column groups. Kept out of the statement because dbDelta() reads every
		// line of the field list as a column definition — see ActivatorSqlTest,
		// which fails on a comment, a blank line or an interior semicolon inside a
		// CREATE literal (#994, #997).
		// - Appointment details.
		// - Contact information (for non-logged users or additional info).
		// - Additional data (JSON: custom fields).
		// - Notes.
		// - Status workflow.
		// - Approval (if calendar requires approval). Category A instant since 6.6.0 (#249).
		// - Verification token for guest users.
		// - Validation code (user-friendly code for verification, like certificates).
		// - Metadata.
		// - Reminder sent tracking. Category A instant since 6.6.0.
		$sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            calendar_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned DEFAULT NULL COMMENT 'WordPress user (if logged in)',
            appointment_date date NOT NULL,
            start_time time NOT NULL,
            end_time time NOT NULL,
            name varchar(255) DEFAULT NULL,
            email_encrypted text DEFAULT NULL,
            email_hash varchar(64) DEFAULT NULL,
            cpf_encrypted text DEFAULT NULL,
            cpf_hash varchar(64) DEFAULT NULL,
            rf_encrypted text DEFAULT NULL,
            rf_hash varchar(64) DEFAULT NULL,
            phone_encrypted text DEFAULT NULL,
            custom_data_encrypted longtext DEFAULT NULL,
            user_notes text DEFAULT NULL,
            admin_notes text DEFAULT NULL,
            status varchar(20) DEFAULT 'pending' COMMENT 'pending, confirmed, cancelled, completed, no_show, waitlist',
            approved_at bigint(20) unsigned DEFAULT NULL,
            approved_by bigint(20) unsigned DEFAULT NULL,
            cancelled_at bigint(20) unsigned DEFAULT NULL,
            cancelled_by bigint(20) unsigned DEFAULT NULL,
            cancellation_reason text DEFAULT NULL,
            confirmation_token varchar(64) DEFAULT NULL,
            validation_code varchar(20) DEFAULT NULL,
            consent_given tinyint(1) DEFAULT 0,
            consent_date bigint(20) unsigned DEFAULT NULL,
            consent_text text DEFAULT NULL,
            user_ip_encrypted text DEFAULT NULL,
            user_agent varchar(255) DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime DEFAULT NULL,
            reminder_sent_at bigint(20) unsigned DEFAULT NULL,
            PRIMARY KEY (id),
            KEY calendar_id (calendar_id),
            KEY user_id (user_id),
            KEY appointment_date (appointment_date),
            KEY status (status),
            KEY email_hash (email_hash),
            KEY cpf_hash (cpf_hash),
            KEY rf_hash (rf_hash),
            KEY confirmation_token (confirmation_token),
            UNIQUE KEY validation_code (validation_code),
            KEY idx_calendar_date (calendar_id, appointment_date),
            KEY idx_calendar_datetime (calendar_id, appointment_date, start_time)
        ) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Migrate appointments table to add split cpf/rf columns
	 *
	 * @return void
	 */
	private static function migrate_appointments_table(): void {
		global $wpdb;
		$table_name = $wpdb->prefix . 'ffc_self_scheduling_appointments';

		// Determine position for new columns.
		$after_column = 'email_hash';
		if ( ! self::column_exists( $table_name, 'email_hash' ) ) {
			$after_column = 'name';
		}

		// Add split cpf/rf encrypted columns.
		self::add_column_if_missing( $table_name, 'cpf_encrypted', 'text DEFAULT NULL', $after_column );
		self::add_column_if_missing( $table_name, 'cpf_hash', 'varchar(64) DEFAULT NULL', 'cpf_encrypted', 'cpf_hash' );
		self::add_column_if_missing( $table_name, 'rf_encrypted', 'text DEFAULT NULL', 'cpf_hash' );
		self::add_column_if_missing( $table_name, 'rf_hash', 'varchar(64) DEFAULT NULL', 'rf_encrypted', 'rf_hash' );
	}

	/**
	 * Migrate appointments table to add validation_code column
	 *
	 * @return void
	 */
	private static function migrate_appointments_validation_code(): void {
		global $wpdb;
		$table_name = $wpdb->prefix . 'ffc_self_scheduling_appointments';

		// Check if validation_code column exists.
		if ( ! self::column_exists( $table_name, 'validation_code' ) ) {
			// Add validation_code column after confirmation_token.
			self::add_column_if_missing( $table_name, 'validation_code', 'varchar(20) DEFAULT NULL', 'confirmation_token', 'validation_code' );

			// Generate validation codes for existing appointments.
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

		// Get appointments without validation codes.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$appointments = $wpdb->get_results(
			$wpdb->prepare( "SELECT id FROM %i WHERE validation_code IS NULL OR validation_code = ''", $table_name )
		);

		foreach ( $appointments as $appointment ) {
			// Generate unique validation code.
			$validation_code = self::generate_unique_validation_code();

			// Update appointment.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$table_name,
				array( 'validation_code' => $validation_code ),
				array( 'id' => $appointment->id ),
				array( '%s' ),
				array( '%d' )
			);
		}
	}

	/**
	 * Generate unique validation code
	 *
	 * Generates a 12-character alphanumeric code (stored without hyphens).
	 * Use DocumentFormatter::format_auth_code() to display with hyphens (XXXX-XXXX-XXXX).
	 *
	 * @return string 12-character code without hyphens
	 */
	private static function generate_unique_validation_code(): string {
		global $wpdb;
		$table_name = $wpdb->prefix . 'ffc_self_scheduling_appointments';

		do {
			// Generate 12 alphanumeric characters (stored clean, without hyphens).
			$code = \FreeFormCertificate\Core\AuthCodeService::generate_random_string( 12 );

			// Check if code already exists.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$existing = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT id FROM %i WHERE validation_code = %s',
					$table_name,
					$code
				)
			);
		} while ( $existing );

		return $code;
	}


	/**
	 * Run migrations on plugin load
	 * This ensures migrations run even if plugin wasn't re-activated
	 *
	 * @return void
	 */
	public static function maybe_migrate(): void {
		global $wpdb;

		// Migrate appointments table.
		$appointments_table = $wpdb->prefix . 'ffc_self_scheduling_appointments';

		if ( self::table_exists( $appointments_table ) ) {
			// Run migration to ensure cpf/rf split columns exist.
			self::migrate_appointments_table();
			// Run migration to ensure validation_code column exists.
			self::migrate_appointments_validation_code();
		}

		// Migrate calendars table.
		$calendars_table = $wpdb->prefix . 'ffc_self_scheduling_calendars';

		if ( self::table_exists( $calendars_table ) ) {
			// Run migration to ensure minimum_interval_between_bookings column exists.
			self::migrate_calendars_table();
			// Run migration to add visibility columns (replacing require_login/allowed_roles).
			self::migrate_visibility_columns();
			// Run migration to add business hours restriction columns.
			self::migrate_business_hours_restriction_columns();
			// Run migration to add custom-scheduling columns (#941).
			self::migrate_custom_scheduling_columns();
			// Run migration to add waitlist columns (#941 phase 2).
			self::migrate_waitlist_columns();
			// Run migration to add the per-user block cap column (#941 phase 3).
			self::migrate_block_cap_column();
		}
	}

	/**
	 * Migrate calendars table to add the per-user block cap column (#941 phase 3).
	 *
	 * `max_blocks_per_user` caps how many distinct blocks a single user may hold
	 * in a custom calendar (0 = disabled). Defaults off, so existing calendars
	 * are unchanged on upgrade. Custom mode only.
	 *
	 * @since 6.20.0
	 * @return void
	 */
	private static function migrate_block_cap_column(): void {
		global $wpdb;
		$table_name = $wpdb->prefix . 'ffc_self_scheduling_calendars';

		self::add_column_if_missing(
			$table_name,
			'max_blocks_per_user',
			"int unsigned DEFAULT 0 COMMENT 'Custom mode: max blocks a single user may book in this calendar (0 = disabled)'",
			'waitlist_capacity'
		);
	}

	/**
	 * Migrate calendars table to add the waitlist columns (#941 phase 2).
	 *
	 * `waitlist_enabled` turns the per-calendar waitlist on; `waitlist_capacity`
	 * caps the queue length per slot (0 = unlimited). Both default off/unbounded,
	 * so existing calendars keep the current "full slot → reject" behaviour on
	 * upgrade. Applies to both scheduling modes.
	 *
	 * @since 6.20.0
	 * @return void
	 */
	private static function migrate_waitlist_columns(): void {
		global $wpdb;
		$table_name = $wpdb->prefix . 'ffc_self_scheduling_calendars';

		self::add_column_if_missing(
			$table_name,
			'waitlist_enabled',
			"tinyint(1) DEFAULT 0 COMMENT '1 = full slots offer a waitlist instead of rejecting'",
			'max_appointments_per_slot'
		);
		self::add_column_if_missing(
			$table_name,
			'waitlist_capacity',
			"int unsigned DEFAULT 0 COMMENT 'Max queue length per slot (0 = unlimited)'",
			'waitlist_enabled'
		);
	}

	/**
	 * Migrate calendars table to add the custom-scheduling columns (#941).
	 *
	 * `schedule_type` selects between the regular weekly working-hours model
	 * (default, unchanged behaviour) and the custom explicit-blocks model;
	 * `custom_slots` holds the blocks JSON for the latter. Existing calendars
	 * default to `regular`, so no behaviour changes on upgrade.
	 *
	 * @since 6.20.0
	 * @return void
	 */
	private static function migrate_custom_scheduling_columns(): void {
		global $wpdb;
		$table_name = $wpdb->prefix . 'ffc_self_scheduling_calendars';

		self::add_column_if_missing(
			$table_name,
			'schedule_type',
			"enum('regular','custom') NOT NULL DEFAULT 'regular' COMMENT 'regular = weekly working_hours; custom = explicit custom_slots'",
			'description'
		);
		self::add_column_if_missing(
			$table_name,
			'custom_slots',
			'longtext DEFAULT NULL',
			'working_hours'
		);
	}

	/**
	 * Migrate calendars table to add minimum_interval_between_bookings column
	 *
	 * @return void
	 */
	private static function migrate_calendars_table(): void {
		global $wpdb;
		$table_name = $wpdb->prefix . 'ffc_self_scheduling_calendars';

		// Add minimum_interval_between_bookings column after cancellation_min_hours.
		self::add_column_if_missing(
			$table_name,
			'minimum_interval_between_bookings',
			"int unsigned DEFAULT 24 COMMENT 'Minimum hours between user bookings (0 = disabled)'",
			'cancellation_min_hours'
		);
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

		// Check if visibility column already exists.
		if ( self::column_exists( $table_name, 'visibility' ) ) {
			return;
		}

		// Check if require_login column exists (old schema).
		$require_login_exists = self::column_exists( $table_name, 'require_login' );

		// Add new columns.
		self::add_column_if_missing(
			$table_name,
			'visibility',
			"enum('public','private') DEFAULT 'public' COMMENT 'Calendar visibility: public or private'",
			'max_appointments_per_slot'
		);
		self::add_column_if_missing(
			$table_name,
			'scheduling_visibility',
			"enum('public','private') DEFAULT 'public' COMMENT 'Booking access: public or private'",
			'visibility'
		);

		// Migrate data from require_login if the old column exists.
		if ( $require_login_exists ) {
			// require_login=1 → private/private.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE %i SET visibility = 'private', scheduling_visibility = 'private' WHERE require_login = 1",
					$table_name
				)
			);

			// require_login=0 → public/public (already default, but be explicit).
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE %i SET visibility = 'public', scheduling_visibility = 'public' WHERE require_login = 0",
					$table_name
				)
			);

			// Drop old columns.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					'ALTER TABLE %i DROP COLUMN require_login, DROP COLUMN allowed_roles',
					$table_name
				)
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

		// Add business hours restriction columns after scheduling_visibility.
		self::add_column_if_missing(
			$table_name,
			'restrict_viewing_to_hours',
			"tinyint(1) DEFAULT 0 COMMENT 'Restrict viewing to working hours only'",
			'scheduling_visibility'
		);
		self::add_column_if_missing(
			$table_name,
			'restrict_booking_to_hours',
			"tinyint(1) DEFAULT 0 COMMENT 'Restrict booking to working hours only'",
			'restrict_viewing_to_hours'
		);
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
		$table_name      = $wpdb->prefix . 'ffc_self_scheduling_blocked_dates';
		$charset_collate = $wpdb->get_charset_collate();

		// Check if table already exists.
		if ( self::table_exists( $table_name ) ) {
			return;
		}

		// Column groups. Kept out of the statement because dbDelta() reads every
		// line of the field list as a column definition — see ActivatorSqlTest,
		// which fails on a comment, a blank line or an interior semicolon inside a
		// CREATE literal (#994, #997).
		// - Block type.
		// - Date range.
		// - Time range (for partial day blocks).
		// - Recurring pattern (JSON: {type: 'weekly', days: [0,6], etc}).
		// - Description.
		// - Metadata.
		$sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            calendar_id bigint(20) unsigned DEFAULT NULL COMMENT 'NULL = applies to all calendars',
            block_type varchar(20) DEFAULT 'full_day' COMMENT 'full_day, time_range, recurring',
            start_date date NOT NULL,
            end_date date DEFAULT NULL COMMENT 'For multi-day blocks',
            start_time time DEFAULT NULL,
            end_time time DEFAULT NULL,
            recurring_pattern longtext DEFAULT NULL,
            reason varchar(255) DEFAULT NULL COMMENT 'Holiday, maintenance, etc',
            created_at datetime NOT NULL,
            created_by bigint(20) unsigned DEFAULT NULL,
            PRIMARY KEY (id),
            KEY calendar_id (calendar_id),
            KEY start_date (start_date),
            KEY block_type (block_type),
            KEY idx_calendar_daterange (calendar_id, start_date, end_date)
        ) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
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
			$wpdb->prefix . 'ffc_self_scheduling_blocked_dates',
		);

		foreach ( $tables as $table ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
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

		self::add_indexes_if_missing(
			$table_name,
			array(
				'idx_calendar_status_date' => '(calendar_id, status, appointment_date)',
				'idx_user_status'          => '(user_id, status)',
			)
		);
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

		// Check if validation_code index exists and whether it's already unique.
		if ( self::index_exists( $table_name, 'validation_code' ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$indexes = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $table_name, 'validation_code' ) );

			// Check if Non_unique = 0 (already unique).
			if ( (int) 0 === $indexes[0]->Non_unique ) {
				return; // Already unique.
			}

			// Drop the non-unique index first.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP INDEX %i', $table_name, 'validation_code' ) );
		}

		// Add UNIQUE index.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD UNIQUE KEY validation_code (validation_code)', $table_name ) );
	}
}
