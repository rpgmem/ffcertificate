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
use FreeFormCertificate\Core\RequestInput;

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
	public const SLUG = 'ffcertificate';

	/**
	 * Site-transient key caching the parsed latest release + ETag.
	 */
	private const CACHE_KEY = 'ffc_github_update';

	/**
	 * Cache TTL in seconds (12h).
	 */
	private const CACHE_TTL = 43200;

	/**
	 * The screenshots the readme's `== Screenshots ==` captions describe, in
	 * caption order, under `docs/screenshots/` in the repository.
	 *
	 * They are served from GitHub at the release tag rather than shipped in
	 * the zip. `PluginDetailsAssetsTest` holds this list to the folder and to
	 * the number of captions.
	 *
	 * @since 6.39.0
	 */
	public const SCREENSHOTS = array(
		'01-certificates-dashboard.png',
		'02-form-builder.png',
		'03-certificate-preview.png',
		'04-submissions.png',
		'05-public-form.png',
		'06-certificate-verification.png',
		'07-booking-calendar.png',
		'08-appointments.png',
		'09-room-calendar.png',
		'10-date-message-preview.png',
		'11-qr-generator.png',
		'12-short-urls.png',
		'13-dark-mode.png',
		'14-user-dashboard.png',
	);

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

		// An admin-initiated "Check again" (Dashboard → Updates → force-check)
		// drops our 12h release cache so the scan re-fetches GitHub instead of
		// serving a stale payload. delete_site_transient() also clears the value
		// from a persistent object cache (Redis / Memcached / LiteSpeed LSMCD),
		// so one "Check again" refreshes it there too — no separate cache purge.
		if ( self::is_forced_check() ) {
			delete_site_transient( self::CACHE_KEY );
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
		$compat              = self::compat( $release );
		$info->requires      = $compat['requires'];
		$info->tested        = $compat['tested'];
		$info->requires_php  = $compat['requires_php'];
		$info->last_updated  = $release['published_at'];
		$info->download_link = $release['package'];
		$info->icons         = self::icons();
		$info->banners       = self::banners();
		$info->sections      = self::sections( $release );

		return $info;
	}

	/**
	 * The modal's tabs: the offered release's readme, plus its notes as the
	 * changelog.
	 *
	 * The readme is the one fetched with the release, so the tabs describe the
	 * version being offered, in the viewer's language when the plugin ships a
	 * translation of it ({@see GithubUpdater::readme()}). The release notes are
	 * Markdown, rendered rather than printed raw, and stay in English: they are
	 * the CHANGELOG, which is not translated.
	 *
	 * @since 6.39.0
	 * @param array<string, string> $release Parsed release payload.
	 * @return array<string, string>
	 */
	private static function sections( array $release ): array {
		$repo   = (string) apply_filters( 'ffc_github_updater_repo', self::REPO );
		$readme = self::readme( $release, self::locale() );
		$tag    = 'v' . $release['version'];

		$shots = array();
		foreach ( self::SCREENSHOTS as $file ) {
			$shots[] = sprintf( 'https://raw.githubusercontent.com/%s/%s/docs/screenshots/%s', $repo, rawurlencode( $tag ), $file );
		}

		$tabs = ReadmeSections::fromReadme( $readme, $repo, $shots );
		if ( '' !== trim( $release['changelog'] ) ) {
			$tabs['changelog'] = ReadmeSections::toHtml( $release['changelog'], $repo );
		}

		$ordered = array();
		foreach ( array( 'description', 'installation', 'faq', 'changelog', 'screenshots', 'other_notes' ) as $key ) {
			if ( isset( $tabs[ $key ] ) ) {
				$ordered[ $key ] = wp_kses_post( $tabs[ $key ] );
			}
		}
		return $ordered;
	}

	/**
	 * The readme text the tabs are built from, in the first of these that
	 * exists: the offered release's translation into `$locale`, the installed
	 * translation, the offered release's English readme, the installed one.
	 *
	 * A translation outranks a newer English text on purpose: an operator who
	 * reads Portuguese is better served by last release's description in
	 * Portuguese than by this release's in English, and the two differ only
	 * when the release's own translation could not be fetched.
	 *
	 * @since 6.39.0
	 * @param array<string, string> $release Parsed release payload.
	 * @param string                $locale  Viewer's locale, e.g. `pt_BR`; empty for English.
	 */
	private static function readme( array $release, string $locale ): string {
		if ( '' !== $locale ) {
			if ( '' !== ( $release[ 'readme_' . $locale ] ?? '' ) ) {
				return $release[ 'readme_' . $locale ];
			}
			$translated = self::installed_file( 'languages/readme-' . $locale . '.txt' );
			if ( '' !== $translated ) {
				return $translated;
			}
		}
		return '' !== ( $release['readme'] ?? '' ) ? $release['readme'] : self::installed_file( 'readme.txt' );
	}

	/**
	 * The viewer's locale when the plugin ships a readme or banner for it, or
	 * an empty string for English.
	 *
	 * Checked against the shipped translations rather than passed through,
	 * because it becomes part of a file path and of a payload key.
	 *
	 * @since 6.39.0
	 */
	private static function locale(): string {
		$locale = get_user_locale();
		return in_array( $locale, self::readme_locales(), true ) ? $locale : '';
	}

	/**
	 * Locales the installed copy ships a translated readme for, read from
	 * `languages/readme-<locale>.txt`.
	 *
	 * @since 6.39.0
	 * @return list<string>
	 */
	public static function readme_locales(): array {
		$locales = array();
		foreach ( glob( FFC_PLUGIN_DIR . 'languages/readme-*.txt' ) ?: array() as $file ) { // phpcs:ignore Universal.Operators.DisallowShortTernary.Found -- glob() returns false only on an error, which means no translation.
			if ( 1 === preg_match( '/^readme-([a-z]{2,3}(?:_[A-Z]{2})?)\.txt$/', basename( $file ), $m ) ) {
				$locales[] = $m[1];
			}
		}
		return $locales;
	}

	/**
	 * A file of the installed copy, or an empty string.
	 *
	 * @since 6.39.0
	 * @param string $relative Path under the plugin folder.
	 */
	private static function installed_file( string $relative ): string {
		$file = FFC_PLUGIN_DIR . $relative;
		return is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a file this plugin ships, not a remote resource.
	}

	/**
	 * The banner at the top of the "View details" modal, from the installed
	 * copy for the same reason as the icon, in the viewer's language when one
	 * ships (`banner-772x250-pt_BR.png`).
	 *
	 * @since 6.39.0
	 * @return array{low: string, high: string}
	 */
	private static function banners(): array {
		$base   = FFC_PLUGIN_URL . 'assets/img/brand/';
		$locale = self::locale();
		$suffix = '' !== $locale && is_readable( FFC_PLUGIN_DIR . 'assets/img/brand/banner-772x250-' . $locale . '.png' ) ? '-' . $locale : '';
		return array(
			'low'  => $base . 'banner-772x250' . $suffix . '.png',
			'high' => $base . 'banner-1544x500' . $suffix . '.png',
		);
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
				__( 'Update package integrity check (SHA-256) failed. Installation aborted.', 'ffcertificate' )
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
		$obj                 = new \stdClass();
		$obj->id             = 'github.com/' . self::REPO;
		$obj->slug           = self::SLUG;
		$obj->plugin         = self::PLUGIN_FILE;
		$obj->new_version    = $new_version;
		$obj->url            = $release['url'];
		$obj->package        = $release['package'];
		$compat              = self::compat( $release );
		$obj->requires       = $compat['requires'];
		$obj->tested         = $compat['tested'];
		$obj->requires_php   = $compat['requires_php'];
		$obj->upgrade_notice = self::upgrade_notice( $release );
		$obj->icons          = self::icons();
		return $obj;
	}

	/**
	 * The plugin icon WordPress draws on Dashboard → Updates, in the plugin
	 * list and in the "View details" modal; without it, core shows its grey
	 * plug placeholder.
	 *
	 * The URLs point at the INSTALLED copy rather than at GitHub, so the
	 * admin's browser fetches nothing from outside the site. That is safe
	 * because the code building this object is itself the installed copy: an
	 * install old enough to lack the files also lacks this method.
	 *
	 * @return array{'1x': string, '2x': string, svg: string}
	 */
	private static function icons(): array {
		$base = FFC_PLUGIN_URL . 'assets/img/brand/';
		return array(
			'1x'  => $base . 'icon-128.png',
			'2x'  => $base . 'icon-256.png',
			'svg' => $base . 'icon.svg',
		);
	}

	/**
	 * The release summary WordPress shows under the plugin on Dashboard →
	 * Updates, read from `readme.txt`'s `== Upgrade Notice ==` section.
	 *
	 * WHY THIS IS READ AT ALL
	 *
	 * The section existed for three years and reached nobody: `compat()` reads
	 * `readme.txt` through `get_file_data()`, which sees HEADERS and not
	 * sections, and nothing else opened the file. So an operator deciding
	 * whether to apply an update saw a version number and nothing else -- not
	 * even a breaking-change warning, which is the one case where the decision
	 * is not automatic.
	 *
	 * WHY THE SECTION HOLDS EXACTLY ONE ENTRY
	 *
	 * `get_latest_release()` fetches `releases/latest`, so the only version
	 * this updater ever offers is the newest one. An entry for any older
	 * release is therefore text no code path can display -- documentation that
	 * looks like history. Keeping one entry also keeps `readme.txt` from
	 * becoming a second changelog, which this file's own `== Changelog ==`
	 * section records as a drift problem it already solved once by deleting
	 * the mirror. `CHANGELOG.md` stays the historical record; this is the
	 * summary of the version being offered, and it says so.
	 *
	 * `ReadmeUpgradeNoticeTest` enforces the shape: one entry, naming the
	 * version `Stable tag` declares, within the 300-character norm.
	 *
	 * WHOSE NOTICE (#1607)
	 *
	 * The notice belongs to the release being OFFERED, so it comes from that
	 * release's own `readme.txt`, fetched with the release by
	 * `fetch_release_readme()`. The installed copy is only the fallback. It
	 * describes the version already running, which is exactly the text an
	 * operator deciding on the update does not need: reading it first is how
	 * 6.34.0's "requires WordPress 6.8" warning reached nobody before they
	 * updated.
	 *
	 * The parse is deliberately forgiving about WHICH version it finds. It
	 * returns the single entry's body whatever version heads it, because a
	 * mismatch between that heading and the release being offered is the
	 * guard's to fail in CI, not this method's to hide at runtime by
	 * returning nothing.
	 *
	 * @param array<string, string>|null $release Release payload being offered, if any.
	 * @return string Plain text; empty when the section is absent or malformed.
	 */
	private static function upgrade_notice( ?array $release = null ): string {
		if ( null !== $release && '' !== ( $release['upgrade_notice'] ?? '' ) ) {
			return $release['upgrade_notice'];
		}

		$readme = FFC_PLUGIN_DIR . 'readme.txt';

		if ( ! is_readable( $readme ) ) {
			return '';
		}

		$contents = file_get_contents( $readme ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a file this plugin ships, not a remote resource; `WP_Filesystem` would need credentials on an update check.

		return is_string( $contents ) ? self::notice_from( $contents ) : '';
	}

	/**
	 * Extract the single Upgrade Notice entry from a `readme.txt` body.
	 *
	 * Shared by the installed copy and the offered release's readme, so the
	 * two can never be parsed differently.
	 *
	 * @param string $contents Full `readme.txt` text.
	 * @return string Plain text; empty when the section is absent or malformed.
	 */
	private static function notice_from( string $contents ): string {
		if ( ! preg_match( '/^==\s*Upgrade Notice\s*==\s*$(.*?)(?=^==\s|\z)/ms', $contents, $section ) ) {
			return '';
		}

		// Stop at the first blank line, not at the end of the section: the
		// section also carries a trailer pointing at `CHANGELOG.md`, which is
		// prose ABOUT the notice and not part of it. A notice is one
		// paragraph -- at 300 characters it cannot usefully be more -- and
		// `ReadmeUpgradeNoticeTest` fails if this swallows the trailer again,
		// which is how the bug was caught in the first place.
		if ( ! preg_match( '/^=\s*\S+\s*=\s*$\R+(.+?)(?=\R\s*\R|\z)/ms', $section[1], $entry ) ) {
			return '';
		}

		return trim( $entry[1] );
	}

	/**
	 * Read the compatibility fields from the files that own them.
	 *
	 * These used to be hard-coded constants, "to avoid a runtime filesystem
	 * read" — a third copy of metadata that nothing kept in sync, and both WP
	 * values had silently drifted (#1022): `tested` still said 7.0 after #984
	 * raised `readme.txt` to 7.1, so every 7.1 site was told on the update
	 * screen that a release verified against 7.1 was untested; `requires` said
	 * 6.2 against an actual 6.4, offering the update to sites too old to run
	 * it. WordPress reads these fields and never `readme.txt`, because the
	 * plugin updates from GitHub rather than the WordPress.org directory, so
	 * the stale copy was the one that counted.
	 *
	 * Two files rather than one, and that is the floor: both are parsed by
	 * tooling **before** PHP runs — WordPress reads the plugin header to decide
	 * whether the plugin may load at all, and WordPress.org reads `readme.txt`
	 * — so neither can be derived from the other. `Tested up to` in particular
	 * is not a plugin-header field at all; it exists only in `readme.txt`.
	 *
	 * The read costs one 8 KB `get_file_data()` pass per file and runs only on
	 * an update check, a handful of times per request at most — deliberately
	 * not memoised, since a static cache would be process-global mutable state
	 * bought for nothing measurable. Values are trimmed but otherwise passed
	 * through: a malformed header is WordPress's to interpret, not this
	 * method's to guess at.
	 *
	 * WHOSE FILES (#1607)
	 *
	 * #1022 made these derived, but it derived them from the INSTALLED copy.
	 * After an update that copy is the offered release, so the screens looked
	 * right. Before it, which is when an operator decides, every field
	 * described the previous version. 6.34.0 was offered to 6.33.0 sites as
	 * "requires 6.4, tested up to 7.1.1" when it actually required 6.8 and was
	 * tested up to 7.1.3. So each field now comes from the offered release's
	 * own `readme.txt`, which carries all three headers and agrees with the
	 * plugin header by construction (`GitHubUpdaterCompatTest`). The installed
	 * files remain the per-field fallback for a readme that could not be
	 * fetched or did not carry that header.
	 *
	 * @param array<string, string>|null $release Release payload being offered, if any.
	 * @return array{requires: string, tested: string, requires_php: string}
	 */
	private static function compat( ?array $release = null ): array {
		$plugin = get_file_data(
			FFC_PLUGIN_DIR . 'ffcertificate.php',
			array(
				'requires'     => 'Requires at least',
				'requires_php' => 'Requires PHP',
			)
		);

		$readme = get_file_data(
			FFC_PLUGIN_DIR . 'readme.txt',
			array( 'tested' => 'Tested up to' )
		);

		$installed = array(
			'requires'     => trim( $plugin['requires'] ),
			'tested'       => trim( $readme['tested'] ),
			'requires_php' => trim( $plugin['requires_php'] ),
		);

		if ( null === $release ) {
			return $installed;
		}

		foreach ( array_keys( $installed ) as $field ) {
			if ( '' !== ( $release[ $field ] ?? '' ) ) {
				$installed[ $field ] = $release[ $field ];
			}
		}

		return $installed;
	}

	/**
	 * Read the offered release's compatibility headers, Upgrade Notice and
	 * full text from its own `readme.txt`, at the release tag, plus each
	 * translation of it the installed copy ships.
	 *
	 * One extra request per NEW release, and one per translation: they run
	 * only on a full (200) fetch of `releases/latest`, never on a 304 or a
	 * cache hit, and the result is stored in the same transient as the
	 * release. `raw.githubusercontent.com` is not the API, so it does not
	 * spend the unauthenticated rate limit.
	 *
	 * Every failure returns empty fields rather than null. The caller then
	 * falls back to the installed copy field by field: degraded, never broken,
	 * because the update scan must not stop over metadata.
	 *
	 * @param string $repo `owner/repo`.
	 * @param string $tag  Release tag, e.g. `v6.34.0`.
	 * @return array<string, string> The metadata fields, `readme`, and `readme_<locale>` per translation.
	 */
	private static function fetch_release_readme( string $repo, string $tag ): array {
		$body = self::fetch_raw( $repo, $tag, 'readme.txt' );
		$out  = self::readme_metadata( $body ) + array( 'readme' => $body );
		foreach ( self::readme_locales() as $locale ) {
			$out[ 'readme_' . $locale ] = self::fetch_raw( $repo, $tag, 'languages/readme-' . $locale . '.txt' );
		}
		return $out;
	}

	/**
	 * One file of the repository at a tag, or an empty string on any failure.
	 *
	 * @since 6.39.0
	 * @param string $repo `owner/repo`.
	 * @param string $tag  Release tag.
	 * @param string $path Path in the repository.
	 */
	private static function fetch_raw( string $repo, string $tag, string $path ): string {
		$response = wp_remote_get(
			sprintf( 'https://raw.githubusercontent.com/%s/%s/%s', $repo, rawurlencode( $tag ), $path ),
			array(
				'timeout' => 10,
				'headers' => array( 'User-Agent' => 'ffcertificate-updater' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			self::debug_log(
				'Release file fetch failed',
				array(
					'path'  => $path,
					'error' => $response->get_error_message(),
				)
			);
			return '';
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		if ( 200 !== $code || '' === $body ) {
			self::debug_log(
				'Release file fetch non-200 or empty',
				array(
					'path' => $path,
					'code' => $code,
				)
			);
			return '';
		}
		return $body;
	}

	/**
	 * Parse the compatibility headers and the Upgrade Notice out of a
	 * `readme.txt` body.
	 *
	 * Headers are read the way `get_file_data()` reads them: the first 8 KB,
	 * case-insensitive, rest of the line. A value that is not a bare version
	 * number is dropped rather than passed on. Unlike the installed copy, this
	 * text came over the network, and WordPress feeds these fields to
	 * `version_compare()`. The notice is reduced to plain text for the same
	 * reason.
	 *
	 * @param string $contents Full `readme.txt` text.
	 * @return array{requires: string, tested: string, requires_php: string, upgrade_notice: string}
	 */
	private static function readme_metadata( string $contents ): array {
		$head   = substr( $contents, 0, 8192 );
		$fields = array(
			'requires'     => 'Requires at least',
			'tested'       => 'Tested up to',
			'requires_php' => 'Requires PHP',
		);

		$out = array();
		foreach ( $fields as $key => $label ) {
			$value = '';
			if ( 1 === preg_match( '/^[ \t\/*#@]*' . preg_quote( $label, '/' ) . ':(.*)$/mi', $head, $m ) ) {
				$value = trim( $m[1] );
			}
			$out[ $key ] = 1 === preg_match( '/^\d+(\.\d+)*$/', $value ) ? $value : '';
		}

		$out['upgrade_notice'] = wp_strip_all_tags( self::notice_from( $contents ) );

		return $out;
	}

	/**
	 * Is this transient scan an admin-initiated "Check again" (force-check)?
	 *
	 * WordPress's Dashboard → Updates "Check again" loads
	 * `update-core.php?force-check=1`, which re-runs `wp_update_plugins()`. On
	 * that request the caller drops our release cache so the scan re-fetches
	 * GitHub. A read-only GET presence check — WP core sets `force-check` on its
	 * own (nonce-protected) "Check again" link and nothing is mutated here.
	 *
	 * @return bool
	 */
	private static function is_forced_check(): bool {
		return RequestInput::has_get( 'force-check' );
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
			'version'        => (string) ( $raw['version'] ?? '' ),
			// The empty() guard above already proved 'package' exists and is
			// truthy, so a ?? here would be a redundant null-coalesce (PHPStan).
			'package'        => (string) $raw['package'],
			'sha256'         => (string) ( $raw['sha256'] ?? '' ),
			'url'            => (string) ( $raw['url'] ?? '' ),
			'changelog'      => (string) ( $raw['changelog'] ?? '' ),
			'published_at'   => (string) ( $raw['published_at'] ?? '' ),
			// The offered release's own metadata (#1607). Absent from payloads
			// cached before it existed, which then fall back to the installed copy.
			'requires'       => (string) ( $raw['requires'] ?? '' ),
			'tested'         => (string) ( $raw['tested'] ?? '' ),
			'requires_php'   => (string) ( $raw['requires_php'] ?? '' ),
			'upgrade_notice' => (string) ( $raw['upgrade_notice'] ?? '' ),
			// The release's whole readme, for the "View details" tabs.
			'readme'         => (string) ( $raw['readme'] ?? '' ),
		) + self::translated_readmes( $raw );
	}

	/**
	 * The `readme_<locale>` entries of a raw payload, one per shipped
	 * translation, empty when the payload predates them.
	 *
	 * @since 6.39.0
	 * @param array<mixed> $raw Raw payload.
	 * @return array<string, string>
	 */
	private static function translated_readmes( array $raw ): array {
		$out = array();
		foreach ( self::readme_locales() as $locale ) {
			$out[ 'readme_' . $locale ] = (string) ( $raw[ 'readme_' . $locale ] ?? '' );
		}
		return $out;
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

		$payload = array_merge( $payload, self::fetch_release_readme( $repo, (string) $data['tag_name'] ) );

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
