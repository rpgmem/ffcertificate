<?php
/**
 * The admin page notice.
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
 * One builder for the plugin's page feedback in wp-admin (#1843).
 *
 * Page feedback, the result of an action or the state of a screen, stays
 * WordPress's own `.notice`: its position at the top of the screen and its
 * dismiss button belong to core, and `common.js` moves it there. What this
 * class adds is the part that used to differ from screen to screen:
 *
 * - a leading `Core\Icons` icon for the type, inside the first paragraph,
 *   in the type's tone (no ✓ or ✗ glyph is typed into the text any more);
 * - one dismissibility rule, decided by the type and never by the caller:
 *   success and info close, a warning or an error stays until the page no
 *   longer has the problem. A permission error and a missing view file are
 *   errors, so they never close.
 *
 * The one exception is a notice that records an acknowledgement
 * (`AbstractDismissibleNotice`): closing it is the whole point, and the
 * dismissal is stored, so it passes `acknowledge`.
 *
 * In-content callouts (a box inside a card) are not page feedback and do not
 * come here: they are `.ffc-notice` (`Core\Notice`). Its JS twin is
 * `FFC.Admin.notify()` in `ffc-admin.js`.
 *
 * Lives in Core, beside `EmailDisabledNotice`, for the same reason: it is
 * called from the settings, recruitment, scheduling, audience, URL-shortener
 * and date-message modules, and every one of them already depends on Core.
 */
final class AdminNotice {

	/**
	 * Each type, with its registry icon and its icon tone.
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	public const TYPES = array(
		'success' => array( 'success', 'success' ),
		'info'    => array( 'info', 'info' ),
		'warning' => array( 'warning', 'warning' ),
		'error'   => array( 'error', 'danger' ),
	);

	/**
	 * Echo a page notice.
	 *
	 * `$message` is HTML the caller already escaped, exactly as for
	 * `wp_admin_notice()`, which prints it through `wp_kses_post()`.
	 *
	 * @param string               $type    `success`, `info`, `warning` or `error`;
	 *                                      `updated` is read as `success`.
	 * @param string               $message Escaped HTML.
	 * @param array<string, mixed> $args {
	 *     Optional. The `wp_admin_notice()` arguments, except `type` and
	 *     `dismissible`, which this class decides.
	 *
	 *     @type string   $id                 Element id.
	 *     @type string[] $additional_classes Extra classes.
	 *     @type array    $attributes         Extra attributes.
	 *     @type bool     $paragraph_wrap     Wrap the message in `<p>`; default true.
	 *     @type bool     $acknowledge        A stored, per-state dismissal; default false.
	 * }
	 * @return void
	 */
	public static function render( string $type, string $message, array $args = array() ): void {
		$type        = self::normalize_type( $type );
		$acknowledge = ! empty( $args['acknowledge'] );
		unset( $args['acknowledge'], $args['type'], $args['dismissible'] );

		$paragraph_wrap = ! isset( $args['paragraph_wrap'] ) || false !== $args['paragraph_wrap'];

		$classes                    = isset( $args['additional_classes'] ) && is_array( $args['additional_classes'] ) ? $args['additional_classes'] : array();
		$classes[]                  = 'ffc-admin-notice';
		$args['additional_classes'] = array_values( array_unique( $classes ) );

		$args['type']        = $type;
		$args['dismissible'] = $acknowledge || self::is_dismissible( $type );

		wp_admin_notice( self::with_icon( $type, $message, $paragraph_wrap ), $args );
	}

	/**
	 * Echo the messages a save queued with `add_settings_error()`.
	 *
	 * The settings-API path into the same notice: each queued message is
	 * printed by {@see render()}, so it carries the icon and the rule like
	 * every other notice. Core's own `settings_errors()` is not called, so a
	 * message is never printed twice.
	 *
	 * @param string $setting The slug the messages were queued under.
	 * @return void
	 */
	public static function settings_errors( string $setting ): void {
		foreach ( get_settings_errors( $setting ) as $error ) {
			self::render(
				$error['type'],
				$error['message'],
				'' !== $error['code'] ? array( 'id' => 'setting-error-' . $error['code'] ) : array()
			);
		}
	}

	/**
	 * Whether a notice of this type closes.
	 *
	 * @param string $type The notice type.
	 * @return bool
	 */
	public static function is_dismissible( string $type ): bool {
		return in_array( self::normalize_type( $type ), array( 'success', 'info' ), true );
	}

	/**
	 * The icon span for a type.
	 *
	 * @param string $type The notice type.
	 * @return string
	 */
	public static function icon( string $type ): string {
		$pair = self::TYPES[ self::normalize_type( $type ) ];
		return '<span class="ffc-admin-notice-icon ffc-icon-' . $pair[0] . ' ffc-icon-tone-' . $pair[1] . '" aria-hidden="true"></span>';
	}

	/**
	 * Map a type onto one of self::TYPES.
	 *
	 * @param string $type The notice type.
	 * @return string
	 */
	public static function normalize_type( string $type ): string {
		if ( 'updated' === $type ) {
			return 'success';
		}
		return isset( self::TYPES[ $type ] ) ? $type : 'info';
	}

	/**
	 * Put the icon at the start of the first paragraph.
	 *
	 * @param string $type           The notice type.
	 * @param string $message        Escaped HTML.
	 * @param bool   $paragraph_wrap Whether core wraps the message in `<p>`.
	 * @return string
	 */
	private static function with_icon( string $type, string $message, bool $paragraph_wrap ): string {
		$icon = self::icon( $type );
		if ( $paragraph_wrap ) {
			return $icon . $message;
		}

		$count  = 0;
		$result = preg_replace( '/^\s*<p(?:\s[^>]*)?>/', '$0' . $icon, $message, 1, $count );
		if ( is_string( $result ) && 1 === $count ) {
			return $result;
		}
		return $icon . $message;
	}
}
