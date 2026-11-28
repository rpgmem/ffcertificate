<?php
/**
 * What a phone sees when it opens a terminal's on-site sign-up code (#2968).
 *
 * Public, in the install page's look. A valid code names the event it is
 * for; a refused one says why and what to do. The sign-up form itself joins
 * this page with the switch that lets an event take on-site sign-up.
 *
 * @package FreeFormCertificate\Audience
 *
 * @var string                                                                                                                    $ffc_terminal_logo Site icon URL.
 * @var array{terminal_id: int, target_type: string, target_id: int, key: int|string, issued_at: int, title: string}|\WP_Error $ffc_join_result   What the code verified to.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FreeFormCertificate\Core\Icons;

$ffc_terminal_logo = isset( $ffc_terminal_logo ) ? (string) $ffc_terminal_logo : '';
$ffc_terminal_site = (string) get_bloginfo( 'name' );
$ffc_join_refused  = ! isset( $ffc_join_result ) || is_wp_error( $ffc_join_result );
?>
<main class="ffc-terminal-install" data-ffc-terminal-join="<?php echo $ffc_join_refused ? 'refused' : 'valid'; ?>">
	<header class="ffc-terminal-install-hero">
		<span class="ffc-terminal-install-logo" aria-hidden="true">
			<?php if ( '' !== $ffc_terminal_logo ) : ?>
				<img src="<?php echo esc_url( $ffc_terminal_logo ); ?>" alt="">
			<?php else : ?>
				<?php echo Icons::svg( 'qr', 72 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons::svg() prints the registry's fixed markup. ?>
			<?php endif; ?>
		</span>
		<h1><?php esc_html_e( 'Sign up at the event', 'ffcertificate' ); ?></h1>
		<?php if ( '' !== $ffc_terminal_site ) : ?>
			<p class="ffc-terminal-install-site"><?php echo esc_html( $ffc_terminal_site ); ?></p>
		<?php endif; ?>
	</header>

	<div class="ffc-terminal-install-body">
		<?php if ( $ffc_join_refused ) : ?>
			<section class="ffc-terminal-install-card" role="alert">
				<p class="ffc-terminal-install-done"><?php echo Icons::svg( 'clock', 24 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons::svg() prints the registry's fixed markup. ?><span><?php esc_html_e( 'This code cannot be used', 'ffcertificate' ); ?></span></p>
				<p><?php echo esc_html( isset( $ffc_join_result ) && is_wp_error( $ffc_join_result ) ? $ffc_join_result->get_error_message() : __( 'This is not a sign-up code. Read the code on the terminal again.', 'ffcertificate' ) ); ?></p>
				<p><?php esc_html_e( 'The terminal\'s code changes every few seconds and works only at the event, while check-in is open.', 'ffcertificate' ); ?></p>
			</section>
		<?php else : ?>
			<section class="ffc-terminal-install-card">
				<p class="ffc-terminal-install-done"><?php echo Icons::svg( 'check', 24 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons::svg() prints the registry's fixed markup. ?><span><?php echo esc_html( $ffc_join_result['title'] ); ?></span></p>
				<p><?php esc_html_e( 'The code is valid. Signing up on this page is not available yet: ask the event operator.', 'ffcertificate' ); ?></p>
			</section>
		<?php endif; ?>
	</div>
</main>
