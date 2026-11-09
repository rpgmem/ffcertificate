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

/**
 * Handler for privacy operations.
 */
class PrivacyHandler {

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
		// Hooked on admin_init, after wp-admin has loaded plugin.php, which
		// declares wp_add_privacy_policy_content().
		$retention_days = \FreeFormCertificate\Settings\SettingsReader::activity_log_retention_days();
		$cleanup_days   = \FreeFormCertificate\Settings\SettingsReader::get_bool( 'cleanup_enabled', false )
			? max( 0, \FreeFormCertificate\Settings\SettingsReader::get_int( 'cleanup_days', 365 ) )
			: 0;
		$device         = \FreeFormCertificate\Security\RateLimitChecker::get_settings()['device'] ?? array();
		$device_days    = max( 1, (int) ( $device['retention_days'] ?? 90 ) );

		wp_add_privacy_policy_content(
			'Free Form Certificate',
			wp_kses_post( self::build_privacy_policy_content( $retention_days, $cleanup_days, $device_days ) )
		);
	}

	/**
	 * Build the suggested privacy-policy HTML. Each suggested sentence is
	 * prefixed with a `privacy-policy-tutorial`-classed label, which the
	 * Policy Guide shows as guidance but excludes from the "copy" output —
	 * matching WordPress core's own default-content convention.
	 *
	 * @param int $retention_days Configured activity-log retention window.
	 * @param int $cleanup_days   Days after which submissions are deleted, or 0 when the cleanup is off.
	 * @param int $device_days    Configured device-signal retention window.
	 * @return string Privacy-policy HTML (sanitised by the caller).
	 */
	private static function build_privacy_policy_content( int $retention_days, int $cleanup_days = 0, int $device_days = 90 ): string {
		$hint = '<strong class="privacy-policy-tutorial">' . __( 'Suggested text:', 'ffcertificate' ) . ' </strong>';

		// Whole-paragraph guidance — never copied (entire node is tutorial).
		$content = '<p class="privacy-policy-tutorial">' . esc_html__( 'This text is suggested by the Free Form Certificate plugin to help you describe how it processes personal data. Review and adapt it to your organisation and to the modules you actually use (form submissions and certificates, appointment scheduling, audiences, re-registration and recruitment). Delete any section that does not apply.', 'ffcertificate' ) . '</p>';

		$content .= '<h2>' . esc_html__( 'What personal data we collect and why', 'ffcertificate' ) . '</h2>';

		$content .= '<p>' . $hint . esc_html__( 'When you submit a form, request a certificate, book an appointment, join an audience or take part in a re-registration or recruitment process through this site, we collect the personal data you provide. Depending on how each form is configured, this may include your name, e-mail address, telephone number, national identification numbers (such as CPF and RF), free-text answers and any custom fields defined by the administrator.', 'ffcertificate' ) . '</p>';

		$content .= '<p>' . $hint . esc_html__( 'We use this data to issue and verify certificates, manage appointments and audiences, run re-registration and recruitment processes, prevent abuse, and comply with our legal obligations under applicable data-protection law (including the Brazilian LGPD where it applies).', 'ffcertificate' ) . '</p>';

		$content .= '<p>' . $hint . esc_html__( 'For security and accountability we also record your IP address and basic browser information, together with the actions you perform, in an activity log.', 'ffcertificate' ) . '</p>';

		$content .= '<p>' . $hint . esc_html__( 'If a form records quiz answers, your answers and score are kept with your submission. If a site sends date messages, such as a birthday greeting, the date is read from your profile and each message sent is recorded.', 'ffcertificate' ) . '</p>';

		$content .= '<h2>' . esc_html__( 'Location, device and abuse prevention', 'ffcertificate' ) . '</h2>';

		$content .= '<p>' . $hint . esc_html__( 'Some forms and check-ins are available only at certain places. Your browser may then ask for your device\'s location. The position is compared with the allowed areas and is not stored; a form remembers only that the check passed.', 'ffcertificate' ) . '</p>';

		$content .= '<p>' . $hint . esc_html__( 'When a form is restricted by the location of your internet connection, your IP address is sent over an encrypted connection to an IP geolocation service (ipinfo.io, or ip-api.com on its paid plan) to find its approximate region.', 'ffcertificate' ) . '</p>';

		$content .= '<p>' . $hint . sprintf(
			/* translators: %d: number of days device signals are retained. */
			esc_html__( 'To limit repeated or automated submissions, we record a device identifier kept in your browser and technical signals about your device and browser with each submission, and keep them for %d days. Attempts that exceed the limits are logged with your IP address (encrypted) and a keyed hash of the e-mail or document used; the log keeps only the most recent entries.', 'ffcertificate' ),
			$device_days
		) . '</p>';

		$content .= '<p>' . $hint . esc_html__( 'The site stores a few items in your browser: a short-lived cookie while you set a password from an invitation, a cookie that carries an approved schedule exception for one form, a device identifier, and the list of forms you have already submitted from this browser, which is used only to show a notice.', 'ffcertificate' ) . '</p>';

		$content .= '<p>' . $hint . esc_html__( 'When the address lookup is on, the postal code (CEP) you type is sent from our server to ViaCEP or BrasilAPI to find its address; nothing else is sent with it. Short links count how many times they were opened and record nothing about who opened them.', 'ffcertificate' ) . '</p>';

		$content .= '<h2>' . esc_html__( 'How your data is stored', 'ffcertificate' ) . '</h2>';

		$content .= '<p>' . $hint . esc_html__( 'Sensitive identifiers such as e-mail addresses and CPF/RF numbers are stored encrypted at rest. Where consent is required, the date and time you gave it are recorded. One-way hashes of some identifiers are kept so records can be located without exposing the original values.', 'ffcertificate' ) . '</p>';

		$content .= '<h2>' . esc_html__( 'How long we keep your data', 'ffcertificate' ) . '</h2>';

		$content .= '<p>' . $hint . sprintf(
			/* translators: %d: number of days activity-log entries are retained. */
			esc_html__( 'Submission, certificate, appointment and audience records are kept for as long as needed to provide the service and to allow previously issued certificates to remain verifiable. Activity-log entries (including IP and browser information) are retained for %d days and then removed automatically.', 'ffcertificate' ),
			$retention_days
		) . '</p>';

		if ( $cleanup_days > 0 ) {
			$content .= '<p>' . $hint . sprintf(
				/* translators: %d: number of days after which certificate submissions are deleted. */
				esc_html__( 'Certificate submissions are deleted automatically %d days after they were made.', 'ffcertificate' ),
				$cleanup_days
			) . '</p>';
		}

		$content .= '<h2>' . esc_html__( 'Who has access to your data', 'ffcertificate' ) . '</h2>';

		$content .= '<p>' . $hint . esc_html__( 'Your data is accessible to the site administrators and to the authorised operators who manage the relevant forms. We do not sell your personal data.', 'ffcertificate' ) . '</p>';

		$content .= '<h2>' . esc_html__( 'Your rights over your data', 'ffcertificate' ) . '</h2>';

		$content .= '<p>' . $hint . esc_html__( 'You can request a copy of your personal data or ask for it to be erased. The administrator can fulfil these requests from the WordPress "Export Personal Data" and "Erase Personal Data" tools, which cover the data this plugin stores. When data is erased, a certificate\'s form answers are kept without the e-mail address, identification numbers or IP address, so that a certificate already issued remains verifiable.', 'ffcertificate' ) . '</p>';

		return $content;
	}

	/**
	 * Register personal data exporters
	 *
	 * @param array<string, array<string, mixed>> $exporters Existing exporters.
	 * @return array<string, array<string, mixed>> Modified exporters
	 */
	public static function register_exporters( array $exporters ): array {
		$exporters['ffcertificate-profile']               = array(
			'exporter_friendly_name' => __( 'FFC Profile', 'ffcertificate' ),
			'callback'               => array( PrivacyExporters::class, 'export_profile' ),
		);
		$exporters['ffcertificate-certificates']          = array(
			'exporter_friendly_name' => __( 'FFC Certificates', 'ffcertificate' ),
			'callback'               => array( PrivacyExporters::class, 'export_certificates' ),
		);
		$exporters['ffcertificate-appointments']          = array(
			'exporter_friendly_name' => __( 'FFC Appointments', 'ffcertificate' ),
			'callback'               => array( PrivacyExporters::class, 'export_appointments' ),
		);
		$exporters['ffcertificate-audience-groups']       = array(
			'exporter_friendly_name' => __( 'FFC Audience Groups', 'ffcertificate' ),
			'callback'               => array( PrivacyExporters::class, 'export_audience_groups' ),
		);
		$exporters['ffcertificate-audience-bookings']     = array(
			'exporter_friendly_name' => __( 'FFC Audience Bookings', 'ffcertificate' ),
			'callback'               => array( PrivacyExporters::class, 'export_audience_bookings' ),
		);
		$exporters['ffcertificate-audience-attendance']   = array(
			'exporter_friendly_name' => __( 'FFC Audience Attendance', 'ffcertificate' ),
			'callback'               => array( PrivacyExporters::class, 'export_audience_attendance' ),
		);
		$exporters['ffcertificate-audience-form-invites'] = array(
			'exporter_friendly_name' => __( 'FFC Post-event Form Invitations', 'ffcertificate' ),
			'callback'               => array( PrivacyExporters::class, 'export_audience_form_invites' ),
		);
		$exporters['ffcertificate-reregistration']        = array(
			'exporter_friendly_name' => __( 'FFC Reregistration', 'ffcertificate' ),
			'callback'               => array( PrivacyExporters::class, 'export_reregistration' ),
		);
		$exporters['ffcertificate-recruitment']           = array(
			'exporter_friendly_name' => __( 'FFC Recruitment', 'ffcertificate' ),
			'callback'               => array( PrivacyExporters::class, 'export_recruitment' ),
		);
		$exporters['ffcertificate-activity-log']          = array(
			'exporter_friendly_name' => __( 'FFC Activity Log', 'ffcertificate' ),
			'callback'               => array( PrivacyExporters::class, 'export_activity_log' ),
		);
		$exporters['ffcertificate-date-messages']         = array(
			'exporter_friendly_name' => __( 'FFC Date Messages', 'ffcertificate' ),
			'callback'               => array( PrivacyExporters::class, 'export_date_message_log' ),
		);
		$exporters['ffcertificate-usermeta']              = array(
			'exporter_friendly_name' => __( 'FFC User Settings', 'ffcertificate' ),
			'callback'               => array( PrivacyExporters::class, 'export_usermeta' ),
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
			'callback'             => array( PrivacyErasers::class, 'erase_personal_data' ),
		);
		return $erasers;
	}
}
