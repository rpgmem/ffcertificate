<?php
/**
 * Template: Identity Resolution — shared-number panel.
 *
 * One identifier stored against two logins: the merge form.
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
use FreeFormCertificate\Admin\IdentityResolutionScreen;
use FreeFormCertificate\Core\DateFormatter;
use FreeFormCertificate\Maintenance\IdentityConflictQuery;
use FreeFormCertificate\Maintenance\IdentityQueue;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
	<?php
	// AN OFFERED BUTTON THAT ANSWERS 403 IS WORSE THAN AN ABSENT ONE.
	//
	// The shared panel IS the merge form -- there is nothing else in it -- so
	// an operator without the capability is shown the findings and a control
	// that refuses them. The panel goes; the findings stay countable, because
	// `IdentityQueuePanels` built them either way and the tier still exists.
	?>
	<?php if ( isset( $ffc_identity_screen->by_tier[ IdentityQueue::TIER_SHARED ] ) && $ffc_identity_may_merge ) : ?>
		<?php
		$ffc_identity_panel = $ffc_identity_screen->by_tier[ IdentityQueue::TIER_SHARED ];
		$ffc_identity_pairs = IdentityResolutionScreen::shown( $ffc_identity_panel );

		$ffc_identity_head(
			$ffc_identity_panel,
			IdentityResolutionLabels::tier_label( IdentityQueue::TIER_SHARED ),
			IdentityResolutionLabels::tier_note( IdentityQueue::TIER_SHARED )
		);
		?>
		<p class="description">
			<?php esc_html_e( 'One identifier is stored against two logins, so one of them is not that person\'s. Merge only when you know they are one person, and choose which login keeps the records. A merge is the one action no other can undo: afterwards nothing can tell which records came from where.', 'ffcertificate' ); ?>
		</p>

		<?php
		// ONE PAIR PER FORM, WHICH IS WHAT REMOVED THE PARTIAL RESULT.
		//
		// This was one form over a list of checkboxes, so an outcome could be
		// part merged and part refused. Each pair is now its own form, its own
		// nonce and its own transaction, and the stepper above shows one at a
		// time unless the operator asked for the list.
		?>
		<?php foreach ( $ffc_identity_pairs as $ffc_identity_pair ) : ?>
			<?php
			$ffc_identity_who = IdentityConflictQuery::parse_accounts(
				$ffc_identity_pair[ IdentityConflictQuery::COLUMN_RELATED ] ?? ''
			);
			$ffc_identity_id  = (string) ( $ffc_identity_pair['subject'] ?? '' );
			?>
			<?php if ( 2 !== count( $ffc_identity_who ) ) : ?>
				<p class="description">
					<?php
					// MORE THAN TWO IS NOT A PAIR, AND IT IS NOT OFFERED. A
					// merge takes one survivor and one absorbed account; three
					// logins sharing a number is three decisions, made two at
					// a time, and a control that hid that would invite one
					// sweep over all of them.
					printf(
						/* translators: %s: how many accounts share the identifier. */
						esc_html__( '%s accounts share this identifier, so it is not one pair. Resolve them two at a time.', 'ffcertificate' ),
						esc_html( number_format_i18n( count( $ffc_identity_who ) ) )
					);
					?>
				</p>
				<?php continue; ?>
			<?php endif; ?>
			<?php
			// THE EVIDENCE BEHIND THE CHOICE, WHERE THE CHOICE IS MADE (#1368).
			//
			// The preview below already states the per-store counts, and it
			// states them AFTER a click -- which is one click too late: the
			// operator picks a survivor at the radio, so the evidence belongs
			// at the radio. `account_activity` reached the audit CSV and the
			// Migrations table and never this form at all.
			//
			// Read for this pair only, through the same `IdentityAccountFacts::of()` the
			// export reads, so the screen and the CSV cannot disagree.
			$ffc_identity_pair_facts = $ffc_identity_facts( $ffc_identity_who );
			?>
			<div class="ffc-identity-pair">
				<p class="ffc-identity-pair-subject">
					<code><?php echo esc_html( substr( $ffc_identity_id, 0, IdentityQueue::DISPLAY_PREFIX ) ); ?></code>
					<span class="description">
						<?php echo esc_html( strtoupper( str_replace( '_hash', '', (string) ( $ffc_identity_pair['identifier_column'] ?? '' ) ) ) ); ?>
					</span>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ffc-identity-merge-one">
					<?php wp_nonce_field( IdentityResolutionPage::MERGE_NONCE . $ffc_identity_id ); ?>
					<input type="hidden" name="action" value="<?php echo esc_attr( IdentityResolutionPage::MERGE_ACTION ); ?>">
					<input type="hidden" name="ffc_key" value="<?php echo esc_attr( (string) ( $ffc_identity_pair[ IdentityQueue::COLUMN_KEY ] ?? '' ) ); ?>">
					<input type="hidden" name="ffc_subject" value="<?php echo esc_attr( $ffc_identity_id ); ?>">
					<input type="hidden" name="ffc_a" value="<?php echo esc_attr( (string) $ffc_identity_who[0] ); ?>">
					<input type="hidden" name="ffc_b" value="<?php echo esc_attr( (string) $ffc_identity_who[1] ); ?>">

					<fieldset class="ffc-identity-pair-choice">
						<legend><?php esc_html_e( 'Which login keeps the records', 'ffcertificate' ); ?></legend>
						<?php // NOTHING IS PROPOSED. The evidence says which login holds the records and when it was last used; which one is the person's real login is not a thing the data settles, and a screen that picked one would be asserting it. ?>
						<p class="description ffc-identity-pair-basis">
							<?php esc_html_e( 'The newer login is usually the accidental one — it exists because the resolver failed to match. What each holds and when it was last used is below; the choice is yours.', 'ffcertificate' ); ?>
						</p>
						<?php foreach ( $ffc_identity_who as $ffc_identity_account ) : ?>
							<?php
							$ffc_identity_fact = $ffc_identity_pair_facts[ (int) $ffc_identity_account ] ?? array();
							$ffc_identity_held = array();

							foreach ( (array) ( $ffc_identity_fact['rows'] ?? array() ) as $ffc_identity_store => $ffc_identity_n ) {
								$ffc_identity_held[] = sprintf(
									/* translators: 1: how many records. 2: the store holding them. */
									__( '%1$s in %2$s', 'ffcertificate' ),
									number_format_i18n( (int) $ffc_identity_n ),
									IdentityResolutionLabels::store_name( $ffc_identity_store )
								);
							}

							// `format_wallclock_date()`, NEVER `format_date()`.
							//
							// `activity_per_account()` already resolved the
							// moment to a site-local `Y-m-d` -- it has to,
							// because the four stores disagree about how a
							// moment is stored and only the rendered date
							// compares like for like across them. So what
							// arrives here is Category B and carries no
							// timezone semantics; `format_date()` would parse
							// it at UTC midnight and re-apply the site zone,
							// printing the PREVIOUS day anywhere west of UTC.
							$ffc_identity_seen = DateFormatter::format_wallclock_date(
								(string) ( $ffc_identity_fact['activity'] ?? '' )
							);
							?>
							<label>
								<input type="radio" name="ffc_keep" required
									class="ffc-identity-keep"
									value="<?php echo esc_attr( (string) $ffc_identity_account ); ?>">
								<a href="<?php echo esc_url( admin_url( 'user-edit.php?user_id=' . rawurlencode( (string) $ffc_identity_account ) ) ); ?>">
									<?php echo esc_html( IdentityResolutionLabels::account( $ffc_identity_account ) ); ?>
								</a>
							</label>
							<p class="description ffc-identity-pair-evidence">
								<?php if ( array() !== $ffc_identity_held ) : ?>
									<span class="ffc-identity-pair-holds">
										<?php
										printf(
											/* translators: %s: the per-store record counts, comma separated. */
											esc_html__( 'Holds %s', 'ffcertificate' ),
											esc_html( wp_sprintf_l( '%l', $ffc_identity_held ) )
										);
										?>
									</span>
								<?php else : ?>
									<?php // An account this audit named is named BECAUSE rows point at it, so no count means the rows sit in a store this install does not resolve -- never that the login is empty. ?>
									<span class="ffc-identity-pair-holds"><?php esc_html_e( 'No records in any store this install can read', 'ffcertificate' ); ?></span>
								<?php endif; ?>
								<?php if ( '' !== $ffc_identity_seen ) : ?>
									<span class="ffc-identity-pair-seen">
										<?php
										printf(
											/* translators: %s: the date the login was last active. */
											esc_html__( 'Last used %s', 'ffcertificate' ),
											esc_html( $ffc_identity_seen )
										);
										?>
									</span>
								<?php else : ?>
									<?php // Not "never used": only the stores carrying a date column are read, so an absent value is an absent reading. ?>
									<span class="ffc-identity-pair-seen"><?php esc_html_e( 'No activity date recorded', 'ffcertificate' ); ?></span>
								<?php endif; ?>
							</p>
						<?php endforeach; ?>
					</fieldset>

					<?php
					// THE PREVIEW IS THE WRITE'S OWN COUNT, ASKED FOR BEFORE
					// THE WRITE. Its region carries the fixed sentences as
					// data attributes, the way the correction preflight does,
					// so every string is escaped at the output point.
					?>
					<p>
						<?php
						AdminUI::render_button(
							array(
								'label' => __( 'Show what would move', 'ffcertificate' ),
								'icon'  => 'eye',
								'class' => 'ffc-identity-preview',
								'attrs' => array(
									'data-ffc-a'      => (string) $ffc_identity_who[0],
									'data-ffc-b'      => (string) $ffc_identity_who[1],
									'data-ffc-region' => 'ffc-merge-preview-' . $ffc_identity_id,
									'data-ffc-ack'    => 'ffc-merge-ack-' . $ffc_identity_id,
								),
							)
						);
						?>
					</p>

					<div class="ffc-identity-preview-region" id="ffc-merge-preview-<?php echo esc_attr( $ffc_identity_id ); ?>"
						aria-live="polite"
						<?php /* translators: 1: the surviving login's name. 2: the emptied login's name. */ ?>
						data-heading="<?php esc_attr_e( '%1$s keeps the records. %2$s is emptied.', 'ffcertificate' ); ?>"
						<?php /* translators: %s: how many records would move. */ ?>
						data-total="<?php esc_attr_e( '%s records move.', 'ffcertificate' ); ?>"
						<?php /* translators: 1: how many records. 2: the store they sit in. */ ?>
						data-store="<?php esc_attr_e( '%1$s in %2$s', 'ffcertificate' ); ?>"
						<?php /* translators: 1: how many memberships, places or permissions. 2: the relationship table they sit in. */ ?>
						data-grant="<?php esc_attr_e( '%1$s in %2$s move with them.', 'ffcertificate' ); ?>"
						<?php /* translators: %s: how many rows are dropped because the surviving login already had that pairing. */ ?>
						data-grant-duplicate="<?php esc_attr_e( '%s dropped, because the surviving login already had it.', 'ffcertificate' ); ?>"
						<?php /* translators: %s: the identifiers the surviving login would gain, comma separated. */ ?>
						data-gains="<?php esc_attr_e( 'The surviving login also gains the %s it did not hold.', 'ffcertificate' ); ?>"
						<?php /* translators: 1: a login's name. 2: how many records it holds. */ ?>
						data-holds="<?php esc_attr_e( '%1$s holds %2$s records today.', 'ffcertificate' ); ?>"
						data-choose="<?php esc_attr_e( 'Choose which login keeps the records first.', 'ffcertificate' ); ?>"
						data-failed="<?php esc_attr_e( 'The preview could not be completed.', 'ffcertificate' ); ?>"></div>

					<?php
					// A FIELD, NOT A BROWSER DIALOG. A `confirm()` is not
					// evidence and does not survive a screen without
					// JavaScript; this posts, and the handler refuses without
					// it rather than doing nothing.
					?>
					<p>
						<label>
							<input type="checkbox" name="ffc_ack" value="1" required
								id="ffc-merge-ack-<?php echo esc_attr( $ffc_identity_id ); ?>">
							<?php esc_html_e( 'I confirm these are one person, and that this cannot be undone.', 'ffcertificate' ); ?>
						</label>
					</p>
					<p>
						<?php
						AdminUI::render_button(
							array(
								'label'    => __( 'Merge this pair', 'ffcertificate' ),
								'category' => 'primary',
								'icon'     => 'merge',
								'type'     => 'submit',
								'id'       => 'ffc-merge-go-' . $ffc_identity_id,
							)
						);
						?>
					</p>
				</form>
			</div>
		<?php endforeach; ?>
		<p class="description">
			<?php esc_html_e( 'The records, the identity index and the surviving login\'s certificate access move together, as one transaction. The emptied login is left in place — removing it is yours to do in Users, because deleting an account runs cleanup this tool does not own and cannot undo.', 'ffcertificate' ); ?>
		</p>

		<?php
		// THE OTHER ANSWER, AND OFTEN THE RIGHT ONE.
		//
		// Two logins sharing a number whose names do not resemble each other
		// are almost never one person twice; they are a wrong document on one
		// of them. A merge would consolidate two people. So the correction is
		// offered beside the merge, per person, and it is scoped to that one
		// login -- the same rewrite applied to the shared hash without a scope
		// would correct BOTH, which is the defect wearing a fix's clothes.
		//
		// OUTSIDE the merge form, because a form cannot contain another.
		?>
		<?php foreach ( $ffc_identity_pairs as $ffc_identity_pair ) : ?>
			<?php
			$ffc_identity_who = IdentityConflictQuery::parse_accounts(
				$ffc_identity_pair[ IdentityConflictQuery::COLUMN_RELATED ] ?? ''
			);
			?>
			<?php if ( 2 !== count( $ffc_identity_who ) ) : ?>
				<?php continue; ?>
			<?php endif; ?>
			<div class="ffc-identity-correct-instead">
				<h4><?php esc_html_e( 'Or correct the document on one of them', 'ffcertificate' ); ?></h4>
				<p class="description">
					<?php esc_html_e( 'If the two names do not look like the same person, one of these logins has the wrong number rather than a duplicate account. Correcting it on that login alone dissolves the finding and leaves both accounts standing. Confirm the number with HR first — the check digit says a number is wrong, never what the right one is.', 'ffcertificate' ); ?>
				</p>
				<?php foreach ( $ffc_identity_who as $ffc_identity_account ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ffc-identity-correct-one">
						<?php wp_nonce_field( IdentityResolutionPage::REPAIR_NONCE . (string) ( $ffc_identity_pair['subject'] ?? '' ) ); ?>
						<input type="hidden" name="action" value="<?php echo esc_attr( IdentityResolutionPage::REPAIR_ACTION ); ?>">
						<input type="hidden" name="ffc_key" value="<?php echo esc_attr( (string) ( $ffc_identity_pair[ IdentityQueue::COLUMN_KEY ] ?? '' ) ); ?>">
						<input type="hidden" name="ffc_subject" value="<?php echo esc_attr( (string) ( $ffc_identity_pair['subject'] ?? '' ) ); ?>">
						<input type="hidden" name="ffc_field" value="<?php echo esc_attr( str_replace( '_hash', '', (string) ( $ffc_identity_pair['identifier_column'] ?? 'rf' ) ) ); ?>">
						<?php // The scope. Without it the rewrite would reach the other login's rows too, since both carry this hash. ?>
						<input type="hidden" name="ffc_account_scope" value="<?php echo esc_attr( (string) $ffc_identity_account ); ?>">
						<label for="ffc-correct-<?php echo esc_attr( (string) ( $ffc_identity_pair['subject'] ?? '' ) . '-' . (string) $ffc_identity_account ); ?>">
							<?php echo esc_html( IdentityResolutionLabels::account( $ffc_identity_account ) ); ?>
						</label>
						<?php // `text` with `inputmode`, never `number`: a leading zero is a digit here, not formatting. ?>
						<input type="text" inputmode="numeric" pattern="[0-9]{7,11}" maxlength="11" size="12" required
							id="ffc-correct-<?php echo esc_attr( (string) ( $ffc_identity_pair['subject'] ?? '' ) . '-' . (string) $ffc_identity_account ); ?>"
							name="ffc_rf" placeholder="<?php esc_attr_e( 'The confirmed number', 'ffcertificate' ); ?>">
						<?php
						AdminUI::render_button(
							array(
								'label' => __( 'Correct theirs', 'ffcertificate' ),
								'icon'  => 'edit',
								'type'  => 'submit',
							)
						);
						?>
						<?php $ffc_identity_ack( (string) ( $ffc_identity_pair['subject'] ?? '' ) ); ?>
					</form>
				<?php endforeach; ?>
			</div>
		<?php endforeach; ?>
		<?php $ffc_identity_foot(); ?>
	<?php endif; ?>
