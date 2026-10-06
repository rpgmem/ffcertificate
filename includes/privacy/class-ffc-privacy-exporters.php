<?php
/**
 * PrivacyExporters
 *
 * Personal Data Exporters for WordPress Privacy Tools
 * (Tools > Export Personal Data) for LGPD/GDPR compliance.
 *
 * Exports user data from all FFC tables. Split out of PrivacyHandler
 * (#591 phase-3) so the data-export concern lives apart from the
 * registration/policy controller. Behaviour is identical — WordPress
 * core invokes these via callable, and the return shapes, pagination
 * and DB queries are unchanged.
 *
 * @package FreeFormCertificate\Privacy
 * @since 6.12.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Privacy;

use FreeFormCertificate\Core\ArrayValue;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$owns} is PrivacySubject::owns_row(), a fragment already prepared by $wpdb->prepare() with every value bound.

/**
 * Personal data exporters.
 */
class PrivacyExporters {

	use \FreeFormCertificate\Core\DatabaseHelperTrait;

	/**
	 * Items per page for batch processing
	 */
	private const ITEMS_PER_PAGE = 50;

	// ──────────────────────────────────────.
	// EXPORTERS.
	// ──────────────────────────────────────.

	/**
	 * Export user profile data
	 *
	 * @param string $email_address User email.
	 * @param int    $page Page number.
	 * @return array<string, mixed>
	 */
	public static function export_profile( string $email_address, int $page = 1 ): array {
		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		// Only export on first page.
		if ( $page > 1 ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		// Source the merged WP + FFC profile snapshot through UserService
		// (#322) so the LGPD export shares its data shape with the REST
		// `/me/profile` endpoint instead of re-doing the merge inline.
		// The WP privacy-export array shape (key/value pairs grouped
		// under group_id/item_id) is built on top — it's a presentation
		// concern that stays local to the handler.
		$bundle  = \FreeFormCertificate\Services\UserService::export_personal_data( (int) $user->ID );
		$profile = is_array( $bundle['profile'] ?? null ) ? $bundle['profile'] : array();

		// That bundle merges three sources with different value types
		// (the WP user object, an optional `ffc_user_profiles` row, a
		// capability map), so it is read through `ArrayValue` rather
		// than cast: `(string)` on a non-scalar yields the literal
		// `Array`, and this one would land in an LGPD export (#1060).
		$data = array(
			array(
				'name'  => __( 'Display Name', 'ffcertificate' ),
				'value' => ArrayValue::string( $profile, 'display_name', (string) $user->display_name ),
			),
			array(
				'name'  => __( 'Email', 'ffcertificate' ),
				'value' => ArrayValue::string( $profile, 'email', (string) $user->user_email ),
			),
		);

		if ( ! empty( $profile['phone'] ) ) {
			$data[] = array(
				'name'  => __( 'Phone', 'ffcertificate' ),
				'value' => ArrayValue::string( $profile, 'phone' ),
			);
		}
		if ( ! empty( $profile['department'] ) ) {
			$data[] = array(
				'name'  => __( 'Department', 'ffcertificate' ),
				'value' => ArrayValue::string( $profile, 'department' ),
			);
		}
		if ( ! empty( $profile['organization'] ) ) {
			$data[] = array(
				'name'  => __( 'Organization', 'ffcertificate' ),
				'value' => ArrayValue::string( $profile, 'organization' ),
			);
		}

		// The canonical birth date (#1538) is stored encrypted, so the raw
		// usermeta export would hand the subject ciphertext; the profile
		// bundle carries it decrypted.
		$birth_date = ArrayValue::string( $profile, 'birth_date' );
		if ( '' !== $birth_date ) {
			$data[] = array(
				'name'  => __( 'Birth date', 'ffcertificate' ),
				'value' => \FreeFormCertificate\Core\DateFormatter::format_wallclock_date( $birth_date ),
			);
		}

		// `ffc_registration_date` post-meta is the canonical "first
		// touch" timestamp written by `UserCreator`. Preferred over
		// `wp_users.user_registered` because the latter is the WP
		// account creation date — which can be earlier than the FFC
		// onboarding when a pre-existing WP user gets promoted.
		$reg_date = get_user_meta( $user->ID, 'ffc_registration_date', true );
		if ( ! empty( $reg_date ) ) {
			$data[] = array(
				'name'  => __( 'Member Since', 'ffcertificate' ),
				'value' => $reg_date,
			);
		}

		$export_items = array(
			array(
				'group_id'    => 'ffc-profile',
				'group_label' => __( 'FFC Profile', 'ffcertificate' ),
				'item_id'     => 'ffc-profile-' . $user->ID,
				'data'        => $data,
			),
		);

		return array(
			'data' => $export_items,
			'done' => true,
		);
	}

	/**
	 * Export certificates (submissions)
	 *
	 * @param string $email_address User email.
	 * @param int    $page Page number.
	 * @return array<string, mixed>
	 */
	public static function export_certificates( string $email_address, int $page = 1 ): array {
		global $wpdb;
		$subject = PrivacySubject::from_email( $email_address );
		if ( $subject->is_empty() ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}
		$owns = $subject->owns_row( 't' );

		$table  = $wpdb->prefix . 'ffc_submissions';
		$offset = ( $page - 1 ) * self::ITEMS_PER_PAGE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot maintenance pass: it must read the live state of the table, so caching it would be wrong rather than merely useless.
		$submissions = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.id, t.form_id, t.submission_date, t.auth_code, t.consent_given,
                    t.email_encrypted, p.post_title AS form_title
             FROM %i t
             LEFT JOIN %i p ON t.form_id = p.ID
             WHERE {$owns} AND t.status != 'trash'
             ORDER BY t.submission_date DESC
             LIMIT %d OFFSET %d",
				$table,
				$wpdb->posts,
				self::ITEMS_PER_PAGE,
				$offset
			),
			ARRAY_A
		);

		$export_items = array();

		foreach ( $submissions as $sub ) {
			$email_display = '';
			if ( ! empty( $sub['email_encrypted'] ) && class_exists( '\FreeFormCertificate\Core\Encryption' ) ) {
				$plain         = \FreeFormCertificate\Core\Encryption::decrypt( $sub['email_encrypted'] );
				$email_display = ( is_string( $plain ) && ! empty( $plain ) ) ? $plain : '';
			}

			$auth_code = $sub['auth_code'] ?? '';
			if ( strlen( $auth_code ) === 12 ) {
				$auth_code = substr( $auth_code, 0, 4 ) . '-' . substr( $auth_code, 4, 4 ) . '-' . substr( $auth_code, 8, 4 );
			}

			$data = array(
				array(
					'name'  => __( 'Form', 'ffcertificate' ),
					'value' => $sub['form_title'] ?? __( 'Unknown', 'ffcertificate' ),
				),
				array(
					'name'  => __( 'Submission Date', 'ffcertificate' ),
					'value' => $sub['submission_date'] ?? '',
				),
				array(
					'name'  => __( 'Auth Code', 'ffcertificate' ),
					'value' => $auth_code,
				),
				array(
					'name'  => __( 'Email', 'ffcertificate' ),
					'value' => $email_display,
				),
				array(
					'name'  => __( 'Consent Given', 'ffcertificate' ),
					'value' => ! empty( $sub['consent_given'] ) ? __( 'Yes', 'ffcertificate' ) : __( 'No', 'ffcertificate' ),
				),
			);

			$export_items[] = array(
				'group_id'    => 'ffc-certificates',
				'group_label' => __( 'FFC Certificates', 'ffcertificate' ),
				'item_id'     => 'ffc-cert-' . $sub['id'],
				'data'        => $data,
			);
		}

		$done = count( $submissions ) < self::ITEMS_PER_PAGE;

		return array(
			'data' => $export_items,
			'done' => $done,
		);
	}

	/**
	 * Export appointments
	 *
	 * @param string $email_address User email.
	 * @param int    $page Page number.
	 * @return array<string, mixed>
	 */
	public static function export_appointments( string $email_address, int $page = 1 ): array {
		global $wpdb;
		$subject = PrivacySubject::from_email( $email_address );
		if ( $subject->is_empty() ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}
		$owns = $subject->owns_row( 't' );

		$table = $wpdb->prefix . 'ffc_self_scheduling_appointments';
		if ( ! self::table_exists( $table ) ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$offset = ( $page - 1 ) * self::ITEMS_PER_PAGE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot maintenance pass: it must read the live state of the table, so caching it would be wrong rather than merely useless.
		$appointments = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.id, t.appointment_date, t.start_time, t.end_time, t.status,
                    t.name, t.email_encrypted, t.phone_encrypted, t.user_notes,
                    p.post_title AS calendar_title
             FROM %i t
             LEFT JOIN %i p ON t.calendar_id = p.ID
             WHERE {$owns}
             ORDER BY t.appointment_date DESC
             LIMIT %d OFFSET %d",
				$table,
				$wpdb->posts,
				self::ITEMS_PER_PAGE,
				$offset
			),
			ARRAY_A
		);

		$export_items = array();

		foreach ( $appointments as $appt ) {
			$email_display = '';
			if ( ! empty( $appt['email_encrypted'] ) && class_exists( '\FreeFormCertificate\Core\Encryption' ) ) {
				$plain         = \FreeFormCertificate\Core\Encryption::decrypt( $appt['email_encrypted'] );
				$email_display = ( is_string( $plain ) && ! empty( $plain ) ) ? $plain : '';
			}

			$phone_display = '';
			if ( ! empty( $appt['phone_encrypted'] ) && class_exists( '\FreeFormCertificate\Core\Encryption' ) ) {
				$plain         = \FreeFormCertificate\Core\Encryption::decrypt( $appt['phone_encrypted'] );
				$phone_display = ( is_string( $plain ) && ! empty( $plain ) ) ? $plain : '';
			}

			$data = array(
				array(
					'name'  => __( 'Calendar', 'ffcertificate' ),
					'value' => $appt['calendar_title'] ?? __( 'Unknown', 'ffcertificate' ),
				),
				array(
					'name'  => __( 'Date', 'ffcertificate' ),
					'value' => $appt['appointment_date'] ?? '',
				),
				array(
					'name'  => __( 'Time', 'ffcertificate' ),
					'value' => ( $appt['start_time'] ?? '' ) . ' - ' . ( $appt['end_time'] ?? '' ),
				),
				array(
					'name'  => __( 'Status', 'ffcertificate' ),
					'value' => $appt['status'] ?? '',
				),
				array(
					'name'  => __( 'Name', 'ffcertificate' ),
					'value' => $appt['name'] ?? '',
				),
				array(
					'name'  => __( 'Email', 'ffcertificate' ),
					'value' => $email_display,
				),
				array(
					'name'  => __( 'Phone', 'ffcertificate' ),
					'value' => $phone_display,
				),
				array(
					'name'  => __( 'Notes', 'ffcertificate' ),
					'value' => $appt['user_notes'] ?? '',
				),
			);

			$export_items[] = array(
				'group_id'    => 'ffc-appointments',
				'group_label' => __( 'FFC Appointments', 'ffcertificate' ),
				'item_id'     => 'ffc-appt-' . $appt['id'],
				'data'        => $data,
			);
		}

		$done = count( $appointments ) < self::ITEMS_PER_PAGE;

		return array(
			'data' => $export_items,
			'done' => $done,
		);
	}

	/**
	 * Export audience group memberships
	 *
	 * @param string $email_address User email.
	 * @param int    $page Page number.
	 * @return array<string, mixed>
	 */
	public static function export_audience_groups( string $email_address, int $page = 1 ): array {
		global $wpdb;
		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$members_table   = $wpdb->prefix . 'ffc_audience_members';
		$audiences_table = $wpdb->prefix . 'ffc_audiences';

		if ( ! self::table_exists( $members_table ) ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		// Small dataset — no pagination needed.
		if ( $page > 1 ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot maintenance pass: it must read the live state of the table, so caching it would be wrong rather than merely useless.
		$groups = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT a.name AS audience_name, a.color, m.created_at AS joined_date
             FROM %i m
             INNER JOIN %i a ON a.id = m.audience_id
             WHERE m.user_id = %d
             ORDER BY a.name ASC',
				$members_table,
				$audiences_table,
				$user->ID
			),
			ARRAY_A
		);

		$export_items = array();

		foreach ( $groups as $group ) {
			$data = array(
				array(
					'name'  => __( 'Audience Name', 'ffcertificate' ),
					'value' => $group['audience_name'] ?? '',
				),
				array(
					'name'  => __( 'Joined Date', 'ffcertificate' ),
					'value' => $group['joined_date'] ?? '',
				),
			);

			$export_items[] = array(
				'group_id'    => 'ffc-audience-groups',
				'group_label' => __( 'FFC Audience Groups', 'ffcertificate' ),
				'item_id'     => 'ffc-group-' . sanitize_title( $group['audience_name'] ?? 'unknown' ),
				'data'        => $data,
			);
		}

		return array(
			'data' => $export_items,
			'done' => true,
		);
	}

	/**
	 * Export audience bookings linked to user
	 *
	 * @param string $email_address User email.
	 * @param int    $page Page number.
	 * @return array<string, mixed>
	 */
	public static function export_audience_bookings( string $email_address, int $page = 1 ): array {
		global $wpdb;
		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$booking_users_table = $wpdb->prefix . 'ffc_audience_booking_users';
		$bookings_table      = $wpdb->prefix . 'ffc_audience_bookings';
		$environments_table  = $wpdb->prefix . 'ffc_audience_environments';

		if ( ! self::table_exists( $booking_users_table ) ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$offset = ( $page - 1 ) * self::ITEMS_PER_PAGE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot maintenance pass: it must read the live state of the table, so caching it would be wrong rather than merely useless.
		$bookings = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT b.id, b.booking_date, b.start_time, b.end_time, b.description,
                    b.status, b.is_all_day, e.name AS environment_name
             FROM %i bu
             INNER JOIN %i b ON b.id = bu.booking_id
             LEFT JOIN %i e ON e.id = b.environment_id
             WHERE bu.user_id = %d
             ORDER BY b.booking_date DESC
             LIMIT %d OFFSET %d',
				$booking_users_table,
				$bookings_table,
				$environments_table,
				$user->ID,
				self::ITEMS_PER_PAGE,
				$offset
			),
			ARRAY_A
		);

		$export_items = array();

		foreach ( $bookings as $booking ) {
			$time = ! empty( $booking['is_all_day'] )
				? __( 'All Day', 'ffcertificate' )
				: ( $booking['start_time'] ?? '' ) . ' - ' . ( $booking['end_time'] ?? '' );

			$data = array(
				array(
					'name'  => __( 'Environment', 'ffcertificate' ),
					'value' => $booking['environment_name'] ?? '',
				),
				array(
					'name'  => __( 'Date', 'ffcertificate' ),
					'value' => $booking['booking_date'] ?? '',
				),
				array(
					'name'  => __( 'Time', 'ffcertificate' ),
					'value' => $time,
				),
				array(
					'name'  => __( 'Description', 'ffcertificate' ),
					'value' => $booking['description'] ?? '',
				),
				array(
					'name'  => __( 'Status', 'ffcertificate' ),
					'value' => $booking['status'] ?? '',
				),
			);

			$export_items[] = array(
				'group_id'    => 'ffc-audience-bookings',
				'group_label' => __( 'FFC Audience Bookings', 'ffcertificate' ),
				'item_id'     => 'ffc-booking-' . $booking['id'],
				'data'        => $data,
			);
		}

		$done = count( $bookings ) < self::ITEMS_PER_PAGE;

		return array(
			'data' => $export_items,
			'done' => $done,
		);
	}

	/**
	 * Export ffc_* user meta data
	 *
	 * @since 4.9.9
	 * @param string $email_address User email.
	 * @param int    $page          Page number.
	 * @return array<string, mixed>
	 */
	public static function export_usermeta( string $email_address, int $page = 1 ): array {
		$user = get_user_by( 'email', $email_address );
		if ( ! $user || $page > 1 ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk statement over the plugin's own ffc_* user-meta keys, matched by prefix — the WordPress meta API cannot filter by key prefix, and doing it per user per key would be thousands of queries.
		$meta_rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT meta_key, meta_value FROM %i
             WHERE user_id = %d AND meta_key LIKE %s',
				$wpdb->usermeta,
				$user->ID,
				'ffc_%'
			),
			ARRAY_A
		);

		if ( empty( $meta_rows ) ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		// Sensitive keys that should not be exported in plain text.
		$redact_keys = array( 'ffc_cpf_rf_hash' );

		$data = array();
		foreach ( $meta_rows as $row ) {
			$value = $row['meta_value'];
			if ( in_array( $row['meta_key'], $redact_keys, true ) ) {
				$value = '[hash]';
			}
			$data[] = array(
				'name'  => $row['meta_key'],
				'value' => $value,
			);
		}

		$export_items = array(
			array(
				'group_id'    => 'ffc-usermeta',
				'group_label' => __( 'FFC User Settings', 'ffcertificate' ),
				'item_id'     => 'ffc-usermeta-' . $user->ID,
				'data'        => $data,
			),
		);

		return array(
			'data' => $export_items,
			'done' => true,
		);
	}

	/**
	 * Export reregistration submissions (#1574).
	 *
	 * A reregistration is always tied to an account, so only the account
	 * finds it. The answers are exported by field key with every encrypted
	 * value decrypted -- the key is what the record stores, and reading the
	 * field labels would couple this module to the reregistration one for no
	 * gain in what the subject receives.
	 *
	 * @param string $email_address User email.
	 * @param int    $page          Page number.
	 * @return array<string, mixed>
	 */
	public static function export_reregistration( string $email_address, int $page = 1 ): array {
		global $wpdb;
		$subject = PrivacySubject::from_email( $email_address );
		$table   = $wpdb->prefix . 'ffc_reregistration_submissions';
		$parent  = $wpdb->prefix . 'ffc_reregistrations';
		if ( $subject->user_id <= 0 || ! self::table_exists( $table ) ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$offset = ( $page - 1 ) * self::ITEMS_PER_PAGE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot privacy export over the plugin's own ffc_* tables: it must read the live state, so caching it would be wrong rather than merely useless.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT t.id, t.status, t.data, t.submitted_at, r.title AS campaign
             FROM %i t
             LEFT JOIN %i r ON t.reregistration_id = r.id
             WHERE t.user_id = %d
             ORDER BY t.id DESC
             LIMIT %d OFFSET %d',
				$table,
				$parent,
				$subject->user_id,
				self::ITEMS_PER_PAGE,
				$offset
			),
			ARRAY_A
		);

		/**
		 * Rows as `$wpdb` hands them back, checked against the SELECT above.
		 *
		 * @var list<array{id: numeric-string, status: string, data: string|null, submitted_at: numeric-string|null, campaign: string|null}>|null $rows
		 */
		if ( ! is_array( $rows ) ) {
			$rows = array();
		}
		$export_items = array();

		foreach ( $rows as $row ) {
			$data = array(
				array(
					'name'  => __( 'Campaign', 'ffcertificate' ),
					'value' => (string) ( $row['campaign'] ?? '' ),
				),
				array(
					'name'  => __( 'Status', 'ffcertificate' ),
					'value' => $row['status'],
				),
				array(
					'name'  => __( 'Submitted', 'ffcertificate' ),
					'value' => null !== $row['submitted_at'] ? (string) wp_date( 'Y-m-d H:i', (int) $row['submitted_at'] ) : '',
				),
			);

			$decoded = json_decode( (string) $row['data'], true );
			$fields  = is_array( $decoded ) && is_array( $decoded['fields'] ?? null ) ? $decoded['fields'] : array();
			foreach ( $fields as $key => $value ) {
				$data[] = array(
					'name'  => (string) $key,
					'value' => self::readable( $value ),
				);
			}

			$export_items[] = array(
				'group_id'    => 'ffc-reregistration',
				'group_label' => __( 'FFC Reregistration', 'ffcertificate' ),
				'item_id'     => 'ffc-rereg-' . $row['id'],
				'data'        => $data,
			);
		}

		return array(
			'data' => $export_items,
			'done' => count( $rows ) < self::ITEMS_PER_PAGE,
		);
	}

	/**
	 * Export recruitment candidacies and their classifications (#1574).
	 *
	 * A candidate exists before any account does, so the record is found by
	 * account or, when unlinked, by e-mail hash.
	 *
	 * @param string $email_address User email.
	 * @param int    $page          Page number.
	 * @return array<string, mixed>
	 */
	public static function export_recruitment( string $email_address, int $page = 1 ): array {
		global $wpdb;
		$subject    = PrivacySubject::from_email( $email_address );
		$candidates = $wpdb->prefix . 'ffc_recruitment_candidate';
		if ( $subject->is_empty() || $page > 1 || ! self::table_exists( $candidates ) ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}
		$owns = $subject->owns_row( 't' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot privacy export over the plugin's own ffc_* table: it must read the live state, so caching it would be wrong rather than merely useless.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.id, t.name, t.email_encrypted, t.cpf_encrypted, t.rf_encrypted, t.phone
             FROM %i t
             WHERE {$owns}
             ORDER BY t.id ASC",
				$candidates
			),
			ARRAY_A
		);

		/**
		 * Rows as `$wpdb` hands them back, checked against the SELECT above.
		 *
		 * @var list<array{id: numeric-string, name: string, email_encrypted: string|null, cpf_encrypted: string|null, rf_encrypted: string|null, phone: string|null}>|null $rows
		 */
		if ( ! is_array( $rows ) ) {
			$rows = array();
		}
		$export_items = array();

		foreach ( $rows as $row ) {
			$data = array(
				array(
					'name'  => __( 'Name', 'ffcertificate' ),
					'value' => $row['name'],
				),
				array(
					'name'  => __( 'Email', 'ffcertificate' ),
					'value' => self::readable( $row['email_encrypted'] ),
				),
				array(
					'name'  => 'CPF',
					'value' => self::readable( $row['cpf_encrypted'] ),
				),
				array(
					'name'  => 'RF',
					'value' => self::readable( $row['rf_encrypted'] ),
				),
				array(
					'name'  => __( 'Phone', 'ffcertificate' ),
					'value' => (string) ( $row['phone'] ?? '' ),
				),
			);

			foreach ( self::classifications_of( (int) $row['id'] ) as $line ) {
				$data[] = array(
					'name'  => __( 'Classification', 'ffcertificate' ),
					'value' => $line,
				);
			}

			$export_items[] = array(
				'group_id'    => 'ffc-recruitment',
				'group_label' => __( 'FFC Recruitment', 'ffcertificate' ),
				'item_id'     => 'ffc-candidate-' . $row['id'],
				'data'        => $data,
			);
		}

		return array(
			'data' => $export_items,
			'done' => true,
		);
	}

	/**
	 * One readable line per classification of a candidate.
	 *
	 * @param int $candidate_id Candidate ID.
	 * @return list<string>
	 */
	private static function classifications_of( int $candidate_id ): array {
		global $wpdb;
		$table   = $wpdb->prefix . 'ffc_recruitment_classification';
		$notices = $wpdb->prefix . 'ffc_recruitment_notice';
		$roles   = $wpdb->prefix . 'ffc_recruitment_adjutancy';
		if ( ! self::table_exists( $table ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot privacy export over the plugin's own ffc_* tables: it must read the live state, so caching it would be wrong rather than merely useless.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT c.list_type, c.score, n.code AS notice, a.name AS adjutancy
             FROM %i c
             LEFT JOIN %i n ON c.notice_id = n.id
             LEFT JOIN %i a ON c.adjutancy_id = a.id
             WHERE c.candidate_id = %d
             ORDER BY c.id ASC',
				$table,
				$notices,
				$roles,
				$candidate_id
			),
			ARRAY_A
		);

		/**
		 * Rows as `$wpdb` hands them back, checked against the SELECT above.
		 *
		 * @var list<array{list_type: string, score: string, notice: string|null, adjutancy: string|null}>|null $rows
		 */
		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map(
			static fn( array $c ): string => sprintf(
				/* translators: 1: notice code, 2: role, 3: list type, 4: score */
				__( '%1$s — %2$s (%3$s, score %4$s)', 'ffcertificate' ),
				(string) ( $c['notice'] ?? '' ),
				(string) ( $c['adjutancy'] ?? '' ),
				$c['list_type'],
				$c['score']
			),
			$rows
		);
	}

	/**
	 * A stored value as the subject should read it: decrypted when it is an
	 * envelope, joined when it is a list, as is otherwise.
	 *
	 * @param mixed $value Stored value.
	 * @return string
	 */
	private static function readable( $value ): string {
		if ( is_array( $value ) ) {
			return implode( ', ', array_map( static fn( $v ): string => self::readable( $v ), $value ) );
		}
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = (string) $value;
		if ( '' !== $value
			&& class_exists( '\FreeFormCertificate\Core\Encryption' )
			&& \FreeFormCertificate\Core\Encryption::looks_like_envelope( $value ) ) {
			$plain = \FreeFormCertificate\Core\Encryption::decrypt( $value );
			return is_string( $plain ) ? $plain : '';
		}
		return $value;
	}

	// table_exists() provided by DatabaseHelperTrait.
}
