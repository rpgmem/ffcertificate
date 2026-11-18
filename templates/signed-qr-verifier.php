<?php
/**
 * The standalone signed QR verifier (#2639): one HTML file that checks a
 * certificate's signed QR code with no server, carrying the site's public
 * keys and the verifier script inline.
 *
 * @package FreeFormCertificate
 * @since 6.45.0
 *
 * @var string                $verifier_site    Site name.
 * @var string                $verifier_script  Contents of assets/js/ffc-signed-qr.js.
 * @var array<string, mixed>  $verifier_data    Keys and strings, as SignedQrVerifier::data() returns them.
 * @var array<string, string> $verifier_ui      Strings of this page.
 * @var string                $verifier_date    Date the file was built, already formatted.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!doctype html>
<html lang="<?php echo esc_attr( str_replace( '_', '-', (string) get_locale() ) ); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( $verifier_ui['title'] . ' — ' . $verifier_site ); ?></title>
<style>
	body { margin: 0; padding: 24px 16px; font: 16px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: #1d2327; background: #f6f7f7; }
	main { max-width: 640px; margin: 0 auto; }
	h1 { font-size: 1.4em; margin: 0 0 4px; }
	.meta { color: #50575e; margin: 0 0 20px; font-size: 0.9em; }
	label { display: block; font-weight: 600; margin-bottom: 6px; }
	textarea { box-sizing: border-box; width: 100%; min-height: 96px; padding: 8px; font: inherit; border: 1px solid #8c8f94; border-radius: 4px; }
	button { margin-top: 10px; padding: 8px 18px; font: inherit; color: #fff; background: #2271b1; border: 0; border-radius: 4px; cursor: pointer; }
	button:focus-visible, textarea:focus-visible { outline: 2px solid #2271b1; outline-offset: 2px; }
	.result { margin-top: 20px; padding: 12px 16px; border-left: 4px solid #72aee6; background: #fff; }
	.result.success { border-color: #00a32a; }
	.result.warning { border-color: #dba617; }
	.result.danger { border-color: #d63638; }
	.result strong { display: block; margin-bottom: 6px; }
	.result p { margin: 4px 0; overflow-wrap: anywhere; }
</style>
</head>
<body>
<main>
	<h1><?php echo esc_html( $verifier_ui['title'] ); ?></h1>
	<p class="meta"><?php echo esc_html( $verifier_site . ' · ' . $verifier_date ); ?></p>
	<p><?php echo esc_html( $verifier_ui['intro'] ); ?></p>
	<form id="ffc-verifier">
		<label for="ffc-verifier-link"><?php echo esc_html( $verifier_ui['label'] ); ?></label>
		<textarea id="ffc-verifier-link" required spellcheck="false"></textarea>
		<button type="submit"><?php echo esc_html( $verifier_ui['check'] ); ?></button>
	</form>
	<div id="ffc-verifier-result" aria-live="polite"></div>
</main>
<script>
<?php
// The verifier file, inlined as it is: it has no dependency.
echo $verifier_script; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The plugin's own script file, read from disk.
?>
</script>
<script>
(function () {
	var data = <?php echo wp_json_encode( $verifier_data ); ?>;
	var noPayload = <?php echo wp_json_encode( array( $verifier_ui['no_payload_title'], $verifier_ui['no_payload'] ) ); ?>;
	var form = document.getElementById('ffc-verifier');
	var out = document.getElementById('ffc-verifier-result');

	function show(tone, title, lines) {
		out.textContent = '';
		var box = document.createElement('div');
		box.className = 'result ' + tone;
		var strong = document.createElement('strong');
		strong.textContent = title;
		box.appendChild(strong);
		lines.forEach(function (line) {
			var p = document.createElement('p');
			p.textContent = line;
			box.appendChild(p);
		});
		out.appendChild(box);
	}

	form.addEventListener('submit', function (event) {
		event.preventDefault();
		var signed = window.FFCSignedQr.signedFrom(document.getElementById('ffc-verifier-link').value.trim());
		if (!signed) {
			show('info', noPayload[0], [noPayload[1]]);
			return;
		}
		window.FFCSignedQr.verify(signed, data.keys).then(function (result) {
			var d = window.FFCSignedQr.describe(result, data.strings, '');
			show(d.tone, d.title, d.lines);
		});
	});
})();
</script>
</body>
</html>
