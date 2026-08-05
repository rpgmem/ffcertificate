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
 * {@see CertTemplateWriter::set_visibility()}. Shipped defaults are protected
 * from deletion (the seeder would re-create them anyway), so their Trash/Delete
 * row actions are removed.
 *
 * Authorization: the toggle requires the manage cap (`ffc_manage_forms`, admins
 * via `manage_options`) exactly like the CPT's write primitives; a view-only
 * operator (`ffc_view_forms`) sees the columns read-only with no toggle link.
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
 * List-table columns, row actions and the visibility toggle for the pool.
 */
class CertTemplateAdminScreen {

	/**
	 * The `admin_action_{$action}` slug for the visibility toggle.
	 */
	private const TOGGLE_ACTION = 'ffc_toggle_cert_template_visibility';

	/**
	 * Register the list-table + row-action hooks.
	 */
	public function __construct() {
		$pt = CertTemplateCpt::POST_TYPE;
		add_filter( "manage_{$pt}_posts_columns", array( $this, 'columns' ) );
		add_action( "manage_{$pt}_posts_custom_column", array( $this, 'render_column' ), 10, 2 );
		add_filter( 'post_row_actions', array( $this, 'row_actions' ), 10, 2 );
		add_action( 'admin_action_' . self::TOGGLE_ACTION, array( $this, 'handle_toggle_visibility' ) );
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
}
