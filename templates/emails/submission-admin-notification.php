<?php
/**
 * Submission admin-notification email body.
 *
 * Wrapped by the configurable chrome (layout.php) at send. Rendered by
 * EmailHandler::send_admin_notification.
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
<h3 style="color:#0073aa;margin:0 0 16px;"><?php echo esc_html__( 'Submission Details:', 'ffcertificate' ); ?></h3>
<?php echo $args['details_table']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-built table; every cell escaped at source in EmailHandler. ?>
