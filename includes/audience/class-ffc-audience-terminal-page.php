<?php
/**
 * Audience Terminal Page
 *
 * The check-in terminal's own page (#2744): what a tablet at an event's
 * entrance opens.
 *
 * @package FreeFormCertificate\Audience
 * @since 6.50.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

use FreeFormCertificate\Core\AssetHelper;
use FreeFormCertificate\Settings\SettingsReader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `/terminal/`: a page of its own, outside the site's theme and the app,
 * because a terminal never signs in with anyone's account. It has its own
 * web app manifest (`/terminal/manifest.webmanifest`, scope `/terminal/`,
 * full screen, landscape), so a tablet can install it as an app that needs
 * no login: the pairing QR code is what binds it. `/?ffc_terminal=1` keeps
 * working, and is the address on a site without pretty permalinks.
 *
 * Neither address registers a rewrite rule: the request path is read on
 * `parse_request`, the way the short URLs are (#2693), so nothing is stored
 * and no flush is needed. Every response is kept out of search engines
 * (`X-Robots-Tag: noindex`) and out of caches (#2780).
 *
 * It starts on "Waiting for pairing": the operator holds the pairing QR code
 * to the terminal's reader, or types the code. The terminal keeps the token
 * it receives in this browser, and then reads presence codes (#2745).
 *
 * The page builds its own document but loads its sheets and scripts through
 * their registered handles, so the asset guards see them like any other.
 */
final class AudienceTerminalPage {

	/**
	 * The query variable that opens the page.
	 */
	public const QUERY_VAR = 'ffc_terminal';

	/**
	 * The path, under the home URL, the terminal answers on.
	 */
	public const PATH = 'terminal';

	/**
	 * The manifest's file name under {@see self::PATH}.
	 */
	public const MANIFEST = 'manifest.webmanifest';

	/**
	 * The query value that asks for the manifest on a site without pretty
	 * permalinks.
	 */
	private const MANIFEST_QUERY = 'manifest';

	/**
	 * The terminal's ground, which is the dark palette's `--ffc-bg`: the
	 * manifest takes a literal colour, and the page is always dark.
	 */
	private const BACKGROUND = '#1d2327';

	/**
	 * What every response tells search engines.
	 */
	private const ROBOTS = 'noindex, nofollow, noarchive';

	/**
	 * Whether a terminal may read codes with its own camera. Off unless an
	 * administrator turned it on: the wedge reader is the recommended mode.
	 *
	 * @return bool
	 */
	public static function camera_allowed(): bool {
		return AudienceCheckinSettings::camera_allowed();
	}

	/**
	 * Hook the page in.
	 *
	 * `/terminal/` is answered on `parse_request` at priority 1, before any
	 * query runs, so a missing rewrite rule cannot turn it into a 404. The
	 * query form renders on `template_redirect` at priority 1, ahead of the
	 * canonical redirect at 10, so `/?ffc_terminal=1` is never rewritten
	 * before it is answered.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'query_vars', array( self::class, 'query_vars' ) );
		add_action( 'parse_request', array( self::class, 'maybe_route' ), 1 );
		add_action( 'template_redirect', array( self::class, 'maybe_render' ), 1 );
	}

	/**
	 * Register the query variable.
	 *
	 * @param mixed $vars Query variables.
	 * @return mixed
	 */
	public static function query_vars( $vars ) {
		if ( is_array( $vars ) ) {
			$vars[] = self::QUERY_VAR;
		}
		return $vars;
	}

	/**
	 * Whether the site serves pretty permalinks, which is what routes
	 * `/terminal/` to WordPress at all.
	 *
	 * @return bool
	 */
	private static function pretty(): bool {
		$structure = get_option( 'permalink_structure', '' );
		return is_string( $structure ) && '' !== $structure;
	}

	/**
	 * The page's address: `/terminal/`, or the query form on a site without
	 * pretty permalinks.
	 *
	 * @return string
	 */
	public static function url(): string {
		return self::pretty() ? home_url( '/' . self::PATH . '/' ) : add_query_arg( self::QUERY_VAR, '1', home_url( '/' ) );
	}

	/**
	 * The manifest's address.
	 *
	 * @return string
	 */
	public static function manifest_url(): string {
		return self::pretty() ? home_url( '/' . self::PATH . '/' . self::MANIFEST ) : add_query_arg( self::QUERY_VAR, self::MANIFEST_QUERY, home_url( '/' ) );
	}

	/**
	 * What a request path asks for: `page`, `manifest` or '' (#2780).
	 *
	 * Only the home path followed by `terminal`, with or without a trailing
	 * slash, or by `terminal/manifest.webmanifest`, matches; `/docs/terminal`
	 * and `/terminal/anything` do not.
	 *
	 * @param string $path      Request path, as sent.
	 * @param string $home_path The home URL's path ('/' at the root, '/site/' in a sub-directory).
	 * @return string
	 */
	public static function route_from_path( string $path, string $home_path ): string {
		$path = trim( $path, '/' );
		$home = trim( $home_path, '/' );
		$base = ( '' === $home ? '' : $home . '/' ) . self::PATH;
		if ( $base === $path ) {
			return 'page';
		}
		if ( $base . '/' . self::MANIFEST === $path ) {
			return 'manifest';
		}
		return '';
	}

	/**
	 * Answer `/terminal/` and its manifest before WordPress queries anything.
	 *
	 * @return void
	 */
	public static function maybe_route(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Server-set REQUEST_URI, parsed by wp_parse_url() and compared with two fixed paths.
		$raw   = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$path  = wp_parse_url( is_string( $raw ) ? $raw : '', PHP_URL_PATH );
		$home  = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$route = self::route_from_path( is_string( $path ) ? $path : '', is_string( $home ) ? $home : '' );
		if ( '' !== $route ) {
			self::serve( $route );
		}
	}

	/**
	 * Render the page, or the manifest, when the query form asked for it.
	 *
	 * @return void
	 */
	public static function maybe_render(): void {
		$value = get_query_var( self::QUERY_VAR );
		$value = is_scalar( $value ) ? (string) $value : '';
		if ( '' === $value ) {
			return;
		}
		self::serve( self::MANIFEST_QUERY === $value ? 'manifest' : 'page' );
	}

	/**
	 * Send the page or the manifest and stop, while the module is on.
	 *
	 * @param string $route `page` or `manifest`.
	 * @return void
	 */
	private static function serve( string $route ): void {
		if ( ! SettingsReader::module_enabled( 'audiences' ) ) {
			return;
		}
		if ( 'manifest' === $route ) {
			self::send_headers( 'application/manifest+json' );
			echo wp_json_encode( self::manifest(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a JSON document served as application/manifest+json, encoded by wp_json_encode().
			exit;
		}
		self::send_headers( 'text/html; charset=utf-8' );
		echo self::document(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- document() escapes every value it prints.
		exit;
	}

	/**
	 * The headers every terminal response carries: no cache may keep it (the
	 * page hands a terminal its credential), and no search engine may list
	 * it (#2780).
	 *
	 * The address is deliberately not barred in robots.txt: a crawler that
	 * may not fetch the page never reads its noindex.
	 *
	 * @param string $content_type The response's content type.
	 * @return void
	 */
	private static function send_headers( string $content_type ): void {
		if ( headers_sent() ) {
			return;
		}
		nocache_headers();
		status_header( 200 );
		foreach ( self::headers( $content_type ) as $name => $value ) {
			header( $name . ': ' . $value );
		}
	}

	/**
	 * The headers {@see self::send_headers()} sends after WordPress's own
	 * no-cache set.
	 *
	 * @param string $content_type The response's content type.
	 * @return array<string, string>
	 */
	public static function headers( string $content_type ): array {
		return array(
			'Content-Type'    => $content_type,
			'Cache-Control'   => 'no-store, no-cache, must-revalidate, max-age=0, private',
			'X-Robots-Tag'    => self::ROBOTS,
			'Referrer-Policy' => 'no-referrer',
		);
	}

	/**
	 * The terminal's web app manifest: full screen, landscape, scoped to the
	 * terminal so it never claims the site or the app.
	 *
	 * @return array<string, mixed>
	 */
	public static function manifest(): array {
		$start = (string) wp_parse_url( self::url(), PHP_URL_PATH );
		$query = (string) wp_parse_url( self::url(), PHP_URL_QUERY );
		$scope = self::pretty() ? $start : (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$name  = __( 'Check-in terminal', 'ffcertificate' );
		$site  = (string) get_bloginfo( 'name' );

		$manifest = array(
			'name'             => '' === $site ? $name : $name . ' — ' . $site,
			'short_name'       => __( 'Terminal', 'ffcertificate' ),
			'id'               => $start . ( '' === $query ? '' : '?' . $query ),
			'start_url'        => $start . ( '' === $query ? '' : '?' . $query ),
			'scope'            => '' === $scope ? '/' : $scope,
			'display'          => 'fullscreen',
			'display_override' => array( 'fullscreen', 'standalone' ),
			'orientation'      => 'landscape',
			'background_color' => self::BACKGROUND,
			'theme_color'      => self::BACKGROUND,
			'lang'             => (string) get_bloginfo( 'language' ),
			'icons'            => array(),
		);
		foreach ( array( 192, 512 ) as $size ) {
			$icon = (string) get_site_icon_url( $size );
			if ( '' !== $icon ) {
				$manifest['icons'][] = array(
					'src'   => $icon,
					'sizes' => $size . 'x' . $size,
					'type'  => 'image/png',
				);
			}
		}
		return $manifest;
	}

	/**
	 * The whole document.
	 *
	 * @return string
	 */
	public static function document(): string {
		self::enqueue();

		ob_start();
		echo '<!DOCTYPE html><html ' . get_language_attributes() . self::root_class() . '><head>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_language_attributes() returns a safe attribute string; root_class() returns one of two literals.
		echo '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
		echo '<meta name="robots" content="noindex,nofollow,noarchive">';
		echo '<meta name="theme-color" content="' . esc_attr( self::BACKGROUND ) . '">';
		echo '<link rel="manifest" href="' . esc_url( self::manifest_url() ) . '">';
		echo '<title>' . esc_html__( 'Check-in terminal', 'ffcertificate' ) . ' — ' . esc_html( get_bloginfo( 'name' ) ) . '</title>';
		wp_print_styles( array( 'ffc-terminal-kiosk' ) );
		echo '</head><body class="ffc-shortcode ffc-terminal-page">';
		$ffc_terminal_logo = (string) get_site_icon_url( 96 );
		include FFC_PLUGIN_DIR . 'templates/public/terminal/kiosk.php';
		wp_print_scripts( array( 'ffc-terminal-kiosk' ) );
		echo '</body></html>';
		return (string) ob_get_clean();
	}

	/**
	 * Register the page's sheet and script, and what the script needs.
	 *
	 * @return void
	 */
	private static function enqueue(): void {
		AssetHelper::enqueue_common_style();
		// Registered for the palette guard; the page prints the dark class itself
		// and never the toggle, so the site's setting cannot turn it light.
		AssetHelper::enqueue_dark_mode();

		$suffix = AssetHelper::asset_suffix();
		wp_enqueue_style(
			'ffc-terminal-kiosk',
			FFC_PLUGIN_URL . "assets/css/ffc-terminal-kiosk{$suffix}.css",
			array( 'ffc-common' ),
			FFC_VERSION
		);
		wp_enqueue_script(
			'ffc-presence-verifier',
			FFC_PLUGIN_URL . "assets/js/ffc-presence-verifier{$suffix}.js",
			array(),
			FFC_VERSION,
			true
		);
		wp_enqueue_script(
			'ffc-terminal-kiosk',
			FFC_PLUGIN_URL . "assets/js/ffc-terminal-kiosk{$suffix}.js",
			array( 'ffc-presence-verifier' ),
			FFC_VERSION,
			true
		);
		wp_localize_script(
			'ffc-terminal-kiosk',
			'ffcTerminal',
			array(
				'rest'     => esc_url_raw( rest_url( AudienceTerminalRestController::NAMESPACE . '/' . AudienceTerminalRestController::REST_BASE . '/' ) ),
				'presence' => esc_url_raw( rest_url( AudiencePresenceRestController::NAMESPACE . '/' . AudiencePresenceRestController::REST_BASE . '/' ) ),
				'camera'   => self::camera_allowed(),
				'strings'  => array(
					'storage'         => __( 'This browser cannot keep the terminal\'s pairing. Allow this site to store data, or use another browser.', 'ffcertificate' ),
					'pairing'         => __( 'Pairing…', 'ffcertificate' ),
					'paired'          => __( 'Terminal paired.', 'ffcertificate' ),
					'failed'          => __( 'The terminal could not reach the site. Check the connection and try again.', 'ffcertificate' ),
					/* translators: %d: number of events */
					'events'          => __( 'Events: %d', 'ffcertificate' ),
					/* translators: %s: date and time */
					'until'           => __( 'Works until %s', 'ffcertificate' ),
					'expired'         => __( 'This terminal\'s pairing has ended. Ask an operator for a new pairing code.', 'ffcertificate' ),
					/* translators: %s: the operator's short name, such as Maria S. */
					'operator'        => __( 'Unlocked by %s.', 'ffcertificate' ),
					'ended'           => __( 'Terminal ended. Pair it again with a new code from the app.', 'ffcertificate' ),
					'queued'          => __( 'Saved. It is sent to the site when the connection returns.', 'ffcertificate' ),
					/* translators: Offline: this person's reading for this event is already waiting to be sent. */
					'alreadyQueued'   => __( 'Already saved on this terminal.', 'ffcertificate' ),
					/* translators: %d: number of readings waiting to be sent */
					'queue'           => __( 'Readings waiting to be sent: %d', 'ffcertificate' ),
					/* translators: %d: number of readings sent */
					'sent'            => __( 'Readings sent: %d', 'ffcertificate' ),
					'nowChecking'     => __( 'Checking in now', 'ffcertificate' ),
					'nextEvent'       => __( 'Next event', 'ffcertificate' ),
					/* translators: %s: time check-in closes */
					'openUntil'       => __( 'Check-in open until %s', 'ffcertificate' ),
					'notOpen'         => __( 'Check-in opens when the organiser opens it.', 'ffcertificate' ),
					/* translators: %s: date and time check-in opens */
					'opensAt'         => __( 'Check-in opens at %s', 'ffcertificate' ),
					'noEvents'        => __( 'No more events on this terminal.', 'ffcertificate' ),
					'terminalTitle'   => __( 'Check-in terminal', 'ffcertificate' ),
					'notPaired'       => __( 'Not paired', 'ffcertificate' ),
					/* translators: %s: time check-in closes */
					'checkinUntil'    => __( 'check-in until %s', 'ffcertificate' ),
					/* translators: 1: people present, 2: people enrolled */
					'present'         => __( '%1$d of %2$d present', 'ffcertificate' ),
					/* translators: %d: people present */
					'presentOnly'     => __( '%d present', 'ffcertificate' ),
					/* translators: %s: time check-in opens */
					'opensIn'         => __( 'Check-in opens at %s, in', 'ffcertificate' ),
					'readyTitle'      => __( 'Hold your phone\'s QR code to the reader', 'ffcertificate' ),
					'readyLead'       => __( 'Open the app, tap Presence and show the screen to the reader.', 'ffcertificate' ),
					'cameraTitle'     => __( 'Show your phone\'s QR code to the camera', 'ffcertificate' ),
					'cameraLead'      => __( 'Hold the screen inside the frame, a hand\'s width from the tablet.', 'ffcertificate' ),
					'recordedTitle'   => __( 'Attendance recorded', 'ffcertificate' ),
					'alreadyTitle'    => __( 'Already recorded', 'ffcertificate' ),
					'queuedTitle'     => __( 'Attendance saved', 'ffcertificate' ),
					'refusedTitle'    => __( 'Not recorded', 'ffcertificate' ),
					'recordedDetail'  => __( 'The certificate is in the app after the event.', 'ffcertificate' ),
					'openBadge'       => __( 'Not enrolled beforehand', 'ffcertificate' ),
					'openDetail'      => __( 'Open event: your attendance counts as usual and shows to the organisers as attendance without enrolment.', 'ffcertificate' ),
					'help'            => __( 'Still a problem? Ask the team at the help desk.', 'ffcertificate' ),
					/* translators: %d: seconds left */
					'nextPerson'      => __( 'Next person in %d s', 'ffcertificate' ),
					/* translators: %d: seconds left */
					'unlockWait'      => __( 'Back to reading on its own in %d s', 'ffcertificate' ),
					'connIdle'        => __( 'Connected · no reading waiting', 'ffcertificate' ),
					/* translators: %d: readings waiting to be sent */
					'connQueue'       => __( 'Connected · %d readings to send', 'ffcertificate' ),
					/* translators: %d: readings kept on the terminal */
					'connOffline'     => __( 'Offline · %d readings saved here', 'ffcertificate' ),
					'connCamera'      => __( 'front camera', 'ffcertificate' ),
					'titles'          => array(
						'ffc_terminal_not_today'           => __( 'No check-in today', 'ffcertificate' ),
						'ffc_terminal_path_off'            => __( 'No terminal check-in', 'ffcertificate' ),
						'ffc_terminal_window_not_open'     => __( 'Check-in not open yet', 'ffcertificate' ),
						'ffc_terminal_window_closed'       => __( 'Check-in closed', 'ffcertificate' ),
						'ffc_terminal_not_enrolled'        => __( 'Not enrolled', 'ffcertificate' ),
						'ffc_terminal_other_event'         => __( 'Another event', 'ffcertificate' ),
						'ffc_terminal_failed'              => __( 'Not saved', 'ffcertificate' ),
						'ffc_presence_code_stale'          => __( 'Code expired', 'ffcertificate' ),
						'ffc_presence_code_future'         => __( 'Check the phone\'s clock', 'ffcertificate' ),
						'ffc_presence_code_revoked'        => __( 'Phone replaced', 'ffcertificate' ),
						'ffc_presence_attestation_expired' => __( 'Activation expired', 'ffcertificate' ),
						'ffc_presence_ticket_expired'      => __( 'Ticket expired', 'ffcertificate' ),
						'ffc_presence_ticket_mismatch'     => __( 'Ticket for someone else', 'ffcertificate' ),
						'ffc_presence_code_malformed'      => __( 'Code not recognised', 'ffcertificate' ),
						'ffc_presence_code_signature'      => __( 'Code not recognised', 'ffcertificate' ),
						'invalid'                          => __( 'Code not recognised', 'ffcertificate' ),
						'full'                             => __( 'Terminal full', 'ffcertificate' ),
					),
					'wakeUnsupported' => __( 'This browser cannot keep the screen on. Set the device\'s screen timeout so the screen does not turn off during the event.', 'ffcertificate' ),
					'wakeRefused'     => __( 'The browser did not keep the screen on, for instance in battery saver. Set the device\'s screen timeout so the screen does not turn off during the event.', 'ffcertificate' ),
					'check'           => array(
						'secureLabel'       => __( 'Secure page (HTTPS)', 'ffcertificate' ),
						'cameraLabel'       => __( 'Camera reading', 'ffcertificate' ),
						'wakeLabel'         => __( 'Screen kept on', 'ffcertificate' ),
						'browserLabel'      => __( 'Browser', 'ffcertificate' ),
						'yes'               => __( 'Yes', 'ffcertificate' ),
						'no'                => __( 'No', 'ffcertificate' ),
						'cameraOn'          => __( 'Supported and allowed', 'ffcertificate' ),
						'cameraOff'         => __( 'Supported, but off in Scheduling → Settings → Check-in', 'ffcertificate' ),
						'cameraInsecure'    => __( 'Needs HTTPS', 'ffcertificate' ),
						'cameraUnsupported' => __( 'Not supported by this browser', 'ffcertificate' ),
						'wakeActive'        => __( 'Active', 'ffcertificate' ),
						'wakeAvailable'     => __( 'Supported, not active now', 'ffcertificate' ),
						'wakeUnsupported'   => __( 'Not supported by this browser', 'ffcertificate' ),
					),
					'camera'          => array(
						'ready'       => __( 'The browser asks for permission to use the camera. Allow it, then hold the presence code inside the frame.', 'ffcertificate' ),
						'reading'     => __( 'Hold the presence code inside the frame.', 'ffcertificate' ),
						'insecure'    => __( 'The camera needs this page to be served over HTTPS. Use a reader connected to the device.', 'ffcertificate' ),
						'unsupported' => __( 'This browser cannot read QR codes with the camera. Use a reader connected to the device.', 'ffcertificate' ),
						'denied'      => __( 'The camera was not allowed. Allow it in the browser\'s site settings, or use a reader connected to the device.', 'ffcertificate' ),
						'failed'      => __( 'The camera could not be started. Use a reader connected to the device.', 'ffcertificate' ),
					),
					'offline'         => array(
						'invalid'                          => __( 'This is not a valid presence code. Show the code on the phone again.', 'ffcertificate' ),
						'ffc_presence_code_stale'          => __( 'This presence code is old. Show the code on the phone again.', 'ffcertificate' ),
						'ffc_presence_code_future'         => __( 'This presence code is dated in the future. Check the phone\'s clock.', 'ffcertificate' ),
						'ffc_presence_code_revoked'        => __( 'This phone was replaced or revoked. Activate the new one in the app.', 'ffcertificate' ),
						'ffc_presence_attestation_expired' => __( 'This presence code has expired. Open the app while online to renew it.', 'ffcertificate' ),
						'ffc_presence_ticket_expired'      => __( 'The event ticket in this presence code has expired.', 'ffcertificate' ),
						'ffc_presence_ticket_mismatch'     => __( 'The event ticket in this presence code is for another person or event.', 'ffcertificate' ),
						'ffc_terminal_other_event'         => __( 'This code is for an event this terminal does not check in. Choose this event on the phone, or use the terminal at its entrance.', 'ffcertificate' ),
						'full'                             => __( 'This terminal cannot keep more readings offline. Connect it to the internet to send them.', 'ffcertificate' ),
					),
				),
			)
		);
	}

	/**
	 * The dark theme's class on the root element, always (#2780).
	 *
	 * A terminal is a display at an entrance, read from a step away in any
	 * light, so it uses the dark palette whatever the site's Dark Mode
	 * setting says, as the approved design does. The palette is the plugin's
	 * own dark theme, so the contrast guards measure it.
	 *
	 * @return string
	 */
	private static function root_class(): string {
		return ' class="ffc-dark-mode"';
	}
}
