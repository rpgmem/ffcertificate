<?php
/**
 * Email Texts Settings Tab (#976)
 *
 * The global email-body hub (#964/#965): the per-email subject + body of every
 * token-based plugin email, edited once here, plus the read-only "All plugin
 * emails" directory. Split out of the SMTP tab so transport (SMTP), chrome
 * (Email Model) and per-email texts each get their own focused screen in the
 * Communication group.
 *
 * @package FreeFormCertificate\Settings\Tabs
 * @since   6.21.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Settings\Tabs;

use FreeFormCertificate\Settings\SettingsTab;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Email Texts settings tab.
 */
class TabEmailTexts extends SettingsTab {

	/**
	 * Init.
	 */
	protected function init(): void {
		$this->tab_id    = 'email_texts';
		$this->tab_group = 'communication';
		$this->tab_title = __( 'Email texts', 'ffcertificate' );
		$this->tab_icon  = 'ffc-icon-email';
		$this->tab_order = 22;

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * The hub edits its own option under a dedicated cap, so a delegated
	 * email-copy editor (holding `ffc_manage_email_templates` but not the blanket
	 * `ffc_manage_settings`) is not locked out by the settings read-only fieldset.
	 *
	 * @return string
	 */
	public function get_manage_cap(): string {
		return 'ffc_manage_email_templates';
	}

	/**
	 * Enqueue the shared "Restore Default Text" button infra for the hub editors
	 * — moved here from the SMTP tab in the #976 split. Only loaded when the user
	 * can reach the hub. Each key maps to the shipped FILE default (so restore
	 * reloads the shipped wording, not the current global override).
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_scripts( string $hook ): void {
		if ( ! $this->should_enqueue_on( $hook ) ) {
			return;
		}
		if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_email_templates' ) ) {
			return;
		}

		$s = \FreeFormCertificate\Core\AssetHelper::asset_suffix();

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

		// Per-email selector (#976 B2): the hub renders one plain <textarea> per
		// email but shows only the selected one and initializes TinyMCE on demand
		// (wp.editor.initialize / .remove), so the 15 editors no longer all boot at
		// once. wp_enqueue_editor() loads the TinyMCE + Quicktags assets the runtime
		// initializer needs.
		wp_enqueue_editor();
		wp_enqueue_script(
			'ffc-email-texts',
			FFC_PLUGIN_URL . "assets/js/ffc-email-texts{$s}.js",
			array( 'jquery', 'editor', 'quicktags' ),
			FFC_VERSION,
			true
		);
		wp_localize_script(
			'ffc-email-texts',
			'ffcEmailTexts',
			array(
				'editorSettings' => array(
					'mediaButtons' => false,
					'tinymce'      => array(
						'toolbar1' => 'bold,italic,underline,bullist,numlist,link,unlink,undo,redo',
						'toolbar2' => '',
						'wpautop'  => true,
						'height'   => 220,
					),
					'quicktags'    => array( 'buttons' => 'strong,em,link,ul,ol,li,close' ),
				),
			)
		);
	}

	/**
	 * Render: process the hub save, then the P5 notice, the hub editors, and the
	 * "All plugin emails" directory.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( $this->maybe_save_email_bodies() ) {
			wp_admin_notice(
				esc_html__( 'Email texts saved.', 'ffcertificate' ),
				array( 'type' => 'success' )
			);
		}
		?>
		<div class="ffc-settings-wrap">
		<?php
		\FreeFormCertificate\Core\EmailDisabledNotice::render();
		$this->render_email_body_hub();
		$this->render_email_index();
		?>
		</div>
		<?php
	}

	/**
	 * The emails the global email-body hub (#964, #965) can edit. Each key is an
	 * allowlisted {@see \FreeFormCertificate\Core\EmailTemplates} template whose
	 * real send default IS its token file. `label` names the email; `tokens` is
	 * the per-email `{{token}}` help list shown under the editor.
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
	 * main settings forms. Nonce-checked, then gated on the dedicated
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
	 * Render the global email-body hub (#964). Hidden entirely unless the user
	 * holds `ffc_manage_email_templates`. The markup lives in a partial (outside
	 * the coverage scope); this method is the thin cap gate + include.
	 *
	 * @return void
	 */
	public function render_email_body_hub(): void {
		if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_email_templates' ) ) {
			return;
		}
		$ffc_email_hub_catalog = self::email_body_hub_catalog();
		$ffc_email_hub_groups  = self::email_hub_groups();
		require FFC_PLUGIN_DIR . 'templates/admin/settings/email-body-hub.php';
	}

	/**
	 * Feature grouping for the hub's email selector (#976 B2): an ordered map of
	 * group label => the catalogued template keys in that group. Drives the
	 * `<optgroup>`s of the "select an email" picker and the render order of the
	 * per-email editors (only the selected one is shown, TinyMCE on demand).
	 *
	 * @return array<string, array<int, string>>
	 */
	private static function email_hub_groups(): array {
		return array(
			__( 'Certificates & forms', 'ffcertificate' ) => array( 'certificate-user' ),
			__( 'Self-scheduling', 'ffcertificate' )      => array(
				'selfscheduling-confirmation',
				'appointment-approval',
				'appointment-cancellation',
				'appointment-reminder',
				'appointment-promoted',
				'appointment-waitlisted',
				'calendar-deleted-cancellation',
			),
			__( 'Recruitment', 'ffcertificate' )          => array( 'recruitment-convocation' ),
			__( 'Reregistration', 'ffcertificate' )       => array(
				'reregistration-invitation',
				'reregistration-reminder',
				'reregistration-confirmation',
			),
			__( 'Audiences', 'ffcertificate' )            => array( 'audience-booking', 'audience-cancellation' ),
			__( 'Account access', 'ffcertificate' )       => array( 'access-granted' ),
		);
	}

	/**
	 * The read-only "All plugin emails" directory (#951/#963), rendered below the
	 * hub editors on this tab. A discoverability index — every row is display-only
	 * and deep-links to where the email is configured. Each group is gated by its
	 * feature's view cap; the panel is suppressed when the user can reach none.
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
			<?php esc_html_e( 'Every email the plugin can send, and where each one is configured. They all share the Email Model (its own tab); the ones marked "Editable text (global)" have their wording edited in the box above on this tab, and the rest ship a fixed default body you can only turn on or off.', 'ffcertificate' ); ?>
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
							<?php $ffc_row_url = isset( $row['url'] ) ? $row['url'] : $group['url']; ?>
							<td><a href="<?php echo esc_url( $ffc_row_url ); ?>"><?php echo esc_html( $open_label ); ?></a></td>
						</tr>
					<?php endforeach; ?>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Directory data. `global` rows deep-link to this Email texts hub; the Email
	 * Model row links to its own tab; the rest deep-link to their feature screen.
	 *
	 * @return array<int, array{cap:string, title:string, url:string, rows:array<int, array{label:string, purpose:string, type:string, url?:string}>}>
	 */
	private static function email_index_groups(): array {
		$hub_url   = admin_url( 'admin.php?page=ffc-settings&tab=email_texts' );
		$model_url = admin_url( 'admin.php?page=ffc-settings&tab=email_model' );
		return array(
			array(
				'cap'   => 'ffc_view_settings',
				'title' => __( 'Shared', 'ffcertificate' ),
				'url'   => $model_url,
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
						'url'     => $hub_url,
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
						'url'     => $hub_url,
					),
					array(
						'label'   => __( 'Approval, cancellation & reminder', 'ffcertificate' ),
						'purpose' => __( 'Booking lifecycle emails to the user — text edited globally in the hub above.', 'ffcertificate' ),
						'type'    => 'global',
						'url'     => $hub_url,
					),
					array(
						'label'   => __( 'Waitlist & promotion notices', 'ffcertificate' ),
						'purpose' => __( 'Sent automatically when a full slot queues or frees up — text edited globally in the hub above.', 'ffcertificate' ),
						'type'    => 'global',
						'url'     => $hub_url,
					),
					array(
						'label'   => __( 'Calendar-deletion cancellation', 'ffcertificate' ),
						'purpose' => __( 'Notifies booked users when an admin deletes a calendar — text edited globally in the hub above.', 'ffcertificate' ),
						'type'    => 'global',
						'url'     => $hub_url,
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
						'url'     => $hub_url,
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
						'url'     => $hub_url,
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
						'url'     => $hub_url,
					),
				),
			),
			array(
				'cap'   => 'ffc_view_settings',
				'title' => __( 'Account access', 'ffcertificate' ),
				'url'   => $hub_url,
				'rows'  => array(
					array(
						'label'   => __( 'Access granted to a user', 'ffcertificate' ),
						'purpose' => __( 'Tells a user they were granted plugin access — text edited globally in the hub above.', 'ffcertificate' ),
						'type'    => 'global',
						'url'     => $hub_url,
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
}
