<?php
/**
 * Recruitment Candidate Dashboard Section
 *
 * Server-rendered "Minhas Convocações" panel exposed as the
 * `[ffc_recruitment_my_calls]` shortcode. Designed to live alongside
 * `[user_dashboard_personal]` on the user-dashboard page; the
 * recruitment loader registers it on `init` next to the public
 * shortcode.
 *
 * Visibility (§9.1): renders ONLY when the logged-in user appears in at
 * least one `classification` row joined via `candidate.user_id`.
 * Anonymous visitors and users without a linked candidate row see
 * nothing — the section silently no-ops, no error message.
 *
 * Layout per §9.2: groups by notice (excluding `draft` notices). Each
 * notice block shows:
 *
 *   1. The candidate's own classification(s) with a prévia/definitive banner
 *      based on `notice.status` (preliminary → preview row + warning
 *      banner; definitive/closed → definitive row + definitive banner).
 *   2. Convocations history — every call row including cancelled ones.
 *      Each call's "Situação" is derived per §9.3 (call's
 *      cancelled_at + classification.status).
 *
 * Sensitive fields are MASKED via `DocumentFormatter` per §10-bis. The
 * candidate sees only the redacted forms of CPF/RF/email even on their
 * own dashboard.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.0.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Recruitment;

use FreeFormCertificate\Shortcodes\DashboardViewMode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Candidate-self dashboard section renderer.
 *
 * @phpstan-import-type CandidateRow      from RecruitmentCandidateRepository
 * @phpstan-import-type ClassificationRow from RecruitmentClassificationRepository
 * @phpstan-import-type NoticeRow         from RecruitmentNoticeRepository
 * @phpstan-import-type CallRow           from RecruitmentCallRepository
 */
final class RecruitmentDashboardSection {

	/** Tag registered with `add_shortcode`. */
	public const SHORTCODE_TAG = 'ffc_recruitment_my_calls';

	/**
	 * Hook registration. Called from {@see RecruitmentLoader::init()}.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_shortcode( self::SHORTCODE_TAG, array( self::class, 'render' ) );
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

		return self::render_for_user( $user_id );
	}

	/**
	 * In-process render entry point. Used by `DashboardShortcode` so the
	 * already-resolved (view-as-aware) `$user_id` can be threaded through
	 * without re-checking nonces/caps a second time. Anyone calling this
	 * directly is responsible for ensuring the supplied id is the user
	 * they actually intend to render — the method does not re-validate.
	 *
	 * @param int $user_id Effective candidate-side WP user id.
	 * @return string
	 */
	public static function render_for_user( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return '';
		}

		$candidates = RecruitmentCandidateRepository::get_by_user_id( $user_id );
		if ( empty( $candidates ) ) {
			// §9.1 visibility: hide the entire section when the user has
			// no linked candidate row. Avoids surprise empty UI.
			return '';
		}

		// Group classifications by notice — skip `draft` notices.
		$by_notice = array();
		foreach ( $candidates as $candidate ) {
			$candidate_id    = (int) $candidate->id;
			$classifications = RecruitmentClassificationRepository::get_for_candidate( $candidate_id );
			foreach ( $classifications as $cls ) {
				$notice_id = (int) $cls->notice_id;
				$notice    = RecruitmentNoticeRepository::get_by_id( $notice_id );
				if ( null === $notice || 'draft' === $notice->status ) {
					continue;
				}

				if ( ! isset( $by_notice[ $notice_id ] ) ) {
					$by_notice[ $notice_id ] = array(
						'notice'          => $notice,
						'classifications' => array(),
					);
				}
				$by_notice[ $notice_id ]['classifications'][] = $cls;
			}
		}

		if ( empty( $by_notice ) ) {
			return '';
		}

		$html  = '<section class="ffc-recruitment-my-calls">';
		$html .= '<h2>' . esc_html__( 'My Calls', 'ffcertificate' ) . '</h2>';

		foreach ( $by_notice as $bundle ) {
			$html .= self::render_notice_block( $bundle['notice'], $bundle['classifications'] );
		}

		$html .= '</section>';
		return $html;
	}

	/**
	 * Render one notice block (classification banner + calls history).
	 *
	 * @param object $notice                 Notice row (NoticeRow shape).
	 * @phpstan-param NoticeRow $notice
	 * @param array  $classifications        The candidate's classifications in this notice (list<ClassificationRow>).
	 * @phpstan-param list<ClassificationRow> $classifications
	 * @return string
	 */
	private static function render_notice_block( object $notice, array $classifications ): string {
		$is_preliminary = 'preliminary' === $notice->status;
		$banner_text    = $is_preliminary
			? __( 'Preliminary classification — subject to review', 'ffcertificate' )
			: __( 'Final classification', 'ffcertificate' );
		$banner_class   = 'ffc-banner-' . ( $is_preliminary ? 'preliminary' : 'definitive' );

		$html  = '<article class="ffc-recruitment-my-notice">';
		$html .= '<header><h3>' . esc_html( $notice->code . ' — ' . $notice->name ) . '</h3>';
		$html .= '<div class="ffc-recruitment-banner ' . esc_attr( $banner_class ) . '">' . esc_html( $banner_text ) . '</div></header>';

		$html .= '<h4>' . esc_html__( 'Your classification(s) for this notice', 'ffcertificate' ) . '</h4>';
		$html .= self::render_classifications_table( $classifications, $is_preliminary );

		// History of calls across all this candidate's classifications in
		// this notice.
		$call_history = self::collect_calls( $classifications );
		if ( ! empty( $call_history ) ) {
			$html .= '<h4>' . esc_html__( 'Call history', 'ffcertificate' ) . '</h4>';
			$html .= self::render_calls_table( $call_history );
		} else {
			$html .= '<p>' . esc_html__( 'You have not been called for this notice yet.', 'ffcertificate' ) . '</p>';
		}

		$html .= '</article>';
		return $html;
	}

	/**
	 * Render the classifications mini-table.
	 *
	 * For preliminary notices, only `preview` rows are shown; for
	 * definitive/closed, only `definitive` rows. The §5.2 invariant guarantees
	 * preview is always `status='empty'`, so preliminary always shows
	 * "Aguardando".
	 *
	 * @param array $classifications  All classification rows for this notice/candidate (list<ClassificationRow>).
	 * @phpstan-param list<ClassificationRow> $classifications
	 * @param bool  $is_preliminary   Whether the parent notice is in `preliminary`.
	 * @return string
	 */
	private static function render_classifications_table( array $classifications, bool $is_preliminary ): string {
		$wanted = $is_preliminary ? 'preview' : 'definitive';

		$html  = '<table class="ffc-recruitment-table"><thead><tr>';
		$html .= '<th>' . esc_html__( 'Adjutancy', 'ffcertificate' ) . '</th>';
		$html .= '<th>' . esc_html__( 'Rank', 'ffcertificate' ) . '</th>';
		$html .= '<th>' . esc_html__( 'Score', 'ffcertificate' ) . '</th>';
		$html .= '<th>' . esc_html__( 'Status', 'ffcertificate' ) . '</th>';
		$html .= '</tr></thead><tbody>';

		$rendered_any = false;
		foreach ( $classifications as $cls ) {
			if ( $cls->list_type !== $wanted ) {
				continue;
			}
			$adjutancy    = RecruitmentAdjutancyRepository::get_by_id( (int) $cls->adjutancy_id );
			$html        .= '<tr>';
			$html        .= '<td>' . esc_html( null === $adjutancy ? '—' : $adjutancy->name ) . '</td>';
			$html        .= '<td>' . esc_html( (string) $cls->rank ) . '</td>';
			$html        .= '<td>' . esc_html( (string) $cls->score ) . '</td>';
			$html        .= '<td>' . esc_html( self::status_label( (string) $cls->status ) ) . '</td>';
			$html        .= '</tr>';
			$rendered_any = true;
		}

		if ( ! $rendered_any ) {
			$html .= '<tr><td colspan="4">' . esc_html__( 'No classification visible at the moment.', 'ffcertificate' ) . '</td></tr>';
		}
		$html .= '</tbody></table>';
		return $html;
	}

	/**
	 * Aggregate the call rows across the candidate's classifications in
	 * a single notice.
	 *
	 * @param array $classifications Classification rows (list<ClassificationRow>).
	 * @phpstan-param list<ClassificationRow> $classifications
	 * @return array<int, array{call: object, classification: object}>
	 * @phpstan-return list<array{call: CallRow, classification: ClassificationRow}>
	 */
	private static function collect_calls( array $classifications ): array {
		$ids = array();
		foreach ( $classifications as $cls ) {
			$ids[] = (int) $cls->id;
		}
		if ( empty( $ids ) ) {
			return array();
		}

		$calls = RecruitmentCallRepository::get_history_for_classifications( $ids );

		// Index classifications for quick lookup when building the joined
		// rows.
		$by_cls = array();
		foreach ( $classifications as $cls ) {
			$by_cls[ (int) $cls->id ] = $cls;
		}

		$out = array();
		foreach ( $calls as $call ) {
			$cid = (int) $call->classification_id;
			if ( isset( $by_cls[ $cid ] ) ) {
				$out[] = array(
					'call'           => $call,
					'classification' => $by_cls[ $cid ],
				);
			}
		}

		return $out;
	}

	/**
	 * Render the calls history mini-table.
	 *
	 * @param array $rows Joined call+classification pairs (list<array{call,classification}>).
	 * @phpstan-param list<array{call: CallRow, classification: ClassificationRow}> $rows
	 * @return string
	 */
	private static function render_calls_table( array $rows ): string {
		$html  = '<table class="ffc-recruitment-table"><thead><tr>';
		$html .= '<th>' . esc_html__( 'Adjutancy', 'ffcertificate' ) . '</th>';
		$html .= '<th>' . esc_html__( 'Called at', 'ffcertificate' ) . '</th>';
		$html .= '<th>' . esc_html__( 'Date to assume', 'ffcertificate' ) . '</th>';
		$html .= '<th>' . esc_html__( 'Time', 'ffcertificate' ) . '</th>';
		$html .= '<th>' . esc_html__( 'Status', 'ffcertificate' ) . '</th>';
		$html .= '<th>' . esc_html__( 'Notes', 'ffcertificate' ) . '</th>';
		$html .= '</tr></thead><tbody>';

		foreach ( $rows as $pair ) {
			$call      = $pair['call'];
			$class     = $pair['classification'];
			$adjutancy = RecruitmentAdjutancyRepository::get_by_id( (int) $class->adjutancy_id );
			$adj_name  = null === $adjutancy ? '—' : (string) $adjutancy->name;
			$situation = self::call_situation_label( $call, (string) $class->status );

			$html .= '<tr>';
			$html .= '<td>' . esc_html( $adj_name ) . '</td>';
			$html .= '<td>' . esc_html( (string) $call->called_at ) . '</td>';
			$html .= '<td>' . esc_html( (string) $call->date_to_assume ) . '</td>';
			$html .= '<td>' . esc_html( (string) $call->time_to_assume ) . '</td>';
			$html .= '<td>' . esc_html( $situation ) . '</td>';
			$html .= '<td>' . esc_html( null === $call->notes ? '' : (string) $call->notes ) . '</td>';
			$html .= '</tr>';
		}

		$html .= '</tbody></table>';
		return $html;
	}

	/**
	 * Map raw classification status to the public-facing label.
	 *
	 * @param string $status Raw enum value.
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
	 * Derive the per-call "Situação" label per §9.3.
	 *
	 * @param object $call         Call row (CallRow shape).
	 * @phpstan-param CallRow $call
	 * @param string $class_status Current classification status.
	 * @return string
	 */
	private static function call_situation_label( object $call, string $class_status ): string {
		if ( null !== $call->cancelled_at ) {
			return __( 'Cancelled', 'ffcertificate' );
		}
		if ( in_array( $class_status, array( 'called', 'accepted' ), true ) ) {
			return __( 'Called', 'ffcertificate' );
		}
		if ( 'not_shown' === $class_status ) {
			return __( 'Did not show up', 'ffcertificate' );
		}
		if ( 'hired' === $class_status ) {
			return __( 'Hired', 'ffcertificate' );
		}
		// `empty` after a non-cancelled call → manual reversal (rare).
		return __( 'Call reverted', 'ffcertificate' );
	}
}
