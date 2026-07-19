<?php
/**
 * Documentation Tab
 *
 * The Quick-Navigation TOC and the section cards are both driven by a single
 * ordered, **recursive** registry ($ffc_doc_tree): a tree of nodes mirroring
 * the plugin's functional areas (Certificates / Scheduling / Reregistration /
 * Recruitment / Short URLs / Developer / Troubleshooting) — the reorganization
 * from #697. Each node may carry its own page (`file` + `anchor`) and/or a list
 * of `children`; the nav renders as a collapsible tree and the page partials
 * are required in tree order. Adding or moving a doc page is a one-line
 * registry edit; the nav and the require order stay in sync automatically.
 *
 * NOTE (foundation step of #697): this only re-groups the *existing* pages
 * under the new functional tree — page files, anchors and content are
 * unchanged. Each functional area is then fleshed out (renames to functional
 * slugs, merges/splits, new content) in its own follow-up PR.
 *
 * @package FreeFormCertificate\Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Recursive documentation registry.
 *
 * Each node is an array with:
 *  - `title`    (string, required)         — display label.
 *  - `icon`     (string, optional)         — dashicon class (e.g. 'dashicons-info').
 *  - `anchor`   (string, optional)         — id="" on the page heading; makes the
 *                                            nav label a link. Required when `file` is set.
 *  - `file`     (string, optional)         — partial under documentation/ to require.
 *  - `children` (array<int, node>, opt.)   — nested nodes; renders a collapsible branch.
 *
 * A node with `children` but no `file` is a pure grouping branch. A node with a
 * `file` is a page (and may also carry children).
 *
 * @var array<int, array<string, mixed>> $ffc_doc_tree
 */
$ffc_doc_tree = array(
	array(
		'anchor' => 'overview',
		'icon'   => 'dashicons-info',
		'title'  => __( 'Overview & Features', 'ffcertificate' ),
		'file'   => 'overview.php',
	),
	array(
		'icon'     => 'dashicons-feedback',
		'title'    => __( 'Certificates & Forms', 'ffcertificate' ),
		'children' => array(
			array(
				'title'    => __( 'Forms', 'ffcertificate' ),
				'icon'     => 'dashicons-feedback',
				'children' => array(
					array(
						'anchor' => 'feature-certificates',
						'icon'   => 'dashicons-feedback',
						'title'  => __( 'Certificates & Forms', 'ffcertificate' ),
						'file'   => 'feature-certificates.php',
					),
					array(
						'anchor' => 'reference-html-styling',
						'icon'   => 'dashicons-art',
						'title'  => __( 'HTML & Styling', 'ffcertificate' ),
						'file'   => 'reference-html-styling.php',
					),
					array(
						'anchor' => 'reference-tokens',
						'icon'   => 'dashicons-tag',
						'title'  => __( 'Template Variables / Tokens', 'ffcertificate' ),
						'file'   => 'reference-tokens.php',
					),
					array(
						'anchor' => 'forms-dynamic-fields',
						'icon'   => 'dashicons-forms',
						'title'  => __( 'Dynamic Fields', 'ffcertificate' ),
						'file'   => 'forms-dynamic-fields.php',
					),
					array(
						'anchor' => 'reference-validation-url',
						'icon'   => 'dashicons-yes-alt',
						'title'  => __( 'Validation URL', 'ffcertificate' ),
						'file'   => 'reference-validation-url.php',
					),
					array(
						'anchor' => 'reference-qr-codes',
						'icon'   => 'dashicons-camera',
						'title'  => __( 'QR Codes', 'ffcertificate' ),
						'file'   => 'reference-qr-codes.php',
					),
					array(
						'anchor' => 'reference-security',
						'icon'   => 'dashicons-lock',
						'title'  => __( 'Security & Restrictions', 'ffcertificate' ),
						'file'   => 'reference-security.php',
					),
					array(
						'anchor' => 'forms-schedule',
						'icon'   => 'dashicons-clock',
						'title'  => __( 'Schedule', 'ffcertificate' ),
						'file'   => 'forms-schedule.php',
					),
					array(
						'anchor' => 'forms-geolocation',
						'icon'   => 'dashicons-location',
						'title'  => __( 'Geolocation', 'ffcertificate' ),
						'file'   => 'forms-geolocation.php',
					),
					array(
						'anchor' => 'forms-email',
						'icon'   => 'dashicons-email-alt',
						'title'  => __( 'Email', 'ffcertificate' ),
						'file'   => 'forms-email.php',
					),
					array(
						'anchor' => 'feature-quiz',
						'icon'   => 'dashicons-chart-bar',
						'title'  => __( 'Quiz / Evaluation', 'ffcertificate' ),
						'file'   => 'feature-quiz.php',
					),
					array(
						'anchor' => 'forms-public-operator-access',
						'icon'   => 'dashicons-share',
						'title'  => __( 'Public Operator Access', 'ffcertificate' ),
						'file'   => 'forms-public-operator-access.php',
					),
				),
			),
			array(
				'title'    => __( 'Submissions', 'ffcertificate' ),
				'icon'     => 'dashicons-list-view',
				'children' => array(
					array(
						'anchor' => 'submissions-list',
						'icon'   => 'dashicons-list-view',
						'title'  => __( 'Submissions — list & editing', 'ffcertificate' ),
						'file'   => 'submissions-list.php',
					),
					array(
						'anchor' => 'submissions-download',
						'icon'   => 'dashicons-download',
						'title'  => __( 'Downloading the certificate', 'ffcertificate' ),
						'file'   => 'submissions-download.php',
					),
				),
			),
			array(
				'title'    => __( 'Configuration', 'ffcertificate' ),
				'icon'     => 'dashicons-admin-generic',
				'children' => array(
					array(
						'anchor' => 'config-general',
						'icon'   => 'dashicons-admin-settings',
						'title'  => __( 'General', 'ffcertificate' ),
						'file'   => 'config-general.php',
					),
					array(
						'anchor' => 'reference-emails',
						'icon'   => 'dashicons-email',
						'title'  => __( 'Emails & Delivery', 'ffcertificate' ),
						'file'   => 'reference-emails.php',
					),
					array(
						'anchor' => 'config-cache',
						'icon'   => 'dashicons-performance',
						'title'  => __( 'Cache', 'ffcertificate' ),
						'file'   => 'config-cache.php',
					),
					array(
						'anchor' => 'config-rate-limit',
						'icon'   => 'dashicons-shield-alt',
						'title'  => __( 'Rate Limit', 'ffcertificate' ),
						'file'   => 'config-rate-limit.php',
					),
					array(
						'anchor' => 'config-geolocation',
						'icon'   => 'dashicons-location-alt',
						'title'  => __( 'Geolocation', 'ffcertificate' ),
						'file'   => 'config-geolocation.php',
					),
					array(
						'anchor' => 'feature-user-dashboard',
						'icon'   => 'dashicons-admin-users',
						'title'  => __( 'User Dashboard & Access', 'ffcertificate' ),
						'file'   => 'feature-user-dashboard.php',
					),
					array(
						'anchor' => 'reference-capabilities',
						'icon'   => 'dashicons-admin-network',
						'title'  => __( 'Capabilities & Roles', 'ffcertificate' ),
						'file'   => 'reference-capabilities.php',
					),
					array(
						'anchor' => 'config-advanced',
						'icon'   => 'dashicons-admin-tools',
						'title'  => __( 'Advanced', 'ffcertificate' ),
						'file'   => 'config-advanced.php',
					),
					array(
						'anchor' => 'operations-maintenance',
						'icon'   => 'dashicons-admin-tools',
						'title'  => __( 'Maintenance Tools', 'ffcertificate' ),
						'file'   => 'operations-maintenance.php',
					),
				),
			),
		),
	),
	array(
		'icon'     => 'dashicons-calendar',
		'title'    => __( 'Scheduling / Appointments', 'ffcertificate' ),
		'children' => array(
			array(
				'anchor' => 'feature-self-scheduling',
				'icon'   => 'dashicons-calendar',
				'title'  => __( 'Personal Calendars', 'ffcertificate' ),
				'file'   => 'feature-self-scheduling.php',
			),
			array(
				'anchor' => 'feature-audiences',
				'icon'   => 'dashicons-calendar-alt',
				'title'  => __( 'Audience Calendars', 'ffcertificate' ),
				'file'   => 'feature-audiences.php',
			),
		),
	),
	array(
		'icon'     => 'dashicons-update-alt',
		'title'    => __( 'Reregistration', 'ffcertificate' ),
		'children' => array(
			array(
				'anchor' => 'feature-reregistration',
				'icon'   => 'dashicons-update-alt',
				'title'  => __( 'Campaigns', 'ffcertificate' ),
				'file'   => 'feature-reregistration.php',
			),
			array(
				'anchor' => 'feature-ficha',
				'icon'   => 'dashicons-media-document',
				'title'  => __( 'Ficha PDF', 'ffcertificate' ),
				'file'   => 'feature-ficha.php',
			),
		),
	),
	array(
		'anchor' => 'feature-recruitment',
		'icon'   => 'dashicons-groups',
		'title'  => __( 'Recruitment', 'ffcertificate' ),
		'file'   => 'feature-recruitment.php',
	),
	array(
		'anchor' => 'feature-url-shortener',
		'icon'   => 'dashicons-admin-links',
		'title'  => __( 'Short URLs & QR Codes', 'ffcertificate' ),
		'file'   => 'feature-url-shortener.php',
	),
	array(
		'icon'     => 'dashicons-editor-code',
		'title'    => __( 'Developer', 'ffcertificate' ),
		'children' => array(
			array(
				'anchor' => 'developer-hooks-api',
				'icon'   => 'dashicons-editor-code',
				'title'  => __( 'Hooks, REST & Forms API', 'ffcertificate' ),
				'file'   => 'developer-hooks-api.php',
			),
			array(
				'anchor' => 'reference-shortcodes',
				'icon'   => 'dashicons-shortcode',
				'title'  => __( 'Shortcodes', 'ffcertificate' ),
				'file'   => 'reference-shortcodes.php',
			),
		),
	),
	array(
		'anchor' => 'operations-troubleshooting',
		'icon'   => 'dashicons-sos',
		'title'  => __( 'Troubleshooting', 'ffcertificate' ),
		'file'   => 'operations-troubleshooting.php',
	),
);

/**
 * Render the Quick-Navigation tree recursively.
 *
 * A node with children renders as a collapsible <details> branch; a leaf with
 * an anchor renders as a link. Leaf <li>s (and branch <li>s) stay filterable by
 * ffc-doc-search.js — grouping is expressed via nesting, not the retired
 * `.ffc-doc-toc-section` separator.
 *
 * @param array<int, array<string, mixed>> $ffc_nodes Nodes to render.
 * @param int                              $ffc_depth Current depth (0 = top).
 * @return void
 */
$ffc_render_doc_nav = static function ( array $ffc_nodes, int $ffc_depth ) use ( &$ffc_render_doc_nav ): void {
	$ffc_list_class = 0 === $ffc_depth ? 'ffc-doc-toc-list' : 'ffc-doc-toc-sublist';
	echo '<ul class="' . esc_attr( $ffc_list_class ) . '">';

	foreach ( $ffc_nodes as $ffc_node ) {
		$ffc_icon     = isset( $ffc_node['icon'] ) ? (string) $ffc_node['icon'] : '';
		$ffc_title    = isset( $ffc_node['title'] ) ? (string) $ffc_node['title'] : '';
		$ffc_anchor   = isset( $ffc_node['anchor'] ) ? (string) $ffc_node['anchor'] : '';
		$ffc_children = ( isset( $ffc_node['children'] ) && is_array( $ffc_node['children'] ) ) ? $ffc_node['children'] : array();

		if ( ! empty( $ffc_children ) ) {
			echo '<li class="ffc-doc-toc-branch"><details open><summary>';
			if ( '' !== $ffc_icon ) {
				echo '<span class="dashicons ' . esc_attr( $ffc_icon ) . '" aria-hidden="true"></span> ';
			}
			if ( '' !== $ffc_anchor ) {
				echo '<a href="#' . esc_attr( $ffc_anchor ) . '">' . esc_html( $ffc_title ) . '</a>';
			} else {
				echo '<span class="ffc-doc-toc-branch-label">' . esc_html( $ffc_title ) . '</span>';
			}
			echo '</summary>';
			$ffc_render_doc_nav( $ffc_children, $ffc_depth + 1 );
			echo '</details></li>';
			continue;
		}

		echo '<li><a href="#' . esc_attr( $ffc_anchor ) . '">';
		if ( '' !== $ffc_icon ) {
			echo '<span class="dashicons ' . esc_attr( $ffc_icon ) . '" aria-hidden="true"></span> ';
		}
		echo esc_html( $ffc_title ) . '</a></li>';
	}

	echo '</ul>';
};

/**
 * Require the page partials in tree (depth-first) order.
 *
 * @param array<int, array<string, mixed>> $ffc_nodes Nodes to walk.
 * @return void
 */
$ffc_require_doc_pages = static function ( array $ffc_nodes ) use ( &$ffc_require_doc_pages ): void {
	foreach ( $ffc_nodes as $ffc_node ) {
		if ( ! empty( $ffc_node['file'] ) ) {
			require __DIR__ . '/documentation/' . (string) $ffc_node['file'];
		}
		if ( isset( $ffc_node['children'] ) && is_array( $ffc_node['children'] ) ) {
			$ffc_require_doc_pages( $ffc_node['children'] );
		}
	}
};
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
	the user has scrolled past its original position. Grouped as a tree of
	collapsible functional areas (#697). -->
<div class="card ffc-doc-toc ffc-doc-toc--tree">
	<h3><?php esc_html_e( 'Quick Navigation', 'ffcertificate' ); ?></h3>
	<p>
		<input type="search" id="ffc-doc-search" class="regular-text" placeholder="<?php esc_attr_e( 'Search documentation…', 'ffcertificate' ); ?>" aria-label="<?php esc_attr_e( 'Search documentation', 'ffcertificate' ); ?>">
	</p>
	<?php $ffc_render_doc_nav( $ffc_doc_tree, 0 ); ?>
</div>

<?php $ffc_require_doc_pages( $ffc_doc_tree ); ?>

</div><!-- .ffc-settings-wrap -->
