<?php
/**
 * PiiExport
 *
 * The one rule every staff CSV export with personal data follows (#1863).
 *
 * @package FreeFormCertificate\Core
 * @since   6.40.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Masks personal columns for an exporter without the module's PII cap, and
 * records every export that carried them in clear.
 *
 * An export is the bulk form of a reveal, so it follows the reveal policy:
 * the module's export cap lets a role download the file, and only the
 * module's `ffc_view_<module>_pii` cap (or `manage_options`) puts CPF, RF,
 * email, phone, IP and the sensitive custom fields in it in clear. Everyone
 * else receives the same columns, masked, so the file keeps its shape. Each
 * export that carried clear values writes one `pii_exported` row to the
 * activity log naming the export and the row count.
 *
 * A source asks {@see self::allowed()} once, when the job starts, and freezes
 * the answer in the job context, so a long export cannot change mode between
 * batches.
 */
final class PiiExport {

	/**
	 * Whether the current user's export may carry personal data in clear.
	 *
	 * @param string $pii_cap The module reveal cap.
	 * @return bool
	 */
	public static function allowed( string $pii_cap ): bool {
		return PiiAccessPolicy::can_reveal_any( $pii_cap );
	}

	/**
	 * Whether a column key carries personal data.
	 *
	 * @param string $key Column or field key.
	 * @return bool
	 */
	public static function is_personal( string $key ): bool {
		// The one key map (#1920), then the sensitive fields the registry knows.
		if ( null !== DocumentFormatter::kind_for_key( $key ) ) {
			return true;
		}
		$static = SensitiveFieldRegistry::universal_sensitive_keys();
		unset( $static['data'] );
		return isset( $static[ $key ] ) || isset( SensitiveFieldRegistry::dynamic_sensitive_keys()[ $key ] );
	}

	/**
	 * One cell: the value as is when the export is clear or the key is not
	 * personal, otherwise masked.
	 *
	 * @param string $key   Column or field key.
	 * @param mixed  $value Cell value.
	 * @param bool   $clear Whether the export carries personal data in clear.
	 * @return mixed
	 */
	public static function cell( string $key, $value, bool $clear ) {
		if ( $clear || ! is_scalar( $value ) || '' === (string) $value || ! self::is_personal( $key ) ) {
			return $value;
		}
		return DocumentFormatter::mask_any( $key, (string) $value );
	}

	/**
	 * Record an export that carried personal data in clear. Nothing is
	 * written for a masked one: it disclosed nothing.
	 *
	 * @param string $export Export type (the source's `type()`).
	 * @param int    $rows   Rows written.
	 * @return void
	 */
	public static function log( string $export, int $rows ): void {
		ActivityLog::log(
			'pii_exported',
			ActivityLog::LEVEL_INFO,
			array(
				'export' => $export,
				'rows'   => $rows,
			),
			get_current_user_id(),
			0
		);
	}
}
