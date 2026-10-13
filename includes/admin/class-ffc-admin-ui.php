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
	 *                                    data-* attributes on the input).
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

		$wrapper_class = 'ffc-toggle' . ( '' !== $extra ? ' ' . $extra : '' );
		$title_attr    = '' !== $title ? ' title="' . esc_attr( $title ) . '"' : '';

		$data_attrs = '';
		if ( is_array( $data ) ) {
			foreach ( $data as $k => $v ) {
				$data_attrs .= ' data-' . esc_attr( (string) $k ) . '="' . esc_attr( (string) $v ) . '"';
			}
		}

		$input_class_attr = '' !== $input_class ? ' class="' . esc_attr( $input_class ) . '"' : '';

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
		echo '<span class="ffc-toggle-track" aria-hidden="true"></span>';
		if ( '' !== $label ) {
			echo '<span class="ffc-toggle-label">' . esc_html( $label ) . '</span>';
		}
		echo '</label>';
	}

	/**
	 * Palettes a three-position switch can wear (#1745), passed as `palette`.
	 * Each colours the knob through the `ffc-tri-toggle-<palette>` class.
	 *
	 * - `severity`: grey, amber, red -- the further right, the harsher.
	 * - `traffic`: red, amber, green -- a traffic light, for a scale where
	 *   the right-hand end is the good one.
	 */
	public const TRI_PALETTES = array( 'severity', 'traffic' );

	/**
	 * Render a three-position switch: a track and a knob whose position and
	 * colour say which of three ordered values is chosen (#1745).
	 *
	 * Underneath are three native radios, so the arrow keys, screen readers
	 * and forced colours work, and the form posts the value without any
	 * script. The chosen value's name and its description sit beside the
	 * switch and are swapped by CSS alone. Use it for an ordered scale --
	 * off, moderate, strict -- not for three unrelated choices, which a
	 * position and a colour would misrepresent.
	 *
	 * For autosave pass `data-ffc-autosave-key` (or `-form-key`) in `data`:
	 * it lands on each radio, and the widget saves the group as one field.
	 *
	 * @param array<string, mixed> $args Render arguments -- `name` (required),
	 *                                    `options` (required: exactly three
	 *                                    value => label pairs, mildest first),
	 *                                    `value` (the chosen value; the first
	 *                                    option when it is not one of them),
	 *                                    `id` (optional base id, derived from
	 *                                    `name`), `legend` (optional group
	 *                                    name read by screen readers),
	 *                                    `palette` (optional, a
	 *                                    {@see self::TRI_PALETTES} key, default
	 *                                    `severity`), `descriptions` (optional
	 *                                    value => text), `disabled` (optional
	 *                                    bool), `data` (optional data-*
	 *                                    attributes on each radio).
	 */
	public static function render_tri_toggle( array $args ): void {
		$name    = is_string( $args['name'] ?? null ) ? $args['name'] : '';
		$options = is_array( $args['options'] ?? null ) ? $args['options'] : array();
		if ( '' === $name || 3 !== count( $options ) ) {
			return;
		}
		$values  = array_map( 'strval', array_keys( $options ) );
		$value   = (string) ( $args['value'] ?? '' );
		$value   = in_array( $value, $values, true ) ? $value : $values[0];
		$base_id = is_string( $args['id'] ?? null ) && '' !== $args['id'] ? $args['id'] : trim( (string) preg_replace( '/[^a-z0-9_-]+/i', '-', $name ), '-' );
		$palette = in_array( $args['palette'] ?? null, self::TRI_PALETTES, true ) ? (string) $args['palette'] : self::TRI_PALETTES[0];
		$legend  = is_string( $args['legend'] ?? null ) ? $args['legend'] : '';
		$descs   = is_array( $args['descriptions'] ?? null ) ? $args['descriptions'] : array();
		$data    = is_array( $args['data'] ?? null ) ? $args['data'] : array();

		$data_attrs = '';
		foreach ( $data as $k => $v ) {
			$data_attrs .= ' data-' . esc_attr( (string) $k ) . '="' . esc_attr( (string) $v ) . '"';
		}

		printf( '<fieldset class="ffc-tri-toggle ffc-tri-toggle-%s">', esc_attr( $palette ) );
		if ( '' !== $legend ) {
			echo '<legend class="screen-reader-text">' . esc_html( $legend ) . '</legend>';
		}
		foreach ( $values as $i => $v ) {
			printf(
				'<input type="radio" class="ffc-tri-toggle-input" id="%1$s" name="%2$s" value="%3$s"%4$s%5$s%6$s>',
				esc_attr( $base_id . '-' . ( $i + 1 ) ),
				esc_attr( $name ),
				esc_attr( $v ),
				$v === $value ? ' checked' : '',
				! empty( $args['disabled'] ) ? ' disabled' : '',
				$data_attrs // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped above.
			);
		}
		echo '<span class="ffc-tri-toggle-track">';
		foreach ( $values as $i => $v ) {
			printf(
				'<label class="ffc-tri-toggle-stop" for="%1$s"><span class="screen-reader-text">%2$s</span></label>',
				esc_attr( $base_id . '-' . ( $i + 1 ) ),
				esc_html( (string) $options[ $v ] )
			);
		}
		echo '<span class="ffc-tri-toggle-knob" aria-hidden="true"></span></span>';
		echo '<span class="ffc-tri-toggle-value" aria-hidden="true">';
		foreach ( $values as $i => $v ) {
			printf( '<span class="ffc-tri-toggle-at-%1$d">%2$s</span>', (int) $i + 1, esc_html( (string) $options[ $v ] ) );
		}
		echo '</span>';
		foreach ( $values as $i => $v ) {
			if ( isset( $descs[ $v ] ) && '' !== (string) $descs[ $v ] ) {
				printf( '<span class="description ffc-tri-toggle-desc ffc-tri-toggle-at-%1$d">%2$s</span>', (int) $i + 1, esc_html( (string) $descs[ $v ] ) );
			}
		}
		echo '</fieldset>';
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
