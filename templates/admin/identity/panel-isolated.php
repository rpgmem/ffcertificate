<?php
/**
 * Template: Identity Resolution — numbers to correct.
 *
 * Stored numbers that fail their own check digit, and the empty-queue
 * branches that share their condition chain.
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
use FreeFormCertificate\Maintenance\IdentityCheckDigitScan;
use FreeFormCertificate\Maintenance\IdentityConflictQuery;
use FreeFormCertificate\Maintenance\IdentityQueue;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
	<?php
	$ffc_identity_rows = array();

	if ( isset( $ffc_identity_screen->by_tier[ IdentityQueue::TIER_ISOLATED ] ) ) {
		$ffc_identity_panel = $ffc_identity_screen->by_tier[ IdentityQueue::TIER_ISOLATED ];
		$ffc_identity_rows  = IdentityResolutionScreen::shown( $ffc_identity_panel );

		$ffc_identity_head(
			$ffc_identity_panel,
			IdentityResolutionLabels::tier_label( IdentityQueue::TIER_ISOLATED ),
			__( 'A stored RF or CPF whose own check digit does not match, and which no action above corrects: the account holds only this one, the row belongs to a candidacy that carries no account until promotion, or the card naming it can only move, split or merge — none of which touches a number. The correct value comes from HR.', 'ffcertificate' )
		);

		// THE TAP IS STILL RUNNING, AND THIS PANEL NEVER SAID SO (#1500).
		//
		// The queue and the submission form disagree about what a valid RF is.
		// `IdentityRepair::well_formed()` is `validate_rf() &&
		// rf_check_digit_matches()`, so the `&&` makes this panel ALWAYS require
		// the digit. The form judges by `validate_rf()` alone, which requires it
		// only when `ffc_validate_rf_check_digit` is on -- off by default, and
		// the form's own refusal then reads "Invalid RF. Must contain only
		// numbers."
		//
		// So an operator can work this panel to zero and watch it refill, with
		// nothing on the screen explaining why. The sentence is the cheapest
		// thing that stops work undoing itself.
		//
		// THE TWO ARE NOT MEANT TO AGREE, and the fix is never to loosen this
		// panel. It judges values ALREADY STORED and a number that cannot be
		// anyone's is worth reporting whatever the intake policy is; the form
		// applies an administrator's policy about what to admit. The setting
		// exists so an institution whose RF scheme carries no check digit can
		// still use the plugin.
		//
		// MEASURED, NOT WARNED GENERICALLY: silent when the setting is on,
		// because then the sentence would be false -- a notice that renders
		// unconditionally is the same defect as a strip that always reads clean.
		if ( ! $ffc_identity_rf_gate ) {
			\FreeFormCertificate\Core\AdminNotice::callout(
				'warning',
				esc_html__( 'New wrong RFs can still arrive: the submission form currently accepts any seven digits, because the RF check-digit requirement is off. Correcting the values below does not close that door — Settings → General turns it on. It does not affect CPF, which is always checked on submission.', 'ffcertificate' )
			);
		}
	}
	?>

	<?php if ( array() === $ffc_identity_screen->panels ) : ?>
		<?php require FFC_PLUGIN_DIR . 'templates/admin/identity/empty-queue.php'; ?>
	<?php elseif ( array() !== $ffc_identity_rows ) : ?>
		<?php
		// THE SAME CARD AS THE ACCOUNT TIERS, OVER A FINDING OF A DIFFERENT
		// SHAPE (#1407 sprint 3).
		//
		// What this tier has and they do not is a COUNT: the check-digit scan
		// selects `ALIAS_ROW_COUNT` and the row ids per store, so the card can
		// say how far a correction reaches. The account tiers carry no such
		// number and a test forbids inventing one there -- the two cards are
		// deliberately not identical, and making them match is the temptation
		// that rule exists against.
		//
		// The FORM IS UNTOUCHED: same fields, same nonce, same action, same
		// `data-ffc-*` hooks the preflight binds to.
		?>
		<div class="ffc-identity-cards">
		<?php foreach ( $ffc_identity_rows as $ffc_identity_row ) : ?>
			<?php
			$ffc_identity_accounts = IdentityConflictQuery::parse_accounts(
				$ffc_identity_row[ IdentityConflictQuery::COLUMN_RELATED ] ?? ''
			);
			$ffc_identity_by_store = IdentityCheckDigitScan::parse_row_ids(
				$ffc_identity_row[ IdentityConflictQuery::COLUMN_ROW_IDS ] ?? ''
			);
			// NOT `$ffc_identity_who`: the shared panel already uses that
			// name for its pair of ACCOUNTS, and PHP does not scope a
			// `foreach`. Two loops in one file holding different things
			// under one name is how a blind edit lands in the wrong form.
			$ffc_identity_person_names = $ffc_identity_names(
				(string) ( $ffc_identity_row['subject'] ?? '' ),
				str_replace( '_hash', '', (string) ( $ffc_identity_row['identifier_column'] ?? 'rf_hash' ) )
			);
			$ffc_identity_kind         = strtoupper( str_replace( '_hash', '', (string) ( $ffc_identity_row['identifier_column'] ?? 'rf_hash' ) ) );
			?>
			<div class="ffc-identity-card">
				<div class="ffc-identity-card-main">
					<p class="ffc-identity-card-who">
						<?php
						// FROM THE RECORD, NEVER FROM AN ACCOUNT.
						//
						// This tier's whole shape is a failure no account
						// explains, so there is often no account to ask: a
						// recruitment candidacy carries no WordPress user
						// until it is promoted. The stores hold the name
						// themselves.
						//
						// Three outcomes, and the last two are NOT the same.
						// A name; no name recorded beside rows that were
						// read; or no store this can read being present at
						// all, which is not a result.
						?>
						<?php if ( array() !== $ffc_identity_person_names['names'] ) : ?>
							<?php echo esc_html( implode( ', ', array_map( 'strval', $ffc_identity_person_names['names'] ) ) ); ?>
							<?php if ( ! empty( $ffc_identity_person_names['capped'] ) ) : ?>
								<span class="description"><?php esc_html_e( 'and more', 'ffcertificate' ); ?></span>
							<?php endif; ?>
						<?php elseif ( empty( $ffc_identity_person_names['readable'] ) ) : ?>
							<span class="description">
								<?php esc_html_e( 'No store that records a name is installed, so this was not looked up.', 'ffcertificate' ); ?>
							</span>
						<?php else : ?>
							<span class="description">
								<?php esc_html_e( 'No name recorded beside these rows.', 'ffcertificate' ); ?>
							</span>
						<?php endif; ?>
					</p>

					<?php
					// NO SENTENCE ON THIS CARD, DELIBERATELY.
					//
					// The panel note above already says what this tier is, in
					// almost the same words -- and unlike the account tiers
					// there is nothing per-finding for a sentence to add: what
					// varies here is which accounts carry the value and how
					// many rows do, and both are shown as themselves below.
					// A card that repeats its own heading once per finding is
					// prose an operator learns to skip.
					?>
					<div class="ffc-identity-card-hashes">
						<span class="ffc-identity-card-hash ffc-identity-card-hash-bad">
							<code><?php echo esc_html( substr( (string) ( $ffc_identity_row['subject'] ?? '' ), 0, IdentityQueue::DISPLAY_PREFIX ) ); ?></code>
							<span class="ffc-identity-card-said">
								<?php
								printf(
									/* translators: %s: RF or CPF. */
									esc_html__( '%s · fails its check digit', 'ffcertificate' ),
									esc_html( $ffc_identity_kind )
								);
								?>
							</span>
						</span>
						<?php
						// THE ACCOUNTS ARE EVIDENCE HERE, NOT THE SUBJECT.
						//
						// A finding of this tier is about a stored VALUE. How
						// many logins happen to carry it is what decides
						// whether one corrected number can serve them all,
						// which is why it sits beside the hash rather than in
						// a column of its own.
						?>
						<?php if ( array() === $ffc_identity_accounts ) : ?>
							<span class="ffc-identity-card-said description"><?php esc_html_e( 'No account', 'ffcertificate' ); ?></span>
						<?php else : ?>
							<?php foreach ( $ffc_identity_accounts as $ffc_identity_account ) : ?>
								<a href="<?php echo esc_url( admin_url( 'user-edit.php?user_id=' . rawurlencode( (string) $ffc_identity_account ) ) ); ?>">
									<?php echo esc_html( IdentityResolutionLabels::account( $ffc_identity_account ) ); ?>
								</a>
							<?php endforeach; ?>
						<?php endif; ?>
					</div>

					<p class="ffc-identity-card-where">
						<?php
						// THE COUNT THIS TIER ACTUALLY HAS. `ALIAS_ROW_COUNT`
						// is selected by the check-digit scan, so it is read
						// rather than derived -- and it is per FINDING, which
						// is what makes it sayable in the same breath as the
						// stores.
						printf(
							/* translators: 1: the stores holding the records, comma separated. 2: how many records carry the value. */
							esc_html__( 'Appears in %1$s · %2$s records', 'ffcertificate' ),
							esc_html( IdentityResolutionLabels::store_list( $ffc_identity_row[ IdentityConflictQuery::COLUMN_STORES ] ?? '' ) ),
							esc_html( number_format_i18n( (int) ( $ffc_identity_row[ IdentityConflictQuery::ALIAS_ROW_COUNT ] ?? 0 ) ) )
						);
						?>
					</p>

					<?php
					// THE ROW IDS ARE THE ONLY HANDLE ON A RECORD WITH NO
					// ACCOUNT, so they stay -- and the truncation notice stays
					// with them, because a partial list of handles that does
					// not say it is partial is the `#1071` rule again.
					?>
					<p class="ffc-identity-card-rows">
						<?php foreach ( $ffc_identity_by_store as $ffc_identity_store => $ffc_identity_ids ) : ?>
							<span class="ffc-identity-card-rowset">
								<strong><?php echo esc_html( IdentityResolutionLabels::store_name( $ffc_identity_store ) ); ?></strong>
								<code><?php echo esc_html( implode( ', ', array_map( 'strval', $ffc_identity_ids ) ) ); ?></code>
							</span>
						<?php endforeach; ?>
						<?php if ( ! empty( $ffc_identity_row[ IdentityConflictQuery::COLUMN_ROW_IDS_TRUNCATED ] ) ) : ?>
							<span class="description"><?php esc_html_e( 'Too many rows to list.', 'ffcertificate' ); ?></span>
						<?php endif; ?>
					</p>
				</div>
				<div class="ffc-identity-card-act">
					<?php if ( count( $ffc_identity_accounts ) > 1 ) : ?>
						<span class="description">
							<?php esc_html_e( 'Names more than one account — the same wrong number was typed by more than one person, so one corrected value cannot serve it.', 'ffcertificate' ); ?>
						</span>
					<?php else : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( IdentityResolutionPage::REPAIR_NONCE . (string) ( $ffc_identity_row['subject'] ?? '' ) ); ?>
							<input type="hidden" name="action" value="<?php echo esc_attr( IdentityResolutionPage::REPAIR_ACTION ); ?>">
							<input type="hidden" name="ffc_key" value="<?php echo esc_attr( (string) ( $ffc_identity_row[ IdentityQueue::COLUMN_KEY ] ?? '' ) ); ?>">
							<input type="hidden" name="ffc_subject" value="<?php echo esc_attr( (string) ( $ffc_identity_row['subject'] ?? '' ) ); ?>">
							<?php
							// THE FORM HAS TO SAY WHICH IDENTIFIER, AND IT DID NOT (#1486).
							//
							// Without this `posted_field()` falls back to
							// `IdentityRepair::FIELD`, which is `rf`. Traced
							// through: a CPF finding would reach
							// `rows_for( $subject, 'rf_hash', … )`, match nothing
							// and answer `ffc_identity_repair_gone` -- "Nothing
							// carries that value any more". Not a wrong write, a
							// FALSE SENTENCE about a finding still sitting there.
							// It was unreachable only because the scan was
							// RF-only, which is what this change undoes.
							?>
							<input type="hidden" name="ffc_field" value="<?php echo esc_attr( str_replace( '_hash', '', (string) ( $ffc_identity_row['identifier_column'] ?? 'rf' ) ) ); ?>">
							<?php
							// `text` with `inputmode`, never `number`: these are
							// fixed-width identifiers, and a number input drops a
							// leading zero -- which `rf_normalized varchar(7)` says
							// is a digit, not formatting. That is also why the
							// screen is outside `RequiredNumericInputTest`'s scope.
							//
							// 7 TO 11 DIGITS, AS THE SHARED-PAIR FORM ALREADY HAS:
							// `[0-9]{7}` made an 11-digit CPF impossible to type,
							// and the browser would have refused the submit with a
							// message about a number the operator had read
							// correctly off the finding.
							?>
							<label class="screen-reader-text" for="ffc-rf-<?php echo esc_attr( (string) ( $ffc_identity_row['subject'] ?? '' ) ); ?>">
								<?php echo esc_html( IdentityResolutionLabels::corrected_label( (string) ( $ffc_identity_row['identifier_column'] ?? '' ) ) ); ?>
							</label>
							<input type="text" inputmode="numeric" pattern="[0-9]{7,11}" maxlength="11" size="12" required
								id="ffc-rf-<?php echo esc_attr( (string) ( $ffc_identity_row['subject'] ?? '' ) ); ?>"
								name="ffc_rf" placeholder="<?php esc_attr_e( 'The confirmed number', 'ffcertificate' ); ?>">
							<?php
							// THE FIELD IS NEVER PRE-FILLED AND NOTHING
							// STORED COMES BACK.
							//
							// The value travels browser -> server, which
							// is what a correction is. `Check` asks what
							// this number would do -- above all whether it
							// already belongs to somebody else, which is a
							// different finding rather than a failed
							// correction -- and the answer carries an
							// account, never an identifier.
							?>
							<?php
							$ffc_identity_subject = (string) ( $ffc_identity_row['subject'] ?? '' );
							AdminUI::render_button(
								array(
									'label' => __( 'Check', 'ffcertificate' ),
									'icon'  => 'file-check',
									'class' => 'ffc-identity-check',
									'attrs' => array(
										'data-ffc-subject' => $ffc_identity_subject,
										'data-ffc-field'   => str_replace( '_hash', '', (string) ( $ffc_identity_row['identifier_column'] ?? 'rf' ) ),
										'data-ffc-value'   => 'ffc-rf-' . $ffc_identity_subject,
										'data-ffc-verdict' => 'ffc-check-' . $ffc_identity_subject,
										'data-ffc-ack'     => 'ffc-ack-' . $ffc_identity_subject,
									),
								)
							);
							AdminUI::render_button(
								array(
									'label'    => __( 'Correct', 'ffcertificate' ),
									'category' => 'primary',
									'icon'     => 'edit',
									'type'     => 'submit',
								)
							);
							?>
							<?php $ffc_identity_ack( (string) ( $ffc_identity_row['subject'] ?? '' ), true ); ?>
							<div class="ffc-identity-verdict" id="ffc-check-<?php echo esc_attr( (string) ( $ffc_identity_row['subject'] ?? '' ) ); ?>"
								aria-live="polite"
								<?php /* translators: %s: how many records the correction would rewrite. */ ?>
								data-allowed="<?php esc_attr_e( 'This correction rewrites %s records.', 'ffcertificate' ); ?>"
								<?php /* translators: %s: how many records the correction would rewrite. */ ?>
								data-consolidates="<?php esc_attr_e( 'This correction rewrites %s records and consolidates them with this account\'s other record.', 'ffcertificate' ); ?>"
								<?php
								// TWO SENTENCES FOR WHAT WAS ONE (#1478), because
								// the two answers stopped being the same one.
								//
								// `data-shared` is the ALLOWED verdict that
								// produces a shared number: it says what will
								// happen and where the merge is then decided.
								// `data-holder` is the refusal that is left --
								// rows nobody owns, or more than one other
								// account -- and it no longer claims a
								// correction into another account's value is a
								// merge, because it is not one.
								?>
								<?php /* translators: 1: how many records the correction would rewrite. 2: the account's display name. 3: the account number. */ ?>
								data-shared="<?php esc_attr_e( 'This correction rewrites %1$s records, and the number you typed already belongs to %2$s (#%3$s). It is not a merge — no record moves — but afterwards both accounts hold the number, and it cannot be undone here. Confirm below, then decide the merge under "Two accounts, one number".', 'ffcertificate' ); ?>"
								<?php /* translators: 1: the account's display name. 2: the account number. */ ?>
								data-holder="<?php esc_attr_e( 'That number is already on records this cannot attribute to one other account — open %1$s (#%2$s) to see who is involved. Correcting into it would leave a claim on the number that no finding here would report.', 'ffcertificate' ); ?>"
								data-profile="<?php echo esc_attr( admin_url( 'user-edit.php?user_id=' ) ); ?>"
								data-open="<?php esc_attr_e( 'Open that account', 'ffcertificate' ); ?>"
								data-empty="<?php esc_attr_e( 'Enter the number HR confirmed first.', 'ffcertificate' ); ?>"
								data-failed="<?php esc_attr_e( 'The check could not be completed.', 'ffcertificate' ); ?>"></div>
						</form>
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
					$ffc_identity_accept_tier = (string) ( $ffc_identity_row[ IdentityQueue::COLUMN_TIER ] ?? '' );
					$ffc_identity_accept_subj = (string) ( $ffc_identity_row['subject'] ?? '' );
					$ffc_identity_accept_fld  = str_replace( '_hash', '', (string) ( $ffc_identity_row['identifier_column'] ?? '' ) );

			if ( '' !== $ffc_identity_accept_subj && in_array( $ffc_identity_accept_tier, IdentityQueuePanels::ACCEPTABLE, true ) ) :
				?>
					<details class="ffc-identity-accept">
					<summary><?php esc_html_e( 'Nobody can resolve this', 'ffcertificate' ); ?></summary>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( IdentityResolutionPage::ACCEPT_NONCE . $ffc_identity_accept_subj ); ?>
					<input type="hidden" name="action" value="<?php echo esc_attr( IdentityResolutionPage::ACCEPT_ACTION ); ?>">
					<input type="hidden" name="ffc_check" value="<?php echo esc_attr( (string) ( $ffc_identity_row[ IdentityQueue::COLUMN_CHECK ] ?? '' ) ); ?>">
					<input type="hidden" name="ffc_field" value="<?php echo esc_attr( $ffc_identity_accept_fld ); ?>">
					<input type="hidden" name="ffc_subject" value="<?php echo esc_attr( $ffc_identity_accept_subj ); ?>">
					<input type="hidden" name="ffc_tier" value="<?php echo esc_attr( $ffc_identity_accept_tier ); ?>">
					<?php // The held list drops the finding through `report()`, the same way every verb here does. ?>
					<input type="hidden" name="ffc_key" value="<?php echo esc_attr( (string) ( $ffc_identity_row[ IdentityQueue::COLUMN_KEY ] ?? '' ) ); ?>">
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
		<?php $ffc_identity_foot(); ?>
	<?php endif; ?>

	<p class="description ffc-set-mt-10">
		<?php esc_html_e( 'A row naming no account is ordinary rather than an error: a recruitment candidacy carries no WordPress user until it is promoted, so its row ids are the only handle on it. Confirm the corrected number with HR before entering it: the check digit says a number is wrong, never what the right one is.', 'ffcertificate' ); ?>
	</p>
