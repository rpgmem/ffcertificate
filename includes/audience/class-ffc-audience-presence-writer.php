<?php
/**
 * Audience Presence Writer
 *
 * Write-side of the presence devices repository (#2741). Reads live in
 * {@see AudiencePresenceReader}.
 *
 * @package FreeFormCertificate\Audience
 * @since 6.50.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Every statement in this class runs against the plugin's own ffc_audience_presence_devices and ffc_audience_presence_requests tables, which WordPress exposes no API for; its reader caches nothing.
/**
 * Write operations for presence devices and their requests.
 *
 * @since 6.50.0
 */
class AudiencePresenceWriter {
	use \FreeFormCertificate\Core\StaticRepositoryTrait;

	/**
	 * Why a key stopped being valid: another device was activated.
	 */
	public const REVOKED_REPLACED = 'replaced';

	/**
	 * Why a key stopped being valid: an administrator revoked it.
	 */
	public const REVOKED_ADMIN = 'admin';

	/**
	 * A request waiting for an administrator.
	 */
	public const REQUEST_PENDING = 'pending';

	/**
	 * A request an administrator approved, not yet used.
	 */
	public const REQUEST_APPROVED = 'approved';

	/**
	 * A request whose approval an enrolment used.
	 */
	public const REQUEST_USED = 'used';

	/**
	 * Record an activated device.
	 *
	 * @param int         $user_id      User.
	 * @param string      $device_id    Device id.
	 * @param string      $key_id       Device key id.
	 * @param string      $public_key   Base64url public point.
	 * @param string|null $signals_hash Keyed hash of the device signals, or null.
	 * @param bool        $is_change    Whether this counts as a device change.
	 * @param int         $now          Unix time.
	 * @return int The new row's id, or 0 on failure.
	 */
	public static function activate( int $user_id, string $device_id, string $key_id, string $public_key, ?string $signals_hash, bool $is_change, int $now ): int {
		$wpdb = self::db();
		$ok   = $wpdb->insert(
			AudiencePresenceReader::devices_table(),
			array(
				'user_id'      => $user_id,
				'device_id'    => $device_id,
				'key_id'       => $key_id,
				'public_key'   => $public_key,
				'signals_hash' => $signals_hash,
				'is_change'    => $is_change ? 1 : 0,
				'activated_at' => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%d', '%d' )
		);
		return false === $ok ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * Revoke one device row, if it is still active.
	 *
	 * @param int      $id     Row id.
	 * @param string   $reason One of the REVOKED_* constants.
	 * @param int|null $by     Who revoked it; null when the system did.
	 * @param int      $now    Unix time.
	 * @return bool Whether a row was revoked.
	 */
	public static function revoke( int $id, string $reason, ?int $by, int $now ): bool {
		$wpdb  = self::db();
		$table = AudiencePresenceReader::devices_table();
		$sql   = null === $by
			? $wpdb->prepare( 'UPDATE %i SET revoked_at = %d, revoked_reason = %s, revoked_by = NULL WHERE id = %d AND revoked_at IS NULL', $table, $now, $reason, $id )
			: $wpdb->prepare( 'UPDATE %i SET revoked_at = %d, revoked_reason = %s, revoked_by = %d WHERE id = %d AND revoked_at IS NULL', $table, $now, $reason, $by, $id );
		// `prepare()` returns `string|null`, and `query()` takes a string only.
		$rows = is_string( $sql ) ? $wpdb->query( $sql ) : false; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepare()'s output, held to test its type.
		return is_int( $rows ) && $rows > 0;
	}

	/**
	 * Leave a pending request for an administrator, once per user, device
	 * and reason.
	 *
	 * @param int    $user_id   User.
	 * @param string $device_id Device id.
	 * @param string $reason    Refusal reason.
	 * @param int    $now       Unix time.
	 * @return int The pending request's id, or 0 on failure.
	 */
	public static function request( int $user_id, string $device_id, string $reason, int $now ): int {
		$existing = AudiencePresenceReader::request_id( $user_id, $device_id, $reason, self::REQUEST_PENDING );
		if ( null !== $existing ) {
			return $existing;
		}
		$wpdb = self::db();
		$ok   = $wpdb->insert(
			AudiencePresenceReader::requests_table(),
			array(
				'user_id'    => $user_id,
				'device_id'  => $device_id,
				'reason'     => $reason,
				'status'     => self::REQUEST_PENDING,
				'created_at' => $now,
			),
			array( '%d', '%s', '%s', '%s', '%d' )
		);
		return false === $ok ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * Mark an approved request as used, so its approval lets one enrolment
	 * through and no more.
	 *
	 * @param int $id Request id.
	 * @return bool Whether the request was still approved and is now used.
	 */
	public static function use_request( int $id ): bool {
		$wpdb = self::db();
		$sql  = $wpdb->prepare(
			'UPDATE %i SET status = %s WHERE id = %d AND status = %s',
			AudiencePresenceReader::requests_table(),
			self::REQUEST_USED,
			$id,
			self::REQUEST_APPROVED
		);
		// `prepare()` returns `string|null`, and `query()` takes a string only.
		$rows = is_string( $sql ) ? $wpdb->query( $sql ) : false; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepare()'s output, held to test its type.
		return is_int( $rows ) && $rows > 0;
	}
}
