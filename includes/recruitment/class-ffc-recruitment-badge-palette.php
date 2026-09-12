<?php
/**
 * Operator-chosen badge colours, emitted as CSS instead of inline attributes.
 *
 * The recruitment badges are painted with hex values an administrator picks on
 * the Recruitment Settings screen — 16 keys across four families, plus the
 * per-row `color` column of each adjutancy. No static stylesheet can know
 * them, which is why they used to travel in each `<span>`'s `style`.
 *
 * That is not the only way to get data into CSS. `wp_add_inline_style()` is
 * WordPress's own answer for a stylesheet that depends on an option: the rules
 * are generated once per page, appended to the sheet they belong to, and the
 * element goes back to carrying nothing but classes.
 *
 * Why whole rules rather than a block of custom properties: a custom property
 * that does not arrive invalidates the **entire** declaration reading it, so
 * the element renders with no colour at all (#1126 defeito 2) — a failure mode
 * that needs a fallback on every `var()` and is invisible until it happens.
 * Generated rules have no such edge. The one place a custom property is still
 * the right tool is the adjutancy badge, whose colour is per database row and
 * therefore per element; there the rule stays in the stylesheet and only the
 * value rides the attribute.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.24.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Recruitment;

use FreeFormCertificate\Core\ColorValidator;
use FreeFormCertificate\Core\ContrastColor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the `<style>` block that colours every recruitment badge.
 */
final class RecruitmentBadgePalette {

	/**
	 * Variant CSS class => the `RecruitmentSettings` key holding its colour.
	 *
	 * The notice statuses and the classification statuses share one class
	 * prefix (`ffc-recruitment-status-`) because both go through
	 * {@see BadgeHtml} with that family — their value sets are disjoint, so
	 * one map covers both. `accepted` deliberately reads the same key as
	 * `called`: the public surface collapses the two and the admin only
	 * distinguishes them by label.
	 *
	 * @var array<string, string>
	 */
	private const VARIANTS = array(
		// Notice status (admin notices list + the public status banner).
		'ffc-recruitment-status-draft'                  => 'notice_status_color_draft',
		'ffc-recruitment-status-preliminary'            => 'notice_status_color_preliminary',
		'ffc-recruitment-status-definitive'             => 'notice_status_color_definitive',
		'ffc-recruitment-status-closed'                 => 'notice_status_color_closed',
		// Classification status (admin candidate edit + the public listing).
		'ffc-recruitment-status-empty'                  => 'status_color_empty',
		'ffc-recruitment-status-called'                 => 'status_color_called',
		'ffc-recruitment-status-accepted'               => 'status_color_called',
		'ffc-recruitment-status-hired'                  => 'status_color_hired',
		'ffc-recruitment-status-not_shown'              => 'status_color_not_shown',
		'ffc-recruitment-status-withdrew'               => 'status_color_withdrew',
		// Preview list.
		'ffc-recruitment-preview-status-empty'          => 'preview_color_empty',
		'ffc-recruitment-preview-status-denied'         => 'preview_color_denied',
		'ffc-recruitment-preview-status-granted'        => 'preview_color_granted',
		'ffc-recruitment-preview-status-appeal_denied'  => 'preview_color_appeal_denied',
		'ffc-recruitment-preview-status-appeal_granted' => 'preview_color_appeal_granted',
		// Subscription type.
		'ffc-recruitment-subscription-pcd'              => 'subscription_color_pcd',
		'ffc-recruitment-subscription-geral'            => 'subscription_color_geral',
	);

	/**
	 * The family classes, which carry the neutral floor.
	 *
	 * @var array<int, string>
	 */
	private const FAMILIES = array(
		'.ffc-recruitment-status-badge',
		'.ffc-recruitment-preview-status-badge',
		'.ffc-recruitment-subscription-badge',
	);

	/**
	 * Fallback background for a key holding something that is not a colour.
	 *
	 * Mirrors the literal the badge helpers used when a status had no entry.
	 */
	private const FALLBACK_BG = '#e9ecef';

	/**
	 * Build the CSS block.
	 *
	 * Each variant becomes one rule declaring the pair: the operator's
	 * background and the foreground {@see ContrastColor::on()} computes from
	 * it. The two are emitted together on purpose — a rule that paints a
	 * ground without painting its text is the #1126 defeito 4 class.
	 *
	 * @return string CSS, without a `<style>` wrapper.
	 */
	public static function css(): string {
		$settings = RecruitmentSettings::all();

		// A neutral pair for the family, first, so a status with no rule of its
		// own still renders as a badge. The helpers used to do this with a
		// `?? '#e9ecef'` on the colour lookup; with the colour gone from the
		// markup the floor has to live here. It is the same neutral the palette
		// already declares, so it follows the dark theme like everything else.
		$rules = array(
			sprintf(
				'%s{background:var(--ffc-badge-row-bg);color:var(--ffc-badge-row-text)}',
				implode( ',', self::FAMILIES )
			),
		);

		foreach ( self::VARIANTS as $class => $key ) {
			$raw = isset( $settings[ $key ] ) && is_string( $settings[ $key ] ) ? $settings[ $key ] : '';
			$bg  = ColorValidator::normalize( $raw, self::FALLBACK_BG );

			$rules[] = sprintf(
				'.%1$s{background:%2$s;color:%3$s}',
				$class,
				$bg,
				ContrastColor::on( $bg )
			);
		}

		return implode( "\n", $rules );
	}

	/**
	 * Append the block to an already-registered stylesheet handle.
	 *
	 * Inline styles are printed **after** their handle's own file, so these
	 * rules outrank whatever the module sheet declares for the same selector —
	 * which is what makes the operator's choice win without any specificity
	 * trick.
	 *
	 * @param string $handle A handle already passed to `wp_enqueue_style()`.
	 * @return void
	 */
	public static function attach( string $handle ): void {
		wp_add_inline_style( $handle, self::css() );
	}
}
