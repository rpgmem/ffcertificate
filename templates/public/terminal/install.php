<?php
/**
 * The check-in terminal's install page, `/terminal/install/` (#2813).
 *
 * Public, the same for everyone: no pairing, no credential, no personal
 * data. Every state is in the markup and hidden; `ffc-terminal-install.js`
 * shows the one that fits the browser. Without JavaScript only the
 * `<noscript>` hint shows.
 *
 * @package FreeFormCertificate\Audience
 *
 * @var string $ffc_terminal_logo        Site icon URL, or ''.
 * @var string $ffc_terminal_url         The terminal page's address.
 * @var string $ffc_terminal_install_url This page's address.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FreeFormCertificate\Core\Button;
use FreeFormCertificate\Core\Icons;

$ffc_terminal_logo        = isset( $ffc_terminal_logo ) ? (string) $ffc_terminal_logo : '';
$ffc_terminal_url         = isset( $ffc_terminal_url ) ? (string) $ffc_terminal_url : '';
$ffc_terminal_install_url = isset( $ffc_terminal_install_url ) ? (string) $ffc_terminal_install_url : '';
$ffc_terminal_site        = (string) get_bloginfo( 'name' );

$ffc_terminal_steps = static function ( string $first ): void {
	?>
	<ol class="ffc-terminal-install-steps">
		<li><span class="ffc-terminal-install-step-icon" aria-hidden="true"><?php echo Icons::svg( 'share', 28 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons::svg() prints the registry's fixed markup. ?></span><span><?php echo esc_html( $first ); ?></span></li>
		<li><span class="ffc-terminal-install-step-icon" aria-hidden="true"><?php echo Icons::svg( 'plus', 28 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons::svg() prints the registry's fixed markup. ?></span><span><?php esc_html_e( 'Choose Add to Home Screen. If it is not there, scroll the list or tap More.', 'ffcertificate' ); ?></span></li>
		<li><span class="ffc-terminal-install-step-icon" aria-hidden="true"><?php echo Icons::svg( 'check', 28 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons::svg() prints the registry's fixed markup. ?></span><span><?php esc_html_e( 'Tap Add.', 'ffcertificate' ); ?></span></li>
	</ol>
	<?php
};

$ffc_terminal_address = static function () use ( $ffc_terminal_install_url ): void {
	?>
	<input class="ffc-terminal-install-address" type="text" readonly value="<?php echo esc_url( $ffc_terminal_install_url ); ?>" aria-label="<?php esc_attr_e( 'Address of this page', 'ffcertificate' ); ?>" data-ffc-terminal-install-address>
	<?php
	Button::render(
		array(
			'label'    => __( 'Copy address', 'ffcertificate' ),
			'category' => 'primary',
			'icon'     => 'copy',
			'class'    => 'ffc-btn-block',
			'attrs'    => array( 'data-ffc-terminal-install-copy' => '' ),
		)
	);
};
?>
<main class="ffc-terminal-install" data-ffc-terminal-install-page data-copied="<?php esc_attr_e( 'Address copied.', 'ffcertificate' ); ?>" data-copy-failed="<?php esc_attr_e( 'Select the address and copy it.', 'ffcertificate' ); ?>">
	<header class="ffc-terminal-install-hero">
		<span class="ffc-terminal-install-logo" aria-hidden="true">
			<?php if ( '' !== $ffc_terminal_logo ) : ?>
				<img src="<?php echo esc_url( $ffc_terminal_logo ); ?>" alt="">
			<?php else : ?>
				<?php echo Icons::svg( 'qr', 72 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons::svg() prints the registry's fixed markup. ?>
			<?php endif; ?>
		</span>
		<h1><?php esc_html_e( 'Check-in terminal', 'ffcertificate' ); ?></h1>
		<?php if ( '' !== $ffc_terminal_site ) : ?>
			<p class="ffc-terminal-install-site"><?php echo esc_html( $ffc_terminal_site ); ?></p>
		<?php endif; ?>
		<p><?php esc_html_e( 'Install it on this tablet: it opens full screen, without the browser\'s bar, and asks for no login. The operator\'s pairing QR code is what binds the tablet to an event.', 'ffcertificate' ); ?></p>
	</header>

	<div class="ffc-terminal-install-body">
		<section class="ffc-terminal-install-card" data-state="wait" hidden>
			<p><?php esc_html_e( 'Checking this browser…', 'ffcertificate' ); ?></p>
		</section>

		<section class="ffc-terminal-install-card" data-state="prompt" hidden>
			<?php
			Button::render(
				array(
					'label'    => __( 'Install the terminal', 'ffcertificate' ),
					'category' => 'primary',
					'icon'     => 'download',
					'class'    => 'ffc-btn-block',
					'attrs'    => array( 'data-ffc-terminal-install-go' => '' ),
				)
			);
			?>
			<p><?php esc_html_e( 'The browser confirms in its own window. The Terminal icon goes to the home screen.', 'ffcertificate' ); ?></p>
		</section>

		<section class="ffc-terminal-install-card" data-state="ios-top" hidden>
			<h2><?php esc_html_e( 'Add it to the home screen in three taps', 'ffcertificate' ); ?></h2>
			<?php $ffc_terminal_steps( __( 'Tap Share, at the top, beside the address.', 'ffcertificate' ) ); ?>
			<p><?php esc_html_e( 'On iPad and iPhone no browser installs with a button; these steps are the official way.', 'ffcertificate' ); ?></p>
		</section>

		<section class="ffc-terminal-install-card" data-state="ios-bottom" hidden>
			<h2><?php esc_html_e( 'Add it to the home screen in three taps', 'ffcertificate' ); ?></h2>
			<?php $ffc_terminal_steps( __( 'Tap Share in Safari\'s toolbar, at the bottom.', 'ffcertificate' ) ); ?>
			<p><?php esc_html_e( 'On iPad and iPhone no browser installs with a button; these steps are the official way.', 'ffcertificate' ); ?></p>
		</section>

		<section class="ffc-terminal-install-card" data-state="ios-old" hidden>
			<p><?php esc_html_e( 'On this version of iOS only Safari adds apps to the home screen. Open this address in Safari to install.', 'ffcertificate' ); ?></p>
			<?php $ffc_terminal_address(); ?>
		</section>

		<section class="ffc-terminal-install-card" data-state="installed" hidden>
			<p class="ffc-terminal-install-done"><?php echo Icons::svg( 'check', 24 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons::svg() prints the registry's fixed markup. ?><span><?php esc_html_e( 'Already installed on this device', 'ffcertificate' ); ?></span></p>
			<?php
			Button::render(
				array(
					'label'    => __( 'Open the terminal', 'ffcertificate' ),
					'category' => 'primary',
					'icon'     => 'external',
					'class'    => 'ffc-btn-block',
					'href'     => $ffc_terminal_url,
				)
			);
			?>
			<p><?php esc_html_e( 'It did not open? Look for the Terminal icon on the home screen.', 'ffcertificate' ); ?></p>
		</section>

		<section class="ffc-terminal-install-card" data-state="menu" hidden>
			<p><?php esc_html_e( 'This browser has not offered its install button here yet. Open the browser\'s menu (⋮) and choose Install app or Add to Home screen. If the button arrives, it appears on this page.', 'ffcertificate' ); ?></p>
		</section>

		<section class="ffc-terminal-install-card" data-state="unsupported" hidden>
			<p><?php esc_html_e( 'This browser offers no install button. Open this address in Chrome (Android) or Safari (iPad), or look for Install app or Add to Home screen in the browser\'s menu.', 'ffcertificate' ); ?></p>
			<?php $ffc_terminal_address(); ?>
		</section>
		<noscript>
			<section class="ffc-terminal-install-card">
				<p><?php esc_html_e( 'Look for Install app or Add to Home screen in the browser\'s menu.', 'ffcertificate' ); ?></p>
			</section>
		</noscript>

		<p class="ffc-terminal-install-status" role="status" data-ffc-terminal-install-status></p>
		<p class="ffc-terminal-install-continue"><a href="<?php echo esc_url( $ffc_terminal_url ); ?>"><?php esc_html_e( 'Continue in the browser', 'ffcertificate' ); ?></a></p>
	</div>
</main>
