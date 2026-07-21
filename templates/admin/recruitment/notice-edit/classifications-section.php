<?php
/**
 * Template: Recruitment notice edit — Classifications section (preliminary
 * + definitive tabs).
 *
 * Extracted verbatim from
 * {@see \FreeFormCertificate\Recruitment\RecruitmentNoticeEditPageRenderer::render_classifications_section()}.
 * Markup is byte-identical to the pre-extraction inline body; the renderer
 * runs the data-prep pass, pre-renders the private sub-blocks, and includes
 * this file.
 *
 * Variables in scope (provided by the including method):
 *
 * @var int    $notice_id              Notice id.
 * @var array  $def_empties_by_adj     Definitive empties grouped by adjutancy (JSON-encoded into the panel).
 * @var string $active_tab             Active tab key ('preliminary' | 'definitive').
 * @var string $filters_form_html      Pre-rendered (escaped) filter form HTML.
 * @var string $preliminary_table_html Pre-rendered (escaped) preliminary classifications table HTML.
 * @var string $definitive_table_html  Pre-rendered (escaped) definitive classifications table HTML.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.15.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables scoped to this file (aliased by the including renderer method).

echo '<div class="postbox ffc-rec-mt-20">';
echo '<h2 class="hndle"><span>' . esc_html__( 'Classifications', 'ffcertificate' ) . '</span></h2>';
echo '<div class="inside">';

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-rendered, already-escaped HTML.
echo $filters_form_html;

// Tabs.
$prev_is_active = 'preliminary' === $active_tab;
$prev_tab_class = $prev_is_active ? 'nav-tab nav-tab-active' : 'nav-tab';
$def_tab_class  = $prev_is_active ? 'nav-tab' : 'nav-tab nav-tab-active';
$prev_display   = $prev_is_active ? 'block' : 'none';
$def_display    = $prev_is_active ? 'none' : 'block';

echo '<h2 class="nav-tab-wrapper ffc-rec-mb-1">';
echo '<a href="#" class="' . esc_attr( $prev_tab_class ) . '" data-ffc-clstab="preliminary" onclick="return ffcRecruitmentClsTabSwitch(this);">' . esc_html__( 'Preliminary', 'ffcertificate' ) . '</a>';
echo '<a href="#" class="' . esc_attr( $def_tab_class ) . '" data-ffc-clstab="definitive" onclick="return ffcRecruitmentClsTabSwitch(this);">' . esc_html__( 'Definitive', 'ffcertificate' ) . '</a>';
echo '</h2>';

echo '<div data-ffc-clspanel="preliminary" style="display:' . esc_attr( $prev_display ) . ';">';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-rendered, already-escaped HTML.
echo $preliminary_table_html;
echo '</div>';

echo '<div data-ffc-clspanel="definitive" data-ffc-empties="' . esc_attr( (string) wp_json_encode( $def_empties_by_adj ) ) . '" style="display:' . esc_attr( $def_display ) . ';">';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-rendered, already-escaped HTML.
echo $definitive_table_html;
echo '</div>';

// The tab toggle handler (ffcRecruitmentClsTabSwitch) ships in
// ffc-recruitment-notice-edit.js: it swaps .nav-tab-active, shows the
// matching [data-ffc-clspanel], and writes ffc_cls_tab into the URL so
// a later pagination click preserves the operator's chosen tab.

echo '</div></div>';
