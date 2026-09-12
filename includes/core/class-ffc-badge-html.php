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
	 * Render a badge `<span>` with the supplied attributes.
	 *
	 * The emitted `class` is `.ffc-pill` + the two fragments below, in that
	 * order — shape, family, variant — which is the `.ffc-a.ffc-b` composition
	 * the base already uses in ~108 places. An empty fragment is dropped.
	 *
	 * - `$base_class`    family CSS class fragment (e.g. `ffc-recruitment-status-badge`).
	 * - `$variant_class` value-specific class fragment (e.g. `ffc-recruitment-status-empty`).
	 * - `$bg`            pre-validated hex color (caller is responsible for hex validation;
	 *                    use {@see ColorValidator::normalize()}).
	 * - `$label`         already-localized human-readable text; this method `esc_html()`s it.
	 * - `$tooltip`       optional `title=""` content (`esc_attr()`'d). When non-empty,
	 *                    `cursor:help` is added so visitors get a hover hint.
	 *
	 * @param string $base_class    Base CSS class.
	 * @param string $variant_class Variant CSS class.
	 * @param string $bg            Pre-validated hex color.
	 * @param string $label         Localized human-readable label.
	 * @param string $tooltip       Optional tooltip text (rendered as `title=""`).
	 * @return string Already-escaped HTML.
	 */
	public static function render( string $base_class, string $variant_class, string $bg, string $label, string $tooltip = '' ): string {
		$has_tip = '' !== $tooltip;
		$cursor  = $has_tip ? 'help' : 'default';
		$title   = $has_tip ? ' title="' . esc_attr( $tooltip ) . '"' : '';
		$classes = implode(
			' ',
			array_filter(
				array( self::BASE_CLASS, $base_class, $variant_class ),
				static fn ( string $fragment ): bool => '' !== $fragment
			)
		);
		return sprintf(
			'<span class="%1$s"%2$s style="background:%3$s;color:%4$s;cursor:%5$s;">%6$s</span>',
			esc_attr( $classes ),
			$title,
			esc_attr( $bg ),
			esc_attr( ContrastColor::on( $bg ) ),
			esc_attr( $cursor ),
			esc_html( $label )
		);
	}
}
