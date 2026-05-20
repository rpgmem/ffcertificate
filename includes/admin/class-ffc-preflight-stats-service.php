<?php
/**
 * Pre-flight Stats Service
 *
 * Aggregates ActivityLog rows tagged `preflight_blocked` into per-form
 * + per-reason counts over a configurable rolling window. Feeds the
 * form editor metabox badges added by #361 Sprint 3.
 *
 * @package FreeFormCertificate\Admin
 * @since   6.6.4
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

use FreeFormCertificate\Core\ActivityLog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Counts the three pre-flight reasons (cookies / gps_denied /
 * gps_prompt) per form over a rolling window. In-memory aggregation
 * — the context column is JSON, so filtering by form_id in SQL would
 * need JSON_EXTRACT (not portable across MySQL versions). Volumes
 * are bounded by the LIMIT cap below; admins with > 5k pre-flight
 * bails / 30 days have bigger problems than this counter.
 *
 * @since 6.6.4
 */
class PreflightStatsService {

	/**
	 * Defensive cap on how many ActivityLog rows we pull into memory
	 * per call. 5000 covers all but pathological form-volume cases;
	 * exceeding it just means the counts under-report (admin sees
	 * "lower bound" badges).
	 */
	private const MAX_ROWS = 5000;

	/**
	 * Get aggregated pre-flight bail counts for one form.
	 *
	 * @param int $form_id Form post ID to filter.
	 * @param int $days    Rolling-window size in days (default 30).
	 * @return array{cookies:int, gps_denied:int, gps_prompt:int, total:int}
	 */
	public static function get_form_stats( int $form_id, int $days = 30 ): array {
		$counts = array(
			'cookies'    => 0,
			'gps_denied' => 0,
			'gps_prompt' => 0,
			'total'      => 0,
		);

		if ( $form_id <= 0 ) {
			return $counts;
		}

		$date_from = gmdate( 'Y-m-d H:i:s', time() - max( 1, $days ) * DAY_IN_SECONDS );

		$rows = ActivityLog::get_activities(
			array(
				'action'    => 'preflight_blocked',
				'date_from' => $date_from,
				'limit'     => self::MAX_ROWS,
			)
		);

		foreach ( $rows as $row ) {
			$context = $row['context'] ?? null;
			if ( is_string( $context ) ) {
				$decoded = json_decode( $context, true );
				$context = is_array( $decoded ) ? $decoded : null;
			}
			if ( ! is_array( $context ) ) {
				continue;
			}
			if ( ! isset( $context['form_id'] ) || (int) $context['form_id'] !== $form_id ) {
				continue;
			}
			$reason = isset( $context['reason'] ) ? (string) $context['reason'] : '';
			if ( isset( $counts[ $reason ] ) ) {
				++$counts[ $reason ];
				++$counts['total'];
			}
		}

		return $counts;
	}

	/**
	 * Render the form-editor sidebar metabox with the badges row.
	 * Each badge links to the Activity Log filtered by `preflight_blocked`
	 * + the relevant reason, so admins can drill down into individual
	 * rows.
	 *
	 * @param int $form_id Form post ID.
	 */
	public static function render_metabox( int $form_id ): void {
		$stats = self::get_form_stats( $form_id, 30 );

		if ( 0 === $stats['total'] ) {
			echo '<p class="description">'
				. esc_html__( 'No pre-flight or rate-limit friction recorded in the last 30 days. Nice.', 'ffcertificate' )
				. '</p>';
			return;
		}

		$activity_log_url = admin_url(
			'admin.php?page=ffc-activity-log&log_action=preflight_blocked'
		);

		?>
		<ul class="ffc-preflight-stats-badges">
			<li class="ffc-preflight-badge ffc-preflight-badge-cookies">
				<a href="<?php echo esc_url( $activity_log_url ); ?>" title="<?php esc_attr_e( 'Open the Activity Log filtered by cookie-wall hits', 'ffcertificate' ); ?>">
					<span class="ffc-preflight-badge-icon" aria-hidden="true">🍪</span>
					<strong><?php echo esc_html( (string) $stats['cookies'] ); ?></strong>
					<?php esc_html_e( 'cookie wall', 'ffcertificate' ); ?>
				</a>
			</li>
			<li class="ffc-preflight-badge ffc-preflight-badge-gps-denied">
				<a href="<?php echo esc_url( $activity_log_url ); ?>" title="<?php esc_attr_e( 'Open the Activity Log filtered by GPS-denied hits', 'ffcertificate' ); ?>">
					<span class="ffc-preflight-badge-icon" aria-hidden="true">📍</span>
					<strong><?php echo esc_html( (string) $stats['gps_denied'] ); ?></strong>
					<?php esc_html_e( 'GPS denied', 'ffcertificate' ); ?>
				</a>
			</li>
			<li class="ffc-preflight-badge ffc-preflight-badge-gps-prompt">
				<a href="<?php echo esc_url( $activity_log_url ); ?>" title="<?php esc_attr_e( 'Open the Activity Log filtered by GPS-prompt explainer renders', 'ffcertificate' ); ?>">
					<span class="ffc-preflight-badge-icon" aria-hidden="true">📋</span>
					<strong><?php echo esc_html( (string) $stats['gps_prompt'] ); ?></strong>
					<?php esc_html_e( 'GPS prompt', 'ffcertificate' ); ?>
				</a>
			</li>
		</ul>
		<p class="description">
			<?php esc_html_e( 'Counts visitors who hit a pre-flight banner on this form (last 30 days). High numbers may indicate a setup issue — e.g. GPS denied dominating means visitors can\'t pass the geofence, cookie wall dominating means cached HTML is being served with wrong nonces.', 'ffcertificate' ); ?>
		</p>
		<?php
	}
}
