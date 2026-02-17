<?php
declare(strict_types=1);

/**
 * Admin
 * v2.10.0: ENCRYPTION - Shows LGPD consent status, data auto-decrypted by Submission Handler
 *
 * @version 4.0.0 - Removed alias usage (Phase 4 Hotfix 7)
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace (Phase 2)
 */

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

class Admin {

    /** @var object */
    private $submission_handler;
    /** @var object */
    private $csv_exporter;
    /** @var object|null */
    private $email_handler;
    /** @var FormEditor */
    private $form_editor;
    /** @var Settings */
    private $settings_page;
    /** @var MigrationManager|null */
    private $migration_manager;
    /** @var AdminAssetsManager */
    private $assets_manager;
    /** @var AdminSubmissionEditPage */
    private $edit_page;
    /** @var AdminActivityLogPage */
    private $activity_log_page;

    public function __construct( object $handler, object $exporter, ?object $email_handler = null ) {
        $this->submission_handler = $handler;
        $this->csv_exporter = $exporter;
        $this->email_handler = $email_handler;

        // Autoloader handles class loading
        $this->form_editor   = new FormEditor();
        $this->settings_page = new Settings( $handler );

        $this->assets_manager = new AdminAssetsManager();
        $this->assets_manager->register();
        $this->edit_page = new AdminSubmissionEditPage( $handler );
        $this->activity_log_page = new AdminActivityLogPage();

        add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );

        // Priority 999 to run AFTER other plugins
        add_filter( 'tiny_mce_before_init', array( $this, 'configure_tinymce_placeholders' ), 999 );

        add_action( 'admin_init', array( $this, 'handle_submission_actions' ) );
        add_action( 'admin_init', array( $this, 'handle_csv_export_request' ) );
        add_action( 'admin_init', array( $this, 'handle_submission_edit_save' ) );
        add_action( 'admin_init', array( $this, 'handle_migration_action' ) );

        add_action( 'admin_post_ffc_export_csv', array( $this, 'handle_csv_export_request' ) );
    }

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

    public function handle_submission_actions(): void {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Nonce verified per-action below via wp_verify_nonce and check_admin_referer.
        if ( ! isset( $_GET['page'] ) || sanitize_text_field( wp_unslash( $_GET['page'] ) ) !== 'ffc-submissions' ) return;

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- isset() existence checks only.
        if ( isset( $_GET['submission_id'] ) && isset( $_GET['action'] ) ) {
            $id     = absint( wp_unslash( $_GET['submission_id'] ) );
            $action = sanitize_key( wp_unslash( $_GET['action'] ) );
            $nonce  = isset($_GET['_wpnonce']) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
            $manipulation_actions = array( 'trash', 'restore', 'delete' );

            if ( in_array( $action, $manipulation_actions ) ) {
                if ( wp_verify_nonce( $nonce, 'ffc_action_' . $id ) ) {
                    if ( $action === 'trash' ) $this->submission_handler->trash_submission( $id );
                    if ( $action === 'restore' ) $this->submission_handler->restore_submission( $id );
                    if ( $action === 'delete' ) $this->submission_handler->delete_submission( $id );
                    $this->redirect_with_msg( $action );
                }
            }
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- isset()/is_array() existence and type checks only.
        if ( isset($_GET['action']) && isset($_GET['submission']) && is_array($_GET['submission']) ) {
            $bulk_action = sanitize_key( wp_unslash( $_GET['action'] ) );
            if ( $bulk_action === '-1' && isset($_GET['action2']) ) $bulk_action = sanitize_key( wp_unslash( $_GET['action2'] ) );

            $allowed_bulk = array( 'bulk_trash', 'bulk_restore', 'bulk_delete' );
            if ( in_array( $bulk_action, $allowed_bulk ) ) {
                check_admin_referer('bulk-submissions');
                $ids = array_map( 'absint', wp_unslash( $_GET['submission'] ) );

                // Use optimized bulk methods (single query + single log)
                if ( $bulk_action === 'bulk_trash' ) {
                    $this->submission_handler->bulk_trash_submissions( $ids );
                } elseif ( $bulk_action === 'bulk_restore' ) {
                    $this->submission_handler->bulk_restore_submissions( $ids );
                } elseif ( $bulk_action === 'bulk_delete' ) {
                    $this->submission_handler->bulk_delete_submissions( $ids );
                }

                $this->redirect_with_msg('bulk_done');
            }
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
    }

    public function display_submissions_page(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Routing parameter for page display.
        $action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'list';
        if ( $action === 'edit' ) {
            $this->render_edit_page();
        } else {
            $this->render_list_page();
        }
    }

    private function render_list_page(): void {
        // Autoloader handles class loading
        $table = new \FreeFormCertificate\Admin\SubmissionsList( $this->submission_handler );
        $this->display_admin_notices();
        $table->prepare_items();
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'Submissions', 'ffcertificate' ); ?></h1>
            <div class="ffc-admin-top-actions">
                <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="ffc_export_csv">
                    <input type="hidden" name="ffc_action" value="export_csv_smart">
                    <?php
                    // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display filter parameter for form selection.
                    $filter_form_ids = [];
                    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- empty() existence check only.
                    if ( !empty( $_GET['filter_form_id'] ) ) {
                        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- is_array() type check only.
                        if ( is_array( $_GET['filter_form_id'] ) ) {
                            $filter_form_ids = array_map( 'absint', wp_unslash( $_GET['filter_form_id'] ) );
                        } else {
                            $filter_form_ids = [ absint( wp_unslash( $_GET['filter_form_id'] ) ) ];
                        }
                    }
                    // phpcs:enable WordPress.Security.NonceVerification.Recommended

                    if ( !empty( $filter_form_ids ) ) :
                        foreach ( $filter_form_ids as $form_id ) :
                    ?>
                        <input type="hidden" name="form_ids[]" value="<?php echo esc_attr( $form_id ); ?>">
                    <?php
                        endforeach;
                    ?>
                        <button type="submit" class="button button-primary"><?php esc_html_e( 'Export Filtered CSV', 'ffcertificate' ); ?></button>
                    <?php else: ?>
                        <button type="submit" class="button"><?php esc_html_e( 'Export All CSV', 'ffcertificate' ); ?></button>
                    <?php endif; ?>
                    <?php wp_nonce_field('ffc_export_csv_nonce','ffc_export_csv_action'); ?>
                </form>
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

    private function redirect_with_msg( string $msg ): void {
        $url = remove_query_arg(array('action', 'action2', 'submission_id', 'submission', '_wpnonce'), isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '');
        wp_safe_redirect( add_query_arg('msg', $msg, $url) );
        exit;
    }

    private function display_admin_notices(): void {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display-only URL parameters from admin redirects.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- isset() existence check only.
        if (!isset($_GET['msg'])) return;
        $msg = sanitize_key( wp_unslash( $_GET['msg'] ) );
        $text = '';
        $type = 'updated';

        switch ($msg) {
            case 'trash':
                $text = __('Item moved to trash.', 'ffcertificate');
                break;
            case 'restore':
                $text = __('Item restored.', 'ffcertificate');
                break;
            case 'delete':
                $text = __('Item permanently deleted.', 'ffcertificate');
                break;
            case 'bulk_done':
                $text = __('Bulk action completed.', 'ffcertificate');
                break;
            case 'updated':
                $text = __('Submission updated successfully.', 'ffcertificate');
                break;
            case 'migration_success':
                $migrated = isset( $_GET['migrated'] ) ? absint( wp_unslash( $_GET['migrated'] ) ) : 0;
                $migration_name = isset($_GET['migration_name']) ? sanitize_text_field( urldecode( wp_unslash( $_GET['migration_name'] ) ) ) : __('Migration', 'ffcertificate'); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via sanitize_text_field().
                /* translators: 1: migration name, 2: number of records migrated */
                $text = sprintf(__('%1$s: %2$d records migrated successfully.', 'ffcertificate'), $migration_name, $migrated);
                break;
            case 'migration_error':
                $error_msg = isset($_GET['error_msg']) ? sanitize_text_field( urldecode( wp_unslash( $_GET['error_msg'] ) ) ) : __('Unknown error', 'ffcertificate'); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via sanitize_text_field().
                $text = __('Migration Error: ', 'ffcertificate') . $error_msg;
                $type = 'error';
                break;
        }

        if ($text) {
            echo "<div class='" . esc_attr( $type ) . " notice is-dismissible'><p>" . esc_html($text) . "</p></div>";
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
    }


    private function render_edit_page(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Routing parameter for edit page display.
        $submission_id = isset( $_GET['submission_id'] ) ? absint( wp_unslash( $_GET['submission_id'] ) ) : 0;
        $this->edit_page->render( $submission_id );
    }

    public function handle_submission_edit_save(): void {
        $this->edit_page->handle_save();
    }
    public function handle_csv_export_request(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in csv_exporter->handle_export_request().
        if ( isset( $_POST['ffc_action'] ) && sanitize_text_field( wp_unslash( $_POST['ffc_action'] ) ) === 'export_csv_smart' ) {
            $this->csv_exporter->handle_export_request();
        }
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

        // Verify nonce
        check_admin_referer( 'ffc_migration_' . $migration_key );

        // Lazy-load MigrationManager (avoids early translation calls)
        if ( ! $this->migration_manager ) {
            $this->migration_manager = new MigrationManager();
        }

        // Get migration info
        $migration = $this->migration_manager->get_migration( $migration_key );
        if ( ! $migration ) {
            wp_die( esc_html__( 'Invalid migration key', 'ffcertificate' ) );
        }

        // Run migration
        $result = $this->migration_manager->run_migration( $migration_key );

        if ( is_wp_error( $result ) ) {
            $redirect_url = add_query_arg(
                array(
                    'post_type' => 'ffc_form',
                    'page' => 'ffc-submissions',
                    'msg' => 'migration_error',
                    'error_msg' => urlencode( $result->get_error_message() )
                ),
                admin_url( 'edit.php' )
            );
        } else {
            $migrated = isset( $result['migrated'] ) ? $result['migrated'] : 0;

            $redirect_url = add_query_arg(
                array(
                    'post_type' => 'ffc_form',
                    'page' => 'ffc-submissions',
                    'msg' => 'migration_success',
                    'migration_name' => urlencode( $migration['name'] ),
                    'migrated' => $migrated
                ),
                admin_url( 'edit.php' )
            );
        }

        wp_safe_redirect( $redirect_url );
        exit;
    }

    /**
     * Configure TinyMCE to protect placeholders from being processed
     *
     * This prevents TinyMCE from escaping characters inside placeholders.
     * For example: {{validation_url link:m>v}} stays as is,
     * instead of being converted to {{validation_url link:m&gt;v}}
     *
     * @since 2.9.3
     * @param array<string, mixed> $init TinyMCE initialization settings
     * @return array<string, mixed> Modified settings
     */
    public function configure_tinymce_placeholders( array $init ): array {
        // Protect all content between {{ and }} from entity encoding
        $init['noneditable_regexp'] = '/{{[^}]+}}/g';
        $init['noneditable_class'] = 'ffc-placeholder';
        $init['entity_encoding'] = 'raw';

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
