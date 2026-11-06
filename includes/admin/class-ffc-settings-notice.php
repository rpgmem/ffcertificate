<?php
/**
 * SettingsNotice
 *
 * The result of a Settings action, shown once after its redirect (#2338).
 *
 * The maintenance and migration actions used to carry their result text in
 * the URL (`?url_cleanup_msg=…`), and the screen printed whatever the query
 * held: anyone could send an operator a link that put words of their choosing
 * on a Settings screen under the plugin's styling. The text now stays on the
 * server, in a short-lived per-user transient; the URL carries only the area
 * code (`?ffc_notice=url_cleanup`), and the screen shows the notice stored for
 * that area, once.
 *
 * @package FreeFormCertificate\Admin
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One-shot result notices for Settings actions.
 */
final class SettingsNotice {

	/**
	 * Query argument naming the area whose notice to show.
	 */
	public const ARG = 'ffc_notice';

	/**
	 * The areas that report a result. A code not listed is ignored.
	 */
	public const AREAS = array(
		'migration',
		'obsolete_cleanup',
		'url_cleanup',
		'pubaccess',
		'submission_audit',
	);

	/**
	 * How long a stored notice waits for its screen, in seconds.
	 */
	private const TTL = 300;

	/**
	 * Store a notice and return the URL that shows it.
	 *
	 * @param string $url     Redirect URL.
	 * @param string $area    One of self::AREAS.
	 * @param string $type    'success' or 'error'.
	 * @param string $message Text to show, already translated.
	 * @return string
	 */
	public static function add( string $url, string $area, string $type, string $message ): string {
		if ( ! in_array( $area, self::AREAS, true ) ) {
			return $url;
		}

		set_transient(
			self::key( $area ),
			array(
				'type'    => 'error' === $type ? 'error' : 'success',
				'message' => $message,
			),
			self::TTL
		);

		return add_query_arg( self::ARG, $area, $url );
	}

	/**
	 * The notice stored for an area, once, when the URL names that area.
	 *
	 * @param string $area One of self::AREAS.
	 * @return array{type: string, message: string}|null
	 */
	public static function take( string $area ): ?array {
		if ( \FreeFormCertificate\Core\RequestInput::get_get_key( self::ARG ) !== $area || ! in_array( $area, self::AREAS, true ) ) {
			return null;
		}

		$stored = get_transient( self::key( $area ) );
		delete_transient( self::key( $area ) );

		if ( ! is_array( $stored ) || ! isset( $stored['message'] ) || ! is_string( $stored['message'] ) || '' === $stored['message'] ) {
			return null;
		}

		return array(
			'type'    => 'error' === ( $stored['type'] ?? '' ) ? 'error' : 'success',
			'message' => $stored['message'],
		);
	}

	/**
	 * The stored message of a type, or '' — the shape the screens read.
	 *
	 * @param array{type: string, message: string}|null $notice Taken notice.
	 * @param string                                    $type   'success' or 'error'.
	 * @return string
	 */
	public static function message_of( ?array $notice, string $type ): string {
		return ( null !== $notice && $type === $notice['type'] ) ? $notice['message'] : '';
	}

	/**
	 * Transient key, per user and area.
	 *
	 * @param string $area Area.
	 * @return string
	 */
	private static function key( string $area ): string {
		return 'ffc_notice_' . get_current_user_id() . '_' . $area;
	}
}
