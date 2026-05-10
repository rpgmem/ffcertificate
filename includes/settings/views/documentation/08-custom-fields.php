<?php
/**
 * Documentation partial — Section 8: Custom Fields.
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
<!-- 8. Custom Fields Section -->
<div class="card">
	<h3 id="custom-fields" class="ffc-icon-edit"><?php esc_html_e( '8. Custom Fields', 'ffcertificate' ); ?></h3>
	
	<p><?php esc_html_e( 'Any custom field you create in Form Builder automatically becomes a template variable:', 'ffcertificate' ); ?></p>
	
	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'How It Works:', 'ffcertificate' ); ?></h4>
		<ul>
			<li><strong><?php esc_html_e( 'Step 1:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'Create a field in Form Builder (e.g., field name:', 'ffcertificate' ); ?> "company"</li>
			<li><strong><?php esc_html_e( 'Step 2:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'Use in template:', 'ffcertificate' ); ?> <code>{{company}}</code></li>
			<li><strong><?php esc_html_e( 'Step 3:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'Value gets replaced automatically in PDF', 'ffcertificate' ); ?></li>
		</ul>
	</div>
	
	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Example:', 'ffcertificate' ); ?></h4>
		<p><?php esc_html_e( 'If you create these custom fields:', 'ffcertificate' ); ?></p>
		<ul>
			<li><code>company</code> → <?php esc_html_e( 'Use:', 'ffcertificate' ); ?> <code>{{company}}</code></li>
			<li><code>department</code> → <?php esc_html_e( 'Use:', 'ffcertificate' ); ?> <code>{{department}}</code></li>
			<li><code>course_hours</code> → <?php esc_html_e( 'Use:', 'ffcertificate' ); ?> <code>{{course_hours}}</code></li>
		</ul>
	</div>
</div>
