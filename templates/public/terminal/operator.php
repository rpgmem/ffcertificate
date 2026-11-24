<?php
/**
 * The operator's terminal screens in the app (#2744).
 *
 * The markup only: `assets/js/ffc-terminal-operator.js` fills the lists, the
 * codes and the countdowns, and changes a paired terminal's events (#2746). One template serves the three screens, and
 * `$ffc_terminal_mode` says which: `pair`, `terminals` or `qr`. The pair
 * screen also reads `$ffc_terminal_auto_end_options` (hours => label) and
 * `$ffc_terminal_auto_end`, the hours chosen at first (#2781).
 *
 * @package FreeFormCertificate\Audience
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FreeFormCertificate\Admin\AdminUI;
use FreeFormCertificate\Audience\AudienceTerminalPairing;
use FreeFormCertificate\Core\Button;
use FreeFormCertificate\Core\Notice;

$ffc_terminal_auto_end_options = isset( $ffc_terminal_auto_end_options ) && is_array( $ffc_terminal_auto_end_options ) ? $ffc_terminal_auto_end_options : array();
$ffc_terminal_auto_end         = isset( $ffc_terminal_auto_end ) ? (int) $ffc_terminal_auto_end : 0;
$ffc_terminal_mode             = isset( $ffc_terminal_mode ) && in_array( $ffc_terminal_mode, array( 'pair', 'terminals', 'qr' ), true ) ? $ffc_terminal_mode : 'pair';
?>
<div class="ffc-shortcode ffc-terminal-operator" data-ffc-terminal-operator="<?php echo esc_attr( $ffc_terminal_mode ); ?>">
	<noscript>
		<?php echo Notice::render( 'warning', 'warning', __( 'This screen needs JavaScript. Turn it on in the browser and open it again.', 'ffcertificate' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Notice::render() escapes its own output. ?>
	</noscript>

	<p class="ffc-terminal-operator-status" role="status" aria-live="polite" data-ffc-op-part="status"></p>
	<p class="ffc-terminal-operator-error" role="alert" data-ffc-op-part="error" hidden></p>

	<?php if ( 'pair' === $ffc_terminal_mode ) : ?>
		<form class="ffc-terminal-operator-form ffc-terminal-operator-card" data-ffc-op-part="form" novalidate>
			<div class="ffc-terminal-operator-field" data-ffc-op-part="queue-mode" hidden>
				<p class="ffc-terminal-operator-heading" id="ffc-terminal-operator-queue"><?php esc_html_e( 'Event queue', 'ffcertificate' ); ?></p>
				<div role="group" aria-labelledby="ffc-terminal-operator-queue">
					<?php
					AdminUI::render_segmented(
						array(
							'name'         => 'ffc_terminal_queue',
							'id'           => 'ffc-terminal-operator-queue-mode',
							'fluid'        => true,
							'options'      => array(
								'pick'  => __( 'Choose events', 'ffcertificate' ),
								'place' => __( 'By place', 'ffcertificate' ),
							),
							'descriptions' => array(
								'place' => __( 'By place: the queue fills with the place\'s events. Check-in windows that cross are left out.', 'ffcertificate' ),
							),
							'value'        => 'pick',
						)
					);
					?>
				</div>
				<p class="ffc-terminal-operator-place" data-ffc-op-part="by-place" hidden>
					<label for="ffc-terminal-operator-place"><?php esc_html_e( 'Place', 'ffcertificate' ); ?></label>
					<select id="ffc-terminal-operator-place" data-ffc-op-part="place"></select>
				</p>
			</div>
			<fieldset class="ffc-terminal-operator-events">
				<legend class="ffc-terminal-operator-heading">
					<?php
					/* translators: %d: the most events one terminal may serve */
					echo esc_html( sprintf( __( 'Events (up to %d, next 7 days)', 'ffcertificate' ), AudienceTerminalPairing::MAX_BOOKINGS ) );
					?>
				</legend>
				<p class="description"><?php esc_html_e( 'A terminal checks in one event at a time: events whose check-in times cross cannot share it.', 'ffcertificate' ); ?></p>
				<div data-ffc-op-part="events"></div>
				<p class="description" data-ffc-op-part="events-empty" hidden><?php esc_html_e( 'No event in the next 7 days can take a terminal.', 'ffcertificate' ); ?></p>
			</fieldset>
			<div class="ffc-terminal-operator-field ffc-terminal-operator-inline">
				<p class="ffc-terminal-operator-heading" id="ffc-terminal-operator-auto-end"><?php esc_html_e( 'End by itself after the end', 'ffcertificate' ); ?></p>
				<div role="group" aria-labelledby="ffc-terminal-operator-auto-end">
					<?php
					AdminUI::render_segmented(
						array(
							'name'    => 'ffc_terminal_auto_end',
							'id'      => 'ffc-terminal-operator-auto-end-hours',
							'options' => $ffc_terminal_auto_end_options,
							'value'   => (string) $ffc_terminal_auto_end,
						)
					);
					?>
				</div>
			</div>
			<div class="ffc-terminal-operator-field">
				<label class="ffc-terminal-operator-heading" for="ffc-terminal-operator-label"><?php esc_html_e( 'Name for this terminal (optional)', 'ffcertificate' ); ?></label>
				<input id="ffc-terminal-operator-label" type="text" maxlength="100" autocomplete="off" placeholder="<?php esc_attr_e( 'Entrance A', 'ffcertificate' ); ?>" data-ffc-op-part="label">
			</div>
			<?php
			Button::render(
				array(
					'label'    => __( 'Generate pairing code', 'ffcertificate' ),
					'category' => 'primary',
					'icon'     => 'qr',
					'type'     => 'submit',
					'attrs'    => array( 'data-ffc-op-part' => 'generate' ),
				)
			);
			?>
		</form>

		<section class="ffc-terminal-operator-result ffc-terminal-operator-card" data-ffc-op-part="result" aria-labelledby="ffc-terminal-operator-result-title" hidden>
			<div class="ffc-terminal-operator-qr" data-ffc-op-part="qr"></div>
			<div class="ffc-terminal-operator-result-text">
				<h2 class="ffc-terminal-operator-heading" id="ffc-terminal-operator-result-title"><?php esc_html_e( 'Hold to the terminal\'s reader', 'ffcertificate' ); ?></h2>
				<p class="ffc-terminal-operator-code" data-ffc-op-part="code"></p>
				<p class="ffc-terminal-operator-countdown" data-ffc-op-part="countdown"></p>
				<p class="description" data-ffc-op-part="summary"></p>
				<p class="description"><?php esc_html_e( 'Open the terminal page on the device and hold this code to its reader, or type it there.', 'ffcertificate' ); ?></p>
				<?php
				Button::render(
					array(
						'label'    => __( 'Generate another code', 'ffcertificate' ),
						'category' => 'neutral',
						'icon'     => 'sync',
						'attrs'    => array( 'data-ffc-op-part' => 'again' ),
					)
				);
				?>
			</div>
		</section>
	<?php elseif ( 'terminals' === $ffc_terminal_mode ) : ?>
		<section class="ffc-terminal-operator-card" aria-labelledby="ffc-terminal-operator-list-title">
			<h2 class="ffc-terminal-operator-heading" id="ffc-terminal-operator-list-title"><?php esc_html_e( 'Active terminals', 'ffcertificate' ); ?></h2>
			<p class="description" data-ffc-op-part="summary"></p>
		</section>
		<ul class="ffc-terminal-operator-list" data-ffc-op-part="list"></ul>
		<p class="description" data-ffc-op-part="list-empty" hidden><?php esc_html_e( 'No terminal is paired. Pair one under Pair terminal.', 'ffcertificate' ); ?></p>
		<template data-ffc-op-part="row">
			<li class="ffc-terminal-operator-item">
				<p class="ffc-terminal-operator-item-label" data-ffc-op-item="label"></p>
				<p class="description" data-ffc-op-item="events"></p>
				<p class="description" data-ffc-op-item="seen"></p>
				<p class="description" data-ffc-op-item="until"></p>
				<div class="ffc-terminal-operator-actions" data-ffc-op-item="ask">
					<?php
					Button::render(
						array(
							'label'    => __( 'Change events', 'ffcertificate' ),
							'category' => 'neutral',
							'icon'     => 'edit',
							'attrs'    => array( 'data-ffc-op-item' => 'edit' ),
						)
					);
					Button::render(
						array(
							'label'    => __( 'End terminal', 'ffcertificate' ),
							'category' => 'danger',
							'icon'     => 'cross',
							'attrs'    => array( 'data-ffc-op-item' => 'end' ),
						)
					);
					?>
				</div>
				<fieldset class="ffc-terminal-operator-editor" data-ffc-op-item="editor" hidden>
					<legend><?php esc_html_e( 'Events this terminal checks in', 'ffcertificate' ); ?></legend>
					<div data-ffc-op-item="choices"></div>
					<p class="description"><?php esc_html_e( 'The terminal picks the change up within a minute. An event you remove keeps the readings already taken for it.', 'ffcertificate' ); ?></p>
					<?php
					Button::render(
						array(
							'label'    => __( 'Cancel', 'ffcertificate' ),
							'category' => 'neutral',
							'icon'     => 'cross',
							'attrs'    => array( 'data-ffc-op-item' => 'cancel' ),
						)
					);
					Button::render(
						array(
							'label'    => __( 'Save events', 'ffcertificate' ),
							'category' => 'primary',
							'icon'     => 'save',
							'attrs'    => array( 'data-ffc-op-item' => 'save' ),
						)
					);
					?>
				</fieldset>
				<div class="ffc-terminal-operator-actions" data-ffc-op-item="confirm" hidden>
					<p><?php esc_html_e( 'End this terminal? It stops checking people in at once.', 'ffcertificate' ); ?></p>
					<?php
					Button::render(
						array(
							'label'    => __( 'Keep', 'ffcertificate' ),
							'category' => 'neutral',
							'icon'     => 'cross',
							'attrs'    => array( 'data-ffc-op-item' => 'keep' ),
						)
					);
					Button::render(
						array(
							'label'    => __( 'End terminal', 'ffcertificate' ),
							'category' => 'danger',
							'icon'     => 'cross',
							'attrs'    => array( 'data-ffc-op-item' => 'confirm-end' ),
						)
					);
					?>
				</div>
			</li>
		</template>
	<?php else : ?>
		<section class="ffc-terminal-operator-card ffc-terminal-operator-unlock" aria-labelledby="ffc-terminal-operator-qr-title">
			<h2 class="ffc-terminal-operator-heading" id="ffc-terminal-operator-qr-title"><?php esc_html_e( 'Unlock a paired terminal', 'ffcertificate' ); ?></h2>
			<div class="ffc-terminal-operator-qr" data-ffc-op-part="qr"></div>
			<p class="ffc-terminal-operator-countdown" data-ffc-op-part="countdown"></p>
			<p class="description"><?php esc_html_e( 'Hold this code to a terminal\'s reader to unlock its operator menu. It changes every two minutes and works only for you.', 'ffcertificate' ); ?></p>
		</section>
	<?php endif; ?>
</div>
