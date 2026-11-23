<?php
/**
 * The check-in terminal's page (#2744, #2780).
 *
 * The markup only: `assets/js/ffc-terminal-kiosk.js` shows one screen at a
 * time. It pairs the terminal (#2744), reads presence codes online or into
 * its offline queue (#2745), shows the event it checks in now or next (#2746),
 * reads with the device camera where allowed (#2747), keeps the screen on
 * while it has an event (#2775) and opens the operator menu on an operator
 * code.
 *
 * Every screen fills the display, as an entrance terminal is read from a
 * step away (#2780): a header with the event and the clock, the screen, and
 * a footer with the connection and the operator's lock. The reading field
 * stays in the page on every paired screen, off the visible layout, so the
 * reader can type the next code while a result is still showing. The two
 * result regions stay in the page, so a screen reader announces each result
 * as it is written.
 *
 * @package FreeFormCertificate\Audience
 *
 * @var string $ffc_terminal_logo Site icon URL, or ''.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FreeFormCertificate\Core\Button;
use FreeFormCertificate\Core\Icons;
use FreeFormCertificate\Core\Notice;

$ffc_terminal_logo = isset( $ffc_terminal_logo ) ? (string) $ffc_terminal_logo : '';
$ffc_terminal_site = (string) get_bloginfo( 'name' );
?>
<main class="ffc-terminal" data-ffc-terminal data-ffc-terminal-tone="neutral">
	<header class="ffc-terminal-head">
		<span class="ffc-terminal-logo" aria-hidden="true">
			<?php if ( '' !== $ffc_terminal_logo ) : ?>
				<img src="<?php echo esc_url( $ffc_terminal_logo ); ?>" alt="">
			<?php else : ?>
				<?php echo esc_html( function_exists( 'mb_substr' ) ? mb_strtoupper( mb_substr( $ffc_terminal_site, 0, 3 ) ) : strtoupper( substr( $ffc_terminal_site, 0, 3 ) ) ); ?>
			<?php endif; ?>
		</span>
		<div class="ffc-terminal-head-text">
			<h1 class="ffc-terminal-head-title" data-ffc-terminal-part="head-title"><?php esc_html_e( 'Check-in terminal', 'ffcertificate' ); ?></h1>
			<p class="ffc-terminal-head-sub" data-ffc-terminal-part="head-sub"></p>
		</div>
		<div class="ffc-terminal-head-side">
			<p class="ffc-terminal-clock" data-ffc-terminal-part="clock"></p>
			<p class="ffc-terminal-head-note" data-ffc-terminal-part="head-note"></p>
		</div>
	</header>

	<noscript>
		<?php echo Notice::render( 'warning', 'warning', __( 'The check-in terminal needs JavaScript. Turn it on in the browser and open this page again.', 'ffcertificate' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Notice::render() escapes its own output. ?>
	</noscript>

	<p class="ffc-terminal-status" role="status" aria-live="polite" data-ffc-terminal-part="status"></p>
	<p class="ffc-terminal-error" role="alert" data-ffc-terminal-part="error" hidden></p>

	<form class="ffc-terminal-reader" data-ffc-terminal-part="reading-form" novalidate>
		<label for="ffc-terminal-reading"><?php esc_html_e( 'Code read', 'ffcertificate' ); ?></label>
		<input id="ffc-terminal-reading" name="reading" type="text" autocomplete="off" autocapitalize="characters" spellcheck="false" inputmode="none" data-ffc-terminal-part="reading">
		<button type="submit"><?php esc_html_e( 'Check in', 'ffcertificate' ); ?></button>
	</form>

	<section class="ffc-terminal-view" data-ffc-terminal-view="pairing" hidden>
		<div class="ffc-terminal-camera-slot" data-ffc-terminal-camera-slot="pairing"></div>
		<div class="ffc-terminal-art" data-ffc-terminal-camera-art aria-hidden="true"><?php echo Icons::svg( 'qr', 96 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons::svg() prints the registry's fixed markup. ?></div>
		<h2><?php esc_html_e( 'Waiting for pairing', 'ffcertificate' ); ?></h2>
		<p class="ffc-terminal-lead"><?php esc_html_e( 'Operator: hold your operator QR code to this terminal\'s reader (app → More → Operator QR) and choose the events here, or pair it from the app under More → Pair terminal.', 'ffcertificate' ); ?></p>
		<form class="ffc-terminal-pair" data-ffc-terminal-part="form" novalidate>
			<div class="ffc-terminal-field">
				<label for="ffc-terminal-code"><?php esc_html_e( 'Or type the pairing code', 'ffcertificate' ); ?></label>
				<input id="ffc-terminal-code" name="code" type="text" autocomplete="off" autocapitalize="characters" spellcheck="false" inputmode="text" placeholder="P-XXXX-XXXX-XXXX" required data-ffc-terminal-part="code">
			</div>
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
		<p class="ffc-terminal-note"><?php esc_html_e( 'The code lasts five minutes and works once. No one signs in on this device.', 'ffcertificate' ); ?></p>
	</section>

	<section class="ffc-terminal-view ffc-terminal-setup" data-ffc-terminal-view="setup" hidden>
		<h2 class="ffc-icon-link"><?php esc_html_e( 'Set up this terminal', 'ffcertificate' ); ?></h2>
		<p class="ffc-terminal-lead" data-ffc-terminal-part="setup-operator"></p>
		<form class="ffc-terminal-setup-form" data-ffc-terminal-part="setup-form" novalidate>
			<fieldset>
				<legend><?php esc_html_e( 'Event queue', 'ffcertificate' ); ?></legend>
				<div class="ffc-terminal-field">
					<label for="ffc-terminal-setup-place"><?php esc_html_e( 'Fill from one place', 'ffcertificate' ); ?></label>
					<select id="ffc-terminal-setup-place" data-ffc-terminal-part="setup-place">
						<option value=""><?php esc_html_e( 'Choose the events one by one', 'ffcertificate' ); ?></option>
					</select>
				</div>
				<p class="ffc-terminal-note" data-ffc-terminal-part="setup-count"></p>
				<ul class="ffc-terminal-queue-list" data-ffc-terminal-part="setup-list"></ul>
				<p class="ffc-terminal-note" data-ffc-terminal-part="setup-none" hidden><?php esc_html_e( 'No event in the next 7 days takes terminal check-in.', 'ffcertificate' ); ?></p>
			</fieldset>
			<div class="ffc-terminal-field">
				<label for="ffc-terminal-setup-label"><?php esc_html_e( 'Terminal name (optional)', 'ffcertificate' ); ?></label>
				<input id="ffc-terminal-setup-label" type="text" maxlength="60" autocomplete="off" placeholder="<?php esc_attr_e( 'Auditorium entrance', 'ffcertificate' ); ?>" data-ffc-terminal-part="setup-label">
			</div>
			<p class="ffc-terminal-error" role="alert" data-ffc-terminal-part="setup-error" hidden></p>
			<div class="ffc-terminal-actions">
				<?php
				Button::render(
					array(
						'label'    => __( 'Pair this terminal', 'ffcertificate' ),
						'category' => 'primary',
						'icon'     => 'link',
						'type'     => 'submit',
						'attrs'    => array( 'data-ffc-terminal-part' => 'setup-submit' ),
					)
				);
				Button::render(
					array(
						'label'    => __( 'Cancel', 'ffcertificate' ),
						'category' => 'neutral',
						'icon'     => 'chevron-left',
						'attrs'    => array( 'data-ffc-terminal-part' => 'setup-cancel' ),
					)
				);
				?>
			</div>
		</form>
		<p class="ffc-terminal-note"><?php esc_html_e( 'Check-in windows that cross are refused, naming the event they collide with. No code is shown and no one signs in on this device; the terminal stops on its own after its last event, as the site is set.', 'ffcertificate' ); ?></p>
	</section>

	<section class="ffc-terminal-view" data-ffc-terminal-view="locked" hidden>
		<div class="ffc-terminal-badge" aria-hidden="true"><?php echo Icons::svg( 'clock', 88 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons::svg() prints the registry's fixed markup. ?></div>
		<h2><?php esc_html_e( 'Too many pairing attempts', 'ffcertificate' ); ?></h2>
		<div class="ffc-terminal-count">
			<p><?php esc_html_e( 'Try again in', 'ffcertificate' ); ?></p>
			<p class="ffc-terminal-big" aria-live="polite" data-ffc-terminal-part="countdown"></p>
		</div>
		<p class="ffc-terminal-lead"><?php esc_html_e( 'Several wrong codes in a row. Generate a new pairing QR code in the operator app: the reader reads it without typing mistakes.', 'ffcertificate' ); ?></p>
		<p class="ffc-terminal-note"><?php esc_html_e( 'A new mistake after this wait means 30 minutes. A correct pairing, or an hour without attempts, resets the count.', 'ffcertificate' ); ?></p>
	</section>

	<section class="ffc-terminal-view" data-ffc-terminal-view="ready" hidden>
		<div class="ffc-terminal-ready">
			<div class="ffc-terminal-camera-slot" data-ffc-terminal-camera-slot="ready">
				<div class="ffc-terminal-viewfinder" data-ffc-terminal-part="viewfinder" hidden>
					<video data-ffc-terminal-part="camera-video" playsinline muted aria-label="<?php esc_attr_e( 'Front camera view, with the reading frame', 'ffcertificate' ); ?>"></video>
					<canvas class="ffc-terminal-still" data-ffc-terminal-part="camera-still" aria-hidden="true" hidden></canvas>
					<span class="ffc-terminal-frame" aria-hidden="true"></span>
					<span class="ffc-terminal-scanline" aria-hidden="true"></span>
					<span class="ffc-terminal-flash" aria-hidden="true"></span>
				</div>
			</div>
			<div class="ffc-terminal-art" data-ffc-terminal-part="art" aria-hidden="true"><?php echo Icons::svg( 'phone', 96 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons::svg() prints the registry's fixed markup. ?></div>
			<div class="ffc-terminal-ready-text">
				<h2 data-ffc-terminal-part="ready-title"><?php esc_html_e( 'Hold your phone\'s QR code to the reader', 'ffcertificate' ); ?></h2>
				<p class="ffc-terminal-lead" data-ffc-terminal-part="ready-lead"><?php esc_html_e( 'Open the app, tap Presence and show the screen to the reader.', 'ffcertificate' ); ?></p>
				<ul class="ffc-terminal-tips" data-ffc-terminal-part="camera-tips" hidden>
					<li class="ffc-icon-zap"><?php esc_html_e( 'Turn the phone\'s brightness up', 'ffcertificate' ); ?></li>
					<li class="ffc-icon-phone"><?php esc_html_e( 'Open Presence in the app, not a screenshot', 'ffcertificate' ); ?></li>
				</ul>
				<div class="ffc-terminal-camera" data-ffc-terminal-part="camera" hidden>
					<p class="ffc-terminal-note" data-ffc-terminal-part="camera-note"></p>
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
									'hidden' => true,
								),
							)
						);
						?>
					</div>
				</div>
			</div>
		</div>
	</section>

	<section class="ffc-terminal-view" data-ffc-terminal-view="between" hidden>
		<p class="ffc-terminal-kicker"><?php esc_html_e( 'Next event', 'ffcertificate' ); ?></p>
		<h2 data-ffc-terminal-part="next-title"></h2>
		<p class="ffc-terminal-lead" data-ffc-terminal-part="next-where"></p>
		<div class="ffc-terminal-count">
			<p data-ffc-terminal-part="next-opens"></p>
			<p class="ffc-terminal-big" data-ffc-terminal-part="next-countdown"></p>
		</div>
	</section>

	<section class="ffc-terminal-view ffc-terminal-result" data-ffc-terminal-view="result" data-ffc-terminal-part="result" hidden>
		<div class="ffc-terminal-badge" aria-hidden="true">
			<span class="ffc-terminal-badge-ok"><?php echo Icons::svg( 'check', 96 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons::svg() prints the registry's fixed markup. ?></span>
			<span class="ffc-terminal-badge-refused"><?php echo Icons::svg( 'x', 96 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons::svg() prints the registry's fixed markup. ?></span>
		</div>
		<div class="ffc-terminal-result-ok" role="status" aria-live="assertive" data-ffc-terminal-part="result-ok"></div>
		<div class="ffc-terminal-result-refused" role="alert" data-ffc-terminal-part="result-refused"></div>
		<div class="ffc-terminal-next" data-ffc-terminal-part="result-next">
			<span class="ffc-terminal-progress" aria-hidden="true"><span data-ffc-terminal-part="result-bar"></span></span>
			<p data-ffc-terminal-part="result-wait"></p>
		</div>
	</section>

	<section class="ffc-terminal-view" data-ffc-terminal-view="unlock" hidden>
		<div class="ffc-terminal-camera-slot" data-ffc-terminal-camera-slot="unlock"></div>
		<div class="ffc-terminal-art" data-ffc-terminal-camera-art aria-hidden="true"><?php echo Icons::svg( 'lock', 96 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons::svg() prints the registry's fixed markup. ?></div>
		<h2><?php esc_html_e( 'Operator area', 'ffcertificate' ); ?></h2>
		<p class="ffc-terminal-lead"><?php esc_html_e( 'Hold the operator QR code to the reader (app → More → Operator QR). Only someone who may check people in at these events unlocks the terminal.', 'ffcertificate' ); ?></p>
		<p class="ffc-terminal-note" data-ffc-terminal-part="unlock-wait"></p>
		<?php
		Button::render(
			array(
				'label'    => __( 'Cancel', 'ffcertificate' ),
				'category' => 'neutral',
				'icon'     => 'chevron-left',
				'attrs'    => array( 'data-ffc-terminal-part' => 'unlock-cancel' ),
			)
		);
		?>
	</section>

	<section class="ffc-terminal-view ffc-terminal-menu" data-ffc-terminal-view="menu" hidden>
		<div class="ffc-terminal-menu-main">
		<h2 class="ffc-icon-unlock" data-ffc-terminal-part="operator"></h2>
		<p data-ffc-terminal-part="queue"></p>
		<p class="ffc-terminal-note"><?php esc_html_e( 'The menu locks again after two minutes without use.', 'ffcertificate' ); ?></p>
		<p class="ffc-terminal-note ffc-icon-warning" data-ffc-terminal-part="wake-note" hidden></p>
		<div class="ffc-terminal-actions" data-ffc-terminal-part="menu-ask">
			<?php
			Button::render(
				array(
					'label'    => __( 'Back to reading', 'ffcertificate' ),
					'category' => 'primary',
					'icon'     => 'qr',
					'attrs'    => array( 'data-ffc-terminal-part' => 'menu-close' ),
				)
			);
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
					'label'    => __( 'End terminal', 'ffcertificate' ),
					'category' => 'danger',
					'icon'     => 'cross',
					'attrs'    => array( 'data-ffc-terminal-part' => 'menu-end' ),
				)
			);
			?>
		</div>
		<div class="ffc-terminal-actions" data-ffc-terminal-part="menu-confirm" hidden>
			<p><?php esc_html_e( 'End this terminal? It sends the queue, stops checking people in at once, and pairing it again needs a new code.', 'ffcertificate' ); ?></p>
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
		<p class="ffc-terminal-label" data-ffc-terminal-part="label"></p>
		<p class="ffc-terminal-note" data-ffc-terminal-part="events"></p>
		<p class="ffc-terminal-note" data-ffc-terminal-part="until"></p>
		<section class="ffc-terminal-check" aria-labelledby="ffc-terminal-check-title">
			<h3 id="ffc-terminal-check-title" class="ffc-icon-monitor"><?php esc_html_e( 'Device check', 'ffcertificate' ); ?></h3>
			<p class="ffc-terminal-note"><?php esc_html_e( 'What this browser offers the terminal. Note it, or take a picture of it, when trying a new tablet.', 'ffcertificate' ); ?></p>
			<dl data-ffc-terminal-part="device-check"></dl>
		</section>
		</div>
		<section class="ffc-terminal-menu-side ffc-terminal-queue" aria-labelledby="ffc-terminal-queue-title">
			<h3 id="ffc-terminal-queue-title" class="ffc-icon-calendar"><?php esc_html_e( 'Events on this terminal', 'ffcertificate' ); ?></h3>
			<p class="ffc-terminal-note" data-ffc-terminal-part="queue-summary"></p>
			<ul class="ffc-terminal-queue-list" data-ffc-terminal-part="queue-list"></ul>
			<p class="ffc-terminal-error" role="alert" data-ffc-terminal-part="queue-error" hidden></p>
			<p class="ffc-terminal-note" data-ffc-terminal-part="queue-locked" hidden><?php esc_html_e( 'Only an operator who may pair terminals changes its events, here or in the app.', 'ffcertificate' ); ?></p>
			<div class="ffc-terminal-actions" data-ffc-terminal-part="queue-edit" hidden>
				<?php
				Button::render(
					array(
						'label'    => __( 'Add event', 'ffcertificate' ),
						'category' => 'create',
						'icon'     => 'plus',
						'attrs'    => array( 'data-ffc-terminal-part' => 'queue-open-add' ),
					)
				);
				?>
			</div>
			<div class="ffc-terminal-queue-add" data-ffc-terminal-part="queue-add" hidden>
				<h4><?php esc_html_e( 'Events this terminal can take', 'ffcertificate' ); ?></h4>
				<p class="ffc-terminal-note"><?php esc_html_e( 'Events whose check-in windows cross another one on this terminal are refused, naming the event they collide with.', 'ffcertificate' ); ?></p>
				<ul class="ffc-terminal-queue-list" data-ffc-terminal-part="queue-offered"></ul>
				<p class="ffc-terminal-note" data-ffc-terminal-part="queue-none" hidden><?php esc_html_e( 'No other event can be added now.', 'ffcertificate' ); ?></p>
				<?php
				Button::render(
					array(
						'label'    => __( 'Close', 'ffcertificate' ),
						'category' => 'neutral',
						'icon'     => 'cross',
						'attrs'    => array( 'data-ffc-terminal-part' => 'queue-close-add' ),
					)
				);
				?>
			</div>
		</section>
	</section>

	<footer class="ffc-terminal-foot" data-ffc-terminal-part="foot" hidden>
		<p class="ffc-terminal-conn" data-ffc-terminal-part="conn"></p>
		<p class="ffc-terminal-help"><?php esc_html_e( 'No app? Ask the team at the help desk.', 'ffcertificate' ); ?></p>
		<button type="button" class="ffc-btn ffc-btn-neutral ffc-btn-icon ffc-icon-lock ffc-terminal-lock" data-ffc-terminal-part="lock" aria-label="<?php esc_attr_e( 'Operator area', 'ffcertificate' ); ?>" title="<?php esc_attr_e( 'Operator area', 'ffcertificate' ); ?>"></button>
	</footer>
</main>
