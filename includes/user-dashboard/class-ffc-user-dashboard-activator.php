<?php
/**
 * User Dashboard Activator
 *
 * Owns the user-dashboard module's activation work, extracted from the
 * monolithic {@see \FreeFormCertificate\Activator} (#563 Sprint 7, A5): the
 * base `ffc_end_user` role + admin capability grant, the user-profiles and
 * custom-fields tables, and the front-end dashboard page.
 *
 * @package FreeFormCertificate\UserDashboard
 * @since   6.12.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\UserDashboard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Installs the user-dashboard role, tables, and page on plugin activation.
 */
class UserDashboardActivator {

	/**
	 * Run all user-dashboard activation steps.
	 *
	 * Called during plugin activation. Order matches the previous inline
	 * sequence in Activator::activate(): role → dashboard page → profiles
	 * table → custom-fields table.
	 *
	 * @return void
	 */
	public static function create_tables(): void {
		self::register_user_role();
		self::create_dashboard_page();
		self::create_user_profiles_table();
		self::create_custom_fields_table();
	}

	/**
	 * Heal the user-dashboard SCHEMA on an install that was never re-activated.
	 *
	 * Same gap as `ReregistrationActivator::maybe_migrate()` (#1311):
	 * `create_tables()` had exactly one caller, `Activator::activate()`, which
	 * runs on plugin ACTIVATION -- and a WordPress plugin update does not
	 * activate. So a table or column added here would have reached a fresh
	 * install and no upgraded one. Nothing is broken today; this is the same
	 * shape as the defect #1311 found next door, caught while it was still
	 * latent.
	 *
	 * **It deliberately does NOT call `create_tables()`, and that is the whole
	 * point of this method existing separately.** Healing the schema is not
	 * re-running activation: two of those four steps are one-shot SETUP that
	 * must not repeat.
	 *
	 * - `create_dashboard_page()` looks the page up by the `dashboard` slug and
	 *   inserts one when it finds none. On activation that is the intent. Run
	 *   again after every release it would RESURRECT a page the administrator
	 *   deleted or renamed on purpose -- a behaviour change nobody asked for,
	 *   silently, on an upgrade.
	 * - `register_user_role()` is role lifecycle, which `CLAUDE.md` ("Module
	 *   bootstrap") assigns to the orchestrator; `Loader::register_ffc_roles_safe()`
	 *   already owns it, and doing it twice from two places is how the two
	 *   drift.
	 *
	 * What is left is the two table creations, both of which return early on an
	 * existing table, so the chain is a no-op on an install that is current.
	 *
	 * The `FFC_VERSION` gate and the write-after-body ordering carry the same
	 * reasoning as the sibling method; see it for why a one-shot boolean is the
	 * wrong marker (#1231).
	 *
	 * @since 6.26.0
	 * @return void
	 */
	public static function maybe_migrate(): void {
		$ffc_schema_option = 'ffc_user_dashboard_schema_version';
		if ( get_option( $ffc_schema_option, '' ) === FFC_VERSION ) {
			return;
		}

		self::create_user_profiles_table();
		self::create_custom_fields_table();

		update_option( $ffc_schema_option, FFC_VERSION );
	}

	/**
	 * Register the ffc_end_user role and grant admin-level FFC caps to the
	 * administrator role.
	 */
	private static function register_user_role(): void {
		// Load User Manager if not already loaded.
		if ( ! class_exists( '\FreeFormCertificate\UserDashboard\UserManager' ) ) {
			$user_manager_file = FFC_PLUGIN_DIR . 'includes/user-dashboard/class-ffc-user-manager.php';
			if ( file_exists( $user_manager_file ) ) {
				require_once $user_manager_file;
			}
		}

		if ( class_exists( '\FreeFormCertificate\UserDashboard\UserManager' ) ) {
			\FreeFormCertificate\UserDashboard\RoleRegistrar::register_role();

			// Grant admin-level FFC capabilities to the administrator role.
			$admin_role = get_role( 'administrator' );
			if ( $admin_role ) {
				foreach ( \FreeFormCertificate\UserDashboard\CapabilityManager::ADMIN_CAPABILITIES as $cap ) {
					$admin_role->add_cap( $cap, true );
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
		$table_name      = $wpdb->prefix . 'ffc_user_profiles';
		$charset_collate = $wpdb->get_charset_collate();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema probe on an activation/migration path. The answer must reflect the live schema, so caching it is exactly what would be wrong, and WordPress exposes no API for it.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name ) {
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
            preferences longtext DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_user_id (user_id)
        ) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
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
		$table_name      = $wpdb->prefix . 'ffc_custom_fields';
		$charset_collate = $wpdb->get_charset_collate();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema probe on an activation/migration path. The answer must reflect the live schema, so caching it is exactly what would be wrong, and WordPress exposes no API for it.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name ) {
			return;
		}

		$sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            audience_id bigint(20) unsigned NOT NULL,
            field_key varchar(100) NOT NULL,
            field_label varchar(250) NOT NULL,
            field_type varchar(50) NOT NULL DEFAULT 'text',
            field_group varchar(100) NOT NULL DEFAULT '',
            field_source varchar(20) NOT NULL DEFAULT 'custom',
            field_profile_key varchar(100) DEFAULT NULL,
            field_mask varchar(50) DEFAULT NULL,
            is_sensitive tinyint(1) NOT NULL DEFAULT 0,
            field_options longtext DEFAULT NULL,
            validation_rules longtext DEFAULT NULL,
            sort_order int(11) NOT NULL DEFAULT 0,
            is_required tinyint(1) NOT NULL DEFAULT 0,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_audience_id (audience_id),
            KEY idx_field_key (field_key),
            KEY idx_sort_order (audience_id, sort_order),
            KEY idx_group_sort (audience_id, field_group, sort_order),
            KEY idx_source (audience_id, field_source)
        ) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Create the front-end "My Dashboard" page hosting the personal
	 * dashboard shortcode.
	 */
	private static function create_dashboard_page(): void {
		$existing_page = get_page_by_path( 'dashboard' );

		if ( $existing_page ) {
			update_option( 'ffc_dashboard_page_id', $existing_page->ID );
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
			'ping_status'    => 'closed',
		);

		$page_id = wp_insert_post( $page_data, true );

		if ( ! is_wp_error( $page_id ) ) {
			update_option( 'ffc_dashboard_page_id', $page_id );
			update_post_meta( $page_id, '_ffc_managed_page', '1' );
		}
	}
}
