<?php
/**
 * Template: Identity Resolution — empty queue.
 *
 * What an empty queue means, in the three ways it can be empty.
 *
 * Included from `includes/admin/views/identity-resolution-page.php`, inside
 * `IdentityResolutionPage::render_page()`, so the page's `$ffc_identity_*`
 * locals are in scope. Moved verbatim (#1925): the page renders byte for
 * byte as it did when this was inline.
 *
 * @package FreeFormCertificate\Admin
 * @since   6.41.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// AN EMPTY LIST MEANS THREE DIFFERENT THINGS AND ONLY ONE IS GOOD NEWS.
//
// The scan returns failures, so it returns none when no store carries
// the columns it needs, when nothing it read could be decrypted, and
// when every value is genuinely fine. Reporting the third when it was
// one of the first two is the `#1071` / `#1094` rule broken on a
// screen instead of in a guard.
// Read once, above, where the strip states them: three names for one
// set of numbers is how a screen comes to disagree with itself.
?>
		<?php if ( 0 === $ffc_identity_screen->all['stores'] ) : ?>
			<?php
			\FreeFormCertificate\Core\AdminNotice::callout(
				'warning',
				esc_html__( 'Nothing was scanned: no store on this install carries an RF or a CPF in a form this check can read, which needs the hash, the ciphertext and a row id on the same table. This is not a clean result.', 'ffcertificate' )
			);
			?>
		<?php elseif ( $ffc_identity_screen->all['examined'] > 0 && $ffc_identity_screen->all['examined'] === $ffc_identity_screen->all['unreadable'] ) : ?>
			<?php
			\FreeFormCertificate\Core\AdminNotice::callout(
				'error',
				esc_html(
					sprintf(
						/* translators: %s: how many distinct stored values were found. */
						__( 'Found %s stored values and could not read any of them, so nothing was checked. That is what an encryption key which does not match this data looks like — this is not a clean result.', 'ffcertificate' ),
						number_format_i18n( $ffc_identity_screen->all['examined'] )
					)
				)
			);
			?>
		<?php elseif ( 0 === $ffc_identity_screen->all['examined'] ) : ?>
			<?php
			// FOUR STATES, NOT THREE. The first pass at this fixed the two
			// obvious unread cases and left THIS one falling into the
			// reassuring branch, which then claimed zero values checked and
			// all of them sound -- vacuous, and it reads as reassurance.
			// (Phrased without quoting that sentence: a test below anchors on
			// it, and prose that repeats a literal is the same trap this
			// repository records for suppression scanners.) Zero examined is
			// not a clean result: it
			// says no row in any scanned store carries both a hash and a
			// ciphertext. On a fresh or test install that is ordinary; on an
			// install with submissions it is a signal.
			\FreeFormCertificate\Core\AdminNotice::callout(
				'info',
				esc_html(
					sprintf(
						/* translators: %s: how many stores were scanned. */
						__( 'No stored RF or CPF was found at all: none of the %s stores scanned holds a row with both a hash and a ciphertext, so there was nothing to check. Ordinary on an install that has not captured one yet — worth looking into on one that has.', 'ffcertificate' ),
						number_format_i18n( $ffc_identity_screen->all['stores'] )
					)
				)
			);
			?>
		<?php elseif ( array() !== $ffc_identity_accepted ) : ?>
			<?php
			// A FIFTH STATE, AND ACCEPTANCE IS WHAT ADDED IT (#1532).
			//
			// The four above split an empty list by what the SCAN managed to
			// read. This one is different in kind: the scan read fine and found
			// failures, and they are absent from the list because somebody
			// judged them unresolvable. Falling into the branch below would
			// claim every stored value satisfies its check digit, which is
			// false while a single acceptance stands -- the same shape as the
			// three readings above, one layer further out.
			?>
			<p class="description">
				<?php
				printf(
					/* translators: 1: values checked, 2: how many findings are accepted, 3: values that could not be read. */
					esc_html__( 'Nothing left to work: %1$s stored values checked, and the %2$s finding(s) that remain are recorded below as impossible to resolve. This is not the same as every value being sound. %3$s could not be read and were not checked.', 'ffcertificate' ),
					esc_html( number_format_i18n( $ffc_identity_screen->all['examined'] ) ),
					esc_html( number_format_i18n( count( $ffc_identity_accepted ) ) ),
					esc_html( number_format_i18n( $ffc_identity_screen->all['unreadable'] ) )
				);
				?>
			</p>
		<?php else : ?>
			<p class="description">
				<?php
				printf(
					/* translators: 1: values checked, 2: values that could not be read. */
					esc_html__( 'Nothing to resolve: %1$s stored values checked and every one satisfies its check digit. %2$s could not be read and were not checked.', 'ffcertificate' ),
					esc_html( number_format_i18n( $ffc_identity_screen->all['examined'] ) ),
					esc_html( number_format_i18n( $ffc_identity_screen->all['unreadable'] ) )
				);
				?>
			</p>
		<?php endif; ?>
