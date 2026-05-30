<?php
/**
 * FormEditor
 * Handles the advanced UI for the Form Builder, including AJAX and layout management.
 *
 * @package FreeFormCertificate\Admin
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace (Phase 2)
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Form Editor.
 */
class FormEditor {

	/**
	 * Metabox renderer.
	 *
	 * @var \FreeFormCertificate\Admin\FormEditorMetaboxRenderer
	 */
	private $metabox_renderer;
	/**
	 * Save handler.
	 *
	 * @var \FreeFormCertificate\Admin\FormEditorSaveHandler
	 */
	private $save_handler;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->metabox_renderer = new \FreeFormCertificate\Admin\FormEditorMetaboxRenderer();
		$this->save_handler     = new \FreeFormCertificate\Admin\FormEditorSaveHandler();

		add_action( 'add_meta_boxes', array( $this, 'add_custom_metaboxes' ), 20 );
		add_action( 'save_post', array( $this->save_handler, 'save_form_data' ) );
		add_action( 'admin_notices', array( $this->save_handler, 'display_save_errors' ) );
		// Priority 20 so we run AFTER AdminAssetsManager (default 10) has
		// registered `ffc-admin-js`. The `wp_localize_script` call below
		// attaches `ffcFormMetaAutosave` to that handle; calling localize
		// before the script is registered makes WP silently drop the
		// data, leaving the form-meta autosave handler in ffc-admin.js
		// with a `window.ffcFormMetaAutosave === undefined` guard and
		// no change listeners wired (toggles stop auto-saving — closes
		// the post-#240 regression).
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ), 20 );

		// AJAX handlers for the editor.
		add_action( 'wp_ajax_ffc_generate_codes', array( $this, 'ajax_generate_random_codes' ) );
		add_action( 'wp_ajax_ffc_load_template', array( $this, 'ajax_load_template' ) );
	}

	/**
	 * Enqueue scripts and styles for form editor
	 *
	 * @param string $hook Hook name.
	 */
	public function enqueue_scripts( string $hook ): void {
		// Only load on form edit page.
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'ffc_form' !== $screen->post_type ) {
			return;
		}

		$s = \FreeFormCertificate\Core\Utils::asset_suffix();

		// Pure validator (`window.FFCGeofenceValidation.analyzeDateTimeOrder`)
		// — no jQuery dep, loaded first so the admin script can read it on
		// every input change.
		wp_enqueue_script(
			'ffc-geofence-validation',
			FFC_PLUGIN_URL . "assets/js/ffc-geofence-validation{$s}.js",
			array(),
			FFC_VERSION,
			true
		);

		wp_enqueue_script(
			'ffc-geofence-admin',
			FFC_PLUGIN_URL . "assets/js/ffc-geofence-admin{$s}.js",
			array( 'jquery', 'ffc-geofence-validation' ),
			FFC_VERSION,
			true
		);

		// Vertical-tab behaviour for the configuration metabox. Pure
		// progressive enhancement (jQuery only) — the panels stay usable
		// stacked if this fails to load.
		wp_enqueue_script(
			'ffc-form-editor-tabs',
			FFC_PLUGIN_URL . "assets/js/ffc-form-editor-tabs{$s}.js",
			array( 'jquery' ),
			FFC_VERSION,
			true
		);

		// If the previous save left validation errors, tell the tab script
		// which tabs to flag (and which one to auto-open). The matching
		// transients are still consumed by
		// FormEditorSaveHandler::display_save_errors() to render the notice
		// itself — admin_enqueue_scripts runs first (head) and only reads.
		$error_tabs = $this->get_error_tab_keys();
		if ( ! empty( $error_tabs ) ) {
			wp_localize_script( 'ffc-form-editor-tabs', 'ffcFormTabsErrors', $error_tabs );
		}

		// Required-tag list for the client-side save guard: block the submit
		// and open the Layout tab with a banner when the certificate layout
		// is missing any required {{tag}}. The server-side check in
		// FormEditorSaveHandler is the backstop for JS-disabled clients.
		$required_tags = \FreeFormCertificate\Settings\SettingsReader::required_certificate_tags();

		// Per-form gate: when this form has an Event Schedule configured
		// (geofence `class_time_*`), the layout must contain {{schedule}}
		// — same rule the save handler enforces server-side. Surfacing it
		// in the client-side guard means the operator sees the banner on
		// the very next save attempt, not after a server round-trip.
		$current_post = get_post();
		if ( $current_post && 'ffc_form' === $current_post->post_type ) {
			$geofence = get_post_meta( $current_post->ID, '_ffc_geofence_config', true );
			if ( is_array( $geofence )
				&& ( ! empty( $geofence['class_time_start'] ) || ! empty( $geofence['class_time_end'] ) )
				&& ! in_array( '{{schedule}}', $required_tags, true )
			) {
				$required_tags[] = '{{schedule}}';
			}
		}

		wp_localize_script(
			'ffc-form-editor-tabs',
			'ffcFormRequiredTags',
			array(
				'tags'    => $required_tags,
				'aliases' => array( '{{name}}' => array( '{{nome}}' ) ),
				'message' => __( 'This certificate layout is missing required tags:', 'ffcertificate' ),
			)
		);

		wp_localize_script(
			'ffc-geofence-admin',
			'ffc_geofence_admin',
			array(
				'alert_message' => __( 'At least one geolocation method (GPS or IP) must be enabled when geolocation is active.', 'ffcertificate' ),
			)
		);

		// Localize per-form-meta autosave wiring (nonce + post id) so the
		// admin script can talk to FormMetaAjaxEndpoint on toggle change.
		// `ffc-admin-js` is registered globally and already enqueued on
		// this screen by AdminAssets; we attach the localized object to
		// its handle so the inline `data-ffc-autosave-form-key` listeners
		// read it on document-ready.
		$post = get_post();
		if ( $post && 'ffc_form' === $post->post_type && $post->ID > 0 ) {
			wp_localize_script(
				'ffc-admin-js',
				'ffcFormMetaAutosave',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'action'  => \FreeFormCertificate\Admin\FormMetaAjaxEndpoint::AJAX_ACTION,
					'nonce'   => wp_create_nonce( \FreeFormCertificate\Admin\FormMetaAjaxEndpoint::AJAX_ACTION ),
					'postId'  => $post->ID,
					'strings' => array(
						'saving' => __( 'Saving…', 'ffcertificate' ),
						'saved'  => __( 'Saved', 'ffcertificate' ),
						'error'  => __( 'Save failed', 'ffcertificate' ),
					),
				)
			);
		}
	}

	/**
	 * Tab keys with a pending validation error from the last save attempt,
	 * read from the per-user transients set by FormEditorSaveHandler.
	 *
	 * Non-destructive: the transients are consumed (deleted) by
	 * {@see FormEditorSaveHandler::display_save_errors()} when it renders the
	 * admin notice; this method only peeks so the tab script can flag the
	 * offending tabs and open the first one.
	 *
	 * @return array<int, string> Ordered list of tab keys, e.g. ['layout'].
	 */
	public function get_error_tab_keys(): array {
		$uid  = get_current_user_id();
		$keys = array();
		// Missing required {{tags}} in the PDF layout → Layout tab.
		if ( get_transient( 'ffc_save_error_' . $uid ) ) {
			$keys[] = 'layout';
		}
		// Geofence validation failures route to the specific sub-tab(s) —
		// datetime → Time, area → Geolocation — via the companion transient
		// the save handler sets. Fall back to flagging both when only the
		// legacy error transient is present.
		$geofence_tabs = get_transient( 'ffc_geofence_error_tabs_' . $uid );
		if ( is_array( $geofence_tabs ) && $geofence_tabs ) {
			$keys = array_merge( $keys, $geofence_tabs );
		} elseif ( get_transient( 'ffc_geofence_error_' . $uid ) ) {
			$keys[] = 'time';
			$keys[] = 'geolocation';
		}
		return $keys;
	}

	/**
	 * Registers all metaboxes for the Form CPT
	 *
	 * ✅ v3.1.1: Delegates rendering to FFC_Form_Editor_Metabox_Renderer
	 */
	public function add_custom_metaboxes(): void {
		// Remove any potential duplicates.
		remove_meta_box( 'ffc_form_builder', 'ffc_form', 'normal' );
		remove_meta_box( 'ffc_form_config', 'ffc_form', 'normal' );
		remove_meta_box( 'ffc_builder_box', 'ffc_form', 'normal' );

		// The seven content sections are now rendered inside one wrapper
		// metabox as a vertical-tabbed container (WooCommerce "Product
		// data" style) instead of seven stacked metaboxes. Each tab panel
		// reuses the matching `render_box_*` method, so the save path and
		// the form-meta autosave are unchanged; see
		// `FormEditorMetaboxRenderer::render_tabbed_container()`.
		add_meta_box(
			'ffc_box_tabs',
			__( 'Certificate Form Configuration', 'ffcertificate' ),
			array( $this->metabox_renderer, 'render_tabbed_container' ),
			'ffc_form',
			'normal',
			'high'
		);

		// Device Fingerprint Limit (former Section 8) is rendered as a
		// sub-section of "Restriction & Security" (the Security tab) — both
		// answer the same question ("who can submit this form?") so they
		// belong together. The dispatch happens inside
		// `FormEditorMetaboxRenderer::render_box_restriction()`.

		// Sidebar metabox (shortcode + instructions) - Delegated to Metabox Renderer.
		add_meta_box(
			'ffc_form_shortcode',
			__( 'How to Use / Shortcode', 'ffcertificate' ),
			array( $this->metabox_renderer, 'render_shortcode_metabox' ),
			'ffc_form',
			'side',
			'high'
		);

		// 6.6.4 follow-up (#361 Sprint 3) — per-form pre-flight stats
		// badges (cookie wall / GPS wall / rate-limit hits over the
		// last 30 days). Sidebar position, low priority so it sits
		// below the more-frequently-used metaboxes.
		add_meta_box(
			'ffc_form_preflight_stats',
			__( 'User-friction stats — last 30 days', 'ffcertificate' ),
			static function ( $post ) {
				\FreeFormCertificate\Admin\PreflightStatsService::render_metabox( (int) $post->ID );
			},
			'ffc_form',
			'side',
			'low'
		);
	}

	/**
	 * AJAX: Generates a list of unique ticket codes
	 */
	public function ajax_generate_random_codes(): void {
		check_ajax_referer( 'ffc_admin_pdf_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error();
		}

		$qty   = isset( $_POST['qty'] ) ? absint( wp_unslash( $_POST['qty'] ) ) : 10;
		$codes = array();
		for ( $i = 0; $i < $qty; $i++ ) {
			$rnd     = strtoupper( bin2hex( random_bytes( 4 ) ) );
			$codes[] = substr( $rnd, 0, 4 ) . '-' . substr( $rnd, 4, 4 );
		}
		wp_send_json_success( array( 'codes' => implode( "\n", $codes ) ) );
	}

	/**
	 * AJAX: Loads a local HTML template from the plugin directory
	 */
	public function ajax_load_template(): void {
		check_ajax_referer( 'ffc_admin_pdf_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error();
		}

		$filename = isset( $_POST['filename'] ) ? sanitize_file_name( wp_unslash( $_POST['filename'] ) ) : '';
		if ( empty( $filename ) ) {
			wp_send_json_error();
		}

		$filepath = FFC_PLUGIN_DIR . 'html/' . $filename;
		if ( ! file_exists( $filepath ) ) {
			wp_send_json_error();
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading bundled plugin HTML template; no remote URL.
		$content = file_get_contents( $filepath );
		wp_send_json_success( $content );
	}
}
