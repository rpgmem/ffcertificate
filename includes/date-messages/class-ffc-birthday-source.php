<?php
/**
 * Birthday date source.
 *
 * @package FreeFormCertificate\DateMessages
 * @since 6.33.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\DateMessages;

use FreeFormCertificate\Core\BirthDate;
use FreeFormCertificate\Core\DateFormatter;
use FreeFormCertificate\UserDashboard\UserManager;
use FreeFormCertificate\UserDashboard\UserProfileFieldMap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The canonical profile birth date as a date source (#1538).
 *
 * It queries the plaintext `MM-DD` mirror, never the encrypted date: the
 * mirror is what exists to be matched by SQL. The full date is decrypted only
 * for `{{age}}`, one person at a time, when a message is actually built.
 *
 * 29 FEBRUARY. Outside a leap year the day does not exist, so on 28 February
 * of a common year the source also returns the people born on the 29th.
 * Every year each of them is due exactly once.
 */
final class BirthdaySource implements DateSourceInterface {

	/**
	 * Source id, stored in rules.
	 */
	public const ID = 'birthday';

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Birthday', 'ffcertificate' );
	}

	/**
	 * The `MM-DD` values due on a target day.
	 *
	 * @param \DateTimeImmutable $target The day.
	 * @return array<int, string>
	 */
	public static function month_days_for( \DateTimeImmutable $target ): array {
		$days = array( $target->format( 'm-d' ) );
		if ( '02-28' === $days[0] && '0' === $target->format( 'L' ) ) {
			$days[] = '02-29';
		}
		return $days;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param \DateTimeImmutable $target        The day the date must fall on.
	 * @param int                $after_user_id Keyset cursor.
	 * @param int                $limit         Page size.
	 * @return array<int, array{user_id: int, email: string, name: string}>
	 */
	public function due( \DateTimeImmutable $target, int $after_user_id, int $limit ): array {
		global $wpdb;

		$days         = self::month_days_for( $target );
		$placeholders = implode( ', ', array_fill( 0, count( $days ), '%s' ) );
		$args         = array_merge( array( UserProfileFieldMap::BIRTH_MONTH_DAY_META_KEY ), $days, array( max( 0, $after_user_id ), max( 1, $limit ) ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Core `wp_users` / `wp_usermeta`, named per line rather than under a file-level disable: the only interpolation is the core `$wpdb->users` / `$wpdb->usermeta` properties and a list of `%s` placeholders, and every value is bound. A scheduled send must see the live rows, never a cache. The arguments arrive as one array, which the placeholder-count sniff cannot read.
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Core tables, annotated per line; see above.
			$wpdb->prepare(
				'SELECT u.ID AS user_id, u.user_email AS email, u.display_name AS name FROM ' . $wpdb->users . ' u'
				. ' INNER JOIN ' . $wpdb->usermeta . ' m ON m.user_id = u.ID AND m.meta_key = %s'
				. " WHERE m.meta_value IN ({$placeholders}) AND u.ID > %d"
				. ' ORDER BY u.ID ASC LIMIT %d',
				$args
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		$out = array();
		foreach ( (array) $rows as $row ) {
			if ( ! is_array( $row ) || ! is_numeric( $row['user_id'] ?? null ) ) {
				continue;
			}
			$out[] = array(
				'user_id' => (int) $row['user_id'],
				'email'   => is_string( $row['email'] ?? null ) ? $row['email'] : '',
				'name'    => is_string( $row['name'] ?? null ) ? $row['name'] : '',
			);
		}
		return $out;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int                $user_id The person.
	 * @param \DateTimeImmutable $target  The day of the date.
	 * @return array<string, string>
	 */
	public function tokens( int $user_id, \DateTimeImmutable $target ): array {
		$extended = UserManager::get_extended_profile( $user_id, array( 'birth_date' ) );
		$stored   = $extended['birth_date'] ?? '';
		$age      = is_string( $stored ) ? BirthDate::age_on( $stored, $target ) : null;

		return array(
			'date' => DateFormatter::format_wallclock_date( $target->format( 'Y-m-d' ) ),
			'age'  => null === $age ? '' : (string) $age,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param \DateTimeImmutable $target The day of the date.
	 * @return array<string, string>
	 */
	public function sample_tokens( \DateTimeImmutable $target ): array {
		return array(
			'date' => DateFormatter::format_wallclock_date( $target->format( 'Y-m-d' ) ),
			'age'  => '35',
		);
	}
}
