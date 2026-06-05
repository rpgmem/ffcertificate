<?php
/**
 * RateLimitRepository
 *
 * Persistence layer for {@see RateLimitChecker}: the raw $wpdb counter
 * reads/writes against `ffc_rate_limits` + `ffc_submissions`, the temporary
 * block read/write, and the window start/end helpers. Split out of
 * RateLimitChecker in the frontend-audit Item 3 fragmentation — pure code
 * movement, no behavior change. The checker is the public facade; this class
 * holds no business logic or config.
 *
 * @package FreeFormCertificate\Security
 */

declare(strict_types=1);

namespace FreeFormCertificate\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rate Limit Repository (persistence helpers).
 */
final class RateLimitRepository {

	/**
	 * Get count from db.
	 *
	 * @param string   $type Type.
	 * @param string   $identifier Identifier.
	 * @param string   $window Window.
	 * @param int|null $form_id Form ID.
	 * @return int
	 */
	public static function get_count_from_db( string $type, string $identifier, string $window, ?int $form_id ): int {
		global $wpdb;
		$t           = $wpdb->prefix . 'ffc_rate_limits';
		$ws          = self::get_window_start( $window );
		$form_clause = $form_id ? $wpdb->prepare( 'AND form_id = %d', $form_id ) : 'AND form_id IS NULL';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $form_clause is pre-prepared via $wpdb->prepare().
		$c = $wpdb->get_var( $wpdb->prepare( "SELECT count FROM %i WHERE type=%s AND identifier=%s AND window_type=%s $form_clause AND window_start>=%s ORDER BY id DESC LIMIT 1", $t, $type, $identifier, $window, $ws ) );
		return $c ? intval( $c ) : 0;
	}

	/**
	 * Increment counter.
	 *
	 * @param string   $type Type.
	 * @param string   $identifier Identifier.
	 * @param string   $window Window.
	 * @param int|null $form_id Form ID.
	 */
	public static function increment_counter( string $type, string $identifier, string $window, ?int $form_id ): void {
		global $wpdb;
		$t  = $wpdb->prefix . 'ffc_rate_limits';
		$ws = self::get_window_start( $window );
		$we = self::get_window_end( $window );

		$form_clause = $form_id ? $wpdb->prepare( 'AND form_id = %d', $form_id ) : 'AND form_id IS NULL';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $form_clause is pre-prepared via $wpdb->prepare().
		$e = $wpdb->get_row( $wpdb->prepare( "SELECT id,count FROM %i WHERE type=%s AND identifier=%s AND window_type=%s $form_clause AND window_start=%s", $t, $type, $identifier, $window, $ws ) );

		if ( $e ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$t,
				array(
					'count'        => $e->count + 1,
					'last_attempt' => current_time( 'mysql' ),
				),
				array( 'id' => $e->id ),
				array( '%d', '%s' ),
				array( '%d' )
			);
		} else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert(
				$t,
				array(
					'type'         => $type,
					'identifier'   => $identifier,
					'form_id'      => $form_id,
					'count'        => 1,
					'window_type'  => $window,
					'window_start' => $ws,
					'window_end'   => $we,
					'last_attempt' => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s' )
			);
		}
	}

	/**
	 * Get submission count.
	 *
	 * @param string   $field Field definition.
	 * @param string   $value Value.
	 * @param string   $period Period.
	 * @param int|null $form_id Form ID.
	 * @return int
	 */
	public static function get_submission_count( string $field, string $value, string $period, ?int $form_id ): int {
		global $wpdb;
		$t  = $wpdb->prefix . 'ffc_submissions';
		$dw = 'day' === $period ? 'AND submission_date >= DATE_SUB(NOW(), INTERVAL 1 DAY)' : ( 'week' === $period ? 'AND submission_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)' : 'AND submission_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)' );
		$fw = $form_id ? $wpdb->prepare( 'AND form_id=%d', $form_id ) : '';

		if ( 'email' === $field ) {
			$email_hash = class_exists( '\FreeFormCertificate\Core\Encryption' ) && \FreeFormCertificate\Core\Encryption::is_configured()
				? \FreeFormCertificate\Core\Encryption::hash( $value )
				: hash( 'sha256', strtolower( trim( $value ) ) );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $dw and $fw are pre-validated date window and form clauses.
			return intval( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE email_hash=%s $dw $fw", $t, $email_hash ) ) );
		} elseif ( 'cpf' === $field ) {
			if ( class_exists( '\FreeFormCertificate\Core\Encryption' ) && \FreeFormCertificate\Core\Encryption::is_configured() ) {
				$h  = \FreeFormCertificate\Core\Encryption::hash( $value );
				$hc = strlen( \FreeFormCertificate\Core\DataSanitizer::normalize_cpf_rf( $value ) ) === 7 ? 'rf_hash' : 'cpf_hash';
				// Search the specific split column based on digit count.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Pre-validated clauses from trusted internal logic.
				return intval( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE {$hc}=%s $dw $fw", $t, $h ) ) );
			}
		}

		return 0;
	}

	/**
	 * Check whether temporarily blocked.
	 *
	 * @param string   $type Type.
	 * @param string   $identifier Identifier.
	 * @param int|null $form_id Form ID.
	 * @return bool
	 */
	public static function is_temporarily_blocked( string $type, string $identifier, ?int $form_id ): bool {
		global $wpdb;
		$t           = $wpdb->prefix . 'ffc_rate_limits';
		$form_clause = $form_id ? $wpdb->prepare( 'AND form_id = %d', $form_id ) : 'AND form_id IS NULL';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Pre-validated clauses from trusted internal logic.
		return ! empty( $wpdb->get_var( $wpdb->prepare( "SELECT blocked_until FROM %i WHERE type=%s AND identifier=%s $form_clause AND is_blocked=1 AND blocked_until>NOW() ORDER BY id DESC LIMIT 1", $t, $type, $identifier ) ) );
	}

	/**
	 * Block temporarily.
	 *
	 * @param string   $type Type.
	 * @param string   $identifier Identifier.
	 * @param int|null $form_id Form ID.
	 * @param int      $hours Hours.
	 */
	public static function block_temporarily( string $type, string $identifier, ?int $form_id, int $hours ): void {
		global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Pre-validated clauses from trusted internal logic.
		$blocked_ts_raw = strtotime( "+$hours hours" );
		$blocked_ts     = $blocked_ts_raw ? $blocked_ts_raw : time();
		$wpdb->insert(
			$wpdb->prefix . 'ffc_rate_limits',
			array(
				'type'           => $type,
				'identifier'     => $identifier,
				'form_id'        => $form_id,
				'count'          => 999,
				'window_type'    => 'hour',
				'window_start'   => current_time( 'mysql' ),
				'window_end'     => gmdate( 'Y-m-d H:i:s', $blocked_ts ),
				'last_attempt'   => current_time( 'mysql' ),
				'is_blocked'     => 1,
				'blocked_until'  => gmdate( 'Y-m-d H:i:s', $blocked_ts ),
				'blocked_reason' => 'abuse',
			),
			array( '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
	}

	/**
	 * Get window start.
	 *
	 * @param string $window Window.
	 * @return string
	 */
	public static function get_window_start( string $window ): string {
		switch ( $window ) {
			case 'minute':
				return gmdate( 'Y-m-d H:i:00' );
			case 'hour':
				return gmdate( 'Y-m-d H:00:00' );
			case 'day':
				return gmdate( 'Y-m-d 00:00:00' );
			case 'week':
				return gmdate( 'Y-m-d 00:00:00', strtotime( 'monday this week' ) );
			case 'month':
				return gmdate( 'Y-m-01 00:00:00' );
			case 'year':
				return gmdate( 'Y-01-01 00:00:00' );
			default:
				return gmdate( 'Y-m-d H:i:s' );
		}
	}

	/**
	 * Get window end.
	 *
	 * @param string $window Window.
	 * @return string
	 */
	public static function get_window_end( string $window ): string {
		switch ( $window ) {
			case 'minute':
				return gmdate( 'Y-m-d H:i:59' );
			case 'hour':
				return gmdate( 'Y-m-d H:59:59' );
			case 'day':
				return gmdate( 'Y-m-d 23:59:59' );
			case 'week':
				return gmdate( 'Y-m-d 23:59:59', strtotime( 'sunday this week' ) );
			case 'month':
				return gmdate( 'Y-m-t 23:59:59' );
			case 'year':
				return gmdate( 'Y-12-31 23:59:59' );
			default:
				return gmdate( 'Y-m-d H:i:s', strtotime( '+1 hour' ) );
		}
	}
}
