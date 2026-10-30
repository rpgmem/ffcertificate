<?php
/**
 * RateLimiter v3.3.0
 * Advanced rate limiting system (transient-backed short-window counters)
 *
 * V3.3.0: Added strict types and type hints
 * v3.2.0: Migrated to namespace
 * 6.40.0: the short-window counters are transients again ({@see RateCounter}).
 *         The object cache alone does not persist without a Redis/Memcached
 *         drop-in, which reset every counter on every request (#1861).
 *
 * S4 refactor: this class is now a thin facade that forwards each public
 * static method to {@see RateLimitChecker}, {@see RateLimitLogger} or
 * {@see RateLimitStats}. The public API (method signatures and the
 * CACHE_GROUP constant) is preserved for external callers.
 *
 * @package FreeFormCertificate\Security
 */

declare(strict_types=1);

namespace FreeFormCertificate\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rate Limiter (facade).
 *
 * @phpstan-import-type RateLimitVerdict from RateLimitChecker
 */
class RateLimiter {

	/**
	 * Cache group the counters used before they moved to transients
	 * (#1861). Kept because it is public API; nothing here reads it.
	 *
	 * @since 3.2.0
	 */
	const CACHE_GROUP = 'ffc_rate_limit';

	/**
	 * Forwards to {@see RateLimitChecker::get_settings()}.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_settings(): array {
		return RateLimitChecker::get_settings();
	}

	/**
	 * Forwards to {@see RateLimitChecker::check_all()}.
	 *
	 * @param string                     $ip IP address.
	 * @param string|null                $email Email address.
	 * @param string|null                $cpf CPF document.
	 * @param int|null                   $form_id Form ID.
	 * @param array<string, string>|null $device_signals Device fingerprint hashes.
	 * @param bool                       $skip_device Set true to bypass the device fingerprint check.
	 * @return RateLimitVerdict
	 */
	public static function check_all( string $ip, ?string $email = null, ?string $cpf = null, ?int $form_id = null, ?array $device_signals = null, bool $skip_device = false ): array {
		return RateLimitChecker::check_all( $ip, $email, $cpf, $form_id, $device_signals, $skip_device );
	}

	/**
	 * Forwards to {@see RateLimitChecker::check_ip_limit()}.
	 *
	 * @param string   $ip IP address.
	 * @param int|null $form_id Form ID.
	 * @return RateLimitVerdict
	 */
	public static function check_ip_limit( string $ip, ?int $form_id = null ): array {
		return RateLimitChecker::check_ip_limit( $ip, $form_id );
	}

	/**
	 * Forwards to {@see RateLimitChecker::check_email_limit()}.
	 *
	 * @param string   $email Email address.
	 * @param int|null $form_id Form ID.
	 * @return RateLimitVerdict
	 */
	public static function check_email_limit( string $email, ?int $form_id = null ): array {
		return RateLimitChecker::check_email_limit( $email, $form_id );
	}

	/**
	 * Forwards to {@see RateLimitChecker::check_cpf_limit()}.
	 *
	 * @param string   $cpf CPF document.
	 * @param int|null $form_id Form ID.
	 * @return RateLimitVerdict
	 */
	public static function check_cpf_limit( string $cpf, ?int $form_id = null ): array {
		return RateLimitChecker::check_cpf_limit( $cpf, $form_id );
	}

	/**
	 * Forwards to {@see RateLimitChecker::check_global_limit()}.
	 *
	 * @return RateLimitVerdict
	 */
	public static function check_global_limit(): array {
		return RateLimitChecker::check_global_limit();
	}

	/**
	 * Forwards to {@see RateLimitChecker::should_bypass_for_manager()}.
	 *
	 * @return bool
	 */
	public static function should_bypass_for_manager(): bool {
		return RateLimitChecker::should_bypass_for_manager();
	}

	/**
	 * Forwards to {@see RateLimitChecker::get_device_effective_settings()}.
	 *
	 * @param int $form_id Form post ID.
	 * @return array{max: int, threshold: int, message: string}
	 */
	public static function get_device_effective_settings( int $form_id ): array {
		return RateLimitChecker::get_device_effective_settings( $form_id );
	}

	/**
	 * Forwards to {@see RateLimitChecker::check_device_limit()}.
	 *
	 * @param int                   $form_id Form ID (post ID).
	 * @param array<string, string> $signals Map of signal name -> SHA-256 hex hash.
	 * @return RateLimitVerdict
	 */
	public static function check_device_limit( int $form_id, array $signals ): array {
		return RateLimitChecker::check_device_limit( $form_id, $signals );
	}

	/**
	 * Forwards to {@see RateLimitChecker::record_device_signals()}.
	 *
	 * @param int|null              $submission_id Submission row id (FK).
	 * @param int                   $form_id       Form post ID.
	 * @param array<string, string> $signals       Signal hashes.
	 */
	public static function record_device_signals( ?int $submission_id, int $form_id, array $signals ): void {
		RateLimitChecker::record_device_signals( $submission_id, $form_id, $signals );
	}

	/**
	 * Forwards to {@see RateLimitChecker::check_verification()}.
	 *
	 * The `$token` argument this carried until 6.24.0 is gone (#1048, announced
	 * 6.22.0): it was accepted and never read, which on a Security method is
	 * worse than absent — the signature advertised a per-token limit that did
	 * not exist. Passing it does no harm on the way out, because PHP ignores
	 * surplus arguments to a userland function.
	 *
	 * @param string $ip IP address.
	 * @return RateLimitVerdict
	 */
	public static function check_verification( string $ip ): array {
		return RateLimitChecker::check_verification( $ip );
	}

	/**
	 * Forwards to {@see RateLimitChecker::record_attempt()}.
	 *
	 * @param string   $type Type.
	 * @param string   $identifier Identifier.
	 * @param int|null $form_id Form ID.
	 */
	public static function record_attempt( string $type, string $identifier, ?int $form_id = null ): void {
		RateLimitChecker::record_attempt( $type, $identifier, $form_id );
	}

	/**
	 * Forwards to {@see RateLimitChecker::check_read_limit()}.
	 *
	 * @since 6.6.2
	 * @param string $ip           Client IP.
	 * @param string $endpoint_key Endpoint identifier (e.g. `calendar_slots`).
	 * @param int    $user_id      Account to count instead of the address, or 0.
	 * @return RateLimitVerdict
	 */
	public static function check_read_limit( string $ip, string $endpoint_key, int $user_id = 0 ): array {
		return RateLimitChecker::check_read_limit( $ip, $endpoint_key, $user_id );
	}

	/**
	 * Forwards to {@see RateLimitChecker::record_read_attempt()}.
	 *
	 * @since 6.6.2
	 * @param string $ip           Client IP.
	 * @param string $endpoint_key Endpoint identifier.
	 * @param int    $user_id      Account to count instead of the address, or 0.
	 * @return void
	 */
	public static function record_read_attempt( string $ip, string $endpoint_key, int $user_id = 0 ): void {
		RateLimitChecker::record_read_attempt( $ip, $endpoint_key, $user_id );
	}

	/**
	 * Forwards to {@see RateLimitChecker::check_user_limit()}.
	 *
	 * @param int    $user_id WordPress user ID.
	 * @param string $action  Action identifier.
	 * @param int    $max_per_hour Max attempts per hour.
	 * @param int    $max_per_day  Max attempts per day.
	 * @return RateLimitVerdict
	 */
	public static function check_user_limit( int $user_id, string $action = 'default', int $max_per_hour = 5, int $max_per_day = 10 ): array {
		return RateLimitChecker::check_user_limit( $user_id, $action, $max_per_hour, $max_per_day );
	}

	/**
	 * Forwards to {@see RateLimitLogger::log_attempt()}.
	 *
	 * @param string   $type Type.
	 * @param string   $identifier Identifier.
	 * @param string   $action Action name.
	 * @param string   $reason Reason.
	 * @param int|null $form_id Form ID.
	 */
	public static function log_attempt( string $type, string $identifier, string $action, string $reason, ?int $form_id ): void {
		RateLimitLogger::log_attempt( $type, $identifier, $action, $reason, $form_id );
	}

	/**
	 * Forwards to {@see RateLimitLogger::cleanup_expired()}.
	 *
	 * @return int
	 */
	public static function cleanup_expired(): int {
		return RateLimitLogger::cleanup_expired();
	}

	/**
	 * Forwards to {@see RateLimitStats::get_stats()}.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_stats(): array {
		return RateLimitStats::get_stats();
	}
}
