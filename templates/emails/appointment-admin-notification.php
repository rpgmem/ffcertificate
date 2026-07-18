<?php
/**
 * Appointment admin-notification email body (miolo).
 *
 * Wrapped by the configurable chrome (layout.php) at send, like every other
 * plugin email. Rendered by AppointmentEmailHandler.
 *
 * @var array<string, mixed> $args {
 *     @type string $details_table Pre-built key/value table HTML (already escaped).
 *     @type string $manage_url    Admin "manage appointments" URL.
 * }
 * @package FreeFormCertificate\SelfScheduling
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<h3 style="color: #0073aa; margin: 0 0 16px;"><?php echo esc_html__( 'New Appointment Booking', 'ffcertificate' ); ?></h3>
<?php echo $args['details_table']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-built table from ffc_admin_notification_table(); every cell escaped at source. ?>
<p style="margin: 20px 0;"><a href="<?php echo esc_url( $args['manage_url'] ); ?>" style="background: #0073aa; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; display: inline-block;"><?php echo esc_html__( 'Manage Appointments', 'ffcertificate' ); ?></a></p>
