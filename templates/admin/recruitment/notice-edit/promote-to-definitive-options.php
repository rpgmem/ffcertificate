<?php
/**
 * Template: Recruitment notice edit — "Promote to definitive" options
 * (the preliminary → definitive dual-path UI).
 *
 * Extracted verbatim from
 * {@see \FreeFormCertificate\Recruitment\RecruitmentNoticeEditPageRenderer::render_preliminary_to_final_options()}.
 * Markup is byte-identical to the pre-extraction inline body; the renderer
 * prepares the locals below and includes this file.
 *
 * Variables in scope (provided by the including method):
 *
 * @var int    $id             Notice id.
 * @var string $nonce_action   Nonce key shared with handle_transition.
 * @var int    $definitive_count Rows of a definitive list left by an earlier cycle (0 when none).
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.15.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo '<h3>' . esc_html__( 'Promote to definitive', 'ffcertificate' ) . '</h3>';

// A definitive list left by an earlier cycle is never promoted as it is
// (#2245): both paths replace it, so the definitive list is always drawn
// from the current preliminary one or from a new file.
if ( $definitive_count > 0 ) {
	echo '<div class="notice notice-warning inline"><p>' . esc_html(
		sprintf(
			/* translators: %s: number of rows */
			_n(
				'A definitive list from an earlier cycle exists (%s row). Either option below replaces it.',
				'A definitive list from an earlier cycle exists (%s rows). Either option below replaces it.',
				$definitive_count,
				'ffcertificate'
			),
			number_format_i18n( $definitive_count )
		)
	) . '</p></div>';
} else {
	echo '<p>' . esc_html__( 'No definitive list exists yet. Choose how to populate it:', 'ffcertificate' ) . '</p>';
}

// Path A — copy. Hits POST /notices/{id}/promote-preview mode=snapshot via
// fetch (the endpoint does the copy + status flip atomically). Confirmation
// goes through the shared confirm-modal in ffc-recruitment-admin.js — the
// data attributes sit on the button itself; the modal intercepts the click
// and on confirm sets data-ffc-confirm-ok=1 so the existing handler proceeds.
$snapshot_lines = array(
	__( 'The current preliminary list is copied verbatim into the definitive list.', 'ffcertificate' ),
);
if ( $definitive_count > 0 ) {
	$snapshot_lines[] = __( 'The definitive list from the earlier cycle is replaced.', 'ffcertificate' );
}
$snapshot_lines[]      = __( 'The notice transitions from `preliminary` to `definitive`.', 'ffcertificate' );
$snapshot_lines[]      = __( 'Calls can be issued from this point on; going back is only possible if no call has been issued.', 'ffcertificate' );
$snapshot_consequences = wp_json_encode( $snapshot_lines );
echo '<p>';
// By hand with the family's classes: `Core\Button` refuses the inline handler (#1904).
echo '<button type="button" class="ffc-btn ffc-btn-primary ffc-icon-promote"'
	. ' data-ffc-confirm'
	. ' data-ffc-confirm-title="' . esc_attr__( 'Copy the preliminary list as definitive?', 'ffcertificate' ) . '"'
	. ' data-ffc-confirm-body="' . esc_attr__( 'You are about to copy the preliminary list as the definitive list.', 'ffcertificate' ) . '"'
	. ' data-ffc-confirm-consequences="' . esc_attr( (string) $snapshot_consequences ) . '"'
	. ' data-ffc-confirm-cta="' . esc_attr__( 'Promote to definitive', 'ffcertificate' ) . '"'
	. ' data-ffc-confirm-style="primary"'
	. ' data-ffc-confirm-countdown="15"'
	. ' onclick="ffcRecruitmentSnapshotPromote(' . (int) $id . ', this);">'
	. esc_html__( 'A — Copy preliminary as definitive', 'ffcertificate' ) . '</button> ';
// A link to the import form below, so it needs no script (#1904).
\FreeFormCertificate\Core\Button::render(
	array(
		'label' => __( 'B — Import a new list as definitive', 'ffcertificate' ),
		'icon'  => 'upload',
		'href'  => '#ffc-recruitment-edit-import',
	)
);
echo '</p>';

// The confirm flow (data-ffc-confirm) intercepts the click and re-fires it
// with data-ffc-confirm-ok=1; ffcRecruitmentSnapshotPromote (in
// ffc-recruitment-notice-edit.js) skips the fetch until that flag is present
// so the first click only opens the modal.

echo '<hr class="ffc-rec-my-15">';
