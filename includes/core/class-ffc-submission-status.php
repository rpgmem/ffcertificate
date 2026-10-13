<?php
/**
 * SubmissionStatus
 *
 * The statuses a certificate submission can carry, and which of them is a
 * certificate. A quiz attempt is stored as a submission whatever its result,
 * so "the row exists" never meant "the certificate exists" (#1744): every
 * place that hands a certificate out asks this class first.
 *
 * @package FreeFormCertificate\Core
 * @since 6.39.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Submission status values and the certificate rule.
 */
final class SubmissionStatus {

	/**
	 * An issued certificate.
	 */
	public const PUBLISH = 'publish';

	/**
	 * Moved to the trash.
	 */
	public const TRASH = 'trash';

	/**
	 * A quiz attempt below the passing score, with attempts left.
	 */
	public const QUIZ_IN_PROGRESS = 'quiz_in_progress';

	/**
	 * A quiz attempt below the passing score, with no attempt left.
	 */
	public const QUIZ_FAILED = 'quiz_failed';

	/**
	 * Quiz statuses that are a record of an attempt, not a certificate.
	 *
	 * @var array<int, string>
	 */
	public const UNPASSED_QUIZ = array( self::QUIZ_IN_PROGRESS, self::QUIZ_FAILED );

	/**
	 * Statuses no participant-facing surface may treat as a certificate.
	 *
	 * @var array<int, string>
	 */
	public const NOT_A_CERTIFICATE = array( self::TRASH, self::QUIZ_IN_PROGRESS, self::QUIZ_FAILED );

	/**
	 * Whether a submission row is a quiz attempt that did not pass.
	 *
	 * A row without a status reads as a certificate, as it always has.
	 *
	 * @param array<array-key, mixed>|object|null $submission Submission row.
	 */
	public static function is_unpassed_quiz( $submission ): bool {
		$status = is_array( $submission ) ? ( $submission['status'] ?? '' ) : ( is_object( $submission ) ? ( $submission->status ?? '' ) : '' );
		return is_string( $status ) && in_array( $status, self::UNPASSED_QUIZ, true );
	}
}
