<?php
/**
 * DeviceLimiter — device-fingerprint rate-limit strategy (#563 Sprint 4, PR 4b).
 *
 * The two-tier "same device" matcher (threshold of matching signals + a
 * minimum of corroborating STRONG signals), plus the per-form effective
 * settings resolver and the signal-row writer. Extracted verbatim from
 * RateLimitChecker::check_device_limit() / get_device_effective_settings() /
 * record_device_signals().
 *
 * Unlike the IP/email/CPF limiters it is keyed by (form_id, signals[]) rather
 * than a single string identifier, so it does not implement RateLimitStrategy.
 * Shared settings come from the injected {@see RateLimitSupport}; the
 * STRONG_SIGNALS / WEAK_SIGNALS registry stays on {@see RateLimitChecker}.
 *
 * @package FreeFormCertificate\Security
 */

declare(strict_types=1);

namespace FreeFormCertificate\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Device-fingerprint rate limit.
 */
class DeviceLimiter {

	/**
	 * Shared support (settings + message formatter).
	 *
	 * @var RateLimitSupport
	 */
	private RateLimitSupport $support;

	/**
	 * Constructor.
	 *
	 * @param RateLimitSupport $support Injected shared support.
	 */
	public function __construct( RateLimitSupport $support ) {
		$this->support = $support;
	}

	/**
	 * Check the device fingerprint limit for a single submission.
	 *
	 * Treats two submissions as "same device" when:
	 *   (a) their cookie hash matches, OR
	 *   (b) (two-tier) at least <threshold> non-cookie signals match AND
	 *       at least <strong_min> of those are STRONG_SIGNALS.
	 *
	 * The strong tier exists because weak signals (ua/screen/tz/…) are
	 * identical across whole fleets of same-model devices, so matching the
	 * threshold on weak signals alone produced mass false positives. When
	 * the incoming submission carries fewer strong signals than
	 * <strong_min> the fuzzy tier cannot be corroborated, so we fall back
	 * to the cookie path only (lenient) and never block on weak signals
	 * alone — a near-miss is recorded in the log for operator visibility.
	 *
	 * Counts how many prior submissions of this form match the incoming
	 * signal set; blocks if the count reaches the configured maximum.
	 *
	 * @param int                   $form_id Form ID (post ID).
	 * @param array<string, string> $signals Map of signal name -> SHA-256 hex hash.
	 * @return array{allowed: bool, message?: string, reason?: string, wait_seconds?: int}
	 */
	public function check( int $form_id, array $signals ): array {
		global $wpdb;

		$d = $this->support->settings()['device'];
		if ( empty( $d['enabled'] ) ) {
			return array( 'allowed' => true );
		}

		$enabled_signals = is_array( $d['signals_enabled'] ) ? array_map( 'strval', $d['signals_enabled'] ) : array();
		$signals         = array_filter(
			$signals,
			static function ( $v, $k ) use ( $enabled_signals ) {
				return '' !== $v && in_array( $k, $enabled_signals, true );
			},
			ARRAY_FILTER_USE_BOTH
		);

		if ( empty( $signals ) ) {
			return array( 'allowed' => true );
		}

		$cookie = $signals['cookie'] ?? null;

		// Whitelisted cookie hashes bypass the limit entirely.
		if ( $cookie && in_array( $cookie, (array) $d['bypass_whitelist_signals'], true ) ) {
			return array( 'allowed' => true );
		}

		$effective = $this->get_effective_settings( $form_id );

		$signal_keys   = array( 'ua', 'screen', 'tz', 'concurrency', 'memory', 'canvas', 'audio', 'webgl', 'fonts', 'plugins', 'permissions', 'mediaqueries', 'math' );
		$sum_parts     = array();
		$values        = array();
		$strong_parts  = array();
		$strong_values = array();
		foreach ( $signal_keys as $k ) {
			if ( ! empty( $signals[ $k ] ) ) {
				$col         = 'sig_' . $k;
				$sum_parts[] = "({$col} = %s)";
				$values[]    = $signals[ $k ];
				if ( in_array( $k, RateLimitChecker::STRONG_SIGNALS, true ) ) {
					$strong_parts[]  = "({$col} = %s)";
					$strong_values[] = $signals[ $k ];
				}
			}
		}

		$threshold  = $effective['threshold'];
		$strong_min = $effective['strong_min'];
		$max        = $effective['max'];
		$table      = $wpdb->prefix . 'ffc_device_signals';

		$count = 0;
		if ( $cookie && empty( $sum_parts ) ) {
			// Cookie-only fallback: count distinct submission rows with matching cookie.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE form_id = %d AND sig_cookie = %s', $table, $form_id, $cookie ) );
		} elseif ( ! empty( $sum_parts ) ) {
			$sum_sql         = implode( ' + ', $sum_parts );
			$fuzzy_blockable = ( 0 === $strong_min ) || ( count( $strong_parts ) >= $strong_min );

			if ( $fuzzy_blockable ) {
				// Two-tier fuzzy condition: total threshold, plus (when the
				// strong tier is active) a minimum of corroborating strong
				// signals so weak-only matches can no longer block.
				$fuzzy_sql  = "( {$sum_sql} ) >= %d";
				$fuzzy_args = array_merge( $values, array( $threshold ) );
				if ( $strong_min > 0 ) {
					$strong_sql = implode( ' + ', $strong_parts );
					$fuzzy_sql .= " AND ( {$strong_sql} ) >= %d";
					$fuzzy_args = array_merge( $values, array( $threshold ), $strong_values, array( $strong_min ) );
				}
				if ( $cookie ) {
					$where_sql = "form_id = %d AND ( sig_cookie = %s OR ( {$fuzzy_sql} ) )";
					$args      = array_merge( array( $table, $form_id, $cookie ), $fuzzy_args );
				} else {
					$where_sql = "form_id = %d AND ( {$fuzzy_sql} )";
					$args      = array_merge( array( $table, $form_id ), $fuzzy_args );
				}
				$sql = "SELECT COUNT(*) FROM %i WHERE {$where_sql}";
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
				$count = (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
			} else {
				// Lenient: too few strong signals present to corroborate a
				// fuzzy match. Rely on the cookie path only; never block on
				// weak signals alone.
				if ( $cookie ) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE form_id = %d AND sig_cookie = %s', $table, $form_id, $cookie ) );
				}
				// Visibility guard: weak signals alone would have crossed the
				// total threshold (a near-miss the legacy 1-tier logic would
				// have blocked). Record it so operators can spot low-entropy
				// devices / strong-signal evasion in the rate-limit log.
				if ( ! empty( $d['log_blocks'] ) && count( $sum_parts ) >= $threshold ) {
					// $signals is a non-empty array of non-empty-string hashes
					// here, so reset() always yields a usable identifier.
					$d_id = $cookie ?? reset( $signals );
					// Action 'suppressed' (not 'allowed') so it bypasses the
					// log_allowed gate and is recorded whenever logging is on —
					// this near-miss is the diagnostic signal operators need.
					RateLimitLogger::log_attempt( 'device', (string) $d_id, 'suppressed', 'strong_signals_insufficient', $form_id );
				}
			}
		}

		if ( $count >= $max ) {
			return array(
				'allowed'      => false,
				'reason'       => 'device_limit',
				'message'      => $effective['message'],
				'wait_seconds' => 0,
			);
		}

		return array( 'allowed' => true );
	}

	/**
	 * Resolve the per-form effective settings for the device limit, applying
	 * post-meta overrides on top of the global device.* values.
	 *
	 * @param int $form_id Form post ID.
	 * @return array{max: int, threshold: int, strong_min: int, message: string}
	 */
	public function get_effective_settings( int $form_id ): array {
		$d           = $this->support->settings()['device'];
		$max_meta    = get_post_meta( $form_id, '_ffc_device_limit_max', true );
		$thr_meta    = get_post_meta( $form_id, '_ffc_device_match_threshold', true );
		$strong_meta = get_post_meta( $form_id, '_ffc_device_strong_min', true );
		$msg_meta    = get_post_meta( $form_id, '_ffc_device_limit_message', true );

		$strong_cap    = count( RateLimitChecker::STRONG_SIGNALS );
		$global_strong = isset( $d['match_strong_min'] ) ? (int) $d['match_strong_min'] : 2;

		$max    = ( '' !== $max_meta ) ? max( 1, (int) $max_meta ) : (int) $d['max_per_form'];
		$thr    = ( '' !== $thr_meta ) ? max( 3, min( 12, (int) $thr_meta ) ) : (int) $d['match_threshold'];
		$strong = ( '' !== $strong_meta )
			? max( 0, min( $strong_cap, (int) $strong_meta ) )
			: max( 0, min( $strong_cap, $global_strong ) );
		$msg    = ( is_string( $msg_meta ) && '' !== $msg_meta ) ? $msg_meta : (string) $d['message'];

		return array(
			'max'        => $max,
			'threshold'  => $thr,
			'strong_min' => $strong,
			'message'    => $msg,
		);
	}

	/**
	 * Persist the collected device signal hashes for a successful submission.
	 *
	 * Called from the ffcertificate_after_submission_save hook so that the
	 * submission_id FK is already known. No-op if the device subsystem is
	 * disabled.
	 *
	 * @param int|null              $submission_id Submission row id (FK).
	 * @param int                   $form_id       Form post ID.
	 * @param array<string, string> $signals       Signal hashes.
	 */
	public function record_signals( ?int $submission_id, int $form_id, array $signals ): void {
		global $wpdb;
		$d = $this->support->settings()['device'];
		if ( empty( $d['enabled'] ) ) {
			return;
		}
		$enabled = is_array( $d['signals_enabled'] ) ? array_map( 'strval', $d['signals_enabled'] ) : array();
		$row     = array(
			'submission_id' => $submission_id,
			'form_id'       => $form_id,
		);
		$has_any = false;
		foreach ( array( 'cookie', 'ua', 'screen', 'tz', 'concurrency', 'memory', 'canvas', 'audio', 'webgl', 'fonts', 'plugins', 'permissions', 'mediaqueries', 'math' ) as $k ) {
			if ( ! empty( $signals[ $k ] ) && in_array( $k, $enabled, true ) ) {
				$row[ 'sig_' . $k ] = substr( (string) $signals[ $k ], 0, 64 );
				$has_any            = true;
			} else {
				$row[ 'sig_' . $k ] = null;
			}
		}
		if ( ! $has_any ) {
			return;
		}
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert( $wpdb->prefix . 'ffc_device_signals', $row );
	}
}
