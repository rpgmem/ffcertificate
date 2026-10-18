<?php
/**
 * Template: Identity Resolution — accepted findings.
 *
 * The findings somebody judged impossible to resolve (#1532).
 *
 * Included from `includes/admin/views/identity-resolution-page.php`, inside
 * `IdentityResolutionPage::render_page()`, so the page's `$ffc_identity_*`
 * locals are in scope. Moved verbatim (#1925): the page renders byte for
 * byte as it did when this was inline.
 *
 * @package FreeFormCertificate\Admin
 * @since   6.41.0
 */

use FreeFormCertificate\Admin\AdminUI;
use FreeFormCertificate\Admin\IdentityResolutionLabels;
use FreeFormCertificate\Admin\IdentityResolutionPage;
use FreeFormCertificate\Core\DateFormatter;
use FreeFormCertificate\Maintenance\IdentityAcceptance;
use FreeFormCertificate\Maintenance\IdentityQueue;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
	<?php
	// ORPHANS — records with an identifier and no account (#1397 sprint 6).
	//
	// A LIST RATHER THAN A STEPPER, AND THE REASON IS NOT LAZINESS.
	//
	// The four panels above step through a HELD worklist: resolving one
	// removes that finding and the cursor keeps its meaning. An adoption
	// does not remove a finding from a held list -- it links rows, and the
	// next read simply finds fewer. So there is nothing for a cursor to hold
	// onto, and a stepper over a list that reshapes under it would be a
	// control that lies. The card chrome is the same, which is what keeps
	// the screen one screen.
	//
	// It is gated on the SPLIT capability: the action can open a WordPress
	// user, which is exactly what that capability was carved out for (#1397).
	?>
	<?php if ( array() !== $ffc_identity_accepted ) : ?>
		<?php
		// THE DECISIONS, NOT A SCAN (#1532).
		//
		// Every other panel on this screen is a view of what the scan found.
		// This one is a view of what people DECIDED, which is why it renders
		// when the scan found nothing and even when the scan could read
		// nothing: a judgement does not stop existing because the encryption
		// key moved.
		//
		// IT IS LISTED, COUNTED AND SEPARATE. Counted because a suppression
		// nobody can see is how a queue comes to lie about being finished;
		// separate because these are not resolutions and must never be read as
		// the "resolved this sitting" number, which is why
		// `IdentityAcceptance::NOT_RESOLUTIONS` keeps them out of
		// `IdentityResolutionPage::RESOLVED_ACTIONS`.
		//
		// The subject is printed as a PREFIX, the same length the cards use,
		// for the reason `IdentityQueue::DISPLAY_PREFIX` gives: the hash of a
		// seven-digit number is not far from the number.
		?>
		<?php
		// THE ORPHAN PANEL'S MARKUP, NOT A NEW HEADING OF ITS OWN.
		//
		// `$ffc_identity_head` carries a cursor and a `See the list` toggle,
		// and this panel can honour neither -- it is always the whole list, and
		// a record is not a held scan. That is the same reason the orphan panel
		// writes its header out, so this follows it class for class: inventing
		// `ffc-identity-head` here would have been a name with no rule behind
		// it, which is what `CLAUDE.md` means by looking for the convention
		// before making one up.
		?>
		<div class="ffc-identity-panel">
		<div class="ffc-identity-panel-head">
			<span class="ffc-identity-panel-chip ffc-identity-chip-accepted"><?php esc_html_e( 'Accepted', 'ffcertificate' ); ?></span>
			<h2 class="ffc-identity-panel-title screen-reader-text"><?php esc_html_e( 'Accepted — no resolution possible', 'ffcertificate' ); ?></h2>
			<p class="ffc-identity-panel-note description"><?php esc_html_e( 'Findings somebody judged impossible to resolve: the number was never supplied, or HR cannot say whose it is. Nothing about them was changed — the stored values still fail their check digits, and a merge or a move blocked by one of them stays blocked. Put one back as soon as the correct value is known.', 'ffcertificate' ); ?></p>
			<div class="ffc-identity-panel-nav">
				<span class="ffc-identity-panel-count">
					<?php
					printf(
						/* translators: %s: how many findings are accepted. */
						esc_html( _n( '%s accepted', '%s accepted', count( $ffc_identity_accepted ), 'ffcertificate' ) ),
						esc_html( number_format_i18n( count( $ffc_identity_accepted ) ) )
					);
					?>
				</span>
			</div>
		</div>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Identifier', 'ffcertificate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Finding', 'ffcertificate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Reason', 'ffcertificate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Accepted', 'ffcertificate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Put back', 'ffcertificate' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php
			// `ffc_identity_accepted_row` AND NOT `ffc_identity_ack`: that name is
			// already a CLOSURE above, called from three cards, and a `foreach` would
			// overwrite it with an array -- a fatal on the next call, which only moves
			// into range when somebody adds a card below this panel. PHP does not scope
			// a `foreach`, which is the whole reason this file keeps saying so.
			?>
			<?php foreach ( $ffc_identity_accepted as $ffc_identity_accepted_row ) : ?>
				<?php
				$ffc_identity_ar_subject = (string) ( $ffc_identity_accepted_row[ IdentityAcceptance::FIELD_SUBJECT ] ?? '' );
				$ffc_identity_ar_field   = (string) ( $ffc_identity_accepted_row[ IdentityAcceptance::FIELD_IDENTIFIER ] ?? '' );
				$ffc_identity_ar_reason  = (string) ( $ffc_identity_accepted_row[ IdentityAcceptance::FIELD_REASON ] ?? '' );
				$ffc_identity_ar_at      = (int) ( $ffc_identity_accepted_row[ IdentityAcceptance::FIELD_AT ] ?? 0 );
				$ffc_identity_ar_check   = (string) ( $ffc_identity_accepted_row[ IdentityAcceptance::FIELD_CHECK ] ?? '' );

				// WHICH KIND OF SUBJECT THIS ROW HOLDS (#1536).
				//
				// The key's subject is a hash for two of the three checks and
				// an ACCOUNT ID for the third, and both used to print through
				// the same `substr()` -- so `438` and a hash prefix rendered
				// identically, with nothing saying which was which. Shape
				// cannot decide it either: a hex prefix can be all digits.
				// `HASH_SUBJECT_CHECKS` is the one place that knows.
				$ffc_identity_ar_is_hash = in_array( $ffc_identity_ar_check, IdentityAcceptance::HASH_SUBJECT_CHECKS, true );
				?>
				<tr>
					<td><code><?php echo esc_html( strtoupper( $ffc_identity_ar_field ) ); ?></code></td>
					<td>
						<?php echo esc_html( IdentityResolutionLabels::tier_label( (string) ( $ffc_identity_accepted_row[ IdentityAcceptance::FIELD_TIER ] ?? '' ) ) ); ?>
						<br>
						<?php if ( $ffc_identity_ar_is_hash ) : ?>
							<code><?php echo esc_html( substr( $ffc_identity_ar_subject, 0, IdentityQueue::DISPLAY_PREFIX ) ); ?></code>
						<?php else : ?>
							<?php
							// `IdentityResolutionLabels::account()`, the resolver the cards
							// above already use, rather than a label of this
							// panel's own: it answers with the person's name
							// when the account is live and with `Account #438`
							// when it is not -- which is exactly what this row
							// needs, because an acceptance OUTLIVES the account
							// it was taken about (see `IdentityAcceptance`).
							echo esc_html( IdentityResolutionLabels::account( $ffc_identity_ar_subject ) );
							?>
						<?php endif; ?>
					</td>
					<td>
						<?php
						if ( IdentityAcceptance::REASON_NEVER_SUPPLIED === $ffc_identity_ar_reason ) {
							esc_html_e( 'The person never supplied this number', 'ffcertificate' );
						} elseif ( IdentityAcceptance::REASON_UNIDENTIFIABLE === $ffc_identity_ar_reason ) {
							esc_html_e( 'HR cannot say whose number this is', 'ffcertificate' );
						} else {
							// A RECORD WHOSE REASON THIS RELEASE DOES NOT KNOW
							// still has to render: an option holds whatever is
							// in it, and a row that vanishes is a suppression
							// nobody can withdraw.
							esc_html_e( 'Reason not recorded', 'ffcertificate' );
						}
						?>
					</td>
					<td>
						<?php echo $ffc_identity_ar_at > 0 ? esc_html( DateFormatter::format_datetime( $ffc_identity_ar_at ) ) : '&mdash;'; ?>
					</td>
					<td>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( IdentityResolutionPage::WITHDRAW_NONCE . $ffc_identity_ar_subject ); ?>
							<input type="hidden" name="action" value="<?php echo esc_attr( IdentityResolutionPage::WITHDRAW_ACTION ); ?>">
							<input type="hidden" name="ffc_check" value="<?php echo esc_attr( $ffc_identity_ar_check ); ?>">
							<input type="hidden" name="ffc_field" value="<?php echo esc_attr( $ffc_identity_ar_field ); ?>">
							<input type="hidden" name="ffc_subject" value="<?php echo esc_attr( $ffc_identity_ar_subject ); ?>">
							<?php
							AdminUI::render_button(
								array(
									'label'    => __( 'Put back in the queue', 'ffcertificate' ),
									'category' => 'caution',
									'icon'     => 'restore',
									'type'     => 'submit',
								)
							);
							?>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
	<?php endif; ?>
