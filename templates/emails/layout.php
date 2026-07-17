<?php
/**
 * Shared email chrome ("shell").
 *
 * The email handler builds the inner "miolo" and passes it as $args['content'];
 * this shell wraps it with the standard header band and the site-name footer
 * card. It is the single source of the email chrome (previously the
 * ffc_email_header() / ffc_email_footer() trait helpers).
 *
 * @var array<string, mixed> $args Expects 'content' => string (pre-built inner HTML).
 * @package FreeFormCertificate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ffc_content = isset( $args['content'] ) && is_string( $args['content'] ) ? $args['content'] : '';
?>
<div style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; background: #f9f9f9; padding: 20px;">
	<?php echo $ffc_content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-escaped inner HTML built by the email handler; each value is escaped at its own output point. ?>
	<div style="background: white; border-radius: 8px; padding: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
		<p style="margin: 0; font-size: 12px; color: #999; text-align: center;"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></p>
	</div>
</div>
