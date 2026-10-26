<?php
/**
 * The message under a field, as the server prints it.
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
 * Server half of the field-message standard (#1844).
 *
 * A field is drawn in one order everywhere: the label with its required
 * marker, the control, the message, and one row with the hint and the
 * counter. The browser half is `FFC.fieldMessage()` in `ffc-core.js`; this
 * class prints the same markup for an error the server already knows, and
 * both derive the message id the same way, so a message printed here is the
 * one the script later updates or clears rather than a second copy.
 *
 * A message printed with the page carries no live-region role: it is part
 * of the document the reader arrives at, not an event to announce. The
 * script adds `role="alert"` (an error) or `role="status"` (a warning) when
 * it changes one.
 */
final class FieldMessage {

	/**
	 * Prefix of every message id.
	 */
	public const ID_PREFIX = 'ffc-msg-';

	/**
	 * What each kind puts on the message and on its control. The same map
	 * as `FIELD_KINDS` in `ffc-core.js`.
	 *
	 * @var array<string, array{message: string, icon: string, control: string}>
	 */
	private const KINDS = array(
		'error'   => array(
			'message' => 'is-error',
			'icon'    => 'ffc-icon-error',
			'control' => 'is-invalid',
		),
		'warning' => array(
			'message' => 'is-warning',
			'icon'    => 'ffc-icon-warning',
			'control' => 'is-warning',
		),
	);

	/**
	 * The message id for a control.
	 *
	 * @param string $control_id The control's id (or name, when it has none).
	 * @return string
	 */
	public static function id( string $control_id ): string {
		return self::ID_PREFIX . (string) preg_replace( '/[^A-Za-z0-9_-]/', '-', $control_id );
	}

	/**
	 * The message, or '' when there is none to show.
	 *
	 * @param string $control_id The control the message describes.
	 * @param string $text       The message; '' prints nothing.
	 * @param string $kind       `error` or `warning`; anything else is an error.
	 * @return string
	 */
	public static function render( string $control_id, string $text, string $kind = 'error' ): string {
		if ( '' === $text ) {
			return '';
		}
		$spec = self::spec( $kind );

		return sprintf(
			'<span id="%1$s" class="ffc-field-message %2$s %3$s">%4$s</span>',
			esc_attr( self::id( $control_id ) ),
			esc_attr( $spec['message'] ),
			esc_attr( $spec['icon'] ),
			esc_html( $text )
		);
	}

	/**
	 * The state class a control takes for a message, or ''.
	 *
	 * For a control that already carries a `class` attribute; one that does
	 * not can take the whole set from `control_attributes()`.
	 *
	 * @param string $text The message; '' means no state.
	 * @param string $kind `error` or `warning`.
	 * @return string
	 *
	 * @deprecated 6.43.0 Nothing in the plugin calls it (#2089).
	 * @removal    6.45.0
	 */
	public static function control_class( string $text, string $kind = 'error' ): string {
		_deprecated_function( __METHOD__, '6.43.0' );

		return '' === $text ? '' : self::spec( $kind )['control'];
	}

	/**
	 * The attributes that tie a control to its message, each with a leading
	 * space; '' when there is no message and no hint.
	 *
	 * `aria-describedby` names the hint first and the message after it, so
	 * the hint keeps being read while an error stands.
	 *
	 * @param string $control_id The control's id.
	 * @param string $text       The message; '' ties the hint alone.
	 * @param string $kind       `error` or `warning`.
	 * @param string $hint_id    Id of the field's hint, or ''.
	 * @param bool   $with_class Whether to include `class` with the state.
	 * @return string
	 */
	public static function control_attributes( string $control_id, string $text, string $kind = 'error', string $hint_id = '', bool $with_class = true ): string {
		$described = array();
		if ( '' !== $hint_id ) {
			$described[] = $hint_id;
		}
		$attributes = '';

		if ( '' !== $text ) {
			$spec        = self::spec( $kind );
			$id          = self::id( $control_id );
			$described[] = $id;
			if ( $with_class ) {
				$attributes .= ' class="' . esc_attr( $spec['control'] ) . '"';
			}
			if ( 'is-invalid' === $spec['control'] ) {
				$attributes .= ' aria-invalid="true"';
			}
			$attributes .= ' data-ffc-message-id="' . esc_attr( $id ) . '"';
		}

		if ( $described ) {
			$attributes .= ' aria-describedby="' . esc_attr( implode( ' ', $described ) ) . '"';
		}

		return $attributes;
	}

	/**
	 * The strings the script half reads from `ffcCoreConfig.strings`.
	 *
	 * @return array<string, string>
	 */
	public static function script_strings(): array {
		return array(
			'fieldRequired' => __( 'This field is required.', 'ffcertificate' ),
		);
	}

	/**
	 * The map entry for a kind; an unknown kind is an error.
	 *
	 * @param string $kind Kind.
	 * @return array{message: string, icon: string, control: string}
	 */
	private static function spec( string $kind ): array {
		return self::KINDS[ $kind ] ?? self::KINDS['error'];
	}
}
