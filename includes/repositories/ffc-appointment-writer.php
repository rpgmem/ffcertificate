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
 * @since   6.11.3
 */

declare(strict_types=1);

namespace FreeFormCertificate\Repositories;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
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
}
