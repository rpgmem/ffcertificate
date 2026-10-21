<?php
/**
 * Announcement recipients.
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
 * Everyone with an account, for a one-off announcement (#1949).
 *
 * An announcement has no date to match, so `due()` ignores the target day and
 * returns every account after the cursor. The audience restriction, the
 * opt-out and the no-address check are the resolver's, exactly as for a dated
 * rule, so an announcement reaches the same people a birthday message would.
 */
final class AnnouncementSource implements DateSourceInterface {

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
