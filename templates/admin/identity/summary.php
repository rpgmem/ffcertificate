<?php
/**
 * Template: Identity Resolution — counters and notices.
 *
 * The counters, the order-of-work advice and the cap notices.
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

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
	<?php
	// THE COUNTERS ARE THE PANELS, COUNTED — NEVER A SECOND OPINION.
	//
	// Both this strip and the bodies below read `$ffc_identity_screen->panels`, which
	// `IdentityQueuePanels::build()` already emptied of the tiers holding
	// nothing. So a category absent here is absent below by construction, and
	// the two cannot drift into disagreeing about how many findings there are.
	//
	// The shared tier is counted even where the merge capability is absent
	// and its panel is therefore not drawn: the finding exists and is
	// somebody's to resolve, and a counter that hid it would report a shorter
	// queue to the operator least able to see why.
	$ffc_identity_remaining = $ffc_identity_screen->remaining;
	?>
	<?php if ( $ffc_identity_remaining > 0 ) : ?>
		<div class="ffc-identity-counts">
			<span class="ffc-identity-counts-total">
				<?php
				printf(
					/* translators: %s: how many findings the whole queue holds. */
					esc_html( _n( '%s finding left:', '%s findings left:', $ffc_identity_remaining, 'ffcertificate' ) ),
					esc_html( number_format_i18n( $ffc_identity_remaining ) )
				);
				?>
			</span>
			<?php foreach ( $ffc_identity_screen->panels as $ffc_identity_panel ) : ?>
				<?php
				// The same derivation the panel heading makes, from the same
				// value: the tier decides the class, never the markup.
				$ffc_identity_tier = (string) $ffc_identity_panel['tier'];
				$ffc_identity_chip = IdentityResolutionLabels::chip_class( $ffc_identity_tier );
				?>
				<span class="ffc-identity-count <?php echo esc_attr( $ffc_identity_chip ); ?>">
					<?php echo esc_html( IdentityResolutionLabels::tier_label( $ffc_identity_tier ) ); ?>
					<strong><?php echo esc_html( number_format_i18n( (int) $ffc_identity_panel['total'] ) ); ?></strong>
				</span>
			<?php endforeach; ?>
			<?php if ( array() !== $ffc_identity_orphans ) : ?>
				<span class="ffc-identity-count ffc-identity-chip-orphans">
					<?php esc_html_e( 'No account', 'ffcertificate' ); ?>
					<strong><?php echo esc_html( number_format_i18n( count( $ffc_identity_orphans ) ) ); ?></strong>
				</span>
			<?php endif; ?>
			<?php
			// WHAT HAS BEEN DONE, BESIDE WHAT IS LEFT, AND BOTH FROM THE SAME
			// INSTANT.
			//
			// The window is the held queue's own: `N left` counts the list
			// taken at `$ffc_identity_taken_at`, so the only number that can
			// sit beside it honestly is one measured from there. Reading the
			// queue again resets the two together, which is the gesture that
			// begins a new round. Zero is not rendered -- an operator who has
			// resolved nothing yet does not need to be told so.
			?>
			<?php if ( $ffc_identity_resolved > 0 ) : ?>
				<span class="ffc-identity-counts-done">
					<?php
					printf(
						/* translators: %s: how many findings this operator has resolved since the queue was read. */
						esc_html( _n( '%s resolved since this queue was read', '%s resolved since this queue was read', $ffc_identity_resolved, 'ffcertificate' ) ),
						esc_html( number_format_i18n( $ffc_identity_resolved ) )
					);
					?>
				</span>
			<?php endif; ?>
			<span class="description ffc-identity-counts-note">
				<?php esc_html_e( 'A category holding nothing does not appear — not here, and not below.', 'ffcertificate' ); ?>
			</span>
			<?php if ( '' !== $ffc_identity_export_url ) : ?>
				<?php
				AdminUI::render_button(
					array(
						'label' => __( 'Export CSV', 'ffcertificate' ),
						'icon'  => 'download',
						'class' => 'ffc-identity-counts-export',
						'href'  => $ffc_identity_export_url,
					)
				);
				?>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php
	// THE ADVICE THE COUNTS CANNOT GIVE (#1498).
	//
	// The chips above say how many findings each tier holds; the panel order
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
	// NO NUMBERS IN IT, DELIBERATELY. The chips one line up already state them,
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
	<?php endif; ?>
