<?php
/**
 * RotationFailureRegisterTrait
 *
 * The rows a key rotation could not re-encrypt, kept in its state (#2338).
 *
 * A rotation walks a cursor, so a row that fails is passed and never read
 * again on that walk. Before this register the failure lived only in the
 * batch that hit it: the next batch, finding nothing past the cursor, reported
 * no errors and latched the rotation complete with those rows still under the
 * previous key. Rotating the WordPress salts afterwards would make them
 * unreadable. The register counts and lists every failure of the walk, keeps
 * the rotation incomplete while it holds any, and is cleared only by a new
 * walk: a changed key, or the card's retry.
 *
 * @package FreeFormCertificate\Migrations\Strategies
 */

declare(strict_types=1);

namespace FreeFormCertificate\Migrations\Strategies;

use FreeFormCertificate\Core\ArrayValue;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Count and list of a walk's failures, stored in the strategy's state array.
 */
trait RotationFailureRegisterTrait {

	/**
	 * Most failure messages kept; the count goes on past it.
	 *
	 * @return int
	 */
	private static function failure_list_max(): int {
		return 50;
	}

	/**
	 * The state with a batch's failures added.
	 *
	 * @param array<string, mixed> $state  Strategy state.
	 * @param array<int, mixed>    $errors The batch's error messages.
	 * @return array<string, mixed>
	 */
	private static function with_failures( array $state, array $errors ): array {
		$messages = array_values(
			array_filter(
				array_map( static fn( $e ): string => is_scalar( $e ) ? (string) $e : '', $errors ),
				static fn( string $e ): bool => '' !== $e
			)
		);
		if ( array() === $messages ) {
			return $state;
		}

		$list = self::failure_list( $state );
		foreach ( $messages as $message ) {
			if ( count( $list ) < self::failure_list_max() ) {
				$list[] = $message;
			}
		}

		$state['failures']      = $list;
		$state['failure_count'] = self::failure_count( $state ) + count( $messages );

		return $state;
	}

	/**
	 * How many failures the walk has met.
	 *
	 * @param array<string, mixed> $state Strategy state.
	 * @return int
	 */
	private static function failure_count( array $state ): int {
		return max( 0, ArrayValue::int( $state, 'failure_count' ) );
	}

	/**
	 * The failure messages kept.
	 *
	 * @param array<string, mixed> $state Strategy state.
	 * @return array<int, string>
	 */
	private static function failure_list( array $state ): array {
		return array_values( array_filter( ArrayValue::array( $state, 'failures' ), 'is_string' ) );
	}

	/**
	 * The state with the register cleared, for a new walk.
	 *
	 * @param array<string, mixed> $state Strategy state.
	 * @return array<string, mixed>
	 */
	private static function without_failures( array $state ): array {
		unset( $state['failures'], $state['failure_count'] );
		return $state;
	}

	/**
	 * A status with the walk's failures applied: they stay pending, the
	 * status lists them, and it offers the retry once nothing is left past
	 * the cursor.
	 *
	 * @param array<string, mixed> $status Status as measured by the cursor.
	 * @param array<string, mixed> $state  Strategy state.
	 * @return array<string, mixed>
	 */
	private static function status_with_failures( array $status, array $state ): array {
		$failed = self::failure_count( $state );
		if ( 0 === $failed ) {
			return $status;
		}

		$total    = ArrayValue::int( $status, 'total' );
		$walk_end = 0 === ArrayValue::int( $status, 'pending' );
		$pending  = min( $total, ArrayValue::int( $status, 'pending' ) + $failed );
		$migrated = max( 0, $total - $pending );

		$status['pending']      = $pending;
		$status['migrated']     = $migrated;
		$status['percent']      = $total > 0 ? round( ( $migrated / $total ) * 100, 2 ) : 100.0;
		$status['is_complete']  = false;
		$status['failed']       = $failed;
		$status['failures']     = self::failure_list( $state );
		$status['salt_warning'] = true;
		$status['retryable']    = $walk_end;

		return $status;
	}
}
