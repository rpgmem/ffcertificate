<?php
/**
 * CertTemplateReceiptSettings
 *
 * The **Receipt** tab of the Scheduling Settings page (#945): pick, globally per
 * scheduling mode (Regular / Custom), which pool template (kind
 * `appointment_receipt`) the comprovante PDF uses — and edit its HTML inline
 * (CodeMirror), duplicating a shipped/default template to get an editable copy.
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
 * Appointment-receipt template selection + inline editor (Scheduling Settings tab).
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
	 * `wp_ajax_{$action}` slug: fetch a template's HTML + editable flag.
	 */
	private const AJAX_LOAD = 'ffc_receipt_template_load';

	/**
	 * `wp_ajax_{$action}` slug: duplicate a template into an editable copy.
	 */
	private const AJAX_DUPLICATE = 'ffc_receipt_template_duplicate';

	/**
	 * Nonce action shared by the form + AJAX endpoints.
	 */
	private const NONCE = 'ffc_receipt_templates';

	/**
	 * Register the tab, its renderer, the save handler and the AJAX endpoints.
	 */
	public function __construct() {
		add_filter( 'ffc_scheduling_settings_tabs', array( $this, 'add_tab' ) );
		add_action( 'ffc_scheduling_settings_render_tab_' . self::TAB, array( $this, 'render_tab' ) );
		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'handle_save' ) );
		add_action( 'wp_ajax_' . self::AJAX_LOAD, array( $this, 'ajax_load' ) );
		add_action( 'wp_ajax_' . self::AJAX_DUPLICATE, array( $this, 'ajax_duplicate' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
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
	 * Whether a template id is an editable appointment-receipt template (a
	 * non-default one of the right kind). Shipped defaults and the "shipped
	 * default" (0) option are read-only.
	 *
	 * @param int $id Template id.
	 * @return bool
	 */
	private function is_editable( int $id ): bool {
		return $id > 0
			&& CertTemplateCpt::KIND_APPOINTMENT_RECEIPT === CertTemplateReader::get_kind( $id )
			&& ! CertTemplateReader::is_default( $id );
	}

	/**
	 * HTML shown when "Shipped default" (0) is selected — the built-in fallback
	 * file the renderer uses when no pool template is chosen.
	 *
	 * @return string
	 */
	private function shipped_default_html(): string {
		$path = FFC_PLUGIN_DIR . 'templates/documents/default_appointment_receipt_1.html';
		if ( ! is_readable( $path ) ) {
			return '';
		}
		$html = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Bundled plugin asset.
		return is_string( $html ) ? $html : '';
	}

	/**
	 * The HTML to display for a selected id (a pool template's body, or the
	 * shipped fallback when 0).
	 *
	 * @param int $id Selected template id.
	 * @return string
	 */
	private function html_for( int $id ): string {
		return $id > 0 ? CertTemplateReader::get_html( $id ) : $this->shipped_default_html();
	}

	/**
	 * Enqueue CodeMirror + the tab script on the Scheduling Settings → Receipt tab.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing: which admin page/tab is open, to decide whether to enqueue.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- See above.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		if ( false === strpos( $hook, 'ffc-scheduling-settings' ) && 'ffc-scheduling-settings' !== $page ) {
			return;
		}
		if ( self::TAB !== $tab ) {
			return;
		}

		// Shared CodeMirror foundation (base styles + theme + wp_enqueue_code_editor
		// + the `ffc-code-editor-core` initializer). One settings blob drives both
		// textareas client-side via `window.FFCCodeEditor.init()`.
		$base = AdminAssetsManager::enqueue_code_editor_base();

		$s = \FreeFormCertificate\Core\AssetHelper::asset_suffix();
		wp_enqueue_script(
			'ffc-receipt-templates',
			FFC_PLUGIN_URL . "assets/js/ffc-receipt-templates{$s}.js",
			array( 'jquery', 'ffc-core', 'ffc-code-editor-core' ),
			FFC_VERSION,
			true
		);
		wp_localize_script(
			'ffc-receipt-templates',
			'ffcReceiptTemplates',
			array(
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'nonce'          => wp_create_nonce( self::NONCE ),
				'loadAction'     => self::AJAX_LOAD,
				'dupAction'      => self::AJAX_DUPLICATE,
				'editorSettings' => $base['settings'],
				'theme'          => $base['theme'],
				'profileUrl'     => admin_url( 'profile.php#syntax_highlighting' ),
				'modes'          => array( 'regular', 'custom' ),
				'strings'        => array_merge(
					AdminAssetsManager::code_editor_notice_strings(),
					array(
						'readonlyNote' => __( 'This is a shipped/default template — its HTML is read-only. Use “Duplicate to edit” to create an editable copy.', 'ffcertificate' ),
						'editableNote' => __( 'Editing this template’s HTML. Changes save with the form below.', 'ffcertificate' ),
						'dupFailed'    => __( 'Could not duplicate the template.', 'ffcertificate' ),
						'loadFailed'   => __( 'Could not load the template.', 'ffcertificate' ),
					)
				),
			)
		);
	}

	/**
	 * Render the Receipt tab panel: per-mode dropdown + inline HTML editor.
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
			<?php esc_html_e( 'Choose the template the appointment receipt (comprovante) PDF uses, separately for Regular and Custom calendars, and edit its HTML below. Duplicate a shipped/default template to create an editable copy.', 'ffcertificate' ); ?>
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
	 * Render one mode's section: label, template dropdown, duplicate button and
	 * the inline HTML editor.
	 *
	 * @param string                                                   $mode      'regular' | 'custom'.
	 * @param string                                                   $heading   Section heading.
	 * @param array<int, array{id:int, label:string, is_default:bool}> $templates Receipt-kind pool templates.
	 * @return void
	 */
	private function render_mode_section( string $mode, string $heading, array $templates ): void {
		$selected = self::selected_id( $mode );
		$editable = $this->is_editable( $selected );
		$html     = $this->html_for( $selected );

		$select_name = 'ffc_receipt_' . $mode;
		$html_name   = 'ffc_receipt_' . $mode . '_html';
		$editor_id   = 'ffc_receipt_' . $mode . '_editor';
		?>
		<div class="ffc-receipt-mode" data-mode="<?php echo esc_attr( $mode ); ?>">
			<h3><?php echo esc_html( $heading ); ?></h3>
			<p>
				<label for="<?php echo esc_attr( $select_name ); ?>"><strong><?php esc_html_e( 'Template:', 'ffcertificate' ); ?></strong></label>
				<select name="<?php echo esc_attr( $select_name ); ?>" id="<?php echo esc_attr( $select_name ); ?>" class="ffc-receipt-select">
					<option value="0"<?php selected( $selected, 0 ); ?>><?php esc_html_e( 'Shipped default', 'ffcertificate' ); ?></option>
					<?php foreach ( $templates as $tpl ) : ?>
						<option value="<?php echo esc_attr( (string) $tpl['id'] ); ?>" data-default="<?php echo $tpl['is_default'] ? '1' : '0'; ?>"<?php selected( $selected, (int) $tpl['id'] ); ?>>
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
				<button type="button" class="button ffc-receipt-duplicate" data-mode="<?php echo esc_attr( $mode ); ?>">
					<?php esc_html_e( 'Duplicate to edit', 'ffcertificate' ); ?>
				</button>
			</p>
			<p class="description ffc-receipt-note" data-mode="<?php echo esc_attr( $mode ); ?>">
				<?php
				echo esc_html(
					$editable
						? __( 'Editing this template’s HTML. Changes save with the form below.', 'ffcertificate' )
						: __( 'This is a shipped/default template — its HTML is read-only. Use “Duplicate to edit” to create an editable copy.', 'ffcertificate' )
				);
				?>
			</p>
			<div class="ffc-code-editor-wrapper">
				<textarea
					name="<?php echo esc_attr( $html_name ); ?>"
					id="<?php echo esc_attr( $editor_id ); ?>"
					class="ffc-receipt-editor ffc-w100"
					data-mode="<?php echo esc_attr( $mode ); ?>"
					rows="16"
					<?php echo $editable ? '' : 'readonly'; ?>><?php echo esc_textarea( $html ); ?></textarea>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX: return a template's HTML + editable flag for the inline editor.
	 *
	 * @return void
	 */
	public function ajax_load(): void {
		if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_forms' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ffcertificate' ) ), 403 );
		}
		check_ajax_referer( self::NONCE, 'nonce' );

		$id = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;

		wp_send_json_success(
			array(
				'html'     => $this->html_for( $id ),
				'editable' => $this->is_editable( $id ),
			)
		);
	}

	/**
	 * AJAX: duplicate a template (or the shipped default) into an editable copy.
	 *
	 * @return void
	 */
	public function ajax_duplicate(): void {
		if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_forms' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ffcertificate' ) ), 403 );
		}
		check_ajax_referer( self::NONCE, 'nonce' );

		$id   = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
		$html = $this->html_for( $id );

		if ( $id > 0 ) {
			$source = get_post( $id );
			$title  = $source instanceof \WP_Post ? (string) $source->post_title : __( 'Appointment receipt', 'ffcertificate' );
		} else {
			$title = __( 'Appointment receipt', 'ffcertificate' );
		}

		/* translators: %s: source template title */
		$new_title = sprintf( __( '%s (copy)', 'ffcertificate' ), $title );
		$new_id    = CertTemplateWriter::create( $new_title, $html, true, '', CertTemplateCpt::KIND_APPOINTMENT_RECEIPT );

		if ( $new_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Could not duplicate the template.', 'ffcertificate' ) ), 500 );
		}

		wp_send_json_success(
			array(
				'id'    => $new_id,
				'label' => $new_title,
				'html'  => $html,
			)
		);
	}

	/**
	 * Persist the per-mode selection + any edited HTML, then redirect to the tab.
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
			$id = isset( $_POST[ 'ffc_receipt_' . $mode ] ) ? absint( wp_unslash( $_POST[ 'ffc_receipt_' . $mode ] ) ) : 0;
			$id = $this->sanitize_receipt_id( $id );

			// Save edited HTML only for an editable (non-default) selected template.
			if ( $this->is_editable( $id ) && isset( $_POST[ 'ffc_receipt_' . $mode . '_html' ] ) ) {
				$raw  = (string) wp_unslash( $_POST[ 'ffc_receipt_' . $mode . '_html' ] );
				$body = wp_kses( $raw, \FreeFormCertificate\Core\HtmlPolicy::get_allowed_html_tags() );
				CertTemplateWriter::update_html( $id, $body );
			}

			$selection[ $mode ] = $id;
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
