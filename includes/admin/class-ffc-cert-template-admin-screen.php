<?php
/**
 * CertTemplateAdminScreen
 *
 * Management UI for the certificate-template pool (issue #865): the columns,
 * row actions and visibility toggle layered onto the native `ffc_cert_template`
 * list table (the CPT is registered with `show_ui => true` as a "Templates"
 * submenu under the Certificate menu — see {@see CertTemplateCpt}).
 *
 * Adds two columns — **Type** (Default vs Custom) and **Visible** (whether the
 * template appears in the form editor's "Load" list) — plus a nonce-protected
 * "Show / Hide" row action that flips {@see CertTemplateCpt::META_VISIBLE} via
 * {@see CertTemplateWriter::set_visibility()}.
 *
 * Shipped defaults are **read-only** (#865 decision #11), enforced server-side:
 * their HTML cannot be edited (the save handler skips a default's body), they
 * cannot be renamed (`wp_insert_post_data` restores the title) or deleted
 * (`map_meta_cap` returns `do_not_allow`, and the Trash/Delete row actions are
 * hidden). Only visibility stays togglable — a default can be hidden, not
 * changed; customizing one means Duplicate → an editable user template.
 *
 * Authorization: the toggle requires the manage cap (`ffc_manage_forms`, admins
 * via `manage_options`) exactly like the CPT's write primitives; a view-only
 * operator (`ffc_view_forms`) sees the columns read-only with no toggle link.
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
 * List-table columns, row actions and the visibility toggle for the pool.
 */
class CertTemplateAdminScreen {

	/**
	 * The `admin_action_{$action}` slug for the visibility toggle (list-screen
	 * row action — a full-page nonce-protected navigation).
	 */
	private const TOGGLE_ACTION = 'ffc_toggle_cert_template_visibility';

	/**
	 * The `wp_ajax_{$action}` slug for the edit-screen visibility autosave. The
	 * sidebar "Template options" toggle persists over AJAX so a shipped default —
	 * whose Publish/Update box is removed — stays live-editable for that one flag.
	 */
	private const AJAX_TOGGLE_ACTION = 'ffc_cert_template_toggle_visibility';

	/**
	 * Script handle for the edit-screen admin behaviours (title lock + toggle
	 * autosave).
	 */
	private const EDIT_HANDLE = 'ffc-cert-template-admin';

	/**
	 * The `admin_action_{$action}` slug for duplicating a template.
	 */
	private const DUPLICATE_ACTION = 'ffc_duplicate_cert_template';

	/**
	 * The `admin_action_{$action}` slug for exporting a template as `.html`.
	 */
	private const EXPORT_ACTION = 'ffc_export_cert_template';

	/**
	 * The `admin_action_{$action}` slug for the "Restore defaults" toolbar button.
	 */
	private const RESTORE_ACTION = 'ffc_restore_cert_templates';

	/**
	 * The `admin_action_{$action}` slug for the sample-data preview page.
	 */
	private const PREVIEW_ACTION = 'ffc_preview_cert_template';

	/**
	 * Script handle for the preview overlay.
	 */
	private const PREVIEW_HANDLE = 'ffc-cert-template-preview';

	/**
	 * Nonce action for the edit-screen metabox save.
	 */
	private const SAVE_NONCE = 'ffc_save_cert_template';

	/**
	 * Register the list-table + row-action hooks.
	 */
	public function __construct() {
		$pt = CertTemplateCpt::POST_TYPE;
		add_filter( "manage_{$pt}_posts_columns", array( $this, 'columns' ) );
		add_action( "manage_{$pt}_posts_custom_column", array( $this, 'render_column' ), 10, 2 );
		add_filter( 'post_row_actions', array( $this, 'row_actions' ), 10, 2 );
		add_action( 'admin_action_' . self::TOGGLE_ACTION, array( $this, 'handle_toggle_visibility' ) );
		// Edit-screen visibility autosave (the sidebar toggle persists without the
		// Publish/Update box, which is removed for shipped defaults). Priv-only.
		add_action( 'wp_ajax_' . self::AJAX_TOGGLE_ACTION, array( $this, 'ajax_toggle_visibility' ) );
		// Row + toolbar management actions (#865 decision #11/#13): Duplicate and
		// Export any template; Restore defaults re-seeds shipped defaults.
		add_action( 'admin_action_' . self::DUPLICATE_ACTION, array( $this, 'handle_duplicate' ) );
		add_action( 'admin_action_' . self::EXPORT_ACTION, array( $this, 'handle_export' ) );
		add_action( 'admin_action_' . self::RESTORE_ACTION, array( $this, 'handle_restore_defaults' ) );
		add_action( 'restrict_manage_posts', array( $this, 'render_restore_button' ) );
		// Sample-data preview (#865 decision #13): a read-only preview page a
		// "Preview" row action opens in an overlay iframe (graceful no-JS: the
		// link navigates to the page directly).
		add_action( 'admin_action_' . self::PREVIEW_ACTION, array( $this, 'handle_preview' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_preview_assets' ) );
		// Edit screen gets the same CodeMirror HTML editor as the form-editor
		// layout box, so editing a template looks identical to editing a form's
		// certificate HTML.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_edit_assets' ) );
		// Edit screen: HTML body + visibility metabox (the CPT only `supports`
		// title, so the template body needs its own field).
		add_action( 'add_meta_boxes_' . $pt, array( $this, 'add_edit_metabox' ) );
		add_action( 'save_post_' . $pt, array( $this, 'save_edit_metabox' ), 10, 2 );
		// Shipped defaults are read-only (#865 decision #11): block delete
		// server-side (priority 20 so it runs after CptCapPolicy's map at 10)
		// and block rename via the insert filter. HTML-edit is blocked in the
		// save handler; customizing a default means Duplicate → user template.
		add_filter( 'map_meta_cap', array( $this, 'protect_default_caps' ), 20, 4 );
		add_filter( 'wp_insert_post_data', array( $this, 'preserve_default_title' ), 10, 2 );
	}

	/**
	 * Insert the Type + Visible columns after the title.
	 *
	 * @param array<string, string> $columns Existing columns.
	 * @return array<string, string>
	 */
	public function columns( array $columns ): array {
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'title' === $key ) {
				$out['ffc_type']    = __( 'Type', 'ffcertificate' );
				$out['ffc_visible'] = __( 'Visible', 'ffcertificate' );
			}
		}
		// Fallback if the title column is absent for any reason.
		if ( ! isset( $out['ffc_type'] ) ) {
			$out['ffc_type']    = __( 'Type', 'ffcertificate' );
			$out['ffc_visible'] = __( 'Visible', 'ffcertificate' );
		}
		return $out;
	}

	/**
	 * Render the custom column cells.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Row post id.
	 * @return void
	 */
	public function render_column( string $column, int $post_id ): void {
		if ( 'ffc_type' === $column ) {
			echo CertTemplateReader::is_default( $post_id )
				? esc_html__( 'Default', 'ffcertificate' )
				: esc_html__( 'Custom', 'ffcertificate' );
			return;
		}

		if ( 'ffc_visible' === $column ) {
			$visible = '1' === (string) get_post_meta( $post_id, CertTemplateCpt::META_VISIBLE, true );
			echo $visible
				? esc_html__( 'Visible', 'ffcertificate' )
				: esc_html__( 'Hidden', 'ffcertificate' );
		}
	}

	/**
	 * Add the Show/Hide toggle row action and protect shipped defaults from
	 * deletion.
	 *
	 * @param array<string, string> $actions Row actions.
	 * @param \WP_Post              $post    Row post.
	 * @return array<string, string>
	 */
	public function row_actions( array $actions, $post ): array {
		// $post is always a WP_Post here (the post_row_actions filter contract);
		// only act on our pool's rows.
		if ( CertTemplateCpt::POST_TYPE !== $post->post_type ) {
			return $actions;
		}

		// Shipped defaults are re-seeded on demand — deleting one is pointless
		// and confusing, so drop its Trash/Delete actions for everyone.
		if ( CertTemplateReader::is_default( (int) $post->ID ) ) {
			unset( $actions['trash'], $actions['delete'] );
		}

		// Preview is read-only — available to anyone who can see the list
		// (`ffc_view_forms`, which list access already requires), before the
		// manage-cap gate below. The class hooks the JS overlay; the href is a
		// working no-JS fallback that opens the preview page directly.
		$preview_url = wp_nonce_url(
			admin_url( 'admin.php?action=' . self::PREVIEW_ACTION . '&post=' . (int) $post->ID ),
			self::PREVIEW_ACTION . '_' . (int) $post->ID
		);

		$actions['ffc_preview'] = '<a href="' . esc_url( $preview_url ) . '" class="ffc-template-preview" target="_blank" rel="noopener">' . esc_html__( 'Preview', 'ffcertificate' ) . '</a>';

		// The visibility toggle is a write — manage cap only.
		if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_forms' ) ) {
			return $actions;
		}

		$visible = '1' === (string) get_post_meta( (int) $post->ID, CertTemplateCpt::META_VISIBLE, true );
		$url     = wp_nonce_url(
			admin_url( 'admin.php?action=' . self::TOGGLE_ACTION . '&post=' . (int) $post->ID ),
			self::TOGGLE_ACTION . '_' . (int) $post->ID
		);
		$label   = $visible ? __( 'Hide', 'ffcertificate' ) : __( 'Show', 'ffcertificate' );

		$actions['ffc_toggle_visibility'] = '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';

		// Duplicate — allowed for every template (customizing a shipped default
		// means Duplicate → editable user template, #865 decision #11).
		$dup_url = wp_nonce_url(
			admin_url( 'admin.php?action=' . self::DUPLICATE_ACTION . '&post=' . (int) $post->ID ),
			self::DUPLICATE_ACTION . '_' . (int) $post->ID
		);

		$actions['ffc_duplicate'] = '<a href="' . esc_url( $dup_url ) . '">' . esc_html__( 'Duplicate', 'ffcertificate' ) . '</a>';

		// Export the raw template HTML as a `.html` download.
		$exp_url = wp_nonce_url(
			admin_url( 'admin.php?action=' . self::EXPORT_ACTION . '&post=' . (int) $post->ID ),
			self::EXPORT_ACTION . '_' . (int) $post->ID
		);

		$actions['ffc_export'] = '<a href="' . esc_url( $exp_url ) . '">' . esc_html__( 'Export', 'ffcertificate' ) . '</a>';

		return $actions;
	}

	/**
	 * Handle the nonce-protected visibility toggle admin action.
	 *
	 * @return void
	 */
	public function handle_toggle_visibility(): void {
		if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_forms' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage certificate templates.', 'ffcertificate' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified immediately below via check_admin_referer.
		$post_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;

		check_admin_referer( self::TOGGLE_ACTION . '_' . $post_id );

		$visible = '1' === (string) get_post_meta( $post_id, CertTemplateCpt::META_VISIBLE, true );
		CertTemplateWriter::set_visibility( $post_id, ! $visible );

		wp_safe_redirect( admin_url( 'edit.php?post_type=' . CertTemplateCpt::POST_TYPE ) );
		exit;
	}

	/**
	 * Autosave the edit-screen "Show in Load list" visibility toggle over AJAX.
	 *
	 * The one editable control on a shipped default (its Publish/Update box is
	 * removed) and a live switch on user templates too. Gated by the same
	 * `ffc_manage_forms` capability + a per-action nonce as every other template
	 * mutation.
	 *
	 * @return void
	 */
	public function ajax_toggle_visibility(): void {
		if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_forms' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to manage certificate templates.', 'ffcertificate' ) ), 403 );
		}

		check_ajax_referer( self::AJAX_TOGGLE_ACTION, 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
		$post    = $post_id > 0 ? get_post( $post_id ) : null;
		if ( ! $post instanceof \WP_Post || CertTemplateCpt::POST_TYPE !== $post->post_type ) {
			wp_send_json_error( array( 'message' => __( 'Template not found.', 'ffcertificate' ) ), 404 );
		}

		$visible = isset( $_POST['visible'] ) && '1' === (string) wp_unslash( $_POST['visible'] );
		CertTemplateWriter::set_visibility( $post_id, $visible );

		wp_send_json_success( array( 'visible' => $visible ) );
	}

	/**
	 * Duplicate a template into a new editable user template, then open it.
	 *
	 * @return void
	 */
	public function handle_duplicate(): void {
		if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_forms' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage certificate templates.', 'ffcertificate' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified immediately below via check_admin_referer.
		$post_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;
		check_admin_referer( self::DUPLICATE_ACTION . '_' . $post_id );

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || CertTemplateCpt::POST_TYPE !== $post->post_type ) {
			wp_die( esc_html__( 'Template not found.', 'ffcertificate' ) );
		}

		$html = (string) get_post_meta( $post_id, CertTemplateCpt::META_HTML, true );
		/* translators: %s: source template title */
		$title  = sprintf( __( '%s (copy)', 'ffcertificate' ), (string) $post->post_title );
		$new_id = CertTemplateWriter::create( $title, $html, true );

		// Open the new copy for editing; fall back to the list on failure.
		$redirect = $new_id > 0
			? admin_url( 'post.php?post=' . $new_id . '&action=edit' )
			: admin_url( 'edit.php?post_type=' . CertTemplateCpt::POST_TYPE );

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Export a template's raw HTML as a `.html` file download.
	 *
	 * @return void
	 */
	public function handle_export(): void {
		if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_forms' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage certificate templates.', 'ffcertificate' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified immediately below via check_admin_referer.
		$post_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;
		check_admin_referer( self::EXPORT_ACTION . '_' . $post_id );

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || CertTemplateCpt::POST_TYPE !== $post->post_type ) {
			wp_die( esc_html__( 'Template not found.', 'ffcertificate' ) );
		}

		$html = (string) get_post_meta( $post_id, CertTemplateCpt::META_HTML, true );
		$slug = sanitize_file_name( '' !== (string) $post->post_title ? (string) $post->post_title : 'template' );
		if ( '' === $slug ) {
			$slug = 'template';
		}

		$this->emit_download( $slug . '.html', $html );
	}

	/**
	 * Send a file download and terminate. Isolated so tests can override the
	 * terminal `header()`/`echo`/`exit` without emitting real headers.
	 *
	 * @param string $filename Download filename.
	 * @param string $body     File body.
	 * @return void
	 */
	protected function emit_download( string $filename, string $body ): void {
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $body ) );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw `.html` file download of the pre-sanitized (HtmlPolicy) stored template body; escaping would corrupt the exported file.
		echo $body;
		exit;
	}

	/**
	 * Re-seed the shipped default templates (non-destructive) — the "Restore
	 * defaults" toolbar action (#865 decision #13).
	 *
	 * @return void
	 */
	public function handle_restore_defaults(): void {
		if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_forms' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage certificate templates.', 'ffcertificate' ) );
		}
		check_admin_referer( self::RESTORE_ACTION );

		CertTemplateSeeder::restore();

		wp_safe_redirect( admin_url( 'edit.php?post_type=' . CertTemplateCpt::POST_TYPE . '&ffc_restored=1' ) );
		exit;
	}

	/**
	 * Render the "Restore defaults" button in the list-table toolbar.
	 *
	 * @param string $post_type Current list-table post type.
	 * @return void
	 */
	public function render_restore_button( string $post_type ): void {
		if ( CertTemplateCpt::POST_TYPE !== $post_type ) {
			return;
		}
		if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_forms' ) ) {
			return;
		}

		$url = wp_nonce_url(
			admin_url( 'admin.php?action=' . self::RESTORE_ACTION ),
			self::RESTORE_ACTION
		);
		echo '<a href="' . esc_url( $url ) . '" class="button">' . esc_html__( 'Restore defaults', 'ffcertificate' ) . '</a>';
	}

	/**
	 * Render a template's sample-data preview page (#865 decision #13). Read-only,
	 * so gated on the view cap (list access already requires `ffc_view_forms`).
	 *
	 * @return void
	 */
	public function handle_preview(): void {
		if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_view_forms' ) ) {
			wp_die( esc_html__( 'You do not have permission to preview certificate templates.', 'ffcertificate' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified immediately below via check_admin_referer.
		$post_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;
		check_admin_referer( self::PREVIEW_ACTION . '_' . $post_id );

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || CertTemplateCpt::POST_TYPE !== $post->post_type ) {
			wp_die( esc_html__( 'Template not found.', 'ffcertificate' ) );
		}

		$html     = (string) get_post_meta( $post_id, CertTemplateCpt::META_HTML, true );
		$rendered = $this->render_sample( $html, (string) $post->post_title );

		$this->emit_preview_page( (string) $post->post_title, $rendered );
	}

	/**
	 * Render a template body with sample token values + the configured logos,
	 * reusing the real certificate render pipeline. Isolated so tests can stub it.
	 *
	 * @param string $template_html Stored template HTML.
	 * @param string $title         Template title (used as the sample form title).
	 * @return string The rendered body fragment.
	 */
	protected function render_sample( string $template_html, string $title ): string {
		$renderer = new \FreeFormCertificate\Generators\PdfHtmlRenderer();
		$samples  = \FreeFormCertificate\Core\CertificatePreviewSamples::get_map();

		return $renderer->generate_html( $samples, $title, array( 'pdf_layout' => $template_html ) );
	}

	/**
	 * Emit the self-contained preview HTML document and terminate. Isolated so
	 * tests can override the terminal `header()`/`echo`/`exit`.
	 *
	 * @param string $title Template title (page title).
	 * @param string $body  Rendered body fragment.
	 * @return void
	 */
	protected function emit_preview_page( string $title, string $body ): void {
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow', true );

		$doc = '<!DOCTYPE html><html><head><meta charset="utf-8">'
			. '<meta name="robots" content="noindex,nofollow">'
			. '<title>' . esc_html( $title ) . '</title>'
			. '<style>html,body{margin:0;padding:0;background:#e5e5e5;}'
			. '.ffc-preview-canvas{background:#fff;margin:24px auto;max-width:1123px;box-shadow:0 2px 12px rgba(0,0,0,.25);}'
			. '</style></head><body><div class="ffc-preview-canvas">' . $body . '</div></body></html>';

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Self-contained preview page: the title is esc_html'd and the body is the HtmlPolicy-sanitized template rendered through PdfHtmlRenderer (which wp_kses-filters every injected value); the frame is sandboxed script-less.
		echo $doc;
		exit;
	}

	/**
	 * Enqueue the preview overlay script on the pool list screen only.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_preview_assets( string $hook ): void {
		if ( 'edit.php' !== $hook ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen instanceof \WP_Screen || CertTemplateCpt::POST_TYPE !== $screen->post_type ) {
			return;
		}

		$suffix = \FreeFormCertificate\Core\AssetHelper::asset_suffix();
		wp_enqueue_script(
			self::PREVIEW_HANDLE,
			FFC_PLUGIN_URL . "assets/js/ffc-cert-template-preview{$suffix}.js",
			array( 'jquery' ),
			FFC_VERSION,
			true
		);
		wp_localize_script(
			self::PREVIEW_HANDLE,
			'ffcCertTemplatePreview',
			array(
				'title' => __( 'Template preview', 'ffcertificate' ),
				'close' => __( 'Close', 'ffcertificate' ),
			)
		);
	}

	/**
	 * Enqueue the shared CodeMirror HTML editor on the template edit screen.
	 *
	 * Mirrors what the form editor does for its layout box, so the two HTML
	 * editors are visually identical. Shipped defaults get a read-only editor,
	 * matching the server-rendered `readonly` textarea (#865 decision #11).
	 *
	 * @return void
	 */
	public function enqueue_edit_assets(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen instanceof \WP_Screen || 'post' !== $screen->base || CertTemplateCpt::POST_TYPE !== $screen->post_type ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing: which post is being edited, to decide the read-only editor state. No state change; no nonce applies.
		$post_id    = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;
		$is_default = $post_id > 0 && CertTemplateReader::is_default( $post_id );

		AdminAssetsManager::enqueue_code_editor_for( 'ffc_template_html', $is_default );

		// Editable templates get the same Background Image + Insert Image tools as
		// the form-editor layout box (the buttons render only for non-defaults).
		if ( ! $is_default ) {
			AdminAssetsManager::enqueue_image_tools();
		}

		// Edit-screen behaviours: lock the title on defaults + autosave the
		// visibility toggle (so the removed Publish box isn't needed for it).
		$suffix = \FreeFormCertificate\Core\AssetHelper::asset_suffix();
		wp_enqueue_script(
			self::EDIT_HANDLE,
			FFC_PLUGIN_URL . "assets/js/ffc-cert-template-admin{$suffix}.js",
			array( 'jquery' ),
			FFC_VERSION,
			true
		);
		wp_localize_script(
			self::EDIT_HANDLE,
			'ffcCertTemplateAdmin',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'toggleAction' => self::AJAX_TOGGLE_ACTION,
				'nonce'        => wp_create_nonce( self::AJAX_TOGGLE_ACTION ),
				'postId'       => $post_id,
				'isDefault'    => $is_default,
				'savingText'   => __( 'Saving…', 'ffcertificate' ),
				'savedText'    => __( 'Saved', 'ffcertificate' ),
				'errorText'    => __( 'Save failed', 'ffcertificate' ),
			)
		);
	}

	/**
	 * Register the HTML-body + visibility metabox on the edit screen.
	 *
	 * @param \WP_Post $post Post being edited (passed by `add_meta_boxes_{cpt}`).
	 * @return void
	 */
	public function add_edit_metabox( \WP_Post $post ): void {
		// Shipped defaults are read-only except the visibility toggle, which
		// autosaves over AJAX — so drop the Publish/Update box entirely (there is
		// nothing else to save, and its presence implied an editable name/body).
		if ( CertTemplateReader::is_default( (int) $post->ID ) ) {
			remove_meta_box( 'submitdiv', CertTemplateCpt::POST_TYPE, 'side' );
			remove_meta_box( 'slugdiv', CertTemplateCpt::POST_TYPE, 'normal' );
		}

		add_meta_box(
			'ffc_cert_template_body',
			__( 'Template', 'ffcertificate' ),
			array( $this, 'render_edit_metabox' ),
			CertTemplateCpt::POST_TYPE,
			'normal',
			'high'
		);

		// Template options (currently the "Load list" visibility toggle) live in
		// a sidebar box, WP-idiomatically grouped beside the editor rather than
		// buried under the HTML textarea.
		add_meta_box(
			'ffc_cert_template_options',
			__( 'Template options', 'ffcertificate' ),
			array( $this, 'render_options_metabox' ),
			CertTemplateCpt::POST_TYPE,
			'side',
			'default'
		);
	}

	/**
	 * Render the HTML editor + visibility checkbox.
	 *
	 * @param \WP_Post $post Current template post.
	 * @return void
	 */
	public function render_edit_metabox( \WP_Post $post ): void {
		$html       = (string) get_post_meta( $post->ID, CertTemplateCpt::META_HTML, true );
		$bg_image   = (string) get_post_meta( $post->ID, CertTemplateCpt::META_BG_IMAGE, true );
		$is_default = CertTemplateReader::is_default( (int) $post->ID );

		wp_nonce_field( self::SAVE_NONCE, 'ffc_cert_template_nonce' );

		if ( $is_default ) {
			echo '<p class="description">' .
				esc_html__( 'This is a shipped default template — its HTML is read-only. Duplicate it to create an editable copy; you can still show/hide it in Template options.', 'ffcertificate' ) .
				'</p>';
		}

		// Image tools mirror the form editor's layout toolbar (same button ids →
		// same delegated handlers in ffc-admin-pdf.js). Rendered only for editable
		// templates: a shipped default's HTML and background can't be changed.
		if ( ! $is_default ) {
			?>
			<div class="ffc-action-group">
				<button type="button" class="button" id="ffc_btn_media_lib">
					<span class="dashicons dashicons-cover-image" aria-hidden="true"></span>
					<?php esc_html_e( 'Background Image', 'ffcertificate' ); ?>
				</button>
				<button type="button" class="button" id="ffc_btn_insert_image">
					<span class="dashicons dashicons-format-image" aria-hidden="true"></span>
					<?php esc_html_e( 'Insert Image', 'ffcertificate' ); ?>
				</button>
			</div>
			<?php
		}
		?>
		<label class="ffc-block-label" for="ffc_template_html"><strong><?php esc_html_e( 'Certificate HTML', 'ffcertificate' ); ?></strong></label>
		<div class="ffc-code-editor-wrapper">
			<textarea name="ffc_template_html" id="ffc_template_html" class="ffc-w100" rows="16" <?php wp_readonly( $is_default, true ); ?>><?php echo esc_textarea( $html ); ?></textarea>
		</div>
		<p class="description">
			<?php esc_html_e( 'Mandatory Tags:', 'ffcertificate' ); ?> <code>{{auth_code}}</code>, <code>{{name}}</code>, <code>{{cpf_rf}}</code>.
		</p>

		<div class="ffc-input-group ffc-mt-15">
			<label class="ffc-block-label" for="ffc_template_bg_image"><strong><?php esc_html_e( 'Background Image URL:', 'ffcertificate' ); ?></strong></label>
			<input type="text" name="ffc_template_bg_image" id="ffc_template_bg_image" value="<?php echo esc_url( $bg_image ); ?>" class="ffc-w100" <?php wp_readonly( $is_default, true ); ?>>
		</div>
		<?php
	}

	/**
	 * Render the sidebar "Template options" metabox — the "Load list" visibility
	 * toggle. Posts in the same edit form as the body metabox, so its value is
	 * read by {@see self::save_edit_metabox()} under the same nonce.
	 *
	 * @param \WP_Post $post Current template post.
	 * @return void
	 */
	public function render_options_metabox( \WP_Post $post ): void {
		// New templates ("Add New" → an auto-draft) are born visible (#865
		// decision #10); existing ones reflect their stored flag.
		$visible = 'auto-draft' === $post->post_status
			? true
			: '1' === (string) get_post_meta( $post->ID, CertTemplateCpt::META_VISIBLE, true );
		?>
		<label class="ffc-toggle">
			<input type="checkbox" id="ffc_template_visible" name="ffc_template_visible" value="1" <?php checked( $visible ); ?>>
			<span class="ffc-toggle-track" aria-hidden="true"></span>
			<span class="ffc-toggle-label"><?php esc_html_e( 'Show in the form editor’s “Load” list', 'ffcertificate' ); ?></span>
		</label>
		<span id="ffc_template_visible_status" class="ffc-autosave-status" role="status" aria-live="polite"></span>
		<p class="description">
			<?php esc_html_e( 'When on, this template appears in the certificate form editor’s “Load” dropdown.', 'ffcertificate' ); ?>
		</p>
		<?php
	}

	/**
	 * Persist the HTML body + visibility from the edit screen.
	 *
	 * @param int      $post_id Saved post id.
	 * @param \WP_Post $post    Saved post.
	 * @return void
	 */
	public function save_edit_metabox( int $post_id, \WP_Post $post ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( CertTemplateCpt::POST_TYPE !== $post->post_type ) {
			return;
		}

		$nonce = isset( $_POST['ffc_cert_template_nonce'] )
			? sanitize_key( wp_unslash( $_POST['ffc_cert_template_nonce'] ) )
			: '';
		if ( ! wp_verify_nonce( $nonce, self::SAVE_NONCE ) ) {
			return;
		}
		if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_forms' ) ) {
			return;
		}

		// Shipped defaults are read-only (#865 decision #11): never rewrite a
		// default's HTML from the edit screen, even if the request carries a
		// body (the textarea is rendered `readonly`, but enforce server-side).
		if ( ! CertTemplateReader::is_default( $post_id ) ) {
			// Certificate HTML legitimately carries rich markup (tables, inline
			// styles) — sanitize through the same allowlist the form-layout save
			// uses, never a plain sanitize_text_field.
			$raw  = isset( $_POST['ffc_template_html'] ) ? (string) wp_unslash( $_POST['ffc_template_html'] ) : '';
			$html = wp_kses( $raw, \FreeFormCertificate\Core\HtmlPolicy::get_allowed_html_tags() );
			CertTemplateWriter::update_html( $post_id, $html );

			// Background image URL: carried into the form's Background Image field
			// when this template is loaded in the editor (#865).
			$bg_image = isset( $_POST['ffc_template_bg_image'] )
				? esc_url_raw( wp_unslash( $_POST['ffc_template_bg_image'] ) )
				: '';
			CertTemplateWriter::set_bg_image( $post_id, $bg_image );
		}

		// Visibility is togglable for every template, defaults included
		// (decision #10/#11: a default can be hidden, just not edited/deleted).
		CertTemplateWriter::set_visibility( $post_id, isset( $_POST['ffc_template_visible'] ) );
	}

	/**
	 * Deny deletion of shipped default templates at the capability layer
	 * (#865 decision #11 — server-side, not just hiding the row action).
	 *
	 * Runs after {@see CptCapPolicy::gate_cpt_writes} (priority 20 > 10) so it
	 * overrides the manage-cap mapping with `do_not_allow` for defaults.
	 *
	 * @param array<int, string> $caps    Mapped primitive caps.
	 * @param string             $cap     Meta cap being checked.
	 * @param int                $user_id User id (unused).
	 * @param array<int, mixed>  $args    `[0]` is the post id for delete_post.
	 * @return array<int, string>
	 */
	public function protect_default_caps( array $caps, string $cap, int $user_id, array $args ): array {
		if ( 'delete_post' !== $cap ) {
			return $caps;
		}
		$post_id = isset( $args[0] ) ? (int) $args[0] : 0;
		if ( $post_id <= 0 ) {
			return $caps;
		}
		$post = get_post( $post_id );
		if ( $post instanceof \WP_Post
			&& CertTemplateCpt::POST_TYPE === $post->post_type
			&& CertTemplateReader::is_default( $post_id )
		) {
			return array( 'do_not_allow' );
		}
		return $caps;
	}

	/**
	 * Prevent renaming a shipped default (#865 decision #11): restore the
	 * stored title on any save of a default template.
	 *
	 * @param array<string, mixed> $data    Sanitized post data to be written.
	 * @param array<string, mixed> $postarr Raw post array (carries `ID`).
	 * @return array<string, mixed>
	 */
	public function preserve_default_title( array $data, array $postarr ): array {
		if ( CertTemplateCpt::POST_TYPE !== ( $data['post_type'] ?? '' ) ) {
			return $data;
		}
		$post_id = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;
		if ( $post_id > 0 && CertTemplateReader::is_default( $post_id ) ) {
			$existing = get_post_field( 'post_title', $post_id );
			if ( is_string( $existing ) && '' !== $existing ) {
				$data['post_title'] = wp_slash( $existing );
			}
		}
		return $data;
	}
}
