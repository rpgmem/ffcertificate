<?php
/**
 * DeviceSignalsResolver — pipeline stage 10 (#563 Sprint 1).
 *
 * Resolves the device-fingerprint signals + role bypass before the
 * consolidated rate-limit check. The actual N-of-M test runs inside
 * RateLimiter::check_all(); this guard only decodes/validates the posted
 * signals and decides whether the per-device gate is bypassed for a
 * manager. Never rejects.
 *
 * Runs after NonceGuard, so the $_POST read here is nonce-verified.
 *
 * @package FreeFormCertificate\Frontend\Submission
 */

declare(strict_types=1);

namespace FreeFormCertificate\Frontend\Submission;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Device-fingerprint signal resolution (no rejection).
 */
class DeviceSignalsResolver {

	/**
	 * Populate device signals + skip-device flag on the context.
	 *
	 * @param SubmissionContext $ctx Submission context.
	 */
	public function apply( SubmissionContext $ctx ): void {
		$device_signals = null;
		$skip_device    = false;
		if ( class_exists( '\FreeFormCertificate\Security\RateLimiter' ) ) {
			$rl_settings         = \FreeFormCertificate\Security\RateLimiter::get_settings();
			$device_globally_on  = ! empty( $rl_settings['device']['enabled'] );
			$device_form_enabled = '1' === (string) get_post_meta( $ctx->form_id, '_ffc_device_limit_enabled', true );

			if ( $device_globally_on && $device_form_enabled ) {
				if ( \FreeFormCertificate\Security\RateLimiter::should_bypass_for_manager() ) {
					$skip_device = true;
					\FreeFormCertificate\Security\RateLimiter::log_attempt(
						'device',
						(string) get_current_user_id(),
						'allowed',
						'manager_bypass',
						$ctx->form_id
					);
				} else {
					// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified upstream by NonceGuard; signals validated by strict hex regex below.
					$raw_signals = isset( $_POST['ffc_device_signals'] ) ? wp_unslash( $_POST['ffc_device_signals'] ) : '';
					if ( is_string( $raw_signals ) && '' !== $raw_signals ) {
						$decoded = json_decode( $raw_signals, true );
						if ( is_array( $decoded ) ) {
							$clean_signals = array();
							foreach ( array( 'cookie', 'ua', 'screen', 'tz', 'concurrency', 'memory', 'canvas', 'audio', 'webgl', 'fonts' ) as $sig_key ) {
								if ( isset( $decoded[ $sig_key ] ) && is_string( $decoded[ $sig_key ] ) && preg_match( '/^[a-f0-9]{64}$/i', $decoded[ $sig_key ] ) ) {
									$clean_signals[ $sig_key ] = strtolower( $decoded[ $sig_key ] );
								}
							}
							if ( ! empty( $clean_signals ) ) {
								$device_signals = $clean_signals;
							}
						}
					}
				}
			}
		}

		$ctx->device_signals = $device_signals;
		$ctx->skip_device    = $skip_device;
	}
}
