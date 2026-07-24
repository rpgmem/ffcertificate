<?php
/**
 * Reregistration Admin
 *
 * Provides the admin interface for managing reregistration campaigns:
 * - List of campaigns with filters
 * - Create/edit campaign form
 * - View submissions per campaign (approve/reject/remind)
 *
 * Action handlers are delegated to focused classes:
 * - ReregistrationSubmissionActions (approve, reject, return, bulk)
 * - ReregistrationExportSource (batched CSV export via the #772 dispatcher)
 * - ReregistrationCustomFieldsPage (custom fields submenu)
 *
 * @package FreeFormCertificate\Reregistration
 * @since 4.11.0
 * @since 4.12.13  Extracted action handlers and custom fields page
 * @since 4.12.14  Extracted AJAX callbacks and submission details renderer
 */

declare(strict_types=1);

namespace FreeFormCertificate\Reregistration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reregistration Admin.
 *
 * @phpstan-import-type ReregistrationRow from ReregistrationRepository
 * @phpstan-import-type ReregistrationSubmissionRow from ReregistrationSubmissionReader
 * @phpstan-import-type CustomFieldRow from CustomFieldReader
 * @phpstan-import-type AudienceRow from \FreeFormCertificate\Audience\AudienceReader
 */
class ReregistrationAdmin {

	/**
	 * Menu slug.
	 */
	public const MENU_SLUG = 'ffc-reregistration';

	/**
	 * Required capability.
	 */
	private const CAPABILITY = 'ffc_manage_reregistration';

	/**
	 * Read-only "view" capability — the *só vê* tier of the 3-state model.
	 * Opens the campaigns list/submissions read-only; every write still
	 * requires {@see self::CAPABILITY}.
	 */
	private const VIEW_CAPABILITY = 'ffc_view_reregistration';

	/**
	 * Whether the current user can edit (create/update/delete) — the manage
	 * tier. WP admins always pass.
	 *
	 * @return bool
	 */
	private function can_edit(): bool {
		return \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( self::CAPABILITY );
	}

	/**
	 * AJAX handler (lazily created in init()).
	 *
	 * @var ReregistrationAjaxHandler|null
	 */
	private ?ReregistrationAjaxHandler $ajax_handler = null;

	/**
	 * Initialize admin hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		$this->ajax_handler = new ReregistrationAjaxHandler();

		add_action( 'admin_menu', array( $this, 'add_menu' ), 30 );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_ffc_generate_ficha', array( $this->ajax_handler, 'ajax_generate_ficha' ) );
		add_action( 'wp_ajax_ffc_rereg_count_members', array( $this->ajax_handler, 'ajax_count_members' ) );
		add_action( 'wp_ajax_ffc_view_submission_details', array( $this->ajax_handler, 'ajax_view_submission_details' ) );
	}

	/**
	 * Register admin menu pages.
	 *
	 * Creates a top-level "Reregistration" menu with submenus:
	 * - Campaigns (default landing page)
	 * - Custom Fields (per-audience field management)
	 *
	 * @return void
	 */
	public function add_menu(): void {
		add_menu_page(
			__( 'Reregistration', 'ffcertificate' ),
			__( 'Reregistration', 'ffcertificate' ),
			self::VIEW_CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_page' ),
			'dashicons-update-alt',
			// Float to keep the FFC block contiguous (26.1 → 26.2 → 26.3);
			// see Audience admin for rationale.
			26.2
		);

		// Rename auto-generated first submenu from "Reregistration" to "Campaigns".
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Campaigns', 'ffcertificate' ),
			__( 'Campaigns', 'ffcertificate' ),
			self::VIEW_CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);

		// Custom Fields submenu.
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Custom Fields', 'ffcertificate' ),
			__( 'Custom Fields', 'ffcertificate' ),
			self::CAPABILITY,
			'ffc-custom-fields',
			array( ReregistrationCustomFieldsPage::class, 'render' )
		);
	}

	/**
	 * Enqueue admin assets for reregistration pages.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		if ( strpos( $hook, self::MENU_SLUG ) === false && strpos( $hook, 'ffc-custom-fields' ) === false ) {
			return;
		}

		$s = \FreeFormCertificate\Core\AssetHelper::asset_suffix();

		// Make sure ffc-common.css is registered + available so the
		// dependency below resolves even when this page isn't matched
		// by AdminAssetsManager (post_type=empty + $_GET['page']
		// already starts with ffc-, but defensive).
		if ( function_exists( 'wp_style_is' ) && ! wp_style_is( 'ffc-common', 'registered' ) ) {
			wp_register_style(
				'ffc-common',
				FFC_PLUGIN_URL . "assets/css/ffc-common{$s}.css",
				array(),
				FFC_VERSION
			);
		}

		wp_enqueue_style(
			'ffc-reregistration-admin',
			FFC_PLUGIN_URL . "assets/css/ffc-reregistration-admin{$s}.css",
			array( 'ffc-common' ),
			FFC_VERSION
		);

		// Shared batched-export driver (#772): the submissions "Export CSV" button
		// drives the unified ffc_export_* dispatcher through window.FFCBatchedExport,
		// which needs FFC.request from ffc-core.
		wp_enqueue_script(
			'ffc-core',
			FFC_PLUGIN_URL . "assets/js/ffc-core{$s}.js",
			array( 'jquery' ),
			FFC_VERSION,
			true
		);
		wp_enqueue_script(
			'ffc-batched-export',
			FFC_PLUGIN_URL . "assets/js/ffc-batched-export{$s}.js",
			array( 'jquery', 'ffc-core' ),
			FFC_VERSION,
			true
		);
		// Shared progress-overlay modal styles (#786): the batched-export driver
		// renders window.FFCProgressOverlay, identical to the public download.
		wp_enqueue_style(
			'ffc-progress-overlay',
			FFC_PLUGIN_URL . "assets/css/ffc-progress-overlay{$s}.css",
			array(),
			FFC_VERSION
		);

		wp_enqueue_script(
			'ffc-reregistration-admin',
			FFC_PLUGIN_URL . "assets/js/ffc-reregistration-admin{$s}.js",
			array( 'jquery', 'ffc-core', 'ffc-batched-export' ),
			FFC_VERSION,
			true
		);

		wp_localize_script(
			'ffc-reregistration-admin',
			'ffcReregistrationAdmin',
			array(
				'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
				'adminNonce'       => wp_create_nonce( 'ffc_reregistration_nonce' ),
				'fichaNonce'       => wp_create_nonce( 'ffc_generate_ficha' ),
				'viewDetailsNonce' => wp_create_nonce( 'ffc_view_submission_details' ),
				'exportNonce'      => wp_create_nonce( 'ffc_reregistration_export' ),
				'strings'          => array(
					'exportPreparing'      => __( 'Preparing…', 'ffcertificate' ),
					/* translators: %1$d processed, %2$d total */
					'exportProgress'       => __( 'Exporting %1$d/%2$d…', 'ffcertificate' ),
					'exportDone'           => __( 'Done!', 'ffcertificate' ),
					'exportError'          => __( 'An error occurred.', 'ffcertificate' ),
					'confirmDelete'        => __( 'Are you sure you want to delete this reregistration? This will also delete all submissions.', 'ffcertificate' ),
					'confirmApprove'       => __( 'Approve selected submissions?', 'ffcertificate' ),
					'confirmReturnToDraft' => __( 'Return this submission to draft? The user will be able to edit and resubmit.', 'ffcertificate' ),
					'generatingPdf'        => __( 'Generating PDF...', 'ffcertificate' ),
					'errorGenerating'      => __( 'Error generating ficha.', 'ffcertificate' ),
					'ficha'                => __( 'Record', 'ffcertificate' ),
					'affectedUsers'        => __( 'Affected users:', 'ffcertificate' ),
					'loadingDetails'       => __( 'Loading…', 'ffcertificate' ),
					'errorLoadingDetails'  => __( 'Failed to load submission details.', 'ffcertificate' ),
				),
			)
		);

		// Enqueue PDF libraries on submissions view.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$view = \FreeFormCertificate\Core\RequestInput::get_get_string( 'view' );
		if ( 'submissions' === $view ) {
			wp_enqueue_script( 'html2canvas', FFC_PLUGIN_URL . 'libs/js/html2canvas.min.js', array(), FFC_HTML2CANVAS_VERSION, true );
			wp_enqueue_script( 'jspdf', FFC_PLUGIN_URL . 'libs/js/jspdf.umd.min.js', array(), FFC_JSPDF_VERSION, true );
			wp_enqueue_script( 'ffc-pdf-generator', FFC_PLUGIN_URL . 'assets/js/ffc-pdf-generator.min.js', array( 'html2canvas', 'jspdf' ), FFC_VERSION, true );
		}
	}

	/**
	 * Render the current page view.
	 *
	 * @return void
	 */
	public function render_page(): void {
		// 3-state: viewers (ffc_view_reregistration) reach the list/submissions
		// read-only; the campaign editor (new/edit) needs the manage cap.
		if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( self::VIEW_CAPABILITY ) ) {
			wp_die( esc_html__( 'Permission denied.', 'ffcertificate' ) );
		}

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$view = \FreeFormCertificate\Core\RequestInput::get_get_string( 'view', 'list' );

		// The editor form is a write surface — deny read-only viewers.
		if ( in_array( $view, array( 'new', 'edit' ), true ) && ! $this->can_edit() ) {
			wp_die( esc_html__( 'Permission denied.', 'ffcertificate' ) );
		}
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

		$can_edit = $this->can_edit();

		echo '<div class="wrap">';

		switch ( $view ) {
			case 'new':
			case 'edit':
				ReregistrationAdminRenderer::render_form( self::MENU_SLUG, $id );
				break;
			case 'submissions':
				ReregistrationAdminRenderer::render_submissions( self::MENU_SLUG, $id, $can_edit );
				break;
			default:
				ReregistrationAdminRenderer::render_list( self::MENU_SLUG, $can_edit );
		}

		echo '</div>';
	}

	// ─────────────────────────────────────────────.
	// ACTION HANDLERS.
	// ─────────────────────────────────────────────.

	/**
	 * Handle admin actions (save, delete, approve, reject, bulk).
	 *
	 * Delegates submission workflow actions to ReregistrationSubmissionActions.
	 * CSV export moved to the batched engine (#772) and no longer runs here.
	 *
	 * @return void
	 */
	public function handle_actions(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = \FreeFormCertificate\Core\RequestInput::get_get_string( 'page' );
		if ( self::MENU_SLUG !== $page ) {
			return;
		}

		// Show redirect messages.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['message'] ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$msg      = \FreeFormCertificate\Core\RequestInput::get_get_string( 'message' );
			$messages = array(
				'created'                => __( 'Reregistration created successfully.', 'ffcertificate' ),
				'updated'                => __( 'Reregistration updated successfully.', 'ffcertificate' ),
				'deleted'                => __( 'Reregistration deleted successfully.', 'ffcertificate' ),
				'approved'               => __( 'Submission approved.', 'ffcertificate' ),
				'rejected'               => __( 'Submission rejected.', 'ffcertificate' ),
				'returned_to_draft'      => __( 'Submission returned to draft for revision.', 'ffcertificate' ),
				'bulk_approved'          => __( 'Selected submissions approved.', 'ffcertificate' ),
				'bulk_returned_to_draft' => __( 'Selected submissions returned to draft.', 'ffcertificate' ),
				'reminders_sent'         => __( 'Reminder emails sent.', 'ffcertificate' ),
			);
			if ( isset( $messages[ $msg ] ) ) {
				add_settings_error( 'ffc_reregistration', 'ffc_message', $messages[ $msg ], 'success' );
			}
		}

		// Campaign CRUD.
		$this->handle_save();
		$this->handle_delete();

		// Submission workflow (delegated).
		ReregistrationSubmissionActions::handle_approve();
		ReregistrationSubmissionActions::handle_reject();
		ReregistrationSubmissionActions::handle_return_to_draft();
		ReregistrationSubmissionActions::handle_bulk();

		// CSV export moved to the batched engine (#772): the "Export CSV" button
		// drives the unified ffc_export_* dispatcher via ReregistrationExportSource
		// (registered in ReregistrationLoader), so there is no page-action handler
		// here any more.
	}

	/**
	 * Handle save (create/update) reregistration.
	 *
	 * @return void
	 */
	private function handle_save(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified immediately below.
		if ( ! isset( $_POST['ffc_action'] ) || 'save_reregistration' !== $_POST['ffc_action'] ) {
			return;
		}
		if ( ! wp_verify_nonce( \FreeFormCertificate\Core\RequestInput::get_post_string( 'ffc_reregistration_nonce' ), 'save_reregistration' ) ) {
			return;
		}

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$id          = isset( $_POST['reregistration_id'] ) ? absint( $_POST['reregistration_id'] ) : 0;
		$prev_status = null;

		if ( $id > 0 ) {
			$existing    = ReregistrationRepository::get_by_id( $id );
			$prev_status = $existing ? $existing->status : null;
		}

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified above (line 707).
		$data = array(
			'title'                      => \FreeFormCertificate\Core\RequestInput::get_post_string( 'rereg_title' ),
			'start_date'                 => \FreeFormCertificate\Core\RequestInput::get_post_string( 'rereg_start_date' ),
			'end_date'                   => \FreeFormCertificate\Core\RequestInput::get_post_string( 'rereg_end_date' ),
			'auto_approve'               => ! empty( $_POST['rereg_auto_approve'] ) ? 1 : 0,
			'email_invitation_enabled'   => ! empty( $_POST['rereg_email_invitation'] ) ? 1 : 0,
			'email_reminder_enabled'     => ! empty( $_POST['rereg_email_reminder'] ) ? 1 : 0,
			'email_confirmation_enabled' => ! empty( $_POST['rereg_email_confirmation'] ) ? 1 : 0,
			'reminder_days'              => isset( $_POST['rereg_reminder_days'] ) ? absint( $_POST['rereg_reminder_days'] ) : 7,
			'status'                     => \FreeFormCertificate\Core\RequestInput::get_post_string( 'rereg_status', 'draft' ),
		);

		// Collect audience IDs from transfer list hidden inputs.
		$audience_ids = array();
		if ( isset( $_POST['rereg_audience_ids'] ) && is_array( $_POST['rereg_audience_ids'] ) ) {
			$audience_ids = array_map( 'absint', $_POST['rereg_audience_ids'] );
		}
        // phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( $id > 0 ) {
			ReregistrationRepository::update( $id, $data );
			ReregistrationRepository::set_audience_ids( $id, $audience_ids );

			// If transitioning to active, create submissions for members and send invitations.
			if ( 'active' === $data['status'] && 'active' !== $prev_status ) {
				ReregistrationSubmissionWriter::create_for_audience_members( $id, $audience_ids );
				ReregistrationEmailHandler::send_invitations( $id );
			}

			wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&view=edit&id=' . $id . '&message=updated' ) );
			exit;
		} else {
			$new_id = ReregistrationRepository::create( $data );
			if ( $new_id ) {
				ReregistrationRepository::set_audience_ids( $new_id, $audience_ids );

				// If creating as active, also create submissions and send invitations.
				if ( 'active' === $data['status'] ) {
					ReregistrationSubmissionWriter::create_for_audience_members( $new_id, $audience_ids );
					ReregistrationEmailHandler::send_invitations( $new_id );
				}

				wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&view=edit&id=' . $new_id . '&message=created' ) );
				exit;
			}
		}
	}

	/**
	 * Handle delete reregistration.
	 *
	 * @return void
	 */
	private function handle_delete(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['action'] ) || 'delete' !== $_GET['action'] || ! isset( $_GET['id'] ) ) {
			return;
		}

		// Deleting a campaign cascades to its submissions — gated by the
		// dedicated destructive cap (GAP E), not the page-level manage cap.
		if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_delete_reregistration' ) ) {
			wp_die( esc_html__( 'You do not have permission to delete reregistration campaigns.', 'ffcertificate' ) );
		}

		$id = absint( $_GET['id'] );
		if ( ! wp_verify_nonce( \FreeFormCertificate\Core\RequestInput::get_get_string( '_wpnonce' ), 'delete_reregistration_' . $id ) ) {
			return;
		}

		ReregistrationRepository::delete( $id );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&message=deleted' ) );
		exit;
	}

	/**
	 * Return the AJAX handler, creating it on first access.
	 *
	 * Allows the thin delegators below to work when callers (notably tests)
	 * invoke AJAX methods directly on the facade without going through init().
	 *
	 * @return ReregistrationAjaxHandler
	 */
	private function get_ajax_handler(): ReregistrationAjaxHandler {
		if ( null === $this->ajax_handler ) {
			$this->ajax_handler = new ReregistrationAjaxHandler();
		}
		return $this->ajax_handler;
	}

	/**
	 * AJAX: Generate ficha PDF data for a submission.
	 *
	 * Thin delegator to ReregistrationAjaxHandler; preserves the facade's
	 * public surface so existing direct callers keep working.
	 *
	 * @return void
	 */
	public function ajax_generate_ficha(): void {
		$this->get_ajax_handler()->ajax_generate_ficha();
	}

	/**
	 * AJAX: Return HTML with the full submission detail grouped by fieldset.
	 *
	 * Thin delegator to ReregistrationAjaxHandler.
	 *
	 * @return void
	 */
	public function ajax_view_submission_details(): void {
		$this->get_ajax_handler()->ajax_view_submission_details();
	}

	/**
	 * AJAX: Count members for a set of audience IDs.
	 *
	 * Thin delegator to ReregistrationAjaxHandler.
	 *
	 * @return void
	 */
	public function ajax_count_members(): void {
		$this->get_ajax_handler()->ajax_count_members();
	}
}
