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
 * @since 6.7.x
 */

declare(strict_types=1);

namespace FreeFormCertificate\Privacy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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

		$data = array(
			array(
				'name'  => __( 'Display Name', 'ffcertificate' ),
				'value' => (string) ( $profile['display_name'] ?? $user->display_name ),
			),
			array(
				'name'  => __( 'Email', 'ffcertificate' ),
				'value' => (string) ( $profile['email'] ?? $user->user_email ),
			),
		);

		if ( ! empty( $profile['phone'] ) ) {
			$data[] = array(
				'name'  => __( 'Phone', 'ffcertificate' ),
				'value' => (string) $profile['phone'],
			);
		}
		if ( ! empty( $profile['department'] ) ) {
			$data[] = array(
				'name'  => __( 'Department', 'ffcertificate' ),
				'value' => (string) $profile['department'],
			);
		}
		if ( ! empty( $profile['organization'] ) ) {
			$data[] = array(
				'name'  => __( 'Organization', 'ffcertificate' ),
				'value' => (string) $profile['organization'],
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
		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$table  = $wpdb->prefix . 'ffc_submissions';
		$offset = ( $page - 1 ) * self::ITEMS_PER_PAGE;

		$submissions = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.id, s.form_id, s.submission_date, s.auth_code, s.consent_given,
                    s.email_encrypted, p.post_title AS form_title
             FROM %i s
             LEFT JOIN %i p ON s.form_id = p.ID
             WHERE s.user_id = %d AND s.status != 'trash'
             ORDER BY s.submission_date DESC
             LIMIT %d OFFSET %d",
				$table,
				$wpdb->posts,
				$user->ID,
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
		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$table = $wpdb->prefix . 'ffc_self_scheduling_appointments';
		if ( ! self::table_exists( $table ) ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$offset = ( $page - 1 ) * self::ITEMS_PER_PAGE;

		$appointments = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT a.id, a.appointment_date, a.start_time, a.end_time, a.status,
                    a.name, a.email_encrypted, a.phone_encrypted, a.user_notes,
                    p.post_title AS calendar_title
             FROM %i a
             LEFT JOIN %i p ON a.calendar_id = p.ID
             WHERE a.user_id = %d
             ORDER BY a.appointment_date DESC
             LIMIT %d OFFSET %d',
				$table,
				$wpdb->posts,
				$user->ID,
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

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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

	// table_exists() provided by DatabaseHelperTrait.
}
