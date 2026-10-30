<?php
/**
 * Template: confirm closing a day that holds audience bookings (#2243).
 *
 * In-scope: $pending — the closure AudienceClosedDay::hold() kept: its
 * `date`, `description`, the `bookings` it cancels and the form `fields`
 * to post again with the confirmation.
 *
 * @package FreeFormCertificate\Audience
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ffc_closed_count = count( $pending['bookings'] );
?>
<div class="notice notice-warning inline ffc-closed-day-confirm">
	<p>
		<strong>
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: number of bookings, 2: the date being closed */
					_n(
						'%1$d booking will be cancelled and its participants e-mailed if %2$s is closed:',
						'%1$d bookings will be cancelled and their participants e-mailed if %2$s is closed:',
						$ffc_closed_count,
						'ffcertificate'
					),
					$ffc_closed_count,
					\FreeFormCertificate\Core\DateFormatter::format_wallclock_date( $pending['date'] )
				)
			);
			?>
		</strong>
	</p>
	<table class="widefat striped">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Time', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Environment', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Description', 'ffcertificate' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $pending['bookings'] as $ffc_closed_booking ) : ?>
				<?php $ffc_closed_times = \FreeFormCertificate\Audience\AudienceBookingRules::stored_day_times( $ffc_closed_booking, $pending['date'] ); ?>
				<tr>
					<td><?php echo esc_html( $ffc_closed_times['start_time'] . '–' . $ffc_closed_times['end_time'] ); ?></td>
					<td><?php echo esc_html( (string) ( $ffc_closed_booking->environment_name ?? '' ) ); ?></td>
					<td><?php echo esc_html( (string) ( $ffc_closed_booking->description ?? '' ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<form method="post" class="ffc-closed-day-confirm-form">
		<?php foreach ( $pending['fields'] as $ffc_closed_name => $ffc_closed_value ) : ?>
			<input type="hidden" name="<?php echo esc_attr( $ffc_closed_name ); ?>" value="<?php echo esc_attr( $ffc_closed_value ); ?>">
		<?php endforeach; ?>
		<input type="hidden" name="<?php echo esc_attr( \FreeFormCertificate\Audience\AudienceClosedDay::CONFIRM_FIELD ); ?>" value="1">
		<p>
			<?php
			\FreeFormCertificate\Admin\AdminUI::render_button(
				array(
					'label'    => __( 'Close the day and cancel the bookings', 'ffcertificate' ),
					'category' => 'danger',
					'icon'     => 'lock',
					'type'     => 'submit',
				)
			);
			?>
		</p>
	</form>
</div>
