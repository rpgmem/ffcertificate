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
	 * Personalisation reality (verified): only a few emails expose an editable
	 * body — the rest ship fixed default bodies with (at most) an on/off toggle.
	 * The `type` column tells them apart: `editable` / `toggle` / `system`.
	 *
	 * @return array<int, array{cap:string, title:string, url:string, rows:array<int, array{label:string, purpose:string, type:string}>}>
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
						'purpose' => __( 'Sent to the person after they submit a form (per form).', 'ffcertificate' ),
						'type'    => 'editable',
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
						'purpose' => __( 'Confirms a booking to the user (editable body/subject per calendar).', 'ffcertificate' ),
						'type'    => 'editable',
					),
					array(
						'label'   => __( 'Approval / cancellation / reminder / admin notice', 'ffcertificate' ),
						'purpose' => __( 'Booking lifecycle emails — each on/off per calendar; fixed body.', 'ffcertificate' ),
						'type'    => 'toggle',
					),
					array(
						'label'   => __( 'Waitlist & promotion notices', 'ffcertificate' ),
						'purpose' => __( 'Sent automatically when a full slot queues or frees up; fixed body.', 'ffcertificate' ),
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
						'purpose' => __( 'Campaign lifecycle emails — each on/off per campaign; fixed body.', 'ffcertificate' ),
						'type'    => 'toggle',
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
						'purpose' => __( 'Sent when a candidate is called (editable body/subject).', 'ffcertificate' ),
						'type'    => 'editable',
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
						'purpose' => __( 'Audience booking lifecycle — each on/off per schedule; fixed body.', 'ffcertificate' ),
						'type'    => 'toggle',
					),
				),
			),
		);
	}

	/**
	 * Label for a personalisation type.
	 *
	 * @param string $type One of editable / toggle / system.
	 * @return string
	 */
	private static function email_type_label( string $type ): string {
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
			<?php esc_html_e( 'Every email the plugin can send, and where each one is configured. They all share the Email Model above; a few have editable text, the rest ship a fixed default body you can only turn on or off.', 'ffcertificate' ); ?>
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
							<td><a href="<?php echo esc_url( $group['url'] ); ?>"><?php echo esc_html( $open_label ); ?></a></td>
						</tr>
					<?php endforeach; ?>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render.
	 */
	public function render(): void {
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
