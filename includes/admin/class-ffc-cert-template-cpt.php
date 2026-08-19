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
 * @since   6.18.0
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
	 * Meta key holding the template's background-image URL (mirrors how a form
	 * stores its background in `_ffc_form_config['bg_image']`). Carried into the
	 * form's Background Image URL field when the template is loaded in the editor.
	 */
	public const META_BG_IMAGE = '_ffc_template_bg_image';

	/**
	 * Meta key discriminating which plugin surface a template serves (#945):
	 * `certificate` (the original pool contents) or `appointment_receipt` (the
	 * self-scheduling comprovante). An **absent** value counts as `certificate`,
	 * so pre-#945 templates keep working unchanged. The value is a free-form
	 * string on purpose — a future surface adds its own kind and filters the pool
	 * by it, reusing this one storage instead of minting a parallel CPT.
	 */
	public const META_KIND = '_ffc_template_kind';

	/**
	 * Kind value: a certificate template (the pool's original, default contents).
	 */
	public const KIND_CERTIFICATE = 'certificate';

	/**
	 * Kind value: a self-scheduling appointment-receipt (comprovante) template (#945).
	 */
	public const KIND_APPOINTMENT_RECEIPT = 'appointment_receipt';

	/**
	 * Whether a string is one of the known template kinds. Guards a
	 * request-supplied kind (e.g. the `?ffc_kind=` "Add New" preset, #951)
	 * before it is written to `META_KIND`.
	 *
	 * @param string $kind Candidate kind value.
	 * @return bool
	 */
	public static function is_valid_kind( string $kind ): bool {
		return in_array( $kind, array( self::KIND_CERTIFICATE, self::KIND_APPOINTMENT_RECEIPT ), true );
	}

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
			// Kind-neutral: the pool holds certificate AND appointment-receipt
			// templates (and future kinds), so the labels no longer say
			// "Certificate" (#951).
			'name'          => _x( 'Document Templates', 'Post Type General Name', 'ffcertificate' ),
			'singular_name' => _x( 'Document Template', 'Post Type Singular Name', 'ffcertificate' ),
			'menu_name'     => __( 'Document Templates', 'ffcertificate' ),
			'all_items'     => __( 'Document Templates', 'ffcertificate' ),
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
			// Management UI (#865): the native list table + edit screen are the
			// single hub for every document model (#951 Direction 1), surfaced as
			// a "Document Templates" submenu under the FFC Settings menu — a
			// kind-neutral home, since the pool is no longer certificate-only.
			// Gated by the same forms caps below. Visibility, columns and the
			// HTML-editing metabox are wired by CertTemplateAdminScreen.
			'show_ui'         => true,
			'show_in_menu'    => 'ffc-settings',
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
