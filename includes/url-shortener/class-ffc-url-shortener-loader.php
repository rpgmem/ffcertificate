<?php
/**
 * URL Shortener Loader
 *
 * Module coordinator: registers rewrite rules, handles redirects,
 * and initializes admin components (meta box, admin page).
 *
 * @package FreeFormCertificate\UrlShortener
 * @since 5.1.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\UrlShortener;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loader for url shortener module.
 */
class UrlShortenerLoader {

	/**
	 * Description.
	 *
	 * @var UrlShortenerService
	 */
	private UrlShortenerService $service;

	/**
	 * Description.
	 *
	 * @var bool Whether a redirect was already handled in this request.
	 */
	private bool $redirected = false;

	/**
	 * Constructor.
	 */
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

		// Rewrite rules (front-end routing).
		add_action( 'init', array( $this, 'register_rewrite_rules' ) );
		add_filter( 'query_vars', array( $this, 'add_query_vars' ) );

		// Primary: intercept during parse_request (before WP_Query, no rewrite dependency).
		add_action( 'parse_request', array( $this, 'intercept_short_url' ), 1 );
		// Fallback: template_redirect catches anything parse_request missed.
		add_action( 'template_redirect', array( $this, 'handle_redirect' ), 1 );

		// Auto-flush rewrite rules when prefix changes or first install.
		add_action( 'init', array( $this, 'maybe_flush_rewrite_rules' ), 99 );

		// Expose short URLs as the canonical WordPress shortlink for opted-in
		// post types. Registered in both contexts (front-end `<head>`/header +
		// admin "Get Shortlink" button), so it sits outside the is_admin() gate.
		$shortlink = new UrlShortenerShortlink( $this->service );
		$shortlink->init();

		// REST: QR endpoint for external reuse (#889). REST runs in a non-admin
		// context, so this sits outside the is_admin() gate.
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		// Auto-create the short URL on publish. The block editor saves over the
		// REST API, where is_admin() is false, so registering this inside the
		// gate below meant a Gutenberg publish never created one — the short
		// URL only appeared later, when someone reopened the editor and the
		// meta box's render() created it on demand (#1013). The handler guards
		// itself, so running it in both contexts is safe.
		$meta_box = new UrlShortenerMetaBox( $this->service );
		$meta_box->init_auto_create();

		// The link follows its post (#2337). Posts are trashed and deleted
		// over REST and WP-CLI too, so this sits outside the gate as well.
		( new UrlShortenerPostLifecycle( $this->service ) )->init();

		// Admin components.
		if ( is_admin() ) {
			$admin_page = new UrlShortenerAdminPage( $this->service );
			$admin_page->init();

			$meta_box->init();

			// The list's on/off toggle saves through the record autosave (#1904).
			UrlShortenerRecordAutosave::register( $this->service );

			// Manual QR code generator (#1563): a submenu and its two AJAX
			// actions, both admin-only. admin-ajax.php runs with is_admin()
			// true, so the endpoint is reachable from here.
			( new QrGeneratorPage() )->init();
			( new QrGeneratorAjaxEndpoint( $this->service ) )->init();
			// The generator's signed event links. admin-post.php defines
			// WP_ADMIN, so this gate covers the anonymous scan as well.
			( new QrEventIcsHandler() )->init();

			$backfill = new UrlShortenerBackfillHandler( $this->service );
			$backfill->init();

			// CSV export — register the batched source with the shared registry
			// (#772); the unified dispatcher (wired in Loader) routes
			// `type=url_shortener` start/batch/download requests to it.
			$repository = $this->service->get_repository();
			\FreeFormCertificate\Core\SourceRegistry::register(
				UrlShortenerExportSource::TYPE,
				static function () use ( $repository ): UrlShortenerExportSource {
					return new UrlShortenerExportSource( $repository );
				}
			);
		}

		// AJAX handlers (needed for both admin and front-end contexts).
		$qr_handler = new UrlShortenerQrHandler( $this->service );
		$qr_handler->init();
	}

	/**
	 * Register the module's REST routes (the QR endpoint, #889).
	 */
	public function register_rest_routes(): void {
		$controller = new UrlShortenerQrRestController( $this->service );
		$controller->register_routes();
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
	 * WordPress rewrite rules matched the request. The match is anchored to
	 * the site's home path (#2337): only `<home path>/<prefix>/<code>` is a
	 * short URL. Unanchored, `/docs/go/install` was captured at
	 * `parse_request` priority 1 and sent home.
	 *
	 * @return string Short code, or empty string if not a short URL request.
	 */
	private function extract_code_from_uri(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Server-set REQUEST_URI, parsed by wp_parse_url() and matched against the anchored code pattern.
		$raw_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		return self::code_from_path( (string) wp_parse_url( $raw_uri, PHP_URL_PATH ), (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), $this->service->get_prefix() );
	}

	/**
	 * The short code a request path names, or '' (#2337): the path must be
	 * exactly the home path, the prefix and one code, with an optional
	 * trailing slash.
	 *
	 * @param string $path      Request path, as sent.
	 * @param string $home_path The home URL's path ('/' at the root, '/site/' in a sub-directory).
	 * @param string $prefix    Short URL prefix.
	 * @return string
	 */
	public static function code_from_path( string $path, string $home_path, string $prefix ): string {
		$path = trim( $path, '/' );
		$home = trim( $home_path, '/' );
		$base = ( '' === $home ? '' : preg_quote( $home, '/' ) . '\/' ) . preg_quote( trim( $prefix, '/' ), '/' );

		if ( '' !== trim( $prefix, '/' ) && 1 === preg_match( '/^' . $base . '\/([A-Za-z0-9]+)$/', $path, $matches ) ) {
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
			$this->forward_previous_prefix();
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
		// Skip if already handled by intercept_short_url (prevents double-counting).
		if ( $this->redirected ) {
			return;
		}

		// Try the rewrite-rule query var first.
		$code = get_query_var( 'ffc_short_code' );

		// Fallback: parse URI directly.
		if ( empty( $code ) ) {
			$code = $this->extract_code_from_uri();
		}

		if ( empty( $code ) ) {
			$this->forward_previous_prefix();
			return;
		}

		$this->do_redirect( sanitize_text_field( $code ) );
	}

	/**
	 * Send a request under a previous prefix on to the current one (#2337).
	 *
	 * Only for a code the site knows, live or retired: anything else under an
	 * old prefix may be a page of the site that happens to share the word,
	 * and is left to WordPress. The click is counted once, on the final hop,
	 * so this hop is a plain 302 that no browser keeps.
	 */
	private function forward_previous_prefix(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Server-set REQUEST_URI, parsed by wp_parse_url() and matched against the anchored code pattern.
		$raw_uri   = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$path      = (string) wp_parse_url( $raw_uri, PHP_URL_PATH );
		$home_path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );

		foreach ( $this->service->get_previous_prefixes() as $prefix ) {
			$code = self::code_from_path( $path, $home_path, $prefix );
			if ( '' === $code ) {
				continue;
			}

			$repository = $this->service->get_repository();
			if ( ! $repository->findByShortCode( $code ) && ! $repository->codeRetired( $code ) ) {
				return;
			}

			$this->redirected = true;
			nocache_headers();
			wp_redirect( $this->service->get_short_url( $code ), 302 ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- The target is this site's own short URL, built from home_url().
			exit;
		}
	}

	/**
	 * Perform the actual redirect for a given short code.
	 *
	 * @param string $code Sanitized short code.
	 */
	private function do_redirect( string $code ): void {
		$repository = $this->service->get_repository();
		$record     = $repository->findByShortCode( $code );

		// A dead code gets a page that says so, not a silent trip home (#2337):
		// 410 for a code that existed (off, trashed or retired), 404 otherwise.
		if ( ! $record ) {
			self::render_inactive_page( $repository->codeRetired( $code ) ? 410 : 404 );
		}
		if ( 'active' !== $record['status'] ) {
			self::render_inactive_page( 410 );
		}

		// Defer click counter to shutdown so the redirect response is sent
		// first. A HEAD request or a link preview is not a visit (#2337).
		$record_id = (int) $record['id'];
		if ( self::is_counted_visit() ) {
			add_action(
				'shutdown',
				static function () use ( $repository, $record_id ): void {
					$repository->incrementClickCount( $record_id );
				}
			);
		}

		// Resolve the destination dynamically: post-linked short URLs follow the
		// post's current permalink (self-heals on slug / permalink-structure
		// change), manual ones use the stored target (#888).
		// The same schemes the destination was accepted under (#2337): the
		// default list would turn an sftp:// or ssh:// destination into ''.
		$target_url    = esc_url_raw( UrlShortenerService::resolve_target( $record ), UrlShortenerService::DESTINATION_SCHEMES );
		$redirect_type = $this->service->get_redirect_type();

		// Prevent redirect loops.
		if ( empty( $target_url ) ) {
			nocache_headers();
			wp_redirect( home_url( '/' ), 302 ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- The target is home_url(), a first-party constant — wp_safe_redirect() would add a host allowlist check with nothing request-derived to check.
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

		// The redirect is decided here on every visit: a cache that kept it
		// would go on sending visitors to a link after it is switched off.
		nocache_headers();

		// Use wp_redirect for external, wp_safe_redirect for internal.
		if ( wp_validate_redirect( $target_url, '' ) ) {
			wp_safe_redirect( $target_url, $redirect_type );
		} else {
            // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- External URL redirect is intentional.
			wp_redirect( $target_url, $redirect_type );
		}
		exit;
	}

	/**
	 * User agents of the link previews that fetch a URL when it is pasted
	 * into a chat or a post (#2337). Matched case-insensitively as substrings.
	 */
	public const PREVIEW_AGENTS = array(
		'facebookexternalhit',
		'facebot',
		'whatsapp',
		'slackbot',
		'slack-imgproxy',
		'twitterbot',
		'telegrambot',
		'linkedinbot',
		'discordbot',
		'skypeuripreview',
		'microsoftpreview',
		'pinterestbot',
		'redditbot',
		'embedly',
		'iframely',
		'vkshare',
		'mastodon',
		'bluesky',
	);

	/**
	 * Whether this request counts as a click: not a HEAD request and not a
	 * link preview (#2337).
	 *
	 * @return bool
	 */
	public static function is_counted_visit(): bool {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'HEAD' === $method ) {
			return false;
		}

		$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) ) : '';
		foreach ( self::PREVIEW_AGENTS as $preview ) {
			if ( false !== strpos( $agent, $preview ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Answer a dead code with a page that says so, then stop (#2337).
	 *
	 * The page stands alone, outside the theme, so it renders the same on
	 * every site. It is not indexed, and it goes to the home page after 15
	 * seconds, with a link to go now.
	 *
	 * @param int $status 410 for a code that existed, 404 for one that never did.
	 * @return never
	 */
	public static function render_inactive_page( int $status ): void {
		status_header( $status );
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );

		$home_url  = home_url( '/' );
		$site_name = get_bloginfo( 'name' );
		$seconds   = self::INACTIVE_COUNTDOWN;
		include FFC_PLUGIN_DIR . 'templates/url-shortener/inactive-link.php';
		exit;
	}

	/**
	 * Seconds the inactive-link page waits before going home: the plugin's
	 * countdown convention.
	 */
	public const INACTIVE_COUNTDOWN = 15;

	/**
	 * Register the short-URL rewrite rules and flush them.
	 *
	 * @deprecated 6.43.0 Nothing in the plugin calls it (#2089).
	 * @removal    6.45.0
	 */
	public static function flush_rules(): void {
		_deprecated_function( __METHOD__, '6.43.0' );

		$loader = new self();
		$loader->register_rewrite_rules();
		flush_rewrite_rules();
	}
}
