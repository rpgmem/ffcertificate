<?php
/**
 * RateLimitLogger
 *
 * Handles persistence and retention of rate-limit attempt logs. Extracted
 * from {@see RateLimiter} as part of the S4 god-object refactor — pure
 * code movement, no behavior changes.
 *
 * @package FreeFormCertificate\Security
 */

declare(strict_types=1);

namespace FreeFormCertificate\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Every statement in this class runs against one of the plugin's own ffc_* tables, which WordPress exposes no API for. Caching is decided per read at the repository layer, not per statement (#1042).
/**
 * Rate Limit Logger.
 */
final class RateLimitLogger {

	/**
	 * Log attempt.
	 *
	 * @param string   $type Type.
	 * @param string   $identifier Identifier.
	 * @param string   $action Action name.
	 * @param string   $reason Reason.
	 * @param int|null $form_id Form ID.
	 */
	public static function log_attempt( string $type, string $identifier, string $action, string $reason, ?int $form_id ): void {
		$s = RateLimitChecker::get_settings();
		if ( ! $s['logging']['enabled'] || ( ! $s['logging']['log_allowed'] && 'allowed' === $action ) ) {
			return;
		}

		// Hash identifier before storage for LGPD/GDPR compliance. IP types remain plaintext for operator troubleshooting.
		$stored_identifier = ( 'ip' === $type ) ? $identifier : hash( 'sha256', (string) $identifier );

		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'ffc_rate_limit_logs',
			array(
				'type'          => $type,
				'identifier'    => $stored_identifier,
				'form_id'       => $form_id,
				'action'        => $action,
				'reason'        => $reason,
				// Log the request IP through the same resolver the enforcement
				// path uses (`RequestInput::get_user_ip()` → the global
				// `ffc_ip_resolver_mode` toggle), so the logged IP matches the
				// bucket that was actually rate-limited (#931). For `ip`-type
				// rows this equals `$identifier`; for other types it records the
				// requester behind that identifier.
				'ip_address'    => \FreeFormCertificate\Core\RequestInput::get_user_ip(),
				'user_agent'    => substr( isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '', 0, 255 ),
				'current_count' => 0,
				'max_allowed'   => 0,
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%d' )
		);

		self::cleanup_old_logs();
	}

	/**
	 * Cleanup old logs.
	 */
	private static function cleanup_old_logs(): void {
		global $wpdb;
		$s = RateLimitChecker::get_settings();
		$t = $wpdb->prefix . 'ffc_rate_limit_logs';
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)', $t, $s['logging']['retention_days'] ) );
		$c = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $t ) );
		if ( $c > $s['logging']['max_logs'] ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id NOT IN (SELECT id FROM (SELECT id FROM %i ORDER BY id DESC LIMIT %d) tmp)', $t, $t, $s['logging']['max_logs'] ) );
		}
	}

	/**
	 * Cleanup expired.
	 *
	 * @return int
	 */
	public static function cleanup_expired(): int {
		global $wpdb;
		$deleted = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE window_end < NOW()', $wpdb->prefix . 'ffc_rate_limits' ) );

		$d = RateLimitChecker::get_settings()['device'];
		if ( ! empty( $d['retention_days'] ) ) {
			$retention = max( 1, (int) $d['retention_days'] );
			$deleted  += (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)', $wpdb->prefix . 'ffc_device_signals', $retention ) );
		}
		return $deleted;
	}
}
