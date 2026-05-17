<?php
/**
 * RateLimitChecker
 *
 * Encapsulates all rate-limit evaluation logic (IP, email, CPF, device,
 * global, verification and per-user) for the FreeForm Certificate plugin.
 * Extracted from {@see RateLimiter} as part of the S4 god-object refactor —
 * pure code movement, no behavior changes.
 *
 * @package FreeFormCertificate\Security
 */

declare(strict_types=1);

namespace FreeFormCertificate\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rate Limit Checker.
 */
final class RateLimitChecker {

	/**
	 * Cached settings for the current request
	 *
	 * @since 4.6.13
	 * @var array<string, mixed>|null
	 */
	private static ?array $settings_cache = null;

	/**
	 * Get settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_settings(): array {
		if ( null !== self::$settings_cache ) {
			return self::$settings_cache;
		}
		$defaults             = array(
			'ip'        => array(
				'enabled'          => true,
				'max_per_hour'     => 5,
				'max_per_day'      => 20,
				'cooldown_seconds' => 60,
				'apply_to'         => 'all',
				'message'          => __( 'Limit reached. Please wait {time}.', 'ffcertificate' ),
			),
			'email'     => array(
				'enabled'        => true,
				'max_per_day'    => 3,
				'max_per_week'   => 10,
				'max_per_month'  => 30,
				'wait_hours'     => 24,
				'apply_to'       => 'all',
				'message'        => __( 'You already have {count} certificates.', 'ffcertificate' ),
				'check_database' => true,
			),
			'cpf'       => array(
				'enabled'         => false,
				'max_per_month'   => 5,
				'max_per_year'    => 50,
				'block_threshold' => 3,
				'block_hours'     => 1,
				'block_duration'  => 24,
				'apply_to'        => 'all',
				'message'         => __( 'CPF/RF limit reached.', 'ffcertificate' ),
				'check_database'  => true,
			),
			'global'    => array(
				'enabled'        => false,
				'max_per_minute' => 100,
				'max_per_hour'   => 1000,
				'message'        => __( 'System unavailable.', 'ffcertificate' ),
			),
			'device'    => array(
				'enabled'                   => false,
				'max_per_form'              => 1,
				// 6.3.2: bumped from 5 to 7 to keep the same ~55% match-ratio
				// against the new 13-signal palette (was 5/9 ≈ 55%, now 7/13 ≈ 54%).
				// Existing installs keep their saved value; this default only
				// applies to fresh installs that have never persisted the array.
				'match_threshold'           => 7,
				'signals_enabled'           => array( 'cookie', 'ua', 'screen', 'tz', 'concurrency', 'memory', 'canvas', 'audio', 'webgl', 'fonts', 'plugins', 'permissions', 'mediaqueries', 'math' ),
				'bypass_logged_in_managers' => true,
				'bypass_whitelist_signals'  => array(),
				'message'                   => __( 'Multiple submissions detected from this device. Please contact the organizer.', 'ffcertificate' ),
				'retention_days'            => 90,
				'log_blocks'                => true,
			),
			'whitelist' => array(
				'ips'           => array(),
				'emails'        => array(),
				'email_domains' => array(),
				'cpfs'          => array(),
			),
			'blacklist' => array(
				'ips'           => array(),
				'emails'        => array(),
				'email_domains' => array(),
				'cpfs'          => array(),
			),
			'logging'   => array(
				'enabled'        => true,
				'log_allowed'    => false,
				'log_blocked'    => true,
				'retention_days' => 30,
				'max_logs'       => 10000,
			),
			'ui'        => array(
				'show_remaining'  => true,
				'show_wait_time'  => true,
				'countdown_timer' => true,
			),
		);
		self::$settings_cache = wp_parse_args( get_option( 'ffc_rate_limit_settings', $defaults ), $defaults );
		return self::$settings_cache;
	}

	/**
	 * Check all.
	 *
	 * @param string                     $ip IP address.
	 * @param string|null                $email Email address.
	 * @param string|null                $cpf CPF document.
	 * @param int|null                   $form_id Form ID.
	 * @param array<string, string>|null $device_signals Device fingerprint hashes (cookie, ua, screen, ...).
	 * @param bool                       $skip_device Set true to bypass the device fingerprint check (e.g. for managers).
	 * @return array{allowed: bool, message?: string, reason?: string, wait_seconds?: int}
	 */
	public static function check_all( string $ip, ?string $email = null, ?string $cpf = null, ?int $form_id = null, ?array $device_signals = null, bool $skip_device = false ): array {
		$s = self::get_settings();

		$bl = self::check_blacklist( $ip, $email, $cpf );
		if ( ! $bl['allowed'] ) {
			RateLimitLogger::log_attempt( 'blacklist', $ip, 'blocked', $bl['reason'] ?? '', $form_id );
			return $bl; }

		if ( self::is_whitelisted( $ip, $email, $cpf ) ) {
			RateLimitLogger::log_attempt( 'whitelist', $ip, 'whitelisted', 'In whitelist', $form_id );
			return array( 'allowed' => true ); }

		if ( ! $skip_device && ! empty( $s['device']['enabled'] ) && $form_id && is_array( $device_signals ) ) {
			$d = self::check_device_limit( $form_id, $device_signals );
			if ( ! $d['allowed'] ) {
				if ( ! empty( $s['device']['log_blocks'] ) ) {
					$first_signal = reset( $device_signals );
					$d_id         = $device_signals['cookie'] ?? ( false !== $first_signal ? $first_signal : 'unknown' );
					RateLimitLogger::log_attempt( 'device', (string) $d_id, 'blocked', $d['reason'] ?? '', $form_id );
				}
				return $d;
			}
		}

		if ( $s['global']['enabled'] ) {
			$g = self::check_global_limit();
			if ( ! $g['allowed'] ) {
				RateLimitLogger::log_attempt( 'global', 'system', 'blocked', $g['reason'] ?? '', $form_id );
				return $g; }
		}

		if ( $s['ip']['enabled'] && self::applies_to_form( $s['ip']['apply_to'], $form_id ) ) {
			$i = self::check_ip_limit( $ip, $form_id );
			if ( ! $i['allowed'] ) {
				RateLimitLogger::log_attempt( 'ip', $ip, 'blocked', $i['reason'] ?? '', $form_id );
				return $i; }
		}

		if ( $email && $s['email']['enabled'] && self::applies_to_form( $s['email']['apply_to'], $form_id ) ) {
			$e = self::check_email_limit( $email, $form_id );
			if ( ! $e['allowed'] ) {
				RateLimitLogger::log_attempt( 'email', $email, 'blocked', $e['reason'] ?? '', $form_id );
				return $e; }
		}

		if ( $cpf && $s['cpf']['enabled'] && self::applies_to_form( $s['cpf']['apply_to'], $form_id ) ) {
			$c = self::check_cpf_limit( $cpf, $form_id );
			if ( ! $c['allowed'] ) {
				RateLimitLogger::log_attempt( 'cpf', $cpf, 'blocked', $c['reason'] ?? '', $form_id );
				return $c; }
		}

		if ( $s['logging']['log_allowed'] ) {
			RateLimitLogger::log_attempt( 'ip', $ip, 'allowed', 'Passed', $form_id );
		}

		return array( 'allowed' => true );
	}

	/**
	 * Check ip limit.
	 *
	 * @param string   $ip IP address.
	 * @param int|null $form_id Form ID.
	 * @return array{allowed: bool, message?: string, reason?: string, wait_seconds?: int}
	 */
	public static function check_ip_limit( string $ip, ?int $form_id = null ): array {
		$s  = self::get_settings()['ip'];
		$hk = 'ffc_rate_ip_' . md5( $ip . $form_id ) . '_hour';
		// v3.2.0: Use Object Cache API (auto Redis/Memcached if available).
		$hc = wp_cache_get( $hk, RateLimiter::CACHE_GROUP );
		$hc = false !== $hc ? $hc : 0;
		if ( $hc >= $s['max_per_hour'] ) {
			return array(
				'allowed'      => false,
				'reason'       => 'ip_hour_limit',
				'message'      => self::format_message( $s['message'], array( 'time' => __( '1 hour', 'ffcertificate' ) ) ),
				'wait_seconds' => 3600,
			);
		}

		$dc = self::get_count_from_db( 'ip', $ip, 'day', $form_id );
		if ( $dc >= $s['max_per_day'] ) {
			return array(
				'allowed'      => false,
				'reason'       => 'ip_day_limit',
				'message'      => self::format_message( $s['message'], array( 'time' => __( '24 hours', 'ffcertificate' ) ) ),
				'wait_seconds' => 86400,
			);
		}

		$last = wp_cache_get( 'ffc_rate_ip_' . md5( $ip . $form_id ) . '_last', RateLimiter::CACHE_GROUP );
		if ( $last && ( time() - $last ) < $s['cooldown_seconds'] ) {
			$w = $s['cooldown_seconds'] - ( time() - $last );
			return array(
				'allowed'      => false,
				'reason'       => 'ip_cooldown',
				'message'      => sprintf(
					/* translators: %d: number of seconds to wait */
					__( 'Please wait %d seconds.', 'ffcertificate' ),
					$w
				),
				'wait_seconds' => $w,
			);
		}

		return array( 'allowed' => true );
	}

	/**
	 * Check email limit.
	 *
	 * @param string   $email Email address.
	 * @param int|null $form_id Form ID.
	 * @return array{allowed: bool, message?: string, reason?: string, wait_seconds?: int}
	 */
	public static function check_email_limit( string $email, ?int $form_id = null ): array {
		$s = self::get_settings()['email'];
		if ( ! $s['check_database'] ) {
			return array( 'allowed' => true );
		}

		$dc = self::get_submission_count( 'email', $email, 'day', $form_id );
		if ( $dc >= $s['max_per_day'] ) {
			return array(
				'allowed'      => false,
				'reason'       => 'email_day_limit',
				'message'      => self::format_message(
					$s['message'],
					array(
						'count' => $dc,
						'time'  => __( '24 hours', 'ffcertificate' ),
					)
				),
				'wait_seconds' => 86400,
			);
		}

		$wc = self::get_submission_count( 'email', $email, 'week', $form_id );
		if ( $wc >= $s['max_per_week'] ) {
			return array(
				'allowed'      => false,
				'reason'       => 'email_week_limit',
				'message'      => self::format_message(
					$s['message'],
					array(
						'count' => $wc,
						'time'  => __( '1 week', 'ffcertificate' ),
					)
				),
				'wait_seconds' => 604800,
			);
		}

		$mc = self::get_submission_count( 'email', $email, 'month', $form_id );
		if ( $mc >= $s['max_per_month'] ) {
			return array(
				'allowed'      => false,
				'reason'       => 'email_month_limit',
				'message'      => self::format_message(
					$s['message'],
					array(
						'count' => $mc,
						'time'  => __( '1 month', 'ffcertificate' ),
					)
				),
				'wait_seconds' => 2592000,
			);
		}

		return array( 'allowed' => true );
	}

	/**
	 * Check cpf limit.
	 *
	 * @param string   $cpf CPF document.
	 * @param int|null $form_id Form ID.
	 * @return array{allowed: bool, message?: string, reason?: string, wait_seconds?: int}
	 */
	public static function check_cpf_limit( string $cpf, ?int $form_id = null ): array {
		$s  = self::get_settings()['cpf'];
		$cc = \FreeFormCertificate\Core\DataSanitizer::normalize_cpf_rf( $cpf );

		if ( self::is_temporarily_blocked( 'cpf', $cc, $form_id ) ) {
			return array(
				'allowed'      => false,
				'reason'       => 'cpf_blocked',
				'message'      => __( 'CPF blocked.', 'ffcertificate' ),
				'wait_seconds' => 86400,
			);
		}

		if ( $s['check_database'] ) {
			$mc = self::get_submission_count( 'cpf', $cc, 'month', $form_id );
			if ( $mc >= $s['max_per_month'] ) {
				return array(
					'allowed'      => false,
					'reason'       => 'cpf_month_limit',
					'message'      => $s['message'],
					'wait_seconds' => 2592000,
				);
			}

			$yc = self::get_submission_count( 'cpf', $cc, 'year', $form_id );
			if ( $yc >= $s['max_per_year'] ) {
				return array(
					'allowed'      => false,
					'reason'       => 'cpf_year_limit',
					'message'      => $s['message'],
					'wait_seconds' => 31536000,
				);
			}
		}

		$ac = self::get_count_from_db( 'cpf', $cc, 'hour', $form_id );
		if ( $ac >= $s['block_threshold'] ) {
			self::block_temporarily( 'cpf', $cc, $form_id, $s['block_duration'] );
			return array(
				'allowed'      => false,
				'reason'       => 'cpf_abuse',
				'message'      => __( 'CPF blocked.', 'ffcertificate' ),
				'wait_seconds' => $s['block_duration'] * 3600,
			);
		}

		return array( 'allowed' => true );
	}

	/**
	 * Check global limit.
	 *
	 * @return array{allowed: bool, message?: string, reason?: string, wait_seconds?: int}
	 */
	public static function check_global_limit(): array {
		$s  = self::get_settings()['global'];
		$mk = 'ffc_rate_global_minute_' . floor( time() / 60 );
		// v3.2.0: Use Object Cache API.
		$mc = wp_cache_get( $mk, RateLimiter::CACHE_GROUP );
		$mc = false !== $mc ? $mc : 0;
		if ( $mc >= $s['max_per_minute'] ) {
			return array(
				'allowed'      => false,
				'reason'       => 'global_minute_limit',
				'message'      => $s['message'],
				'wait_seconds' => 60,
			);
		}

		$hc = self::get_count_from_db( 'global', 'system', 'hour', null );
		if ( $hc >= $s['max_per_hour'] ) {
			return array(
				'allowed'      => false,
				'reason'       => 'global_hour_limit',
				'message'      => $s['message'],
				'wait_seconds' => 3600,
			);
		}

		return array( 'allowed' => true );
	}

	/**
	 * Check whether the current submission should bypass the device limit
	 * because the user is logged in as administrator or carries the
	 * ffc_manage_settings capability (typical "Certificate Manager" profile).
	 *
	 * @return bool
	 */
	public static function should_bypass_for_manager(): bool {
		$d = self::get_settings()['device'];
		if ( empty( $d['bypass_logged_in_managers'] ) ) {
			return false;
		}
		if ( ! is_user_logged_in() ) {
			return false;
		}
		if ( class_exists( '\FreeFormCertificate\Core\Utils' ) ) {
			return \FreeFormCertificate\Core\Utils::current_user_can_admin_or( 'ffc_manage_settings' );
		}
		return current_user_can( 'manage_options' );
	}

	/**
	 * Resolve the per-form effective settings for the device limit, applying
	 * post-meta overrides on top of the global device.* values.
	 *
	 * @param int $form_id Form post ID.
	 * @return array{max: int, threshold: int, message: string}
	 */
	public static function get_device_effective_settings( int $form_id ): array {
		$d        = self::get_settings()['device'];
		$max_meta = get_post_meta( $form_id, '_ffc_device_limit_max', true );
		$thr_meta = get_post_meta( $form_id, '_ffc_device_match_threshold', true );
		$msg_meta = get_post_meta( $form_id, '_ffc_device_limit_message', true );

		$max = ( '' !== $max_meta ) ? max( 1, (int) $max_meta ) : (int) $d['max_per_form'];
		$thr = ( '' !== $thr_meta ) ? max( 3, min( 12, (int) $thr_meta ) ) : (int) $d['match_threshold'];
		$msg = ( is_string( $msg_meta ) && '' !== $msg_meta ) ? $msg_meta : (string) $d['message'];

		return array(
			'max'       => $max,
			'threshold' => $thr,
			'message'   => $msg,
		);
	}

	/**
	 * Check the device fingerprint limit for a single submission.
	 *
	 * Treats two submissions as "same device" when:
	 *   (a) their cookie hash matches, OR
	 *   (b) at least <threshold> non-cookie signal hashes match.
	 *
	 * Counts how many prior submissions of this form match the incoming
	 * signal set; blocks if the count reaches the configured maximum.
	 *
	 * @param int                   $form_id Form ID (post ID).
	 * @param array<string, string> $signals Map of signal name -> SHA-256 hex hash.
	 * @return array{allowed: bool, message?: string, reason?: string, wait_seconds?: int}
	 */
	public static function check_device_limit( int $form_id, array $signals ): array {
		global $wpdb;

		$d = self::get_settings()['device'];
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

		$effective = self::get_device_effective_settings( $form_id );

		$signal_keys = array( 'ua', 'screen', 'tz', 'concurrency', 'memory', 'canvas', 'audio', 'webgl', 'fonts', 'plugins', 'permissions', 'mediaqueries', 'math' );
		$sum_parts   = array();
		$values      = array();
		foreach ( $signal_keys as $k ) {
			if ( ! empty( $signals[ $k ] ) ) {
				$col         = 'sig_' . $k;
				$sum_parts[] = "({$col} = %s)";
				$values[]    = $signals[ $k ];
			}
		}

		$threshold = $effective['threshold'];
		$max       = $effective['max'];
		$table     = $wpdb->prefix . 'ffc_device_signals';

		$count = 0;
		if ( $cookie && empty( $sum_parts ) ) {
			// Cookie-only fallback: count distinct submission rows with matching cookie.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE form_id = %d AND sig_cookie = %s', $table, $form_id, $cookie ) );
		} elseif ( ! empty( $sum_parts ) ) {
			$sum_sql = implode( ' + ', $sum_parts );
			if ( $cookie ) {
				$where_sql = "form_id = %d AND ( sig_cookie = %s OR ( {$sum_sql} ) >= %d )";
				$args      = array_merge( array( $table, $form_id, $cookie ), $values, array( $threshold ) );
			} else {
				$where_sql = "form_id = %d AND ( {$sum_sql} ) >= %d";
				$args      = array_merge( array( $table, $form_id ), $values, array( $threshold ) );
			}
			$sql = "SELECT COUNT(*) FROM %i WHERE {$where_sql}";
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$count = (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
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
	public static function record_device_signals( ?int $submission_id, int $form_id, array $signals ): void {
		global $wpdb;
		$d = self::get_settings()['device'];
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

	/**
	 * Check rate limit for verification requests (magic links)
	 *
	 * @param string      $ip IP address.
	 * @param string|null $token Token.
	 * @return array{allowed: bool, message?: string, wait_seconds?: int}
	 */
	public static function check_verification( string $ip, ?string $token = null ): array {
		$settings = self::get_settings();

		if ( empty( $settings['ip']['enabled'] ) ) {
			return array( 'allowed' => true );
		}

		$max_per_hour = 10;
		$max_per_day  = 30;

		$hour_key = 'ffc_verify_ip_' . md5( $ip ) . '_hour_' . gmdate( 'YmdH' );
		// v3.2.0: Use Object Cache API.
		$hour_count = wp_cache_get( $hour_key, RateLimiter::CACHE_GROUP );

		if ( false === $hour_count ) {
			$hour_count = 0;
		}

		if ( $hour_count >= $max_per_hour ) {
			$wait_seconds = 3600 - ( time() % 3600 );

			return array(
				'allowed'      => false,
				/* translators: %s: formatted wait time */
				'message'      => sprintf( __( 'Too many verification attempts. Please wait %s.', 'ffcertificate' ), self::format_wait_time( $wait_seconds ) ),
				'wait_seconds' => $wait_seconds,
			);
		}

		$day_key   = 'ffc_verify_ip_' . md5( $ip ) . '_day_' . gmdate( 'Ymd' );
		$day_count = wp_cache_get( $day_key, RateLimiter::CACHE_GROUP );

		if ( false === $day_count ) {
			$day_count = 0;
		}

		if ( $day_count >= $max_per_day ) {
			$wait_seconds = 86400 - ( time() % 86400 );

			return array(
				'allowed'      => false,
				'message'      => __( 'Daily verification limit reached. Please try again tomorrow.', 'ffcertificate' ),
				'wait_seconds' => $wait_seconds,
			);
		}

		wp_cache_set( $hour_key, $hour_count + 1, RateLimiter::CACHE_GROUP, 3600 );
		wp_cache_set( $day_key, $day_count + 1, RateLimiter::CACHE_GROUP, 86400 );

		if ( ! empty( $settings['logging']['enabled'] ) ) {
			RateLimitLogger::log_attempt( 'ip', $ip, 'allowed', 'verification_attempt', null );
		}

		return array( 'allowed' => true );
	}

	/**
	 * Format wait time.
	 *
	 * @param int $seconds Seconds.
	 * @return string
	 */
	private static function format_wait_time( int $seconds ): string {
		if ( $seconds < 60 ) {
			/* translators: %d: number of seconds */
			return sprintf( _n( '%d second', '%d seconds', $seconds, 'ffcertificate' ), $seconds );
		}

		$minutes = (int) ceil( $seconds / 60 );
		if ( $minutes < 60 ) {
			/* translators: %d: number of minutes */
			return sprintf( _n( '%d minute', '%d minutes', $minutes, 'ffcertificate' ), $minutes );
		}

		$hours = (int) ceil( $minutes / 60 );
		/* translators: %d: number of hours */
		return sprintf( _n( '%d hour', '%d hours', $hours, 'ffcertificate' ), $hours );
	}
	/**
	 * Record attempt.
	 *
	 * @param string   $type Type.
	 * @param string   $identifier Identifier.
	 * @param int|null $form_id Form ID.
	 */
	public static function record_attempt( string $type, string $identifier, ?int $form_id = null ): void {
		$s = self::get_settings();

		if ( 'ip' === $type ) {
			$hk = 'ffc_rate_ip_' . md5( $identifier . $form_id ) . '_hour';
			// v3.2.0: Use Object Cache API for better performance.
			$current = wp_cache_get( $hk, RateLimiter::CACHE_GROUP );
			$current = false !== $current ? $current : 0;
			wp_cache_set( $hk, $current + 1, RateLimiter::CACHE_GROUP, 3600 );

			$last_key = 'ffc_rate_ip_' . md5( $identifier . $form_id ) . '_last';
			wp_cache_set( $last_key, time(), RateLimiter::CACHE_GROUP, $s['ip']['cooldown_seconds'] );
		}

		if ( 'global' === $type ) {
			$mk      = 'ffc_rate_global_minute_' . floor( time() / 60 );
			$current = wp_cache_get( $mk, RateLimiter::CACHE_GROUP );
			$current = false !== $current ? $current : 0;
			wp_cache_set( $mk, $current + 1, RateLimiter::CACHE_GROUP, 60 );
		}

		self::increment_counter( $type, $identifier, 'day', $form_id );
		if ( in_array( $type, array( 'email', 'cpf' ), true ) ) {
			self::increment_counter( $type, $identifier, 'month', $form_id );
			if ( 'cpf' === $type ) {
				self::increment_counter( $type, $identifier, 'hour', $form_id );
			}
		}
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
	private static function get_count_from_db( string $type, string $identifier, string $window, ?int $form_id ): int {
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
	private static function increment_counter( string $type, string $identifier, string $window, ?int $form_id ): void {
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
	private static function get_submission_count( string $field, string $value, string $period, ?int $form_id ): int {
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
	 * Check blacklist.
	 *
	 * @param string      $ip IP address.
	 * @param string|null $email Email address.
	 * @param string|null $cpf CPF document.
	 * @return array{allowed: bool, message?: string, reason?: string}
	 */
	private static function check_blacklist( string $ip, ?string $email, ?string $cpf ): array {
		$s  = self::get_settings();
		$bl = $s['blacklist'];

		if ( in_array( $ip, $bl['ips'], true ) ) {
			return array(
				'allowed' => false,
				'reason'  => 'ip_blacklisted',
				'message' => __( 'IP blocked.', 'ffcertificate' ),
			);
		}

		if ( $email ) {
			if ( in_array( $email, $bl['emails'], true ) ) {
				return array(
					'allowed' => false,
					'reason'  => 'email_blacklisted',
					'message' => __( 'Email blocked.', 'ffcertificate' ),
				);
			}
			$at_part = strrchr( $email, '@' );
			$d       = substr( $at_part ? $at_part : '', 1 );
			if ( in_array( '*@' . $d, $bl['email_domains'], true ) ) {
				return array(
					'allowed' => false,
					'reason'  => 'domain_blacklisted',
					'message' => __( 'Domain blocked.', 'ffcertificate' ),
				);
			}
		}

		if ( $cpf && in_array( \FreeFormCertificate\Core\DataSanitizer::normalize_cpf_rf( $cpf ), $bl['cpfs'], true ) ) {
			return array(
				'allowed' => false,
				'reason'  => 'cpf_blacklisted',
				'message' => __( 'CPF blocked.', 'ffcertificate' ),
			);
		}

		return array( 'allowed' => true );
	}

	/**
	 * Check whether whitelisted.
	 *
	 * @param string      $ip Ip.
	 * @param string|null $email Email address.
	 * @param string|null $cpf Cpf.
	 * @return bool
	 */
	private static function is_whitelisted( string $ip, ?string $email, ?string $cpf ): bool {
		$s  = self::get_settings();
		$wl = $s['whitelist'];

		if ( in_array( $ip, $wl['ips'], true ) ) {
			return true;
		}

		if ( $email ) {
			if ( in_array( $email, $wl['emails'], true ) ) {
				return true;
			}
			$at_part = strrchr( $email, '@' );
			$d       = substr( $at_part ? $at_part : '', 1 );
			if ( in_array( '*@' . $d, $wl['email_domains'], true ) ) {
				return true;
			}
		}

		if ( $cpf && in_array( \FreeFormCertificate\Core\DataSanitizer::normalize_cpf_rf( $cpf ), $wl['cpfs'], true ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Check whether temporarily blocked.
	 *
	 * @param string   $type Type.
	 * @param string   $identifier Identifier.
	 * @param int|null $form_id Form ID.
	 * @return bool
	 */
	private static function is_temporarily_blocked( string $type, string $identifier, ?int $form_id ): bool {
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
	private static function block_temporarily( string $type, string $identifier, ?int $form_id, int $hours ): void {
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
	 * Format message.
	 *
	 * @param string               $template Template.
	 * @param array<string, mixed> $data Data.
	 */
	private static function format_message( string $template, array $data ): string {
		return str_replace( array( '{time}', '{count}', '{max}', '{remaining}' ), array( (string) ( $data['time'] ?? '' ), (string) ( $data['count'] ?? 0 ), (string) ( $data['max'] ?? 0 ), (string) ( ( $data['max'] ?? 0 ) - ( $data['count'] ?? 0 ) ) ), $template );
	}

	/**
	 * Applies to form.
	 *
	 * @param mixed    $apply_to Apply to.
	 * @param int|null $form_id Form ID.
	 */
	private static function applies_to_form( $apply_to, ?int $form_id ): bool {
		return 'all' === $apply_to || ( is_array( $apply_to ) && in_array( $form_id, $apply_to, true ) );
	}

	/**
	 * Get window start.
	 *
	 * @param string $window Window.
	 * @return string
	 */
	private static function get_window_start( string $window ): string {
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
	private static function get_window_end( string $window ): string {
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

	/**
	 * Get user ip.
	 *
	 * @return string
	 */
	public static function get_user_ip(): string {
		// By default we only trust REMOTE_ADDR, which is set by the web server and cannot be spoofed by clients.
		// When the site sits behind a known reverse proxy (Cloudflare, AWS ALB, nginx), administrators can opt in
		// to forwarded headers via the 'ffc_trust_forwarded_headers' filter. Returning true enables the legacy
		// behavior that consults HTTP_X_FORWARDED_FOR and friends.
		$trust_forwarded = (bool) apply_filters( 'ffc_trust_forwarded_headers', false );

		$candidates = $trust_forwarded
			? array( 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR' )
			: array( 'REMOTE_ADDR' );

		foreach ( $candidates as $key ) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Value unslashed and sanitized on next line.
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
				if ( strpos( $ip, ',' ) !== false ) {
					$ip = trim( explode( ',', $ip )[0] );
				}
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}
		return '0.0.0.0';
	}

	/**
	 * Check rate limit for authenticated user actions (password change, privacy request)
	 *
	 * @since 4.9.9
	 * @param int    $user_id WordPress user ID.
	 * @param string $action  Action identifier (e.g. 'password_change', 'privacy_request').
	 * @param int    $max_per_hour Max attempts per hour (default 5).
	 * @param int    $max_per_day  Max attempts per day (default 10).
	 * @return array{allowed: bool, message?: string, wait_seconds?: int}
	 */
	public static function check_user_limit( int $user_id, string $action = 'default', int $max_per_hour = 5, int $max_per_day = 10 ): array {
		if ( $user_id <= 0 ) {
			return array( 'allowed' => true );
		}

		$hour_key   = 'ffc_rate_user_' . $user_id . '_' . $action . '_hour_' . gmdate( 'YmdH' );
		$hour_count = wp_cache_get( $hour_key, RateLimiter::CACHE_GROUP );
		$hour_count = false !== $hour_count ? (int) $hour_count : 0;

		if ( $hour_count >= $max_per_hour ) {
			$wait = 3600 - ( time() % 3600 );
			return array(
				'allowed'      => false,
				/* translators: %s: formatted wait time */
				'message'      => sprintf( __( 'Too many attempts. Please wait %s.', 'ffcertificate' ), self::format_wait_time( $wait ) ),
				'wait_seconds' => $wait,
			);
		}

		$day_key   = 'ffc_rate_user_' . $user_id . '_' . $action . '_day_' . gmdate( 'Ymd' );
		$day_count = wp_cache_get( $day_key, RateLimiter::CACHE_GROUP );
		$day_count = false !== $day_count ? (int) $day_count : 0;

		if ( $day_count >= $max_per_day ) {
			return array(
				'allowed'      => false,
				'message'      => __( 'Daily limit reached. Please try again tomorrow.', 'ffcertificate' ),
				'wait_seconds' => 86400 - ( time() % 86400 ),
			);
		}

		// Increment counters.
		wp_cache_set( $hour_key, $hour_count + 1, RateLimiter::CACHE_GROUP, 3600 );
		wp_cache_set( $day_key, $day_count + 1, RateLimiter::CACHE_GROUP, 86400 );

		return array( 'allowed' => true );
	}
}
