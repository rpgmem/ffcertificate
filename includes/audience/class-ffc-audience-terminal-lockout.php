<?php
/**
 * Audience Terminal Lockout
 *
 * Counts wrong pairing codes on the server (#2744), per device and per
 * address.
 *
 * @package FreeFormCertificate\Audience
 * @since 6.50.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

use FreeFormCertificate\Security\RateCounter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The pairing lockout.
 *
 * - **Per device:** three wrong codes in a row lock it for 15 minutes; each
 *   further wrong code locks it for 30, the ceiling.
 * - **Per address:** at most 20 wrong codes an hour across every device, so
 *   one tablet cannot lock a whole school out behind one NAT, and a device
 *   that changes its id still meets a wall.
 * - **Reset:** a correct pairing, or an hour since the device's last
 *   attempt.
 *
 * Nothing here stores a code. The device is keyed by the keyed hash of the
 * id it sent, and the address by its keyed fingerprint.
 */
final class AudienceTerminalLockout {

	/**
	 * Wrong codes in a row before the first lock.
	 */
	public const FREE_TRIES = 3;

	/**
	 * The first lock, in seconds.
	 */
	public const FIRST_LOCK = 900;

	/**
	 * Every later lock, in seconds.
	 */
	public const NEXT_LOCK = 1800;

	/**
	 * Quiet time that forgets a device's count, in seconds.
	 */
	public const RESET_AFTER = 3600;

	/**
	 * Wrong codes an address may send in one window.
	 */
	public const ADDRESS_MAX = 20;

	/**
	 * The address window, in seconds.
	 */
	public const ADDRESS_WINDOW = 3600;

	/**
	 * Seconds left on a lock that applies, or 0 when the device may try.
	 *
	 * @param string $device_hash Keyed hash of the device id.
	 * @param string $address     Keyed fingerprint of the address.
	 * @param int    $now         Unix time.
	 * @return int
	 */
	public static function wait( string $device_hash, string $address, int $now ): int {
		$state  = self::state( $device_hash, $now );
		$device = max( 0, $state['locked_until'] - $now );

		$window = self::address_window( $now );
		$used   = RateCounter::get( self::address_key( $address, $window ) );
		$ip     = $used >= self::ADDRESS_MAX ? max( 0, ( $window + 1 ) * self::ADDRESS_WINDOW - $now ) : 0;

		return max( $device, $ip );
	}

	/**
	 * Count a wrong code.
	 *
	 * @param string $device_hash Keyed hash of the device id.
	 * @param string $address     Keyed fingerprint of the address.
	 * @param int    $now         Unix time.
	 * @return array{fails: int, wait: int} Wrong codes in a row, and the lock this one started (0 when none).
	 */
	public static function fail( string $device_hash, string $address, int $now ): array {
		$state = self::state( $device_hash, $now );
		$fails = $state['fails'] + 1;
		$lock  = 0;
		if ( self::FREE_TRIES === $fails ) {
			$lock = self::FIRST_LOCK;
		} elseif ( $fails > self::FREE_TRIES ) {
			$lock = self::NEXT_LOCK;
		}
		set_transient(
			self::device_key( $device_hash ),
			array(
				'fails'        => $fails,
				'locked_until' => $now + $lock,
				'last'         => $now,
			),
			self::RESET_AFTER + self::NEXT_LOCK
		);

		$window = self::address_window( $now );
		$used   = RateCounter::increment( self::address_key( $address, $window ), self::ADDRESS_WINDOW );
		$ip     = $used >= self::ADDRESS_MAX ? max( 0, ( $window + 1 ) * self::ADDRESS_WINDOW - $now ) : 0;

		return array(
			'fails' => $fails,
			'wait'  => max( $lock, $ip ),
		);
	}

	/**
	 * Forget a device's count after a correct pairing.
	 *
	 * @param string $device_hash Keyed hash of the device id.
	 * @return void
	 */
	public static function succeed( string $device_hash ): void {
		delete_transient( self::device_key( $device_hash ) );
	}

	/**
	 * A device's count, forgotten after an hour without attempts.
	 *
	 * @param string $device_hash Keyed hash of the device id.
	 * @param int    $now         Unix time.
	 * @return array{fails: int, locked_until: int, last: int}
	 */
	private static function state( string $device_hash, int $now ): array {
		$raw   = get_transient( self::device_key( $device_hash ) );
		$state = array(
			'fails'        => 0,
			'locked_until' => 0,
			'last'         => 0,
		);
		if ( is_array( $raw ) ) {
			$state = array(
				'fails'        => isset( $raw['fails'] ) && is_numeric( $raw['fails'] ) ? (int) $raw['fails'] : 0,
				'locked_until' => isset( $raw['locked_until'] ) && is_numeric( $raw['locked_until'] ) ? (int) $raw['locked_until'] : 0,
				'last'         => isset( $raw['last'] ) && is_numeric( $raw['last'] ) ? (int) $raw['last'] : 0,
			);
		}
		if ( $state['last'] > 0 && $now - $state['last'] >= self::RESET_AFTER && $state['locked_until'] <= $now ) {
			$state = array(
				'fails'        => 0,
				'locked_until' => 0,
				'last'         => 0,
			);
		}
		return $state;
	}

	/**
	 * The transient that holds a device's count.
	 *
	 * @param string $device_hash Keyed hash of the device id.
	 * @return string
	 */
	private static function device_key( string $device_hash ): string {
		return 'ffc_term_lock_' . substr( $device_hash, 0, 32 );
	}

	/**
	 * The current address window's number.
	 *
	 * @param int $now Unix time.
	 * @return int
	 */
	private static function address_window( int $now ): int {
		return intdiv( $now, self::ADDRESS_WINDOW );
	}

	/**
	 * The counter of one address in one window.
	 *
	 * @param string $address Keyed fingerprint of the address.
	 * @param int    $window  Window number.
	 * @return string
	 */
	private static function address_key( string $address, int $window ): string {
		return 'ffc_term_ip_' . substr( $address, 0, 24 ) . '_' . $window;
	}
}
