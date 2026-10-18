<?php
/**
 * Template: Identity Resolution — coverage strips.
 *
 * What each scan read, one strip per identifier column.
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
use FreeFormCertificate\Admin\IdentityResolutionPage;
use FreeFormCertificate\Core\DateFormatter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
	<?php
	// WHAT THE SCAN READ, STATED WHERE THE QUEUE IS WORKED.
	//
	// These three numbers used to be read inside the empty-queue branch
	// alone, so the only screen state that never stated its own coverage was
	// the state an operator actually works: a queue WITH findings. A partial
	// list whose completeness is unstated reads as a whole one -- the
	// `#1071` / `#1094` rule ("an empty result must never read as clean") one
	// step along, where what passes for clean is a list rather than a zero.
	//
	// The four empty-queue notices further down stay exactly as they are.
	// This strip states numbers; they say what an unread scan MEANS, and the
	// strip must never become a cheerier second answer to their question --
	// which is why its three verdicts are derived from the same readings
	// those branches test, and why the reassuring one is the narrowest.
	// ONE STRIP PER COLUMN, AND SUMMING THEM WOULD UNDO THIS WHOLE SECTION (#1486).
	//
	// Two identifiers are scanned now. Worked through: RF reading 5,000 values
	// cleanly plus a CPF scan that read NOTHING sums to `stores > 0`,
	// `examined = 5000`, `unreadable = 0` -- which the three verdicts below
	// render as "The scan read the data". The healthy half would vouch for the
	// half that never ran, in the one place built to stop exactly that. So the
	// coverage arrives keyed by column and each key gets its own verdict.
	//
	// A column ABSENT from the coverage was never scanned, which is different
	// again from one that found no store, and it simply draws no strip -- the
	// screen states what it measured and stays silent about the rest.
	// A HELD LIST THAT LOST ITS READINGS, AND THE READING ACROSS BOTH COLUMNS
	// THE EMPTY-QUEUE BRANCHES NEED, ARE `IdentityResolutionScreen`'s to decide.

	foreach ( $ffc_identity_screen->strips as $ffc_identity_strip ) :
		$ffc_identity_examined   = $ffc_identity_strip['examined'];
		$ffc_identity_unreadable = $ffc_identity_strip['unreadable'];
		$ffc_identity_stores     = $ffc_identity_strip['stores'];
		$ffc_identity_scan_name  = $ffc_identity_strip['name'];
		$ffc_identity_scan_cap   = $ffc_identity_strip['scan_cap'];
		$ffc_identity_read_none  = $ffc_identity_strip['read_none'];
		$ffc_identity_read_part  = $ffc_identity_strip['read_part'];
		$ffc_identity_scan_state = $ffc_identity_strip['state'];
		?>
	<div class="ffc-identity-scan ffc-identity-scan-<?php echo esc_attr( $ffc_identity_scan_state ); ?>">
		<p class="ffc-identity-scan-verdict">
			<?php
			// THE VERDICT NAMES THE IDENTIFIER, BECAUSE THERE ARE TWO OF THEM.
			//
			// "This scan read nothing" was unambiguous while one scan ran. With
			// two it has to say WHICH, or an operator reading it beside a queue
			// full of RF findings cannot tell whether the sentence is about the
			// work in front of them or about the half that is missing.
			if ( '' === $ffc_identity_scan_name ) {
				if ( $ffc_identity_read_none ) {
					esc_html_e( 'This scan read nothing', 'ffcertificate' );
				} elseif ( $ffc_identity_read_part ) {
					esc_html_e( 'The scan read part of the data', 'ffcertificate' );
				} else {
					esc_html_e( 'The scan read the data', 'ffcertificate' );
				}
			} elseif ( $ffc_identity_read_none ) {
				/* translators: %s: an identifier's name, e.g. RF -- a proper noun, untranslated. */
				printf( esc_html__( 'The %s scan read nothing', 'ffcertificate' ), esc_html( $ffc_identity_scan_name ) );
			} elseif ( $ffc_identity_read_part ) {
				/* translators: %s: an identifier's name, e.g. RF -- a proper noun, untranslated. */
				printf( esc_html__( 'The %s scan read part of the data', 'ffcertificate' ), esc_html( $ffc_identity_scan_name ) );
			} else {
				/* translators: %s: an identifier's name, e.g. RF -- a proper noun, untranslated. */
				printf( esc_html__( 'The %s scan read the data', 'ffcertificate' ), esc_html( $ffc_identity_scan_name ) );
			}
			?>
		</p>
		<p class="ffc-identity-scan-detail">
			<?php
			// THREE COUNTS, THREE PLURALS, AND ONE SENTENCE WOULD HAVE NEEDED
			// THE SAME FORM FOR ALL THREE.
			//
			// The first version of this was a single string carrying all
			// three numbers, which renders `1 valores distintos verificados`
			// in Portuguese and is wrong in every language that inflects.
			// `_n()` picks a form per NUMBER, so the three have to be three
			// calls -- and they are joined by the same separator rather than
			// by a fourth string, because what sits between them is
			// punctuation, not prose.
			printf(
				/* translators: %s: how many distinct stored values were checked. */
				esc_html( _n( '%s distinct value checked', '%s distinct values checked', $ffc_identity_examined, 'ffcertificate' ) ),
				esc_html( number_format_i18n( $ffc_identity_examined ) )
			);
			?>
			·
			<?php
			printf(
				/* translators: %s: how many stored values could not be read. */
				esc_html( _n( '%s could not be read', '%s could not be read', $ffc_identity_unreadable, 'ffcertificate' ) ),
				esc_html( number_format_i18n( $ffc_identity_unreadable ) )
			);
			?>
			·
			<?php
			printf(
				/* translators: %s: how many stores the scan read. */
				esc_html( _n( '%s store scanned', '%s stores scanned', $ffc_identity_stores, 'ffcertificate' ) ),
				esc_html( number_format_i18n( $ffc_identity_stores ) )
			);
			?>
			<?php if ( $ffc_identity_scan_cap ) : ?>
				· <?php esc_html_e( 'the scan reached its cap', 'ffcertificate' ); ?>
			<?php endif; ?>
			<?php if ( array() !== $ffc_identity_capped ) : ?>
				· <?php esc_html_e( 'a check returned a full page', 'ffcertificate' ); ?>
			<?php endif; ?>
		</p>
		<?php if ( $ffc_identity_taken_at > 0 ) : ?>
			<div class="ffc-identity-scan-when">
				<span>
					<?php
					printf(
						/* translators: %s: when the queue was read, as a date and time. */
						esc_html__( 'Read at %s and held still while you work it', 'ffcertificate' ),
						esc_html( DateFormatter::format_datetime( $ffc_identity_taken_at ) )
					);
					?>
				</span>
				<?php // A form, because reading again is a POST — which is also why it cannot sit inside the paragraph above: a form is not phrasing content, and nesting it in a `<p>` closes the paragraph early. ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( IdentityResolutionPage::RESCAN_NONCE ); ?>
					<input type="hidden" name="action" value="<?php echo esc_attr( IdentityResolutionPage::RESCAN_ACTION ); ?>">
					<?php
					AdminUI::render_button(
						array(
							'label' => __( 'Read the queue again', 'ffcertificate' ),
							'icon'  => 'sync',
							'type'  => 'submit',
						)
					);
					?>
				</form>
			</div>
		<?php endif; ?>
	</div>
	<?php endforeach; ?>
