<?php
/**
 * Date-message opt-out categories.
 *
 * @package FreeFormCertificate\DateMessages
 * @since 6.33.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\DateMessages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The categories a person can opt out of (#1949).
 *
 * Its own class, not constants on `OptOut`: the unsubscribe handler reads these
 * ids, and its tests replace `OptOut` with an alias mock, which has no
 * constants and returns null from every method.
 *
 * - `personal`     -- dated messages (birthdays). Its key is the one this module
 *   shipped with, so existing profiles and links keep meaning what they meant.
 * - `announcement` -- one-off announcements to an audience. Kept apart, so a
 *   person who turned off birthday greetings still gets a notice they were
 *   asked to receive, and the reverse holds too.
 */
final class OptOutCategory {

	public const PERSONAL     = 'personal';
	public const ANNOUNCEMENT = 'announcement';

	/**
	 * Preference key of each category, inside `ffc_user_profiles.preferences`.
	 * Both mean RECEIVE: absent or true is "send", only an explicit `false` opts out.
	 */
	private const KEYS = array(
		self::PERSONAL     => 'notify_date_messages',
		self::ANNOUNCEMENT => 'notify_date_announcements',
	);

	/**
	 * Every category id.
	 *
	 * @return array<int, string>
	 */
	public static function all(): array {
		return array_keys( self::KEYS );
	}

	/**
	 * Whether a category id is known.
	 *
	 * @param string $category Category id.
	 * @return bool
	 */
	public static function has( string $category ): bool {
		return isset( self::KEYS[ $category ] );
	}

	/**
	 * Preference key of a category.
	 *
	 * @param string $category One of all(); an unknown id reads as personal, the
	 *                         category this module shipped with.
	 * @return string
	 */
	public static function key( string $category ): string {
		return self::KEYS[ $category ] ?? self::KEYS[ self::PERSONAL ];
	}
}
