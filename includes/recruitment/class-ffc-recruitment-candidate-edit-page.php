<?php
/**
 * Recruitment Candidate Edit Page.
 *
 * Dedicated wp-admin screen reached via the "Edit" row action on the
 * Candidates list table:
 *
 *   admin.php?page=ffc-recruitment&tab=candidates&action=edit-candidate&candidate_id=N
 *
 * Three sections:
 *
 *   1. General        — name, email, phone, notes (the §12 always-
 *                       editable set). Email change re-runs the
 *                       UserCreator promotion path via the existing
 *                       RecruitmentCandidateRepository::update flow.
 *   2. Sensitive data — CPF / RF / email decrypted in plain (cap-gated
 *                       per §10-bis admin surface column). CPF / RF
 *                       are read-only here since they're CSV-only per
 *                       §12; email is editable via the General form.
 *   3. Classifications + call history — every classification this
 *                       candidate has across notices + every call ever
 *                       issued (active or cancelled). Read-only.
 *
 * Hard-delete lives at the bottom, gated per §7-bis (DeleteService
 * rejects when classification count > 0). The form requires a reason
 * to discourage accidental deletes; the reason is logged via
 * ActivityLog (the candidate row goes away but the log entry stays).
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.1.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Recruitment;

use FreeFormCertificate\Core\DateFormatter;
use FreeFormCertificate\Core\DocumentFormatter;
use FreeFormCertificate\Core\Encryption;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Candidate edit screen renderer + admin-post handlers.
 *
 * @phpstan-import-type CandidateRow      from RecruitmentCandidateRepository
 * @phpstan-import-type ClassificationRow from RecruitmentClassificationRepository
 * @phpstan-import-type CallRow           from RecruitmentCallRepository
 */
final class RecruitmentCandidateEditPage {

	private const CAP = 'ffc_manage_recruitment';

	/**
	 * Hook the admin-post save + delete endpoints.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_post_ffc_recruitment_save_candidate', array( self::class, 'handle_save' ), 10 );
		add_action( 'admin_post_ffc_recruitment_delete_candidate', array( self::class, 'handle_delete' ), 10 );
		add_action( 'admin_post_ffc_recruitment_link_candidate_user', array( self::class, 'handle_link_user' ), 10 );
		add_action( 'admin_post_ffc_recruitment_unlink_candidate_user', array( self::class, 'handle_unlink_user' ), 10 );
	}

	/**
	 * Render the edit screen body. Called by RecruitmentAdminPage when
	 * `?action=edit-candidate` is detected on the page.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Access denied.', 'ffcertificate' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only render.
		$candidate_id = isset( $_GET['candidate_id'] ) ? absint( wp_unslash( (string) $_GET['candidate_id'] ) ) : 0;
		$candidate    = $candidate_id > 0 ? RecruitmentCandidateRepository::get_by_id( $candidate_id ) : null;
		if ( null === $candidate ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Candidate not found.', 'ffcertificate' ) . '</p></div>';
			echo '<p><a href="' . esc_url( self::back_url() ) . '">&larr; ' . esc_html__( 'Back to Candidates', 'ffcertificate' ) . '</a></p>';
			return;
		}

		echo '<p><a href="' . esc_url( self::back_url() ) . '">&larr; ' . esc_html__( 'Back to Candidates', 'ffcertificate' ) . '</a></p>';
		echo '<h2>' . sprintf(
			/* translators: %s — candidate name */
			esc_html__( 'Edit candidate — %s', 'ffcertificate' ),
			esc_html( (string) $candidate->name )
		) . '</h2>';

		self::render_general_section( $candidate );
		self::render_sensitive_section( $candidate );
		self::render_classifications_section( $candidate );
		self::render_history_section( $candidate );
		self::render_delete_section( $candidate );
	}

	/**
	 * Section 1: General editable fields per §12.
	 *
	 * @param object $candidate Candidate row.
	 * @phpstan-param CandidateRow $candidate
	 * @return void
	 */
	private static function render_general_section( object $candidate ): void {
		$id           = (int) $candidate->id;
		$nonce_action = 'ffc_recruitment_save_candidate_' . $id;

		$email = '';
		if ( null !== $candidate->email_encrypted && '' !== (string) $candidate->email_encrypted ) {
			$decrypted = Encryption::decrypt( (string) $candidate->email_encrypted );
			$email     = is_string( $decrypted ) ? $decrypted : '';
		}

		echo '<div class="postbox" style="margin-top:20px;">';
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
	}

	/**
	 * Section 2: Sensitive data displayed in plain to cap-holders.
	 *
	 * @param object $candidate Candidate row.
	 * @phpstan-param CandidateRow $candidate
	 * @return void
	 */
	private static function render_sensitive_section( object $candidate ): void {
		// Resolve the access tier once for the whole postbox so all three
		// fields (CPF, RF, email) get a consistent treatment per user
		// (issue #330).
		$tier = RecruitmentPiiAccessPolicy::resolve( $candidate, get_current_user_id() );

		echo '<div class="postbox" style="margin-top:20px;">';
		echo '<h2 class="hndle"><span>' . esc_html__( 'Sensitive data (admin only)', 'ffcertificate' ) . '</span></h2>';
		echo '<div class="inside">';

		if ( RecruitmentPiiAccessPolicy::TIER_REVEAL === $tier ) {
			echo '<p class="description">' . esc_html__( 'Sensitive fields are masked by default. Click "Reveal" to view; each reveal is recorded in the activity log.', 'ffcertificate' ) . '</p>';
		}

		echo '<table class="form-table"><tbody>';

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- render_sensitive_row returns escaped HTML built from esc_html() and known-safe markup.
		echo '<tr><th>' . esc_html__( 'CPF', 'ffcertificate' ) . '</th>';
		echo '<td>' . self::render_sensitive_row(
			$tier,
			(int) $candidate->id,
			'cpf',
			(string) ( $candidate->cpf_encrypted ?? '' ),
			array( DocumentFormatter::class, 'format_cpf' )
		) . ' ';
		echo '<span class="description">' . esc_html__( 'CSV import only — not editable here.', 'ffcertificate' ) . '</span></td></tr>';

		echo '<tr><th>' . esc_html__( 'RF', 'ffcertificate' ) . '</th>';
		echo '<td>' . self::render_sensitive_row(
			$tier,
			(int) $candidate->id,
			'rf',
			(string) ( $candidate->rf_encrypted ?? '' ),
			array( DocumentFormatter::class, 'format_rf' )
		) . ' ';
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
			echo ' <form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;margin-left:.5em;"'
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
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;">';
		echo '<input type="hidden" name="action" value="ffc_recruitment_link_candidate_user">';
		echo '<input type="hidden" name="candidate_id" value="' . esc_attr( (string) $candidate->id ) . '">';
		wp_nonce_field( $nonce_action );
		echo '<input type="text" name="user_lookup" placeholder="' . esc_attr__( 'WP user ID, login, or email', 'ffcertificate' ) . '" class="regular-text" required> ';
		echo '<button type="submit" class="button button-secondary">' . esc_html__( 'Link', 'ffcertificate' ) . '</button>';
		echo '<p class="description">' . esc_html__( 'Resolved via WP_User lookup (numeric → ID, contains @ → email, otherwise login). Does NOT create users; only links existing ones.', 'ffcertificate' ) . '</p>';
		echo '</form>';
		echo '</td></tr>';

		echo '</tbody></table>';

		// Inline JS for the reveal buttons. Only emitted when the user is
		// on the `reveal` tier — the `unmasked` and `masked` tiers don't
		// render the button so the script would be a no-op anyway.
		if ( RecruitmentPiiAccessPolicy::TIER_REVEAL === $tier ) {
			self::render_sensitive_reveal_script();
		}

		echo '</div></div>';
	}

	/**
	 * Decrypt and render a sensitive value, applying an optional formatter.
	 * Returns the em-dash placeholder for empty input.
	 *
	 * @param string   $cipher    Stored ciphertext.
	 * @param callable $formatter Static method `(string) => string`.
	 * @return string Rendered HTML (already escaped).
	 */
	private static function render_decrypted( string $cipher, callable $formatter ): string {
		if ( '' === $cipher ) {
			return '<em>—</em>';
		}
		$plain = Encryption::decrypt( $cipher );
		if ( ! is_string( $plain ) || '' === $plain ) {
			return '<em>—</em>';
		}
		return '<code>' . esc_html( $formatter( $plain ) ) . '</code>';
	}

	/**
	 * Render one row of the Sensitive data postbox under the access tier.
	 *
	 * - `unmasked`: decrypt and show the value immediately, same as
	 *   render_decrypted().
	 * - `reveal`: render a `<code>` placeholder with a "Reveal" button
	 *   alongside; the JS handler (see render_sensitive_reveal_script())
	 *   POSTs to /candidates/{id}/reveal-pii on click and swaps the
	 *   placeholder text in place.
	 * - `masked`: render the placeholder without the button — the user
	 *   has no path to see this value at all.
	 *
	 * @param string   $tier         One of RecruitmentPiiAccessPolicy::TIER_*.
	 * @param int      $candidate_id Candidate row ID (used by the reveal handler).
	 * @param string   $field_key    Logical key (`cpf`, `rf`, `email`).
	 * @param string   $cipher       Encrypted column value.
	 * @param callable $formatter    Formatter for the plaintext (used in unmasked tier).
	 * @return string Already-escaped HTML.
	 */
	private static function render_sensitive_row( string $tier, int $candidate_id, string $field_key, string $cipher, callable $formatter ): string {
		if ( '' === $cipher ) {
			return '<em>—</em>';
		}

		if ( RecruitmentPiiAccessPolicy::TIER_UNMASKED === $tier ) {
			return self::render_decrypted( $cipher, $formatter );
		}

		// reveal + masked both start with the placeholder. reveal adds a
		// button next to it (the JS swaps the placeholder text on
		// success); masked stops at the placeholder.
		$placeholder = '<code class="ffc-pii-placeholder" data-ffc-pii-field="' . esc_attr( $field_key ) . '">' . esc_html( self::mask_placeholder_for( $field_key ) ) . '</code>';

		if ( RecruitmentPiiAccessPolicy::TIER_REVEAL !== $tier ) {
			return $placeholder;
		}

		$button = ' <button type="button"'
			. ' class="button button-secondary ffc-pii-reveal-btn"'
			. ' data-ffc-pii-candidate="' . esc_attr( (string) $candidate_id ) . '"'
			. ' data-ffc-pii-field="' . esc_attr( $field_key ) . '">'
			. esc_html__( 'Reveal', 'ffcertificate' )
			. '</button>';

		return $placeholder . $button;
	}

	/**
	 * Per-field masked placeholder. Mirrors the shape of the real value
	 * (e.g. `***.***.***-**` for CPF) so the operator can tell which
	 * field is which before clicking.
	 *
	 * @param string $field_key One of `cpf`, `rf`, `email`.
	 * @return string
	 */
	private static function mask_placeholder_for( string $field_key ): string {
		switch ( $field_key ) {
			case 'cpf':
				return '***.***.***-**';
			case 'rf':
				return '****-*';
			case 'email':
				return '****@****';
			default:
				return '****';
		}
	}

	/**
	 * Inline JS for the "Reveal" buttons in the Sensitive data postbox
	 * (issue #330). Rendered once per page load by render_sensitive_section
	 * when the user is on the `reveal` tier.
	 *
	 * The handler POSTs to /candidates/{id}/reveal-pii with the field key,
	 * swaps the placeholder text on success, replaces the button with a
	 * "Hide" toggle that restores the placeholder, and surfaces any error
	 * inline so the operator knows whether the request failed (and why)
	 * without a page reload.
	 *
	 * @return void
	 */
	private static function render_sensitive_reveal_script(): void {
		$rest_root  = esc_url_raw( rest_url( 'ffcertificate/v1/recruitment/candidates/' ) );
		$nonce      = wp_create_nonce( 'wp_rest' );
		$hide_lbl   = esc_js( __( 'Hide', 'ffcertificate' ) );
		$reveal_lbl = esc_js( __( 'Reveal', 'ffcertificate' ) );
		$err_lbl    = esc_js( __( 'Error', 'ffcertificate' ) );

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- All interpolated values are escaped via esc_url_raw / esc_js / esc_attr above; no user-controlled content.
		echo '<script>'
			. '(function(){'
			. 'document.addEventListener("click",function(ev){'
			. 'var btn=ev.target;'
			. 'if(!btn||!btn.classList||!btn.classList.contains("ffc-pii-reveal-btn"))return;'
			. 'ev.preventDefault();'
			. 'var cid=btn.getAttribute("data-ffc-pii-candidate");'
			. 'var field=btn.getAttribute("data-ffc-pii-field");'
			. 'var ph=btn.parentNode.querySelector(\'.ffc-pii-placeholder[data-ffc-pii-field="\'+field+\'"]\');'
			. 'if(!cid||!field||!ph)return;'
			. 'if(btn.getAttribute("data-ffc-pii-revealed")==="1"){'
			// Hide path — restore the saved placeholder text and reset the button label.
			. 'ph.textContent=btn.getAttribute("data-ffc-pii-mask")||"";'
			. 'btn.textContent="' . $reveal_lbl . '";'
			. 'btn.removeAttribute("data-ffc-pii-revealed");'
			. 'return;'
			. '}'
			. 'btn.disabled=true;'
			. 'var fd=new FormData();fd.append("field",field);'
			. 'fetch("' . $rest_root . '"+cid+"/reveal-pii",{'
			. 'method:"POST",'
			. 'headers:{"X-WP-Nonce":"' . esc_attr( $nonce ) . '"},'
			. 'body:fd,credentials:"same-origin"'
			. '}).then(function(r){return r.json().then(function(d){return{status:r.status,body:d};});}).then(function(o){'
			. 'btn.disabled=false;'
			. 'if(o.status>=200&&o.status<300&&o.body&&typeof o.body.value==="string"){'
			. 'btn.setAttribute("data-ffc-pii-mask",ph.textContent);'
			. 'ph.textContent=o.body.value;'
			. 'btn.textContent="' . $hide_lbl . '";'
			. 'btn.setAttribute("data-ffc-pii-revealed","1");'
			. '}else{'
			. 'var msg=(o.body&&o.body.message)?o.body.message:"' . $err_lbl . '";'
			. 'ph.textContent="["+msg+"]";'
			. '}'
			. '}).catch(function(){btn.disabled=false;ph.textContent="[' . $err_lbl . ']";});'
			. '});'
			. '})();'
			. '</script>';
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Section 3: Classifications + call history for this candidate.
	 *
	 * @param object $candidate Candidate row.
	 * @phpstan-param CandidateRow $candidate
	 * @return void
	 */
	private static function render_classifications_section( object $candidate ): void {
		$classifications = RecruitmentClassificationRepository::get_for_candidate( (int) $candidate->id );

		echo '<div class="postbox" style="margin-top:20px;">';
		echo '<h2 class="hndle"><span>' . esc_html__( 'Classifications + call history', 'ffcertificate' ) . '</span></h2>';
		echo '<div class="inside">';

		if ( empty( $classifications ) ) {
			echo '<p><em>' . esc_html__( '(no classifications)', 'ffcertificate' ) . '</em></p>';
		} else {
			$classification_ids = array_map( static fn( $c ) => (int) $c->id, $classifications );
			$calls_by_class     = self::group_calls_by_classification( $classification_ids );

			// Pre-compute the set of adjutancies attached to each
			// distinct notice referenced by this candidate's rows, so
			// the per-row <select> renders only the junction-attached
			// options (anything else would be rejected by the REST
			// endpoint and lead to a confusing UX).
			$adjutancies_by_notice = self::adjutancies_per_notice_for_rows( $classifications );

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
				$notice_obj = RecruitmentNoticeRepository::get_by_id( (int) $c->notice_id );
				$call_count = isset( $calls_by_class[ (int) $c->id ] ) ? count( $calls_by_class[ (int) $c->id ] ) : 0;

				echo '<tr>';
				echo '<td>' . esc_html( null !== $notice_obj ? (string) $notice_obj->code : '#' . (int) $c->notice_id ) . '</td>';
				echo '<td>' . self::render_adjutancy_cell( $c, $adjutancies_by_notice ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_adjutancy_cell returns pre-escaped HTML.
				echo '<td>' . esc_html( (string) $c->list_type ) . '</td>';
				echo '<td>' . esc_html( (string) $c->rank ) . '</td>';
				echo '<td>' . esc_html( (string) $c->score ) . '</td>';
				echo '<td><span class="ffc-status-badge ffc-status-' . esc_attr( (string) $c->status ) . '">' . esc_html( (string) $c->status ) . '</span></td>';
				echo '<td>' . esc_html( (string) $call_count ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
			self::render_adjutancy_swap_script();

			// Render an inline calls table per classification that has any calls.
			foreach ( $classifications as $c ) {
				$calls = $calls_by_class[ (int) $c->id ] ?? array();
				if ( empty( $calls ) ) {
					continue;
				}
				echo '<h4 style="margin-top:1.5em;">' . sprintf(
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
					echo '<td>' . esc_html( \FreeFormCertificate\Core\DateFormatter::format_datetime( (int) $call->called_at ) ) . '</td>';
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
	}

	/**
	 * Group every call row by its classification id for fast lookup.
	 *
	 * @param array<int, int> $classification_ids List of ids.
	 * @return array<int, list<object>>
	 * @phpstan-return array<int, list<CallRow>>
	 */
	private static function group_calls_by_classification( array $classification_ids ): array {
		$rows = RecruitmentCallRepository::get_history_for_classifications( $classification_ids );
		$out  = array();
		foreach ( $rows as $row ) {
			$cid = (int) $row->classification_id;
			if ( ! isset( $out[ $cid ] ) ) {
				$out[ $cid ] = array();
			}
			$out[ $cid ][] = $row;
		}
		return $out;
	}

	/**
	 * Section 4: per-candidate activity log feed (issue #331 "History").
	 *
	 * Surfaces every recruitment event that references this candidate
	 * — direct (`candidate_id` in context) or indirect (`classification_id`
	 * in context, matched against the candidate's current classifications).
	 * Delegates the cross-table lookup + decryption to
	 * {@see RecruitmentCandidateHistoryService::get_for_candidate()}.
	 *
	 * Rendered ABOVE the hard-delete postbox so the operator can scan
	 * the audit trail before committing a destructive action.
	 *
	 * @since 6.6.2
	 * @param object $candidate Candidate row.
	 * @phpstan-param CandidateRow $candidate
	 * @return void
	 */
	private static function render_history_section( object $candidate ): void {
		$entries = RecruitmentCandidateHistoryService::get_for_candidate( (int) $candidate->id );

		echo '<div class="postbox" style="margin-top:20px;">';
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
		foreach ( $entries as $entry ) {
			$action  = (string) ( $entry['action'] ?? '' );
			$context = is_array( $entry['context'] ?? null ) ? $entry['context'] : array();
			$when    = (string) ( $entry['created_at'] ?? '' );
			$uid     = (int) ( $entry['user_id'] ?? 0 );

			echo '<tr>';
			echo '<td>' . esc_html( '' === $when ? '—' : DateFormatter::format_datetime( $when ) ) . '</td>';
			echo '<td>' . esc_html( self::render_history_actor( $uid ) ) . '</td>';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- summarize_event() returns pre-escaped HTML.
			echo '<td>' . RecruitmentCandidateHistoryService::summarize_event( $action, $context ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		echo '</div></div>';
	}

	/**
	 * Resolve a user_id to a display string for the History table.
	 *
	 * Mirrors the convention used by the global activity-log admin
	 * page: real users → "Display Name (login)"; deleted users → the
	 * row keeps its trace via "User #N (deleted)"; system events
	 * (user_id 0, e.g. cron-driven cleanups) → "System".
	 *
	 * @since 6.6.2
	 * @param int $uid `wp_users.ID`, or 0 for system events.
	 * @return string Plain text, NOT yet escaped (caller wraps in esc_html).
	 */
	private static function render_history_actor( int $uid ): string {
		if ( $uid <= 0 ) {
			return __( 'System', 'ffcertificate' );
		}
		$user = get_userdata( $uid );
		if ( false === $user ) {
			return sprintf(
				/* translators: %d — orphaned WP user id */
				__( 'User #%d (deleted)', 'ffcertificate' ),
				$uid
			);
		}
		return sprintf( '%s (%s)', (string) $user->display_name, (string) $user->user_login );
	}

	/**
	 * Section 5: Hard-delete with reason (gated per §7-bis).
	 *
	 * @param object $candidate Candidate row.
	 * @phpstan-param CandidateRow $candidate
	 * @return void
	 */
	private static function render_delete_section( object $candidate ): void {
		$id                   = (int) $candidate->id;
		$nonce_action         = 'ffc_recruitment_delete_candidate_' . $id;
		$classification_count = RecruitmentClassificationRepository::count_for_candidate( $id );

		echo '<div class="postbox" style="margin-top:20px;">';
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
			self::build_delete_consequences( $candidate )
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
	}

	/**
	 * Build the consequences-bullet list for the hard-delete confirm
	 * modal. Issue #331: front-loads the candidate-row context (created /
	 * last-updated timestamps + linked WP user) so the operator sees
	 * actionable details BEFORE the standard "what will happen" lines.
	 *
	 * Returned as a `list<string>` ready to feed into wp_json_encode for
	 * the `data-ffc-confirm-consequences` attribute.
	 *
	 * @since 6.6.2
	 * @param object $candidate Candidate row.
	 * @phpstan-param CandidateRow $candidate
	 * @return list<string>
	 */
	private static function build_delete_consequences( object $candidate ): array {
		$context = array();

		$created_at = (string) $candidate->created_at;
		if ( '' !== $created_at ) {
			$context[] = sprintf(
				/* translators: %s — formatted datetime when the candidate row was created */
				__( 'Created on %s.', 'ffcertificate' ),
				DateFormatter::format_datetime( $created_at )
			);
		}

		$updated_at = (string) $candidate->updated_at;
		if ( '' !== $updated_at && $updated_at !== $created_at ) {
			$context[] = sprintf(
				/* translators: %s — formatted datetime when the candidate row was last touched */
				__( 'Last updated on %s.', 'ffcertificate' ),
				DateFormatter::format_datetime( $updated_at )
			);
		}

		$user_id = (int) ( $candidate->user_id ?? 0 );
		if ( $user_id > 0 ) {
			$user = get_userdata( $user_id );
			if ( false !== $user ) {
				$context[] = sprintf(
					/* translators: %s — linked WordPress user login */
					__( 'Linked WP user: @%s (preserved untouched on delete).', 'ffcertificate' ),
					(string) $user->user_login
				);
			}
		}

		// Standard "what will happen" lines — same vocabulary as before
		// #331, appended after the context block so the operator reads
		// "row state → action consequences" top-down.
		return array_merge(
			$context,
			array(
				__( 'The candidate row is removed permanently.', 'ffcertificate' ),
				__( 'ActivityLog entries are kept (with sensitive payloads already redacted).', 'ffcertificate' ),
				__( 'This cannot be undone.', 'ffcertificate' ),
			)
		);
	}

	/**
	 * Admin-post handler for the General section save.
	 *
	 * @return void
	 */
	public static function handle_save(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Access denied.', 'ffcertificate' ) );
		}
		$id = isset( $_POST['candidate_id'] ) ? absint( wp_unslash( (string) $_POST['candidate_id'] ) ) : 0;
		check_admin_referer( 'ffc_recruitment_save_candidate_' . $id );

		if ( $id <= 0 ) {
			wp_safe_redirect( self::back_url() );
			exit;
		}

		$name  = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['name'] ) ) : '';
		$email = isset( $_POST['email'] ) ? strtolower( sanitize_email( wp_unslash( (string) $_POST['email'] ) ) ) : '';
		$phone = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['phone'] ) ) : '';
		$notes = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['notes'] ) ) : '';

		// Read the pre-update row so we can audit-log the diff after the
		// repository write lands. Done BEFORE the update because the
		// repository's update method invalidates the cache key but
		// returns the wpdb int affected-rows, not the updated row.
		$before = RecruitmentCandidateRepository::get_by_id( $id );

		$update = array(
			'name'  => $name,
			'phone' => '' === $phone ? null : $phone,
			'notes' => '' === $notes ? null : $notes,
		);

		// Email: re-encrypt + re-hash via the registry path so the new
		// value is consistent with the rest of the system. Empty string
		// means "clear the email" — repository nulls both columns.
		if ( '' === $email ) {
			$update['email_encrypted'] = null;
			$update['email_hash']      = null;
		} else {
			$update['email_encrypted'] = Encryption::encrypt( $email );
			$update['email_hash']      = Encryption::hash( $email );
		}

		RecruitmentCandidateRepository::update( $id, $update );

		if ( null !== $before ) {
			$changes = self::diff_general_fields( $before, $name, $phone, $notes, $email );
			RecruitmentActivityLogger::candidate_fields_edited( $id, $changes );
		}

		self::redirect_with_notice( $id, 'saved' );
	}

	/**
	 * Diff the four General-section fields against the pre-update row,
	 * returning the entries that actually changed. Used by handle_save()
	 * to feed the audit logger — empty diff means "operator pressed
	 * Save without changing anything" and the logger short-circuits.
	 *
	 * The `email` field is reported as the SHA-256 hash digests of the
	 * old / new addresses (never plaintext); the other three live
	 * unencrypted in the candidate table by design (§12) and are
	 * logged as-is.
	 *
	 * @since 6.6.2
	 * @param object $before  Pre-update candidate row.
	 * @phpstan-param CandidateRow $before
	 * @param string $name    New name.
	 * @param string $phone   New phone (empty string means "clear").
	 * @param string $notes   New notes (empty string means "clear").
	 * @param string $email   New email (empty string means "clear").
	 * @return array<string, array{old: scalar|null, new: scalar|null}>
	 */
	private static function diff_general_fields( object $before, string $name, string $phone, string $notes, string $email ): array {
		$old_name       = (string) ( $before->name ?? '' );
		$old_phone      = null === $before->phone ? '' : (string) $before->phone;
		$old_notes      = null === $before->notes ? '' : (string) $before->notes;
		$old_email_hash = null === $before->email_hash ? '' : (string) $before->email_hash;
		$new_email_hash = '' === $email ? '' : (string) Encryption::hash( $email );

		$changes = array();
		if ( $old_name !== $name ) {
			$changes['name'] = array(
				'old' => $old_name,
				'new' => $name,
			);
		}
		if ( $old_phone !== $phone ) {
			$changes['phone'] = array(
				'old' => $old_phone,
				'new' => $phone,
			);
		}
		if ( $old_notes !== $notes ) {
			$changes['notes'] = array(
				'old' => $old_notes,
				'new' => $notes,
			);
		}
		if ( $old_email_hash !== $new_email_hash ) {
			$changes['email_hash'] = array(
				'old' => $old_email_hash,
				'new' => $new_email_hash,
			);
		}
		return $changes;
	}

	/**
	 * Admin-post handler for hard-delete.
	 *
	 * @return void
	 */
	public static function handle_delete(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Access denied.', 'ffcertificate' ) );
		}
		$id = isset( $_POST['candidate_id'] ) ? absint( wp_unslash( (string) $_POST['candidate_id'] ) ) : 0;
		check_admin_referer( 'ffc_recruitment_delete_candidate_' . $id );

		if ( $id <= 0 ) {
			wp_safe_redirect( self::back_url() );
			exit;
		}

		$result = RecruitmentDeleteService::delete_candidate( $id );

		if ( true === $result['success'] ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'    => RecruitmentAdminPage::PAGE_SLUG,
						'tab'     => 'candidates',
						'ffc_msg' => 'deleted',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		self::redirect_with_notice( $id, 'delete-blocked' );
	}

	/**
	 * Admin-post handler — link candidate to an existing WP user
	 * resolved by id, login, or email. Does NOT create new users; only
	 * sets the candidate.user_id pointer.
	 *
	 * @return void
	 */
	public static function handle_link_user(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Access denied.', 'ffcertificate' ) );
		}
		$id = isset( $_POST['candidate_id'] ) ? absint( wp_unslash( (string) $_POST['candidate_id'] ) ) : 0;
		check_admin_referer( 'ffc_recruitment_link_candidate_user_' . $id );

		if ( $id <= 0 ) {
			wp_safe_redirect( self::back_url() );
			exit;
		}

		$lookup = isset( $_POST['user_lookup'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['user_lookup'] ) ) : '';
		$lookup = trim( $lookup );

		$user = self::resolve_user( $lookup );
		if ( null === $user ) {
			self::redirect_with_notice( $id, 'link-user-not-found' );
		}

		RecruitmentCandidateRepository::set_user_id( $id, (int) $user->ID );
		self::redirect_with_notice( $id, 'link-user-ok' );
	}

	/**
	 * Admin-post handler — clear the candidate.user_id pointer. The
	 * linked wp_user account is preserved untouched.
	 *
	 * @return void
	 */
	public static function handle_unlink_user(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Access denied.', 'ffcertificate' ) );
		}
		$id = isset( $_POST['candidate_id'] ) ? absint( wp_unslash( (string) $_POST['candidate_id'] ) ) : 0;
		check_admin_referer( 'ffc_recruitment_link_candidate_user_' . $id );

		if ( $id > 0 ) {
			RecruitmentCandidateRepository::set_user_id( $id, null );
		}
		self::redirect_with_notice( $id, 'unlink-user-ok' );
	}

	/**
	 * Resolve a free-text lookup string into a `WP_User` or null.
	 *
	 * Strategy: numeric → ID, contains `@` → email, otherwise login.
	 * Returning null lets the caller surface a "not found" flash.
	 *
	 * @param string $lookup Free-text input.
	 * @return \WP_User|null
	 */
	private static function resolve_user( string $lookup ): ?\WP_User {
		if ( '' === $lookup ) {
			return null;
		}

		$candidate = null;
		if ( ctype_digit( $lookup ) ) {
			$candidate = get_user_by( 'id', (int) $lookup );
		} elseif ( false !== strpos( $lookup, '@' ) ) {
			$candidate = get_user_by( 'email', $lookup );
		} else {
			$candidate = get_user_by( 'login', $lookup );
		}

		return $candidate instanceof \WP_User ? $candidate : null;
	}

	/**
	 * Back-to-list URL.
	 *
	 * @return string
	 */
	private static function back_url(): string {
		return add_query_arg(
			array(
				'page' => RecruitmentAdminPage::PAGE_SLUG,
				'tab'  => 'candidates',
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Redirect back to the edit screen with a flash key.
	 *
	 * @param int    $candidate_id Candidate ID.
	 * @param string $message_key  Flash key.
	 * @return never
	 */
	private static function redirect_with_notice( int $candidate_id, string $message_key ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => RecruitmentAdminPage::PAGE_SLUG,
					'tab'          => 'candidates',
					'action'       => 'edit-candidate',
					'candidate_id' => $candidate_id,
					'ffc_msg'      => $message_key,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Build a `notice_id => list<adjutancy_id>` map for the unique
	 * notices referenced by the candidate's classifications. Used by
	 * the per-row Adjutancy <select> so the operator only sees options
	 * the notice_adjutancy junction would actually accept.
	 *
	 * @since 6.6.2
	 * @param array<int, object> $classifications Candidate's classification rows.
	 * @phpstan-param array<int, ClassificationRow> $classifications
	 * @return array<int, list<int>>
	 */
	private static function adjutancies_per_notice_for_rows( array $classifications ): array {
		$out  = array();
		$seen = array();
		foreach ( $classifications as $c ) {
			$notice_id = (int) $c->notice_id;
			if ( isset( $seen[ $notice_id ] ) ) {
				continue;
			}
			$seen[ $notice_id ] = true;
			$out[ $notice_id ]  = array_values( array_map( 'intval', RecruitmentNoticeAdjutancyRepository::get_adjutancy_ids_for_notice( $notice_id ) ) );
		}
		return $out;
	}

	/**
	 * Render the Adjutancy table cell for a single classification row
	 * — either an inline <select> + Save button (when the notice has
	 * >1 adjutancy attached) or the read-only `<code>` slug rendered
	 * before #331 (when 0 or 1 alternatives exist, swap would be a
	 * no-op or rejected). Issue #331 "Edit estendido".
	 *
	 * The returned HTML is already escaped and safe to echo directly.
	 *
	 * @since 6.6.2
	 * @param object                $c                     Classification row.
	 * @phpstan-param ClassificationRow $c
	 * @param array<int, list<int>> $adjutancies_by_notice  Pre-fetched map.
	 * @return string
	 */
	private static function render_adjutancy_cell( object $c, array $adjutancies_by_notice ): string {
		$current_adj_id = (int) $c->adjutancy_id;
		$notice_id      = (int) $c->notice_id;
		$candidates     = $adjutancies_by_notice[ $notice_id ] ?? array();
		$current_obj    = RecruitmentAdjutancyRepository::get_by_id( $current_adj_id );
		$current_label  = null !== $current_obj ? (string) $current_obj->slug : '#' . $current_adj_id;

		if ( count( $candidates ) < 2 ) {
			return '<code>' . esc_html( $current_label ) . '</code>';
		}

		$html  = '<span class="ffc-adjutancy-swap" data-ffc-cls-id="' . esc_attr( (string) (int) $c->id ) . '">';
		$html .= '<select class="ffc-adjutancy-swap-select">';
		foreach ( $candidates as $aid ) {
			$obj   = RecruitmentAdjutancyRepository::get_by_id( (int) $aid );
			$label = null !== $obj ? (string) $obj->slug : '#' . (int) $aid;
			$sel   = (int) $aid === $current_adj_id ? ' selected' : '';
			$html .= '<option value="' . esc_attr( (string) (int) $aid ) . '"' . esc_attr( $sel ) . '>' . esc_html( $label ) . '</option>';
		}
		$html .= '</select> ';
		$html .= '<button type="button" class="button button-small ffc-adjutancy-swap-btn">' . esc_html__( 'Save', 'ffcertificate' ) . '</button>';
		$html .= ' <span class="ffc-adjutancy-swap-msg" aria-live="polite"></span>';
		$html .= '</span>';
		return $html;
	}

	/**
	 * Inline JS for the per-row Adjutancy swap button. Issue #331.
	 *
	 * On click: PATCH /classifications/{id}/adjutancy with the selected
	 * option, surface success or the WP_Error message inline (the REST
	 * controller emits a stable `code` field per failure mode so we
	 * could branch on it for richer messaging later — for now we just
	 * show whatever the server returned).
	 *
	 * @since 6.6.2
	 * @return void
	 */
	private static function render_adjutancy_swap_script(): void {
		$rest_root = esc_url_raw( rest_url( 'ffcertificate/v1/recruitment/classifications/' ) );
		$nonce     = wp_create_nonce( 'wp_rest' );
		$ok_lbl    = esc_js( __( 'Saved', 'ffcertificate' ) );
		$err_lbl   = esc_js( __( 'Error', 'ffcertificate' ) );

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- All interpolated values escaped via esc_url_raw / esc_js above.
		echo '<script>'
			. '(function(){'
			. 'document.addEventListener("click",function(ev){'
			. 'var btn=ev.target;'
			. 'if(!btn||!btn.classList||!btn.classList.contains("ffc-adjutancy-swap-btn"))return;'
			. 'ev.preventDefault();'
			. 'var wrap=btn.closest(".ffc-adjutancy-swap");'
			. 'if(!wrap)return;'
			. 'var cid=wrap.getAttribute("data-ffc-cls-id");'
			. 'var sel=wrap.querySelector(".ffc-adjutancy-swap-select");'
			. 'var msg=wrap.querySelector(".ffc-adjutancy-swap-msg");'
			. 'if(!cid||!sel||!msg)return;'
			. 'msg.textContent="";'
			. 'btn.disabled=true;'
			. 'fetch("' . $rest_root . '"+encodeURIComponent(cid)+"/adjutancy",{'
			. 'method:"PATCH",'
			. 'credentials:"same-origin",'
			. 'headers:{"Content-Type":"application/json","X-WP-Nonce":"' . esc_js( $nonce ) . '"},'
			. 'body:JSON.stringify({adjutancy_id:parseInt(sel.value,10)})'
			. '}).then(function(r){return r.json().then(function(b){return{ok:r.ok,body:b};});})'
			. '.then(function(res){'
			. 'btn.disabled=false;'
			. 'if(res.ok&&res.body&&res.body.success){msg.textContent="' . $ok_lbl . '";msg.style.color="#1a7f37";}'
			. 'else{msg.textContent="' . $err_lbl . ': "+((res.body&&res.body.message)||"");msg.style.color="#b91c1c";}'
			. '}).catch(function(){btn.disabled=false;msg.textContent="' . $err_lbl . '";msg.style.color="#b91c1c";});'
			. '});'
			. '})();'
			. '</script>';
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
