<?php
/**
 * Template: Identity Resolution — panel header.
 *
 * A panel's chip, its position in the sequence and the way to the list.
 * Rendered by `$ffc_identity_head`, which passes `$panel`, `$label` and
 * `$note`.
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

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$tier  = (string) $panel['tier'];
$total = (int) $panel['total'];
$list  = ! empty( $panel['list'] );
// A FLOOR IS NOT A TOTAL, AND THIS IS WHERE THE NUMBER IS READ (#1466).
//
// The checks behind this panel reached their cap, so `$total` is `at
// least` this many and not this many. The scan already reported the cap
// in the banner at the top of the page -- but that banner speaks about
// the SCAN, once, while the number is per category and further down, and
// an operator who scrolled past it reads `32` as thirty-two.
//
// `IdentityQueuePanels` derives this per panel from the items' own
// `COLUMN_CHECK`, so one capped check qualifies every tier it feeds
// (`CHECK_MULTIPLE` feeds three) without anything here knowing which.
$capped = ! empty( $panel['capped'] );

$toggle = $ffc_identity_screen->toggle( $tier, $list );
// The tier is part of the class, so the chip is coloured by what the
// finding IS rather than by a decision taken in the markup.
$chip = IdentityResolutionLabels::chip_class( $tier );
?>
	<div class="ffc-identity-panel">
	<div class="ffc-identity-panel-head">
		<span class="ffc-identity-panel-chip <?php echo esc_attr( $chip ); ?>"><?php echo esc_html( $label ); ?></span>
		<h2 class="ffc-identity-panel-title screen-reader-text"><?php echo esc_html( $label ); ?></h2>
		<p class="ffc-identity-panel-note description"><?php echo esc_html( $note ); ?></p>
		<div class="ffc-identity-panel-nav">
			<?php if ( $list ) : ?>
				<span class="ffc-identity-panel-count" aria-live="polite">
					<?php
					printf(
						$capped
							/* translators: %s: how many findings this category holds, as a lower bound. */
							? esc_html( _n( 'at least %s finding', 'at least %s findings', $total, 'ffcertificate' ) )
							/* translators: %s: how many findings this category holds. */
							: esc_html( _n( '%s finding', '%s findings', $total, 'ffcertificate' ) ),
						esc_html( number_format_i18n( $total ) )
					);
					?>
				</span>
			<?php else : ?>
				<span class="ffc-identity-panel-count" aria-live="polite">
					<?php
					printf(
						$capped
							/* translators: 1: the finding being shown, 2: how many there are, as a lower bound. */
							? esc_html__( '%1$s of at least %2$s', 'ffcertificate' )
							/* translators: 1: the finding being shown, 2: how many there are. */
							: esc_html__( '%1$s of %2$s', 'ffcertificate' ),
						esc_html( number_format_i18n( (int) $panel['index'] + 1 ) ),
						esc_html( number_format_i18n( $total ) )
					);
					?>
				</span>
				<?php
				// AN ARROW, AND THE WORD IN THREE PLACES THE ARROW IS NOT.
				//
				// These two sit between the position ("3 of 14") and the link
				// to the list, so the direction is the whole message and the
				// words spend a third of the strip's width saying it twice.
				// Dropping to a glyph is only safe because the word survives
				// everywhere it was doing work: `aria-label` IS the accessible
				// name, so nothing changes for a screen reader; `title` draws
				// the tooltip the admin sheets already style on hover -- and
				// this sheet adds the `:focus-visible` half of that family,
				// which did not exist, so a keyboard reaches the same label
				// the mouse does (WCAG 1.4.13).
				//
				// What no tooltip reaches is touch, where neither hover nor
				// focus fires. That is carried by the glyph being a chevron
				// and by the 40px target, and it is the reason the label is
				// an `aria-label` rather than a `title` alone.
				?>
				<?php foreach ( array( 'previous', 'next' ) as $ffc_identity_step ) : ?>
					<?php
					$ffc_identity_step_label = 'previous' === $ffc_identity_step
						? __( 'Previous', 'ffcertificate' )
						: __( 'Next', 'ffcertificate' );
					// Icon-only family buttons (#1904): the chevron is the
					// button's own registry icon, the label its accessible name.
					$ffc_identity_step_icon  = 'previous' === $ffc_identity_step ? 'chevron-left' : 'chevron-right';
					$ffc_identity_step_class = 'ffc-identity-panel-step ffc-identity-panel-step-' . $ffc_identity_step;
					?>
					<?php if ( '' !== (string) $panel[ $ffc_identity_step ] ) : ?>
						<?php
						AdminUI::render_button(
							array(
								'label'     => $ffc_identity_step_label,
								'icon'      => $ffc_identity_step_icon,
								'icon_only' => true,
								'class'     => $ffc_identity_step_class,
								'href'      => $ffc_identity_screen->url( $tier, (string) $panel[ $ffc_identity_step ], $ffc_identity_screen->listed ),
							)
						);
						?>
					<?php else : ?>
						<?php // Rendered and disabled rather than absent, so the controls do not move under the pointer as the operator walks the list. By hand: a disabled step has nowhere to go, so it is a span, not a link. ?>
						<span class="ffc-btn ffc-btn-neutral ffc-btn-icon ffc-icon-<?php echo esc_attr( $ffc_identity_step_icon ); ?> <?php echo esc_attr( $ffc_identity_step_class ); ?> disabled"
							aria-disabled="true"
							aria-label="<?php echo esc_attr( $ffc_identity_step_label ); ?>"
							title="<?php echo esc_attr( $ffc_identity_step_label ); ?>"></span>
					<?php endif; ?>
				<?php endforeach; ?>
			<?php endif; ?>
			<a class="ffc-identity-panel-toggle" href="<?php echo esc_url( $ffc_identity_screen->url( '', '', $toggle ) ); ?>">
				<?php
				echo $list
					? esc_html__( 'One at a time', 'ffcertificate' )
					: esc_html__( 'See the list', 'ffcertificate' );
				?>
			</a>
		</div>
	</div>
	<div class="ffc-identity-panel-body">
	<?php
