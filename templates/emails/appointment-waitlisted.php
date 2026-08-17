<?php
/**
 * Appointment waitlist-joined email body (#941 phase 2).
 *
 * Wrapped by layout.php. Rendered by AppointmentEmailHandler.
 *
 * @var array<string, mixed> $args {
 *     @type string $calendar_title Calendar title.
 *     @type string $date_formatted Formatted appointment date.
 *     @type string $time_formatted Formatted appointment time.
 *     @type string $cancel_url     Cancellation URL (empty when not allowed).
 * }
 * @package FreeFormCertificate\SelfScheduling
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
	<h2 style="margin: 0 0 20px 0; color: #6c5ce7; font-size: 24px;">📋 <?php echo esc_html__( "You're on the Waitlist", 'ffcertificate' ); ?></h2>
	<p style="margin: 0 0 15px 0; font-size: 16px;"><?php echo esc_html__( 'This time was full, so you have been added to the waitlist. If a spot opens up, the next person in line is promoted automatically and you will be notified by email.', 'ffcertificate' ); ?></p>
	<div style="background: #eeecfb; padding: 20px; border-radius: 8px; margin: 20px 0; border: 1px solid #d6d0f5;">
		<p style="margin: 0 0 10px 0;"><strong><?php echo esc_html__( 'Calendar:', 'ffcertificate' ); ?></strong> <?php echo esc_html( $args['calendar_title'] ); ?></p>
		<p style="margin: 0 0 10px 0;"><strong><?php echo esc_html__( 'Date:', 'ffcertificate' ); ?></strong> <?php echo esc_html( $args['date_formatted'] ); ?></p>
		<p style="margin: 0;"><strong><?php echo esc_html__( 'Time:', 'ffcertificate' ); ?></strong> <?php echo esc_html( $args['time_formatted'] ); ?></p>
	</div>
	<?php if ( ! empty( $args['cancel_url'] ) ) : ?>
	<div style="text-align: center; margin: 20px 0;">
		<p style="margin: 0 0 10px 0; font-size: 14px; color: #666;"><?php echo esc_html__( 'Changed your mind?', 'ffcertificate' ); ?></p>
		<a href="<?php echo esc_url( $args['cancel_url'] ); ?>" style="display: inline-block; background: #dc3545; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; font-size: 14px;"><?php echo esc_html__( 'Leave Waitlist', 'ffcertificate' ); ?></a>
	</div>
	<?php endif; ?>
