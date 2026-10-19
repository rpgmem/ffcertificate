<?php
/**
 * Template: Identity Resolution — account panels.
 *
 * The three account-side tiers: mistyped, decision and shared mailbox.
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
use FreeFormCertificate\Admin\IdentityQueuePanels;
use FreeFormCertificate\Admin\IdentityResolutionLabels;
use FreeFormCertificate\Admin\IdentityResolutionPage;
use FreeFormCertificate\Admin\IdentityResolutionScreen;
use FreeFormCertificate\Maintenance\IdentityAcceptance;
use FreeFormCertificate\Maintenance\IdentityConflictQuery;
use FreeFormCertificate\Maintenance\IdentityQueue;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
	<?php
	// THREE PANELS OVER ONE CARD, because what differs between them is the
	// verb offered, not the shape of the finding: all three are an account
	// holding more than one identifier. The tier label moved into the panel
	// header, so the `What is known` column below now says it once per panel
	// rather than once per row.
	//
	// The third is the shared mailbox (#1368), and it is here rather than in
	// a panel of its own precisely because the finding is the same shape --
	// what makes it a tier is that the verb column is empty by decision. A
	// separate layout would have made that look like a different kind of
	// finding, when it is the same finding the screen refuses to act on.
	$ffc_identity_account_tiers = array( IdentityQueue::TIER_MECHANICAL, IdentityQueue::TIER_DECISION, IdentityQueue::TIER_MAILBOX );
	?>
	<?php foreach ( $ffc_identity_account_tiers as $ffc_identity_this_tier ) : ?>
		<?php if ( ! isset( $ffc_identity_screen->by_tier[ $ffc_identity_this_tier ] ) ) : ?>
			<?php continue; ?>
		<?php endif; ?>
		<?php
		$ffc_identity_panel     = $ffc_identity_screen->by_tier[ $ffc_identity_this_tier ];
		$ffc_identity_accountal = IdentityResolutionScreen::shown( $ffc_identity_panel );

		$ffc_identity_head(
			$ffc_identity_panel,
			IdentityResolutionLabels::tier_label( (string) $ffc_identity_this_tier ),
			IdentityResolutionLabels::tier_note( (string) $ffc_identity_this_tier )
		);
		?>
		<?php
		// A CARD PER FINDING, NOT A ROW PER FINDING (#1407 sprint 2).
		//
		// The table asked the operator to read four cells and work out what
		// they meant together: two hashes in one column, a verdict beside
		// each, an account in another, and the verb in a fourth. What the
		// check digits had already decided was derivable from it and stated
		// nowhere. The card states it in a sentence and shows the two hashes
		// as the before and after of the write the button performs.
		//
		// The FORMS ARE UNTOUCHED -- same fields, same nonces, same actions,
		// moved into a column of their own. This sprint is markup and CSS.
		?>
		<div class="ffc-identity-cards">
		<?php foreach ( $ffc_identity_accountal as $ffc_identity_item ) : ?>
			<?php
			$ffc_identity_tier = (string) ( $ffc_identity_item[ IdentityQueue::COLUMN_TIER ] ?? '' );

			// The two orientations of one finding, and they are mirror
			// images: an account holding several numbers names the account
			// in `subject`, while a number held by several accounts names
			// the NUMBER there and the accounts in `related`. Reading one
			// shape for both is how an account id gets printed as a hash.
			if ( IdentityQueue::TIER_SHARED === $ffc_identity_tier ) {
				$ffc_identity_who   = IdentityConflictQuery::parse_accounts( $ffc_identity_item[ IdentityConflictQuery::COLUMN_RELATED ] ?? '' );
				$ffc_identity_which = array( (string) ( $ffc_identity_item['subject'] ?? '' ) => '' );
			} else {
				$ffc_identity_who   = IdentityConflictQuery::parse_accounts( $ffc_identity_item['subject'] ?? '' );
				$ffc_identity_which = (array) ( $ffc_identity_item[ IdentityQueue::COLUMN_VERDICTS ] ?? array() );
			}

			// The kind, as the operator says it. `RF` and `CPF` are proper
			// nouns here, so they are uppercased rather than translated --
			// the criterion `CLAUDE.md` states for a domain acronym inside an
			// English sentence.
			$ffc_identity_kind  = strtoupper( str_replace( '_hash', '', (string) ( $ffc_identity_item['identifier_column'] ?? '' ) ) );
			$ffc_identity_wrong = (string) ( $ffc_identity_item[ IdentityQueue::COLUMN_WRONG ] ?? '' );
			$ffc_identity_right = (string) ( $ffc_identity_item[ IdentityQueue::COLUMN_RIGHT ] ?? '' );

			// MECHANICAL IS THE ONLY TIER WITH AN ORDER TO SHOW.
			//
			// `IdentityQueue::tiered()` sets `wrong` and `right` only where
			// exactly one identifier failed and every other one was READ --
			// so the pair, and the arrow between them, exist precisely when
			// the write has a direction. Everywhere else the hashes are a
			// set, and drawing an arrow over them would assert a decision
			// the check digits refused to make.
			$ffc_identity_directed = IdentityQueue::TIER_MECHANICAL === $ffc_identity_tier
				&& '' !== $ffc_identity_wrong
				&& '' !== $ffc_identity_right;
			?>
			<div class="ffc-identity-card">
				<div class="ffc-identity-card-main">
					<p class="ffc-identity-card-who">
						<?php if ( array() === $ffc_identity_who ) : ?>
							<span class="description"><?php esc_html_e( 'No account', 'ffcertificate' ); ?></span>
						<?php else : ?>
							<?php foreach ( $ffc_identity_who as $ffc_identity_account ) : ?>
								<?php // The NAME, then the number. A finding an operator has to act on is about a person, and `#4821` is not a person. ?>
								<a href="<?php echo esc_url( admin_url( 'user-edit.php?user_id=' . rawurlencode( (string) $ffc_identity_account ) ) ); ?>">
									<?php echo esc_html( IdentityResolutionLabels::account( $ffc_identity_account ) ); ?>
								</a>
							<?php endforeach; ?>
						<?php endif; ?>
					</p>

					<p class="ffc-identity-card-says">
						<?php if ( $ffc_identity_directed ) : ?>
							<?php
							printf(
								/* translators: %s: RF or CPF. */
								esc_html__( 'This account holds two %s. One fails its own check digit and the other is sound, so the right one is the one the account already has.', 'ffcertificate' ),
								esc_html( $ffc_identity_kind )
							);
							?>
						<?php elseif ( IdentityQueue::TIER_MAILBOX === $ffc_identity_tier ) : ?>
							<?php
							// THE COUNT IS THE EVIDENCE HERE, NOT A DETAIL.
							//
							// The reading turns on how many numbers one
							// address carries: at two it is a person who
							// typed twice, and at nine it is not. So the
							// sentence leads with the number rather than
							// mentioning it, and says what the screen cannot
							// decide instead of what an operator should do.
							printf(
								/* translators: 1: how many identifiers the account holds. 2: RF or CPF. */
								esc_html__( 'This account holds %1$s %2$s under one address, and they are not variants of each other. One address on that many numbers does not identify one person, so this is a shared mailbox or an account submitting for other people — which of the two, only the account itself says.', 'ffcertificate' ),
								esc_html( number_format_i18n( count( $ffc_identity_which ) ) ),
								esc_html( $ffc_identity_kind )
							);
							?>
							<?php
							// THE CAUTION CAME UP FROM THE IDENTIFIERS, WHERE IT REPEATED (#1464).
							//
							// It is about the CARD -- this account may legitimately hold
							// what it holds -- so it belongs beside the sentence that
							// describes the account, not above each route. Its own
							// sentence rather than folded into the one above, which is a
							// translated string saying something else and would have to
							// be retranslated to carry this.
							?>
							<?php esc_html_e( 'Read what it submitted before either route: the records may legitimately be its own.', 'ffcertificate' ); ?>
						<?php else : ?>
							<?php
							printf(
								/* translators: 1: how many identifiers the account holds. 2: RF or CPF. */
								esc_html__( 'This account holds %1$s %2$s, and the check digits do not single one out — none fails, or more than one does. The right value comes from HR.', 'ffcertificate' ),
								esc_html( number_format_i18n( count( $ffc_identity_which ) ) ),
								esc_html( $ffc_identity_kind )
							);
							?>
						<?php endif; ?>
					</p>

					<?php
					// THE HASHES, IN THE ORDER THE WRITE READS THEM.
					//
					// Ordered rather than iterated where there is a
					// direction, so the chip that goes away is first and the
					// chip that survives is second -- which is what makes the
					// arrow mean the same thing as the button.
					$ffc_identity_chips = $ffc_identity_directed
						? array(
							$ffc_identity_wrong => IdentityConflictQuery::VERDICT_INVALID,
							$ffc_identity_right => IdentityConflictQuery::VERDICT_VALID,
						)
						: $ffc_identity_which;
					$ffc_identity_first = true;
					?>
					<div class="ffc-identity-card-hashes">
						<?php foreach ( $ffc_identity_chips as $ffc_identity_hash => $ffc_identity_verdict ) : ?>
							<?php if ( $ffc_identity_directed && ! $ffc_identity_first ) : ?>
								<?php // `aria-hidden`, because the sentence above already says which of the two survives and each chip carries its own verdict in words. ?>
								<span class="ffc-identity-card-arrow" aria-hidden="true">&rarr;</span>
							<?php endif; ?>
							<?php
							$ffc_identity_first = false;

							if ( IdentityConflictQuery::VERDICT_INVALID === $ffc_identity_verdict ) {
								$ffc_identity_tone = 'bad';
								/* translators: %s: RF or CPF. */
								$ffc_identity_said = sprintf( __( '%s · fails its check digit', 'ffcertificate' ), $ffc_identity_kind );
							} elseif ( IdentityConflictQuery::VERDICT_VALID === $ffc_identity_verdict ) {
								$ffc_identity_tone = 'ok';
								/* translators: %s: RF or CPF. */
								$ffc_identity_said = sprintf( __( '%s · well formed', 'ffcertificate' ), $ffc_identity_kind );
							} elseif ( IdentityConflictQuery::VERDICT_UNREADABLE === $ffc_identity_verdict ) {
								// NOT A FAILURE, AND IT MUST NOT LOOK LIKE ONE.
								// A value nobody could read is a value nothing
								// is known about -- painting it red would make
								// the screen assert the opposite.
								$ffc_identity_tone = 'unknown';
								/* translators: %s: RF or CPF. */
								$ffc_identity_said = sprintf( __( '%s · could not be read', 'ffcertificate' ), $ffc_identity_kind );
							} elseif ( IdentityConflictQuery::VERDICT_ABSENT === $ffc_identity_verdict ) {
								$ffc_identity_tone = 'unknown';
								/* translators: %s: RF or CPF. */
								$ffc_identity_said = sprintf( __( '%s · not stored where this can read', 'ffcertificate' ), $ffc_identity_kind );
							} else {
								$ffc_identity_tone = 'plain';
								$ffc_identity_said = $ffc_identity_kind;
							}
							?>
							<span class="ffc-identity-card-hash ffc-identity-card-hash-<?php echo esc_attr( $ffc_identity_tone ); ?>">
								<code><?php echo esc_html( substr( (string) $ffc_identity_hash, 0, IdentityQueue::DISPLAY_PREFIX ) ); ?></code>
								<span class="ffc-identity-card-said"><?php echo esc_html( $ffc_identity_said ); ?></span>
							</span>
						<?php endforeach; ?>
					</div>

					<?php
					// THE STORES, AND DELIBERATELY NO RECORD COUNT.
					//
					// The mockup's card ends with a count of records. There
					// is none to show here: `ALIAS_ROW_COUNT` is produced only
					// by the RF check-digit scan, and neither
					// `multiple_identities()` nor `shared_identities()`
					// selects it. Widening those queries to invent one was
					// weighed and refused -- the count would be per hash
					// rather than per finding, which is not the number the
					// sentence above would imply. The stores are what say how
					// far the write reaches, and they are read rather than
					// derived.
					$ffc_identity_where = IdentityResolutionLabels::store_list( $ffc_identity_item[ IdentityConflictQuery::COLUMN_STORES ] ?? '' );
					?>
					<?php if ( '' !== $ffc_identity_where ) : ?>
						<p class="ffc-identity-card-where">
							<?php
							printf(
								/* translators: %s: the stores holding the records, comma separated. */
								esc_html__( 'Appears in %s', 'ffcertificate' ),
								esc_html( $ffc_identity_where )
							);
							?>
						</p>
					<?php endif; ?>
				</div>
				<div class="ffc-identity-card-act">
					<?php if ( IdentityQueue::TIER_MECHANICAL === $ffc_identity_tier ) : ?>
						<?php
						$ffc_identity_wrong = (string) ( $ffc_identity_item[ IdentityQueue::COLUMN_WRONG ] ?? '' );
						$ffc_identity_right = (string) ( $ffc_identity_item[ IdentityQueue::COLUMN_RIGHT ] ?? '' );
						?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( IdentityResolutionPage::CONSOLIDATE_NONCE . $ffc_identity_wrong ); ?>
							<input type="hidden" name="action" value="<?php echo esc_attr( IdentityResolutionPage::CONSOLIDATE_ACTION ); ?>">
							<input type="hidden" name="ffc_key" value="<?php echo esc_attr( (string) ( $ffc_identity_item[ IdentityQueue::COLUMN_KEY ] ?? '' ) ); ?>">
							<input type="hidden" name="ffc_subject" value="<?php echo esc_attr( $ffc_identity_wrong ); ?>">
							<?php
							// TWO HASHES AND NO VALUE.
							//
							// The number to write is the account's sound
							// identifier, which the service reads in
							// memory. Posting the value instead would put
							// a stored RF or CPF through the browser for
							// no reason -- and there is nothing here an
							// operator needs to read, which is what makes
							// this one click rather than a question.
							?>
							<input type="hidden" name="ffc_target" value="<?php echo esc_attr( $ffc_identity_right ); ?>">
							<input type="hidden" name="ffc_field" value="<?php echo esc_attr( str_replace( '_hash', '', (string) ( $ffc_identity_item['identifier_column'] ?? '' ) ) ); ?>">
							<?php
							// PRIMARY, BECAUSE THIS CARD HAS ONE VERB.
							//
							// The whole tier is "the account already holds the
							// right number" -- there is nothing to type and no
							// second path, so the only question is whether to
							// act. The decision tier below is deliberately the
							// opposite: two peers, neither promoted, because
							// promoting one of two destinations is the screen
							// answering a question it is asking.
							?>
							<?php
							AdminUI::render_button(
								array(
									'label'    => __( 'Consolidate', 'ffcertificate' ),
									'category' => 'primary',
									'icon'     => 'merge',
									'type'     => 'submit',
								)
							);
							?>
							<?php $ffc_identity_ack( $ffc_identity_wrong ); ?>
						</form>
						<?php
						// THE SHARED MAILBOX GETS THE SAME TWO VERBS, AND NOT THE THIRD.
						//
						// #1368 withheld all three here, for a harm it named
						// precisely: verbs `that would write one person's number
						// onto another person's records`. That describes CONSOLIDATE,
						// which rewrites one identifier into another, and it is why
						// consolidate stays absent. It does not describe these two:
						// a move relocates records without touching the identifier,
						// and a split creates an account nobody else uses. The sweep
						// removed three verbs for a reason that justified one.
						//
						// The view's own refusal argued something different again --
						// that splitting `would detach records from an account that
						// may legitimately hold them`. That is a sound CAUTION and
						// was doing duty as a prohibition: the same sentence told
						// the operator to `decide with HR`, and then the screen had
						// nowhere to put the answer. What makes the decision
						// deliberate is the address the split makes them type and
						// the acknowledgement the move makes them tick, not the
						// absence of a button.
						//
						// `IdentityMailboxVerbsTest` holds the shape: these two and
						// never consolidate.
						?>
					<?php elseif ( IdentityQueue::TIER_DECISION === $ffc_identity_tier || IdentityQueue::TIER_MAILBOX === $ffc_identity_tier ) : ?>
						<?php
						$ffc_identity_field = str_replace( '_hash', '', (string) ( $ffc_identity_item['identifier_column'] ?? '' ) );
						?>
						<?php
						// ONE GROUP PER IDENTIFIER, BECAUSE THE COLUMN HOLDS
						// TWO VERBS PER IDENTIFIER AND SHOWED NEITHER WHOSE.
						//
						// This tier is an account holding several numbers, so
						// the column renders MOVE and SPLIT once each per
						// number -- eight controls for two numbers, with the
						// only clue to which belonged to which being the hash
						// inside one button's label. The heading says it
						// instead, and the sentence that explains the two
						// verbs sits with them rather than in a paragraph at
						// the foot of the panel.
						?>
						<?php foreach ( array_keys( $ffc_identity_which ) as $ffc_identity_move ) : ?>
							<div class="ffc-identity-card-verb">
								<p class="ffc-identity-card-verb-head">
									<code><?php echo esc_html( substr( (string) $ffc_identity_move, 0, IdentityQueue::DISPLAY_PREFIX ) ); ?></code>
									<span class="ffc-identity-card-said"><?php echo esc_html( $ffc_identity_kind ); ?></span>
								</p>
								<?php
								// WHOSE NUMBER THIS IS, FROM THE RECORDS (#1684).
								//
								// A hash prefix tells the operator nothing they can
								// take to HR, and on a shared mailbox every number
								// sits on one login, so the account's name is the
								// one name that does NOT tell them apart. The rows
								// carrying each number hold the person's own name --
								// inside the submission's answers on this panel --
								// and that is what each group now says.
								$ffc_identity_number_names = $ffc_identity_names( (string) $ffc_identity_move, $ffc_identity_field );
								?>
								<p class="ffc-identity-card-names">
									<?php if ( array() !== $ffc_identity_number_names['names'] ) : ?>
										<strong><?php echo esc_html( implode( ', ', array_map( 'strval', $ffc_identity_number_names['names'] ) ) ); ?></strong>
										<?php if ( ! empty( $ffc_identity_number_names['capped'] ) ) : ?>
											<span class="description"><?php esc_html_e( 'and more', 'ffcertificate' ); ?></span>
										<?php endif; ?>
									<?php elseif ( empty( $ffc_identity_number_names['readable'] ) ) : ?>
										<span class="description"><?php esc_html_e( 'No store that records a name is installed, so this was not looked up.', 'ffcertificate' ); ?></span>
									<?php else : ?>
										<span class="description"><?php esc_html_e( 'No name recorded beside these rows.', 'ffcertificate' ); ?></span>
									<?php endif; ?>
								</p>
							<?php
							// TWO ROUTES TO ONE DESTINATION, EACH NAMED -- NOT A SENTENCE ABOUT BOTH (#1464).
							//
							// What stood here was `Move it to the account it belongs to,
							// or split it onto an account of its own`, printed above every
							// identifier of the account -- so a card with four numbers said
							// it four times, as if it were instructions for operating two
							// forms.
							//
							// The origin is one and the destination is one; where the origin
							// carries several records the destination is decided one
							// identifier at a time. So these are not two destinations
							// competing -- they are the two ways of reaching the single one,
							// and naming each route on the control that takes it is what the
							// repeated sentence was failing to do.
							//
							// The shared-mailbox caution left with it and did not disappear:
							// it is about the CARD, not about either route, so it sits once,
							// beside the sentence that describes the account.
							?>
							<p class="ffc-identity-card-route"><?php esc_html_e( 'To an account that already exists', 'ffcertificate' ); ?></p>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ffc-set-mb-2xs"
								id="ffc-relink-form-<?php echo esc_attr( (string) $ffc_identity_move ); ?>">
								<?php wp_nonce_field( IdentityResolutionPage::RELINK_NONCE . (string) $ffc_identity_move ); ?>
								<input type="hidden" name="action" value="<?php echo esc_attr( IdentityResolutionPage::RELINK_ACTION ); ?>">
								<input type="hidden" name="ffc_key" value="<?php echo esc_attr( (string) ( $ffc_identity_item[ IdentityQueue::COLUMN_KEY ] ?? '' ) ); ?>">
							<input type="hidden" name="ffc_subject" value="<?php echo esc_attr( (string) $ffc_identity_move ); ?>">
								<input type="hidden" name="ffc_field" value="<?php echo esc_attr( $ffc_identity_field ); ?>">
								<label class="screen-reader-text" for="ffc-relink-<?php echo esc_attr( (string) $ffc_identity_move ); ?>">
									<?php
									printf(
										/* translators: %s: the identifier's hash prefix. */
										esc_html__( 'Account to move the records carrying %s to', 'ffcertificate' ),
										esc_html( substr( (string) $ffc_identity_move, 0, IdentityQueue::DISPLAY_PREFIX ) )
									);
									?>
								</label>
								<?php
								// A numeric account id, typed — the operator
								// arrives from the audit export, which names
								// accounts by id and links to `user-edit.php`.
								//
								// `required` because each row carries its OWN
								// form: an empty field cannot mean "leave this
								// alone" when submitting is already the way to
								// act on one row, and a cleared number field
								// posts the empty string that `absint()` reads
								// as zero (#1114).
								?>
								<input type="number" inputmode="numeric" min="1" step="1" size="6" required
									id="ffc-relink-<?php echo esc_attr( (string) $ffc_identity_move ); ?>"
									name="ffc_account" placeholder="<?php esc_attr_e( 'Account #', 'ffcertificate' ); ?>">
								<?php
								// THE NUMBER FIELD STAYS, AND THAT IS THE POINT.
								//
								// The dialog writes into it rather than
								// replacing it, so the form posts the same
								// thing it always did and a screen without
								// JavaScript keeps the verb it had. What the
								// dialog adds is knowing the answer before
								// committing, which is not the same as being
								// the only way to give one.
								?>
								<?php
								AdminUI::render_button(
									array(
										'label' => __( 'Search…', 'ffcertificate' ),
										'icon'  => 'search',
										'class' => 'ffc-identity-find',
										'attrs' => array(
											'data-ffc-subject' => (string) $ffc_identity_move,
											'data-ffc-field'   => $ffc_identity_field,
											'data-ffc-input'   => 'ffc-relink-' . (string) $ffc_identity_move,
											'data-ffc-split'   => 'ffc-split-form-' . (string) $ffc_identity_move,
											'data-ffc-submit'  => 'ffc-relink-go-' . (string) $ffc_identity_move,
										),
									)
								);
								?>
								<?php
								// THE HASH LEAVES THE LABEL AND STAYS IN THE NAME.
								//
								// It was in the label because nothing else on
								// the card said WHICH number a verb acted on --
								// and then #1407 gave each identifier its own
								// group with the hash in the heading directly
								// above, which made the label the second place
								// it was written.
								//
								// MEASURED, BECAUSE THE OBVIOUS REASON IS NOT
								// THE REAL ONE. This looked like it would fix
								// two groups wrapping to different heights; it
								// does not, and could not -- every hash is
								// truncated to the same length, so the two
								// labels were always the same width and the
								// groups always matched. What the shorter label
								// actually buys is a WRAP ROW, and only in a
								// band: rendered in Chromium at 1920 / 1600 /
								// 1440 the card is 153px either way, at 1100 it
								// is 211px with the hash and 171 without, and at
								// 960 and below it is 211 either way. One row
								// of twelve screens' worth of width.
								//
								// What it cannot simply lose is the DISTINCTION:
								// a screen reader announcing two bare "Move"
								// buttons gives an operator no way to tell them
								// apart, and a heading two elements away is not
								// part of either name. So the hash moves into
								// the name rather than out of it.
								?>
								<?php if ( IdentityQueue::TIER_MAILBOX === $ffc_identity_tier ) : ?>
									<?php
									// THE ACKNOWLEDGEMENT IS ON THE MOVE, NOT ON THE SPLIT.
									//
									// It sits on the verb that cannot be undone. After a
									// split the records are alone on an account nobody else
									// uses, so a wrong split is still a group somebody can
									// move onward; after a move they are mixed with another
									// person's and telling the two apart stops being
									// possible from the data -- the same reason the merge
									// refuses a disagreement rather than warning about one.
									//
									// The split needs none: the address it makes the
									// operator type is already its deliberateness gate,
									// which is what `IdentitySplit`'s own docblock argues.
									//
									// `required` is safe although the form can be barred,
									// because it is barred by `disabled` and a disabled
									// control is exempt from constraint validation -- the
									// #1114 rule, relied on here rather than re-derived.
									?>
									<?php
									// A CLASS, BECAUSE THE SHEET IT BELONGS IN ALREADY EXISTS.
									//
									// #1461 shipped this label bare, on a comment claiming
									// that not one `.ffc-identity-` rule existed anywhere.
									// There are 152 of them, in `ffc-admin.css`, and they
									// were there the whole time -- the search that said
									// otherwise had failed rather than found nothing, and
									// an error read as a clean result is the one mistake
									// this screen's guards exist to make impossible.
									//
									// Bare, the label had no alignment and no measure: the
									// checkbox sat on the first line and the sentence ran
									// the full width of the verb column, crossing the
									// button beneath it. Two lines of CSS fix it, next to
									// the rules that already size this column.
									?>
									<label class="ffc-identity-ack">
										<input type="checkbox" required name="ffc_acknowledged" value="1">
										<?php esc_html_e( 'HR confirmed these records are this person’s, not the shared account’s.', 'ffcertificate' ); ?>
									</label>
								<?php endif; ?>
								<?php // By hand with the family's classes: the label carries a screen-reader-only prefix, more than a label (#1904). ?>
								<button type="submit" class="ffc-btn ffc-btn-neutral ffc-icon-move"
									id="ffc-relink-go-<?php echo esc_attr( (string) $ffc_identity_move ); ?>">
									<?php esc_html_e( 'Move', 'ffcertificate' ); ?>
									<span class="screen-reader-text">
										<?php echo esc_html( substr( (string) $ffc_identity_move, 0, IdentityQueue::DISPLAY_PREFIX ) ); ?>
									</span>
								</button>
								<span class="ffc-identity-chosen" id="ffc-relink-chosen-<?php echo esc_attr( (string) $ffc_identity_move ); ?>" hidden></span>
								<?php if ( IdentityQueue::TIER_MAILBOX === $ffc_identity_tier ) : ?>
									<?php // Shown by the address field, which bars this verb rather than the other way round -- see the split form below for why the precedence is inverted here. ?>
									<span class="ffc-identity-move-barred description" hidden>
										<?php esc_html_e( 'An address for a new account is typed, so splitting takes precedence: a split can still be corrected afterwards and a move cannot. Clear the address to move instead.', 'ffcertificate' ); ?>
									</span>
								<?php endif; ?>
							</form>
							<?php // Only the split goes when the capability is absent: moving is the other verb on this identifier and stays available. ?>
							<?php if ( $ffc_identity_may_split ) : ?>
							<p class="ffc-identity-card-route"><?php esc_html_e( 'Or to a new account', 'ffcertificate' ); ?></p>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ffc-set-mb-xs"
								id="ffc-split-form-<?php echo esc_attr( (string) $ffc_identity_move ); ?>">
								<?php wp_nonce_field( IdentityResolutionPage::SPLIT_NONCE . (string) $ffc_identity_move ); ?>
								<input type="hidden" name="action" value="<?php echo esc_attr( IdentityResolutionPage::SPLIT_ACTION ); ?>">
								<input type="hidden" name="ffc_key" value="<?php echo esc_attr( (string) ( $ffc_identity_item[ IdentityQueue::COLUMN_KEY ] ?? '' ) ); ?>">
							<input type="hidden" name="ffc_subject" value="<?php echo esc_attr( (string) $ffc_identity_move ); ?>">
								<input type="hidden" name="ffc_field" value="<?php echo esc_attr( $ffc_identity_field ); ?>">
								<label class="screen-reader-text" for="ffc-split-<?php echo esc_attr( (string) $ffc_identity_move ); ?>">
									<?php
									printf(
										/* translators: %s: the identifier's hash prefix. */
										esc_html__( 'E-mail address for the new account holding %s', 'ffcertificate' ),
										esc_html( substr( (string) $ffc_identity_move, 0, IdentityQueue::DISPLAY_PREFIX ) )
									);
									?>
								</label>
								<?php
								// THE ADDRESS IS DERIVED WHERE THERE IS ONE TO INHERIT (#1480).
								//
								// This used to be typed always, and the reason was
								// measured: every production finding reported both
								// identifiers sharing the address the existing account
								// already uses, so there was nothing to inherit. Still
								// nearly true -- 37 of 38 on the 2026-09-26 audit -- and
								// it does not describe the 38th.
								//
								// Where the finding's own verdict says the addresses are
								// DISTINCT, each identifier has its own, and asking
								// somebody to type an address they can already see is
								// where a typo enters. So it is proposed, pre-filled and
								// still editable, and the operator confirms a derived
								// value rather than filling a blank.
								//
								// Asked only on that verdict, which is the criterion and
								// not an optimisation: identifier plus address is the pair
								// of discordant elements that makes these records somebody
								// else's. The name is shown as EVIDENCE beside it and is
								// never part of the test -- two people share a name often,
								// and one person's name is spelled two ways across two
								// submissions, so a differing name proves nothing either
								// way. It is here for the operator's judgement.
								$ffc_identity_derived = IdentityConflictQuery::VERDICT_DISTINCT_EMAILS
									=== (string) ( $ffc_identity_item[ IdentityConflictQuery::COLUMN_EMAIL_VERDICT ] ?? '' )
										? $ffc_identity_proposal( (string) $ffc_identity_move, $ffc_identity_field )
										: array(
											'email'  => '',
											'reason' => '',
											'names'  => array(),
										);
								?>
								<?php
								// ON THE SHARED MAILBOX THE PRECEDENCE IS INVERTED, DELIBERATELY.
								//
								// Everywhere else, choosing a destination bars the split:
								// two destinations for one set of records is a mistake the
								// form should not express, and which half yields is
								// arbitrary there. Here it is not arbitrary. A split leaves
								// the records alone on a fresh account, so a wrong one is
								// still correctable; a move mixes them into somebody else's
								// and the data can no longer separate them. So the address
								// bars the move, and `data-ffc-prefer-split` is what tells
								// the search dialog to leave this split alone when a
								// destination is picked.
								//
								// The capability edge closes itself: this whole form is
								// inside `if ( $ffc_identity_may_split )`, so an operator
								// who cannot split has no address field, nothing bars the
								// move, and the precedence cannot strand them.
								?>
								<input type="email" size="22" required
									id="ffc-split-<?php echo esc_attr( (string) $ffc_identity_move ); ?>"
									value="<?php echo esc_attr( (string) $ffc_identity_derived['email'] ); ?>"
									<?php if ( IdentityQueue::TIER_MAILBOX === $ffc_identity_tier ) : ?>
									data-ffc-prefer-split="1"
									data-ffc-move="ffc-relink-form-<?php echo esc_attr( (string) $ffc_identity_move ); ?>"
									data-ffc-move-submit="ffc-relink-go-<?php echo esc_attr( (string) $ffc_identity_move ); ?>"
									<?php endif; ?>
									name="ffc_email" placeholder="<?php esc_attr_e( 'New account e-mail', 'ffcertificate' ); ?>">
								<?php
								AdminUI::render_button(
									array(
										'label' => __( 'Split off', 'ffcertificate' ),
										'icon'  => 'split',
										'type'  => 'submit',
									)
								);
								?>
								<?php
								// Printed disabled-free and hidden; the dialog
								// shows it and disables the fields above when a
								// destination is chosen, because two destinations
								// for one set of records is a mistake the form
								// should not be able to express.
								?>
								<span class="ffc-identity-split-barred description" hidden>
									<?php esc_html_e( 'A destination account is chosen, so splitting onto a new one is not available. Clear the destination to split instead.', 'ffcertificate' ); ?>
								</span>
								<?php if ( '' !== (string) $ffc_identity_derived['email'] ) : ?>
									<p class="description">
										<?php
										esc_html_e( 'That address is the one these records carry, filled in for you — check it and change it if HR says otherwise.', 'ffcertificate' );

										if ( array() !== $ffc_identity_derived['names'] ) {
											echo ' ';
											printf(
												/* translators: %s: the names on the records, comma separated. */
												esc_html__( 'They are recorded under: %s.', 'ffcertificate' ),
												esc_html( implode( ', ', $ffc_identity_derived['names'] ) )
											);
										}
										?>
									</p>
								<?php elseif ( 'several' === (string) $ffc_identity_derived['reason'] ) : ?>
									<p class="description">
										<?php esc_html_e( 'These records carry more than one address, so none of them can be the new account\'s — two values do not say which. Confirm with HR which person these records belong to.', 'ffcertificate' ); ?>
									</p>
								<?php elseif ( 'taken' === (string) $ffc_identity_derived['reason'] ) : ?>
									<p class="description">
										<?php esc_html_e( 'The address these records carry already belongs to an account, so it cannot open a new one — move the records to that account instead of splitting.', 'ffcertificate' ); ?>
									</p>
								<?php elseif ( 'unreadable' === (string) $ffc_identity_derived['reason'] ) : ?>
									<p class="description">
										<?php esc_html_e( 'These records carry an address this could not read, so nothing was filled in. That is not the same as carrying none — confirm it with HR rather than assuming there is no address.', 'ffcertificate' ); ?>
									</p>
								<?php endif; ?>
							</form>
							<?php endif; ?>
							</div>
						<?php endforeach; ?>
					<?php else : ?>
						<span class="description">
							<?php esc_html_e( 'Open the account — this one is not decided here.', 'ffcertificate' ); ?>
						</span>
					<?php endif; ?>
			<?php
					// ACCEPTING A FINDING NOBODY CAN RESOLVE (#1532).
					//
					// The queue is a live scan with no memory, which is what keeps it honest --
					// and wrong for one population: a stored number the person never supplied,
					// which HR cannot trace to anybody. The correction panel asks for a value
					// nobody has, so the finding returns forever and the bottom of the list
					// stops being read.
					//
					// IT IS NOT A RESOLUTION. The number still fails its check digit, the
					// records are still behind it, and a merge or a move over it still refuses
					// -- those refusals name this panel instead of the corrections one, which
					// is what stops #1523 from returning. Nothing here is counted as resolved.
					//
					// Which tiers may be accepted is `IdentityQueuePanels::ACCEPTABLE`, with the
					// reason the other two are not; a literal list here would drift from it.
					//
					// `ffc_identity_accept_*` AND NOT `ffc_identity_this_*`: the outer panel
					// loop already owns that name for the PANEL's tier, and PHP does not scope a
					// `foreach`. The first version of this block reused it and clobbered the
					// panel's value for the mailbox branch further down -- which is the trap the
					// `ffc_identity_person_names` comment in the other card already records.
					$ffc_identity_accept_tier = (string) ( $ffc_identity_item[ IdentityQueue::COLUMN_TIER ] ?? '' );
					$ffc_identity_accept_subj = (string) ( $ffc_identity_item['subject'] ?? '' );
					$ffc_identity_accept_fld  = str_replace( '_hash', '', (string) ( $ffc_identity_item['identifier_column'] ?? '' ) );

			if ( '' !== $ffc_identity_accept_subj && in_array( $ffc_identity_accept_tier, IdentityQueuePanels::ACCEPTABLE, true ) ) :
				?>
					<details class="ffc-identity-accept">
					<summary><?php esc_html_e( 'Nobody can resolve this', 'ffcertificate' ); ?></summary>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( IdentityResolutionPage::ACCEPT_NONCE . $ffc_identity_accept_subj ); ?>
					<input type="hidden" name="action" value="<?php echo esc_attr( IdentityResolutionPage::ACCEPT_ACTION ); ?>">
					<input type="hidden" name="ffc_check" value="<?php echo esc_attr( (string) ( $ffc_identity_item[ IdentityQueue::COLUMN_CHECK ] ?? '' ) ); ?>">
					<input type="hidden" name="ffc_field" value="<?php echo esc_attr( $ffc_identity_accept_fld ); ?>">
					<input type="hidden" name="ffc_subject" value="<?php echo esc_attr( $ffc_identity_accept_subj ); ?>">
					<input type="hidden" name="ffc_tier" value="<?php echo esc_attr( $ffc_identity_accept_tier ); ?>">
					<?php // The held list drops the finding through `report()`, the same way every verb here does. ?>
					<input type="hidden" name="ffc_key" value="<?php echo esc_attr( (string) ( $ffc_identity_item[ IdentityQueue::COLUMN_KEY ] ?? '' ) ); ?>">
					<p class="description">
					<?php esc_html_e( 'This takes the finding out of the queue and lists it under "Accepted — no resolution possible", where it can be put back. It does not change any stored value, and a merge or a move blocked by this number stays blocked.', 'ffcertificate' ); ?>
					</p>
					<label>
					<?php esc_html_e( 'Why it cannot be resolved', 'ffcertificate' ); ?>
					<select name="ffc_reason" required>
					<option value=""><?php esc_html_e( 'Choose a reason', 'ffcertificate' ); ?></option>
					<option value="<?php echo esc_attr( IdentityAcceptance::REASON_NEVER_SUPPLIED ); ?>"><?php esc_html_e( 'The person never supplied this number', 'ffcertificate' ); ?></option>
					<option value="<?php echo esc_attr( IdentityAcceptance::REASON_UNIDENTIFIABLE ); ?>"><?php esc_html_e( 'HR cannot say whose number this is', 'ffcertificate' ); ?></option>
					</select>
					</label>
					<p>
					<?php
					AdminUI::render_button(
						array(
							'label'    => __( 'Accept as unresolvable', 'ffcertificate' ),
							'category' => 'caution',
							'icon'     => 'flag',
							'type'     => 'submit',
						)
					);
					?>
					</p>
					</form>
					</details>
					<?php endif; ?>
				</div>
			</div>
		<?php endforeach; ?>
		</div>
		<?php
		// WHAT SURVIVED OF THE FOUR-SENTENCE PARAGRAPH, AND WHY ONLY THIS (#1464).
		//
		// It explained all three verbs in the abstract, under every panel that
		// offers any -- three tiers, so up to three copies of the same four
		// sentences on one page. Three of those sentences described what a verb
		// does, which each control now says about the case in front of the
		// operator, in a line attached to itself.
		//
		// This one is the exception because it is a property of the SCREEN and
		// not of a verb: every write here is one transaction and none of them
		// prints a stored number. A card cannot claim that about the others,
		// so it stays, once.
		?>
		<p class="description">
			<?php esc_html_e( 'Every action on this screen runs as a single transaction, rolled back whole if any part of it refuses, and none of them shows a stored number.', 'ffcertificate' ); ?>
		</p>
		<?php if ( IdentityQueue::TIER_MAILBOX === $ffc_identity_this_tier ) : ?>
			<?php
			// The absence of a verb cannot be explained by a control that is
			// not there, so this half stays at the panel. The precedence left:
			// the card shows it, by promoting the verb that wins and barring
			// the other, which is more legible than a sentence about it.
			?>
			<p class="description">
				<?php esc_html_e( 'Consolidating is not offered on this panel: it rewrites one identifier into another, and where the identifiers belong to different people that writes one person’s number onto another person’s records. Moving and splitting both leave every number as it is.', 'ffcertificate' ); ?>
			</p>
		<?php endif; ?>
		<?php $ffc_identity_foot(); ?>
	<?php endforeach; ?>
