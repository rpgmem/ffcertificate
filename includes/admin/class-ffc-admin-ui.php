<?php
/**
 * Reusable admin UI helpers.
 *
 * Currently exposes `render_toggle()` — emits the markup contract the
 * `.ffc-control-toggle` CSS expects (label > hidden checkbox + decorative
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
	 * Badge strings for the inline autosave widget.
	 *
	 * One source for both screens that enqueue it — the settings tabs and
	 * the form editor. They were separate before #1116, and the settings
	 * half had no strings at all: `FFC.Admin.autoSaveField` fell back to
	 * English literals while the form editor's copy was translated. A
	 * single widget with a translated half is worse than two, so the
	 * strings are shared with it.
	 *
	 * @return array<string, string>
	 */
	public static function autosave_strings(): array {
		return array(
			'saving'  => __( 'Saving…', 'ffcertificate' ),
			'saved'   => __( 'Saved', 'ffcertificate' ),
			'error'   => __( 'Save failed', 'ffcertificate' ),
			'invalid' => __( 'Enter a valid value', 'ffcertificate' ),
		);
	}

	/**
	 * Render a toggle switch (`.ffc-control-toggle`) — visually a switch, an
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
	 * @param array<string, mixed> $args Render arguments — `name` (required string,
	 *                                    form input name), `id` (optional string,
	 *                                    defaults to `name`), `value` (optional string,
	 *                                    submitted value when checked, defaults to '1'),
	 *                                    `checked` (optional bool, whether the toggle
	 *                                    starts on), `label` (optional string, visible
	 *                                    label next to the switch), `disabled` (optional
	 *                                    bool, renders disabled), `class` (optional
	 *                                    string, extra classes on the wrapper),
	 *                                    `input_class` (optional string, extra
	 *                                    classes on the inner checkbox — needed
	 *                                    when the rendered field is read by a JS
	 *                                    serializer via class selector, e.g.
	 *                                    `.ffc-field-required`), `title` (optional
	 *                                    string, tooltip on the wrapper label),
	 *                                    `data` (optional array<string,string> of
	 *                                    data-* attributes on the input), `mark`
	 *                                    (optional bool, default true: draws a
	 *                                    check or a cross on the knob so on and
	 *                                    off do not rest on colour alone; #1748,
	 *                                    the default since #1873 — pass false to
	 *                                    opt a toggle out), `aria_label`
	 *                                    (optional string, names the checkbox
	 *                                    when no visible label does, as in a
	 *                                    table cell under a column header),
	 *                                    `description` (optional string, a short
	 *                                    sentence printed as a `p.description`
	 *                                    under the switch and tied to it by
	 *                                    `aria-describedby`; the pair is wrapped
	 *                                    in a `div.ffc-control-toggle-field`, so
	 *                                    do not pass it from inside a `<p>`).
	 *
	 * Several toggles stacked in one cell go inside a
	 * `div.ffc-control-toggle-stack`, which owns the spacing between them
	 * (#1816) — never a `<br />` between calls.
	 */
	public static function render_toggle( array $args ): void {
		$name = $args['name'] ?? '';
		if ( '' === $name ) {
			return;
		}
		$id          = $args['id'] ?? $name;
		$value       = $args['value'] ?? '1';
		$checked     = ! empty( $args['checked'] );
		$disabled    = ! empty( $args['disabled'] );
		$label       = $args['label'] ?? '';
		$title       = trim( (string) ( $args['title'] ?? '' ) );
		$extra       = trim( (string) ( $args['class'] ?? '' ) );
		$input_class = trim( (string) ( $args['input_class'] ?? '' ) );
		$data        = $args['data'] ?? array();

		$mark          = ! array_key_exists( 'mark', $args ) || ! empty( $args['mark'] );
		$wrapper_class = 'ffc-control-toggle' . ( $mark ? ' ffc-control-toggle-mark' : '' ) . ( '' !== $extra ? ' ' . $extra : '' );
		$title_attr    = '' !== $title ? ' title="' . esc_attr( $title ) . '"' : '';

		$data_attrs = '';
		if ( is_array( $data ) ) {
			foreach ( $data as $k => $v ) {
				$data_attrs .= ' data-' . esc_attr( (string) $k ) . '="' . esc_attr( (string) $v ) . '"';
			}
		}

		$input_class_attr = '' !== $input_class ? ' class="' . esc_attr( $input_class ) . '"' : '';
		$aria_label       = trim( (string) ( $args['aria_label'] ?? '' ) );
		if ( '' !== $aria_label ) {
			$data_attrs .= ' aria-label="' . esc_attr( $aria_label ) . '"';
		}

		$description    = trim( (string) ( $args['description'] ?? '' ) );
		$description_id = $id . '-description';
		if ( '' !== $description ) {
			$data_attrs .= ' aria-describedby="' . esc_attr( $description_id ) . '"';
			echo '<div class="ffc-control-toggle-field">';
		}

		printf(
			'<label class="%1$s" for="%2$s"%3$s>',
			esc_attr( $wrapper_class ),
			esc_attr( $id ),
			$title_attr // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped above.
		);
		printf(
			'<input type="checkbox" id="%1$s" name="%2$s" value="%3$s"%4$s%5$s%6$s%7$s>',
			esc_attr( $id ),
			esc_attr( $name ),
			esc_attr( (string) $value ),
			$checked ? ' checked' : '',
			$disabled ? ' disabled' : '',
			$input_class_attr, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped above.
			$data_attrs // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped above.
		);
		echo '<span class="ffc-control-toggle-track" aria-hidden="true"></span>';
		if ( '' !== $label ) {
			echo '<span class="ffc-control-toggle-label">' . esc_html( $label ) . '</span>';
		}
		echo '</label>';
		if ( '' !== $description ) {
			printf(
				'<p class="description" id="%1$s">%2$s</p></div>',
				esc_attr( $description_id ),
				esc_html( $description )
			);
		}
	}

	/**
	 * Palettes a steps control can wear (#1745), passed as `palette`. Each
	 * colours the filled rail and the thumb's ring after the chosen step.
	 *
	 * - `primary`: the accent at every step -- for a scale where no end is
	 *   worse than the other (the default).
	 * - `severity`: grey, amber, red -- the further right, the harsher.
	 * - `traffic`: red, amber, green -- for a scale whose right-hand end is
	 *   the good one.
	 */
	public const STEPS_PALETTES = array( 'primary', 'severity', 'traffic' );

	/**
	 * Tones one step can name in `tones`, overriding the palette for it.
	 */
	public const STEPS_TONES = array( 'neutral', 'primary', 'info', 'success', 'warning', 'danger' );

	/**
	 * Fewest and most options a steps control draws. Outside them a select
	 * is printed instead: two values are a toggle, and past eleven the stops
	 * are too close to read.
	 */
	public const STEPS_MIN = 3;
	public const STEPS_MAX = 11;

	/**
	 * Past this many stops the control tightens and, on a phone, shows only
	 * the chosen step's name.
	 */
	private const STEPS_MANY = 5;

	/**
	 * Render a steps control: a rail with one stop per value of an ordered
	 * scale, filled up to the chosen one (#1745).
	 *
	 * Underneath are native radios, one per stop, so the arrow keys, screen
	 * readers and forced colours work, and the form posts the value without
	 * any script. Use it for an ORDERED scale of 3 to 11 values -- off,
	 * moderate, strict -- never for unrelated choices, which a rail and a
	 * fill would misrepresent. With 2 values use {@see self::render_toggle()}.
	 *
	 * For autosave pass `data-ffc-autosave-key` (or `-form-key`) in `data`:
	 * it lands on each radio, and the widget saves the group as one field.
	 *
	 * @param array<string, mixed> $args Render arguments:
	 *   - `name` (string, required) and `options` (array, required: value =>
	 *     label, mildest first, 3 to 11 of them);
	 *   - `value` (the chosen value) and `default` (shown when `value` is not
	 *     one of the options; the first option otherwise);
	 *   - `id` (base id, derived from `name`) and `legend` (the group's name,
	 *     read by screen readers);
	 *   - `descriptions` (value => text; the chosen one shows under the rail);
	 *   - `labels`: `all` (default) or `selected` -- every step's name under
	 *     its stop, or only the chosen one's (the others stay for screen
	 *     readers);
	 *   - `palette`: a {@see self::STEPS_PALETTES} key, default `primary`;
	 *   - `tones` (value => a {@see self::STEPS_TONES} key), overriding the
	 *     palette for those steps;
	 *   - `size`: `sm`, `md` (default) or `lg`; `shape`: `round` (default) or
	 *     `square`; `fluid` (bool): the stops share the row's width;
	 *   - `disabled` (bool) and `data` (data-* attributes on each radio);
	 *   - `disabled_options` (list of values): those stops stay drawn, named
	 *     and described, but cannot be chosen -- a value the site cannot run
	 *     right now, such as a mode that needs HTTPS (#1758).
	 */
	public static function render_steps( array $args ): void {
		$name    = is_string( $args['name'] ?? null ) ? $args['name'] : '';
		$options = is_array( $args['options'] ?? null ) ? $args['options'] : array();
		if ( '' === $name || array() === $options ) {
			return;
		}
		$values   = array_map( 'strval', array_keys( $options ) );
		$default  = (string) ( $args['default'] ?? '' );
		$default  = in_array( $default, $values, true ) ? $default : $values[0];
		$value    = (string) ( $args['value'] ?? '' );
		$value    = in_array( $value, $values, true ) ? $value : $default;
		$base_id  = self::control_id( $args, $name );
		$data     = self::control_data_attrs( $args );
		$disabled = ! empty( $args['disabled'] ) ? ' disabled' : '';

		$count = count( $values );
		if ( $count < self::STEPS_MIN || $count > self::STEPS_MAX ) {
			_doing_it_wrong(
				__METHOD__,
				// A message to the developer, read under WP_DEBUG: English, untranslated, like core's own.
				esc_html(
					sprintf(
						'A steps control takes %2$d to %3$d ordered options; %1$d were given, so a select is printed instead. Use AdminUI::render_toggle() for two values and a select for more than %3$d.',
						$count,
						self::STEPS_MIN,
						self::STEPS_MAX
					)
				),
				'6.39.0'
			);
			self::control_fallback_select( $base_id, $name, $options, $value, $disabled, $data );
			return;
		}

		$palette = in_array( $args['palette'] ?? null, self::STEPS_PALETTES, true ) ? (string) $args['palette'] : self::STEPS_PALETTES[0];
		$tones   = is_array( $args['tones'] ?? null ) ? $args['tones'] : array();
		$descs   = is_array( $args['descriptions'] ?? null ) ? $args['descriptions'] : array();
		$legend  = is_string( $args['legend'] ?? null ) ? $args['legend'] : '';
		$blocked = is_array( $args['disabled_options'] ?? null ) ? array_map( 'strval', $args['disabled_options'] ) : array();

		$classes = array( 'ffc-control-steps', sprintf( 'ffc-control-steps-%s', $palette ) );
		if ( in_array( $args['size'] ?? null, array( 'sm', 'lg' ), true ) ) {
			$classes[] = sprintf( 'ffc-control-steps-%s', (string) $args['size'] );
		}
		if ( 'square' === ( $args['shape'] ?? null ) ) {
			$classes[] = 'ffc-control-steps-square';
		}
		if ( ! empty( $args['fluid'] ) ) {
			$classes[] = 'ffc-control-steps-fluid';
		}
		if ( 'selected' === ( $args['labels'] ?? null ) ) {
			$classes[] = 'ffc-control-steps-labels-selected';
		}
		if ( $count > self::STEPS_MANY ) {
			$classes[] = 'ffc-control-steps-many';
		}

		// The group carries the base id, so a block can collapse on the
		// chosen value (`data-ffc-master` + `data-ffc-master-value`).
		printf( '<fieldset id="%1$s" class="%2$s"%3$s>', esc_attr( $base_id ), esc_attr( implode( ' ', $classes ) ), $disabled ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $disabled is a literal attribute or empty.
		if ( '' !== $legend ) {
			echo '<legend class="screen-reader-text">' . esc_html( $legend ) . '</legend>';
		}
		echo '<span class="ffc-control-steps-track">';
		foreach ( $values as $i => $v ) {
			$tone = $tones[ $v ] ?? null;
			printf(
				'<label class="ffc-control-steps-stop"%1$s><input type="radio" id="%2$s" name="%3$s" value="%4$s"%5$s%6$s%8$s><span class="ffc-control-steps-mark" aria-hidden="true"></span><span class="ffc-control-steps-label">%7$s</span></label>',
				in_array( $tone, self::STEPS_TONES, true ) ? ' data-tone="' . esc_attr( (string) $tone ) . '"' : '',
				esc_attr( $base_id . '-' . ( $i + 1 ) ),
				esc_attr( $name ),
				esc_attr( $v ),
				$v === $value ? ' checked' : '',
				$data, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped by control_data_attrs().
				esc_html( (string) $options[ $v ] ),
				in_array( $v, $blocked, true ) ? ' disabled' : ''
			);
		}
		echo '</span>';
		foreach ( $values as $i => $v ) {
			if ( isset( $descs[ $v ] ) && '' !== (string) $descs[ $v ] ) {
				printf( '<span class="description ffc-control-steps-desc" data-step="%1$d">%2$s</span>', (int) $i + 1, esc_html( (string) $descs[ $v ] ) );
			}
		}
		echo '</fieldset>';
	}

	/**
	 * Fewest and most options a segmented control draws. Outside them a
	 * select is printed instead: one option is no choice, and past six the
	 * segments no longer fit a settings row.
	 */
	public const SEGMENTED_MIN = 2;
	public const SEGMENTED_MAX = 6;

	/**
	 * Render a segmented control: a row of options joined in one pill, the
	 * chosen one filled (#1750).
	 *
	 * Underneath are native radios, so the arrow keys, screen readers and
	 * forced colours work, and the form posts the value without any script.
	 * Use it for 2 to 6 choices with NO order between them -- dark, light,
	 * auto. An ordered scale is {@see self::render_steps()}; a plain on/off
	 * is {@see self::render_toggle()}.
	 *
	 * For autosave pass `data-ffc-autosave-key` (or `-form-key`) in `data`:
	 * it lands on each radio, and the widget saves the group as one field.
	 *
	 * @param array<string, mixed> $args Render arguments:
	 *   - `name` (string, required) and `options` (array, required: value =>
	 *     label, 2 to 6 of them; keep the labels short);
	 *   - `value` (the chosen value) and `default` (shown when `value` is not
	 *     one of the options; the first option otherwise);
	 *   - `allow_none` (bool): when `value` is not one of the options, no
	 *     option is checked and `default` is ignored -- for a stored state no
	 *     option describes, which the caller explains beside the control;
	 *   - `id` (base id, derived from `name`) and `legend` (the group's name,
	 *     read by screen readers);
	 *   - `descriptions` (value => text; the chosen one shows under the row);
	 *   - `size`: `sm`, `md` (default) or `lg`; `fluid` (bool): the segments
	 *     share the row's width;
	 *   - `disabled` (bool) and `data` (data-* attributes on each radio).
	 */
	public static function render_segmented( array $args ): void {
		$name    = is_string( $args['name'] ?? null ) ? $args['name'] : '';
		$options = is_array( $args['options'] ?? null ) ? $args['options'] : array();
		if ( '' === $name || array() === $options ) {
			return;
		}
		$values   = array_map( 'strval', array_keys( $options ) );
		$default  = (string) ( $args['default'] ?? '' );
		$default  = in_array( $default, $values, true ) ? $default : $values[0];
		$value    = (string) ( $args['value'] ?? '' );
		$value    = in_array( $value, $values, true ) || ! empty( $args['allow_none'] ) ? $value : $default;
		$base_id  = self::control_id( $args, $name );
		$data     = self::control_data_attrs( $args );
		$disabled = ! empty( $args['disabled'] ) ? ' disabled' : '';

		$count = count( $values );
		if ( $count < self::SEGMENTED_MIN || $count > self::SEGMENTED_MAX ) {
			_doing_it_wrong(
				__METHOD__,
				// A message to the developer, read under WP_DEBUG: English, untranslated, like core's own.
				esc_html(
					sprintf(
						'A segmented control takes %2$d to %3$d options; %1$d were given, so a select is printed instead. Use a select for more than %3$d.',
						$count,
						self::SEGMENTED_MIN,
						self::SEGMENTED_MAX
					)
				),
				'6.39.0'
			);
			self::control_fallback_select( $base_id, $name, $options, $value, $disabled, $data );
			return;
		}

		$descs  = is_array( $args['descriptions'] ?? null ) ? $args['descriptions'] : array();
		$legend = is_string( $args['legend'] ?? null ) ? $args['legend'] : '';

		$classes = array( 'ffc-control-segmented' );
		if ( in_array( $args['size'] ?? null, array( 'sm', 'lg' ), true ) ) {
			$classes[] = sprintf( 'ffc-control-segmented-%s', (string) $args['size'] );
		}
		if ( ! empty( $args['fluid'] ) ) {
			$classes[] = 'ffc-control-segmented-fluid';
		}

		// The group carries the base id, so a block can collapse on the
		// chosen value (`data-ffc-master` + `data-ffc-master-value`).
		printf( '<fieldset id="%1$s" class="%2$s"%3$s>', esc_attr( $base_id ), esc_attr( implode( ' ', $classes ) ), $disabled ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $disabled is a literal attribute or empty.
		if ( '' !== $legend ) {
			echo '<legend class="screen-reader-text">' . esc_html( $legend ) . '</legend>';
		}
		echo '<span class="ffc-control-segmented-track">';
		foreach ( $values as $i => $v ) {
			printf(
				'<label class="ffc-control-segmented-option"><input type="radio" id="%1$s" name="%2$s" value="%3$s"%4$s%5$s><span class="ffc-control-segmented-label">%6$s</span></label>',
				esc_attr( $base_id . '-' . ( $i + 1 ) ),
				esc_attr( $name ),
				esc_attr( $v ),
				$v === $value ? ' checked' : '',
				$data, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped by control_data_attrs().
				esc_html( (string) $options[ $v ] )
			);
		}
		echo '</span>';
		foreach ( $values as $i => $v ) {
			if ( isset( $descs[ $v ] ) && '' !== (string) $descs[ $v ] ) {
				printf( '<span class="description ffc-control-segmented-desc" data-option="%1$d">%2$s</span>', (int) $i + 1, esc_html( (string) $descs[ $v ] ) );
			}
		}
		echo '</fieldset>';
	}

	/**
	 * Base id of a control's radios: `id` when given, else derived from `name`.
	 *
	 * @param array<string, mixed> $args Render arguments.
	 * @param string               $name The field name.
	 * @return string
	 */
	private static function control_id( array $args, string $name ): string {
		return is_string( $args['id'] ?? null ) && '' !== $args['id'] ? $args['id'] : trim( (string) preg_replace( '/[^a-z0-9_-]+/i', '-', $name ), '-' );
	}

	/**
	 * The `data` argument of a control as escaped `data-*` attributes.
	 *
	 * @param array<string, mixed> $args Render arguments.
	 * @return string Escaped attributes, each with a leading space.
	 */
	private static function control_data_attrs( array $args ): string {
		$data  = is_array( $args['data'] ?? null ) ? $args['data'] : array();
		$attrs = '';
		foreach ( $data as $k => $v ) {
			$attrs .= ' data-' . esc_attr( (string) $k ) . '="' . esc_attr( (string) $v ) . '"';
		}
		return $attrs;
	}

	/**
	 * The select a control prints when it is given a number of options it
	 * cannot draw, so the field still works.
	 *
	 * @param string               $id       Element id.
	 * @param string               $name     Field name.
	 * @param array<string, mixed> $options  Value => label.
	 * @param string               $value    The chosen value.
	 * @param string               $disabled ' disabled' or empty.
	 * @param string               $data     Pre-escaped data attributes.
	 */
	private static function control_fallback_select( string $id, string $name, array $options, string $value, string $disabled, string $data ): void {
		printf( '<select id="%1$s" name="%2$s"%3$s%4$s>', esc_attr( $id ), esc_attr( $name ), $disabled, $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literals and pre-escaped attributes.
		foreach ( $options as $v => $label ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( (string) $v ), selected( (string) $v, $value, false ), esc_html( (string) $label ) );
		}
		echo '</select>';
	}

	/**
	 * A button of the `.ffc-btn` family (#1874): the category says what the
	 * action does, the icon is a `Core\Icons` class.
	 *
	 * The family is shared with the public screens, so the markup lives in
	 * {@see \FreeFormCertificate\Core\Button}; this is its admin entry point.
	 *
	 * @param array<string, mixed> $args See {@see \FreeFormCertificate\Core\Button::html()}.
	 * @return string
	 */
	public static function button( array $args ): string {
		return \FreeFormCertificate\Core\Button::html( $args );
	}

	/**
	 * A column header for a table drawn by hand, in the markup
	 * `WP_List_Table::print_column_headers()` prints (#2291).
	 *
	 * A list built on WP_List_Table gets its sortable headers from core; a
	 * hand-drawn one (the Audience bookings list) calls this, so both look
	 * and behave alike: the same classes, arrows, `aria-sort` and the same
	 * flip on a second click. A column not in `$sortable` prints plain text.
	 *
	 * @param string                $key      The column's sort key, '' when it does not sort.
	 * @param string                $label    Header text.
	 * @param string                $column   The `column-*` class suffix.
	 * @param array<string, string> $sortable Sort key → direction of a first click (`asc` / `desc`).
	 * @param string                $orderby  The sort key in effect.
	 * @param string                $order    The direction in effect, `asc` or `desc`.
	 * @param string                $base_url The list URL with its filters, without paging or sorting.
	 * @return string
	 */
	public static function sortable_column_header( string $key, string $label, string $column, array $sortable, string $orderby, string $order, string $base_url ): string {
		if ( '' === $key || ! isset( $sortable[ $key ] ) ) {
			return sprintf( '<th scope="col" class="manage-column column-%s">%s</th>', esc_attr( $column ), esc_html( $label ) );
		}
		$current = $key === $orderby;
		$next    = $current ? ( 'asc' === $order ? 'desc' : 'asc' ) : ( 'desc' === $sortable[ $key ] ? 'desc' : 'asc' );
		// Core marks an unsorted column with the arrow it does NOT start on.
		$class = $current ? 'sorted ' . ( 'asc' === $order ? 'asc' : 'desc' ) : 'sortable ' . ( 'asc' === $next ? 'desc' : 'asc' );
		$url   = add_query_arg(
			array(
				'orderby' => $key,
				'order'   => $next,
			),
			$base_url
		);
		// Core tells a screen reader what a click on an unsorted column does;
		// the sorted one announces itself through `aria-sort`.
		$hint = '';
		if ( ! $current ) {
			$hint = ' <span class="screen-reader-text">' . esc_html( 'asc' === $next ? __( 'Sort ascending.', 'ffcertificate' ) : __( 'Sort descending.', 'ffcertificate' ) ) . '</span>';
		}
		return sprintf(
			'<th scope="col" class="manage-column column-%1$s %2$s"%3$s><a href="%4$s"><span>%5$s</span><span class="sorting-indicators"><span class="sorting-indicator asc" aria-hidden="true"></span><span class="sorting-indicator desc" aria-hidden="true"></span></span>%6$s</a></th>',
			esc_attr( $column ),
			esc_attr( $class ),
			$current ? ' aria-sort="' . ( 'asc' === $order ? 'ascending' : 'descending' ) . '"' : '',
			esc_url( $url ),
			esc_html( $label ),
			$hint
		);
	}

	/**
	 * Print {@see self::button()}.
	 *
	 * @param array<string, mixed> $args See {@see \FreeFormCertificate\Core\Button::html()}.
	 */
	public static function render_button( array $args ): void {
		\FreeFormCertificate\Core\Button::render( $args );
	}

	/**
	 * The plugin's one file field (#2038): a neutral family button with the
	 * `upload` icon, and beside it the name of the chosen file.
	 *
	 * The browser's own "Choose File" button cannot carry an icon, so it is
	 * hidden and a `<label>` for the input is drawn as the button; the input
	 * stays in the form, focusable and visible as the file name the browser
	 * prints. No script is needed: the label opens the picker, and the browser
	 * updates the name.
	 *
	 * @param array<string, mixed> $args `id` (required, the label points at it),
	 *                                   `name`, `accept`, `required` (bool),
	 *                                   `class` (extra classes on the input, the
	 *                                   hooks scripts read) and `label`
	 *                                   (defaults to "Choose file").
	 * @return string
	 */
	public static function file_picker( array $args ): string {
		$id = (string) ( $args['id'] ?? '' );
		if ( '' === $id ) {
			$id = function_exists( 'wp_unique_id' ) ? wp_unique_id( 'ffc-file-' ) : 'ffc-file-' . wp_rand();
		}
		$label   = (string) ( $args['label'] ?? '' );
		$classes = trim( 'ffc-file-picker-input ' . (string) ( $args['class'] ?? '' ) );

		$input = '<input type="file" id="' . esc_attr( $id ) . '" class="' . esc_attr( $classes ) . '"';
		foreach ( array( 'name', 'accept' ) as $key ) {
			if ( isset( $args[ $key ] ) && '' !== (string) $args[ $key ] ) {
				$input .= ' ' . $key . '="' . esc_attr( (string) $args[ $key ] ) . '"';
			}
		}
		if ( ! empty( $args['required'] ) ) {
			$input .= ' required';
		}
		$input .= '>';

		return '<span class="ffc-file-picker">' . $input
			. '<label for="' . esc_attr( $id ) . '" class="ffc-btn ffc-btn-neutral ffc-icon-upload ffc-file-picker-button">'
			. esc_html( '' !== $label ? $label : __( 'Choose file', 'ffcertificate' ) )
			. '</label></span>';
	}

	/**
	 * Print {@see self::file_picker()}.
	 *
	 * @param array<string, mixed> $args See {@see self::file_picker()}.
	 */
	public static function render_file_picker( array $args ): void {
		echo self::file_picker( $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every attribute and the label are escaped in file_picker().
	}

	/**
	 * The save button of a settings form: the screen's primary action, in a
	 * `p.submit` like the `submit_button()` it replaces (#1874).
	 *
	 * @param string               $label Label; defaults to WordPress's "Save Changes".
	 * @param array<string, mixed> $args  Further {@see self::button()} arguments.
	 */
	public static function render_save_button( string $label = '', array $args = array() ): void {
		echo '<p class="submit">';
		self::render_button(
			array_merge(
				array(
					'label'    => '' !== $label ? $label : __( 'Save Changes', 'ffcertificate' ),
					'category' => 'primary',
					'icon'     => 'save',
					'type'     => 'submit',
					'name'     => 'submit',
				),
				$args
			)
		);
		echo '</p>';
	}

	/**
	 * Same as {@see self::render_toggle()} but returns the markup as a string
	 * instead of echoing — for callers that build an HTML string.
	 *
	 * @param array<string, mixed> $args See {@see self::render_toggle()}.
	 * @return string
	 */
	public static function get_toggle( array $args ): string {
		ob_start();
		self::render_toggle( $args );
		return (string) ob_get_clean();
	}

	/**
	 * Markup for an empty state (`.ffc-empty-state`): an icon badge, a title,
	 * a sentence saying why the list is empty, and the actions that change it.
	 *
	 * One helper for every admin list, so an empty list says the same kind of
	 * thing everywhere: which view or filter produced nothing, and the way out.
	 * The badge takes the primary tone: the neutral one has the ground of a
	 * striped list row, so on the table it vanished.
	 *
	 * @param array<string, mixed> $args `icon` (registered `Core\Icons` class name
	 *                                    without the `ffc-icon-` prefix, default
	 *                                    `inbox`), `title` (required string), `text`
	 *                                    (optional string), `actions` (optional list
	 *                                    of `{label, url, category?, icon?}`, drawn
	 *                                    as `.ffc-btn` links, neutral by default).
	 * @return string
	 */
	public static function get_empty_state( array $args ): string {
		$title = (string) ( $args['title'] ?? '' );
		if ( '' === $title ) {
			return '';
		}
		$icon    = (string) ( $args['icon'] ?? 'inbox' );
		$text    = (string) ( $args['text'] ?? '' );
		$actions = is_array( $args['actions'] ?? null ) ? $args['actions'] : array();

		$html  = '<div class="ffc-empty-state">';
		$html .= '<span class="ffc-empty-state__icon ffc-icon-badge ffc-icon-badge-primary ffc-icon-' . esc_attr( $icon ) . '" aria-hidden="true"></span>';
		$html .= '<p class="ffc-empty-state__title">' . esc_html( $title ) . '</p>';
		if ( '' !== $text ) {
			$html .= '<p class="ffc-empty-state__text">' . esc_html( $text ) . '</p>';
		}

		$links = '';
		foreach ( $actions as $action ) {
			if ( ! is_array( $action ) || empty( $action['label'] ) || empty( $action['url'] ) ) {
				continue;
			}
			$links .= self::button(
				array(
					'label'    => (string) $action['label'],
					'href'     => (string) $action['url'],
					'category' => (string) ( $action['category'] ?? 'neutral' ),
					'icon'     => (string) ( $action['icon'] ?? '' ),
				)
			);
		}
		if ( '' !== $links ) {
			$html .= '<div class="ffc-empty-state__actions">' . $links . '</div>';
		}

		return $html . '</div>';
	}

	/**
	 * Opening markup of a collapsible section (`.ffc-section`): a `<details>`
	 * whose summary carries an icon, a title, a hint and, optionally, a chip.
	 *
	 * The chip says what the section is set to without opening it. With
	 * `master` it follows a toggle live (`ffc-admin-sections.js` keeps it in
	 * step); with `chip` it is fixed text the caller computed. Close the
	 * section with {@see self::section_close()}.
	 *
	 * @param array<string, mixed> $args `title` (required), `icon` (a `Core\Icons`
	 *                                    drawing name), `hint`, `open` (bool),
	 *                                    `id`, `master` (id of the toggle the
	 *                                    chip follows) with `on` (its state at
	 *                                    render), `chip` (fixed chip text).
	 * @return string
	 */
	public static function section_open( array $args ): string {
		$title  = (string) ( $args['title'] ?? '' );
		$icon   = (string) ( $args['icon'] ?? '' );
		$hint   = (string) ( $args['hint'] ?? '' );
		$id     = (string) ( $args['id'] ?? '' );
		$master = (string) ( $args['master'] ?? '' );
		$chip   = (string) ( $args['chip'] ?? '' );

		$html  = sprintf(
			'<details class="ffc-section"%s%s data-ffc-section>',
			'' !== $id ? ' id="' . esc_attr( $id ) . '"' : '',
			empty( $args['open'] ) ? '' : ' open'
		);
		$html .= '<summary class="ffc-section__summary">';
		if ( '' !== $icon ) {
			$html .= '<span class="ffc-section__icon">' . \FreeFormCertificate\Core\Icons::svg( $icon, 22 ) . '</span>';
		}
		$html .= '<span class="ffc-section__text"><span class="ffc-section__title">' . esc_html( $title ) . '</span>';
		if ( '' !== $hint ) {
			$html .= '<span class="ffc-section__hint">' . esc_html( $hint ) . '</span>';
		}
		$html .= '</span>';
		if ( '' !== $master ) {
			$on    = ! empty( $args['on'] );
			$html .= sprintf(
				'<span class="ffc-section__chip %s" data-ffc-section-master="%s" data-on="%s" data-off="%s">%s</span>',
				$on ? 'is-on' : 'is-off',
				esc_attr( $master ),
				esc_attr__( 'On', 'ffcertificate' ),
				esc_attr__( 'Off', 'ffcertificate' ),
				$on ? esc_html__( 'On', 'ffcertificate' ) : esc_html__( 'Off', 'ffcertificate' )
			);
		} elseif ( '' !== $chip ) {
			$html .= '<span class="ffc-section__chip">' . esc_html( $chip ) . '</span>';
		}
		return $html . '</summary><div class="ffc-section__body">';
	}

	/**
	 * Closing markup of a section opened with {@see self::section_open()}.
	 *
	 * @return string
	 */
	public static function section_close(): string {
		return '</div></details>';
	}

	/**
	 * Print {@see self::section_open()}.
	 *
	 * @param array<string, mixed> $args See {@see self::section_open()}.
	 */
	public static function render_section_open( array $args ): void {
		echo self::section_open( $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- section_open() escapes every value.
	}

	/**
	 * Print {@see self::section_close()}.
	 */
	public static function render_section_close(): void {
		echo self::section_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static closing markup.
	}

	/**
	 * Script handle of the vertical tabs (`assets/js/ffc-vertical-tabs.js`).
	 */
	public const VERTICAL_TABS_HANDLE = 'ffc-vertical-tabs';

	/**
	 * Enqueue the vertical-tab script, for a screen that prints the
	 * component.
	 *
	 * @return void
	 */
	public static function enqueue_vertical_tabs(): void {
		$s = \FreeFormCertificate\Core\AssetHelper::asset_suffix();
		// ffc-core: the required-tag banner is drawn by FFC.notice() (#1843).
		\FreeFormCertificate\Core\AssetRegistry::enqueue_scripts( 'ffc-core' );
		wp_enqueue_script(
			self::VERTICAL_TABS_HANDLE,
			FFC_PLUGIN_URL . "assets/js/ffc-vertical-tabs{$s}.js",
			array( 'jquery', 'ffc-core' ),
			FFC_VERSION,
			true
		);
	}

	/**
	 * Script handle of the shortcode copy button (`assets/js/ffc-copy-shortcode.js`).
	 */
	public const SHORTCODE_COPY_HANDLE = 'ffc-copy-shortcode';

	/**
	 * Enqueue the script behind the shortcode copy button, for a screen that
	 * prints {@see self::render_shortcode_copy()}.
	 *
	 * @return void
	 */
	public static function enqueue_shortcode_copy(): void {
		$s = \FreeFormCertificate\Core\AssetHelper::asset_suffix();
		wp_enqueue_script(
			self::SHORTCODE_COPY_HANDLE,
			FFC_PLUGIN_URL . "assets/js/ffc-copy-shortcode{$s}.js",
			array(),
			FFC_VERSION,
			true
		);
	}

	/**
	 * Print a shortcode with its copy button, the compact form a list-table
	 * cell uses: the code, and an icon button that copies it.
	 *
	 * One markup for every list that shows a shortcode — the certificate
	 * forms list, and the audience calendar and environment lists (#1814) —
	 * so `ffc-copy-shortcode.js` and the `.ffc-shortcode-cell` rules in
	 * `ffc-admin.css` serve them all. A screen that prints it calls
	 * {@see self::enqueue_shortcode_copy()}.
	 *
	 * @param string $shortcode The shortcode, unescaped.
	 * @return void
	 */
	public static function render_shortcode_copy( string $shortcode ): void {
		$label = __( 'Copy shortcode', 'ffcertificate' );
		printf(
			'<span class="ffc-shortcode-cell">'
			. '<code class="ffc-shortcode-code">%1$s</code>'
			. '<button type="button" class="ffc-copy-shortcode" data-shortcode="%2$s" title="%3$s" aria-label="%3$s">'
			. '<span class="ffc-icon-copy" aria-hidden="true"></span>'
			. '</button>'
			. '</span>',
			esc_html( $shortcode ),
			esc_attr( $shortcode ),
			esc_attr( $label )
		);
	}

	/**
	 * Print a vertical-tab container (`.ffc-vtabs`): a nav on the left
	 * and one `<section role="tabpanel">` per tab on the right.
	 *
	 * One markup for every editor that splits its settings into tabs — the
	 * certificate form editor, the personal calendar editor, and the audience
	 * calendar and audience editors (#1761) — so `ffc-vertical-tabs.js` and
	 * the component's CSS serve them all. Every panel stays in the DOM (an
	 * inactive one is only `display:none` once the script marks the container
	 * ready), so the post save reads every field, and without the script the
	 * panels degrade to a stacked layout.
	 *
	 * This prints every panel through a callback. A screen whose panels hold
	 * forms of their own — one `<form>` spanning a run of panels, the next
	 * panel holding another — prints the same markup with the streaming
	 * primitives this uses: {@see self::vertical_tabs_open()},
	 * {@see self::vertical_tab_panel_open()}, {@see self::vertical_tab_panel_close()}
	 * and {@see self::vertical_tabs_close()}.
	 *
	 * @param array<int, array{key: string, icon: string, label: string, title?: string, masters?: array<int, string>, needs?: string}> $tabs        Tabs, in display order; the first starts active.
	 * @param callable(string): void                                                                                                    $render_body Prints a panel's body, given its tab key.
	 */
	public static function render_vertical_tabs( array $tabs, callable $render_body ): void {
		$active = (string) ( $tabs[0]['key'] ?? '' );

		self::vertical_tabs_open( $tabs, $active );
		foreach ( $tabs as $tab ) {
			self::vertical_tab_panel_open( $tab, $active );
			$render_body( $tab['key'] );
			self::vertical_tab_panel_close();
		}
		self::vertical_tabs_close();
	}

	/**
	 * Open a vertical-tab container: the tab strip, then the panels column.
	 *
	 * Each tab is `key`, `icon` (an `ffc-icon-*` class, resolved through
	 * {@see \FreeFormCertificate\Core\Icons::tab_class()}), `label` (terse,
	 * in the nav), optionally `title` (the panel heading; the label when
	 * absent), `masters` (ids of the toggles that switch the tab's feature on,
	 * which draw the state dot) and `needs` (a selector list of fields the tab
	 * cannot do without). The state texts the dots read are printed only when
	 * a tab draws one.
	 *
	 * @param array<int, array{key: string, icon: string, label: string, title?: string, masters?: array<int, string>, needs?: string}> $tabs        Tabs, in display order.
	 * @param string                                                                                                                    $active      Key of the tab that starts open.
	 * @param string                                                                                                                    $extra_class Further classes on the container, for a screen's own layout.
	 * @return void
	 */
	public static function vertical_tabs_open( array $tabs, string $active, string $extra_class = '' ): void {
		printf(
			'<div class="%s" data-ffc-vtabs>',
			esc_attr( '' === $extra_class ? 'ffc-vtabs' : $extra_class . ' ffc-vtabs' )
		);

		$has_state = false;
		foreach ( $tabs as $tab ) {
			if ( array() !== ( $tab['masters'] ?? array() ) || '' !== ( $tab['needs'] ?? '' ) ) {
				$has_state = true;
				break;
			}
		}
		if ( $has_state ) {
			printf(
				'<ul class="ffc-vtabs__nav" role="tablist" aria-orientation="vertical" data-ffc-state-on="%s" data-ffc-state-off="%s" data-ffc-state-pending="%s">',
				esc_attr__( 'On', 'ffcertificate' ),
				esc_attr__( 'Off', 'ffcertificate' ),
				esc_attr__( 'Incomplete', 'ffcertificate' )
			);
		} else {
			echo '<ul class="ffc-vtabs__nav" role="tablist" aria-orientation="vertical">';
		}

		foreach ( $tabs as $tab ) {
			$is_active = $tab['key'] === $active;
			$masters   = $tab['masters'] ?? array();
			$needs     = $tab['needs'] ?? '';
			printf(
				'<li class="ffc-vtabs__nav-item" role="presentation"><a href="#ffc-tab-%1$s" id="ffc-tabnav-%1$s" class="ffc-vtabs__tab%2$s" role="tab" aria-controls="ffc-tabpanel-%1$s" aria-selected="%3$s" tabindex="%4$s"%7$s%8$s><span class="ffc-vtabs__icon %5$s" aria-hidden="true"></span><span class="ffc-vtabs__label">%6$s</span></a></li>',
				esc_attr( $tab['key'] ),
				$is_active ? ' is-active' : '',
				$is_active ? 'true' : 'false',
				$is_active ? '0' : '-1',
				esc_attr( \FreeFormCertificate\Core\Icons::tab_class( $tab['icon'] ) ),
				esc_html( $tab['label'] ),
				array() === $masters ? '' : ' data-ffc-tab-masters="' . esc_attr( implode( ' ', $masters ) ) . '"',
				'' === $needs ? '' : ' data-ffc-tab-needs="' . esc_attr( $needs ) . '"'
			);
		}
		echo '</ul>';

		echo '<div class="ffc-vtabs__panels">';
	}

	/**
	 * Open one panel of a vertical-tab container, with its title.
	 *
	 * A panel is reached by its id (`ffc-tabpanel-<key>`), never by
	 * position, which is what lets a `<form>` wrap a run of panels while the
	 * next panel holds a form of its own.
	 *
	 * @param array{key: string, icon: string, label: string, title?: string} $tab    The tab.
	 * @param string                                                          $active Key of the tab that starts open.
	 * @return void
	 */
	public static function vertical_tab_panel_open( array $tab, string $active ): void {
		printf(
			'<section id="ffc-tabpanel-%1$s" class="ffc-vtabs__panel%2$s" role="tabpanel" aria-labelledby="ffc-tabnav-%1$s" tabindex="0">',
			esc_attr( $tab['key'] ),
			$tab['key'] === $active ? ' is-active' : ''
		);
		printf(
			'<h2 class="ffc-vtabs__panel-title"><span class="ffc-vtabs__icon %1$s" aria-hidden="true"></span>%2$s</h2>',
			esc_attr( \FreeFormCertificate\Core\Icons::tab_class( $tab['icon'] ) ),
			esc_html( $tab['title'] ?? $tab['label'] )
		);
	}

	/**
	 * Close a panel opened with {@see self::vertical_tab_panel_open()}.
	 *
	 * @return void
	 */
	public static function vertical_tab_panel_close(): void {
		echo '</section>';
	}

	/**
	 * Close the panels column and the container.
	 *
	 * @return void
	 */
	public static function vertical_tabs_close(): void {
		echo '</div></div>';
	}

	/**
	 * Markup for a stat card (`.ffc-stat-card`): an icon badge, a number and
	 * what it counts. Lay several out in a `.ffc-stats` row.
	 *
	 * @param array<string, mixed> $args `label` (required), `value` (int or
	 *                                    string; '—' until known), `icon`
	 *                                    (`.ffc-icon-*` name without the
	 *                                    prefix), `tone` (the badge's tone:
	 *                                    primary by default, or info, success,
	 *                                    warning, danger, neutral), `id` (on
	 *                                    the value, for a script that fills
	 *                                    it in), `url` with `link` (a link
	 *                                    under the label to the screen the
	 *                                    number counts).
	 * @return string
	 */
	public static function get_stat_card( array $args ): string {
		$label = (string) ( $args['label'] ?? '' );
		if ( '' === $label ) {
			return '';
		}
		$value = $args['value'] ?? '—';
		$value = is_int( $value ) ? number_format_i18n( $value ) : (string) $value;
		$icon  = (string) ( $args['icon'] ?? '' );
		$id    = (string) ( $args['id'] ?? '' );
		$url   = (string) ( $args['url'] ?? '' );
		$link  = (string) ( $args['link'] ?? '' );
		$tone  = (string) ( $args['tone'] ?? 'primary' );

		$html = '<div class="ffc-stat-card">';
		if ( '' !== $icon ) {
			// The plain badge is the neutral one; every other tone is a modifier.
			// Whole class names, never assembled, so the icon-class scan reads
			// each one (IconStylesheetTest).
			$badges = array(
				'primary' => 'ffc-icon-badge ffc-icon-badge-primary',
				'info'    => 'ffc-icon-badge ffc-icon-badge-info',
				'success' => 'ffc-icon-badge ffc-icon-badge-success',
				'warning' => 'ffc-icon-badge ffc-icon-badge-warning',
				'danger'  => 'ffc-icon-badge ffc-icon-badge-danger',
			);
			$badge  = $badges[ $tone ] ?? 'ffc-icon-badge';
			$html  .= '<span class="ffc-stat-card__icon ' . esc_attr( $badge ) . ' ffc-icon-' . esc_attr( $icon ) . '" aria-hidden="true"></span>';
		}
		$html .= sprintf(
			'<span class="ffc-stat-card__value"%s>%s</span><span class="ffc-stat-card__label">%s</span>',
			'' !== $id ? ' id="' . esc_attr( $id ) . '"' : '',
			esc_html( $value ),
			esc_html( $label )
		);
		if ( '' !== $url && '' !== $link ) {
			$html .= sprintf(
				'<a class="ffc-stat-card__link" href="%s">%s &rarr;</a>',
				esc_url( $url ),
				esc_html( $link )
			);
		}
		return $html . '</div>';
	}

	/**
	 * The "Mandatory Tags" line under a certificate HTML editor, read from
	 * the configured list (#1734). It used to be a fixed sentence naming the
	 * default three, so a list changed on the Certificates tab was enforced
	 * on save and contradicted on the screen.
	 *
	 * @return string Escaped HTML.
	 */
	public static function get_required_tags_line(): string {
		$tags = array_map(
			static fn( string $tag ): string => '<code>' . esc_html( $tag ) . '</code>',
			\FreeFormCertificate\Settings\SettingsReader::required_certificate_tags()
		);
		return sprintf(
			'%1$s %2$s. <a href="%3$s">%4$s</a>',
			esc_html__( 'Mandatory Tags:', 'ffcertificate' ),
			implode( ', ', $tags ),
			esc_url( admin_url( 'admin.php?page=ffc-settings&tab=certificates' ) ),
			esc_html__( 'Settings → Certificates', 'ffcertificate' )
		);
	}
}
