<?php
/**
 * Template: Recruitment candidate edit — Hard-delete section (gated per
 * §7-bis).
 *
 * Extracted verbatim from
 * {@see \FreeFormCertificate\Recruitment\RecruitmentCandidateEditPage::render_delete_section()}
 * (rpgmem/ffcertificate#589 phase-2). Markup is byte-identical to the
 * pre-extraction inline body; the renderer prepares the locals below and
 * includes this file. The delete-consequences list (which wraps the
 * private build_delete_consequences() helper) is precomputed by the
 * including method and arrives as a `list<string>` ready for wp_json_encode.
 *
 * Variables in scope (provided by the including method):
 *
 * @var object        $candidate            Candidate row.
 * @var int           $id                   Candidate id.
 * @var string        $nonce_action         Nonce action key for the delete form.
 * @var int           $classification_count Classifications referencing the candidate.
 * @var array<string> $delete_consequences  Consequence bullets for the confirm modal.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.7.7
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables scoped to this file (aliased by the including renderer method).

echo '<div class="postbox ffc-rec-mt-20">';
echo '<h2 class="hndle"><span>' . esc_html__( 'Hard-delete candidate', 'ffcertificate' ) . '</span></h2>';
echo '<div class="inside">';

if ( $classification_count > 0 ) {
	echo '<p>' . sprintf(
		/* translators: %d — count of classifications referencing the candidate */
		esc_html__( 'Blocked: this candidate is referenced by %d classification(s). Delete those first (or leave them — historical records survive).', 'ffcertificate' ),
		(int) $classification_count
	) . '</p>';
	echo '</div></div>';
	return;
}

echo '<p>' . esc_html__( 'Removes the candidate row permanently. The linked WordPress user (if any) is preserved untouched. ActivityLog entries are kept (with sensitive payloads already redacted).', 'ffcertificate' ) . '</p>';

// Issue #331 asked for `last_classification_at` / `last_call_at`
// in the dialog. With the current §7-bis gate (`classification_count
// === 0`) those are always null by construction — the candidate
// can't be deleted while any classification still references it,
// and calls hang off classifications. So we surface the timestamps
// that ARE always meaningful (and that the operator actually wants
// before a destructive action): when the row was created and when
// it was last touched. Plus the WP user link, if any — the modal
// already says "preserved untouched" but seeing the actual login
// helps the operator confirm they're looking at the right row.
$hard_delete_consequences = wp_json_encode(
	$delete_consequences
);
echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"'
	. ' data-ffc-confirm'
	. ' data-ffc-confirm-title="' . esc_attr__( 'Hard-delete this candidate?', 'ffcertificate' ) . '"'
	. ' data-ffc-confirm-body="' . esc_attr__( 'You are about to permanently delete this candidate record.', 'ffcertificate' ) . '"'
	. ' data-ffc-confirm-consequences="' . esc_attr( (string) $hard_delete_consequences ) . '"'
	. ' data-ffc-confirm-cta="' . esc_attr__( 'Delete permanently', 'ffcertificate' ) . '"'
	. ' data-ffc-confirm-style="destructive"'
	. ' data-ffc-confirm-reason-label="' . esc_attr__( 'Reason (logged):', 'ffcertificate' ) . '">';
echo '<input type="hidden" name="action" value="ffc_recruitment_delete_candidate">';
echo '<input type="hidden" name="candidate_id" value="' . esc_attr( (string) $id ) . '">';
wp_nonce_field( $nonce_action );
// No-JS fallback: a plain reason input that submits with the form
// when the modal interceptor (assets/js/ffc-recruitment-admin.js)
// hasn't loaded. When JS is on, the modal injects its own hidden
// `reason` input after this one — the appended value wins in PHP's
// `$_POST` parsing, so the operator's modal-typed reason takes
// precedence and the inline field becomes a no-JS safety net.
echo '<noscript><p>'
	. '<label for="ffc-cand-delete-reason">' . esc_html__( 'Reason (logged):', 'ffcertificate' ) . '</label><br>'
	. '<input id="ffc-cand-delete-reason" type="text" class="large-text" name="reason" required>'
	. '</p></noscript>';
submit_button( __( 'Delete permanently', 'ffcertificate' ), 'delete' );
echo '</form>';

echo '</div></div>';
