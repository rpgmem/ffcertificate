<?php
/**
 * Audience Form Invite Reader
 *
 * Read-side of the post-event invitation records (#1948). Writes live in
 * {@see AudienceFormInviteWriter}.
 *
 * @package FreeFormCertificate\Audience
 * @since 6.42.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

use FreeFormCertificate\Core\ArrayValue;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Every statement in this class runs against the plugin's own ffc_audience_form_invites and ffc_submissions tables, which WordPress exposes no API for. Nothing is cached: the screen reads the live state.
/**
 * Read operations for post-event invitations and the answers they led to.
 *
 * @since 6.42.0
 */
class AudienceFormInviteReader {
	use \FreeFormCertificate\Core\StaticRepositoryTrait;

	/**
	 * Invitations table name.
	 *
	 * @return string
	 */
	public static function get_table_name(): string {
		return self::db()->prefix . 'ffc_audience_form_invites';
	}

	/**
	 * The invitations sent for a booking.
	 *
	 * @param int $booking_id Booking ID.
	 * @return array<int, array{sent_at: int, send_count: int}> User ID => last send and how many.
	 */
	public static function for_booking( int $booking_id ): array {
		$wpdb = self::db();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT user_id, sent_at, send_count FROM %i WHERE booking_id = %d',
				self::get_table_name(),
				$booking_id
			),
			ARRAY_A
		);

		$sent = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$sent[ ArrayValue::int( $row, 'user_id' ) ] = array(
				'sent_at'    => ArrayValue::int( $row, 'sent_at' ),
				'send_count' => ArrayValue::int( $row, 'send_count' ),
			);
		}

		return $sent;
	}

	/**
	 * What each person invited by a booking answered: the status of their
	 * submission. A submission is linked to its booking by
	 * `ffc_submissions.audience_booking_id`, which the certificate form writes
	 * for an invited submission; a trashed one does not count.
	 *
	 * @param int $booking_id Booking ID.
	 * @return array<int, string> User ID => submission status (`publish` when the certificate was issued).
	 */
	public static function answers( int $booking_id ): array {
		$wpdb = self::db();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, status FROM %i WHERE audience_booking_id = %d AND user_id IS NOT NULL AND status <> 'trash' ORDER BY id ASC",
				$wpdb->prefix . 'ffc_submissions',
				$booking_id
			),
			ARRAY_A
		);

		$answers = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$answers[ ArrayValue::int( $row, 'user_id' ) ] = ArrayValue::string( $row, 'status' );
		}

		return $answers;
	}
}
