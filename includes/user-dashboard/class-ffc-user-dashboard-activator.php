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

use FreeFormCertificate\Core\DatabaseHelperTrait;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Installs the user-dashboard role, tables, and page on plugin activation.
 */
class UserDashboardActivator {

	use DatabaseHelperTrait;

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
		self::add_user_profiles_columns();
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
	 * existing table, plus the two steps #1313 added for the identity index:
	 * `add_user_profiles_columns()`, which the creations cannot cover because
	 * they return early on exactly the installs that need a new column, and
	 * `backfill_identity_index()`. Both are idempotent, so the chain is still a
	 * no-op on an install that is current -- it costs four indexed statements
	 * once per release, not per request.
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
		self::add_user_profiles_columns();
		self::backfill_identity_index();
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
            cpf_hash varchar(64) DEFAULT NULL,
            rf_hash varchar(64) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_user_id (user_id),
            KEY idx_cpf_hash (cpf_hash),
            KEY idx_rf_hash (rf_hash)
        ) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Add the identity-index columns to an EXISTING ffc_user_profiles (#1313).
	 *
	 * `create_user_profiles_table()` returns early when the table is there,
	 * which is precisely every install that needs these columns -- so the
	 * `CREATE` covers fresh installs and this covers the rest. The two must
	 * name the same columns; `SchemaAgreementTest` is what enforces that, and
	 * the index names have to match too or an upgraded install ends up
	 * carrying two indexes on one column under different names (the shape
	 * #1093 left on `auth_code`).
	 *
	 * @since 6.26.0
	 * @return void
	 */
	private static function add_user_profiles_columns(): void {
		global $wpdb;
		$table_name = $wpdb->prefix . 'ffc_user_profiles';

		if ( ! self::table_exists( $table_name ) ) {
			return;
		}

		self::add_columns_if_missing(
			$table_name,
			array(
				'cpf_hash' => array(
					'type'  => 'VARCHAR(64) DEFAULT NULL',
					'after' => 'preferences',
					'index' => 'cpf_hash',
				),
				'rf_hash'  => array(
					'type'  => 'VARCHAR(64) DEFAULT NULL',
					'after' => 'cpf_hash',
					'index' => 'rf_hash',
				),
			)
		);
	}

	/**
	 * Move the identity hashes out of wp_usermeta and into their columns.
	 *
	 * WHAT IT COPIES, AND WHAT IT DOES NOT FIX
	 *
	 * It copies the stored hash EXACTLY as it stands, including a hash written
	 * before #1313 PR 1 normalised the input -- i.e. possibly the hash of a
	 * masked `123.456.789-09` rather than of the digits. That is deliberate:
	 * this step moves an index, it does not correct it, and correcting it means
	 * decrypting the ciphertext, which is the migration card of PR 3. Copying
	 * the value as it is keeps behaviour identical across the move; leaving the
	 * column NULL instead would make it a regression until an operator runs a
	 * card that may sit unrun for months.
	 *
	 * WHY IT IS SET OPERATIONS AND NOT A LOOP
	 *
	 * This runs on an ordinary admin request, once per release. A PHP loop over
	 * users is unbounded there -- an install with 50k users is 100k meta rows
	 * through the meta API. Four statements let the server bound the work, and
	 * each is idempotent: the UPDATE only fills a column that is still NULL,
	 * and the INSERT only reaches users with no profile row at all. A second
	 * run therefore touches nothing.
	 *
	 * `MAX()` over a GROUP BY rather than a bare column: wp_usermeta does not
	 * constrain (user_id, meta_key) to be unique, and a bare column under
	 * ONLY_FULL_GROUP_BY is rejected outright.
	 *
	 * @since 6.26.0
	 * @return void
	 */
	private static function backfill_identity_index(): void {
		global $wpdb;
		$table_name = $wpdb->prefix . 'ffc_user_profiles';

		if ( ! self::table_exists( $table_name ) ) {
			return;
		}

		foreach ( UserProfileFieldMap::sensitive_field_keys() as $field_key ) {
			$column   = UserProfileFieldMap::hash_column( $field_key );
			$meta_key = UserProfileFieldMap::legacy_hash_meta_key( $field_key );

			if ( null === $column || null === $meta_key ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot schema backfill on the plugin's own table; WordPress has no API for a cross-table UPDATE, and a cached answer is exactly what a migration must not take.
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE %i AS p INNER JOIN %i AS m ON m.user_id = p.user_id AND m.meta_key = %s SET p.%i = m.meta_value WHERE p.%i IS NULL AND m.meta_value <> %s',
					$table_name,
					$wpdb->usermeta,
					$meta_key,
					$column,
					$column,
					''
				)
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- As above: the users who carry the meta but have no profile row yet.
			$wpdb->query(
				$wpdb->prepare(
					'INSERT INTO %i (user_id, %i) SELECT m.user_id, MAX(m.meta_value) FROM %i AS m LEFT JOIN %i AS p ON p.user_id = m.user_id WHERE m.meta_key = %s AND m.meta_value <> %s AND p.user_id IS NULL GROUP BY m.user_id',
					$table_name,
					$column,
					$wpdb->usermeta,
					$table_name,
					$meta_key,
					''
				)
			);
		}
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
