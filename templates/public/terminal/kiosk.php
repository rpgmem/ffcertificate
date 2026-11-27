<?php
/**
 * The check-in terminal's page (#2744, #2780).
 *
 * The markup only: `assets/js/ffc-terminal-kiosk.js` shows one screen at a
 * time. It pairs the terminal at its lock (#2744, #2900), reads presence codes online or into
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
 * @var string $ffc_terminal_logo        Site icon URL, or ''.
 * @var string $ffc_terminal_install_url The install page's address (#2813).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FreeFormCertificate\Admin\AdminUI;
use FreeFormCertificate\Audience\AudienceTerminalPairing;
use FreeFormCertificate\Core\Button;
use FreeFormCertificate\Core\Icons;
use FreeFormCertificate\Core\Notice;

$ffc_terminal_logo        = isset( $ffc_terminal_logo ) ? (string) $ffc_terminal_logo : '';
$ffc_terminal_install_url = isset( $ffc_terminal_install_url ) ? (string) $ffc_terminal_install_url : '';
$ffc_terminal_site        = (string) get_bloginfo( 'name' );
?>
<?php if ( '' !== $ffc_terminal_install_url ) : ?>
<aside class="ffc-terminal-install-banner" data-ffc-terminal-install-banner aria-label="<?php esc_attr_e( 'Install this terminal', 'ffcertificate' ); ?>" hidden>
	<span class="ffc-terminal-install-banner-icon" aria-hidden="true"><?php echo Icons::svg( 'download', 26 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons::svg() prints the registry's fixed markup. ?></span>
	<p><strong><?php esc_html_e( 'Install this terminal as an app.', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'It opens full screen, without the browser\'s bar, and comes back to this screen by itself.', 'ffcertificate' ); ?></p>
	<?php
	Button::render(
		array(
			'label'    => __( 'Install', 'ffcertificate' ),
			'category' => 'primary',
			'icon'     => 'download',
			'href'     => $ffc_terminal_install_url,
		)
	);
	Button::render(
		array(
			'label'     => __( 'Not now', 'ffcertificate' ),
			'category'  => 'neutral',
			'icon'      => 'cross',
			'icon_only' => true,
			'attrs'     => array( 'data-ffc-terminal-install-dismiss' => '' ),
		)
	);
	?>
</aside>
<?php endif; ?>
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

	<?php
	// Shown until the script starts and hides it (#2910): every view and the
	// footer start hidden, so a script that fails to start would otherwise
	// leave a blank screen that says nothing. It fades in after a moment, so
	// a normal start never flashes it.
	?>
	<p class="ffc-terminal-boot ffc-icon-info" role="status" data-ffc-terminal-part="boot"><?php esc_html_e( 'Starting the terminal… If this message stays, the terminal could not start: close it and open it again, and tell the event team if it happens again.', 'ffcertificate' ); ?></p>

	<p class="ffc-terminal-status" role="status" aria-live="polite" data-ffc-terminal-part="status"></p>
	<p class="ffc-terminal-error" role="alert" data-ffc-terminal-part="error" hidden></p>
	<p class="ffc-terminal-error ffc-terminal-offline" role="status" data-ffc-terminal-part="offline" hidden></p>

	<form class="ffc-terminal-reader" data-ffc-terminal-part="reading-form" novalidate>
		<label for="ffc-terminal-reading"><?php esc_html_e( 'Code read', 'ffcertificate' ); ?></label>
		<input id="ffc-terminal-reading" name="reading" type="text" autocomplete="off" autocapitalize="characters" spellcheck="false" inputmode="none" data-ffc-terminal-part="reading">
		<button type="submit"><?php esc_html_e( 'Check in', 'ffcertificate' ); ?></button>
	</form>

	<section class="ffc-terminal-view" data-ffc-terminal-view="idle" hidden>
		<div class="ffc-terminal-badge" aria-hidden="true"><?php echo Icons::svg( 'lock', 88 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons::svg() prints the registry's fixed markup. ?></div>
		<h2><?php esc_html_e( 'Terminal without events', 'ffcertificate' ); ?></h2>
		<p class="ffc-terminal-lead"><?php esc_html_e( 'An operator must release this terminal: tap the lock below and hold the operator QR code to the reader.', 'ffcertificate' ); ?></p>
	</section>

	<section class="ffc-terminal-view" data-ffc-terminal-view="unpaired" hidden>
		<div class="ffc-terminal-badge" aria-hidden="true"><?php echo Icons::svg( 'link', 88 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons::svg() prints the registry's fixed markup. ?></div>
		<h2><?php esc_html_e( 'This terminal is not paired', 'ffcertificate' ); ?></h2>
		<p class="ffc-terminal-lead"><?php esc_html_e( 'It does not read presence codes or issue certificates yet. The event team pairs it.', 'ffcertificate' ); ?></p>
	</section>

	<section class="ffc-terminal-view ffc-terminal-setup" data-ffc-terminal-view="setup" hidden>
		<div class="ffc-terminal-setup-head">
			<h2 class="ffc-icon-link"><?php esc_html_e( 'Set up this terminal', 'ffcertificate' ); ?></h2>
			<p class="ffc-terminal-note" data-ffc-terminal-part="setup-operator"></p>
		</div>
		<form class="ffc-terminal-setup-form" data-ffc-terminal-part="setup-form" novalidate>
			<div class="ffc-terminal-setup-main">
				<div role="group" aria-label="<?php esc_attr_e( 'Pair by', 'ffcertificate' ); ?>" data-ffc-terminal-part="setup-mode">
					<?php
					AdminUI::render_segmented(
						array(
							'name'    => 'ffc_terminal_setup_mode',
							'id'      => 'ffc-terminal-setup-mode',
							'fluid'   => true,
							'size'    => 'lg',
							'options' => array(
								'event' => __( 'By event', 'ffcertificate' ),
								'place' => __( 'By place', 'ffcertificate' ),
							),
							'value'   => 'place',
						)
					);
					?>
				</div>
				<div class="ffc-terminal-setup-by-place" data-ffc-terminal-part="setup-by-place">
					<div class="ffc-terminal-field">
						<label for="ffc-terminal-setup-location"><?php esc_html_e( 'Place', 'ffcertificate' ); ?></label>
						<select id="ffc-terminal-setup-location" data-ffc-terminal-part="setup-location"></select>
					</div>
					<p class="ffc-terminal-note"><?php esc_html_e( 'The GPS locations of Settings → Geolocation. The terminal stays tied to this place: an event or a certificate form created for it later joins on its own when the terminal connects.', 'ffcertificate' ); ?></p>
					<p class="ffc-terminal-note" data-ffc-terminal-part="setup-no-location" hidden><?php esc_html_e( 'No GPS location is set in Settings → Geolocation. Pair by event, or add a location there first.', 'ffcertificate' ); ?></p>
				</div>
				<p class="ffc-terminal-note" data-ffc-terminal-part="setup-by-event" hidden>
					<?php
					/* translators: %d: the most items one terminal may hold */
					echo esc_html( sprintf( __( 'Tick the events and certificate forms this terminal reads, up to %d. The queue stays as it was ticked.', 'ffcertificate' ), AudienceTerminalPairing::MAX_BOOKINGS ) );
					?>
				</p>
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
			</div>
			<section class="ffc-terminal-setup-items" aria-labelledby="ffc-terminal-setup-items-title">
				<h3 id="ffc-terminal-setup-items-title" data-ffc-terminal-part="setup-list-title"></h3>
				<p class="ffc-terminal-note" data-ffc-terminal-part="setup-count" hidden></p>
				<ul class="ffc-terminal-queue-list" data-ffc-terminal-part="setup-list"></ul>
				<p class="ffc-terminal-note" data-ffc-terminal-part="setup-none" hidden></p>
				<p class="ffc-terminal-note"><?php esc_html_e( 'It reads one item at a time; one whose hours cross waits for the earlier one to close.', 'ffcertificate' ); ?></p>
			</section>
		</form>
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
		<p class="ffc-terminal-note ffc-icon-warning" data-ffc-terminal-part="clock-note" hidden></p>
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
		</div>
		<div class="ffc-terminal-menu-side">
		<section class="ffc-terminal-queue" aria-labelledby="ffc-terminal-queue-title">
			<h3 id="ffc-terminal-queue-title" class="ffc-icon-calendar"><?php esc_html_e( 'Events on this terminal', 'ffcertificate' ); ?></h3>
			<p class="ffc-terminal-note" data-ffc-terminal-part="queue-summary"></p>
			<ul class="ffc-terminal-queue-list" data-ffc-terminal-part="queue-list"></ul>
			<p class="ffc-terminal-error" role="alert" data-ffc-terminal-part="queue-error" hidden></p>
			<p class="ffc-terminal-note ffc-icon-map-pin" data-ffc-terminal-part="queue-follows" hidden></p>
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
				<div role="group" aria-label="<?php esc_attr_e( 'Add by', 'ffcertificate' ); ?>" data-ffc-terminal-part="queue-add-mode">
					<?php
					AdminUI::render_segmented(
						array(
							'name'    => 'ffc_terminal_add_mode',
							'id'      => 'ffc-terminal-add-mode',
							'fluid'   => true,
							'options' => array(
								'event' => __( 'By event', 'ffcertificate' ),
								'place' => __( 'By place', 'ffcertificate' ),
							),
							'value'   => 'event',
						)
					);
					?>
				</div>
				<div data-ffc-terminal-part="queue-add-by-event">
					<p class="ffc-terminal-note"><?php esc_html_e( 'Events whose check-in windows cross another one on this terminal are refused, naming the event they collide with.', 'ffcertificate' ); ?></p>
					<ul class="ffc-terminal-queue-list" data-ffc-terminal-part="queue-offered"></ul>
					<p class="ffc-terminal-note" data-ffc-terminal-part="queue-none" hidden><?php esc_html_e( 'No other event can be added now.', 'ffcertificate' ); ?></p>
				</div>
				<div data-ffc-terminal-part="queue-add-by-place" hidden>
					<div class="ffc-terminal-field">
						<label for="ffc-terminal-add-location"><?php esc_html_e( 'Place', 'ffcertificate' ); ?></label>
						<select id="ffc-terminal-add-location" data-ffc-terminal-part="queue-add-location"></select>
					</div>
					<p class="ffc-terminal-note"><?php esc_html_e( 'The terminal stops holding the events ticked for it and follows this place from now on: what is created for the place joins on its own, and what ends leaves.', 'ffcertificate' ); ?></p>
					<p class="ffc-terminal-note" data-ffc-terminal-part="queue-add-no-location" hidden><?php esc_html_e( 'No GPS location is set in Settings → Geolocation.', 'ffcertificate' ); ?></p>
					<ul class="ffc-terminal-queue-list" data-ffc-terminal-part="queue-place-list"></ul>
					<p class="ffc-terminal-note" data-ffc-terminal-part="queue-place-none" hidden><?php esc_html_e( 'Nothing at this place in the next 7 days takes the terminal yet. What is created for it later joins on its own.', 'ffcertificate' ); ?></p>
					<div class="ffc-terminal-actions">
						<?php
						Button::render(
							array(
								'label'    => __( 'Follow this place', 'ffcertificate' ),
								'category' => 'caution',
								'icon'     => 'map-pin',
								'attrs'    => array( 'data-ffc-terminal-part' => 'queue-follow' ),
							)
						);
						?>
					</div>
				</div>
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
		<section class="ffc-terminal-check" aria-labelledby="ffc-terminal-check-title">
			<h3 id="ffc-terminal-check-title" class="ffc-icon-monitor"><?php esc_html_e( 'Device check', 'ffcertificate' ); ?></h3>
			<p class="ffc-terminal-note"><?php esc_html_e( 'What this browser offers the terminal. Note it, or take a picture of it, when trying a new tablet.', 'ffcertificate' ); ?></p>
			<dl data-ffc-terminal-part="device-check"></dl>
		</section>
		</div>
	</section>

	<footer class="ffc-terminal-foot" data-ffc-terminal-part="foot" hidden>
		<p class="ffc-terminal-conn" data-ffc-terminal-part="conn"></p>
		<p class="ffc-terminal-help" data-ffc-terminal-part="help"><?php esc_html_e( 'No app? Ask the team at the help desk.', 'ffcertificate' ); ?></p>
		<p class="ffc-terminal-help" data-ffc-terminal-part="help-operator" hidden><?php esc_html_e( 'Operator: tap the lock', 'ffcertificate' ); ?></p>
		<button type="button" class="ffc-btn ffc-btn-neutral ffc-btn-icon ffc-icon-lock ffc-terminal-lock" data-ffc-terminal-part="lock" aria-label="<?php esc_attr_e( 'Operator area', 'ffcertificate' ); ?>" title="<?php esc_attr_e( 'Operator area', 'ffcertificate' ); ?>"></button>
	</footer>
</main>
