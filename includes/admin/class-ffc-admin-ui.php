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
	 *                                    (optional bool, draws a check or a cross
	 *                                    on the knob so on and off do not rest on
	 *                                    colour alone; #1748), `aria_label`
	 *                                    (optional string, names the checkbox
	 *                                    when no visible label does, as in a
	 *                                    table cell under a column header).
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

		$wrapper_class = 'ffc-control-toggle' . ( ! empty( $args['mark'] ) ? ' ffc-control-toggle-mark' : '' ) . ( '' !== $extra ? ' ' . $extra : '' );
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
		$value    = in_array( $value, $values, true ) ? $value : $default;
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
	 *                                    of `{label, url, primary?}`).
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
			$links .= sprintf(
				'<a href="%s" class="button%s">%s</a>',
				esc_url( (string) $action['url'] ),
				empty( $action['primary'] ) ? '' : ' button-primary',
				esc_html( (string) $action['label'] )
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
			static fn( string $tag ): string => '<code>' . esc_html( $tag ) . '</code>'
				// Asked only of a form with an Event Schedule (#1741).
				. ( '{{schedule}}' === $tag ? ' ' . esc_html__( '(when the form has an Event Schedule)', 'ffcertificate' ) : '' ),
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
