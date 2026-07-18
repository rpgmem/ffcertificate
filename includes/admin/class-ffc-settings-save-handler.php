<?php
/**
 * Settings Save Handler
 *
 * Handles saving and validation of all settings types.
 * Extracted from FFC_Settings (v3.1.1) following Single Responsibility Principle.
 *
 * @since   3.1.1
 * @package FreeFormCertificate\Admin
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

use FreeFormCertificate\Submissions\SubmissionHandler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates, sanitizes, and persists plugin settings.
 *
 * @since 3.1.1
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

		// Handle Global Data Deletion (Danger Zone).
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- isset() existence check only; nonce verified via check_admin_referer.
		if ( isset( $_POST['ffc_delete_all_data'] ) && check_admin_referer( 'ffc_delete_all_data', 'ffc_critical_nonce' ) ) {
			$this->handle_danger_zone();
		}
	}

	/**
	 * Save general and tab-specific settings (General, SMTP, QR Code, Date Format)
	 *
	 * @return void
	 */
	private function save_general_and_specific_settings(): void {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_all_submissions() via wp_verify_nonce.
		$current = get_option( 'ffc_settings', array() );
		$new     = \FreeFormCertificate\Core\RequestInput::get_post_array( 'ffc_settings' );

		$clean = $current;

		// Process each settings type.
		$clean = $this->save_general_settings( $clean, $new );
		$clean = $this->save_smtp_settings( $clean, $new );
		$clean = $this->save_qrcode_settings( $clean, $new );
		$clean = $this->save_date_format_settings( $clean, $new );
		$clean = $this->save_url_shortener_settings( $clean, $new );

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

		update_option( 'ffc_settings', $clean );

		/**
		 * Fires after plugin settings are saved.
		 *
		 * @since 4.6.4
		 * @param array $clean Saved settings.
		 */
		do_action( 'ffcertificate_settings_saved', $clean );

        // phpcs:enable WordPress.Security.NonceVerification.Missing
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
		// Dark Mode (v4.6.16).
		if ( isset( $new['dark_mode'] ) ) {
			$allowed_modes      = array( 'off', 'on', 'auto' );
			$clean['dark_mode'] = in_array( $new['dark_mode'], $allowed_modes, true ) ? $new['dark_mode'] : 'off';
		}

		// Cleanup Days.
		if ( isset( $new['cleanup_days'] ) ) {
			$clean['cleanup_days'] = absint( $new['cleanup_days'] );
		}

		// Main Address.
		if ( isset( $new['main_address'] ) ) {
			$clean['main_address'] = sanitize_text_field( $new['main_address'] );
		}

		// CSV Download Page URL.
		if ( isset( $new['csv_download_page_url'] ) ) {
			$clean['csv_download_page_url'] = esc_url_raw( $new['csv_download_page_url'] );
		}

		// v4.6.16: Activity Log + Debug moved to Advanced tab.
		$ffc_tab = isset( $_POST['_ffc_tab'] ) ? sanitize_key( wp_unslash( $_POST['_ffc_tab'] ) ) : '';

		if ( 'advanced' === $ffc_tab ) {
			$clean['enable_activity_log'] = isset( $new['enable_activity_log'] ) ? 1 : 0;

			if ( isset( $new['activity_log_retention_days'] ) ) {
				$clean['activity_log_retention_days'] = min( 365, absint( $new['activity_log_retention_days'] ) );
			}

			// Activity-log granular filter: minimum level + per-category enables.
			if ( isset( $new['activity_log_min_level'] ) ) {
				$lvl                             = sanitize_key( (string) $new['activity_log_min_level'] );
				$clean['activity_log_min_level'] = in_array( $lvl, array( 'debug', 'info', 'warning', 'error' ), true ) ? $lvl : 'debug';
			}
			foreach ( \FreeFormCertificate\Core\ActivityLog::CATEGORIES as $ffc_cat ) {
				$clean[ 'activity_log_cat_' . $ffc_cat ] = empty( $new[ 'activity_log_cat_' . $ffc_cat ] ) ? 0 : 1;
			}

			if ( isset( $new['public_csv_sync_max_rows'] ) ) {
				$value = absint( $new['public_csv_sync_max_rows'] );
				if ( $value < \FreeFormCertificate\Frontend\PublicCsvExporter::SYNC_MAX_ROWS_MIN ) {
					$value = \FreeFormCertificate\Frontend\PublicCsvExporter::SYNC_MAX_ROWS_MIN;
				}
				if ( $value > \FreeFormCertificate\Frontend\PublicCsvExporter::SYNC_MAX_ROWS_MAX ) {
					$value = \FreeFormCertificate\Frontend\PublicCsvExporter::SYNC_MAX_ROWS_MAX;
				}
				$clean['public_csv_sync_max_rows'] = $value;
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
			if ( isset( $new['public_csv_default_limit'] ) ) {
				$clean['public_csv_default_limit'] = max( 1, absint( $new['public_csv_default_limit'] ) );
			}

			// Code Editor theme (Certificate HTML editor via CodeMirror).
			if ( isset( $new['code_editor_theme'] ) ) {
				$allowed_themes             = array( 'auto', 'light', 'dark' );
				$clean['code_editor_theme'] = in_array( $new['code_editor_theme'], $allowed_themes, true )
					? $new['code_editor_theme']
					: 'dark';
			}
		}

		// v4.6.16: Cache settings moved to Cache tab.
		if ( 'cache' === $ffc_tab ) {
			$clean['cache_enabled'] = isset( $new['cache_enabled'] ) ? 1 : 0;

			if ( isset( $new['cache_expiration'] ) ) {
				$clean['cache_expiration'] = absint( $new['cache_expiration'] );
			}

			$clean['cache_auto_warm'] = isset( $new['cache_auto_warm'] ) ? 1 : 0;
		}
        // phpcs:enable WordPress.Security.NonceVerification.Missing

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
		// Email Status checkbox (only when on SMTP tab to prevent unchecking from other tabs).
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_all_submissions() via wp_verify_nonce.
		if ( isset( $_POST['_ffc_tab'] ) && sanitize_key( wp_unslash( $_POST['_ffc_tab'] ) ) === 'smtp' ) {
			// The SMTP view emits a hidden `ffc_settings[disable_all_emails]`
			// mirror alongside the inverted "Enable email sending" toggle,
			// so the field is ALWAYS present in $new — we check the value,
			// not its existence: '0' means emails enabled, '1' means disabled.
			$clean['disable_all_emails'] = ! empty( $new['disable_all_emails'] ) ? 1 : 0;
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

		if ( isset( $new['smtp_mode'] ) ) {
			$clean['smtp_mode'] = sanitize_key( $new['smtp_mode'] );
		}

		if ( isset( $new['smtp_host'] ) ) {
			$clean['smtp_host'] = sanitize_text_field( $new['smtp_host'] );
		}

		if ( isset( $new['smtp_port'] ) ) {
			$clean['smtp_port'] = absint( $new['smtp_port'] );
		}

		if ( isset( $new['smtp_user'] ) ) {
			$clean['smtp_user'] = sanitize_text_field( $new['smtp_user'] );
		}

		if ( isset( $new['smtp_pass'] ) ) {
			$clean['smtp_pass'] = sanitize_text_field( $new['smtp_pass'] );
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
		// QR Cache checkbox - v4.6.16: now on Cache tab (was qr_code tab).
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_all_submissions() via wp_verify_nonce.
		if ( isset( $_POST['_ffc_tab'] ) && sanitize_key( wp_unslash( $_POST['_ffc_tab'] ) ) === 'cache' ) {
			$clean['qr_cache_enabled'] = isset( $new['qr_cache_enabled'] ) ? 1 : 0;
		}

		if ( isset( $new['qr_default_size'] ) ) {
			$clean['qr_default_size'] = absint( $new['qr_default_size'] );
		}

		if ( isset( $new['qr_default_margin'] ) ) {
			$clean['qr_default_margin'] = absint( $new['qr_default_margin'] );
		}

		if ( isset( $new['qr_default_error_level'] ) ) {
			$clean['qr_default_error_level'] = sanitize_text_field( $new['qr_default_error_level'] );
		}

		return $clean;
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
	 * Save URL Shortener settings (v5.1.0)
	 *
	 * @param array<string, mixed> $clean Current settings.
	 * @param array<string, mixed> $new New settings from POST.
	 * @return array<string, mixed> Updated settings
	 */
	private function save_url_shortener_settings( array $clean, array $new ): array {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_all_submissions().
		$ffc_tab = isset( $_POST['_ffc_tab'] ) ? sanitize_key( wp_unslash( $_POST['_ffc_tab'] ) ) : '';

		// Checkbox fields (unchecked = absent from POST) — only process on URL Shortener tab.
		if ( 'url_shortener' === $ffc_tab ) {
			$clean['url_shortener_enabled']     = isset( $new['url_shortener_enabled'] ) ? 1 : 0;
			$clean['url_shortener_auto_create'] = isset( $new['url_shortener_auto_create'] ) ? 1 : 0;
		}

		if ( isset( $new['url_shortener_prefix'] ) ) {
			$old_prefix                    = $clean['url_shortener_prefix'] ?? 'go';
			$clean['url_shortener_prefix'] = sanitize_title( $new['url_shortener_prefix'] );
			// Flush rewrite rules when prefix changes.
			if ( $clean['url_shortener_prefix'] !== $old_prefix ) {
				delete_option( 'ffc_url_shortener_rewrite_version' );
				add_action( 'shutdown', 'flush_rewrite_rules' );
			}
		}

		if ( isset( $new['url_shortener_code_length'] ) ) {
			$clean['url_shortener_code_length'] = max( 4, min( 10, (int) $new['url_shortener_code_length'] ) );
		}

		if ( isset( $new['url_shortener_redirect_type'] ) ) {
			$type                                 = (int) $new['url_shortener_redirect_type'];
			$clean['url_shortener_redirect_type'] = in_array( $type, array( 301, 302, 307 ), true ) ? $type : 302;
		}

		// Post types is an array of checkboxes - only process on URL Shortener tab.
		if ( 'url_shortener' === $ffc_tab ) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
			if ( isset( $_POST['ffc_settings']['url_shortener_post_types'] ) && is_array( $_POST['ffc_settings']['url_shortener_post_types'] ) ) {
				$clean['url_shortener_post_types'] = array_map( 'sanitize_key', wp_unslash( $_POST['ffc_settings']['url_shortener_post_types'] ) );
			} else {
				$clean['url_shortener_post_types'] = array();
			}
		}

        // phpcs:enable WordPress.Security.NonceVerification.Missing
		return $clean;
	}

	/**
	 * Save User Access settings (v3.1.0)
	 *
	 * @return void
	 */
	private function save_user_access_settings(): void {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_all_submissions() via wp_verify_nonce.
        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- isset()/empty()/is_array() are existence and type checks; values are sanitized with wp_unslash + sanitize_text_field/esc_url_raw/sanitize_textarea_field.
		$settings = array(
			'block_wp_admin'    => isset( $_POST['block_wp_admin'] ),
			'blocked_roles'     => \FreeFormCertificate\Core\RequestInput::get_post_array( 'blocked_roles', array( 'ffc_user' ) ),
			'redirect_url'      => ! empty( $_POST['redirect_url'] )
				? esc_url_raw( wp_unslash( $_POST['redirect_url'] ) )
				: home_url( '/dashboard' ),
			'redirect_message'  => isset( $_POST['redirect_message'] )
				? sanitize_textarea_field( wp_unslash( $_POST['redirect_message'] ) )
				: '',
			'allow_admin_bar'   => isset( $_POST['allow_admin_bar'] ),
			'bypass_for_admins' => isset( $_POST['bypass_for_admins'] ),
		);
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash

		update_option( 'ffc_user_access_settings', $settings );
		add_settings_error(
			'ffc_user_access_settings',
			'ffc_user_access_updated',
			__( 'User Access settings saved successfully.', 'ffcertificate' ),
			'updated'
		);
	}

	/**
	 * Handle Danger Zone - Global Data Deletion
	 *
	 * @return void
	 */
	private function handle_danger_zone(): void {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_all_submissions() via check_admin_referer.
		$target        = \FreeFormCertificate\Core\RequestInput::get_post_string( 'delete_target', 'all' );
		$reset_counter = \FreeFormCertificate\Core\RequestInput::get_post_string( 'reset_counter' ) === '1';
        // phpcs:enable WordPress.Security.NonceVerification.Missing

		/**
		 * Fires before bulk data deletion from the danger zone.
		 *
		 * @since 4.6.4
		 * @param string $target        Deletion target ('all' or form ID).
		 * @param bool   $reset_counter Whether the auto-increment counter is reset.
		 */
		do_action( 'ffcertificate_before_data_deletion', $target, $reset_counter );

		$this->submission_handler->delete_all_submissions(
			'all' === $target ? null : absint( $target ),
			$reset_counter
		);

		$message = $reset_counter
			? __( 'Data deleted and counter reset successfully.', 'ffcertificate' )
			: __( 'Data deleted successfully.', 'ffcertificate' );
		add_settings_error( 'ffc_settings', 'ffc_data_deleted', $message, 'updated' );
	}
}
