<?php
/**
 * SMTP Settings Tab
 *
 * @package FreeFormCertificate\Settings\Tabs
 * @since 2.10.0
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace
 */

declare(strict_types=1);

namespace FreeFormCertificate\Settings\Tabs;

use FreeFormCertificate\Settings\SettingsTab;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tab S M T P settings tab.
 */
class TabSMTP extends SettingsTab {

	/**
	 * Init.
	 */
	protected function init(): void {
		$this->tab_id    = 'smtp';
		$this->tab_group = 'communication';
		$this->tab_title = __( 'SMTP', 'ffcertificate' );
		$this->tab_icon  = 'ffc-icon-email';
		$this->tab_order = 20;

		// Enqueue scripts for this tab.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Enqueue scripts for SMTP settings page
	 *
	 * @param string $hook Hook name.
	 */
	public function enqueue_scripts( string $hook ): void {
		// Only load on the FFC Settings page with this tab active.
		if ( ! $this->should_enqueue_on( $hook ) ) {
			return;
		}

		$s = \FreeFormCertificate\Core\AssetHelper::asset_suffix();
		wp_enqueue_script(
			'ffc-smtp-settings',
			FFC_PLUGIN_URL . "assets/js/ffc-smtp-settings{$s}.js",
			array( 'jquery' ),
			FFC_VERSION,
			true
		);
		// Powers the `.ffc-toggle` switch on `disable_all_emails`.
		$this->enqueue_autosave_infra();

		// "Email Model" box: color pickers, media uploader, live preview.
		wp_enqueue_media();
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_style(
			'ffc-email-model',
			FFC_PLUGIN_URL . "assets/css/ffc-email-model{$s}.css",
			array(),
			FFC_VERSION
		);
		wp_enqueue_script(
			'ffc-email-model',
			FFC_PLUGIN_URL . "assets/js/ffc-email-model{$s}.js",
			array( 'jquery', 'wp-color-picker' ),
			FFC_VERSION,
			true
		);
		wp_localize_script(
			'ffc-email-model',
			'ffcEmailModel',
			array(
				'defaults'       => \FreeFormCertificate\Core\EmailTemplateOptions::defaults(),
				'fontStacks'     => \FreeFormCertificate\Core\EmailTemplateOptions::font_stacks(),
				'tokens'         => \FreeFormCertificate\Core\EmailTemplateOptions::footer_tokens( array( 'recipient' => 'user@example.com' ) ),
				'siteName'       => get_bloginfo( 'name' ),
				'sampleTitle'    => __( 'Sample email', 'ffcertificate' ),
				'sampleBody'     => __( 'This is how your plugin emails will look with the current model.', 'ffcertificate' ),
				'sampleLink'     => __( 'A sample link', 'ffcertificate' ),
				'confirmRestore' => __( 'Restore all Email Model fields to their defaults? Unsaved changes will be lost.', 'ffcertificate' ),
			)
		);

		// Global email-body hub (#964): the shared "Restore Default Text" button
		// for every editor. Only loaded when the user can reach the hub. Each key
		// maps to the shipped FILE default (so restore reloads the shipped wording,
		// not the current global override).
		if ( \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_email_templates' ) ) {
			$ffc_restore_defaults = array();
			foreach ( array_keys( self::email_body_hub_catalog() ) as $ffc_key ) {
				$ffc_restore_defaults[ $ffc_key ] = array(
					'body'    => \FreeFormCertificate\Core\EmailTemplates::body( $ffc_key, 'body' ),
					'confirm' => __( 'Replace this email with its shipped default text? Your changes to it will be lost when you save.', 'ffcertificate' ),
				);
			}
			wp_enqueue_script(
				'ffc-email-restore-default',
				FFC_PLUGIN_URL . "assets/js/ffc-email-restore-default{$s}.js",
				array( 'jquery' ),
				FFC_VERSION,
				true
			);
			wp_localize_script( 'ffc-email-restore-default', 'ffcEmailRestoreDefaults', $ffc_restore_defaults );
		}
	}

	/**
	 * The read-only "All plugin emails" directory (#951, same spirit as the
	 * Document-Templates "Current assignments" panel).
	 *
	 * The email PIPELINE is already centralized (#662): one chrome, one
	 * transport, default bodies in `templates/emails/`. What is scattered is
	 * only WHERE each email is configured — so this is a discoverability index,
	 * not a move of any control. Every row is display-only and deep-links to the
	 * feature's own screen; nothing here edits an email or reads a feature
	 * class, so no new module edge is introduced (only hard-coded URL/cap
	 * strings). Each group is gated by its feature's view cap, and the whole
	 * panel is suppressed when the user can reach none of them.
	 *
	 * Personalisation reality (verified): the token-based emails now have their
	 * text edited **globally** in the Email texts hub above (#964); the rest ship
	 * fixed default bodies with (at most) an on/off toggle. The `type` column
	 * tells them apart: `global` (edit in the hub) / `editable` / `toggle` /
	 * `system`. A row may carry its own `url` (the hub) overriding the group's
	 * feature-screen link.
	 *
	 * @return array<int, array{cap:string, title:string, url:string, rows:array<int, array{label:string, purpose:string, type:string, url?:string}>}>
	 */
	private static function email_index_groups(): array {
		return array(
			array(
				'cap'   => 'ffc_view_settings',
				'title' => __( 'Shared', 'ffcertificate' ),
				'url'   => admin_url( 'admin.php?page=ffc-settings&tab=smtp' ),
				'rows'  => array(
					array(
						'label'   => __( 'Email Model (shared header/footer)', 'ffcertificate' ),
						'purpose' => __( 'The chrome wrapped around every plugin email — colours, logo, footer.', 'ffcertificate' ),
						'type'    => 'editable',
					),
				),
			),
			array(
				'cap'   => 'ffc_view_forms',
				'title' => __( 'Certificates & forms', 'ffcertificate' ),
				'url'   => admin_url( 'edit.php?post_type=ffc_form' ),
				'rows'  => array(
					array(
						'label'   => __( 'Certificate email to the user', 'ffcertificate' ),
						'purpose' => __( 'Sent to the person after they submit a form — global text in the hub above, with an optional per-form custom version.', 'ffcertificate' ),
						'type'    => 'global',
						'url'     => admin_url( 'admin.php?page=ffc-settings&tab=smtp' ),
					),
					array(
						'label'   => __( 'New-submission admin notification', 'ffcertificate' ),
						'purpose' => __( 'Alerts the admin of a new submission — on/off per form; fixed body.', 'ffcertificate' ),
						'type'    => 'toggle',
					),
				),
			),
			array(
				'cap'   => 'ffc_view_calendars',
				'title' => __( 'Self-scheduling', 'ffcertificate' ),
				'url'   => admin_url( 'edit.php?post_type=ffc_self_scheduling' ),
				'rows'  => array(
					array(
						'label'   => __( 'Appointment confirmation', 'ffcertificate' ),
						'purpose' => __( 'Confirms a booking to the user — default text (incl. receipt/cancel buttons) edited globally in the hub above; a calendar may still set its own.', 'ffcertificate' ),
						'type'    => 'global',
						'url'     => admin_url( 'admin.php?page=ffc-settings&tab=smtp' ),
					),
					array(
						'label'   => __( 'Approval, cancellation & reminder', 'ffcertificate' ),
						'purpose' => __( 'Booking lifecycle emails to the user — text edited globally in the hub above.', 'ffcertificate' ),
						'type'    => 'global',
						'url'     => admin_url( 'admin.php?page=ffc-settings&tab=smtp' ),
					),
					array(
						'label'   => __( 'Waitlist & promotion notices', 'ffcertificate' ),
						'purpose' => __( 'Sent automatically when a full slot queues or frees up — text edited globally in the hub above.', 'ffcertificate' ),
						'type'    => 'global',
						'url'     => admin_url( 'admin.php?page=ffc-settings&tab=smtp' ),
					),
					array(
						'label'   => __( 'Calendar-deletion cancellation', 'ffcertificate' ),
						'purpose' => __( 'Notifies booked users when an admin deletes a calendar — text edited globally in the hub above.', 'ffcertificate' ),
						'type'    => 'global',
						'url'     => admin_url( 'admin.php?page=ffc-settings&tab=smtp' ),
					),
					array(
						'label'   => __( 'New-appointment admin notification', 'ffcertificate' ),
						'purpose' => __( 'Alerts the admin of a new booking; fixed body.', 'ffcertificate' ),
						'type'    => 'system',
					),
				),
			),
			array(
				'cap'   => 'ffc_view_reregistration',
				'title' => __( 'Reregistration', 'ffcertificate' ),
				'url'   => admin_url( 'admin.php?page=ffc-reregistration' ),
				'rows'  => array(
					array(
						'label'   => __( 'Invitation, reminder & confirmation', 'ffcertificate' ),
						'purpose' => __( 'Campaign lifecycle emails — text edited globally in the hub above; each still on/off per campaign.', 'ffcertificate' ),
						'type'    => 'global',
						'url'     => admin_url( 'admin.php?page=ffc-settings&tab=smtp' ),
					),
				),
			),
			array(
				'cap'   => 'ffc_view_recruitment_settings',
				'title' => __( 'Recruitment', 'ffcertificate' ),
				'url'   => admin_url( 'admin.php?page=ffc-recruitment&tab=settings' ),
				'rows'  => array(
					array(
						'label'   => __( 'Convocation email', 'ffcertificate' ),
						'purpose' => __( 'Sent when a candidate is called — subject/body edited globally in the hub above.', 'ffcertificate' ),
						'type'    => 'global',
						'url'     => admin_url( 'admin.php?page=ffc-settings&tab=smtp' ),
					),
				),
			),
			array(
				'cap'   => 'ffc_view_audiences',
				'title' => __( 'Audiences', 'ffcertificate' ),
				'url'   => admin_url( 'admin.php?page=ffc-scheduling-calendars' ),
				'rows'  => array(
					array(
						'label'   => __( 'Booking & cancellation notices', 'ffcertificate' ),
						'purpose' => __( 'Audience booking lifecycle — default text edited globally in the hub above; a schedule may still set its own.', 'ffcertificate' ),
						'type'    => 'global',
						'url'     => admin_url( 'admin.php?page=ffc-settings&tab=smtp' ),
					),
				),
			),
			array(
				'cap'   => 'ffc_view_settings',
				'title' => __( 'Account access', 'ffcertificate' ),
				'url'   => admin_url( 'admin.php?page=ffc-settings&tab=smtp' ),
				'rows'  => array(
					array(
						'label'   => __( 'Access granted to a user', 'ffcertificate' ),
						'purpose' => __( 'Tells a user they were granted plugin access — text edited globally in the hub above.', 'ffcertificate' ),
						'type'    => 'global',
						'url'     => admin_url( 'admin.php?page=ffc-settings&tab=smtp' ),
					),
				),
			),
		);
	}

	/**
	 * Label for a personalisation type.
	 *
	 * @param string $type One of global / editable / toggle / system.
	 * @return string
	 */
	private static function email_type_label( string $type ): string {
		if ( 'global' === $type ) {
			return __( 'Editable text (global)', 'ffcertificate' );
		}
		if ( 'editable' === $type ) {
			return __( 'Editable text', 'ffcertificate' );
		}
		if ( 'toggle' === $type ) {
			return __( 'On/off only', 'ffcertificate' );
		}
		return __( 'System default', 'ffcertificate' );
	}

	/**
	 * Render the read-only "All plugin emails" directory. Called from the SMTP
	 * view after the Email Model box. See {@see self::email_index_groups()}.
	 *
	 * @return void
	 */
	public function render_email_index(): void {
		$groups = array_filter(
			self::email_index_groups(),
			static fn( array $g ): bool => \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( $g['cap'] )
		);

		if ( empty( $groups ) ) {
			return;
		}

		$open_label = __( 'Open →', 'ffcertificate' );
		?>
		<h2 class="ffc-icon-email"><?php esc_html_e( 'All plugin emails', 'ffcertificate' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Every email the plugin can send, and where each one is configured. They all share the Email Model above; the ones marked "Editable text (global)" have their wording edited in the Email texts box above, and the rest ship a fixed default body you can only turn on or off.', 'ffcertificate' ); ?>
		</p>
		<table class="widefat striped ffc-email-index" style="max-width:820px;">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Email', 'ffcertificate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Personalisation', 'ffcertificate' ); ?></th>
					<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Where', 'ffcertificate' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $groups as $group ) : ?>
					<tr>
						<th colspan="3" scope="rowgroup" style="background:transparent;">
							<strong><?php echo esc_html( $group['title'] ); ?></strong>
						</th>
					</tr>
					<?php foreach ( $group['rows'] as $row ) : ?>
						<tr>
							<td>
								<strong><?php echo esc_html( $row['label'] ); ?></strong><br>
								<span class="description"><?php echo esc_html( $row['purpose'] ); ?></span>
							</td>
							<td><?php echo esc_html( self::email_type_label( $row['type'] ) ); ?></td>
							<?php
							// A `global` row links to the Email texts hub above; the
							// rest deep-link to their feature's own screen (#964).
							$ffc_row_url = isset( $row['url'] ) ? $row['url'] : $group['url'];
							?>
							<td><a href="<?php echo esc_url( $ffc_row_url ); ?>"><?php echo esc_html( $open_label ); ?></a></td>
						</tr>
					<?php endforeach; ?>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * The emails the global email-body hub (#964, #965) can edit. Each key is an
	 * allowlisted {@see \FreeFormCertificate\Core\EmailTemplates} template whose
	 * real send default IS its token file. The self-scheduling booking confirmation
	 * plus the five appointment lifecycle emails (approval / cancellation / reminder
	 * / promotion / waitlist) were tokenized in phase 2 (#965) — their conditional
	 * buttons are now pre-rendered `{{…_button}}` tokens, so an admin can move or
	 * drop them from the body. `label` names the email; `tokens` is the per-email
	 * `{{token}}` help list shown under the editor.
	 *
	 * @return array<string, array{label:string, tokens:array<int, string>}>
	 */
	private static function email_body_hub_catalog(): array {
		return array(
			'certificate-user'              => array(
				'label'  => __( 'Certificate email to the user', 'ffcertificate' ),
				'tokens' => array( 'name', 'form_title', 'date', 'auth_code', 'validation_url' ),
			),
			'selfscheduling-confirmation'   => array(
				'label'  => __( 'Appointment booking confirmation', 'ffcertificate' ),
				'tokens' => array( 'user_name', 'user_email', 'calendar_title', 'appointment_date', 'appointment_time', 'status_message', 'status_label', 'user_notes_block', 'receipt_button', 'cancel_button' ),
			),
			'appointment-approval'          => array(
				'label'  => __( 'Appointment approved', 'ffcertificate' ),
				'tokens' => array( 'user_name', 'user_email', 'calendar_title', 'appointment_date', 'appointment_time', 'receipt_button' ),
			),
			'appointment-cancellation'      => array(
				'label'  => __( 'Appointment cancelled', 'ffcertificate' ),
				'tokens' => array( 'user_name', 'user_email', 'calendar_title', 'appointment_date', 'appointment_time', 'cancellation_reason_block' ),
			),
			'appointment-reminder'          => array(
				'label'  => __( 'Appointment reminder', 'ffcertificate' ),
				'tokens' => array( 'user_name', 'user_email', 'calendar_title', 'appointment_date', 'appointment_time', 'cancel_button' ),
			),
			'appointment-promoted'          => array(
				'label'  => __( 'Waitlist spot opened (promotion)', 'ffcertificate' ),
				'tokens' => array( 'user_name', 'user_email', 'calendar_title', 'appointment_date', 'appointment_time', 'status_message', 'receipt_button', 'cancel_button' ),
			),
			'appointment-waitlisted'        => array(
				'label'  => __( 'Added to waitlist', 'ffcertificate' ),
				'tokens' => array( 'user_name', 'user_email', 'calendar_title', 'appointment_date', 'appointment_time', 'waitlist_button' ),
			),
			'recruitment-convocation'       => array(
				'label'  => __( 'Recruitment convocation', 'ffcertificate' ),
				'tokens' => array( 'name', 'cpf_masked', 'rf_masked', 'email_masked', 'adjutancy', 'notice_code', 'notice_name', 'rank', 'score', 'is_pcd', 'date_to_assume', 'time_to_assume', 'called_at', 'site_name', 'site_url', 'notes' ),
			),
			'reregistration-invitation'     => array(
				'label'  => __( 'Reregistration invitation', 'ffcertificate' ),
				'tokens' => array( 'user_name', 'reregistration_title', 'audience_name', 'start_date', 'end_date', 'dashboard_url', 'site_name' ),
			),
			'reregistration-reminder'       => array(
				'label'  => __( 'Reregistration reminder', 'ffcertificate' ),
				'tokens' => array( 'user_name', 'reregistration_title', 'audience_name', 'start_date', 'end_date', 'days_left', 'dashboard_url', 'site_name' ),
			),
			'reregistration-confirmation'   => array(
				'label'  => __( 'Reregistration confirmation', 'ffcertificate' ),
				'tokens' => array( 'user_name', 'reregistration_title', 'audience_name', 'submission_status', 'auth_code', 'magic_link_url', 'dashboard_url', 'site_name' ),
			),
			'audience-booking'              => array(
				'label'  => __( 'Audience booking confirmation', 'ffcertificate' ),
				'tokens' => array( 'user_name', 'user_email', 'environment_name', 'environment_label', 'schedule_name', 'booking_date', 'start_time', 'end_time', 'description', 'audiences', 'creator_name', 'site_name', 'site_url' ),
			),
			'audience-cancellation'         => array(
				'label'  => __( 'Audience booking cancellation', 'ffcertificate' ),
				'tokens' => array( 'user_name', 'user_email', 'environment_name', 'environment_label', 'schedule_name', 'booking_date', 'start_time', 'end_time', 'description', 'audiences', 'cancelled_by_name', 'cancellation_reason', 'site_name', 'site_url' ),
			),
			'access-granted'                => array(
				'label'  => __( 'Access granted to a user', 'ffcertificate' ),
				'tokens' => array( 'user_name', 'context_label', 'site_name', 'dashboard_button' ),
			),
			'calendar-deleted-cancellation' => array(
				'label'  => __( 'Appointment cancelled (calendar deleted)', 'ffcertificate' ),
				'tokens' => array( 'site_name', 'calendar_title', 'appointment_date', 'appointment_time' ),
			),
		);
	}

	/**
	 * Persist the email-body hub form (#964) — its own POST, separate from the
	 * main SMTP settings form. Nonce-checked, then gated on the dedicated
	 * `ffc_manage_email_templates` cap (a view-only user can still POST the nonce
	 * directly, so the capability is enforced here, not only in the hidden UI).
	 *
	 * Each catalogued email is written via
	 * {@see \FreeFormCertificate\Core\EmailTemplates::save_global()}; a subject +
	 * body that both equal the shipped file default clear the override instead
	 * (⇒ the email falls back to its file default and tracks future changes).
	 *
	 * @return bool True when a save was processed (so the caller can flash a notice).
	 */
	private function maybe_save_email_bodies(): bool {
		if ( ! isset( $_POST['ffc_save_email_bodies'] ) ) {
			return false;
		}
		check_admin_referer( 'ffc_email_bodies_nonce' );
		if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_email_templates' ) ) {
			return false;
		}

		$raw = ( isset( $_POST['ffc_email_bodies'] ) && is_array( $_POST['ffc_email_bodies'] ) )
			? wp_unslash( $_POST['ffc_email_bodies'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each field is sanitized individually below (kses/text).
			: array();

		foreach ( array_keys( self::email_body_hub_catalog() ) as $name ) {
			$entry   = ( isset( $raw[ $name ] ) && is_array( $raw[ $name ] ) ) ? $raw[ $name ] : array();
			$subject = isset( $entry['subject'] ) ? sanitize_text_field( (string) $entry['subject'] ) : '';
			$body    = isset( $entry['body'] ) ? wp_kses_post( (string) $entry['body'] ) : '';

			$file_subject = \FreeFormCertificate\Core\EmailTemplates::body( $name, 'subject' );
			$file_body    = \FreeFormCertificate\Core\EmailTemplates::body( $name, 'body' );

			if ( $subject === $file_subject && $body === $file_body ) {
				\FreeFormCertificate\Core\EmailTemplates::clear_global( $name );
			} else {
				\FreeFormCertificate\Core\EmailTemplates::save_global(
					$name,
					array(
						'subject' => $subject,
						'body'    => $body,
					)
				);
			}
		}

		return true;
	}

	/**
	 * Render the global email-body hub (#964) — the seven phase-1 emails, each
	 * with a subject field + `wp_editor` body + "Restore Default Text" + its
	 * `{{token}}` help. Hidden entirely unless the user holds
	 * `ffc_manage_email_templates`. The markup lives in a partial (outside the
	 * coverage scope); this method is the thin cap gate + include.
	 *
	 * @return void
	 */
	public function render_email_body_hub(): void {
		if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_email_templates' ) ) {
			return;
		}
		$ffc_email_hub_catalog = self::email_body_hub_catalog();
		require FFC_PLUGIN_DIR . 'templates/admin/settings/email-body-hub.php';
	}

	/**
	 * Render.
	 */
	public function render(): void {
		if ( $this->maybe_save_email_bodies() ) {
			wp_admin_notice(
				esc_html__( 'Email texts saved.', 'ffcertificate' ),
				array( 'type' => 'success' )
			);
		}

		// Include view file.
		$view_file = FFC_PLUGIN_DIR . 'includes/settings/views/ffc-tab-smtp.php';

		if ( file_exists( $view_file ) ) {
			$settings = $this;
			include $view_file;
		} else {
			wp_admin_notice(
				esc_html__( 'SMTP settings view file not found.', 'ffcertificate' ),
				array( 'type' => 'error' )
			);
		}
	}
}
