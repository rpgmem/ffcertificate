<?php
/**
 * Appointment Writer
 *
 * Write-side of the appointment repository split (#563 backlog, A6). Holds every
 * domain mutation (status transitions, creation, bulk deletes, anonymous linking)
 * plus the write-only validation-code helper. Reads live in {@see AppointmentReader};
 * {@see AppointmentRepository} remains the public façade that delegates to both.
 *
 * Extends AbstractRepository so it reuses the same wpdb binding, table name, cache
 * group and inherited insert/update/clear_cache helpers — the global $wpdb shared
 * across the façade/reader/writer keeps transactions and FOR UPDATE locks coherent.
 *
 * @package FreeFormCertificate\Repositories
 * @since   6.12.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Repositories;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $sql is the return value of $wpdb->prepare() a few lines above and the sniff cannot follow a prepared string across an assignment; {$hash_column} is a ternary over two literal column names.
/**
 * Write operations for appointment records.
 */
class AppointmentWriter extends AbstractRepository {

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
	 * Confirm an appointment that is still pending (#2244).
	 *
	 * The status is in the WHERE clause, so a row another request confirmed,
	 * cancelled or moved meanwhile is left alone and the write reports 0 rows.
	 *
	 * @param int      $id          Record ID.
	 * @param int|null $approved_by User ID who approved.
	 * @return int|false Rows changed, or false on a database error.
	 */
	public function confirmPending( int $id, ?int $approved_by = null ) {
		return $this->transition(
			$id,
			'pending',
			'confirmed',
			array(
				'approved_at' => time(),
				'approved_by' => $approved_by,
			)
		);
	}

	/**
	 * Cancel an appointment only if it still carries $from (#2244).
	 *
	 * @param int         $id           Record ID.
	 * @param string      $from         Status the caller read on the row.
	 * @param int|null    $cancelled_by User ID who cancelled.
	 * @param string|null $reason       Cancellation reason.
	 * @return int|false Rows changed (0 when the row moved meanwhile), or false
	 *                   on a database error.
	 */
	public function cancelFrom( int $id, string $from, ?int $cancelled_by = null, ?string $reason = null ) {
		return $this->transition(
			$id,
			$from,
			'cancelled',
			array(
				'cancelled_at'        => time(),
				'cancelled_by'        => $cancelled_by,
				'cancellation_reason' => $reason,
			)
		);
	}

	/**
	 * Promote a waitlisted appointment when a spot frees up (#941 phase 2).
	 *
	 * Moves a `waitlist` row into the active pool: `confirmed` when the calendar
	 * auto-confirms, or `pending` when it requires manual approval (so the admin
	 * still reviews the promoted booking). Only the confirmed path stamps
	 * `approved_at`.
	 *
	 * @param int  $id Record ID.
	 * @param bool $requires_approval Whether the calendar requires manual approval.
	 * @return int|false
	 */
	public function promoteFromWaitlist( int $id, bool $requires_approval ) {
		if ( $requires_approval ) {
			return $this->update(
				$id,
				array(
					'status'     => 'pending',
					'updated_at' => current_time( 'mysql' ),
				)
			);
		}

		return $this->update(
			$id,
			array(
				'status'      => 'confirmed',
				// `approved_at` is unix UTC int since 6.6.0 (#249 sub-escopo d).
				'approved_at' => time(),
				'updated_at'  => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * The statuses an attendance mark writes over a confirmed appointment.
	 */
	public const ATTENDANCE_STATUSES = array( 'completed', 'no_show' );

	/**
	 * Mark a confirmed appointment as attended.
	 *
	 * @param int $id Record ID.
	 * @return int|false Rows changed (0 when the appointment is no longer
	 *                   confirmed), or false on a database error.
	 */
	public function markCompleted( int $id ) {
		return $this->transition( $id, 'confirmed', 'completed' );
	}

	/**
	 * Mark a confirmed appointment as a no-show.
	 *
	 * @param int $id Record ID.
	 * @return int|false Rows changed (0 when the appointment is no longer
	 *                   confirmed), or false on a database error.
	 */
	public function markNoShow( int $id ) {
		return $this->transition( $id, 'confirmed', 'no_show' );
	}

	/**
	 * Undo an attendance mark, putting the appointment back to confirmed.
	 *
	 * @param int    $id   Record ID.
	 * @param string $from The attendance status the caller read on the row.
	 * @return int|false Rows changed (0 when the row no longer carries $from),
	 *                   or false on a database error or a $from that is not
	 *                   an attendance status.
	 */
	public function revertAttendance( int $id, string $from ) {
		if ( ! in_array( $from, self::ATTENDANCE_STATUSES, true ) ) {
			return false;
		}
		return $this->transition( $id, $from, 'confirmed' );
	}

	/**
	 * Move an appointment from one status to another, only if it still
	 * carries the first one.
	 *
	 * The status is part of the WHERE clause, so a row another request moved
	 * meanwhile is left alone and the write reports 0 rows.
	 *
	 * @param int                  $id     Record ID.
	 * @param string               $from   Status the row must carry.
	 * @param string               $to     Status to write.
	 * @param array<string, mixed> $fields Further columns written with it.
	 * @return int|false
	 */
	private function transition( int $id, string $from, string $to, array $fields = array() ) {
		$result = $this->wpdb->update(
			$this->table,
			array_merge(
				$fields,
				array(
					'status'     => $to,
					'updated_at' => current_time( 'mysql' ),
				)
			),
			array(
				'id'     => $id,
				'status' => $from,
			)
		);

		if ( false === $result ) {
			$this->log_db_error( 'update', $id );
		} else {
			$this->clear_cache( "id_{$id}" );
		}

		return $result;
	}

	/**
	 * Mark one batch of confirmed appointments dated on or before $cutoff as
	 * completed (the old-appointment close-out).
	 *
	 * The status is in the WHERE clause, so a row closed by this batch leaves
	 * the set the next batch reads, and a caller looping until a batch changes
	 * fewer rows than it asked for always stops.
	 *
	 * @param string $cutoff Last appointment date to close, `Y-m-d` (wall-clock).
	 * @param int    $limit  Rows per batch.
	 * @return int|false Rows changed, or false on a database error.
	 */
	public function completeConfirmedUpTo( string $cutoff, int $limit ) {
		$sql = $this->wpdb->prepare(
			"UPDATE %i SET status = 'completed', updated_at = %s
             WHERE status = 'confirmed' AND appointment_date <= %s
             ORDER BY id LIMIT %d",
			$this->table,
			current_time( 'mysql' ),
			$cutoff,
			$limit
		);
		return $this->run_closeout( $sql );
	}

	/**
	 * Cancel one batch of pending appointments dated on or before $cutoff:
	 * nobody approved them, and their date has gone.
	 *
	 * @param string $cutoff Last appointment date to close, `Y-m-d` (wall-clock).
	 * @param string $reason Cancellation reason stored on each row.
	 * @param int    $limit  Rows per batch.
	 * @return int|false Rows changed, or false on a database error.
	 */
	public function expirePendingUpTo( string $cutoff, string $reason, int $limit ) {
		$sql = $this->wpdb->prepare(
			"UPDATE %i SET status = 'cancelled', cancelled_at = %d, cancelled_by = NULL,
             cancellation_reason = %s, updated_at = %s
             WHERE status = 'pending' AND appointment_date <= %s
             ORDER BY id LIMIT %d",
			$this->table,
			time(),
			$reason,
			current_time( 'mysql' ),
			$cutoff,
			$limit
		);
		return $this->run_closeout( $sql );
	}

	/**
	 * Run one close-out UPDATE and drop the group cache when it changed rows.
	 *
	 * @param string|null $sql Prepared statement.
	 * @return int|false
	 */
	private function run_closeout( ?string $sql ) {
		if ( ! is_string( $sql ) ) {
			return false;
		}
		$rows = $this->wpdb->query( $sql );
		if ( ! is_int( $rows ) ) {
			$this->log_db_error( 'update' );
			return false;
		}
		if ( $rows > 0 ) {
			$this->clear_cache();
		}
		return $rows;
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
				if ( 'rf' === \FreeFormCertificate\Core\DataSanitizer::classify_cpf_rf( $clean_id ) ) {
					$data['rf'] = $clean_id;
				} else {
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
	 * Delete the appointments whose ids are given (#1872).
	 *
	 * The calendar's Clean Up box reads the ids a criterion reaches and then
	 * deletes exactly those, so what the activity log records is what went.
	 * Replaces the four criterion deletes (`deleteByCalendar*`, #340), which
	 * had no caller left once the box started naming its records.
	 *
	 * @since 6.41.0
	 * @param array<int, int> $ids Appointment ids.
	 * @return int Rows deleted (0 when nothing valid was given).
	 */
	public function deleteByIds( array $ids ): int {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn( int $id ): bool => $id > 0 ) ) );
		if ( empty( $ids ) ) {
			return 0;
		}

		$deleted = 0;
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			$sql          = $this->wpdb->prepare( "DELETE FROM %i WHERE id IN ({$placeholders})", array_merge( array( $this->table ), $chunk ) );
			if ( ! is_string( $sql ) ) {
				continue;
			}
			$rows     = $this->wpdb->query( $sql );
			$deleted += is_int( $rows ) ? $rows : 0;
		}
		$this->clear_cache();
		return $deleted;
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
		$rows = $this->wpdb->query( $sql );
		$this->clear_cache();
		return is_int( $rows ) ? $rows : 0;
	}
}
