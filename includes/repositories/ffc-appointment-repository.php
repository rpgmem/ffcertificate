<?php
/**
 * Appointment Repository
 *
 * Data access layer for appointment operations.
 * Follows Repository pattern for separation of concerns.
 *
 * @package FreeFormCertificate\Repositories
 * @since 4.1.0
 * @version 4.6.10 - Added FOR UPDATE lock support for concurrent booking safety
 */

declare(strict_types=1);

namespace FreeFormCertificate\Repositories;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
/**
 * Database repository for appointment records.
 */
class AppointmentRepository extends AbstractRepository {

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
	 * Cancel appointment
	 *
	 * @param int         $id Record ID.
	 * @param int|null    $cancelled_by User ID who cancelled.
	 * @param string|null $reason Cancellation reason.
	 * @return int|false
	 */
	public function cancel( int $id, ?int $cancelled_by = null, ?string $reason = null ) {
		return $this->update(
			$id,
			array(
				'status'              => 'cancelled',
				// `cancelled_at` is unix UTC int since 6.6.0 (#249 sub-escopo d).
				'cancelled_at'        => time(),
				'cancelled_by'        => $cancelled_by,
				'cancellation_reason' => $reason,
				'updated_at'          => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Confirm appointment (admin approval)
	 *
	 * @param int      $id Record ID.
	 * @param int|null $approved_by User ID who approved.
	 * @return int|false
	 */
	public function confirm( int $id, ?int $approved_by = null ) {
		return $this->update(
			$id,
			array(
				'status'      => 'confirmed',
				// `approved_at` is unix UTC int since 6.6.0 (#249 sub-escopo d).
				'approved_at' => time(),
				'approved_by' => $approved_by,
				'updated_at'  => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Mark as completed
	 *
	 * @param int $id Record ID.
	 * @return int|false
	 */
	public function markCompleted( int $id ) {
		return $this->update(
			$id,
			array(
				'status'     => 'completed',
				'updated_at' => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Mark as no-show
	 *
	 * @param int $id Record ID.
	 * @return int|false
	 */
	public function markNoShow( int $id ) {
		return $this->update(
			$id,
			array(
				'status'     => 'no_show',
				'updated_at' => current_time( 'mysql' ),
			)
		);
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
	 * Mark reminder as sent
	 *
	 * @param int $id Record ID.
	 * @return int|false
	 */
	public function markReminderSent( int $id ) {
		return $this->update(
			$id,
			array(
				// `reminder_sent_at` is unix UTC int since 6.6.0 (#249 sub-escopo d).
				'reminder_sent_at' => time(),
			)
		);
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
	 * Create appointment with encryption support
	 *
	 * @param array<string, mixed> $data Data.
	 * @return int|false
	 */
	public function createAppointment( array $data ) {
		// Normalize cpf_rf → split cpf/rf before the registry sees it; the
		// registry knows about the split columns, not the combined input.
		if ( ! empty( $data['cpf_rf'] ) ) {
			$clean_id = \FreeFormCertificate\Core\DataSanitizer::normalize_cpf_rf( (string) $data['cpf_rf'] );
			if ( '' !== $clean_id ) {
				if ( 7 === strlen( $clean_id ) ) {
					$data['rf'] = $clean_id;
				} else {
					// 11 digits (CPF) or unknown length — default to CPF.
					$data['cpf'] = $clean_id;
				}
			}
		}

		// custom_data may arrive as an array; the registry expects a scalar.
		if ( isset( $data['custom_data'] ) && is_array( $data['custom_data'] ) ) {
			$data['custom_data'] = wp_json_encode( $data['custom_data'] );
		}

		$encrypted_columns = \FreeFormCertificate\Core\SensitiveFieldRegistry::encrypt_fields(
			\FreeFormCertificate\Core\SensitiveFieldRegistry::CONTEXT_APPOINTMENT,
			$data
		);
		$data              = array_merge( $data, $encrypted_columns );

		// Always strip plaintext from the row (whether encrypted above or
		// left in place because encryption is disabled). wpdb->insert would
		// fail with "Unknown column" otherwise, and storing plaintext breaks
		// LGPD when keys are present.
		foreach ( \FreeFormCertificate\Core\SensitiveFieldRegistry::plaintext_keys(
			\FreeFormCertificate\Core\SensitiveFieldRegistry::CONTEXT_APPOINTMENT
		) as $plain_key ) {
			unset( $data[ $plain_key ] );
		}
		unset( $data['cpf_rf'] );

		// Generate confirmation token for all appointments (allows receipt access without login).
		if ( empty( $data['confirmation_token'] ) ) {
			$data['confirmation_token'] = bin2hex( random_bytes( 32 ) );
		}

		// Generate validation code for all appointments (user-friendly code like certificates).
		if ( empty( $data['validation_code'] ) ) {
			$data['validation_code'] = $this->generate_unique_validation_code();
		}

		// Set timestamps.
		if ( empty( $data['created_at'] ) ) {
			$data['created_at'] = current_time( 'mysql' );
		}

		return $this->insert( $data );
	}

	/**
	 * Generate unique validation code
	 *
	 * Generates a 12-character alphanumeric code (stored without hyphens).
	 * Use DocumentFormatter::format_auth_code() to display with hyphens (XXXX-XXXX-XXXX).
	 *
	 * @return string 12-character code without hyphens
	 */
	private function generate_unique_validation_code(): string {
		return \FreeFormCertificate\Core\AuthCodeService::generate_globally_unique_auth_code();
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
	 * Delete every appointment row for a calendar. Issue #340.
	 *
	 * @since 6.6.2
	 * @param int $calendar_id Calendar ID.
	 * @return int Rows deleted (0 when prepare/query fails).
	 */
	public function deleteByCalendar( int $calendar_id ): int {
		if ( $calendar_id <= 0 ) {
			return 0;
		}
		$sql = $this->wpdb->prepare( 'DELETE FROM %i WHERE calendar_id = %d', $this->table, $calendar_id );
		if ( ! is_string( $sql ) ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->wpdb->query( $sql );
		$this->clear_cache();
		return is_int( $rows ) ? $rows : 0;
	}

	/**
	 * Delete every appointment for a calendar dated strictly before
	 * `$date` (Y-m-d). Issue #340.
	 *
	 * @since 6.6.2
	 * @param int    $calendar_id Calendar ID.
	 * @param string $date        Cutoff date (`Y-m-d`).
	 * @return int Rows deleted.
	 */
	public function deleteByCalendarBefore( int $calendar_id, string $date ): int {
		if ( $calendar_id <= 0 || '' === $date ) {
			return 0;
		}
		$sql = $this->wpdb->prepare(
			'DELETE FROM %i WHERE calendar_id = %d AND appointment_date < %s',
			$this->table,
			$calendar_id,
			$date
		);
		if ( ! is_string( $sql ) ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->wpdb->query( $sql );
		$this->clear_cache();
		return is_int( $rows ) ? $rows : 0;
	}

	/**
	 * Delete every appointment for a calendar dated on or after `$date`
	 * (Y-m-d). Issue #340.
	 *
	 * @since 6.6.2
	 * @param int    $calendar_id Calendar ID.
	 * @param string $date        Cutoff date (`Y-m-d`).
	 * @return int Rows deleted.
	 */
	public function deleteByCalendarAfter( int $calendar_id, string $date ): int {
		if ( $calendar_id <= 0 || '' === $date ) {
			return 0;
		}
		$sql = $this->wpdb->prepare(
			'DELETE FROM %i WHERE calendar_id = %d AND appointment_date >= %s',
			$this->table,
			$calendar_id,
			$date
		);
		if ( ! is_string( $sql ) ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->wpdb->query( $sql );
		$this->clear_cache();
		return is_int( $rows ) ? $rows : 0;
	}

	/**
	 * Delete every appointment for a calendar matching a specific
	 * status (e.g. `'cancelled'`). Issue #340.
	 *
	 * @since 6.6.2
	 * @param int    $calendar_id Calendar ID.
	 * @param string $status      Status to delete (matches column verbatim).
	 * @return int Rows deleted.
	 */
	public function deleteByCalendarAndStatus( int $calendar_id, string $status ): int {
		if ( $calendar_id <= 0 || '' === $status ) {
			return 0;
		}
		$sql = $this->wpdb->prepare(
			'DELETE FROM %i WHERE calendar_id = %d AND status = %s',
			$this->table,
			$calendar_id,
			$status
		);
		if ( ! is_string( $sql ) ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->wpdb->query( $sql );
		$this->clear_cache();
		return is_int( $rows ) ? $rows : 0;
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
	 * Bulk-link anonymous appointment rows (`user_id IS NULL`) to a
	 * just-created WP user by matching a hash column (e.g. `email_hash`,
	 * `cpf_hash`). Used by `UserCreator` after promotion. Issue #340.
	 *
	 * The `$hash_column` value is validated against an allow-list so it
	 * never reaches SQL as raw operator input — only the four
	 * `*_hash` columns the schema actually carries are accepted.
	 *
	 * @since 6.6.2
	 * @param int    $user_id     Newly-created WP user id.
	 * @param string $hash_column Hash column to match against (one of
	 *                            `email_hash`, `cpf_hash`, `rf_hash`,
	 *                            `ticket_hash`).
	 * @param string $hash_value  Hash digest.
	 * @return int Rows linked (0 when inputs invalid or no match).
	 */
	public function linkByIdentifierHash( int $user_id, string $hash_column, string $hash_value ): int {
		if ( $user_id <= 0 || '' === $hash_value ) {
			return 0;
		}
		$allowed = array( 'email_hash', 'cpf_hash', 'rf_hash', 'ticket_hash' );
		if ( ! in_array( $hash_column, $allowed, true ) ) {
			return 0;
		}
		$sql = $this->wpdb->prepare(
			"UPDATE %i SET user_id = %d WHERE {$hash_column} = %s AND user_id IS NULL",
			$this->table,
			$user_id,
			$hash_value
		);
		if ( ! is_string( $sql ) ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $hash_column is verified against a fixed allow-list above; values are bound through wpdb->prepare.
		$rows = $this->wpdb->query( $sql );
		$this->clear_cache();
		return is_int( $rows ) ? $rows : 0;
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
}
