<?php
/**
 * Admin Dashboard
 *
 * The pieces a module dashboard is drawn with (#2486): a section card with
 * what the section holds and the buttons that open it, and a list of
 * label → number pairs. The layout itself is `.ffc-dashboard` in
 * ffc-admin-components.css; the stat cards are `AdminUI::get_stat_card()`.
 *
 * @package FreeFormCertificate\Core
 * @since   6.44.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the shared parts of a module dashboard.
 */
final class AdminDashboard {

	/**
	 * A section card: the card heading with its icon, what the section
	 * holds, and the buttons that open it.
	 *
	 * @param string                    $icon    `ffc-icon-*` class of the heading.
	 * @param string                    $title   Section name.
	 * @param string                    $body    Body markup, already escaped.
	 * @param list<array<string,mixed>> $buttons `Button::html()` arguments.
	 * @return void
	 */
	public static function section_card( string $icon, string $title, string $body, array $buttons ): void {
		printf( '<div class="card ffc-dashboard__card"><h2 class="%1$s">%2$s</h2>', esc_attr( $icon ), esc_html( $title ) );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the caller.
		echo '<div class="ffc-dashboard__actions">';
		foreach ( $buttons as $button ) {
			Button::render( $button );
		}
		echo '</div></div>';
	}

	/**
	 * A list of label → number pairs.
	 *
	 * @param array<string, int|string> $rows Label => value.
	 * @return string Escaped markup.
	 */
	public static function figures( array $rows ): string {
		$html = '<ul class="ffc-dashboard__figures">';
		foreach ( $rows as $label => $value ) {
			$html .= sprintf(
				'<li><span>%1$s</span><span>%2$s</span></li>',
				esc_html( (string) $label ),
				esc_html( is_int( $value ) ? number_format_i18n( $value ) : $value )
			);
		}
		return $html . '</ul>';
	}
}
