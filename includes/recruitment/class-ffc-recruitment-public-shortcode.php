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
 * @package FreeFormCertificate\Recruitment
 * @since   6.0.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Recruitment;

use FreeFormCertificate\Core\DocumentFormatter;
use FreeFormCertificate\Core\Encryption;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public-facing shortcode renderer.
 *
 * @phpstan-type ColumnsConfig array{
 *   rank: bool, name: bool, status: bool, pcd_badge: bool,
 *   date_to_assume: bool, score: bool,
 *   cpf_masked: bool, rf_masked: bool, email_masked: bool,
 * }
 *
 * @phpstan-import-type CandidateRow      from RecruitmentCandidateRepository
 * @phpstan-import-type ClassificationRow from RecruitmentClassificationRepository
 * @phpstan-import-type NoticeRow         from RecruitmentNoticeRepository
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
			return self::wrap_output( self::msg( __( 'Notice attribute is required.', 'ffcertificate' ), 'error' ) );
		}

		// Per-IP rate limit BEFORE cache lookup so cache hits don't bypass
		// the throttle (the abuse vector is hammering the URL, regardless
		// of whether each render is cached).
		if ( ! self::check_rate_limit() ) {
			return self::wrap_output( self::msg( __( 'Too many requests. Please try again in a few seconds.', 'ffcertificate' ), 'warning' ) );
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
		return '<div class="ffc-recruitment-queue">' . $body . '</div>';
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
		$notice = RecruitmentNoticeRepository::get_by_code( $notice_code );
		if ( null === $notice ) {
			return self::msg( __( 'Notice not found.', 'ffcertificate' ), 'error' );
		}

		// draft → never expose data publicly.
		if ( 'draft' === $notice->status ) {
			return self::msg(
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
			$adjutancy = RecruitmentAdjutancyRepository::get_by_slug( $slug_filter );
			if ( null === $adjutancy ) {
				return self::msg( __( 'Adjutancy not found for this notice.', 'ffcertificate' ), 'error' );
			}
			$attached_ids = RecruitmentNoticeAdjutancyRepository::get_adjutancy_ids_for_notice( (int) $notice->id );
			if ( ! in_array( (int) $adjutancy->id, $attached_ids, true ) ) {
				return self::msg( __( 'Adjutancy not found for this notice.', 'ffcertificate' ), 'error' );
			}
			$adjutancy_id = (int) $adjutancy->id;
		}

		$rows = RecruitmentClassificationRepository::get_for_notice(
			(int) $notice->id,
			$list_type,
			$adjutancy_id
		);

		if ( empty( $rows ) ) {
			return self::wrap_with_banner(
				$notice,
				self::msg( __( 'No candidates classified yet.', 'ffcertificate' ), 'info' )
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
						$candidate = RecruitmentCandidateRepository::get_by_id( (int) $row->candidate_id );
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
			$body = self::render_filters_bar( $notice, $filter_locked, $name_query, $subscription )
				. self::msg( __( 'No matches for the current search.', 'ffcertificate' ), 'info' );
			return self::wrap_with_banner( $notice, $body );
		}

		$columns = self::parse_columns_config( $notice->public_columns_config );

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

		$filters_bar = self::render_filters_bar( $notice, $filter_locked, $name_query, $subscription );

		$top_section    = self::render_section(
			__( 'Waiting called', 'ffcertificate' ),
			$empty_rows,
			$columns,
			false,
			$page_top,
			$page_size,
			'page_top'
		);
		$bottom_section = self::render_section(
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

		return self::wrap_with_banner( $notice, $body );
	}

	// ─────────────────────────────────────────────────────────────────────
	// Section rendering
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * Render one of the two sections (waiting / called).
	 *
	 * @param string             $heading      Section title.
	 * @param array              $rows         Classification rows in this section (list<ClassificationRow>).
	 * @phpstan-param list<ClassificationRow> $rows
	 * @param array<string,bool> $columns      Column visibility map.
	 * @param bool               $show_date    Whether to render the `date_to_assume` column.
	 * @param int                $current_page 1-indexed current page.
	 * @param int                $page_size    Items per page.
	 * @param string             $page_param   Query-string param for navigation links.
	 * @return string
	 */
	private static function render_section( string $heading, array $rows, array $columns, bool $show_date, int $current_page, int $page_size, string $page_param ): string {
		$total = count( $rows );
		if ( 0 === $total ) {
			return '';
		}

		$pages        = max( 1, (int) ceil( $total / max( 1, $page_size ) ) );
		$current_page = min( $current_page, $pages );
		$offset       = ( $current_page - 1 ) * $page_size;
		$page_rows    = array_slice( $rows, $offset, $page_size );

		$html  = '<section class="ffc-recruitment-section">';
		$html .= '<h3>' . esc_html( $heading ) . '</h3>';
		$html .= '<table class="ffc-recruitment-table"><thead><tr>';
		if ( $columns['rank'] ) {
			$html .= '<th>' . esc_html__( 'Rank', 'ffcertificate' ) . '</th>';
		}
		if ( $columns['name'] ) {
			$html .= '<th>' . esc_html__( 'Name', 'ffcertificate' ) . '</th>';
		}
		if ( $columns['adjutancy'] ) {
			$html .= '<th>' . esc_html__( 'Adjutancy', 'ffcertificate' ) . '</th>';
		}
		if ( $columns['cpf_masked'] ) {
			$html .= '<th>CPF</th>';
		}
		if ( $columns['rf_masked'] ) {
			$html .= '<th>RF</th>';
		}
		if ( $columns['email_masked'] ) {
			$html .= '<th>' . esc_html__( 'E-mail', 'ffcertificate' ) . '</th>';
		}
		if ( $columns['score'] ) {
			$html .= '<th>' . esc_html__( 'Score', 'ffcertificate' ) . '</th>';
		}
		if ( $columns['time_points'] ) {
			$html .= '<th>' . esc_html__( 'Time points', 'ffcertificate' ) . '</th>';
		}
		if ( $columns['hab_emebs'] ) {
			$html .= '<th>' . esc_html__( 'HAB. EMEBs', 'ffcertificate' ) . '</th>';
		}
		if ( $columns['status'] ) {
			$html .= '<th>' . esc_html__( 'Status', 'ffcertificate' ) . '</th>';
		}
		if ( $columns['pcd_badge'] ) {
			$html .= '<th>' . esc_html__( 'Subscription', 'ffcertificate' ) . '</th>';
		}
		if ( $show_date && $columns['date_to_assume'] ) {
			$html .= '<th>' . esc_html__( 'Date to assume', 'ffcertificate' ) . '</th>';
		}
		if ( $show_date && $columns['time_to_assume'] ) {
			$html .= '<th>' . esc_html__( 'Time', 'ffcertificate' ) . '</th>';
		}
		$html .= '</tr></thead><tbody>';

		// Warm the candidate object cache with a single batch SELECT
		// before the per-row loop. render_row() still calls get_by_id()
		// for the cells that need a name / decrypted field, but those
		// calls now hit the in-memory cache instead of issuing N
		// individual SELECTs. Drops cold-cache render time on large
		// notices from O(N) round-trips to O(1).
		$candidate_ids = array_map( static fn( $r ) => (int) $r->candidate_id, $page_rows );
		RecruitmentCandidateRepository::get_by_ids( $candidate_ids );

		foreach ( $page_rows as $row ) {
			$candidate = RecruitmentCandidateRepository::get_by_id( (int) $row->candidate_id );
			if ( null === $candidate ) {
				continue;
			}
			$html .= self::render_row( $row, $candidate, $columns, $show_date );
		}

		$html .= '</tbody></table>';
		$html .= self::render_pagination( $current_page, $pages, $page_param );
		$html .= '</section>';

		return $html;
	}

	/**
	 * Render a single classification row.
	 *
	 * @param object             $row       Classification row (ClassificationRow shape).
	 * @param object             $candidate Candidate row (CandidateRow shape).
	 * @phpstan-param ClassificationRow $row
	 * @phpstan-param CandidateRow      $candidate
	 * @param array<string,bool> $columns   Column visibility map.
	 * @param bool               $show_date Whether to include the date_to_assume cell.
	 * @return string
	 */
	private static function render_row( object $row, object $candidate, array $columns, bool $show_date ): string {
		$html = '<tr>';
		if ( $columns['rank'] ) {
			$html .= '<td>' . esc_html( (string) $row->rank ) . '</td>';
		}
		if ( $columns['name'] ) {
			$html .= '<td>' . esc_html( (string) $candidate->name ) . '</td>';
		}
		if ( $columns['adjutancy'] ) {
			$adjutancy = RecruitmentAdjutancyRepository::get_by_id( (int) $row->adjutancy_id );
			$html     .= '<td>' . self::render_adjutancy_badge( $adjutancy ) . '</td>';
		}
		if ( $columns['cpf_masked'] ) {
			$plain = self::decrypt_field( $candidate->cpf_encrypted );
			$html .= '<td>' . esc_html( null === $plain ? '' : DocumentFormatter::mask_cpf( $plain ) ) . '</td>';
		}
		if ( $columns['rf_masked'] ) {
			$plain = self::decrypt_field( $candidate->rf_encrypted );
			$html .= '<td>' . esc_html( null === $plain ? '' : DocumentFormatter::mask_rf( $plain ) ) . '</td>';
		}
		if ( $columns['email_masked'] ) {
			$plain = self::decrypt_field( $candidate->email_encrypted );
			$html .= '<td>' . esc_html( null === $plain ? '' : DocumentFormatter::mask_email( $plain ) ) . '</td>';
		}
		if ( $columns['score'] ) {
			// §3.5 score is DECIMAL(10,4); operator-facing surface
			// truncates to 2 decimals for readability (53.00 vs 53.0000).
			$html .= '<td>' . esc_html( number_format( (float) $row->score, 2, '.', '' ) ) . '</td>';
		}
		if ( $columns['time_points'] ) {
			$tp    = isset( $row->time_points ) ? (float) $row->time_points : 0.0;
			$html .= '<td>' . esc_html( number_format( $tp, 2, '.', '' ) ) . '</td>';
		}
		if ( $columns['hab_emebs'] ) {
			$on    = isset( $row->hab_emebs ) && 1 === (int) $row->hab_emebs;
			$html .= '<td>' . ( $on ? esc_html__( 'Yes', 'ffcertificate' ) : '—' ) . '</td>';
		}
		if ( $columns['status'] ) {
			// On the preview list the §5.2 `status` column is always
			// 'empty', so it carries no signal — render the configurable
			// preview_status badge instead. The reason text (when set
			// and the notice opted into surfacing it via
			// public_columns_config.preview_reason) trails the badge in
			// the same cell so the existing column count stays stable.
			$is_preview = isset( $row->list_type ) && 'preview' === (string) $row->list_type;
			if ( $is_preview ) {
				$preview_status_value = isset( $row->preview_status ) ? (string) $row->preview_status : 'empty';
				// Resolve the reason label only when the per-notice
				// public-visibility toggle is on and the row carries a
				// reference; pass it down to the badge so it renders as
				// a hover tooltip rather than inline text below.
				$reason_label = '';
				if ( ! empty( $columns['preview_reason'] ) && isset( $row->preview_reason_id ) && null !== $row->preview_reason_id ) {
					$reason_id = (int) $row->preview_reason_id;
					if ( $reason_id > 0 ) {
						$reason = RecruitmentReasonRepository::get_by_id( $reason_id );
						if ( null !== $reason ) {
							$reason_label = (string) $reason->label;
						}
					}
				}
				$html .= '<td>' . self::render_preview_status_badge( $preview_status_value, $reason_label ) . '</td>';
			} else {
				$html .= '<td>' . self::render_status_badge( (string) $row->status ) . '</td>';
			}
		}
		if ( $columns['pcd_badge'] ) {
			// `verify()` returns null when the hash doesn't decode against
			// either domain — treat that as non-PCD on the public surface
			// rather than hiding the badge entirely.
			$is_pcd = true === RecruitmentPcdHasher::verify( (string) $candidate->pcd_hash, (int) $candidate->id );
			$html  .= '<td>' . self::render_subscription_badge( $is_pcd ) . '</td>';
		}
		if ( $show_date && ( $columns['date_to_assume'] || $columns['time_to_assume'] ) ) {
			$call = RecruitmentCallRepository::get_active_for_classification( (int) $row->id );
			if ( $columns['date_to_assume'] ) {
				$date  = null === $call ? '' : self::format_date_br( (string) $call->date_to_assume );
				$html .= '<td>' . esc_html( $date ) . '</td>';
			}
			if ( $columns['time_to_assume'] ) {
				$time  = null === $call ? '' : self::format_time_hm( (string) $call->time_to_assume );
				$html .= '<td>' . esc_html( $time ) . '</td>';
			}
		} else {
			if ( $show_date && $columns['date_to_assume'] ) {
				$html .= '<td></td>';
			}
			if ( $show_date && $columns['time_to_assume'] ) {
				$html .= '<td></td>';
			}
		}
		$html .= '</tr>';
		return $html;
	}

	/**
	 * Render the search/filter bar that sits above the listings.
	 *
	 * Combines the adjutancy dropdown (already a no-op when fewer than 2
	 * adjutancies are attached or when the shortcode pinned the adjutancy
	 * via attribute) with the name search input. Both controls share a
	 * single <form> so a name search keeps the active adjutancy filter
	 * and vice-versa — submitting one preserves the other in the URL.
	 *
	 * @param object $notice        Notice row (NoticeRow shape).
	 * @phpstan-param NoticeRow $notice
	 * @param bool   $filter_locked Whether the shortcode pinned the adjutancy.
	 * @param string $name_query    Current `?q=` value (echoed back into the input).
	 * @param string $subscription  Current `?subscription=` value (echoed back).
	 * @return string
	 */
	private static function render_filters_bar( object $notice, bool $filter_locked, string $name_query, string $subscription = '' ): string {
		$adjutancy_html    = $filter_locked ? '' : self::render_adjutancy_filter_inputs( (int) $notice->id );
		$search_html       = self::render_name_search_input( $name_query );
		$subscription_html = self::render_subscription_filter_inputs( $subscription );

		if ( '' === $adjutancy_html && '' === $search_html && '' === $subscription_html ) {
			return '';
		}

		// Re-emit every other GET param so submitting any control
		// doesn't strip notice/page state.
		$preserved = '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only re-emission of caller's params.
		foreach ( (array) $_GET as $key => $value ) {
			$key_str = (string) $key;
			if ( in_array( $key_str, array( 'adjutancy', 'q', 'subscription' ), true ) ) {
				continue;
			}
			$preserved .= '<input type="hidden" name="' . esc_attr( $key_str ) . '" value="' . esc_attr( wp_unslash( (string) $value ) ) . '">';
		}

		return '<form class="ffc-recruitment-filters" method="get">'
			. $preserved
			. $adjutancy_html
			. $subscription_html
			. $search_html
			. '</form>';
	}

	/**
	 * Render the subscription-type <select> for the public filter bar.
	 * Three options: All / PCD / GERAL. Auto-submits on change so the
	 * UX matches the adjutancy dropdown.
	 *
	 * @param string $subscription Current `?subscription=` value.
	 * @return string
	 */
	private static function render_subscription_filter_inputs( string $subscription ): string {
		$html  = '<label class="ffc-recruitment-subscription-filter">';
		$html .= esc_html__( 'Subscription:', 'ffcertificate' ) . ' ';
		$html .= '<select name="subscription" onchange="this.form.submit()">';
		$html .= '<option value=""' . selected( '', $subscription, false ) . '>' . esc_html__( 'All', 'ffcertificate' ) . '</option>';
		$html .= '<option value="pcd"' . selected( 'pcd', $subscription, false ) . '>' . esc_html__( 'PCD', 'ffcertificate' ) . '</option>';
		$html .= '<option value="geral"' . selected( 'geral', $subscription, false ) . '>' . esc_html__( 'GERAL', 'ffcertificate' ) . '</option>';
		$html .= '</select></label>';
		return $html;
	}

	/**
	 * Render the name-search input. Always rendered (even when no
	 * candidates are visible) so visitors can clear or change the
	 * search from the same control. Submission relies on the wrapping
	 * <form> from {@see self::render_filters_bar()}.
	 *
	 * @param string $name_query Current `?q=` value.
	 * @return string
	 */
	private static function render_name_search_input( string $name_query ): string {
		$html  = '<label class="ffc-recruitment-name-search">';
		$html .= esc_html__( 'Search by name:', 'ffcertificate' ) . ' ';
		$html .= '<input type="search" name="q" value="' . esc_attr( $name_query ) . '" placeholder="' . esc_attr__( 'name…', 'ffcertificate' ) . '">';
		$html .= ' <button type="submit">' . esc_html__( 'Search', 'ffcertificate' ) . '</button>';
		$html .= '</label>';
		return $html;
	}

	/**
	 * Build only the adjutancy <label>+<select> portion of the filter
	 * bar (no wrapping <form> — the caller in
	 * {@see self::render_filters_bar()} owns the form). Empty string
	 * when fewer than 2 adjutancies are attached.
	 *
	 * @param int $notice_id Notice ID.
	 * @return string
	 */
	private static function render_adjutancy_filter_inputs( int $notice_id ): string {
		$ids = RecruitmentNoticeAdjutancyRepository::get_adjutancy_ids_for_notice( $notice_id );
		if ( count( $ids ) < 2 ) {
			return '';
		}

		$adjutancies = array();
		foreach ( $ids as $id ) {
			$a = RecruitmentAdjutancyRepository::get_by_id( $id );
			if ( null !== $a ) {
				$adjutancies[] = $a;
			}
		}
		usort(
			$adjutancies,
			static fn( $a, $b ) => strcasecmp( (string) $a->name, (string) $b->name )
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display state.
		$selected = isset( $_GET['adjutancy'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['adjutancy'] ) ) : '';

		$html  = '<label class="ffc-recruitment-adjutancy-filter">';
		$html .= esc_html__( 'Filter by adjutancy:', 'ffcertificate' ) . ' ';
		$html .= '<select name="adjutancy" onchange="this.form.submit()">';
		$html .= '<option value="">' . esc_html__( 'All', 'ffcertificate' ) . '</option>';
		foreach ( $adjutancies as $a ) {
			$is_selected = (string) $a->slug === $selected ? ' selected' : '';
			$html       .= '<option value="' . esc_attr( (string) $a->slug ) . '"' . $is_selected . '>' . esc_html( (string) $a->name ) . '</option>';
		}
		$html .= '</select></label>';

		return $html;
	}

	/**
	 * Render pagination links for one section.
	 *
	 * @param int    $current_page 1-indexed current page.
	 * @param int    $total_pages  Total page count.
	 * @param string $page_param   Query-string parameter name.
	 * @return string
	 */
	private static function render_pagination( int $current_page, int $total_pages, string $page_param ): string {
		if ( $total_pages <= 1 ) {
			return '';
		}

		$base = remove_query_arg( $page_param );
		$html = '<nav class="ffc-recruitment-pagination">';
		for ( $p = 1; $p <= $total_pages; $p++ ) {
			$url = add_query_arg( $page_param, $p, $base );
			if ( $p === $current_page ) {
				$html .= '<span class="current">' . esc_html( (string) $p ) . '</span>';
			} else {
				$html .= '<a href="' . esc_url( $url ) . '">' . esc_html( (string) $p ) . '</a>';
			}
		}
		$html .= '</nav>';
		return $html;
	}

	// ─────────────────────────────────────────────────────────────────────
	// Helpers
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * Decode the per-notice public_columns_config JSON into a normalized map.
	 *
	 * `rank` and `name` are FORCED on regardless of stored config (they are
	 * mandatory per §3.2 / §8.2). Missing keys fall back to the default
	 * shape declared on the notice repository.
	 *
	 * @param string $json Stored JSON.
	 * @return array<string, bool>
	 */
	private static function parse_columns_config( string $json ): array {
		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			$decoded = array();
		}
		/**
		 * Default column visibility map decoded from the schema's JSON.
		 *
		 * @var array<string,bool> $default
		 */
		$default = (array) json_decode( RecruitmentNoticeRepository::DEFAULT_PUBLIC_COLUMNS_CONFIG, true );

		$merged = array_merge( $default, $decoded );

		$out = array();
		foreach ( array( 'rank', 'name', 'adjutancy', 'status', 'pcd_badge', 'date_to_assume', 'time_to_assume', 'score', 'time_points', 'hab_emebs', 'cpf_masked', 'rf_masked', 'email_masked', 'preview_reason' ) as $key ) {
			$out[ $key ] = ! empty( $merged[ $key ] );
		}

		// Mandatory columns (validation also enforced server-side on PATCH).
		$out['rank'] = true;
		$out['name'] = true;

		return $out;
	}

	/**
	 * Wrap the listing body with the notice-status banner.
	 *
	 * @param object $notice Notice row (NoticeRow shape).
	 * @phpstan-param NoticeRow $notice
	 * @param string $body   Already-rendered body HTML.
	 * @return string
	 */
	private static function wrap_with_banner( object $notice, string $body ): string {
		$status_messages = array(
			'preliminary' => __( 'Preliminary list — classifications and participants may still change before this notice is finalized.', 'ffcertificate' ),
			'definitive'  => __( 'Final classification.', 'ffcertificate' ),
			'closed'      => __( 'Notice closed.', 'ffcertificate' ),
		);
		$banner          = '';
		if ( isset( $status_messages[ $notice->status ] ) ) {
			$settings = RecruitmentSettings::all();
			$colors   = array(
				'preliminary' => (string) $settings['notice_status_color_preliminary'],
				'definitive'  => (string) $settings['notice_status_color_definitive'],
				'closed'      => (string) $settings['notice_status_color_closed'],
			);
			$bg       = $colors[ $notice->status ];
			$banner   = sprintf(
				'<div class="ffc-recruitment-banner ffc-recruitment-banner-%1$s" role="status" style="background:%2$s;color:#333;">%3$s</div>',
				esc_attr( $notice->status ),
				esc_attr( $bg ),
				esc_html( $status_messages[ $notice->status ] )
			);
		}

		// Banner sits above the notice code/name and both lines render at the
		// same font size + center alignment. The status callout is the more
		// load-bearing of the two — it answers "is this list final?" — so
		// elevating it above the edital identifier keeps the most relevant
		// signal at the top of the page on mobile/narrow viewports.
		$head = '<header class="ffc-recruitment-header">'
			. $banner
			. '<p class="ffc-recruitment-notice-title">' . esc_html( (string) $notice->code . ' — ' . (string) $notice->name ) . '</p>'
			. '</header>';

		return $head . $body;
	}

	/**
	 * Decrypt a `*_encrypted` column or return null.
	 *
	 * @param mixed $value Stored ciphertext.
	 * @return string|null
	 */
	private static function decrypt_field( $value ): ?string {
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}
		$plain = Encryption::decrypt( $value );
		return null === $plain ? null : $plain;
	}

	/**
	 * Translate an internal status code into a public-facing label.
	 *
	 * `accepted` is intentionally rendered as `Convocado` per §5.2 (the
	 * accepted state is admin-only — the public view treats it the same
	 * as called).
	 *
	 * @param string $status Raw status value.
	 * @return string
	 */
	private static function status_label( string $status ): string {
		$map = array(
			'empty'     => __( 'Waiting', 'ffcertificate' ),
			'called'    => __( 'Called', 'ffcertificate' ),
			'accepted'  => __( 'Called', 'ffcertificate' ),
			'not_shown' => __( 'Did not show up', 'ffcertificate' ),
			'hired'     => __( 'Hired', 'ffcertificate' ),
		);
		return $map[ $status ] ?? $status;
	}

	/**
	 * Render an adjutancy as a colored "tag" badge. The color comes from
	 * the per-adjutancy `color` column (configured in the Recruitment ›
	 * Adjutancies admin tab); rows missing the column fall back to the
	 * neutral default. Mirrors {@see self::render_status_badge()} so the
	 * two badge types share visual treatment.
	 *
	 * @param object|null $adjutancy Adjutancy row (AdjutancyRow) or null.
	 * @phpstan-param (\stdClass&object{name?: mixed, color?: mixed})|null $adjutancy
	 * @return string Already-escaped HTML; empty string when adjutancy is null.
	 */
	private static function render_adjutancy_badge( ?object $adjutancy ): string {
		if ( null === $adjutancy ) {
			return '';
		}
		$color_raw = $adjutancy->color ?? '';
		$color     = is_string( $color_raw ) && '' !== $color_raw
			? $color_raw
			: RecruitmentAdjutancyRepository::DEFAULT_COLOR;
		$name      = $adjutancy->name ?? '';
		return RecruitmentBadgeHtml::render(
			'ffc-recruitment-adjutancy-badge',
			'',
			$color,
			is_string( $name ) ? $name : ''
		);
	}

	/**
	 * Render the subscription-type badge ("PCD" or "GERAL") with the
	 * configured `subscription_color_*` background. The candidate's
	 * pcd_hash is verifiable boolean (PCD or non-PCD per §3.4); GERAL
	 * is the only non-PCD value so a binary badge covers the whole
	 * matrix without needing an enum.
	 *
	 * @param bool $is_pcd Whether the candidate is PCD.
	 * @return string Already-escaped HTML.
	 */
	private static function render_subscription_badge( bool $is_pcd ): string {
		$settings = RecruitmentSettings::all();
		$bg       = $is_pcd
			? (string) $settings['subscription_color_pcd']
			: (string) $settings['subscription_color_geral'];
		$label    = $is_pcd ? __( 'PCD', 'ffcertificate' ) : __( 'GERAL', 'ffcertificate' );
		return RecruitmentBadgeHtml::render(
			'ffc-recruitment-subscription-badge',
			'ffc-recruitment-subscription-' . ( $is_pcd ? 'pcd' : 'geral' ),
			$bg,
			$label
		);
	}

	/**
	 * Render the preview-list status as a colored "tag" badge. Visual-only;
	 * mirrors {@see self::render_status_badge()} but reads colors from
	 * the `preview_color_*` Settings sub-keys and labels from
	 * {@see self::preview_status_label()}.
	 *
	 * When `$reason_label` is non-empty, the badge gets a `title=""`
	 * hover tooltip carrying the operator-defined reason text — opted
	 * in per-notice via `public_columns_config.preview_reason`. The
	 * cursor changes to `help` so visitors get a hint that hover
	 * reveals more context.
	 *
	 * @param string $status       Preview status enum value.
	 * @param string $reason_label Optional reason label rendered as the badge's hover tooltip.
	 * @return string Already-escaped HTML.
	 */
	private static function render_preview_status_badge( string $status, string $reason_label = '' ): string {
		$settings = RecruitmentSettings::all();
		$colors   = array(
			'empty'          => (string) $settings['preview_color_empty'],
			'denied'         => (string) $settings['preview_color_denied'],
			'granted'        => (string) $settings['preview_color_granted'],
			'appeal_denied'  => (string) $settings['preview_color_appeal_denied'],
			'appeal_granted' => (string) $settings['preview_color_appeal_granted'],
		);
		return RecruitmentBadgeHtml::render(
			'ffc-recruitment-preview-status-badge',
			'ffc-recruitment-preview-status-' . $status,
			$colors[ $status ] ?? '#e9ecef',
			self::preview_status_label( $status ),
			$reason_label
		);
	}

	/**
	 * Localized public-facing label for a preview-status enum value.
	 *
	 * @param string $status Enum value.
	 * @return string
	 */
	private static function preview_status_label( string $status ): string {
		$map = array(
			'empty'          => __( 'Empty', 'ffcertificate' ),
			'denied'         => __( 'Denied', 'ffcertificate' ),
			'granted'        => __( 'Granted', 'ffcertificate' ),
			'appeal_denied'  => __( 'Appeal denied', 'ffcertificate' ),
			'appeal_granted' => __( 'Appeal granted', 'ffcertificate' ),
		);
		return $map[ $status ] ?? $status;
	}

	/**
	 * Render the status as a colored "tag" badge. Colors come from the
	 * recruitment Settings page (admins can override per-deployment); the
	 * defaults are the soft palette the user requested:
	 *
	 *   Empty → soft yellow.
	 *   Called / accepted → soft purple.
	 *   Not_shown → soft red.
	 *   Hired → soft green.
	 *
	 * Inline `style` is used (not a CSS variable) because each notice
	 * could in theory render under a host theme without the recruitment
	 * public CSS — keeping the color in the markup guarantees the badge
	 * renders correctly even there.
	 *
	 * @param string $status Classification status enum value.
	 * @return string Already-escaped HTML.
	 */
	private static function render_status_badge( string $status ): string {
		$settings = RecruitmentSettings::all();
		$colors   = array(
			'empty'     => (string) $settings['status_color_empty'],
			'called'    => (string) $settings['status_color_called'],
			'accepted'  => (string) $settings['status_color_called'],
			'hired'     => (string) $settings['status_color_hired'],
			'not_shown' => (string) $settings['status_color_not_shown'],
		);
		return RecruitmentBadgeHtml::render(
			'ffc-recruitment-status-badge',
			'ffc-recruitment-status-' . $status,
			$colors[ $status ] ?? '#e9ecef',
			self::status_label( $status )
		);
	}

	/**
	 * Format a `Y-m-d` date string as `d-m-Y` per the user request.
	 *
	 * Falls back to the original input when parsing fails (defensive —
	 * the database stores dates in ISO already, so the strtotime path
	 * should always succeed for non-empty input).
	 *
	 * @param string $iso ISO date.
	 * @return string
	 */
	private static function format_date_br( string $iso ): string {
		if ( '' === $iso ) {
			return '';
		}
		$ts = strtotime( $iso );
		return false === $ts ? $iso : gmdate( 'd-m-Y', $ts );
	}

	/**
	 * Format a `H:i:s` time string as `H:i` per the user request.
	 *
	 * @param string $time DB time.
	 * @return string
	 */
	private static function format_time_hm( string $time ): string {
		if ( '' === $time ) {
			return '';
		}
		// Strip seconds if present.
		return substr( $time, 0, 5 );
	}

	/**
	 * Render a styled message block (error/warning/info).
	 *
	 * @param string $text Already-localized text.
	 * @param string $kind `error`, `warning`, or `info`.
	 * @return string
	 */
	private static function msg( string $text, string $kind ): string {
		return sprintf(
			'<div class="ffc-recruitment-message ffc-recruitment-message-%s">%s</div>',
			esc_attr( $kind ),
			esc_html( $text )
		);
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
