<?php
/**
 * Recruitment Activator
 *
 * Creates database tables for the Recruitment module — Brazilian public-tender
 * ("concurso público") candidate queue management.
 *
 * Six tables, all prefixed `{$wpdb->prefix}ffc_recruitment_`:
 *
 * - ffc_recruitment_adjutancy           Reusable subject/role definitions (matérias).
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
	}

	/**
	 * Create `ffc_recruitment_adjutancy` table.
	 *
	 * Reusable subject/role definitions ("matérias"). One row per adjutancy,
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
            code varchar(64) NOT NULL COMMENT 'Normalized to UPPERCASE on storage',
            name varchar(255) NOT NULL,
            status enum('draft','preliminary','active','closed') NOT NULL DEFAULT 'draft',
            opened_at datetime DEFAULT NULL COMMENT 'Site TZ; set on first transition to active, never modified afterwards',
            closed_at datetime DEFAULT NULL COMMENT 'Site TZ; overwritten on each transition to closed',
            was_reopened tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Flips to 1 on first closed -> active; drives reopen-freeze',
            public_columns_config longtext NOT NULL COMMENT 'JSON: per-notice column visibility for public shortcode',
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
            user_id bigint(20) unsigned DEFAULT NULL COMMENT 'wp_users.ID set on promotion (logical FK)',
            name varchar(255) NOT NULL COMMENT 'Plain — classified list is public by law',
            cpf_encrypted text DEFAULT NULL,
            cpf_hash varchar(64) DEFAULT NULL,
            rf_encrypted text DEFAULT NULL,
            rf_hash varchar(64) DEFAULT NULL,
            email_encrypted text DEFAULT NULL,
            email_hash varchar(64) DEFAULT NULL,
            phone varchar(32) DEFAULT NULL COMMENT 'Plain — admin-editable, low sensitivity',
            notes text DEFAULT NULL,
            pcd_hash varchar(64) NOT NULL COMMENT 'HMAC(salt, (1|0)||id); both domains so non-PCD is also verifiable',
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
	 * (final list); convocation acts only on `definitive` per the §5.2
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
            `rank` int unsigned NOT NULL COMMENT 'Ties allowed; tie-break is (rank ASC, candidate_id ASC)',
            score decimal(10,4) NOT NULL,
            status enum('empty','called','accepted','not_shown','hired') NOT NULL DEFAULT 'empty',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_candidate_adjutancy_notice_list (candidate_id, adjutancy_id, notice_id, list_type),
            KEY idx_notice_adjutancy_list_status_rank (notice_id, adjutancy_id, list_type, status, `rank`),
            KEY idx_candidate_id (candidate_id)
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
            called_at datetime NOT NULL COMMENT 'Site TZ',
            date_to_assume date NOT NULL,
            time_to_assume time NOT NULL,
            out_of_order tinyint(1) NOT NULL DEFAULT 0,
            out_of_order_reason text DEFAULT NULL COMMENT 'Required when out_of_order=1 (app-layer invariant)',
            cancellation_reason text DEFAULT NULL,
            cancelled_at datetime DEFAULT NULL COMMENT 'Site TZ; set on cancel, never cleared (audit trail)',
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
}
