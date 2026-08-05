<?php
/**
 * CertTemplateCpt
 *
 * Registers the `ffc_cert_template` custom post type — the database-backed
 * pool of reusable certificate templates (issue #865). Mirrors the `ffc_form`
 * CPT's capability decoupling (#739): the CPT is gated by the FFC
 * `ffc_view_forms` / `ffc_manage_forms` caps via `capability_type` +
 * `map_meta_cap`, with per-post writes forced to the manage cap by
 * {@see CptCapPolicy}. Templates are a certificate-authoring surface, so they
 * live under the same "Forms" management domain rather than minting a new one.
 *
 * The admin management screen (the "Templates" submenu, columns, row actions,
 * visibility toggle) is added in a follow-up phase; this class only establishes
 * the post type + its shared meta-key / capability contract, so the seeder,
 * reader and editor "Load" flow have a stable home. `show_ui` is therefore
 * `false` for now (a pure data container queried by {@see CertTemplateReader}).
 *
 * @package FreeFormCertificate\Admin
 * @since   6.20.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Certificate-template pool custom post type.
 */
class CertTemplateCpt {

	/**
	 * Post type slug.
	 */
	public const POST_TYPE = 'ffc_cert_template';

	/**
	 * Meta key holding the template's HTML body (mirrors how a form stores its
	 * layout in `_ffc_form_config['pdf_layout']`; kept in meta to bypass the
	 * post-content filters).
	 */
	public const META_HTML = '_ffc_template_html';

	/**
	 * Meta flag (`'1'`) marking a plugin-shipped default template. Defaults are
	 * seeded from code and are read-only in the management UI.
	 */
	public const META_IS_DEFAULT = '_ffc_is_default';

	/**
	 * Meta key holding a shipped default's stable slug (e.g. `default_certificate_1`)
	 * so the seeder can re-add a missing default idempotently.
	 */
	public const META_DEFAULT_SLUG = '_ffc_default_slug';

	/**
	 * Meta flag (`'1'`) controlling whether the template appears in the form
	 * editor's "Load" list. Defaults to visible; toggled in the management UI.
	 */
	public const META_VISIBLE = '_ffc_template_visible';

	/**
	 * Constructor — registers the post type on `init`.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register' ) );
	}

	/**
	 * Register the `ffc_cert_template` post type.
	 *
	 * @return void
	 */
	public function register(): void {
		$labels = array(
			'name'          => _x( 'Certificate Templates', 'Post Type General Name', 'ffcertificate' ),
			'singular_name' => _x( 'Certificate Template', 'Post Type Singular Name', 'ffcertificate' ),
			'menu_name'     => __( 'Templates', 'ffcertificate' ),
			'all_items'     => __( 'Templates', 'ffcertificate' ),
			'add_new'       => __( 'Add New Template', 'ffcertificate' ),
			'add_new_item'  => __( 'Add New Template', 'ffcertificate' ),
			'edit_item'     => __( 'Edit Template', 'ffcertificate' ),
			'new_item'      => __( 'New Template', 'ffcertificate' ),
			'search_items'  => __( 'Search Templates', 'ffcertificate' ),
			'not_found'     => __( 'No templates found.', 'ffcertificate' ),
		);

		$args = array(
			'labels'          => $labels,
			'public'          => false,
			// Management UI (#865): the native list table + edit screen are
			// exposed as a "Templates" submenu under the Certificate (ffc_form)
			// menu, gated by the same forms caps below. Visibility, columns and
			// the HTML-editing metabox are wired by CertTemplateAdminScreen.
			'show_ui'         => true,
			'show_in_menu'    => 'edit.php?post_type=ffc_form',
			'query_var'       => false,
			// Same #739 decoupling as `ffc_form`: gate by the FFC forms caps, not
			// native post caps. List/read primitives map to the read-only
			// `ffc_view_forms`; write primitives to `ffc_manage_forms`. The per-post
			// meta caps (read_post/edit_post/delete_post) are deliberately NOT
			// mapped (that poisons the primitive check — see CPT for the full
			// rationale); per-post writes are gated by CptCapPolicy.
			'capability_type' => 'ffc_cert_template',
			'map_meta_cap'    => true,
			'capabilities'    => array(
				'edit_posts'             => 'ffc_view_forms',
				'edit_others_posts'      => 'ffc_view_forms',
				'read_private_posts'     => 'ffc_view_forms',
				'delete_posts'           => 'ffc_manage_forms',
				'delete_others_posts'    => 'ffc_manage_forms',
				'publish_posts'          => 'ffc_manage_forms',
				'create_posts'           => 'ffc_manage_forms',
				'edit_published_posts'   => 'ffc_manage_forms',
				'delete_published_posts' => 'ffc_manage_forms',
			),
			'has_archive'     => false,
			'hierarchical'    => false,
			'supports'        => array( 'title' ),
			'rewrite'         => false,
		);

		register_post_type( self::POST_TYPE, $args );
	}
}
