<?php
/**
 * Recruitment Public Shortcode Renderer
 *
 * Stateless helper class extracted from {@see RecruitmentPublicShortcode}
 * to keep the shortcode facade focused on registration, caching, and
 * rate-limiting. Hosts every HTML-fragment helper used by the public
 * listing (sections, rows, filter bar, badges, formatters, message
 * blocks). All methods are pure / static — moving them out is a
 * code-organization split with no behavioral change.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.0.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Recruitment;

use FreeFormCertificate\Core\BadgeHtml;
use FreeFormCertificate\Core\DocumentFormatter;
use FreeFormCertificate\Core\Encryption;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * HTML helpers for the public recruitment shortcode.
 *
 * @phpstan-type ColumnsConfig array{
 *   rank: bool, name: bool, status: bool, pcd_badge: bool,
 *   date_to_assume: bool, score: bool,
 *   cpf_masked: bool, rf_masked: bool, email_masked: bool,
 * }
 *
 * @phpstan-import-type CandidateRow      from RecruitmentCandidateReader
 * @phpstan-import-type ClassificationRow from RecruitmentClassificationRepository
 * @phpstan-import-type NoticeRow         from RecruitmentNoticeReader
 */
final class RecruitmentPublicShortcodeRenderer {

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
	public static function render_section( string $heading, array $rows, array $columns, bool $show_date, int $current_page, int $page_size, string $page_param ): string {
		$total = count( $rows );
		if ( 0 === $total ) {
			return '';
		}

		$pages        = max( 1, (int) ceil( $total / max( 1, $page_size ) ) );
		$current_page = min( $current_page, $pages );
		$offset       = ( $current_page - 1 ) * $page_size;
		$page_rows    = array_slice( $rows, $offset, $page_size );

		// Heading + total-count badge on the same line. `$total` is the
		// post-filter count, so when the operator types into the search
		// box the badge reflects the narrowed list instead of the global
		// total — which is what they actually need to see.
		$count_label = sprintf(
			/* translators: %s: number of candidates in the section (post-filter) */
			_n( '%s candidate', '%s candidates', $total, 'ffcertificate' ),
			number_format_i18n( $total )
		);
		$html  = '<section class="ffc-recruitment-section">';
		$html .= '<h3 class="ffc-recruitment-section-heading">'
			. '<span class="ffc-recruitment-section-title">' . esc_html( $heading ) . '</span>'
			. '<span class="ffc-recruitment-section-count">' . esc_html( $count_label ) . '</span>'
			. '</h3>';
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
			$html .= '<th>' . esc_html__( 'CPF', 'ffcertificate' ) . '</th>';
		}
		if ( $columns['rf_masked'] ) {
			$html .= '<th>' . esc_html__( 'RF', 'ffcertificate' ) . '</th>';
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
		RecruitmentCandidateReader::get_by_ids( $candidate_ids );

		foreach ( $page_rows as $row ) {
			$candidate = RecruitmentCandidateReader::get_by_id( (int) $row->candidate_id );
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
			$adjutancy = RecruitmentAdjutancyReader::get_by_id( (int) $row->adjutancy_id );
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
						$reason = RecruitmentReasonReader::get_by_id( $reason_id );
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
			$call = RecruitmentCallReader::get_active_for_classification( (int) $row->id );
			if ( $columns['date_to_assume'] ) {
				$date  = null === $call ? '' : self::format_date_br( (string) $call->date_to_assume );
				$html .= '<td>' . esc_html( $date ) . '</td>';
			}
			if ( $columns['time_to_assume'] ) {
				$time  = null === $call ? '' : self::format_time_hm( (string) $call->time_to_assume );
				$html .= '<td>' . esc_html( $time ) . '</td>';
			}
		}
		// 6.6.5 — removed the `else { if ( $show_date && $cols['…'] ) … }`
		// fallback: those inner checks are dead code by construction
		// (the outer condition negates as "$show_date false OR both
		// columns false", which forces both inner $show_date && col
		// checks to be false). PHPStan 2.1.55 surfaced this.
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
	public static function render_filters_bar( object $notice, bool $filter_locked, string $name_query, string $subscription = '' ): string {
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
		// Inline magnifying-glass glyph — keeps the asset count flat and
		// avoids a font dependency. `aria-hidden` so screen readers fall
		// through to the button label.
		$icon = '<svg class="ffc-recruitment-search-btn-icon" viewBox="0 0 16 16" width="14" height="14" aria-hidden="true" focusable="false">'
			. '<path fill="currentColor" d="M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001q.044.06.098.115l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85a1 1 0 0 0-.115-.1zM12 6.5a5.5 5.5 0 1 1-11 0 5.5 5.5 0 0 1 11 0z"/>'
			. '</svg>';

		$html  = '<label class="ffc-recruitment-name-search">';
		$html .= esc_html__( 'Search by name:', 'ffcertificate' ) . ' ';
		$html .= '<input type="search" name="q" value="' . esc_attr( $name_query ) . '" placeholder="' . esc_attr__( 'name…', 'ffcertificate' ) . '">';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- icon is a constant SVG string with no dynamic content.
		$html .= ' <button type="submit" class="ffc-recruitment-search-btn">' . $icon . '<span>' . esc_html__( 'Search', 'ffcertificate' ) . '</span></button>';
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
			$a = RecruitmentAdjutancyReader::get_by_id( $id );
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
	 * Renders a 7-page window centered on the current page (±3) plus
	 * first / prev / next / last arrows so notices with 30+ pages don't
	 * dump every number on one line. The window slides at the edges so
	 * it always shows up to 7 entries when there's enough room.
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

		$base   = remove_query_arg( $page_param );
		$url_to = static function ( int $p ) use ( $page_param, $base ): string {
			return add_query_arg( $page_param, $p, $base );
		};

		// Window math — 7 entries centered on $current_page, slid to fit
		// the [1, total_pages] range so we always emit the full window
		// when there's room.
		$window = 7;
		$half   = (int) floor( $window / 2 );
		$start  = max( 1, $current_page - $half );
		$end    = min( $total_pages, $start + $window - 1 );
		$start  = max( 1, $end - $window + 1 );

		$html = '<nav class="ffc-recruitment-pagination">';

		// First / prev arrows. Hidden when already at the start so the
		// markup doesn't carry inert affordances.
		if ( $current_page > 1 ) {
			$html .= '<a class="ffc-recruitment-pagination-arrow" href="' . esc_url( $url_to( 1 ) ) . '" aria-label="' . esc_attr__( 'First page', 'ffcertificate' ) . '" title="' . esc_attr__( 'First page', 'ffcertificate' ) . '">&laquo;</a>';
			$html .= '<a class="ffc-recruitment-pagination-arrow" href="' . esc_url( $url_to( $current_page - 1 ) ) . '" aria-label="' . esc_attr__( 'Previous page', 'ffcertificate' ) . '" title="' . esc_attr__( 'Previous page', 'ffcertificate' ) . '">&lsaquo;</a>';
		}

		for ( $p = $start; $p <= $end; $p++ ) {
			if ( $p === $current_page ) {
				$html .= '<span class="current" aria-current="page">' . esc_html( (string) $p ) . '</span>';
			} else {
				$html .= '<a href="' . esc_url( $url_to( $p ) ) . '">' . esc_html( (string) $p ) . '</a>';
			}
		}

		if ( $current_page < $total_pages ) {
			$html .= '<a class="ffc-recruitment-pagination-arrow" href="' . esc_url( $url_to( $current_page + 1 ) ) . '" aria-label="' . esc_attr__( 'Next page', 'ffcertificate' ) . '" title="' . esc_attr__( 'Next page', 'ffcertificate' ) . '">&rsaquo;</a>';
			$html .= '<a class="ffc-recruitment-pagination-arrow" href="' . esc_url( $url_to( $total_pages ) ) . '" aria-label="' . esc_attr__( 'Last page', 'ffcertificate' ) . '" title="' . esc_attr__( 'Last page', 'ffcertificate' ) . '">&raquo;</a>';
		}

		$html .= '</nav>';
		return $html;
	}

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
	public static function parse_columns_config( string $json ): array {
		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			$decoded = array();
		}
		/**
		 * Default column visibility map decoded from the schema's JSON.
		 *
		 * @var array<string,bool> $default
		 */
		$default = (array) json_decode( RecruitmentNoticeReader::DEFAULT_PUBLIC_COLUMNS_CONFIG, true );

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
	public static function wrap_with_banner( object $notice, string $body ): string {
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
			'withdrew'  => __( 'Withdrew', 'ffcertificate' ),
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
			: RecruitmentAdjutancyReader::DEFAULT_COLOR;
		$name      = $adjutancy->name ?? '';
		return BadgeHtml::render(
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
	public static function render_subscription_badge( bool $is_pcd ): string {
		$settings = RecruitmentSettings::all();
		$bg       = $is_pcd
			? (string) $settings['subscription_color_pcd']
			: (string) $settings['subscription_color_geral'];
		$label    = $is_pcd ? __( 'PCD', 'ffcertificate' ) : __( 'GERAL', 'ffcertificate' );
		return BadgeHtml::render(
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
		return BadgeHtml::render(
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
			'withdrew'  => (string) $settings['status_color_withdrew'],
		);
		return BadgeHtml::render(
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
	public static function msg( string $text, string $kind ): string {
		return sprintf(
			'<div class="ffc-recruitment-message ffc-recruitment-message-%s">%s</div>',
			esc_attr( $kind ),
			esc_html( $text )
		);
	}
}
