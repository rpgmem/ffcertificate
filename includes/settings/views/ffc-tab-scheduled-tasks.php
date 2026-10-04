<?php
/**
 * Settings Tab: Scheduled Tasks
 *
 * The plugin's WP-Cron tasks: when each is due, when it last actually ran, the
 * time of day each daily task runs at, and the server crontab line that keeps
 * WP-Cron running (#1538). The times are saved by TabScheduledTasks::render().
 *
 * @package FreeFormCertificate\Settings\Views
 * @since 6.33.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ffcertificate_now         = time();
$ffcertificate_rows        = \FreeFormCertificate\Core\ScheduledTasks::report( $ffcertificate_now );
$ffcertificate_singles     = \FreeFormCertificate\Core\ScheduledTasks::pending_singles( (array) _get_cron_array() );
$ffcertificate_cron_off    = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
$ffcertificate_lines       = \FreeFormCertificate\Settings\Tabs\TabScheduledTasks::crontab_lines();
$ffcertificate_default_min = 15;
$ffcertificate_any_late    = false;
$ffcertificate_times       = \FreeFormCertificate\Core\ScheduledTasks::times();
foreach ( $ffcertificate_rows as $ffcertificate_row ) {
	if ( 'late' === $ffcertificate_row['state'] ) {
		$ffcertificate_any_late = true;
	}
}
$ffcertificate_method_labels = array(
	'wp_cli' => __( 'WP-CLI (recommended)', 'ffcertificate' ),
	'wget'   => __( 'HTTP with wget', 'ffcertificate' ),
	'curl'   => __( 'HTTP with curl', 'ffcertificate' ),
);
?>
<div class="ffc-settings-wrap">

	<div class="card">
		<h2 class="ffc-icon-calendar"><?php esc_html_e( 'Scheduled Tasks', 'ffcertificate' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'The background tasks this plugin runs through WP-Cron: when each one is due and when it last actually ran. WordPress only records when a task is due, so "last run" is recorded by the plugin itself and appears after a task first runs.', 'ffcertificate' ); ?>
		</p>

		<?php if ( $ffcertificate_any_late ) : ?>
			<div class="notice notice-warning inline">
				<p><?php esc_html_e( 'At least one task has not run when expected. WP-Cron only runs when the site receives visits; configure the server line below so tasks run on time.', 'ffcertificate' ); ?></p>
			</div>
		<?php endif; ?>

		<form method="post">
		<?php wp_nonce_field( 'ffc_cron_times_nonce' ); ?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Task', 'ffcertificate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Frequency', 'ffcertificate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Time of day', 'ffcertificate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Next run', 'ffcertificate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Last run', 'ffcertificate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'State', 'ffcertificate' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $ffcertificate_rows as $ffcertificate_row ) : ?>
					<tr>
						<td>
							<strong><?php echo esc_html( $ffcertificate_row['label'] ); ?></strong><br>
							<code><?php echo esc_html( $ffcertificate_row['hook'] ); ?></code>
							<?php if ( ! $ffcertificate_row['module_enabled'] ) : ?>
								<br><em><?php esc_html_e( 'Module turned off: the task fires but does nothing.', 'ffcertificate' ); ?></em>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( 'hourly' === $ffcertificate_row['recurrence'] ? __( 'Hourly', 'ffcertificate' ) : __( 'Daily', 'ffcertificate' ) ); ?></td>
						<td>
							<?php if ( 'daily' === $ffcertificate_row['recurrence'] ) : ?>
								<label class="screen-reader-text" for="<?php echo esc_attr( 'ffc-cron-time-' . $ffcertificate_row['hook'] ); ?>"><?php echo esc_html( $ffcertificate_row['label'] ); ?></label>
								<input type="time" id="<?php echo esc_attr( 'ffc-cron-time-' . $ffcertificate_row['hook'] ); ?>" name="<?php echo esc_attr( 'ffc_cron_times[' . $ffcertificate_row['hook'] . ']' ); ?>" value="<?php echo esc_attr( $ffcertificate_times[ $ffcertificate_row['hook'] ] ?? '' ); ?>">
							<?php else : ?>
								—
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( null !== $ffcertificate_row['next_run'] ? \FreeFormCertificate\Core\DateFormatter::format_datetime( $ffcertificate_row['next_run'] ) : '—' ); ?></td>
						<td><?php echo esc_html( null !== $ffcertificate_row['last_run'] ? \FreeFormCertificate\Core\DateFormatter::format_datetime( $ffcertificate_row['last_run'] ) : '—' ); ?></td>
						<td>
							<span class="dashicons <?php echo esc_attr( 'ok' === $ffcertificate_row['state'] ? 'dashicons-yes-alt' : ( 'late' === $ffcertificate_row['state'] || 'not_scheduled' === $ffcertificate_row['state'] ? 'dashicons-warning' : 'dashicons-clock' ) ); ?>" aria-hidden="true"></span>
							<?php echo esc_html( \FreeFormCertificate\Settings\Tabs\TabScheduledTasks::state_label( $ffcertificate_row['state'] ) ); ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: timezone name */
					__( 'Times are in the site timezone (%s). Leave a time empty to keep the task where it is. A task becomes due at its time and runs when WP-Cron next fires, so with the server line below it runs within that line\'s interval.', 'ffcertificate' ),
					wp_timezone_string()
				)
			);
			?>
		</p>
		<p><button type="submit" name="ffc_save_cron_times" value="1" class="button button-primary"><?php esc_html_e( 'Save times', 'ffcertificate' ); ?></button></p>
		</form>

		<h3><?php esc_html_e( 'Queued one-off tasks', 'ffcertificate' ); ?></h3>
		<p class="description"><?php esc_html_e( 'Created per piece of work and removed once they run. A number that keeps growing means WP-Cron is not running.', 'ffcertificate' ); ?></p>
		<ul>
			<?php foreach ( $ffcertificate_singles as $ffcertificate_hook => $ffcertificate_count ) : ?>
				<li>
					<?php echo esc_html( \FreeFormCertificate\Core\ScheduledTasks::label( $ffcertificate_hook ) ); ?>:
					<strong><?php echo esc_html( number_format_i18n( $ffcertificate_count ) ); ?></strong>
				</li>
			<?php endforeach; ?>
		</ul>
	</div>

	<div class="card">
		<h2 class="ffc-icon-settings"><?php esc_html_e( 'Server cron', 'ffcertificate' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'By default WP-Cron runs only when someone visits the site, so on a quiet site a daily task can run hours late. A server cron line calls WP-Cron on a fixed rhythm. The line does not decide when each task runs — WordPress keeps that schedule — it only makes sure due tasks are run promptly, so a short interval is right.', 'ffcertificate' ); ?>
		</p>

		<?php if ( $ffcertificate_cron_off ) : ?>
			<div class="notice notice-success inline">
				<p><?php esc_html_e( 'DISABLE_WP_CRON is set: WP-Cron no longer depends on visits. Make sure the server line below is installed, or no task will run.', 'ffcertificate' ); ?></p>
			</div>
		<?php else : ?>
			<div class="notice notice-info inline">
				<p>
					<?php
					echo wp_kses(
						__( 'Recommended: install the server line below, then add <code>define( \'DISABLE_WP_CRON\', true );</code> to <code>wp-config.php</code> so page visits stop triggering WP-Cron.', 'ffcertificate' ),
						array( 'code' => array() )
					);
					?>
				</p>
			</div>
		<?php endif; ?>

		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><label for="ffc-crontab-method"><?php esc_html_e( 'Method', 'ffcertificate' ); ?></label></th>
					<td>
						<select id="ffc-crontab-method">
							<?php foreach ( \FreeFormCertificate\Core\ScheduledTasks::CRONTAB_METHODS as $ffcertificate_method ) : ?>
								<option value="<?php echo esc_attr( $ffcertificate_method ); ?>"><?php echo esc_html( $ffcertificate_method_labels[ $ffcertificate_method ] ?? $ffcertificate_method ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'WP-CLI runs on the server itself and needs WP-CLI installed. The HTTP methods call the site over the network and work on any host.', 'ffcertificate' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ffc-crontab-minutes"><?php esc_html_e( 'Every', 'ffcertificate' ); ?></label></th>
					<td>
						<select id="ffc-crontab-minutes">
							<?php foreach ( \FreeFormCertificate\Core\ScheduledTasks::CRONTAB_MINUTES as $ffcertificate_minutes ) : ?>
								<option value="<?php echo esc_attr( (string) $ffcertificate_minutes ); ?>" <?php selected( $ffcertificate_default_min, $ffcertificate_minutes ); ?>>
									<?php
									/* translators: %d: number of minutes */
									echo esc_html( sprintf( __( '%d minutes', 'ffcertificate' ), $ffcertificate_minutes ) );
									?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ffc-crontab-line"><?php esc_html_e( 'Line to install', 'ffcertificate' ); ?></label></th>
					<td>
						<input type="text" id="ffc-crontab-line" class="large-text code" readonly value="<?php echo esc_attr( $ffcertificate_lines['wp_cli'][ $ffcertificate_default_min ] ?? '' ); ?>">
						<p>
							<button type="button" class="button ffc-copy-link" data-ffc-copy-target="#ffc-crontab-line"><?php esc_html_e( 'Copy', 'ffcertificate' ); ?></button>
						</p>
						<p class="description"><?php esc_html_e( 'Add it with "crontab -e" as the user that owns the WordPress files, or paste it into your hosting panel\'s cron section.', 'ffcertificate' ); ?></p>
					</td>
				</tr>
			</tbody>
		</table>
	</div>

</div>
