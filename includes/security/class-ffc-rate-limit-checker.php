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
 *
 * A denial always carries the message shown to the visitor, so a caller
 * never needs a fallback of its own (#2153).
 *
 * @phpstan-type RateLimitVerdict array{allowed: true}|array{allowed: false, message: string, reason?: string, wait_seconds?: int}
 */
final class RateLimitChecker {

	/**
	 * Strong device signals — high-entropy probes that rarely coincide
	 * between two distinct physical devices and survive incognito /
	 * cleared-storage sessions (they are hardware/software bound, not
	 * stored client-side). The two-tier device match requires at least
	 * `match_strong_min` of these to corroborate a fuzzy match before it
	 * counts as the "same device", so matching only weak signals (which
	 * are identical across whole fleets of same-model devices) can no
	 * longer trigger a block on its own. Fixed in code by design.
	 *
	 * @since 6.8.0
	 * @var string[]
	 */
	public const STRONG_SIGNALS = array( 'canvas', 'webgl', 'audio', 'fonts', 'plugins', 'permissions' );

	/**
	 * Every device signal the client sends and a submission stores, in the
	 * order of the `sig_*` columns.
	 */
	/**
	 * The device id every submission without signals shares (#2339), so they
	 * count as one device per form. Shaped like a signal (64 hex) and kept
	 * whatever signals are enabled, or turning the cookie signal off would
	 * reopen the bypass.
	 */
	public const NO_SIGNALS_DEVICE = '0000000000000000000000000000000000000000000000000000000000000000';

	public const DEVICE_SIGNALS = array( 'cookie', 'ua', 'screen', 'tz', 'concurrency', 'memory', 'canvas', 'audio', 'webgl', 'fonts', 'plugins', 'permissions', 'mediaqueries', 'math' );

	/**
	 * Cached settings for the current request
	 *
	 * @since 4.6.13
	 * @var array<string, mixed>|null
	 */
	private static ?array $settings_cache = null;

	/**
	 * The read endpoints and their limits before an administrator saves the
	 * Rate Limit tab (#259, #2243). The tab shows and saves these; a stored
	 * endpoint overrides its entry here, and an endpoint never saved reads
	 * this one, so a limit the tab shows as on is on.
	 *
	 * @var array<string, array{enabled: bool, max_per_minute: int, max_per_hour: int}>
	 */
	public const READ_ENDPOINT_DEFAULTS = array(
		'calendar_slots'     => array(
			'enabled'        => true,
			'max_per_minute' => 60,
			'max_per_hour'   => 1000,
		),
		'calendar_list'      => array(
			'enabled'        => true,
			'max_per_minute' => 30,
			'max_per_hour'   => 500,
		),
		'calendar_detail'    => array(
			'enabled'        => true,
			'max_per_minute' => 60,
			'max_per_hour'   => 1000,
		),
		// The audience calendar's month read: one request per month
		// navigated, so these leave a person untouched and stop a loop.
		// Counted for logged-in callers too, by account.
		'audience_bookings'  => array(
			'enabled'        => true,
			'max_per_minute' => 30,
			'max_per_hour'   => 500,
		),
		// The public CSV screen (#2341): its info read, the certificate
		// preview, the no-JS download and the batched export's start. An
		// operator makes a handful per visit; a hash guessed by brute force
		// makes thousands.
		'public_csv'         => array(
			'enabled'        => true,
			'max_per_minute' => 10,
			'max_per_hour'   => 100,
		),
		// Its three operator actions: open early, postpone the close and
		// the schedule exception. Each changes the form, so tighter still.
		'public_csv_actions' => array(
			'enabled'        => true,
			'max_per_minute' => 5,
			'max_per_hour'   => 30,
		),
	);

	/**
	 * Get settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_settings(): array {
		if ( null !== self::$settings_cache ) {
			return self::$settings_cache;
		}
		// One declaration of the defaults, merged recursively (#2339): see
		// RateLimitSettingsReader::effective().
		self::$settings_cache = \FreeFormCertificate\Settings\RateLimitSettingsReader::effective();
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
	 * @param bool                       $skip_ip     Set true to bypass the per-address check, for an authenticated API caller whose requests share one server address (#2342).
	 * @return RateLimitVerdict
	 */
	public static function check_all( string $ip, ?string $email = null, ?string $cpf = null, ?int $form_id = null, ?array $device_signals = null, bool $skip_device = false, bool $skip_ip = false ): array {
		$s = self::get_settings();

		$bl = self::check_blacklist( $ip, $email, $cpf );
		if ( ! $bl['allowed'] ) {
			RateLimitLogger::log_attempt( 'blacklist', $ip, 'blocked', $bl['reason'] ?? '', $form_id );
			return $bl; }

		// An allowlisted ADDRESS skips every check: the address is what the
		// server saw. An allowlisted e-mail or CPF/RF is only what the visitor
		// typed, so it skips the limits on that identity and nothing else --
		// never the address, device or global limits (#2339).
		if ( self::is_ip_whitelisted( $ip ) ) {
			RateLimitLogger::log_attempt( 'whitelist', $ip, 'whitelisted', 'In whitelist', $form_id );
			return array( 'allowed' => true ); }
		$identity_whitelisted = self::is_identity_whitelisted( $email, $cpf );

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

		if ( ! $skip_ip && $s['ip']['enabled'] && self::applies_to_form( $s['ip']['apply_to'], $form_id ) ) {
			$i = self::check_ip_limit( $ip, $form_id );
			if ( ! $i['allowed'] ) {
				RateLimitLogger::log_attempt( 'ip', $ip, 'blocked', $i['reason'] ?? '', $form_id );
				return $i; }
		}

		if ( ! $identity_whitelisted && $email && $s['email']['enabled'] && self::applies_to_form( $s['email']['apply_to'], $form_id ) ) {
			$e = self::check_email_limit( $email, $form_id );
			if ( ! $e['allowed'] ) {
				RateLimitLogger::log_attempt( 'email', $email, 'blocked', $e['reason'] ?? '', $form_id );
				return $e; }
		}

		if ( ! $identity_whitelisted && $cpf && $s['cpf']['enabled'] && self::applies_to_form( $s['cpf']['apply_to'], $form_id ) ) {
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
	 * @return RateLimitVerdict
	 */
	public static function check_ip_limit( string $ip, ?int $form_id = null ): array {
		return ( new IpLimiter( new RateLimitSupport() ) )->check( $ip, $form_id );
	}

	/**
	 * Check email limit.
	 *
	 * @param string   $email Email address.
	 * @param int|null $form_id Form ID.
	 * @return RateLimitVerdict
	 */
	public static function check_email_limit( string $email, ?int $form_id = null ): array {
		return ( new EmailLimiter( new RateLimitSupport() ) )->check( $email, $form_id );
	}

	/**
	 * Check cpf limit.
	 *
	 * @param string   $cpf CPF document.
	 * @param int|null $form_id Form ID.
	 * @return RateLimitVerdict
	 */
	public static function check_cpf_limit( string $cpf, ?int $form_id = null ): array {
		return ( new CpfLimiter( new RateLimitSupport() ) )->check( $cpf, $form_id );
	}

	/**
	 * Check global limit.
	 *
	 * @return RateLimitVerdict
	 */
	public static function check_global_limit(): array {
		$s  = self::get_settings()['global'];
		$mk = 'ffc_rate_global_minute_' . floor( time() / 60 );
		$mc = RateCounter::get( $mk );
		if ( $mc >= $s['max_per_minute'] ) {
			$wait = RateLimitSupport::window_left( MINUTE_IN_SECONDS );
			return array(
				'allowed'      => false,
				'reason'       => 'global_minute_limit',
				'message'      => ( new RateLimitSupport() )->format_message( $s['message'], array( 'wait' => $wait ) ),
				'wait_seconds' => $wait,
			);
		}

		$hc = RateLimitRepository::get_count_from_db( 'global', 'system', 'hour', null );
		if ( $hc >= $s['max_per_hour'] ) {
			$wait = RateLimitSupport::window_left( HOUR_IN_SECONDS );
			return array(
				'allowed'      => false,
				'reason'       => 'global_hour_limit',
				'message'      => ( new RateLimitSupport() )->format_message( $s['message'], array( 'wait' => $wait ) ),
				'wait_seconds' => $wait,
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
			return \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_settings' );
		}
		return current_user_can( 'manage_options' );
	}

	/**
	 * Resolve the per-form effective settings for the device limit, applying
	 * post-meta overrides on top of the global device.* values.
	 *
	 * @param int $form_id Form post ID.
	 * @return array{max: int, threshold: int, strong_min: int, message: string}
	 */
	public static function get_device_effective_settings( int $form_id ): array {
		return ( new DeviceLimiter( new RateLimitSupport() ) )->get_effective_settings( $form_id );
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
	 * @return RateLimitVerdict
	 */
	public static function check_device_limit( int $form_id, array $signals ): array {
		return ( new DeviceLimiter( new RateLimitSupport() ) )->check( $form_id, $signals );
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
		( new DeviceLimiter( new RateLimitSupport() ) )->record_signals( $submission_id, $form_id, $signals );
	}

	/**
	 * Check rate limit for verification requests (magic links).
	 *
	 * Throttles by IP only — 10/hour and 30/day. It carried a `$token` argument
	 * until 6.24.0 that nothing ever read; it was removed in #1048, announced in
	 * 6.22.0.
	 *
	 * A per-token limit is still not needed, and the reason is worth keeping so
	 * it is not re-derived: the only thing keying on the token would add is
	 * defence against a distributed brute-force of a single auth code, and the
	 * code space rules that out — `AuthCodeService` emits 12 characters over
	 * `[A-Z0-9]` (36^12, ~62 bits) with global uniqueness verified across the
	 * three tables that hold one. Revisit it only if the auth code shortens, or
	 * a flow appears that accepts a short human-typed code (6-8 characters); at
	 * that length the IP-only ceiling stops being sufficient.
	 *
	 * @param string $ip IP address.
	 * @return RateLimitVerdict
	 */
	public static function check_verification( string $ip ): array {
		$settings = self::get_settings();

		if ( empty( $settings['ip']['enabled'] ) ) {
			return array( 'allowed' => true );
		}

		$max_per_hour = 10;
		$max_per_day  = 30;

		$hour_key   = 'ffc_verify_ip_' . md5( \FreeFormCertificate\Core\IpAddress::fingerprint( $ip ) ) . '_hour_' . gmdate( 'YmdH' );
		$hour_count = RateCounter::get( $hour_key );

		if ( $hour_count >= $max_per_hour ) {
			$wait_seconds = 3600 - ( time() % 3600 );

			return array(
				'allowed'      => false,
				/* translators: %s: formatted wait time */
				'message'      => sprintf( __( 'Too many verification attempts. Please wait %s.', 'ffcertificate' ), RateLimitSupport::wait_words( $wait_seconds ) ),
				'wait_seconds' => $wait_seconds,
			);
		}

		$day_key   = 'ffc_verify_ip_' . md5( \FreeFormCertificate\Core\IpAddress::fingerprint( $ip ) ) . '_day_' . gmdate( 'Ymd' );
		$day_count = RateCounter::get( $day_key );

		if ( $day_count >= $max_per_day ) {
			$wait_seconds = 86400 - ( time() % 86400 );

			return array(
				'allowed'      => false,
				'message'      => __( 'Daily verification limit reached. Please try again tomorrow.', 'ffcertificate' ),
				'wait_seconds' => $wait_seconds,
			);
		}

		RateCounter::increment( $hour_key, 3600 );
		RateCounter::increment( $day_key, 86400 );

		if ( ! empty( $settings['logging']['enabled'] ) ) {
			RateLimitLogger::log_attempt( 'ip', $ip, 'allowed', 'verification_attempt', null );
		}

		return array( 'allowed' => true );
	}

	/**
	 * Count a captcha that did not verify against the caller's address
	 * (#2339), the way a submission is counted, so guessing the challenge
	 * spends the same per-address budget as submitting.
	 *
	 * Listens on `ffcertificate_captcha_failed`. Off when the IP limit is off.
	 *
	 * @param mixed $data Form data the challenge came with.
	 * @return void
	 */
	public static function record_captcha_failure( $data = array() ): void {
		if ( empty( self::get_settings()['ip']['enabled'] ) ) {
			return;
		}
		$ip = \FreeFormCertificate\Core\RequestInput::get_user_ip();
		if ( '' === $ip ) {
			return;
		}
		$form_id = is_array( $data ) && isset( $data['form_id'] ) && is_numeric( $data['form_id'] ) ? (int) $data['form_id'] : 0;
		self::record_attempt( 'ip', $ip, $form_id > 0 ? $form_id : null );
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
			// Transients, so the counters persist without an object cache (#1861).
			RateCounter::increment( RateCounter::windowed( 'ffc_rate_ip_' . md5( \FreeFormCertificate\Core\IpAddress::fingerprint( $identifier ) . $form_id ) . '_hour', HOUR_IN_SECONDS ), HOUR_IN_SECONDS );
			RateCounter::put( 'ffc_rate_ip_' . md5( \FreeFormCertificate\Core\IpAddress::fingerprint( $identifier ) . $form_id ) . '_last', time(), max( 1, (int) $s['ip']['cooldown_seconds'] ) );
		}

		if ( 'global' === $type ) {
			RateCounter::increment( 'ffc_rate_global_minute_' . floor( time() / 60 ), 60 );
		}

		RateLimitRepository::increment_counter( $type, $identifier, 'day', $form_id );
		if ( in_array( $type, array( 'email', 'cpf' ), true ) ) {
			RateLimitRepository::increment_counter( $type, $identifier, 'month', $form_id );
			if ( 'cpf' === $type ) {
				RateLimitRepository::increment_counter( $type, $identifier, 'hour', $form_id );
			}
		}
	}

	/**
	 * Check blacklist.
	 *
	 * @param string      $ip IP address.
	 * @param string|null $email Email address.
	 * @param string|null $cpf CPF document.
	 * @return RateLimitVerdict
	 */
	private static function check_blacklist( string $ip, ?string $email, ?string $cpf ): array {
		$s  = self::get_settings();
		$bl = $s['blacklist'];

		if ( \FreeFormCertificate\Core\IpAddress::in_any( $ip, (array) $bl['ips'] ) ) {
			return array(
				'allowed' => false,
				'reason'  => 'ip_blacklisted',
				'message' => __( 'Submissions from this network are not accepted. If you think this is a mistake, contact the site administrator.', 'ffcertificate' ),
			);
		}

		if ( $email ) {
			if ( in_array( $email, $bl['emails'], true ) ) {
				return array(
					'allowed' => false,
					'reason'  => 'email_blacklisted',
					'message' => __( 'This e-mail address is not accepted. Use another address, or contact the site administrator.', 'ffcertificate' ),
				);
			}
			$at_part = strrchr( $email, '@' );
			$d       = substr( $at_part ? $at_part : '', 1 );
			if ( in_array( '*@' . $d, $bl['email_domains'], true ) ) {
				return array(
					'allowed' => false,
					'reason'  => 'domain_blacklisted',
					'message' => __( 'E-mail addresses from this domain are not accepted. Use another address, or contact the site administrator.', 'ffcertificate' ),
				);
			}
		}

		if ( $cpf && in_array( \FreeFormCertificate\Core\DataSanitizer::normalize_cpf_rf( $cpf ), $bl['cpfs'], true ) ) {
			return array(
				'allowed' => false,
				'reason'  => 'cpf_blacklisted',
				'message' => __( 'This CPF/RF is not accepted. If you think this is a mistake, contact the site administrator.', 'ffcertificate' ),
			);
		}

		return array( 'allowed' => true );
	}

	/**
	 * Whether the e-mail or the CPF/RF the visitor typed is allowlisted.
	 *
	 * Self-declared, so it lifts only the limits on that identity (#2339).
	 *
	 * @param string|null $email E-mail address.
	 * @param string|null $cpf   CPF/RF.
	 * @return bool
	 */
	private static function is_identity_whitelisted( ?string $email, ?string $cpf ): bool {
		$wl = self::get_settings()['whitelist'];

		if ( $email ) {
			if ( in_array( $email, (array) $wl['emails'], true ) ) {
				return true;
			}
			$at_part = strrchr( $email, '@' );
			$d       = substr( $at_part ? $at_part : '', 1 );
			if ( in_array( '*@' . $d, (array) $wl['email_domains'], true ) ) {
				return true;
			}
		}

		return $cpf && in_array( \FreeFormCertificate\Core\DataSanitizer::normalize_cpf_rf( $cpf ), (array) $wl['cpfs'], true );
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
	 * Check rate limit for authenticated user actions (password change, privacy request)
	 *
	 * @since 4.9.9
	 * @param int    $user_id WordPress user ID.
	 * @param string $action  Action identifier (e.g. 'password_change', 'privacy_request').
	 * @param int    $max_per_hour Max attempts per hour (default 5).
	 * @param int    $max_per_day  Max attempts per day (default 10).
	 * @return RateLimitVerdict
	 */
	public static function check_user_limit( int $user_id, string $action = 'default', int $max_per_hour = 5, int $max_per_day = 10 ): array {
		if ( $user_id <= 0 ) {
			return array( 'allowed' => true );
		}

		$hour_key   = 'ffc_rate_user_' . $user_id . '_' . $action . '_hour_' . gmdate( 'YmdH' );
		$hour_count = RateCounter::get( $hour_key );

		if ( $hour_count >= $max_per_hour ) {
			$wait = 3600 - ( time() % 3600 );
			return array(
				'allowed'      => false,
				/* translators: %s: formatted wait time */
				'message'      => sprintf( __( 'Too many attempts. Please wait %s.', 'ffcertificate' ), RateLimitSupport::wait_words( $wait ) ),
				'wait_seconds' => $wait,
			);
		}

		$day_key   = 'ffc_rate_user_' . $user_id . '_' . $action . '_day_' . gmdate( 'Ymd' );
		$day_count = RateCounter::get( $day_key );

		if ( $day_count >= $max_per_day ) {
			return array(
				'allowed'      => false,
				'message'      => __( 'Daily limit reached. Please try again tomorrow.', 'ffcertificate' ),
				'wait_seconds' => 86400 - ( time() % 86400 ),
			);
		}

		// Increment counters.
		RateCounter::increment( $hour_key, 3600 );
		RateCounter::increment( $day_key, 86400 );

		return array( 'allowed' => true );
	}

	/**
	 * Check the per-read rate limit for a given (IP, endpoint_key) pair.
	 * Issue #259 — public read endpoints (Calendar REST) need their own
	 * pool so a scraper that never submits forms doesn't bypass the
	 * IP-pool circuit breaker.
	 *
	 * Thresholds are looked up from `settings['read']['endpoints'][$endpoint_key]`.
	 * When the endpoint is disabled (or absent from settings) the call
	 * short-circuits to `allowed=true` so feature stays opt-in per endpoint.
	 *
	 * Minute and hour windows are transient counters (persistent with or
	 * without an object cache, #1861); their TTLs mirror the window length
	 * (60 / 3600 seconds).
	 *
	 * A logged-in caller can be counted by account instead of by address
	 * (#2243): many members of an institution share one address, and a
	 * per-address cap would then cap the whole building.
	 *
	 * @since 6.6.2
	 * @param string $ip            Client IP.
	 * @param string $endpoint_key  Endpoint identifier (e.g. `calendar_slots`).
	 * @param int    $user_id       Account to count instead of the address, or 0.
	 * @return RateLimitVerdict
	 */
	public static function check_read_limit( string $ip, string $endpoint_key, int $user_id = 0 ): array {
		$s        = self::get_settings();
		$endpoint = self::read_endpoint_settings( $s, $endpoint_key );
		if ( null === $endpoint || empty( $endpoint['enabled'] ) ) {
			return array( 'allowed' => true );
		}

		$max_min  = (int) ( $endpoint['max_per_minute'] ?? 0 );
		$max_hour = (int) ( $endpoint['max_per_hour'] ?? 0 );
		$message  = (string) ( $s['read']['message'] ?? __( 'Too many requests. Please wait {time}.', 'ffcertificate' ) );

		if ( $max_min > 0 ) {
			$mc = RateCounter::get( RateCounter::windowed( 'ffc_rate_read_' . md5( self::read_subject( $ip, $user_id ) . '|' . $endpoint_key ) . '_minute', MINUTE_IN_SECONDS ) );
			if ( $mc >= $max_min ) {
				return array(
					'allowed'      => false,
					'reason'       => 'read_minute_limit',
					'message'      => ( new RateLimitSupport() )->format_message(
						$message,
						array(
							'count' => $mc,
							'max'   => $max_min,
							'wait'  => RateLimitSupport::window_left( MINUTE_IN_SECONDS ),
						)
					),
					'wait_seconds' => RateLimitSupport::window_left( MINUTE_IN_SECONDS ),
				);
			}
		}

		if ( $max_hour > 0 ) {
			$hc = RateCounter::get( RateCounter::windowed( 'ffc_rate_read_' . md5( self::read_subject( $ip, $user_id ) . '|' . $endpoint_key ) . '_hour', HOUR_IN_SECONDS ) );
			if ( $hc >= $max_hour ) {
				return array(
					'allowed'      => false,
					'reason'       => 'read_hour_limit',
					'message'      => ( new RateLimitSupport() )->format_message(
						$message,
						array(
							'count' => $hc,
							'max'   => $max_hour,
							'wait'  => RateLimitSupport::window_left( HOUR_IN_SECONDS ),
						)
					),
					'wait_seconds' => RateLimitSupport::window_left( HOUR_IN_SECONDS ),
				);
			}
		}

		return array( 'allowed' => true );
	}

	/**
	 * Record a successful read against the per-endpoint counters.
	 * Increments both the minute and hour counters keyed by
	 * `(ip, endpoint_key)` plus the underlying `ffc_rate_limits` row
	 * (via `record_attempt`) so historical scans and the admin log
	 * tab pick up the same audit trail other rate-limit types use.
	 *
	 * @since 6.6.2
	 * @param string $ip           Client IP.
	 * @param string $endpoint_key Endpoint identifier.
	 * @param int    $user_id      Account to count instead of the address, or 0.
	 * @return void
	 */
	public static function record_read_attempt( string $ip, string $endpoint_key, int $user_id = 0 ): void {
		$identifier = self::read_subject( $ip, $user_id ) . '|' . $endpoint_key;

		RateCounter::increment( RateCounter::windowed( 'ffc_rate_read_' . md5( $identifier ) . '_minute', MINUTE_IN_SECONDS ), MINUTE_IN_SECONDS );
		RateCounter::increment( RateCounter::windowed( 'ffc_rate_read_' . md5( $identifier ) . '_hour', HOUR_IN_SECONDS ), HOUR_IN_SECONDS );

		// Persist the day counter too so the admin "rate-limit log" tab
		// surfaces read activity alongside the other types. `record_attempt`
		// already knows the global / ip / email / cpf branches — passing
		// `'read'` short-circuits all of them and reuses just the day
		// counter persistence at the end.
		self::record_attempt( 'read', $identifier );
	}

	/**
	 * Who a read is counted against: the account when one is given, the
	 * address's keyed fingerprint otherwise.
	 *
	 * @param string $ip      Client IP.
	 * @param int    $user_id Account, or 0.
	 * @return string
	 */
	private static function read_subject( string $ip, int $user_id ): string {
		return $user_id > 0 ? 'user:' . $user_id : \FreeFormCertificate\Core\IpAddress::fingerprint( $ip );
	}

	/**
	 * Whether the address is allowlisted. An allowlisted address skips
	 * every check; `check_all()` and the read trait both ask it (#259,
	 * #2339).
	 *
	 * @since 6.6.2
	 * @param string $ip Client IP.
	 * @return bool
	 */
	public static function is_ip_whitelisted( string $ip ): bool {
		$wl  = self::get_settings()['whitelist'] ?? array();
		$ips = is_array( $wl ) && isset( $wl['ips'] ) && is_array( $wl['ips'] ) ? $wl['ips'] : array();
		return \FreeFormCertificate\Core\IpAddress::in_any( $ip, $ips );
	}

	/**
	 * Read the `settings['read']['endpoints'][$key]` slot, or `null`
	 * when the endpoint isn't configured. Centralizes the lookup so
	 * `check_read_limit` + future read methods share the schema shape.
	 *
	 * @param array<string, mixed> $settings     Full rate-limit settings array.
	 * @param string               $endpoint_key Endpoint identifier.
	 * @return array<string, mixed>|null
	 */
	private static function read_endpoint_settings( array $settings, string $endpoint_key ): ?array {
		$read = $settings['read'] ?? array();
		if ( ! is_array( $read ) ) {
			return null;
		}
		$endpoints = $read['endpoints'] ?? array();
		$stored    = is_array( $endpoints ) && isset( $endpoints[ $endpoint_key ] ) && is_array( $endpoints[ $endpoint_key ] ) ? $endpoints[ $endpoint_key ] : null;
		$default   = self::READ_ENDPOINT_DEFAULTS[ $endpoint_key ] ?? null;
		if ( null === $default ) {
			return $stored;
		}
		return null === $stored ? $default : array_replace( $default, $stored );
	}
}
