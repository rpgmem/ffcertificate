<?php
/**
 * Configurable email chrome ("shell").
 *
 * The single, admin-configurable shell wrapping every plugin email (#662 P2).
 * The handler builds the inner "miolo" and passes it as $args['content'];
 * this shell wraps it with the header band, body card, footer and outer
 * wrapper — all styled from {@see \FreeFormCertificate\Core\EmailTemplateOptions}
 * (the "Email Model" box in Settings → SMTP). Table-based + inline styles so it
 * survives Gmail/Outlook `<style>`-stripping.
 *
 * @var array<string, mixed> $args {
 *     @type string $content   Pre-built inner HTML (the miolo).
 *     @type string $recipient Optional recipient email (for the {{recipient}} footer token).
 * }
 * @package FreeFormCertificate
 */

use FreeFormCertificate\Core\EmailTemplateOptions;
use FreeFormCertificate\Core\TokenResolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ffc_content   = isset( $args['content'] ) && is_string( $args['content'] ) ? $args['content'] : '';
$ffc_recipient = isset( $args['recipient'] ) && is_string( $args['recipient'] ) ? $args['recipient'] : '';

// all() returns sanitized values (cleaned on save; defaults are clean literals),
// so the render path just casts + escapes — no absint()/sanitize_hex_color() here.
$ffc_opt      = EmailTemplateOptions::all();
$ffc_font     = EmailTemplateOptions::font_stack( (string) $ffc_opt['body_font_family'] );
$ffc_max      = (int) $ffc_opt['body_max_width'];
$ffc_footer   = TokenResolver::resolve(
	(string) $ffc_opt['footer_text'],
	EmailTemplateOptions::footer_tokens( array( 'recipient' => $ffc_recipient ) )
);
$ffc_has_logo = '' !== (string) $ffc_opt['header_logo_url'];
?>
<!DOCTYPE html>
<html>
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta http-equiv="X-UA-Compatible" content="IE=edge">
	<title><?php echo esc_html( get_bloginfo( 'name' ) ); ?></title>
	<style type="text/css">
		body { margin: 0; padding: 0; }
		table { border-collapse: collapse; }
		img { border: 0; outline: none; text-decoration: none; max-width: 100%; height: auto; }
		.ffc-email-body a { color: <?php echo esc_attr( (string) $ffc_opt['body_link_color'] ); ?>; }
		@media only screen and (max-width: <?php echo esc_attr( (string) ( $ffc_max + 40 ) ); ?>px) {
			.ffc-email-card { width: 100% !important; }
		}
	</style>
</head>
<body style="margin:0;padding:0;background-color:<?php echo esc_attr( (string) $ffc_opt['wrapper_bg'] ); ?>;font-family:<?php echo esc_attr( $ffc_font ); ?>;">
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color:<?php echo esc_attr( (string) $ffc_opt['wrapper_bg'] ); ?>;padding:<?php echo esc_attr( (string) (int) $ffc_opt['wrapper_padding'] ); ?>px 0;">
	<tr>
		<td align="center" valign="top">
			<table role="presentation" class="ffc-email-card" cellpadding="0" cellspacing="0" border="0" width="<?php echo esc_attr( (string) $ffc_max ); ?>" style="width:<?php echo esc_attr( (string) $ffc_max ); ?>px;max-width:100%;background-color:<?php echo esc_attr( (string) $ffc_opt['body_bg'] ); ?>;border-radius:<?php echo esc_attr( (string) (int) $ffc_opt['wrapper_border_radius'] ); ?>px;overflow:hidden;">
				<tr>
					<td align="<?php echo esc_attr( (string) $ffc_opt['header_alignment'] ); ?>" valign="middle" style="background-color:<?php echo esc_attr( (string) $ffc_opt['header_bg'] ); ?>;color:<?php echo esc_attr( (string) $ffc_opt['header_text_color'] ); ?>;padding:<?php echo esc_attr( (string) (int) $ffc_opt['header_padding'] ); ?>px;font-family:<?php echo esc_attr( $ffc_font ); ?>;">
						<?php if ( $ffc_has_logo ) : ?>
							<img src="<?php echo esc_url( (string) $ffc_opt['header_logo_url'] ); ?>" alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" width="<?php echo esc_attr( (string) (int) $ffc_opt['header_logo_max_width'] ); ?>" style="display:inline-block;max-width:<?php echo esc_attr( (string) (int) $ffc_opt['header_logo_max_width'] ); ?>px;height:auto;">
						<?php else : ?>
							<span style="font-size:22px;font-weight:600;color:<?php echo esc_attr( (string) $ffc_opt['header_text_color'] ); ?>;"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<td class="ffc-email-body" valign="top" style="background-color:<?php echo esc_attr( (string) $ffc_opt['body_bg'] ); ?>;color:<?php echo esc_attr( (string) $ffc_opt['body_text_color'] ); ?>;font-family:<?php echo esc_attr( $ffc_font ); ?>;font-size:<?php echo esc_attr( (string) (int) $ffc_opt['body_font_size'] ); ?>px;line-height:1.6;padding:<?php echo esc_attr( (string) (int) $ffc_opt['body_padding'] ); ?>px;">
						<?php echo $ffc_content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-escaped miolo built by the email handler; each value is escaped at its own output point. ?>
					</td>
				</tr>
				<?php if ( '' !== trim( (string) $ffc_footer ) ) : ?>
				<tr>
					<td align="center" valign="top" style="background-color:<?php echo esc_attr( (string) $ffc_opt['footer_bg'] ); ?>;color:<?php echo esc_attr( (string) $ffc_opt['footer_text_color'] ); ?>;font-family:<?php echo esc_attr( $ffc_font ); ?>;font-size:12px;line-height:1.6;padding:16px <?php echo esc_attr( (string) (int) $ffc_opt['body_padding'] ); ?>px;">
						<?php echo wp_kses_post( $ffc_footer ); ?>
					</td>
				</tr>
				<?php endif; ?>
			</table>
		</td>
	</tr>
</table>
</body>
</html>
