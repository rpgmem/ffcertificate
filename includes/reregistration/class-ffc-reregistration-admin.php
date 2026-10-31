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

use FreeFormCertificate\Core\RequestInput;

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
	 * `?page=` of the Custom Fields submenu.
	 */
	public const CUSTOM_FIELDS_SLUG = 'ffc-custom-fields';

	/**
	 * Required capability.
	 */
	private const CAPABILITY = 'ffc_manage_reregistration';

	/**
	 * Read-only "view" capability — the *view only* tier of the 3-state model.
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
	 * Whether the current user can open the campaigns screen — the view OR
	 * the manage tier (the 3-state model: a manage grant does not also need
	 * the view one). WP admins always pass.
	 *
	 * @return bool
	 */
	private function can_view(): bool {
		return \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( self::VIEW_CAPABILITY ) || $this->can_edit();
	}

	/**
	 * The capability the Reregistration menu is registered with.
	 *
	 * WordPress gates a menu on ONE capability, while the screen opens for
	 * view OR manage, so the menu names whichever of the two the user holds.
	 *
	 * @return string
	 */
	private function menu_capability(): string {
		return ! current_user_can( self::VIEW_CAPABILITY ) && current_user_can( self::CAPABILITY ) ? self::CAPABILITY : self::VIEW_CAPABILITY;
	}

	/**
	 * AJAX handler (lazily created in init()).
	 *
	 * @var ReregistrationAjaxHandler|null
	 */
	private ?ReregistrationAjaxHandler $ajax_handler = null;

	/**
	 * CSV-import endpoints (#1214).
	 *
	 * Held so PHPStan does not read it as write-only; the object registers its
	 * own four actions and is otherwise never touched from here.
	 *
	 * @var ReregistrationImportAjaxHandler|null
	 */
	private ?ReregistrationImportAjaxHandler $import_ajax_handler = null;

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
		add_action( 'wp_ajax_ffc_generate_record', array( $this->ajax_handler, 'ajax_generate_record' ) );
		add_action( 'wp_ajax_ffc_rereg_count_members', array( $this->ajax_handler, 'ajax_count_members' ) );
		add_action( 'wp_ajax_ffc_rereg_send_invitations', array( $this->ajax_handler, 'ajax_send_invitations' ) );
		add_action( 'wp_ajax_ffc_view_submission_details', array( $this->ajax_handler, 'ajax_view_submission_details' ) );

		// Registered HERE rather than from `add_menu()`, because `admin_menu`
		// does not fire on `admin-ajax.php` -- which is the only request these
		// four ever serve. The same mistake the #772 export contract records
		// for a source registered too late.
		$this->import_ajax_handler = new ReregistrationImportAjaxHandler();
		$this->import_ajax_handler->register();
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
		$menu_cap = $this->menu_capability();

		add_menu_page(
			\FreeFormCertificate\Core\PluginAreas::label( 'reregistration' ),
			\FreeFormCertificate\Core\PluginAreas::label( 'reregistration' ),
			$menu_cap,
			self::MENU_SLUG,
			array( $this, 'render_page' ),
			'none', // Drawn from the icon registry by AdminMenuIcons (#1640).
			// Float to keep the FFC block contiguous (26.1 → 26.2 → 26.3);
			// see Audience admin for rationale.
			26.2
		);

		// Rename auto-generated first submenu from "Reregistration" to "Campaigns".
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Campaigns', 'ffcertificate' ),
			__( 'Campaigns', 'ffcertificate' ),
			$menu_cap,
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);

		// Custom Fields submenu.
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Custom Fields', 'ffcertificate' ),
			__( 'Custom Fields', 'ffcertificate' ),
			self::CAPABILITY,
			self::CUSTOM_FIELDS_SLUG,
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

		// The palette, so the dependency below resolves even when this page
		// isn't matched by AdminAssetsManager (post_type=empty + $_GET['page']
		// already starts with ffc-, but defensive).
		\FreeFormCertificate\Core\AssetHelper::enqueue_common_style();

		wp_enqueue_style(
			'ffc-reregistration-admin',
			FFC_PLUGIN_URL . "assets/css/ffc-reregistration-admin{$s}.css",
			array( 'ffc-common' ),
			FFC_VERSION
		);

		// Shared batched-export driver (#772): the submissions "Export CSV" button
		// drives the unified ffc_export_* dispatcher through window.FFCBatchedExport,
		// which needs FFC.request from ffc-core.
		\FreeFormCertificate\Core\AssetRegistry::enqueue_scripts( 'ffc-core', 'ffc-batched-export' );
		// Shared progress-overlay modal styles (#786): the batched-export driver
		// renders window.FFCProgressOverlay, identical to the public download.
		\FreeFormCertificate\Core\AssetRegistry::enqueue_styles( 'ffc-progress-overlay' );

		// The campaign form's audience picker is the shared component (#1648).
		\FreeFormCertificate\Audience\AudienceTransferList::enqueue();

		// The campaign's toggles save per record (#1757).
		\FreeFormCertificate\Core\RecordAutosave::enqueue();

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
				// The nonce ACTION string stays `ffc_generate_ficha` on purpose
				// (#1264): it is an opaque salt nobody reads, and renaming it
				// would reject the nonce on every page already rendered at
				// upgrade time -- a real breakage window bought for nothing.
				'recordNonce'      => wp_create_nonce( 'ffc_generate_ficha' ),
				'viewDetailsNonce' => wp_create_nonce( 'ffc_view_submission_details' ),
				'exportNonce'      => wp_create_nonce( 'ffc_reregistration_export' ),
				'strings'          => array(
					'inviteSending'       => __( 'Sending…', 'ffcertificate' ),
					'inviteError'         => __( 'An error occurred.', 'ffcertificate' ),
					'exportPreparing'     => __( 'Preparing…', 'ffcertificate' ),
					/* translators: %1$d processed, %2$d total */
					'exportProgress'      => __( 'Exporting %1$d/%2$d…', 'ffcertificate' ),
					'exportDone'          => __( 'Done!', 'ffcertificate' ),
					'exportError'         => __( 'An error occurred.', 'ffcertificate' ),
					'confirmDelete'       => __( 'Are you sure you want to delete this reregistration? This will also delete all submissions.', 'ffcertificate' ),
					'confirmApprove'      => __( 'Approve selected submissions?', 'ffcertificate' ),
					'reviewRejectTitle'   => __( 'Reject submission', 'ffcertificate' ),
					'reviewReturnTitle'   => __( 'Return to draft', 'ffcertificate' ),
					/* translators: %d: number of selected submissions. */
					'reviewRejectIntro'   => __( 'Selected: %d. The member is told the reason and can correct and resubmit until the deadline. The verification code is deleted.', 'ffcertificate' ),
					/* translators: %d: number of selected submissions. */
					'reviewReturnIntro'   => __( 'Selected: %d. The submission goes back to the member as a draft with its answers, and the member is told the reason.', 'ffcertificate' ),
					'generatingPdf'       => __( 'Generating PDF...', 'ffcertificate' ),
					'errorGenerating'     => __( 'Error generating record.', 'ffcertificate' ),
					'record'              => __( 'Record', 'ffcertificate' ),
					'affectedUsers'       => __( 'Affected users:', 'ffcertificate' ),
					'loadingDetails'      => __( 'Loading…', 'ffcertificate' ),
					'errorLoadingDetails' => __( 'Failed to load submission details.', 'ffcertificate' ),
				),
			)
		);

		$view = \FreeFormCertificate\Core\RequestInput::get_get_string( 'view' );

		// The import client rides only the campaign editor, which is the one
		// screen that prints the panel. Gating it on the view rather than on
		// the capability keeps the two halves in step: a user without the cap
		// gets no panel from `render_form()` either, and a script with no
		// markup to bind to is dead weight on every other screen of the menu.
		if ( in_array( $view, array( 'new', 'edit' ), true ) ) {
			wp_enqueue_script(
				'ffc-reregistration-import',
				FFC_PLUGIN_URL . "assets/js/ffc-reregistration-import{$s}.js",
				array( 'jquery', 'ffc-core' ),
				FFC_VERSION,
				true
			);

			wp_localize_script(
				'ffc-reregistration-import',
				'ffcReregImport',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( ReregistrationImportAjaxHandler::NONCE_ACTION ),
					'strings' => array(
						'chooseFile'   => __( 'Choose a CSV file.', 'ffcertificate' ),
						'staging'      => __( 'Reading the file…', 'ffcertificate' ),
						'validating'   => __( 'Checking every row…', 'ffcertificate' ),
						'ready'        => __( 'Ready. Press Import to write these rows.', 'ffcertificate' ),
						'blocked'      => __( 'Nothing was imported. Fix these lines and check the file again:', 'ffcertificate' ),
						'countTotal'   => __( 'Rows in the file:', 'ffcertificate' ),
						'countReady'   => __( 'Will be imported:', 'ffcertificate' ),
						'countSkipped' => __( 'Already submitted, kept as is:', 'ffcertificate' ),
						'countFailed'  => __( 'Failing:', 'ffcertificate' ),
						/* translators: 1: rows written so far, 2: rows in the file. */
						'importing'    => __( 'Importing %1$d/%2$d…', 'ffcertificate' ),
						'finishing'    => __( 'Finishing…', 'ffcertificate' ),
						/* translators: 1: rows written, 2: rows skipped because the person had already submitted. */
						'done'         => __( 'Imported %1$d. Skipped %2$d.', 'ffcertificate' ),
						// Said HERE because it is the only moment before the
						// invitation is sent (#1300). The e-mail itself adapts
						// its wording per recipient, so this states what the
						// operator is about to cause rather than warning them
						// off doing it.
						'afterImport'  => __( 'If you send the campaign invitation, these people receive it too — it will tell them their reregistration is already recorded and link to their dashboard, not ask them to fill anything in.', 'ffcertificate' ),
						'error'        => __( 'An error occurred.', 'ffcertificate' ),
						'network'      => __( 'The server could not be reached.', 'ffcertificate' ),
					),
				)
			);
		}

		// Enqueue PDF libraries on submissions view.
		if ( 'submissions' === $view ) {
			\FreeFormCertificate\Core\AssetRegistry::enqueue_scripts( 'html2canvas', 'jspdf', 'ffc-pdf-generator' );
		}
	}

	/**
	 * Render the current page view.
	 *
	 * @return void
	 */
	public function render_page(): void {
		// 3-state: viewers (ffc_view_reregistration) reach the list/submissions
		// read-only; the campaign editor (new/edit) needs the manage cap, and
		// a manage-only role opens the screen without the view cap (#1698).
		if ( ! $this->can_view() ) {
			wp_die( esc_html__( 'Permission denied.', 'ffcertificate' ) );
		}

		$view = \FreeFormCertificate\Core\RequestInput::get_get_string( 'view', 'list' );

		// The editor form is a write surface — deny read-only viewers.
		if ( in_array( $view, array( 'new', 'edit' ), true ) && ! $this->can_edit() ) {
			wp_die( esc_html__( 'Permission denied.', 'ffcertificate' ) );
		}
		$id = RequestInput::get_get_int( 'id' );

		$can_edit = $this->can_edit();

		// The campaign editor is a screen of boxed sections (`.ffc-boxed`,
		// #1629); the list and submissions keep the plain list-table screen.
		$boxed = in_array( $view, array( 'new', 'edit' ), true );
		echo '<div class="wrap ffc-admin-page ffc-page-reregistration ' . ( $boxed ? 'ffc-boxed' : '' ) . '">';

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

		$page = \FreeFormCertificate\Core\RequestInput::get_get_string( 'page' );
		if ( self::MENU_SLUG !== $page ) {
			return;
		}

		// Show redirect messages.
		if ( RequestInput::has_get( 'message' ) ) {
			$msg      = \FreeFormCertificate\Core\RequestInput::get_get_string( 'message' );
			$messages = array(
				'created'           => __( 'Reregistration created successfully.', 'ffcertificate' ),
				'updated'           => __( 'Reregistration updated successfully.', 'ffcertificate' ),
				'deleted'           => __( 'Reregistration deleted successfully.', 'ffcertificate' ),
				'approved'          => __( 'Submission approved.', 'ffcertificate' ),
				'rejected'          => __( 'Submission rejected.', 'ffcertificate' ),
				'returned_to_draft' => __( 'Submission returned to draft for revision.', 'ffcertificate' ),
				'bulk_approved'     => __( 'Selected submissions approved.', 'ffcertificate' ),
				'reminders_sent'    => __( 'Reminder emails sent.', 'ffcertificate' ),
			);
			if ( 'bulk_approved' === $msg && RequestInput::has_get( 'skipped' ) ) {
				$messages[ $msg ] = self::bulk_approved_message( RequestInput::get_get_int( 'count' ), RequestInput::get_get_int( 'skipped' ) );
			}
			if ( in_array( $msg, array( 'rejected', 'returned_to_draft' ), true ) && RequestInput::has_get( 'skipped' ) ) {
				$messages[ $msg ] = self::review_decision_message( $msg, RequestInput::get_get_int( 'count' ), RequestInput::get_get_int( 'skipped' ) );
			}
			if ( 'reminders_sent' === $msg && RequestInput::has_get( 'skipped' ) ) {
				$messages[ $msg ] = self::reminders_message( RequestInput::get_get_int( 'count' ), RequestInput::get_get_int( 'skipped' ), RequestInput::get_get_int( 'failed' ) );
			}
			if ( 'reminders_blocked' === $msg ) {
				add_settings_error( 'ffc_reregistration', 'ffc_message', self::reminders_blocked_message( RequestInput::get_get_string( 'reason' ) ), 'error' );
			}
			if ( 'reason_required' === $msg ) {
				add_settings_error( 'ffc_reregistration', 'ffc_message', self::reason_required_message(), 'error' );
			}
			if ( isset( $messages[ $msg ] ) ) {
				add_settings_error( 'ffc_reregistration', 'ffc_message', $messages[ $msg ], 'success' );
			}
		}

		if ( 'scheduled' === \FreeFormCertificate\Core\RequestInput::get_get_string( 'invitations' ) ) {
			add_settings_error( 'ffc_reregistration', 'ffc_invitations_scheduled', self::invitations_scheduled_message(), 'info' );
		}

		// Campaign CRUD.
		$this->handle_save();
		$this->handle_delete();

		// Submission workflow (delegated).
		ReregistrationSubmissionActions::handle_approve();
		ReregistrationSubmissionActions::handle_review_decision();
		ReregistrationSubmissionActions::handle_bulk();

		// CSV export moved to the batched engine (#772): the "Export CSV" button
		// drives the unified ffc_export_* dispatcher via ReregistrationExportSource
		// (registered in ReregistrationLoader), so there is no page-action handler
		// here any more.
	}

	/**
	 * The redirect argument that asks the edit screen to announce the
	 * invitations: present only when the campaign sends them.
	 *
	 * @param array<string, mixed> $data The saved campaign fields.
	 * @return string `&invitations=scheduled`, or ''.
	 */
	private static function invitations_notice_arg( array $data ): string {
		return empty( $data['email_invitation_enabled'] ) ? '' : '&invitations=scheduled';
	}

	/**
	 * The notice after a reject or a return to draft (#2242).
	 *
	 * @param string $decision `rejected` or `returned_to_draft`.
	 * @param int    $done     Rows changed.
	 * @param int    $skipped  Selected rows the decision did not apply to.
	 * @return string
	 */
	public static function review_decision_message( string $decision, int $done, int $skipped ): string {
		$message = 'rejected' === $decision
			/* translators: %d: number of submissions rejected. */
			? sprintf( _n( '%d submission rejected.', '%d submissions rejected.', $done, 'ffcertificate' ), $done )
			/* translators: %d: number of submissions returned to draft. */
			: sprintf( _n( '%d submission returned to draft.', '%d submissions returned to draft.', $done, 'ffcertificate' ), $done );
		if ( $skipped > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of selected submissions the decision did not apply to. */
				_n( '%d selected submission was skipped because its status does not allow it.', '%d selected submissions were skipped because their status does not allow it.', $skipped, 'ffcertificate' ),
				$skipped
			);
		}
		return $message;
	}

	/**
	 * The notice when a reject or a return arrives without a usable reason.
	 *
	 * @return string
	 */
	public static function reason_required_message(): string {
		return sprintf(
			/* translators: %d: minimum number of characters. */
			__( 'Nothing was changed: give the member a reason of at least %d characters.', 'ffcertificate' ),
			ReregistrationSubmissionWriter::MIN_REASON_LENGTH
		);
	}

	/**
	 * The notice after a bulk reminder (#2242): how many went out, and how
	 * many of the selection did not, with why.
	 *
	 * @param int $sent    Reminders sent.
	 * @param int $skipped Selected rows not awaiting an answer.
	 * @param int $failed  Reminders the mailer did not accept.
	 * @return string
	 */
	public static function reminders_message( int $sent, int $skipped, int $failed ): string {
		/* translators: %d: number of reminder e-mails sent. */
		$message = sprintf( _n( '%d reminder sent.', '%d reminders sent.', $sent, 'ffcertificate' ), $sent );
		if ( $skipped > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of selected submissions that were not awaiting an answer. */
				_n( '%d selected submission was skipped because it is not awaiting an answer.', '%d selected submissions were skipped because they are not awaiting an answer.', $skipped, 'ffcertificate' ),
				$skipped
			);
		}
		if ( $failed > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of reminders the mailer did not send. */
				_n( '%d reminder could not be sent (the member has no account or e-mail, or the mailer refused it).', '%d reminders could not be sent (the member has no account or e-mail, or the mailer refused it).', $failed, 'ffcertificate' ),
				$failed
			);
		}
		return $message;
	}

	/**
	 * The notice when no reminder of the campaign can go out at all.
	 *
	 * @param string $reason A `ReregistrationEmailHandler::REMINDER_BLOCKED_*` value.
	 * @return string
	 */
	public static function reminders_blocked_message( string $reason ): string {
		switch ( $reason ) {
			case ReregistrationEmailHandler::REMINDER_BLOCKED_EMAILS_OFF:
				return __( 'No reminder was sent: every plugin e-mail is disabled in the settings.', 'ffcertificate' );
			case ReregistrationEmailHandler::REMINDER_BLOCKED_NO_TEXT:
				return __( 'No reminder was sent: the reminder e-mail text is empty.', 'ffcertificate' );
			default:
				return __( 'No reminder was sent: reminders are turned off for this campaign.', 'ffcertificate' );
		}
	}

	/**
	 * The bulk-approval notice (#2242).
	 *
	 * Bulk approval acts only on submissions awaiting review, so the notice
	 * says how many of the selection it left alone.
	 *
	 * @param int $approved Rows approved.
	 * @param int $skipped  Selected rows not awaiting review.
	 * @return string
	 */
	public static function bulk_approved_message( int $approved, int $skipped ): string {
		/* translators: %d: number of submissions approved. */
		$message = sprintf( _n( '%d submission approved.', '%d submissions approved.', $approved, 'ffcertificate' ), $approved );
		if ( $skipped > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of selected submissions that were not awaiting review. */
				_n( '%d selected submission was skipped because it was not awaiting review.', '%d selected submissions were skipped because they were not awaiting review.', $skipped, 'ffcertificate' ),
				$skipped
			);
		}
		return $message;
	}

	/**
	 * What the operator is told after activating a campaign that sends
	 * invitations (#1762): nobody was mailed yet, and when they will be.
	 *
	 * The time is the next run of the daily reregistration event, which is
	 * the one that sends them. When it is not scheduled -- WP-Cron cleared, a
	 * deactivation half-undone -- the sentence says so instead of inventing a
	 * time.
	 *
	 * @return string
	 */
	public static function invitations_scheduled_message(): string {
		$next = wp_next_scheduled( 'ffc_reregistration_expire' );

		if ( false === $next ) {
			return __( 'No invitation was sent yet. They go out with the daily reregistration run, which is not scheduled on this site right now; check Settings → Scheduled Tasks.', 'ffcertificate' );
		}

		return sprintf(
			/* translators: %s: date and time of the next daily run */
			__( 'No invitation was sent yet. They go out with the next daily reregistration run, on %s, in batches. Until then you can still change or deactivate the campaign.', 'ffcertificate' ),
			\FreeFormCertificate\Core\DateFormatter::format_datetime( (int) $next )
		);
	}

	/**
	 * Handle save (create/update) reregistration.
	 *
	 * @return void
	 */
	private function handle_save(): void {
		if ( ! isset( $_POST['ffc_action'] ) || 'save_reregistration' !== $_POST['ffc_action'] ) {
			return;
		}
		if ( ! wp_verify_nonce( \FreeFormCertificate\Core\RequestInput::get_post_string( 'ffc_reregistration_nonce' ), 'save_reregistration' ) ) {
			return;
		}

		$id          = isset( $_POST['reregistration_id'] ) ? absint( $_POST['reregistration_id'] ) : 0;
		$prev_status = null;

		if ( $id > 0 ) {
			$existing    = ReregistrationRepository::get_by_id( $id );
			$prev_status = $existing ? $existing->status : null;
		}

		// An emptied number field is "not supplied", never zero. `absint( '' )`
		// is 0, and the reminder sweep reads
		// `DATEDIFF(end_date, <site date>) <= reminder_days`, so a cleared field
		// would quietly move every reminder to the campaign's last day — late
		// enough to be useless, with nothing on screen saying so. The input
		// declares `min="1"`, so a deliberately typed 0 lands on that floor
		// (#1117; the shape #1114 fixed on the Rate Limit tab).
		$ffc_reminder_raw = \FreeFormCertificate\Core\RequestInput::get_post_string( 'rereg_reminder_days', '' );

		$data = array(
			'title'                      => \FreeFormCertificate\Core\RequestInput::get_post_string( 'rereg_title' ),
			'start_date'                 => \FreeFormCertificate\Core\RequestInput::get_post_string( 'rereg_start_date' ),
			'end_date'                   => \FreeFormCertificate\Core\RequestInput::get_post_string( 'rereg_end_date' ),
			'auto_approve'               => ! empty( $_POST['rereg_auto_approve'] ) ? 1 : 0,
			'email_invitation_enabled'   => ! empty( $_POST['rereg_email_invitation'] ) ? 1 : 0,
			'email_reminder_enabled'     => ! empty( $_POST['rereg_email_reminder'] ) ? 1 : 0,
			'email_confirmation_enabled' => ! empty( $_POST['rereg_email_confirmation'] ) ? 1 : 0,
			'reminder_days'              => '' === $ffc_reminder_raw ? 7 : max( 1, absint( $ffc_reminder_raw ) ),
			'status'                     => \FreeFormCertificate\Core\RequestInput::get_post_string( 'rereg_status', 'draft' ),
		);

		// The window has to be a window: an end on or before the start would
		// leave a campaign nobody can ever answer (#2242).
		$ffc_start = ReregistrationWindow::normalise( (string) $data['start_date'] );
		$ffc_end   = ReregistrationWindow::normalise( (string) $data['end_date'] );
		if ( '' === $ffc_start || '' === $ffc_end || $ffc_end <= $ffc_start ) {
			add_settings_error( 'ffc_reregistration', 'ffc_window', __( 'The end date must be after the start date. Nothing was saved.', 'ffcertificate' ), 'error' );
			return;
		}

		// Collect audience IDs from transfer list hidden inputs.
		$audience_ids = array();
		if ( isset( $_POST['rereg_audience_ids'] ) && is_array( $_POST['rereg_audience_ids'] ) ) {
			$audience_ids = array_map( 'absint', $_POST['rereg_audience_ids'] );
		}

		if ( $id > 0 ) {
			ReregistrationRepository::update( $id, $data );
			ReregistrationRepository::set_audience_ids( $id, $audience_ids );

			/*
			 * Transition to active: reopen, then seed, then invite — the order
			 * matters and is the whole fix for #1125.
			 *
			 * `create_for_audience_members()` skips a user who already has a
			 * submission, so on a *reactivation* it does nothing for everyone
			 * from the previous cycle: they still carry the `expired` that
			 * `expire_overdue()` wrote, a status that neither lets them submit
			 * nor draws a banner. Reopening first puts them back on `pending`,
			 * which is also the status `send_invitations()` queries — so the
			 * invitation email, silent on reactivation for the same reason,
			 * starts working without a second fix.
			 *
			 * Seeding still runs after, for members added since.
			 *
			 * The invitations themselves no longer go out here (#1762): the
			 * daily run sends them, so a campaign activated by mistake can be
			 * taken back before anybody is mailed. The redirect carries a flag
			 * so the screen says when that run is.
			 */
			$notice = '';
			if ( 'active' === $data['status'] && 'active' !== $prev_status ) {
				ReregistrationRepository::reopen_expired_submissions( $id );
				ReregistrationSubmissionWriter::create_for_audience_members( $id, $audience_ids );
				$notice = self::invitations_notice_arg( $data );
			} elseif ( 'active' === $data['status'] ) {
				// Saving an active campaign seeds too (#2242): an audience added
				// to it gets its rows now, not only on the next daily run.
				ReregistrationSubmissionWriter::create_for_audience_members( $id, $audience_ids );
			}

			wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&view=edit&id=' . $id . '&message=updated' . $notice ) );
			exit;
		} else {
			$new_id = ReregistrationRepository::create( $data );
			if ( $new_id ) {
				ReregistrationRepository::set_audience_ids( $new_id, $audience_ids );

				// Created as active: seed the submissions now; the invitations
				// wait for the daily run, as on activation (#1762).
				$notice = '';
				if ( 'active' === $data['status'] ) {
					ReregistrationSubmissionWriter::create_for_audience_members( $new_id, $audience_ids );
					$notice = self::invitations_notice_arg( $data );
				}

				wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&view=edit&id=' . $new_id . '&message=created' . $notice ) );
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
	 * AJAX: Generate record PDF data for a submission.
	 *
	 * Thin delegator to ReregistrationAjaxHandler; preserves the facade's
	 * public surface so existing direct callers keep working.
	 *
	 * @return void
	 */
	public function ajax_generate_record(): void {
		$this->get_ajax_handler()->ajax_generate_record();
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
