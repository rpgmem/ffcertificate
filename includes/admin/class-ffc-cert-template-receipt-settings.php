<?php
/**
 * CertTemplateReceiptSettings
 *
 * "Appointment Receipt" settings page under the Scheduling menu (#945): lets an
 * admin pick, globally per scheduling mode (Regular / Custom), which pool
 * template (kind `appointment_receipt`) the comprovante PDF uses. The selection
 * is stored in a dedicated option ({@see CertTemplateReceiptResolver::OPTION}),
 * full-rebuilt from this page's own form, so it never clobbers the shared
 * `ffc_settings` blob.
 *
 * Lives in the Admin (Forms) module beside the template pool it reads, gated by
 * the same `ffc_manage_forms` capability, and hangs its submenu off the
 * Scheduling menu so operators find it where they manage calendars.
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
 * Global per-mode appointment-receipt template selection page.
 */
class CertTemplateReceiptSettings {

	/**
	 * `admin_post_{$action}` slug for the save handler.
	 */
	private const SAVE_ACTION = 'ffc_save_receipt_templates';

	/**
	 * Nonce action for the settings form.
	 */
	private const NONCE = 'ffc_receipt_templates_save';

	/**
	 * Register the menu page + save handler.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_page' ), 26 );
		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'handle_save' ) );
	}

	/**
	 * Add the "Appointment Receipt" submenu under the Scheduling menu.
	 *
	 * @return void
	 */
	public function add_page(): void {
		add_submenu_page(
			'ffc-scheduling',
			__( 'Appointment Receipt', 'ffcertificate' ),
			__( 'Appointment Receipt', 'ffcertificate' ),
			'ffc_manage_forms',
			'ffc-appointment-receipt',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the two per-mode template dropdowns.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_forms' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'ffcertificate' ) );
		}

		$templates = CertTemplateReader::list_for_editor( CertTemplateCpt::KIND_APPOINTMENT_RECEIPT );
		$regular   = CertTemplateReceiptResolver::selected_id( 'regular' );
		$custom    = CertTemplateReceiptResolver::selected_id( 'custom' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only "saved" flash flag on the post-redirect-get; no state change.
		$saved = isset( $_GET['ffc_saved'] );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Appointment Receipt Templates', 'ffcertificate' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Choose which template the appointment receipt (comprovante) PDF uses, separately for Regular and Custom calendars. Templates are managed in the Templates screen; leave a mode on “Shipped default” to keep the built-in layout.', 'ffcertificate' ); ?>
			</p>
			<?php if ( $saved ) : ?>
				<?php
				wp_admin_notice(
					esc_html__( 'Appointment receipt templates saved.', 'ffcertificate' ),
					array(
						'type'        => 'success',
						'dismissible' => true,
					)
				);
				?>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>">
				<?php wp_nonce_field( self::NONCE ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ffc_receipt_regular"><?php esc_html_e( 'Regular calendars', 'ffcertificate' ); ?></label></th>
						<td><?php $this->render_select( 'ffc_receipt_regular', $templates, $regular ); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="ffc_receipt_custom"><?php esc_html_e( 'Custom calendars', 'ffcertificate' ); ?></label></th>
						<td><?php $this->render_select( 'ffc_receipt_custom', $templates, $custom ); ?></td>
					</tr>
				</table>
				<?php submit_button( __( 'Save Changes', 'ffcertificate' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render one template <select> (with a "Shipped default" zero option).
	 *
	 * @param string                                                   $name      Field name/id.
	 * @param array<int, array{id:int, label:string, is_default:bool}> $templates Pool templates of the receipt kind.
	 * @param int                                                      $selected  Currently selected id.
	 * @return void
	 */
	private function render_select( string $name, array $templates, int $selected ): void {
		echo '<select name="' . esc_attr( $name ) . '" id="' . esc_attr( $name ) . '">';
		echo '<option value="0"' . selected( $selected, 0, false ) . '>' . esc_html__( 'Shipped default', 'ffcertificate' ) . '</option>';
		foreach ( $templates as $tpl ) {
			$label = $tpl['is_default']
				/* translators: %s: template title */
				? sprintf( __( '%s (default)', 'ffcertificate' ), $tpl['label'] )
				: $tpl['label'];
			echo '<option value="' . esc_attr( (string) $tpl['id'] ) . '"' . selected( $selected, (int) $tpl['id'], false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}

	/**
	 * Persist the per-mode selection, then redirect back.
	 *
	 * @return void
	 */
	public function handle_save(): void {
		if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_forms' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage these settings.', 'ffcertificate' ) );
		}
		check_admin_referer( self::NONCE );

		$regular = isset( $_POST['ffc_receipt_regular'] ) ? absint( wp_unslash( $_POST['ffc_receipt_regular'] ) ) : 0;
		$custom  = isset( $_POST['ffc_receipt_custom'] ) ? absint( wp_unslash( $_POST['ffc_receipt_custom'] ) ) : 0;

		update_option(
			CertTemplateReceiptResolver::OPTION,
			array(
				'regular' => $this->sanitize_receipt_id( $regular ),
				'custom'  => $this->sanitize_receipt_id( $custom ),
			)
		);

		wp_safe_redirect( admin_url( 'admin.php?page=ffc-appointment-receipt&ffc_saved=1' ) );
		exit;
	}

	/**
	 * Keep only an id that points at an actual appointment-receipt template
	 * (0 otherwise) so a stale/foreign id can't be stored.
	 *
	 * @param int $id Candidate template id.
	 * @return int
	 */
	private function sanitize_receipt_id( int $id ): int {
		if ( $id <= 0 ) {
			return 0;
		}
		return CertTemplateCpt::KIND_APPOINTMENT_RECEIPT === CertTemplateReader::get_kind( $id ) ? $id : 0;
	}
}
