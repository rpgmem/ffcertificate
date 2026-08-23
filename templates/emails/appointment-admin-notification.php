<?php
/**
 * Appointment admin-notification email body.
 *
 * Wrapped by the configurable chrome (layout.php) at send, like every other
 * plugin email. Rendered by AppointmentEmailHandler.
 *
 * Standard layout (#976): h2 event title (no emoji, semantic colour). Stays a
 * system-default email (not hub-editable) but follows the shared visual pattern.
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
<h2 style="margin: 0 0 20px 0; font-size: 24px; color: #2271b1;"><?php echo esc_html__( 'New appointment booking', 'ffcertificate' ); ?></h2>
<?php echo $args['details_table']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-built table from ffc_admin_notification_table(); every cell escaped at source. ?>
<p style="margin: 20px 0;"><a href="<?php echo esc_url( $args['manage_url'] ); ?>" style="background: #2271b1; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; display: inline-block;"><?php echo esc_html__( 'Manage Appointments', 'ffcertificate' ); ?></a></p>
