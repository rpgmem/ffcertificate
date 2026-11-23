<?php
/**
 * The check-in terminal's page (#2744).
 *
 * The markup only: `assets/js/ffc-terminal-kiosk.js` shows one view at a
 * time. Reading presence codes arrives with #2745; this page pairs the
 * terminal and says what it holds.
 *
 * @package FreeFormCertificate\Audience
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FreeFormCertificate\Core\Button;
use FreeFormCertificate\Core\Notice;
?>
<main class="ffc-terminal" data-ffc-terminal>
	<h1 class="ffc-terminal-title ffc-icon-qr"><?php esc_html_e( 'Check-in terminal', 'ffcertificate' ); ?></h1>

	<noscript>
		<?php echo Notice::render( 'warning', 'warning', __( 'The check-in terminal needs JavaScript. Turn it on in the browser and open this page again.', 'ffcertificate' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Notice::render() escapes its own output. ?>
	</noscript>

	<p class="ffc-terminal-status" role="status" aria-live="polite" data-ffc-terminal-part="status"></p>
	<p class="ffc-terminal-error" role="alert" data-ffc-terminal-part="error" hidden></p>

	<section class="ffc-terminal-view" data-ffc-terminal-view="pairing" hidden>
		<h2><?php esc_html_e( 'Waiting for pairing', 'ffcertificate' ); ?></h2>
		<p><?php esc_html_e( 'An operator generates a pairing code in the app, under More → Pair terminal. Hold its QR code to this terminal\'s reader, or type the code below.', 'ffcertificate' ); ?></p>
		<form class="ffc-terminal-form" data-ffc-terminal-part="form" novalidate>
			<label for="ffc-terminal-code"><?php esc_html_e( 'Pairing code', 'ffcertificate' ); ?></label>
			<input id="ffc-terminal-code" name="code" type="text" autocomplete="off" autocapitalize="characters" spellcheck="false" inputmode="text" placeholder="P-XXXX-XXXX-XXXX" required data-ffc-terminal-part="code">
			<?php
			Button::render(
				array(
					'label'    => __( 'Pair', 'ffcertificate' ),
					'category' => 'primary',
					'icon'     => 'link',
					'type'     => 'submit',
				)
			);
			?>
		</form>
	</section>

	<section class="ffc-terminal-view" data-ffc-terminal-view="locked" hidden>
		<h2><?php esc_html_e( 'Pairing paused', 'ffcertificate' ); ?></h2>
		<p class="ffc-terminal-countdown" data-ffc-terminal-part="countdown"></p>
		<p><?php esc_html_e( 'Wrong codes are counted on the site. Ask the operator for a new code, and type it carefully once the wait is over.', 'ffcertificate' ); ?></p>
	</section>

	<section class="ffc-terminal-view" data-ffc-terminal-view="paired" hidden>
		<h2 class="ffc-icon-checkmark"><?php esc_html_e( 'Terminal paired', 'ffcertificate' ); ?></h2>
		<p class="ffc-terminal-label" data-ffc-terminal-part="label"></p>
		<p data-ffc-terminal-part="events"></p>
		<p data-ffc-terminal-part="until"></p>
		<p class="description"><?php esc_html_e( 'Reading presence codes arrives in a coming update. An operator can end this terminal in the app, under More → My terminals.', 'ffcertificate' ); ?></p>
	</section>
</main>
