<?php
/**
 * Activator v3.0.1
 * Added: edited_at and edited_by columns
 *
 * @package FreeFormCertificate
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace (Phase 2)
 */

declare(strict_types=1);

namespace FreeFormCertificate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin activation tasks for plugin.
 */
class Activator {

	use \FreeFormCertificate\Core\DatabaseHelperTrait;

	/**
	 * Activate.
	 */
	public static function activate(): void {
		self::create_submissions_table();
		self::create_activity_log_table();
		self::add_columns();
		self::create_verification_page();

		if ( class_exists( '\FreeFormCertificate\Security\RateLimitActivator' ) ) {
			\FreeFormCertificate\Security\RateLimitActivator::create_tables();
		}

		self::register_user_role();
		self::create_dashboard_page();
		self::create_user_profiles_table();
		self::create_custom_fields_table();
		self::create_reregistrations_table();
		self::create_reregistration_audiences_table();
		self::create_reregistration_submissions_table();
		self::add_reregistration_submissions_columns();
		self::migrate_reregistration_audience_to_junction();
		self::upgrade_auth_code_unique_constraints();

		if ( class_exists( '\FreeFormCertificate\Migrations\MigrationSelfSchedulingTables' ) ) {
			\FreeFormCertificate\Migrations\MigrationSelfSchedulingTables::run();
		}

		if ( class_exists( '\FreeFormCertificate\Migrations\MigrationRenameCapabilities' ) ) {
			\FreeFormCertificate\Migrations\MigrationRenameCapabilities::run();
		}

		if ( class_exists( '\FreeFormCertificate\Migrations\MigrationCustomFieldsTables' ) ) {
			\FreeFormCertificate\Migrations\MigrationCustomFieldsTables::run();
		}

		if ( class_exists( '\FreeFormCertificate\Migrations\MigrationDynamicReregFields' ) ) {
			\FreeFormCertificate\Migrations\MigrationDynamicReregFields::run();
		}

		if ( class_exists( '\FreeFormCertificate\SelfScheduling\SelfSchedulingActivator' ) ) {
			\FreeFormCertificate\SelfScheduling\SelfSchedulingActivator::create_tables();
		}

		if ( class_exists( '\FreeFormCertificate\Audience\AudienceActivator' ) ) {
			\FreeFormCertificate\Audience\AudienceActivator::create_tables();
		}

		if ( class_exists( '\FreeFormCertificate\UrlShortener\UrlShortenerActivator' ) ) {
			\FreeFormCertificate\UrlShortener\UrlShortenerActivator::create_tables();
		}

		if ( class_exists( '\FreeFormCertificate\Recruitment\RecruitmentActivator' ) ) {
			\FreeFormCertificate\Recruitment\RecruitmentActivator::create_tables();
		}

		if ( class_exists( '\FreeFormCertificate\UserDashboard\CapabilityManager' ) ) {
			\FreeFormCertificate\UserDashboard\CapabilityManager::register_recruitment_manager_role();
			\FreeFormCertificate\UserDashboard\CapabilityManager::register_module_roles();
		}

		self::add_composite_indexes();
		self::add_foreign_keys();
		self::run_migrations();

		// Clean up legacy cron hooks from pre-4.6.15 versions.
		wp_clear_scheduled_hook( 'ffc_daily_cleanup_hook' );
		wp_clear_scheduled_hook( 'ffc_process_submission_hook' );
		wp_clear_scheduled_hook( 'ffc_warm_cache_hook' );

		// Schedule daily cleanup cron.
		if ( ! wp_next_scheduled( 'ffcertificate_daily_cleanup_hook' ) ) {
			wp_schedule_event( time(), 'daily', 'ffcertificate_daily_cleanup_hook' );
		}

		// Schedule the per-form ticket-pool sweep for ended forms.
		\FreeFormCertificate\Admin\ExpiredTicketsCleanup::schedule();

		self::seed_reregistration_field_options();

		flush_rewrite_rules();
	}

	/**
	 * Seed the admin-editable Divisão → Setor map into `ffc_settings`.
	 *
	 * Idempotent: only writes when the key is absent, so an admin's edits
	 * survive re-activation / upgrade. Seeds the hardcoded DRE São Miguel MP
	 * default — matching the per-audience field snapshots the seeder writes
	 * from the same source, so no display resync is needed here.
	 *
	 * @return void
	 */
	private static function seed_reregistration_field_options(): void {
		if ( ! class_exists( '\FreeFormCertificate\Reregistration\ReregistrationFieldOptions' ) ) {
			return;
		}

		$settings = get_option( 'ffc_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		if ( isset( $settings['divisao_setor_map'] ) ) {
			return;
		}

		$settings['divisao_setor_map'] = \FreeFormCertificate\Reregistration\ReregistrationFieldOptions::get_default_divisao_setor_map();
		update_option( 'ffc_settings', $settings );
	}

	/**
	 * Create submissions table.
	 */
	private static function create_submissions_table(): void {
		global $wpdb;
		$table_name      = \FreeFormCertificate\Core\Utils::get_submissions_table();
		$charset_collate = $wpdb->get_charset_collate();

		if ( self::table_exists( $table_name ) ) {
			return;
		}

		// `submission_date` is Category A (instant) since 6.6.0 — unix UTC
		// seconds. See CLAUDE.md "Date / time storage convention".
		$sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            form_id bigint(20) unsigned NOT NULL,
            submission_date bigint(20) unsigned NOT NULL,
            data longtext NULL,
            status varchar(20) DEFAULT 'publish',
            magic_token varchar(32) DEFAULT NULL,
            auth_code varchar(20) DEFAULT NULL,
            PRIMARY KEY (id),
            KEY form_id (form_id),
            KEY status (status),
            KEY magic_token (magic_token),
            KEY auth_code (auth_code)
        ) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
		dbDelta( $sql );
	}

	/**
	 * Ensure submissions table schema is up-to-date on every load.
	 *
	 * Compares stored DB version with plugin version and runs add_columns()
	 * when they differ.  This prevents "column not found" errors after a
	 * plugin update that adds new columns without a full deactivate/activate.
	 *
	 * @since 4.12.26
	 */
	public static function maybe_add_columns(): void {
		$stored = get_option( 'ffc_submissions_db_version', '' );

		if ( FFC_VERSION === $stored ) {
			return;
		}

		self::add_columns();
		update_option( 'ffc_submissions_db_version', FFC_VERSION, true );
	}

	/**
	 * Idempotently add `idx_created` indexes to FFC custom tables that
	 * order/filter on `created_at` but did not declare the index in
	 * their CREATE TABLE statement. Runs once per `FFC_VERSION`.
	 *
	 * Concrete benefit:
	 *   - `ffc_recruitment_candidate.created_at`: every admin candidate
	 *     listing and every adjutancy-keyed search runs `ORDER BY created_at
	 *     DESC` — without an index this is a full-table sort on the
	 *     entire candidate roster.
	 *   - `ffc_recruitment_notice.created_at`: smaller table, but the
	 *     notice list page runs `ORDER BY created_at DESC` on every render.
	 *   - `ffc_reregistration_submissions.created_at`: secondary sort
	 *     after `r.start_date DESC` in the submissions list join.
	 *
	 * Tables intentionally NOT touched by this migration:
	 *   - `ffc_activity_log`, `ffc_rate_limit_logs`, `ffc_device_signals`
	 *     already declare `idx_created` in their CREATE TABLE.
	 *   - `ffc_user_profiles`, `ffc_custom_fields`, `ffc_audience_*`,
	 *     `ffc_audiences` have `created_at` columns but no query
	 *     orders/filters on them — adding an index there would be pure
	 *     write overhead.
	 *
	 * @since 6.5.0
	 * @return void
	 */
	public static function maybe_add_perf_indexes(): void {
		$stored = get_option( 'ffc_perf_indexes_db_version', '' );

		if ( FFC_VERSION === $stored ) {
			return;
		}

		global $wpdb;
		$tables_with_idx_created = array(
			$wpdb->prefix . 'ffc_recruitment_candidate',
			$wpdb->prefix . 'ffc_recruitment_notice',
			$wpdb->prefix . 'ffc_reregistration_submissions',
		);

		foreach ( $tables_with_idx_created as $table ) {
			if ( self::table_exists( $table ) ) {
				self::add_index_if_missing( $table, 'idx_created', '(created_at)' );
			}
		}

		update_option( 'ffc_perf_indexes_db_version', FFC_VERSION, true );
	}

	/**
	 * Idempotently migrate `submission_date` from a DATETIME column
	 * (storing the value in the site's TZ via `current_time('mysql')`)
	 * to a `BIGINT UNSIGNED` column storing unix UTC seconds (#249
	 * sub-escopo a). The column name stays `submission_date` so external
	 * SQL referencing it keeps working — the breaking change is the
	 * type swap, which the 6.6.0 release notes call out.
	 *
	 * Steps, all gated on a one-shot option flag:
	 *   1. Add staging column `submission_date_ts BIGINT UNSIGNED NOT NULL DEFAULT 0`
	 *      (only if it doesn't already exist — gives us retries on a
	 *      failure mid-backfill).
	 *   2. PHP backfill: for every row with `submission_date_ts = 0`,
	 *      parse the old `submission_date` DATETIME in `wp_timezone()`
	 *      and stash the unix UTC int. Batched so a million-row table
	 *      doesn't run out of memory.
	 *   3. Drop indexes that reference the old DATETIME column.
	 *   4. Drop the old `submission_date` column.
	 *   5. Rename `submission_date_ts` to `submission_date`.
	 *   6. Recreate the dropped indexes against the new int column.
	 *   7. Flip the one-shot flag so subsequent boots are a no-op.
	 *
	 * Re-run-safe in two layers: the option flag short-circuits the
	 * whole routine after a successful first run; before that, the
	 * `column_exists()` / `index_exists()` guards make each sub-step
	 * survive being re-attempted after a partial failure.
	 *
	 * @since 6.6.0
	 */
	public static function maybe_migrate_submission_date_to_unix(): void {
		if ( '1' === get_option( 'ffc_submission_date_unix_migrated', '' ) ) {
			return;
		}

		global $wpdb;
		$table = \FreeFormCertificate\Core\Utils::get_submissions_table();

		if ( ! self::table_exists( $table ) ) {
			return; // Fresh install before create_submissions_table() — nothing to do.
		}

		$has_old = self::column_exists( $table, 'submission_date' );
		$has_new = self::column_exists( $table, 'submission_date_ts' );

		// Step 1: ensure staging column exists.
		if ( ! $has_new ) {
			if ( ! $has_old ) {
				// Fresh table created at 6.6.0+ already has the int column — nothing to migrate.
				update_option( 'ffc_submission_date_unix_migrated', '1', true );
				return;
			}
			self::add_column_if_missing(
				$table,
				'submission_date_ts',
				'BIGINT UNSIGNED NOT NULL DEFAULT 0',
				'submission_date'
			);
			$has_new = true;
		}

		// Step 2: PHP backfill — interpret the stored DATETIME literal in the
		// site's TZ, convert to unix UTC. MySQL's UNIX_TIMESTAMP() would
		// interpret the value in the session TZ which WP doesn't pin, so we
		// don't trust it for this. Batches of 500 keep peak memory bounded
		// on large historic tables.
		if ( $has_old ) {
			$tz         = wp_timezone();
			$batch_size = 500;
			do {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$rows      = $wpdb->get_results(
					$wpdb->prepare( 'SELECT id, submission_date FROM %i WHERE submission_date_ts = 0 LIMIT %d', $table, $batch_size )
				);
				$row_count = is_array( $rows ) ? count( $rows ) : 0;
				if ( 0 === $row_count ) {
					break;
				}
				foreach ( $rows as $row ) {
					try {
						$dt = new \DateTimeImmutable( (string) $row->submission_date, $tz );
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
						$wpdb->update(
							$table,
							array( 'submission_date_ts' => $dt->getTimestamp() ),
							array( 'id' => (int) $row->id ),
							array( '%d' ),
							array( '%d' )
						);
					} catch ( \Exception $e ) {
						// Unparseable DATETIME (corrupted row). Leave the
						// ts at 0 — admin will see Jan 1 1970 in the UI,
						// which is loud enough to investigate. Don't block
						// the rest of the migration on one bad row.
						unset( $e );
					}
				}
			} while ( $row_count === $batch_size );

			// Step 3-5: drop legacy indexes that include `submission_date`,
			// drop the old DATETIME column, rename the staging column over it.
			foreach ( array( 'idx_status_submission_date', 'idx_form_status_date' ) as $idx ) {
				if ( self::index_exists( $table, $idx ) ) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
					$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP INDEX %i', $table, $idx ) );
				}
			}
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN %i', $table, 'submission_date' ) );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i CHANGE %i %i BIGINT UNSIGNED NOT NULL', $table, 'submission_date_ts', 'submission_date' ) );
		}

		// Step 6: recreate the composite indexes (now over the int column).
		self::add_indexes_if_missing(
			$table,
			array(
				'idx_status_submission_date' => '(status, submission_date)',
				'idx_form_status_date'       => '(form_id, status, submission_date)',
			)
		);

		// Step 7: mark complete.
		update_option( 'ffc_submission_date_unix_migrated', '1', true );
	}

	/**
	 * Idempotently migrate `submitted_at` on `ffc_reregistration_submissions`
	 * from DATETIME (site TZ via `current_time('mysql')`) to BIGINT UNSIGNED
	 * NULL storing unix UTC seconds (#249 sub-escopo b). The column name
	 * stays the same — see {@see maybe_migrate_submission_date_to_unix()}
	 * for the rationale + step-by-step playbook.
	 *
	 * NULL is meaningful here: drafts are inserted with `submitted_at = NULL`
	 * and only get a value once the user clicks Submit. So the staging
	 * column uses NULL (not 0) for "not yet submitted", and the backfill
	 * only touches rows where the old DATETIME is non-NULL.
	 *
	 * `reviewed_at` on the same table was migrated alongside this column
	 * via {@see self::maybe_migrate_sibling_instants_to_unix()}. The
	 * housekeeping columns (`created_at`, `updated_at`) stay DATETIME by
	 * design — see the "Category A exception — housekeeping timestamps"
	 * subsection of CLAUDE.md.
	 *
	 * @since 6.6.0
	 */
	public static function maybe_migrate_submitted_at_to_unix(): void {
		if ( '1' === get_option( 'ffc_submitted_at_unix_migrated', '' ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'ffc_reregistration_submissions';

		if ( ! self::table_exists( $table ) ) {
			return;
		}

		$has_old = self::column_exists( $table, 'submitted_at' );
		$has_new = self::column_exists( $table, 'submitted_at_ts' );

		if ( ! $has_new ) {
			if ( ! $has_old ) {
				update_option( 'ffc_submitted_at_unix_migrated', '1', true );
				return;
			}
			self::add_column_if_missing(
				$table,
				'submitted_at_ts',
				'BIGINT UNSIGNED DEFAULT NULL',
				'submitted_at'
			);
			$has_new = true;
		}

		if ( $has_old ) {
			$tz         = wp_timezone();
			$batch_size = 500;
			do {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$rows      = $wpdb->get_results(
					$wpdb->prepare( 'SELECT id, submitted_at FROM %i WHERE submitted_at IS NOT NULL AND submitted_at_ts IS NULL LIMIT %d', $table, $batch_size )
				);
				$row_count = is_array( $rows ) ? count( $rows ) : 0;
				if ( 0 === $row_count ) {
					break;
				}
				foreach ( $rows as $row ) {
					try {
						$dt = new \DateTimeImmutable( (string) $row->submitted_at, $tz );
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
						$wpdb->update(
							$table,
							array( 'submitted_at_ts' => $dt->getTimestamp() ),
							array( 'id' => (int) $row->id ),
							array( '%d' ),
							array( '%d' )
						);
					} catch ( \Exception $e ) {
						unset( $e );
					}
				}
			} while ( $row_count === $batch_size );

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN %i', $table, 'submitted_at' ) );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i CHANGE %i %i BIGINT UNSIGNED DEFAULT NULL', $table, 'submitted_at_ts', 'submitted_at' ) );
		}

		update_option( 'ffc_submitted_at_unix_migrated', '1', true );
	}

	/**
	 * Sprint-d (#249) — migrate the sibling instant columns that share
	 * tables with the (a)(b)(c) targets. Each one follows the same
	 * Category A semantic (instant in time) and would otherwise leave
	 * the tables half-converted between DATETIME-with-WP-TZ and unix
	 * UTC int. Tables touched: `ffc_submissions`, `ffc_reregistration_submissions`,
	 * `ffc_recruitment_call`, and the self-scheduling appointments table.
	 *
	 * Out of scope by design: `created_at` / `updated_at` columns stay
	 * DATETIME — they're documented as the Category A housekeeping
	 * exception. See "Category A exception — housekeeping timestamps"
	 * in CLAUDE.md for rationale + per-table inventory.
	 *
	 * Idempotent via the `ffc_sibling_instants_unix_migrated` option flag.
	 *
	 * @since 6.6.0
	 */
	public static function maybe_migrate_sibling_instants_to_unix(): void {
		if ( '1' === get_option( 'ffc_sibling_instants_unix_migrated', '' ) ) {
			return;
		}

		global $wpdb;

		// ffc_submissions (paired with submission_date from Sprint a).
		$submissions_table = \FreeFormCertificate\Core\Utils::get_submissions_table();
		self::migrate_datetime_column_to_unix( $submissions_table, 'consent_date', true );
		self::migrate_datetime_column_to_unix( $submissions_table, 'edited_at', true );

		// ffc_reregistration_submissions (paired with submitted_at from Sprint b).
		self::migrate_datetime_column_to_unix(
			$wpdb->prefix . 'ffc_reregistration_submissions',
			'reviewed_at',
			true
		);

		// ffc_recruitment_call (paired with called_at from Sprint c). The
		// composite index `idx_classification_cancelled` references the old
		// DATETIME column, so it must drop + recreate alongside the rename.
		self::migrate_datetime_column_to_unix(
			$wpdb->prefix . 'ffc_recruitment_call',
			'cancelled_at',
			true,
			array( 'idx_classification_cancelled' ),
			array( 'idx_classification_cancelled' => '(classification_id, cancelled_at)' )
		);

		// Self-scheduling appointments table (#249 expansion).
		$apt_table = $wpdb->prefix . 'ffc_appointments';
		foreach ( array( 'approved_at', 'cancelled_at', 'consent_date', 'reminder_sent_at' ) as $col ) {
			self::migrate_datetime_column_to_unix( $apt_table, $col, true );
		}

		update_option( 'ffc_sibling_instants_unix_migrated', '1', true );
	}

	/**
	 * Add columns.
	 */
	private static function add_columns(): void {
		global $wpdb;
		$table_name = \FreeFormCertificate\Core\Utils::get_submissions_table();

		$columns = array(
			'user_id'                 => array(
				'type'  => 'BIGINT(20) UNSIGNED DEFAULT NULL',
				'after' => 'form_id',
				'index' => 'user_id',
			),
			'magic_token'             => array(
				'type'  => 'VARCHAR(32) DEFAULT NULL',
				'after' => 'status',
				'index' => 'magic_token',
			),
			'auth_code'               => array(
				'type'  => 'VARCHAR(20) DEFAULT NULL',
				'after' => 'magic_token',
				'index' => 'auth_code',
			),
			'email_encrypted'         => array(
				'type'  => 'TEXT NULL DEFAULT NULL',
				'after' => 'auth_code',
			),
			'email_hash'              => array(
				'type'  => 'VARCHAR(64) NULL DEFAULT NULL',
				'after' => 'email_encrypted',
				'index' => 'email_hash',
			),
			'cpf_encrypted'           => array(
				'type'  => 'TEXT NULL DEFAULT NULL',
				'after' => 'email_hash',
			),
			'cpf_hash'                => array(
				'type'  => 'VARCHAR(64) NULL DEFAULT NULL',
				'after' => 'cpf_encrypted',
				'index' => 'cpf_hash',
			),
			'rf_encrypted'            => array(
				'type'  => 'TEXT NULL DEFAULT NULL',
				'after' => 'cpf_hash',
			),
			'rf_hash'                 => array(
				'type'  => 'VARCHAR(64) NULL DEFAULT NULL',
				'after' => 'rf_encrypted',
				'index' => 'rf_hash',
			),
			'ticket_hash'             => array(
				'type'  => 'VARCHAR(64) NULL DEFAULT NULL',
				'after' => 'rf_hash',
				'index' => 'ticket_hash',
			),
			'user_ip_encrypted'       => array(
				'type'  => 'TEXT NULL DEFAULT NULL',
				'after' => 'ticket_hash',
			),
			'data_encrypted'          => array(
				'type'  => 'LONGTEXT NULL DEFAULT NULL',
				'after' => 'user_ip_encrypted',
			),
			'consent_given'           => array(
				'type'  => 'TINYINT(1) DEFAULT 0',
				'after' => 'data_encrypted',
			),
			// Category A instant since 6.6.0 (#249 sub-escopo d) — unix UTC.
			'consent_date'            => array(
				'type'  => 'BIGINT UNSIGNED DEFAULT NULL',
				'after' => 'consent_given',
			),
			'consent_text'            => array(
				'type'  => 'TEXT DEFAULT NULL',
				'after' => 'consent_date',
			),
			'qr_code_cache'           => array(
				'type'  => 'LONGTEXT DEFAULT NULL',
				'after' => 'consent_text',
			),
			// Category A instant since 6.6.0 (#249 sub-escopo d) — unix UTC.
			'edited_at'               => array(
				'type'  => 'BIGINT UNSIGNED DEFAULT NULL',
				'after' => 'qr_code_cache',
			),
			'edited_by'               => array(
				'type'  => 'BIGINT(20) UNSIGNED NULL DEFAULT NULL',
				'after' => 'edited_at',
			),
			// Category B wall-clock TIMEs for the operator-driven schedule
			// exception (#366). NULL means "no override — use the form/geofence
			// baseline at render time". Stored as literal HH:MM:SS, never
			// converted across TZs. See CLAUDE.md "Date / time storage
			// convention".
			'schedule_start_override' => array(
				'type'  => 'TIME NULL DEFAULT NULL',
				'after' => 'edited_by',
			),
			'schedule_end_override'   => array(
				'type'  => 'TIME NULL DEFAULT NULL',
				'after' => 'schedule_start_override',
			),
		);

		self::add_columns_if_missing( $table_name, $columns );
		self::add_index_if_missing( $table_name, 'idx_form_cpf_new', '(form_id, cpf_hash)' );
		self::add_index_if_missing( $table_name, 'idx_form_rf', '(form_id, rf_hash)' );
	}

	/**
	 * Add composite indexes for common query patterns.
	 *
	 * @since 4.6.2
	 */
	private static function add_composite_indexes(): void {
		$table_name = \FreeFormCertificate\Core\Utils::get_submissions_table();

		self::add_indexes_if_missing(
			$table_name,
			array(
				'idx_form_status'            => '(form_id, status)',
				'idx_status_submission_date' => '(status, submission_date)',
				'idx_email_hash_form_id'     => '(email_hash, form_id)',
				'idx_form_ticket_hash'       => '(form_id, ticket_hash)',
				// Covers the common admin list pattern: filter by form + status,
				// then sort by submission_date DESC for pagination. Without this
				// composite, MySQL either fans out via idx_form_status and then
				// sorts on a temporary file, or uses idx_status_submission_date
				// but still filters by form_id row-by-row.
				'idx_form_status_date'       => '(form_id, status, submission_date)',
			)
		);
	}

	/**
	 * Add FOREIGN KEY constraints for referential integrity
	 *
	 * @since 4.9.7
	 */
	private static function add_foreign_keys(): void {
		if ( class_exists( '\FreeFormCertificate\Migrations\MigrationForeignKeys' ) ) {
			\FreeFormCertificate\Migrations\MigrationForeignKeys::run();
		}
	}

	/**
	 * Create activity log table.
	 */
	private static function create_activity_log_table(): void {
		// Delegate to ActivityLog::create_table() to avoid schema mismatch (v4.6.9).
		if ( class_exists( '\FreeFormCertificate\Core\ActivityLog' ) ) {
			\FreeFormCertificate\Core\ActivityLog::create_table();
		}
	}

	/**
	 * Create verification page.
	 */
	private static function create_verification_page(): void {
		$existing_page = get_page_by_path( 'valid' );

		if ( $existing_page ) {
			update_option( 'ffc_verification_page_id', $existing_page->ID );
			return;
		}

		$page_data = array(
			'post_title'     => 'Certificate Verification',
			'post_content'   => '[ffc_verification]',
			'post_status'    => 'publish',
			'post_type'      => 'page',
			'post_name'      => 'valid',
			'post_author'    => 1,
			'comment_status' => 'closed',
			'ping_status'    => 'closed',
		);

		$page_id = wp_insert_post( $page_data, true );

		if ( ! is_wp_error( $page_id ) ) {
			update_option( 'ffc_verification_page_id', $page_id );
			update_post_meta( $page_id, '_ffc_managed_page', '1' );
		}
	}

	/**
	 * Run batch migrations during activation.
	 *
	 * V5.0.0: Only split_cpf_rf remains and it must be run manually by admin
	 * (requires existing data). No auto-run migrations left.
	 *
	 * @return void
	 */
	private static function run_migrations(): void {
		// v5.0.0: All auto-run migrations have been retired.
		// split_cpf_rf is the only remaining migration and must be run manually.
	}

	/**
	 * Register ffc_user role
	 *
	 * @since 3.1.0
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
			\FreeFormCertificate\UserDashboard\UserManager::register_role();

			// Grant admin-level FFC capabilities to the administrator role.
			$admin_role = get_role( 'administrator' );
			if ( $admin_role ) {
				foreach ( \FreeFormCertificate\UserDashboard\UserManager::ADMIN_CAPABILITIES as $cap ) {
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
	 * Create reregistrations table
	 *
	 * Stores reregistration campaigns linked to audiences.
	 *
	 * @since 4.11.0
	 */
	private static function create_reregistrations_table(): void {
		global $wpdb;
		$table_name      = $wpdb->prefix . 'ffc_reregistrations';
		$charset_collate = $wpdb->get_charset_collate();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
            created_by bigint(20) unsigned NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_status (status),
            KEY idx_dates (start_date, end_date)
        ) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
		dbDelta( $sql );
	}

	/**
	 * Create reregistration ↔ audiences junction table.
	 *
	 * @since 4.13.0
	 */
	private static function create_reregistration_audiences_table(): void {
		global $wpdb;
		$table_name      = $wpdb->prefix . 'ffc_reregistration_audiences';
		$charset_collate = $wpdb->get_charset_collate();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
		dbDelta( $sql );
	}

	/**
	 * Migrate existing audience_id column data into the junction table.
	 *
	 * @since 4.13.0
	 */
	private static function migrate_reregistration_audience_to_junction(): void {
		global $wpdb;
		$rereg_table    = $wpdb->prefix . 'ffc_reregistrations';
		$junction_table = $wpdb->prefix . 'ffc_reregistration_audiences';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (reregistration_id, audience_id)
             SELECT id, audience_id FROM %i WHERE audience_id > 0',
				$junction_table,
				$rereg_table
			)
		);

		// Drop the old column and its index.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP INDEX idx_audience_id', $rereg_table ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN audience_id', $rereg_table ) );
	}

	/**
	 * Create reregistration submissions table
	 *
	 * Stores individual user responses to reregistration campaigns.
	 *
	 * @since 4.11.0
	 */
	private static function create_reregistration_submissions_table(): void {
		global $wpdb;
		$table_name      = $wpdb->prefix . 'ffc_reregistration_submissions';
		$charset_collate = $wpdb->get_charset_collate();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name ) {
			return;
		}

		// `submitted_at` is Category A (instant) since 6.6.0 — unix UTC
		// seconds. See CLAUDE.md "Date / time storage convention".
		$sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            reregistration_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            data json DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
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
            KEY idx_created (created_at)
        ) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
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
				'auth_code'   => array(
					'type'  => 'VARCHAR(20) DEFAULT NULL',
					'after' => 'status',
					'index' => 'auth_code',
				),
				'magic_token' => array(
					'type'  => 'VARCHAR(64) DEFAULT NULL',
					'after' => 'auth_code',
					'index' => 'magic_token',
				),
			)
		);
	}

	/**
	 * Upgrade auth_code indexes to UNIQUE constraints across all tables.
	 *
	 * Prevents cross-table code collisions by ensuring each auth_code
	 * is unique within its own table. Combined with the centralized
	 * generate_globally_unique_auth_code() this guarantees global uniqueness.
	 *
	 * Safe to run multiple times (idempotent).
	 *
	 * @since 4.12.0
	 */
	private static function upgrade_auth_code_unique_constraints(): void {
		global $wpdb;

		$tables = array(
			$wpdb->prefix . 'ffc_submissions' => 'auth_code',
			$wpdb->prefix . 'ffc_reregistration_submissions' => 'auth_code',
		);

		foreach ( $tables as $table => $column ) {
			if ( ! self::table_exists( $table ) ) {
				continue;
			}

			// Check if a UNIQUE index already exists on this column.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$indexes         = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Column_name = %s', $table, $column ) );
			$has_unique      = false;
			$old_index_names = array();

			foreach ( $indexes as $idx ) {
				if ( (int) 0 === $idx->Non_unique ) {
					$has_unique = true;
				} else {
					$old_index_names[] = $idx->Key_name;
				}
			}

			if ( $has_unique ) {
				continue;
			}

			// Remove duplicate auth_codes (keep the most recent) before adding constraint.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					"DELETE t1 FROM %i t1
                     INNER JOIN %i t2
                     WHERE t1.%i = t2.%i
                       AND t1.%i IS NOT NULL
                       AND t1.%i != ''
                       AND t1.id < t2.id",
					$table,
					$table,
					$column,
					$column,
					$column,
					$column
				)
			);

			// Drop old non-unique indexes.
			foreach ( array_unique( $old_index_names ) as $name ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
				$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP INDEX %i', $table, $name ) );
			}

			// Add UNIQUE constraint.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dynamic index name uq_{$column} from trusted internal config.
			$wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD UNIQUE INDEX uq_{$column} (%i)", $table, $column ) );
		}
	}

	/**
	 * Create dashboard page
	 *
	 * @since 3.1.0
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
