<?php
/**
 * Template: Recruitment notice edit — the shortcode that shows this notice
 * (#1646).
 *
 * The read-only input is what the shared copy button (`.ffc-copy-link`, in
 * ffc-core.js) reads.
 *
 * @var string $shortcode This notice's shortcode.
 * @var string $selector  The shortcode without a notice, which renders a selector.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.35.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="card">
	<h2 class="ffc-icon-code"><?php esc_html_e( 'Show on the site', 'ffcertificate' ); ?></h2>
	<div class="ffc-shortcode-copy">
		<input type="text" readonly id="ffc-notice-shortcode" class="ffc-shortcode-display code" value="<?php echo esc_attr( $shortcode ); ?>" onclick="this.select();">
		<button type="button" class="button ffc-copy-link ffc-icon-copy" data-ffc-copy-target="#ffc-notice-shortcode"><?php esc_html_e( 'Copy', 'ffcertificate' ); ?></button>
	</div>
	<p class="description">
		<?php
		printf(
			/* translators: %s: the shortcode without a notice */
			esc_html__( 'Paste it into a page or post to publish this notice\'s classification. For a selector with every published notice, use %s.', 'ffcertificate' ),
			'<code>' . esc_html( $selector ) . '</code>'
		);
		?>
	</p>
</div>
