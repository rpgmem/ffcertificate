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

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Every statement in this class runs against one of the plugin's own ffc_* tables, which WordPress exposes no API for. Caching is decided per read at the repository layer, not per statement (#1042).
/**
 * Rate Limit Repository (persistence helpers).
 */
final class RateLimitRepository {

	/**
	 * Days each submission-count period looks back over.
	 *
	 * @var array{day: int, week: int, month: int, year: int}
	 */
	public const SUBMISSION_WINDOW_DAYS = array(
		'day'   => 1,
		'week'  => 7,
		'month' => 30,
		'year'  => 365,
	);

	/**
	 * Identifier types whose value is a person's e-mail or document, stored
	 * as a keyed hash (#2343).
	 */
	public const KEYED_TYPES = array( 'email', 'cpf' );

	/**
	 * Storage key for a rate-limit identifier, on the `ffc_rate_limits`
	 * counter table and the `ffc_rate_limit_logs` log alike. A row only needs
	 * a stable equality key, so nothing is stored in the clear:
	 *
	 * - an address becomes its fingerprint, which counts an IPv6 caller by
	 *   its /64 and is keyed with the plugin's hash secret (#2109);
	 * - an e-mail or a CPF/RF its keyed hash (#2343). An unkeyed sha256 of a
	 *   CPF is reversed by trying every CPF, about a billion of them; the key
	 *   lives in `wp-config.php` and never in the database;
	 * - anything else its sha256 hash (#839).
	 *
	 * Applied on the read and write sides alike so lookups stay consistent; a
	 * row written under an older key simply misses and lapses after its
	 * window (a one-time reset at most), and the "Rate Limit: Keyed
	 * Identifiers" migration deletes the older e-mail and CPF rows.
	 *
	 * @param string $type       Identifier type ('ip' | 'email' | 'cpf' | …).
	 * @param string $identifier Raw identifier.
	 * @return string
	 */
	public static function storage_identifier( string $type, string $identifier ): string {
		if ( in_array( $type, array( 'ip', 'blacklist', 'whitelist' ), true ) ) {
			return \FreeFormCertificate\Core\IpAddress::fingerprint( $identifier );
		}
		if ( in_array( $type, self::KEYED_TYPES, true ) ) {
			return \FreeFormCertificate\Core\Encryption::keyed_hash( $identifier, 'rate_limit_' . $type );
		}
		return hash( 'sha256', $identifier );
	}

	/**
	 * Delete the counters and the log lines kept for a person's identifiers,
	 * for an erasure request (#2343).
	 *
	 * Both tables store an identifier only as {@see self::storage_identifier()}
	 * makes it, which {@see RateLimitLogger::log_attempt()} mirrors, so the
	 * raw values are turned into that key here and never reach a statement.
	 * Each value is tried as given and as the limiter receives it (an address
	 * lowercased, a CPF/RF as digits), because the limiter hashes what the
	 * submission carried and the caller may hold a different spelling.
	 *
	 * @param string             $type   Identifier type, 'email' or 'cpf'.
	 * @param array<int, string> $values Raw identifiers.
	 * @return int Rows deleted across both tables.
	 */
	public static function erase_identifiers( string $type, array $values ): int {
		global $wpdb;

		$keys = array();
		foreach ( $values as $value ) {
			$value = trim( $value );
			if ( '' === $value ) {
				continue;
			}
			$variants = array(
				$value,
				'email' === $type
					? \FreeFormCertificate\Core\DataSanitizer::normalize_email( $value )
					: \FreeFormCertificate\Core\DataSanitizer::normalize_cpf_rf( $value ),
			);
			foreach ( $variants as $variant ) {
				if ( '' !== $variant ) {
					$keys[ self::storage_identifier( $type, $variant ) ] = true;
				}
			}
		}
		if ( array() === $keys ) {
			return 0;
		}

		$deleted = 0;
		foreach ( array( 'ffc_rate_limits', 'ffc_rate_limit_logs' ) as $table ) {
			foreach ( array_keys( $keys ) as $key ) {
				$rows     = $wpdb->delete(
					$wpdb->prefix . $table,
					array(
						'type'       => $type,
						'identifier' => $key,
					),
					array( '%s', '%s' )
				);
				$deleted += is_int( $rows ) ? $rows : 0;
			}
		}

		return $deleted;
	}

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
		$identifier  = self::storage_identifier( $type, $identifier );
		$t           = $wpdb->prefix . 'ffc_rate_limits';
		$ws          = self::get_window_start( $window );
		$form_clause = $form_id ? $wpdb->prepare( 'AND form_id = %d', $form_id ) : 'AND form_id IS NULL';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $form_clause is pre-prepared via $wpdb->prepare().
		$c = $wpdb->get_var( $wpdb->prepare( "SELECT count FROM %i WHERE type=%s AND identifier=%s AND window_type=%s $form_clause AND window_start>=%s ORDER BY id DESC LIMIT 1", $t, $type, $identifier, $window, $ws ) );
		return $c ? intval( $c ) : 0;
	}

	/**
	 * The attempts counted over the last `$hours` clock hours, this one
	 * included (#2339): "block after N attempts in X hours" sums the hour
	 * rows, where reading only the current hour's row reset the count on
	 * every hour boundary whatever X said.
	 *
	 * @param string   $type       Type.
	 * @param string   $identifier Identifier.
	 * @param int      $hours      Hours, at least 1.
	 * @param int|null $form_id    Form ID.
	 * @return int
	 */
	public static function get_hourly_count_since( string $type, string $identifier, int $hours, ?int $form_id ): int {
		global $wpdb;
		$identifier  = self::storage_identifier( $type, $identifier );
		$t           = $wpdb->prefix . 'ffc_rate_limits';
		$since       = gmdate( 'Y-m-d H:00:00', time() - ( max( 1, $hours ) - 1 ) * HOUR_IN_SECONDS );
		$form_clause = $form_id ? $wpdb->prepare( 'AND form_id = %d', $form_id ) : 'AND form_id IS NULL';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $form_clause is pre-prepared via $wpdb->prepare().
		$c = $wpdb->get_var( $wpdb->prepare( "SELECT SUM(count) FROM %i WHERE type=%s AND identifier=%s AND window_type='hour' AND is_blocked=0 $form_clause AND window_start>=%s", $t, $type, $identifier, $since ) );
		return $c ? (int) $c : 0;
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
		$identifier = self::storage_identifier( $type, $identifier );
		$t          = $wpdb->prefix . 'ffc_rate_limits';
		$ws         = self::get_window_start( $window );
		$we         = self::get_window_end( $window );

		$form_clause = $form_id ? $wpdb->prepare( 'AND form_id = %d', $form_id ) : 'AND form_id IS NULL';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $form_clause is pre-prepared via $wpdb->prepare().
		$e = $wpdb->get_row( $wpdb->prepare( "SELECT id,count FROM %i WHERE type=%s AND identifier=%s AND window_type=%s $form_clause AND window_start=%s", $t, $type, $identifier, $window, $ws ) );

		if ( $e ) {
			// The increment happens in the database (#2339): adding one to the
			// count read above lost every hit that landed between the read and
			// the write. The first hit of a window still reads, then inserts;
			// two of those racing can write two rows when `form_id` is NULL,
			// which the unique key does not cover, and the read then takes the
			// newest row, so the loss is at most the first hits of a window.
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET count = count + 1, last_attempt = %s WHERE id = %d',
					$t,
					gmdate( 'Y-m-d H:i:s' ),
					(int) $e->id
				)
			);
		} else {
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
					'last_attempt' => gmdate( 'Y-m-d H:i:s' ),
				),
				array( '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s' )
			);
		}
	}

	/**
	 * Get submission count.
	 *
	 * Every period has its own window. `year` used to fall through to the
	 * 30-day one, so the per-CPF yearly limit counted the same month the
	 * monthly limit did and never blocked anything the monthly one had not
	 * (#2156). The period is typed to the four keys, so PHPStan refuses an
	 * unknown one at the call site instead of it being read as a month.
	 *
	 * @param string                      $field Field definition.
	 * @param string                      $value Value.
	 * @param 'day'|'week'|'month'|'year' $period Period.
	 * @param int|null                    $form_id Form ID.
	 * @return int
	 */
	public static function get_submission_count( string $field, string $value, string $period, ?int $form_id ): int {
		return (int) self::submission_aggregate( 'COUNT(*)', $field, $value, self::SUBMISSION_WINDOW_DAYS[ $period ], $form_id );
	}

	/**
	 * When the oldest submission a period limit counts was made, so the
	 * refusal can say when it stops counting (#2339): with the count at the
	 * limit, that is when the next submission is allowed.
	 *
	 * @param string                      $field   'email' or 'cpf'.
	 * @param string                      $value   Value as typed.
	 * @param 'day'|'week'|'month'|'year' $period  Period.
	 * @param int|null                    $form_id Form ID.
	 * @return int|null Unix time, or null when nothing is counted.
	 */
	public static function oldest_submission_in( string $field, string $value, string $period, ?int $form_id ): ?int {
		$oldest = self::submission_aggregate( 'MIN(submission_date)', $field, $value, self::SUBMISSION_WINDOW_DAYS[ $period ], $form_id );
		return null === $oldest || '' === $oldest ? null : (int) $oldest;
	}

	/**
	 * When the latest submission with this identifier was made, at any age:
	 * the reference of the minimum interval between two submissions (#2339).
	 *
	 * @param string   $field   'email' or 'cpf'.
	 * @param string   $value   Value as typed.
	 * @param int|null $form_id Form ID.
	 * @return int|null Unix time, or null when there is none.
	 */
	public static function latest_submission( string $field, string $value, ?int $form_id ): ?int {
		$latest = self::submission_aggregate( 'MAX(submission_date)', $field, $value, 0, $form_id );
		return null === $latest || '' === $latest ? null : (int) $latest;
	}

	/**
	 * One aggregate over the submissions carrying this identifier.
	 *
	 * The value arrives as the visitor typed it, and the hash it is compared
	 * against was written from the CANONICAL form (#1314): both branches
	 * canonicalise first, through the registry, the single hash boundary
	 * (#1313). `submission_date` is a unix instant since 6.6.0 (#249), so the
	 * window is one too (#2339).
	 *
	 * @param string   $aggregate One of the three aggregates above.
	 * @param string   $field     'email' or 'cpf'.
	 * @param string   $value     Value as typed.
	 * @param int      $days      Window in days; 0 for no window.
	 * @param int|null $form_id   Form ID.
	 * @return string|null The aggregate as the database returns it.
	 */
	private static function submission_aggregate( string $aggregate, string $field, string $value, int $days, ?int $form_id ): ?string {
		global $wpdb;
		$t  = $wpdb->prefix . 'ffc_submissions';
		$dw = $days > 0 ? sprintf( 'AND submission_date >= %d', time() - $days * DAY_IN_SECONDS ) : '';
		$fw = $form_id ? $wpdb->prepare( 'AND form_id=%d', $form_id ) : '';

		if ( 'email' === $field ) {
			$hash   = class_exists( '\FreeFormCertificate\Core\Encryption' ) && \FreeFormCertificate\Core\Encryption::is_configured()
				? \FreeFormCertificate\Core\SensitiveFieldRegistry::hash_identifier( 'email', $value )
				: hash( 'sha256', \FreeFormCertificate\Core\DataSanitizer::normalize_email( $value ) );
			$column = 'email_hash';
		} elseif ( 'cpf' === $field && class_exists( '\FreeFormCertificate\Core\Encryption' ) && \FreeFormCertificate\Core\Encryption::is_configured() ) {
			$hash = \FreeFormCertificate\Core\SensitiveFieldRegistry::hash_identifier( 'cpf', $value );
			// Search the split column the digit count names.
			$column = 'rf' === \FreeFormCertificate\Core\DataSanitizer::classify_cpf_rf( $value ) ? 'rf_hash' : 'cpf_hash';
		} else {
			return null;
		}

		if ( null === $hash ) {
			return null;
		}

		$aggregate = in_array( $aggregate, array( 'COUNT(*)', 'MIN(submission_date)', 'MAX(submission_date)' ), true ) ? $aggregate : 'COUNT(*)';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $aggregate is from a fixed list, $column from two literals, $dw an integer clause and $fw pre-prepared.
		$result = $wpdb->get_var( $wpdb->prepare( "SELECT {$aggregate} FROM %i WHERE {$column}=%s $dw $fw", $t, $hash ) );

		return null === $result ? null : (string) $result;
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
		return null !== self::blocked_until( $type, $identifier, $form_id );
	}

	/**
	 * When a temporary block on this identifier ends, so the refusal can say
	 * how long is left of it (#2339).
	 *
	 * @param string   $type       Type.
	 * @param string   $identifier Identifier.
	 * @param int|null $form_id    Form ID.
	 * @return int|null Unix time, or null when it is not blocked.
	 */
	public static function blocked_until( string $type, string $identifier, ?int $form_id ): ?int {
		global $wpdb;
		$identifier  = self::storage_identifier( $type, $identifier );
		$t           = $wpdb->prefix . 'ffc_rate_limits';
		$form_clause = $form_id ? $wpdb->prepare( 'AND form_id = %d', $form_id ) : 'AND form_id IS NULL';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Pre-validated clauses from trusted internal logic.
		$until = $wpdb->get_var( $wpdb->prepare( "SELECT blocked_until FROM %i WHERE type=%s AND identifier=%s $form_clause AND is_blocked=1 AND blocked_until>%s ORDER BY id DESC LIMIT 1", $t, $type, $identifier, gmdate( 'Y-m-d H:i:s' ) ) );
		if ( empty( $until ) ) {
			return null;
		}

		// Written by gmdate(), so read back as UTC.
		$ts = strtotime( (string) $until . ' UTC' );
		return false === $ts ? null : $ts;
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
		$identifier     = self::storage_identifier( $type, $identifier );
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
				'window_start'   => gmdate( 'Y-m-d H:i:s' ),
				'window_end'     => gmdate( 'Y-m-d H:i:s', $blocked_ts ),
				'last_attempt'   => gmdate( 'Y-m-d H:i:s' ),
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
