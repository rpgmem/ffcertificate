<?php
/**
 * Template: Recruitment candidate edit — Classifications + call history
 * section (read-only summary table plus an inline calls table per
 * classification that has any calls).
 *
 * Extracted verbatim from
 * {@see \FreeFormCertificate\Recruitment\RecruitmentCandidateEditPage::render_classifications_section()}.
 * Markup is byte-identical to the pre-extraction inline body; the renderer
 * runs the LOGIC pass (prepare_classifications_section_data()) and hands the
 * per-row cells that need a DB read (`$notice_labels`, `$adjutancy_cells`)
 * as already-escaped values keyed by classification id.
 *
 * Variables in scope (provided by the including method):
 *
 * @var array<int, object>       $classifications Classification rows.
 * @var array<int, list<object>> $calls_by_class  Calls grouped by classification id.
 * @var array<int, string>       $notice_labels   Notice label per classification id (pre-esc source).
 * @var array<int, string>       $adjutancy_cells Already-escaped Adjutancy cell HTML per classification id.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.16.0
 */

use FreeFormCertificate\Core\DateFormatter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables scoped to this file (aliased by the including renderer method).

echo '<div class="postbox ffc-rec-mt-20">';
echo '<h2 class="hndle"><span>' . esc_html__( 'Classifications + call history', 'ffcertificate' ) . '</span></h2>';
echo '<div class="inside">';

if ( empty( $classifications ) ) {
	echo '<p><em>' . esc_html__( '(no classifications)', 'ffcertificate' ) . '</em></p>';
} else {
	echo '<table class="widefat striped"><thead><tr>';
	echo '<th>' . esc_html__( 'Notice', 'ffcertificate' ) . '</th>';
	echo '<th>' . esc_html__( 'Adjutancy', 'ffcertificate' ) . '</th>';
	echo '<th>' . esc_html__( 'List', 'ffcertificate' ) . '</th>';
	echo '<th>' . esc_html__( 'Rank', 'ffcertificate' ) . '</th>';
	echo '<th>' . esc_html__( 'Score', 'ffcertificate' ) . '</th>';
	echo '<th>' . esc_html__( 'Status', 'ffcertificate' ) . '</th>';
	echo '<th>' . esc_html__( 'Calls', 'ffcertificate' ) . '</th>';
	echo '</tr></thead><tbody>';

	foreach ( $classifications as $c ) {
		$call_count = isset( $calls_by_class[ (int) $c->id ] ) ? count( $calls_by_class[ (int) $c->id ] ) : 0;

		echo '<tr>';
		echo '<td>' . esc_html( $notice_labels[ (int) $c->id ] ) . '</td>';
		echo '<td>' . $adjutancy_cells[ (int) $c->id ] . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_adjutancy_cell returns pre-escaped HTML.
		echo '<td>' . esc_html( (string) $c->list_type ) . '</td>';
		echo '<td>' . esc_html( (string) $c->rank ) . '</td>';
		echo '<td>' . esc_html( (string) $c->score ) . '</td>';
		echo '<td><span class="ffc-status-badge ffc-status-' . esc_attr( (string) $c->status ) . '">' . esc_html( (string) $c->status ) . '</span></td>';
		echo '<td>' . esc_html( (string) $call_count ) . '</td>';
		echo '</tr>';
	}
	echo '</tbody></table>';

	// Render an inline calls table per classification that has any calls.
	foreach ( $classifications as $c ) {
		$calls = $calls_by_class[ (int) $c->id ] ?? array();
		if ( empty( $calls ) ) {
			continue;
		}
		echo '<h4 class="ffc-rec-mt-1-5em">' . sprintf(
			/* translators: %d — classification id */
			esc_html__( 'Calls for classification #%d', 'ffcertificate' ),
			(int) $c->id
		) . '</h4>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Called at', 'ffcertificate' ) . '</th>';
		echo '<th>' . esc_html__( 'Date to assume', 'ffcertificate' ) . '</th>';
		echo '<th>' . esc_html__( 'Time', 'ffcertificate' ) . '</th>';
		echo '<th>' . esc_html__( 'Out of order', 'ffcertificate' ) . '</th>';
		echo '<th>' . esc_html__( 'Cancelled at', 'ffcertificate' ) . '</th>';
		echo '<th>' . esc_html__( 'Notes', 'ffcertificate' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $calls as $call ) {
			echo '<tr>';
			// `called_at` is unix UTC int since 6.6.0 (#249 sub-escopo c).
			echo '<td>' . esc_html( DateFormatter::format_datetime( (int) $call->called_at ) ) . '</td>';
			echo '<td>' . esc_html( (string) $call->date_to_assume ) . '</td>';
			echo '<td>' . esc_html( (string) $call->time_to_assume ) . '</td>';
			echo '<td>' . ( '1' === (string) $call->out_of_order ? esc_html__( 'Yes', 'ffcertificate' ) : '—' ) . '</td>';
			echo '<td>' . ( null === $call->cancelled_at ? '—' : esc_html( (string) $call->cancelled_at ) ) . '</td>';
			echo '<td>' . esc_html( null === $call->notes ? '' : (string) $call->notes ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}
}

echo '</div></div>';
