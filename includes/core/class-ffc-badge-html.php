<?php
/**
 * Inline-styled "tag" badge helper.
 *
 * Single source of truth for the small, configurable-colored badges used
 * across admin and public surfaces of the plugin (recruitment status badges,
 * adjutancy badges, notice status, preview status, subscription type, etc.).
 *
 * The helper emits a `<span class=… style=…>` shape; visual treatment
 * (padding / radius / font-size / display) is captured in {@see self::BADGE_STYLE}
 * so future changes (dark-mode-aware text color, accessibility attributes,
 * padding tweaks) flow through every badge with one edit.
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

	/** Shared CSS declarations applied to every badge. */
	private const BADGE_STYLE = 'color:#333;padding:3px 10px;border-radius:12px;font-size:12px;font-weight:500;display:inline-block;';

	/**
	 * Render a badge `<span>` with the supplied attributes.
	 *
	 * - `$base_class`    base CSS class fragment (e.g. `ffc-recruitment-status-badge`).
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
		return sprintf(
			'<span class="%1$s %2$s"%3$s style="background:%4$s;%5$scursor:%6$s;">%7$s</span>',
			esc_attr( $base_class ),
			esc_attr( $variant_class ),
			$title,
			esc_attr( $bg ),
			self::BADGE_STYLE,
			esc_attr( $cursor ),
			esc_html( $label )
		);
	}
}
