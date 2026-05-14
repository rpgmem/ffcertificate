<?php
/**
 * Reusable admin UI helpers.
 *
 * Currently exposes `render_toggle()` — emits the markup contract the
 * `.ffc-toggle` CSS expects (label > hidden checkbox + decorative
 * track + visible label text). Keeping the markup behind a helper lets
 * every call-site stay consistent without copying the HTML.
 *
 * @package FreeFormCertificate\Admin
 * @since 6.5.4
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AdminUI: small set of WordPress-admin UI helpers.
 */
class AdminUI {

	/**
	 * Render a toggle switch (`.ffc-toggle`) — visually a switch, an
	 * accessible checkbox underneath.
	 *
	 * Usage:
	 *   AdminUI::render_toggle(
	 *       array(
	 *           'name'    => 'admin_bypass_geo',
	 *           'id'      => 'ffc_admin_bypass_geo',
	 *           'checked' => (bool) $settings['admin_bypass_geo'],
	 *           'label'   => __( 'Admins bypass geolocation', 'ffcertificate' ),
	 *       )
	 *   );
	 *
	 * @param array<string, mixed> $args {
	 *     @type string $name    Required. The form input name.
	 *     @type string $id      Optional. The input id (defaults to `name`).
	 *     @type string $value   Optional. Submitted value when checked (default '1').
	 *     @type bool   $checked Optional. Whether the toggle starts on.
	 *     @type string $label   Optional. Visible label text next to the switch.
	 *     @type bool   $disabled Optional. Renders the switch in disabled state.
	 *     @type string $class   Optional. Extra classes appended to the wrapper.
	 *     @type array<string, string> $data Optional. data-* attributes on the input.
	 * }
	 */
	public static function render_toggle( array $args ): void {
		$name = $args['name'] ?? '';
		if ( '' === $name ) {
			return;
		}
		$id       = $args['id'] ?? $name;
		$value    = $args['value'] ?? '1';
		$checked  = ! empty( $args['checked'] );
		$disabled = ! empty( $args['disabled'] );
		$label    = $args['label'] ?? '';
		$extra    = trim( (string) ( $args['class'] ?? '' ) );
		$data     = $args['data'] ?? array();

		$wrapper_class = 'ffc-toggle' . ( '' !== $extra ? ' ' . $extra : '' );

		$data_attrs = '';
		if ( is_array( $data ) ) {
			foreach ( $data as $k => $v ) {
				$data_attrs .= ' data-' . esc_attr( (string) $k ) . '="' . esc_attr( (string) $v ) . '"';
			}
		}

		printf(
			'<label class="%1$s" for="%2$s">',
			esc_attr( $wrapper_class ),
			esc_attr( $id )
		);
		printf(
			'<input type="checkbox" id="%1$s" name="%2$s" value="%3$s"%4$s%5$s%6$s>',
			esc_attr( $id ),
			esc_attr( $name ),
			esc_attr( (string) $value ),
			$checked ? ' checked' : '',
			$disabled ? ' disabled' : '',
			$data_attrs // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped above.
		);
		echo '<span class="ffc-toggle-track" aria-hidden="true"></span>';
		if ( '' !== $label ) {
			echo '<span class="ffc-toggle-label">' . esc_html( $label ) . '</span>';
		}
		echo '</label>';
	}
}
