<?php
/**
 * Settings Save Handler
 *
 * Handles saving and validation of all settings types.
 * Extracted from FFC_Settings (v3.1.1) following Single Responsibility Principle.
 *
 * @since   3.2.0
 * @package FreeFormCertificate\Admin
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

use FreeFormCertificate\Core\Captcha\AltchaCaptcha;
use FreeFormCertificate\Core\Captcha\CaptchaProvider;
use FreeFormCertificate\Core\Captcha\CaptchaSettings;
use FreeFormCertificate\Core\Captcha\MathCaptcha;
use FreeFormCertificate\Submissions\SubmissionHandler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates, sanitizes, and persists plugin settings.
 *
 * @since 3.2.0
 */
class SettingsSaveHandler {

	/**
	 * Submission handler for danger zone operations.
	 *
	 * @var SubmissionHandler
	 */
	private $submission_handler;

	/**
	 * Constructor
	 *
	 * @param SubmissionHandler $handler Submission handler for danger zone.
	 */
	public function __construct( SubmissionHandler $handler ) {
		$this->submission_handler = $handler;
	}

	/**
	 * Handle all settings submissions
	 * Main entry point called by FFC_Settings
	 */
	public function handle_all_submissions(): void {
		if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_settings' ) ) {
			return;
		}

		// Handle General/SMTP/QR Settings.
		if ( wp_verify_nonce( \FreeFormCertificate\Core\RequestInput::get_post_string( 'ffc_settings_nonce' ), 'ffc_settings_action' ) ) {
			$this->save_general_and_specific_settings();
		}

		// Handle User Access Settings (v3.1.0).
		if ( wp_verify_nonce( \FreeFormCertificate\Core\RequestInput::get_post_string( 'ffc_user_access_nonce' ), 'ffc_user_access_settings' ) ) {
			$this->save_user_access_settings();
		}

		// Handle Global Data Deletion (Danger Zone). Requires the dedicated
		// danger-zone sub-cap (#711) on top of the page's ffc_manage_settings —
		// a settings manager without ffc_manage_settings_dangerzone cannot run
		// destructive actions.
		if ( isset( $_POST['ffc_delete_all_data'] )
			&& \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_settings_dangerzone' ) ) {
			// check_admin_referer() wp_die()s on an invalid nonce, so it never
			// returns a falsy value — call it as a statement rather than as a
			// condition. Same behaviour, minus an operand that is always true.
			check_admin_referer( 'ffc_delete_all_data', 'ffc_critical_nonce' );
			$this->handle_danger_zone();
		}
	}

	/**
	 * Save general and tab-specific settings (General, SMTP, QR Code, Date Format)
	 *
	 * @return void
	 */
	private function save_general_and_specific_settings(): void {
		$current = get_option( 'ffc_settings', array() );
		$new     = \FreeFormCertificate\Core\RequestInput::get_post_array( 'ffc_settings' );

		$clean = $current;

		// Process each settings type.
		$clean = $this->save_general_settings( $clean, $new );
		$clean = $this->save_smtp_settings( $clean, $new );
		$clean = $this->save_qrcode_settings( $clean, $new );
		$clean = $this->save_url_shortener_settings( $clean, $new );
		$clean = $this->save_captcha_settings( $clean, $new );
		$clean = $this->save_public_design_settings( $clean, $new );

		// "Email Model" chrome lives in its own option (`ffc_email_template`),
		// posted from its own form in the SMTP tab.
		$this->save_email_template_settings();

		/**
		 * Filters plugin settings before they are saved.
		 *
		 * @since 4.6.4
		 * @param array $clean   Settings to be saved.
		 * @param array $current Previous settings.
		 */
		$clean = apply_filters( 'ffcertificate_settings_before_save', $clean, $current );

		// Settings that delete or suppress data are audited before they are
		// written, so switching the log off is still recorded (#2340).
		DangerzoneSettingsAudit::record( is_array( $current ) ? $current : array(), $clean );
		SettingsChangeAudit::record( 'ffc_settings', is_array( $current ) ? $current : array(), $clean );

		update_option( 'ffc_settings', $clean );

		/**
		 * Fires after plugin settings are saved.
		 *
		 * @since 4.6.4
		 * @param array $clean Saved settings.
		 */
		do_action( 'ffcertificate_settings_saved', $clean );

		add_settings_error( 'ffc_settings', 'ffc_settings_updated', __( 'Settings saved.', 'ffcertificate' ), 'updated' );
	}

	/**
	 * Save General tab settings
	 *
	 * @param array<string, mixed> $clean Current settings.
	 * @param array<string, mixed> $new New settings from POST.
	 * @return array<string, mixed> Updated settings
	 */
	private function save_general_settings( array $clean, array $new ): array {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_all_submissions() via wp_verify_nonce.
		$ffc_tab = $this->posted_tab();

		// Every section is rebuilt from its own tab's form only (#2338): a
		// switch absent from another tab's POST is not "off", and a key
		// another tab never posts must keep its stored value.
		if ( 'general' === $ffc_tab ) {
			$clean = $this->save_general_tab( $clean, $new );
		}

		$clean = $this->save_certificates_tab( $clean, $ffc_tab, $new );

		if ( 'advanced' === $ffc_tab ) {
			$clean = $this->save_advanced_tab( $clean, $new );
		}

        // phpcs:enable WordPress.Security.NonceVerification.Missing

		return $clean;
	}

	/**
	 * Whether the current user may change the settings listed in
	 * `SettingsAjaxEndpoint::dangerzone_settings()`.
	 *
	 * @return bool
	 */
	private static function can_change_dangerzone_settings(): bool {
		return \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_settings_dangerzone' );
	}

	/**
	 * The tab whose form was posted (`_ffc_tab`), or '' when none was.
	 *
	 * @return string
	 */
	private function posted_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_all_submissions() via wp_verify_nonce.
		return isset( $_POST['_ffc_tab'] ) ? sanitize_key( wp_unslash( $_POST['_ffc_tab'] ) ) : '';
	}

	/**
	 * A posted number inside its bounds, or null when the field was left
	 * empty or holds no whole number (#2338). `absint( '' )` is 0, so a
	 * cleared field used to store the floor; null keeps the stored value.
	 *
	 * The bounds default to the field's autosave entry, so the form save and
	 * the autosave clamp alike.
	 *
	 * @param array<string, mixed> $new Posted settings.
	 * @param string               $key Setting key.
	 * @param int|null             $min Lower bound, or null for the autosave entry's.
	 * @param int|null             $max Upper bound, or null for the autosave entry's.
	 * @return int|null
	 */
	private function bounded_int( array $new, string $key, ?int $min = null, ?int $max = null ): ?int {
		$value = \FreeFormCertificate\Core\RequestInput::optional_int( $new[ $key ] ?? null );
		if ( null === $value ) {
			return null;
		}
		$entry = SettingsAjaxEndpoint::allowlist()[ $key ] ?? array();
		$min   = $min ?? ( isset( $entry['min'] ) ? (int) $entry['min'] : null );
		$max   = $max ?? ( isset( $entry['max'] ) ? (int) $entry['max'] : null );
		if ( null !== $min ) {
			$value = max( $min, $value );
		}
		if ( null !== $max ) {
			$value = min( $max, $value );
		}
		return $value;
	}

	/**
	 * Save the General tab.
	 *
	 * @param array<string, mixed> $clean Current settings.
	 * @param array<string, mixed> $new   New settings from POST.
	 * @return array<string, mixed> Updated settings
	 */
	private function save_general_tab( array $clean, array $new ): array {
		// Dark Mode (v4.6.16).
		if ( isset( $new['dark_mode'] ) ) {
			$allowed_modes      = array( 'off', 'on', 'auto' );
			$clean['dark_mode'] = in_array( $new['dark_mode'], $allowed_modes, true ) ? $new['dark_mode'] : 'off';
		}

		// Code Editor theme (Certificate HTML editor via CodeMirror). Lives next to
		// Dark Mode on the General tab since #1148 item 2 — its 'auto' value reads
		// dark_mode, so the two only make sense side by side.
		if ( isset( $new['code_editor_theme'] ) ) {
			$allowed_themes             = array( 'auto', 'light', 'dark' );
			$clean['code_editor_theme'] = in_array( $new['code_editor_theme'], $allowed_themes, true )
				? $new['code_editor_theme']
				: 'dark';
		}

		// Submission auto-delete (#936). The enable toggle is a checkbox, so an
		// unchecked switch is simply absent from POST — rebuild it on a General
		// save (no-clobber) from presence. The day window is clamped to >= 1;
		// the toggle, not a 0-day value, is what disables the cleanup. Both
		// need the Danger Zone capability (#2338); without it they keep the
		// stored value.
		$dangerzone = self::can_change_dangerzone_settings();
		if ( $dangerzone ) {
			$clean['cleanup_enabled'] = ! empty( $new['cleanup_enabled'] );
		}

		// RF check digit (#1345). A checkbox, so rebuild it from presence on
		// a General save -- the no-clobber invariant: a key the rebuild
		// omits is silently reset on the next Save, however recently the
		// autosave wrote it.
		$clean['validate_rf_check_digit'] = ! empty( $new['validate_rf_check_digit'] );

		// Address lookup by CEP (#2234). The toggle is rebuilt from presence
		// like the RF one; the provider is kept to its two values.
		$clean['cep_lookup_enabled'] = ! empty( $new['cep_lookup_enabled'] );
		if ( isset( $new['cep_lookup_primary'] ) ) {
			$clean['cep_lookup_primary'] = in_array( $new['cep_lookup_primary'], array( 'viacep', 'brasilapi' ), true )
				? $new['cep_lookup_primary']
				: 'viacep';
		}
		$days = $dangerzone ? $this->bounded_int( $new, 'cleanup_days' ) : null;
		if ( null !== $days ) {
			$clean['cleanup_days'] = $days;
		}

		// Main Address.
		if ( isset( $new['main_address'] ) ) {
			$clean['main_address'] = sanitize_text_field( $new['main_address'] );
		}

		// CSV Download Page URL.
		if ( isset( $new['csv_download_page_url'] ) ) {
			$clean['csv_download_page_url'] = esc_url_raw( $new['csv_download_page_url'] );
		}

		// Branding logos (#865 Phase 2) — {{logo_gov}} / {{logo_org}} sources.
		if ( isset( $new['logo_gov'] ) ) {
			$clean['logo_gov'] = esc_url_raw( $new['logo_gov'] );
		}
		if ( isset( $new['logo_org'] ) ) {
			$clean['logo_org'] = esc_url_raw( $new['logo_org'] );
		}

		return $this->save_date_format_settings( $clean, $new );
	}

	/**
	 * Save the Certificates tab.
	 *
	 * @param array<string, mixed> $clean   Current settings.
	 * @param string               $ffc_tab Posted tab.
	 * @param array<string, mixed> $new     New settings from POST.
	 * @return array<string, mixed> Updated settings
	 */
	private function save_certificates_tab( array $clean, string $ffc_tab, array $new ): array {
		// The "Required data" table (#1759). It also autosaves as a whole;
		// this keeps the tab's Save button writing what is shown. The rows
		// are read raw, since `$new` went through sanitize_text_field(). The
		// Email and {{auth_code}} rows are always rendered, so a table that is
		// not posted at all was not on the page. A row left out is unchecked,
		// not unchanged: its switches are absent from the POST.
		$raw_settings = \FreeFormCertificate\Core\RequestInput::get_post_raw_array( 'ffc_settings' );
		if ( 'certificates' === $ffc_tab && isset( $raw_settings['required_data'] ) && is_array( $raw_settings['required_data'] ) ) {
			$table = \FreeFormCertificate\Settings\RequiredData::sanitize( array_values( array_filter( $raw_settings['required_data'], 'is_array' ) ) );
			if ( null !== $table ) {
				$clean['required_data'] = $table;
				// The table replaces the two settings it was built from; once
				// it is saved nothing reads them, so they leave with the save.
				unset( $clean['required_form_fields'], $clean['required_certificate_tags'] );
			}
		}

		// The Strict quiz level's grace (#1745). An emptied field is not 0:
		// it keeps the stored value, as every bounded number here does.
		if ( 'certificates' === $ffc_tab && isset( $raw_settings['quiz_focus_grace_ms'] ) && is_scalar( $raw_settings['quiz_focus_grace_ms'] ) && '' !== trim( (string) $raw_settings['quiz_focus_grace_ms'] ) ) {
			$clean['quiz_focus_grace_ms'] = \FreeFormCertificate\Core\QuizLeaveLevel::clamp_grace( absint( $raw_settings['quiz_focus_grace_ms'] ) );
		}

		// The browser check (#2030). A checkbox, so rebuilt from presence on
		// every Certificates save (the no-clobber invariant).
		if ( 'certificates' === $ffc_tab ) {
			$clean['browser_check'] = ! empty( $new['browser_check'] );
		}

		return $clean;
	}

	/**
	 * Save the Advanced tab.
	 *
	 * @param array<string, mixed> $clean Current settings.
	 * @param array<string, mixed> $new   New settings from POST.
	 * @return array<string, mixed> Updated settings
	 */
	private function save_advanced_tab( array $clean, array $new ): array {
		// The activity log switch, its audit items and its retention need the
		// Danger Zone capability (#2338): without it they keep the stored
		// value, so the audit trail cannot be switched off or shortened.
		$dangerzone = self::can_change_dangerzone_settings();

		if ( $dangerzone ) {
			$clean['enable_activity_log'] = isset( $new['enable_activity_log'] ) ? 1 : 0;

			// Retention in days and a record cap, bounded by one helper
			// that the autosave reads too (#2338).
			$days = $this->bounded_int( $new, 'activity_log_retention_days' );
			if ( null !== $days ) {
				$clean['activity_log_retention_days'] = \FreeFormCertificate\Core\ActivityLogRetention::clamp_days( $days );
			}
			$records = $this->bounded_int( $new, 'activity_log_max_records' );
			if ( null !== $records ) {
				$clean['activity_log_max_records'] = \FreeFormCertificate\Core\ActivityLogRetention::clamp_records( $records );
			}
		}

		// Minimum level and the items (#2340). While the log is off the screen
		// greys them out, and a disabled control posts nothing, so they keep
		// their stored values instead of reading as switched off. Audit items
		// also need the Danger Zone capability; secondary ones only the tab's.
		$log_on = ! empty( $clean['enable_activity_log'] );
		if ( $log_on && isset( $new['activity_log_min_level'] ) ) {
			$lvl                             = sanitize_key( (string) $new['activity_log_min_level'] );
			$clean['activity_log_min_level'] = in_array( $lvl, array( 'debug', 'info', 'warning', 'error' ), true ) ? $lvl : 'debug';
		}
		if ( $log_on ) {
			foreach ( \FreeFormCertificate\Core\ActivityLogItems::ITEMS as $ffc_item => $ffc_definition ) {
				if ( \FreeFormCertificate\Core\ActivityLogItems::GROUP_AUDIT === $ffc_definition['group'] && ! $dangerzone ) {
					continue;
				}
				$ffc_key           = \FreeFormCertificate\Core\ActivityLogItems::setting_key( $ffc_item );
				$clean[ $ffc_key ] = empty( $new[ $ffc_key ] ) ? 0 : 1;
			}
		}

		$rows = $this->bounded_int(
			$new,
			'public_csv_sync_max_rows',
			\FreeFormCertificate\Frontend\PublicCsvExporter::SYNC_MAX_ROWS_MIN,
			\FreeFormCertificate\Frontend\PublicCsvExporter::SYNC_MAX_ROWS_MAX
		);
		if ( null !== $rows ) {
			$clean['public_csv_sync_max_rows'] = $rows;
		}

		// Debug Settings.
		$debug_flags = array(
			'debug_pdf_generator',
			'debug_email_handler',
			'debug_form_processor',
			'debug_encryption',
			'debug_geofence',
			'debug_user_manager',
			'debug_rest_api',
			'debug_migrations',
			'debug_activity_log',
			'debug_frontend',
			'debug_admin',
			'debug_self_scheduling',
			'debug_audience',
			'debug_qrcode',
			// 6.6.4 follow-up (#361 Sprint 1).
			'debug_browser_env',
		);

		foreach ( $debug_flags as $flag ) {
			$clean[ $flag ] = isset( $new[ $flag ] ) ? 1 : 0;
		}

		// Public CSV Download default limit.
		$limit = $this->bounded_int( $new, 'public_csv_default_limit' );
		if ( null !== $limit ) {
			$clean['public_csv_default_limit'] = $limit;
		}

		return $clean;
	}

	/**
	 * Save SMTP tab settings
	 *
	 * @param array<string, mixed> $clean Current settings.
	 * @param array<string, mixed> $new New settings from POST.
	 * @return array<string, mixed> Updated settings
	 */
	private function save_smtp_settings( array $clean, array $new ): array {
		// SMTP / email settings carry their own sub-cap (#711): a user with
		// ffc_manage_settings but not ffc_manage_settings_smtp can save the other
		// tabs but not the SMTP transport / email config, so leave those fields
		// untouched. The one-shot migration grants the sub-cap to every existing
		// ffc_manage_settings holder, so this only restricts deliberately-limited
		// roles.
		if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_settings_smtp' ) ) {
			return $clean;
		}

		// Only the SMTP tab's form carries these fields (#2338).
		if ( 'smtp' !== $this->posted_tab() ) {
			return $clean;
		}

		// The global "disable all emails" kill-switch is a destructive toggle, so
		// it also requires the dangerzone sub-cap (#739 §4.4): a _smtp-only
		// operator saves the transport but keeps the current kill-switch value.
		// It is read from the "Enable email sending" toggle itself, which is a
		// field of this form: a hidden copy frozen at page load used to undo
		// what the toggle had just autosaved (#2338).
		if ( \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_settings_dangerzone' ) ) {
			$clean['disable_all_emails'] = \FreeFormCertificate\Core\RequestInput::get_post_bool( 'emails_enabled' ) ? 0 : 1;
		}

		// User creation email settings (radio buttons - always have a value).
		if ( isset( $new['send_wp_user_email_submission'] ) ) {
			$clean['send_wp_user_email_submission'] = sanitize_text_field( $new['send_wp_user_email_submission'] );
		}

		if ( isset( $new['send_wp_user_email_appointment'] ) ) {
			$clean['send_wp_user_email_appointment'] = sanitize_text_field( $new['send_wp_user_email_appointment'] );
		}

		if ( isset( $new['send_wp_user_email_csv_import'] ) ) {
			$clean['send_wp_user_email_csv_import'] = sanitize_text_field( $new['send_wp_user_email_csv_import'] );
		}

		if ( isset( $new['send_wp_user_email_migration'] ) ) {
			$clean['send_wp_user_email_migration'] = sanitize_text_field( $new['send_wp_user_email_migration'] );
		}

		// Access-granted notification (#1123). Same shape as the four above:
		// the view emits a hidden '0' sibling, so the key is always present
		// on an SMTP-tab POST and the value is what decides.
		if ( isset( $new['notify_capability_grant'] ) ) {
			$clean['notify_capability_grant'] = sanitize_text_field( $new['notify_capability_grant'] );
		}

		if ( isset( $new['smtp_mode'] ) ) {
			$clean['smtp_mode'] = sanitize_key( $new['smtp_mode'] );
		}

		if ( isset( $new['smtp_host'] ) ) {
			$clean['smtp_host'] = sanitize_text_field( $new['smtp_host'] );
		}

		$port = $this->bounded_int( $new, 'smtp_port', 1, 65535 );
		if ( null !== $port ) {
			$clean['smtp_port'] = $port;
		}

		if ( isset( $new['smtp_user'] ) ) {
			$clean['smtp_user'] = sanitize_text_field( $new['smtp_user'] );
		}

		// The password is read raw: `$new` went through sanitize_text_field(),
		// which changes a password holding `<`, `%xx` or extra spaces. An empty
		// field keeps the stored one, so saving with "Change password" closed
		// never erases it; turning authentication off is how to stop using it
		// (#2342).
		$raw_settings = \FreeFormCertificate\Core\RequestInput::get_post_raw_array( 'ffc_settings' );
		$typed_pass   = isset( $raw_settings['smtp_pass'] ) && is_string( $raw_settings['smtp_pass'] ) ? $raw_settings['smtp_pass'] : '';
		$stored_pass  = \FreeFormCertificate\Integrations\SmtpPassword::for_storage( $typed_pass );
		if ( '' !== $stored_pass ) {
			$clean['smtp_pass'] = $stored_pass;
		}

		if ( isset( $new['smtp_secure'] ) ) {
			$clean['smtp_secure'] = sanitize_key( $new['smtp_secure'] );
		}

		if ( isset( $new['smtp_from_email'] ) ) {
			$clean['smtp_from_email'] = sanitize_email( $new['smtp_from_email'] );
		}

		if ( isset( $new['smtp_from_name'] ) ) {
			$clean['smtp_from_name'] = sanitize_text_field( $new['smtp_from_name'] );
		}

		return $clean;
	}

	/**
	 * Save the "Email Model" chrome options (`ffc_email_template`).
	 *
	 * Only runs when the Email Model form was submitted (its `ffc_email_template`
	 * array is present), so submitting any other settings form leaves the model
	 * untouched. {@see \FreeFormCertificate\Core\EmailTemplateOptions::update()}
	 * sanitizes every field.
	 *
	 * @return void
	 */
	private function save_email_template_settings(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_all_submissions() via wp_verify_nonce.
		if ( ! isset( $_POST['ffc_email_template'] ) ) {
			return;
		}
		// The Email Model is part of the SMTP/email surface — gate it on the
		// same sub-cap as the SMTP transport (#711).
		if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_settings_smtp' ) ) {
			return;
		}
		$raw = \FreeFormCertificate\Core\RequestInput::get_post_array( 'ffc_email_template' );
		\FreeFormCertificate\Core\EmailTemplateOptions::update( $raw );
		add_settings_error( 'ffc_settings', 'ffc_email_model_updated', __( 'Email Model saved.', 'ffcertificate' ), 'updated' );
	}

	/**
	 * Save QR Code tab settings
	 *
	 * @param array<string, mixed> $clean Current settings.
	 * @param array<string, mixed> $new New settings from POST.
	 * @return array<string, mixed> Updated settings
	 */
	private function save_qrcode_settings( array $clean, array $new ): array {
		$ffc_tab = $this->posted_tab();

		// QR Cache checkbox - v4.6.16: now on Cache tab (was qr_code tab).
		if ( 'cache' === $ffc_tab ) {
			$clean['qr_cache_enabled'] = isset( $new['qr_cache_enabled'] ) ? 1 : 0;
		}

		if ( 'qr_code' !== $ffc_tab ) {
			return $clean;
		}

		// Clamped to the bounds the autosave uses; an emptied field keeps
		// the stored value (#2338).
		$size = $this->bounded_int( $new, 'qr_default_size' );
		if ( null !== $size ) {
			$clean['qr_default_size'] = $size;
		}

		$margin = $this->bounded_int( $new, 'qr_default_margin' );
		if ( null !== $margin ) {
			$clean['qr_default_margin'] = $margin;
		}

		if ( isset( $new['qr_default_error_level'] ) ) {
			$level                           = strtoupper( sanitize_text_field( $new['qr_default_error_level'] ) );
			$clean['qr_default_error_level'] = in_array( $level, array( 'L', 'M', 'Q', 'H' ), true ) ? $level : 'M';
		}

		// The design and its two switches only exist on the QR Code tab, so
		// they are rebuilt from that form alone: an unchecked switch is absent
		// from the POST, and any other tab's save must not read that as "off".
		// The values go through `QrDesign`, the same normaliser the renderer
		// reads with, so nothing the renderer would refuse is ever stored.
		$design = new \FreeFormCertificate\Generators\QrDesign(
			array(
				'dots'            => $new['qr_design_dots'] ?? null,
				'eye_frame'       => $new['qr_design_eye_frame'] ?? null,
				'eye_ball'        => $new['qr_design_eye_ball'] ?? null,
				'color'           => $new['qr_design_color'] ?? null,
				'gradient'        => true,
				'color_end'       => $new['qr_design_color_end'] ?? null,
				'background'      => $new['qr_design_background'] ?? null,
				'eye_frame_color' => $new['qr_design_eye_frame_color'] ?? null,
				'eye_ball_color'  => $new['qr_design_eye_ball_color'] ?? null,
				'frame'           => $new['qr_design_frame'] ?? null,
				'frame_text'      => $new['qr_design_frame_text'] ?? null,
				'frame_color'     => $new['qr_design_frame_color'] ?? null,
				'frame_icon'      => $new['qr_design_frame_icon'] ?? null,
			)
		);

		// Only an image the renderer can embed is kept; anything else --
		// another post type, an SVG, a deleted attachment -- clears the logo.
		$logo_id = absint( $new['qr_design_logo_id'] ?? 0 );
		if ( $logo_id > 0 && ! in_array( (string) get_post_mime_type( $logo_id ), \FreeFormCertificate\Generators\QrLogo::MIMES, true ) ) {
			$logo_id = 0;
		}

		$clean['qr_design_dots']            = $design->dots;
		$clean['qr_design_eye_frame']       = $design->eye_frame;
		$clean['qr_design_eye_ball']        = $design->eye_ball;
		$clean['qr_design_color']           = $design->color;
		$clean['qr_design_gradient']        = isset( $new['qr_design_gradient'] ) ? 1 : 0;
		$clean['qr_design_color_end']       = $design->color_end;
		$clean['qr_design_background']      = $design->background;
		$clean['qr_design_eye_frame_color'] = $design->eye_frame_color;
		$clean['qr_design_eye_ball_color']  = $design->eye_ball_color;
		$clean['qr_design_logo_id']         = $logo_id;
		$clean['qr_design_frame']           = $design->frame;
		$clean['qr_design_frame_text']      = $design->frame_text;
		$clean['qr_design_frame_color']     = $design->frame_color;
		$clean['qr_design_frame_icon']      = $design->frame_icon;
		$clean['qr_design_transparent']     = isset( $new['qr_design_transparent'] ) ? 1 : 0;
		$clean['qr_design_on_certificate']  = isset( $new['qr_design_on_certificate'] ) ? 1 : 0;
		$clean['qr_design_on_short_urls']   = isset( $new['qr_design_on_short_urls'] ) ? 1 : 0;

		return $clean;
	}

	/**
	 * Save the Public Design tab.
	 *
	 * The tab's switch and values only exist on its own form, so they are
	 * rebuilt from that form alone, through `PublicDesign`, the same
	 * normaliser the stylesheet reads with. A colour pair below WCAG's floor
	 * is still saved, with a warning naming it: the administrator may have a
	 * reason, and refusing the colour would hide which pair was weak.
	 *
	 * @param array<string, mixed> $clean Current settings.
	 * @param array<string, mixed> $new New settings from POST.
	 * @return array<string, mixed> Updated settings
	 */
	private function save_public_design_settings( array $clean, array $new ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_all_submissions() via wp_verify_nonce.
		if ( ! isset( $_POST['_ffc_tab'] ) || 'public_design' !== sanitize_key( wp_unslash( $_POST['_ffc_tab'] ) ) ) {
			return $clean;
		}

		$raw = array();
		foreach ( \FreeFormCertificate\Core\PublicDesign::KEYS as $field => $key ) {
			$raw[ $field ] = $new[ $key ] ?? null;
		}
		$design = new \FreeFormCertificate\Core\PublicDesign( $raw );

		$warning = $design->contrast_warning();
		if ( '' !== $warning ) {
			add_settings_error(
				'ffc_settings',
				'ffc_public_design_contrast',
				sprintf(
					/* translators: %s: the warning that names the weak colour pairs. */
					__( 'The design was saved. %s', 'ffcertificate' ),
					$warning
				),
				'warning'
			);
		}

		return array_merge( $clean, $design->to_settings() );
	}

	/**
	 * Save Date Format settings (v2.10.0)
	 *
	 * @param array<string, mixed> $clean Current settings.
	 * @param array<string, mixed> $new New settings from POST.
	 * @return array<string, mixed> Updated settings
	 */
	private function save_date_format_settings( array $clean, array $new ): array {
		if ( isset( $new['date_format'] ) ) {
			$clean['date_format'] = sanitize_text_field( $new['date_format'] );
		}

		if ( isset( $new['date_format_custom'] ) ) {
			$clean['date_format_custom'] = sanitize_text_field( $new['date_format_custom'] );
		}

		return $clean;
	}

	/**
	 * Save the captcha strategy and its widget options (#1053).
	 *
	 * Gated on the tab marker, like every other section here, so saving any
	 * other tab preserves these keys rather than rebuilding them from a POST
	 * that never carried them.
	 *
	 * @param array<string, mixed> $clean Current settings.
	 * @param array<string, mixed> $new   New settings from POST.
	 * @return array<string, mixed> Updated settings.
	 */
	private function save_captcha_settings( array $clean, array $new ): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_all_submissions().
		$ffc_tab = isset( $_POST['_ffc_tab'] ) ? sanitize_key( wp_unslash( $_POST['_ffc_tab'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( 'captcha' !== $ffc_tab ) {
			return $clean;
		}

		$requested = CaptchaSettings::one_of(
			$new['captcha_provider'] ?? '',
			CaptchaProvider::available(),
			MathCaptcha::ID
		);

		/*
		 * The ALTCHA widget refuses to run outside a secure context — it
		 * throws "Secure context (HTTPS) required" rather than degrading — so
		 * a mode with no arithmetic fallback would leave every visitor on a
		 * plain-HTTP site with a form they cannot submit. Refuse the save and
		 * keep whatever was configured before, rather than accepting a value
		 * that produces a broken public page.
		 *
		 * The composite mode is allowed: its `<noscript>` half still works,
		 * which is exactly what it is for.
		 */
		if ( AltchaCaptcha::ID === $requested && ! is_ssl() ) {
			add_settings_error(
				'ffc_settings',
				'ffc_captcha_requires_https',
				__( 'The ALTCHA-only mode was not saved: the widget refuses to run on a page that is not served over HTTPS, which would leave every visitor unable to submit. Serve the site over HTTPS, or choose the mode that keeps the math challenge as a fallback.', 'ffcertificate' ),
				'error'
			);

			$requested = CaptchaSettings::one_of(
				$clean['captcha_provider'] ?? '',
				CaptchaProvider::available(),
				MathCaptcha::ID
			);
		}

		$clean['captcha_provider'] = $requested;

		if ( isset( $new['captcha_altcha_complexity'] ) ) {
			$clean['captcha_altcha_complexity'] = CaptchaSettings::clamp_complexity( $new['captcha_altcha_complexity'] );
		}
		if ( isset( $new['captcha_altcha_ttl'] ) ) {
			$clean['captcha_altcha_ttl'] = CaptchaSettings::clamp_ttl( $new['captcha_altcha_ttl'] );
		}

		$clean['captcha_altcha_type'] = CaptchaSettings::one_of( $new['captcha_altcha_type'] ?? '', CaptchaSettings::types(), 'checkbox' );
		$clean['captcha_altcha_auto'] = CaptchaSettings::one_of( $new['captcha_altcha_auto'] ?? '', CaptchaSettings::auto_modes(), 'off' );

		// Toggles worded as what is shown (#1758), over keys that still store
		// what is hidden: absent from the POST means off, which means hidden.
		$clean['captcha_altcha_hide_logo']   = empty( $new['captcha_altcha_show_logo'] ) ? 1 : 0;
		$clean['captcha_altcha_hide_footer'] = empty( $new['captcha_altcha_show_footer'] ) ? 1 : 0;

		return $clean;
	}

	/**
	 * Save URL Shortener settings (v5.1.0)
	 *
	 * @param array<string, mixed> $clean Current settings.
	 * @param array<string, mixed> $new New settings from POST.
	 * @return array<string, mixed> Updated settings
	 */
	private function save_url_shortener_settings( array $clean, array $new ): array {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_all_submissions().
		// Only the Short URLs tab's form carries these fields (#2338).
		if ( 'url_shortener' !== $this->posted_tab() ) {
			return $clean;
		}

		// Checkbox fields (unchecked = absent from POST).
		// NOTE: `url_shortener_enabled` is intentionally NOT written here — the
		// module on/off toggle lives solely on the Modules tab (autosaved), so this
		// form must preserve the existing value rather than zero it out when the
		// (now-removed) enable checkbox is absent from the POST.
		$clean['url_shortener_auto_create'] = isset( $new['url_shortener_auto_create'] ) ? 1 : 0;

		if ( isset( $new['url_shortener_prefix'] ) ) {
			$clean = $this->save_url_shortener_prefix( $clean, (string) $new['url_shortener_prefix'] );
		}

		$length = $this->bounded_int( $new, 'url_shortener_code_length', 4, 10 );
		if ( null !== $length ) {
			$clean['url_shortener_code_length'] = $length;
		}

		if ( isset( $new['url_shortener_redirect_type'] ) ) {
			$type                                 = (int) $new['url_shortener_redirect_type'];
			$clean['url_shortener_redirect_type'] = in_array( $type, array( 301, 302, 307 ), true ) ? $type : 302;
		}

		// Post types is an array of checkboxes.
		if ( isset( $_POST['ffc_settings']['url_shortener_post_types'] ) && is_array( $_POST['ffc_settings']['url_shortener_post_types'] ) ) {
			$clean['url_shortener_post_types'] = array_map( 'sanitize_key', wp_unslash( $_POST['ffc_settings']['url_shortener_post_types'] ) );
		} else {
			$clean['url_shortener_post_types'] = array();
		}

		// "Expose as shortlink" is a strict subset of the shortened types:
		// intersect the submitted expose list with the (already-sanitized)
		// post-types list so the stored value can never expose a type that
		// is not also shortened, even if the coupling JS was bypassed.
		if ( isset( $_POST['ffc_settings']['url_shortener_expose_post_types'] ) && is_array( $_POST['ffc_settings']['url_shortener_expose_post_types'] ) ) {
			$expose = array_map( 'sanitize_key', wp_unslash( $_POST['ffc_settings']['url_shortener_expose_post_types'] ) );
		} else {
			$expose = array();
		}
		$clean['url_shortener_expose_post_types'] = array_values(
			array_intersect( $expose, $clean['url_shortener_post_types'] )
		);

        // phpcs:enable WordPress.Security.NonceVerification.Missing
		return $clean;
	}

	/**
	 * Save the short URL prefix and remember the ones it replaces (#2337).
	 *
	 * An empty prefix is refused: every short URL would sit at the site's
	 * root. A changed prefix joins the list of previous prefixes, whose links
	 * keep working by forwarding to the current one. A previous prefix that
	 * is now the address of a page or post is refused, since the two would
	 * share the same addresses.
	 *
	 * @param array<string, mixed> $clean     Current settings.
	 * @param string               $requested The prefix as posted.
	 * @return array<string, mixed>
	 */
	private function save_url_shortener_prefix( array $clean, string $requested ): array {
		$old_prefix = (string) ( $clean['url_shortener_prefix'] ?? 'go' );
		$prefix     = sanitize_title( $requested );

		if ( '' === $prefix ) {
			add_settings_error( 'ffc_settings', 'ffc_short_url_prefix_empty', __( 'The URL prefix cannot be empty. Choose a short word, such as "go".', 'ffcertificate' ) );
			return $clean;
		}

		$stored   = is_array( $clean['url_shortener_previous_prefixes'] ?? null ) ? $clean['url_shortener_previous_prefixes'] : array();
		$previous = array_map( 'sanitize_title', array_map( 'strval', $stored ) );
		if ( $prefix !== $old_prefix && '' !== $old_prefix ) {
			$previous[] = $old_prefix;
		}
		$previous = array_values( array_unique( array_diff( array_filter( $previous ), array( $prefix ) ) ) );

		$public_types = array_values( get_post_types( array( 'public' => true ) ) );
		foreach ( $previous as $earlier ) {
			$page = get_page_by_path( $earlier, OBJECT, $public_types );
			if ( $page instanceof \WP_Post ) {
				add_settings_error(
					'ffc_settings',
					'ffc_short_url_prefix_taken',
					sprintf(
						/* translators: 1: a previous short URL prefix, 2: title of the page that now uses it as its address */
						__( 'The previous prefix "%1$s" is now the address of "%2$s". Change that address or choose another prefix, so that links printed with "%1$s" keep working.', 'ffcertificate' ),
						$earlier,
						get_the_title( $page )
					)
				);
				return $clean;
			}
		}

		$clean['url_shortener_prefix']            = $prefix;
		$clean['url_shortener_previous_prefixes'] = $previous;

		if ( $prefix !== $old_prefix ) {
			// Forgetting the flag makes the next request flush once, after
			// `init` has registered the NEW prefix's rule. A flush here, at
			// shutdown of this request, would write the old prefix's rule,
			// which is the one registered now (#2337).
			delete_option( 'ffc_url_shortener_rewrite_version' );
			add_settings_error(
				'ffc_settings',
				'ffc_short_url_prefix_changed',
				sprintf(
					/* translators: 1: new short URL prefix, 2: previous prefix */
					__( 'Prefix changed to "%1$s". Links and QR codes printed with "%2$s" keep working.', 'ffcertificate' ),
					$prefix,
					$old_prefix
				),
				'success'
			);
		}

		return $clean;
	}

	/**
	 * Save User Access settings (v3.1.0)
	 *
	 * @return void
	 */
	private function save_user_access_settings(): void {
		$stored = get_option( 'ffc_user_access_settings', array() );
		$stored = is_array( $stored ) ? $stored : array();

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_all_submissions() via wp_verify_nonce.
		$settings = array(
			'block_wp_admin'    => isset( $_POST['block_wp_admin'] ),
			// A role that administers the site is dropped (#2338).
			'blocked_roles'     => \FreeFormCertificate\UserDashboard\AccessControl::blockable( \FreeFormCertificate\Core\RequestInput::get_post_array( 'blocked_roles', array( 'ffc_end_user' ) ) ),
			'redirect_url'      => ! empty( $_POST['redirect_url'] )
				? esc_url_raw( wp_unslash( $_POST['redirect_url'] ) )
				: home_url( '/dashboard' ),
			'redirect_message'  => isset( $_POST['redirect_message'] )
				? sanitize_textarea_field( wp_unslash( $_POST['redirect_message'] ) )
				: '',
			'allow_admin_bar'   => isset( $_POST['allow_admin_bar'] ),
			// Whether administrators skip the block is theirs to decide (#2338).
			'bypass_for_admins' => current_user_can( 'manage_options' )
				? isset( $_POST['bypass_for_admins'] )
				: ! empty( $stored['bypass_for_admins'] ?? true ),
		);
        // phpcs:enable WordPress.Security.NonceVerification.Missing

		update_option( 'ffc_user_access_settings', $settings );
		add_settings_error(
			'ffc_user_access_settings',
			'ffc_user_access_updated',
			__( 'User Access settings saved successfully.', 'ffcertificate' ),
			'updated'
		);
	}

	/**
	 * How many submissions each Danger Zone target removes (#2338), shown in
	 * the target list and in the confirmation. Empty for an account that
	 * cannot delete, which sees the controls disabled.
	 *
	 * @return array<int, int> Row count by form ID.
	 */
	public static function danger_zone_counts(): array {
		if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_settings_dangerzone' ) ) {
			return array();
		}
		return ( new \FreeFormCertificate\Repositories\SubmissionRepository() )->countAllByForm();
	}

	/**
	 * The word the operator types to confirm a Danger Zone delete (#2338).
	 *
	 * Translated, so an operator types a word of their own language; the
	 * dialog shows it and the server compares against the same string.
	 *
	 * @return string
	 */
	public static function confirm_word(): string {
		return _x( 'DELETE', 'word typed to confirm a Danger Zone deletion', 'ffcertificate' );
	}

	/**
	 * Handle Danger Zone - Global Data Deletion
	 *
	 * @return void
	 */
	private function handle_danger_zone(): void {
		$target        = \FreeFormCertificate\Core\RequestInput::get_post_string( 'delete_target', '' );
		$reset_counter = \FreeFormCertificate\Core\RequestInput::get_post_string( 'reset_counter' ) === '1';

		// The target is chosen, never defaulted, and the word is typed (#2338):
		// a stray submit, or one from a page without the dialog, deletes nothing.
		if ( 'all' !== $target && ( ! ctype_digit( $target ) || absint( $target ) <= 0 ) ) {
			add_settings_error( 'ffc_settings', 'ffc_data_not_deleted', __( 'Choose what to delete before confirming.', 'ffcertificate' ), 'error' );
			return;
		}
		if ( trim( \FreeFormCertificate\Core\RequestInput::get_post_string( 'ffc_delete_confirm' ) ) !== self::confirm_word() ) {
			add_settings_error( 'ffc_settings', 'ffc_data_not_deleted', __( 'Nothing was deleted: the confirmation word did not match.', 'ffcertificate' ), 'error' );
			return;
		}

		/**
		 * Fires before bulk data deletion from the danger zone.
		 *
		 * @since 4.6.4
		 * @param string $target        Deletion target ('all' or form ID).
		 * @param bool   $reset_counter Whether the auto-increment counter is reset.
		 */
		do_action( 'ffcertificate_before_data_deletion', $target, $reset_counter );

		$deleted = $this->submission_handler->delete_all_submissions(
			'all' === $target ? null : absint( $target ),
			$reset_counter
		);

		// Who deleted what (#2338). Best effort: the rows are already gone, so
		// a log that cannot be written must not hide the result.
		try {
			\FreeFormCertificate\Core\ActivityLog::log(
				'danger_zone_data_deleted',
				\FreeFormCertificate\Core\ActivityLog::LEVEL_WARNING,
				array(
					'target'        => 'all' === $target ? 'all' : absint( $target ),
					'deleted'       => $deleted,
					'reset_counter' => $reset_counter,
				),
				get_current_user_id()
			);
		} catch ( \Throwable $e ) {
			unset( $e );
		}

		$message = $reset_counter
			? __( 'Data deleted and counter reset successfully.', 'ffcertificate' )
			: __( 'Data deleted successfully.', 'ffcertificate' );
		add_settings_error( 'ffc_settings', 'ffc_data_deleted', $message, 'updated' );
	}
}
