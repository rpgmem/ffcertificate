<?php
/**
 * RateLimiter v3.3.0
 * Advanced rate limiting system with WordPress Object Cache API
 *
 * V3.3.0: Added strict types and type hints
 * v3.2.0: Migrated to namespace (Phase 2)
 *         Migrated from transients to WordPress Object Cache API
 *         - Automatically uses Redis/Memcached if available (via LiteSpeed Cache, etc.)
 *         - Falls back to transients if no object cache plugin is installed
 *         - Significant performance improvement for high-traffic sites
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
 */
class RateLimiter {

	/**
	 * Cache group for WordPress Object Cache API
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
	 * @return array{allowed: bool, message?: string, reason?: string, wait_seconds?: int}
	 */
	public static function check_all( string $ip, ?string $email = null, ?string $cpf = null, ?int $form_id = null, ?array $device_signals = null, bool $skip_device = false ): array {
		return RateLimitChecker::check_all( $ip, $email, $cpf, $form_id, $device_signals, $skip_device );
	}

	/**
	 * Forwards to {@see RateLimitChecker::check_ip_limit()}.
	 *
	 * @param string   $ip IP address.
	 * @param int|null $form_id Form ID.
	 * @return array{allowed: bool, message?: string, reason?: string, wait_seconds?: int}
	 */
	public static function check_ip_limit( string $ip, ?int $form_id = null ): array {
		return RateLimitChecker::check_ip_limit( $ip, $form_id );
	}

	/**
	 * Forwards to {@see RateLimitChecker::check_email_limit()}.
	 *
	 * @param string   $email Email address.
	 * @param int|null $form_id Form ID.
	 * @return array{allowed: bool, message?: string, reason?: string, wait_seconds?: int}
	 */
	public static function check_email_limit( string $email, ?int $form_id = null ): array {
		return RateLimitChecker::check_email_limit( $email, $form_id );
	}

	/**
	 * Forwards to {@see RateLimitChecker::check_cpf_limit()}.
	 *
	 * @param string   $cpf CPF document.
	 * @param int|null $form_id Form ID.
	 * @return array{allowed: bool, message?: string, reason?: string, wait_seconds?: int}
	 */
	public static function check_cpf_limit( string $cpf, ?int $form_id = null ): array {
		return RateLimitChecker::check_cpf_limit( $cpf, $form_id );
	}

	/**
	 * Forwards to {@see RateLimitChecker::check_global_limit()}.
	 *
	 * @return array{allowed: bool, message?: string, reason?: string, wait_seconds?: int}
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
	 * @return array{allowed: bool, message?: string, reason?: string, wait_seconds?: int}
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
	 * @param string      $ip IP address.
	 * @param string|null $token Token.
	 * @return array{allowed: bool, message?: string, wait_seconds?: int}
	 */
	public static function check_verification( string $ip, ?string $token = null ): array {
		return RateLimitChecker::check_verification( $ip, $token );
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
	 * Forwards to {@see RateLimitChecker::check_user_limit()}.
	 *
	 * @param int    $user_id WordPress user ID.
	 * @param string $action  Action identifier.
	 * @param int    $max_per_hour Max attempts per hour.
	 * @param int    $max_per_day  Max attempts per day.
	 * @return array{allowed: bool, message?: string, wait_seconds?: int}
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
