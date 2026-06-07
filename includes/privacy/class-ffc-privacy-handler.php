<?php
/**
 * PrivacyHandler
 *
 * Integrates with WordPress Privacy Tools (Tools > Export/Erase Personal Data)
 * for LGPD/GDPR compliance.
 *
 * Registers:
 * - Personal Data Exporters: exports user data from all FFC tables
 * - Personal Data Erasers: anonymizes/deletes user data from all FFC tables
 *
 * @package FreeFormCertificate\Privacy
 * @since 4.9.5
 */

declare(strict_types=1);

namespace FreeFormCertificate\Privacy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
/**
 * Handler for privacy operations.
 */
class PrivacyHandler {

	use \FreeFormCertificate\Core\DatabaseHelperTrait;

	/**
	 * Items per page for batch processing
	 */
	private const ITEMS_PER_PAGE = 50;

	/**
	 * Initialize privacy hooks
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_erasers' ) );
		// Suggested privacy-policy text for Settings → Privacy → Policy Guide.
		// wp_add_privacy_policy_content() must be called on admin_init (it
		// flags _doing_it_wrong otherwise), so we defer to that hook.
		add_action( 'admin_init', array( __CLASS__, 'add_privacy_policy_content' ) );
	}

	/**
	 * Contribute suggested privacy-policy text to the WordPress Privacy
	 * Policy Guide (Settings → Privacy → Policy Guide). The administrator
	 * can copy and adapt it for the site's own privacy page.
	 *
	 * @return void
	 */
	public static function add_privacy_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$retention_days = \FreeFormCertificate\Settings\SettingsReader::activity_log_retention_days();

		wp_add_privacy_policy_content(
			'Free Form Certificate',
			wp_kses_post( self::build_privacy_policy_content( $retention_days ) )
		);
	}

	/**
	 * Build the suggested privacy-policy HTML. Each suggested sentence is
	 * prefixed with a `privacy-policy-tutorial`-classed label, which the
	 * Policy Guide shows as guidance but excludes from the "copy" output —
	 * matching WordPress core's own default-content convention.
	 *
	 * @param int $retention_days Configured activity-log retention window.
	 * @return string Privacy-policy HTML (sanitised by the caller).
	 */
	private static function build_privacy_policy_content( int $retention_days ): string {
		$hint = '<strong class="privacy-policy-tutorial">' . __( 'Suggested text:', 'ffcertificate' ) . ' </strong>';

		// Whole-paragraph guidance — never copied (entire node is tutorial).
		$content = '<p class="privacy-policy-tutorial">' . esc_html__( 'This text is suggested by the Free Form Certificate plugin to help you describe how it processes personal data. Review and adapt it to your organisation and to the modules you actually use (form submissions and certificates, appointment scheduling, audiences, re-registration and recruitment). Delete any section that does not apply.', 'ffcertificate' ) . '</p>';

		$content .= '<h2>' . esc_html__( 'What personal data we collect and why', 'ffcertificate' ) . '</h2>';

		$content .= '<p>' . $hint . esc_html__( 'When you submit a form, request a certificate, book an appointment, join an audience or take part in a re-registration or recruitment process through this site, we collect the personal data you provide. Depending on how each form is configured, this may include your name, e-mail address, telephone number, national identification numbers (such as CPF and RF), free-text answers and any custom fields defined by the administrator.', 'ffcertificate' ) . '</p>';

		$content .= '<p>' . $hint . esc_html__( 'We use this data to issue and verify certificates, manage appointments and audiences, run re-registration and recruitment processes, prevent abuse, and comply with our legal obligations under applicable data-protection law (including the Brazilian LGPD where it applies).', 'ffcertificate' ) . '</p>';

		$content .= '<p>' . $hint . esc_html__( 'For security and accountability we also record your IP address and basic browser information, together with the actions you perform, in an activity log.', 'ffcertificate' ) . '</p>';

		$content .= '<h2>' . esc_html__( 'How your data is stored', 'ffcertificate' ) . '</h2>';

		$content .= '<p>' . $hint . esc_html__( 'Sensitive identifiers such as e-mail addresses and CPF/RF numbers are stored encrypted at rest. Where consent is required, the date and time you gave it are recorded. One-way hashes of some identifiers are kept so records can be located without exposing the original values.', 'ffcertificate' ) . '</p>';

		$content .= '<h2>' . esc_html__( 'How long we keep your data', 'ffcertificate' ) . '</h2>';

		$content .= '<p>' . $hint . sprintf(
			/* translators: %d: number of days activity-log entries are retained. */
			esc_html__( 'Submission, certificate, appointment and audience records are kept for as long as needed to provide the service and to allow previously issued certificates to remain verifiable. Activity-log entries (including IP and browser information) are retained for %d days and then removed automatically.', 'ffcertificate' ),
			$retention_days
		) . '</p>';

		$content .= '<h2>' . esc_html__( 'Who has access to your data', 'ffcertificate' ) . '</h2>';

		$content .= '<p>' . $hint . esc_html__( 'Your data is accessible to the site administrators and to the authorised operators who manage the relevant forms. We do not sell your personal data.', 'ffcertificate' ) . '</p>';

		$content .= '<h2>' . esc_html__( 'Your rights over your data', 'ffcertificate' ) . '</h2>';

		$content .= '<p>' . $hint . esc_html__( 'You can request a copy of your personal data or ask for it to be erased. The administrator can fulfil these requests from the WordPress "Export Personal Data" and "Erase Personal Data" tools, which cover the data this plugin stores. Certificate authentication codes and verification tokens may be retained in anonymised form so that certificates already issued remain verifiable after erasure.', 'ffcertificate' ) . '</p>';

		return $content;
	}

	/**
	 * Register personal data exporters
	 *
	 * @param array<string, array<string, mixed>> $exporters Existing exporters.
	 * @return array<string, array<string, mixed>> Modified exporters
	 */
	public static function register_exporters( array $exporters ): array {
		$exporters['ffcertificate-profile']           = array(
			'exporter_friendly_name' => __( 'FFC Profile', 'ffcertificate' ),
			'callback'               => array( __CLASS__, 'export_profile' ),
		);
		$exporters['ffcertificate-certificates']      = array(
			'exporter_friendly_name' => __( 'FFC Certificates', 'ffcertificate' ),
			'callback'               => array( __CLASS__, 'export_certificates' ),
		);
		$exporters['ffcertificate-appointments']      = array(
			'exporter_friendly_name' => __( 'FFC Appointments', 'ffcertificate' ),
			'callback'               => array( __CLASS__, 'export_appointments' ),
		);
		$exporters['ffcertificate-audience-groups']   = array(
			'exporter_friendly_name' => __( 'FFC Audience Groups', 'ffcertificate' ),
			'callback'               => array( __CLASS__, 'export_audience_groups' ),
		);
		$exporters['ffcertificate-audience-bookings'] = array(
			'exporter_friendly_name' => __( 'FFC Audience Bookings', 'ffcertificate' ),
			'callback'               => array( __CLASS__, 'export_audience_bookings' ),
		);
		$exporters['ffcertificate-usermeta']          = array(
			'exporter_friendly_name' => __( 'FFC User Settings', 'ffcertificate' ),
			'callback'               => array( __CLASS__, 'export_usermeta' ),
		);
		return $exporters;
	}

	/**
	 * Register personal data erasers
	 *
	 * @param array<string, array<string, mixed>> $erasers Existing erasers.
	 * @return array<string, array<string, mixed>> Modified erasers
	 */
	public static function register_erasers( array $erasers ): array {
		$erasers['ffcertificate'] = array(
			'eraser_friendly_name' => __( 'Free Form Certificate', 'ffcertificate' ),
			'callback'             => array( __CLASS__, 'erase_personal_data' ),
		);
		return $erasers;
	}

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

	// ──────────────────────────────────────.
	// ERASER.
	// ──────────────────────────────────────.

	/**
	 * Erase personal data across all FFC tables
	 *
	 * Strategy:
	 * - Submissions: SET user_id = NULL, clear encrypted PII columns
	 *   (preserve auth_code, magic_token for public certificate verification)
	 * - Appointments: SET user_id = NULL, clear PII fields
	 * - Audience members/booking users/permissions: DELETE
	 * - User profiles: DELETE
	 * - Activity log: SET user_id = NULL
	 *
	 * @param string $email_address User email.
	 * @param int    $page Page number.
	 * @return array<string, mixed>
	 */
	public static function erase_personal_data( string $email_address, int $page = 1 ): array {
		global $wpdb;
		$user = get_user_by( 'email', $email_address );

		if ( ! $user ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$user_id        = $user->ID;
		$items_removed  = 0;
		$items_retained = 0;
		$messages       = array();

		// 1. Submissions: anonymize (preserve certificate verification)
		$submissions_table = $wpdb->prefix . 'ffc_submissions';
		$rows              = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i
             SET user_id = NULL, email_encrypted = NULL,
                 cpf_encrypted = NULL, rf_encrypted = NULL,
                 cpf_hash = NULL, rf_hash = NULL
             WHERE user_id = %d',
				$submissions_table,
				$user_id
			)
		);
		if ( $rows > 0 ) {
			$items_removed  += $rows;
			$items_retained += $rows; // Certificate records retained (anonymized).
			$messages[]      = sprintf(
				/* translators: %d: number of submissions */
				__( '%d certificate submissions anonymized (auth codes and verification links preserved).', 'ffcertificate' ),
				$rows
			);
		}

		// 2. Appointments: anonymize PII.
		$appointments_table = $wpdb->prefix . 'ffc_self_scheduling_appointments';
		if ( self::table_exists( $appointments_table ) ) {
			$rows = $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i
                 SET user_id = NULL, name = NULL, email_encrypted = NULL,
                     email_hash = NULL, phone_encrypted = NULL,
                     cpf_encrypted = NULL, cpf_hash = NULL,
                     rf_encrypted = NULL, rf_hash = NULL,
                     custom_data_encrypted = NULL,
                     user_notes = NULL, user_ip_encrypted = NULL,
                     user_agent = NULL
                 WHERE user_id = %d',
					$appointments_table,
					$user_id
				)
			);
			if ( $rows > 0 ) {
				$items_removed += $rows;
				$messages[]     = sprintf(
					/* translators: %d: number of appointments */
					__( '%d appointments anonymized.', 'ffcertificate' ),
					$rows
				);
			}
		}

		// 3. Audience members: DELETE.
		$members_table = $wpdb->prefix . 'ffc_audience_members';
		if ( self::table_exists( $members_table ) ) {
			$rows = $wpdb->delete( $members_table, array( 'user_id' => $user_id ), array( '%d' ) );
			if ( $rows > 0 ) {
				$items_removed += $rows;
				$messages[]     = sprintf(
					/* translators: %d: number of memberships */
					__( '%d audience memberships removed.', 'ffcertificate' ),
					$rows
				);
			}
		}

		// 4. Audience booking users: DELETE.
		$booking_users_table = $wpdb->prefix . 'ffc_audience_booking_users';
		if ( self::table_exists( $booking_users_table ) ) {
			$rows = $wpdb->delete( $booking_users_table, array( 'user_id' => $user_id ), array( '%d' ) );
			if ( $rows > 0 ) {
				$items_removed += $rows;
			}
		}

		// 5. Audience schedule permissions: DELETE.
		$permissions_table = $wpdb->prefix . 'ffc_audience_schedule_permissions';
		if ( self::table_exists( $permissions_table ) ) {
			$rows = $wpdb->delete( $permissions_table, array( 'user_id' => $user_id ), array( '%d' ) );
			if ( $rows > 0 ) {
				$items_removed += $rows;
			}
		}

		// 6. User profiles: DELETE.
		$profiles_table = $wpdb->prefix . 'ffc_user_profiles';
		if ( self::table_exists( $profiles_table ) ) {
			$rows = $wpdb->delete( $profiles_table, array( 'user_id' => $user_id ), array( '%d' ) );
			if ( $rows > 0 ) {
				++$items_removed;
				$messages[] = __( 'User profile deleted.', 'ffcertificate' );
			}
		}

		// 7. Activity log: SET user_id = NULL.
		\FreeFormCertificate\Core\ActivityLogQuery::redact_user_id( $user_id );

		// 8. ffc_* user meta: DELETE.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$meta_deleted = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE user_id = %d AND meta_key LIKE %s',
				$wpdb->usermeta,
				$user_id,
				'ffc_%'
			)
		);
		if ( $meta_deleted > 0 ) {
			$items_removed += $meta_deleted;
			$messages[]     = sprintf(
				/* translators: %d: number of settings */
				__( '%d user settings removed.', 'ffcertificate' ),
				$meta_deleted
			);
		}

		// Log the erasure.
		if ( class_exists( '\FreeFormCertificate\Core\ActivityLog' ) ) {
			\FreeFormCertificate\Core\ActivityLog::log(
				'privacy_data_erased',
				\FreeFormCertificate\Core\ActivityLog::LEVEL_WARNING,
				array(
					'email'          => $email_address,
					'items_removed'  => $items_removed,
					'items_retained' => $items_retained,
				)
			);
		}

		return array(
			'items_removed'  => $items_removed > 0,
			'items_retained' => $items_retained > 0,
			'messages'       => $messages,
			'done'           => true,
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
