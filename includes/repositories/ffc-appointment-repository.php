<?php
declare(strict_types=1);

/**
 * Appointment Repository
 *
 * Data access layer for appointment operations.
 * Follows Repository pattern for separation of concerns.
 *
 * @since 4.1.0
 * @version 4.1.0
 */

namespace FreeFormCertificate\Repositories;

if ( ! defined( 'ABSPATH' ) ) { exit; }

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter

class AppointmentRepository extends AbstractRepository {

    /**
     * Get table name
     *
     * @return string
     */
    protected function get_table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'ffc_self_scheduling_appointments';
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
     * @param int $calendar_id
     * @param int|null $limit
     * @param int $offset
     * @return array
     */
    public function findByCalendar(int $calendar_id, ?int $limit = null, int $offset = 0): array {
        return $this->findAll(
            ['calendar_id' => $calendar_id],
            'appointment_date',
            'DESC',
            $limit,
            $offset
        );
    }

    /**
     * Find appointments by user ID
     *
     * @param int $user_id
     * @param array $statuses Optional status filter
     * @param int|null $limit
     * @param int $offset
     * @return array
     */
    public function findByUserId(int $user_id, array $statuses = [], ?int $limit = null, int $offset = 0): array {
        $conditions = ['user_id' => $user_id];

        if (!empty($statuses)) {
            // For multiple statuses, we'll need raw SQL
            $status_placeholders = implode(',', array_fill(0, count($statuses), '%s'));
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $where = $this->wpdb->prepare(
                "WHERE user_id = %d AND status IN ({$status_placeholders})",
                array_merge([$user_id], $statuses)
            );

            $sql = "SELECT * FROM {$this->table} {$where} ORDER BY appointment_date DESC";

            if ($limit) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
                $sql = $this->wpdb->prepare($sql . " LIMIT %d OFFSET %d", $limit, $offset);
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            return $this->wpdb->get_results($sql, ARRAY_A);
        }

        return $this->findAll($conditions, 'appointment_date', 'DESC', $limit, $offset);
    }

    /**
     * Find appointments by email
     *
     * @param string $email
     * @param int|null $limit
     * @param int $offset
     * @return array
     */
    public function findByEmail(string $email, ?int $limit = null, int $offset = 0): array {
        // Search both plain and hashed email
        $email_hash = hash('sha256', strtolower(trim($email)));

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE email = %s OR email_hash = %s
             ORDER BY appointment_date DESC",
            $email,
            $email_hash
        );

        if ($limit) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $sql = $this->wpdb->prepare($sql . " LIMIT %d OFFSET %d", $limit, $offset);
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $this->wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * Find appointments by CPF/RF
     *
     * @param string $cpf_rf
     * @param int|null $limit
     * @param int $offset
     * @return array
     */
    public function findByCpfRf(string $cpf_rf, ?int $limit = null, int $offset = 0): array {
        // Search both plain and hashed CPF/RF
        $cpf_rf_clean = preg_replace('/[^0-9]/', '', $cpf_rf);
        $cpf_rf_hash = hash('sha256', $cpf_rf_clean);

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE cpf_rf = %s OR cpf_rf_hash = %s
             ORDER BY appointment_date DESC",
            $cpf_rf,
            $cpf_rf_hash
        );

        if ($limit) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $sql = $this->wpdb->prepare($sql . " LIMIT %d OFFSET %d", $limit, $offset);
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $this->wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * Find appointment by confirmation token
     *
     * @param string $token
     * @return array|null
     */
    public function findByConfirmationToken(string $token): ?array {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $result = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE confirmation_token = %s",
                $token
            ),
            ARRAY_A
        );

        return $result ?: null;
    }

    /**
     * Find appointment by validation code
     *
     * @param string $validation_code
     * @return array|null
     */
    public function findByValidationCode(string $validation_code): ?array {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $result = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE validation_code = %s",
                strtoupper($validation_code)
            ),
            ARRAY_A
        );

        return $result ?: null;
    }

    /**
     * Get appointments for a specific date and calendar
     *
     * @param int $calendar_id
     * @param string $date Date in Y-m-d format
     * @param array $statuses Optional status filter (default: confirmed appointments)
     * @return array
     */
    public function getAppointmentsByDate(int $calendar_id, string $date, array $statuses = ['confirmed', 'pending']): array {
        $status_placeholders = implode(',', array_fill(0, count($statuses), '%s'));

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE calendar_id = %d
             AND appointment_date = %s
             AND status IN ({$status_placeholders})
             ORDER BY start_time ASC",
            array_merge([$calendar_id, $date], $statuses)
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $this->wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * Get appointments for a date range
     *
     * @param int $calendar_id
     * @param string $start_date
     * @param string $end_date
     * @param array $statuses
     * @return array
     */
    public function getAppointmentsByDateRange(int $calendar_id, string $start_date, string $end_date, array $statuses = ['confirmed', 'pending']): array {
        $status_placeholders = implode(',', array_fill(0, count($statuses), '%s'));

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE calendar_id = %d
             AND appointment_date BETWEEN %s AND %s
             AND status IN ({$status_placeholders})
             ORDER BY appointment_date ASC, start_time ASC",
            array_merge([$calendar_id, $start_date, $end_date], $statuses)
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $this->wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * Check if slot is available
     *
     * @param int $calendar_id
     * @param string $date
     * @param string $start_time
     * @param int $max_per_slot
     * @return bool
     */
    public function isSlotAvailable(int $calendar_id, string $date, string $start_time, int $max_per_slot = 1): bool {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $count = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table}
                 WHERE calendar_id = %d
                 AND appointment_date = %s
                 AND start_time = %s
                 AND status IN ('confirmed', 'pending')",
                $calendar_id,
                $date,
                $start_time
            )
        );

        return (int)$count < $max_per_slot;
    }

    /**
     * Cancel appointment
     *
     * @param int $id
     * @param int|null $cancelled_by User ID who cancelled
     * @param string|null $reason Cancellation reason
     * @return int|false
     */
    public function cancel(int $id, ?int $cancelled_by = null, ?string $reason = null) {
        return $this->update($id, [
            'status' => 'cancelled',
            'cancelled_at' => current_time('mysql'),
            'cancelled_by' => $cancelled_by,
            'cancellation_reason' => $reason,
            'updated_at' => current_time('mysql')
        ]);
    }

    /**
     * Confirm appointment (admin approval)
     *
     * @param int $id
     * @param int|null $approved_by User ID who approved
     * @return int|false
     */
    public function confirm(int $id, ?int $approved_by = null) {
        return $this->update($id, [
            'status' => 'confirmed',
            'approved_at' => current_time('mysql'),
            'approved_by' => $approved_by,
            'updated_at' => current_time('mysql')
        ]);
    }

    /**
     * Mark as completed
     *
     * @param int $id
     * @return int|false
     */
    public function markCompleted(int $id) {
        return $this->update($id, [
            'status' => 'completed',
            'updated_at' => current_time('mysql')
        ]);
    }

    /**
     * Mark as no-show
     *
     * @param int $id
     * @return int|false
     */
    public function markNoShow(int $id) {
        return $this->update($id, [
            'status' => 'no_show',
            'updated_at' => current_time('mysql')
        ]);
    }

    /**
     * Get upcoming appointments for reminders
     *
     * @param int $hours_before Hours before appointment
     * @return array
     */
    public function getUpcomingForReminders(int $hours_before = 24): array {
        $target_datetime = gmdate('Y-m-d H:i:s', strtotime("+{$hours_before} hours"));
        $target_date = gmdate('Y-m-d', strtotime("+{$hours_before} hours"));
        $target_time = gmdate('H:i:s', strtotime("+{$hours_before} hours"));

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $sql = $this->wpdb->prepare(
            "SELECT a.*, c.title as calendar_title, c.email_config
             FROM {$this->table} a
             LEFT JOIN {$this->wpdb->prefix}ffc_self_scheduling_calendars c ON a.calendar_id = c.id
             WHERE a.status = 'confirmed'
             AND a.reminder_sent_at IS NULL
             AND a.appointment_date = %s
             AND a.start_time <= %s
             AND a.start_time > DATE_SUB(%s, INTERVAL 1 HOUR)",
            $target_date,
            $target_time,
            $target_time
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $this->wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * Mark reminder as sent
     *
     * @param int $id
     * @return int|false
     */
    public function markReminderSent(int $id) {
        return $this->update($id, [
            'reminder_sent_at' => current_time('mysql')
        ]);
    }

    /**
     * Get appointment statistics for calendar
     *
     * @param int $calendar_id
     * @param string|null $start_date
     * @param string|null $end_date
     * @return array
     */
    public function getStatistics(int $calendar_id, ?string $start_date = null, ?string $end_date = null): array {
        $where = $this->wpdb->prepare("WHERE calendar_id = %d", $calendar_id);

        if ($start_date && $end_date) {
            $where .= $this->wpdb->prepare(" AND appointment_date BETWEEN %s AND %s", $start_date, $end_date);
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $stats = $this->wpdb->get_row(
            "SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'no_show' THEN 1 ELSE 0 END) as no_show
             FROM {$this->table}
             {$where}",
            ARRAY_A
        );

        return $stats ?: [
            'total' => 0,
            'confirmed' => 0,
            'pending' => 0,
            'cancelled' => 0,
            'completed' => 0,
            'no_show' => 0
        ];
    }

    /**
     * Create appointment with encryption support
     *
     * @param array $data
     * @return int|false
     */
    public function createAppointment(array $data) {
        // Handle encryption if configured
        if (class_exists('\FreeFormCertificate\Core\Encryption') &&
            \FreeFormCertificate\Core\Encryption::is_configured()) {

            if (!empty($data['email'])) {
                $data['email_encrypted'] = \FreeFormCertificate\Core\Encryption::encrypt($data['email']);
                $data['email_hash'] = hash('sha256', strtolower(trim($data['email'])));
                // Clear plain text - do not store unencrypted (LGPD compliance)
                unset($data['email']);
            }

            if (!empty($data['cpf_rf'])) {
                $data['cpf_rf_encrypted'] = \FreeFormCertificate\Core\Encryption::encrypt($data['cpf_rf']);
                $data['cpf_rf_hash'] = hash('sha256', preg_replace('/[^0-9]/', '', $data['cpf_rf']));
                // Clear plain text - do not store unencrypted (LGPD compliance)
                unset($data['cpf_rf']);
            }

            if (!empty($data['phone'])) {
                $data['phone_encrypted'] = \FreeFormCertificate\Core\Encryption::encrypt($data['phone']);
                // Clear plain text - do not store unencrypted (LGPD compliance)
                unset($data['phone']);
            }

            if (!empty($data['custom_data'])) {
                $custom_json = is_array($data['custom_data']) ? json_encode($data['custom_data']) : $data['custom_data'];
                $data['custom_data_encrypted'] = \FreeFormCertificate\Core\Encryption::encrypt($custom_json);
                // Clear plain text - do not store unencrypted (LGPD compliance)
                unset($data['custom_data']);
            }

            if (!empty($data['user_ip'])) {
                $data['user_ip_encrypted'] = \FreeFormCertificate\Core\Encryption::encrypt($data['user_ip']);
                // Clear plain text - do not store unencrypted (LGPD compliance)
                unset($data['user_ip']);
            }
        }

        // Generate confirmation token for all appointments (allows receipt access without login)
        if (empty($data['confirmation_token'])) {
            $data['confirmation_token'] = bin2hex(random_bytes(32));
        }

        // Generate validation code for all appointments (user-friendly code like certificates)
        if (empty($data['validation_code'])) {
            $data['validation_code'] = $this->generate_unique_validation_code();
        }

        // Set timestamps
        if (empty($data['created_at'])) {
            $data['created_at'] = current_time('mysql');
        }

        return $this->insert($data);
    }

    /**
     * Generate unique validation code
     *
     * Generates a 12-character alphanumeric code (stored without hyphens).
     * Use Utils::format_auth_code() to display with hyphens (XXXX-XXXX-XXXX).
     *
     * @return string 12-character code without hyphens
     */
    private function generate_unique_validation_code(): string {
        do {
            // Generate 12 alphanumeric characters (stored clean, without hyphens)
            $code = \FreeFormCertificate\Core\Utils::generate_random_string(12);

            // Check if code already exists
            $existing = $this->findAll(['validation_code' => $code], 'id', 'ASC', 1);
        } while (!empty($existing));

        return $code;
    }
}
