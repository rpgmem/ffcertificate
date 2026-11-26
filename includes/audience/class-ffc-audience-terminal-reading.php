<?php
/**
 * Audience Terminal Reading
 *
 * What a paired terminal does with a presence code it reads (#2745).
 *
 * @package FreeFormCertificate\Audience
 * @since 6.50.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

use FreeFormCertificate\Core\ActivityLog;
use FreeFormCertificate\Core\AttendanceTargetRegistry;
use FreeFormCertificate\Core\TerminalTargetKey;
use FreeFormCertificate\Core\TerminalVerdict;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns one reading into attendance, or into a refusal that says what to do.
 *
 * The code is checked by its one verifier ({@see AudiencePresenceCode}), with
 * the revocation list, and then against what the terminal was paired for:
 * the target is one of the terminal's, or was when the code was read. What
 * the reading then means is the target's to say
 * ({@see \FreeFormCertificate\Core\TerminalTargetInterface::terminal_read()}),
 * whichever module it belongs to (#2890); for an audience booking the rules
 * are in {@see AudienceAttendanceTarget}. A reading is judged at the moment
 * it was taken: when the site receives it online, or the moment the terminal
 * read it when it sends a queue it kept offline.
 *
 * What a result shows is the person's short name ("Maria S.") and nothing
 * else; a refusal shows no name at all, so a terminal never says who tried.
 *
 * @phpstan-import-type TerminalRow from AudienceTerminalReader
 * @phpstan-import-type Verdict from \FreeFormCertificate\Core\TerminalVerdict
 * @phpstan-type ReadingResult Verdict
 */
final class AudienceTerminalReading {

	/**
	 * Attendance was recorded.
	 */
	public const RECORDED = TerminalVerdict::RECORDED;

	/**
	 * The person was already recorded today.
	 */
	public const ALREADY = TerminalVerdict::ALREADY;

	/**
	 * The reading was refused.
	 */
	public const REFUSED = TerminalVerdict::REFUSED;

	/**
	 * The refusal of a queued reading a revoked terminal took after its
	 * revocation (#2802).
	 */
	public const AFTER_REVOCATION = 'ffc_terminal_after_revocation';

	/**
	 * How many readings one sync may carry.
	 */
	public const SYNC_BATCH = 100;

	/**
	 * Read a code now, online.
	 *
	 * @param array  $terminal Terminal row.
	 * @param string $code     The text the reader typed.
	 * @param int    $now      Unix time.
	 * @phpstan-param TerminalRow $terminal
	 * @return ReadingResult
	 */
	public static function read( array $terminal, string $code, int $now ): array {
		return self::evaluate( $terminal, $code, $now );
	}

	/**
	 * Read a code the terminal queued offline, as of the moment it read it
	 * (#2745).
	 *
	 * Everything is checked at that moment: the code's own freshness, the
	 * keys revoked by then, the window open then. A phone replaced after the
	 * reading does not undo it; a reading taken after the replacement is
	 * refused. The moment is the terminal's clock, so it is bounded: not
	 * before the terminal was paired, not after the site's now plus the
	 * clock tolerance, and not older than an attestation lives.
	 *
	 * @param array  $terminal Terminal row.
	 * @param string $code     The text the reader typed.
	 * @param int    $at       When the terminal read it.
	 * @param int    $now      The site's Unix time.
	 * @phpstan-param TerminalRow $terminal
	 * @return ReadingResult
	 */
	public static function read_queued( array $terminal, string $code, int $at, int $now ): array {
		if ( $at > $now + AudiencePresenceCode::SKEW || $at < $terminal['paired_at'] - AudiencePresenceCode::SKEW || $at < $now - AudiencePresenceAttestation::TTL ) {
			return self::refused( 'ffc_terminal_reading_time', __( 'This reading was taken at a time this terminal cannot vouch for, and was not recorded.', 'ffcertificate' ) );
		}
		return self::evaluate( $terminal, $code, $at );
	}

	/**
	 * Read a batch the terminal queued offline. Sending the same batch twice
	 * records nothing twice: a person already recorded that day comes back
	 * as already recorded.
	 *
	 * A refused reading is logged with its reason, never with the code or a
	 * name, so a reading taken after a revocation leaves a trace.
	 *
	 * A revoked terminal still sends its queue (#2802): a reading it took
	 * before the revocation is judged as any other, a later one is refused
	 * and counted on the terminal.
	 *
	 * @param array $terminal Terminal row.
	 * @param array $readings Each `{id, code, at}`.
	 * @param int   $now      Unix time.
	 * @phpstan-param TerminalRow $terminal
	 * @phpstan-param list<array{id: string, code: string, at: int}> $readings
	 * @return array<string, ReadingResult> Results by the terminal's reading id.
	 */
	public static function sync( array $terminal, array $readings, int $now ): array {
		$results = array();
		$refused = array();
		$late    = 0;
		foreach ( array_slice( $readings, 0, self::SYNC_BATCH ) as $reading ) {
			if ( null !== $terminal['revoked_at'] && $reading['at'] >= $terminal['revoked_at'] ) {
				$result = self::refused( self::AFTER_REVOCATION, __( 'This terminal was revoked before this reading, so it was not recorded.', 'ffcertificate' ) );
				++$late;
			} else {
				$result = self::read_queued( $terminal, $reading['code'], $reading['at'], $now );
			}
			$results[ $reading['id'] ] = $result;
			if ( self::REFUSED === $result['status'] ) {
				$refused[ $result['code'] ] = ( $refused[ $result['code'] ] ?? 0 ) + 1;
			}
		}
		AudienceTerminalWriter::count_refused_after_revocation( $terminal['id'], $late );
		if ( array() !== $refused ) {
			ActivityLog::log(
				'terminal_readings_refused',
				ActivityLog::LEVEL_WARNING,
				array(
					'terminal_id' => $terminal['id'],
					'reasons'     => $refused,
				)
			);
		}
		return $results;
	}

	/**
	 * Read a code as of a moment.
	 *
	 * @param array  $terminal Terminal row.
	 * @param string $code     The text the reader typed.
	 * @param int    $at       The moment of the reading.
	 * @phpstan-param TerminalRow $terminal
	 * @return ReadingResult
	 */
	private static function evaluate( array $terminal, string $code, int $at ): array {
		$revoked = AudiencePresenceReader::revoked_key_ids_between( $at - AudiencePresenceAttestation::TTL, $at );
		$reading = AudiencePresenceCode::verify( $code, $at, $revoked );
		if ( is_wp_error( $reading ) ) {
			return self::refused( (string) $reading->get_error_code(), $reading->get_error_message() );
		}

		$key = TerminalTargetKey::of( $reading['target_type'], $reading['target_id'] );
		if ( ! AudienceTerminalPairing::held_at( $terminal, $key, $at ) ) {
			return self::refused( 'ffc_terminal_other_event', __( 'This code is for an event this terminal does not check in. Choose this event on the phone, or use the terminal at its entrance.', 'ffcertificate' ) );
		}
		$found = AttendanceTargetRegistry::terminal( $key );
		if ( null === $found ) {
			return self::refused( 'ffc_terminal_other_event', __( 'This code is for an event this terminal does not check in. Choose this event on the phone, or use the terminal at its entrance.', 'ffcertificate' ) );
		}

		$result = $found['target']->terminal_read( $found['id'], $reading['user_id'], $reading['name'], $reading['ticket'], $at );
		if ( self::RECORDED === $result['status'] ) {
			AudienceTerminalWriter::count_reading( $terminal['id'] );
		}
		return $result;
	}

	/**
	 * A refusal, with no name in it.
	 *
	 * @param string $code    Reason code.
	 * @param string $message What to do.
	 * @return ReadingResult
	 */
	private static function refused( string $code, string $message ): array {
		return TerminalVerdict::refused( $code, $message );
	}
}
