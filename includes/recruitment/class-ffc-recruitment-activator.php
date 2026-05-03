<?php
/**
 * Recruitment Activator
 *
 * Creates database tables for the Recruitment module — Brazilian public-tender
 * ("concurso público") candidate queue management.
 *
 * Six tables, all prefixed `{$wpdb->prefix}ffc_recruitment_`:
 *
 * - ffc_recruitment_adjutancy           Reusable subject/role definitions (subjects).
 * - ffc_recruitment_notice              The edital lifecycle (draft → preliminary → active → closed).
 * - ffc_recruitment_notice_adjutancy    N:N — which adjutancies belong to which notice.
 * - ffc_recruitment_candidate           Standalone candidate list (linked to wp_users on promotion).
 * - ffc_recruitment_classification      Candidate standing per (adjutancy, notice, list_type).
 * - ffc_recruitment_call                Append-only convocation events (with cancellation columns).
 *
 * All tables use ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 — InnoDB is required for
 * the atomic transactional CSV import (preserves the previous list on validation
 * failure via ROLLBACK), and utf8mb4 ensures full Unicode coverage on free-form
 * fields (accented names, emoji in notes).
 *
 * No DB-level FOREIGN KEY constraints are declared: cross-table integrity is
 * enforced at the repository/state-machine layer (matches the existing plugin
 * convention).
 *
 * All DATETIME columns are written via `current_time( 'mysql' )` (site
 * timezone, matching the rest of the plugin); display likewise uses the WP
 * site timezone (`wp_timezone()`).
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.0.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Recruitment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange
/**
 * Plugin activation tasks for the Recruitment module.
 *
 * Public entry-point: {@see self::create_tables()} — invoked from the main
 * plugin Activator on activation. Idempotent: each table is gated by an
 * existence check before issuing CREATE TABLE.
 */
class RecruitmentActivator {

	use \FreeFormCertificate\Core\DatabaseHelperTrait;

	/**
	 * Create all Recruitment-related tables.
	 *
	 * Called from \FreeFormCertificate\Activator::activate() during plugin
	 * activation. Each individual create method is idempotent (skips when the
	 * table already exists), so this method is safe to call repeatedly across
	 * activation cycles.
	 *
	 * @return void
	 */
	public static function create_tables(): void {
		self::create_adjutancy_table();
		self::create_notice_table();
		self::create_notice_adjutancy_table();
		self::create_candidate_table();
		self::create_classification_table();
		self::create_call_table();
		self::create_reason_table();
	}

	/**
	 * Run idempotent post-create migrations.
	 *
	 * Tracked via the `ffc_recruitment_schema_version` option so each
	 * migration step runs exactly once across activation cycles. Hooked
	 * from {@see RecruitmentLoader::init()} on `plugins_loaded` so it
	 * applies even when the plugin was upgraded in place (no fresh
	 * `register_activation_hook` callback firing).
	 *
	 * Steps:
	 *
	 * - v2 (6.1.0): rename status enum value `active` → `definitive` on
	 *   `ffc_recruitment_notice`. v2 originally targeted `final` — that
	 *   intermediate name has been retired in favor of `definitive`
	 *   (which lines up with `classification.list_type='definitive'`).
	 *   Installs that already ran v2 with the old target value get
	 *   migrated by v3 below.
	 * - v3 (6.1.0): rename status enum value `final` → `definitive` for
	 *   the cohort of installs that ran the original v2 (which produced
	 *   `final`). Idempotent: skipped on installs that never had `final`
	 *   in the enum because they're already on the v3-canonical state.
	 *
	 * @return void
	 */
	public static function maybe_migrate(): void {
		$option_key = 'ffc_recruitment_schema_version';
		$current    = (int) get_option( $option_key, 0 );

		if ( $current < 2 ) {
			self::migrate_status_active_to_definitive();
			update_option( $option_key, 2 );
		}

		if ( $current < 3 ) {
			self::migrate_status_final_to_definitive();
			update_option( $option_key, 3 );
		}

		if ( $current < 4 ) {
			self::migrate_add_adjutancy_color();
			update_option( $option_key, 4 );
		}

		if ( $current < 5 ) {
			self::migrate_add_classification_preview_columns();
			self::create_reason_table();
			update_option( $option_key, 5 );
		}

		if ( $current < 6 ) {
			self::migrate_add_classification_csv_extension_columns();
			update_option( $option_key, 6 );
		}
	}

	/**
	 * V6 schema migration — add `time_points` (DECIMAL) and `hab_emebs`
	 * (TINYINT) columns to `ffc_recruitment_classification` so the CSV
	 * importer has somewhere to land the optional new fields.
	 *
	 * Both columns default to 0 / 0 so existing rows stay valid; CSVs
	 * that omit the new headers populate the defaults at import time.
	 *
	 * Idempotent via the SHOW COLUMNS guard.
	 *
	 * @return void
	 */
	private static function migrate_add_classification_csv_extension_columns(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'ffc_recruitment_classification';

		if ( ! self::table_exists( $table ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema migration; $table is built from $wpdb->prefix.
		$has_time_points = $wpdb->get_var( "SHOW COLUMNS FROM `{$table}` LIKE 'time_points'" );
		if ( null === $has_time_points || '' === (string) $has_time_points ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema migration.
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN time_points DECIMAL(10,4) NOT NULL DEFAULT 0 AFTER score" );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema migration.
		$has_hab_emebs = $wpdb->get_var( "SHOW COLUMNS FROM `{$table}` LIKE 'hab_emebs'" );
		if ( null === $has_hab_emebs || '' === (string) $has_hab_emebs ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema migration.
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN hab_emebs TINYINT(1) NOT NULL DEFAULT 0 AFTER time_points" );
		}
	}

	/**
	 * V5 schema migration — add `preview_status` enum + `preview_reason_id`
	 * FK columns to `ffc_recruitment_classification`. The columns are
	 * read only when `list_type='preview'` (the §5.2 state machine on
	 * the definitive list still owns the `status` column); they exist on
	 * every row anyway because adding NULL/DEFAULT-bearing columns is
	 * cheaper than partitioning the table by list_type.
	 *
	 * Visual-only: the enum values do not feed into the state machine.
	 *
	 * @return void
	 */
	private static function migrate_add_classification_preview_columns(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'ffc_recruitment_classification';

		if ( ! self::table_exists( $table ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema migration; $table is built from $wpdb->prefix.
		$existing = $wpdb->get_var( "SHOW COLUMNS FROM `{$table}` LIKE 'preview_status'" );
		if ( null === $existing || '' === (string) $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema migration.
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN preview_status ENUM('empty','denied','granted','appeal_denied','appeal_granted') NOT NULL DEFAULT 'empty' AFTER status" );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema migration.
		$existing_reason = $wpdb->get_var( "SHOW COLUMNS FROM `{$table}` LIKE 'preview_reason_id'" );
		if ( null === $existing_reason || '' === (string) $existing_reason ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema migration.
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN preview_reason_id BIGINT(20) UNSIGNED DEFAULT NULL AFTER preview_status" );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema migration.
			$wpdb->query( "ALTER TABLE `{$table}` ADD INDEX idx_preview_reason_id (preview_reason_id)" );
		}
	}

	/**
	 * V4 schema migration — add `color` column to `ffc_recruitment_adjutancy`.
	 *
	 * Holds a per-adjutancy badge background color rendered by the public
	 * shortcode (mirrors the existing per-status color knobs in the
	 * Settings tab). VARCHAR(9) is wide enough for `#RRGGBBAA`.
	 *
	 * @return void
	 */
	private static function migrate_add_adjutancy_color(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'ffc_recruitment_adjutancy';

		if ( ! self::table_exists( $table ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema migration; $table is built from $wpdb->prefix.
		$existing = $wpdb->get_var( "SHOW COLUMNS FROM `{$table}` LIKE 'color'" );
		if ( null !== $existing && '' !== (string) $existing ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema migration.
		$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN color VARCHAR(9) NOT NULL DEFAULT '#e9ecef' AFTER name" );
	}

	/**
	 * V2 schema migration — rename `active` → `definitive` in the notice
	 * status enum (target updated from the original `final`).
	 *
	 * Three-step ALTER (widen enum, update rows, narrow enum) so the
	 * intermediate state is always consistent with whichever enum
	 * definition the table currently advertises.
	 *
	 * @return void
	 */
	private static function migrate_status_active_to_definitive(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'ffc_recruitment_notice';

		if ( ! self::table_exists( $table ) ) {
			return;
		}

		// Step 1: widen the enum to include both legacy and new values
		// so the UPDATE in step 2 doesn't violate any enum constraint.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema migration; $table is built from $wpdb->prefix.
		$wpdb->query( "ALTER TABLE `{$table}` MODIFY status ENUM('draft','preliminary','active','definitive','closed') NOT NULL DEFAULT 'draft'" );

		// Step 2: rename existing rows.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema migration.
		$wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET status = %s WHERE status = %s", 'definitive', 'active' ) );

		// Step 3: narrow the enum back to the canonical 6.1.0 set.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema migration.
		$wpdb->query( "ALTER TABLE `{$table}` MODIFY status ENUM('draft','preliminary','definitive','closed') NOT NULL DEFAULT 'draft'" );
	}

	/**
	 * V3 schema migration — rename `final` → `definitive` for installs
	 * that ran the original v2 (which produced `final`).
	 *
	 * Idempotent: when the enum already lacks `final`, the ALTER step 1
	 * widens it back temporarily, the UPDATE catches zero rows, and the
	 * narrow step 3 restores the canonical state. Cheap on installs that
	 * never had `final` to begin with.
	 *
	 * @return void
	 */
	private static function migrate_status_final_to_definitive(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'ffc_recruitment_notice';

		if ( ! self::table_exists( $table ) ) {
			return;
		}

		// Step 1: widen the enum to include both legacy `final` and the
		// new `definitive` so the UPDATE doesn't choke.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema migration.
		$wpdb->query( "ALTER TABLE `{$table}` MODIFY status ENUM('draft','preliminary','final','definitive','closed') NOT NULL DEFAULT 'draft'" );

		// Step 2: flip every `final` row to `definitive`.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema migration.
		$wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET status = %s WHERE status = %s", 'definitive', 'final' ) );

		// Step 3: narrow the enum back to the canonical state.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema migration.
		$wpdb->query( "ALTER TABLE `{$table}` MODIFY status ENUM('draft','preliminary','definitive','closed') NOT NULL DEFAULT 'draft'" );
	}

	/**
	 * Create `ffc_recruitment_adjutancy` table.
	 *
	 * Reusable subject/role definitions ("subjects"). One row per adjutancy,
	 * shared across notices via the junction table.
	 *
	 * @return void
	 */
	private static function create_adjutancy_table(): void {
		global $wpdb;
		$table_name      = $wpdb->prefix . 'ffc_recruitment_adjutancy';
		$charset_collate = $wpdb->get_charset_collate();

		if ( self::table_exists( $table_name ) ) {
			return;
		}

		$sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            slug varchar(64) NOT NULL,
            name varchar(255) NOT NULL,
            color varchar(9) NOT NULL DEFAULT '#e9ecef',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_slug (slug)
        ) ENGINE=InnoDB {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Create `ffc_recruitment_notice` table.
	 *
	 * The edital. Lifecycle is governed by the `status` column
	 * (draft → preliminary → active → closed). `was_reopened` flips to 1 on
	 * the first closed → active transition and drives the freeze rule for
	 * `hired`/`not_shown` classifications. `public_columns_config` (JSON) is
	 * the per-notice column-visibility config for the public shortcode.
	 *
	 * @return void
	 */
	private static function create_notice_table(): void {
		global $wpdb;
		$table_name      = $wpdb->prefix . 'ffc_recruitment_notice';
		$charset_collate = $wpdb->get_charset_collate();

		if ( self::table_exists( $table_name ) ) {
			return;
		}

		$sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            code varchar(64) NOT NULL,
            name varchar(255) NOT NULL,
            status enum('draft','preliminary','definitive','closed') NOT NULL DEFAULT 'draft',
            opened_at datetime DEFAULT NULL,
            closed_at datetime DEFAULT NULL,
            was_reopened tinyint(1) NOT NULL DEFAULT 0,
            public_columns_config longtext NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_code (code),
            KEY idx_status (status)
        ) ENGINE=InnoDB {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Create `ffc_recruitment_notice_adjutancy` junction table.
	 *
	 * N:N between notices and adjutancies — declares which adjutancies belong
	 * to a given notice. PK is the composite `(notice_id, adjutancy_id)`; a
	 * reverse-lookup index on `adjutancy_id` covers "which notices include
	 * this adjutancy" queries.
	 *
	 * @return void
	 */
	private static function create_notice_adjutancy_table(): void {
		global $wpdb;
		$table_name      = $wpdb->prefix . 'ffc_recruitment_notice_adjutancy';
		$charset_collate = $wpdb->get_charset_collate();

		if ( self::table_exists( $table_name ) ) {
			return;
		}

		$sql = "CREATE TABLE {$table_name} (
            notice_id bigint(20) unsigned NOT NULL,
            adjutancy_id bigint(20) unsigned NOT NULL,
            PRIMARY KEY (notice_id, adjutancy_id),
            KEY idx_adjutancy_id (adjutancy_id)
        ) ENGINE=InnoDB {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Create `ffc_recruitment_candidate` table.
	 *
	 * Standalone candidate list (no wp_users link until promotion via the
	 * three §4 triggers). Encrypted columns follow the existing plugin
	 * convention: `*_encrypted` is TEXT, `*_hash` is VARCHAR(64). The hash
	 * columns are computed via the existing Encryption helper so cross-table
	 * matches against `ffc_submissions.cpf_hash` / `rf_hash` work (which is
	 * how §4 trigger 2 looks up an existing wp_user).
	 *
	 * `pcd_hash` is NOT NULL with both domains: HMAC(salt, "1"||id) for PCD,
	 * HMAC(salt, "0"||id) for non-PCD. This keeps the value verifiable
	 * (recompute both candidate domains and compare) without being
	 * enumerable on column scan.
	 *
	 * UNIQUE on `cpf_hash` and on `rf_hash` (each separately) prevents
	 * duplicate candidate rows for the same CPF or RF — a candidate
	 * classified in multiple notices/adjutancies reuses the same row via
	 * additional rows in `ffc_recruitment_classification`.
	 *
	 * @return void
	 */
	private static function create_candidate_table(): void {
		global $wpdb;
		$table_name      = $wpdb->prefix . 'ffc_recruitment_candidate';
		$charset_collate = $wpdb->get_charset_collate();

		if ( self::table_exists( $table_name ) ) {
			return;
		}

		$sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned DEFAULT NULL,
            name varchar(255) NOT NULL,
            cpf_encrypted text DEFAULT NULL,
            cpf_hash varchar(64) DEFAULT NULL,
            rf_encrypted text DEFAULT NULL,
            rf_hash varchar(64) DEFAULT NULL,
            email_encrypted text DEFAULT NULL,
            email_hash varchar(64) DEFAULT NULL,
            phone varchar(32) DEFAULT NULL,
            notes text DEFAULT NULL,
            pcd_hash varchar(64) NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_cpf_hash (cpf_hash),
            UNIQUE KEY uq_rf_hash (rf_hash),
            KEY idx_email_hash (email_hash),
            KEY idx_user_id (user_id)
        ) ENGINE=InnoDB {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Create `ffc_recruitment_classification` table.
	 *
	 * The candidate's standing per (adjutancy, notice, list_type). `list_type`
	 * discriminates between `preview` (preliminary list) and `definitive`
	 * (definitive list); convocation acts only on `definitive` per the §5.2
	 * invariant.
	 *
	 * Composite index `(notice_id, adjutancy_id, list_type, status, rank)`
	 * covers the hot-path "lowest-rank empty" query used by the in-order
	 * call check and by ranking displays — `status` is placed before `rank`
	 * so the index serves filtered scans efficiently.
	 *
	 * @return void
	 */
	private static function create_classification_table(): void {
		global $wpdb;
		$table_name      = $wpdb->prefix . 'ffc_recruitment_classification';
		$charset_collate = $wpdb->get_charset_collate();

		if ( self::table_exists( $table_name ) ) {
			return;
		}

		$sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            candidate_id bigint(20) unsigned NOT NULL,
            adjutancy_id bigint(20) unsigned NOT NULL,
            notice_id bigint(20) unsigned NOT NULL,
            list_type enum('preview','definitive') NOT NULL,
            `rank` int unsigned NOT NULL,
            score decimal(10,4) NOT NULL,
            time_points decimal(10,4) NOT NULL DEFAULT 0,
            hab_emebs tinyint(1) NOT NULL DEFAULT 0,
            status enum('empty','called','accepted','not_shown','hired') NOT NULL DEFAULT 'empty',
            preview_status enum('empty','denied','granted','appeal_denied','appeal_granted') NOT NULL DEFAULT 'empty',
            preview_reason_id bigint(20) unsigned DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_candidate_adjutancy_notice_list (candidate_id, adjutancy_id, notice_id, list_type),
            KEY idx_notice_adjutancy_list_status_rank (notice_id, adjutancy_id, list_type, status, `rank`),
            KEY idx_candidate_id (candidate_id),
            KEY idx_preview_reason_id (preview_reason_id)
        ) ENGINE=InnoDB {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Create `ffc_recruitment_call` table.
	 *
	 * A convocation event. Append-only history: cancellation does not delete
	 * the row; it sets `cancellation_reason`, `cancelled_at`, `cancelled_by`
	 * on the existing row. A subsequent re-call for the same classification
	 * creates a new row.
	 *
	 * The composite index `(classification_id, cancelled_at)` covers both
	 * "active call for classification" lookups (`WHERE classification_id=?
	 * AND cancelled_at IS NULL`) and "all calls history of classification"
	 * listings used by GET /me/recruitment.
	 *
	 * The invariant "out_of_order=1 implies out_of_order_reason is non-empty"
	 * is enforced at the application/repository layer (not as a DB CHECK).
	 *
	 * @return void
	 */
	private static function create_call_table(): void {
		global $wpdb;
		$table_name      = $wpdb->prefix . 'ffc_recruitment_call';
		$charset_collate = $wpdb->get_charset_collate();

		if ( self::table_exists( $table_name ) ) {
			return;
		}

		$sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            classification_id bigint(20) unsigned NOT NULL,
            called_at datetime NOT NULL,
            date_to_assume date NOT NULL,
            time_to_assume time NOT NULL,
            out_of_order tinyint(1) NOT NULL DEFAULT 0,
            out_of_order_reason text DEFAULT NULL,
            cancellation_reason text DEFAULT NULL,
            cancelled_at datetime DEFAULT NULL,
            cancelled_by bigint(20) unsigned DEFAULT NULL,
            notes text DEFAULT NULL,
            created_by bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY idx_classification_cancelled (classification_id, cancelled_at)
        ) ENGINE=InnoDB {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Create `ffc_recruitment_reason` table.
	 *
	 * Global catalog of operator-defined "reasons" — labels operators
	 * attach to a preliminary-list classification when setting its
	 * `preview_status`. Like adjutancies in shape but UNLIKE adjutancies
	 * in scope: reasons are reusable across every notice without an
	 * attach junction. The catalog stays small enough that loading the
	 * full set in PHP is acceptable.
	 *
	 * `applies_to` is an empty-or-CSV list of preview_status enum values
	 * (`denied,granted,appeal_denied,appeal_granted`). Empty string means
	 * "applies to every preview status" (the operator left it
	 * unconstrained); a non-empty list narrows the dropdown when the
	 * admin picks a status.
	 *
	 * `color` mirrors the adjutancy `color` shape (#RGB / #RRGGBB /
	 * #RRGGBBAA, lowercase). Renders as a small dot/swatch beside the
	 * reason in the dropdown so operators can spot the right one fast.
	 *
	 * @return void
	 */
	private static function create_reason_table(): void {
		global $wpdb;
		$table_name      = $wpdb->prefix . 'ffc_recruitment_reason';
		$charset_collate = $wpdb->get_charset_collate();

		if ( self::table_exists( $table_name ) ) {
			return;
		}

		$sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            slug varchar(64) NOT NULL,
            label varchar(255) NOT NULL,
            color varchar(9) NOT NULL DEFAULT '#e9ecef',
            applies_to varchar(255) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_slug (slug)
        ) ENGINE=InnoDB {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}
