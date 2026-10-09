<?php
/**
 * Configurable email chrome ("shell").
 *
 * The single, admin-configurable shell wrapping every plugin email (#662 P2).
 * The handler builds the inner "email body" and passes it as $args['content'];
 * this shell wraps it with the header band, body card, footer and outer
 * wrapper — all styled from {@see \FreeFormCertificate\Core\EmailTemplateOptions}
 * (the "Email Model" box in Settings → SMTP). Table-based + inline styles so it
 * survives Gmail/Outlook `<style>`-stripping.
 *
 * @var array<string, mixed> $args {
 *     @type string $content   Pre-built inner HTML (the email body).
 *     @type string $recipient Optional recipient email (for the {{recipient}} footer token).
 *     @type array  $body_appearance Optional body background and text column, from
 *                                   {@see \FreeFormCertificate\Core\EmailBodyAppearance::document_args()};
 *                                   absent, the body renders exactly as the Email Model sets it.
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

// Per-message body appearance (#1660). Only the body cell changes; the header
// and footer above and below it are the Email Model's for every message.
$ffc_app       = isset( $args['body_appearance'] ) && is_array( $args['body_appearance'] ) ? $args['body_appearance'] : array();
$ffc_body_bg   = '' !== (string) ( $ffc_app['fallback_color'] ?? '' ) ? (string) $ffc_app['fallback_color'] : (string) $ffc_opt['body_bg'];
$ffc_body_text = '' !== (string) ( $ffc_app['text_color'] ?? '' ) ? (string) $ffc_app['text_color'] : (string) $ffc_opt['body_text_color'];
$ffc_body_img  = (string) ( $ffc_app['image_url'] ?? '' );
$ffc_body_min  = '' !== $ffc_body_img ? max( 0, (int) ( $ffc_app['min_height'] ?? 0 ) ) : 0;
$ffc_text_pos  = in_array( $ffc_app['position'] ?? 'full', array( 'left', 'right' ), true ) ? (string) $ffc_app['position'] : 'full';
$ffc_text_w    = (int) ( $ffc_app['text_width'] ?? 60 );
$ffc_text_w    = in_array( $ffc_text_w, array( 50, 60, 70 ), true ) ? $ffc_text_w : 60;
// The image is anchored on the side the text leaves free.
$ffc_img_pos  = 'left' === $ffc_text_pos ? 'right center' : ( 'right' === $ffc_text_pos ? 'left center' : 'center center' );
$ffc_body_css = 'background-color:' . $ffc_body_bg . ';';
if ( '' !== $ffc_body_img ) {
	$ffc_body_css .= "background-image:url('" . esc_url_raw( $ffc_body_img ) . "');background-position:" . $ffc_img_pos . ';background-size:cover;background-repeat:no-repeat;';
}
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
			<?php if ( 'full' !== $ffc_text_pos ) : ?>
			.ffc-email-col-text { width: 100% !important; display: block !important; }
			.ffc-email-col-space { display: none !important; }
			<?php endif; ?>
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
					<td class="ffc-email-body" valign="top"<?php echo '' !== $ffc_body_img ? ' background="' . esc_url( $ffc_body_img ) . '" bgcolor="' . esc_attr( $ffc_body_bg ) . '"' : ''; ?><?php echo $ffc_body_min > 0 ? ' height="' . esc_attr( (string) $ffc_body_min ) . '"' : ''; ?> style="<?php echo esc_attr( $ffc_body_css ); ?>color:<?php echo esc_attr( $ffc_body_text ); ?>;font-family:<?php echo esc_attr( $ffc_font ); ?>;font-size:<?php echo esc_attr( (string) (int) $ffc_opt['body_font_size'] ); ?>px;line-height:1.6;padding:<?php echo esc_attr( (string) (int) $ffc_opt['body_padding'] ); ?>px;">
						<?php if ( 'full' === $ffc_text_pos ) : ?>
							<?php echo $ffc_content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-escaped email body built by the email handler; each value is escaped at its own output point. ?>
						<?php else : ?>
						<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
							<tr>
								<?php if ( 'right' === $ffc_text_pos ) : ?>
								<td class="ffc-email-col-space" width="<?php echo esc_attr( (string) ( 100 - $ffc_text_w ) ); ?>%">&nbsp;</td>
								<?php endif; ?>
								<td class="ffc-email-col-text" width="<?php echo esc_attr( (string) $ffc_text_w ); ?>%" valign="top" style="color:<?php echo esc_attr( $ffc_body_text ); ?>;">
									<?php echo $ffc_content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-escaped email body built by the email handler; each value is escaped at its own output point. ?>
								</td>
								<?php if ( 'left' === $ffc_text_pos ) : ?>
								<td class="ffc-email-col-space" width="<?php echo esc_attr( (string) ( 100 - $ffc_text_w ) ); ?>%">&nbsp;</td>
								<?php endif; ?>
							</tr>
						</table>
						<?php endif; ?>
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
