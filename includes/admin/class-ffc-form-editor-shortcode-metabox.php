<?php
/**
 * Form Editor Shortcode Metabox Renderer
 *
 * Extracted from FormEditorMetaboxRenderer as part of S3 god-object refactor.
 *
 * @since   3.2.0
 * @package FreeFormCertificate\Admin
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Form Editor Shortcode Metabox Renderer.
 *
 * @since 3.2.0
 */
class FormEditorShortcodeMetabox {

	/**
	 * Render the shortcode sidebar metabox
	 *
	 * @param WP_Post $post The post object.
	 */
	public function render( WP_Post $post ): void {
		?>
		<div class="ffc-shortcode-box">
			<p><label for="ffc-form-shortcode"><strong><?php esc_html_e( 'Copy this Shortcode:', 'ffcertificate' ); ?></strong></label></p>
			<?php
			// A read-only input, not a <code>: the shared copy button
			// (`.ffc-copy-link`) reads the .val() of its target (#1614).
			?>
			<div class="ffc-shortcode-copy">
				<input type="text" readonly id="ffc-form-shortcode" class="ffc-shortcode-display code" value="<?php echo esc_attr( '[ffc_form id="' . $post->ID . '"]' ); ?>">
				<button type="button" class="button ffc-copy-link ffc-icon-copy" data-ffc-copy-target="#ffc-form-shortcode"><?php esc_html_e( 'Copy', 'ffcertificate' ); ?></button>
			</div>
			<p class="description">
				<?php esc_html_e( 'Paste this code into any Page or Post to display the form.', 'ffcertificate' ); ?>
			</p>
		</div>
		<hr>
		<p><strong><?php esc_html_e( 'Tips:', 'ffcertificate' ); ?></strong></p>
		<ul class="ffc-tips-list">
			<li><?php echo wp_kses_post( __( 'Use <b>{{field_name}}</b> in the PDF Layout to insert user data.', 'ffcertificate' ) ); ?></li>
			<li><?php esc_html_e( 'Common variables include {{auth_code}}, {{submission_date}}, and {{ticket}}.', 'ffcertificate' ); ?></li>
		</ul>
		<?php
	}
}
