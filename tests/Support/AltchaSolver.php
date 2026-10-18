<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Support;

/**
 * Solve an ALTCHA challenge the way the 3.x widget does (#1928).
 *
 * Counts up from zero, deriving `SHA-256(salt || nonce || uint32_be(counter))`
 * until the key starts with `keyPrefix`, then builds the base64 payload the
 * widget posts. Solving for real, rather than reading the counter out of the
 * class under test, is what keeps a test honest about the wire format: a
 * payload built from the class's own internals would pass even after the
 * format drifted away from what the widget sends.
 *
 * Shared because two test classes solve challenges, and two solvers are two
 * opinions about the format.
 *
 * @package FreeFormCertificate\Tests\Support
 */
final class AltchaSolver {

	/**
	 * The counter that solves a challenge, or null when none up to the ceiling does.
	 *
	 * @param array<string, mixed> $challenge Challenge as issued.
	 * @param int                  $ceiling   Highest counter to try.
	 * @return int|null
	 */
	public static function counter_of( array $challenge, int $ceiling ): ?int {
		$parameters = $challenge['parameters'];
		$prefix     = (string) $parameters['keyPrefix'];

		for ( $counter = 0; $counter <= $ceiling; $counter++ ) {
			if ( 0 === strpos( self::key( $parameters, $counter ), $prefix ) ) {
				return $counter;
			}
		}

		return null;
	}

	/**
	 * The payload the widget posts for a solved challenge.
	 *
	 * @param array<string, mixed> $challenge Challenge as issued.
	 * @param int                  $counter   Counter to post.
	 * @return string Base64 JSON.
	 */
	public static function payload( array $challenge, int $counter ): string {
		return self::encode(
			array(
				'challenge' => array(
					'parameters' => $challenge['parameters'],
					'signature'  => $challenge['signature'],
				),
				'solution'  => array(
					'counter'    => $counter,
					'derivedKey' => self::key( $challenge['parameters'], $counter ),
					'time'       => 12.3,
				),
			)
		);
	}

	/**
	 * The derived key for a counter, as lowercase hex.
	 *
	 * @param array<string, mixed> $parameters Challenge parameters.
	 * @param int                  $counter    Counter.
	 * @return string
	 */
	public static function key( array $parameters, int $counter ): string {
		return hash( 'sha256', (string) hex2bin( (string) $parameters['salt'] ) . (string) hex2bin( (string) $parameters['nonce'] ) . pack( 'N', $counter ) );
	}

	/**
	 * Base64 JSON, the widget's encoding.
	 *
	 * @param array<string, mixed> $payload Payload.
	 * @return string
	 */
	public static function encode( array $payload ): string {
		return base64_encode( (string) json_encode( $payload ) );
	}
}
