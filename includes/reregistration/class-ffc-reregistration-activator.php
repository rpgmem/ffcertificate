<?php
/**
 * Reregistration Activator
 *
 * Owns the reregistration module's schema, extracted from the monolithic
 * {@see \FreeFormCertificate\Activator} (#563 Sprint 7, A5). Each module owns
 * its own `create_tables()` installer; the core Activator orchestrates them.
 *
 * @package FreeFormCertificate\Reregistration
 * @since   6.12.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Reregistration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Schema and data statements against the plugin's own ffc_* tables during activation/migration. WordPress exposes no API for them, and there is nothing to cache on this path.
/**
 * Creates + migrates the reregistration tables on plugin activation.
 */
class ReregistrationActivator {

	use \FreeFormCertificate\Core\DatabaseHelperTrait;

	/**
	 * Create all reregistration-related tables (and run their migrations).
	 *
	 * Called during plugin activation. Order matches the previous inline
	 * sequence in Activator::activate(): campaigns → audiences junction →
	 * submissions → submission columns back-fill → audience junction migration,
	 * then the two CSV-import tables (#1214), which depend on nothing above and
	 * are created last for that reason.
	 *
	 * @return void
	 */
	public static function create_tables(): void {
		self::create_reregistrations_table();
		self::create_reregistration_audiences_table();
		self::create_reregistration_submissions_table();
		self::add_reregistration_submissions_columns();
		self::add_reregistrations_columns();
		self::migrate_reregistration_audience_to_junction();
		self::drop_superseded_indexes();
		self::create_import_jobs_table();
		self::create_import_staging_table();
	}

	/**
	 * Heal the reregistration schema on an install that was never re-activated.
	 *
	 * **`create_tables()` alone is not enough, and #1311 is what that costs.**
	 * Its only caller is `Activator::activate()`, which runs on plugin
	 * ACTIVATION -- and neither a WordPress plugin update nor the rsync deploy
	 * to the testes host activates anything. So the two CSV-import tables added
	 * in #1214 existed on a fresh install and on no upgraded one, which made the
	 * import write into tables that were not there. The post-deploy smoke said
	 * so on twelve consecutive deploys before anybody read it; the `fresh-install`
	 * job stayed green the whole time, correctly, because it performs a real
	 * activation -- the one path that did create them.
	 *
	 * Four sibling modules already had this method for the same reason. This
	 * module and `UserDashboardActivator` were the two that did not.
	 *
	 * **Why the whole chain is safe to re-run**, which is what lets this call
	 * `create_tables()` rather than a hand-picked subset: every `create_*_table()`
	 * returns early on `table_exists()`, both `add_*_columns()` are
	 * `add_column_if_missing`, `drop_superseded_indexes()` returns on a missing
	 * table and only drops an index whose canonical replacement it has already
	 * seen, and `migrate_reregistration_audience_to_junction()` returns the
	 * moment the column it moves is gone. `UserDashboardActivator::maybe_migrate()`
	 * deliberately does NOT do this -- see the reason written there.
	 *
	 * **The gate is `FFC_VERSION`, not a one-shot boolean** (#1231): the property
	 * to preserve is "the schema heals itself after an update", not "it runs on
	 * every request". A boolean marker would mean a column introduced in a future
	 * release never reaches whoever already stored it. The option is written
	 * AFTER the body, so a failure partway through does not lock the chain at a
	 * version it never finished applying.
	 *
	 * @since 6.26.0
	 * @return void
	 */
	public static function maybe_migrate(): void {
		$ffc_schema_option = 'ffc_reregistration_schema_version';
		if ( get_option( $ffc_schema_option, '' ) === FFC_VERSION ) {
			return;
		}

		self::create_tables();

		update_option( $ffc_schema_option, FFC_VERSION );
	}

	/**
	 * Drop the duplicate indexes the two declaration paths left behind (#1087).
	 *
	 * `ffc_reregistration_submissions` was declared by this activator **and** by
	 * two migrations, and the two sides indexed the same columns under different
	 * names — so an install that took both paths carries two indexes on
	 * `auth_code` and two on `magic_token`, costing write time and space for
	 * nothing. #1102 aligned the declarations, so no new install inherits the
	 * pair; this removes it from the installs that already have it.
	 *
	 * **Why the pair survived:** `Activator::upgrade_auth_code_unique_constraints()`
	 * drops the non-unique indexes on `auth_code` only on the run where it still
	 * has to create the UNIQUE — once one exists it returns early, so anything
	 * left beside it stays forever. That is fixed at the source by the aligned
	 * declarations; what remains is this cleanup.
	 *
	 * The legacy names are listed explicitly rather than derived: the canonical
	 * name is read from the `CREATE TABLE`, and dropping "whichever index looks
	 * redundant" would be a heuristic over a schema this file already knows.
	 * Each drop is gated on the canonical index existing, so the column is never
	 * left unindexed — and `index_exists()` makes it idempotent.
	 *
	 * @return void
	 */
	private static function drop_superseded_indexes(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'ffc_reregistration_submissions';

		if ( ! self::table_exists( $table ) ) {
			return;
		}

		// Legacy index name => the canonical index that must already cover the
		// same column before the legacy one may go.
		$superseded = array(
			'idx_auth_code'   => 'uq_auth_code',
			'idx_magic_token' => 'magic_token',
		);

		foreach ( $superseded as $legacy => $canonical ) {
			if ( ! self::index_exists( $table, $legacy ) || ! self::index_exists( $table, $canonical ) ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema change on an activation path; there is nothing to cache and WordPress exposes no API for DROP INDEX.
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP INDEX %i', $table, $legacy ) );
		}
	}

	/**
	 * Create reregistrations (campaigns) table.
	 *
	 * @since 4.11.0
	 */
	private static function create_reregistrations_table(): void {
		global $wpdb;
		$table_name      = $wpdb->prefix . 'ffc_reregistrations';
		$charset_collate = $wpdb->get_charset_collate();

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name ) {
			return;
		}

		$sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            title varchar(250) NOT NULL,
            start_date datetime NOT NULL,
            end_date datetime NOT NULL,
            auto_approve tinyint(1) NOT NULL DEFAULT 0,
            email_invitation_enabled tinyint(1) NOT NULL DEFAULT 0,
            email_reminder_enabled tinyint(1) NOT NULL DEFAULT 0,
            email_confirmation_enabled tinyint(1) NOT NULL DEFAULT 0,
            reminder_days int(11) NOT NULL DEFAULT 7,
            status varchar(20) NOT NULL DEFAULT 'draft',
            deadline_extended_at bigint(20) unsigned DEFAULT NULL,
            created_by bigint(20) unsigned NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_status (status),
            KEY idx_dates (start_date, end_date)
        ) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Create reregistration ↔ audiences junction table.
	 *
	 * @since 5.0.0
	 */
	private static function create_reregistration_audiences_table(): void {
		global $wpdb;
		$table_name      = $wpdb->prefix . 'ffc_reregistration_audiences';
		$charset_collate = $wpdb->get_charset_collate();

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name ) {
			return;
		}

		$sql = "CREATE TABLE {$table_name} (
            reregistration_id bigint(20) unsigned NOT NULL,
            audience_id bigint(20) unsigned NOT NULL,
            PRIMARY KEY (reregistration_id, audience_id),
            KEY idx_audience_id (audience_id)
        ) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Migrate existing audience_id column data into the junction table.
	 *
	 * @since 5.0.0
	 */
	private static function migrate_reregistration_audience_to_junction(): void {
		global $wpdb;
		$rereg_table    = $wpdb->prefix . 'ffc_reregistrations';
		$junction_table = $wpdb->prefix . 'ffc_reregistration_audiences';

		$has_column = $wpdb->get_results(
			$wpdb->prepare(
				'SHOW COLUMNS FROM %i LIKE %s',
				$rereg_table,
				'audience_id'
			)
		);

		if ( empty( $has_column ) ) {
			return; // Column already dropped — migration done.
		}

		// Copy audience_id into junction table (skip if already migrated).
		$wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (reregistration_id, audience_id)
             SELECT id, audience_id FROM %i WHERE audience_id > 0',
				$junction_table,
				$rereg_table
			)
		);

		// Drop the old column and its index.
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP INDEX idx_audience_id', $rereg_table ) );
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN audience_id', $rereg_table ) );
	}

	/**
	 * Create reregistration submissions table
	 *
	 * Stores individual user responses to reregistration campaigns.
	 *
	 * **`auth_code` is declared UNIQUE (#1087 passo 8)** because
	 * `Activator::upgrade_auth_code_unique_constraints()` converts it on this
	 * table too — a plain `KEY auth_code` never survives, and declaring one made
	 * `dbDelta()` ask for it back on every run that reached this method.
	 *
	 * @since 4.11.0
	 */
	private static function create_reregistration_submissions_table(): void {
		global $wpdb;
		$table_name      = $wpdb->prefix . 'ffc_reregistration_submissions';
		$charset_collate = $wpdb->get_charset_collate();

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name ) {
			return;
		}

		// `submitted_at` is Category A (instant) since 6.6.0 — unix UTC
		// seconds. See CLAUDE.md "Date / time storage convention".
		//
		// User-deletion policy (#822): `user_id` is a `NOT NULL` reference to
		// `wp_users.ID` covered by NEITHER the `UserCleanup` hook NOR the
		// `MigrationForeignKeys` backstop — by deliberate decision, not
		// oversight. A reregistration submission is a retained record: the
		// row (and its `data` JSON of form answers) must survive the user
		// account, so it is NOT anonymised or deleted on `deleted_user`. The
		// `user_id` therefore becomes an accepted orphan (like `activity_log`,
		// which is FK-only and never app-nulled). Nulling `user_id` alone
		// would be a half-measure anyway — the identifying PII lives in the
		// `data` body, not the FK. Consumers that JOIN `wp_users` already
		// degrade gracefully (the admin listing renders "—" for a deleted
		// user). `reviewed_by` follows the same retain-the-row rule. See
		// CLAUDE.md "Security & PII conventions" for the plugin-wide policy +
		// gap inventory.
		$sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            reregistration_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            data longtext DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            auth_code varchar(20) DEFAULT NULL,
            magic_token varchar(64) DEFAULT NULL,
            invited_at bigint(20) unsigned DEFAULT NULL,
            reminder_sent_at bigint(20) unsigned DEFAULT NULL,
            submitted_at bigint(20) unsigned DEFAULT NULL,
            reviewed_at bigint(20) unsigned DEFAULT NULL,
            reviewed_by bigint(20) unsigned DEFAULT NULL,
            notes text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_reregistration_user (reregistration_id, user_id),
            KEY idx_user_id (user_id),
            KEY idx_status (status),
            KEY idx_created (created_at),
            UNIQUE KEY uq_auth_code (auth_code),
            KEY magic_token (magic_token)
        ) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Add auth_code column to reregistration submissions table for existing installs.
	 *
	 * @since 4.12.0
	 */
	private static function add_reregistration_submissions_columns(): void {
		global $wpdb;
		$table_name = $wpdb->prefix . 'ffc_reregistration_submissions';

		if ( ! self::table_exists( $table_name ) ) {
			return;
		}

		self::add_columns_if_missing(
			$table_name,
			array(
				'auth_code'        => array(
					'type'  => 'VARCHAR(20) DEFAULT NULL',
					'after' => 'status',
					'index' => 'auth_code',
				),
				'magic_token'      => array(
					'type'  => 'VARCHAR(64) DEFAULT NULL',
					'after' => 'auth_code',
					'index' => 'magic_token',
				),
				// When the invitation for this submission was sent — Category A
				// (unix UTC), like `submitted_at` beside it. NULL means never
				// invited, which is the only honest answer for every row that
				// predates #1190: `status = 'pending'` was the proxy before, and
				// it cannot tell "not invited yet" from "invited and ignored".
				'invited_at'       => array(
					'type'  => 'BIGINT(20) UNSIGNED DEFAULT NULL',
					'after' => 'magic_token',
				),
				// When this submission's REMINDER was sent -- Category A (unix
				// UTC), sibling of `invited_at`. NULL = never reminded.
				//
				// Without it the reminder was resent EVERY DAY: the campaign
				// query uses `DATEDIFF(end_date, CURDATE()) <= reminder_days`,
				// which is a WINDOW and not a day, and the cron is daily -- so
				// with `reminder_days = 7` every pending participant received
				// seven emails, one a day (#1232).
				'reminder_sent_at' => array(
					'type'  => 'BIGINT(20) UNSIGNED DEFAULT NULL',
					'after' => 'invited_at',
				),
			)
		);
	}
	/**
	 * Add columns the campaigns table gained after its first release.
	 *
	 * {@see self::create_reregistrations_table()} returns early when the table
	 * exists, so an install created before a column was declared never gets it
	 * from there — this is the only path that reaches an existing install.
	 *
	 * @since 6.25.0
	 */
	private static function add_reregistrations_columns(): void {
		global $wpdb;
		$table_name = $wpdb->prefix . 'ffc_reregistrations';

		if ( ! self::table_exists( $table_name ) ) {
			return;
		}

		self::add_columns_if_missing(
			$table_name,
			array(
				// When the campaign's `end_date` was last pushed FORWARD —
				// Category A (unix UTC). Only an extension is recorded (#1190):
				// shortening a deadline must not re-invite anybody, so a date
				// that moves backwards leaves this untouched.
				'deadline_extended_at' => array(
					'type'  => 'BIGINT(20) UNSIGNED DEFAULT NULL',
					'after' => 'status',
				),
			)
		);
	}

	/**
	 * Create the CSV-import job header table (#1214).
	 *
	 * The import is a four-phase job (ingest → validate → promote → commit),
	 * so the header lives apart from the staged rows: it carries the phase
	 * (`status`), the progress pair the client polls, the `user_id` fence that
	 * `authorize_*` checks on every phase, and the `created_at` the stale-job
	 * sweep orders by. Same split as the recruitment importer's
	 * `ffc_recruitment_import_jobs`, for the same reasons.
	 *
	 * **Both tables are in-flight state with a TTL, never a record.** They are
	 * emptied at commit and swept when a job is abandoned, which is why they
	 * carry cleartext identifiers (see the staging table) and why `ENGINE`
	 * is stated explicitly, matching the recruitment pair rather than the
	 * reregistration tables above.
	 *
	 * @since 6.26.0
	 * @return void
	 */
	private static function create_import_jobs_table(): void {
		global $wpdb;
		$table_name      = $wpdb->prefix . 'ffc_reregistration_import_jobs';
		$charset_collate = $wpdb->get_charset_collate();

		if ( self::table_exists( $table_name ) ) {
			return;
		}

		$sql = "CREATE TABLE {$table_name} (
            job_id varchar(40) NOT NULL,
            reregistration_id bigint(20) unsigned NOT NULL,
            audience_id bigint(20) unsigned NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'ingested',
            total int(10) unsigned NOT NULL DEFAULT 0,
            processed_count int(10) unsigned NOT NULL DEFAULT 0,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (job_id),
            KEY idx_campaign_status (reregistration_id, status),
            KEY idx_cleanup (created_at)
        ) ENGINE=InnoDB {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Create the CSV-import staging table (#1214).
	 *
	 * **It stages the row as JSON, and that is the one structural way this
	 * importer cannot copy recruitment's.** There the staged columns are typed
	 * (`rank_value`, `score`, `adjutancy_slug`) because the domain fixes them.
	 * Here the columns are rows of `ffc_custom_fields` keyed by `audience_id`,
	 * defined per audience by the operator, so no fixed column list can hold
	 * them — `payload` carries the header→`field_key` map applied to the row.
	 * `longtext`, never `json`: MariaDB implements `json` as `LONGTEXT` plus a
	 * CHECK, so a `json` column can never match its own statement (the dbDelta
	 * idempotence gate).
	 *
	 * The resolution columns beside it are the ones validation and promotion
	 * query on, and they are deliberately **cleartext**: this is in-flight data
	 * with a TTL, and hashing before validation would make "this CPF is
	 * malformed" unreportable to the operator. `RecruitmentActivator` reached
	 * the same conclusion and recreated its staging table plaintext (V11).
	 * Nothing here survives commit.
	 *
	 * `user_id` and `submission_id` are filled by the validate phase, so the
	 * promote phase re-reads a decision instead of taking it twice; `0` means
	 * unresolved rather than "user zero".
	 *
	 * @since 6.26.0
	 * @return void
	 */
	private static function create_import_staging_table(): void {
		global $wpdb;
		$table_name      = $wpdb->prefix . 'ffc_reregistration_import_staging';
		$charset_collate = $wpdb->get_charset_collate();

		if ( self::table_exists( $table_name ) ) {
			return;
		}

		$sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            job_id varchar(40) NOT NULL,
            row_no int(10) unsigned NOT NULL,
            line_no int(10) unsigned NOT NULL,
            reregistration_id bigint(20) unsigned NOT NULL,
            audience_id bigint(20) unsigned NOT NULL,
            payload longtext DEFAULT NULL,
            cpf_normalized varchar(11) NOT NULL DEFAULT '',
            rf_normalized varchar(7) NOT NULL DEFAULT '',
            email varchar(255) NOT NULL DEFAULT '',
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            submission_id bigint(20) unsigned NOT NULL DEFAULT 0,
            row_status varchar(20) NOT NULL DEFAULT 'staged',
            error text DEFAULT NULL,
            processed tinyint(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uq_job_row (job_id, row_no),
            KEY idx_job_processed (job_id, processed),
            KEY idx_job_status (job_id, row_status),
            KEY idx_job_cpf (job_id, cpf_normalized),
            KEY idx_job_rf (job_id, rf_normalized),
            KEY idx_job_email (job_id, email)
        ) ENGINE=InnoDB {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}
