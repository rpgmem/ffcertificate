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
 * @var bool   $has_definitive Whether a definitive classification list already exists.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.15.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables scoped to this file (aliased by the including renderer method).

echo '<h3>' . esc_html__( 'Promote to definitive', 'ffcertificate' ) . '</h3>';

if ( $has_definitive ) {
	// Definitive list already exists — single-button path.
	echo '<p>' . esc_html__( 'A definitive list already exists for this notice. Promoting just flips the status to `definitive` (the existing definitive list is preserved).', 'ffcertificate' ) . '</p>';

	$promote_consequences = wp_json_encode(
		array(
			__( 'The notice transitions from `preliminary` to `definitive`.', 'ffcertificate' ),
			__( 'The existing definitive list is preserved as-is.', 'ffcertificate' ),
			__( 'Calls can be issued from this point on; going back is only possible if no call has been issued.', 'ffcertificate' ),
		)
	);
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"'
		. ' data-ffc-confirm'
		. ' data-ffc-confirm-title="' . esc_attr__( 'Promote to definitive?', 'ffcertificate' ) . '"'
		. ' data-ffc-confirm-body="' . esc_attr__( 'You are about to flip the notice status to `definitive`.', 'ffcertificate' ) . '"'
		. ' data-ffc-confirm-consequences="' . esc_attr( (string) $promote_consequences ) . '"'
		. ' data-ffc-confirm-cta="' . esc_attr__( 'Promote to definitive', 'ffcertificate' ) . '"'
		. ' data-ffc-confirm-style="primary"'
		. ' data-ffc-confirm-countdown="15">';
	echo '<input type="hidden" name="action" value="ffc_recruitment_transition_notice">';
	echo '<input type="hidden" name="notice_id" value="' . esc_attr( (string) $id ) . '">';
	echo '<input type="hidden" name="target_status" value="definitive">';
	wp_nonce_field( $nonce_action );
	submit_button( __( 'Promote to definitive', 'ffcertificate' ), 'primary', '', false );
	echo '</form>';

	echo '<hr class="ffc-rec-my-15">';
} else {
	echo '<p>' . esc_html__( 'No definitive list exists yet. Choose how to populate it:', 'ffcertificate' ) . '</p>';

	// Path A — snapshot. Hits POST /notices/{id}/promote-preview
	// mode=snapshot via fetch (the endpoint does the copy +
	// status flip atomically). Confirmation goes through the shared
	// confirm-modal in ffc-recruitment-admin.js — we put the data
	// attributes on the button itself; the modal intercepts the
	// click and on confirm sets data-ffc-confirm-ok=1 so the
	// existing handler proceeds.
	$snapshot_consequences = wp_json_encode(
		array(
			__( 'The current preliminary list is copied verbatim into the definitive list.', 'ffcertificate' ),
			__( 'The notice transitions from `preliminary` to `definitive`.', 'ffcertificate' ),
			__( 'Calls can be issued from this point on; going back is only possible if no call has been issued.', 'ffcertificate' ),
		)
	);
	echo '<p>';
	echo '<button type="button" class="button button-primary"'
		. ' data-ffc-confirm'
		. ' data-ffc-confirm-title="' . esc_attr__( 'Promote (snapshot) to definitive?', 'ffcertificate' ) . '"'
		. ' data-ffc-confirm-body="' . esc_attr__( 'You are about to snapshot the preliminary list as the definitive list.', 'ffcertificate' ) . '"'
		. ' data-ffc-confirm-consequences="' . esc_attr( (string) $snapshot_consequences ) . '"'
		. ' data-ffc-confirm-cta="' . esc_attr__( 'Promote to definitive', 'ffcertificate' ) . '"'
		. ' data-ffc-confirm-style="primary"'
		. ' data-ffc-confirm-countdown="15"'
		. ' onclick="ffcRecruitmentSnapshotPromote(' . (int) $id . ', this);">'
		. esc_html__( 'A — Publish preliminary as definitive (snapshot, no changes)', 'ffcertificate' ) . '</button> ';
	echo '<button type="button" class="button button-secondary" onclick="document.getElementById(\'ffc-recruitment-edit-import\').scrollIntoView({behavior:\'smooth\'});">' . esc_html__( 'B — Import a new list as definitive', 'ffcertificate' ) . '</button>';
	echo '</p>';

	// The confirm flow (data-ffc-confirm) intercepts the click and
	// re-fires it with data-ffc-confirm-ok=1; ffcRecruitmentSnapshotPromote
	// (in ffc-recruitment-notice-edit.js) skips the fetch until that flag
	// is present so the first click only opens the modal.

	echo '<hr class="ffc-rec-my-15">';
}
