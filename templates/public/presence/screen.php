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
use FreeFormCertificate\Core\Icons;
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
		<span class="ffc-presence-activate-icon" aria-hidden="true"><?php echo Icons::svg( 'qr', 28 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons::svg() returns a closed-vocabulary drawing. ?></span>
		<h2 class="ffc-presence-activate-title" data-ffc-presence-part="activate-title"><?php esc_html_e( 'Activate presence on this phone', 'ffcertificate' ); ?></h2>
		<p class="ffc-presence-activate-lead"><?php esc_html_e( 'The presence code works on one phone at a time, even without internet.', 'ffcertificate' ); ?></p>
		<p class="ffc-presence-current" data-ffc-presence-part="current" hidden></p>
		<dl class="ffc-presence-facts" data-ffc-presence-part="facts" hidden>
			<div class="ffc-presence-fact">
				<dt><?php esc_html_e( 'Phone changes', 'ffcertificate' ); ?></dt>
				<dd data-ffc-presence-part="changes"></dd>
			</div>
			<div class="ffc-presence-fact">
				<dt><?php esc_html_e( 'Works without internet for', 'ffcertificate' ); ?></dt>
				<dd data-ffc-presence-part="offline"></dd>
			</div>
		</dl>
		<p class="ffc-presence-activate-note"><?php esc_html_e( 'You receive an e-mail about the change. This phone stays reserved for your account for 30 days.', 'ffcertificate' ); ?></p>
		<p class="ffc-presence-activate-note" data-ffc-presence-part="ios-note" hidden><?php esc_html_e( 'On an iPhone, always open Presence from the app on the home screen: Safari and the app keep separate keys, so each counts as a phone.', 'ffcertificate' ); ?></p>
		<div class="ffc-presence-actions">
			<?php
			Button::render(
				array(
					'label'    => __( 'Activate on this phone', 'ffcertificate' ),
					'category' => 'primary',
					'icon'     => 'checkmark',
					'attrs'    => array( 'data-ffc-presence-part' => 'activate' ),
				)
			);
			Button::render(
				array(
					'label'    => __( 'Not now', 'ffcertificate' ),
					'category' => 'neutral',
					'icon'     => 'chevron-left',
					'href'     => home_url( '/' ),
					'attrs'    => array( 'data-ffc-presence-part' => 'later' ),
				)
			);
			?>
		</div>
	</div>

	<div class="ffc-presence-code" data-ffc-presence-part="code-view" data-ffc-presence-view="code" hidden>
		<ul class="ffc-presence-pending" data-ffc-presence-part="pending" aria-label="<?php esc_attr_e( 'Waiting for you', 'ffcertificate' ); ?>" hidden></ul>
		<div class="ffc-presence-stage">
			<div class="ffc-presence-heading">
				<h2 class="ffc-presence-title" data-ffc-presence-part="title"></h2>
				<p class="ffc-presence-when" data-ffc-presence-part="when"></p>
			</div>
			<div class="ffc-presence-picker" data-ffc-presence-part="picker" hidden>
				<label class="ffc-presence-label" for="ffc-presence-event"><?php esc_html_e( 'Event', 'ffcertificate' ); ?></label>
				<select id="ffc-presence-event" class="ffc-presence-event" data-ffc-presence-part="event"></select>
			</div>
			<p class="ffc-presence-attendance ffc-icon-checkmark" data-ffc-presence-part="attendance" hidden></p>
			<p class="ffc-presence-empty" data-ffc-presence-part="empty" hidden><?php esc_html_e( 'You have no event in the next 7 days. Open this screen while online to update the list.', 'ffcertificate' ); ?></p>
			<div class="ffc-presence-card" data-ffc-presence-part="card">
				<div class="ffc-presence-qr" data-ffc-presence-part="qr"></div>
			</div>
			<div class="ffc-presence-rotation">
				<progress class="ffc-presence-progress" max="30" value="30" data-ffc-presence-part="progress" aria-hidden="true"></progress>
				<p class="ffc-presence-countdown" data-ffc-presence-part="countdown"></p>
			</div>
			<ul class="ffc-presence-tips">
				<li class="ffc-icon-qr"><?php esc_html_e( 'Hold the screen up to the terminal\'s reader at the entrance.', 'ffcertificate' ); ?></li>
				<li class="ffc-icon-shield"><?php esc_html_e( 'Works without internet: the code is made and signed on this phone.', 'ffcertificate' ); ?></li>
				<li class="ffc-icon-clock"><?php esc_html_e( 'A screenshot will not do: the code changes every 30 seconds.', 'ffcertificate' ); ?></li>
			</ul>
		</div>
	</div>
</div>
