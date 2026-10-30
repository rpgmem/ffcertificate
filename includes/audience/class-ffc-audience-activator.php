<?php
/**
 * Audience Activator
 *
 * Creates database tables for audience booking system.
 * This system allows users with permission to book audiences/groups for specific environments.
 *
 * Tables created:
 * - wp_ffc_audience_schedules: Calendar configurations
 * - wp_ffc_audience_schedule_permissions: User permissions per calendar
 * - wp_ffc_audience_environments: Physical locations/rooms
 * - wp_ffc_audience_holidays: Holidays per calendar
 * - wp_ffc_audiences: Audience groups (with hierarchy)
 * - wp_ffc_audience_members: Users belonging to audience groups
 * - wp_ffc_audience_bookings: Booking records
 * - wp_ffc_audience_booking_audiences: N:N booking to audiences
 * - wp_ffc_audience_booking_users: N:N booking to individual users
 *
 * @package FreeFormCertificate\Audience
 * @since 4.5.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin activation tasks for audience.
 */
class AudienceActivator {

	use \FreeFormCertificate\Core\DatabaseHelperTrait;

	/**
	 * Revision of the columns {@see self::maybe_heal_columns()} adds. Bump it in
	 * the PR that adds one, so installs on the same `FFC_VERSION` heal too.
	 *
	 * @var string
	 */
	public const SCHEMA_REVISION = '8';

	/**
	 * Option holding the revision last applied.
	 *
	 * @var string
	 */
	public const SCHEMA_REVISION_OPTION = 'ffc_audience_schema_revision';

	/**
	 * Create all audience-related tables
	 *
	 * Called during plugin activation.
	 *
	 * @return void
	 */
	public static function create_tables(): void {
		self::create_schedules_table();
		self::create_schedule_permissions_table();
		self::create_environments_table();
		self::create_holidays_table();
		self::create_audiences_table();
		self::create_audience_members_table();
		self::create_bookings_table();
		self::create_booking_audiences_table();
		self::create_booking_users_table();
		self::create_attendance_table();
		self::create_checkin_windows_table();
		self::create_form_invites_table();
		self::register_capabilities();
		self::add_composite_indexes();
	}

	/**
	 * Register audience booking capabilities
	 *
	 * Adds the ffc_view_own_audience_bookings capability to appropriate roles.
	 *
	 * @return void
	 */
	public static function register_capabilities(): void {
		// Get the ffc_end_user role.
		$ffc_user_role = get_role( 'ffc_end_user' );
		if ( $ffc_user_role ) {
			$ffc_user_role->add_cap( 'ffc_view_own_audience_bookings' );
		}

		// WordPress's own `subscriber` role is deliberately NOT given this
		// capability (#1302). It used to be, which put an FFC capability on a
		// core role on every install — and it reached every subscriber on the
		// site, member of an audience or not. What it was covering is that only
		// the self-join route granted the capability; the admin-side paths did
		// not. `AudienceWriter::add_member()` now grants it at the single point
		// all of them pass through, so membership carries the capability and no
		// role WordPress owns has to.

		// Administrator already has all caps via manage_options.
	}

	/**
	 * Create schedules table
	 *
	 * Stores calendar configurations for audience booking.
	 *
	 * @return void
	 */
	private static function create_schedules_table(): void {
		global $wpdb;
		$table_name      = $wpdb->prefix . 'ffc_audience_schedules';
		$charset_collate = $wpdb->get_charset_collate();

		if ( self::table_exists( $table_name ) ) {
			return;
		}

		$sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            description text DEFAULT NULL,
            environment_label varchar(100) DEFAULT NULL COMMENT 'Custom label for environments (default: Environments)',
            visibility enum('public','private') DEFAULT 'private',
            future_days_limit int(10) unsigned DEFAULT NULL COMMENT 'NULL = no limit, only applies to non-admin',
            notify_on_booking tinyint(1) DEFAULT 1,
            notify_on_cancellation tinyint(1) DEFAULT 1,
            notify_admin_on_booking tinyint(1) DEFAULT 0,
            notify_admin_on_cancellation tinyint(1) DEFAULT 0,
            admin_notification_emails text DEFAULT NULL,
            email_template_booking text DEFAULT NULL,
            email_template_cancellation text DEFAULT NULL,
            include_ics tinyint(1) DEFAULT 0,
            show_event_list tinyint(1) DEFAULT 0 COMMENT 'Show event list alongside calendar',
            event_list_position enum('side','below') DEFAULT 'side' COMMENT 'Position of event list',
            status enum('active','inactive') DEFAULT 'active',
            created_by bigint(20) unsigned NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            booking_label_singular varchar(50) DEFAULT NULL COMMENT 'Custom singular label for booking badge',
            booking_label_plural varchar(50) DEFAULT NULL COMMENT 'Custom plural label for booking badge',
            audience_badge_format enum('name','parent_name') DEFAULT 'name' COMMENT 'How audience badges display: name only or parent: child',
            is_isolated tinyint(1) DEFAULT 0 COMMENT 'Ignore conflicts from other schedules',
            admin_bypass tinyint(1) DEFAULT 1 COMMENT 'Audience bypass holders may book outside the rules',
            post_event_form_id bigint(20) unsigned DEFAULT NULL,
            post_event_window_days smallint(5) unsigned DEFAULT NULL,
            post_event_attendees_only tinyint(1) DEFAULT 0,
            appearance_names longtext DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_status (status),
            KEY idx_created_by (created_by)
        ) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Create schedule permissions table
	 *
	 * Stores user permissions per calendar.
	 *
	 * @return void
	 */
	private static function create_schedule_permissions_table(): void {
		global $wpdb;
		$table_name      = $wpdb->prefix . 'ffc_audience_schedule_permissions';
		$charset_collate = $wpdb->get_charset_collate();

		if ( self::table_exists( $table_name ) ) {
			return;
		}

		$sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            schedule_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            can_book tinyint(1) DEFAULT 1,
            can_cancel_others tinyint(1) DEFAULT 0,
            can_override_conflicts tinyint(1) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_schedule_user (schedule_id, user_id),
            KEY idx_user (user_id)
        ) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Create environments table
	 *
	 * Stores physical locations/rooms within a schedule.
	 *
	 * @return void
	 */
	private static function create_environments_table(): void {
		global $wpdb;
		$table_name      = $wpdb->prefix . 'ffc_audience_environments';
		$charset_collate = $wpdb->get_charset_collate();

		if ( self::table_exists( $table_name ) ) {
			return;
		}

		$sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            schedule_id bigint(20) unsigned NOT NULL,
            name varchar(255) NOT NULL,
            color varchar(7) DEFAULT '#3788d8' COMMENT 'Hex color for visual identification',
            description text DEFAULT NULL,
            working_hours longtext DEFAULT NULL COMMENT 'JSON: {mon: {start, end, closed}, ...}',
            status enum('active','inactive') DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_schedule (schedule_id),
            KEY idx_status (status)
        ) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Create holidays table
	 *
	 * Stores holidays/closed dates per calendar.
	 *
	 * @return void
	 */
	private static function create_holidays_table(): void {
		global $wpdb;
		$table_name      = $wpdb->prefix . 'ffc_audience_holidays';
		$charset_collate = $wpdb->get_charset_collate();

		if ( self::table_exists( $table_name ) ) {
			return;
		}

		$sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            schedule_id bigint(20) unsigned NOT NULL,
            holiday_date date NOT NULL,
            description varchar(255) DEFAULT NULL,
            kind varchar(20) NOT NULL DEFAULT 'holiday',
            created_by bigint(20) unsigned NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_schedule_date (schedule_id, holiday_date),
            KEY idx_date (holiday_date)
        ) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Create audiences table
	 *
	 * Stores audience groups with 3-level hierarchy (parent/child/grandchild).
	 *
	 * @return void
	 */
	private static function create_audiences_table(): void {
		global $wpdb;
		$table_name      = $wpdb->prefix . 'ffc_audiences';
		$charset_collate = $wpdb->get_charset_collate();

		if ( self::table_exists( $table_name ) ) {
			return;
		}

		$sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            color varchar(7) DEFAULT '#3788d8' COMMENT 'Hex color for visual identification',
            parent_id bigint(20) unsigned DEFAULT NULL COMMENT 'NULL = parent group, otherwise child',
            status enum('active','inactive') DEFAULT 'active',
            created_by bigint(20) unsigned NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            allow_self_join tinyint(1) DEFAULT 0 COMMENT 'Allow users to join/leave from dashboard',
            members_only tinyint(1) DEFAULT 0 COMMENT 'Top-level: tree hidden from the Join groups panel for non-members',
            require_membership tinyint(1) DEFAULT 0 COMMENT 'Top-level: a member cannot leave the last group of the tree',
            PRIMARY KEY (id),
            KEY idx_parent (parent_id),
            KEY idx_status (status)
        ) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Create audience members table
	 *
	 * Stores users belonging to audience groups.
	 *
	 * @return void
	 */
	private static function create_audience_members_table(): void {
		global $wpdb;
		$table_name      = $wpdb->prefix . 'ffc_audience_members';
		$charset_collate = $wpdb->get_charset_collate();

		if ( self::table_exists( $table_name ) ) {
			return;
		}

		$sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            audience_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_audience_user (audience_id, user_id),
            KEY idx_user (user_id)
        ) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Create bookings table
	 *
	 * Stores booking records.
	 *
	 * @return void
	 */
	private static function create_bookings_table(): void {
		global $wpdb;
		$table_name      = $wpdb->prefix . 'ffc_audience_bookings';
		$charset_collate = $wpdb->get_charset_collate();

		if ( self::table_exists( $table_name ) ) {
			return;
		}

		$sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            environment_id bigint(20) unsigned NOT NULL,
            booking_date date NOT NULL,
            start_time time NOT NULL,
            end_time time NOT NULL,
            is_all_day tinyint(1) DEFAULT 0 COMMENT 'All-day event flag',
            booking_type enum('audience','individual') NOT NULL,
            description varchar(300) NOT NULL COMMENT 'Required, 15-300 chars',
            status enum('active','cancelled') DEFAULT 'active',
            created_by bigint(20) unsigned NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            cancelled_by bigint(20) unsigned DEFAULT NULL,
            cancelled_at datetime DEFAULT NULL,
            cancellation_reason varchar(500) DEFAULT NULL COMMENT 'Required when cancelling',
            end_date date DEFAULT NULL COMMENT 'Last day of a range, NULL for a single day',
            time_mode enum('daily','span') DEFAULT NULL COMMENT 'Hours of a range: daily or span',
            skipped_dates text DEFAULT NULL COMMENT 'JSON list of blocked days a range left out',
            day_times text DEFAULT NULL COMMENT 'JSON hours of each day of a span, frozen at creation',
            attendance_mode enum('single','total') DEFAULT NULL,
            attendance_min_percent tinyint(3) unsigned DEFAULT NULL,
            post_event_custom tinyint(1) DEFAULT 0,
            post_event_form_id bigint(20) unsigned DEFAULT NULL,
            post_event_window_days smallint(5) unsigned DEFAULT NULL,
            post_event_attendees_only tinyint(1) DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_environment (environment_id),
            KEY idx_date (booking_date),
            KEY idx_status (status),
            KEY idx_created_by (created_by),
            KEY idx_env_date_status (environment_id, booking_date, status)
        ) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Create the attendance table (#1947).
	 *
	 * One row per person, booking and day they were present. `user_id` is
	 * nullable because a row is a record: deleting the account drops the link
	 * and keeps the line (CLAUDE.md "Security & PII conventions"). The unique
	 * key is what makes a second check-in on the same day a no-op.
	 *
	 * @return void
	 */
	private static function create_attendance_table(): void {
		global $wpdb;
		$table_name      = $wpdb->prefix . 'ffc_audience_attendance';
		$charset_collate = $wpdb->get_charset_collate();

		if ( self::table_exists( $table_name ) ) {
			return;
		}

		$sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            booking_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned DEFAULT NULL,
            attendance_date date NOT NULL,
            checked_at bigint(20) unsigned NOT NULL,
            method enum('qr','manual') NOT NULL DEFAULT 'qr',
            marked_by bigint(20) unsigned DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_booking_user_date (booking_id,user_id,attendance_date),
            KEY idx_user (user_id),
            KEY idx_booking_date (booking_id,attendance_date)
        ) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Create the check-in windows table (#1947).
	 *
	 * A window is one opening of check-in for one day of a booking. Its id
	 * enters the QR signature, so reopening invalidates every code shown
	 * before; `closes_at` is the automatic close, `closed_at` the actual one.
	 * `geofence_locations` lists the saved geofence locations a check-in must
	 * fall inside (comma-separated registry ids), NULL for none.
	 *
	 * @return void
	 */
	private static function create_checkin_windows_table(): void {
		global $wpdb;
		$table_name      = $wpdb->prefix . 'ffc_audience_checkin_windows';
		$charset_collate = $wpdb->get_charset_collate();

		if ( self::table_exists( $table_name ) ) {
			return;
		}

		$sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            booking_id bigint(20) unsigned NOT NULL,
            window_date date NOT NULL,
            rotation_seconds smallint(5) unsigned NOT NULL DEFAULT 60,
            opened_at bigint(20) unsigned NOT NULL,
            opened_by bigint(20) unsigned DEFAULT NULL,
            closes_at bigint(20) unsigned NOT NULL,
            closed_at bigint(20) unsigned DEFAULT NULL,
            closed_by bigint(20) unsigned DEFAULT NULL,
            geofence_locations varchar(255) DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_booking_date (booking_id,window_date)
        ) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Create the post-event invitations table (#1948).
	 *
	 * One row per person and booking whose post-event form invitation was
	 * sent; the unique key is what makes a second send update the row rather
	 * than add one. A row is a relationship, not a record: it is deleted with
	 * the account.
	 *
	 * @return void
	 */
	private static function create_form_invites_table(): void {
		global $wpdb;
		$table_name      = $wpdb->prefix . 'ffc_audience_form_invites';
		$charset_collate = $wpdb->get_charset_collate();

		if ( self::table_exists( $table_name ) ) {
			return;
		}

		$sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            booking_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            form_id bigint(20) unsigned NOT NULL,
            sent_at bigint(20) unsigned NOT NULL,
            send_count smallint(5) unsigned NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            UNIQUE KEY uq_booking_user (booking_id,user_id),
            KEY idx_user (user_id)
        ) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Create booking audiences table
	 *
	 * N:N relationship between bookings and audience groups.
	 *
	 * @return void
	 */
	private static function create_booking_audiences_table(): void {
		global $wpdb;
		$table_name      = $wpdb->prefix . 'ffc_audience_booking_audiences';
		$charset_collate = $wpdb->get_charset_collate();

		if ( self::table_exists( $table_name ) ) {
			return;
		}

		$sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            booking_id bigint(20) unsigned NOT NULL,
            audience_id bigint(20) unsigned NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY unique_booking_audience (booking_id, audience_id),
            KEY idx_audience (audience_id)
        ) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Create booking users table
	 *
	 * N:N relationship between bookings and individual users.
	 *
	 * @return void
	 */
	private static function create_booking_users_table(): void {
		global $wpdb;
		$table_name      = $wpdb->prefix . 'ffc_audience_booking_users';
		$charset_collate = $wpdb->get_charset_collate();

		if ( self::table_exists( $table_name ) ) {
			return;
		}

		$sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            booking_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY unique_booking_user (booking_id, user_id),
            KEY idx_user (user_id)
        ) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Run migrations on plugin load
	 * This ensures migrations run even if plugin wasn't re-activated
	 *
	 * @since 4.7.0
	 * @return void
	 */
	/**
	 * Version gate: the chain below only needs to run once per `FFC_VERSION`
	 * (#1231).
	 *
	 * Without it, `AudienceActivator::maybe_migrate()` probed the schema on
	 * EVERY request -- anonymous frontend included -- because `table_exists()`
	 * is an uncached `SHOW TABLES LIKE` and every `add_column_if_missing()`
	 * fires a `SHOW COLUMNS` before deciding to do nothing. Summed across the
	 * `Loader`'s four chains, that was 48 DDL queries per page on an install
	 * with nothing to migrate.
	 *
	 * **The gate is `FFC_VERSION`, and NOT a one-shot marker, on purpose.**
	 * These calls exist because an in-place plugin update (wp-admin's "Update"
	 * button) does NOT fire `register_activation_hook` -- the property to
	 * preserve is "the schema heals itself after an update", not "it runs on
	 * every request". With `FFC_VERSION` the constant changes on the update and
	 * the chain runs once on the first request after it, identical to what it
	 * did before. With a one-shot boolean, a column introduced in a future
	 * release would never reach whoever already had the marker stored.
	 *
	 * The option is written **after** the body, so a failure partway through
	 * does not lock the chain at a version it never finished applying.
	 */
	public static function maybe_migrate(): void {
		// Columns added between releases heal on their own gate first (#1918).
		self::maybe_heal_columns();

		// Version gate (#1231) -- see the note just above the signature.
		$ffc_schema_option = 'ffc_audience_schema_version';
		if ( get_option( $ffc_schema_option, '' ) === FFC_VERSION ) {
			return;
		}

		global $wpdb;

		$schedules_table = $wpdb->prefix . 'ffc_audience_schedules';

		if ( self::table_exists( $schedules_table ) ) {
			self::migrate_environment_label_column();
			self::migrate_booking_is_all_day_column();
			self::migrate_environment_color_column();
			self::migrate_schedule_event_list_columns();
			self::migrate_schedule_audience_badge_format_column();
			self::migrate_schedule_booking_label_columns();
			self::migrate_schedule_isolated_column();
			self::migrate_schedule_admin_notification_columns();
			self::migrate_schedule_admin_bypass_column();
			// One-shot: rewrite stored email templates {token} -> {{token}} (#653).
			AudienceEmailTokenMigration::maybe_migrate();
		}

		self::migrate_audience_self_join_column();
		self::migrate_subscriber_cap_to_members();

		update_option( $ffc_schema_option, FFC_VERSION );
	}

	/**
	 * Move `ffc_view_own_audience_bookings` off the `subscriber` role and onto
	 * the people who actually belong to an audience (#1302).
	 *
	 * **The order is forced and is the opposite of the intuitive one.**
	 * `CapabilityManager::grant_audience_capabilities()` skips a user who
	 * already `has_cap()`, and a capability inherited from a role answers that
	 * question yes — so back-filling while `subscriber` still carries the
	 * capability grants nothing at all to the very users this exists for. The
	 * role is stripped first, and only then are the members seeded.
	 *
	 * The flag is written last, so an interrupted run is retried on the next
	 * request rather than leaving members without the capability forever.
	 *
	 * The walk is over `ffc_audience_members`, not over the user base: work is
	 * proportional to how many people belong to an audience, which is the
	 * prefilter rule #1254 established for the capability migrations.
	 *
	 * @since 6.26.0
	 * @return void
	 */
	private static function migrate_subscriber_cap_to_members(): void {
		$flag = 'ffc_audience_cap_off_subscriber_v1';
		if ( '1' === get_option( $flag, '' ) ) {
			return;
		}

		$subscriber_role = get_role( 'subscriber' );
		if ( $subscriber_role instanceof \WP_Role ) {
			$subscriber_role->remove_cap( 'ffc_view_own_audience_bookings' );
		}

		global $wpdb;
		$members_table = $wpdb->prefix . 'ffc_audience_members';

		if ( self::table_exists( $members_table ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot migration over the plugin's own table; there is no WordPress API for it and the result is used once.
			$member_ids = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT user_id FROM %i', $members_table ) );

			foreach ( (array) $member_ids as $member_id ) {
				if ( class_exists( '\FreeFormCertificate\UserDashboard\CapabilityManager' ) ) {
					\FreeFormCertificate\UserDashboard\CapabilityManager::grant_audience_capabilities( (int) $member_id );
				}
			}
		}

		update_option( $flag, '1', true );
	}

	/**
	 * Migrate schedules table to add environment_label column
	 *
	 * Allows each calendar to define a custom label for its environments
	 * (e.g. "Salas", "Consultórios", "Categorias") instead of the default "Ambientes".
	 *
	 * @since 4.7.0
	 * @return void
	 */
	private static function migrate_environment_label_column(): void {
		global $wpdb;
		$table_name = $wpdb->prefix . 'ffc_audience_schedules';

		// Add environment_label column after description.
		self::add_column_if_missing(
			$table_name,
			'environment_label',
			"varchar(100) DEFAULT NULL COMMENT 'Custom label for environments (default: Environments)'",
			'description'
		);
	}

	/**
	 * Migrate bookings table to add is_all_day column
	 *
	 * @since 4.8.0
	 * @return void
	 */
	private static function migrate_booking_is_all_day_column(): void {
		global $wpdb;
		$table_name = $wpdb->prefix . 'ffc_audience_bookings';

		self::add_column_if_missing(
			$table_name,
			'is_all_day',
			"tinyint(1) DEFAULT 0 COMMENT 'All-day event flag'",
			'end_time'
		);
	}

	/**
	 * Migrate environments table to add color column
	 *
	 * @since 4.8.0
	 * @return void
	 */
	private static function migrate_environment_color_column(): void {
		global $wpdb;
		$table_name = $wpdb->prefix . 'ffc_audience_environments';

		self::add_column_if_missing(
			$table_name,
			'color',
			"varchar(7) DEFAULT '#3788d8' COMMENT 'Hex color for visual identification'",
			'name'
		);
	}

	/**
	 * Migrate schedules table to add event list columns
	 *
	 * @since 4.8.0
	 * @return void
	 */
	private static function migrate_schedule_event_list_columns(): void {
		global $wpdb;
		$table_name = $wpdb->prefix . 'ffc_audience_schedules';

		self::add_column_if_missing(
			$table_name,
			'show_event_list',
			"tinyint(1) DEFAULT 0 COMMENT 'Show event list alongside calendar'",
			'include_ics'
		);
		self::add_column_if_missing(
			$table_name,
			'event_list_position',
			"enum('side','below') DEFAULT 'side' COMMENT 'Position of event list'",
			'show_event_list'
		);
	}

	/**
	 * Migrate schedules table to add booking_label_singular / booking_label_plural columns.
	 *
	 * Custom labels for the booking count badge shown in calendar day cells
	 * (e.g. "atendimento" / "atendimentos" instead of "reserva" / "reservas").
	 *
	 * @since 4.9.0
	 * @return void
	 */
	private static function migrate_schedule_booking_label_columns(): void {
		global $wpdb;
		$table_name = $wpdb->prefix . 'ffc_audience_schedules';

		self::add_column_if_missing(
			$table_name,
			'booking_label_singular',
			"varchar(50) DEFAULT NULL COMMENT 'Custom singular label for booking badge'"
		);
		self::add_column_if_missing(
			$table_name,
			'booking_label_plural',
			"varchar(50) DEFAULT NULL COMMENT 'Custom plural label for booking badge'"
		);
	}

	/**
	 * Migrate schedules table to add audience_badge_format column.
	 *
	 * Controls how audience badges are displayed on the frontend:
	 * 'name' (default) shows only the audience name,
	 * 'parent_name' shows "Parent: Child".
	 *
	 * @since 4.9.0
	 * @return void
	 */
	private static function migrate_schedule_audience_badge_format_column(): void {
		global $wpdb;
		$table_name = $wpdb->prefix . 'ffc_audience_schedules';

		self::add_column_if_missing(
			$table_name,
			'audience_badge_format',
			"enum('name','parent_name') DEFAULT 'name' COMMENT 'How audience badges display: name only or parent: child'"
		);
	}

	/**
	 * Migrate schedules table to add is_isolated column
	 *
	 * When enabled, the schedule ignores audience and user conflicts from
	 * other schedules — only conflicts within the same schedule are checked.
	 *
	 * @since 5.0.0
	 * @return void
	 */
	private static function migrate_schedule_isolated_column(): void {
		global $wpdb;
		$table_name = $wpdb->prefix . 'ffc_audience_schedules';

		self::add_column_if_missing(
			$table_name,
			'is_isolated',
			"tinyint(1) DEFAULT 0 COMMENT 'Ignore conflicts from other schedules'"
		);
	}

	/**
	 * Migrate schedules table to add the admin-notification columns.
	 *
	 * Adds an opt-in (default off) admin notification for audience bookings and
	 * cancellations — mirroring the self-scheduling calendars — plus a
	 * per-schedule recipient list (empty = fall back to the site admin email).
	 *
	 * @since 6.14.0
	 * @return void
	 */
	private static function migrate_schedule_admin_notification_columns(): void {
		global $wpdb;
		$table_name = $wpdb->prefix . 'ffc_audience_schedules';

		self::add_column_if_missing(
			$table_name,
			'notify_admin_on_booking',
			"tinyint(1) DEFAULT 0 COMMENT 'Notify admin on new booking'",
			'notify_on_cancellation'
		);
		self::add_column_if_missing(
			$table_name,
			'notify_admin_on_cancellation',
			"tinyint(1) DEFAULT 0 COMMENT 'Notify admin on cancellation'",
			'notify_admin_on_booking'
		);
		self::add_column_if_missing(
			$table_name,
			'admin_notification_emails',
			"text DEFAULT NULL COMMENT 'Comma-separated admin recipients (empty = site admin)'",
			'notify_admin_on_cancellation'
		);
	}

	/**
	 * Migrate schedules table to add the admin_bypass column (#1859).
	 *
	 * The per-calendar "Admin bypass" toggle, mirroring the one personal
	 * calendars keep in their config. It defaults on, so an existing calendar
	 * keeps letting administrators book outside its rules after the update.
	 *
	 * @since 6.40.0
	 * @return void
	 */
	private static function migrate_schedule_admin_bypass_column(): void {
		global $wpdb;
		$table_name = $wpdb->prefix . 'ffc_audience_schedules';

		self::add_column_if_missing(
			$table_name,
			'admin_bypass',
			"tinyint(1) DEFAULT 1 COMMENT 'Audience bypass holders may book outside the rules'"
		);
	}

	/**
	 * Migrate audiences table to add allow_self_join column
	 *
	 * When enabled, logged-in users can join/leave the audience group
	 * from their dashboard (max 2 groups per user).
	 *
	 * @since 4.9.9
	 * @return void
	 */
	private static function migrate_audience_self_join_column(): void {
		global $wpdb;
		$table_name = $wpdb->prefix . 'ffc_audiences';

		if ( ! self::table_exists( $table_name ) ) {
			return;
		}

		self::add_column_if_missing(
			$table_name,
			'allow_self_join',
			"tinyint(1) DEFAULT 0 COMMENT 'Allow users to join/leave from dashboard'"
		);
	}

	/**
	 * Heal the columns this module gained without a version bump (#1918).
	 *
	 * The `FFC_VERSION` gate above runs the chain once per release, which is
	 * right for production and wrong for the testes site: `develop` deploys
	 * there on every merge under the same `FFC_VERSION`, so a column added
	 * mid-batch would reach it only at the next release, and every write
	 * naming the column would fail until then. This gate is the module's own
	 * schema revision instead: bump {@see self::SCHEMA_REVISION} in the PR that
	 * adds a column here, and the next request on every install adds it.
	 *
	 * Tables only, never activation's side effects (CLAUDE.md "Schema-
	 * reachability guard"): `add_column_if_missing()` and nothing else. The
	 * option is written when a table is missing, because the table's `CREATE`
	 * already carries every column listed here; it is not written when an
	 * `ALTER` failed, so the next request tries again instead of the install
	 * running for good without the column.
	 *
	 * @return void
	 */
	private static function maybe_heal_columns(): void {
		if ( get_option( self::SCHEMA_REVISION_OPTION, '' ) === self::SCHEMA_REVISION ) {
			return;
		}

		global $wpdb;

		$healed = self::migrate_audience_tree_access_columns();
		$healed = self::migrate_booking_range_columns() && $healed;
		$healed = self::migrate_booking_day_times_column() && $healed;
		$healed = self::migrate_booking_attendance_columns() && $healed;
		$healed = self::migrate_post_event_form_columns() && $healed;
		$healed = self::migrate_holiday_kind_column() && $healed;
		$healed = self::migrate_schedule_appearance_names_column() && $healed;

		// The attendance tables of #1947 and the invitations of #1948 reach
		// upgraded installs here: the version-gated chain below never creates
		// a table (#1311).
		self::create_attendance_table();
		self::create_checkin_windows_table();
		self::create_form_invites_table();
		$healed = $healed
			&& self::table_exists( $wpdb->prefix . 'ffc_audience_attendance' )
			&& self::table_exists( $wpdb->prefix . 'ffc_audience_checkin_windows' )
			&& self::table_exists( $wpdb->prefix . 'ffc_audience_form_invites' );

		if ( $healed ) {
			update_option( self::SCHEMA_REVISION_OPTION, self::SCHEMA_REVISION );
		}
	}

	/**
	 * Whether a table holds every column named, after the heal ran.
	 * `add_column_if_missing()` answers `false` both for a column that was
	 * already there and for an `ALTER` that failed, so its answer cannot say
	 * whether the schema is complete; reading the columns back can.
	 *
	 * @param string   $table_name Table name.
	 * @param string[] $columns    Column names.
	 * @return bool
	 */
	private static function has_columns( string $table_name, array $columns ): bool {
		foreach ( $columns as $column ) {
			if ( ! self::column_exists( $table_name, $column ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Add the range columns to bookings (#1946).
	 *
	 * All three default to NULL, so a stored single-day booking reads exactly as
	 * before: `end_date` NULL means one day, `time_mode` and `skipped_dates` apply
	 * only to a range.
	 *
	 * @return bool Whether the table now has the columns, or does not exist yet.
	 */
	private static function migrate_booking_range_columns(): bool {
		global $wpdb;
		$table_name = $wpdb->prefix . 'ffc_audience_bookings';

		if ( ! self::table_exists( $table_name ) ) {
			return true;
		}

		self::add_column_if_missing( $table_name, 'end_date', 'date DEFAULT NULL', 'booking_date' );
		self::add_column_if_missing( $table_name, 'time_mode', "enum('daily','span') DEFAULT NULL", 'end_date' );
		self::add_column_if_missing( $table_name, 'skipped_dates', 'text DEFAULT NULL', 'time_mode' );

		return self::has_columns( $table_name, array( 'end_date', 'time_mode', 'skipped_dates' ) );
	}

	/**
	 * Add the frozen hours of a span to bookings (#2243), and freeze them on
	 * the active spans that do not end in the past.
	 *
	 * A span's middle days took the space's opening window read at every
	 * conflict check, so editing the working hours moved bookings already
	 * made. The hours each span takes today are written once, which is what
	 * those readers computed until now; past spans are left as they are,
	 * since nothing books against them any more. The walk is bounded by the
	 * active spans still to happen, a handful on any install.
	 *
	 * @return bool Whether the table now has the column, or does not exist yet.
	 */
	private static function migrate_booking_day_times_column(): bool {
		global $wpdb;
		$table_name = $wpdb->prefix . 'ffc_audience_bookings';

		if ( ! self::table_exists( $table_name ) ) {
			return true;
		}

		self::add_column_if_missing( $table_name, 'day_times', 'text DEFAULT NULL', 'skipped_dates' );
		if ( ! self::has_columns( $table_name, array( 'day_times' ) ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot migration over the plugin's own table; there is no WordPress API for it and the result is used once.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, environment_id, booking_date, end_date, time_mode, skipped_dates, day_times, start_time, end_time FROM %i
                WHERE status = 'active' AND time_mode = 'span' AND day_times IS NULL AND end_date > booking_date AND end_date >= CURDATE()",
				$table_name
			)
		);
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! is_object( $row ) ) {
				continue;
			}
			$days = array();
			foreach ( AudienceBookingRules::booked_days( (string) ( $row->booking_date ?? '' ), (string) ( $row->end_date ?? '' ), isset( $row->skipped_dates ) ? (string) $row->skipped_dates : null ) as $day ) {
				$days[] = array( 'date' => $day ) + AudienceBookingRules::stored_day_times( $row, $day );
			}
			$frozen = AudienceBookingRules::encode_day_times( $days, 'span' );
			if ( null !== $frozen ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot migration over the plugin's own table; there is no WordPress API for it.
				$wpdb->update( $table_name, array( 'day_times' => $frozen ), array( 'id' => (int) ( $row->id ?? 0 ) ), array( '%s' ), array( '%d' ) );
			}
		}

		return true;
	}

	/**
	 * Add the attendance rule to bookings (#1947).
	 *
	 * Both default to NULL, which reads as the *single* rule: one day of
	 * attendance is enough.
	 *
	 * @return bool Whether the table now has the columns, or does not exist yet.
	 */
	private static function migrate_booking_attendance_columns(): bool {
		global $wpdb;
		$table_name = $wpdb->prefix . 'ffc_audience_bookings';

		if ( ! self::table_exists( $table_name ) ) {
			return true;
		}

		self::add_column_if_missing( $table_name, 'attendance_mode', "enum('single','total') DEFAULT NULL", 'skipped_dates' );
		self::add_column_if_missing( $table_name, 'attendance_min_percent', 'tinyint(3) unsigned DEFAULT NULL', 'attendance_mode' );

		return self::has_columns( $table_name, array( 'attendance_mode', 'attendance_min_percent' ) );
	}

	/**
	 * Add a calendar's own names to schedules (#2223): the JSON override of
	 * the state names and the Show "Closed" switch, read through
	 * CalendarAppearance::clean_override(). NULL reads as no override, so an
	 * existing calendar keeps the general names.
	 *
	 * @return bool Whether the table now has the column, or does not exist yet.
	 */
	private static function migrate_schedule_appearance_names_column(): bool {
		global $wpdb;
		$table_name = $wpdb->prefix . 'ffc_audience_schedules';

		if ( ! self::table_exists( $table_name ) ) {
			return true;
		}

		self::add_column_if_missing( $table_name, 'appearance_names', 'longtext DEFAULT NULL', 'post_event_attendees_only' );

		return self::has_columns( $table_name, array( 'appearance_names' ) );
	}

	/**
	 * Add the kind to closed dates (#2202): a holiday or an optional holiday.
	 * It defaults to `holiday`, so every closed date stored before reads as
	 * it did.
	 *
	 * @return bool Whether the table now has the column, or does not exist yet.
	 */
	private static function migrate_holiday_kind_column(): bool {
		global $wpdb;
		$table_name = $wpdb->prefix . 'ffc_audience_holidays';

		if ( ! self::table_exists( $table_name ) ) {
			return true;
		}

		self::add_column_if_missing( $table_name, 'kind', "varchar(20) NOT NULL DEFAULT 'holiday'", 'description' );

		return self::has_columns( $table_name, array( 'kind' ) );
	}

	/**
	 * Add the post-event form to calendars and bookings (#1948).
	 *
	 * A calendar names the certificate form its bookings lead to, how many
	 * days it stays open after the event and whether only those present may
	 * answer. A booking follows its calendar until `post_event_custom` is set,
	 * and then reads its own three columns instead. Everything defaults to
	 * NULL or 0, which reads as no form: an existing calendar invites nobody.
	 *
	 * @return bool Whether both tables now have the columns, or do not exist yet.
	 */
	private static function migrate_post_event_form_columns(): bool {
		global $wpdb;
		$schedules = $wpdb->prefix . 'ffc_audience_schedules';
		$bookings  = $wpdb->prefix . 'ffc_audience_bookings';
		$healed    = true;

		if ( self::table_exists( $schedules ) ) {
			self::add_column_if_missing( $schedules, 'post_event_form_id', 'bigint(20) unsigned DEFAULT NULL' );
			self::add_column_if_missing( $schedules, 'post_event_window_days', 'smallint(5) unsigned DEFAULT NULL', 'post_event_form_id' );
			self::add_column_if_missing( $schedules, 'post_event_attendees_only', 'tinyint(1) DEFAULT 0', 'post_event_window_days' );
			$healed = self::has_columns( $schedules, array( 'post_event_form_id', 'post_event_window_days', 'post_event_attendees_only' ) );
		}

		if ( self::table_exists( $bookings ) ) {
			self::add_column_if_missing( $bookings, 'post_event_custom', 'tinyint(1) DEFAULT 0', 'attendance_min_percent' );
			self::add_column_if_missing( $bookings, 'post_event_form_id', 'bigint(20) unsigned DEFAULT NULL', 'post_event_custom' );
			self::add_column_if_missing( $bookings, 'post_event_window_days', 'smallint(5) unsigned DEFAULT NULL', 'post_event_form_id' );
			self::add_column_if_missing( $bookings, 'post_event_attendees_only', 'tinyint(1) DEFAULT NULL', 'post_event_window_days' );
			$healed = self::has_columns( $bookings, array( 'post_event_custom', 'post_event_form_id', 'post_event_window_days', 'post_event_attendees_only' ) ) && $healed;
		}

		return $healed;
	}

	/**
	 * Migrate audiences table to add the two tree-access flags (#1918).
	 *
	 * Both are read on a top-level audience only and cover its whole tree:
	 * `members_only` hides the tree from the dashboard's Join groups panel for
	 * users who belong to none of its groups, and `require_membership` keeps a
	 * member from leaving the last group they hold in it. Both default off, so
	 * an existing tree behaves as before.
	 *
	 * @return bool Whether the table now has the columns, or does not exist yet.
	 */
	private static function migrate_audience_tree_access_columns(): bool {
		global $wpdb;
		$table_name = $wpdb->prefix . 'ffc_audiences';

		if ( ! self::table_exists( $table_name ) ) {
			return true;
		}

		self::add_column_if_missing(
			$table_name,
			'members_only',
			"tinyint(1) DEFAULT 0 COMMENT 'Top-level: tree hidden from the Join groups panel for non-members'"
		);
		self::add_column_if_missing(
			$table_name,
			'require_membership',
			"tinyint(1) DEFAULT 0 COMMENT 'Top-level: a member cannot leave the last group of the tree'"
		);

		return self::has_columns( $table_name, array( 'members_only', 'require_membership' ) );
	}

	/**
	 * Drop all audience tables (for uninstall)
	 *
	 * @return void
	 *
	 * @deprecated 6.43.0 Nothing in the plugin calls it (#2089).
	 * @removal    6.45.0
	 */
	public static function drop_tables(): void {
		_deprecated_function( __METHOD__, '6.43.0' );

		global $wpdb;

		// Drop in reverse order of dependencies.
		$tables = array(
			$wpdb->prefix . 'ffc_audience_form_invites',
			$wpdb->prefix . 'ffc_audience_attendance',
			$wpdb->prefix . 'ffc_audience_checkin_windows',
			$wpdb->prefix . 'ffc_audience_booking_users',
			$wpdb->prefix . 'ffc_audience_booking_audiences',
			$wpdb->prefix . 'ffc_audience_bookings',
			$wpdb->prefix . 'ffc_audience_members',
			$wpdb->prefix . 'ffc_audiences',
			$wpdb->prefix . 'ffc_audience_holidays',
			$wpdb->prefix . 'ffc_audience_environments',
			$wpdb->prefix . 'ffc_audience_schedule_permissions',
			$wpdb->prefix . 'ffc_audience_schedules',
		);

		foreach ( $tables as $table ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Drops the plugin's own ffc_* tables; WordPress has no API for them and there is nothing left to cache once they are gone.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		}
	}

	/**
	 * Get table status information
	 *
	 * @return array<string, array{exists: bool, count: int}>
	 *
	 * @deprecated 6.43.0 Nothing in the plugin calls it (#2089).
	 * @removal    6.45.0
	 */
	public static function get_tables_status(): array {
		_deprecated_function( __METHOD__, '6.43.0' );

		global $wpdb;

		$tables = array(
			'schedules'         => 'ffc_audience_schedules',
			'permissions'       => 'ffc_audience_schedule_permissions',
			'environments'      => 'ffc_audience_environments',
			'holidays'          => 'ffc_audience_holidays',
			'audiences'         => 'ffc_audiences',
			'members'           => 'ffc_audience_members',
			'bookings'          => 'ffc_audience_bookings',
			'booking_audiences' => 'ffc_audience_booking_audiences',
			'booking_users'     => 'ffc_audience_booking_users',
			'attendance'        => 'ffc_audience_attendance',
			'checkin_windows'   => 'ffc_audience_checkin_windows',
			'form_invites'      => 'ffc_audience_form_invites',
		);

		$status = array();

		foreach ( $tables as $key => $suffix ) {
			$table_name = $wpdb->prefix . $suffix;

			$exists = self::table_exists( $table_name );

			$count = 0;
			if ( $exists ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Row count over the plugin's own ffc_* tables for the activation status report, which exists to show the live state.
				$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table_name ) );
			}

			$status[ $key ] = array(
				'table'  => $table_name,
				'exists' => $exists,
				'count'  => $count,
			);
		}

		return $status;
	}

	/**
	 * Add composite indexes for common query patterns.
	 *
	 * @since 4.6.2
	 */
	private static function add_composite_indexes(): void {
		global $wpdb;
		$table_name = $wpdb->prefix . 'ffc_audience_bookings';

		self::add_indexes_if_missing(
			$table_name,
			array(
				'idx_date_status'     => '(booking_date, status)',
				'idx_created_by_date' => '(created_by, booking_date)',
			)
		);
	}
}
