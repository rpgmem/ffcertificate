<?php
/**
 * Recruitment Badge HTML helper.
 *
 * Single source of truth for the inline-styled "tag" badges used
 * across the recruitment module. The admin and public surfaces ship
 * roughly seven near-identical render helpers (status, preview
 * status, subscription type, adjutancy, notice status, plus admin
 * mirrors); they all emit the same `<span class=… style=…>` shape
 * with one of the configured `*_color_*` settings as background.
 *
 * Centralizing the markup here keeps the visual treatment consistent
 * and lets future changes (e.g. dark-mode-aware text color, padding
 * tweaks, accessibility attributes) flow through every badge in one
 * edit instead of seven.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.1.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Recruitment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stateless helper that emits an inline-styled badge span.
 *
 * Visual treatment (padding / radius / font-size / display) is
 * captured in {@see self::BADGE_STYLE}; only background, label, and
 * optional title/cursor vary per call.
 */
final class RecruitmentBadgeHtml {

	/** Shared CSS declarations applied to every badge. */
	private const BADGE_STYLE = 'color:#333;padding:3px 10px;border-radius:12px;font-size:12px;font-weight:500;display:inline-block;';

	/**
	 * Render a badge `<span>` with the supplied attributes.
	 *
	 * Caller picks:
	 * - `$class_suffix` — namespaced CSS class fragment (we always
	 *   prepend `ffc-recruitment-` and a base class so consumers can
	 *   target `.ffc-recruitment-status-badge`,
	 *   `.ffc-recruitment-preview-status-badge`, etc.).
	 * - `$variant_class` — the value-specific class fragment
	 *   (e.g. `ffc-recruitment-status-empty`).
	 * - `$bg` — pre-validated hex color already passing
	 *   `RecruitmentSettings::sanitize_color()`.
	 * - `$label` — already-localized label; this method esc_html()s it.
	 * - `$tooltip` — optional `title=""` content (esc_attr()'d). When
	 *   non-empty, the cursor is also flipped to `help` so visitors get
	 *   a hover hint.
	 *
	 * @param string $base_class    Base CSS class (e.g. `ffc-recruitment-status-badge`).
	 * @param string $variant_class Variant CSS class (e.g. `ffc-recruitment-status-empty`).
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
