<?php
/**
 * Documentation partial — Reference: HTML & Styling.
 *
 * Lists the HTML tags and inline CSS supported in the certificate template
 * for styling the generated PDF.
 *
 * @package FreeFormCertificate\Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!-- 7. HTML & Styling Section -->
<div class="card">
	<h3 id="reference-html-styling"><span class="dashicons dashicons-art" aria-hidden="true"></span> <?php esc_html_e( 'HTML & Styling', 'ffcertificate' ); ?></h3>
	<p><?php esc_html_e( 'You can use HTML and inline CSS to style your certificate:', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'Supported HTML Tags:', 'ffcertificate' ); ?></h4>
	<table class="widefat striped">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Tag', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Usage', 'ffcertificate' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<tr>
				<td><code>&lt;strong&gt;</code> <code>&lt;b&gt;</code></td>
				<td><?php esc_html_e( 'Bold text:', 'ffcertificate' ); ?> <code>&lt;strong&gt;{{name}}&lt;/strong&gt;</code></td>
			</tr>
			<tr>
				<td><code>&lt;em&gt;</code> <code>&lt;i&gt;</code></td>
				<td><?php esc_html_e( 'Italic text:', 'ffcertificate' ); ?> <code>&lt;em&gt;Certificate&lt;/em&gt;</code></td>
			</tr>
			<tr>
				<td><code>&lt;u&gt;</code></td>
				<td><?php esc_html_e( 'Underline text:', 'ffcertificate' ); ?> <code>&lt;u&gt;Important&lt;/u&gt;</code></td>
			</tr>
			<tr>
				<td><code>&lt;br&gt;</code></td>
				<td><?php esc_html_e( 'Line break', 'ffcertificate' ); ?></td>
			</tr>
			<tr>
				<td><code>&lt;p&gt;</code></td>
				<td><?php esc_html_e( 'Paragraph with spacing', 'ffcertificate' ); ?></td>
			</tr>
			<tr>
				<td><code>&lt;div&gt;</code></td>
				<td><?php esc_html_e( 'Container for sections', 'ffcertificate' ); ?></td>
			</tr>
			<tr>
				<td><code>&lt;table&gt;</code> <code>&lt;tr&gt;</code> <code>&lt;td&gt;</code> <code>&lt;th&gt;</code></td>
				<td><?php esc_html_e( 'Tables for layout (logos, signatures)', 'ffcertificate' ); ?></td>
			</tr>
			<tr>
				<td><code>&lt;img&gt;</code></td>
				<td><?php esc_html_e( 'Images (logos, signatures, decorations)', 'ffcertificate' ); ?></td>
			</tr>
			<tr>
				<td><code>&lt;h1&gt;</code> <code>&lt;h2&gt;</code> <code>&lt;h3&gt;</code> <code>&lt;h4&gt;</code></td>
				<td><?php esc_html_e( 'Headers/titles', 'ffcertificate' ); ?></td>
			</tr>
			<tr>
				<td><code>&lt;ul&gt;</code> <code>&lt;ol&gt;</code> <code>&lt;li&gt;</code></td>
				<td><?php esc_html_e( 'Lists (bullet or numbered)', 'ffcertificate' ); ?></td>
			</tr>
			<tr>
				<td><code>&lt;span&gt;</code> <code>&lt;hr&gt;</code> <code>&lt;font&gt;</code></td>
				<td><?php esc_html_e( 'Inline styling, a horizontal rule, and legacy font color/size/face', 'ffcertificate' ); ?></td>
			</tr>
		</tbody>
	</table>

	<div class="ffc-doc-note">
		<p>
			<strong class="ffc-icon-info"><?php esc_html_e( 'The template is filtered on save.', 'ffcertificate' ); ?></strong><br>
			<?php esc_html_e( 'Only the tags above survive, each with a fixed set of attributes: style works on almost every tag, but class is removed from img, td and th, and style blocks are not allowed — use inline styles. Developers can widen the list with the ffcertificate_allowed_html_tags filter.', 'ffcertificate' ); ?>
		</p>
	</div>

	<h4><?php esc_html_e( 'Image Attributes:', 'ffcertificate' ); ?></h4>
	<table class="widefat striped">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Example', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Result', 'ffcertificate' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<tr>
				<td><code>&lt;img src="logo.png" width="200"&gt;</code></td>
				<td><?php esc_html_e( 'Logo with fixed width', 'ffcertificate' ); ?></td>
			</tr>
			<tr>
				<td><code>&lt;img src="signature.png" height="80"&gt;</code></td>
				<td><?php esc_html_e( 'Signature with fixed height, proportional width', 'ffcertificate' ); ?></td>
			</tr>
			<tr>
				<td><code>&lt;img src="photo.png" width="150" height="150"&gt;</code></td>
				<td><?php esc_html_e( 'Image scaled to exactly these dimensions (it is stretched, not cropped, when the proportions differ)', 'ffcertificate' ); ?></td>
			</tr>
		</tbody>
	</table>

	<h4><?php esc_html_e( 'Common Inline Styles:', 'ffcertificate' ); ?></h4>
	<table class="widefat striped">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Style', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Example', 'ffcertificate' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<tr>
				<td><?php esc_html_e( 'Font size', 'ffcertificate' ); ?></td>
				<td><code>style="font-size: 14pt;"</code></td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'Text color', 'ffcertificate' ); ?></td>
				<td><code>style="color: #2271b1;"</code></td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'Text alignment', 'ffcertificate' ); ?></td>
				<td><code>style="text-align: center;"</code></td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'Background color', 'ffcertificate' ); ?></td>
				<td><code>style="background-color: #f0f0f0;"</code></td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'Margins/padding', 'ffcertificate' ); ?></td>
				<td><code>style="margin: 20px; padding: 15px;"</code></td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'Font family', 'ffcertificate' ); ?></td>
				<td><code>style="font-family: Arial, sans-serif;"</code></td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'Border', 'ffcertificate' ); ?></td>
				<td><code>style="border: 2px solid #000;"</code></td>
			</tr>
		</tbody>
	</table>

	<h4><?php esc_html_e( 'Helper classes:', 'ffcertificate' ); ?></h4>
	<p>
		<?php esc_html_e( 'The PDF stylesheet ships alignment helpers for the certificate body:', 'ffcertificate' ); ?>
		<code>ffc-txt-center</code>, <code>ffc-txt-left</code>, <code>ffc-txt-right</code>, <code>ffc-txt-justify</code>, <code>ffc-full-width</code>.
		<?php esc_html_e( 'Use them on a paragraph, div, span, heading, list or table.', 'ffcertificate' ); ?>
	</p>
	<p class="description">
		<?php esc_html_e( 'The stylesheet also defines image helpers', 'ffcertificate' ); ?>
		<code>ffc-responsive-logo</code> / <code>ffc-full-width-img</code>,
		<?php esc_html_e( 'but they cannot be used in a form\'s certificate template today: the class attribute is removed from img tags when the template is saved. Size images with width / height or an inline style instead.', 'ffcertificate' ); ?>
	</p>
</div>
