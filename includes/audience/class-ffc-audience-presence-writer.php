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
	 * A request an administrator refused (#2743).
	 */
	public const REQUEST_REFUSED = 'refused';

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
	 * @param string      $platform     Platform family, such as Android (#2781).
	 * @param string      $browser      Browser family, such as Chrome (#2781).
	 * @return int The new row's id, or 0 on failure.
	 */
	public static function activate( int $user_id, string $device_id, string $key_id, string $public_key, ?string $signals_hash, bool $is_change, int $now, string $platform = '', string $browser = '' ): int {
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
				'attested_at'  => $now,
				'platform'     => '' === $platform ? null : $platform,
				'browser'      => '' === $browser ? null : $browser,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s' )
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

	/**
	 * Record that a device's attestation was signed again, so the Presence
	 * devices screen can say until when it works offline (#2743).
	 *
	 * @param int $id  Device row id.
	 * @param int $now Unix time.
	 * @return bool Whether the row was updated.
	 */
	public static function attested( int $id, int $now ): bool {
		return self::run(
			self::db()->prepare( 'UPDATE %i SET attested_at = %d WHERE id = %d', AudiencePresenceReader::devices_table(), $now, $id )
		) > 0;
	}

	/**
	 * Release a device from every other account it is reserved to, so the
	 * account asking can activate it (#2743).
	 *
	 * @param string $device_id  Device id.
	 * @param int    $except_user The account asking, whose rows stay as they are.
	 * @param int    $by         The administrator.
	 * @param int    $now        Unix time.
	 * @return int How many rows were released.
	 */
	public static function release_device( string $device_id, int $except_user, int $by, int $now ): int {
		return self::run(
			self::db()->prepare(
				'UPDATE %i SET released_at = %d, released_by = %d WHERE device_id = %s AND user_id <> %d AND released_at IS NULL',
				AudiencePresenceReader::devices_table(),
				$now,
				$by,
				$device_id,
				$except_user
			)
		);
	}

	/**
	 * Decide a pending request.
	 *
	 * @param int    $id     Request id.
	 * @param string $status REQUEST_APPROVED or REQUEST_REFUSED.
	 * @param int    $by     The administrator.
	 * @param int    $now    Unix time.
	 * @return bool Whether the request was still pending and is now decided.
	 */
	public static function decide_request( int $id, string $status, int $by, int $now ): bool {
		if ( self::REQUEST_APPROVED !== $status && self::REQUEST_REFUSED !== $status ) {
			return false;
		}
		return self::run(
			self::db()->prepare(
				'UPDATE %i SET status = %s, decided_at = %d, decided_by = %d WHERE id = %d AND status = %s',
				AudiencePresenceReader::requests_table(),
				$status,
				$now,
				$by,
				$id,
				self::REQUEST_PENDING
			)
		) > 0;
	}

	/**
	 * Dismiss a shared-signals alert: every active device reporting those
	 * signals stops being listed, until a new device reports them (#2743).
	 *
	 * @param string $signals_hash Keyed hash of the signals.
	 * @param int    $now          Unix time.
	 * @return int How many rows were dismissed.
	 */
	public static function dismiss_signals( string $signals_hash, int $now ): int {
		return self::run(
			self::db()->prepare(
				'UPDATE %i SET signals_dismissed_at = %d WHERE signals_hash = %s AND revoked_at IS NULL AND signals_dismissed_at IS NULL',
				AudiencePresenceReader::devices_table(),
				$now,
				$signals_hash
			)
		);
	}

	/**
	 * Run a prepared UPDATE.
	 *
	 * @param string|null $sql The prepared statement.
	 * @return int Rows changed; 0 on failure.
	 */
	private static function run( ?string $sql ): int {
		// `prepare()` returns `string|null`, and `query()` takes a string only.
		$rows = is_string( $sql ) ? self::db()->query( $sql ) : false; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepare()'s output, held to test its type.
		return is_int( $rows ) ? $rows : 0;
	}
}
