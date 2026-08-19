<?php
/**
 * CertTemplateReceiptSettings
 *
 * The **Receipt** tab of the Scheduling Settings page (#945): pick, globally per
 * scheduling mode (Regular / Custom), which pool template (kind
 * `appointment_receipt`) the comprovante PDF uses.
 *
 * Since #951 (Direction 1) this tab is a **pure selector** — editing, creating
 * and duplicating templates happen in the single document-template hub (the
 * `ffc_cert_template` list under Settings). The tab links into that hub
 * (Manage / Edit / + New) instead of carrying its own inline editor.
 *
 * The tab is contributed to the (Audience-owned) Scheduling Settings page via
 * its `ffc_scheduling_settings_tabs` filter + `ffc_scheduling_settings_render_tab_receipt`
 * action, so no module references the other directly. The selection lives in a
 * dedicated option ({@see self::OPTION}); template bodies live in the pool.
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
 * Appointment-receipt template selector (Scheduling Settings tab).
 */
class CertTemplateReceiptSettings {

	/**
	 * Tab id within the Scheduling Settings page.
	 */
	private const TAB = 'receipt';

	/**
	 * Dedicated option storing the per-mode selection:
	 * `['regular' => int, 'custom' => int]` of pool template ids (0 = shipped default).
	 */
	public const OPTION = 'ffc_scheduling_receipt_templates';

	/**
	 * `admin_post_{$action}` slug for the save handler.
	 */
	private const SAVE_ACTION = 'ffc_save_receipt_templates';

	/**
	 * Nonce action shared by the form.
	 */
	private const NONCE = 'ffc_receipt_templates';

	/**
	 * Register the tab, its renderer and the save handler.
	 */
	public function __construct() {
		add_filter( 'ffc_scheduling_settings_tabs', array( $this, 'add_tab' ) );
		add_action( 'ffc_scheduling_settings_render_tab_' . self::TAB, array( $this, 'render_tab' ) );
		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'handle_save' ) );
	}

	/**
	 * The selected pool template id for a scheduling mode, or 0 when none.
	 *
	 * @param string $schedule_type 'regular' | 'custom'.
	 * @return int
	 */
	public static function selected_id( string $schedule_type ): int {
		$opt = get_option( self::OPTION, array() );
		if ( ! is_array( $opt ) ) {
			return 0;
		}
		$key = 'custom' === $schedule_type ? 'custom' : 'regular';
		return (int) ( $opt[ $key ] ?? 0 );
	}

	/**
	 * Add the Receipt tab to the Scheduling Settings tab set.
	 *
	 * @param array<string, array{label:string, icon:string}> $tabs Current tabs.
	 * @return array<string, array{label:string, icon:string}>
	 */
	public function add_tab( array $tabs ): array {
		$tabs[ self::TAB ] = array(
			'label' => __( 'Receipt', 'ffcertificate' ),
			'icon'  => 'media-document',
		);
		return $tabs;
	}

	/**
	 * URL of the document-template hub, pre-filtered to appointment-receipt
	 * templates.
	 *
	 * @return string
	 */
	private static function hub_list_url(): string {
		return admin_url( 'edit.php?post_type=' . CertTemplateCpt::POST_TYPE . '&ffc_kind=' . CertTemplateCpt::KIND_APPOINTMENT_RECEIPT );
	}

	/**
	 * URL that creates a new receipt-kind template in the hub (kind preset).
	 *
	 * @return string
	 */
	private static function hub_new_url(): string {
		return admin_url( 'post-new.php?post_type=' . CertTemplateCpt::POST_TYPE . '&ffc_kind=' . CertTemplateCpt::KIND_APPOINTMENT_RECEIPT );
	}

	/**
	 * Edit-screen URL for a specific template.
	 *
	 * @param int $id Template post id.
	 * @return string
	 */
	private static function hub_edit_url( int $id ): string {
		return admin_url( 'post.php?post=' . $id . '&action=edit' );
	}

	/**
	 * Render the Receipt tab panel: the per-mode selectors + links into the hub.
	 *
	 * @return void
	 */
	public function render_tab(): void {
		if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_forms' ) ) {
			echo '<p>' . esc_html__( 'You do not have permission to manage these settings.', 'ffcertificate' ) . '</p>';
			return;
		}

		$templates = CertTemplateReader::list_for_editor( CertTemplateCpt::KIND_APPOINTMENT_RECEIPT );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only "saved" flash flag on the post-redirect-get.
		if ( isset( $_GET['ffc_saved'] ) ) {
			wp_admin_notice(
				esc_html__( 'Appointment receipt templates saved.', 'ffcertificate' ),
				array(
					'type'        => 'success',
					'dismissible' => true,
				)
			);
		}
		?>
		<h2><?php esc_html_e( 'Appointment Receipt Templates', 'ffcertificate' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Choose which template the appointment receipt (comprovante) PDF uses, separately for Regular and Custom calendars. Create, edit and duplicate templates in the Document Templates hub.', 'ffcertificate' ); ?>
		</p>
		<p>
			<a class="button" href="<?php echo esc_url( self::hub_list_url() ); ?>" target="_blank" rel="noopener">
				<?php esc_html_e( 'Manage receipt templates', 'ffcertificate' ); ?>
			</a>
			<a class="button" href="<?php echo esc_url( self::hub_new_url() ); ?>" target="_blank" rel="noopener">
				<?php esc_html_e( '+ New template', 'ffcertificate' ); ?>
			</a>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ffc-receipt-form">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>">
			<?php wp_nonce_field( self::NONCE ); ?>
			<?php
			$this->render_mode_section( 'regular', __( 'Regular calendars', 'ffcertificate' ), $templates );
			$this->render_mode_section( 'custom', __( 'Custom calendars', 'ffcertificate' ), $templates );
			?>
			<?php submit_button( __( 'Save Changes', 'ffcertificate' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Render one mode's section: label, template dropdown and an "Edit" link for
	 * the selected (editable) template.
	 *
	 * @param string                                                   $mode      'regular' | 'custom'.
	 * @param string                                                   $heading   Section heading.
	 * @param array<int, array{id:int, label:string, is_default:bool}> $templates Receipt-kind pool templates.
	 * @return void
	 */
	private function render_mode_section( string $mode, string $heading, array $templates ): void {
		$selected = self::selected_id( $mode );
		// A non-default pool template can be opened for editing; the shipped
		// default (0) and the seeded defaults are read-only (duplicate in the hub).
		$can_edit_selected = $selected > 0 && ! CertTemplateReader::is_default( $selected );

		$select_name = 'ffc_receipt_' . $mode;
		?>
		<div class="ffc-receipt-mode" data-mode="<?php echo esc_attr( $mode ); ?>">
			<h3><?php echo esc_html( $heading ); ?></h3>
			<p>
				<label for="<?php echo esc_attr( $select_name ); ?>"><strong><?php esc_html_e( 'Template:', 'ffcertificate' ); ?></strong></label>
				<select name="<?php echo esc_attr( $select_name ); ?>" id="<?php echo esc_attr( $select_name ); ?>" class="ffc-receipt-select">
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
				<?php if ( $can_edit_selected ) : ?>
					<a href="<?php echo esc_url( self::hub_edit_url( $selected ) ); ?>" target="_blank" rel="noopener">
						<?php esc_html_e( 'Edit this template →', 'ffcertificate' ); ?>
					</a>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Persist the per-mode selection, then redirect to the tab.
	 *
	 * @return void
	 */
	public function handle_save(): void {
		if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_forms' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage these settings.', 'ffcertificate' ) );
		}
		check_admin_referer( self::NONCE );

		$selection = array();
		foreach ( array( 'regular', 'custom' ) as $mode ) {
			$id                 = isset( $_POST[ 'ffc_receipt_' . $mode ] ) ? absint( wp_unslash( $_POST[ 'ffc_receipt_' . $mode ] ) ) : 0;
			$selection[ $mode ] = $this->sanitize_receipt_id( $id );
		}

		update_option( self::OPTION, $selection );

		wp_safe_redirect( admin_url( 'admin.php?page=ffc-scheduling-settings&tab=' . self::TAB . '&ffc_saved=1' ) );
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
