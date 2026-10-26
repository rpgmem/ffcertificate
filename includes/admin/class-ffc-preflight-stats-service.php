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

use FreeFormCertificate\Core\Icons;
use FreeFormCertificate\Frontend\PreflightTelemetry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Counts the pre-flight reasons (`PreflightTelemetry::ALLOWED_REASONS`)
 * per form over a rolling window. In-memory aggregation
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
	 * Per-form transient prefix (#1234).
	 *
	 * The key carries `form_id` AND the window in days, because both are part of
	 * the question: caching by form alone would return the 30-day count to
	 * whoever asked for 7.
	 *
	 * @var string
	 */
	private const STATS_CACHE_PREFIX = 'ffc_preflight_stats_';

	/**
	 * Lifetime of the transient above -- 5 minutes, the same as
	 * `SubmissionReader::COUNT_CACHE_TTL`, and for the same reason: it is a
	 * counter the admin reads on a screen, not a value that decides anything.
	 * The read costs up to 5,000 rows in memory plus one `json_decode` per row,
	 * so even 5 minutes eliminate almost all the repeated work.
	 *
	 * @var int
	 */
	private const STATS_CACHE_TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * Get aggregated pre-flight bail counts for one form.
	 *
	 * @param int $form_id Form post ID to filter.
	 * @param int $days    Rolling-window size in days (default 30).
	 * @return array<string, int> One count per reason, plus `total`.
	 */
	public static function get_form_stats( int $form_id, int $days = 30 ): array {
		$counts          = array_fill_keys( PreflightTelemetry::ALLOWED_REASONS, 0 );
		$counts['total'] = 0;

		if ( $form_id <= 0 ) {
			return $counts;
		}

		$cache_key = self::STATS_CACHE_PREFIX . $form_id . '_' . max( 1, $days );

		/**
		 * A transient is `mixed` by construction -- `is_array()` narrows the
		 * container, never the values. A cached entry written before a reason
		 * was added lacks its key, so the fresh shape is laid under it.
		 *
		 * @var array<string, int>|false $cached
		 */
		$cached = \get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return array_merge( $counts, $cached );
		}

		$date_from = gmdate( 'Y-m-d H:i:s', time() - max( 1, $days ) * DAY_IN_SECONDS );

		$rows = \FreeFormCertificate\Core\ActivityLogQuery::get_activities(
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

		\set_transient( $cache_key, $counts, self::STATS_CACHE_TTL );

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
			'admin.php?page=ffc-settings&tab=activity_log&log_action=preflight_blocked'
		);

		$badges = array(
			'cookies'                => array( 'cookie', 'cookies', __( 'cookie wall', 'ffcertificate' ), __( 'Open the Activity Log filtered by cookie-wall hits', 'ffcertificate' ) ),
			'gps_denied'             => array( 'map-pin', 'gps-denied', __( 'GPS denied', 'ffcertificate' ), __( 'Open the Activity Log filtered by GPS-denied hits', 'ffcertificate' ) ),
			'gps_prompt'             => array( 'clipboard', 'gps-prompt', __( 'GPS prompt', 'ffcertificate' ), __( 'Open the Activity Log filtered by GPS-prompt explainer renders', 'ffcertificate' ) ),
			'secure'                 => array( 'lock', 'secure', __( 'no HTTPS', 'ffcertificate' ), __( 'Open the Activity Log: visitors stopped because the page is not served over HTTPS', 'ffcertificate' ) ),
			'browser_inapp'          => array( 'phone', 'browser-inapp', __( 'app browser', 'ffcertificate' ), __( 'Open the Activity Log: visitors asked to leave an app\'s own browser', 'ffcertificate' ) ),
			'browser_inapp_continue' => array( 'skip', 'browser-inapp-continue', __( 'continued in app', 'ffcertificate' ), __( 'Open the Activity Log: visitors who chose "Continue anyway" in an app\'s browser', 'ffcertificate' ) ),
			'browser_features'       => array( 'monitor', 'browser-features', __( 'browser missing features', 'ffcertificate' ), __( 'Open the Activity Log: visitors whose browser lacks what the form needs', 'ffcertificate' ) ),
			'browser_boot'           => array( 'sync', 'browser-boot', __( 'form did not load', 'ffcertificate' ), __( 'Open the Activity Log: forms whose scripts did not load in time', 'ffcertificate' ) ),
		);

		?>
		<ul class="ffc-preflight-stats-badges">
			<?php
			foreach ( $badges as $reason => $badge ) :
				// The first three always show, as before; a newer reason only
				// when it happened, so a form without it keeps a short row.
				if ( ! in_array( $reason, array( 'cookies', 'gps_denied', 'gps_prompt' ), true ) && 0 === (int) ( $stats[ $reason ] ?? 0 ) ) {
					continue;
				}
				?>
			<li class="ffc-preflight-badge ffc-preflight-badge-<?php echo esc_attr( $badge[1] ); ?>">
				<a href="<?php echo esc_url( $activity_log_url ); ?>" title="<?php echo esc_attr( $badge[3] ); ?>">
					<span class="ffc-preflight-badge-icon"><?php echo Icons::svg( $badge[0], 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- constant icon markup. ?></span>
					<strong><?php echo esc_html( (string) ( $stats[ $reason ] ?? 0 ) ); ?></strong>
					<?php echo esc_html( $badge[2] ); ?>
				</a>
			</li>
			<?php endforeach; ?>
		</ul>
		<p class="description">
			<?php esc_html_e( 'Counts visitors who hit a pre-flight banner on this form (last 30 days). High numbers may indicate a setup issue — e.g. GPS denied dominating means visitors can\'t pass the geofence, cookie wall dominating means cached HTML is being served with wrong nonces.', 'ffcertificate' ); ?>
		</p>
		<?php
	}
}
