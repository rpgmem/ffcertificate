<?php
/**
 * The app's Presence screen (#2742).
 *
 * The markup only: `assets/js/ffc-presence-app.js` shows one view at a time
 * and fills the code, the event list and the countdown.
 *
 * @package FreeFormCertificate\Audience
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FreeFormCertificate\Core\Button;
use FreeFormCertificate\Core\Notice;
?>
<div class="ffc-shortcode ffc-presence" data-ffc-presence>
	<noscript>
		<?php echo Notice::render( 'warning', 'warning', __( 'The presence code needs JavaScript. Turn it on in the browser and open this screen again.', 'ffcertificate' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Notice::render() escapes its own output. ?>
	</noscript>

	<p class="ffc-presence-status" role="status" aria-live="polite" data-ffc-presence-part="status"></p>

	<div data-ffc-presence-part="unsupported" data-ffc-presence-view="unsupported" hidden>
		<?php echo Notice::render( 'warning', 'warning', __( 'This browser cannot keep a presence key. Open the app installed on your phone, or update the browser.', 'ffcertificate' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Notice::render() escapes its own output. ?>
	</div>

	<div class="ffc-presence-activate" data-ffc-presence-part="activate-view" data-ffc-presence-view="activate" hidden>
		<p><?php esc_html_e( 'Activate this phone once, while online, to show your presence code at an event terminal. It then works without internet.', 'ffcertificate' ); ?></p>
		<p><?php esc_html_e( 'One phone per account: activating another one later stops this one, and you receive an e-mail.', 'ffcertificate' ); ?></p>
		<?php
		Button::render(
			array(
				'label'    => __( 'Activate this phone', 'ffcertificate' ),
				'category' => 'primary',
				'icon'     => 'checkmark',
				'attrs'    => array( 'data-ffc-presence-part' => 'activate' ),
			)
		);
		?>
	</div>

	<div class="ffc-presence-code" data-ffc-presence-part="code-view" data-ffc-presence-view="code" hidden>
		<ul class="ffc-presence-pending" data-ffc-presence-part="pending" aria-label="<?php esc_attr_e( 'Waiting for you', 'ffcertificate' ); ?>" hidden></ul>
		<label class="ffc-presence-label" for="ffc-presence-event"><?php esc_html_e( 'Event', 'ffcertificate' ); ?></label>
		<select id="ffc-presence-event" class="ffc-presence-event" data-ffc-presence-part="event"></select>
		<p class="ffc-presence-attendance ffc-icon-checkmark" data-ffc-presence-part="attendance" hidden></p>
		<p class="ffc-presence-empty" data-ffc-presence-part="empty" hidden><?php esc_html_e( 'You have no event in the next 7 days. Open this screen while online to update the list.', 'ffcertificate' ); ?></p>
		<div class="ffc-presence-qr" data-ffc-presence-part="qr"></div>
		<p class="ffc-presence-name" data-ffc-presence-part="name"></p>
		<p class="ffc-presence-countdown" data-ffc-presence-part="countdown"></p>
		<p class="ffc-presence-help"><?php esc_html_e( 'Hold the code up to the terminal\'s reader, about 20 cm away, with the screen bright. A photo or screenshot of the code stops working within a minute.', 'ffcertificate' ); ?></p>
	</div>
</div>
