<?php
/**
 * The operator's terminal screens in the app (#2744).
 *
 * The markup only: `assets/js/ffc-terminal-operator.js` fills the lists, the
 * codes and the countdowns, and changes a paired terminal's events (#2746). One template serves both screens, and
 * `$ffc_terminal_mode` says which: `terminals` or `qr`. A terminal is paired
 * on the terminal itself (#2900).
 *
 * @package FreeFormCertificate\Audience
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FreeFormCertificate\Core\Button;
use FreeFormCertificate\Core\Notice;

$ffc_terminal_mode = isset( $ffc_terminal_mode ) && in_array( $ffc_terminal_mode, array( 'terminals', 'qr' ), true ) ? $ffc_terminal_mode : 'terminals';
?>
<div class="ffc-shortcode ffc-terminal-operator" data-ffc-terminal-operator="<?php echo esc_attr( $ffc_terminal_mode ); ?>">
	<noscript>
		<?php echo Notice::render( 'warning', 'warning', __( 'This screen needs JavaScript. Turn it on in the browser and open it again.', 'ffcertificate' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Notice::render() escapes its own output. ?>
	</noscript>

	<p class="ffc-terminal-operator-status" role="status" aria-live="polite" data-ffc-op-part="status"></p>
	<p class="ffc-terminal-operator-error" role="alert" data-ffc-op-part="error" hidden></p>

	<?php if ( 'terminals' === $ffc_terminal_mode ) : ?>
		<section class="ffc-terminal-operator-card" aria-labelledby="ffc-terminal-operator-list-title">
			<h2 class="ffc-terminal-operator-heading" id="ffc-terminal-operator-list-title"><?php esc_html_e( 'Active terminals', 'ffcertificate' ); ?></h2>
			<p class="description" data-ffc-op-part="summary"></p>
		</section>
		<ul class="ffc-terminal-operator-list" data-ffc-op-part="list"></ul>
		<p class="description" data-ffc-op-part="list-empty" hidden><?php esc_html_e( 'No terminal is paired. A terminal is paired on the terminal itself: tap its lock and show your Operator QR.', 'ffcertificate' ); ?></p>
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
