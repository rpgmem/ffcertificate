<?php
/**
 * CertTemplateAdminScreen
 *
 * Management UI for the document-template pool (issue #865): the columns,
 * row actions and visibility toggle layered onto the native `ffc_cert_template`
 * list table (the CPT is registered with `show_ui => true` as the "Document
 * Templates" hub submenu under the FFC Settings menu — #951, see
 * {@see CertTemplateCpt}).
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

use FreeFormCertificate\Core\RequestInput;

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
	public const TOGGLE_ACTION = 'ffc_toggle_cert_template_visibility';

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
	public const DUPLICATE_ACTION = 'ffc_duplicate_cert_template';

	/**
	 * The `admin_action_{$action}` slug for exporting a template as `.html`.
	 */
	public const EXPORT_ACTION = 'ffc_export_cert_template';

	/**
	 * The `admin_action_{$action}` slug for the "Restore defaults" toolbar button.
	 */
	public const RESTORE_ACTION = 'ffc_restore_cert_templates';

	/**
	 * The `admin_action_{$action}` slug for the sample-data preview page.
	 */
	public const PREVIEW_ACTION = 'ffc_preview_cert_template';

	/**
	 * The `wp_ajax_{$action}` slug for the visual editor's sample-data
	 * preview (#2586): the unsaved layout through the PDF renderer.
	 */
	public const PREVIEW_LAYOUT_ACTION = 'ffc_preview_visual_layout';

	/**
	 * The `admin_action_{$action}` slug that turns a visual template into an
	 * HTML template (#2586), one way.
	 */
	public const CONVERT_ACTION = 'ffc_convert_cert_template_to_html';

	/**
	 * The `admin_action_{$action}` slug that creates a visual copy of an HTML
	 * certificate template (#2587).
	 */
	public const VISUAL_COPY_ACTION = 'ffc_visual_copy_cert_template';

	/**
	 * The `wp_ajax_{$action}` slugs of the measured visual copy (#2606): the
	 * template's HTML for the browser to render, and the layout it read.
	 * Both check the visual-copy link's own nonce.
	 */
	public const VISUAL_SOURCE_AJAX = 'ffc_visual_copy_source';

	public const VISUAL_SAVE_AJAX = 'ffc_visual_copy_save';

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
		// Category (kind) list filter (#945): a dropdown in the list toolbar +
		// the matching main-query meta_query.
		add_action( 'restrict_manage_posts', array( $this, 'render_kind_filter' ) );
		add_action( 'pre_get_posts', array( $this, 'filter_by_kind' ) );
		// Sample-data preview (#865 decision #13): a read-only preview page a
		// "Preview" row action opens in an overlay iframe (graceful no-JS: the
		// link navigates to the page directly).
		add_action( 'admin_action_' . self::PREVIEW_ACTION, array( $this, 'handle_preview' ) );
		// The visual editor's preview and its one-way conversion (#2586).
		add_action( 'wp_ajax_' . self::PREVIEW_LAYOUT_ACTION, array( $this, 'ajax_preview_visual_layout' ) );
		add_action( 'admin_action_' . self::CONVERT_ACTION, array( $this, 'handle_convert_to_html' ) );
		add_action( 'admin_action_' . self::VISUAL_COPY_ACTION, array( $this, 'handle_visual_copy' ) );
		add_action( 'wp_ajax_' . self::VISUAL_SOURCE_AJAX, array( $this, 'ajax_visual_copy_source' ) );
		add_action( 'wp_ajax_' . self::VISUAL_SAVE_AJAX, array( $this, 'ajax_visual_copy_save' ) );
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
				$out['ffc_category'] = __( 'Category', 'ffcertificate' );
				$out['ffc_type']     = __( 'Type', 'ffcertificate' );
				$out['ffc_visible']  = __( 'Visible', 'ffcertificate' );
			}
		}
		// Fallback if the title column is absent for any reason.
		if ( ! isset( $out['ffc_type'] ) ) {
			$out['ffc_category'] = __( 'Category', 'ffcertificate' );
			$out['ffc_type']     = __( 'Type', 'ffcertificate' );
			$out['ffc_visible']  = __( 'Visible', 'ffcertificate' );
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
		if ( 'ffc_category' === $column ) {
			echo esc_html( self::kind_label( CertTemplateReader::get_kind( $post_id ) ) );
			return;
		}

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

		// A visual copy of an HTML certificate (#2587); the original stays as it is.
		if ( self::can_have_visual_copy( (int) $post->ID ) ) {
			$actions['ffc_visual_copy'] = '<a href="' . esc_url( self::visual_copy_url( (int) $post->ID ) ) . '" data-ffc-visual-measure="' . (int) $post->ID . '">' . esc_html__( 'Create visual version', 'ffcertificate' ) . '</a>';
		}

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

		$post_id = RequestInput::get_get_int( 'post' );

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

		$post_id = RequestInput::get_post_int( 'post_id' );
		$post    = $post_id > 0 ? get_post( $post_id ) : null;
		if ( ! $post instanceof \WP_Post || CertTemplateCpt::POST_TYPE !== $post->post_type ) {
			wp_send_json_error( array( 'message' => __( 'Template not found.', 'ffcertificate' ) ), 404 );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Strict comparison against '1' — the raw value never survives the expression.
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

		$post_id = RequestInput::get_get_int( 'post' );
		check_admin_referer( self::DUPLICATE_ACTION . '_' . $post_id );

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || CertTemplateCpt::POST_TYPE !== $post->post_type ) {
			wp_die( esc_html__( 'Template not found.', 'ffcertificate' ) );
		}

		$html  = (string) get_post_meta( $post_id, CertTemplateCpt::META_HTML, true );
		$bg    = (string) get_post_meta( $post_id, CertTemplateCpt::META_BG_IMAGE, true );
		$kind  = CertTemplateReader::get_kind( $post_id );
		$color = (string) get_post_meta( $post_id, CertTemplateCpt::META_BG_COLOR, true );
		/* translators: %s: source template title */
		$title = sprintf( __( '%s (copy)', 'ffcertificate' ), (string) $post->post_title );
		// Preserve the source's kind + background so a duplicated receipt stays a
		// receipt (#945) and a duplicated certificate keeps its background.
		$new_id = CertTemplateWriter::create( $title, $html, true, $bg, $kind, $color );
		// A visual template's copy stays visual, with its layout (#2584).
		if ( $new_id > 0 && \FreeFormCertificate\Core\VisualLayout::is_visual( $post_id ) ) {
			update_post_meta( $new_id, \FreeFormCertificate\Core\VisualLayout::META_EDITOR, \FreeFormCertificate\Core\VisualLayout::EDITOR_VISUAL );
			\FreeFormCertificate\Core\VisualLayout::store_json( $new_id, (string) get_post_meta( $post_id, \FreeFormCertificate\Core\VisualLayout::META_LAYOUT, true ) );
		}

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

		$post_id = RequestInput::get_get_int( 'post' );
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
		\FreeFormCertificate\Admin\AdminUI::render_button(
			array(
				'label'    => __( 'Restore defaults', 'ffcertificate' ),
				'category' => 'caution',
				'icon'     => 'restore',
				'href'     => $url,
			)
		);
	}

	/**
	 * Human label for a template kind (#945).
	 *
	 * @param string $kind One of the `CertTemplateCpt::KIND_*` values.
	 * @return string
	 */
	private static function kind_label( string $kind ): string {
		if ( CertTemplateCpt::KIND_APPOINTMENT_RECEIPT === $kind ) {
			return __( 'Appointment receipt', 'ffcertificate' );
		}
		if ( CertTemplateCpt::KIND_RECORD === $kind ) {
			return __( 'Record', 'ffcertificate' );
		}
		return __( 'Certificate', 'ffcertificate' );
	}

	/**
	 * Render the Category (kind) filter dropdown in the list-table toolbar (#945).
	 *
	 * @param string $post_type Current list-table post type.
	 * @return void
	 */
	public function render_kind_filter( string $post_type ): void {
		if ( CertTemplateCpt::POST_TYPE !== $post_type ) {
			return;
		}

		$current = RequestInput::get_get_key( 'ffc_kind' );
		$options = array(
			''                                        => __( 'All categories', 'ffcertificate' ),
			CertTemplateCpt::KIND_CERTIFICATE         => self::kind_label( CertTemplateCpt::KIND_CERTIFICATE ),
			CertTemplateCpt::KIND_APPOINTMENT_RECEIPT => self::kind_label( CertTemplateCpt::KIND_APPOINTMENT_RECEIPT ),
			CertTemplateCpt::KIND_RECORD              => self::kind_label( CertTemplateCpt::KIND_RECORD ),
		);

		echo '<select name="ffc_kind">';
		foreach ( $options as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $current, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}

	/**
	 * Constrain the pool list to the selected Category (kind) (#945).
	 *
	 * @param \WP_Query $query The query being prepared.
	 * @return void
	 */
	public function filter_by_kind( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( CertTemplateCpt::POST_TYPE !== $query->get( 'post_type' ) ) {
			return;
		}

		$kind = RequestInput::get_get_key( 'ffc_kind' );
		if ( '' === $kind ) {
			return;
		}

		if ( CertTemplateCpt::KIND_CERTIFICATE === $kind ) {
			// Absent meta counts as certificate (retrocompat).
			$query->set(
				'meta_query',
				array(
					'relation' => 'OR',
					array(
						'key'   => CertTemplateCpt::META_KIND,
						'value' => CertTemplateCpt::KIND_CERTIFICATE,
					),
					array(
						'key'     => CertTemplateCpt::META_KIND,
						'compare' => 'NOT EXISTS',
					),
				)
			);
		} else {
			$query->set(
				'meta_query',
				array(
					array(
						'key'   => CertTemplateCpt::META_KIND,
						'value' => $kind,
					),
				)
			);
		}
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

		$post_id = RequestInput::get_get_int( 'post' );
		check_admin_referer( self::PREVIEW_ACTION . '_' . $post_id );

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || CertTemplateCpt::POST_TYPE !== $post->post_type ) {
			wp_die( esc_html__( 'Template not found.', 'ffcertificate' ) );
		}

		// Declared fields print their defaults (#2581).
		$html     = \FreeFormCertificate\Core\CertificateTemplateLink::layout( $post_id, array() );
		$bg       = (string) get_post_meta( $post_id, CertTemplateCpt::META_BG_IMAGE, true );
		$rendered = $this->render_sample( $html, (string) $post->post_title );

		// The background sits behind the body as the PDF draws it; a template
		// carries no colour of its own, so none is painted. Without one the
		// page keeps its own size, inside the PDF's own layers so it takes
		// their text styles (#2598).
		$page = '' === $bg
			? '<div class="ffc-pdf-wrapper"><div class="ffc-pdf-content">' . $rendered . '</div></div>'
			: \FreeFormCertificate\Generators\FormLayoutPreview::wrap( $rendered, $bg, '' );

		$this->emit_preview_page( (string) $post->post_title, $page );
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
			// The fonts the site installed, so a template that names one
			// previews in it (#2569); built from validated names and URLs.
			. \FreeFormCertificate\Core\CertificateFonts::css()
			// The PDF's own text styles, which the generator draws with (#2598).
			. self::pdf_core_css()
			. '</style></head><body><div class="ffc-preview-canvas">' . $body . '</div></body></html>';

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Self-contained preview page: the title is esc_html'd and the body is the HtmlPolicy-sanitized template rendered through PdfHtmlRenderer (which wp_kses-filters every injected value); the frame is sandboxed script-less.
		echo $doc;
		exit;
	}

	/**
	 * The PDF stylesheet's rules, for the self-contained preview page, which
	 * prints its own document and enqueues nothing.
	 *
	 * @return string CSS, or '' when the file cannot be read.
	 */
	private static function pdf_core_css(): string {
		$path = FFC_PLUGIN_DIR . 'assets/css/ffc-pdf-core' . \FreeFormCertificate\Core\AssetHelper::asset_suffix() . '.css';
		$css  = is_readable( $path ) ? (string) file_get_contents( $path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A stylesheet shipped in the plugin directory, read from disk.

		// A `</style>` in the file would end the element early.
		return str_ireplace( '</style', '', $css );
	}

	/**
	 * The measured visual copy (#2606): its script, and what the page needs to
	 * render a template as it prints — the PDF stylesheet and the fonts.
	 *
	 * @return void
	 */
	private static function enqueue_visual_measure(): void {
		\FreeFormCertificate\Core\AssetRegistry::enqueue_styles( 'ffc-pdf-core' );
		\FreeFormCertificate\Core\CertificateFonts::enqueue();
		$suffix = \FreeFormCertificate\Core\AssetHelper::asset_suffix();
		wp_enqueue_script(
			'ffc-visual-measure',
			FFC_PLUGIN_URL . "assets/js/ffc-visual-measure{$suffix}.js",
			array( 'ffc-core' ),
			FFC_VERSION,
			true
		);
		wp_localize_script(
			'ffc-visual-measure',
			'ffcVisualMeasure',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				// An image shipped with the plugin is recognised by this (#2606).
				'pluginUrl' => FFC_PLUGIN_URL,
				'strings'   => array(
					'busy'        => __( 'Reading the template…', 'ffcertificate' ),
					'frame'       => __( 'Frame', 'ffcertificate' ),
					'line'        => __( 'Line', 'ffcertificate' ),
					'text'        => __( 'Text', 'ffcertificate' ),
					'field'       => __( 'Data field', 'ffcertificate' ),
					'qr'          => __( 'QR code', 'ffcertificate' ),
					'image'       => __( 'Image', 'ffcertificate' ),
					'signature'   => __( 'Signature', 'ffcertificate' ),
					'html'        => __( 'HTML block', 'ffcertificate' ),
					'mixed'       => __( 'mixed text styles', 'ffcertificate' ),
					'rotated'     => __( 'rotated', 'ffcertificate' ),
					'background'  => __( 'background image', 'ffcertificate' ),
					'offPage'     => __( 'Off the page', 'ffcertificate' ),
					'pageColour'  => __( 'Page colour', 'ffcertificate' ),
					'decoration'  => __( 'underline or shadow', 'ffcertificate' ),
					'wordSpacing' => __( 'Word spacing', 'ffcertificate' ),
					'merged'      => __( 'Styles inside one line merged', 'ffcertificate' ),
				),
			)
		);
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

		self::enqueue_theme();
		// The row action's visual copy is measured on this page (#2606).
		self::enqueue_visual_measure();

		$suffix = \FreeFormCertificate\Core\AssetHelper::asset_suffix();
		wp_enqueue_script(
			self::PREVIEW_HANDLE,
			FFC_PLUGIN_URL . "assets/js/ffc-cert-template-preview{$suffix}.js",
			array( 'jquery', 'ffc-core' ),
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
	 * A shipped visual model, drawn read-only (#2628): the sample-data
	 * preview in a frame of the page's proportions, since a default has no
	 * canvas and its JSON alone shows nothing.
	 *
	 * @param int $post_id Template post id.
	 * @return void
	 */
	private static function render_default_page( int $post_id ): void {
		$layout   = \FreeFormCertificate\Core\VisualLayout::stored( $post_id );
		$portrait = null !== $layout && 'portrait' === $layout['page']['orientation'];
		$preview  = wp_nonce_url(
			admin_url( 'admin.php?action=' . self::PREVIEW_ACTION . '&post=' . $post_id ),
			self::PREVIEW_ACTION . '_' . $post_id
		);
		printf(
			'<iframe class="%1$s" src="%2$s" title="%3$s" loading="lazy"></iframe>',
			$portrait ? 'ffc-ve-default-page ffc-ve-default-page-portrait' : 'ffc-ve-default-page',
			esc_url( $preview ),
			esc_attr__( 'The model with sample data', 'ffcertificate' )
		);
	}

	/**
	 * The page canvas of a visual template (#2585), with its labels.
	 *
	 * @param int $post_id Template post id, for the preview (#2586).
	 * @return void
	 */
	private static function enqueue_visual_editor( int $post_id ): void {
		$suffix = \FreeFormCertificate\Core\AssetHelper::asset_suffix();
		self::enqueue_visual_editor_style();
		wp_enqueue_script(
			'ffc-visual-editor',
			FFC_PLUGIN_URL . "assets/js/ffc-visual-editor{$suffix}.js",
			array( 'ffc-core', 'ffc-layout-preview' ),
			FFC_VERSION,
			true
		);
		wp_localize_script(
			'ffc-visual-editor',
			'ffcVisualEditor',
			array(
				// Images shipped with the plugin are drawn from here (#2606).
				'pluginUrl'      => FFC_PLUGIN_URL,
				'newText'        => __( 'Text', 'ffcertificate' ),
				'image'          => __( 'Image', 'ffcertificate' ),
				'signature'      => __( 'Signature', 'ffcertificate' ),
				'qr'             => __( 'QR code', 'ffcertificate' ),
				'html'           => __( 'HTML block', 'ffcertificate' ),
				'background'     => __( 'Background (page colour and image)', 'ffcertificate' ),
				'moveUp'         => __( 'Bring forward', 'ffcertificate' ),
				'moveDown'       => __( 'Send backward', 'ffcertificate' ),
				'lock'           => __( 'Lock', 'ffcertificate' ),
				'unlock'         => __( 'Unlock', 'ffcertificate' ),
				'discard'        => __( 'Discard changes', 'ffcertificate' ),
				'discardTitle'   => __( 'Discard changes?', 'ffcertificate' ),
				'discardMessage' => __( 'The page goes back to the last save; Undo brings back what changed since.', 'ffcertificate' ),
				'undone'         => __( 'Undone', 'ffcertificate' ),
				'redone'         => __( 'Redone', 'ffcertificate' ),
				/* translators: %d: number of elements */
				'copied'         => __( '%d copied', 'ffcertificate' ),
				/* translators: %d: number of elements */
				'pasted'         => __( '%d added', 'ffcertificate' ),
				/* translators: %s: an element's name, such as "Text: Certificate" */
				'selectedOne'    => __( '%s selected', 'ffcertificate' ),
				/* translators: %d: number of elements */
				'selectedMany'   => __( '%d elements selected', 'ffcertificate' ),
				'selectedNone'   => __( 'Nothing selected', 'ffcertificate' ),
				'oneAtATime'     => __( 'Select one to change its properties.', 'ffcertificate' ),
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'previewAction'  => self::PREVIEW_LAYOUT_ACTION,
				'previewNonce'   => wp_create_nonce( self::PREVIEW_LAYOUT_ACTION ),
				'postId'         => $post_id,
				// The canvas draws the page colour under the background (#2612).
				'bgColor'        => $post_id > 0 ? (string) sanitize_hex_color( CertTemplateReader::get_bg_color( $post_id ) ) : '',
				'previewLoading' => __( 'Drawing the page…', 'ffcertificate' ),
				'previewError'   => __( 'The preview could not be drawn.', 'ffcertificate' ),
				/* translators: %s: comma-separated tags, such as {{auth_code}} */
				'missingTags'    => __( 'Settings → Certificates requires %s, and the layout does not carry it yet.', 'ffcertificate' ),
				/* translators: %s: comma-separated font names */
				'missingFonts'   => __( 'Not installed on this site: %s. The preview and the PDF draw the next font of each list.', 'ffcertificate' ),
				/* translators: %s: the font that prints instead */
				'fontFallback'   => __( 'Not installed on this site: the text prints in %s until it is.', 'ffcertificate' ),
				'fontDefault'    => __( 'the device’s default font', 'ffcertificate' ),
				'knownFonts'     => \FreeFormCertificate\Core\CertificateFonts::available_names(),
				'chooseImage'    => __( 'Choose image', 'ffcertificate' ),
				'useImage'       => __( 'Use this image', 'ffcertificate' ),
				/* translators: %d: Media Library attachment id */
				'imageChosen'    => __( 'Image #%d', 'ffcertificate' ),
				'noImage'        => __( 'No image chosen yet.', 'ffcertificate' ),
				'types'          => array(
					'text'  => __( 'Text', 'ffcertificate' ),
					'field' => __( 'Data field', 'ffcertificate' ),
					'image' => __( 'Image', 'ffcertificate' ),
					'qr'    => __( 'QR code', 'ffcertificate' ),
					'frame' => __( 'Frame', 'ffcertificate' ),
					'html'  => __( 'HTML block', 'ffcertificate' ),
				),
			)
		);
	}

	/**
	 * The palette and the dark-mode toggle, on the template list and editor.
	 *
	 * Both are screens WordPress draws for a plugin post type, so
	 * `AdminAssetsManager` (which only knows `?page=ffc-*` and the form post
	 * type) never reached them, and they stayed light in dark mode.
	 *
	 * @return void
	 */
	private static function enqueue_theme(): void {
		\FreeFormCertificate\Core\AssetHelper::enqueue_common_style();
		\FreeFormCertificate\Core\AssetHelper::enqueue_dark_mode();
	}

	/**
	 * The visual editor's stylesheet, which also styles a default's read-only
	 * page (#2628).
	 *
	 * @return void
	 */
	private static function enqueue_visual_editor_style(): void {
		$suffix = \FreeFormCertificate\Core\AssetHelper::asset_suffix();
		wp_enqueue_style(
			'ffc-visual-editor',
			FFC_PLUGIN_URL . "assets/css/ffc-visual-editor{$suffix}.css",
			array( 'ffc-common' ),
			FFC_VERSION
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

		self::enqueue_theme();

		$post_id    = RequestInput::get_get_int( 'post' );
		$is_default = $post_id > 0 && CertTemplateReader::is_default( $post_id );

		// A visual template edits its layout on the page canvas (#2585), not HTML (#2584).
		if ( \FreeFormCertificate\Core\VisualLayout::is_visual( $post_id ) ) {
			if ( $is_default ) {
				// The read-only page frame (#2628).
				self::enqueue_visual_editor_style();
			} else {
				// The preview is captured the way the form editor's is (#2586).
				\FreeFormCertificate\Core\AssetRegistry::enqueue_scripts( 'ffc-layout-preview' );
				self::enqueue_visual_editor( $post_id );
			}
		} else {
			AdminAssetsManager::enqueue_code_editor_for( 'ffc_template_html', $is_default );
			// Its visual copy is measured on this page (#2606).
			if ( $post_id > 0 && self::can_have_visual_copy( $post_id ) ) {
				self::enqueue_visual_measure();
			}
		}

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
			array( 'jquery', 'ffc-core' ),
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

		// The visual editor's properties (#2586), beside the page, under Save.
		if ( ! CertTemplateReader::is_default( (int) $post->ID ) && \FreeFormCertificate\Core\VisualLayout::is_visual( (int) $post->ID ) ) {
			add_meta_box(
				'ffc_cert_template_properties',
				__( 'Element properties', 'ffcertificate' ),
				array( $this, 'render_properties_metabox' ),
				CertTemplateCpt::POST_TYPE,
				'side',
				'high'
			);
		}

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
		$kind       = CertTemplateReader::get_kind( (int) $post->ID );
		$is_receipt = CertTemplateCpt::KIND_APPOINTMENT_RECEIPT === $kind;
		$is_record  = CertTemplateCpt::KIND_RECORD === $kind;

		wp_nonce_field( self::SAVE_NONCE, 'ffc_cert_template_nonce' );

		// "Add New" kind preset (#951): a feature's "+ New template" link opens
		// this screen with `?ffc_kind=<kind>`; carry it in a hidden field on the
		// new auto-draft so save_edit_metabox() can stamp META_KIND (a plain
		// "Add New" otherwise defaults to certificate). Only for new posts, so an
		// ordinary edit never resubmits — and re-types — an existing kind.
		$requested_kind = RequestInput::get_get_key( 'ffc_kind' );
		if ( 'auto-draft' === $post->post_status && CertTemplateCpt::is_valid_kind( $requested_kind ) ) {
			echo '<input type="hidden" name="ffc_template_kind" value="' . esc_attr( $requested_kind ) . '">';
		}

		if ( $is_default ) {
			echo '<p class="description">' .
				esc_html__( 'This is a shipped default template — its HTML is read-only. Duplicate it to create an editable copy; you can still show/hide it in Template options.', 'ffcertificate' ) .
				'</p>';
		}

		// Visual templates (#2584): certificates only. A new certificate
		// template chooses its editor; an existing one keeps the one it has.
		$is_certificate = ! $is_receipt && ! $is_record;
		if ( $is_certificate && ! $is_default && 'auto-draft' === $post->post_status && '' === $requested_kind ) {
			AdminUI::render_segmented(
				array(
					'name'         => 'ffc_template_editor',
					'legend'       => __( 'Editor', 'ffcertificate' ),
					'options'      => array(
						\FreeFormCertificate\Core\VisualLayout::EDITOR_HTML   => __( 'HTML', 'ffcertificate' ),
						\FreeFormCertificate\Core\VisualLayout::EDITOR_VISUAL => __( 'Visual', 'ffcertificate' ),
					),
					'value'        => \FreeFormCertificate\Core\VisualLayout::EDITOR_HTML,
					'descriptions' => array(
						\FreeFormCertificate\Core\VisualLayout::EDITOR_HTML   => __( 'Write the certificate as HTML.', 'ffcertificate' ),
						\FreeFormCertificate\Core\VisualLayout::EDITOR_VISUAL => __( 'Place elements on the page; the HTML is generated from the layout. Save to start editing it.', 'ffcertificate' ),
					),
				)
			);
		}
		if ( $is_certificate && \FreeFormCertificate\Core\VisualLayout::is_visual( (int) $post->ID ) ) {
			$this->render_visual_layout_field( $post, $is_default, $bg_image );
			return;
		}

		// Image tools mirror the form editor's layout toolbar (same button ids →
		// same delegated handlers in ffc-admin-pdf.js). Rendered only for editable
		// templates: a shipped default's HTML and background can't be changed.
		if ( ! $is_default ) {
			?>
			<div class="ffc-action-group">
				<?php
				\FreeFormCertificate\Admin\AdminUI::render_button(
					array(
						'label' => __( 'Background Image', 'ffcertificate' ),
						'icon'  => 'image',
						'id'    => 'ffc_btn_media_lib',
					)
				);
				\FreeFormCertificate\Admin\AdminUI::render_button(
					array(
						'label'    => __( 'Insert Image', 'ffcertificate' ),
						'category' => 'create',
						'icon'     => 'image-plus',
						'id'       => 'ffc_btn_insert_image',
					)
				);
				?>
			</div>
			<?php
		}

		// A visual copy of this HTML certificate (#2587), defaults included:
		// the original is not touched.
		if ( 'auto-draft' !== $post->post_status && self::can_have_visual_copy( (int) $post->ID ) ) {
			echo '<p class="ffc-visual-copy">';
			AdminUI::render_button(
				array(
					'label'    => __( 'Create visual version', 'ffcertificate' ),
					'icon'     => 'layout',
					'category' => 'create',
					'href'     => self::visual_copy_url( (int) $post->ID ),
					// Measured in the browser when it can be (#2606); the
					// address is the server importer, for no JavaScript.
					'attrs'    => array( 'data-ffc-visual-measure' => (string) (int) $post->ID ),
				)
			);
			echo ' <span class="description">' . esc_html__( 'A new visual template read from this one; this template stays as it is.', 'ffcertificate' ) . '</span></p>';
		}

		// Just converted from a visual template (#2586).
		if ( '1' === RequestInput::get_get_key( 'ffc_converted' ) ) {
			\FreeFormCertificate\Core\AdminNotice::callout(
				'success',
				esc_html__( 'The template is now an HTML template. Its HTML is what the visual layout generated, so the forms that use it print the same document.', 'ffcertificate' ),
				array( 'classes' => array( 'inline' ) )
			);
		}

		// The fonts this template names that the site lacks (#2573): saved
		// HTML, so a font added in the editor is reported after the save.
		\FreeFormCertificate\Core\CertificateFonts::render_missing_notice( $html );
		?>
		<label class="ffc-block-label" for="ffc_template_html"><strong>
			<?php
			if ( $is_receipt ) {
				esc_html_e( 'Receipt HTML', 'ffcertificate' );
			} elseif ( $is_record ) {
				esc_html_e( 'Record HTML', 'ffcertificate' );
			} else {
				esc_html_e( 'Certificate HTML', 'ffcertificate' );
			}
			?>
		</strong></label>
		<div class="ffc-code-editor-wrapper">
			<textarea name="ffc_template_html" id="ffc_template_html" class="ffc-w100" rows="16" <?php wp_readonly( $is_default, true ); ?>><?php echo esc_textarea( $html ); ?></textarea>
		</div>
		<p class="description">
			<?php if ( $is_receipt ) : ?>
				<?php esc_html_e( 'Common tags:', 'ffcertificate' ); ?> <code>{{name}}</code>, <code>{{cpf_rf}}</code>, <code>{{calendar_title}}</code>, <code>{{session_name}}</code>, <code>{{session_label}}</code>, <code>{{appointment_date}}</code>, <code>{{appointment_time}}</code>, <code>{{appointment_time_range}}</code>, <code>{{validation_code}}</code>, <code>{{qr_code:size=140}}</code>, <code>{{validation_url}}</code>.
			<?php elseif ( $is_record ) : ?>
				<?php esc_html_e( 'Common tags:', 'ffcertificate' ); ?> <code>{{name}}</code>, <code>{{cpf_rf}}</code>, <code>{{custom_fields_section}}</code>, <code>{{termo_ciencia}}</code>, <code>{{submitted_at}}</code>.
			<?php else : ?>
				<?php echo \FreeFormCertificate\Admin\AdminUI::get_required_tags_line(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped where it is built. ?>
			<?php endif; ?>
		</p>

		<div class="ffc-input-group ffc-mt-xl">
			<label class="ffc-block-label" for="ffc_template_bg_image"><strong><?php esc_html_e( 'Background Image URL:', 'ffcertificate' ); ?></strong></label>
			<input type="text" name="ffc_template_bg_image" id="ffc_template_bg_image" value="<?php echo esc_url( $bg_image ); ?>" class="ffc-w100" <?php wp_readonly( $is_default, true ); ?>>
			<?php // Filled in by `ffc-admin-pdf.js` when the "Background Image" button picks an image; empty until then. ?>
			<div id="ffc_bg_image_preview"></div>
		</div>
		<?php
	}

	/**
	 * System font stacks the properties panel offers beside the installed
	 * families (#2586): each opens with a family every device has.
	 */
	private const SYSTEM_STACKS = array(
		'Arial, Helvetica, sans-serif',
		'Georgia, serif',
		'"Times New Roman", Times, serif',
		'Verdana, sans-serif',
		'"Trebuchet MS", sans-serif',
		'"Courier New", Courier, monospace',
	);

	/**
	 * The tags the properties panel offers for a data field or a text: the
	 * required ones first, then the common certificate tags.
	 *
	 * @return list<string>
	 */
	public static function layout_tokens(): array {
		$common = array(
			'{{name}}',
			'{{cpf_rf}}',
			'{{email}}',
			'{{auth_code}}',
			'{{form_title}}',
			'{{submission_date}}',
			'{{schedule}}',
			'{{schedule:date}}',
			'{{schedule:dates}}',
			'{{validation_url}}',
			'{{signer:name}}',
			'{{signer:role}}',
			'{{signer:rf}}',
			'{{signer2:name}}',
			'{{signer2:role}}',
			'{{signer2:rf}}',
			'{{signer3:name}}',
			'{{signer3:role}}',
			'{{signer3:rf}}',
		);
		return array_values( array_unique( array_merge( \FreeFormCertificate\Settings\SettingsReader::required_certificate_tags(), $common ) ) );
	}

	/**
	 * The font choices: the page default, the installed Font Library
	 * families, then the system stacks; value => label.
	 *
	 * @return array<string, string>
	 */
	public static function font_choices(): array {
		$choices = array( '' => __( 'Page default', 'ffcertificate' ) );
		foreach ( \FreeFormCertificate\Core\CertificateFonts::installed() as $family ) {
			$choices[ $family['css'] ] = $family['name'];
		}
		foreach ( self::SYSTEM_STACKS as $stack ) {
			if ( ! isset( $choices[ $stack ] ) ) {
				$choices[ $stack ] = trim( explode( ',', $stack )[0], '" ' );
			}
		}
		return $choices;
	}

	/**
	 * The visual editor's properties panel (#2586). One set of controls per
	 * element type; `ffc-visual-editor.js` shows the set of the selected
	 * element and writes every change into the layout. The controls carry no
	 * name, so the form posts only the layout JSON.
	 *
	 * @return void
	 */
	public function render_properties_metabox(): void {
		$selects = array(
			'font_weight'    => array(
				'100' => __( 'Thin (100)', 'ffcertificate' ),
				'300' => __( 'Light (300)', 'ffcertificate' ),
				'400' => __( 'Regular (400)', 'ffcertificate' ),
				'500' => __( 'Medium (500)', 'ffcertificate' ),
				'600' => __( 'Semibold (600)', 'ffcertificate' ),
				'700' => __( 'Bold (700)', 'ffcertificate' ),
				'900' => __( 'Black (900)', 'ffcertificate' ),
			),
			'font_style'     => array(
				'normal' => __( 'Normal', 'ffcertificate' ),
				'italic' => __( 'Italic', 'ffcertificate' ),
			),
			'align'          => array(
				'left'    => __( 'Left', 'ffcertificate' ),
				'center'  => __( 'Centre', 'ffcertificate' ),
				'right'   => __( 'Right', 'ffcertificate' ),
				'justify' => __( 'Justify', 'ffcertificate' ),
			),
			'text_transform' => array(
				'none'       => __( 'As typed', 'ffcertificate' ),
				'uppercase'  => __( 'UPPERCASE', 'ffcertificate' ),
				'lowercase'  => __( 'lowercase', 'ffcertificate' ),
				'capitalize' => __( 'Capitalised', 'ffcertificate' ),
			),
			'fit'            => array(
				'none' => __( 'Off: the text keeps its size', 'ffcertificate' ),
				'line' => __( 'Shrink to one line', 'ffcertificate' ),
				'box'  => __( 'Shrink to fit the box', 'ffcertificate' ),
			),
			'image_fit'      => array(
				'contain' => __( 'Contain: the whole image shows', 'ffcertificate' ),
				'cover'   => __( 'Cover: the image fills the box', 'ffcertificate' ),
			),
			'border_style'   => array(
				'solid'  => __( 'Solid', 'ffcertificate' ),
				'dashed' => __( 'Dashed', 'ffcertificate' ),
				'dotted' => __( 'Dotted', 'ffcertificate' ),
				'double' => __( 'Double', 'ffcertificate' ),
			),
		);
		?>
		<div class="ffc-ve-props" data-ffc-ve-properties>
			<p class="description" data-ffc-ve-props-empty><?php esc_html_e( 'Select an element on the page, or in the Layers list, to change it here.', 'ffcertificate' ); ?></p>
			<div data-ffc-ve-props-form hidden>
				<p class="ffc-ve-props-title" data-ffc-ve-props-title></p>

				<fieldset class="ffc-ve-props-group">
					<legend><?php esc_html_e( 'Position and size (page pixels)', 'ffcertificate' ); ?></legend>
					<div class="ffc-ve-props-grid">
						<?php
						self::number_control( 'x', __( 'Left', 'ffcertificate' ), -2000, 2000, 1 );
						self::number_control( 'y', __( 'Top', 'ffcertificate' ), -2000, 2000, 1 );
						self::number_control( 'w', __( 'Width', 'ffcertificate' ), 1, 2000, 1 );
						self::number_control( 'h', __( 'Height', 'ffcertificate' ), 1, 2000, 1 );
						self::number_control( 'rotation', __( 'Rotation (degrees)', 'ffcertificate' ), -360, 360, 1 );
						?>
					</div>
				</fieldset>

				<fieldset class="ffc-ve-props-group" data-ffc-ve-for="text">
					<legend><?php esc_html_e( 'Text', 'ffcertificate' ); ?></legend>
					<label for="ffc_ve_prop_text"><?php esc_html_e( 'Content', 'ffcertificate' ); ?></label>
					<textarea id="ffc_ve_prop_text" class="ffc-w100" rows="4" data-ffc-ve-prop="text"></textarea>
					<label for="ffc_ve_prop_insert_token"><?php esc_html_e( 'Insert a tag', 'ffcertificate' ); ?></label>
					<select id="ffc_ve_prop_insert_token" class="ffc-w100" data-ffc-ve-insert-token>
						<option value=""><?php esc_html_e( 'Choose a tag to insert…', 'ffcertificate' ); ?></option>
						<?php foreach ( self::layout_tokens() as $token ) : ?>
							<option value="<?php echo esc_attr( $token ); ?>"><?php echo esc_html( $token ); ?></option>
						<?php endforeach; ?>
					</select>
				</fieldset>

				<fieldset class="ffc-ve-props-group" data-ffc-ve-for="field">
					<legend><?php esc_html_e( 'Data field', 'ffcertificate' ); ?></legend>
					<label for="ffc_ve_prop_token"><?php esc_html_e( 'Tag', 'ffcertificate' ); ?></label>
					<input type="text" id="ffc_ve_prop_token" class="ffc-w100 code" list="ffc_ve_tokens" data-ffc-ve-prop="token">
					<datalist id="ffc_ve_tokens">
						<?php foreach ( self::layout_tokens() as $token ) : ?>
							<option value="<?php echo esc_attr( $token ); ?>"></option>
						<?php endforeach; ?>
					</datalist>
					<p class="description"><?php esc_html_e( 'One tag, such as {{name}}. The preview shows it with sample data.', 'ffcertificate' ); ?></p>
				</fieldset>

				<fieldset class="ffc-ve-props-group" data-ffc-ve-for="text field">
					<legend><?php esc_html_e( 'Font', 'ffcertificate' ); ?></legend>
					<label for="ffc_ve_prop_font_family"><?php esc_html_e( 'Family', 'ffcertificate' ); ?></label>
					<select id="ffc_ve_prop_font_family" class="ffc-w100" data-ffc-ve-prop="font_family">
						<?php foreach ( self::font_choices() as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description ffc-ve-font-missing" data-ffc-ve-font-missing hidden></p>
					<div class="ffc-ve-props-grid">
						<?php
						self::number_control( 'font_size', __( 'Size (px)', 'ffcertificate' ), 4, 300, 1 );
						self::select_control( 'font_weight', __( 'Weight', 'ffcertificate' ), $selects['font_weight'] );
						self::select_control( 'font_style', __( 'Style', 'ffcertificate' ), $selects['font_style'] );
						self::color_control( 'color', __( 'Colour', 'ffcertificate' ) );
						self::select_control( 'align', __( 'Alignment', 'ffcertificate' ), $selects['align'] );
						self::select_control( 'text_transform', __( 'Case', 'ffcertificate' ), $selects['text_transform'] );
						self::number_control( 'letter_spacing', __( 'Letter spacing (px)', 'ffcertificate' ), -20, 100, 0.1 );
						self::number_control( 'line_height', __( 'Line height', 'ffcertificate' ), 0.5, 5, 0.05 );
						?>
					</div>
					<?php self::select_control( 'fit', __( 'Fit', 'ffcertificate' ), $selects['fit'] ); ?>
					<p class="description"><?php esc_html_e( 'Shrinking keeps a long name inside its box; the preview applies it as the PDF does.', 'ffcertificate' ); ?></p>
				</fieldset>

				<fieldset class="ffc-ve-props-group" data-ffc-ve-for="image">
					<legend><?php esc_html_e( 'Image', 'ffcertificate' ); ?></legend>
					<label for="ffc_ve_prop_image_source"><?php esc_html_e( 'Source', 'ffcertificate' ); ?></label>
					<select id="ffc_ve_prop_image_source" class="ffc-w100" data-ffc-ve-image-source>
						<option value="media"><?php esc_html_e( 'An image from the Media Library', 'ffcertificate' ); ?></option>
						<option value="signature"><?php esc_html_e( 'Signer 1’s signature', 'ffcertificate' ); ?></option>
						<option value="signature2"><?php esc_html_e( 'Signer 2’s signature', 'ffcertificate' ); ?></option>
						<option value="signature3"><?php esc_html_e( 'Signer 3’s signature', 'ffcertificate' ); ?></option>
					</select>
					<p data-ffc-ve-image-media>
						<?php
						AdminUI::render_button(
							array(
								'label' => __( 'Choose image', 'ffcertificate' ),
								'icon'  => 'image',
								'attrs' => array( 'data-ffc-ve-choose-image' => '1' ),
							)
						);
						?>
						<span class="description" data-ffc-ve-image-name></span>
					</p>
					<?php self::select_control( 'fit', __( 'Fit', 'ffcertificate' ), $selects['image_fit'], 'image_fit' ); ?>
				</fieldset>

				<fieldset class="ffc-ve-props-group" data-ffc-ve-for="qr">
					<legend><?php esc_html_e( 'QR code', 'ffcertificate' ); ?></legend>
					<?php self::number_control( 'margin', __( 'Quiet zone (modules)', 'ffcertificate' ), 0, 10, 1 ); ?>
					<p class="description"><?php esc_html_e( 'The code links to the validation page; its size is the box.', 'ffcertificate' ); ?></p>
				</fieldset>

				<fieldset class="ffc-ve-props-group" data-ffc-ve-for="frame">
					<legend><?php esc_html_e( 'Frame', 'ffcertificate' ); ?></legend>
					<div class="ffc-ve-props-grid">
						<?php
						self::number_control( 'border_width', __( 'Thickness (px)', 'ffcertificate' ), 0, 100, 1 );
						self::select_control( 'border_style', __( 'Line', 'ffcertificate' ), $selects['border_style'] );
						self::color_control( 'border_color', __( 'Line colour', 'ffcertificate' ) );
						self::number_control( 'radius', __( 'Corner radius (px)', 'ffcertificate' ), 0, 600, 1 );
						self::color_control( 'fill', __( 'Fill', 'ffcertificate' ) );
						?>
					</div>
					<p><label><input type="checkbox" data-ffc-ve-no-fill> <?php esc_html_e( 'No fill', 'ffcertificate' ); ?></label></p>
					<p><label><input type="checkbox" data-ffc-ve-gradient-on> <?php esc_html_e( 'Gradient', 'ffcertificate' ); ?></label></p>
					<div class="ffc-ve-gradient" data-ffc-ve-gradient hidden>
						<p class="ffc-ve-prop">
							<label for="ffc_ve_gradient_angle"><?php esc_html_e( 'Angle (degrees)', 'ffcertificate' ); ?></label>
							<input type="number" id="ffc_ve_gradient_angle" min="0" max="360" step="1" data-ffc-ve-gradient-angle>
						</p>
						<?php for ( $stop = 0; $stop < 5; $stop++ ) : ?>
							<div data-ffc-ve-gradient-stop="<?php echo esc_attr( (string) $stop ); ?>"><div class="ffc-ve-props-grid">
								<p class="ffc-ve-prop">
									<label for="ffc_ve_gradient_color_<?php echo esc_attr( (string) $stop ); ?>">
										<?php
										/* translators: %d: the colour's number in the gradient, from 1 */
										echo esc_html( sprintf( __( 'Colour %d', 'ffcertificate' ), $stop + 1 ) );
										?>
									</label>
									<input type="color" id="ffc_ve_gradient_color_<?php echo esc_attr( (string) $stop ); ?>" data-ffc-ve-gradient-color>
								</p>
								<p class="ffc-ve-prop">
									<label for="ffc_ve_gradient_at_<?php echo esc_attr( (string) $stop ); ?>"><?php esc_html_e( 'Position (%)', 'ffcertificate' ); ?></label>
									<input type="number" id="ffc_ve_gradient_at_<?php echo esc_attr( (string) $stop ); ?>" min="0" max="100" step="1" data-ffc-ve-gradient-at>
								</p>
							</div>
							<p><label><input type="checkbox" data-ffc-ve-gradient-clear> <?php esc_html_e( 'Transparent', 'ffcertificate' ); ?></label></p>
							</div>
						<?php endfor; ?>
						<p>
							<?php
							AdminUI::render_button(
								array(
									'label'    => __( 'Add colour', 'ffcertificate' ),
									'icon'     => 'plus',
									'category' => 'create',
									'attrs'    => array( 'data-ffc-ve-gradient-add' => '1' ),
								)
							);
							AdminUI::render_button(
								array(
									'label' => __( 'Remove last colour', 'ffcertificate' ),
									'icon'  => 'minus',
									'attrs' => array( 'data-ffc-ve-gradient-remove' => '1' ),
								)
							);
							?>
						</p>
					</div>
				</fieldset>

				<fieldset class="ffc-ve-props-group" data-ffc-ve-for="html">
					<legend><?php esc_html_e( 'HTML block', 'ffcertificate' ); ?></legend>
					<label for="ffc_ve_prop_html"><?php esc_html_e( 'Code', 'ffcertificate' ); ?></label>
					<textarea id="ffc_ve_prop_html" class="ffc-w100 code" rows="8" spellcheck="false" data-ffc-ve-prop="html"></textarea>
					<p class="description"><?php esc_html_e( 'Filtered on save like the HTML templates; tags work here too.', 'ffcertificate' ); ?></p>
				</fieldset>
			</div>
		</div>
		<?php
	}

	/**
	 * A numeric property control. It carries no name and no `required`: the
	 * layout JSON is what the form posts, and an empty field leaves the
	 * property as it was.
	 *
	 * @param string $prop  Layout property.
	 * @param string $label Label.
	 * @param float  $min   Minimum.
	 * @param float  $max   Maximum.
	 * @param float  $step  Step.
	 * @return void
	 */
	private static function number_control( string $prop, string $label, float $min, float $max, float $step ): void {
		$id = 'ffc_ve_prop_' . $prop;
		printf(
			'<p class="ffc-ve-prop"><label for="%1$s">%2$s</label><input type="number" id="%1$s" min="%3$s" max="%4$s" step="%5$s" data-ffc-ve-prop="%6$s" data-ffc-ve-number></p>',
			esc_attr( $id ),
			esc_html( $label ),
			esc_attr( (string) $min ),
			esc_attr( (string) $max ),
			esc_attr( (string) $step ),
			esc_attr( $prop )
		);
	}

	/**
	 * A select property control.
	 *
	 * @param string                    $prop    Layout property.
	 * @param string                    $label   Label.
	 * @param array<int|string, string> $options Value => label (numeric values arrive as int keys).
	 * @param string                    $id_key  Id suffix, when two types share a property.
	 * @return void
	 */
	private static function select_control( string $prop, string $label, array $options, string $id_key = '' ): void {
		$id = 'ffc_ve_prop_' . ( '' !== $id_key ? $id_key : $prop );
		echo '<p class="ffc-ve-prop"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label><select id="' . esc_attr( $id ) . '" data-ffc-ve-prop="' . esc_attr( $prop ) . '">';
		foreach ( $options as $value => $text ) {
			echo '<option value="' . esc_attr( (string) $value ) . '">' . esc_html( $text ) . '</option>';
		}
		echo '</select></p>';
	}

	/**
	 * A colour property control.
	 *
	 * @param string $prop  Layout property.
	 * @param string $label Label.
	 * @return void
	 */
	private static function color_control( string $prop, string $label ): void {
		$id = 'ffc_ve_prop_' . $prop;
		printf(
			'<p class="ffc-ve-prop"><label for="%1$s">%2$s</label><input type="color" id="%1$s" data-ffc-ve-prop="%3$s"></p>',
			esc_attr( $id ),
			esc_html( $label ),
			esc_attr( $prop )
		);
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

		$kind = CertTemplateReader::get_kind( (int) $post->ID );
		if ( CertTemplateCpt::KIND_APPOINTMENT_RECEIPT === $kind ) {
			$toggle_label = __( 'Show in the appointment-receipt selection', 'ffcertificate' );
			$toggle_help  = __( 'When on, this template can be chosen as the appointment receipt in the Personal Calendars settings.', 'ffcertificate' );
		} elseif ( CertTemplateCpt::KIND_RECORD === $kind ) {
			$toggle_label = __( 'Show in the record selection', 'ffcertificate' );
			$toggle_help  = __( 'When on, this template can be chosen as the record in Reregistration settings.', 'ffcertificate' );
		} else {
			$toggle_label = __( 'Show in the form editor’s “Load” list', 'ffcertificate' );
			$toggle_help  = __( 'When on, this template appears in the certificate form editor’s “Load” dropdown.', 'ffcertificate' );
		}
		?>
		<label class="ffc-control-toggle ffc-control-toggle-mark">
			<input type="checkbox" id="ffc_template_visible" name="ffc_template_visible" value="1" <?php checked( $visible ); ?>>
			<span class="ffc-control-toggle-track" aria-hidden="true"></span>
			<span class="ffc-control-toggle-label"><?php echo esc_html( $toggle_label ); ?></span>
		</label>
		<span id="ffc_template_visible_status" class="ffc-status" role="status" aria-live="polite" hidden></span>
		<p class="description">
			<?php echo esc_html( $toggle_help ); ?>
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
		if ( ! CertTemplateReader::is_default( $post_id ) && $this->save_visual_layout( $post_id ) ) {
			$bg_image = isset( $_POST['ffc_template_bg_image'] )
				? esc_url_raw( wp_unslash( $_POST['ffc_template_bg_image'] ) )
				: '';
			CertTemplateWriter::set_bg_image( $post_id, $bg_image );
		} elseif ( ! CertTemplateReader::is_default( $post_id ) ) {
			// Certificate HTML legitimately carries rich markup (tables, inline
			// styles) — sanitize through the same allowlist the form-layout save
			// uses, never a plain sanitize_text_field.
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Deliberately not sanitize_text_field: this is template HTML, filtered on the next line by wp_kses() with the HtmlPolicy allowlist.
			$raw  = isset( $_POST['ffc_template_html'] ) ? (string) wp_unslash( $_POST['ffc_template_html'] ) : '';
			$html = \FreeFormCertificate\Core\HtmlPolicy::kses_layout( $raw );
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

		// "Add New" kind preset (#951): a new template created from a feature's
		// "+ New" link carries the intended kind in a hidden field (rendered only
		// on the auto-draft); stamp it so the template is born the right kind.
		$requested_kind = isset( $_POST['ffc_template_kind'] ) ? sanitize_key( wp_unslash( $_POST['ffc_template_kind'] ) ) : '';
		if ( CertTemplateCpt::is_valid_kind( $requested_kind ) ) {
			CertTemplateWriter::set_kind( $post_id, $requested_kind );
		}
	}

	/**
	 * The layout field of a visual template (#2584): its JSON, until the
	 * canvas of #2585 replaces it, the background and the last save's error.
	 *
	 * @param \WP_Post $post       Current template post.
	 * @param bool     $is_default Whether it is a shipped default.
	 * @param string   $bg_image   Background image URL.
	 * @return void
	 */
	private function render_visual_layout_field( \WP_Post $post, bool $is_default, string $bg_image ): void {
		$stored = (string) get_post_meta( $post->ID, \FreeFormCertificate\Core\VisualLayout::META_LAYOUT, true );
		$json   = '' !== $stored ? $stored : \FreeFormCertificate\Core\VisualLayout::to_json( \FreeFormCertificate\Core\VisualLayout::blank() );

		$error_key = self::layout_error_key( (int) $post->ID );
		$error     = get_transient( $error_key );
		if ( is_string( $error ) && '' !== $error ) {
			delete_transient( $error_key );
			\FreeFormCertificate\Core\AdminNotice::callout(
				'error',
				esc_html( $error ),
				array( 'title' => __( 'The layout was not saved.', 'ffcertificate' ) )
			);
		}

		// What a visual copy converted, kept and dropped (#2587), once.
		self::render_visual_copy_report( (int) $post->ID );

		// What the saved layout lacks (#2586): the tags Settings → Certificates
		// requires and the fonts the site does not have. A warning, not a
		// refusal: a draft can be saved half done.
		$html    = (string) get_post_meta( $post->ID, CertTemplateCpt::META_HTML, true );
		$missing = self::missing_required_tags( $html );
		if ( array() !== $missing && '' !== $stored ) {
			\FreeFormCertificate\Core\AdminNotice::callout(
				'warning',
				'<p>' . sprintf(
					/* translators: %s: comma-separated tags, such as {{auth_code}} */
					esc_html__( 'Settings → Certificates requires every certificate to carry %s, and this layout does not. Insert a Data field for each one; a form using this template is refused on save until it does.', 'ffcertificate' ),
					implode( ', ', array_map( static fn( string $tag ): string => '<code>' . esc_html( $tag ) . '</code>', $missing ) )
				) . '</p>',
				array(
					'title'   => __( 'Required fields are missing', 'ffcertificate' ),
					'classes' => array( 'ffc-ve-required-missing', 'inline' ),
				)
			);
		}
		\FreeFormCertificate\Core\CertificateFonts::render_missing_notice( $html );

		if ( ! $is_default ) {
			$this->render_visual_canvas( (int) $post->ID );
		} else {
			self::render_default_page( (int) $post->ID );
		}
		?>
		<details class="ffc-ve-json">
			<summary><?php esc_html_e( 'Visual layout (JSON)', 'ffcertificate' ); ?></summary>
			<label class="screen-reader-text" for="ffc_template_layout"><?php esc_html_e( 'Visual layout (JSON)', 'ffcertificate' ); ?></label>
			<textarea name="ffc_template_layout" id="ffc_template_layout" class="ffc-w100 code" rows="20" spellcheck="false" <?php wp_readonly( $is_default, true ); ?>><?php echo esc_textarea( $json ); ?></textarea>
			<p class="description">
				<?php esc_html_e( 'The page as a list of positioned elements, in page pixels (1123 × 794 landscape, 794 × 1123 portrait); the editor above keeps it current. Saving checks the layout and generates the certificate HTML from it. The format is described under Settings → Documentation → Document Templates.', 'ffcertificate' ); ?>
			</p>
		</details>
		<div class="ffc-input-group ffc-mt-xl">
			<label class="ffc-block-label" for="ffc_template_bg_image"><strong><?php esc_html_e( 'Background Image URL:', 'ffcertificate' ); ?></strong></label>
			<input type="text" name="ffc_template_bg_image" id="ffc_template_bg_image" value="<?php echo esc_url( $bg_image ); ?>" class="ffc-w100" <?php wp_readonly( $is_default, true ); ?>>
			<div id="ffc_bg_image_preview"></div>
		</div>
		<?php
	}

	/**
	 * The page canvas of a visual template (#2585): the Insert, Page and
	 * Layers menu beside the page. `ffc-visual-editor.js` draws the elements
	 * and keeps the layout field current; without it the canvas stays hidden
	 * and the field is edited by hand.
	 *
	 * @param int $post_id Template post id.
	 * @return void
	 */
	private function render_visual_canvas( int $post_id ): void {
		$preview = wp_nonce_url(
			admin_url( 'admin.php?action=' . self::PREVIEW_ACTION . '&post=' . $post_id ),
			self::PREVIEW_ACTION . '_' . $post_id
		);
		$convert = wp_nonce_url(
			admin_url( 'admin.php?action=' . self::CONVERT_ACTION . '&post=' . $post_id ),
			self::CONVERT_ACTION . '_' . $post_id
		);
		$types   = array(
			'text'  => array( __( 'Text', 'ffcertificate' ), 'text' ),
			'field' => array( __( 'Data field', 'ffcertificate' ), 'tag' ),
			'image' => array( __( 'Image', 'ffcertificate' ), 'image' ),
			'qr'    => array( __( 'QR code', 'ffcertificate' ), 'qr' ),
			'frame' => array( __( 'Frame', 'ffcertificate' ), 'layout' ),
			'html'  => array( __( 'HTML block', 'ffcertificate' ), 'code' ),
		);
		$aligns  = array(
			'left'   => array( __( 'Align left', 'ffcertificate' ), 'chevrons-left' ),
			'center' => array( __( 'Centre horizontally', 'ffcertificate' ), 'split' ),
			'right'  => array( __( 'Align right', 'ffcertificate' ), 'chevrons-right' ),
			'top'    => array( __( 'Align top', 'ffcertificate' ), 'chevron-up' ),
			'middle' => array( __( 'Centre vertically', 'ffcertificate' ), 'merge' ),
			'bottom' => array( __( 'Align bottom', 'ffcertificate' ), 'chevron-down' ),
		);
		?>
		<div class="ffc-visual-editor" data-ffc-visual-editor="ffc_template_layout" hidden>
			<div class="ffc-ve-gate notice notice-info inline" hidden>
				<p>
					<?php esc_html_e( 'Editing the page needs a window at least 1280 pixels wide and a mouse or trackpad. Your changes are kept: widen the window to go on. Meanwhile you can still open the template list or the test PDF.', 'ffcertificate' ); ?>
				</p>
				<p class="ffc-ve-buttons">
					<?php
					AdminUI::render_button(
						array(
							'label' => __( 'Template list', 'ffcertificate' ),
							'icon'  => 'list',
							'href'  => admin_url( 'edit.php?post_type=' . CertTemplateCpt::POST_TYPE ),
						)
					);
					AdminUI::render_button(
						array(
							'label' => __( 'Test PDF', 'ffcertificate' ),
							'icon'  => 'eye',
							'href'  => $preview,
							'attrs' => array( 'target' => '_blank' ),
						)
					);
					?>
				</p>
			</div>
			<div class="ffc-ve-workspace">
				<div class="ffc-ve-menu">
					<section class="ffc-ve-group">
						<h3 class="ffc-ve-group-title ffc-icon-edit"><?php esc_html_e( 'Edit', 'ffcertificate' ); ?></h3>
						<div class="ffc-ve-buttons">
							<?php
							$commands = array(
								'undo'      => array( __( 'Undo', 'ffcertificate' ), 'restore' ),
								'redo'      => array( __( 'Redo', 'ffcertificate' ), 'sync' ),
								'copy'      => array( __( 'Copy', 'ffcertificate' ), 'copy' ),
								'paste'     => array( __( 'Paste', 'ffcertificate' ), 'clipboard' ),
								'duplicate' => array( __( 'Duplicate', 'ffcertificate' ), 'replicate-down' ),
							);
							foreach ( $commands as $command => $button ) {
								AdminUI::render_button(
									array(
										'label'     => $button[0],
										'icon'      => $button[1],
										'icon_only' => true,
										'attrs'     => array( 'data-ffc-ve-cmd' => $command ),
									)
								);
							}
							?>
						</div>
						<p class="description"><?php esc_html_e( 'Copied elements can be pasted into another visual template.', 'ffcertificate' ); ?></p>
					</section>
					<section class="ffc-ve-group">
						<h3 class="ffc-ve-group-title ffc-icon-plus"><?php esc_html_e( 'Insert', 'ffcertificate' ); ?></h3>
						<div class="ffc-ve-buttons">
							<?php
							foreach ( $types as $type => $button ) {
								AdminUI::render_button(
									array(
										'label'    => $button[0],
										'icon'     => $button[1],
										'category' => 'create',
										'attrs'    => array( 'data-ffc-ve-insert' => $type ),
									)
								);
							}
							?>
						</div>
					</section>
					<section class="ffc-ve-group">
						<h3 class="ffc-ve-group-title ffc-icon-file"><?php esc_html_e( 'Page', 'ffcertificate' ); ?></h3>
						<p>
							<label for="ffc_ve_orientation"><?php esc_html_e( 'Orientation', 'ffcertificate' ); ?></label>
							<select id="ffc_ve_orientation" data-ffc-ve-orientation>
								<option value="landscape"><?php esc_html_e( 'Landscape', 'ffcertificate' ); ?></option>
								<option value="portrait"><?php esc_html_e( 'Portrait', 'ffcertificate' ); ?></option>
							</select>
						</p>
						<p>
							<label for="ffc_ve_zoom"><?php esc_html_e( 'Zoom', 'ffcertificate' ); ?></label>
							<select id="ffc_ve_zoom" data-ffc-ve-zoom>
								<option value="fit"><?php esc_html_e( 'Fit to screen', 'ffcertificate' ); ?></option>
								<option value="0.5">50%</option>
								<option value="0.75">75%</option>
								<option value="1">100%</option>
							</select>
						</p>
						<p>
							<label><input type="checkbox" data-ffc-ve-snap checked> <?php esc_html_e( 'Snap to a 10 px grid', 'ffcertificate' ); ?></label>
						</p>
						<p>
							<?php
							AdminUI::render_button(
								array(
									'label' => __( 'Preview with sample data', 'ffcertificate' ),
									'icon'  => 'eye',
									'attrs' => array( 'data-ffc-ve-preview' => '1' ),
								)
							);
							?>
						</p>
						<p class="ffc-ve-group-title"><?php esc_html_e( 'Align', 'ffcertificate' ); ?></p>
						<p class="description"><?php esc_html_e( 'One element aligns to the page; several align to each other. Shift-click adds an element to the selection.', 'ffcertificate' ); ?></p>
						<div class="ffc-ve-buttons">
							<?php
							foreach ( $aligns as $mode => $button ) {
								AdminUI::render_button(
									array(
										'label'     => $button[0],
										'icon'      => $button[1],
										'icon_only' => true,
										'attrs'     => array( 'data-ffc-ve-align' => $mode ),
									)
								);
							}
							AdminUI::render_button(
								array(
									'label'     => __( 'Distribute horizontally', 'ffcertificate' ),
									'icon'      => 'move',
									'icon_only' => true,
									'attrs'     => array( 'data-ffc-ve-distribute' => 'x' ),
								)
							);
							AdminUI::render_button(
								array(
									'label'     => __( 'Distribute vertically', 'ffcertificate' ),
									'icon'      => 'grip',
									'icon_only' => true,
									'attrs'     => array( 'data-ffc-ve-distribute' => 'y' ),
								)
							);
							?>
						</div>
					</section>
					<section class="ffc-ve-group">
						<h3 class="ffc-ve-group-title ffc-icon-stack-plus"><?php esc_html_e( 'Layers', 'ffcertificate' ); ?></h3>
						<p class="description"><?php esc_html_e( 'The first in the list is in front. Drag to reorder, or use the arrows.', 'ffcertificate' ); ?></p>
						<ul class="ffc-ve-layers"></ul>
					</section>
					<section class="ffc-ve-group">
						<?php
						AdminUI::render_button(
							array(
								'label'    => __( 'Discard changes', 'ffcertificate' ),
								'icon'     => 'restore',
								'category' => 'caution',
								'attrs'    => array( 'data-ffc-ve-discard' => '1' ),
							)
						);
						?>
						<p class="description"><?php esc_html_e( 'Back to the last save. Save with the Update button.', 'ffcertificate' ); ?></p>
						<?php
						AdminUI::render_button(
							array(
								'label'    => __( 'Convert to HTML', 'ffcertificate' ),
								'icon'     => 'code',
								'category' => 'caution',
								'href'     => $convert,
								'attrs'    => array(
									'data-confirm'      => __( 'Convert this template to HTML? The page becomes the HTML generated at the last save, edited as code from then on; the visual layout is dropped and cannot be brought back. Forms that use the template keep printing the same document. Save any change first.', 'ffcertificate' ),
									'data-confirm-category' => 'caution',
									'data-confirm-icon' => 'code',
								),
							)
						);
						?>
						<p class="description"><?php esc_html_e( 'One way: from then on the template is edited as HTML.', 'ffcertificate' ); ?></p>
					</section>
				</div>
				<div class="ffc-ve-stage">
					<div class="ffc-ve-frame">
						<div class="ffc-ve-page" tabindex="0" role="application" aria-label="<?php esc_attr_e( 'Certificate page. Select an element; the arrow keys move it 1 pixel, with Shift 10, and Delete removes it. The keyboard shortcuts are listed under the page.', 'ffcertificate' ); ?>" aria-describedby="ffc_ve_status"></div>
					</div>
					<p id="ffc_ve_status" class="screen-reader-text" role="status" aria-live="polite" data-ffc-ve-status></p>
					<details class="ffc-ve-keys">
						<summary class="ffc-icon-help"><?php esc_html_e( 'Keyboard shortcuts', 'ffcertificate' ); ?></summary>
						<dl>
							<?php
							$keys = array(
								'] / ['               => __( 'Select the next or previous element', 'ffcertificate' ),
								'Ctrl + A'            => __( 'Select every element', 'ffcertificate' ),
								'Esc'                 => __( 'Clear the selection', 'ffcertificate' ),
								__( 'Drag over the page', 'ffcertificate' ) => __( 'Select what the rectangle touches; with Shift, add it', 'ffcertificate' ),
								__( 'Arrows', 'ffcertificate' ) => __( 'Move 1 px; with Shift, 10 px', 'ffcertificate' ),
								'Alt + ' . __( 'arrows', 'ffcertificate' ) => __( 'Resize from the right and bottom edges', 'ffcertificate' ),
								'R / Shift + R'       => __( 'Rotate 15° clockwise or back; with Alt, 1°', 'ffcertificate' ),
								'Ctrl + ↑ / ↓'        => __( 'Bring forward or send backward', 'ffcertificate' ),
								'L'                   => __( 'Lock or unlock', 'ffcertificate' ),
								'Delete'              => __( 'Remove', 'ffcertificate' ),
								'Ctrl + Z / Ctrl + Y' => __( 'Undo or redo', 'ffcertificate' ),
								'Ctrl + C / V / D'    => __( 'Copy, paste or duplicate', 'ffcertificate' ),
							);
							foreach ( $keys as $combo => $what ) {
								echo '<dt><kbd>' . esc_html( (string) $combo ) . '</kbd></dt><dd>' . esc_html( $what ) . '</dd>';
							}
							?>
						</dl>
					</details>
				</div>
			</div>
			<dialog class="ffc-ve-preview" aria-labelledby="ffc_ve_preview_title">
				<div class="ffc-ve-preview-header">
					<h2 id="ffc_ve_preview_title"><?php esc_html_e( 'Preview with sample data', 'ffcertificate' ); ?></h2>
					<?php
					AdminUI::render_button(
						array(
							'label'     => __( 'Close', 'ffcertificate' ),
							'icon'      => 'cross',
							'icon_only' => true,
							'attrs'     => array( 'data-ffc-ve-preview-close' => '1' ),
						)
					);
					?>
				</div>
				<p class="description"><?php esc_html_e( 'The unsaved layout, drawn by the PDF renderer with sample data: the fit options and the fonts apply as they will print.', 'ffcertificate' ); ?></p>
				<div class="ffc-ve-preview-warnings" data-ffc-ve-preview-warnings></div>
				<p class="ffc-ve-preview-status" role="status" data-ffc-ve-preview-status></p>
				<div class="ffc-ve-preview-body">
					<img class="ffc-ve-preview-page" alt="<?php esc_attr_e( 'Preview of the certificate page', 'ffcertificate' ); ?>" hidden>
				</div>
			</dialog>
		</div>
		<?php
	}

	/**
	 * Save a visual template's layout (#2584), when the template is one or is
	 * being created as one. The certificate HTML is regenerated from the
	 * layout, so every reader of a template keeps reading HTML. A layout that
	 * does not validate leaves the stored one in place and says why.
	 *
	 * @param int $post_id Saved post id.
	 * @return bool Whether the template is visual (the HTML body is then not read).
	 */
	private function save_visual_layout( int $post_id ): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- the caller verified the nonce and the capability.
		$chosen = isset( $_POST['ffc_template_editor'] ) ? sanitize_key( wp_unslash( $_POST['ffc_template_editor'] ) ) : '';
		$visual = \FreeFormCertificate\Core\VisualLayout::is_visual( $post_id );
		if ( ! $visual && \FreeFormCertificate\Core\VisualLayout::EDITOR_VISUAL === $chosen && CertTemplateCpt::KIND_CERTIFICATE === CertTemplateReader::get_kind( $post_id ) ) {
			$layout = \FreeFormCertificate\Core\VisualLayout::blank();
			update_post_meta( $post_id, \FreeFormCertificate\Core\VisualLayout::META_EDITOR, \FreeFormCertificate\Core\VisualLayout::EDITOR_VISUAL );
			\FreeFormCertificate\Core\VisualLayout::store_json( $post_id, \FreeFormCertificate\Core\VisualLayout::to_json( $layout ) );
			CertTemplateWriter::update_html( $post_id, \FreeFormCertificate\Core\VisualLayout::render( $layout ) );
			return true;
		}
		if ( ! $visual ) {
			return false;
		}
		if ( ! isset( $_POST['ffc_template_layout'] ) ) {
			return true;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON, validated and normalised field by field on the next line.
		$layout = \FreeFormCertificate\Core\VisualLayout::validate( (string) wp_unslash( $_POST['ffc_template_layout'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( is_wp_error( $layout ) ) {
			set_transient( self::layout_error_key( $post_id ), $layout->get_error_message(), 5 * MINUTE_IN_SECONDS );
			return true;
		}
		\FreeFormCertificate\Core\VisualLayout::store_json( $post_id, \FreeFormCertificate\Core\VisualLayout::to_json( $layout ) );
		CertTemplateWriter::update_html( $post_id, \FreeFormCertificate\Core\VisualLayout::render( $layout ) );
		return true;
	}

	/**
	 * Where a refused layout's message waits for the next screen, per user.
	 *
	 * @param int $post_id Template post id.
	 */
	private static function layout_error_key( int $post_id ): string {
		return 'ffc_layout_error_' . get_current_user_id() . '_' . $post_id;
	}

	/**
	 * AJAX: the visual editor's preview (#2586). Renders the unsaved layout
	 * with sample data through the PDF renderer, so the fit options and the
	 * fonts apply as they will print; the client captures it with
	 * `FFCLayoutPreview`. Also says which required tags and fonts the layout
	 * lacks, so the editor can warn before the save.
	 *
	 * @return void
	 */
	public function ajax_preview_visual_layout(): void {
		check_ajax_referer( self::PREVIEW_LAYOUT_ACTION, 'nonce' );

		if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_forms' ) ) {
			wp_send_json_error( null, 403 );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON, validated and normalised field by field by VisualLayout::validate().
		$json   = isset( $_POST['layout'] ) ? (string) wp_unslash( $_POST['layout'] ) : '';
		$layout = \FreeFormCertificate\Core\VisualLayout::validate( $json );
		if ( is_wp_error( $layout ) ) {
			wp_send_json_error( array( 'message' => $layout->get_error_message() ), 400 );
		}

		$html     = \FreeFormCertificate\Core\VisualLayout::render( $layout );
		$portrait = 'portrait' === $layout['page']['orientation'];
		$title    = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$image    = isset( $_POST['bg_image'] ) ? esc_url_raw( wp_unslash( $_POST['bg_image'] ) ) : '';
		$post_id  = RequestInput::get_post_int( 'post_id', 0 );
		$color    = $post_id > 0 && CertTemplateCpt::POST_TYPE === get_post_type( $post_id ) ? CertTemplateReader::get_bg_color( $post_id ) : '';
		$size     = \FreeFormCertificate\Generators\FormLayoutPreview::page_size( $portrait );

		wp_send_json_success(
			array(
				'html'    => $this->preview_page( $html, $title, $image, $color, $portrait ),
				'width'   => $size['width'],
				'height'  => $size['height'],
				'missing' => self::missing_required_tags( $html ),
				'fonts'   => \FreeFormCertificate\Core\CertificateFonts::missing( $html ),
			)
		);
	}

	/**
	 * The page the preview captures: the layout's HTML with sample data,
	 * through the PDF renderer, on its background.
	 *
	 * @param string $html     Rendered layout HTML.
	 * @param string $title    Template title.
	 * @param string $image    Background image URL, or ''.
	 * @param string $color    Page colour, or ''.
	 * @param bool   $portrait Whether the page is portrait.
	 * @return string
	 */
	protected function preview_page( string $html, string $title, string $image, string $color, bool $portrait ): string {
		return \FreeFormCertificate\Generators\FormLayoutPreview::body(
			$html,
			$title,
			$image,
			$color,
			\FreeFormCertificate\Core\CertificatePreviewSamples::get_map(),
			$portrait
		);
	}

	/**
	 * The tags Settings → Certificates requires that a template's HTML does
	 * not carry, with the `{{name}}` / `{{nome}}` alias the form save honours.
	 *
	 * @param string $html Template HTML.
	 * @return array<int, string>
	 */
	public static function missing_required_tags( string $html ): array {
		return ( new FormEditorSaveValidator() )->missing_required_tags( $html );
	}

	/**
	 * Turn a visual template into an HTML template (#2586), after the
	 * confirmation its button asks for. One way: the layout is dropped and
	 * the HTML it generated at the last save stays, so every form that uses
	 * the template prints exactly what it printed before.
	 *
	 * @return void
	 */
	public function handle_convert_to_html(): void {
		$post_id = RequestInput::get_get_int( 'post' );
		check_admin_referer( self::CONVERT_ACTION . '_' . $post_id );

		if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_forms' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage certificate templates.', 'ffcertificate' ) );
		}
		if ( CertTemplateCpt::POST_TYPE !== get_post_type( $post_id )
			|| CertTemplateReader::is_default( $post_id )
			|| ! \FreeFormCertificate\Core\VisualLayout::is_visual( $post_id ) ) {
			wp_die( esc_html__( 'Only a visual template you can edit can be converted to HTML.', 'ffcertificate' ) );
		}

		self::convert_to_html( $post_id );

		wp_safe_redirect( admin_url( 'post.php?post=' . $post_id . '&action=edit&ffc_converted=1' ) );
		exit;
	}

	/**
	 * Drop a template's visual layout and keep its generated HTML.
	 *
	 * @param int $post_id Template post id.
	 * @return void
	 */
	public static function convert_to_html( int $post_id ): void {
		delete_post_meta( $post_id, \FreeFormCertificate\Core\VisualLayout::META_LAYOUT );
		delete_post_meta( $post_id, \FreeFormCertificate\Core\VisualLayout::META_EDITOR );
	}

	/**
	 * Whether a template can be read into a visual copy: an HTML certificate.
	 *
	 * @param int $post_id Template post id.
	 */
	private static function can_have_visual_copy( int $post_id ): bool {
		return CertTemplateCpt::KIND_CERTIFICATE === CertTemplateReader::get_kind( $post_id )
			&& ! \FreeFormCertificate\Core\VisualLayout::is_visual( $post_id );
	}

	/**
	 * The nonced address of the visual-copy action.
	 *
	 * @param int $post_id Template post id.
	 */
	private static function visual_copy_url( int $post_id ): string {
		return wp_nonce_url(
			admin_url( 'admin.php?action=' . self::VISUAL_COPY_ACTION . '&post=' . $post_id ),
			self::VISUAL_COPY_ACTION . '_' . $post_id
		);
	}

	/**
	 * Create a visual template from an HTML certificate template (#2587). The
	 * new template is read from the saved HTML; the original is not touched.
	 * What was converted, kept as HTML and dropped waits for the new
	 * template's screen.
	 *
	 * @return void
	 */
	public function handle_visual_copy(): void {
		$post_id = RequestInput::get_get_int( 'post' );
		check_admin_referer( self::VISUAL_COPY_ACTION . '_' . $post_id );

		if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_forms' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage certificate templates.', 'ffcertificate' ) );
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || CertTemplateCpt::POST_TYPE !== $post->post_type || ! self::can_have_visual_copy( $post_id ) ) {
			wp_die( esc_html__( 'Only an HTML certificate template can have a visual version.', 'ffcertificate' ) );
		}

		$new_id = self::create_visual_copy( $post );
		if ( $new_id <= 0 ) {
			wp_die( esc_html__( 'The visual version could not be created.', 'ffcertificate' ) );
		}

		wp_safe_redirect( admin_url( 'post.php?post=' . $new_id . '&action=edit' ) );
		exit;
	}

	/**
	 * Read a template into a new visual template and keep the report for
	 * its screen.
	 *
	 * @param \WP_Post $post The HTML certificate template.
	 * @return int The new template's id, or 0.
	 */
	public static function create_visual_copy( \WP_Post $post ): int {
		$html   = (string) get_post_meta( $post->ID, CertTemplateCpt::META_HTML, true );
		$result = \FreeFormCertificate\Core\VisualLayoutImporter::from_html( $html );

		return self::save_visual_copy( $post, $result['layout'], $result['report'], $result['bg_color'] );
	}

	/**
	 * Save a layout read from an HTML certificate template as a new visual
	 * template, and keep the report for its screen.
	 *
	 * @param \WP_Post                                                                                          $post     The HTML certificate template.
	 * @param array{version: int, page: array{orientation: string}, elements: array<int, array<string, mixed>>} $layout A validated layout.
	 * @param array<int, array{status: string, label: string}>                                                  $report What became what.
	 * @param string                                                                                            $bg_color Page colour the reading found, or ''.
	 * @return int The new template's id, or 0.
	 */
	private static function save_visual_copy( \WP_Post $post, array $layout, array $report, string $bg_color ): int {
		$color = (string) get_post_meta( $post->ID, CertTemplateCpt::META_BG_COLOR, true );
		$color = '' !== $color ? $color : $bg_color;

		/* translators: %s: source template title */
		$title  = sprintf( __( '%s (visual)', 'ffcertificate' ), (string) $post->post_title );
		$new_id = CertTemplateWriter::create(
			$title,
			\FreeFormCertificate\Core\VisualLayout::render( $layout ),
			true,
			(string) get_post_meta( $post->ID, CertTemplateCpt::META_BG_IMAGE, true ),
			CertTemplateCpt::KIND_CERTIFICATE,
			$color
		);
		if ( $new_id <= 0 ) {
			return 0;
		}
		update_post_meta( $new_id, \FreeFormCertificate\Core\VisualLayout::META_EDITOR, \FreeFormCertificate\Core\VisualLayout::EDITOR_VISUAL );
		\FreeFormCertificate\Core\VisualLayout::store_json( $new_id, \FreeFormCertificate\Core\VisualLayout::to_json( $layout ) );
		set_transient( self::visual_copy_report_key( $new_id ), $report, HOUR_IN_SECONDS );
		return $new_id;
	}

	/**
	 * The template a measured-copy request names, after its checks: the
	 * link's nonce, the capability, an HTML certificate template. Answers
	 * the request with an error otherwise.
	 *
	 * @return \WP_Post
	 */
	private static function visual_copy_request(): \WP_Post {
		$post_id = RequestInput::get_post_int( 'post' );
		check_ajax_referer( self::VISUAL_COPY_ACTION . '_' . $post_id );

		if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_forms' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to manage certificate templates.', 'ffcertificate' ) ), 403 );
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || CertTemplateCpt::POST_TYPE !== $post->post_type || ! self::can_have_visual_copy( $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Only an HTML certificate template can have a visual version.', 'ffcertificate' ) ), 400 );
		}
		return $post;
	}

	/**
	 * AJAX: the saved HTML of a template, for the browser to render and
	 * measure (#2606). The original is only read.
	 *
	 * @return void
	 */
	public function ajax_visual_copy_source(): void {
		$post = self::visual_copy_request();
		wp_send_json_success( array( 'html' => (string) get_post_meta( $post->ID, CertTemplateCpt::META_HTML, true ) ) );
	}

	/**
	 * AJAX: save the layout the browser read as a new visual template
	 * (#2606). An image the Media Library knows becomes an image element;
	 * any other stays an HTML block. The layout is validated like any other.
	 *
	 * @return void
	 */
	public function ajax_visual_copy_save(): void {
		$post = self::visual_copy_request();

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- JSON, validated field by field by VisualLayout::validate(); the nonce is checked in visual_copy_request().
		$json = isset( $_POST['layout'] ) ? (string) wp_unslash( $_POST['layout'] ) : '';
		$raw  = json_decode( $json, true );
		if ( is_array( $raw ) && isset( $raw['elements'] ) && is_array( $raw['elements'] ) ) {
			$raw['elements'] = array_map( array( self::class, 'library_image' ), $raw['elements'] );
			$json            = (string) wp_json_encode( $raw );
		}
		$layout = \FreeFormCertificate\Core\VisualLayout::validate( $json );
		if ( is_wp_error( $layout ) ) {
			wp_send_json_error( array( 'message' => $layout->get_error_message() ), 400 );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- JSON, each entry reduced to a known status and a plain label below; the nonce is checked in visual_copy_request().
		$entries = json_decode( isset( $_POST['report'] ) ? (string) wp_unslash( $_POST['report'] ) : '', true );
		$report  = array();
		foreach ( is_array( $entries ) ? array_slice( $entries, 0, \FreeFormCertificate\Core\VisualLayout::MAX_ELEMENTS * 2 ) : array() as $entry ) {
			$status = is_array( $entry ) ? (string) ( $entry['status'] ?? '' ) : '';
			if ( in_array( $status, array( \FreeFormCertificate\Core\VisualLayoutImporter::CONVERTED, \FreeFormCertificate\Core\VisualLayoutImporter::KEPT, \FreeFormCertificate\Core\VisualLayoutImporter::DROPPED ), true ) ) {
				$report[] = array(
					'status' => $status,
					'label'  => sanitize_text_field( (string) ( $entry['label'] ?? '' ) ),
				);
			}
		}
		$color = sanitize_hex_color( RequestInput::get_post_string( 'bg_color' ) );

		$new_id = self::save_visual_copy( $post, $layout, $report, is_string( $color ) ? $color : '' );
		if ( $new_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'The visual version could not be created.', 'ffcertificate' ) ), 500 );
		}
		wp_send_json_success( array( 'edit_url' => admin_url( 'post.php?post=' . $new_id . '&action=edit' ) ) );
	}

	/**
	 * An image block the browser read, as an image element when the Media
	 * Library knows its file; any other element unchanged.
	 *
	 * @param mixed $element Raw element.
	 * @return mixed
	 */
	private static function library_image( $element ) {
		if ( ! is_array( $element ) || 'html' !== ( $element['type'] ?? '' ) || ! is_string( $element['src'] ?? null ) || '' === $element['src'] ) {
			return $element;
		}
		$id = (int) attachment_url_to_postid( $element['src'] );
		unset( $element['src'] );
		if ( $id <= 0 ) {
			return $element;
		}
		$element['type']          = 'image';
		$element['attachment_id'] = $id;
		unset( $element['html'] );
		return $element;
	}

	/**
	 * Where a visual copy's report waits for its screen, per user.
	 *
	 * @param int $post_id The new template's id.
	 */
	private static function visual_copy_report_key( int $post_id ): string {
		return 'ffc_visual_copy_report_' . get_current_user_id() . '_' . $post_id;
	}

	/**
	 * Print, once, what the visual copy converted, kept and dropped.
	 *
	 * @param int $post_id The new template's id.
	 * @return void
	 */
	private static function render_visual_copy_report( int $post_id ): void {
		$key    = self::visual_copy_report_key( $post_id );
		$report = get_transient( $key );
		if ( ! is_array( $report ) ) {
			return;
		}
		delete_transient( $key );

		$groups = array(
			\FreeFormCertificate\Core\VisualLayoutImporter::CONVERTED => __( 'Converted to elements', 'ffcertificate' ),
			\FreeFormCertificate\Core\VisualLayoutImporter::KEPT      => __( 'Kept as HTML blocks', 'ffcertificate' ),
			\FreeFormCertificate\Core\VisualLayoutImporter::DROPPED   => __( 'Dropped', 'ffcertificate' ),
		);
		$body   = '<p>' . esc_html__( 'This visual template was read from the HTML template; the original is unchanged. Check the page and the preview before using it.', 'ffcertificate' ) . '</p>';
		foreach ( $groups as $status => $heading ) {
			$items = array_filter( $report, static fn( $entry ): bool => is_array( $entry ) && ( $entry['status'] ?? '' ) === $status );
			if ( array() === $items ) {
				continue;
			}
			$body .= '<p><strong>' . esc_html( $heading ) . ' (' . count( $items ) . ')</strong></p><ul class="ffc-visual-copy-report">';
			foreach ( $items as $entry ) {
				$body .= '<li>' . esc_html( (string) ( $entry['label'] ?? '' ) ) . '</li>';
			}
			$body .= '</ul>';
		}
		\FreeFormCertificate\Core\AdminNotice::callout(
			'info',
			$body,
			array(
				'title'   => __( 'Visual version created', 'ffcertificate' ),
				'classes' => array( 'ffc-visual-copy-report-notice', 'inline' ),
			)
		);
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
			if ( '' !== $existing ) {
				$data['post_title'] = wp_slash( $existing );
			}
		}
		return $data;
	}
}
