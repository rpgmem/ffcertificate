<?php
/**
 * Admin
 * v2.10.0: ENCRYPTION - Shows LGPD consent status, data auto-decrypted by Submission Handler
 *
 * @package FreeFormCertificate\Admin
 * @version 4.0.0 - Removed alias usage (Phase 4 Hotfix 7)
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace (Phase 2)
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

use FreeFormCertificate\Admin\FormEditor;
use FreeFormCertificate\Admin\Settings;
use FreeFormCertificate\Migrations\MigrationManager;
use FreeFormCertificate\Admin\AdminAssetsManager;
use FreeFormCertificate\Admin\AdminSubmissionEditPage;
use FreeFormCertificate\Admin\AdminActivityLogPage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin.
 */
class Admin {

	/**
	 * Submission handler.
	 *
	 * @var \FreeFormCertificate\Submissions\SubmissionHandler
	 */
	private $submission_handler;
	/**
	 * Csv exporter.
	 *
	 * @var CsvExporter
	 */
	private $csv_exporter;
	/**
	 * Form editor.
	 *
	 * @var FormEditor
	 */
	private $form_editor; // @phpstan-ignore property.onlyWritten
	/**
	 * Settings page.
	 *
	 * @var Settings
	 */
	private $settings_page; // @phpstan-ignore property.onlyWritten
	/**
	 * Migration manager.
	 *
	 * @var MigrationManager|null
	 */
	private $migration_manager;
	/**
	 * Assets manager.
	 *
	 * @var AdminAssetsManager
	 */
	private $assets_manager;
	/**
	 * Edit page.
	 *
	 * @var AdminSubmissionEditPage
	 */
	private $edit_page;
	/**
	 * Activity log page.
	 *
	 * @var AdminActivityLogPage
	 */
	private $activity_log_page;

	/**
	 * Constructor.
	 *
	 * Constructor.
	 *
	 * Constructor.
	 *
	 * Constructor.
	 *
	 * Constructor.
	 *
	 * Constructor.
	 *
	 * Constructor.
	 *
	 * Constructor.
	 *
	 * Constructor.
	 *
	 * Constructor.
	 *
	 * Constructor.
	 *
	 * Constructor.
	 *
	 * Constructor.
	 *
	 * Constructor.
	 *
	 * Constructor.
	 *
	 * Constructor.
	 *
	 * Constructor.
	 *
	 * Constructor.
	 *
	 * Constructor.
	 *
	 * Constructor.
	 *
	 * Constructor.
	 *
	 * Constructor.
	 *
	 * Constructor.
	 *
	 * Constructor.
	 *
	 * Constructor.
	 *
	 * Constructor.
	 *
	 * Constructor.
	 *
	 * Constructor.
	 *
	 * Constructor.
	 *
	 * Constructor.
	 *
	 * Constructor.
	 *
	 * Constructor.
	 *
	 * Constructor.
	 *
	 * Constructor.
	 *
	 * Constructor.
	 *
	 * Constructor.
	 *
	 * Constructor.
	 *
	 * Constructor.
	 *
	 * Constructor.
	 *
	 * Constructor.
	 *
	 * Constructor.
	 *
	 * @param \FreeFormCertificate\Submissions\SubmissionHandler $handler Handler.
	 * @param object                                             $exporter Exporter.
	 * @phpstan-param CsvExporter $exporter
	 */
	public function __construct( \FreeFormCertificate\Submissions\SubmissionHandler $handler, object $exporter ) {
		$this->submission_handler = $handler;
		$this->csv_exporter       = $exporter;

		// Autoloader handles class loading.
		$this->form_editor   = new FormEditor();
		$this->settings_page = new Settings( $handler );

		$this->assets_manager = new AdminAssetsManager();
		$this->assets_manager->register();
		$this->edit_page         = new AdminSubmissionEditPage( $handler );
		$this->activity_log_page = new AdminActivityLogPage();

		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );

		// Scope the TinyMCE placeholder-protection filter to the ffc_form editor screen only.
		// Registered on `admin_head` because `get_current_screen()` is not available at construction time.
		add_action( 'admin_head', array( $this, 'maybe_register_tinymce_placeholder_filter' ) );

		add_action( 'admin_init', array( $this, 'handle_submission_actions' ) );
		add_action( 'admin_init', array( $this, 'handle_submission_edit_save' ) );
		add_action( 'admin_init', array( $this, 'handle_migration_action' ) );

		// AJAX-driven CSV export (avoids web-server timeouts with large datasets).
		$this->csv_exporter->register_ajax_hooks();
	}

	/**
	 * Register admin menu.
	 */
	public function register_admin_menu(): void {
		add_submenu_page(
			'edit.php?post_type=ffc_form',
			__( 'Submissions', 'ffcertificate' ),
			__( 'Submissions', 'ffcertificate' ),
			'manage_options',
			'ffc-submissions',
			array( $this, 'display_submissions_page' )
		);

		$this->activity_log_page->register_menu();
	}

	/**
	 * Handle submission actions.
	 */
	public function handle_submission_actions(): void {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Nonce verified per-action below via wp_verify_nonce and check_admin_referer.
		if ( ! isset( $_GET['page'] ) || sanitize_text_field( wp_unslash( $_GET['page'] ) ) !== 'ffc-submissions' ) {
			return;
		}

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- isset() existence checks only.
		if ( isset( $_GET['submission_id'] ) && isset( $_GET['action'] ) ) {
			$id                   = absint( wp_unslash( $_GET['submission_id'] ) );
			$action               = sanitize_key( wp_unslash( $_GET['action'] ) );
			$nonce                = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
			$manipulation_actions = array( 'trash', 'restore', 'delete' );

			if ( in_array( $action, $manipulation_actions, true ) ) {
				if ( wp_verify_nonce( $nonce, 'ffc_action_' . $id ) ) {
					if ( 'trash' === $action ) {
						$this->submission_handler->trash_submission( $id );
					}
					if ( 'restore' === $action ) {
						$this->submission_handler->restore_submission( $id );
					}
					if ( 'delete' === $action ) {
						$this->submission_handler->delete_submission( $id );
					}
					$this->redirect_with_msg( $action );
				}
			}
		}

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- isset()/is_array() existence and type checks only.
		if ( isset( $_GET['action'] ) && isset( $_GET['submission'] ) && is_array( $_GET['submission'] ) ) {
			$bulk_action = sanitize_key( wp_unslash( $_GET['action'] ) );
			if ( '-1' === $bulk_action && isset( $_GET['action2'] ) ) {
				$bulk_action = sanitize_key( wp_unslash( $_GET['action2'] ) );
			}

			$allowed_bulk = array( 'bulk_trash', 'bulk_restore', 'bulk_delete', 'move_to_form' );
			if ( in_array( $bulk_action, $allowed_bulk, true ) ) {
				check_admin_referer( 'bulk-submissions' );
				$ids = array_map( 'absint', wp_unslash( $_GET['submission'] ) );

				// Use optimized bulk methods (single query + single log).
				if ( 'bulk_trash' === $bulk_action ) {
					$this->submission_handler->bulk_trash_submissions( $ids );
					$this->redirect_with_msg( 'bulk_done' );
				} elseif ( 'bulk_restore' === $bulk_action ) {
					$this->submission_handler->bulk_restore_submissions( $ids );
					$this->redirect_with_msg( 'bulk_done' );
				} elseif ( 'bulk_delete' === $bulk_action ) {
					$this->submission_handler->bulk_delete_submissions( $ids );
					$this->redirect_with_msg( 'bulk_done' );
				} elseif ( 'move_to_form' === $bulk_action ) {
					$this->handle_bulk_move_to_form( $ids );
				}
			}
		}
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Display submissions page.
	 */
	public function display_submissions_page(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Routing parameter for page display.
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'list';
		if ( 'edit' === $action ) {
			$this->render_edit_page();
		} else {
			$this->render_list_page();
		}
	}

	/**
	 * Render list page.
	 */
	private function render_list_page(): void {
		// Autoloader handles class loading.
		$table = new \FreeFormCertificate\Admin\SubmissionsList( $this->submission_handler );
		$this->display_admin_notices();
		$table->prepare_items();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Submissions', 'ffcertificate' ); ?></h1>
			<div class="ffc-admin-top-actions">
				<?php
                // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display filter parameters for export button.
				$export_status   = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'publish';
				$filter_form_ids = array();
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- empty() existence check only.
				if ( ! empty( $_GET['filter_form_id'] ) ) {
                    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- is_array() type check only.
					if ( is_array( $_GET['filter_form_id'] ) ) {
						$filter_form_ids = array_map( 'absint', wp_unslash( $_GET['filter_form_id'] ) );
					} else {
						$filter_form_ids = array( absint( wp_unslash( $_GET['filter_form_id'] ) ) );
					}
				}
                // phpcs:enable WordPress.Security.NonceVerification.Recommended

				$has_filters = ! empty( $filter_form_ids ) || 'publish' !== $export_status;
				$btn_class   = $has_filters ? 'button button-primary' : 'button';
				$btn_label   = $has_filters
					? __( 'Export Filtered CSV', 'ffcertificate' )
					: __( 'Export All CSV', 'ffcertificate' );
				?>
				<button
					type="button"
					id="ffc-csv-export-btn"
					class="<?php echo esc_attr( $btn_class ); ?>"
					<?php $form_ids_json = wp_json_encode( $filter_form_ids ); ?>
					data-form-ids="<?php echo esc_attr( $form_ids_json ? $form_ids_json : '' ); ?>"
					data-status="<?php echo esc_attr( $export_status ); ?>"
				><?php echo esc_html( $btn_label ); ?></button>
				<span id="ffc-csv-export-progress" style="display:none; margin-left:8px; vertical-align:middle;"></span>
			</div>
			<hr class="wp-header-end">
			<form method="GET">
				<input type="hidden" name="post_type" value="ffc_form">
				<input type="hidden" name="page" value="ffc-submissions">
				<?php
				$table->views();
				$table->search_box( __( 'Search', 'ffcertificate' ), 's' );
				?>
				<div class="ffc-table-responsive">
					<?php $table->display(); ?>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * Handle the "Move to form…" bulk action.
	 *
	 * Reads the source form ID from the existing `filter_form_id` filter
	 * (single value only — the bulk action only surfaces in the list table
	 * when exactly one form is filtered) and the target form ID from the
	 * modal-supplied `move_to_form_id` param. Defers identifier-based
	 * conflict detection to SubmissionHandler::move_submissions_between_forms,
	 * then redirects with a result message carrying the moved/conflict counts
	 * and the conflict IDs (so the admin notice can list them).
	 *
	 * @param array<int, int> $ids Submission IDs.
	 * @return void
	 */
	private function handle_bulk_move_to_form( array $ids ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Already verified by check_admin_referer( 'bulk-submissions' ) in the caller.
		$filter_raw = isset( $_GET['filter_form_id'] ) ? wp_unslash( $_GET['filter_form_id'] ) : null;
		$from_form  = 0;
		if ( is_array( $filter_raw ) && 1 === count( $filter_raw ) ) {
			$from_form = absint( reset( $filter_raw ) );
		} elseif ( is_string( $filter_raw ) || is_numeric( $filter_raw ) ) {
			$from_form = absint( $filter_raw );
		}
		$to_form = isset( $_GET['move_to_form_id'] ) ? absint( wp_unslash( $_GET['move_to_form_id'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( $from_form <= 0 || $to_form <= 0 || $from_form === $to_form ) {
			$this->redirect_with_msg( 'move_invalid' );
			return;
		}

		if ( get_post_type( $to_form ) !== 'ffc_form' ) {
			$this->redirect_with_msg( 'move_invalid_target' );
			return;
		}

		$result = $this->submission_handler->move_submissions_between_forms( $from_form, $to_form, $ids );

		$args = array(
			'msg'         => 'move_done',
			'moved'       => count( $result['moved'] ),
			'conflicts'   => count( $result['conflicts'] ),
			'to_form'     => $to_form,
			'conflict_id' => implode( ',', array_slice( $result['conflicts'], 0, 50 ) ),
		);
		$this->redirect_with_extra_args( $args );
	}

	/**
	 * Redirect to the current admin page with arbitrary query args.
	 *
	 * @param array<string, mixed> $args Query args (must include `msg`).
	 * @return void
	 */
	private function redirect_with_extra_args( array $args ): void {
		$page      = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $page ) {
			$args['page'] = $page;
		}
		if ( $post_type ) {
			$args['post_type'] = $post_type;
		}

		$url = add_query_arg( $args, admin_url( 'edit.php' ) );
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Redirect with msg.
	 *
	 * @param string $msg Msg.
	 */
	private function redirect_with_msg( string $msg ): void {
		// Build the redirect target from the current admin screen instead of REQUEST_URI, so the URL
		// path cannot be influenced by request-level input. Preserve the page and post_type context.
		$page      = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$args = array( 'msg' => $msg );
		if ( $page ) {
			$args['page'] = $page;
		}
		if ( $post_type ) {
			$args['post_type'] = $post_type;
		}

		$url = add_query_arg( $args, admin_url( 'edit.php' ) );
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Display admin notices.
	 */
	private function display_admin_notices(): void {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display-only URL parameters from admin redirects.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- isset() existence check only.
		if ( ! isset( $_GET['msg'] ) ) {
			return;
		}
		$msg  = sanitize_key( wp_unslash( $_GET['msg'] ) );
		$text = '';
		$type = 'updated';

		switch ( $msg ) {
			case 'trash':
				$text = __( 'Item moved to trash.', 'ffcertificate' );
				break;
			case 'restore':
				$text = __( 'Item restored.', 'ffcertificate' );
				break;
			case 'delete':
				$text = __( 'Item permanently deleted.', 'ffcertificate' );
				break;
			case 'bulk_done':
				$text = __( 'Bulk action completed.', 'ffcertificate' );
				break;
			case 'updated':
				$text = __( 'Submission updated successfully.', 'ffcertificate' );
				break;
			case 'migration_success':
				$migrated       = isset( $_GET['migrated'] ) ? absint( wp_unslash( $_GET['migrated'] ) ) : 0;
				$migration_name = isset( $_GET['migration_name'] ) ? sanitize_text_field( urldecode( wp_unslash( $_GET['migration_name'] ) ) ) : __( 'Migration', 'ffcertificate' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via sanitize_text_field().
				/* translators: 1: migration name, 2: number of records migrated */
				$text = sprintf( __( '%1$s: %2$d records migrated successfully.', 'ffcertificate' ), $migration_name, $migrated );
				break;
			case 'migration_error':
				$error_msg = isset( $_GET['error_msg'] ) ? sanitize_text_field( urldecode( wp_unslash( $_GET['error_msg'] ) ) ) : __( 'Unknown error', 'ffcertificate' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via sanitize_text_field().
				$text      = __( 'Migration Error: ', 'ffcertificate' ) . $error_msg;
				$type      = 'error';
				break;
			case 'move_invalid':
				$text = __( 'Move failed: source or target form is missing or invalid.', 'ffcertificate' );
				$type = 'error';
				break;
			case 'move_invalid_target':
				$text = __( 'Move failed: the selected target form does not exist.', 'ffcertificate' );
				$type = 'error';
				break;
			case 'move_done':
				$this->render_move_done_notice();
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				return;
		}

		if ( $text ) {
			echo "<div class='" . esc_attr( $type ) . " notice is-dismissible'><p>" . esc_html( $text ) . '</p></div>';
		}
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Render the "Move to form" result notice.
	 *
	 * Reads the moved/conflict counts and the conflict-id list from the
	 * redirect query args and emits one or two notices: success when at
	 * least one row moved, warning when at least one row stayed put due to
	 * an identifier conflict (with the original IDs spelled out so the
	 * admin can audit them).
	 *
	 * @return void
	 */
	private function render_move_done_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display-only URL parameters from admin redirects.
		$moved        = isset( $_GET['moved'] ) ? absint( wp_unslash( $_GET['moved'] ) ) : 0;
		$conflicts    = isset( $_GET['conflicts'] ) ? absint( wp_unslash( $_GET['conflicts'] ) ) : 0;
		$to_form      = isset( $_GET['to_form'] ) ? absint( wp_unslash( $_GET['to_form'] ) ) : 0;
		$conflict_raw = isset( $_GET['conflict_id'] ) ? sanitize_text_field( wp_unslash( $_GET['conflict_id'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$conflict_ids = array();
		if ( '' !== $conflict_raw ) {
			$conflict_ids = array_values(
				array_filter(
					array_map( 'absint', explode( ',', $conflict_raw ) )
				)
			);
		}

		$to_title = $to_form > 0 ? get_the_title( $to_form ) : '';
		if ( '' === $to_title ) {
			$to_title = (string) $to_form;
		}

		if ( $moved > 0 ) {
			echo "<div class='updated notice is-dismissible'><p>" . esc_html(
				sprintf(
					/* translators: 1: number of moved submissions, 2: target form title */
					_n(
						'%1$d submission moved to "%2$s".',
						'%1$d submissions moved to "%2$s".',
						$moved,
						'ffcertificate'
					),
					$moved,
					$to_title
				)
			) . '</p></div>';
		}

		if ( $conflicts > 0 ) {
			$ids_text = $conflict_ids
				? implode( ', ', array_map( 'strval', $conflict_ids ) )
				: '';
			$message  = sprintf(
				/* translators: 1: number of conflicting submissions, 2: target form title */
				_n(
					'%1$d submission was kept in the original form because an identifier (CPF, RF, e-mail, or user) already exists in "%2$s".',
					'%1$d submissions were kept in the original form because an identifier (CPF, RF, e-mail, or user) already exists in "%2$s".',
					$conflicts,
					'ffcertificate'
				),
				$conflicts,
				$to_title
			);
			echo "<div class='notice notice-warning is-dismissible'><p>" . esc_html( $message );
			if ( '' !== $ids_text ) {
				echo ' <strong>' . esc_html__( 'Conflict IDs:', 'ffcertificate' ) . '</strong> ' . esc_html( $ids_text );
			}
			echo '</p></div>';
		}

		if ( 0 === $moved && 0 === $conflicts ) {
			echo "<div class='notice notice-warning is-dismissible'><p>" . esc_html__( 'No submissions matched the selection.', 'ffcertificate' ) . '</p></div>';
		}
	}


	/**
	 * Render edit page.
	 */
	private function render_edit_page(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Routing parameter for edit page display.
		$submission_id = isset( $_GET['submission_id'] ) ? absint( wp_unslash( $_GET['submission_id'] ) ) : 0;
		$this->edit_page->render( $submission_id );
	}

	/**
	 * Handle submission edit save.
	 */
	public function handle_submission_edit_save(): void {
		$this->edit_page->handle_save();
	}
	/**
	 * Handle migration action (unified handler for all migrations)
	 *
	 * @since 2.9.13
	 */
	public function handle_migration_action(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified below via check_admin_referer.
		if ( ! isset( $_GET['ffc_migration'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions', 'ffcertificate' ) );
		}

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified immediately below via check_admin_referer.
		$migration_key = sanitize_key( wp_unslash( $_GET['ffc_migration'] ) );

		// Verify nonce.
		check_admin_referer( 'ffc_migration_' . $migration_key );

		// Lazy-load MigrationManager (avoids early translation calls).
		if ( ! $this->migration_manager ) {
			$this->migration_manager = new MigrationManager();
		}

		// Get migration info.
		$migration = $this->migration_manager->get_migration( $migration_key );
		if ( ! $migration ) {
			wp_die( esc_html__( 'Invalid migration key', 'ffcertificate' ) );
		}

		// Run migration.
		$result = $this->migration_manager->run_migration( $migration_key );

		if ( is_wp_error( $result ) ) {
			$redirect_url = add_query_arg(
				array(
					'post_type' => 'ffc_form',
					'page'      => 'ffc-submissions',
					'msg'       => 'migration_error',
					'error_msg' => rawurlencode( $result->get_error_message() ),
				),
				admin_url( 'edit.php' )
			);
		} else {
			$migrated = isset( $result['migrated'] ) ? $result['migrated'] : 0;

			$redirect_url = add_query_arg(
				array(
					'post_type'      => 'ffc_form',
					'page'           => 'ffc-submissions',
					'msg'            => 'migration_success',
					'migration_name' => rawurlencode( $migration['name'] ),
					'migrated'       => $migrated,
				),
				admin_url( 'edit.php' )
			);
		}

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Register the TinyMCE placeholder-protection filter only on the ffc_form edit screen.
	 *
	 * Running the filter globally would mutate TinyMCE init on unrelated admin screens
	 * (Classic Editor, third-party plugins). Scoping it to the post type that actually
	 * uses placeholders eliminates that side effect.
	 *
	 * @since 5.4.1
	 */
	public function maybe_register_tinymce_placeholder_filter(): void {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || 'ffc_form' !== $screen->post_type ) {
			return;
		}
		// Priority 999 to run AFTER other plugins.
		add_filter( 'tiny_mce_before_init', array( $this, 'configure_tinymce_placeholders' ), 999 );
	}

	/**
	 * Configure TinyMCE to protect placeholders from being processed
	 *
	 * This prevents TinyMCE from escaping characters inside placeholders.
	 * For example: {{validation_url link:m>v}} stays as is,
	 * instead of being converted to {{validation_url link:m&gt;v}}
	 *
	 * @since 2.9.3
	 * @param array<string, mixed> $init TinyMCE initialization settings.
	 * @return array<string, mixed> Modified settings
	 */
	public function configure_tinymce_placeholders( array $init ): array {
		// Protect all content between {{ and }} from entity encoding.
		$init['noneditable_regexp'] = '/{{[^}]+}}/g';
		$init['noneditable_class']  = 'ffc-placeholder';
		$init['entity_encoding']    = 'raw';

		if ( ! isset( $init['extended_valid_elements'] ) ) {
			$init['extended_valid_elements'] = '';
		}

		if ( ! isset( $init['protect'] ) ) {
			$init['protect'] = array();
		}
		if ( is_array( $init['protect'] ) ) {
			$init['protect'][] = '/{{[^}]+}}/g';
		}

		return $init;
	}
}
