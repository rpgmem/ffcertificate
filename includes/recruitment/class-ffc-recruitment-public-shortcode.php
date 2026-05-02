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
 *   - `draft`       → error message "Edital ainda não publicado.";
 *   - `preliminary` → warning-only render — "Esta lista está em revisão";
 *                     no listing exposed (preview rows never render publicly).
 *   - `definitive` → two-section layout (não chamados / chamados) of the
 *                     `list_type='definitive'` rows; no banner.
 *   - `closed`      → same listing + a "Edital encerrado" banner.
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
	 * Register the shortcode.
	 *
	 * Hooked from {@see RecruitmentLoader::init()} on `init` (priority 10:
	 * canonical hook for `add_shortcode` registration).
	 *
	 * @return void
	 */
	public static function register(): void {
		add_shortcode( self::SHORTCODE_TAG, array( self::class, 'render' ) );
	}

	/**
	 * Shortcode callback. Always returns HTML (never null) — error/empty
	 * states are themselves rendered as user-facing messages.
	 *
	 * @param array<string|int, mixed>|string $atts Raw shortcode attributes.
	 * @return string
	 */
	public static function render( $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'notice'    => '',
				'adjutancy' => '',
			),
			is_array( $atts ) ? $atts : array(),
			self::SHORTCODE_TAG
		);

		$notice_code = trim( (string) $atts['notice'] );
		$slug_filter = trim( (string) $atts['adjutancy'] );

		if ( '' === $notice_code ) {
			return self::msg( __( 'Notice attribute is required.', 'ffcertificate' ), 'error' );
		}

		// Per-IP rate limit BEFORE cache lookup so cache hits don't bypass
		// the throttle (the abuse vector is hammering the URL, regardless
		// of whether each render is cached).
		if ( ! self::check_rate_limit() ) {
			return self::msg( __( 'Too many requests. Please try again in a few seconds.', 'ffcertificate' ), 'warning' );
		}

		$page_top    = max( 1, (int) ( $_GET['page_top'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page_bottom = max( 1, (int) ( $_GET['page_bottom'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$cache_key = self::cache_key( $notice_code, $slug_filter, $page_top, $page_bottom );
		$cached    = get_transient( $cache_key );
		if ( is_string( $cached ) ) {
			return $cached;
		}

		$html = self::render_uncached( $notice_code, $slug_filter, $page_top, $page_bottom );

		$settings = RecruitmentSettings::all();
		$ttl      = (int) $settings['public_cache_seconds'];
		if ( $ttl > 0 ) {
			set_transient( $cache_key, $html, $ttl );
		}

		return $html;
	}

	/**
	 * Build the rendered HTML without consulting the transient cache.
	 *
	 * Split out so tests / direct callers can bypass caching without
	 * messing with transients.
	 *
	 * @param string $notice_code  User-supplied notice code.
	 * @param string $slug_filter  User-supplied adjutancy slug filter (may be empty).
	 * @param int    $page_top     1-indexed page for the "Não chamados" section.
	 * @param int    $page_bottom  1-indexed page for the "Chamados" section.
	 * @return string
	 */
	public static function render_uncached( string $notice_code, string $slug_filter, int $page_top, int $page_bottom ): string {
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

		$settings  = RecruitmentSettings::all();
		$page_size = (int) $settings['public_default_page_size'];

		$adjutancy_filter_html = '' === $slug_filter
			? self::render_adjutancy_filter( (int) $notice->id )
			: '';

		$top_section    = self::render_section(
			__( 'Not yet called', 'ffcertificate' ),
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
			. $adjutancy_filter_html
			. $top_section
			. $bottom_section
			. '</div>';

		return self::wrap_with_banner( $notice, $body );
	}

	// ─────────────────────────────────────────────────────────────────────
	// Section rendering
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * Render one of the two sections (não chamados / chamados).
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
		if ( $columns['status'] ) {
			$html .= '<th>' . esc_html__( 'Status', 'ffcertificate' ) . '</th>';
		}
		if ( $columns['pcd_badge'] ) {
			$html .= '<th>PCD</th>';
		}
		if ( $show_date && $columns['date_to_assume'] ) {
			$html .= '<th>' . esc_html__( 'Date to assume', 'ffcertificate' ) . '</th>';
		}
		$html .= '</tr></thead><tbody>';

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
			$html .= '<td>' . esc_html( (string) $row->score ) . '</td>';
		}
		if ( $columns['status'] ) {
			$html .= '<td>' . esc_html( self::status_label( (string) $row->status ) ) . '</td>';
		}
		if ( $columns['pcd_badge'] ) {
			$is_pcd = RecruitmentPcdHasher::verify( (string) $candidate->pcd_hash, (int) $candidate->id );
			$html  .= '<td>' . ( true === $is_pcd ? '<span class="ffc-recruitment-pcd">PCD</span>' : '' ) . '</td>';
		}
		if ( $show_date && $columns['date_to_assume'] ) {
			$call  = RecruitmentCallRepository::get_active_for_classification( (int) $row->id );
			$html .= '<td>' . esc_html( null === $call ? '' : (string) $call->date_to_assume ) . '</td>';
		}
		$html .= '</tr>';
		return $html;
	}

	/**
	 * Build the adjutancy filter HTML when the notice has 2+ adjutancies
	 * AND the shortcode wasn't called with a fixed `adjutancy=` attr.
	 *
	 * @param int $notice_id Notice ID.
	 * @return string
	 */
	private static function render_adjutancy_filter( int $notice_id ): string {
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

		$html  = '<form class="ffc-recruitment-adjutancy-filter" method="get">';
		$html .= '<label>' . esc_html__( 'Filter by adjutancy:', 'ffcertificate' ) . ' ';
		$html .= '<select name="adjutancy" onchange="this.form.submit()">';
		$html .= '<option value="">' . esc_html__( 'All', 'ffcertificate' ) . '</option>';
		foreach ( $adjutancies as $a ) {
			$html .= '<option value="' . esc_attr( (string) $a->slug ) . '">' . esc_html( (string) $a->name ) . '</option>';
		}
		$html .= '</select></label></form>';

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
		foreach ( array( 'rank', 'name', 'status', 'pcd_badge', 'date_to_assume', 'score', 'cpf_masked', 'rf_masked', 'email_masked' ) as $key ) {
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
		$banner = '';
		if ( 'preliminary' === $notice->status ) {
			$banner = '<div class="ffc-recruitment-banner ffc-recruitment-banner-preliminary" role="status">'
				. esc_html__( 'Preliminary list — classifications and participants may still change before this notice is finalized.', 'ffcertificate' )
				. '</div>';
		} elseif ( 'definitive' === $notice->status ) {
			$banner = '<div class="ffc-recruitment-banner ffc-recruitment-banner-final" role="status">'
				. esc_html__( 'Final classification.', 'ffcertificate' )
				. '</div>';
		} elseif ( 'closed' === $notice->status ) {
			$banner = '<div class="ffc-recruitment-banner ffc-recruitment-banner-closed">'
				. esc_html__( 'Notice closed.', 'ffcertificate' )
				. '</div>';
		}

		$head = '<header class="ffc-recruitment-header">'
			. '<h2>' . esc_html( (string) $notice->code . ' — ' . (string) $notice->name ) . '</h2>'
			. $banner
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
	 * @param int    $page_top      Página da seção "Não chamados".
	 * @param int    $page_bottom   Página da seção "Chamados".
	 * @return string Transient key (under WP's 172-char limit).
	 */
	private static function cache_key( string $notice_code, string $slug_filter, int $page_top, int $page_bottom ): string {
		return self::CACHE_PREFIX . md5(
			strtoupper( $notice_code ) . '|' . $slug_filter . '|' . $page_top . '|' . $page_bottom
		);
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
