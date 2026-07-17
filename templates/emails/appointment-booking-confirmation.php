<?php
/**
 * Appointment booking-confirmation email body (miolo).
 *
 * Wrapped by layout.php. Rendered by AppointmentEmailHandler.
 *
 * @var array<string, mixed> $args {
 *     @type string $calendar_title Calendar title.
 *     @type string $status_message Confirmed / pending-approval message.
 *     @type string $date_formatted Formatted appointment date.
 *     @type string $time_formatted Formatted appointment time.
 *     @type string $status_label   Human-readable status label.
 *     @type string $user_notes     User-supplied notes (may be empty).
 *     @type string $receipt_url    Receipt URL (empty when unavailable).
 *     @type string $cancel_url     Cancellation URL (empty when not allowed).
 * }
 * @package FreeFormCertificate\SelfScheduling
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div style="background: white; border-radius: 8px; padding: 30px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
	<h2 style="margin: 0 0 20px 0; color: #0073aa; font-size: 24px;">📅 <?php echo esc_html__( 'Appointment Booked!', 'ffcertificate' ); ?></h2>
	<p style="margin: 0 0 15px 0; font-size: 16px;"><?php echo esc_html( $args['status_message'] ); ?></p>
	<div style="background: #f5f5f5; padding: 20px; border-radius: 8px; margin: 20px 0;">
		<p style="margin: 0 0 10px 0;"><strong><?php echo esc_html__( 'Calendar:', 'ffcertificate' ); ?></strong> <?php echo esc_html( $args['calendar_title'] ); ?></p>
		<p style="margin: 0 0 10px 0;"><strong><?php echo esc_html__( 'Date:', 'ffcertificate' ); ?></strong> <?php echo esc_html( $args['date_formatted'] ); ?></p>
		<p style="margin: 0 0 10px 0;"><strong><?php echo esc_html__( 'Time:', 'ffcertificate' ); ?></strong> <?php echo esc_html( $args['time_formatted'] ); ?></p>
		<p style="margin: 0;"><strong><?php echo esc_html__( 'Status:', 'ffcertificate' ); ?></strong> <?php echo esc_html( $args['status_label'] ); ?></p>
	</div>
	<?php if ( ! empty( $args['user_notes'] ) ) : ?>
	<div style="margin: 20px 0;">
		<p style="margin: 0 0 5px 0; font-weight: bold; color: #666;"><?php echo esc_html__( 'Your Notes:', 'ffcertificate' ); ?></p>
		<p style="margin: 0; color: #333;"><?php echo esc_html( $args['user_notes'] ); ?></p>
	</div>
	<?php endif; ?>
	<?php if ( ! empty( $args['receipt_url'] ) ) : ?>
	<div style="text-align: center; margin: 30px 0;">
		<a href="<?php echo esc_url( $args['receipt_url'] ); ?>" style="display: inline-block; background: #0073aa; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; font-size: 16px; font-weight: bold;">📄 <?php echo esc_html__( 'View/Print Receipt', 'ffcertificate' ); ?></a>
	</div>
	<?php endif; ?>
	<?php if ( ! empty( $args['cancel_url'] ) ) : ?>
	<div style="text-align: center; margin: 30px 0;">
		<p style="margin: 0 0 10px 0; font-size: 14px; color: #666;"><?php echo esc_html__( 'Need to cancel?', 'ffcertificate' ); ?></p>
		<a href="<?php echo esc_url( $args['cancel_url'] ); ?>" style="display: inline-block; background: #dc3545; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; font-size: 14px;"><?php echo esc_html__( 'Cancel Appointment', 'ffcertificate' ); ?></a>
	</div>
	<?php endif; ?>
</div>
