<?php
/**
 * The contract with Site PWA, checked with both plugins running (#2875).
 *
 * **WHY.** The two plugins meet in PHP, and both sides are defensive: an
 * action nobody fires does nothing, a consumer that sees an `$api` it does
 * not know registers nothing, and a source that answers null makes the app
 * fall back to its own look. So a break on either side is silent, and
 * `SitePwaContractTest` only reads this side's source. This script boots a
 * WordPress with both plugins active and asks Site PWA's own classes what they
 * received from this plugin.
 *
 * **WHO RUNS IT.**
 *   - `site-pwa-integration.yml` here, against Site PWA's `develop`, on every
 *     pull request, on `develop` and daily, so a change in either repository
 *     shows up within a day.
 *   - Site PWA's `ffc-integration.yml`, against this repository's `develop`,
 *     with this same file, so the check has one source.
 *   - The testes deploy of both plugins, with `--read-only`, against the real
 *     site. There it writes nothing and judges only shapes, because the site's
 *     settings are the administrator's.
 *
 * **WHAT IT CHECKS.**
 *   1. Both plugins are loaded: Site PWA's classes exist and `FFC_VERSION` is
 *      defined. With `--write` a missing class fails, because a CI run that
 *      checked nothing must not read as a pass; read-only, on a real site, a
 *      plugin left inactive is a setting, and the check says SKIPPED.
 *   2. The contract version each consumer here was written for equals the one
 *      Site PWA sends (`$api`). A mismatch is the silent break: the consumer
 *      refuses the call and the app shows none of this plugin's screens.
 *   3. As an administrator, Site PWA's registry holds at least one screen and
 *      refused none (`Screens::add()` reports a refusal through
 *      `_doing_it_wrong()`), More's "My details" opens the Profile screen
 *      where the app has that link, and Preferences carries the
 *      Notifications section, with the date-message toggles exactly while
 *      the Messages tab shows, where the app has sections, and Certificates
 *      carries its badge callable, where the app draws a bar screen's badge,
 *      and is the address a certificate to complete opens (#2936).
 *   4. Both look sources are offered under the id `ffc`, and their readers
 *      answer in Site PWA's shapes; the dark root class is in Site PWA's list.
 *   5. With `--write` only: set this plugin's Public Design and Dark Mode, point
 *      Site PWA at the `ffc` sources, and read the look and the scheme back
 *      through `Identity::current()` and `Scheme::current()`. The options are
 *      restored before the script exits.
 *   6. With `--write` only: publish a certificate form open today with a
 *      consent field and check that it joins the administrator's Presence
 *      list once, with its ticket and consent text; record a reading that
 *      waits on profile data and check that Site PWA's Home keeps it as a
 *      pending item. The form and the reading are removed afterwards.
 *
 * Usage: php site-pwa-integration-check.php <wp-root> [--write | --read-only]
 * Exit codes: 0 = every check passed (or skipped, read-only), 1 = a check
 * failed, 2 = usage.
 *
 * @package FreeFormCertificate\CI
 */

declare(strict_types=1);

$ffc_pwa_root  = rtrim( (string) ( $argv[1] ?? '' ), '/' );
$ffc_pwa_write = in_array( '--write', $argv, true );

if ( '' === $ffc_pwa_root || ! is_readable( $ffc_pwa_root . '/wp-load.php' ) ) {
	fwrite( STDERR, "Usage: php site-pwa-integration-check.php <wp-root> [--write | --read-only]\n" );
	exit( 2 );
}

echo 'Site PWA integration check (' . ( $ffc_pwa_write ? 'write' : 'read-only' ) . ")\n";

// wp_die() on an unreachable database prints its page and exits 0, which
// would read as a pass with nothing checked. Any end before the verdict fails.
$ffc_pwa_done = false;
register_shutdown_function(
	static function () use ( &$ffc_pwa_done ): void {
		if ( ! $ffc_pwa_done ) {
			echo "\n  [FAIL] WordPress ended before the check finished (database unreachable, or a fatal while booting).\n";
			exit( 1 );
		}
	}
);

$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';
define( 'WP_USE_THEMES', false );
require $ffc_pwa_root . '/wp-load.php';

$ffc_pwa_failures = 0;

/**
 * Print one result and count a failure.
 *
 * @param bool   $ok     Whether it passed.
 * @param string $label  What was checked.
 * @param string $detail What was seen.
 */
function ffc_pwa_report( bool $ok, string $label, string $detail = '' ): void {
	global $ffc_pwa_failures;
	if ( ! $ok ) {
		++$ffc_pwa_failures;
	}
	printf( "  [%s] %s%s\n", $ok ? 'OK' : 'FAIL', $label, '' === $detail ? '' : ': ' . $detail );
}

/**
 * Print the verdict and exit.
 *
 * @return never
 */
function ffc_pwa_finish(): void {
	global $ffc_pwa_failures, $ffc_pwa_done;
	$ffc_pwa_done = true;
	echo 0 === $ffc_pwa_failures ? "\nINTEGRATION PASSED\n" : "\nINTEGRATION FAILED ({$ffc_pwa_failures})\n";
	exit( 0 === $ffc_pwa_failures ? 0 : 1 );
}

// 1. Both plugins are loaded. On the testes site (read-only) a plugin that is
// not active is the administrator's choice, so the check skips; in CI, where
// the workflow activated both, a missing one fails.
$ffc_pwa_classes = array(
	'\SitePwa\Settings',
	'\SitePwa\App\Screens',
	'\SitePwa\App\Identity',
	'\SitePwa\App\Scheme',
	'\SitePwa\App\Frame',
	'\SitePwa\App\Home',
);
$ffc_pwa_missing = array_values( array_filter( $ffc_pwa_classes, static fn( string $c ): bool => ! class_exists( $c ) ) );
if ( ! $ffc_pwa_write && ( array() !== $ffc_pwa_missing || ! defined( 'FFC_VERSION' ) ) ) {
	echo "  SKIPPED: Site PWA and Free Form Certificate are not both active on this site.\n";
	$ffc_pwa_done = true;
	exit( 0 );
}
ffc_pwa_report( array() === $ffc_pwa_missing, 'Site PWA is loaded', array() === $ffc_pwa_missing ? '' : 'missing ' . implode( ', ', $ffc_pwa_missing ) );
ffc_pwa_report( defined( 'FFC_VERSION' ), 'Free Form Certificate is loaded', defined( 'FFC_VERSION' ) ? FFC_VERSION : 'FFC_VERSION is not defined' );
if ( array() !== $ffc_pwa_missing || ! defined( 'FFC_VERSION' ) ) {
	ffc_pwa_finish();
}

// 2. One contract version on both sides.
$ffc_pwa_versions = array(
	'site_pwa_register_screens' => array( \SitePwa\App\Screens::API_VERSION, array( \FreeFormCertificate\Shortcodes\DashboardAppScreens::class, \FreeFormCertificate\Audience\AudienceAppScreens::class ) ),
	'site_pwa_home'             => array( \SitePwa\App\Home::API_VERSION, array( \FreeFormCertificate\Shortcodes\AppHome::class ) ),
);
foreach ( $ffc_pwa_versions as $ffc_pwa_hook => list( $ffc_pwa_sent, $ffc_pwa_consumers ) ) {
	foreach ( $ffc_pwa_consumers as $ffc_pwa_consumer ) {
		$ffc_pwa_spoken = defined( $ffc_pwa_consumer . '::API_VERSION' ) ? (int) constant( $ffc_pwa_consumer . '::API_VERSION' ) : null;
		ffc_pwa_report(
			$ffc_pwa_spoken === (int) $ffc_pwa_sent,
			"{$ffc_pwa_hook}: \$api",
			sprintf( 'Site PWA sends %d, %s speaks %s', (int) $ffc_pwa_sent, $ffc_pwa_consumer, null === $ffc_pwa_spoken ? 'nothing' : (string) $ffc_pwa_spoken )
		);
	}
}

// 3. The screens, as an administrator.
$ffc_pwa_admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);
ffc_pwa_report( array() !== $ffc_pwa_admins, 'an administrator exists to register the screens for' );
if ( array() !== $ffc_pwa_admins ) {
	wp_set_current_user( (int) $ffc_pwa_admins[0] );
	$ffc_pwa_refused = array();
	add_action(
		'doing_it_wrong_run',
		static function ( $function_name, $message ) use ( &$ffc_pwa_refused ): void {
			if ( false !== strpos( (string) $function_name, 'SitePwa' ) ) {
				$ffc_pwa_refused[] = wp_strip_all_tags( (string) $message );
			}
		},
		10,
		2
	);
	\SitePwa\App\Screens::reset();
	$ffc_pwa_screens = array_keys( \SitePwa\App\Screens::all() );
	ffc_pwa_report( array() !== $ffc_pwa_screens, 'screens registered', array() === $ffc_pwa_screens ? 'none' : implode( ', ', $ffc_pwa_screens ) );
	ffc_pwa_report( array() === $ffc_pwa_refused, 'no registration refused', implode( ' | ', $ffc_pwa_refused ) );
	// More's "My details" opens Profile (site-pwa#56); an older app has no
	// such link to check.
	if ( method_exists( '\\SitePwa\\App\\Frame', 'detailsUrl' ) ) {
		$ffc_pwa_profile = \SitePwa\App\Screens::find( \FreeFormCertificate\Shortcodes\DashboardAppScreens::PROFILE_SCREEN );
		$ffc_pwa_details = \SitePwa\App\Frame::detailsUrl( wp_get_current_user() );
		ffc_pwa_report(
			null !== $ffc_pwa_profile && \SitePwa\App\Screens::link( $ffc_pwa_profile ) === $ffc_pwa_details,
			'My details opens the Profile screen',
			$ffc_pwa_details
		);
	}
	// The Certificates badge reaches the bar (#2893); an older app draws
	// a nav screen's badge nowhere, so there is nothing to check.
	$ffc_pwa_certificates = \SitePwa\App\Screens::find( 'certificates' );
	if ( null !== $ffc_pwa_certificates && array_key_exists( 'badge', \SitePwa\App\Navigation::items( '' )[0] ?? array() ) ) {
		ffc_pwa_report(
			is_callable( $ffc_pwa_certificates['badge'] ),
			'Certificates carries its badge of new certificates',
			is_callable( $ffc_pwa_certificates['badge'] ) ? 'callable' : ( is_string( $ffc_pwa_certificates['badge'] ) ? 'a fixed string' : 'not callable' )
		);
	}
	// A certificate waiting on profile data is completed on the Certificates
	// screen (#2936): the address this plugin builds from Site PWA's routes
	// must be that screen's own.
	if ( null !== $ffc_pwa_certificates ) {
		$ffc_pwa_complete_at = \FreeFormCertificate\Frontend\CertificateCompletionLink::app_screen_url();
		ffc_pwa_report(
			untrailingslashit( \SitePwa\App\Screens::link( $ffc_pwa_certificates ) ) === untrailingslashit( $ffc_pwa_complete_at ),
			'a certificate to complete opens the Certificates screen',
			$ffc_pwa_complete_at
		);
	}
	// The notification toggles in Preferences (site-pwa#66); an older app
	// has no such sections.
	if ( method_exists( '\\SitePwa\\App\\Frame', 'preferenceSections' ) ) {
		$ffc_pwa_messages_tab = ! empty( \FreeFormCertificate\Shortcodes\DashboardAssetManager::tab_permissions( get_current_user_id() )['messages'] );
		$ffc_pwa_sections     = \SitePwa\App\Frame::preferenceSections( wp_get_current_user() );
		$ffc_pwa_pref_ids     = array_column( $ffc_pwa_sections, 'id' );
		$ffc_pwa_pref_html    = (string) ( array_column( $ffc_pwa_sections, 'html', 'id' )['notifications'] ?? '' );
		ffc_pwa_report(
			in_array( 'notifications', $ffc_pwa_pref_ids, true ) && false !== strpos( $ffc_pwa_pref_html, 'data-key="notify_appointment_reminder"' ),
			'Preferences carries the Notifications section with the appointment reminder',
			implode( ', ', $ffc_pwa_pref_ids )
		);
		ffc_pwa_report(
			$ffc_pwa_messages_tab === ( false !== strpos( $ffc_pwa_pref_html, 'data-key="notify_date_messages"' ) ),
			'the date-message toggles join it exactly while the Messages tab shows',
			$ffc_pwa_messages_tab ? 'Messages tab shown' : 'Messages tab hidden'
		);
		// The one Site PWA name this plugin reads: a rename there would put
		// the toggles back on Messages and Profile without anything failing.
		ffc_pwa_report(
			\FreeFormCertificate\Shortcodes\DashboardAppScreens::app_has_preference_sections(),
			'Messages and Profile leave their toggles to Preferences',
			''
		);
	}
	wp_set_current_user( 0 );
}

// 4. The look sources, in Site PWA's shapes.
$ffc_pwa_identity = \SitePwa\App\Identity::sources();
$ffc_pwa_scheme   = \SitePwa\App\Scheme::sources();
ffc_pwa_report( isset( $ffc_pwa_identity['ffc'] ), 'identity source "ffc" offered', implode( ', ', array_keys( $ffc_pwa_identity ) ) );
ffc_pwa_report( isset( $ffc_pwa_scheme['ffc'] ), 'scheme source "ffc" offered', implode( ', ', array_keys( $ffc_pwa_scheme ) ) );
if ( isset( $ffc_pwa_identity['ffc'] ) ) {
	$ffc_pwa_look = call_user_func( $ffc_pwa_identity['ffc']['read'] );
	$ffc_pwa_fits = null === $ffc_pwa_look
		|| ( is_array( $ffc_pwa_look ) && array() === array_diff( array_keys( $ffc_pwa_look ), array( 'accent', 'radius', 'control_radius' ) ) );
	ffc_pwa_report( $ffc_pwa_fits, 'identity reader answers accent / radius / control_radius or null', (string) wp_json_encode( $ffc_pwa_look ) );
}
if ( isset( $ffc_pwa_scheme['ffc'] ) ) {
	$ffc_pwa_choice = call_user_func( $ffc_pwa_scheme['ffc']['read'] );
	ffc_pwa_report(
		null === $ffc_pwa_choice || in_array( $ffc_pwa_choice, array( \SitePwa\App\Scheme::LIGHT, \SitePwa\App\Scheme::DARK, \SitePwa\App\Scheme::AUTO ), true ),
		'scheme reader answers light / dark / auto or null',
		(string) wp_json_encode( $ffc_pwa_choice )
	);
}
$ffc_pwa_classes = \SitePwa\App\Frame::darkRootClasses();
ffc_pwa_report(
	in_array( \FreeFormCertificate\Shortcodes\AppLook::DARK_ROOT_CLASS, $ffc_pwa_classes, true ),
	'dark root class in Site PWA\'s list',
	implode( ', ', $ffc_pwa_classes )
);

// 5. The look, end to end, with the settings set and restored.
if ( $ffc_pwa_write ) {
	$ffc_pwa_saved = array(
		\SitePwa\Settings::OPTION => get_option( \SitePwa\Settings::OPTION, null ),
		'ffc_settings'            => get_option( 'ffc_settings', null ),
	);
	try {
		$ffc_pwa_app                    = (array) get_option( \SitePwa\Settings::OPTION, array() );
		$ffc_pwa_app['identity_source'] = 'ffc';
		$ffc_pwa_app['color_scheme']    = \SitePwa\App\Scheme::FFC;
		update_option( \SitePwa\Settings::OPTION, $ffc_pwa_app );

		$ffc_pwa_set = static function ( array $values ): void {
			update_option( 'ffc_settings', array_merge( (array) get_option( 'ffc_settings', array() ), $values ) );
		};

		$ffc_pwa_set(
			array(
				'public_design'             => 1,
				'public_design_accent'      => '#1a5276',
				'public_design_radius'      => 6,
				'public_design_card_radius' => 14,
			)
		);
		$ffc_pwa_now = \SitePwa\App\Identity::current();
		ffc_pwa_report(
			'ffc' === $ffc_pwa_now['source'] && '#1a5276' === $ffc_pwa_now['accent'] && 14 === $ffc_pwa_now['radius'] && 6 === $ffc_pwa_now['control_radius'],
			'Public Design on reaches the app (card corner as radius, control corner as control_radius)',
			(string) wp_json_encode( $ffc_pwa_now )
		);

		$ffc_pwa_set( array( 'public_design' => 0 ) );
		$ffc_pwa_now = \SitePwa\App\Identity::current();
		ffc_pwa_report( 'ffc' !== $ffc_pwa_now['source'], 'Public Design off gives the app its own look', (string) wp_json_encode( $ffc_pwa_now ) );

		foreach ( array(
			'on'   => \SitePwa\App\Scheme::DARK,
			'off'  => \SitePwa\App\Scheme::LIGHT,
			'auto' => \SitePwa\App\Scheme::AUTO,
		) as $ffc_pwa_mode => $ffc_pwa_expected ) {
			$ffc_pwa_set( array( 'dark_mode' => $ffc_pwa_mode ) );
			$ffc_pwa_got = \SitePwa\App\Scheme::current();
			ffc_pwa_report( $ffc_pwa_expected === $ffc_pwa_got, "Dark Mode \"{$ffc_pwa_mode}\" reaches the app as \"{$ffc_pwa_expected}\"", $ffc_pwa_got );
		}
	} finally {
		foreach ( $ffc_pwa_saved as $ffc_pwa_option => $ffc_pwa_value ) {
			if ( null === $ffc_pwa_value ) {
				delete_option( $ffc_pwa_option );
			} else {
				update_option( $ffc_pwa_option, $ffc_pwa_value );
			}
		}
	}
}

// 6. A certificate form the terminal reads, end to end (#2893): a form open
// today with a consent field joins the person's Presence list, and a reading
// that waits on profile data reaches the app's Home through Site PWA's own
// checks, which drop an item whose icon, tone or link they refuse. Write
// mode only: it publishes a form and records a reading, both removed after.
if ( $ffc_pwa_write && array() !== $ffc_pwa_admins && class_exists( '\\SitePwa\\App\\Home' ) ) {
	$ffc_pwa_user    = (int) $ffc_pwa_admins[0];
	$ffc_pwa_form = 0;
	// No page embeds the form, on purpose: with Site PWA the certificate is
	// completed on the app's Certificates screen (#2936), so the item must
	// reach Home without one.
	try {
		$ffc_pwa_form = (int) wp_insert_post(
			array(
				'post_type'   => 'ffc_form',
				'post_status' => 'publish',
				'post_title'  => 'FFC integration check form',
			)
		);
		$ffc_pwa_today = (string) wp_date( 'Y-m-d' );
		update_post_meta(
			$ffc_pwa_form,
			'_ffc_geofence_config',
			array(
				'datetime_enabled' => '1',
				'date_start'       => $ffc_pwa_today,
				'date_end'         => $ffc_pwa_today,
				'time_start'       => '00:00',
				'time_end'         => '23:59',
			)
		);
		update_post_meta(
			$ffc_pwa_form,
			'_ffc_form_fields',
			array(
				array(
					'type'           => 'checkbox',
					'name'           => 'terms',
					'label'          => 'I accept the <strong>terms</strong>',
					'options'        => 'I accept',
					'required'       => '1',
					'profile_source' => \FreeFormCertificate\Frontend\CertificateProfileSource::CONSENT,
				),
			)
		);

		$ffc_pwa_items = array_values(
			array_filter(
				\FreeFormCertificate\Audience\AudiencePresenceEvents::for_user( $ffc_pwa_user, time() ),
				static fn( $item ) => is_array( $item ) && \FreeFormCertificate\Frontend\CertificateFormTarget::TYPE === ( $item['target_type'] ?? '' ) && $ffc_pwa_form === (int) ( $item['target_id'] ?? 0 )
			)
		);
		$ffc_pwa_item  = $ffc_pwa_items[0] ?? array();
		ffc_pwa_report(
			1 === count( $ffc_pwa_items ) && '' !== (string) ( $ffc_pwa_item['ticket'] ?? '' ) && 'I accept the terms' === ( $ffc_pwa_item['consent'] ?? null ) && array_key_exists( 'needs_ticket', $ffc_pwa_item ),
			'a certificate form open today joins Presence once, with its ticket and its consent text',
			(string) wp_json_encode( array_intersect_key( $ffc_pwa_item, array_flip( array( 'target_type', 'title', 'consent', 'needs_ticket' ) ) ) )
		);

		\FreeFormCertificate\Frontend\CertificateTerminalReadingRepository::record(
			$ffc_pwa_form,
			$ffc_pwa_user,
			0,
			time(),
			\FreeFormCertificate\Frontend\CertificateTerminalReadingRepository::AWAITING_DATA,
			'',
			array( 'school' ),
			null,
			time()
		);
		$ffc_pwa_home  = \SitePwa\App\Home::items( get_userdata( $ffc_pwa_user ) );
		$ffc_pwa_found = array_filter(
			$ffc_pwa_home['pending'],
			static fn( $row ) => false !== strpos( (string) $row['meta'], 'FFC integration check form' ) && 'warning' === $row['tone'] && false !== strpos( (string) ( $row['url'] ?? '' ), '/s/certificates/' )
		);
		ffc_pwa_report(
			array() !== $ffc_pwa_found,
			'a reading that waits on profile data reaches the app\'s Home as a pending item leading to Certificates',
			(string) wp_json_encode( array_column( $ffc_pwa_home['pending'], 'label' ) )
		);
	} finally {
		if ( $ffc_pwa_form > 0 ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The check's own row in the plugin's table.
			$wpdb->delete( $wpdb->prefix . 'ffc_certificate_terminal_readings', array( 'form_id' => $ffc_pwa_form ), array( '%d' ) );
			wp_delete_post( $ffc_pwa_form, true );
		}
	}
}

ffc_pwa_finish();
