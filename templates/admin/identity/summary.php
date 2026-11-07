<?php
/**
 * Template: Identity Resolution — the notices above the queue.
 *
 * The order-of-work advice and the cap notices. The counters are
 * `stats.php` since #2498, above the two columns.
 *
 * Included from `includes/admin/views/identity-resolution-page.php`, inside
 * `IdentityResolutionPage::render_page()`, so the page's `$ffc_identity_*`
 * locals are in scope. Moved verbatim (#1925): the page renders byte for
 * byte as it did when this was inline.
 *
 * @package FreeFormCertificate\Admin
 * @since   6.41.0
 */

use FreeFormCertificate\Admin\IdentityQueuePanels;
use FreeFormCertificate\Admin\IdentityResolutionPage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

	<?php
	// THE ADVICE THE COUNTS CANNOT GIVE (#1498).
	//
	// The stat cards above say how many findings each tier holds; the panel order
	// puts every correction above every tier that reads a number as evidence
	// about people (#1491). What neither says is WHY that order matters, and it
	// is not obvious: correcting a wrong number can make a finding below
	// DISAPPEAR rather than be resolved, because a shared number that fails its
	// own check digit was never evidence the two accounts were one person.
	//
	// That sentence exists in the code already -- but only as the merge's
	// REFUSAL, reached after an operator has picked a pair and been turned away.
	// This is the same fact said before the work starts, which is when it
	// changes what somebody does.
	//
	// NO NUMBERS IN IT, DELIBERATELY. The stat cards already state them,
	// and this view's own rule is that the counters are the panels counted and
	// never a second opinion. A sentence repeating them could disagree with
	// them; one stating the rule cannot. It also sidesteps the plural trap the
	// coverage strip records -- a number in a sentence needs `_n()` per number,
	// and three numbers need three calls.
	//
	// GATED ON BOTH HALVES EXISTING, because advice about dissolving findings
	// below is about nothing when there is nothing below, and nothing to correct
	// is not a plan. The two lists come from `IdentityQueuePanels`, so the
	// condition reuses the partition that decided the order rather than
	// restating which tiers are which -- a second list would agree with a
	// reordering that broke it.
	?>
	<?php if ( $ffc_identity_screen->to_correct > 0 && $ffc_identity_screen->to_judge > 0 ) : ?>
		<?php
		\FreeFormCertificate\Core\AdminNotice::callout(
			'info',
			esc_html__( 'Work the corrections first. A number that fails its own check digit cannot be anyone\'s, so it is not evidence that two accounts belong to one person — correcting it can make a finding further down disappear rather than be resolved.', 'ffcertificate' )
		);
		?>
	<?php endif; ?>

	<?php if ( array() !== $ffc_identity_capped ) : ?>
		<?php
		\FreeFormCertificate\Core\AdminNotice::callout(
			'warning',
			esc_html(
				sprintf(
					/* translators: 1: how many findings each check returns at most, 2: comma-separated check names. */
					__( 'This queue lists at most %1$s findings per check, and %2$s returned a full page — so more exist than are listed. The counts below are of what was read, not of what there is.', 'ffcertificate' ),
					number_format_i18n( IdentityResolutionPage::LIMIT ),
					implode( ', ', array_map( 'strval', $ffc_identity_capped ) )
				)
			)
		);
		?>
	<?php endif; ?>

	<?php if ( $ffc_identity_screen->truncated ) : ?>
		<?php
		\FreeFormCertificate\Core\AdminNotice::callout(
			'warning',
			esc_html__( 'The scan reached its cap before reading every stored value, so this list is partial. Work it down and run the screen again.', 'ffcertificate' )
		);
		?>
		<?php
	endif;
