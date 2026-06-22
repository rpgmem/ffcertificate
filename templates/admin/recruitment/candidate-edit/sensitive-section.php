<?php
/**
 * Template: Recruitment candidate edit — Sensitive data section (CPF / RF
 * plus the linked-WP-user link/unlink controls).
 *
 * Extracted verbatim from
 * {@see \FreeFormCertificate\Recruitment\RecruitmentCandidateEditPage::render_sensitive_section()}
 * (rpgmem/ffcertificate#589 phase-2). Markup is byte-identical to the
 * pre-extraction inline body; the renderer prepares the locals below and
 * includes this file. The two PII cells (`$cpf_cell`, `$rf_cell`) are
 * precomputed by the including method (they wrap the private
 * render_sensitive_row() helper) and arrive as already-escaped HTML.
 *
 * Variables in scope (provided by the including method):
 *
 * @var object $candidate Candidate row.
 * @var string $tier      Resolved PII access tier (RecruitmentPiiAccessPolicy::TIER_*).
 * @var string $cpf_cell  Already-escaped CPF cell HTML.
 * @var string $rf_cell   Already-escaped RF cell HTML.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.7.7
 */

use FreeFormCertificate\Recruitment\RecruitmentPiiAccessPolicy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables scoped to this file (aliased by the including renderer method).

echo '<div class="postbox ffc-rec-mt-20">';
echo '<h2 class="hndle"><span>' . esc_html__( 'Sensitive data (admin only)', 'ffcertificate' ) . '</span></h2>';
echo '<div class="inside">';

if ( RecruitmentPiiAccessPolicy::TIER_REVEAL === $tier ) {
	echo '<p class="description">' . esc_html__( 'Sensitive fields are masked by default. Click "Reveal" to view; each reveal is recorded in the activity log.', 'ffcertificate' ) . '</p>';
}

echo '<table class="form-table"><tbody>';

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- render_sensitive_row returns escaped HTML built from esc_html() and known-safe markup.
echo '<tr><th>' . esc_html__( 'CPF', 'ffcertificate' ) . '</th>';
echo '<td>' . $cpf_cell . ' ';
echo '<span class="description">' . esc_html__( 'CSV import only — not editable here.', 'ffcertificate' ) . '</span></td></tr>';

echo '<tr><th>' . esc_html__( 'RF', 'ffcertificate' ) . '</th>';
echo '<td>' . $rf_cell . ' ';
echo '<span class="description">' . esc_html__( 'CSV import only — not editable here.', 'ffcertificate' ) . '</span></td></tr>';
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped

echo '<tr><th>' . esc_html__( 'Linked WP user', 'ffcertificate' ) . '</th>';
echo '<td>';
$user_id      = null === $candidate->user_id ? 0 : (int) $candidate->user_id;
$nonce_action = 'ffc_recruitment_link_candidate_user_' . (int) $candidate->id;
if ( $user_id > 0 ) {
	$user = get_userdata( $user_id );
	if ( false !== $user ) {
		echo '<a href="' . esc_url( get_edit_user_link( $user_id ) ) . '">' . esc_html( $user->user_login ) . '</a>';
	} else {
		echo '<code>#' . esc_html( (string) $user_id ) . '</code> <em>(' . esc_html__( 'orphaned reference', 'ffcertificate' ) . ')</em>';
	}
	// Unlink form — clears the user_id without touching the wp_user.
	$unlink_consequences = wp_json_encode(
		array(
			__( 'The candidate\'s user_id column is cleared.', 'ffcertificate' ),
			__( 'The WordPress user account itself is preserved untouched.', 'ffcertificate' ),
		)
	);
	echo ' <form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ffc-rec-inline-ml"'
		. ' data-ffc-confirm'
		. ' data-ffc-confirm-title="' . esc_attr__( 'Unlink WordPress user?', 'ffcertificate' ) . '"'
		. ' data-ffc-confirm-body="' . esc_attr__( 'Unlink this candidate from the WordPress user account.', 'ffcertificate' ) . '"'
		. ' data-ffc-confirm-consequences="' . esc_attr( (string) $unlink_consequences ) . '"'
		. ' data-ffc-confirm-cta="' . esc_attr__( 'Unlink', 'ffcertificate' ) . '"'
		. ' data-ffc-confirm-style="destructive">';
	echo '<input type="hidden" name="action" value="ffc_recruitment_unlink_candidate_user">';
	echo '<input type="hidden" name="candidate_id" value="' . esc_attr( (string) $candidate->id ) . '">';
	wp_nonce_field( $nonce_action );
	echo '<button type="submit" class="button button-link-delete">' . esc_html__( 'Unlink', 'ffcertificate' ) . '</button>';
	echo '</form>';
} else {
	echo '<em>' . esc_html__( '(not promoted yet)', 'ffcertificate' ) . '</em>';
}
echo '</td></tr>';

// Link form — operator picks any wp_user by ID/login/email and the
// candidate's user_id is set to it. Same admin-post handler routes
// both link + relink (no separate "force" mode — the operator
// already saw the current state in the row above).
echo '<tr><th>' . esc_html__( 'Link manually to WP user', 'ffcertificate' ) . '</th>';
echo '<td>';
echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ffc-rec-inline">';
echo '<input type="hidden" name="action" value="ffc_recruitment_link_candidate_user">';
echo '<input type="hidden" name="candidate_id" value="' . esc_attr( (string) $candidate->id ) . '">';
wp_nonce_field( $nonce_action );
echo '<input type="text" name="user_lookup" placeholder="' . esc_attr__( 'WP user ID, login, or email', 'ffcertificate' ) . '" class="regular-text" required> ';
echo '<button type="submit" class="button button-secondary">' . esc_html__( 'Link', 'ffcertificate' ) . '</button>';
echo '<p class="description">' . esc_html__( 'Resolved via WP_User lookup (numeric → ID, contains @ → email, otherwise login). Does NOT create users; only links existing ones.', 'ffcertificate' ) . '</p>';
echo '</form>';
echo '</td></tr>';

echo '</tbody></table>';

// PII reveal/hide is handled by the enqueued
// assets/js/ffc-recruitment-candidate-edit.js (a document-delegated
// handler that's inert unless the reveal-tier buttons are present).

echo '</div></div>';
