<?php
/**
 * The check-in terminal's page (#2744).
 *
 * The markup only: `assets/js/ffc-terminal-kiosk.js` shows one view at a
 * time. It pairs the terminal (#2744), reads presence codes online or into
 * its offline queue (#2745), shows the event it checks in now or next (#2746), reads with the device camera where allowed (#2747), keeps the screen on while it has an event (#2775) and opens the operator menu on an operator code. The two result regions stay
 * in the page, so a screen reader announces each result as it is written.
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

	<section class="ffc-terminal-view" data-ffc-terminal-view="ready" hidden>
		<div class="ffc-terminal-event" data-ffc-terminal-part="event" hidden>
			<p class="ffc-terminal-event-kind" data-ffc-terminal-part="event-kind"></p>
			<p class="ffc-terminal-event-title" data-ffc-terminal-part="event-title"></p>
			<p class="ffc-terminal-event-when" data-ffc-terminal-part="event-when"></p>
		</div>
		<h2><?php esc_html_e( 'Show your presence code', 'ffcertificate' ); ?></h2>
		<p><?php esc_html_e( 'Open Presence in the app, choose this event and hold the code to the reader.', 'ffcertificate' ); ?></p>
		<div class="ffc-terminal-result" data-ffc-terminal-part="result">
			<div class="ffc-terminal-result-ok" role="status" aria-live="polite" data-ffc-terminal-part="result-ok"></div>
			<div class="ffc-terminal-result-refused" role="alert" data-ffc-terminal-part="result-refused"></div>
		</div>
		<form class="ffc-terminal-form" data-ffc-terminal-part="reading-form" novalidate>
			<label for="ffc-terminal-reading"><?php esc_html_e( 'Code read', 'ffcertificate' ); ?></label>
			<input id="ffc-terminal-reading" name="reading" type="text" autocomplete="off" autocapitalize="characters" spellcheck="false" inputmode="none" data-ffc-terminal-part="reading">
			<?php
			Button::render(
				array(
					'label'    => __( 'Check in', 'ffcertificate' ),
					'category' => 'primary',
					'icon'     => 'checkmark',
					'type'     => 'submit',
				)
			);
			?>
		</form>
		<div class="ffc-terminal-camera" data-ffc-terminal-part="camera" hidden>
			<p class="description" data-ffc-terminal-part="camera-note"></p>
			<div class="ffc-terminal-viewfinder" data-ffc-terminal-part="viewfinder" hidden>
				<video data-ffc-terminal-part="camera-video" playsinline muted aria-label="<?php esc_attr_e( 'Camera view', 'ffcertificate' ); ?>"></video>
			</div>
			<div class="ffc-terminal-actions">
				<?php
				Button::render(
					array(
						'label'    => __( 'Use the camera', 'ffcertificate' ),
						'category' => 'neutral',
						'icon'     => 'qr',
						'attrs'    => array( 'data-ffc-terminal-part' => 'camera-start' ),
					)
				);
				Button::render(
					array(
						'label'    => __( 'Stop the camera', 'ffcertificate' ),
						'category' => 'neutral',
						'icon'     => 'cross',
						'attrs'    => array(
							'data-ffc-terminal-part' => 'camera-stop',
							'hidden'                 => true,
						),
					)
				);
				?>
			</div>
		</div>
		<p class="ffc-terminal-label" data-ffc-terminal-part="label"></p>
		<p class="description" data-ffc-terminal-part="events"></p>
		<p class="description" data-ffc-terminal-part="until"></p>
	</section>

	<section class="ffc-terminal-view" data-ffc-terminal-view="menu" hidden>
		<h2 class="ffc-icon-unlock"><?php esc_html_e( 'Operator menu', 'ffcertificate' ); ?></h2>
		<p data-ffc-terminal-part="operator"></p>
		<p data-ffc-terminal-part="queue"></p>
		<p class="description"><?php esc_html_e( 'The menu locks again after two minutes without use.', 'ffcertificate' ); ?></p>
		<p class="description ffc-icon-warning" data-ffc-terminal-part="wake-note" hidden></p>
		<div class="ffc-terminal-actions" data-ffc-terminal-part="menu-ask">
			<?php
			Button::render(
				array(
					'label'    => __( 'Send queue now', 'ffcertificate' ),
					'category' => 'neutral',
					'icon'     => 'sync',
					'attrs'    => array( 'data-ffc-terminal-part' => 'menu-sync' ),
				)
			);
			Button::render(
				array(
					'label'    => __( 'Close menu', 'ffcertificate' ),
					'category' => 'neutral',
					'icon'     => 'cross',
					'attrs'    => array( 'data-ffc-terminal-part' => 'menu-close' ),
				)
			);
			Button::render(
				array(
					'label'    => __( 'End terminal', 'ffcertificate' ),
					'category' => 'danger',
					'icon'     => 'cross',
					'attrs'    => array( 'data-ffc-terminal-part' => 'menu-end' ),
				)
			);
			?>
		</div>
		<div class="ffc-terminal-actions" data-ffc-terminal-part="menu-confirm" hidden>
			<p><?php esc_html_e( 'End this terminal? It stops checking people in at once, and pairing it again needs a new code.', 'ffcertificate' ); ?></p>
			<?php
			Button::render(
				array(
					'label'    => __( 'Keep', 'ffcertificate' ),
					'category' => 'neutral',
					'icon'     => 'cross',
					'attrs'    => array( 'data-ffc-terminal-part' => 'menu-keep' ),
				)
			);
			Button::render(
				array(
					'label'    => __( 'End terminal', 'ffcertificate' ),
					'category' => 'danger',
					'icon'     => 'cross',
					'attrs'    => array( 'data-ffc-terminal-part' => 'menu-confirm-end' ),
				)
			);
			?>
		</div>
	</section>
</main>
