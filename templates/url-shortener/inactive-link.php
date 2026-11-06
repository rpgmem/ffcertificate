<?php
/**
 * Template: the page a dead short URL answers with (#2337).
 *
 * A document of its own, outside the theme, so it renders the same on every
 * site: its CSS is inline for that reason (CLAUDE.md "Inline CSS"). The meta
 * refresh goes home after the countdown without JavaScript; the script only
 * ticks the number.
 *
 * @var string $home_url  Home page URL.
 * @var string $site_name Site name.
 * @var int    $seconds   Countdown in seconds.
 *
 * @package FreeFormCertificate\UrlShortener
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php echo esc_attr( get_bloginfo( 'charset' ) ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta http-equiv="refresh" content="<?php echo esc_attr( $seconds . ';url=' . $home_url ); ?>">
<title><?php echo esc_html( __( 'This link is no longer active', 'ffcertificate' ) . ' · ' . $site_name ); ?></title>
<style>
:root { color-scheme: light dark; --bg: #eef1f4; --card: #fff; --fg: #1d2327; --muted: #50575e; --line: #dcdcde; --accent: #2271b1; --on-accent: #fff; }
@media (prefers-color-scheme: dark) { :root { --bg: #1a1d21; --card: #1f2328; --fg: #e6e8ea; --muted: #a7aaad; --line: #353b42; --accent: #72aee6; --on-accent: #0b1a28; } }
body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px 16px; box-sizing: border-box; background: var(--bg); color: var(--fg); font: 16px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
main { background: var(--card); border: 1px solid var(--line); border-radius: 12px; max-width: 420px; width: 100%; padding: 28px; box-sizing: border-box; text-align: center; display: flex; flex-direction: column; gap: 12px; }
.site { font-size: 14px; color: var(--muted); }
h1 { font-size: 22px; margin: 0; }
p { margin: 0; }
.count { font-variant-numeric: tabular-nums; font-weight: 600; }
a { display: inline-flex; align-self: center; align-items: center; min-height: 44px; padding: 0 18px; border-radius: 8px; background: var(--accent); color: var(--on-accent); text-decoration: none; }
a:focus-visible { outline: 2px solid var(--fg); outline-offset: 2px; }
</style>
</head>
<body>
<main>
	<div class="site"><?php echo esc_html( $site_name ); ?></div>
	<h1><?php esc_html_e( 'This link is no longer active', 'ffcertificate' ); ?></h1>
	<p><?php esc_html_e( 'The address you opened was switched off or does not exist.', 'ffcertificate' ); ?></p>
	<p>
		<?php
		printf(
			/* translators: %s: number of seconds, wrapped in a counter */
			esc_html__( 'You will be taken to the home page in %s seconds.', 'ffcertificate' ),
			'<span class="count" id="ffc-inactive-count">' . (int) $seconds . '</span>'
		);
		?>
	</p>
	<a href="<?php echo esc_url( $home_url ); ?>"><?php esc_html_e( 'Go to the home page now', 'ffcertificate' ); ?></a>
</main>
<script>
( function () {
	var el = document.getElementById( 'ffc-inactive-count' );
	var left = <?php echo (int) $seconds; ?>;
	var timer = setInterval( function () {
		left -= 1;
		if ( left <= 0 ) {
			clearInterval( timer );
			return;
		}
		el.textContent = String( left );
	}, 1000 );
}() );
</script>
</body>
</html>
