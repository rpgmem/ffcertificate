<?php
/**
 * Attendance
 *
 * The one write path for attendance, whatever records it (#2740). A caller
 * names a target as a type and an id; the module registered for that type
 * stores the record. The methods and results are shared, so every module
 * speaks one vocabulary and every screen can name how a person was recorded.
 *
 * @package FreeFormCertificate\Core
 * @since   6.46.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes an attendance reading to its target.
 */
final class Attendance {

	/**
	 * The person scanned the QR code an operator projects.
	 */
	public const METHOD_QR = 'qr';

	/**
	 * An operator marked the person by hand.
	 */
	public const METHOD_MANUAL = 'manual';

	/**
	 * A self-service terminal read the person's own QR code (#2738).
	 */
	public const METHOD_TERMINAL = 'terminal';

	/**
	 * Every method a record may carry, in the order they were introduced.
	 */
	public const METHODS = array( self::METHOD_QR, self::METHOD_MANUAL, self::METHOD_TERMINAL );

	/**
	 * The record was written.
	 */
	public const RECORDED = 'recorded';

	/**
	 * The person was already recorded for that target and day; nothing changed.
	 */
	public const ALREADY = 'already';

	/**
	 * Nothing was written: an unknown target type, a bad argument, or a
	 * database failure.
	 */
	public const FAILED = 'failed';

	/**
	 * Record a person present at a target.
	 *
	 * @param string   $type       A type registered in {@see AttendanceTargetRegistry}.
	 * @param int      $target_id  Id within the type.
	 * @param int      $user_id    The person.
	 * @param int      $checked_at Unix UTC seconds of the reading.
	 * @param string   $method     One of {@see self::METHODS}.
	 * @param int|null $marked_by  The operator, for a mark by hand.
	 * @return string {@see self::RECORDED}, {@see self::ALREADY} or {@see self::FAILED}.
	 */
	public static function record( string $type, int $target_id, int $user_id, int $checked_at, string $method, ?int $marked_by = null ): string {
		if ( $target_id <= 0 || $user_id <= 0 || $checked_at <= 0 || ! self::is_method( $method ) ) {
			return self::FAILED;
		}

		$target = AttendanceTargetRegistry::get( $type );
		if ( null === $target ) {
			return self::FAILED;
		}

		return $target->record( $target_id, $user_id, $checked_at, $method, $marked_by );
	}

	/**
	 * Whether a value is a known method.
	 *
	 * @param string $method Method.
	 * @return bool
	 */
	public static function is_method( string $method ): bool {
		return in_array( $method, self::METHODS, true );
	}

	/**
	 * How a person was recorded, in a few words for a table cell.
	 *
	 * @param string $method Method.
	 * @return string
	 */
	public static function short_label( string $method ): string {
		switch ( $method ) {
			case self::METHOD_MANUAL:
				return __( 'by hand', 'ffcertificate' );
			case self::METHOD_TERMINAL:
				return __( 'terminal', 'ffcertificate' );
			default:
				return __( 'QR', 'ffcertificate' );
		}
	}

	/**
	 * How a person was recorded, as a sentence-case phrase for an export.
	 *
	 * @param string $method Method.
	 * @return string
	 */
	public static function label( string $method ): string {
		switch ( $method ) {
			case self::METHOD_MANUAL:
				return __( 'Marked by staff', 'ffcertificate' );
			case self::METHOD_TERMINAL:
				return __( 'Self-service terminal', 'ffcertificate' );
			default:
				return __( 'QR check-in', 'ffcertificate' );
		}
	}
}
