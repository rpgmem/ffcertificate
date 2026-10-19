<?php
/**
 * Template: Identity Resolution — destination dialog.
 *
 * The dialog that picks the account a move lands on.
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

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
	<?php
	// THE DIALOG'S FIXED PROSE LIVES HERE, NOT IN THE SCRIPT.
	//
	// One shell, reused by whichever move form opened it, so the sentences
	// are escaped at the output point and reach the catalogue as ordinary
	// source strings. The script fills the two regions that carry data and
	// otherwise only shows and hides this.
	//
	// It is inside the `wrap`, which is what gives its rules the page anchor
	// `ffc-page-identities` (`CLAUDE.md`, "Page scope on the admin wrap").
	?>
	<div class="ffc-identity-dialog" id="ffc-identity-dialog" role="dialog" aria-modal="true" aria-labelledby="ffc-identity-dialog-title" hidden>
		<div class="ffc-identity-dialog-backdrop" data-ffc-dialog-dismiss></div>
		<div class="ffc-identity-dialog-panel">
			<div class="ffc-identity-dialog-head">
				<div class="ffc-identity-dialog-heading">
					<h2 id="ffc-identity-dialog-title"><?php esc_html_e( 'Choose the destination account', 'ffcertificate' ); ?></h2>
					<p class="ffc-identity-dialog-sub" id="ffc-identity-dialog-sub"></p>
				</div>
				<?php
				\FreeFormCertificate\Core\Button::render(
					array(
						'label'     => __( 'Close', 'ffcertificate' ),
						'category'  => 'neutral',
						'icon'      => 'cross',
						'icon_only' => true,
						'class'     => 'ffc-identity-dialog-close',
						'attrs'     => array( 'data-ffc-dialog-dismiss' => true ),
					)
				);
				?>
			</div>
			<div class="ffc-identity-dialog-body">
				<?php
				// The two fixed sentences the script paints into this region
				// travel as data attributes rather than through
				// `wp_localize_script`, so every string in the dialog that
				// carries no runtime value is escaped at the output point and
				// reaches the catalogue from the same file as its neighbours.
				?>
				<div class="ffc-identity-dialog-suggestion" id="ffc-identity-dialog-suggestion"
					data-head="<?php esc_attr_e( 'Suggestion — an account already files one of these identifiers', 'ffcertificate' ); ?>"
					data-none="<?php esc_attr_e( 'No account files any identifier these records carry, so there is nothing to suggest. Search below.', 'ffcertificate' ); ?>"></div>
				<p class="ffc-identity-dialog-search">
					<label for="ffc-identity-dialog-q"><?php esc_html_e( 'Or search by name, e-mail or account number', 'ffcertificate' ); ?></label>
					<input type="search" id="ffc-identity-dialog-q" autocomplete="off" spellcheck="false">
				</p>
				<div class="ffc-identity-dialog-results" id="ffc-identity-dialog-results" aria-live="polite"
					data-truncated="<?php esc_attr_e( 'More accounts match than are shown. Narrow the search.', 'ffcertificate' ); ?>"></div>
				<p class="description ffc-identity-dialog-column">
					<?php esc_html_e( 'The badge on the right says whether the move would be accepted, before you confirm.', 'ffcertificate' ); ?>
				</p>
				<p class="ffc-identity-dialog-rule">
					<?php esc_html_e( 'This is the rule the service applies when writing: the two sides must already agree on one identifier, and where the destination holds none of that kind, it gains this one. A second identifier that disagrees refuses the whole move. Here the rule is read before the refusal rather than after it.', 'ffcertificate' ); ?>
				</p>
			</div>
			<div class="ffc-identity-dialog-foot">
				<?php
				AdminUI::render_button(
					array(
						'label'    => __( 'Select this account', 'ffcertificate' ),
						'category' => 'primary',
						'icon'     => 'user-check',
						'id'       => 'ffc-identity-dialog-confirm',
						'disabled' => true,
					)
				);
				AdminUI::render_button(
					array(
						'label' => __( 'Cancel', 'ffcertificate' ),
						'icon'  => 'cross',
						'attrs' => array(
							'data-ffc-dialog-dismiss' => true,
						),
					)
				);
				?>
				<span class="description"><?php esc_html_e( 'Selecting only chooses the destination. Writing asks for a confirmation afterwards.', 'ffcertificate' ); ?></span>
			</div>
		</div>
	</div>
