<?php
/**
 * Template: Date Messages — daily send time.
 *
 * Included from page.php; see it for the variables in scope.
 *
 * @var bool      $can_manage Whether the user may change anything.
 * @var string    $send_time  Daily send time, HH:MM.
 * @var int|false $next_run   Next daily run.
 *
 * @package FreeFormCertificate\DateMessages
 * @since   6.33.0
 */

use FreeFormCertificate\Core\DateFormatter;
use FreeFormCertificate\DateMessages\DateMessagesAdminPage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<?php wp_nonce_field( DateMessagesAdminPage::SETTINGS_ACTION ); ?>
	<input type="hidden" name="action" value="<?php echo esc_attr( DateMessagesAdminPage::SETTINGS_ACTION ); ?>">
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="ffc-dm-send-time"><?php esc_html_e( 'Daily send time', 'ffcertificate' ); ?></label></th>
			<td>
				<input type="time" id="ffc-dm-send-time" name="send_time" required value="<?php echo esc_attr( $send_time ); ?>" <?php disabled( ! $can_manage ); ?>>
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
							? __( 'The daily run is not scheduled. Saving this form schedules it.', 'ffcertificate' )
							: sprintf(
								/* translators: %s: date and time */
								__( 'Next daily run: %s.', 'ffcertificate' ),
								DateFormatter::format_datetime( $next_run )
							)
					);
					?>
				</p>
				<p class="description">
					<?php
					printf(
						/* translators: %s: link to the Scheduled Tasks screen */
						esc_html__( 'WordPress only runs scheduled tasks when the site is visited, unless the server runs them; %s shows the line to add to the server.', 'ffcertificate' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=ffc-settings&tab=scheduled_tasks' ) ) . '">' . esc_html__( 'Scheduled Tasks', 'ffcertificate' ) . '</a>'
					);
					?>
				</p>
			</td>
		</tr>
	</table>
	<?php if ( $can_manage ) : ?>
		<?php submit_button( __( 'Save', 'ffcertificate' ) ); ?>
	<?php endif; ?>
</form>
