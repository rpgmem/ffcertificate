<?php
/**
 * The public system notice.
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
 * One notice component for the public screens (#1842): `.ffc-notice` plus one
 * of five tones, a leading registry icon, an optional title and the message.
 *
 * Every emitter goes through this class (or its JS twin, `FFC.notice()` in
 * `ffc-core.js`), so no screen hand-writes the markup and the two cannot
 * drift: both print
 *
 *     <div class="ffc-notice ffc-notice-<tone>" role="…">
 *       <span class="ffc-notice-icon ffc-icon-<icon>" aria-hidden="true"></span>
 *       <div class="ffc-notice-body">
 *         <strong class="ffc-notice-title">…</strong>
 *         <p class="ffc-notice-text">…</p>
 *         <div class="ffc-notice-actions">…</div>
 *       </div>
 *     </div>
 *
 * The look lives in `ffc-common.css`, so it reaches the forms, the
 * verification page and the user dashboard alike, reading tokens only.
 *
 * A notice is a system message, never operator content: the form's own Info
 * field (`.ffc-form-info-block`) stays what it is.
 */
final class Notice {

	/**
	 * The tones, each with the icon it falls back to when the one asked for
	 * is not in the registry.
	 *
	 * @var array<string, string>
	 */
	public const TONES = array(
		'info'    => 'info',
		'warning' => 'warning',
		'danger'  => 'error',
		'success' => 'success',
		'neutral' => 'info',
	);

	/**
	 * The roles a notice may take. `status` is polite news, `alert` is
	 * something that went wrong and interrupts, `note` is context read with
	 * the page and never announced.
	 *
	 * @var array<int, string>
	 */
	public const ROLES = array( 'status', 'alert', 'note' );

	/**
	 * The markup of a notice.
	 *
	 * `$message` is plain text and is escaped, unless `$args['html']` is true,
	 * in which case it goes through `wp_kses_post()`. A message that is a list
	 * of strings prints one paragraph each.
	 *
	 * @param string                    $tone    One of self::TONES; anything else is `info`.
	 * @param string                    $icon    A `.ffc-icon-*` class name without the prefix (`clock`).
	 * @param string|array<int, string> $message The message.
	 * @param string                    $title   Optional bold title, plain text.
	 * @param array<string, mixed>      $args {
	 *          Optional.
	 *
	 *     @type string                $role    One of self::ROLES; default `status`.
	 *     @type string                $live    `polite` or `assertive`; default none.
	 *     @type bool                  $html    The message is trusted HTML; default false.
	 *     @type string                $hook    A `data-ffc-notice` value a script finds the notice by.
	 *     @type array<string, string> $attrs   Further `data-*` or `id` attributes.
	 *     @type string                $actions Trusted HTML for the notice's buttons and links, printed
	 *                                          through `wp_kses_post()` below the text -- the PHP twin of
	 *                                          `FFC.notice()`'s `children` option.
	 * }
	 * @return string
	 */
	public static function render( string $tone, string $icon, string|array $message, string $title = '', array $args = array() ): string {
		$tone = isset( self::TONES[ $tone ] ) ? $tone : 'info';
		$icon = isset( Icons::classes()[ $icon ] ) ? $icon : self::TONES[ $tone ];
		$role = isset( $args['role'] ) && in_array( $args['role'], self::ROLES, true ) ? (string) $args['role'] : 'status';
		$live = isset( $args['live'] ) && in_array( $args['live'], array( 'polite', 'assertive' ), true ) ? (string) $args['live'] : '';
		$html = ! empty( $args['html'] );

		$attributes = ' role="' . esc_attr( $role ) . '"';
		if ( '' !== $live ) {
			$attributes .= ' aria-live="' . esc_attr( $live ) . '"';
		}
		if ( isset( $args['hook'] ) && is_string( $args['hook'] ) && '' !== $args['hook'] ) {
			$attributes .= ' data-ffc-notice="' . esc_attr( $args['hook'] ) . '"';
		}
		$extra = isset( $args['attrs'] ) && is_array( $args['attrs'] ) ? $args['attrs'] : array();
		foreach ( $extra as $name => $value ) {
			$name = (string) $name;
			if ( 'id' === $name || 1 === preg_match( '/^data-[a-z0-9-]+$/', $name ) ) {
				$attributes .= ' ' . $name . '="' . esc_attr( (string) $value ) . '"';
			}
		}

		$body = '';
		if ( '' !== $title ) {
			$body .= '<strong class="ffc-notice-title">' . esc_html( $title ) . '</strong>';
		}
		foreach ( is_array( $message ) ? $message : array( $message ) as $paragraph ) {
			$paragraph = (string) $paragraph;
			if ( '' === $paragraph ) {
				continue;
			}
			$body .= '<p class="ffc-notice-text">' . ( $html ? wp_kses_post( $paragraph ) : esc_html( $paragraph ) ) . '</p>';
		}
		if ( isset( $args['actions'] ) && is_string( $args['actions'] ) && '' !== $args['actions'] ) {
			$body .= '<div class="ffc-notice-actions">' . wp_kses_post( $args['actions'] ) . '</div>';
		}

		return '<div class="ffc-notice ffc-notice-' . esc_attr( $tone ) . '"' . $attributes . '>'
			. '<span class="ffc-notice-icon ffc-icon-' . esc_attr( $icon ) . '" aria-hidden="true"></span>'
			. '<div class="ffc-notice-body">' . $body . '</div>'
			. '</div>';
	}
}
