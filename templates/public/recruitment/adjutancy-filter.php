<?php
/**
 * Template: Public recruitment filter bar — adjutancy <select>.
 *
 * Extracted verbatim from
 * RecruitmentPublicShortcodeRenderer::render_adjutancy_filter_inputs();
 * markup byte-identical. The adjutancy list is fetched, filtered and sorted by
 * the caller; the currently-selected slug is resolved from `$_GET` there too.
 * No wrapping <form> — the caller owns it.
 *
 * @var array<int,object> $adjutancies Sorted adjutancy rows (name/slug).
 * @var string            $selected    Currently-selected adjutancy slug.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.16.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables scoped to this file (aliased by the including renderer method).

echo '<label class="ffc-recruitment-adjutancy-filter">';
echo esc_html__( 'Filter by adjutancy:', 'ffcertificate' ) . ' ';
echo '<select name="adjutancy" onchange="this.form.submit()">';
echo '<option value="">' . esc_html__( 'All', 'ffcertificate' ) . '</option>';
foreach ( $adjutancies as $a ) {
	$is_selected = (string) $a->slug === $selected ? ' selected' : '';
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $is_selected is a constant ' selected' / '' flag; slug and name are escaped above.
	echo '<option value="' . esc_attr( (string) $a->slug ) . '"' . $is_selected . '>' . esc_html( (string) $a->name ) . '</option>';
}
echo '</select></label>';
