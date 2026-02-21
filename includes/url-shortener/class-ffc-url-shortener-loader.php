<?php
declare(strict_types=1);

/**
 * URL Shortener Loader
 *
 * Module coordinator: registers rewrite rules, handles redirects,
 * and initializes admin components (meta box, admin page).
 *
 * @since 5.1.0
 * @package FreeFormCertificate\UrlShortener
 */

namespace FreeFormCertificate\UrlShortener;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class UrlShortenerLoader {

    /** @var UrlShortenerService */
    private UrlShortenerService $service;

    public function __construct() {
        $this->service = new UrlShortenerService();
    }

    /**
     * Option key used to track whether rewrite rules have been flushed
     * for the current prefix configuration.
     */
    private const FLUSH_FLAG = 'ffc_url_shortener_rewrite_version';

    /**
     * Wire all hooks for the URL Shortener module.
     */
    public function init(): void {
        if ( ! $this->service->is_enabled() ) {
            return;
        }

        // Rewrite rules (front-end routing)
        add_action( 'init', [ $this, 'register_rewrite_rules' ] );
        add_filter( 'query_vars', [ $this, 'add_query_vars' ] );
        add_action( 'template_redirect', [ $this, 'handle_redirect' ] );

        // Auto-flush rewrite rules when prefix changes or first install
        add_action( 'init', [ $this, 'maybe_flush_rewrite_rules' ], 99 );

        // Admin components
        if ( is_admin() ) {
            $admin_page = new UrlShortenerAdminPage( $this->service );
            $admin_page->init();

            $meta_box = new UrlShortenerMetaBox( $this->service );
            $meta_box->init();
        }

        // AJAX handlers (needed for both admin and front-end contexts)
        $qr_handler = new UrlShortenerQrHandler( $this->service );
        $qr_handler->init();
    }

    /**
     * Flush rewrite rules once when the module is first installed
     * or when the URL prefix changes.
     */
    public function maybe_flush_rewrite_rules(): void {
        $current_version = $this->service->get_prefix() . ':1';
        $stored_version  = get_option( self::FLUSH_FLAG, '' );

        if ( $stored_version !== $current_version ) {
            flush_rewrite_rules( false );
            update_option( self::FLUSH_FLAG, $current_version, true );
        }
    }

    /**
     * Register WordPress rewrite rules for the short URL prefix.
     */
    public function register_rewrite_rules(): void {
        $prefix = $this->service->get_prefix();
        add_rewrite_rule(
            '^' . preg_quote( $prefix, '/' ) . '/([A-Za-z0-9]+)/?$',
            'index.php?ffc_short_code=$matches[1]',
            'top'
        );
    }

    /**
     * Register custom query variable.
     *
     * @param array<string> $vars Existing query vars.
     * @return array<string>
     */
    public function add_query_vars( array $vars ): array {
        $vars[] = 'ffc_short_code';
        return $vars;
    }

    /**
     * Handle incoming short URL requests and redirect to target.
     */
    public function handle_redirect(): void {
        $code = get_query_var( 'ffc_short_code' );

        if ( empty( $code ) ) {
            return;
        }

        $code = sanitize_text_field( $code );
        $repository = $this->service->get_repository();
        $record = $repository->findByShortCode( $code );

        if ( ! $record || $record['status'] !== 'active' ) {
            // Short code not found or disabled - let WordPress handle the 404
            return;
        }

        // Increment click counter
        $repository->incrementClickCount( (int) $record['id'] );

        $target_url    = esc_url_raw( $record['target_url'] );
        $redirect_type = $this->service->get_redirect_type();

        // Prevent redirect loops
        if ( empty( $target_url ) ) {
            return;
        }

        /**
         * Fires before a short URL redirect.
         *
         * @since 5.1.0
         * @param array  $record      The short URL record.
         * @param string $target_url   The target URL.
         * @param int    $redirect_type HTTP status code.
         */
        do_action( 'ffcertificate_before_short_redirect', $record, $target_url, $redirect_type );

        // Use wp_redirect for external, wp_safe_redirect for internal
        if ( wp_validate_redirect( $target_url, false ) ) {
            wp_safe_redirect( $target_url, $redirect_type );
        } else {
            // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- External URL redirect is intentional.
            wp_redirect( $target_url, $redirect_type );
        }
        exit;
    }

    /**
     * Flush rewrite rules (call on activation/settings change).
     */
    public static function flush_rules(): void {
        $loader = new self();
        $loader->register_rewrite_rules();
        flush_rewrite_rules();
    }
}
