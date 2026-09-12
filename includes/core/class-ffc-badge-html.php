<?php
/**
 * Inline-styled "tag" badge helper.
 *
 * Single source of truth for the small, configurable-colored badges used
 * across admin and public surfaces of the plugin (recruitment status badges,
 * adjutancy badges, notice status, preview status, subscription type, etc.).
 *
 * The helper emits a `<span class=… style=…>`: the shape comes from the
 * `.ffc-pill` base class in `ffc-common.css` ({@see self::BASE_CLASS}), and
 * only the operator-chosen colour pair stays in the attribute (#1193).
 *
 * Originally introduced in the recruitment module as `RecruitmentBadgeHtml`
 * in 6.1.0; promoted to `Core\BadgeHtml` in 6.2.0 so other modules
 * (scheduling, reregistration, audience) can reuse the markup contract.
 *
 * @package FreeFormCertificate\Core
 * @since   6.2.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stateless badge-span renderer.
 */
final class BadgeHtml {

	/**
	 * Base class carrying the badge's shape.
	 *
	 * The shape used to be a literal here — `padding:3px 10px;border-radius:12px;…`
	 * concatenated into every `<span>`'s `style` attribute. It moved to
	 * `.ffc-pill` in `ffc-common.css` (#1193), because an inline declaration
	 * beats any class: while it existed, no stylesheet could describe a badge,
	 * and three sheets ended up describing three different ones under one name
	 * (#1183).
	 *
	 * The docblock it replaced justified the inline with *"a badge is rendered
	 * on screens whose stylesheets do not all overlap"*. Measured: all seven
	 * call sites are the recruitment module's, and both of its sheets declare
	 * `array( 'ffc-common' )` as a dependency, so the palette — and this class
	 * with it — reaches every one of them.
	 *
	 * The colour stays inline because it is a hex an operator picked, per
	 * status, in Settings or in the adjutancy row; the foreground is computed
	 * from it by {@see ContrastColor::on()} (#1126). Moving the *rule* for it
	 * into the sheets is #1193's second half.
	 */
	private const BASE_CLASS = 'ffc-pill';

	/**
	 * Class applied while a badge carries a tooltip.
	 *
	 * Was `cursor:help` inline. It is a property of the markup — the badge has
	 * a `title` or it does not — so it is a class, and the stylesheet decides
	 * what that looks like.
	 */
	private const TIP_CLASS = 'ffc-pill-has-tip';

	/**
	 * Class applied when the colour arrives as a custom property on the element.
	 *
	 * The rule that reads `--ffc-badge-row-bg` belongs with the property, and
	 * the property is this class's — so both live in `ffc-common.css`, next to
	 * `.ffc-pill`. Declaring it in the two module sheets instead would be the
	 * same component with no owner that #1162 is about.
	 */
	private const ROW_COLOR_CLASS = 'ffc-pill-row-color';

	/**
	 * Render a badge `<span>` coloured by its own stylesheet.
	 *
	 * The emitted `class` is `.ffc-pill` + the two fragments below, in that
	 * order — shape, family, variant — which is the `.ffc-a.ffc-b` composition
	 * the base already uses in ~108 places. An empty fragment is dropped.
	 *
	 * **No `style` attribute at all.** The colour of every status variant is a
	 * rule the calling module generates from its own settings and appends to
	 * its stylesheet (#1193). An inline declaration would outrank that rule,
	 * which is the whole reason it is gone. Core deliberately does not name the
	 * class that does it — not even in a docblock: `ModuleBoundaryTest` reads
	 * the text, and it is right to, since a `Core → feature` reference is
	 * coupling whether or not it compiles.
	 *
	 * @param string $base_class    Family CSS class (e.g. `ffc-recruitment-status-badge`).
	 * @param string $variant_class Variant CSS class (e.g. `ffc-recruitment-status-empty`).
	 * @param string $label         Localized human-readable label; this method `esc_html()`s it.
	 * @param string $tooltip       Optional `title=""` content (`esc_attr()`'d).
	 * @return string Already-escaped HTML.
	 */
	public static function render( string $base_class, string $variant_class, string $label, string $tooltip = '' ): string {
		return self::span( $base_class, $variant_class, $label, $tooltip, '' );
	}

	/**
	 * Render a badge whose colour is a per-row value, not a per-status one.
	 *
	 * The adjutancy badge is the one case a generated rule cannot serve: the
	 * hex lives in the adjutancy's own database row, so the number of values on
	 * a page is the number of adjutancies on it. Here the value — and only the
	 * value — rides the attribute, as a custom property; the rule that reads it
	 * stays in the stylesheet, where `.ffc-recruitment-adjutancy-badge`
	 * declares the pair.
	 *
	 * The foreground is computed rather than declared, for the same reason it
	 * always was: the background is a colour somebody picked, and no fixed
	 * foreground is readable over all of them (#1126).
	 *
	 * @param string $base_class Family CSS class.
	 * @param string $bg         Hex colour from the row (validated here).
	 * @param string $label      Localized human-readable label.
	 * @param string $tooltip    Optional `title=""` content.
	 * @return string Already-escaped HTML.
	 */
	public static function render_with_row_color( string $base_class, string $bg, string $label, string $tooltip = '' ): string {
		$color = ColorValidator::normalize( $bg, self::FALLBACK_BG );
		$style = sprintf(
			'--ffc-badge-row-bg:%1$s;--ffc-badge-row-text:%2$s;',
			$color,
			ContrastColor::on( $color )
		);

		return self::span( $base_class, self::ROW_COLOR_CLASS, $label, $tooltip, $style );
	}

	/**
	 * Background used when a row holds something that is not a hex colour.
	 */
	private const FALLBACK_BG = '#e9ecef';

	/**
	 * Compose the `<span>`.
	 *
	 * @param string $base_class    Family CSS class.
	 * @param string $variant_class Variant CSS class, or empty.
	 * @param string $label         Localized label.
	 * @param string $tooltip       Optional tooltip.
	 * @param string $style         Already-safe `style` content, or empty.
	 * @return string Already-escaped HTML.
	 */
	private static function span( string $base_class, string $variant_class, string $label, string $tooltip, string $style ): string {
		$has_tip = '' !== $tooltip;
		$classes = implode(
			' ',
			array_filter(
				array( self::BASE_CLASS, $base_class, $variant_class, $has_tip ? self::TIP_CLASS : '' ),
				static fn ( string $fragment ): bool => '' !== $fragment
			)
		);

		return sprintf(
			'<span class="%1$s"%2$s%3$s>%4$s</span>',
			esc_attr( $classes ),
			$has_tip ? ' title="' . esc_attr( $tooltip ) . '"' : '',
			'' !== $style ? ' style="' . esc_attr( $style ) . '"' : '',
			esc_html( $label )
		);
	}
}
