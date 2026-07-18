<?php
/**
 * Documentation partial — Section 7: HTML & Styling.
 *
 * Extracted from `ffc-tab-documentation.php` per S8 of the
 * god-object refactor (rpgmem/ffcertificate#141).
 *
 * @package FreeFormCertificate\Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!-- 7. HTML & Styling Section -->
<div class="card">
	<h3 id="reference-html-styling" class="ffc-icon-palette"><?php esc_html_e( 'HTML & Styling', 'ffcertificate' ); ?></h3>
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
				<td><code>&lt;table&gt;</code> <code>&lt;tr&gt;</code> <code>&lt;td&gt;</code></td>
				<td><?php esc_html_e( 'Tables for layout (logos, signatures)', 'ffcertificate' ); ?></td>
			</tr>
			<tr>
				<td><code>&lt;img&gt;</code></td>
				<td><?php esc_html_e( 'Images (logos, signatures, decorations)', 'ffcertificate' ); ?></td>
			</tr>
			<tr>
				<td><code>&lt;h1&gt;</code> <code>&lt;h2&gt;</code> <code>&lt;h3&gt;</code></td>
				<td><?php esc_html_e( 'Headers/titles', 'ffcertificate' ); ?></td>
			</tr>
			<tr>
				<td><code>&lt;ul&gt;</code> <code>&lt;ol&gt;</code> <code>&lt;li&gt;</code></td>
				<td><?php esc_html_e( 'Lists (bullet or numbered)', 'ffcertificate' ); ?></td>
			</tr>
		</tbody>
	</table>

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
				<td><?php esc_html_e( 'Photo cropped to fit dimensions', 'ffcertificate' ); ?></td>
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
</div>
