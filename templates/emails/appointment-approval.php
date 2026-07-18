<?php
/**
 * Appointment approval email body (miolo).
 *
 * Wrapped by layout.php. Rendered by AppointmentEmailHandler.
 *
 * @var array<string, mixed> $args {
 *     @type string $calendar_title Calendar title.
 *     @type string $date_formatted Formatted appointment date.
 *     @type string $time_formatted Formatted appointment time.
 *     @type string $receipt_url    Receipt URL (empty when unavailable).
 * }
 * @package FreeFormCertificate\SelfScheduling
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
	<h2 style="margin: 0 0 20px 0; color: #28a745; font-size: 24px;">✅ <?php echo esc_html__( 'Appointment Confirmed!', 'ffcertificate' ); ?></h2>
	<p style="margin: 0 0 15px 0; font-size: 16px;"><?php echo esc_html__( 'Your appointment has been approved and confirmed.', 'ffcertificate' ); ?></p>
	<div style="background: #d4edda; padding: 20px; border-radius: 8px; margin: 20px 0; border: 1px solid #c3e6cb;">
		<p style="margin: 0 0 10px 0;"><strong><?php echo esc_html__( 'Calendar:', 'ffcertificate' ); ?></strong> <?php echo esc_html( $args['calendar_title'] ); ?></p>
		<p style="margin: 0 0 10px 0;"><strong><?php echo esc_html__( 'Date:', 'ffcertificate' ); ?></strong> <?php echo esc_html( $args['date_formatted'] ); ?></p>
		<p style="margin: 0;"><strong><?php echo esc_html__( 'Time:', 'ffcertificate' ); ?></strong> <?php echo esc_html( $args['time_formatted'] ); ?></p>
	</div>
	<?php if ( ! empty( $args['receipt_url'] ) ) : ?>
	<div style="text-align: center; margin: 30px 0;">
		<a href="<?php echo esc_url( $args['receipt_url'] ); ?>" style="display: inline-block; background: #0073aa; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; font-size: 16px; font-weight: bold;">📄 <?php echo esc_html__( 'View/Print Receipt', 'ffcertificate' ); ?></a>
	</div>
	<?php endif; ?>
