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

    /** @var bool Whether a redirect was already handled in this request. */
    private bool $redirected = false;

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

        // Primary: intercept during parse_request (before WP_Query, no rewrite dependency)
        add_action( 'parse_request', [ $this, 'intercept_short_url' ], 1 );
        // Fallback: template_redirect catches anything parse_request missed
        add_action( 'template_redirect', [ $this, 'handle_redirect' ], 1 );

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
     * Extract a short code from the current request path.
     *
     * Parses REQUEST_URI directly so it works regardless of whether
     * WordPress rewrite rules matched the request.
     *
     * @return string Short code, or empty string if not a short URL request.
     */
    private function extract_code_from_uri(): string {
        $prefix  = $this->service->get_prefix();
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Existence checked by preg_match; sanitized by regex capture group (alphanumeric only).
        $raw_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
        $path    = trim( (string) wp_parse_url( $raw_uri, PHP_URL_PATH ), '/' );

        if ( preg_match( '/(?:^|\/)' . preg_quote( $prefix, '/' ) . '\/([A-Za-z0-9]+)\/?$/', $path, $matches ) ) {
            return $matches[1];
        }

        return '';
    }

    /**
     * Primary redirect handler — fires during parse_request, before WP_Query.
     *
     * This is more efficient than template_redirect because it avoids
     * running the main query for requests that are just short URL redirects.
     * It also works when rewrite rules are not properly flushed.
     *
     * @param \WP $wp WordPress environment instance.
     */
    public function intercept_short_url( $wp ): void {
        $code = $this->extract_code_from_uri();

        if ( empty( $code ) ) {
            return;
        }

        $this->redirected = true;
        $this->do_redirect( sanitize_text_field( $code ) );
    }

    /**
     * Fallback redirect handler — fires on template_redirect.
     *
     * Catches short URL requests that were not handled by intercept_short_url
     * (e.g. if parse_request was skipped by another plugin).
     */
    public function handle_redirect(): void {
        // Skip if already handled by intercept_short_url (prevents double-counting)
        if ( $this->redirected ) {
            return;
        }

        // Try the rewrite-rule query var first
        $code = get_query_var( 'ffc_short_code' );

        // Fallback: parse URI directly
        if ( empty( $code ) ) {
            $code = $this->extract_code_from_uri();
        }

        if ( empty( $code ) ) {
            return;
        }

        $this->do_redirect( sanitize_text_field( $code ) );
    }

    /**
     * Perform the actual redirect for a given short code.
     *
     * @param string $code Sanitized short code.
     */
    private function do_redirect( string $code ): void {
        $repository = $this->service->get_repository();
        $record     = $repository->findByShortCode( $code );

        if ( ! $record || $record['status'] !== 'active' ) {
            nocache_headers();
            wp_redirect( home_url( '/' ), 302 ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
            exit;
        }

        // Increment click counter
        $repository->incrementClickCount( (int) $record['id'] );

        $target_url    = esc_url_raw( $record['target_url'] );
        $redirect_type = $this->service->get_redirect_type();

        // Prevent redirect loops
        if ( empty( $target_url ) ) {
            nocache_headers();
            wp_redirect( home_url( '/' ), 302 ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
            exit;
        }

        /**
         * Fires before a short URL redirect.
         *
         * @since 5.1.0
         * @param array  $record        The short URL record.
         * @param string $target_url    The target URL.
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
