<?php
/**
 * Recruitment Notice Locks
 *
 * What each notice status lets an operator change (#2245).
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.44.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Recruitment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The one table of which change a notice takes in which status.
 *
 * The rules used to be written where each change happened, as a status
 * comparison at every site: the import, the promotion, deletion, the call
 * paths, and nothing at all for the preliminary statuses, the adjutancy
 * links or the admin undo. That is how a closed notice could still take an
 * undo and a definitive one an adjutancy change. Every write path now asks
 * {@see self::allows()}, and the notice screen asks the same question to
 * decide what it draws, so the screen and the API cannot disagree.
 *
 * The transitions between statuses are {@see RecruitmentNoticeStateMachine}'s;
 * this class says what may happen inside one. Each site keeps its own error
 * code, because the message names the change that was refused.
 *
 * | Change                         | draft | preliminary | definitive | closed |
 * |--------------------------------|:-----:|:-----------:|:----------:|:------:|
 * | Import the preliminary list    |   ✓   |      ✓      |            |        |
 * | Import the definitive list     |       |      ✓      |            |        |
 * | Promote the preliminary list   |       |      ✓      |            |        |
 * | Attach or detach adjutancies   |   ✓   |      ✓      |            |        |
 * | Preliminary statuses / reasons |       |      ✓      |            |        |
 * | Calls, outcomes, cancel, undo  |       |             |     ✓      |        |
 * | Delete the notice              |   ✓   |             |            |        |
 */
final class RecruitmentNoticeLocks {

	/** Import the preliminary (preview) list. */
	public const IMPORT_PREVIEW = 'import_preview';

	/** Import the definitive list (it promotes the notice in the same step). */
	public const IMPORT_DEFINITIVE = 'import_definitive';

	/** Promote the preliminary list to the definitive one. */
	public const PROMOTE = 'promote';

	/** Attach or detach adjutancies. */
	public const ADJUTANCIES = 'adjutancies';

	/** Set a preliminary-list row's status and reason. */
	public const PREVIEW_MARKS = 'preview_marks';

	/** Call, record an outcome, cancel, reopen or undo a decision. */
	public const CALLS = 'calls';

	/** Delete the notice with its lists. */
	public const DELETE = 'delete';

	/**
	 * Change → the statuses that take it.
	 *
	 * @var array<string, list<string>>
	 */
	private const MATRIX = array(
		self::IMPORT_PREVIEW    => array( 'draft', 'preliminary' ),
		self::IMPORT_DEFINITIVE => array( 'preliminary' ),
		self::PROMOTE           => array( 'preliminary' ),
		self::ADJUTANCIES       => array( 'draft', 'preliminary' ),
		self::PREVIEW_MARKS     => array( 'preliminary' ),
		self::CALLS             => array( 'definitive' ),
		self::DELETE            => array( 'draft' ),
	);

	/**
	 * Whether a notice in `$status` takes `$change`.
	 *
	 * An unknown change is refused, so a typo locks rather than opens.
	 *
	 * @param string $change One of the class constants.
	 * @param string $status Notice status.
	 * @return bool
	 */
	public static function allows( string $change, string $status ): bool {
		return in_array( $status, self::MATRIX[ $change ] ?? array(), true );
	}

	/**
	 * The statuses that take `$change`.
	 *
	 * @param string $change One of the class constants.
	 * @return list<string>
	 */
	public static function statuses( string $change ): array {
		return self::MATRIX[ $change ] ?? array();
	}

	/**
	 * Every change the table knows.
	 *
	 * @return list<string>
	 */
	public static function changes(): array {
		return array_keys( self::MATRIX );
	}
}
