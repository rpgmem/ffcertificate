<?php
/**
 * Template: Public recruitment filter bar — name-search input.
 *
 * Extracted verbatim from
 * RecruitmentPublicShortcodeRenderer::render_name_search_input();
 * markup byte-identical. No wrapping <form> — the caller owns it.
 *
 * @var string $name_query Current `?q=` value.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.16.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables scoped to this file (aliased by the including renderer method).

// Inline magnifying-glass glyph — keeps the asset count flat and
// avoids a font dependency. `aria-hidden` so screen readers fall
// through to the button label.
$icon = '<svg class="ffc-recruitment-search-btn-icon" viewBox="0 0 16 16" width="14" height="14" aria-hidden="true" focusable="false">'
	. '<path fill="currentColor" d="M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001q.044.06.098.115l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85a1 1 0 0 0-.115-.1zM12 6.5a5.5 5.5 0 1 1-11 0 5.5 5.5 0 0 1 11 0z"/>'
	. '</svg>';

echo '<label class="ffc-recruitment-name-search">';
echo esc_html__( 'Search by name:', 'ffcertificate' ) . ' ';
echo '<input type="search" name="q" value="' . esc_attr( $name_query ) . '" placeholder="' . esc_attr__( 'name…', 'ffcertificate' ) . '">';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- icon is a constant SVG string with no dynamic content.
echo ' <button type="submit" class="ffc-recruitment-search-btn">' . $icon . '<span>' . esc_html__( 'Search', 'ffcertificate' ) . '</span></button>';
echo '</label>';
