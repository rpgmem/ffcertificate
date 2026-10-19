<?php
/**
 * Recruitment Candidate Dashboard Section
 *
 * Server-rendered "My Calls" panel exposed as the
 * `[ffc_recruitment_my_calls]` shortcode and reused as the user dashboard's
 * recruitment tab.
 *
 * Visibility (§9.1): renders ONLY when the logged-in user appears in at
 * least one `classification` row joined via `candidate.user_id`.
 * Anonymous visitors and users without a linked candidate row see
 * nothing — the section silently no-ops, no error message.
 *
 * Layout (#1889): one panel with three status sections, in the order a
 * candidate needs them, and a summary of counters that jump to each:
 *
 *   1. Called — one card per active call (classification `called` or
 *      `accepted`, call not cancelled), with the presentation date, how far
 *      away it is, an "Add to calendar" download and the day the card leaves.
 *   2. Waiting — the classifications not called yet, final ones first, then
 *      the preliminary ones, as two labelled groups; paged by 5 on the client.
 *   3. Closed — hired, withdrew, did not show up, and "Deadline passed": a
 *      call still `called` one day after its presentation date. That status
 *      is derived on every render, never stored, so the operator's later
 *      update replaces it. Paged by 2 on the client.
 *
 * Extra context — a passed deadline, a reverted call — lives only behind an
 * icon-only `info` button beside the row's badge. Paging and the popover are
 * `assets/js/ffc-recruitment-my-calls.js`; without it every row shows.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.0.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Recruitment;

use FreeFormCertificate\Core\Button;
use FreeFormCertificate\Core\DateFormatter;
use FreeFormCertificate\Shortcodes\DashboardAssetManager;
use FreeFormCertificate\Shortcodes\DashboardViewMode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Candidate-self dashboard section renderer.
 *
 * @phpstan-import-type CandidateRow      from RecruitmentCandidateReader
 * @phpstan-import-type ClassificationRow from RecruitmentClassificationRepository
 * @phpstan-import-type NoticeRow         from RecruitmentNoticeReader
 * @phpstan-import-type CallRow           from RecruitmentCallReader
 *
 * @phpstan-type MyCallsEntry array{notice: NoticeRow, classification: ClassificationRow, calls: list<CallRow>}
 * @phpstan-type MyCallsItem array{status: string, notice: NoticeRow, classification: ClassificationRow, call: CallRow|null, reverted: CallRow|null}
 * @phpstan-type MyCallsSections array{called: list<MyCallsItem>, waiting: list<MyCallsItem>, closed: list<MyCallsItem>}
 */
final class RecruitmentDashboardSection {

	/** Tag registered with `add_shortcode`. */
	public const SHORTCODE_TAG = 'ffc_recruitment_my_calls';

	/** Handle of the script that pages the tables and opens the popovers. */
	public const SCRIPT_HANDLE = 'ffc-recruitment-my-calls';

	/** Rows per page in Waiting. */
	public const WAITING_PER_PAGE = 5;

	/** Rows per page in Closed. */
	public const CLOSED_PER_PAGE = 2;

	/**
	 * Popover ids printed so far in this request, so two popovers never
	 * share one even when the section renders twice on a page.
	 *
	 * @var int
	 */
	private static int $popover_seq = 0;

	/**
	 * Hook registration. Called from {@see RecruitmentLoader::init()}.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_shortcode( self::SHORTCODE_TAG, array( self::class, 'render' ) );
		RecruitmentCallIcs::register();
	}

	/**
	 * Shortcode callback. Resolves the effective user via the dashboard's
	 * "view as user" override (admins only, gated by nonce + cap inside
	 * {@see DashboardViewMode::get_view_as_user_id()}) so the section
	 * renders the impersonated user's classifications instead of the
	 * admin's own when an admin is in view-as mode.
	 *
	 * @param array<string|int, mixed>|string $atts Raw shortcode attributes (unused).
	 * @return string
	 */
	public static function render( $atts = array() ): string {
		unset( $atts );

		if ( ! is_user_logged_in() ) {
			return '';
		}

		$view_as = class_exists( DashboardViewMode::class )
			? DashboardViewMode::get_view_as_user_id()
			: false;
		$user_id = $view_as ? (int) $view_as : (int) get_current_user_id();

		$html = self::render_for_user( $user_id );

		// On a page of its own nothing else loads the section's rules, which
		// live in the dashboard's sheet with the tab that shows the same
		// section (#1832). Loaded only when there is a section to style.
		if ( '' !== $html ) {
			DashboardAssetManager::enqueue_styles();
		}

		return $html;
	}

	/**
	 * In-process render entry point. Used by `DashboardShortcode` so the
	 * already-resolved (view-as-aware) `$user_id` can be threaded through
	 * without re-checking nonces/caps a second time. Anyone calling this
	 * directly is responsible for ensuring the supplied id is the user
	 * they actually intend to render — the method does not re-validate.
	 *
	 * @param int      $user_id Effective candidate-side WP user id.
	 * @param int|null $now     Unix time the deadline rule reads; null for now.
	 * @return string
	 */
	public static function render_for_user( int $user_id, ?int $now = null ): string {
		if ( $user_id <= 0 ) {
			return '';
		}

		$entries = self::collect_entries( $user_id );
		if ( empty( $entries ) ) {
			return '';
		}

		$now      = null === $now ? time() : $now;
		$sections = self::build_sections( $entries, $now );

		$html = self::render_panel( $sections, $now );

		self::enqueue_script();

		return $html;
	}

	/**
	 * The candidate's classifications that a notice shows, each with its
	 * notice and its calls (most recent first).
	 *
	 * Draft notices are skipped. A preliminary notice shows the `preview`
	 * list and a definitive or closed one the `definitive` list, as before
	 * (§5.2): the other list is not the candidate's current standing.
	 *
	 * @param int $user_id WP user id.
	 * @return array<int, array<string, mixed>>
	 * @phpstan-return list<MyCallsEntry>
	 */
	private static function collect_entries( int $user_id ): array {
		$candidates = RecruitmentCandidateReader::get_by_user_id( $user_id );
		if ( empty( $candidates ) ) {
			// §9.1 visibility: hide the entire section when the user has
			// no linked candidate row. Avoids surprise empty UI.
			return array();
		}

		$notices = array();
		$kept    = array();
		foreach ( $candidates as $candidate ) {
			$classifications = RecruitmentClassificationRepository::get_for_candidate( (int) $candidate->id );
			foreach ( $classifications as $cls ) {
				$notice_id = (int) $cls->notice_id;
				if ( ! array_key_exists( $notice_id, $notices ) ) {
					$notices[ $notice_id ] = RecruitmentNoticeReader::get_by_id( $notice_id );
				}
				$notice = $notices[ $notice_id ];
				if ( null === $notice || 'draft' === $notice->status ) {
					continue;
				}
				$wanted = 'preliminary' === $notice->status ? 'preview' : 'definitive';
				if ( $cls->list_type !== $wanted ) {
					continue;
				}
				$kept[] = array(
					'notice'         => $notice,
					'classification' => $cls,
				);
			}
		}

		if ( empty( $kept ) ) {
			return array();
		}

		$ids = array();
		foreach ( $kept as $pair ) {
			$ids[] = (int) $pair['classification']->id;
		}
		$by_cls = array();
		foreach ( RecruitmentCallReader::get_history_for_classifications( $ids ) as $call ) {
			$by_cls[ (int) $call->classification_id ][] = $call;
		}

		$entries = array();
		foreach ( $kept as $pair ) {
			$calls = $by_cls[ (int) $pair['classification']->id ] ?? array();
			usort(
				$calls,
				static function ( $a, $b ): int {
					return (int) $b->called_at <=> (int) $a->called_at;
				}
			);
			$entries[] = array(
				'notice'         => $pair['notice'],
				'classification' => $pair['classification'],
				'calls'          => $calls,
			);
		}

		return $entries;
	}

	/**
	 * Sort the entries into the three status sections.
	 *
	 * - A `called` / `accepted` classification with a call that is not
	 *   cancelled is Called, until {@see self::deadline_passed()}; then it is
	 *   Closed as `expired` ("Deadline passed"). One with no live call (a
	 *   state the call service does not produce) reads as Waiting.
	 * - `empty` is Waiting, `final` or `preliminary` by the notice's status.
	 * - `hired`, `withdrew`, `not_shown` and any status this screen does not
	 *   know are Closed.
	 *
	 * Every item also carries the most recent cancelled call (`reverted`),
	 * which the Waiting and Closed rows explain behind their info button.
	 *
	 * @param array<int, array<string, mixed>> $entries From {@see self::collect_entries()}.
	 * @phpstan-param list<MyCallsEntry> $entries
	 * @param int                              $now     Unix time.
	 * @return array<string, array<int, array<string, mixed>>>
	 * @phpstan-return MyCallsSections
	 */
	public static function build_sections( array $entries, int $now ): array {
		$called  = array();
		$waiting = array();
		$closed  = array();

		foreach ( $entries as $entry ) {
			$live     = null;
			$reverted = null;
			foreach ( $entry['calls'] as $call ) {
				if ( null === $call->cancelled_at ) {
					$live = $live ?? $call;
				} else {
					$reverted = $reverted ?? $call;
				}
			}

			$item = array(
				'status'         => '',
				'notice'         => $entry['notice'],
				'classification' => $entry['classification'],
				'call'           => $live,
				'reverted'       => $reverted,
			);

			$status = (string) $entry['classification']->status;
			if ( in_array( $status, array( 'called', 'accepted' ), true ) && null !== $live ) {
				if ( self::deadline_passed( (string) $live->date_to_assume, $now ) ) {
					$item['status'] = 'expired';
					$closed[]       = $item;
				} else {
					$item['status'] = 'called';
					$called[]       = $item;
				}
				continue;
			}

			if ( in_array( $status, array( 'empty', 'called', 'accepted' ), true ) ) {
				$item['status'] = 'preliminary' === $entry['notice']->status ? 'preliminary' : 'final';
				$waiting[]      = $item;
				continue;
			}

			$item['status'] = $status;
			$closed[]       = $item;
		}

		usort(
			$called,
			static function ( array $a, array $b ): int {
				return self::when_key( $a['call'] ) <=> self::when_key( $b['call'] );
			}
		);
		usort(
			$waiting,
			static function ( array $a, array $b ): int {
				$group = ( 'final' === $a['status'] ? 0 : 1 ) <=> ( 'final' === $b['status'] ? 0 : 1 );
				if ( 0 !== $group ) {
					return $group;
				}
				$code = strcmp( (string) $a['notice']->code, (string) $b['notice']->code );
				return 0 !== $code ? $code : (int) $a['classification']->rank <=> (int) $b['classification']->rank;
			}
		);
		usort(
			$closed,
			static function ( array $a, array $b ): int {
				// Most recent presentation first; a row without one goes last.
				return self::when_key( $b['call'] ) <=> self::when_key( $a['call'] );
			}
		);

		return array(
			'called'  => $called,
			'waiting' => $waiting,
			'closed'  => $closed,
		);
	}

	/**
	 * Whether a call's card has left: its presentation date plus one day has
	 * begun, in the site timezone.
	 *
	 * A call presented on the 19th shows its card through the 19th and leaves
	 * at midnight that starts the 20th — the day "This card leaves on" names.
	 * `date_to_assume` is a wall-clock DATE (Category B), so it is read as a
	 * day of the site's calendar, never as an instant.
	 *
	 * @param string $date_to_assume Presentation date, `Y-m-d`.
	 * @param int    $now            Unix time.
	 * @return bool False for an unreadable date: the card stays rather than
	 *              vanish on a value nobody can check.
	 */
	public static function deadline_passed( string $date_to_assume, int $now ): bool {
		$leaves = self::leave_moment( $date_to_assume );
		return null !== $leaves && $now >= $leaves->getTimestamp();
	}

	/**
	 * The day a call's card leaves, `Y-m-d`, or '' for an unreadable date.
	 *
	 * @param string $date_to_assume Presentation date, `Y-m-d`.
	 * @return string
	 */
	public static function leaves_on( string $date_to_assume ): string {
		$leaves = self::leave_moment( $date_to_assume );
		return null === $leaves ? '' : $leaves->format( 'Y-m-d' );
	}

	/**
	 * Whole days from the site's today to the presentation date, or null for
	 * an unreadable date.
	 *
	 * @param string $date_to_assume Presentation date, `Y-m-d`.
	 * @param int    $now            Unix time.
	 * @return int|null
	 */
	public static function days_until( string $date_to_assume, int $now ): ?int {
		$tz   = wp_timezone();
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date_to_assume, $tz );
		if ( false === $date || $date->format( 'Y-m-d' ) !== $date_to_assume ) {
			return null;
		}
		$today = \DateTimeImmutable::createFromFormat( '!Y-m-d', ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( $tz )->format( 'Y-m-d' ), $tz );
		if ( false === $today ) {
			return null;
		}
		$diff = $today->diff( $date );
		$days = (int) $diff->days;
		return $diff->invert ? -$days : $days;
	}

	/**
	 * Midnight that starts the day after the presentation, site timezone.
	 *
	 * @param string $date_to_assume Presentation date, `Y-m-d`.
	 * @return \DateTimeImmutable|null
	 */
	private static function leave_moment( string $date_to_assume ): ?\DateTimeImmutable {
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date_to_assume, wp_timezone() );
		if ( false === $date || $date->format( 'Y-m-d' ) !== $date_to_assume ) {
			return null;
		}
		return $date->modify( '+1 day' );
	}

	/**
	 * Sort key of a call's presentation, `Y-m-d H:i:s`; '' without a call.
	 *
	 * @param object|null $call Call row or null.
	 * @phpstan-param CallRow|null $call
	 * @return string
	 */
	private static function when_key( ?object $call ): string {
		return null === $call ? '' : (string) $call->date_to_assume . ' ' . (string) $call->time_to_assume;
	}

	/**
	 * The whole panel.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $sections From {@see self::build_sections()}.
	 * @phpstan-param MyCallsSections $sections
	 * @param int                                             $now      Unix time.
	 * @return string
	 */
	private static function render_panel( array $sections, int $now ): string {
		$html  = '<section class="ffc-shortcode ffc-recruitment-my-calls">';
		$html .= '<h2>' . esc_html__( 'My Calls', 'ffcertificate' ) . '</h2>';
		$html .= self::render_summary( $sections );

		if ( ! empty( $sections['called'] ) ) {
			$html .= self::section_open( 'called', 'megaphone', __( 'Called', 'ffcertificate' ), __( 'Show up on the date and time shown.', 'ffcertificate' ) );
			$html .= '<div class="ffc-mycalls-cards">';
			foreach ( $sections['called'] as $item ) {
				$html .= self::render_card( $item, $now );
			}
			$html .= '</div></section>';
		}

		if ( ! empty( $sections['waiting'] ) ) {
			$html .= self::section_open( 'waiting', 'clock', __( 'Waiting for a call', 'ffcertificate' ), __( 'Final classification first; a preliminary one may still change.', 'ffcertificate' ) );
			$html .= self::render_waiting( $sections['waiting'] );
			$html .= '</section>';
		}

		if ( ! empty( $sections['closed'] ) ) {
			$html .= self::section_open( 'closed', 'inbox', _x( 'Closed', 'recruitment calls section', 'ffcertificate' ), __( 'Hired, withdrew, did not show up, or the presentation deadline passed.', 'ffcertificate' ) );
			$html .= self::render_closed( $sections['closed'] );
			$html .= '</section>';
		}

		$html .= '</section>';
		return $html;
	}

	/**
	 * The counters at the top; a section with nothing in it has none.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $sections Sections.
	 * @phpstan-param MyCallsSections $sections
	 * @return string
	 */
	private static function render_summary( array $sections ): string {
		$called  = count( $sections['called'] );
		$waiting = count( $sections['waiting'] );
		$closed  = count( $sections['closed'] );

		$chips = array();
		if ( $called > 0 ) {
			/* translators: %s: number of active calls */
			$chips['called'] = sprintf( esc_html( _n( '%s active call', '%s active calls', $called, 'ffcertificate' ) ), '<b>' . esc_html( number_format_i18n( $called ) ) . '</b>' );
		}
		if ( $waiting > 0 ) {
			/* translators: %s: number of classifications waiting for a call */
			$chips['waiting'] = sprintf( esc_html( _n( '%s waiting', '%s waiting', $waiting, 'ffcertificate' ) ), '<b>' . esc_html( number_format_i18n( $waiting ) ) . '</b>' );
		}
		if ( $closed > 0 ) {
			/* translators: %s: number of closed calls */
			$chips['closed'] = sprintf( esc_html( _n( '%s closed', '%s closed', $closed, 'ffcertificate' ) ), '<b>' . esc_html( number_format_i18n( $closed ) ) . '</b>' );
		}

		$html = '<nav class="ffc-mycalls-summary" aria-label="' . esc_attr__( 'Summary', 'ffcertificate' ) . '">';
		foreach ( $chips as $key => $text ) {
			$html .= '<a class="ffc-mycalls-chip ffc-mycalls-chip-' . esc_attr( $key ) . '" href="#ffc-mycalls-' . esc_attr( $key ) . '">' . $text . '</a>';
		}
		$html .= '</nav>';
		return $html;
	}

	/**
	 * Opening of a status section: the heading with its icon and the hint.
	 *
	 * @param string $key   `called`, `waiting` or `closed`.
	 * @param string $icon  `Core\Icons` class.
	 * @param string $title Heading.
	 * @param string $hint  One line under it.
	 * @return string
	 */
	private static function section_open( string $key, string $icon, string $title, string $hint ): string {
		$html  = '<section class="ffc-mycalls-section ffc-mycalls-section-' . esc_attr( $key ) . '" id="ffc-mycalls-' . esc_attr( $key ) . '">';
		$html .= '<header class="ffc-mycalls-section-header">';
		$html .= '<h3 class="ffc-mycalls-heading ffc-icon-' . esc_attr( $icon ) . '">' . esc_html( $title ) . '</h3>';
		$html .= '<p class="ffc-mycalls-hint">' . esc_html( $hint ) . '</p>';
		$html .= '</header>';
		return $html;
	}

	/**
	 * One active call.
	 *
	 * @param array<string, mixed> $item Section item.
	 * @phpstan-param MyCallsItem $item
	 * @param int                  $now  Unix time.
	 * @return string
	 */
	private static function render_card( array $item, int $now ): string {
		$call   = $item['call'];
		$notice = $item['notice'];
		$cls    = $item['classification'];
		if ( null === $call ) {
			return '';
		}

		$date = DateFormatter::format_wallclock_date( (string) $call->date_to_assume );
		$time = DateFormatter::format_wallclock_time( (string) $call->time_to_assume );
		$when = '' === $time
			? $date
			/* translators: 1: presentation date, 2: presentation time */
			: sprintf( __( '%1$s at %2$s', 'ffcertificate' ), $date, $time );

		$html  = '<article class="ffc-mycalls-card">';
		$html .= '<div class="ffc-mycalls-card-top">';
		$html .= '<div class="ffc-mycalls-notice">' . self::notice_html( $notice ) . '</div>';
		$html .= self::classification_badge( 'preliminary' === $notice->status ? 'preliminary' : 'final' );
		$html .= '</div>';

		$html .= '<p class="ffc-mycalls-when ffc-icon-calendar"><span>';
		/* translators: %s: presentation date and time */
		$html .= sprintf( esc_html__( 'Show up on %s', 'ffcertificate' ), '<strong>' . esc_html( $when ) . '</strong>' );
		$html .= '</span>' . self::countdown_html( (string) $call->date_to_assume, $now ) . '</p>';

		$html     .= '<dl class="ffc-mycalls-meta">';
		$html     .= self::meta( __( 'Adjutancy', 'ffcertificate' ), self::subject_name( $cls ) );
		$html     .= self::meta( __( 'Position · Score', 'ffcertificate' ), number_format_i18n( (int) $cls->rank ) . ' · ' . number_format_i18n( (float) $cls->score, 2 ) );
		$called_at = (int) $call->called_at > 0 ? DateFormatter::format_datetime( (int) $call->called_at ) : '';
		if ( '' !== $called_at ) {
			$html .= self::meta( __( 'Called at', 'ffcertificate' ), $called_at );
		}
		$notes = null === $call->notes ? '' : trim( (string) $call->notes );
		if ( '' !== $notes ) {
			$html .= self::meta( __( 'Notes', 'ffcertificate' ), $notes );
		}
		$html .= '</dl>';

		$html  .= '<div class="ffc-mycalls-card-foot">';
		$html  .= Button::html(
			array(
				'label'    => __( 'Add to calendar', 'ffcertificate' ),
				'category' => 'neutral',
				'icon'     => 'calendar-check',
				'href'     => RecruitmentCallIcs::url( (int) $call->id ),
				'class'    => 'ffc-mycalls-ics',
				'attrs'    => array( 'download' => true ),
			)
		);
		$leaves = self::leaves_on( (string) $call->date_to_assume );
		if ( '' !== $leaves ) {
			/* translators: %s: date the card leaves this section */
			$html .= '<span class="ffc-mycalls-leaves">' . esc_html( sprintf( __( 'This card leaves on %s.', 'ffcertificate' ), DateFormatter::format_wallclock_date( $leaves ) ) ) . '</span>';
		}
		$html .= '</div>';

		$html .= '</article>';
		return $html;
	}

	/**
	 * "today" / "tomorrow" / "in N days" beside the presentation date.
	 *
	 * @param string $date_to_assume Presentation date.
	 * @param int    $now            Unix time.
	 * @return string
	 */
	private static function countdown_html( string $date_to_assume, int $now ): string {
		$days = self::days_until( $date_to_assume, $now );
		if ( null === $days || $days < 0 ) {
			return '';
		}
		if ( 0 === $days ) {
			return ' <span class="ffc-mycalls-countdown ffc-mycalls-countdown-today">' . esc_html__( 'today', 'ffcertificate' ) . '</span>';
		}
		if ( 1 === $days ) {
			return ' <span class="ffc-mycalls-countdown">' . esc_html__( 'tomorrow', 'ffcertificate' ) . '</span>';
		}
		/* translators: %s: number of days until the presentation */
		$label = sprintf( _n( 'in %s day', 'in %s days', $days, 'ffcertificate' ), number_format_i18n( $days ) );
		return ' <span class="ffc-mycalls-countdown">' . esc_html( $label ) . '</span>';
	}

	/**
	 * One `<dt>`/`<dd>` pair of a card.
	 *
	 * @param string $term  Label.
	 * @param string $value Value.
	 * @return string
	 */
	private static function meta( string $term, string $value ): string {
		return '<div><dt>' . esc_html( $term ) . '</dt><dd>' . esc_html( $value ) . '</dd></div>';
	}

	/**
	 * The Waiting table: final rows, then preliminary rows, each group under
	 * a label row the pager repeats with "(continued)" on the next page.
	 *
	 * @param array<int, array<string, mixed>> $items Waiting items, final first.
	 * @phpstan-param list<MyCallsItem> $items
	 * @return string
	 */
	private static function render_waiting( array $items ): string {
		$labels = array(
			'final'       => __( 'Final classification', 'ffcertificate' ),
			'preliminary' => __( 'Preliminary classification — subject to review', 'ffcertificate' ),
		);

		$html  = self::table_open(
			array(
				__( 'Notice', 'ffcertificate' ),
				__( 'Adjutancy', 'ffcertificate' ),
				__( 'Position', 'ffcertificate' ),
				__( 'Score', 'ffcertificate' ),
				__( 'Classification', 'ffcertificate' ),
			)
		);
		$group = null;
		foreach ( $items as $item ) {
			$cls = $item['classification'];
			if ( $item['status'] !== $group ) {
				$group = $item['status'];
				$html .= '<tr class="ffc-mycalls-group-row" data-group="' . esc_attr( $group ) . '"><td colspan="5">';
				$html .= esc_html( $labels[ $group ] ?? '' );
				$html .= '<span class="ffc-mycalls-group-continued"> ' . esc_html__( '(continued)', 'ffcertificate' ) . '</span>';
				$html .= '</td></tr>';
			}
			$html .= '<tr class="ffc-mycalls-row" data-group="' . esc_attr( $group ) . '">';
			$html .= '<td class="ffc-mycalls-notice-cell" data-label="' . esc_attr__( 'Notice', 'ffcertificate' ) . '">' . self::notice_html( $item['notice'] ) . '</td>';
			$html .= '<td data-label="' . esc_attr__( 'Adjutancy', 'ffcertificate' ) . '">' . esc_html( self::subject_name( $cls ) ) . '</td>';
			$html .= '<td class="ffc-mycalls-num" data-label="' . esc_attr__( 'Position', 'ffcertificate' ) . '">' . esc_html( number_format_i18n( (int) $cls->rank ) ) . '</td>';
			$html .= '<td class="ffc-mycalls-num" data-label="' . esc_attr__( 'Score', 'ffcertificate' ) . '">' . esc_html( number_format_i18n( (float) $cls->score, 2 ) ) . '</td>';
			$html .= '<td data-label="' . esc_attr__( 'Classification', 'ffcertificate' ) . '"><div class="ffc-mycalls-status">';
			$html .= self::classification_badge( $group );
			$html .= null === $item['reverted'] ? '' : self::reverted_info( $item['reverted'] );
			$html .= '</div></td>';
			$html .= '</tr>';
		}
		$html .= '</tbody></table></div>';
		$html .= self::pager( self::WAITING_PER_PAGE, __( 'Pages: Waiting for a call', 'ffcertificate' ) );
		return $html;
	}

	/**
	 * The Closed table.
	 *
	 * @param array<int, array<string, mixed>> $items Closed items.
	 * @phpstan-param list<MyCallsItem> $items
	 * @return string
	 */
	private static function render_closed( array $items ): string {
		$html = self::table_open(
			array(
				__( 'Notice', 'ffcertificate' ),
				__( 'Adjutancy', 'ffcertificate' ),
				__( 'Status', 'ffcertificate' ),
				__( 'Presentation date', 'ffcertificate' ),
				__( 'Notes', 'ffcertificate' ),
			)
		);
		foreach ( $items as $item ) {
			$call  = $item['call'];
			$when  = self::presentation_text( $call );
			$notes = null === $call || null === $call->notes ? '' : trim( (string) $call->notes );

			$html .= '<tr class="ffc-mycalls-row">';
			$html .= '<td class="ffc-mycalls-notice-cell" data-label="' . esc_attr__( 'Notice', 'ffcertificate' ) . '">' . self::notice_html( $item['notice'] ) . '</td>';
			$html .= '<td data-label="' . esc_attr__( 'Adjutancy', 'ffcertificate' ) . '">' . esc_html( self::subject_name( $item['classification'] ) ) . '</td>';
			$html .= '<td data-label="' . esc_attr__( 'Status', 'ffcertificate' ) . '"><div class="ffc-mycalls-status">';
			$html .= self::status_badge( $item['status'] );
			if ( 'expired' === $item['status'] ) {
				$html .= self::expired_info( '' === $when ? '—' : $when );
			}
			$html .= null === $item['reverted'] ? '' : self::reverted_info( $item['reverted'] );
			$html .= '</div></td>';
			$html .= '<td class="ffc-mycalls-num" data-label="' . esc_attr__( 'Presentation date', 'ffcertificate' ) . '">' . esc_html( '' === $when ? '—' : $when ) . '</td>';
			$html .= '<td data-label="' . esc_attr__( 'Notes', 'ffcertificate' ) . '">' . esc_html( '' === $notes ? '—' : $notes ) . '</td>';
			$html .= '</tr>';
		}
		$html .= '</tbody></table></div>';
		$html .= self::pager( self::CLOSED_PER_PAGE, __( 'Pages: Closed', 'ffcertificate' ) );
		return $html;
	}

	/**
	 * A table's wrapper, head and opening `<tbody>`.
	 *
	 * @param array<int, string> $columns Column labels.
	 * @return string
	 */
	private static function table_open( array $columns ): string {
		$html = '<div class="ffc-mycalls-table-wrap"><table class="ffc-recruitment-table ffc-mycalls-table"><thead><tr>';
		foreach ( $columns as $label ) {
			$html .= '<th scope="col">' . esc_html( $label ) . '</th>';
		}
		return $html . '</tr></thead><tbody>';
	}

	/**
	 * The pager's container. The script fills it when the rows need more
	 * than one page; empty, it draws nothing, so without the script every
	 * row shows and no pager does.
	 *
	 * @param int    $per_page Rows per page.
	 * @param string $label    The navigation's accessible name.
	 * @return string
	 */
	private static function pager( int $per_page, string $label ): string {
		return '<nav class="ffc-mycalls-pager" aria-label="' . esc_attr( $label ) . '"'
			. ' data-per-page="' . esc_attr( (string) $per_page ) . '"'
			. ' data-label-previous="' . esc_attr__( 'Previous page', 'ffcertificate' ) . '"'
			. ' data-label-next="' . esc_attr__( 'Next page', 'ffcertificate' ) . '"'
			/* translators: %d: page number */
			. ' data-label-page="' . esc_attr__( 'Page %d', 'ffcertificate' ) . '"'
			/* translators: 1: first row shown, 2: last row shown, 3: number of rows */
			. ' data-label-range="' . esc_attr__( 'Showing %1$s–%2$s of %3$s', 'ffcertificate' ) . '"'
			. '></nav>';
	}

	/**
	 * Notice code and name, the way every row names its notice.
	 *
	 * @param object $notice Notice row.
	 * @phpstan-param NoticeRow $notice
	 * @return string
	 */
	private static function notice_html( object $notice ): string {
		return '<span class="ffc-mycalls-notice-code">' . esc_html( (string) $notice->code ) . '</span> '
			. '<span class="ffc-mycalls-notice-name">' . esc_html( (string) $notice->name ) . '</span>';
	}

	/**
	 * The subject (adjutancy) of a classification, or a dash when deleted.
	 *
	 * @param object $cls Classification row.
	 * @phpstan-param ClassificationRow $cls
	 * @return string
	 */
	private static function subject_name( object $cls ): string {
		$adjutancy = RecruitmentAdjutancyReader::get_by_id( (int) $cls->adjutancy_id );
		return null === $adjutancy ? '—' : (string) $adjutancy->name;
	}

	/**
	 * The presentation date and time of a call, or ''.
	 *
	 * @param object|null $call Call row.
	 * @phpstan-param CallRow|null $call
	 * @return string
	 */
	private static function presentation_text( ?object $call ): string {
		if ( null === $call ) {
			return '';
		}
		$date = DateFormatter::format_wallclock_date( (string) $call->date_to_assume );
		$time = DateFormatter::format_wallclock_time( (string) $call->time_to_assume );
		return trim( $date . ' ' . $time );
	}

	/**
	 * Badge of a classification list.
	 *
	 * @param string $group `final` or `preliminary`.
	 * @return string
	 */
	private static function classification_badge( string $group ): string {
		if ( 'preliminary' === $group ) {
			return '<span class="ffc-pill ffc-mycalls-badge-preliminary">' . esc_html_x( 'Preliminary', 'classification list', 'ffcertificate' ) . '</span>';
		}
		return '<span class="ffc-pill ffc-mycalls-badge-final">' . esc_html__( 'Final', 'ffcertificate' ) . '</span>';
	}

	/**
	 * Badge of a closed status.
	 *
	 * @param string $status Derived or stored status.
	 * @return string
	 */
	private static function status_badge( string $status ): string {
		$map  = array(
			'hired'     => array( 'ffc-mycalls-badge-hired', __( 'Hired', 'ffcertificate' ) ),
			'withdrew'  => array( 'ffc-mycalls-badge-withdrew', __( 'Withdrew', 'ffcertificate' ) ),
			'not_shown' => array( 'ffc-mycalls-badge-not-shown', __( 'Did not show up', 'ffcertificate' ) ),
			'expired'   => array( 'ffc-mycalls-badge-expired', __( 'Deadline passed', 'ffcertificate' ) ),
		);
		$pair = $map[ $status ] ?? array( 'ffc-mycalls-badge-withdrew', $status );
		return '<span class="ffc-pill ' . esc_attr( $pair[0] ) . '">' . esc_html( $pair[1] ) . '</span>';
	}

	/**
	 * The info button and popover of a passed deadline.
	 *
	 * @param string $when Presentation date and time, formatted.
	 * @return string
	 */
	private static function expired_info( string $when ): string {
		$body  = '<dl class="ffc-mycalls-pop-facts">';
		$body .= '<dt>' . esc_html__( 'Presentation', 'ffcertificate' ) . '</dt><dd>' . esc_html( $when ) . '</dd>';
		$body .= '</dl>';
		$body .= '<p class="ffc-mycalls-pop-text">' . esc_html__( 'The date has passed and the team has not recorded the result yet. The final status appears here once it is updated.', 'ffcertificate' ) . '</p>';

		return self::info_popover( __( 'About the passed deadline', 'ffcertificate' ), __( 'Status not updated yet', 'ffcertificate' ), $body );
	}

	/**
	 * The info button and popover of a reverted call. The reason is left
	 * out when the operator gave none.
	 *
	 * @param object $call The cancelled call.
	 * @phpstan-param CallRow $call
	 * @return string
	 */
	public static function reverted_info( object $call ): string {
		$called   = (int) $call->called_at > 0 ? DateFormatter::format_datetime( (int) $call->called_at ) : '';
		$reverted = (int) $call->cancelled_at > 0 ? DateFormatter::format_datetime( (int) $call->cancelled_at ) : '';
		$reason   = null === $call->cancellation_reason ? '' : trim( (string) $call->cancellation_reason );

		$body = '<dl class="ffc-mycalls-pop-facts">';
		if ( '' !== $called ) {
			$body .= '<dt>' . esc_html__( 'Called on', 'ffcertificate' ) . '</dt><dd>' . esc_html( $called ) . '</dd>';
		}
		if ( '' !== $reverted ) {
			$body .= '<dt>' . esc_html__( 'Reverted on', 'ffcertificate' ) . '</dt><dd>' . esc_html( $reverted ) . '</dd>';
		}
		if ( '' !== $reason ) {
			$body .= '<dt>' . esc_html__( 'Reason', 'ffcertificate' ) . '</dt><dd>' . esc_html( $reason ) . '</dd>';
		}
		$body .= '</dl>';

		return self::info_popover( __( 'About the reverted call', 'ffcertificate' ), __( 'Previous call reverted', 'ffcertificate' ), $body );
	}

	/**
	 * An icon-only `info` button and the popover it opens.
	 *
	 * The popover starts hidden twice over: the `hidden` attribute, and the
	 * sheet, which shows it only under `.is-open` — so it stays shut even
	 * where an older WordPress's KSES drops the attribute.
	 *
	 * @param string $label Accessible name of the button.
	 * @param string $title Popover heading.
	 * @param string $body  Popover body, already escaped.
	 * @return string
	 */
	private static function info_popover( string $label, string $title, string $body ): string {
		++self::$popover_seq;
		$id = 'ffc-mycalls-pop-' . self::$popover_seq;

		$html  = '<div class="ffc-mycalls-info">';
		$html .= Button::html(
			array(
				'label'     => $label,
				'category'  => 'neutral',
				'icon'      => 'info',
				'icon_only' => true,
				'class'     => 'ffc-mycalls-info-btn',
				'attrs'     => array(
					'aria-expanded' => 'false',
					'aria-controls' => $id,
				),
			)
		);
		$html .= '<div class="ffc-mycalls-pop" id="' . esc_attr( $id ) . '" hidden>';
		$html .= '<strong class="ffc-mycalls-pop-title">' . esc_html( $title ) . '</strong>';
		$html .= $body;
		$html .= '</div></div>';
		return $html;
	}

	/**
	 * Load the script that pages the tables and opens the popovers.
	 *
	 * @return void
	 */
	private static function enqueue_script(): void {
		$s = \FreeFormCertificate\Core\AssetHelper::asset_suffix();
		wp_enqueue_script( self::SCRIPT_HANDLE, FFC_PLUGIN_URL . "assets/js/ffc-recruitment-my-calls{$s}.js", array(), FFC_VERSION, true );
	}
}
