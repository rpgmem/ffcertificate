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
 * @since   6.11.3
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

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
            preferences json DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_user_id (user_id)
        ) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
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

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name ) {
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
