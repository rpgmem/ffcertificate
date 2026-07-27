<?php
/**
 * GithubUpdater
 *
 * Self-hosted plugin updater: teaches WordPress's native plugin-update check to
 * see this plugin's GitHub Releases. It injects the release into the
 * `update_plugins` transient (pointing at the built `ffcertificate-X.Y.Z.zip`
 * release asset, never the source zipball), serves the "view details" modal,
 * and verifies the downloaded package's SHA-256 before install.
 *
 * Posture: notify + native opt-in. It never forces `auto_update_plugin`; the
 * admin enables background auto-updates via WordPress's own per-plugin toggle
 * (which appears because the plugin is placed in `no_update` when current).
 *
 * Repo is public, so no token is needed; a 12h transient + ETag conditional
 * requests keep the unauthenticated 60/h rate limit at bay.
 *
 * @package FreeFormCertificate\Integrations
 * @since   6.18.0
 * @see     https://github.com/rpgmem/ffcertificate/issues/820
 */

declare(strict_types=1);

namespace FreeFormCertificate\Integrations;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GitHub Releases updater.
 */
class GithubUpdater {

	/**
	 * `owner/repo` this plugin ships from.
	 */
	private const REPO = 'rpgmem/ffcertificate';

	/**
	 * Plugin basename (folder/main-file) as WordPress keys it.
	 */
	private const PLUGIN_FILE = 'ffcertificate/ffcertificate.php';

	/**
	 * Plugin slug (also the details-modal slug).
	 */
	private const SLUG = 'ffcertificate';

	/**
	 * Site-transient key caching the parsed latest release + ETag.
	 */
	private const CACHE_KEY = 'ffc_github_update';

	/**
	 * Cache TTL in seconds (12h).
	 */
	private const CACHE_TTL = 43200;

	/**
	 * Compatibility fields surfaced in the update UI. Kept in sync with
	 * `readme.txt` (Requires at least / Tested up to) and the plugin header
	 * (Requires PHP); hard-coded to avoid a runtime filesystem read.
	 */
	private const WP_REQUIRES  = '6.2';
	private const WP_TESTED    = '7.0';
	private const PHP_REQUIRES = '8.3';

	/**
	 * Guard so the hooks register only once.
	 *
	 * @var bool
	 */
	private static $booted = false;

	/**
	 * Register the update hooks. Wired unconditionally (NOT behind is_admin):
	 * the update scan runs in cron, outside the admin context.
	 */
	public static function init(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'check_for_update' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'verify_download' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'flush_cache' ) );
	}

	/**
	 * Inject the GitHub release into the plugin-update transient.
	 *
	 * When an update is available it lands in `->response`; when current it
	 * lands in `->no_update` (required for WP to show the per-plugin
	 * auto-update toggle for a self-hosted plugin).
	 *
	 * @param mixed $transient The `update_plugins` transient object.
	 * @return mixed
	 */
	public static function check_for_update( $transient ) {
		// The update_plugins transient is a stdClass; narrowing here keeps
		// property access statically type-safe.
		$obj = ( $transient instanceof \stdClass ) ? $transient : null;
		if ( null === $obj || ! apply_filters( 'ffc_github_updater_enabled', true ) ) {
			return $transient;
		}

		// WP populates ->checked during a real scan; bail otherwise so we never
		// fabricate an entry during unrelated transient writes.
		$checked = isset( $obj->checked ) && is_array( $obj->checked ) ? $obj->checked : array();
		if ( empty( $checked[ self::PLUGIN_FILE ] ) ) {
			return $transient;
		}

		$release = self::get_latest_release();
		if ( null === $release ) {
			return $transient;
		}

		$installed = (string) $checked[ self::PLUGIN_FILE ];

		if ( ! isset( $obj->response ) || ! is_array( $obj->response ) ) {
			$obj->response = array();
		}
		if ( ! isset( $obj->no_update ) || ! is_array( $obj->no_update ) ) {
			$obj->no_update = array();
		}

		if ( version_compare( $installed, $release['version'], '<' ) ) {
			$obj->response[ self::PLUGIN_FILE ] = self::build_update_object( $release, $release['version'] );
			unset( $obj->no_update[ self::PLUGIN_FILE ] );
		} else {
			$obj->no_update[ self::PLUGIN_FILE ] = self::build_update_object( $release, $installed );
			unset( $obj->response[ self::PLUGIN_FILE ] );
		}

		return $transient;
	}

	/**
	 * Serve the "View details" modal (`plugins_api`) for this plugin.
	 *
	 * @param mixed  $res    Default result (false) or another plugin's object.
	 * @param string $action The plugins_api action.
	 * @param mixed  $args   Query args (expects ->slug).
	 * @return mixed
	 */
	public static function plugin_info( $res, $action = '', $args = null ) {
		if ( 'plugin_information' !== $action || ! ( $args instanceof \stdClass ) ) {
			return $res;
		}

		if ( empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $res;
		}

		$release = self::get_latest_release();
		if ( null === $release ) {
			return $res;
		}

		$info                = new \stdClass();
		$info->name          = 'Free Form Certificate';
		$info->slug          = self::SLUG;
		$info->version       = $release['version'];
		$info->author        = '<a href="https://github.com/rpgmem">Alex Meusburger</a>';
		$info->homepage      = $release['url'];
		$info->requires      = self::WP_REQUIRES;
		$info->tested        = self::WP_TESTED;
		$info->requires_php  = self::PHP_REQUIRES;
		$info->last_updated  = $release['published_at'];
		$info->download_link = $release['package'];
		$info->sections      = array(
			'changelog' => wpautop( esc_html( $release['changelog'] ) ),
		);

		return $info;
	}

	/**
	 * Verify the SHA-256 of our release asset before WordPress installs it.
	 *
	 * Returning a file path short-circuits WP's own download with the verified
	 * file; returning a WP_Error aborts the install on a checksum mismatch.
	 * Any package that is not ours (or has no published digest) passes through
	 * untouched.
	 *
	 * @param mixed  $reply    Short-circuit value (false = let WP download).
	 * @param string $package  The package URL WP is about to download.
	 * @param mixed  $upgrader The WP_Upgrader instance (unused).
	 * @return mixed
	 */
	public static function verify_download( $reply, $package = '', $upgrader = null ) {
		unset( $upgrader );

		$release = self::get_latest_release();
		if ( null === $release || '' === $release['sha256'] || $package !== $release['package'] ) {
			return $reply;
		}

		if ( ! function_exists( 'download_url' ) ) {
			return $reply;
		}

		$tmp = download_url( $package );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		$actual = (string) hash_file( 'sha256', (string) $tmp );
		if ( ! hash_equals( strtolower( $release['sha256'] ), strtolower( $actual ) ) ) {
			if ( function_exists( 'wp_delete_file' ) ) {
				wp_delete_file( (string) $tmp );
			}
			self::debug_log(
				'SHA-256 mismatch — aborting install',
				array(
					'expected' => $release['sha256'],
					'actual'   => $actual,
				)
			);
			return new WP_Error(
				'ffc_updater_checksum',
				__( 'A verificação de integridade (SHA-256) do pacote de atualização falhou. Instalação abortada.', 'ffcertificate' )
			);
		}

		return $tmp;
	}

	/**
	 * Drop the cached release after any plugin update completes.
	 */
	public static function flush_cache(): void {
		delete_site_transient( self::CACHE_KEY );
	}

	/**
	 * Build the object WordPress expects in response/no_update.
	 *
	 * @param array<string, string> $release     Parsed release payload.
	 * @param string                $new_version Version to advertise.
	 * @return \stdClass
	 */
	private static function build_update_object( array $release, string $new_version ): \stdClass {
		$obj               = new \stdClass();
		$obj->id           = 'github.com/' . self::REPO;
		$obj->slug         = self::SLUG;
		$obj->plugin       = self::PLUGIN_FILE;
		$obj->new_version  = $new_version;
		$obj->url          = $release['url'];
		$obj->package      = $release['package'];
		$obj->requires     = self::WP_REQUIRES;
		$obj->tested       = self::WP_TESTED;
		$obj->requires_php = self::PHP_REQUIRES;
		return $obj;
	}

	/**
	 * Return the parsed latest release, from cache or a fresh fetch.
	 *
	 * @return array<string, string>|null
	 */
	private static function get_latest_release(): ?array {
		$cache      = get_site_transient( self::CACHE_KEY );
		$cache      = is_array( $cache ) ? $cache : array();
		$fetched_at = isset( $cache['fetched_at'] ) ? (int) $cache['fetched_at'] : 0;
		$cached     = self::to_payload( $cache['payload'] ?? null );

		// Fresh cache hit.
		if ( $fetched_at > 0 && ( time() - $fetched_at ) < self::CACHE_TTL && null !== $cached ) {
			return $cached;
		}

		$etag    = isset( $cache['etag'] ) ? (string) $cache['etag'] : '';
		$fetched = self::fetch_release( $etag );

		// Fetch failed → reuse a stale payload if we have one (never break the scan).
		if ( null === $fetched ) {
			return $cached;
		}

		// 304 Not Modified → refresh the TTL, keep the cached payload + ETag.
		if ( 'not_modified' === $fetched['status'] ) {
			$cache['fetched_at'] = time();
			set_site_transient( self::CACHE_KEY, $cache, self::CACHE_TTL );
			return $cached;
		}

		$payload = self::to_payload( $fetched['payload'] ?? null );
		$store   = array(
			'payload'    => $payload,
			'etag'       => $fetched['etag'] ?? '',
			'fetched_at' => time(),
		);
		set_site_transient( self::CACHE_KEY, $store, self::CACHE_TTL );

		return $payload;
	}

	/**
	 * Normalize a raw payload (from cache or a fetch) into a typed shape, or
	 * null if it lacks a package URL.
	 *
	 * @param mixed $raw Raw payload.
	 * @return array<string, string>|null
	 */
	private static function to_payload( $raw ): ?array {
		if ( ! is_array( $raw ) || empty( $raw['package'] ) ) {
			return null;
		}
		return array(
			'version'      => (string) ( $raw['version'] ?? '' ),
			'package'      => (string) ( $raw['package'] ?? '' ),
			'sha256'       => (string) ( $raw['sha256'] ?? '' ),
			'url'          => (string) ( $raw['url'] ?? '' ),
			'changelog'    => (string) ( $raw['changelog'] ?? '' ),
			'published_at' => (string) ( $raw['published_at'] ?? '' ),
		);
	}

	/**
	 * Fetch releases/latest with an ETag conditional request.
	 *
	 * @param string $etag Prior ETag (sent as If-None-Match; a 304 costs no rate limit).
	 * @return array{status: string, payload?: array<string, string>, etag?: string}|null
	 */
	private static function fetch_release( string $etag ) {
		$repo    = (string) apply_filters( 'ffc_github_updater_repo', self::REPO );
		$url     = sprintf( 'https://api.github.com/repos/%s/releases/latest', $repo );
		$headers = array(
			'Accept'     => 'application/vnd.github+json',
			'User-Agent' => 'ffcertificate-updater',
		);
		if ( '' !== $etag ) {
			$headers['If-None-Match'] = $etag;
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 10,
				'headers' => $headers,
			)
		);

		if ( is_wp_error( $response ) ) {
			self::debug_log( 'Release fetch failed', array( 'error' => $response->get_error_message() ) );
			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 304 === $code ) {
			return array( 'status' => 'not_modified' );
		}
		if ( 200 !== $code ) {
			self::debug_log( 'Release fetch non-200', array( 'code' => $code ) );
			return null;
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['tag_name'] ) ) {
			return null;
		}

		$payload = self::parse_release( $data );
		if ( null === $payload ) {
			return null;
		}

		$new_etag = wp_remote_retrieve_header( $response, 'etag' );

		return array(
			'status'  => 'ok',
			'payload' => $payload,
			'etag'    => is_string( $new_etag ) ? $new_etag : '',
		);
	}

	/**
	 * Normalize a releases/latest response down to what the updater needs.
	 * Selects the built `ffcertificate-*.zip` asset (never the source zipball)
	 * and its SHA-256 digest; returns null if no proper asset is attached.
	 *
	 * @param array<string, mixed> $data Decoded GitHub release.
	 * @return array<string, string>|null
	 */
	private static function parse_release( array $data ): ?array {
		$version = ltrim( (string) $data['tag_name'], 'v' );
		$package = '';
		$sha256  = '';

		$assets = isset( $data['assets'] ) && is_array( $data['assets'] ) ? $data['assets'] : array();
		foreach ( $assets as $asset ) {
			if ( ! is_array( $asset ) ) {
				continue;
			}
			$name = (string) ( $asset['name'] ?? '' );
			if ( 1 === preg_match( '/^ffcertificate-.*\.zip$/', $name ) ) {
				$package = (string) ( $asset['browser_download_url'] ?? '' );
				$digest  = (string) ( $asset['digest'] ?? '' );
				if ( 0 === strpos( $digest, 'sha256:' ) ) {
					$sha256 = substr( $digest, 7 );
				}
				break;
			}
		}

		if ( '' === $package ) {
			return null;
		}

		return array(
			'version'      => $version,
			'package'      => $package,
			'sha256'       => $sha256,
			'url'          => (string) ( $data['html_url'] ?? '' ),
			'changelog'    => (string) ( $data['body'] ?? '' ),
			'published_at' => (string) ( $data['published_at'] ?? '' ),
		);
	}

	/**
	 * Gated debug breadcrumb (off by default; reuses the admin debug area).
	 *
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Context.
	 */
	private static function debug_log( string $message, array $context = array() ): void {
		if ( class_exists( '\FreeFormCertificate\Core\Debug' ) ) {
			\FreeFormCertificate\Core\Debug::log_admin( '[GithubUpdater] ' . $message, $context );
		}
	}
}
