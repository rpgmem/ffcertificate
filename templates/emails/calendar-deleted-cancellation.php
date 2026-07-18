<?php
/**
 * "Calendar deleted → appointment cancelled" email body.
 *
 * Sent to booked users when an admin deletes a self-scheduling calendar.
 * Wrapped by the configurable chrome (layout.php) at send. Rendered by
 * SelfSchedulingCPT::send_calendar_deletion_notification.
 *
 * @var array<string, mixed> $args {
 *     @type string $calendar_title Calendar title.
 *     @type string $date_formatted Formatted appointment date.
 *     @type string $time_formatted Formatted appointment time.
 * }
 * @package FreeFormCertificate\SelfScheduling
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ffc_cal  = isset( $args['calendar_title'] ) ? (string) $args['calendar_title'] : '';
$ffc_date = isset( $args['date_formatted'] ) ? (string) $args['date_formatted'] : '';
$ffc_time = isset( $args['time_formatted'] ) ? (string) $args['time_formatted'] : '';
?>
<p><?php echo esc_html__( 'Hello,', 'ffcertificate' ); ?></p>
<p><?php echo esc_html( sprintf( /* translators: %s: calendar title */ __( 'We regret to inform you that your appointment has been cancelled because the calendar "%s" is no longer available.', 'ffcertificate' ), $ffc_cal ) ); ?></p>
<div style="background:#fef2f2;padding:15px;border-radius:4px;margin:20px 0;border-left:4px solid #dc3545;">
	<div style="margin:8px 0;"><span style="font-weight:600;"><?php echo esc_html__( 'Date:', 'ffcertificate' ); ?></span> <?php echo esc_html( $ffc_date ); ?></div>
	<div style="margin:8px 0;"><span style="font-weight:600;"><?php echo esc_html__( 'Time:', 'ffcertificate' ); ?></span> <?php echo esc_html( $ffc_time ); ?></div>
	<div style="margin:8px 0;"><span style="font-weight:600;"><?php echo esc_html__( 'Calendar:', 'ffcertificate' ); ?></span> <?php echo esc_html( $ffc_cal ); ?></div>
</div>
<p><?php echo esc_html__( 'We apologize for any inconvenience this may cause.', 'ffcertificate' ); ?></p>
