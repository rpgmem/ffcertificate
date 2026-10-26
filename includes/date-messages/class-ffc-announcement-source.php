<?php
/**
 * Announcement recipients.
 *
 * @package FreeFormCertificate\DateMessages
 * @since 6.42.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\DateMessages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Everyone with an account, for a one-off announcement (#1949).
 *
 * An announcement has no date to match, so `due()` ignores the target day and
 * returns every account after the cursor. The opt-out and the no-address check
 * are the resolver's, exactly as for a dated rule. The audience restriction is
 * the resolver's too, but it pages over the audience members and reads only
 * those accounts through `due_among()`, rather than walking every account on
 * the site to drop the ones outside it (#1996).
 */
final class AnnouncementSource implements MemberScopedSourceInterface {

	/**
	 * Stable id, stored in the rule's `source` column.
	 */
	public const ID = Rule::ANNOUNCEMENT_SOURCE;

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Announcement', 'ffcertificate' );
	}

	/**
	 * Every account after the cursor, ascending by id. The target day is not a
	 * filter: the run's occurrence key is what keeps a second send out.
	 *
	 * @param \DateTimeImmutable $target        Unused: an announcement has no date.
	 * @param int                $after_user_id Keyset cursor.
	 * @param int                $limit         Page size.
	 * @return array<int, array{user_id: int, email: string, name: string}>
	 */
	public function due( \DateTimeImmutable $target, int $after_user_id, int $limit ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Core `wp_users`, named per line: the only interpolation is the core `$wpdb->users` property, and every value is bound. A send must see the live rows.
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Core table, annotated per line; see above.
			$wpdb->prepare(
				'SELECT ID AS user_id, user_email AS email, display_name AS name FROM ' . $wpdb->users . ' WHERE ID > %d ORDER BY ID ASC LIMIT %d',
				max( 0, $after_user_id ),
				max( 1, $limit )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		return self::rows( $rows );
	}

	/**
	 * The accounts among one page of audience members, ascending by id. The
	 * target day is not a filter, as for `due()`.
	 *
	 * @param \DateTimeImmutable $target   Unused: an announcement has no date.
	 * @param array<int, int>    $user_ids One page of member ids.
	 * @return array<int, array{user_id: int, email: string, name: string}>
	 */
	public function due_among( \DateTimeImmutable $target, array $user_ids ): array {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $user_ids ), static fn( int $id ): bool => $id > 0 ) ) );
		if ( array() === $ids ) {
			return array();
		}
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Core `wp_users`, named per line: the only interpolation is the core `$wpdb->users` property and a list of `%d` placeholders, and every value is bound. The placeholders arrive in a variable and the arguments as one array, which the placeholder sniffs cannot read.
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Core table, annotated per line; see above.
			$wpdb->prepare(
				'SELECT ID AS user_id, user_email AS email, display_name AS name FROM ' . $wpdb->users . " WHERE ID IN ({$placeholders}) ORDER BY ID ASC",
				$ids
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		return self::rows( $rows );
	}

	/**
	 * The candidate shape the resolver reads, from raw rows.
	 *
	 * @param mixed $rows Rows from `get_results( …, ARRAY_A )`.
	 * @return array<int, array{user_id: int, email: string, name: string}>
	 */
	private static function rows( $rows ): array {
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
	 * No per-person tokens: an announcement's body is the same for everyone.
	 *
	 * @param int                $user_id The person.
	 * @param \DateTimeImmutable $target  The day of the send.
	 * @return array<string, string>
	 */
	public function tokens( int $user_id, \DateTimeImmutable $target ): array {
		return array();
	}

	/**
	 * No sample tokens for the same reason.
	 *
	 * @param \DateTimeImmutable $target The day of the send.
	 * @return array<string, string>
	 */
	public function sample_tokens( \DateTimeImmutable $target ): array {
		return array();
	}
}
