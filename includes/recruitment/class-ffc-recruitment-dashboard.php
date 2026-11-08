<?php
/**
 * Recruitment Dashboard
 *
 * The page the Recruitment menu opens on (#2486): the counts an operator
 * checks first, one card per section with what it holds and a button to
 * open it, and a side column with what needs action — the presentations of
 * the next seven days and the calls whose presentation date has passed.
 *
 * Every figure is a count over the plugin's own tables; nothing personal is
 * read, so the page needs no more than the recruitment view capability.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.44.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Recruitment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Every statement in this class counts rows of the plugin's own ffc_recruitment_* tables, which WordPress exposes no API for; the dashboard is read once per visit, so nothing is cached.

/**
 * Renders the Recruitment dashboard.
 *
 * @phpstan-type UpcomingRow array{code: string, date: string, people: int}
 * @phpstan-type DashboardStats array{notices: array<string, int>, candidates: int, linked: int, awaiting: int, upcoming: int, upcoming_rows: list<UpcomingRow>, overdue: int, adjutancies: int, unattached: int, reasons: int}
 */
final class RecruitmentDashboard {

	/** Days ahead the presentations are counted over, today included. */
	public const UPCOMING_DAYS = 7;

	/** Presentation rows listed in the side column. */
	private const UPCOMING_ROWS = 5;

	/**
	 * Render the dashboard.
	 *
	 * @return void
	 */
	public static function render(): void {
		$stats    = self::stats( (string) wp_date( 'Y-m-d' ) );
		$can_edit = \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_recruitment' );
		$reasons  = RecruitmentAdminPage::can_view_reasons();
		$settings = RecruitmentAdminPage::can_view_settings();

		include FFC_PLUGIN_DIR . 'templates/admin/recruitment/admin-page/dashboard.php';

		if ( $can_edit ) {
			RecruitmentAdminPageRenderer::render_create_notice_form();
		}
	}

	/**
	 * The dashboard figures.
	 *
	 * The presentation counts read the active calls — not cancelled, on a
	 * classification still `called` or `accepted` — by their wall-clock
	 * `date_to_assume` (CLAUDE.md "Date / time storage", category B).
	 *
	 * @param string $today Today in the site timezone, `Y-m-d`.
	 * @return array<string, mixed>
	 * @phpstan-return DashboardStats
	 */
	public static function stats( string $today ): array {
		global $wpdb;

		$notice_table    = RecruitmentNoticeReader::get_table_name();
		$candidate_table = RecruitmentCandidateReader::get_table_name();
		$class_table     = RecruitmentClassificationRepository::get_table_name();
		$call_table      = RecruitmentCallReader::get_table_name();
		$adj_table       = RecruitmentAdjutancyReader::get_table_name();
		$link_table      = RecruitmentNoticeAdjutancyRepository::get_table_name();
		$reason_table    = RecruitmentReasonReader::get_table_name();

		$notices = array_fill_keys( array( 'draft', 'preliminary', 'definitive', 'closed' ), 0 );
		/**
		 * One row per status, the count as the driver returns it.
		 *
		 * @var list<array{status: string, n: numeric-string}>|null $rows
		 */
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS n FROM %i GROUP BY status', $notice_table ), ARRAY_A );
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$status = $row['status'];
			if ( isset( $notices[ $status ] ) ) {
				$notices[ $status ] = (int) $row['n'];
			}
		}

		/**
		 * Both counts as the driver returns them.
		 *
		 * @var array{total: numeric-string, linked: numeric-string}|null $candidates
		 */
		$candidates = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) AS total, COUNT(user_id) AS linked FROM %i', $candidate_table ), ARRAY_A );

		$until = gmdate( 'Y-m-d', (int) strtotime( $today . ' +' . ( self::UPCOMING_DAYS - 1 ) . ' days' ) );
		/**
		 * One row per notice and day.
		 *
		 * @var list<array{code: string, date: string, people: numeric-string}>|null $upcoming
		 */
		$upcoming       = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT n.code AS code, c.date_to_assume AS date, COUNT(*) AS people
				FROM %i c
				INNER JOIN %i cl ON cl.id = c.classification_id
				INNER JOIN %i n ON n.id = cl.notice_id
				WHERE c.cancelled_at IS NULL AND cl.status IN ('called', 'accepted') AND c.date_to_assume BETWEEN %s AND %s
				GROUP BY n.code, c.date_to_assume
				ORDER BY c.date_to_assume ASC, n.code ASC",
				$call_table,
				$class_table,
				$notice_table,
				$today,
				$until
			),
			ARRAY_A
		);
		$upcoming_rows  = array();
		$upcoming_total = 0;
		foreach ( is_array( $upcoming ) ? $upcoming : array() as $row ) {
			$people          = (int) $row['people'];
			$upcoming_total += $people;
			if ( count( $upcoming_rows ) < self::UPCOMING_ROWS ) {
				$upcoming_rows[] = array(
					'code'   => $row['code'],
					'date'   => $row['date'],
					'people' => $people,
				);
			}
		}

		$overdue = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT cl.id) FROM %i c
				INNER JOIN %i cl ON cl.id = c.classification_id
				WHERE c.cancelled_at IS NULL AND cl.status = 'called' AND c.date_to_assume < %s",
				$call_table,
				$class_table,
				$today
			)
		);

		return array(
			'notices'       => $notices,
			'candidates'    => is_array( $candidates ) ? (int) $candidates['total'] : 0,
			'linked'        => is_array( $candidates ) ? (int) $candidates['linked'] : 0,
			'awaiting'      => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status = 'called'", $class_table ) ),
			'upcoming'      => $upcoming_total,
			'upcoming_rows' => $upcoming_rows,
			'overdue'       => (int) $overdue,
			'adjutancies'   => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $adj_table ) ),
			'unattached'    => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i a WHERE NOT EXISTS (SELECT 1 FROM %i l WHERE l.adjutancy_id = a.id)', $adj_table, $link_table ) ),
			'reasons'       => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $reason_table ) ),
		);
	}
}
