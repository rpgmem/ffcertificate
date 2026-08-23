<?php
/**
 * Submission admin-notification email body.
 *
 * Wrapped by the configurable chrome (layout.php) at send. Rendered by
 * EmailHandler::send_admin_notification.
 *
 * Standard layout (#976): h2 event title (no emoji, semantic colour). Stays a
 * system-default email (not hub-editable) but follows the shared visual pattern.
 *
 * @var array<string, mixed> $args {
 *     @type string $details_table Pre-built key/value table HTML (already escaped at source).
 * }
 * @package FreeFormCertificate\Integrations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<h2 style="margin: 0 0 20px 0; font-size: 24px; color: #2271b1;"><?php echo esc_html__( 'New form submission', 'ffcertificate' ); ?></h2>
<?php echo $args['details_table']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-built table; every cell escaped at source in EmailHandler. ?>
