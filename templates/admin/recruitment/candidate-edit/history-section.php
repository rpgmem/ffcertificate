<?php
/**
 * Template: Recruitment candidate edit — History section (per-candidate
 * activity-log feed, issue #331). Rendered above the hard-delete postbox so
 * the operator can scan the audit trail before a destructive action.
 *
 * Extracted verbatim from
 * {@see \FreeFormCertificate\Recruitment\RecruitmentCandidateEditPage::render_history_section()}.
 * Markup is byte-identical to the pre-extraction inline body; the renderer
 * runs the LOGIC pass (prepare_history_rows()) and hands the resolved cells
 * — `when` / `who` (pre-esc sources) and the already-escaped `event` summary.
 *
 * Variables in scope (provided by the including method):
 *
 * @var array<int, array<string, mixed>>                        $entries      Raw history entries (only the empty-check is read here).
 * @var array<int, array{when: string, who: string, event: string}> $history_rows Resolved display cells.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.16.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo '<div class="postbox ffc-rec-mt-20">';
echo '<h2 class="hndle"><span>' . esc_html__( 'History', 'ffcertificate' ) . '</span></h2>';
echo '<div class="inside">';

if ( empty( $entries ) ) {
	echo '<p><em>' . esc_html__( '(no activity recorded for this candidate)', 'ffcertificate' ) . '</em></p>';
	echo '</div></div>';
	return;
}

echo '<p class="description">' . esc_html__( 'Most recent first. Pulled from the activity log for events referencing this candidate or any of its classifications.', 'ffcertificate' ) . '</p>';
echo '<table class="widefat striped"><thead><tr>';
echo '<th>' . esc_html__( 'When', 'ffcertificate' ) . '</th>';
echo '<th>' . esc_html__( 'Who', 'ffcertificate' ) . '</th>';
echo '<th>' . esc_html__( 'Event', 'ffcertificate' ) . '</th>';
echo '</tr></thead><tbody>';
foreach ( $history_rows as $row ) {
	echo '<tr>';
	echo '<td>' . esc_html( $row['when'] ) . '</td>';
	echo '<td>' . esc_html( $row['who'] ) . '</td>';
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- summarize_event() returns pre-escaped HTML.
	echo '<td>' . $row['event'] . '</td>';
	echo '</tr>';
}
echo '</tbody></table>';

echo '</div></div>';
