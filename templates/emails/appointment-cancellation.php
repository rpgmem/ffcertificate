<?php
/**
 * Appointment cancellation email body (miolo).
 *
 * Wrapped by layout.php. Rendered by AppointmentEmailHandler.
 *
 * @var array<string, mixed> $args {
 *     @type string $calendar_title      Calendar title.
 *     @type string $date_formatted      Formatted appointment date.
 *     @type string $time_formatted      Formatted appointment time.
 *     @type string $cancellation_reason Cancellation reason (may be empty).
 * }
 * @package FreeFormCertificate\SelfScheduling
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div style="background: white; border-radius: 8px; padding: 30px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
	<h2 style="margin: 0 0 20px 0; color: #dc3545; font-size: 24px;">❌ <?php echo esc_html__( 'Appointment Cancelled', 'ffcertificate' ); ?></h2>
	<p style="margin: 0 0 15px 0; font-size: 16px;"><?php echo esc_html__( 'Your appointment has been cancelled.', 'ffcertificate' ); ?></p>
	<div style="background: #f8d7da; padding: 20px; border-radius: 8px; margin: 20px 0; border: 1px solid #f5c6cb;">
		<p style="margin: 0 0 10px 0;"><strong><?php echo esc_html__( 'Calendar:', 'ffcertificate' ); ?></strong> <?php echo esc_html( $args['calendar_title'] ); ?></p>
		<p style="margin: 0 0 10px 0;"><strong><?php echo esc_html__( 'Date:', 'ffcertificate' ); ?></strong> <?php echo esc_html( $args['date_formatted'] ); ?></p>
		<p style="margin: 0;"><strong><?php echo esc_html__( 'Time:', 'ffcertificate' ); ?></strong> <?php echo esc_html( $args['time_formatted'] ); ?></p>
	</div>
	<?php if ( ! empty( $args['cancellation_reason'] ) ) : ?>
	<div style="margin: 20px 0;">
		<p style="margin: 0 0 5px 0; font-weight: bold; color: #666;"><?php echo esc_html__( 'Cancellation Reason:', 'ffcertificate' ); ?></p>
		<p style="margin: 0; color: #333;"><?php echo esc_html( $args['cancellation_reason'] ); ?></p>
	</div>
	<?php endif; ?>
</div>
