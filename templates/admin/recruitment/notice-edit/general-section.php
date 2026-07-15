<?php
/**
 * Template: Recruitment notice edit — General section (code + name +
 * public_columns_config).
 *
 * Extracted verbatim from
 * {@see \FreeFormCertificate\Recruitment\RecruitmentNoticeEditPageRenderer::render_general_section()}
 * (rpgmem/ffcertificate#589 phase-2). Markup is byte-identical to the
 * pre-extraction inline body; the renderer prepares the locals below and
 * includes this file.
 *
 * Variables in scope (provided by the including method):
 *
 * @var object $notice          Notice row.
 * @var string $nonce_action    Nonce action key for the save form.
 * @var string $columns_toggles Pre-rendered (already-escaped) column-toggles grid HTML.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.7.7
 */

use FreeFormCertificate\Recruitment\RecruitmentNoticeReader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables scoped to this file (aliased by the including renderer method).

echo '<div class="postbox ffc-rec-mt-20">';
echo '<h2 class="hndle"><span>' . esc_html__( 'General', 'ffcertificate' ) . '</span></h2>';
echo '<div class="inside">';

echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
echo '<input type="hidden" name="action" value="ffc_recruitment_save_notice">';
echo '<input type="hidden" name="notice_id" value="' . esc_attr( (string) $notice->id ) . '">';
wp_nonce_field( $nonce_action );

echo '<table class="form-table"><tbody>';

echo '<tr><th><label>' . esc_html__( 'Code', 'ffcertificate' ) . '</label></th>';
echo '<td><code>' . esc_html( (string) $notice->code ) . '</code> ';
// §3.2 — codes are stable identifiers; never editable post-creation.
echo '<span class="description">' . esc_html__( '(read-only — codes are stable identifiers and cannot be changed after creation)', 'ffcertificate' ) . '</span></td></tr>';

echo '<tr><th><label for="ffc-notice-name">' . esc_html__( 'Name', 'ffcertificate' ) . '</label></th>';
echo '<td><input id="ffc-notice-name" type="text" class="regular-text" name="name" value="' . esc_attr( (string) $notice->name ) . '" required></td></tr>';

echo '<tr><th>' . esc_html__( 'Public columns', 'ffcertificate' ) . '</th>';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_columns_toggles() returns already-escaped HTML.
echo '<td>' . $columns_toggles;
echo '<p class="description">' . esc_html__( 'Toggle which columns the public shortcode renders. Rank and Name are mandatory and cannot be turned off.', 'ffcertificate' ) . '</p></td></tr>';

// Dedicated row for the preliminary-reason public visibility
// toggle. Stored under the same `public_columns_config.preview_reason`
// key as the column grid so the save handler stays unchanged,
// but rendered separately because it isn't a column — it's a
// per-edital all-or-nothing toggle for whether the preliminary
// reason text shows up next to the badge on the public listing.
$decoded         = json_decode( (string) $notice->public_columns_config, true );
$decoded         = is_array( $decoded ) ? $decoded : array();
$preview_default = (array) json_decode( RecruitmentNoticeReader::DEFAULT_PUBLIC_COLUMNS_CONFIG, true );
$preview_state   = array_merge( $preview_default, $decoded );
$preview_checked = ! empty( $preview_state['preview_reason'] );

echo '<tr><th>' . esc_html__( 'Preliminary reasons', 'ffcertificate' ) . '</th><td>';
\FreeFormCertificate\Admin\AdminUI::render_toggle(
	array(
		'name'    => 'public_columns[preview_reason]',
		'id'      => 'ffc-notice-pcc-preview_reason',
		'checked' => $preview_checked,
		'label'   => __( 'Show preliminary reasons publicly on this notice', 'ffcertificate' ),
	)
);
echo '<p class="description">' . esc_html__( 'When on, the public shortcode will render the reason label next to the preliminary status badge. Off by default per notice — operators decide all-or-nothing per edital.', 'ffcertificate' ) . '</p>';
echo '</td></tr>';

echo '</tbody></table>';
submit_button( __( 'Save general', 'ffcertificate' ) );
echo '</form>';

echo '</div></div>';
