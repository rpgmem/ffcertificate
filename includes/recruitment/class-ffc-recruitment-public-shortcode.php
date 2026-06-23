<?php
/**
 * Recruitment Public Shortcode
 *
 * Renders `[ffc_recruitment_queue notice="EDITAL-2026-01" adjutancy="..."]`
 * server-side, fully public (no login required, per §8 of the
 * implementation plan).
 *
 * Notice-status branching:
 *
 *   - `draft`       → error message "Notice not yet published.";
 *   - `preliminary` → warning-only render — "This list is under review";
 *                     no listing exposed (preview rows never render publicly).
 *   - `definitive` → two-section layout (waiting / called) of the
 *                     `list_type='definitive'` rows; no banner.
 *   - `closed`      → same listing + a "Notice closed" banner.
 *
 * Column visibility is per-notice via `notice.public_columns_config`
 * (JSON). `rank` and `name` are mandatory; `status` / `pcd_badge` /
 * `date_to_assume` default on; `score` / `cpf_masked` / `rf_masked` /
 * `email_masked` are opt-in.
 *
 * Abuse protection (§8.3, §15):
 *
 *   - Server-side transient cache keyed by (notice, adjutancy filter, page),
 *     TTL from `RecruitmentSettings::public_cache_seconds` (default 60s).
 *   - Per-IP rate limit from
 *     `RecruitmentSettings::public_rate_limit_per_minute` (default 30).
 *     Implemented as a transient counter keyed by `IP|YYYY-mm-dd-HH-MM`.
 *
 * Sort (both sections): `(rank ASC, candidate_id ASC)` — same tie-break
 * as the §3 convention.
 *
 * This class is the public-facing facade: it owns shortcode registration,
 * the transient cache, per-IP rate limiting, and the top-level
 * orchestration in {@see self::render_uncached()}. All HTML-fragment
 * rendering (sections, rows, filter bar, badges, formatters, message
 * blocks) is delegated to {@see RecruitmentPublicShortcodeRenderer} —
 * a stateless helper class extracted in S7 of #141 to keep this facade
 * focused on caching/abuse-protection concerns.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.0.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Recruitment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public-facing shortcode renderer.
 *
 * @phpstan-import-type CandidateRow      from RecruitmentCandidateReader
 * @phpstan-import-type ClassificationRow from RecruitmentClassificationRepository
 * @phpstan-import-type NoticeRow         from RecruitmentNoticeReader
 */
final class RecruitmentPublicShortcode {

	/** Tag registered with `add_shortcode`. */
	public const SHORTCODE_TAG = 'ffc_recruitment_queue';

	/** Cache transient key prefix (matches `uninstall.php` cleanup). */
	private const CACHE_PREFIX = 'ffc_recruitment_public_cache_';

	/** Rate-limit transient key prefix. */
	private const RATE_PREFIX = 'ffc_recruitment_public_rate_';

	/**
	 * Versioned cache option. Bumped by {@see self::invalidate_public_cache()}
	 * on every admin write that affects the public listing; included in
	 * the cache_key hash so a single increment atomically retires every
	 * existing transient (the keys simply no longer match anything looked
	 * up after the bump).
	 */
	private const CACHE_VERSION_OPTION = 'ffc_recruitment_public_cache_version';

	/**
	 * Register the shortcode.
	 *
	 * Hooked from {@see RecruitmentLoader::init()} on `init` (priority 10:
	 * canonical hook for `add_shortcode` registration).
	 *
	 * @return void
	 */
	public static function register(): void {
		add_shortcode( self::SHORTCODE_TAG, array( self::class, 'render' ) );
		add_action( self::CACHE_DIRTY_ACTION, array( self::class, 'invalidate_public_cache' ) );
	}

	/**
	 * Shortcode callback. Always returns HTML (never null) — error/empty
	 * states are themselves rendered as user-facing messages.
	 *
	 * @param array<string|int, mixed>|string $atts Raw shortcode attributes.
	 * @return string
	 */
	public static function render( $atts = array() ): string {
		// Always enqueue the CSS — even error short-circuits render
		// styled message blocks and need the scoped rules.
		self::enqueue_public_css();

		$atts = shortcode_atts(
			array(
				'notice'    => '',
				'adjutancy' => '',
			),
			is_array( $atts ) ? $atts : array(),
			self::SHORTCODE_TAG
		);

		$notice_code = trim( (string) $atts['notice'] );
		$attr_filter = trim( (string) $atts['adjutancy'] );
		$slug_filter = $attr_filter;

		// When the shortcode wasn't called with a fixed adjutancy attribute,
		// honor the per-page filter dropdown (`?adjutancy=foo`). Sanitize
		// to a sane slug shape so a hostile URL doesn't bleed into the
		// repository lookup. The filter UI is suppressed only when the
		// shortcode itself pinned an adjutancy via attribute — selecting
		// a value via the dropdown must still leave the dropdown rendered
		// so visitors can switch back to "All" or pick a different one.
		$filter_locked = '' !== $attr_filter;
		if ( '' === $slug_filter ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
			$get_filter  = isset( $_GET['adjutancy'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['adjutancy'] ) ) : '';
			$slug_filter = trim( $get_filter );
		}

		if ( '' === $notice_code ) {
			return self::wrap_output( RecruitmentPublicShortcodeRenderer::msg( __( 'Notice attribute is required.', 'ffcertificate' ), 'error' ) );
		}

		// Per-IP rate limit BEFORE cache lookup so cache hits don't bypass
		// the throttle (the abuse vector is hammering the URL, regardless
		// of whether each render is cached).
		if ( ! self::check_rate_limit() ) {
			return self::wrap_output( RecruitmentPublicShortcodeRenderer::msg( __( 'Too many requests. Please try again in a few seconds.', 'ffcertificate' ), 'warning' ) );
		}

		$page_top    = max( 1, (int) ( $_GET['page_top'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page_bottom = max( 1, (int) ( $_GET['page_bottom'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only name filter.
		$name_query = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['q'] ) ) : '';
		$name_query = trim( $name_query );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only subscription filter.
		$subscription_raw = isset( $_GET['subscription'] ) ? sanitize_key( wp_unslash( (string) $_GET['subscription'] ) ) : '';
		$subscription     = in_array( $subscription_raw, array( 'pcd', 'geral' ), true ) ? $subscription_raw : '';

		$cache_key = self::cache_key( $notice_code, $slug_filter, $page_top, $page_bottom, $filter_locked, $name_query, $subscription );
		$cached    = get_transient( $cache_key );
		if ( is_string( $cached ) ) {
			return $cached;
		}

		$html = self::wrap_output( self::render_uncached( $notice_code, $slug_filter, $page_top, $page_bottom, $filter_locked, $name_query, $subscription ) );

		$settings = RecruitmentSettings::all();
		$ttl      = (int) $settings['public_cache_seconds'];
		if ( $ttl > 0 ) {
			set_transient( $cache_key, $html, $ttl );
		}

		return $html;
	}

	/**
	 * Wrap rendered HTML in the scoped container so the dashboard-style
	 * CSS rules apply without leaking into the host theme's other markup.
	 * Every public-facing render — happy path or error short-circuit —
	 * goes through here.
	 *
	 * @param string $body Inner HTML.
	 * @return string
	 */
	private static function wrap_output( string $body ): string {
		return '<div class="ffc-shortcode ffc-recruitment-queue">' . $body . '</div>';
	}

	/**
	 * Enqueue the public-shortcode CSS. Scoped under
	 * `.ffc-recruitment-queue` so it can't leak.
	 *
	 * @return void
	 */
	private static function enqueue_public_css(): void {
		$path = FFC_PLUGIN_DIR . 'assets/css/ffc-recruitment-public.css';
		$ver  = file_exists( $path ) ? (string) filemtime( $path ) : FFC_VERSION;
		wp_enqueue_style(
			'ffc-recruitment-public',
			FFC_PLUGIN_URL . 'assets/css/ffc-recruitment-public.css',
			array(),
			$ver
		);
	}

	/**
	 * Build the rendered HTML without consulting the transient cache.
	 *
	 * Split out so tests / direct callers can bypass caching without
	 * messing with transients.
	 *
	 * @param string $notice_code   User-supplied notice code.
	 * @param string $slug_filter   User-supplied adjutancy slug filter (may be empty).
	 * @param int    $page_top      1-indexed page for the "Waiting called" section.
	 * @param int    $page_bottom   1-indexed page for the "Called" section.
	 * @param bool   $filter_locked True when the shortcode was called with a fixed
	 *                              `adjutancy=` attribute — the filter UI is then
	 *                              suppressed so callers who pinned the adjutancy
	 *                              cannot be re-routed by URL tampering.
	 * @param string $name_query    Case-insensitive name substring filter (`?q=`);
	 *                              empty means "no name filter".
	 * @param string $subscription  Subscription-type filter (`?subscription=`).
	 *                              Values: '' / 'pcd' / 'geral'. Empty means
	 *                              "no subscription filter".
	 * @return string
	 */
	public static function render_uncached( string $notice_code, string $slug_filter, int $page_top, int $page_bottom, bool $filter_locked = false, string $name_query = '', string $subscription = '' ): string {
		$notice = RecruitmentNoticeReader::get_by_code( $notice_code );
		if ( null === $notice ) {
			return RecruitmentPublicShortcodeRenderer::msg( __( 'Notice not found.', 'ffcertificate' ), 'error' );
		}

		// draft → never expose data publicly.
		if ( 'draft' === $notice->status ) {
			return RecruitmentPublicShortcodeRenderer::msg(
				__( 'This notice is still being prepared. Public data will be available once the preliminary classification is published.', 'ffcertificate' ),
				'info'
			);
		}

		// preliminary, definitive, closed → render the listing. The list_type
		// switches between `preview` (preliminary) and `definitive`
		// (definitive/closed); the banner copy at the top differs accordingly.
		$list_type    = 'preliminary' === $notice->status ? 'preview' : 'definitive';
		$adjutancy_id = null;
		if ( '' !== $slug_filter ) {
			$adjutancy = RecruitmentAdjutancyReader::get_by_slug( $slug_filter );
			if ( null === $adjutancy ) {
				return RecruitmentPublicShortcodeRenderer::msg( __( 'Adjutancy not found for this notice.', 'ffcertificate' ), 'error' );
			}
			$attached_ids = RecruitmentNoticeAdjutancyRepository::get_adjutancy_ids_for_notice( (int) $notice->id );
			if ( ! in_array( (int) $adjutancy->id, $attached_ids, true ) ) {
				return RecruitmentPublicShortcodeRenderer::msg( __( 'Adjutancy not found for this notice.', 'ffcertificate' ), 'error' );
			}
			$adjutancy_id = (int) $adjutancy->id;
		}

		$rows = RecruitmentClassificationRepository::get_for_notice(
			(int) $notice->id,
			$list_type,
			$adjutancy_id
		);

		if ( empty( $rows ) ) {
			return RecruitmentPublicShortcodeRenderer::wrap_with_banner(
				$notice,
				RecruitmentPublicShortcodeRenderer::msg( __( 'No candidates classified yet.', 'ffcertificate' ), 'info' )
			);
		}

		// Apply the public name filter (`?q=`) and the subscription
		// filter (`?subscription=`) before splitting into waiting /
		// called sections so pagination counts reflect only the matching
		// subset. Per-row lookups go through the repository's object
		// cache so repeating the candidate fetch in render_row() later
		// is a cache hit rather than a second SELECT. The two filters
		// share a single pass when either is active.
		$has_q   = '' !== $name_query;
		$has_sub = 'pcd' === $subscription || 'geral' === $subscription;
		if ( $has_q || $has_sub ) {
			$needle = $has_q
				? ( function_exists( 'mb_strtolower' ) ? mb_strtolower( $name_query, 'UTF-8' ) : strtolower( $name_query ) )
				: '';
			$rows   = array_values(
				array_filter(
					$rows,
					static function ( $row ) use ( $needle, $has_q, $has_sub, $subscription ): bool {
						$candidate = RecruitmentCandidateReader::get_by_id( (int) $row->candidate_id );
						if ( null === $candidate ) {
							return false;
						}
						if ( $has_q ) {
							$name = (string) ( $candidate->name ?? '' );
							$hay  = function_exists( 'mb_strtolower' )
								? mb_strtolower( $name, 'UTF-8' )
								: strtolower( $name );
							if ( false === strpos( $hay, $needle ) ) {
								return false;
							}
						}
						if ( $has_sub ) {
							// Defensive normalization: a row whose
							// pcd_hash doesn't decode against either
							// domain falls into GERAL — same as the
							// badge rendering path.
							$is_pcd       = true === RecruitmentPcdHasher::verify( (string) ( $candidate->pcd_hash ?? '' ), (int) $candidate->id );
							$row_sub_type = $is_pcd ? 'pcd' : 'geral';
							if ( $row_sub_type !== $subscription ) {
								return false;
							}
						}
						return true;
					}
				)
			);
		}

		if ( empty( $rows ) ) {
			$body = RecruitmentPublicShortcodeRenderer::render_filters_bar( $notice, $filter_locked, $name_query, $subscription )
				. RecruitmentPublicShortcodeRenderer::msg( __( 'No matches for the current search.', 'ffcertificate' ), 'info' );
			return RecruitmentPublicShortcodeRenderer::wrap_with_banner( $notice, $body );
		}

		$columns = RecruitmentPublicShortcodeRenderer::parse_columns_config( $notice->public_columns_config );

		$empty_rows  = array();
		$called_rows = array();
		foreach ( $rows as $row ) {
			if ( 'empty' === $row->status ) {
				$empty_rows[] = $row;
			} else {
				$called_rows[] = $row;
			}
		}

		// "Called" rows render newest-first (highest rank at the top of
		// page 1) so the most recently-called candidates land on the
		// landing page without forcing a paginate-to-end. The repository
		// returns rows in `(rank ASC, candidate_id ASC)` per §3, so a
		// straight reverse is sufficient — no resort needed.
		$called_rows = array_reverse( $called_rows );

		$settings  = RecruitmentSettings::all();
		$page_size = (int) $settings['public_default_page_size'];

		$filters_bar = RecruitmentPublicShortcodeRenderer::render_filters_bar( $notice, $filter_locked, $name_query, $subscription );

		$top_section    = RecruitmentPublicShortcodeRenderer::render_section(
			__( 'Waiting called', 'ffcertificate' ),
			$empty_rows,
			$columns,
			false,
			$page_top,
			$page_size,
			'page_top'
		);
		$bottom_section = RecruitmentPublicShortcodeRenderer::render_section(
			__( 'Called', 'ffcertificate' ),
			$called_rows,
			$columns,
			true,
			$page_bottom,
			$page_size,
			'page_bottom'
		);

		$body = '<div class="ffc-recruitment-queue">'
			. $filters_bar
			. $top_section
			. $bottom_section
			. '</div>';

		return RecruitmentPublicShortcodeRenderer::wrap_with_banner( $notice, $body );
	}

	/**
	 * Build the cache-key for a given (notice, adjutancy filter, page) tuple.
	 *
	 * @param string $notice_code   Notice code (case-preserved input).
	 * @param string $slug_filter   Adjutancy slug filter ('' when no filter).
	 * @param int    $page_top      1-indexed page for the "Waiting called" section.
	 * @param int    $page_bottom   1-indexed page for the "Called" section.
	 * @param bool   $filter_locked Whether the shortcode pinned the adjutancy
	 *                              via attribute (filter UI is suppressed).
	 * @param string $name_query    Lowercased name-search filter ('' when none).
	 * @param string $subscription  Subscription-type filter ('' / 'pcd' / 'geral').
	 * @return string Transient key (under WP's 172-char limit).
	 */
	private static function cache_key( string $notice_code, string $slug_filter, int $page_top, int $page_bottom, bool $filter_locked = false, string $name_query = '', string $subscription = '' ): string {
		$version = (int) get_option( self::CACHE_VERSION_OPTION, 0 );
		return self::CACHE_PREFIX . md5(
			strtoupper( $notice_code ) . '|' . $slug_filter . '|' . $page_top . '|' . $page_bottom . '|' . ( $filter_locked ? '1' : '0' ) . '|' . strtolower( $name_query ) . '|s:' . $subscription . '|v' . $version
		);
	}

	/**
	 * Action hook fired by repositories whenever data the public listing
	 * renders is mutated (notices, classifications, candidates,
	 * adjutancies, calls, notice↔adjutancy attachments). The shortcode
	 * subscribes via {@see self::register()} so a single hook firing
	 * invalidates every cached render without each repository having
	 * to reach into the shortcode class directly.
	 */
	public const CACHE_DIRTY_ACTION = 'ffc_recruitment_public_cache_dirty';

	/**
	 * Invalidate every cached public-shortcode render in one shot.
	 *
	 * Bumps a monotonic version counter folded into {@see self::cache_key()};
	 * every transient written under the previous version is implicitly
	 * orphaned (lookups under the new version miss and re-render fresh).
	 * The orphaned transients still expire under their own TTL — no
	 * sweep needed.
	 *
	 * Wired to {@see self::CACHE_DIRTY_ACTION} from {@see self::register()}.
	 *
	 * @return void
	 */
	public static function invalidate_public_cache(): void {
		$current = (int) get_option( self::CACHE_VERSION_OPTION, 0 );
		// Wrap around at PHP_INT_MAX is theoretical here — even at one
		// bump per second it would take ~292 billion years to overflow —
		// but the modulo guards against accidental misconfiguration.
		update_option( self::CACHE_VERSION_OPTION, ( $current + 1 ) % PHP_INT_MAX, false );
	}

	/**
	 * Per-IP rate limit. Returns true when the request should be served.
	 *
	 * Uses a per-minute transient counter keyed by the client IP. Falls
	 * open (returns true) when the IP can't be determined or the rate
	 * limit setting is 0 (disabled).
	 *
	 * @return bool
	 */
	private static function check_rate_limit(): bool {
		$settings = RecruitmentSettings::all();
		$limit    = (int) $settings['public_rate_limit_per_minute'];
		if ( $limit <= 0 ) {
			return true;
		}

		$ip = self::client_ip();
		if ( '' === $ip ) {
			return true;
		}

		$bucket = gmdate( 'Y-m-d-H-i' );
		$key    = self::RATE_PREFIX . md5( $ip . '|' . $bucket );

		$count = get_transient( $key );
		$count = is_int( $count ) ? $count : 0;

		if ( $count >= $limit ) {
			return false;
		}

		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * Best-effort client-IP detection. Returns '' when we can't identify
	 * the caller (rate-limit then falls open per `check_rate_limit`).
	 *
	 * @return string
	 */
	private static function client_ip(): string {
		foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ) as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) && is_string( $_SERVER[ $key ] ) ) {
				$raw       = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
				$candidate = trim( explode( ',', $raw )[0] );
				if ( '' !== $candidate ) {
					return $candidate;
				}
			}
		}
		return '';
	}
}
