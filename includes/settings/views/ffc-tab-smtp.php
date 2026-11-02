<?php
/**
 * SMTP Settings Tab
 *
 * @package FreeFormCertificate\Settings\Views
 * @version 3.1.0 - Added user creation email controls (submission & migration)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ffcertificate_get_option = \Closure::fromCallable( array( $settings, 'get_option' ) );

// Fresh-install default for smtp_mode — pick WP Default so the radio
// renders pre-selected on a first visit (user expectation from #442
// follow-up).
$ffcertificate_smtp_mode = (string) $ffcertificate_get_option( 'smtp_mode' );
if ( '' === $ffcertificate_smtp_mode ) {
	$ffcertificate_smtp_mode = 'wp';
}

// While Total Mail Queue is active it owns delivery, so the choice is
// locked to WP Default (#1758): the radios render disabled and post
// nothing, which keeps the stored value for when it is deactivated, and
// EmailHandler::configure_custom_smtp() leaves PHPMailer alone meanwhile.
$ffcertificate_mail_queue = \FreeFormCertificate\Integrations\MailQueue::is_active();
if ( $ffcertificate_mail_queue ) {
	$ffcertificate_smtp_mode = 'wp';
}

// The toggle UI now reads "Enable email sending" (positive framing),
// but the historical storage slot is `disable_all_emails`. Compute the
// inverted view value once so the rest of the template stays simple.
$ffcertificate_emails_disabled = '1' === (string) $ffcertificate_get_option( 'disable_all_emails' );
$ffcertificate_emails_enabled  = ! $ffcertificate_emails_disabled;
?>

<div class="ffc-settings-wrap">

<div class="card">
	<h2 class="ffc-icon-send"><?php esc_html_e( 'Email Configuration', 'ffcertificate' ); ?></h2>
	<?php \FreeFormCertificate\Core\EmailDisabledNotice::render(); ?>

	<form method="post">
		<?php wp_nonce_field( 'ffc_settings_action', 'ffc_settings_nonce' ); ?>
		<input type="hidden" name="_ffc_tab" value="smtp">

		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row">
						<label for="emails_enabled"><?php esc_html_e( 'Enable Email Sending', 'ffcertificate' ); ?></label>
					</th>
					<td>
						<?php // Mirror the inverted UI value to the legacy form field so a fallback non-autosave POST still writes the right slot. ?>
						<input type="hidden" name="ffc_settings[disable_all_emails]" value="<?php echo $ffcertificate_emails_disabled ? '1' : '0'; ?>">
						<?php
						\FreeFormCertificate\Admin\AdminUI::render_toggle(
							array(
								'name'    => 'emails_enabled',
								'id'      => 'emails_enabled',
								'checked' => $ffcertificate_emails_enabled,
								'label'   => __( 'Send plugin emails (certificates, notifications, password resets, etc.)', 'ffcertificate' ),
								'data'    => array( 'ffc-autosave-key' => 'emails_enabled' ),
							)
						);
						?>
						<p class="description">
							<?php esc_html_e( 'When OFF, the plugin will NOT send any emails and the configuration below is hidden. Use it for testing or to fully silence outbound mail.', 'ffcertificate' ); ?>
						</p>
					</td>
				</tr>

				<?php
				// Each row used to be a pair of Enabled/Disabled radios
				// emitting '1' or '0'. Replaced with a hidden sibling
				// (value=0) followed by a .ffc-control-toggle whose checked
				// value is '1' — WordPress POST takes the last value
				// with the same name, so unchecked → '0' / checked → '1'.
				// The on-disk shape is unchanged.
				$ffcertificate_email_toggles = array(
					'send_wp_user_email_submission'  => array(
						'th_label'    => __( 'User Creation Emails (Submission)', 'ffcertificate' ),
						'description' => __( 'Send welcome email when a new WordPress user is created via form submission. The email contains a password reset link.', 'ffcertificate' ),
						'default'     => '1',
					),
					'send_wp_user_email_appointment' => array(
						'th_label'    => __( 'User Creation Emails (Appointment)', 'ffcertificate' ),
						'description' => __( 'Send welcome email when a new WordPress user is created via appointment booking. The email contains a password reset link.', 'ffcertificate' ),
						'default'     => '1',
					),
					'send_wp_user_email_csv_import'  => array(
						'th_label'    => __( 'User Creation Emails (CSV Import)', 'ffcertificate' ),
						'description' => __( 'Send welcome email when a new WordPress user is created via CSV import. Recommended to keep disabled to avoid sending bulk emails.', 'ffcertificate' ),
						'default'     => '0',
					),
					'send_wp_user_email_migration'   => array(
						'th_label'    => __( 'User Creation Emails (Migration)', 'ffcertificate' ),
						'description' => __( 'Send welcome email when a new WordPress user is created during migration. Recommended to keep disabled to avoid sending bulk emails.', 'ffcertificate' ),
						'default'     => '0',
					),
					// #1123: the "Access granted" email was composed, editable in
					// the email hub and gated on this key — which nothing wrote,
					// so it never left any install. Default '0' keeps that
					// behaviour; the toggle is what makes it reachable.
					'notify_capability_grant'        => array(
						'th_label'    => __( 'Access Granted Notification', 'ffcertificate' ),
						'description' => __( 'Email the user when they are granted access to certificates, appointments or audience groups. Edit the text under Settings → Email texts → Account access.', 'ffcertificate' ),
						'default'     => '0',
					),
				);
				foreach ( $ffcertificate_email_toggles as $ffcertificate_key => $ffcertificate_row ) :
					$ffcertificate_current = (string) $ffcertificate_get_option( $ffcertificate_key, $ffcertificate_row['default'] );
					?>
					<tr class="ffc-email-option-row">
						<th scope="row">
							<label for="<?php echo esc_attr( $ffcertificate_key ); ?>"><?php echo esc_html( $ffcertificate_row['th_label'] ); ?></label>
						</th>
						<td>
							<input type="hidden" name="ffc_settings[<?php echo esc_attr( $ffcertificate_key ); ?>]" value="0">
							<?php
							\FreeFormCertificate\Admin\AdminUI::render_toggle(
								array(
									'name'    => 'ffc_settings[' . $ffcertificate_key . ']',
									'id'      => $ffcertificate_key,
									'checked' => '1' === $ffcertificate_current,
									'label'   => __( 'Enabled', 'ffcertificate' ),
									'data'    => array( 'ffc-autosave-key' => $ffcertificate_key ),
								)
							);
							?>
							<p class="description">
								<?php echo esc_html( $ffcertificate_row['description'] ); ?>
							</p>
						</td>
					</tr>
				<?php endforeach; ?>

				<tr class="ffc-email-option-row">
					<th scope="row">
						<label><?php esc_html_e( 'Mode', 'ffcertificate' ); ?></label>
					</th>
					<td>
						<?php if ( $ffcertificate_mail_queue ) : ?>
							<?php
							\FreeFormCertificate\Core\AdminNotice::callout(
								'info',
								esc_html__( 'Total Mail Queue is active and manages email delivery, so WP Default is used. The choice saved here is kept and applies again when Total Mail Queue is deactivated.', 'ffcertificate' )
							);
							?>
						<?php endif; ?>
						<fieldset id="smtp-mode-options"<?php disabled( $ffcertificate_mail_queue ); ?>>
							<label>
								<input type="radio" name="ffc_settings[smtp_mode]" value="wp" <?php checked( 'wp', $ffcertificate_smtp_mode ); ?>>
								<strong><?php esc_html_e( 'WP Default (PHPMail)', 'ffcertificate' ); ?></strong>
							</label>
							<p class="description">
								<?php esc_html_e( 'Uses WordPress default mail function. Simple but may have deliverability issues.', 'ffcertificate' ); ?>
							</p>

							<label>
								<input type="radio" name="ffc_settings[smtp_mode]" value="custom" <?php checked( 'custom', $ffcertificate_smtp_mode ); ?>>
								<strong><?php esc_html_e( 'Custom SMTP', 'ffcertificate' ); ?></strong>
							</label>
							<p class="description">
								<?php esc_html_e( 'Use an external SMTP server. Better deliverability and tracking.', 'ffcertificate' ); ?>
							</p>
						</fieldset>
					</td>
				</tr>
			</tbody>
		</table>
		
		<div id="smtp-options" class="ffc-collapsible-section <?php echo esc_attr( ( ! $ffcertificate_emails_disabled && 'custom' === $ffcertificate_smtp_mode ) ? '' : 'ffc-hidden' ); ?>">
			<div class="ffc-collapsible-content active">
				<h3><?php esc_html_e( 'SMTP Server Configuration', 'ffcertificate' ); ?></h3>
				
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="smtp_host"><?php esc_html_e( 'SMTP Host', 'ffcertificate' ); ?></label></th>
							<td>
								<input type="text" name="ffc_settings[smtp_host]" id="smtp_host" value="<?php echo esc_attr( $ffcertificate_get_option( 'smtp_host' ) ); ?>" class="regular-text" placeholder="smtp.gmail.com">
								<p class="description"><?php esc_html_e( 'Your SMTP server address', 'ffcertificate' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="smtp_port"><?php esc_html_e( 'SMTP Port', 'ffcertificate' ); ?></label></th>
							<td>
								<input type="number" name="ffc_settings[smtp_port]" id="smtp_port" value="<?php echo esc_attr( $ffcertificate_get_option( 'smtp_port' ) ); ?>" class="small-text" placeholder="587" min="1" max="65535" required>
								<p class="description"><?php esc_html_e( 'Common ports: 587 (TLS), 465 (SSL), 25 (unencrypted)', 'ffcertificate' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="smtp_user"><?php esc_html_e( 'Username', 'ffcertificate' ); ?></label></th>
							<td>
								<input type="text" name="ffc_settings[smtp_user]" id="smtp_user" value="<?php echo esc_attr( $ffcertificate_get_option( 'smtp_user' ) ); ?>" class="regular-text" autocomplete="username">
								<p class="description"><?php esc_html_e( 'Your SMTP username (usually your email)', 'ffcertificate' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="smtp_pass"><?php esc_html_e( 'Password', 'ffcertificate' ); ?></label></th>
							<td>
								<?php
								// The password never returns to the browser (#2342): the
								// row says whether one is saved, and a new one is typed
								// into an empty field that only opens on request.
								$ffcertificate_pass_state = \FreeFormCertificate\Integrations\SmtpPassword::state(
									array( 'smtp_pass' => (string) $ffcertificate_get_option( 'smtp_pass' ) )
								);
								$ffcertificate_pass_saved = \FreeFormCertificate\Integrations\SmtpPassword::STATE_SAVED === $ffcertificate_pass_state;
								$ffcertificate_pass_edit  = \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_settings_smtp' );
								?>
								<?php if ( $ffcertificate_pass_saved ) : ?>
									<div class="ffc-secret-row" id="ffc-smtp-pass-saved">
										<span class="ffc-pill ffc-secret-saved ffc-icon-lock"><?php esc_html_e( 'Password saved', 'ffcertificate' ); ?></span>
										<?php
										\FreeFormCertificate\Admin\AdminUI::render_button(
											array(
												'label'    => __( 'Change password', 'ffcertificate' ),
												'category' => 'neutral',
												'icon'     => 'key',
												'id'       => 'ffc-smtp-pass-change',
												'disabled' => ! $ffcertificate_pass_edit,
												'attrs'    => $ffcertificate_pass_edit ? array( 'aria-controls' => 'ffc-smtp-pass-edit' ) : array( 'title' => __( 'Only someone who manages SMTP can change the password.', 'ffcertificate' ) ),
											)
										);
										?>
										<?php if ( ! $ffcertificate_pass_edit ) : ?>
											<span class="ffc-icon-info ffc-secret-reason" aria-hidden="true"></span>
										<?php endif; ?>
									</div>
								<?php endif; ?>
								<?php if ( $ffcertificate_pass_edit ) : ?>
									<div class="ffc-secret-row" id="ffc-smtp-pass-edit"<?php echo $ffcertificate_pass_saved ? ' hidden' : ''; ?>>
										<input type="password" name="ffc_settings[smtp_pass]" id="smtp_pass" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo esc_attr( $ffcertificate_pass_saved ? __( 'New password', 'ffcertificate' ) : '' ); ?>">
										<?php
										if ( $ffcertificate_pass_saved ) {
											\FreeFormCertificate\Admin\AdminUI::render_button(
												array(
													'label' => __( 'Cancel', 'ffcertificate' ),
													'category' => 'neutral',
													'icon' => 'cross',
													'id'   => 'ffc-smtp-pass-cancel',
												)
											);
										}
										?>
									</div>
								<?php endif; ?>
								<p class="description" id="ffc-smtp-pass-desc">
									<?php
									if ( ! $ffcertificate_pass_edit ) {
										esc_html_e( 'Only someone who manages SMTP can change the password.', 'ffcertificate' );
									} elseif ( $ffcertificate_pass_saved ) {
										esc_html_e( 'The password is never shown. To replace it, click "Change password", type the new one and save; "Cancel" keeps the saved one.', 'ffcertificate' );
									} elseif ( \FreeFormCertificate\Integrations\SmtpPassword::STATE_UNREADABLE === $ffcertificate_pass_state ) {
										esc_html_e( 'The saved password can no longer be read, because the encryption key changed. Type it again and save.', 'ffcertificate' );
									} else {
										esc_html_e( 'Your SMTP password or app-specific password', 'ffcertificate' );
									}
									?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="smtp_secure"><?php esc_html_e( 'Encryption', 'ffcertificate' ); ?></label></th>
							<td>
								<select name="ffc_settings[smtp_secure]" id="smtp_secure">
									<option value="tls" <?php selected( 'tls', $ffcertificate_get_option( 'smtp_secure' ) ); ?>><?php esc_html_e( 'TLS (recommended)', 'ffcertificate' ); ?></option>
									<option value="ssl" <?php selected( 'ssl', $ffcertificate_get_option( 'smtp_secure' ) ); ?>>SSL</option>
									<option value="none" <?php selected( 'none', $ffcertificate_get_option( 'smtp_secure' ) ); ?>><?php esc_html_e( 'None (not recommended)', 'ffcertificate' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'TLS is recommended for port 587, SSL for port 465', 'ffcertificate' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="smtp_from_email"><?php esc_html_e( 'From Email', 'ffcertificate' ); ?></label></th>
							<td>
								<input type="email" name="ffc_settings[smtp_from_email]" id="smtp_from_email" value="<?php echo esc_attr( $ffcertificate_get_option( 'smtp_from_email' ) ); ?>" class="regular-text">
								<p class="description"><?php esc_html_e( 'Email address to send from', 'ffcertificate' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="smtp_from_name"><?php esc_html_e( 'From Name', 'ffcertificate' ); ?></label></th>
							<td>
								<input type="text" name="ffc_settings[smtp_from_name]" id="smtp_from_name" value="<?php echo esc_attr( $ffcertificate_get_option( 'smtp_from_name' ) ); ?>" class="regular-text">
								<p class="description"><?php esc_html_e( 'Name to display as sender', 'ffcertificate' ); ?></p>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
		</div>
		
		<?php \FreeFormCertificate\Admin\AdminUI::render_save_button(); ?>
	</form>
</div>

<?php // Popular providers are only relevant to Custom SMTP — hidden otherwise (JS keeps it in sync with the Mode radio). ?>
<div class="card<?php echo ( ! $ffcertificate_emails_disabled && 'custom' === $ffcertificate_smtp_mode ) ? '' : ' ffc-hidden'; ?>" id="ffc-smtp-providers">
	<h2 class="ffc-icon-server"><?php esc_html_e( 'Popular SMTP Providers', 'ffcertificate' ); ?></h2>

	<div class="ffc-provider-grid">
		<div class="ffc-provider-card ffc-provider-gmail">
			<h4>Gmail</h4>
			<p><strong>Host:</strong> smtp.gmail.com</p>
			<p><strong>Port:</strong> 587 (TLS)</p>
			<p><strong>Note:</strong> <?php esc_html_e( 'Use app-specific password', 'ffcertificate' ); ?></p>
		</div>
		
		<div class="ffc-provider-card ffc-provider-outlook">
			<h4>Outlook/Office 365</h4>
			<p><strong>Host:</strong> smtp.office365.com</p>
			<p><strong>Port:</strong> 587 (TLS)</p>
			<p><strong>Note:</strong> <?php esc_html_e( 'Full email as username', 'ffcertificate' ); ?></p>
		</div>
		
		<div class="ffc-provider-card ffc-provider-sendgrid">
			<h4>SendGrid</h4>
			<p><strong>Host:</strong> smtp.sendgrid.net</p>
			<p><strong>Port:</strong> 587 (TLS)</p>
			<p><strong>Note:</strong> <?php esc_html_e( 'Use API key as password', 'ffcertificate' ); ?></p>
		</div>

		<div class="ffc-provider-card">
			<h4>Hostinger</h4>
			<p><strong>Host:</strong> smtp.hostinger.com</p>
			<p><strong>Port:</strong> 465 (SSL) 587 (TLS/STARTTLS)</p>
			<p><strong>Note:</strong> <?php esc_html_e( 'Use API key as password, or find your settings in hPanel.', 'ffcertificate' ); ?></p>
			
		</div>
	</div>
</div>

<?php if ( ! $ffcertificate_mail_queue ) : ?>
	<?php
	\FreeFormCertificate\Core\AdminNotice::callout(
		'info',
		'<p>' . esc_html__( 'For queued sending with automatic retries and true multipart (HTML + plain-text) delivery, install the total-mail-queue plugin. Every email this plugin sends already goes through wp_mail(), so it is picked up automatically once activated — no extra configuration.', 'ffcertificate' ) . '</p>'
		. '<p>' . \FreeFormCertificate\Admin\AdminUI::button(
			array(
				'label' => __( 'Learn more about total-mail-queue', 'ffcertificate' ),
				'icon'  => 'external',
				'href'  => 'https://github.com/rpgmem/total-mail-queue',
				'attrs' => array(
					'target' => '_blank',
					'rel'    => 'noopener noreferrer',
				),
			)
		) . '</p>',
		array(
			'icon'  => 'bulb',
			'title' => __( 'Recommended for reliable delivery', 'ffcertificate' ),
			'attrs' => array( 'id' => 'ffc-mailqueue-recommendation' ),
		)
	);
	?>
<?php endif; ?>

</div><!-- .ffc-settings-wrap -->