<?php
/**
 * Reregistration Tab
 *
 * Settings tab for the Reregistration feature (#951 phase 2). Currently hosts
 * the global **ficha template** selector: which pool template (kind `ficha`)
 * the reregistration ficha PDF uses. Editing/creating/duplicating the templates
 * themselves happens in the Document Templates hub — this tab only assigns one.
 *
 * @package FreeFormCertificate\Settings\Tabs
 * @since   6.20.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Settings\Tabs;

use FreeFormCertificate\Settings\SettingsTab;
use FreeFormCertificate\Admin\CertTemplateCpt;
use FreeFormCertificate\Admin\CertTemplateReader;
use FreeFormCertificate\Admin\CertTemplateFichaResolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reregistration settings tab (ficha template selector).
 */
class TabReregistration extends SettingsTab {

	/**
	 * `admin_post_{$action}` slug for the save handler.
	 */
	private const SAVE_ACTION = 'ffc_save_ficha_template';

	/**
	 * Nonce action for the save form.
	 */
	private const NONCE = 'ffc_ficha_template';

	/**
	 * Init.
	 */
	protected function init(): void {
		$this->tab_id    = 'reregistration';
		$this->tab_title = __( 'Reregistration', 'ffcertificate' );
		// Clipboard glyph — one of the emoji that the admin emoji font renders
		// monochrome (as the Activity-Log tab already does), matching the flat
		// icon row. The `ffc-icon-id` (🆔) and `ffc-icon-user` (👤) glyphs both
		// render as a solid colour in that font, so they break the row.
		$this->tab_icon  = 'ffc-icon-clipboard';
		$this->tab_order = 55;

		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'handle_save' ) );
	}

	/**
	 * Gated by the reregistration caps (the feature's own capability).
	 */
	public function get_view_cap(): string {
		return 'ffc_view_reregistration';
	}

	/**
	 * Manage cap — the reregistration manage capability.
	 */
	public function get_manage_cap(): string {
		return 'ffc_manage_reregistration';
	}

	/**
	 * Hub list URL, pre-filtered to ficha templates.
	 *
	 * @return string
	 */
	private static function hub_list_url(): string {
		return admin_url( 'edit.php?post_type=' . CertTemplateCpt::POST_TYPE . '&ffc_kind=' . CertTemplateCpt::KIND_FICHA );
	}

	/**
	 * New ficha-template URL (kind preset).
	 *
	 * @return string
	 */
	private static function hub_new_url(): string {
		return admin_url( 'post-new.php?post_type=' . CertTemplateCpt::POST_TYPE . '&ffc_kind=' . CertTemplateCpt::KIND_FICHA );
	}

	/**
	 * Edit-screen URL for a template id.
	 *
	 * @param int $id Template post id.
	 * @return string
	 */
	private static function hub_edit_url( int $id ): string {
		return admin_url( 'post.php?post=' . $id . '&action=edit' );
	}

	/**
	 * Render the ficha selector + hub links.
	 *
	 * @return void
	 */
	public function render(): void {
		$templates = CertTemplateReader::list_for_editor( CertTemplateCpt::KIND_FICHA );
		$selected  = CertTemplateFichaResolver::selected_id();
		$can_edit  = $selected > 0 && ! CertTemplateReader::is_default( $selected );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only "saved" flash flag on the post-redirect-get.
		if ( isset( $_GET['ffc_saved'] ) ) {
			wp_admin_notice(
				esc_html__( 'Ficha template saved.', 'ffcertificate' ),
				array(
					'type'        => 'success',
					'dismissible' => true,
				)
			);
		}
		?>
		<h2><?php esc_html_e( 'Ficha Template', 'ffcertificate' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Choose which template the reregistration ficha PDF uses. Create, edit and duplicate ficha templates in the Document Templates hub.', 'ffcertificate' ); ?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ffc-ficha-form">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>">
			<?php wp_nonce_field( self::NONCE ); ?>
			<p>
				<label for="ffc_ficha_template"><strong><?php esc_html_e( 'Template:', 'ffcertificate' ); ?></strong></label>
				<select name="ffc_ficha_template" id="ffc_ficha_template">
					<option value="0"<?php selected( $selected, 0 ); ?>><?php esc_html_e( 'Shipped default', 'ffcertificate' ); ?></option>
					<?php foreach ( $templates as $tpl ) : ?>
						<option value="<?php echo esc_attr( (string) $tpl['id'] ); ?>"<?php selected( $selected, (int) $tpl['id'] ); ?>>
							<?php
							echo esc_html(
								$tpl['is_default']
									/* translators: %s: template title */
									? sprintf( __( '%s (default)', 'ffcertificate' ), $tpl['label'] )
									: $tpl['label']
							);
							?>
						</option>
					<?php endforeach; ?>
				</select>
				<?php if ( $can_edit ) : ?>
					<a href="<?php echo esc_url( self::hub_edit_url( $selected ) ); ?>" target="_blank" rel="noopener">
						<?php esc_html_e( 'Edit this template →', 'ffcertificate' ); ?>
					</a>
				<?php endif; ?>
			</p>
			<?php submit_button( __( 'Save Changes', 'ffcertificate' ) ); ?>
		</form>

		<p>
			<a class="button" href="<?php echo esc_url( self::hub_list_url() ); ?>" target="_blank" rel="noopener">
				<?php esc_html_e( 'Manage ficha templates', 'ffcertificate' ); ?>
			</a>
			<a class="button" href="<?php echo esc_url( self::hub_new_url() ); ?>" target="_blank" rel="noopener">
				<?php esc_html_e( '+ New ficha template', 'ffcertificate' ); ?>
			</a>
		</p>
		<?php
	}

	/**
	 * Persist the selected ficha template id, then redirect to the tab.
	 *
	 * @return void
	 */
	public function handle_save(): void {
		if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_reregistration' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage these settings.', 'ffcertificate' ) );
		}
		check_admin_referer( self::NONCE );

		$id = isset( $_POST['ffc_ficha_template'] ) ? absint( wp_unslash( $_POST['ffc_ficha_template'] ) ) : 0;
		// Keep only an id that actually points at a ficha template (0 otherwise).
		if ( $id > 0 && CertTemplateCpt::KIND_FICHA !== CertTemplateReader::get_kind( $id ) ) {
			$id = 0;
		}

		update_option( CertTemplateFichaResolver::OPTION, $id );

		wp_safe_redirect( admin_url( 'admin.php?page=ffc-settings&tab=' . $this->tab_id . '&ffc_saved=1' ) );
		exit;
	}
}
