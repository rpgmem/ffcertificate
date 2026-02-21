<?php
declare(strict_types=1);

/**
 * URL Shortener Meta Box
 *
 * Adds a meta box to post/page editors showing the short URL,
 * QR Code preview, and download options (PNG/SVG).
 *
 * @since 5.1.0
 * @package FreeFormCertificate\UrlShortener
 */

namespace FreeFormCertificate\UrlShortener;

use FreeFormCertificate\Core\AjaxTrait;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class UrlShortenerMetaBox {

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
        add_action( 'add_meta_boxes', [ $this, 'register_meta_box' ] );
        add_action( 'save_post', [ $this, 'on_save_post' ], 20, 2 );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        // AJAX: regenerate short code
        add_action( 'wp_ajax_ffc_regenerate_short_url', [ $this, 'ajax_regenerate' ] );
    }

    /**
     * Register meta box for enabled post types.
     */
    public function register_meta_box(): void {
        $post_types = $this->service->get_enabled_post_types();

        foreach ( $post_types as $post_type ) {
            add_meta_box(
                'ffc_url_shortener',
                __( 'Short URL & QR Code', 'ffcertificate' ),
                [ $this, 'render' ],
                $post_type,
                'side',
                'default'
            );
        }
    }

    /**
     * Render the meta box content.
     *
     * @param \WP_Post $post Current post.
     */
    public function render( \WP_Post $post ): void {
        $repository = $this->service->get_repository();
        $record     = $repository->findByPostId( $post->ID );

        if ( $post->post_status !== 'publish' && ! $record ) {
            echo '<p class="description">' . esc_html__( 'Publish the post to generate a short URL.', 'ffcertificate' ) . '</p>';
            wp_nonce_field( 'ffc_short_url_meta_box', 'ffc_short_url_meta_nonce' );
            return;
        }

        if ( ! $record && $post->post_status === 'publish' ) {
            // Auto-create if post is published
            $permalink = get_permalink( $post->ID );
            $result    = $this->service->create_short_url( $permalink, $post->post_title, $post->ID );
            $record    = $result['success'] ? $result['data'] : null;
        }

        if ( ! $record ) {
            echo '<p class="description">' . esc_html__( 'Could not generate short URL.', 'ffcertificate' ) . '</p>';
            return;
        }

        $short_url = $this->service->get_short_url( $record['short_code'] );
        $permalink = get_permalink( $post->ID );

        // Generate QR Code pointing to the post permalink (not the short URL)
        $qr_handler = new UrlShortenerQrHandler( $this->service );
        $qr_base64  = $qr_handler->generate_qr_base64( $permalink, 200 );

        wp_nonce_field( 'ffc_short_url_meta_box', 'ffc_short_url_meta_nonce' );
        ?>
        <div class="ffc-shorturl-metabox">
            <!-- Short URL -->
            <div style="margin-bottom:12px;">
                <label style="font-weight:600;display:block;margin-bottom:4px;"><?php esc_html_e( 'Short URL', 'ffcertificate' ); ?></label>
                <div style="display:flex;gap:4px;">
                    <input type="text" value="<?php echo esc_attr( $short_url ); ?>" readonly
                           id="ffc-shorturl-input" class="widefat" style="font-size:12px;" />
                    <button type="button" class="button ffc-copy-shorturl" data-url="<?php echo esc_attr( $short_url ); ?>"
                            title="<?php esc_attr_e( 'Copy', 'ffcertificate' ); ?>">
                        <span class="dashicons dashicons-clipboard" style="margin-top:3px;"></span>
                    </button>
                </div>
            </div>

            <!-- Click count -->
            <p style="margin:8px 0;">
                <strong><?php echo esc_html( number_format_i18n( (int) $record['click_count'] ) ); ?></strong>
                <?php esc_html_e( 'clicks', 'ffcertificate' ); ?>
            </p>

            <!-- QR Code Preview -->
            <?php if ( ! empty( $qr_base64 ) ) : ?>
                <div style="text-align:center;margin:12px 0;padding:10px;background:#fff;border:1px solid #ddd;border-radius:4px;">
                    <img src="data:image/png;base64,<?php echo esc_attr( $qr_base64 ); ?>"
                         alt="QR Code" style="width:180px;height:180px;display:block;margin:0 auto;" />
                </div>

                <!-- Download Buttons -->
                <div style="display:flex;gap:6px;justify-content:center;margin-bottom:8px;">
                    <button type="button" class="button button-small ffc-download-qr" data-format="png" data-post-id="<?php echo esc_attr( (string) $post->ID ); ?>">
                        <span class="dashicons dashicons-download" style="margin-top:3px;font-size:14px;"></span> PNG
                    </button>
                    <button type="button" class="button button-small ffc-download-qr" data-format="svg" data-post-id="<?php echo esc_attr( (string) $post->ID ); ?>">
                        <span class="dashicons dashicons-download" style="margin-top:3px;font-size:14px;"></span> SVG
                    </button>
                </div>
            <?php endif; ?>

            <!-- Regenerate -->
            <div style="text-align:center;margin-top:8px;">
                <button type="button" class="button button-small ffc-regenerate-shorturl" data-post-id="<?php echo esc_attr( (string) $post->ID ); ?>">
                    <span class="dashicons dashicons-update" style="margin-top:3px;font-size:14px;"></span>
                    <?php esc_html_e( 'Regenerate', 'ffcertificate' ); ?>
                </button>
            </div>
        </div>
        <?php
    }

    /**
     * Auto-create short URL on post publish.
     *
     * @param int      $post_id Post ID.
     * @param \WP_Post $post    Post object.
     */
    public function on_save_post( int $post_id, \WP_Post $post ): void {
        // Skip auto-save, revisions, and non-publish
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( wp_is_post_revision( $post_id ) ) {
            return;
        }
        if ( $post->post_status !== 'publish' ) {
            return;
        }
        if ( ! $this->service->is_auto_create_enabled() ) {
            return;
        }

        $enabled_types = $this->service->get_enabled_post_types();
        if ( ! in_array( $post->post_type, $enabled_types, true ) ) {
            return;
        }

        // Check if short URL already exists
        $existing = $this->service->get_repository()->findByPostId( $post_id );
        if ( $existing ) {
            return;
        }

        $permalink = get_permalink( $post_id );
        if ( $permalink ) {
            $this->service->create_short_url( $permalink, $post->post_title, $post_id );
        }
    }

    /**
     * Enqueue assets on relevant admin screens.
     *
     * @param string $hook_suffix Admin page hook suffix.
     */
    public function enqueue_assets( string $hook_suffix ): void {
        if ( ! in_array( $hook_suffix, [ 'post.php', 'post-new.php' ], true ) ) {
            return;
        }

        $post_type = get_post_type();
        if ( ! $post_type || ! in_array( $post_type, $this->service->get_enabled_post_types(), true ) ) {
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
                'copied'      => __( 'Copied!', 'ffcertificate' ),
                'copyFailed'  => __( 'Copy failed', 'ffcertificate' ),
                'regenerated' => __( 'Short URL regenerated!', 'ffcertificate' ),
                'error'       => __( 'An error occurred.', 'ffcertificate' ),
                'confirm'     => __( 'Generate a new short code? The old one will stop working.', 'ffcertificate' ),
            ],
        ] );
    }

    /**
     * AJAX: Regenerate short URL for a post.
     */
    public function ajax_regenerate(): void {
        $this->verify_ajax_nonce( 'ffc_short_url_nonce' );
        $this->check_ajax_permission();

        $post_id = (int) ( $_POST['post_id'] ?? 0 );
        if ( $post_id <= 0 ) {
            wp_send_json_error( [ 'message' => __( 'Invalid post ID.', 'ffcertificate' ) ] );
        }

        // Delete existing
        $existing = $this->service->get_repository()->findByPostId( $post_id );
        if ( $existing ) {
            $this->service->delete_short_url( (int) $existing['id'] );
        }

        // Create new
        $permalink = get_permalink( $post_id );
        $post      = get_post( $post_id );
        $result    = $this->service->create_short_url( $permalink, $post->post_title ?? '', $post_id );

        if ( $result['success'] ) {
            $data = $result['data'];
            $data['short_url'] = $this->service->get_short_url( $data['short_code'] );
            wp_send_json_success( $data );
        } else {
            wp_send_json_error( [ 'message' => $result['error'] ] );
        }
    }
}
