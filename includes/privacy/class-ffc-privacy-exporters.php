<?php
/**
 * PrivacyExporters
 *
 * Personal Data Exporters for WordPress Privacy Tools
 * (Tools > Export Personal Data) for LGPD/GDPR compliance.
 *
 * Exports user data from all FFC tables. Split out of PrivacyHandler
 * (#591 phase-3) so the data-export concern lives apart from the
 * registration/policy controller. Every value is exported the way the
 * subject should read it: decrypted, under a translated label, with dates
 * in the site's format (#2343).
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

		// That bundle merges sources with different value types (the WP
		// user object and an optional `ffc_user_profiles` row), so it is
		// read through `ArrayValue` rather
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
				'value' => self::local_datetime( $reg_date ),
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

		// Trashed rows are the subject's data too until they are deleted, so
		// they are exported with their status rather than left out (#2343).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot maintenance pass: it must read the live state of the table, so caching it would be wrong rather than merely useless.
		$submissions = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.id, t.form_id, t.submission_date, t.auth_code, t.consent_given, t.status,
                    t.email_encrypted, t.cpf_encrypted, t.rf_encrypted, t.data, t.data_encrypted,
                    p.post_title AS form_title
             FROM %i t
             LEFT JOIN %i p ON t.form_id = p.ID
             WHERE {$owns}
             ORDER BY t.submission_date DESC, t.id DESC
             LIMIT %d OFFSET %d",
				$table,
				$wpdb->posts,
				self::ITEMS_PER_PAGE,
				$offset
			),
			ARRAY_A
		);
		if ( ! is_array( $submissions ) ) {
			$submissions = array();
		}

		$export_items = array();

		foreach ( $submissions as $sub ) {
			$auth_code = (string) ( $sub['auth_code'] ?? '' );
			if ( strlen( $auth_code ) === 12 ) {
				$auth_code = substr( $auth_code, 0, 4 ) . '-' . substr( $auth_code, 4, 4 ) . '-' . substr( $auth_code, 8, 4 );
			}

			$data   = array(
				array(
					'name'  => __( 'Form', 'ffcertificate' ),
					'value' => $sub['form_title'] ?? __( 'Unknown', 'ffcertificate' ),
				),
				array(
					'name'  => __( 'Submission Date', 'ffcertificate' ),
					'value' => \FreeFormCertificate\Core\DateFormatter::format_datetime( (int) ( $sub['submission_date'] ?? 0 ) > 0 ? (int) $sub['submission_date'] : null ),
				),
				array(
					'name'  => __( 'Status', 'ffcertificate' ),
					'value' => self::status_label( 'submission', (string) ( $sub['status'] ?? '' ) ),
				),
				array(
					'name'  => __( 'Auth Code', 'ffcertificate' ),
					'value' => $auth_code,
				),
				array(
					'name'  => __( 'Email', 'ffcertificate' ),
					'value' => self::readable( $sub['email_encrypted'] ?? null ),
				),
			);
			$data   = array_merge( $data, self::document_rows( $sub ) );
			$data[] = array(
				'name'  => __( 'Consent Given', 'ffcertificate' ),
				'value' => ! empty( $sub['consent_given'] ) ? __( 'Yes', 'ffcertificate' ) : __( 'No', 'ffcertificate' ),
			);

			$labels = self::form_field_labels( (int) ( $sub['form_id'] ?? 0 ) );
			foreach ( self::answers( $sub, 'data', 'data_encrypted' ) as $key => $value ) {
				$data[] = array(
					'name'  => $labels[ $key ] ?? self::humanize( (string) $key ),
					'value' => self::readable( $value ),
				);
			}

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
                    t.name, t.email_encrypted, t.phone_encrypted, t.cpf_encrypted, t.rf_encrypted,
                    t.custom_data_encrypted, t.user_notes,
                    p.post_title AS calendar_title
             FROM %i t
             LEFT JOIN %i p ON t.calendar_id = p.ID
             WHERE {$owns}
             ORDER BY t.appointment_date DESC, t.id DESC
             LIMIT %d OFFSET %d",
				$table,
				$wpdb->posts,
				self::ITEMS_PER_PAGE,
				$offset
			),
			ARRAY_A
		);
		if ( ! is_array( $appointments ) ) {
			$appointments = array();
		}

		$export_items = array();

		foreach ( $appointments as $appt ) {
			$data   = array(
				array(
					'name'  => __( 'Calendar', 'ffcertificate' ),
					'value' => $appt['calendar_title'] ?? __( 'Unknown', 'ffcertificate' ),
				),
				array(
					'name'  => __( 'Date', 'ffcertificate' ),
					'value' => \FreeFormCertificate\Core\DateFormatter::format_wallclock_date( (string) ( $appt['appointment_date'] ?? '' ) ),
				),
				array(
					'name'  => __( 'Time', 'ffcertificate' ),
					'value' => \FreeFormCertificate\Core\DateFormatter::format_schedule( $appt['start_time'] ?? null, $appt['end_time'] ?? null ),
				),
				array(
					'name'  => __( 'Status', 'ffcertificate' ),
					'value' => self::status_label( 'appointment', (string) ( $appt['status'] ?? '' ) ),
				),
				array(
					'name'  => __( 'Name', 'ffcertificate' ),
					'value' => $appt['name'] ?? '',
				),
				array(
					'name'  => __( 'Email', 'ffcertificate' ),
					'value' => self::readable( $appt['email_encrypted'] ?? null ),
				),
				array(
					'name'  => __( 'Phone', 'ffcertificate' ),
					'value' => self::readable( $appt['phone_encrypted'] ?? null ),
				),
			);
			$data   = array_merge( $data, self::document_rows( $appt ) );
			$data[] = array(
				'name'  => __( 'Notes', 'ffcertificate' ),
				'value' => $appt['user_notes'] ?? '',
			);
			foreach ( self::answers( $appt, 'custom_data', 'custom_data_encrypted' ) as $key => $value ) {
				$data[] = array(
					'name'  => self::humanize( (string) $key ),
					'value' => self::readable( $value ),
				);
			}

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
					'value' => self::local_datetime( $group['joined_date'] ?? null ),
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
				'SELECT b.id, b.booking_date, b.end_date, b.start_time, b.end_time, b.description,
                    b.status, b.is_all_day, e.name AS environment_name
             FROM %i bu
             INNER JOIN %i b ON b.id = bu.booking_id
             LEFT JOIN %i e ON e.id = b.environment_id
             WHERE bu.user_id = %d
             ORDER BY b.booking_date DESC, b.id DESC
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
					'value' => \FreeFormCertificate\Audience\AudienceBookingRules::date_label(
						(string) ( $booking['booking_date'] ?? '' ),
						$booking['end_date'] ?? null
					),
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
					'value' => self::status_label( 'booking', (string) ( $booking['status'] ?? '' ) ),
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
	 * Export the post-event form invitations sent to a user (#2024).
	 *
	 * @param string $email_address User email.
	 * @param int    $page          Page number.
	 * @return array<string, mixed>
	 */
	public static function export_audience_form_invites( string $email_address, int $page = 1 ): array {
		global $wpdb;
		$user = get_user_by( 'email', $email_address );

		$invites_table = $wpdb->prefix . 'ffc_audience_form_invites';
		if ( ! $user || ! self::table_exists( $invites_table ) ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot maintenance pass: it must read the live state of the table, so caching it would be wrong rather than merely useless.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT i.id, i.form_id, i.sent_at, i.send_count, b.description
             FROM %i i
             LEFT JOIN %i b ON b.id = i.booking_id
             WHERE i.user_id = %d
             ORDER BY i.sent_at DESC, i.id DESC
             LIMIT %d OFFSET %d',
				$invites_table,
				$wpdb->prefix . 'ffc_audience_bookings',
				$user->ID,
				self::ITEMS_PER_PAGE,
				( $page - 1 ) * self::ITEMS_PER_PAGE
			),
			ARRAY_A
		);

		$export_items = array();
		foreach ( $rows as $row ) {
			$export_items[] = array(
				'group_id'    => 'ffc-audience-form-invites',
				'group_label' => __( 'FFC Post-event Form Invitations', 'ffcertificate' ),
				'item_id'     => 'ffc-form-invite-' . $row['id'],
				'data'        => array(
					array(
						'name'  => __( 'Booking', 'ffcertificate' ),
						'value' => $row['description'] ?? '',
					),
					array(
						'name'  => __( 'Form', 'ffcertificate' ),
						'value' => get_the_title( (int) $row['form_id'] ),
					),
					array(
						'name'  => __( 'Last sent', 'ffcertificate' ),
						'value' => \FreeFormCertificate\Core\DateFormatter::format_datetime( (int) $row['sent_at'] ),
					),
					array(
						'name'  => __( 'Times sent', 'ffcertificate' ),
						'value' => (string) (int) $row['send_count'],
					),
				),
			);
		}

		return array(
			'data' => $export_items,
			'done' => count( $rows ) < self::ITEMS_PER_PAGE,
		);
	}

	/**
	 * Export the activity-log entries recorded against a user (#2246).
	 *
	 * Each entry names what happened, when, its level and, when one was
	 * stored, the client address it came from, decrypted, since that address
	 * is the person's own data. The context is left out on purpose: it is the
	 * operator-facing detail of the event and can name other people, the
	 * operator who acted among them, which an export to this person must not
	 * hand over.
	 *
	 * @param string $email_address User email.
	 * @param int    $page          Page number.
	 * @return array<string, mixed>
	 */
	public static function export_activity_log( string $email_address, int $page = 1 ): array {
		global $wpdb;
		$user  = get_user_by( 'email', $email_address );
		$table = $wpdb->prefix . 'ffc_activity_log';
		if ( ! $user || ! self::table_exists( $table ) ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$with_ip = self::column_exists( $table, 'user_ip_encrypted' );
		$columns = $with_ip ? 'id, action, level, created_at, user_ip_encrypted' : 'id, action, level, created_at';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- One-shot export of live rows; $columns is one of two literal lists chosen above.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT {$columns} FROM %i WHERE user_id = %d ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d",
				$table,
				$user->ID,
				self::ITEMS_PER_PAGE,
				( $page - 1 ) * self::ITEMS_PER_PAGE
			),
			ARRAY_A
		);

		$export_items = array();
		foreach ( $rows as $row ) {
			$data = array(
				array(
					'name'  => __( 'Action', 'ffcertificate' ),
					'value' => \FreeFormCertificate\Admin\AdminActivityLogPage::get_action_label( (string) ( $row['action'] ?? '' ) ),
				),
				array(
					'name'  => __( 'Level', 'ffcertificate' ),
					'value' => self::status_label( 'level', (string) ( $row['level'] ?? '' ) ),
				),
				array(
					'name'  => __( 'Date', 'ffcertificate' ),
					'value' => self::local_datetime( $row['created_at'] ?? null ),
				),
			);

			$ip = '';
			if ( $with_ip && ! empty( $row['user_ip_encrypted'] ) && class_exists( '\FreeFormCertificate\Core\Encryption' ) ) {
				$plain = \FreeFormCertificate\Core\Encryption::decrypt( (string) $row['user_ip_encrypted'] );
				$ip    = is_string( $plain ) ? $plain : '';
			}
			if ( '' !== $ip ) {
				$data[] = array(
					'name'  => __( 'IP Address', 'ffcertificate' ),
					'value' => $ip,
				);
			}

			$export_items[] = array(
				'group_id'    => 'ffc-activity-log',
				'group_label' => __( 'FFC Activity Log', 'ffcertificate' ),
				'item_id'     => 'ffc-activity-' . $row['id'],
				'data'        => $data,
			);
		}

		return array(
			'data' => $export_items,
			'done' => count( $rows ) < self::ITEMS_PER_PAGE,
		);
	}

	/**
	 * Export the date messages sent to a user (#2246).
	 *
	 * One item per delivery: the rule's name, the date it was about and when
	 * it was handed to the mailer. The tables are named here rather than read
	 * from the Date Messages activator so the privacy module takes no
	 * dependency on that module, which may be switched off.
	 *
	 * @param string $email_address User email.
	 * @param int    $page          Page number.
	 * @return array<string, mixed>
	 */
	public static function export_date_message_log( string $email_address, int $page = 1 ): array {
		global $wpdb;
		$user      = get_user_by( 'email', $email_address );
		$log_table = $wpdb->prefix . 'ffc_date_message_log';
		if ( ! $user || ! self::table_exists( $log_table ) ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot maintenance pass: it must read the live state of the table, so caching it would be wrong rather than merely useless.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT l.id, l.occurrence_key, l.sent_at, r.name AS rule_name
             FROM %i l
             LEFT JOIN %i r ON r.id = l.rule_id
             WHERE l.user_id = %d
             ORDER BY l.sent_at DESC, l.id DESC
             LIMIT %d OFFSET %d',
				$log_table,
				$wpdb->prefix . 'ffc_date_message_rules',
				$user->ID,
				self::ITEMS_PER_PAGE,
				( $page - 1 ) * self::ITEMS_PER_PAGE
			),
			ARRAY_A
		);

		$export_items = array();
		foreach ( $rows as $row ) {
			$sent_at        = (int) ( $row['sent_at'] ?? 0 );
			$export_items[] = array(
				'group_id'    => 'ffc-date-messages',
				'group_label' => __( 'FFC Date Messages', 'ffcertificate' ),
				'item_id'     => 'ffc-date-message-' . $row['id'],
				'data'        => array(
					array(
						'name'  => __( 'Message', 'ffcertificate' ),
						'value' => (string) ( $row['rule_name'] ?? '' ),
					),
					array(
						'name'  => __( 'Date it was about', 'ffcertificate' ),
						'value' => \FreeFormCertificate\Core\DateFormatter::format_wallclock_date( (string) ( $row['occurrence_key'] ?? '' ) ),
					),
					array(
						'name'  => __( 'Sent', 'ffcertificate' ),
						'value' => $sent_at > 0 ? \FreeFormCertificate\Core\DateFormatter::format_datetime( $sent_at ) : '',
					),
				),
			);
		}

		return array(
			'data' => $export_items,
			'done' => count( $rows ) < self::ITEMS_PER_PAGE,
		);
	}

	/**
	 * Export the days a user was recorded present at an audience booking (#1947).
	 *
	 * @param string $email_address User email.
	 * @param int    $page          Page number.
	 * @return array<string, mixed>
	 */
	public static function export_audience_attendance( string $email_address, int $page = 1 ): array {
		global $wpdb;
		$user = get_user_by( 'email', $email_address );

		$attendance_table = $wpdb->prefix . 'ffc_audience_attendance';
		if ( ! $user || ! self::table_exists( $attendance_table ) ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot maintenance pass: it must read the live state of the table, so caching it would be wrong rather than merely useless.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT a.id, a.attendance_date, a.checked_at, a.method, b.description
             FROM %i a
             LEFT JOIN %i b ON b.id = a.booking_id
             WHERE a.user_id = %d
             ORDER BY a.attendance_date DESC, a.id DESC
             LIMIT %d OFFSET %d',
				$attendance_table,
				$wpdb->prefix . 'ffc_audience_bookings',
				$user->ID,
				self::ITEMS_PER_PAGE,
				( $page - 1 ) * self::ITEMS_PER_PAGE
			),
			ARRAY_A
		);

		$export_items = array();
		foreach ( $rows as $row ) {
			$export_items[] = array(
				'group_id'    => 'ffc-audience-attendance',
				'group_label' => __( 'FFC Audience Attendance', 'ffcertificate' ),
				'item_id'     => 'ffc-attendance-' . $row['id'],
				'data'        => array(
					array(
						'name'  => __( 'Booking', 'ffcertificate' ),
						'value' => $row['description'] ?? '',
					),
					array(
						'name'  => __( 'Date', 'ffcertificate' ),
						'value' => \FreeFormCertificate\Core\DateFormatter::format_wallclock_date( (string) $row['attendance_date'] ),
					),
					array(
						'name'  => __( 'Recorded at', 'ffcertificate' ),
						'value' => \FreeFormCertificate\Core\DateFormatter::format_datetime( (int) $row['checked_at'] ),
					),
					array(
						'name'  => __( 'Method', 'ffcertificate' ),
						'value' => 'manual' === $row['method'] ? __( 'Marked by staff', 'ffcertificate' ) : __( 'QR check-in', 'ffcertificate' ),
					),
				),
			);
		}

		return array(
			'data' => $export_items,
			'done' => count( $rows ) < self::ITEMS_PER_PAGE,
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
				$wpdb->esc_like( 'ffc_' ) . '%'
			),
			ARRAY_A
		);

		if ( empty( $meta_rows ) ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		// Values are read the way the subject should read them (#2343): a
		// serialized array is opened, an encrypted value decrypted, a custom
		// field named by its label, and a hash -- one-way, meaningless to the
		// subject -- left out.
		$labels = self::custom_field_labels();
		$data   = array();
		foreach ( $meta_rows as $row ) {
			$key = (string) $row['meta_key'];
			if ( '_hash' === substr( $key, -5 ) ) {
				continue;
			}
			$value = maybe_unserialize( (string) $row['meta_value'] );

			if ( 'ffc_custom_fields_data' === $key && is_array( $value ) ) {
				foreach ( $value as $field_key => $field_value ) {
					$data[] = array(
						'name'  => $labels[ (string) $field_key ] ?? self::humanize( (string) $field_key ),
						'value' => self::readable( $field_value ),
					);
				}
				continue;
			}

			$data[] = array(
				'name'  => self::usermeta_label( $key, $labels ),
				'value' => self::usermeta_value( $key, $value ),
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
	 * finds it. Each answer is exported under its field's label, read from the
	 * custom-fields table (the key when the field is gone), with every
	 * encrypted value decrypted (#2343).
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
		$labels       = self::custom_field_labels();

		foreach ( $rows as $row ) {
			$data = array(
				array(
					'name'  => __( 'Campaign', 'ffcertificate' ),
					'value' => (string) ( $row['campaign'] ?? '' ),
				),
				array(
					'name'  => __( 'Status', 'ffcertificate' ),
					'value' => self::status_label( 'reregistration', $row['status'] ),
				),
				array(
					'name'  => __( 'Submitted', 'ffcertificate' ),
					'value' => null !== $row['submitted_at'] ? \FreeFormCertificate\Core\DateFormatter::format_datetime( (int) $row['submitted_at'] ) : '',
				),
			);

			$decoded = json_decode( (string) $row['data'], true );
			$fields  = is_array( $decoded ) && is_array( $decoded['fields'] ?? null ) ? $decoded['fields'] : array();
			foreach ( $fields as $key => $value ) {
				$data[] = array(
					'name'  => $labels[ (string) $key ] ?? self::humanize( (string) $key ),
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
	 * A stored status code as the subject should read it (#2343).
	 *
	 * The maps are the ones the plugin's own screens print, restated here
	 * rather than read from each module so the privacy module stays free of
	 * a dependency on modules that may be switched off. An unknown code is
	 * humanised rather than shown raw.
	 *
	 * @param string $kind   'submission', 'appointment', 'booking', 'reregistration' or 'level'.
	 * @param string $status Stored code.
	 * @return string
	 */
	private static function status_label( string $kind, string $status ): string {
		$maps = array(
			'submission'     => array(
				'publish' => __( 'Published', 'ffcertificate' ),
				'trash'   => __( 'Trash', 'ffcertificate' ),
			),
			'appointment'    => array(
				'pending'   => __( 'Pending Approval', 'ffcertificate' ),
				'confirmed' => __( 'Confirmed', 'ffcertificate' ),
				'cancelled' => __( 'Cancelled', 'ffcertificate' ),
				'completed' => __( 'Completed', 'ffcertificate' ),
				'no_show'   => __( 'No Show', 'ffcertificate' ),
			),
			'booking'        => array(
				'active'    => __( 'Active', 'ffcertificate' ),
				'cancelled' => __( 'Cancelled', 'ffcertificate' ),
			),
			'reregistration' => array(
				'pending'     => __( 'Pending', 'ffcertificate' ),
				'in_progress' => __( 'In Progress', 'ffcertificate' ),
				'submitted'   => __( 'Submitted — Pending Review', 'ffcertificate' ),
				'approved'    => __( 'Approved', 'ffcertificate' ),
				'rejected'    => __( 'Rejected', 'ffcertificate' ),
				'expired'     => __( 'Expired', 'ffcertificate' ),
			),
			'level'          => array(
				'info'    => __( 'Info', 'ffcertificate' ),
				'warning' => __( 'Warning', 'ffcertificate' ),
				'error'   => __( 'Error', 'ffcertificate' ),
				'debug'   => __( 'Debug', 'ffcertificate' ),
			),
		);

		return $maps[ $kind ][ $status ] ?? self::humanize( $status );
	}

	/**
	 * A site-local `DATETIME` (what `current_time( 'mysql' )` writes) in the
	 * site's date and time format. Read through the central ISO conversion,
	 * which knows the value is local; parsing it bare would shift it by the
	 * site's offset.
	 *
	 * @param mixed $value Stored value.
	 * @return string
	 */
	private static function local_datetime( $value ): string {
		if ( ! is_string( $value ) || '' === $value ) {
			return '';
		}
		$iso = \FreeFormCertificate\Core\DateFormatter::iso8601( $value );
		return null === $iso ? $value : \FreeFormCertificate\Core\DateFormatter::format_datetime( $iso );
	}

	/**
	 * CPF and RF rows of a submission or appointment, decrypted, when present.
	 *
	 * @param array<string, mixed> $row Row carrying `cpf_encrypted` / `rf_encrypted`.
	 * @return list<array{name: string, value: string}>
	 */
	private static function document_rows( array $row ): array {
		$rows = array();
		foreach ( array(
			'CPF' => 'cpf_encrypted',
			'RF'  => 'rf_encrypted',
		) as $label => $column ) {
			$value = self::readable( $row[ $column ] ?? null );
			if ( '' !== $value ) {
				$rows[] = array(
					'name'  => $label,
					'value' => $value,
				);
			}
		}
		return $rows;
	}

	/**
	 * The answers a row stores as JSON: the encrypted column first, the plain
	 * one when there is no key or no ciphertext, as the CSV export reads them.
	 *
	 * @param array<string, mixed> $row           Row.
	 * @param string               $plain_key     Plain JSON column.
	 * @param string               $encrypted_key Encrypted JSON column.
	 * @return array<string, mixed>
	 */
	private static function answers( array $row, string $plain_key, string $encrypted_key ): array {
		$json = null;
		if ( ! empty( $row[ $encrypted_key ] ) && class_exists( '\FreeFormCertificate\Core\Encryption' ) ) {
			$plain = \FreeFormCertificate\Core\Encryption::decrypt( (string) $row[ $encrypted_key ] );
			$json  = is_string( $plain ) && '' !== $plain ? $plain : null;
		}
		if ( null === $json && ! empty( $row[ $plain_key ] ) && is_string( $row[ $plain_key ] ) ) {
			$json = $row[ $plain_key ];
		}
		$decoded = null === $json ? null : json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}

		// The consent flag is the separate "Consent Given" row already.
		unset( $decoded['ffc_lgpd_consent'] );
		return $decoded;
	}

	/**
	 * Field name => label of a form, from its builder definition.
	 *
	 * @param int $form_id Form post ID.
	 * @return array<string, string>
	 */
	private static function form_field_labels( int $form_id ): array {
		static $cache = array();
		if ( $form_id <= 0 ) {
			return array();
		}
		if ( ! isset( $cache[ $form_id ] ) ) {
			$labels = array();
			$fields = get_post_meta( $form_id, '_ffc_form_fields', true );
			foreach ( is_array( $fields ) ? $fields : array() as $field ) {
				if ( is_array( $field ) && is_scalar( $field['name'] ?? null ) && is_scalar( $field['label'] ?? null ) && '' !== (string) $field['label'] ) {
					$labels[ (string) $field['name'] ] = wp_strip_all_tags( (string) $field['label'] );
				}
			}
			$cache[ $form_id ] = $labels;
		}
		return $cache[ $form_id ];
	}

	/**
	 * Field key => label of the custom fields (reregistration answers and the
	 * profile fields kept in user meta). Read from the table rather than the
	 * reregistration module, which may be switched off.
	 *
	 * @return array<string, string>
	 */
	private static function custom_field_labels(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'ffc_custom_fields';
		if ( ! self::table_exists( $table ) ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot privacy export over the plugin's own ffc_* table: it must read the live state.
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT field_key, field_label FROM %i ORDER BY id ASC', $table ),
			ARRAY_A
		);

		$labels = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$key = (string) ( $row['field_key'] ?? '' );
			if ( '' !== $key && ! isset( $labels[ $key ] ) ) {
				$labels[ $key ] = (string) ( $row['field_label'] ?? $key );
			}
		}
		return $labels;
	}

	/**
	 * The label of an `ffc_*` user-meta key.
	 *
	 * @param string                $key    Meta key.
	 * @param array<string, string> $labels Custom-field labels.
	 * @return string
	 */
	private static function usermeta_label( string $key, array $labels ): string {
		$known = array(
			'ffc_registration_date'   => __( 'Member Since', 'ffcertificate' ),
			'ffc_user_birth_date'     => __( 'Birth date', 'ffcertificate' ),
			'ffc_user_birth_md'       => __( 'Birthday (day and month)', 'ffcertificate' ),
			'ffc_qr_generator_design' => __( 'QR code generator design', 'ffcertificate' ),
		);
		if ( isset( $known[ $key ] ) ) {
			return $known[ $key ];
		}
		if ( str_starts_with( $key, 'ffc_user_' ) ) {
			$field = substr( $key, strlen( 'ffc_user_' ) );
			return $labels[ $field ] ?? self::humanize( $field );
		}
		return self::humanize( (string) preg_replace( '/^ffc_/', '', $key ) );
	}

	/**
	 * The value of an `ffc_*` user-meta key, readable.
	 *
	 * @param string $key   Meta key.
	 * @param mixed  $value Unserialized value.
	 * @return string
	 */
	private static function usermeta_value( string $key, $value ): string {
		if ( 'ffc_registration_date' === $key ) {
			return self::local_datetime( $value );
		}
		$readable = self::readable( $value );
		if ( 'ffc_user_birth_date' === $key && '' !== $readable ) {
			return \FreeFormCertificate\Core\DateFormatter::format_wallclock_date( $readable );
		}
		return $readable;
	}

	/**
	 * A stored key as words: `endereco_numero` reads "Endereco numero".
	 *
	 * @param string $key Key.
	 * @return string
	 */
	private static function humanize( string $key ): string {
		$words = trim( str_replace( array( '_', '-' ), ' ', $key ) );
		return '' === $words ? $key : ucfirst( $words );
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
