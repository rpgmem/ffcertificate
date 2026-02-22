<?php
declare(strict_types=1);

/**
 * URL Shortener Admin Page
 *
 * Admin submenu page listing all short URLs with CRUD operations.
 *
 * @since 5.1.0
 * @package FreeFormCertificate\UrlShortener
 */

namespace FreeFormCertificate\UrlShortener;

use FreeFormCertificate\Core\AjaxTrait;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class UrlShortenerAdminPage {

    use AjaxTrait;

    /** @var UrlShortenerService */
    private UrlShortenerService $service;

    public function __construct( UrlShortenerService $service ) {
        $this->service = $service;
    }

    /**
     * Register hooks.
     */
    public function init(): void {
        add_action( 'admin_menu', [ $this, 'register_menu' ], 25 );
        add_action( 'admin_init', [ $this, 'handle_actions' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'wp_ajax_ffc_create_short_url', [ $this, 'ajax_create' ] );
        add_action( 'wp_ajax_ffc_delete_short_url', [ $this, 'ajax_delete' ] );
        add_action( 'wp_ajax_ffc_toggle_short_url', [ $this, 'ajax_toggle' ] );
        add_action( 'wp_ajax_ffc_trash_short_url', [ $this, 'ajax_trash' ] );
        add_action( 'wp_ajax_ffc_restore_short_url', [ $this, 'ajax_restore' ] );
        add_action( 'wp_ajax_ffc_empty_trash_short_urls', [ $this, 'ajax_empty_trash' ] );
    }

    /**
     * Enqueue assets on the Short URLs admin page.
     *
     * @param string $hook_suffix Admin page hook suffix.
     */
    public function enqueue_assets( string $hook_suffix ): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Routing parameter for conditional asset loading.
        $page = sanitize_text_field( wp_unslash( $_GET['page'] ?? '' ) );
        if ( $page !== 'ffc-short-urls' ) {
            return;
        }

        wp_enqueue_style(
            'ffc-url-shortener-admin',
            FFC_PLUGIN_URL . 'assets/css/ffc-url-shortener-admin.css',
            [],
            FFC_VERSION
        );
        wp_enqueue_script(
            'ffc-url-shortener-admin',
            FFC_PLUGIN_URL . 'assets/js/ffc-url-shortener-admin.js',
            [ 'jquery' ],
            FFC_VERSION,
            true
        );
        wp_localize_script( 'ffc-url-shortener-admin', 'ffcUrlShortener', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'ffc_short_url_nonce' ),
            'i18n'    => [
                'copied'     => __( 'Copied!', 'ffcertificate' ),
                'copyFailed' => __( 'Copy failed', 'ffcertificate' ),
                'error'      => __( 'An error occurred.', 'ffcertificate' ),
            ],
        ] );
    }

    /**
     * Add submenu under the FFC Forms menu.
     */
    public function register_menu(): void {
        add_submenu_page(
            'edit.php?post_type=ffc_form',
            __( 'Short URLs', 'ffcertificate' ),
            __( 'Short URLs', 'ffcertificate' ),
            'manage_options',
            'ffc-short-urls',
            [ $this, 'render_page' ]
        );
    }

    /**
     * Handle non-AJAX admin actions (bulk delete, toggle).
     */
    public function handle_actions(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Routing parameter; nonce verified below via wp_verify_nonce.
        if ( ! isset( $_GET['page'] ) || sanitize_text_field( wp_unslash( $_GET['page'] ) ) !== 'ffc-short-urls' ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Presence check only; nonce verified below via wp_verify_nonce.
        if ( ! isset( $_GET['ffc_action'] ) ) {
            return;
        }

        $action = sanitize_key( wp_unslash( $_GET['ffc_action'] ) );
        $nonce  = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

        if ( $action === 'trash' && isset( $_GET['id'] ) ) {
            if ( ! wp_verify_nonce( $nonce, 'ffc_short_url_trash_' . absint( $_GET['id'] ) ) ) {
                wp_die( esc_html__( 'Security check failed.', 'ffcertificate' ) );
            }
            $this->service->trash_short_url( absint( wp_unslash( $_GET['id'] ) ) );
            wp_safe_redirect( admin_url( 'edit.php?post_type=ffc_form&page=ffc-short-urls&msg=trashed' ) );
            exit;
        }

        if ( $action === 'restore' && isset( $_GET['id'] ) ) {
            if ( ! wp_verify_nonce( $nonce, 'ffc_short_url_restore_' . absint( $_GET['id'] ) ) ) {
                wp_die( esc_html__( 'Security check failed.', 'ffcertificate' ) );
            }
            $this->service->restore_short_url( absint( wp_unslash( $_GET['id'] ) ) );
            wp_safe_redirect( admin_url( 'edit.php?post_type=ffc_form&page=ffc-short-urls&status=trashed&msg=restored' ) );
            exit;
        }

        if ( $action === 'delete' && isset( $_GET['id'] ) ) {
            if ( ! wp_verify_nonce( $nonce, 'ffc_short_url_delete_' . absint( $_GET['id'] ) ) ) {
                wp_die( esc_html__( 'Security check failed.', 'ffcertificate' ) );
            }
            $this->service->delete_short_url( absint( wp_unslash( $_GET['id'] ) ) );
            wp_safe_redirect( admin_url( 'edit.php?post_type=ffc_form&page=ffc-short-urls&status=trashed&msg=deleted' ) );
            exit;
        }

        if ( $action === 'empty_trash' ) {
            if ( ! wp_verify_nonce( $nonce, 'ffc_short_url_empty_trash' ) ) {
                wp_die( esc_html__( 'Security check failed.', 'ffcertificate' ) );
            }
            $trashed = $this->service->get_repository()->findPaginated( [
                'status'   => 'trashed',
                'per_page' => 1000,
            ] );
            foreach ( $trashed['items'] as $item ) {
                $this->service->delete_short_url( (int) $item['id'] );
            }
            wp_safe_redirect( admin_url( 'edit.php?post_type=ffc_form&page=ffc-short-urls&msg=emptied' ) );
            exit;
        }

        if ( $action === 'toggle' && isset( $_GET['id'] ) ) {
            if ( ! wp_verify_nonce( $nonce, 'ffc_short_url_toggle_' . absint( $_GET['id'] ) ) ) {
                wp_die( esc_html__( 'Security check failed.', 'ffcertificate' ) );
            }
            $this->service->toggle_status( absint( wp_unslash( $_GET['id'] ) ) );
            wp_safe_redirect( admin_url( 'edit.php?post_type=ffc_form&page=ffc-short-urls&msg=toggled' ) );
            exit;
        }
    }

    /**
     * AJAX: Create a new short URL.
     */
    public function ajax_create(): void {
        $this->verify_ajax_nonce( 'ffc_short_url_nonce' );
        $this->check_ajax_permission();

        $url   = esc_url_raw( wp_unslash( $_POST['target_url'] ?? '' ) );
        $title = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );

        if ( empty( $url ) ) {
            wp_send_json_error( [ 'message' => __( 'URL is required.', 'ffcertificate' ) ] );
        }

        $result = $this->service->create_short_url( $url, $title );

        if ( $result['success'] ) {
            $data = $result['data'];
            $data['short_url'] = $this->service->get_short_url( $data['short_code'] );
            wp_send_json_success( $data );
        } else {
            wp_send_json_error( [ 'message' => $result['error'] ] );
        }
    }

    /**
     * AJAX: Delete a short URL.
     */
    public function ajax_delete(): void {
        $this->verify_ajax_nonce( 'ffc_short_url_nonce' );
        $this->check_ajax_permission();

        $id = (int) ( $_POST['id'] ?? 0 );
        if ( $id <= 0 ) {
            wp_send_json_error( [ 'message' => __( 'Invalid ID.', 'ffcertificate' ) ] );
        }

        $this->service->delete_short_url( $id );
        wp_send_json_success();
    }

    /**
     * AJAX: Trash a short URL (soft delete).
     */
    public function ajax_trash(): void {
        $this->verify_ajax_nonce( 'ffc_short_url_nonce' );
        $this->check_ajax_permission();

        $id = (int) ( $_POST['id'] ?? 0 );
        if ( $id <= 0 ) {
            wp_send_json_error( [ 'message' => __( 'Invalid ID.', 'ffcertificate' ) ] );
        }

        $this->service->trash_short_url( $id );
        wp_send_json_success();
    }

    /**
     * AJAX: Restore a short URL from trash.
     */
    public function ajax_restore(): void {
        $this->verify_ajax_nonce( 'ffc_short_url_nonce' );
        $this->check_ajax_permission();

        $id = (int) ( $_POST['id'] ?? 0 );
        if ( $id <= 0 ) {
            wp_send_json_error( [ 'message' => __( 'Invalid ID.', 'ffcertificate' ) ] );
        }

        $this->service->restore_short_url( $id );
        wp_send_json_success();
    }

    /**
     * AJAX: Empty trash — permanently delete all trashed short URLs.
     */
    public function ajax_empty_trash(): void {
        $this->verify_ajax_nonce( 'ffc_short_url_nonce' );
        $this->check_ajax_permission();

        $trashed = $this->service->get_repository()->findPaginated( [
            'status'   => 'trashed',
            'per_page' => 1000,
        ] );
        foreach ( $trashed['items'] as $item ) {
            $this->service->delete_short_url( (int) $item['id'] );
        }

        wp_send_json_success();
    }

    /**
     * AJAX: Toggle short URL status.
     */
    public function ajax_toggle(): void {
        $this->verify_ajax_nonce( 'ffc_short_url_nonce' );
        $this->check_ajax_permission();

        $id = (int) ( $_POST['id'] ?? 0 );
        if ( $id <= 0 ) {
            wp_send_json_error( [ 'message' => __( 'Invalid ID.', 'ffcertificate' ) ] );
        }

        $this->service->toggle_status( $id );
        wp_send_json_success();
    }

    /**
     * Render the admin page.
     */
    public function render_page(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only pagination parameter.
        $page    = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only search parameter.
        $search  = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only sort parameter.
        $orderby = sanitize_key( $_GET['orderby'] ?? 'created_at' );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only sort direction parameter.
        $order   = strtoupper( sanitize_key( $_GET['order'] ?? 'DESC' ) );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only status filter parameter.
        $status  = sanitize_key( $_GET['status'] ?? 'all' );

        $per_page = 20;
        $result   = $this->service->get_repository()->findPaginated( [
            'per_page' => $per_page,
            'page'     => $page,
            'orderby'  => $orderby,
            'order'    => $order,
            'search'   => $search,
            'status'   => $status,
        ] );

        $items       = $result['items'];
        $total       = $result['total'];
        $total_pages = (int) ceil( $total / $per_page );
        $stats       = $this->service->get_stats();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flash message parameter.
        $msg         = sanitize_key( $_GET['msg'] ?? '' );

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'Short URLs', 'ffcertificate' ); ?></h1>
            <hr class="wp-header-end">

            <?php if ( $msg === 'trashed' ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Short URL moved to Trash.', 'ffcertificate' ); ?></p></div>
            <?php elseif ( $msg === 'restored' ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Short URL restored.', 'ffcertificate' ); ?></p></div>
            <?php elseif ( $msg === 'deleted' ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Short URL permanently deleted.', 'ffcertificate' ); ?></p></div>
            <?php elseif ( $msg === 'emptied' ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Trash emptied.', 'ffcertificate' ); ?></p></div>
            <?php elseif ( $msg === 'toggled' ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Status updated.', 'ffcertificate' ); ?></p></div>
            <?php endif; ?>

            <?php if ( $status !== 'trashed' ) : ?>
            <!-- Stats -->
            <div class="ffc-shorturl-stats">
                <div>
                    <strong><?php echo esc_html( number_format_i18n( $stats['total_links'] ) ); ?></strong>
                    <span class="ffc-stat-label"><?php esc_html_e( 'Total Links', 'ffcertificate' ); ?></span>
                </div>
                <div>
                    <strong><?php echo esc_html( number_format_i18n( $stats['active_links'] ) ); ?></strong>
                    <span class="ffc-stat-label"><?php esc_html_e( 'Active', 'ffcertificate' ); ?></span>
                </div>
                <div>
                    <strong><?php echo esc_html( number_format_i18n( $stats['total_clicks'] ) ); ?></strong>
                    <span class="ffc-stat-label"><?php esc_html_e( 'Total Clicks', 'ffcertificate' ); ?></span>
                </div>
            </div>
            <?php endif; ?>

            <?php if ( $status !== 'trashed' ) : ?>
            <!-- Create New -->
            <div class="ffc-shorturl-create">
                <h3><?php esc_html_e( 'Create Short URL', 'ffcertificate' ); ?></h3>
                <form id="ffc-create-short-url">
                    <?php wp_nonce_field( 'ffc_short_url_nonce', 'ffc_short_url_nonce' ); ?>
                    <div>
                        <label for="ffc-shorturl-target"><strong><?php esc_html_e( 'Destination URL', 'ffcertificate' ); ?></strong></label><br>
                        <input type="url" id="ffc-shorturl-target" name="target_url" placeholder="https://example.com/long-page" required />
                    </div>
                    <div>
                        <label for="ffc-shorturl-title"><strong><?php esc_html_e( 'Title (optional)', 'ffcertificate' ); ?></strong></label><br>
                        <input type="text" id="ffc-shorturl-title" name="title" placeholder="<?php esc_attr_e( 'My Campaign', 'ffcertificate' ); ?>" />
                    </div>
                    <div>
                        <button type="submit" class="button button-primary"><?php esc_html_e( 'Create', 'ffcertificate' ); ?></button>
                    </div>
                    <div id="ffc-shorturl-result"></div>
                </form>
            </div>
            <?php endif; ?>

            <!-- Search + Filter -->
            <form method="get" class="ffc-shorturl-filter">
                <input type="hidden" name="post_type" value="ffc_form" />
                <input type="hidden" name="page" value="ffc-short-urls" />
                <div class="ffc-shorturl-filter-row">
                    <select name="status">
                        <option value="all" <?php selected( $status, 'all' ); ?>><?php esc_html_e( 'All statuses', 'ffcertificate' ); ?></option>
                        <option value="active" <?php selected( $status, 'active' ); ?>><?php esc_html_e( 'Active', 'ffcertificate' ); ?></option>
                        <option value="disabled" <?php selected( $status, 'disabled' ); ?>><?php esc_html_e( 'Disabled', 'ffcertificate' ); ?></option>
                        <option value="trashed" <?php selected( $status, 'trashed' ); ?>>
                            <?php
                            /* translators: %d: number of trashed links */
                            printf( esc_html__( 'Trash (%d)', 'ffcertificate' ), $stats['trashed_links'] );
                            ?>
                        </option>
                    </select>
                    <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search...', 'ffcertificate' ); ?>" />
                    <button type="submit" class="button"><?php esc_html_e( 'Filter', 'ffcertificate' ); ?></button>
                </div>
            </form>

            <?php if ( $status === 'trashed' && $total > 0 ) : ?>
                <?php
                $empty_trash_url = wp_nonce_url(
                    admin_url( 'edit.php?post_type=ffc_form&page=ffc-short-urls&ffc_action=empty_trash' ),
                    'ffc_short_url_empty_trash'
                );
                ?>
                <div style="margin-bottom:10px;">
                    <a href="<?php echo esc_url( $empty_trash_url ); ?>" class="button button-link-delete"
                       onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to permanently delete all items in the trash?', 'ffcertificate' ); ?>');">
                        <?php esc_html_e( 'Empty Trash', 'ffcertificate' ); ?>
                    </a>
                </div>
            <?php endif; ?>

            <!-- Table -->
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:25%;"><?php esc_html_e( 'Title', 'ffcertificate' ); ?></th>
                        <th style="width:18%;"><?php esc_html_e( 'Short URL', 'ffcertificate' ); ?></th>
                        <th style="width:25%;"><?php esc_html_e( 'Destination', 'ffcertificate' ); ?></th>
                        <th style="width:8%;">
                            <?php
                            $clicks_url = add_query_arg( [
                                'post_type' => 'ffc_form',
                                'page'      => 'ffc-short-urls',
                                'orderby'   => 'click_count',
                                'order'     => ( $orderby === 'click_count' && $order === 'DESC' ) ? 'asc' : 'desc',
                                's'         => $search,
                                'status'    => $status,
                            ], admin_url( 'edit.php' ) );
                            ?>
                            <a href="<?php echo esc_url( $clicks_url ); ?>"><?php esc_html_e( 'Clicks', 'ffcertificate' ); ?></a>
                        </th>
                        <th style="width:10%;"><?php esc_html_e( 'Status', 'ffcertificate' ); ?></th>
                        <th style="width:14%;"><?php esc_html_e( 'Actions', 'ffcertificate' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $items ) ) : ?>
                        <tr>
                            <td colspan="6"><?php esc_html_e( 'No short URLs found.', 'ffcertificate' ); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ( $items as $item ) : ?>
                            <?php
                            $short_url   = $this->service->get_short_url( $item['short_code'] );
                            $is_trashed  = $item['status'] === 'trashed';

                            if ( $is_trashed ) {
                                $status_class = 'color:#b32d2e;';
                                $status_label = __( 'Trash', 'ffcertificate' );
                            } elseif ( $item['status'] === 'active' ) {
                                $status_class = 'color:#46b450;';
                                $status_label = __( 'Active', 'ffcertificate' );
                            } else {
                                $status_class = 'color:#dc3232;';
                                $status_label = __( 'Disabled', 'ffcertificate' );
                            }
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html( $item['title'] ?: '(' . __( 'no title', 'ffcertificate' ) . ')' ); ?></strong>
                                    <?php if ( $item['post_id'] ) : ?>
                                        <br><small><?php echo esc_html( get_the_title( (int) $item['post_id'] ) ); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <code class="ffc-shorturl-code" title="<?php esc_attr_e( 'Click to copy', 'ffcertificate' ); ?>" data-url="<?php echo esc_attr( $short_url ); ?>">
                                        <?php echo esc_html( $short_url ); ?>
                                    </code>
                                </td>
                                <td>
                                    <a href="<?php echo esc_url( $item['target_url'] ); ?>" target="_blank" rel="noopener noreferrer" title="<?php echo esc_attr( $item['target_url'] ); ?>">
                                        <?php echo esc_html( \FreeFormCertificate\Core\Utils::truncate( $item['target_url'], 50, '...' ) ); ?>
                                    </a>
                                </td>
                                <td><strong><?php echo esc_html( number_format_i18n( (int) $item['click_count'] ) ); ?></strong></td>
                                <td><span style="<?php echo esc_attr( $status_class ); ?>font-weight:600;"><?php echo esc_html( $status_label ); ?></span></td>
                                <td>
                                    <?php if ( $is_trashed ) : ?>
                                        <?php
                                        $restore_url = wp_nonce_url(
                                            admin_url( 'edit.php?post_type=ffc_form&page=ffc-short-urls&ffc_action=restore&id=' . $item['id'] ),
                                            'ffc_short_url_restore_' . $item['id']
                                        );
                                        $delete_url = wp_nonce_url(
                                            admin_url( 'edit.php?post_type=ffc_form&page=ffc-short-urls&ffc_action=delete&id=' . $item['id'] ),
                                            'ffc_short_url_delete_' . $item['id']
                                        );
                                        ?>
                                        <a href="<?php echo esc_url( $restore_url ); ?>" class="button button-small">
                                            <?php esc_html_e( 'Restore', 'ffcertificate' ); ?>
                                        </a>
                                        <a href="<?php echo esc_url( $delete_url ); ?>" class="button button-small button-link-delete" onclick="return confirm('<?php esc_attr_e( 'Delete permanently?', 'ffcertificate' ); ?>');">
                                            <?php esc_html_e( 'Delete Permanently', 'ffcertificate' ); ?>
                                        </a>
                                    <?php else : ?>
                                        <?php
                                        $toggle_url = wp_nonce_url(
                                            admin_url( 'edit.php?post_type=ffc_form&page=ffc-short-urls&ffc_action=toggle&id=' . $item['id'] ),
                                            'ffc_short_url_toggle_' . $item['id']
                                        );
                                        $trash_url = wp_nonce_url(
                                            admin_url( 'edit.php?post_type=ffc_form&page=ffc-short-urls&ffc_action=trash&id=' . $item['id'] ),
                                            'ffc_short_url_trash_' . $item['id']
                                        );
                                        ?>
                                        <button type="button" class="button button-small ffc-show-qr-modal"
                                                data-code="<?php echo esc_attr( $item['short_code'] ); ?>"
                                                data-url="<?php echo esc_attr( $short_url ); ?>"
                                                data-title="<?php echo esc_attr( $item['title'] ?: $item['short_code'] ); ?>">
                                            <span class="dashicons dashicons-screenoptions" style="margin-top:3px;font-size:14px;width:14px;height:14px;"></span>
                                            QR
                                        </button>
                                        <a href="<?php echo esc_url( $toggle_url ); ?>" class="button button-small">
                                            <?php echo $item['status'] === 'active' ? esc_html__( 'Disable', 'ffcertificate' ) : esc_html__( 'Enable', 'ffcertificate' ); ?>
                                        </a>
                                        <a href="<?php echo esc_url( $trash_url ); ?>" class="button button-small button-link-delete">
                                            <?php esc_html_e( 'Trash', 'ffcertificate' ); ?>
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- Pagination -->
            <?php if ( $total_pages > 1 ) : ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <?php
                        echo wp_kses_post( paginate_links( [
                            'base'      => add_query_arg( 'paged', '%#%' ),
                            'format'    => '',
                            'total'     => $total_pages,
                            'current'   => $page,
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                        ] ) );
                        ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- QR Code Modal Overlay -->
            <div id="ffc-qr-modal" class="ffc-qr-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="ffc-qr-modal-title">
                <div class="ffc-qr-modal__backdrop"></div>
                <div class="ffc-qr-modal__content">
                    <button type="button" class="ffc-qr-modal__close" aria-label="<?php esc_attr_e( 'Close', 'ffcertificate' ); ?>">&times;</button>
                    <h2 id="ffc-qr-modal-title" class="ffc-qr-modal__title"></h2>
                    <p class="ffc-qr-modal__url"></p>
                    <div class="ffc-qr-modal__preview">
                        <div class="ffc-qr-modal__spinner"><span class="spinner is-active"></span></div>
                        <img class="ffc-qr-modal__img" src="" alt="QR Code" style="display:none;" />
                    </div>
                    <div class="ffc-qr-modal__actions">
                        <button type="button" class="button ffc-copy-shorturl" data-url="">
                            <span class="dashicons dashicons-clipboard" style="margin-top:3px;"></span>
                            <?php esc_html_e( 'Copy URL', 'ffcertificate' ); ?>
                        </button>
                        <button type="button" class="button ffc-download-qr" data-format="png" data-code="">
                            <span class="dashicons dashicons-download" style="margin-top:3px;"></span>
                            PNG
                        </button>
                        <button type="button" class="button ffc-download-qr" data-format="svg" data-code="">
                            <span class="dashicons dashicons-download" style="margin-top:3px;"></span>
                            SVG
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}
