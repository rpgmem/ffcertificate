<?php
/**
 * Seed a throwaway WordPress for the visitor pre-flight end-to-end baseline (#2030).
 *
 * Usage:
 *   php .github/scripts/preflight-e2e/seed.php /path/to/wp-load.php <manifest.json>
 *   php .github/scripts/preflight-e2e/seed.php /path/to/wp-load.php --captcha=<math|altcha|both>
 *
 * The first form creates the forms and pages run.mjs drives and writes their
 * URLs to the manifest. The second only switches the captcha provider, which
 * is a site-wide setting, so run.mjs can record the ALTCHA path on the same
 * install. Run it against an EMPTY install with the plugin active: it writes
 * forms, pages and settings. Every value below is fictitious.
 *
 * @package FreeFormCertificate
 */

// phpcs:ignoreFile -- Developer tooling run from the CLI against a throwaway install, never shipped.

if ( PHP_SAPI !== 'cli' || empty( $argv[1] ) || empty( $argv[2] ) ) {
	fwrite( STDERR, "Usage: php seed.php /path/to/wp-load.php <manifest.json|--captcha=mode>\n" );
	exit( 1 );
}

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
require $argv[1];

wp_set_current_user( 1 );
add_filter( 'pre_wp_mail', '__return_false' );

if ( 0 === strpos( $argv[2], '--captcha=' ) ) {
	$settings                     = (array) get_option( 'ffc_settings', array() );
	$settings['captcha_provider'] = substr( $argv[2], strlen( '--captcha=' ) );
	update_option( 'ffc_settings', $settings );
	echo 'captcha_provider=' . $settings['captcha_provider'] . "\n";
	exit( 0 );
}

// The area every GPS case is measured against: a 500 m circle. run.mjs
// places the browser inside it, outside it, or refuses the permission.
const FFC_E2E_LAT = -23.5505;
const FFC_E2E_LNG = -46.6333;

$fields = array(
	array( 'type' => 'text', 'label' => 'Full Name', 'name' => 'name', 'required' => '1', 'options' => '' ),
	array( 'type' => 'email', 'label' => 'Email', 'name' => 'email', 'required' => '1', 'options' => '' ),
	array( 'type' => 'text', 'label' => 'CPF / ID', 'name' => 'cpf_rf', 'required' => '1', 'options' => '' ),
);

$layout = (string) file_get_contents( FFC_PLUGIN_DIR . 'templates/certificate-defaults/default_certificate_1.html' );

$gps = array(
	'geo_enabled'       => '1',
	'geo_gps_enabled'   => '1',
	'geo_ip_enabled'    => '0',
	'geo_area_source'   => 'custom',
	'geo_areas'         => FFC_E2E_LAT . ', ' . FFC_E2E_LNG . ', 500',
	'geo_gps_ip_logic'  => 'or',
	'geo_hide_mode'     => 'message',
	'datetime_enabled'  => '0',
);

$closed = array(
	'datetime_enabled'          => '1',
	'date_start'                => wp_date( 'Y-m-d', time() - 10 * DAY_IN_SECONDS ),
	'date_end'                  => wp_date( 'Y-m-d', time() - 3 * DAY_IN_SECONDS ),
	'time_start'                => '',
	'time_end'                  => '',
	'time_mode'                 => 'daily',
	'datetime_hide_mode_before' => 'message',
	'datetime_hide_mode_during' => 'message',
	'datetime_hide_mode_after'  => 'message',
	'msg_datetime'              => '',
	'geo_enabled'               => '0',
);

/**
 * Create a published form and return its id.
 *
 * @param string     $title    Form title.
 * @param array      $fields   Field definitions.
 * @param string     $layout   Certificate layout.
 * @param array|null $geofence Geofence config, or null for none.
 */
function ffc_e2e_form( string $title, array $fields, string $layout, ?array $geofence ): int {
	$id = (int) wp_insert_post(
		array(
			'post_type'   => 'ffc_form',
			'post_status' => 'publish',
			'post_title'  => $title,
		)
	);
	update_post_meta( $id, '_ffc_form_fields', $fields );
	update_post_meta(
		$id,
		'_ffc_form_config',
		array(
			'pdf_layout'         => $layout,
			'bg_image'           => '',
			'enable_restriction' => '',
			'send_user_email'    => '0',
			'restrictions'       => array( 'password' => '0', 'allowlist' => '0', 'denylist' => '0', 'ticket' => '0' ),
			'quiz_enabled'       => '0',
		)
	);
	if ( null !== $geofence ) {
		update_post_meta( $id, '_ffc_geofence_config', $geofence );
	}
	return $id;
}

/**
 * Publish a page and return its permalink.
 *
 * @param string $title   Page title.
 * @param string $content Page content.
 */
function ffc_e2e_page( string $title, string $content ): string {
	$id = (int) wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_content' => $content,
		)
	);
	return (string) get_permalink( $id );
}

$forms = array(
	'plain'  => ffc_e2e_form( 'E2E plain form', $fields, $layout, null ),
	'gps'    => ffc_e2e_form( 'E2E GPS form', $fields, $layout, $gps ),
	'closed' => ffc_e2e_form( 'E2E closed form', $fields, $layout, $closed ),
);

$manifest = array( 'area' => array( 'lat' => FFC_E2E_LAT, 'lng' => FFC_E2E_LNG ), 'forms' => $forms, 'pages' => array() );
foreach ( $forms as $key => $id ) {
	$manifest['pages'][ $key ] = ffc_e2e_page( "E2E $key", '[ffc_form id="' . $id . '"]' );
}
$manifest['pages']['content'] = ffc_e2e_page(
	'E2E gated content',
	'[ffc_geofence areas="' . FFC_E2E_LAT . ', ' . FFC_E2E_LNG . ', 500" gps="yes" ip="no"]<p class="e2e-secret">Gated paragraph</p>[/ffc_geofence]'
);

$settings                     = (array) get_option( 'ffc_settings', array() );
$settings['captcha_provider'] = 'math';
update_option( 'ffc_settings', $settings );

// One browser issues, reprints and issues again within a minute, from one
// address: the per-IP and per-e-mail limits would stop the second request
// with a cooldown that says nothing about the pre-flight.
$limits                     = \FreeFormCertificate\Security\RateLimitChecker::get_settings();
$limits['ip']['enabled']    = false;
$limits['email']['enabled'] = false;
update_option( \FreeFormCertificate\Settings\RateLimitSettingsReader::OPTION_KEY, $limits );

file_put_contents( $argv[2], wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
echo 'seeded ' . count( $forms ) . " forms\n";
