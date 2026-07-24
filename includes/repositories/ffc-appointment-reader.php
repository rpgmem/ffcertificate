<?php
/**
 * Appointment Reader
 *
 * Read-side of the appointment repository split (#563 backlog, A6). Holds every
 * domain SELECT / lookup / aggregate query. Writes live in {@see AppointmentWriter};
 * {@see AppointmentRepository} remains the public façade that delegates to both and
 * still exposes the generic CRUD inherited from {@see AbstractRepository}.
 *
 * Extends AbstractRepository so it reuses the same wpdb binding, table name, cache
 * group and helpers (findAll, get_cache, …) as before — the global $wpdb shared
 * across the façade/reader/writer keeps transactions and FOR UPDATE locks coherent.
 *
 * @package FreeFormCertificate\Repositories
 * @since   6.11.3
 */

declare(strict_types=1);

namespace FreeFormCertificate\Repositories;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
/**
 * Read queries for appointment records.
 */
class AppointmentReader extends AbstractRepository {

	/**
	 * Get table name
	 *
	 * @return string
	 */
	protected function get_table_name(): string {
		return $this->wpdb->prefix . 'ffc_self_scheduling_appointments';
	}

	/**
	 * Get cache group
	 *
	 * @return string
	 */
	protected function get_cache_group(): string {
		return 'ffc_self_scheduling_appointments';
	}

	/**
	 * Find appointments by calendar ID
	 *
	 * @param int      $calendar_id Calendar ID.
	 * @param int|null $limit Limit.
	 * @param int      $offset Offset.
	 * @return array<int, array<string, mixed>>
	 */
	public function findByCalendar( int $calendar_id, ?int $limit = null, int $offset = 0 ): array {
		return $this->findAll(
			array( 'calendar_id' => $calendar_id ),
			'appointment_date',
			'DESC',
			$limit,
			$offset
		);
	}

	/**
	 * Find appointments by user ID
	 *
	 * @param int                $user_id User ID.
	 * @param array<int, string> $statuses Optional status filter.
	 * @param int|null           $limit Limit.
	 * @param int                $offset Offset.
	 * @return array<int, array<string, mixed>>
	 */
	public function findByUserId( int $user_id, array $statuses = array(), ?int $limit = null, int $offset = 0 ): array {
		$conditions = array( 'user_id' => $user_id );

		if ( ! empty( $statuses ) ) {
			$status_placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

			if ( $limit ) {
				// Single prepare call — avoids double-prepare issues.
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$sql = $this->wpdb->prepare(
					"SELECT * FROM %i WHERE user_id = %d AND status IN ({$status_placeholders}) ORDER BY appointment_date DESC LIMIT %d OFFSET %d",
					array_merge( array( $this->table, $user_id ), $statuses, array( $limit, $offset ) )
				);
			} else {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$sql = $this->wpdb->prepare(
					"SELECT * FROM %i WHERE user_id = %d AND status IN ({$status_placeholders}) ORDER BY appointment_date DESC",
					array_merge( array( $this->table, $user_id ), $statuses )
				);
			}

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$results = $this->wpdb->get_results( $sql, ARRAY_A );
			/**
			 * Cast wpdb result to expected shape.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			return is_array( $results ) ? $results : array();
		}

		return $this->findAll( $conditions, 'appointment_date', 'DESC', $limit, $offset );
	}

	/**
	 * Find appointments by email
	 *
	 * @param string   $email Email address.
	 * @param int|null $limit Limit.
	 * @param int      $offset Offset.
	 * @return array<int, array<string, mixed>>
	 */
	public function findByEmail( string $email, ?int $limit = null, int $offset = 0 ): array {
		// Use Encryption::hash without normalization to match SubmissionHandler convention.
		$email_hash = \FreeFormCertificate\Core\Encryption::hash( $email );
		if ( null === $email_hash ) {
			return array();
		}

		if ( $limit ) {
			$sql = $this->wpdb->prepare(
				'SELECT * FROM %i WHERE email_hash = %s ORDER BY appointment_date DESC LIMIT %d OFFSET %d',
				$this->table,
				$email_hash,
				$limit,
				$offset
			);
		} else {
			$sql = $this->wpdb->prepare(
				'SELECT * FROM %i WHERE email_hash = %s ORDER BY appointment_date DESC',
				$this->table,
				$email_hash
			);
		}

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $this->wpdb->get_results( $sql, ARRAY_A );
		/**
		 * Cast wpdb result to expected shape.
		 *
		 * @var array<int, array<string, mixed>>
		 */
		return is_array( $results ) ? $results : array();
	}

	/**
	 * Find appointments by CPF/RF
	 *
	 * @param string   $cpf_rf Cpf rf.
	 * @param int|null $limit Limit.
	 * @param int      $offset Offset.
	 * @return array<int, array<string, mixed>>
	 */
	public function findByCpfRf( string $cpf_rf, ?int $limit = null, int $offset = 0 ): array {
		$cpf_rf_clean = \FreeFormCertificate\Core\DataSanitizer::normalize_cpf_rf( $cpf_rf );
		$cpf_rf_hash  = \FreeFormCertificate\Core\Encryption::hash( $cpf_rf_clean );
		if ( null === $cpf_rf_hash ) {
			return array();
		}

		// Classify by digit count: 7 digits = RF, else CPF.
		$hash_column = strlen( $cpf_rf_clean ) === 7 ? 'rf_hash' : 'cpf_hash';

		// Search targeted split column first.
		if ( $limit ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql = $this->wpdb->prepare(
				"SELECT * FROM %i WHERE {$hash_column} = %s ORDER BY appointment_date DESC LIMIT %d OFFSET %d",
				$this->table,
				$cpf_rf_hash,
				$limit,
				$offset
			);
		} else {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql = $this->wpdb->prepare(
				"SELECT * FROM %i WHERE {$hash_column} = %s ORDER BY appointment_date DESC",
				$this->table,
				$cpf_rf_hash
			);
		}

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $this->wpdb->get_results( $sql, ARRAY_A );

		/**
		 * Cast wpdb result to expected shape.
		 *
		 * @var array<int, array<string, mixed>>
		 */
		return is_array( $results ) ? $results : array();
	}

	/**
	 * Find appointment by confirmation token
	 *
	 * @param string $token Token.
	 * @return array<string, mixed>|null
	 */
	public function findByConfirmationToken( string $token ): ?array {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE confirmation_token = %s',
				$this->table,
				$token
			),
			ARRAY_A
		);

		return $result ? $result : null;
	}

	/**
	 * Find appointment by validation code
	 *
	 * @param string $validation_code Validation code.
	 * @return array<string, mixed>|null
	 */
	public function findByValidationCode( string $validation_code ): ?array {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE validation_code = %s',
				$this->table,
				strtoupper( $validation_code )
			),
			ARRAY_A
		);

		return $result ? $result : null;
	}

	/**
	 * Get appointments for a specific date and calendar
	 *
	 * @param int                $calendar_id Calendar ID.
	 * @param string             $date Date in Y-m-d format.
	 * @param array<int, string> $statuses Optional status filter (default: confirmed appointments).
	 * @param bool               $use_lock Use FOR UPDATE lock (requires active transaction).
	 * @return array<int, array<string, mixed>>
	 */
	public function getAppointmentsByDate( int $calendar_id, string $date, array $statuses = array( 'confirmed', 'pending' ), bool $use_lock = false ): array {
		$status_placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$lock_clause         = $use_lock ? ' FOR UPDATE' : '';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $this->wpdb->prepare(
			"SELECT * FROM %i
             WHERE calendar_id = %d
             AND appointment_date = %s
             AND status IN ({$status_placeholders})
             ORDER BY start_time ASC{$lock_clause}",
			array_merge( array( $this->table, $calendar_id, $date ), $statuses )
		);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $this->wpdb->get_results( $sql, ARRAY_A );
		/**
		 * Cast wpdb result to expected shape.
		 *
		 * @var array<int, array<string, mixed>>
		 */
		return is_array( $results ) ? $results : array();
	}

	/**
	 * Get appointments for a date range
	 *
	 * @param int                $calendar_id Calendar ID.
	 * @param string             $start_date Start date.
	 * @param string             $end_date End date.
	 * @param array<int, string> $statuses Statuses.
	 * @return array<int, array<string, mixed>>
	 */
	public function getAppointmentsByDateRange( int $calendar_id, string $start_date, string $end_date, array $statuses = array( 'confirmed', 'pending' ) ): array {
		$status_placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $this->wpdb->prepare(
			"SELECT * FROM %i
             WHERE calendar_id = %d
             AND appointment_date BETWEEN %s AND %s
             AND status IN ({$status_placeholders})
             ORDER BY appointment_date ASC, start_time ASC",
			array_merge( array( $this->table, $calendar_id, $start_date, $end_date ), $statuses )
		);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $this->wpdb->get_results( $sql, ARRAY_A );
		/**
		 * Cast wpdb result to expected shape.
		 *
		 * @var array<int, array<string, mixed>>
		 */
		return is_array( $results ) ? $results : array();
	}

	/**
	 * Check if slot is available
	 *
	 * @param int    $calendar_id Calendar ID.
	 * @param string $date Date.
	 * @param string $start_time Start time.
	 * @param int    $max_per_slot Max per slot.
	 * @param bool   $use_lock Use FOR UPDATE lock (requires active transaction).
	 * @return bool
	 */
	public function isSlotAvailable( int $calendar_id, string $date, string $start_time, int $max_per_slot = 1, bool $use_lock = false ): bool {
		$lock_clause = $use_lock ? ' FOR UPDATE' : '';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM %i
                 WHERE calendar_id = %d
                 AND appointment_date = %s
                 AND start_time = %s
                 AND status IN ('confirmed', 'pending'){$lock_clause}",
				$this->table,
				$calendar_id,
				$date,
				$start_time
			)
		);

		return (int) $count < $max_per_slot;
	}

	/**
	 * Get upcoming appointments for reminders
	 *
	 * @param int $hours_before Hours before appointment.
	 * @return array<int, array<string, mixed>>
	 */
	public function getUpcomingForReminders( int $hours_before = 24 ): array {
		$reminder_ts_raw = strtotime( "+{$hours_before} hours" );
		$reminder_ts     = $reminder_ts_raw ? $reminder_ts_raw : time();
		$target_datetime = gmdate( 'Y-m-d H:i:s', $reminder_ts );
		$target_date     = gmdate( 'Y-m-d', $reminder_ts );
		$target_time     = gmdate( 'H:i:s', $reminder_ts );

		$calendars_table = $this->wpdb->prefix . 'ffc_self_scheduling_calendars';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $this->wpdb->prepare(
			'SELECT a.*, c.title as calendar_title, c.email_config
             FROM %i a
             LEFT JOIN %i c ON a.calendar_id = c.id
             WHERE a.status = \'confirmed\'
             AND a.reminder_sent_at IS NULL
             AND a.appointment_date = %s
             AND a.start_time <= %s
             AND a.start_time > DATE_SUB(%s, INTERVAL 1 HOUR)',
			$this->table,
			$calendars_table,
			$target_date,
			$target_time,
			$target_time
		);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $this->wpdb->get_results( $sql, ARRAY_A );
		/**
		 * Cast wpdb result to expected shape.
		 *
		 * @var array<int, array<string, mixed>>
		 */
		return is_array( $results ) ? $results : array();
	}

	/**
	 * Get appointment statistics for calendar
	 *
	 * @param int         $calendar_id Calendar ID.
	 * @param string|null $start_date Start date.
	 * @param string|null $end_date End date.
	 * @return array<string, mixed>
	 */
	public function getStatistics( int $calendar_id, ?string $start_date = null, ?string $end_date = null ): array {
		$base_sql = "SELECT
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN status = 'no_show' THEN 1 ELSE 0 END) as no_show
                 FROM %i
                 WHERE calendar_id = %d";

		if ( $start_date && $end_date ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql = $this->wpdb->prepare(
				"{$base_sql} AND appointment_date BETWEEN %s AND %s",
				$this->table,
				$calendar_id,
				$start_date,
				$end_date
			);
		} else {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql = $this->wpdb->prepare( $base_sql, $this->table, $calendar_id );
		}

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$stats = $this->wpdb->get_row( $sql, ARRAY_A );

		return $stats ? $stats : array(
			'total'     => 0,
			'confirmed' => 0,
			'pending'   => 0,
			'cancelled' => 0,
			'completed' => 0,
			'no_show'   => 0,
		);
	}

	/**
	 * Get booking counts by date range
	 *
	 * @param int    $calendar_id Calendar ID.
	 * @param string $start_date YYYY-MM-DD.
	 * @param string $end_date YYYY-MM-DD.
	 * @return array<string, int> Array with date => count
	 */
	public function getBookingCountsByDateRange( int $calendar_id, string $start_date, string $end_date ): array {
		$table = $this->get_table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT appointment_date, COUNT(*) as count
                FROM %i
                WHERE calendar_id = %d
                AND appointment_date >= %s
                AND appointment_date <= %s
                AND status IN ('confirmed', 'pending')
                GROUP BY appointment_date",
				$table,
				$calendar_id,
				$start_date,
				$end_date
			),
			ARRAY_A
		);

		$counts = array();
		if ( $results ) {
			foreach ( $results as $row ) {
				$counts[ $row['appointment_date'] ] = (int) $row['count'];
			}
		}

		return $counts;
	}

	/**
	 * Aggregate appointment counts grouped by `user_id`, optionally
	 * excluding rows matching one status. Backs the admin Users list
	 * "appointments" column where every row needs its own count.
	 *
	 * Issue #340 centralization.
	 *
	 * @since 6.6.2
	 * @param string|null $exclude_status When non-null, rows with this
	 *                                    status are filtered out (e.g.
	 *                                    `'cancelled'`).
	 * @return array<int, int> Map of `user_id => count`. user_id 0 / NULL
	 *                         rows are skipped.
	 */
	public function countAllByUserGrouped( ?string $exclude_status = null ): array {
		$where = 'user_id IS NOT NULL AND user_id > 0';
		$args  = array( $this->table );
		if ( null !== $exclude_status && '' !== $exclude_status ) {
			$where .= ' AND status != %s';
			$args[] = $exclude_status;
		}

		$sql = "SELECT user_id, COUNT(*) AS c FROM %i WHERE {$where} GROUP BY user_id";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is a compile-time fragment with only known placeholders; values are bound through wpdb->prepare.
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, $args ), ARRAY_A );

		$out = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$out[ (int) $row['user_id'] ] = (int) $row['c'];
			}
		}
		return $out;
	}

	/**
	 * Count appointments for a calendar dated before `$date`. Issue #340.
	 *
	 * @since 6.6.2
	 * @param int    $calendar_id Calendar ID.
	 * @param string $date        Cutoff date (`Y-m-d`).
	 * @return int
	 */
	public function countByCalendarBefore( int $calendar_id, string $date ): int {
		if ( $calendar_id <= 0 || '' === $date ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE calendar_id = %d AND appointment_date < %s',
				$this->table,
				$calendar_id,
				$date
			)
		);
		return null === $count ? 0 : (int) $count;
	}

	/**
	 * Count appointments for a calendar dated on or after `$date`. Issue #340.
	 *
	 * @since 6.6.2
	 * @param int    $calendar_id Calendar ID.
	 * @param string $date        Cutoff date (`Y-m-d`).
	 * @return int
	 */
	public function countByCalendarAfter( int $calendar_id, string $date ): int {
		if ( $calendar_id <= 0 || '' === $date ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE calendar_id = %d AND appointment_date >= %s',
				$this->table,
				$calendar_id,
				$date
			)
		);
		return null === $count ? 0 : (int) $count;
	}

	/**
	 * Find appointments for a calendar dated on or after `$date` whose
	 * status is in the allow-list (defaults to active states). Used by
	 * the calendar-delete flow to know whom to notify. Issue #340.
	 *
	 * @since 6.6.2
	 * @param int           $calendar_id Calendar ID.
	 * @param string        $date        Cutoff date (`Y-m-d`).
	 * @param array<string> $statuses    Status allow-list.
	 * @return array<int, array<string, mixed>>
	 */
	public function findByCalendarAfterWithStatus( int $calendar_id, string $date, array $statuses = array( 'pending', 'confirmed' ) ): array {
		if ( $calendar_id <= 0 || '' === $date || empty( $statuses ) ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$args         = array_merge( array( $this->table, $calendar_id, $date ), array_values( $statuses ) );
		$sql          = "SELECT * FROM %i WHERE calendar_id = %d AND appointment_date >= %s AND status IN ({$placeholders}) ORDER BY appointment_date ASC, start_time ASC";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is a compile-time string of %s tokens whose count matches the args supplied to wpdb->prepare.
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, $args ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Find the next upcoming appointment for a user — date >= today
	 * AND status ∈ allow-list, ordered by date+time ASC, single row.
	 * Used by the user-summary REST endpoint to surface "your next
	 * appointment" on the dashboard. Issue #340.
	 *
	 * Returns just the appointment row (no calendar JOIN) — the
	 * caller can fetch the calendar title via `CalendarRepository`
	 * if it needs to display it, keeping this repository's queries
	 * single-table.
	 *
	 * @since 6.6.2
	 * @param int           $user_id  WP user id.
	 * @param array<string> $statuses Status allow-list.
	 * @return array<string, mixed>|null
	 */
	public function findNextUpcomingForUser( int $user_id, array $statuses = array( 'pending', 'confirmed' ) ): ?array {
		if ( $user_id <= 0 || empty( $statuses ) ) {
			return null;
		}
		$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$args         = array_merge( array( $this->table, $user_id ), array_values( $statuses ) );
		$sql          = "SELECT * FROM %i WHERE user_id = %d AND status IN ({$placeholders}) AND appointment_date >= CURDATE() ORDER BY appointment_date ASC, start_time ASC LIMIT 1";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is a compile-time string of %s tokens whose count matches the args supplied to wpdb->prepare.
		$row = $this->wpdb->get_row( $this->wpdb->prepare( $sql, $args ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Build the shared WHERE clause + bind values for the batched CSV export.
	 * Mirrors the filters the export UI offers: calendar id(s), status(es), and a
	 * date window. `$args` is seeded with the table identifier so the caller can
	 * hand it straight to `$wpdb->prepare( $sql, $args )` (single-array form — no
	 * spread — so no `argument.type` widening). Returns an untyped tuple
	 * deliberately (no `@phpstan-return array{0: string}`) so `$where_clause`
	 * stays a plain `string` and the interpolated prepare keeps passing.
	 *
	 * @param array<int, int>|null $calendar_ids Calendar id(s), or null for all.
	 * @param array<int, string>   $statuses     Status filter (empty = all).
	 * @param string|null          $start_date   Start date (`Y-m-d`).
	 * @param string|null          $end_date     End date (`Y-m-d`).
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	private function build_export_where( ?array $calendar_ids, array $statuses, ?string $start_date, ?string $end_date ): array {
		$where = array();
		$args  = array( $this->table );

		if ( ! empty( $calendar_ids ) ) {
			$calendar_ids_int = array_map( 'absint', $calendar_ids );
			$placeholders     = implode( ',', array_fill( 0, count( $calendar_ids_int ), '%d' ) );
			$where[]          = "calendar_id IN ({$placeholders})";
			$args             = array_merge( $args, $calendar_ids_int );
		}

		if ( ! empty( $statuses ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
			$where[]      = "status IN ({$placeholders})";
			$args         = array_merge( $args, array_values( $statuses ) );
		}

		if ( $start_date && $end_date ) {
			$where[] = 'appointment_date BETWEEN %s AND %s';
			$args[]  = $start_date;
			$args[]  = $end_date;
		} elseif ( $start_date ) {
			$where[] = 'appointment_date >= %s';
			$args[]  = $start_date;
		} elseif ( $end_date ) {
			$where[] = 'appointment_date <= %s';
			$args[]  = $end_date;
		}

		$where_clause = ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '';

		return array( $where_clause, $args );
	}

	/**
	 * Count matching appointments for the batched CSV export's progress total.
	 *
	 * @since 6.17.0
	 * @param array<int, int>|null $calendar_ids Calendar id(s), or null for all.
	 * @param array<int, string>   $statuses     Status filter (empty = all).
	 * @param string|null          $start_date   Start date (`Y-m-d`).
	 * @param string|null          $end_date     End date (`Y-m-d`).
	 * @return int
	 */
	public function countForExport( ?array $calendar_ids, array $statuses, ?string $start_date, ?string $end_date ): int {
		list( $where_clause, $args ) = $this->build_export_where( $calendar_ids, $statuses, $start_date, $end_date );

		$sql = "SELECT COUNT(*) FROM %i {$where_clause}";
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $where_clause is built from validated placeholders; values are bound through wpdb->prepare.
		/**
		 * $args is a merged identifier + value list bound by wpdb->prepare.
		 *
		 * @phpstan-ignore-next-line argument.type
		 */
		$sql = $this->wpdb->prepare( $sql, ...$args );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $this->wpdb->get_var( $sql );
	}

	/**
	 * Keyset page for the batched CSV export: rows with `id < $cursor_id`, newest
	 * first (`id DESC`), limited to `$limit`. Keyset (not LIMIT/OFFSET) so paging
	 * stays stable across concurrent inserts during a long export.
	 *
	 * @since 6.17.0
	 * @param array<int, int>|null $calendar_ids Calendar id(s), or null for all.
	 * @param array<int, string>   $statuses     Status filter (empty = all).
	 * @param string|null          $start_date   Start date (`Y-m-d`).
	 * @param string|null          $end_date     End date (`Y-m-d`).
	 * @param int                  $cursor_id    Exclusive upper-bound id (PHP_INT_MAX on the first page).
	 * @param int                  $limit        Page size.
	 * @return array<int, array<string, mixed>>
	 */
	public function getExportBatch( ?array $calendar_ids, array $statuses, ?string $start_date, ?string $end_date, int $cursor_id, int $limit ): array {
		list( $where_clause, $args ) = $this->build_export_where( $calendar_ids, $statuses, $start_date, $end_date );

		$where_clause = '' === $where_clause ? 'WHERE id < %d' : $where_clause . ' AND id < %d';
		$args[]       = $cursor_id;
		$args[]       = $limit;

		$sql = "SELECT * FROM %i {$where_clause} ORDER BY id DESC LIMIT %d";
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $where_clause is built from validated placeholders; values are bound through wpdb->prepare.
		/**
		 * $args is a merged identifier + value list bound by wpdb->prepare.
		 *
		 * @phpstan-ignore-next-line argument.type
		 */
		$sql = $this->wpdb->prepare( $sql, ...$args );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->wpdb->get_results( $sql, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Lightweight keyset page selecting only the JSON columns, for dynamic-key
	 * discovery (the header needs the union of every row's `custom_data` keys).
	 * Skips the encrypted/heavy columns.
	 *
	 * @since 6.17.0
	 * @param array<int, int>|null $calendar_ids Calendar id(s), or null for all.
	 * @param array<int, string>   $statuses     Status filter (empty = all).
	 * @param string|null          $start_date   Start date (`Y-m-d`).
	 * @param string|null          $end_date     End date (`Y-m-d`).
	 * @param int                  $cursor_id    Exclusive upper-bound id (PHP_INT_MAX on the first page).
	 * @param int                  $limit        Page size.
	 * @return array<int, array<string, mixed>>
	 */
	public function getExportKeysBatch( ?array $calendar_ids, array $statuses, ?string $start_date, ?string $end_date, int $cursor_id, int $limit ): array {
		list( $where_clause, $args ) = $this->build_export_where( $calendar_ids, $statuses, $start_date, $end_date );

		$where_clause = '' === $where_clause ? 'WHERE id < %d' : $where_clause . ' AND id < %d';
		$args[]       = $cursor_id;
		$args[]       = $limit;

		$sql = "SELECT id, custom_data, custom_data_encrypted FROM %i {$where_clause} ORDER BY id DESC LIMIT %d";
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $where_clause is built from validated placeholders; values are bound through wpdb->prepare.
		/**
		 * $args is a merged identifier + value list bound by wpdb->prepare.
		 *
		 * @phpstan-ignore-next-line argument.type
		 */
		$sql = $this->wpdb->prepare( $sql, ...$args );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->wpdb->get_results( $sql, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * SQL fragment for the WP_User_Query orderby rewrite that sorts
	 * users by "non-cancelled appointments they own". Returns a
	 * self-contained SELECT subquery — the caller wraps it as
	 * `LEFT JOIN (subquery) AS {alias} ON {alias}.user_id = {wp_users}.ID`.
	 *
	 * Table name is baked in here so the admin layer doesn't have to
	 * `$wpdb->prefix . 'ffc_…'`. The output is intentionally a raw SQL
	 * string because WP_User_Query's `query_from` extension hook only
	 * accepts that — issue #343 group C.
	 *
	 * @since 6.6.2
	 * @return string SQL subquery fragment (already including the
	 *                surrounding parentheses).
	 */
	public function sql_user_appointment_count_subquery(): string {
		return "(SELECT user_id, COUNT(*) AS cnt FROM {$this->table} WHERE user_id IS NOT NULL AND status != 'cancelled' GROUP BY user_id)";
	}
}
