<?php
/**
 * Template: Date Messages — history of runs.
 *
 * Included from page.php; see it for the variables in scope.
 *
 * @var array<int, array<string, mixed>> $history    A page of runs.
 * @var int                              $total      Number of runs.
 * @var int                              $paged      Current page.
 * @var array<int, string>               $rule_names Rule id => name.
 * @var int|false                        $next_run   Next daily run.
 * @var bool                             $show_tests Whether test sends are listed.
 *
 * @package FreeFormCertificate\DateMessages
 * @since   6.33.0
 */

use FreeFormCertificate\Core\DateFormatter;
use FreeFormCertificate\DateMessages\DateMessagesAdminPage;
use FreeFormCertificate\DateMessages\DeliveryLog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ffc_dm_int = static fn( $v ): int => is_numeric( $v ) ? (int) $v : 0;
?>
<p class="description"><?php esc_html_e( '"Sent" counts messages handed to wp_mail(); with a mail queue active, delivery happens afterwards from the queue.', 'ffcertificate' ); ?></p>
<p class="description">
	<?php
	echo esc_html(
		sprintf(
			/* translators: %d: number of days */
			__( 'The history keeps the last %d days. Older runs and their deliveries are removed automatically by the daily run.', 'ffcertificate' ),
			DeliveryLog::RETENTION_DAYS
		)
	);
	?>
</p>

<?php
// The daily runs listed here come from the scheduled task, whose time lives on
// Settings → Scheduled Tasks; a missing run is answered there, so the way is
// offered from where it is noticed. The link is drawn only for who can open it.
?>
<p class="ffc-date-messages-schedule">
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
	<?php if ( \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_view_settings' ) ) : ?>
		<a class="button ffc-icon-clock" href="<?php echo esc_url( admin_url( 'admin.php?page=ffc-settings&tab=scheduled_tasks' ) ); ?>"><?php esc_html_e( 'Open Scheduled Tasks', 'ffcertificate' ); ?></a>
	<?php endif; ?>
</p>

<p class="ffc-date-messages-history-filter">
	<?php if ( $show_tests ) : ?>
		<a href="<?php echo esc_url( add_query_arg( 'tests', 'hide', remove_query_arg( 'paged' ) ) ); ?>"><?php esc_html_e( 'Hide test sends', 'ffcertificate' ); ?></a>
	<?php else : ?>
		<a href="<?php echo esc_url( remove_query_arg( array( 'tests', 'paged' ) ) ); ?>"><?php esc_html_e( 'Show test sends', 'ffcertificate' ); ?></a>
	<?php endif; ?>
</p>

<?php if ( array() === $history ) : ?>
	<p><?php esc_html_e( 'Nothing has been sent yet.', 'ffcertificate' ); ?></p>
<?php else : ?>
	<table class="widefat striped">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Started', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Rule', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Trigger', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Dates', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Sent', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Opted out', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'No valid e-mail', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Outside the audience', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Failed', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Status', 'ffcertificate' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $history as $ffc_dm_run ) : ?>
				<?php $ffc_dm_rule_id = $ffc_dm_int( $ffc_dm_run['rule_id'] ?? 0 ); ?>
				<tr>
					<td><?php echo esc_html( DateFormatter::format_datetime( $ffc_dm_int( $ffc_dm_run['started_at'] ?? 0 ) ) ); ?></td>
					<?php
					$ffc_dm_trigger = is_string( $ffc_dm_run['trigger_kind'] ?? null ) ? $ffc_dm_run['trigger_kind'] : '';
					$ffc_dm_is_test = DeliveryLog::TRIGGER_TEST === $ffc_dm_trigger;
					// A test can come from a form never saved, which has no rule id.
					$ffc_dm_rule_label = 0 === $ffc_dm_rule_id && $ffc_dm_is_test
						? __( '(unsaved rule)', 'ffcertificate' )
						: ( $rule_names[ $ffc_dm_rule_id ] ?? __( '(deleted rule)', 'ffcertificate' ) );
					?>
					<td><?php echo esc_html( $ffc_dm_rule_label ); ?></td>
					<td>
						<?php if ( $ffc_dm_is_test ) : ?>
							<span class="ffc-badge ffc-badge-info"><?php esc_html_e( 'Test', 'ffcertificate' ); ?></span>
							<?php
							$ffc_dm_operator = get_userdata( $ffc_dm_int( $ffc_dm_run['created_by'] ?? 0 ) );
							if ( false !== $ffc_dm_operator ) :
								?>
								<br><span class="description">
									<?php
									/* translators: %s: name of the user who sent the test */
									echo esc_html( sprintf( __( 'by %s', 'ffcertificate' ), $ffc_dm_operator->display_name ) );
									?>
								</span>
							<?php endif; ?>
						<?php else : ?>
							<?php echo esc_html( 'manual' === $ffc_dm_trigger ? __( 'Manual', 'ffcertificate' ) : __( 'Daily', 'ffcertificate' ) ); ?>
						<?php endif; ?>
					</td>
					<td>
						<?php
						$ffc_dm_from = is_string( $ffc_dm_run['target_from'] ?? null ) ? $ffc_dm_run['target_from'] : '';
						$ffc_dm_to   = is_string( $ffc_dm_run['target_to'] ?? null ) ? $ffc_dm_run['target_to'] : '';
						echo esc_html( DateFormatter::format_wallclock_date( $ffc_dm_from ) . ( $ffc_dm_to !== $ffc_dm_from ? ' – ' . DateFormatter::format_wallclock_date( $ffc_dm_to ) : '' ) );
						?>
					</td>
					<?php foreach ( array( 'sent', 'opted_out', 'no_email', 'out_of_audience', 'failed' ) as $ffc_dm_counter ) : ?>
						<td><?php echo esc_html( number_format_i18n( $ffc_dm_int( $ffc_dm_run[ $ffc_dm_counter ] ?? 0 ) ) ); ?></td>
					<?php endforeach; ?>
					<td><?php echo esc_html( $ffc_dm_int( $ffc_dm_run['finished_at'] ?? 0 ) > 0 ? __( 'Finished', 'ffcertificate' ) : __( 'Running', 'ffcertificate' ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<?php
	$ffc_dm_pages = (int) ceil( $total / DateMessagesAdminPage::HISTORY_PER_PAGE );
	if ( $ffc_dm_pages > 1 ) :
		?>
		<div class="tablenav"><div class="tablenav-pages">
			<?php
			echo wp_kses_post(
				(string) paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'current' => $paged,
						'total'   => $ffc_dm_pages,
					)
				)
			);
			?>
		</div></div>
	<?php endif; ?>
<?php endif; ?>
