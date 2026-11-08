<?php
/**
 * Template: Date Messages — settings: the daily send time and the user
 * dashboard (#2503).
 *
 * Included from page.php; see it for the variables in scope. The time is
 * read-only here: it is chosen on Settings → Scheduled Tasks, like every
 * daily task. The dashboard settings save as they change.
 *
 * @var string    $send_time  Daily send time, HH:MM.
 * @var int|false $next_run   Next daily run.
 * @var bool      $can_manage Whether the user may change anything.
 *
 * @package FreeFormCertificate\DateMessages
 * @since   6.33.0
 */

use FreeFormCertificate\Core\DateFormatter;
use FreeFormCertificate\DateMessages\UserMessages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="card">
<h2 class="ffc-icon-clock"><?php esc_html_e( 'Schedule', 'ffcertificate' ); ?></h2>
<table class="form-table" role="presentation">
	<tr>
		<th scope="row"><?php esc_html_e( 'Daily send time', 'ffcertificate' ); ?></th>
		<td>
			<strong><?php echo esc_html( $send_time ); ?></strong>
			<p class="description">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: timezone name */
						__( 'In the site timezone (%s), set in Settings → General.', 'ffcertificate' ),
						wp_timezone_string()
					)
				);
				?>
			</p>
			<p class="description">
				<?php
				echo esc_html(
					false === $next_run
						? __( 'The daily run is not scheduled.', 'ffcertificate' )
						: sprintf(
							/* translators: %s: date and time */
							__( 'Next daily run: %s.', 'ffcertificate' ),
							DateFormatter::format_datetime( $next_run )
						)
				);
				?>
			</p>
			<?php // The button leads to a screen that needs its own capability, so only who can open it sees it, as on History. ?>
			<?php if ( \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_view_settings' ) ) : ?>
			<p>
				<?php
				\FreeFormCertificate\Admin\AdminUI::render_button(
					array(
						'label' => __( 'Change it in Scheduled Tasks', 'ffcertificate' ),
						'icon'  => 'clock',
						'href'  => admin_url( 'admin.php?page=ffc-settings&tab=scheduled_tasks' ),
					)
				);
				?>
			</p>
			<?php endif; ?>
			<p class="description"><?php esc_html_e( 'Every daily task of the plugin has its time chosen on that one screen, which also shows the server line that keeps scheduled tasks running.', 'ffcertificate' ); ?></p>
		</td>
	</tr>
</table>
</div>

<div class="card">
<h2 class="ffc-icon-user"><?php esc_html_e( 'User dashboard', 'ffcertificate' ); ?></h2>
<p class="description"><?php esc_html_e( 'Each person sees what they were sent in the Messages tab of their dashboard, built from the rule as it is now. Deleting a rule removes what it sent from every dashboard.', 'ffcertificate' ); ?></p>
<table class="form-table" role="presentation">
	<tr>
		<th scope="row"><?php esc_html_e( 'Show announcements at the top for', 'ffcertificate' ); ?></th>
		<td>
			<?php
			$ffc_dm_day_options = array();
			foreach ( UserMessages::NOTICE_DAYS_CHOICES as $ffc_dm_choice ) {
				$ffc_dm_day_options[ (string) $ffc_dm_choice ] = sprintf(
					/* translators: %d: number of days */
					_n( '%d day', '%d days', $ffc_dm_choice, 'ffcertificate' ),
					$ffc_dm_choice
				);
			}
			// An ordered scale of days, so steps (#1745).
			\FreeFormCertificate\Admin\AdminUI::render_steps(
				array(
					'name'     => 'ffc_date_messages_notice_days',
					'id'       => 'ffc-date-messages-notice-days',
					'legend'   => __( 'Show announcements at the top for', 'ffcertificate' ),
					'options'  => $ffc_dm_day_options,
					'value'    => (string) UserMessages::notice_days(),
					'default'  => (string) UserMessages::DEFAULT_NOTICE_DAYS,
					'disabled' => ! $can_manage,
					'data'     => array( 'ffc-autosave-key' => 'date_messages_notice_days' ),
				)
			);
			?>
			<p class="description"><?php esc_html_e( 'Counted from the day the announcement was sent. Until then it opens the person\'s dashboard as a notice and heads their Messages tab; after that it is listed with the earlier messages.', 'ffcertificate' ); ?></p>
		</td>
	</tr>
	<tr>
		<th scope="row"><?php esc_html_e( 'Messages from disabled rules', 'ffcertificate' ); ?></th>
		<td>
			<?php
			\FreeFormCertificate\Admin\AdminUI::render_toggle(
				array(
					'name'     => 'ffc_date_messages_hide_disabled_rules',
					'id'       => 'ffc-date-messages-hide-disabled-rules',
					'label'    => __( 'Hide from the dashboard', 'ffcertificate' ),
					'checked'  => UserMessages::hide_disabled_rules(),
					'disabled' => ! $can_manage,
					'data'     => array( 'ffc-autosave-key' => 'date_messages_hide_disabled_rules' ),
				)
			);
			?>
			<p class="description"><?php esc_html_e( 'On: what a disabled rule already sent leaves the Messages tab and the top of the dashboard, and comes back if the rule is enabled again. Off: it stays listed.', 'ffcertificate' ); ?></p>
		</td>
	</tr>
</table>
</div>
