<?php
/**
 * Template: Identity Resolution — records with no account.
 *
 * Records carrying an identifier and no account (#1397).
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
use FreeFormCertificate\Maintenance\IdentityQueue;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
	<?php if ( $ffc_identity_may_split && array() !== $ffc_identity_orphans ) : ?>
		<?php
		// THE SAME CARD, WRITTEN OUT RATHER THAN THROUGH `$ffc_identity_head`.
		//
		// That closure's header carries a cursor and a `See the list` toggle,
		// and both are controls this panel cannot honour: it has no held
		// list to step through and is always the list. Calling it would print
		// a link that does nothing, which is worse than the four lines below.
		?>
		<div class="ffc-identity-panel">
		<div class="ffc-identity-panel-head">
			<span class="ffc-identity-panel-chip ffc-identity-chip-orphans"><?php esc_html_e( 'No account', 'ffcertificate' ); ?></span>
			<h2 class="ffc-identity-panel-title screen-reader-text"><?php esc_html_e( 'No account', 'ffcertificate' ); ?></h2>
			<p class="ffc-identity-panel-note description"><?php esc_html_e( 'Records carrying an identifier that belongs to no login at all.', 'ffcertificate' ); ?></p>
			<div class="ffc-identity-panel-nav">
				<span class="ffc-identity-panel-count">
					<?php
					printf(
						/* translators: %s: how many findings this category holds. */
						esc_html( _n( '%s finding', '%s findings', count( $ffc_identity_orphans ), 'ffcertificate' ) ),
						esc_html( number_format_i18n( count( $ffc_identity_orphans ) ) )
					);
					?>
				</span>
			</div>
		</div>
		<div class="ffc-identity-panel-body">
		<p class="description">
			<?php esc_html_e( 'These are usually candidacies that were never promoted, or records whose account was deleted. Linking one to an existing account uses the same rule as a move: the two must already agree on an identifier. Opening an account needs CPF, RF and an e-mail address together — an account opened from one identifier is one the resolver will fail to match on the next record carrying another.', 'ffcertificate' ); ?>
		</p>
		<?php if ( $ffc_identity_orphan_capped ) : ?>
			<?php \FreeFormCertificate\Core\AdminNotice::callout( 'warning', esc_html__( 'More orphaned records were found than are shown. Resolve these and read the queue again.', 'ffcertificate' ) ); ?>
		<?php endif; ?>
		<?php
		// THE SAME CARD AGAIN, OVER THE ONE FINDING THAT IS ABOUT AN ABSENCE
		// (#1407 sprint 3).
		//
		// What this tier states that no other does is WHAT THE RECORD LACKS.
		// Showing only what is present leaves the operator to work out the
		// gap, and the gap is the whole reason `Open the account` may refuse:
		// it needs CPF, RF and an address together.
		//
		// BOTH FORMS ARE UNTOUCHED -- link and open, same fields, same
		// nonces, same actions, same `data-ffc-*` hooks the search dialog
		// binds to.
		?>
		<div class="ffc-identity-cards">
		<?php foreach ( $ffc_identity_orphans as $ffc_identity_orphan ) : ?>
			<div class="ffc-identity-card">
				<div class="ffc-identity-card-main">
					<p class="ffc-identity-card-who">
						<?php if ( array() !== $ffc_identity_orphan['names'] ) : ?>
							<?php echo esc_html( implode( ', ', array_map( 'strval', $ffc_identity_orphan['names'] ) ) ); ?>
						<?php else : ?>
							<span class="description"><?php esc_html_e( 'No name recorded beside these rows.', 'ffcertificate' ); ?></span>
						<?php endif; ?>
					</p>

					<p class="ffc-identity-card-says">
						<?php if ( array() !== $ffc_identity_orphan['accounts'] ) : ?>
							<?php esc_html_e( 'These records carry an identifier that belongs to no login — but an account already files it, so this one is a link to make rather than an account to open.', 'ffcertificate' ); ?>
						<?php else : ?>
							<?php esc_html_e( 'These records carry an identifier that belongs to no login, and no account files it either — so this one needs an account opened rather than a link made.', 'ffcertificate' ); ?>
						<?php endif; ?>
					</p>

					<div class="ffc-identity-card-hashes">
						<span class="ffc-identity-card-hash ffc-identity-card-hash-plain">
							<code><?php echo esc_html( substr( (string) $ffc_identity_orphan['hash'], 0, IdentityQueue::DISPLAY_PREFIX ) ); ?></code>
							<span class="ffc-identity-card-said"><?php echo esc_html( strtoupper( (string) $ffc_identity_orphan['field'] ) ); ?></span>
						</span>
						<?php foreach ( $ffc_identity_orphan['accounts'] as $ffc_identity_orphan_account ) : ?>
							<a href="<?php echo esc_url( admin_url( 'user-edit.php?user_id=' . rawurlencode( (string) $ffc_identity_orphan_account ) ) ); ?>">
								<?php echo esc_html( IdentityResolutionLabels::account( $ffc_identity_orphan_account ) ); ?>
							</a>
						<?php endforeach; ?>
					</div>

					<?php
					// WHAT IT HAS AND WHAT IT LACKS, BOTH STATED, AND STILL A
					// LIST: three identifiers with a state each is a list, and
					// flattening it into a sentence would lose the per-item
					// colour that makes the gap findable at a glance.
					?>
					<ul class="ffc-identity-orphan-has">
						<?php foreach ( array( 'cpf', 'rf', 'email' ) as $ffc_identity_kind ) : ?>
							<li class="<?php echo empty( $ffc_identity_orphan['has'][ $ffc_identity_kind ] ) ? 'ffc-identity-orphan-missing' : 'ffc-identity-orphan-present'; ?>">
								<?php
								printf(
									/* translators: 1: CPF, RF or e-mail. 2: whether the record carries it. */
									esc_html__( '%1$s — %2$s', 'ffcertificate' ),
									esc_html( 'email' === $ffc_identity_kind ? __( 'E-mail', 'ffcertificate' ) : strtoupper( $ffc_identity_kind ) ),
									empty( $ffc_identity_orphan['has'][ $ffc_identity_kind ] )
										? esc_html__( 'missing', 'ffcertificate' )
										: esc_html__( 'recorded', 'ffcertificate' )
								);
								?>
							</li>
						<?php endforeach; ?>
					</ul>

					<?php // The row ids are the only handle on a record no account names, so they stay exactly as the table showed them. ?>
					<p class="ffc-identity-card-rows">
						<?php foreach ( $ffc_identity_orphan['stores'] as $ffc_identity_orphan_store => $ffc_identity_orphan_ids ) : ?>
							<span class="ffc-identity-card-rowset">
								<strong><?php echo esc_html( IdentityResolutionLabels::store_name( $ffc_identity_orphan_store ) ); ?></strong>
								<code><?php echo esc_html( implode( ', ', array_map( 'strval', $ffc_identity_orphan_ids ) ) ); ?></code>
							</span>
						<?php endforeach; ?>
					</p>
				</div>
				<div class="ffc-identity-card-act">
					<?php
					// The MOVE form, unchanged from the decision tier: an
					// orphan's rows name no account, so the agreement rule
					// reads them against the target exactly as it does
					// there, and the Sprint 3 dialog serves it unaltered.
					?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ffc-set-mb-2xs">
						<?php wp_nonce_field( IdentityResolutionPage::RELINK_NONCE . (string) $ffc_identity_orphan['hash'] ); ?>
						<input type="hidden" name="action" value="<?php echo esc_attr( IdentityResolutionPage::RELINK_ACTION ); ?>">
						<input type="hidden" name="ffc_subject" value="<?php echo esc_attr( (string) $ffc_identity_orphan['hash'] ); ?>">
						<input type="hidden" name="ffc_field" value="<?php echo esc_attr( (string) $ffc_identity_orphan['field'] ); ?>">
						<label class="screen-reader-text" for="ffc-orphan-account-<?php echo esc_attr( (string) $ffc_identity_orphan['hash'] ); ?>">
							<?php esc_html_e( 'Account to link these records to', 'ffcertificate' ); ?>
						</label>
						<input type="number" inputmode="numeric" min="1" step="1" size="6" required
							id="ffc-orphan-account-<?php echo esc_attr( (string) $ffc_identity_orphan['hash'] ); ?>"
							name="ffc_account" placeholder="<?php esc_attr_e( 'Account #', 'ffcertificate' ); ?>">
						<?php
						AdminUI::render_button(
							array(
								'label' => __( 'Search…', 'ffcertificate' ),
								'icon'  => 'search',
								'class' => 'ffc-identity-find',
								'attrs' => array(
									'data-ffc-subject' => (string) $ffc_identity_orphan['hash'],
									'data-ffc-field'   => (string) $ffc_identity_orphan['field'],
									'data-ffc-input'   => 'ffc-orphan-account-' . (string) $ffc_identity_orphan['hash'],
									'data-ffc-split'   => 'ffc-orphan-open-' . (string) $ffc_identity_orphan['hash'],
									'data-ffc-submit'  => 'ffc-orphan-link-' . (string) $ffc_identity_orphan['hash'],
								),
							)
						);
						?>
						<?php
						AdminUI::render_button(
							array(
								'label'    => __( 'Link', 'ffcertificate' ),
								'category' => 'primary',
								'icon'     => 'link',
								'type'     => 'submit',
								'id'       => 'ffc-orphan-link-' . (string) $ffc_identity_orphan['hash'],
							)
						);
						?>
						<span class="ffc-identity-chosen" id="ffc-orphan-chosen-<?php echo esc_attr( (string) $ffc_identity_orphan['hash'] ); ?>" hidden></span>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
						id="ffc-orphan-open-<?php echo esc_attr( (string) $ffc_identity_orphan['hash'] ); ?>">
						<?php wp_nonce_field( IdentityResolutionPage::ADOPT_NONCE . (string) $ffc_identity_orphan['hash'] ); ?>
						<input type="hidden" name="action" value="<?php echo esc_attr( IdentityResolutionPage::ADOPT_ACTION ); ?>">
						<input type="hidden" name="ffc_subject" value="<?php echo esc_attr( (string) $ffc_identity_orphan['hash'] ); ?>">
						<?php
						// ALL THREE ARE TYPED, INCLUDING THE ONE ON RECORD.
						//
						// The record's own value is stored and this screen
						// never shows a stored identifier -- so a field
						// pre-filled from it is not available, and one left
						// empty would be a field whose meaning depends on
						// something invisible. The operator types what they
						// confirmed; the service checks the two numbers
						// against their check digits before opening
						// anything.
						?>
						<label class="screen-reader-text" for="ffc-orphan-cpf-<?php echo esc_attr( (string) $ffc_identity_orphan['hash'] ); ?>">
							<?php esc_html_e( 'CPF', 'ffcertificate' ); ?>
						</label>
						<input type="text" inputmode="numeric" maxlength="14" size="14" required
							id="ffc-orphan-cpf-<?php echo esc_attr( (string) $ffc_identity_orphan['hash'] ); ?>"
							name="ffc_cpf" placeholder="<?php esc_attr_e( 'CPF', 'ffcertificate' ); ?>">
						<label class="screen-reader-text" for="ffc-orphan-rf-<?php echo esc_attr( (string) $ffc_identity_orphan['hash'] ); ?>">
							<?php esc_html_e( 'RF', 'ffcertificate' ); ?>
						</label>
						<input type="text" inputmode="numeric" pattern="[0-9]{7}" maxlength="7" size="8" required
							id="ffc-orphan-rf-<?php echo esc_attr( (string) $ffc_identity_orphan['hash'] ); ?>"
							name="ffc_rf" placeholder="<?php esc_attr_e( 'RF', 'ffcertificate' ); ?>">
						<label class="screen-reader-text" for="ffc-orphan-email-<?php echo esc_attr( (string) $ffc_identity_orphan['hash'] ); ?>">
							<?php esc_html_e( 'E-mail address', 'ffcertificate' ); ?>
						</label>
						<input type="email" size="22" required
							id="ffc-orphan-email-<?php echo esc_attr( (string) $ffc_identity_orphan['hash'] ); ?>"
							name="ffc_email" placeholder="<?php esc_attr_e( 'E-mail', 'ffcertificate' ); ?>">
						<?php
						AdminUI::render_button(
							array(
								'label'    => __( 'Open the account', 'ffcertificate' ),
								'category' => 'create',
								'icon'     => 'user-plus',
								'type'     => 'submit',
							)
						);
						?>
						<span class="ffc-identity-split-barred description" hidden>
							<?php esc_html_e( 'A destination account is chosen, so opening a new one is not available. Clear the destination to open one instead.', 'ffcertificate' ); ?>
						</span>
					</form>
				</div>
			</div>
		<?php endforeach; ?>
		</div>
		<p class="description">
			<?php esc_html_e( 'A record erased at the subject\'s request cannot appear here: the eraser clears the identifier hashes along with the ciphertexts, so nothing is left to match on. Adopting an orphan can never restore a link somebody asked to have removed.', 'ffcertificate' ); ?>
		</p>
		<?php $ffc_identity_foot(); ?>
	<?php endif; ?>
