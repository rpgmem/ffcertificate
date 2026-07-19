<?php
/**
 * Capability "access granted" email body.
 *
 * Wrapped by the configurable chrome (layout.php) at send. Rendered by
 * CapabilityManager when a user is granted plugin access.
 *
 * @var array<string, mixed> $args {
 *     @type string $user_name     Recipient display name.
 *     @type string $context_label Feature label (Certificates / Appointments / …).
 *     @type string $site_name     Site name.
 *     @type string $dashboard_url Dashboard URL (may be empty).
 * }
 * @package FreeFormCertificate\UserDashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ffc_user = isset( $args['user_name'] ) ? (string) $args['user_name'] : '';
$ffc_ctx  = isset( $args['context_label'] ) ? (string) $args['context_label'] : '';
$ffc_site = isset( $args['site_name'] ) ? (string) $args['site_name'] : '';
$ffc_dash = isset( $args['dashboard_url'] ) ? (string) $args['dashboard_url'] : '';
?>
<p><?php echo esc_html( sprintf( /* translators: %s: user display name */ __( 'Hello %s,', 'ffcertificate' ), $ffc_user ) ); ?></p>
<p><?php echo esc_html( sprintf( /* translators: %1$s: feature name, %2$s: site name */ __( 'You now have access to %1$s on %2$s.', 'ffcertificate' ), $ffc_ctx, $ffc_site ) ); ?></p>
<?php if ( '' !== $ffc_dash ) : ?>
<p style="margin:24px 0;"><a href="<?php echo esc_url( $ffc_dash ); ?>" style="display:inline-block;background:#2271b1;color:#ffffff;padding:12px 24px;text-decoration:none;border-radius:4px;font-weight:600;"><?php echo esc_html__( 'Go to your dashboard', 'ffcertificate' ); ?></a></p>
<?php endif; ?>
<p style="color:#666666;font-size:13px;"><?php echo esc_html__( 'This is an automated message.', 'ffcertificate' ); ?></p>
