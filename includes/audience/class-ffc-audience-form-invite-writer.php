<?php
/**
 * Audience Form Invite Writer
 *
 * Write-side of the post-event invitation records (#1948). Reads live in
 * {@see AudienceFormInviteReader}.
 *
 * @package FreeFormCertificate\Audience
 * @since 6.42.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Every statement in this class runs against the plugin's own ffc_audience_form_invites table, which WordPress exposes no API for; its reader caches nothing.
/**
 * Write operations for post-event invitations.
 *
 * @since 6.42.0
 */
class AudienceFormInviteWriter {
	use \FreeFormCertificate\Core\StaticRepositoryTrait;

	/**
	 * Record that an invitation was handed to the mailer.
	 *
	 * The first send inserts the row; a resend moves `sent_at` and counts it,
	 * on the unique key (booking, person), so a person is never two rows.
	 *
	 * @param int $booking_id Booking ID.
	 * @param int $user_id    User ID.
	 * @param int $form_id    Form the link pointed to.
	 * @param int $now        Unix time.
	 * @return bool Whether the row was written.
	 */
	public static function record_sent( int $booking_id, int $user_id, int $form_id, int $now ): bool {
		$wpdb = self::db();

		$sql = $wpdb->prepare(
			'INSERT INTO %i (booking_id, user_id, form_id, sent_at, send_count) VALUES (%d, %d, %d, %d, 1) ON DUPLICATE KEY UPDATE form_id = VALUES(form_id), sent_at = VALUES(sent_at), send_count = send_count + 1',
			AudienceFormInviteReader::get_table_name(),
			$booking_id,
			$user_id,
			$form_id,
			$now
		);
		if ( ! is_string( $sql ) ) {
			return false;
		}

		$written = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is the prepare() result just above, checked to be a string.

		return false !== $written && $written > 0;
	}
}
