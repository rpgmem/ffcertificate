<?php
/**
 * Template: Recruitment candidate edit — General section (name, email,
 * phone, notes).
 *
 * Extracted verbatim from
 * {@see \FreeFormCertificate\Recruitment\RecruitmentCandidateEditPage::render_general_section()}
 * (rpgmem/ffcertificate#589 phase-2). Markup is byte-identical to the
 * pre-extraction inline body; the renderer prepares the locals below and
 * includes this file.
 *
 * Variables in scope (provided by the including method):
 *
 * @var object $candidate    Candidate row.
 * @var int    $id           Candidate id.
 * @var string $nonce_action Nonce action key for the save form.
 * @var string $email        Decrypted email for the editable field.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.7.7
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables scoped to this file (aliased by the including renderer method).

echo '<div class="postbox ffc-rec-mt-20">';
echo '<h2 class="hndle"><span>' . esc_html__( 'General', 'ffcertificate' ) . '</span></h2>';
echo '<div class="inside">';

echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
echo '<input type="hidden" name="action" value="ffc_recruitment_save_candidate">';
echo '<input type="hidden" name="candidate_id" value="' . esc_attr( (string) $id ) . '">';
wp_nonce_field( $nonce_action );

echo '<table class="form-table"><tbody>';

echo '<tr><th><label for="ffc-cand-name">' . esc_html__( 'Name', 'ffcertificate' ) . '</label></th>';
echo '<td><input id="ffc-cand-name" type="text" class="regular-text" name="name" value="' . esc_attr( (string) $candidate->name ) . '" required></td></tr>';

echo '<tr><th><label for="ffc-cand-email">' . esc_html__( 'Email', 'ffcertificate' ) . '</label></th>';
echo '<td><input id="ffc-cand-email" type="email" class="regular-text" name="email" value="' . esc_attr( $email ) . '">';
// §4 trigger 3 — internal reference, not surfaced to operators.
echo '<p class="description">' . esc_html__( 'Setting / changing the email re-runs the user promotion path: an existing WP user matched by email gets linked here, otherwise a new WP user is created.', 'ffcertificate' ) . '</p>';
echo '</td></tr>';

echo '<tr><th><label for="ffc-cand-phone">' . esc_html__( 'Phone', 'ffcertificate' ) . '</label></th>';
echo '<td><input id="ffc-cand-phone" type="text" class="regular-text" name="phone" value="' . esc_attr( null === $candidate->phone ? '' : (string) $candidate->phone ) . '"></td></tr>';

echo '<tr><th><label for="ffc-cand-notes">' . esc_html__( 'Notes', 'ffcertificate' ) . '</label></th>';
echo '<td><textarea id="ffc-cand-notes" name="notes" rows="4" class="large-text">' . esc_textarea( null === $candidate->notes ? '' : (string) $candidate->notes ) . '</textarea></td></tr>';

echo '</tbody></table>';
submit_button( __( 'Save general', 'ffcertificate' ) );
echo '</form>';

echo '</div></div>';
