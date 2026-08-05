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

	/**
	 * Register the HTML-body + visibility metabox on the edit screen.
	 *
	 * @return void
	 */
	public function add_edit_metabox(): void {
		add_meta_box(
			'ffc_cert_template_body',
			__( 'Template', 'ffcertificate' ),
			array( $this, 'render_edit_metabox' ),
			CertTemplateCpt::POST_TYPE,
			'normal',
			'high'
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
		$visible    = '1' === (string) get_post_meta( $post->ID, CertTemplateCpt::META_VISIBLE, true );
		$is_default = CertTemplateReader::is_default( (int) $post->ID );

		wp_nonce_field( self::SAVE_NONCE, 'ffc_cert_template_nonce' );

		if ( $is_default ) {
			echo '<p class="description">' .
				esc_html__( 'This is a shipped default template — its HTML is read-only. Duplicate it to create an editable copy; you can still show/hide it below.', 'ffcertificate' ) .
				'</p>';
		}
		?>
		<p>
			<label class="ffc-block-label" for="ffc_template_html"><strong><?php esc_html_e( 'Certificate HTML', 'ffcertificate' ); ?></strong></label>
			<textarea name="ffc_template_html" id="ffc_template_html" class="ffc-w100" rows="16" <?php wp_readonly( $is_default, true ); ?>><?php echo esc_textarea( $html ); ?></textarea>
		</p>
		<p class="description">
			<?php esc_html_e( 'Mandatory Tags:', 'ffcertificate' ); ?> <code>{{auth_code}}</code>, <code>{{name}}</code>, <code>{{cpf_rf}}</code>.
		</p>
		<p>
			<label>
				<input type="checkbox" name="ffc_template_visible" value="1" <?php checked( $visible ); ?>>
				<?php esc_html_e( 'Show this template in the form editor\'s "Load" list', 'ffcertificate' ); ?>
			</label>
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
