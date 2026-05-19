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
				$notice_obj    = RecruitmentNoticeRepository::get_by_id( (int) $c->notice_id );
				$adjutancy_obj = RecruitmentAdjutancyRepository::get_by_id( (int) $c->adjutancy_id );
				$call_count    = isset( $calls_by_class[ (int) $c->id ] ) ? count( $calls_by_class[ (int) $c->id ] ) : 0;

				echo '<tr>';
				echo '<td>' . esc_html( null !== $notice_obj ? (string) $notice_obj->code : '#' . (int) $c->notice_id ) . '</td>';
				echo '<td><code>' . esc_html( null !== $adjutancy_obj ? (string) $adjutancy_obj->slug : '#' . (int) $c->adjutancy_id ) . '</code></td>';
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
	 * Section 4: Hard-delete with reason (gated per §7-bis).
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

		$hard_delete_consequences = wp_json_encode(
			array(
				__( 'The candidate row is removed permanently.', 'ffcertificate' ),
				__( 'The linked WordPress user (if any) is preserved untouched.', 'ffcertificate' ),
				__( 'ActivityLog entries are kept (with sensitive payloads already redacted).', 'ffcertificate' ),
				__( 'This cannot be undone.', 'ffcertificate' ),
			)
		);
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"'
			. ' data-ffc-confirm'
			. ' data-ffc-confirm-title="' . esc_attr__( 'Hard-delete this candidate?', 'ffcertificate' ) . '"'
			. ' data-ffc-confirm-body="' . esc_attr__( 'You are about to permanently delete this candidate record.', 'ffcertificate' ) . '"'
			. ' data-ffc-confirm-consequences="' . esc_attr( (string) $hard_delete_consequences ) . '"'
			. ' data-ffc-confirm-cta="' . esc_attr__( 'Delete permanently', 'ffcertificate' ) . '"'
			. ' data-ffc-confirm-style="destructive">';
		echo '<input type="hidden" name="action" value="ffc_recruitment_delete_candidate">';
		echo '<input type="hidden" name="candidate_id" value="' . esc_attr( (string) $id ) . '">';
		wp_nonce_field( $nonce_action );
		echo '<p><label for="ffc-cand-delete-reason">' . esc_html__( 'Reason (logged):', 'ffcertificate' ) . '</label><br>';
		echo '<input id="ffc-cand-delete-reason" type="text" class="large-text" name="reason" required></p>';
		submit_button( __( 'Delete permanently', 'ffcertificate' ), 'delete' );
		echo '</form>';

		echo '</div></div>';
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

		self::redirect_with_notice( $id, 'saved' );
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
}
