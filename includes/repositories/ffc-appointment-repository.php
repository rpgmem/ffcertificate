<?php
/**
 * Appointment Repository
 *
 * Data access layer for appointment operations.
 * Follows Repository pattern for separation of concerns.
 *
 * Since the #563 backlog read/write split (A6) this class is a thin façade:
 * domain reads live in {@see AppointmentReader}, domain writes in
 * {@see AppointmentWriter}. The generic CRUD (findById/insert/update/delete/…)
 * inherited from {@see AbstractRepository} stays here so existing callers that
 * use it directly are unaffected. The façade, reader and writer all bind the
 * same global $wpdb, so transactions and FOR UPDATE locks remain coherent.
 *
 * Design note (#563 B3-A): unlike the static repository façades (retired in
 * B3-A), this instance façade is kept by design. It is the transactional
 * aggregate root — `begin_transaction()` → `FOR UPDATE` read → write →
 * `commit()` run on the one shared $wpdb the façade, reader and writer all
 * bind — so it must NOT be retired into separate reader/writer call sites.
 *
 * @package FreeFormCertificate\Repositories
 * @since 4.1.0
 * @version 4.6.10 - Added FOR UPDATE lock support for concurrent booking safety
 */

declare(strict_types=1);

namespace FreeFormCertificate\Repositories;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/**
 * Public façade over {@see AppointmentReader} + {@see AppointmentWriter}.
 */
class AppointmentRepository extends AbstractRepository {

	/**
	 * Read-side collaborator.
	 *
	 * @var AppointmentReader
	 */
	private AppointmentReader $reader;

	/**
	 * Write-side collaborator.
	 *
	 * @var AppointmentWriter
	 */
	private AppointmentWriter $writer;

	/**
	 * Constructor — wires up the read/write collaborators.
	 */
	public function __construct() {
		parent::__construct();
		$this->reader = new AppointmentReader();
		$this->writer = new AppointmentWriter();
	}

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

	// ─────────────────────────────────────────────.
	// Reads — delegate to AppointmentReader.
	// ─────────────────────────────────────────────.

	/**
	 * Find appointments by calendar ID
	 *
	 * @param int      $calendar_id Calendar ID.
	 * @param int|null $limit Limit.
	 * @param int      $offset Offset.
	 * @return array<int, array<string, mixed>>
	 */
	public function findByCalendar( int $calendar_id, ?int $limit = null, int $offset = 0 ): array {
		return $this->reader->findByCalendar( $calendar_id, $limit, $offset );
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
		return $this->reader->findByUserId( $user_id, $statuses, $limit, $offset );
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
		return $this->reader->findByEmail( $email, $limit, $offset );
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
		return $this->reader->findByCpfRf( $cpf_rf, $limit, $offset );
	}

	/**
	 * Find appointment by confirmation token
	 *
	 * @param string $token Token.
	 * @return array<string, mixed>|null
	 */
	public function findByConfirmationToken( string $token ): ?array {
		return $this->reader->findByConfirmationToken( $token );
	}

	/**
	 * Find appointment by validation code
	 *
	 * @param string $validation_code Validation code.
	 * @return array<string, mixed>|null
	 */
	public function findByValidationCode( string $validation_code ): ?array {
		return $this->reader->findByValidationCode( $validation_code );
	}

	/**
	 * Get appointments for a specific date and calendar
	 *
	 * @param int                $calendar_id Calendar ID.
	 * @param string             $date Date in Y-m-d format.
	 * @param array<int, string> $statuses Optional status filter.
	 * @param bool               $use_lock Use FOR UPDATE lock (requires active transaction).
	 * @return array<int, array<string, mixed>>
	 */
	public function getAppointmentsByDate( int $calendar_id, string $date, array $statuses = array( 'confirmed', 'pending' ), bool $use_lock = false ): array {
		return $this->reader->getAppointmentsByDate( $calendar_id, $date, $statuses, $use_lock );
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
		return $this->reader->getAppointmentsByDateRange( $calendar_id, $start_date, $end_date, $statuses );
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
		return $this->reader->isSlotAvailable( $calendar_id, $date, $start_time, $max_per_slot, $use_lock );
	}

	/**
	 * Get upcoming appointments for reminders
	 *
	 * @param int $hours_before Hours before appointment.
	 * @return array<int, array<string, mixed>>
	 */
	public function getUpcomingForReminders( int $hours_before = 24 ): array {
		return $this->reader->getUpcomingForReminders( $hours_before );
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
		return $this->reader->getStatistics( $calendar_id, $start_date, $end_date );
	}

	/**
	 * Get booking counts by date range
	 *
	 * @param int    $calendar_id Calendar ID.
	 * @param string $start_date YYYY-MM-DD.
	 * @param string $end_date YYYY-MM-DD.
	 * @return array<string, int>
	 */
	public function getBookingCountsByDateRange( int $calendar_id, string $start_date, string $end_date ): array {
		return $this->reader->getBookingCountsByDateRange( $calendar_id, $start_date, $end_date );
	}

	/**
	 * Aggregate appointment counts grouped by `user_id`.
	 *
	 * @since 6.6.2
	 * @param string|null $exclude_status Status to filter out, or null.
	 * @return array<int, int>
	 */
	public function countAllByUserGrouped( ?string $exclude_status = null ): array {
		return $this->reader->countAllByUserGrouped( $exclude_status );
	}

	/**
	 * Count appointments for a calendar dated before `$date`.
	 *
	 * @since 6.6.2
	 * @param int    $calendar_id Calendar ID.
	 * @param string $date        Cutoff date (`Y-m-d`).
	 * @return int
	 */
	public function countByCalendarBefore( int $calendar_id, string $date ): int {
		return $this->reader->countByCalendarBefore( $calendar_id, $date );
	}

	/**
	 * Count appointments for a calendar dated on or after `$date`.
	 *
	 * @since 6.6.2
	 * @param int    $calendar_id Calendar ID.
	 * @param string $date        Cutoff date (`Y-m-d`).
	 * @return int
	 */
	public function countByCalendarAfter( int $calendar_id, string $date ): int {
		return $this->reader->countByCalendarAfter( $calendar_id, $date );
	}

	/**
	 * Find appointments for a calendar dated on or after `$date` whose
	 * status is in the allow-list.
	 *
	 * @since 6.6.2
	 * @param int           $calendar_id Calendar ID.
	 * @param string        $date        Cutoff date (`Y-m-d`).
	 * @param array<string> $statuses    Status allow-list.
	 * @return array<int, array<string, mixed>>
	 */
	public function findByCalendarAfterWithStatus( int $calendar_id, string $date, array $statuses = array( 'pending', 'confirmed' ) ): array {
		return $this->reader->findByCalendarAfterWithStatus( $calendar_id, $date, $statuses );
	}

	/**
	 * Find the next upcoming appointment for a user.
	 *
	 * @since 6.6.2
	 * @param int           $user_id  WP user id.
	 * @param array<string> $statuses Status allow-list.
	 * @return array<string, mixed>|null
	 */
	public function findNextUpcomingForUser( int $user_id, array $statuses = array( 'pending', 'confirmed' ) ): ?array {
		return $this->reader->findNextUpcomingForUser( $user_id, $statuses );
	}

	/**
	 * SQL fragment for the WP_User_Query orderby rewrite.
	 *
	 * @since 6.6.2
	 * @return string
	 */
	public function sql_user_appointment_count_subquery(): string {
		return $this->reader->sql_user_appointment_count_subquery();
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
		return $this->reader->countForExport( $calendar_ids, $statuses, $start_date, $end_date );
	}

	/**
	 * Keyset page (id-cursor) for the batched CSV export.
	 *
	 * @since 6.17.0
	 * @param array<int, int>|null $calendar_ids Calendar id(s), or null for all.
	 * @param array<int, string>   $statuses     Status filter (empty = all).
	 * @param string|null          $start_date   Start date (`Y-m-d`).
	 * @param string|null          $end_date     End date (`Y-m-d`).
	 * @param int                  $cursor_id    Exclusive upper-bound id.
	 * @param int                  $limit        Page size.
	 * @return array<int, array<string, mixed>>
	 */
	public function getExportBatch( ?array $calendar_ids, array $statuses, ?string $start_date, ?string $end_date, int $cursor_id, int $limit ): array {
		return $this->reader->getExportBatch( $calendar_ids, $statuses, $start_date, $end_date, $cursor_id, $limit );
	}

	/**
	 * Lightweight keyset page of only the JSON columns, for dynamic-key discovery.
	 *
	 * @since 6.17.0
	 * @param array<int, int>|null $calendar_ids Calendar id(s), or null for all.
	 * @param array<int, string>   $statuses     Status filter (empty = all).
	 * @param string|null          $start_date   Start date (`Y-m-d`).
	 * @param string|null          $end_date     End date (`Y-m-d`).
	 * @param int                  $cursor_id    Exclusive upper-bound id.
	 * @param int                  $limit        Page size.
	 * @return array<int, array<string, mixed>>
	 */
	public function getExportKeysBatch( ?array $calendar_ids, array $statuses, ?string $start_date, ?string $end_date, int $cursor_id, int $limit ): array {
		return $this->reader->getExportKeysBatch( $calendar_ids, $statuses, $start_date, $end_date, $cursor_id, $limit );
	}

	// ─────────────────────────────────────────────.
	// Writes — delegate to AppointmentWriter.
	// ─────────────────────────────────────────────.

	/**
	 * Cancel appointment
	 *
	 * @param int         $id Record ID.
	 * @param int|null    $cancelled_by User ID who cancelled.
	 * @param string|null $reason Cancellation reason.
	 * @return int|false
	 */
	public function cancel( int $id, ?int $cancelled_by = null, ?string $reason = null ) {
		return $this->writer->cancel( $id, $cancelled_by, $reason );
	}

	/**
	 * Confirm appointment (admin approval)
	 *
	 * @param int      $id Record ID.
	 * @param int|null $approved_by User ID who approved.
	 * @return int|false
	 */
	public function confirm( int $id, ?int $approved_by = null ) {
		return $this->writer->confirm( $id, $approved_by );
	}

	/**
	 * Mark as completed
	 *
	 * @param int $id Record ID.
	 * @return int|false
	 */
	public function markCompleted( int $id ) {
		return $this->writer->markCompleted( $id );
	}

	/**
	 * Mark as no-show
	 *
	 * @param int $id Record ID.
	 * @return int|false
	 */
	public function markNoShow( int $id ) {
		return $this->writer->markNoShow( $id );
	}

	/**
	 * Mark reminder as sent
	 *
	 * @param int $id Record ID.
	 * @return int|false
	 */
	public function markReminderSent( int $id ) {
		return $this->writer->markReminderSent( $id );
	}

	/**
	 * Create appointment with encryption support
	 *
	 * @param array<string, mixed> $data Data.
	 * @return int|false
	 */
	public function createAppointment( array $data ) {
		return $this->writer->createAppointment( $data );
	}

	/**
	 * Delete every appointment row for a calendar.
	 *
	 * @since 6.6.2
	 * @param int $calendar_id Calendar ID.
	 * @return int Rows deleted.
	 */
	public function deleteByCalendar( int $calendar_id ): int {
		return $this->writer->deleteByCalendar( $calendar_id );
	}

	/**
	 * Delete every appointment for a calendar dated strictly before `$date`.
	 *
	 * @since 6.6.2
	 * @param int    $calendar_id Calendar ID.
	 * @param string $date        Cutoff date (`Y-m-d`).
	 * @return int Rows deleted.
	 */
	public function deleteByCalendarBefore( int $calendar_id, string $date ): int {
		return $this->writer->deleteByCalendarBefore( $calendar_id, $date );
	}

	/**
	 * Delete every appointment for a calendar dated on or after `$date`.
	 *
	 * @since 6.6.2
	 * @param int    $calendar_id Calendar ID.
	 * @param string $date        Cutoff date (`Y-m-d`).
	 * @return int Rows deleted.
	 */
	public function deleteByCalendarAfter( int $calendar_id, string $date ): int {
		return $this->writer->deleteByCalendarAfter( $calendar_id, $date );
	}

	/**
	 * Delete every appointment for a calendar matching a specific status.
	 *
	 * @since 6.6.2
	 * @param int    $calendar_id Calendar ID.
	 * @param string $status      Status to delete.
	 * @return int Rows deleted.
	 */
	public function deleteByCalendarAndStatus( int $calendar_id, string $status ): int {
		return $this->writer->deleteByCalendarAndStatus( $calendar_id, $status );
	}

	/**
	 * Bulk-link anonymous appointment rows to a just-created WP user.
	 *
	 * @since 6.6.2
	 * @param int    $user_id     Newly-created WP user id.
	 * @param string $hash_column Hash column to match against.
	 * @param string $hash_value  Hash digest.
	 * @return int Rows linked.
	 */
	public function linkByIdentifierHash( int $user_id, string $hash_column, string $hash_value ): int {
		return $this->writer->linkByIdentifierHash( $user_id, $hash_column, $hash_value );
	}
}
