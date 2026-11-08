<?php
/**
 * MigrationForeignKeys
 *
 * Adds `user_id → wp_users(ID)` FOREIGN KEY constraints as the DB-level backstop
 * to the app-layer `UserCleanup` hook: on user deletion the database enforces
 * ON DELETE SET NULL / CASCADE even if the hook is bypassed (a direct SQL
 * delete, a bulk operation).
 *
 * **Why a migration (still necessary):** WordPress's `dbDelta()` cannot emit
 * FOREIGN KEY clauses — none of the `CREATE TABLE` schemas declare them — so
 * this is the *only* code path that creates these FKs. They are absent on a
 * fresh install until this runs; it is not a spent one-off migration.
 *
 * **Covered set = a deliberate subset, not every `user_id` column.** The nine
 * tables here mirror the tables `UserCleanup` handles on `deleted_user`
 * (submissions, appointments, attendance, audience members/booking-users/
 * schedule-perms/form-invites, user-profiles) plus the `activity_log` audit
 * table. Other user-linked tables enforce integrity
 * at the app layer only: recruitment by design (a candidate is not a WP user
 * until promotion). `ffc_reregistration_submissions` and the custom-field value
 * tables carry a `user_id` covered by *neither* layer — tracked as a separate
 * follow-up, not a gap this migration silently owns.
 *
 * Requires InnoDB on all involved tables. A MyISAM table skips its FK with a
 * warning; the activation guard (`Activator::maybe_add_foreign_keys`) then keeps
 * retrying until every FK exists rather than pinning an incomplete version.
 *
 * **Orphans follow the table's rule (#2343).** A row whose `user_id` names no
 * account would make the constraint fail. On a SET NULL table the link is
 * cleared; on a CASCADE table the row is deleted, because those tables declare
 * `user_id NOT NULL` -- nulling there errors in strict mode and writes nothing
 * otherwise, so the constraint never went in and the version never pinned.
 *
 * **Names carry the table prefix (#2343).** Constraint names are unique per
 * database, so two installs sharing one database collided on the bare name.
 * Presence is read from what the constraint does -- a key on `user_id` that
 * references this install's users table -- so an install that already has the
 * old bare name is complete and is not given a second constraint.
 *
 * @package FreeFormCertificate\Migrations
 * @since 4.9.7
 */

declare(strict_types=1);

namespace FreeFormCertificate\Migrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- dbDelta() cannot emit foreign keys, so this migration is the only path that creates them. The ALTER statements run against the plugin's own ffc_* tables on an activation/upgrade path, where there is nothing to cache.
/**
 * Migration Foreign Keys.
 */
class MigrationForeignKeys {

	use \FreeFormCertificate\Core\DatabaseHelperTrait;

	/**
	 * Table suffix => [constraint base name, ON DELETE action].
	 *
	 * SET NULL keeps a record and drops the link; CASCADE deletes a pure
	 * relationship with the person.
	 *
	 * @var array<string, array{0: string, 1: 'SET NULL'|'CASCADE'}>
	 */
	private const CONSTRAINTS = array(
		'ffc_submissions'                   => array( 'fk_ffc_submissions_user', 'SET NULL' ),
		'ffc_self_scheduling_appointments'  => array( 'fk_ffc_appointments_user', 'SET NULL' ),
		'ffc_activity_log'                  => array( 'fk_ffc_activity_log_user', 'SET NULL' ),
		'ffc_audience_attendance'           => array( 'fk_ffc_attendance_user', 'SET NULL' ),
		'ffc_audience_members'              => array( 'fk_ffc_audience_members_user', 'CASCADE' ),
		'ffc_audience_booking_users'        => array( 'fk_ffc_booking_users_user', 'CASCADE' ),
		'ffc_audience_schedule_permissions' => array( 'fk_ffc_schedule_perms_user', 'CASCADE' ),
		'ffc_user_profiles'                 => array( 'fk_ffc_user_profiles_user', 'CASCADE' ),
		'ffc_audience_form_invites'         => array( 'fk_ffc_form_invites_user', 'CASCADE' ),
	);

	/**
	 * The constraint name for this install: the table prefix, then the base
	 * name, cut to MySQL's 64-character identifier limit.
	 *
	 * @param string $base Base name.
	 * @return string
	 */
	private static function constraint_name( string $base ): string {
		global $wpdb;
		return substr( $wpdb->prefix . $base, 0, 64 );
	}

	/**
	 * This install's tables that carry a `user_id` key referencing its users
	 * table, whatever the constraint is called.
	 *
	 * @return array<string, string> Table => constraint name.
	 */
	private static function installed_constraints(): array {
		global $wpdb;

		$tables = array();
		foreach ( array_keys( self::CONSTRAINTS ) as $suffix ) {
			$tables[] = $wpdb->prefix . $suffix;
		}
		$placeholders = implode( ',', array_fill( 0, count( $tables ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders is a list of %s, one per table spread into the arguments, so every value is bound.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT TABLE_NAME, CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = %s AND COLUMN_NAME = 'user_id' AND REFERENCED_TABLE_NAME = %s
             AND TABLE_NAME IN ({$placeholders})",
				DB_NAME,
				$wpdb->users,
				...$tables
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		$installed = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( is_object( $row ) && isset( $row->TABLE_NAME, $row->CONSTRAINT_NAME ) ) {
				$installed[ (string) $row->TABLE_NAME ] = (string) $row->CONSTRAINT_NAME;
			}
		}
		return $installed;
	}

	/**
	 * Run the migration
	 *
	 * @return array<string, mixed> Result with success status and details
	 */
	public static function run(): array {
		global $wpdb;

		$results = array(
			'added'   => array(),
			'skipped' => array(),
			'errors'  => array(),
		);

		$set_null_tables = array();
		$cascade_tables  = array();
		foreach ( self::CONSTRAINTS as $suffix => $definition ) {
			$table = $wpdb->prefix . $suffix;
			if ( 'SET NULL' === $definition[1] ) {
				$set_null_tables[ $table ] = self::constraint_name( $definition[0] );
			} else {
				$cascade_tables[ $table ] = self::constraint_name( $definition[0] );
			}
		}

		// Check wp_users engine first.
		$users_engine = self::get_table_engine( $wpdb->users );
		if ( 'InnoDB' !== $users_engine ) {
			return array(
				'success' => false,
				'added'   => array(),
				'skipped' => array(),
				'errors'  => array(
					sprintf( 'wp_users uses %s engine (InnoDB required for FK). Migration skipped.', $users_engine ? $users_engine : 'unknown' ),
				),
				'message' => __( 'Foreign keys require InnoDB engine. Migration skipped.', 'ffcertificate' ),
			);
		}

		// Process SET NULL constraints.
		foreach ( $set_null_tables as $table => $constraint_name ) {
			$result = self::add_foreign_key( $table, $constraint_name, 'SET NULL' );
			self::record_result( $results, $table, $constraint_name, $result );
		}

		// Process CASCADE constraints.
		foreach ( $cascade_tables as $table => $constraint_name ) {
			$result = self::add_foreign_key( $table, $constraint_name, 'CASCADE' );
			self::record_result( $results, $table, $constraint_name, $result );
		}

		// Log results.
		if ( class_exists( '\FreeFormCertificate\Core\ActivityLog' ) ) {
			\FreeFormCertificate\Core\ActivityLog::log(
				'migration_foreign_keys',
				\FreeFormCertificate\Core\ActivityLog::LEVEL_INFO,
				$results
			);
		}

		$total_added   = count( $results['added'] );
		$total_skipped = count( $results['skipped'] );
		$total_errors  = count( $results['errors'] );

		return array(
			'success' => 0 === $total_errors,
			'added'   => $results['added'],
			'skipped' => $results['skipped'],
			'errors'  => $results['errors'],
			'message' => sprintf(
				/* translators: 1: added count, 2: skipped count, 3: error count */
				__( 'Foreign keys: %1$d added, %2$d skipped, %3$d errors', 'ffcertificate' ),
				$total_added,
				$total_skipped,
				$total_errors
			),
		);
	}

	/**
	 * Add a foreign key constraint to a table
	 *
	 * @param string $table Full table name.
	 * @param string $constraint_name Constraint name.
	 * @param string $on_delete ON DELETE action ('SET NULL' or 'CASCADE').
	 * @return array{status: string, message: string}
	 */
	private static function add_foreign_key( string $table, string $constraint_name, string $on_delete ): array {
		global $wpdb;

		// Check if table and column exist.
		if ( ! self::table_exists( $table ) ) {
			return array(
				'status'  => 'skipped',
				'message' => 'Table does not exist',
			);
		}
		if ( ! self::column_exists( $table, 'user_id' ) ) {
			return array(
				'status'  => 'skipped',
				'message' => 'No user_id column',
			);
		}

		// Check engine.
		$engine = self::get_table_engine( $table );
		if ( 'InnoDB' !== $engine ) {
			return array(
				'status'  => 'skipped',
				'message' => "Engine is {$engine}, not InnoDB",
			);
		}

		// Check if the key already exists, under this name or the bare one an
		// older release used.
		if ( isset( self::installed_constraints()[ $table ] ) ) {
			return array(
				'status'  => 'skipped',
				'message' => 'FK already exists',
			);
		}

		// For SET NULL, ensure user_id allows NULL.
		if ( 'SET NULL' === $on_delete ) {
			$col_info = $wpdb->get_row( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'user_id' ) );
			if ( $col_info && strtoupper( $col_info->Null ) !== 'YES' ) {
				$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i MODIFY user_id BIGINT(20) UNSIGNED DEFAULT NULL', $table ) );
			}
		}

		// Clean up orphaned references (user_id values that don't exist in
		// wp_users) the way the constraint itself will treat them: cleared on a
		// SET NULL table, deleted on a CASCADE one, whose user_id is NOT NULL.
		if ( 'CASCADE' === $on_delete ) {
			$wpdb->query(
				$wpdb->prepare(
					'DELETE FROM %i WHERE user_id IS NOT NULL AND user_id NOT IN (SELECT ID FROM %i)',
					$table,
					$wpdb->users
				)
			);
		} else {
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET user_id = NULL WHERE user_id IS NOT NULL AND user_id NOT IN (SELECT ID FROM %i)',
					$table,
					$wpdb->users
				)
			);
		}

		// Add the FK constraint.
		// $on_delete is a validated SQL keyword ('SET NULL' or 'CASCADE') from hardcoded arrays in run().
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $on_delete is a validated SQL keyword from hardcoded config.
		$sql = $wpdb->prepare(
			"ALTER TABLE %i ADD CONSTRAINT %i FOREIGN KEY (user_id) REFERENCES %i(ID) ON DELETE {$on_delete}",
			$table,
			$constraint_name,
			$wpdb->users
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $on_delete is validated SQL keyword

		if ( false === $result ) {
			return array(
				'status'  => 'error',
				'message' => $wpdb->last_error ? $wpdb->last_error : 'Unknown error adding FK',
			);
		}

		return array(
			'status'  => 'added',
			'message' => "FK added with ON DELETE {$on_delete}",
		);
	}

	/**
	 * Get the storage engine for a table
	 *
	 * @param string $table Table name.
	 * @return string|null Engine name (e.g. 'InnoDB', 'MyISAM') or null
	 */
	private static function get_table_engine( string $table ): ?string {
		global $wpdb;

		$engine = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				DB_NAME,
				$table
			)
		);

		return $engine ? $engine : null;
	}

	/**
	 * Record a result into the results array
	 *
	 * @param array<string, array<int, array<string, string>>> &$results Results accumulator.
	 * @param string                                           $table Table name.
	 * @param string                                           $constraint Constraint name.
	 * @param array<string, string>                            $result Result from add_foreign_key.
	 */
	private static function record_result( array &$results, string $table, string $constraint, array $result ): void {
		$entry = array(
			'table'      => $table,
			'constraint' => $constraint,
			'message'    => $result['message'],
		);

		switch ( $result['status'] ) {
			case 'added':
				$results['added'][] = $entry;
				break;
			case 'skipped':
				$results['skipped'][] = $entry;
				break;
			case 'error':
				$results['errors'][] = $entry;
				break;
		}
	}

	/**
	 * Get migration status
	 *
	 * @return array<string, mixed> Status information
	 */
	public static function get_status(): array {
		$installed = self::installed_constraints();

		$existing = array_values( $installed );
		sort( $existing );
		$existing_count = count( $installed );
		$total          = count( self::CONSTRAINTS );

		return array(
			'available'            => true,
			'total_constraints'    => $total,
			'existing_constraints' => $existing_count,
			'is_complete'          => $existing_count >= $total,
			'existing'             => $existing,
			'message'              => sprintf(
				/* translators: 1: existing count, 2: total count */
				__( '%1$d of %2$d FK constraints exist', 'ffcertificate' ),
				$existing_count,
				$total
			),
		);
	}
}
