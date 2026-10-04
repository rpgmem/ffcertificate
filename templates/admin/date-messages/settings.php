<?php
/**
 * Template: Date Messages — daily send time.
 *
 * Included from page.php; see it for the variables in scope. Read-only: the
 * time is chosen on Settings → Scheduled Tasks, like every daily task.
 *
 * @var string    $send_time Daily send time, HH:MM.
 * @var int|false $next_run  Next daily run.
 *
 * @package FreeFormCertificate\DateMessages
 * @since   6.33.0
 */

use FreeFormCertificate\Core\DateFormatter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
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
			<p>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=ffc-settings&tab=scheduled_tasks' ) ); ?>"><?php esc_html_e( 'Change it in Scheduled Tasks', 'ffcertificate' ); ?></a>
			</p>
			<p class="description"><?php esc_html_e( 'Every daily task of the plugin has its time chosen on that one screen, which also shows the server line that keeps scheduled tasks running.', 'ffcertificate' ); ?></p>
		</td>
	</tr>
</table>
