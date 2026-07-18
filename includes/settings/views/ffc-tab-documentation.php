<?php
/**
 * Documentation Tab
 *
 * The Quick-Navigation TOC and the section cards are both driven by a single
 * ordered registry ($ffc_doc_sections), grouped into sections (Overview /
 * Features / Reference / Developer / Operations) — the reorganization from
 * #674. Adding or moving a doc page is a one-line registry edit; the nav and
 * the require order stay in sync automatically.
 *
 * NOTE: this Foundation step only regroups/reorders the existing pages under
 * the new sections — files, anchors and page content are unchanged. The
 * per-area content tranches rename files to semantic names, merge/split pages
 * and fill gaps.
 *
 * @package FreeFormCertificate\Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Grouped documentation registry.
 *
 * Each section has a `label` and an ordered list of `items`; each item is
 * `[ anchor, icon, title, file ]` — `anchor` matches the id="" on the page's
 * heading and `file` is a partial under documentation/.
 *
 * @var array<int, array{label: string, items: array<int, array<string, string>>}> $ffc_doc_sections
 */
$ffc_doc_sections = array(
	array(
		'label' => __( 'Overview', 'ffcertificate' ),
		'items' => array(
			array(
				'anchor' => 'features',
				'icon'   => 'ffc-icon-celebrate',
				'title'  => __( 'Overview & Features', 'ffcertificate' ),
				'file'   => '13-features.php',
			),
		),
	),
	array(
		'label' => __( 'Features', 'ffcertificate' ),
		'items' => array(
			array(
				'anchor' => 'reregistration',
				'icon'   => 'ffc-icon-note',
				'title'  => __( 'Reregistration', 'ffcertificate' ),
				'file'   => '10-reregistration.php',
			),
			array(
				'anchor' => 'ficha-pdf',
				'icon'   => 'ffc-icon-doc',
				'title'  => __( 'Ficha PDF', 'ffcertificate' ),
				'file'   => '11-ficha-pdf.php',
			),
			array(
				'anchor' => 'feature-certificates',
				'icon'   => 'ffc-icon-doc',
				'title'  => __( 'Certificates & Forms', 'ffcertificate' ),
				'file'   => 'feature-certificates.php',
			),
			array(
				'anchor' => 'custom-fields',
				'icon'   => 'ffc-icon-edit',
				'title'  => __( 'Custom Fields', 'ffcertificate' ),
				'file'   => '08-custom-fields.php',
			),
			array(
				'anchor' => 'audience-custom-fields',
				'icon'   => 'ffc-icon-user',
				'title'  => __( 'Audience Custom Fields', 'ffcertificate' ),
				'file'   => '09-audience-custom-fields.php',
			),
			array(
				'anchor' => 'feature-url-shortener',
				'icon'   => 'ffc-icon-link',
				'title'  => __( 'URL Shortener & QR Codes', 'ffcertificate' ),
				'file'   => 'feature-url-shortener.php',
			),
			array(
				'anchor' => 'feature-recruitment',
				'icon'   => 'ffc-icon-user',
				'title'  => __( 'Recruitment', 'ffcertificate' ),
				'file'   => 'feature-recruitment.php',
			),
		),
	),
	array(
		'label' => __( 'Reference', 'ffcertificate' ),
		'items' => array(
			array(
				'anchor' => 'reference-shortcodes',
				'icon'   => 'ffc-icon-pin',
				'title'  => __( 'Shortcodes', 'ffcertificate' ),
				'file'   => 'reference-shortcodes.php',
			),
			array(
				'anchor' => 'reference-tokens',
				'icon'   => 'ffc-icon-tag',
				'title'  => __( 'Template Variables / Tokens', 'ffcertificate' ),
				'file'   => 'reference-tokens.php',
			),
			array(
				'anchor' => 'quiz-variables',
				'icon'   => 'ffc-icon-tag',
				'title'  => __( 'Quiz / Evaluation Variables', 'ffcertificate' ),
				'file'   => '03-quiz-variables.php',
			),
			array(
				'anchor' => 'appointment-variables',
				'icon'   => 'ffc-icon-tag',
				'title'  => __( 'Appointment Receipt Variables', 'ffcertificate' ),
				'file'   => '04-appointment-variables.php',
			),
			array(
				'anchor' => 'reference-qr-codes',
				'icon'   => 'ffc-icon-phone',
				'title'  => __( 'QR Codes', 'ffcertificate' ),
				'file'   => 'reference-qr-codes.php',
			),
			array(
				'anchor' => 'reference-validation-url',
				'icon'   => 'ffc-icon-link',
				'title'  => __( 'Validation URL', 'ffcertificate' ),
				'file'   => 'reference-validation-url.php',
			),
			array(
				'anchor' => 'reference-html-styling',
				'icon'   => 'ffc-icon-palette',
				'title'  => __( 'HTML & Styling', 'ffcertificate' ),
				'file'   => 'reference-html-styling.php',
			),
			array(
				'anchor' => 'reference-security',
				'icon'   => 'ffc-icon-lock',
				'title'  => __( 'Security Features', 'ffcertificate' ),
				'file'   => 'reference-security.php',
			),
			array(
				'anchor' => 'reference-emails',
				'icon'   => 'ffc-icon-note',
				'title'  => __( 'Emails & Delivery', 'ffcertificate' ),
				'file'   => 'reference-emails.php',
			),
			array(
				'anchor' => 'examples',
				'icon'   => 'ffc-icon-note',
				'title'  => __( 'Complete Examples', 'ffcertificate' ),
				'file'   => '15-examples.php',
			),
		),
	),
	array(
		'label' => __( 'Developer', 'ffcertificate' ),
		'items' => array(
			array(
				'anchor' => 'developer-hooks-api',
				'icon'   => 'ffc-icon-wrench',
				'title'  => __( 'Hooks, REST & Forms API', 'ffcertificate' ),
				'file'   => 'developer-hooks-api.php',
			),
		),
	),
	array(
		'label' => __( 'Operations', 'ffcertificate' ),
		'items' => array(
			array(
				'anchor' => 'operations-maintenance',
				'icon'   => 'ffc-icon-wrench',
				'title'  => __( 'Maintenance Tools', 'ffcertificate' ),
				'file'   => 'operations-maintenance.php',
			),
			array(
				'anchor' => 'operations-troubleshooting',
				'icon'   => 'ffc-icon-wrench',
				'title'  => __( 'Troubleshooting', 'ffcertificate' ),
				'file'   => 'operations-troubleshooting.php',
			),
		),
	),
);
?>

<div class="ffc-settings-wrap">

<!-- Main Documentation Card with intro -->
<div class="card">
	<h2 class="ffc-icon-doc"><?php esc_html_e( 'Complete Plugin Documentation', 'ffcertificate' ); ?></h2>
	<p><?php esc_html_e( 'This plugin allows you to create certificate issuance forms, generate PDFs automatically, and verify authenticity with QR codes.', 'ffcertificate' ); ?></p>
</div>

<!-- Sentinel: when this scrolls out of the viewport the TOC card below
	auto-collapses (handled by ffc-doc-toc.js + IntersectionObserver). -->
<div class="ffc-doc-toc-sentinel" aria-hidden="true"></div>

<!-- Table of Contents — sticky on scroll, collapses to a thin strip once
	the user has scrolled past its original position. Grouped by section. -->
<div class="card ffc-doc-toc">
	<h3><?php esc_html_e( 'Quick Navigation', 'ffcertificate' ); ?></h3>
	<ul class="ffc-doc-toc-list">
		<?php foreach ( $ffc_doc_sections as $ffc_section ) : ?>
			<li class="ffc-doc-toc-section"><?php echo esc_html( $ffc_section['label'] ); ?></li>
			<?php foreach ( $ffc_section['items'] as $ffc_item ) : ?>
				<li><a href="#<?php echo esc_attr( $ffc_item['anchor'] ); ?>" class="<?php echo esc_attr( $ffc_item['icon'] ); ?>"><?php echo esc_html( $ffc_item['title'] ); ?></a></li>
			<?php endforeach; ?>
		<?php endforeach; ?>
	</ul>
</div>

<?php
foreach ( $ffc_doc_sections as $ffc_section ) {
	foreach ( $ffc_section['items'] as $ffc_item ) {
		require __DIR__ . '/documentation/' . $ffc_item['file'];
	}
}
?>

</div><!-- .ffc-settings-wrap -->
