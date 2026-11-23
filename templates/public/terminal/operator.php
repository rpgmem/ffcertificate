<?php
/**
 * The operator's terminal screens in the app (#2744).
 *
 * The markup only: `assets/js/ffc-terminal-operator.js` fills the lists, the
 * codes and the countdowns. One template serves the three screens, and
 * `$ffc_terminal_mode` says which: `pair`, `terminals` or `qr`.
 *
 * @package FreeFormCertificate\Audience
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FreeFormCertificate\Core\Button;
use FreeFormCertificate\Core\Notice;

$ffc_terminal_mode = isset( $ffc_terminal_mode ) && in_array( $ffc_terminal_mode, array( 'pair', 'terminals', 'qr' ), true ) ? $ffc_terminal_mode : 'pair';
?>
<div class="ffc-shortcode ffc-terminal-operator" data-ffc-terminal-operator="<?php echo esc_attr( $ffc_terminal_mode ); ?>">
	<noscript>
		<?php echo Notice::render( 'warning', 'warning', __( 'This screen needs JavaScript. Turn it on in the browser and open it again.', 'ffcertificate' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Notice::render() escapes its own output. ?>
	</noscript>

	<p class="ffc-terminal-operator-status" role="status" aria-live="polite" data-ffc-op-part="status"></p>
	<p class="ffc-terminal-operator-error" role="alert" data-ffc-op-part="error" hidden></p>

	<?php if ( 'pair' === $ffc_terminal_mode ) : ?>
		<form class="ffc-terminal-operator-form" data-ffc-op-part="form" novalidate>
			<fieldset class="ffc-terminal-operator-events">
				<legend><?php esc_html_e( 'Events this terminal checks in', 'ffcertificate' ); ?></legend>
				<div data-ffc-op-part="events"></div>
				<p class="description" data-ffc-op-part="events-empty" hidden><?php esc_html_e( 'No event in the next 7 days can take a terminal.', 'ffcertificate' ); ?></p>
			</fieldset>
			<label for="ffc-terminal-operator-label"><?php esc_html_e( 'Name for this terminal (optional)', 'ffcertificate' ); ?></label>
			<input id="ffc-terminal-operator-label" type="text" maxlength="100" autocomplete="off" placeholder="<?php esc_attr_e( 'Entrance A', 'ffcertificate' ); ?>" data-ffc-op-part="label">
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

		<section class="ffc-terminal-operator-result" data-ffc-op-part="result" hidden>
			<div class="ffc-terminal-operator-qr" data-ffc-op-part="qr"></div>
			<p class="ffc-terminal-operator-code" data-ffc-op-part="code"></p>
			<p class="ffc-terminal-operator-countdown" data-ffc-op-part="countdown"></p>
			<p class="description"><?php esc_html_e( 'Open the terminal page on the device and hold this code to its reader, or type it there. The code works once.', 'ffcertificate' ); ?></p>
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
		</section>
	<?php elseif ( 'terminals' === $ffc_terminal_mode ) : ?>
		<p class="description" data-ffc-op-part="summary"></p>
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
							'label'    => __( 'End terminal', 'ffcertificate' ),
							'category' => 'danger',
							'icon'     => 'cross',
							'attrs'    => array( 'data-ffc-op-item' => 'end' ),
						)
					);
					?>
				</div>
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
		<div class="ffc-terminal-operator-qr" data-ffc-op-part="qr"></div>
		<p class="ffc-terminal-operator-countdown" data-ffc-op-part="countdown"></p>
		<p class="description"><?php esc_html_e( 'Hold this code to a terminal\'s reader to unlock its operator menu. It changes every two minutes and works only for you.', 'ffcertificate' ); ?></p>
	<?php endif; ?>
</div>
