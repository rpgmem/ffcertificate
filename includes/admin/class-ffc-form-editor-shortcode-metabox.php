<?php
/**
 * Form Editor Shortcode Metabox Renderer
 *
 * Extracted from FormEditorMetaboxRenderer as part of S3 god-object refactor.
 *
 * @since   3.1.1
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
 * @since 3.1.1
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
			<p><strong><?php esc_html_e( 'Copy this Shortcode:', 'ffcertificate' ); ?></strong></p>
			<code class="ffc-shortcode-display">
				[ffc_form id="<?php echo esc_attr( (string) $post->ID ); ?>"]
			</code>
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
