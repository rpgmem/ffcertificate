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
	 * Each type, with the registry icon a callout of that type leads with.
	 *
	 * @var array<string, string>
	 */
	public const TYPES = array(
		'success' => 'success',
		'info'    => 'info',
		'warning' => 'warning',
		'error'   => 'error',
	);

	/**
	 * The icon classes of each type, written out whole so the icon guards can
	 * read them: the registry icon and its tone.
	 *
	 * @var array<string, string>
	 */
	private const ICON_CLASSES = array(
		'success' => 'ffc-icon-success ffc-icon-tone-success',
		'info'    => 'ffc-icon-info ffc-icon-tone-info',
		'warning' => 'ffc-icon-warning ffc-icon-tone-warning',
		'error'   => 'ffc-icon-error ffc-icon-tone-danger',
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
	 * Echo an in-content callout: a `.ffc-notice` inside a card or a tab.
	 *
	 * A callout is not page feedback, so it is not a `.notice` and core never
	 * moves it to the top of the screen. It takes the same four types as a
	 * page notice, maps them onto the component's tones (`error` is
	 * `danger`), and leads with the type's icon unless the caller names
	 * another one. Like {@see render()}, `$message` is HTML the caller
	 * already escaped; a message that opens with a block element (a
	 * paragraph, a list, a heading) is placed as it is, anything else is one
	 * paragraph.
	 *
	 * @param string               $type    `success`, `info`, `warning` or `error`.
	 * @param string               $message Escaped HTML.
	 * @param array<string, mixed> $args {
	 *     Optional.
	 *
	 *     @type string             $icon    A registry class name without the prefix.
	 *     @type string             $title   A bold title, plain text.
	 *     @type string             $role    `note` (default), `status` or `alert`.
	 *     @type string             $live    `polite` or `assertive`.
	 *     @type string             $hook    A `data-ffc-notice` value.
	 *     @type array<int, string> $classes Further classes.
	 *     @type array<string, string> $attrs `id` or `data-*` attributes.
	 * }
	 * @return void
	 */
	public static function callout( string $type, string $message, array $args = array() ): void {
		echo wp_kses_post( self::callout_markup( $type, $message, $args ) );
	}

	/**
	 * The markup of an in-content callout; see {@see callout()}.
	 *
	 * @param string               $type    The type.
	 * @param string               $message Escaped HTML.
	 * @param array<string, mixed> $args    See {@see callout()}.
	 * @return string
	 */
	public static function callout_markup( string $type, string $message, array $args = array() ): string {
		$type  = self::normalize_type( $type );
		$icon  = isset( $args['icon'] ) && is_string( $args['icon'] ) ? $args['icon'] : self::TYPES[ $type ];
		$title = isset( $args['title'] ) && is_string( $args['title'] ) ? $args['title'] : '';

		$notice_args = array(
			'html'   => true,
			'blocks' => 1 === preg_match( '/^\s*<(?:p|ul|ol|dl|h[1-6]|div|table|pre|blockquote)[\s>]/i', $message ),
			'role'   => isset( $args['role'] ) ? $args['role'] : 'note',
		);
		foreach ( array( 'live', 'hook', 'classes', 'attrs' ) as $key ) {
			if ( isset( $args[ $key ] ) ) {
				$notice_args[ $key ] = $args[ $key ];
			}
		}

		return Notice::render( self::tone( $type ), $icon, $message, $title, $notice_args );
	}

	/**
	 * The `.ffc-notice` tone of a notice type.
	 *
	 * @param string $type The notice type.
	 * @return string
	 */
	public static function tone( string $type ): string {
		$type = self::normalize_type( $type );
		return 'error' === $type ? 'danger' : $type;
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
		return '<span class="ffc-admin-notice-icon ' . self::ICON_CLASSES[ self::normalize_type( $type ) ] . '" aria-hidden="true"></span>';
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
		if ( 'danger' === $type ) {
			return 'error';
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
