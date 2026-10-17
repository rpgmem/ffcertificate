<?php
/**
 * One button family, by what the action does (#1874).
 *
 * @package FreeFormCertificate\Core
 * @since   6.41.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Markup of a `.ffc-btn` button, admin or public.
 *
 * The category names what the action does — `primary` completes the screen's
 * work, `neutral` opens, picks or navigates, `create` adds something,
 * `caution` undoes or recomputes without losing data, `danger` deletes or
 * empties — and the stylesheet draws each one; the context (admin or public)
 * comes from tokens, not from the markup. The icon is a `Core\Icons` class,
 * drawn as the mask `::before` every other icon uses, so a script that later
 * rewrites the label keeps it. `FFC.button()` in `ffc-core.js` prints the
 * same markup for buttons a script builds.
 *
 * An icon-only button (`icon_only`, #1879) is the one shape without visible
 * text — a dialog's close, a row's remove. Its label is still required: it
 * becomes the `aria-label` and the `title`, so the button keeps a name a
 * screen reader announces and a pointer user can read.
 */
final class Button {

	/**
	 * The categories, in the order a screen reads them.
	 *
	 * @var array<int, string>
	 */
	public const CATEGORIES = array( 'primary', 'neutral', 'create', 'caution', 'danger' );

	/**
	 * What an unknown category falls back to: it says nothing about the
	 * action, which is the honest default for a name nobody recognised.
	 */
	public const FALLBACK = 'neutral';

	/**
	 * The `type` values a `<button>` accepts.
	 *
	 * @var array<int, string>
	 */
	private const TYPES = array( 'button', 'submit', 'reset' );

	/**
	 * The markup.
	 *
	 * Arguments: `label` (visible text, required); `category` (one of
	 * {@see self::CATEGORIES}, default `neutral`); `icon` (a `Core\Icons` class
	 * name without `ffc-icon-`); `href` (makes the button a link); `type`
	 * (`button`, `submit` or `reset`, ignored with `href`); `id`; `name` and
	 * `value` (ignored with `href`); `class` (extra space-separated classes,
	 * the hooks scripts read); `disabled` (`aria-disabled` plus `.disabled` on
	 * a link); `attrs` (further attributes such as `data-*`, `title`, `target`
	 * or `rel`: a string value is escaped, `true` prints the bare attribute,
	 * `false` or `null` drops it, and an `on*` handler is refused);
	 * `icon_only` (prints no text: the label becomes `aria-label` and `title`,
	 * and both the label and a known icon are required — without either
	 * nothing is printed and `_doing_it_wrong()` says why).
	 *
	 * @param array<string, mixed> $args See above.
	 * @return string
	 */
	public static function html( array $args ): string {
		$label     = (string) ( $args['label'] ?? '' );
		$icon_only = ! empty( $args['icon_only'] );
		if ( '' === $label ) {
			if ( $icon_only ) {
				self::doing_it_wrong( 'An icon-only button needs a label: it is the button\'s accessible name. Nothing is printed.' );
			}
			return '';
		}

		$category = (string) ( $args['category'] ?? self::FALLBACK );
		if ( ! in_array( $category, self::CATEGORIES, true ) ) {
			self::doing_it_wrong(
				sprintf(
					'"%1$s" is not a button category; it is drawn as %2$s. Use one of: %3$s.',
					$category,
					self::FALLBACK,
					implode( ', ', self::CATEGORIES )
				)
			);
			$category = self::FALLBACK;
		}

		$classes = array( 'ffc-btn', 'ffc-btn-' . $category );

		$icon  = (string) ( $args['icon'] ?? '' );
		$known = '' !== $icon && isset( Icons::classes()[ $icon ] );
		if ( $icon_only ) {
			if ( ! $known ) {
				self::doing_it_wrong( sprintf( 'An icon-only button needs a Core\Icons class, and "%s" is not one. Nothing is printed.', $icon ) );
				return '';
			}
			$classes[] = 'ffc-btn-icon';
		}
		if ( $known ) {
			$classes[] = 'ffc-icon-' . $icon;
		} elseif ( '' !== $icon ) {
			self::doing_it_wrong( sprintf( '"%s" is not a Core\Icons class; the button is drawn without an icon.', $icon ) );
		}

		$extra = preg_split( '/\s+/', trim( (string) ( $args['class'] ?? '' ) ) );
		foreach ( is_array( $extra ) ? $extra : array() as $one ) {
			if ( '' !== $one ) {
				$classes[] = $one;
			}
		}

		$href     = isset( $args['href'] ) ? (string) $args['href'] : null;
		$disabled = ! empty( $args['disabled'] );
		$attrs    = array();

		if ( null === $href ) {
			$type          = (string) ( $args['type'] ?? 'button' );
			$attrs['type'] = in_array( $type, self::TYPES, true ) ? $type : 'button';
		} else {
			$attrs['href'] = esc_url( $href );
			if ( $disabled ) {
				$classes[] = 'disabled';
			}
		}
		$attrs['class'] = implode( ' ', array_unique( $classes ) );

		foreach ( array( 'id', 'name', 'value' ) as $key ) {
			if ( null !== $href && 'id' !== $key ) {
				continue;
			}
			if ( isset( $args[ $key ] ) && '' !== (string) $args[ $key ] ) {
				$attrs[ $key ] = (string) $args[ $key ];
			}
		}

		if ( $disabled ) {
			if ( null === $href ) {
				$attrs['disabled'] = true;
			} else {
				$attrs['aria-disabled'] = 'true';
			}
		}

		if ( $icon_only ) {
			$attrs['aria-label'] = $label;
			$attrs['title']      = $label;
		}

		$more = is_array( $args['attrs'] ?? null ) ? $args['attrs'] : array();
		foreach ( $more as $key => $value ) {
			$key = strtolower( (string) $key );
			if ( 1 !== preg_match( '/^[a-z][a-z0-9_:.-]*$/', $key ) || str_starts_with( $key, 'on' ) || isset( $attrs[ $key ] ) ) {
				continue;
			}
			if ( true === $value ) {
				$attrs[ $key ] = true;
			} elseif ( is_scalar( $value ) && false !== $value ) {
				$attrs[ $key ] = (string) $value;
			}
		}

		$printed = '';
		foreach ( $attrs as $key => $value ) {
			if ( true === $value ) {
				$printed .= ' ' . $key;
			} elseif ( 'href' === $key ) {
				$printed .= ' href="' . $value . '"';
			} else {
				$printed .= ' ' . $key . '="' . esc_attr( $value ) . '"';
			}
		}

		$tag = null === $href ? 'button' : 'a';

		return '<' . $tag . $printed . '>' . ( $icon_only ? '' : esc_html( $label ) ) . '</' . $tag . '>';
	}

	/**
	 * Print the markup.
	 *
	 * @param array<string, mixed> $args See {@see self::html()}.
	 */
	public static function render( array $args ): void {
		// The trailing newline is the whitespace two buttons on separate source
		// lines had between them, which a PHP tag at the end of a line eats.
		echo self::html( $args ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- html() escapes every value.
	}

	/**
	 * Report a call that asked for something the family does not have.
	 *
	 * @param string $message Message for the developer.
	 */
	private static function doing_it_wrong( string $message ): void {
		// A message to the developer, read under WP_DEBUG: English, untranslated, like core's own.
		_doing_it_wrong( __METHOD__, esc_html( $message ), '6.41.0' );
	}
}
