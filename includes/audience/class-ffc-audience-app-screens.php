<?php
/**
 * AudienceAppScreens
 *
 * The attendance screens inside the installable app (#2750): the
 * participant's Presence screen (#2742) and the operator's section: pair a
 * check-in terminal, list and end the operator's terminals, and show the
 * operator's unlock code (#2744).
 *
 * Registration goes through Site PWA's `site_pwa_register_screens` action;
 * without that plugin the action never fires and nothing here runs.
 *
 * @package FreeFormCertificate\Audience
 * @since   6.46.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

use FreeFormCertificate\Core\AssetHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the attendance screens with Site PWA.
 *
 * @phpstan-type ScreenDef array{label: string, icon: string, place: string, position: int, capability: string, url?: string}
 */
final class AudienceAppScreens {

	/**
	 * The version of Site PWA's screen contract this class speaks.
	 */
	public const API_VERSION = 1;

	/**
	 * What a participant needs to see their own bookings.
	 */
	public const PARTICIPANT_CAPABILITY = 'ffc_view_own_audience_bookings';

	/**
	 * What an operator needs to run a check-in.
	 */
	public const OPERATOR_CAPABILITY = 'ffc_checkin_audiences';

	/**
	 * The method that draws each screen without a URL of its own.
	 */
	private const RENDERERS = array(
		'presence'           => 'render_presence',
		'operator-pair'      => 'render_operator_pair',
		'operator-terminals' => 'render_operator_terminals',
		'operator-qr'        => 'render_operator_qr',
	);

	/**
	 * Hook the registration action.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'site_pwa_register_screens', array( self::class, 'register' ), 10, 2 );
	}

	/**
	 * Add the attendance screens while the audience module is on. The app
	 * checks each screen's capability wherever it shows it.
	 *
	 * @param mixed $screens The app's screen registry.
	 * @param mixed $api     The contract version the app speaks.
	 * @return void
	 */
	public static function register( $screens, $api = 0 ): void {
		if ( self::API_VERSION !== (int) $api || ! is_object( $screens ) || ! method_exists( $screens, 'add' ) ) {
			return;
		}
		if ( ! \FreeFormCertificate\Settings\SettingsReader::module_enabled( 'audiences' ) ) {
			return;
		}

		foreach ( self::definitions() as $id => $screen ) {
			$args = array(
				'label'      => $screen['label'],
				'icon'       => $screen['icon'],
				'place'      => $screen['place'],
				'position'   => $screen['position'],
				'capability' => $screen['capability'],
			);
			if ( isset( $screen['url'] ) ) {
				$args['url'] = $screen['url'];
			} else {
				$args['render'] = array( self::class, self::RENDERERS[ $id ] );
			}
			$screens->add( $id, $args );
		}
	}

	/**
	 * The attendance screens, by id.
	 *
	 * Manual attendance already exists, on the booking's Attendance screen in
	 * wp-admin, so it links there; the others draw their own screen.
	 * Pairing and listing terminals take the terminal capability; the
	 * operator's unlock code takes the check-in one, because it is what a
	 * check-in operator shows a terminal.
	 *
	 * @return array<string, ScreenDef>
	 */
	public static function definitions(): array {
		return array(
			'presence'            => array(
				'label'      => __( 'Presence', 'ffcertificate' ),
				'icon'       => 'check',
				'place'      => 'nav',
				'position'   => 3,
				'capability' => self::PARTICIPANT_CAPABILITY,
			),
			'operator-pair'       => array(
				'label'      => __( 'Pair terminal', 'ffcertificate' ),
				'icon'       => 'link',
				'place'      => 'more',
				'position'   => 40,
				'capability' => AudienceTerminalPairing::CAP,
			),
			'operator-terminals'  => array(
				'label'      => __( 'My terminals', 'ffcertificate' ),
				'icon'       => 'grid',
				'place'      => 'more',
				'position'   => 41,
				'capability' => AudienceTerminalPairing::CAP,
			),
			'operator-qr'         => array(
				'label'      => __( 'Operator QR', 'ffcertificate' ),
				'icon'       => 'add',
				'place'      => 'more',
				'position'   => 42,
				'capability' => self::OPERATOR_CAPABILITY,
			),
			'operator-attendance' => array(
				'label'      => __( 'Manual attendance', 'ffcertificate' ),
				'icon'       => 'check',
				'place'      => 'more',
				'position'   => 43,
				'capability' => self::OPERATOR_CAPABILITY,
				'url'        => admin_url( 'admin.php?page=ffc-scheduling-bookings' ),
			),
		);
	}

	/**
	 * The participant's Presence screen (#2742): the markup from
	 * `templates/public/presence/screen.php`, the encoder, the script and
	 * what it needs to talk to the site.
	 *
	 * The site's palette and dark mode load first, so the screen follows
	 * the same light or dark choice as the rest of the plugin.
	 *
	 * @return string
	 */
	public static function render_presence(): string {
		AssetHelper::enqueue_common_style();
		AssetHelper::enqueue_dark_mode();

		$suffix = AssetHelper::asset_suffix();
		wp_enqueue_style(
			'ffc-presence-app',
			FFC_PLUGIN_URL . "assets/css/ffc-presence-app{$suffix}.css",
			array( 'ffc-common' ),
			FFC_VERSION
		);
		wp_enqueue_script(
			'ffc-qrcodegen',
			FFC_PLUGIN_URL . 'libs/js/qrcodegen-' . FFC_QRCODEGEN_VERSION . '.js',
			array(),
			FFC_QRCODEGEN_VERSION,
			true
		);
		wp_enqueue_script(
			'ffc-presence-app',
			FFC_PLUGIN_URL . "assets/js/ffc-presence-app{$suffix}.js",
			array( 'ffc-qrcodegen' ),
			FFC_VERSION,
			true
		);
		wp_localize_script(
			'ffc-presence-app',
			'ffcPresence',
			array(
				'rest'    => esc_url_raw( rest_url( AudiencePresenceRestController::NAMESPACE . '/' . AudiencePresenceRestController::REST_BASE . '/' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'userId'  => get_current_user_id(),
				'strings' => array(
					'storage'        => __( 'This browser cannot keep a presence key. Open the app installed on your phone, or update the browser.', 'ffcertificate' ),
					'activating'     => __( 'Activating this phone…', 'ffcertificate' ),
					'activated'      => __( 'This phone is active. Show the code below at the event terminal.', 'ffcertificate' ),
					'failed'         => __( 'This phone could not be activated. Check the connection and try again.', 'ffcertificate' ),
					/* translators: %d: seconds until the presence code changes */
					'next'           => __( 'New code in %d s', 'ffcertificate' ),
					'expired'        => __( 'This phone\'s activation has expired. Open this screen while online to renew it.', 'ffcertificate' ),
					'qrLabel'        => __( 'Your presence code. Hold it up to the terminal\'s reader.', 'ffcertificate' ),
					'dismiss'        => __( 'Dismiss', 'ffcertificate' ),
					'fillForm'       => __( 'Fill in the form', 'ffcertificate' ),
					/* translators: %1$s: the phone's platform and browser, e.g. "Android · Chrome"; %2$s: the date it was activated */
					'current'        => __( 'Current phone: %1$s, active since %2$s. It stops making the code as soon as you activate this one.', 'ffcertificate' ),
					'otherPhone'     => __( 'another phone', 'ffcertificate' ),
					'titleFirst'     => __( 'Activate presence on this phone', 'ffcertificate' ),
					'titleElsewhere' => __( 'Your presence is active on another phone', 'ffcertificate' ),
					'activateHere'   => __( 'Activate on this phone', 'ffcertificate' ),
					'moveHere'       => __( 'Move it to this phone', 'ffcertificate' ),
					/* translators: %1$d: changes used; %2$d: changes allowed; %3$d: days in the window */
					'changes'        => __( '%1$d of %2$d in the last %3$d days', 'ffcertificate' ),
					'dayOne'         => __( '1 day', 'ffcertificate' ),
					/* translators: %d: days the code works without internet */
					'days'           => __( '%d days', 'ffcertificate' ),
				),
			)
		);

		ob_start();
		include FFC_PLUGIN_DIR . 'templates/public/presence/screen.php';
		return (string) ob_get_clean();
	}

	/**
	 * The Pair terminal screen.
	 *
	 * @return string
	 */
	public static function render_operator_pair(): string {
		return self::render_operator( 'pair' );
	}

	/**
	 * The My terminals screen.
	 *
	 * @return string
	 */
	public static function render_operator_terminals(): string {
		return self::render_operator( 'terminals' );
	}

	/**
	 * The Operator QR screen.
	 *
	 * @return string
	 */
	public static function render_operator_qr(): string {
		return self::render_operator( 'qr' );
	}

	/**
	 * One of the operator's terminal screens: the markup from
	 * `templates/public/terminal/operator.php`, the presence screen's encoder
	 * for the QR codes, and the script.
	 *
	 * @param string $ffc_terminal_mode `pair`, `terminals` or `qr`; the template reads it.
	 * @return string
	 */
	private static function render_operator( string $ffc_terminal_mode ): string {
		AssetHelper::enqueue_common_style();
		AssetHelper::enqueue_dark_mode();

		$suffix = AssetHelper::asset_suffix();
		wp_enqueue_style(
			'ffc-terminal-operator',
			FFC_PLUGIN_URL . "assets/css/ffc-terminal-operator{$suffix}.css",
			array( 'ffc-common' ),
			FFC_VERSION
		);
		wp_enqueue_script(
			'ffc-qrcodegen',
			FFC_PLUGIN_URL . 'libs/js/qrcodegen-' . FFC_QRCODEGEN_VERSION . '.js',
			array(),
			FFC_QRCODEGEN_VERSION,
			true
		);
		wp_enqueue_script(
			'ffc-presence-app',
			FFC_PLUGIN_URL . "assets/js/ffc-presence-app{$suffix}.js",
			array( 'ffc-qrcodegen' ),
			FFC_VERSION,
			true
		);
		wp_enqueue_script(
			'ffc-terminal-operator',
			FFC_PLUGIN_URL . "assets/js/ffc-terminal-operator{$suffix}.js",
			array( 'ffc-presence-app' ),
			FFC_VERSION,
			true
		);
		wp_localize_script(
			'ffc-terminal-operator',
			'ffcTerminalOperator',
			array(
				'rest'        => esc_url_raw( rest_url( AudienceTerminalRestController::NAMESPACE . '/' . AudienceTerminalRestController::REST_BASE . '/' ) ),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'maxBookings' => AudienceTerminalPairing::MAX_BOOKINGS,
				'autoEnd'     => self::default_auto_end(),
				'strings'     => array(
					'loading'         => __( 'Loading…', 'ffcertificate' ),
					'failed'          => __( 'The site could not be reached. Check the connection and try again.', 'ffcertificate' ),
					'pickEvent'       => __( 'Tick at least one event.', 'ffcertificate' ),
					/* translators: %d: the most events one terminal may serve */
					'tooMany'         => __( 'A terminal serves at most %d events.', 'ffcertificate' ),
					'generating'      => __( 'Generating the code…', 'ffcertificate' ),
					'full'            => __( 'Terminal limit reached', 'ffcertificate' ),
					/* translators: %d: number of terminals already paired for the event */
					'held'            => __( 'Terminals: %d', 'ffcertificate' ),
					/* translators: %s: minutes and seconds left, such as 4:59 */
					'codeExpires'     => __( 'Expires in %s · single use', 'ffcertificate' ),
					/* translators: 1: number of events, 2: hours after the last event */
					'pairSummary'     => __( 'Events: %1$d · ends by itself %2$d h after the last one', 'ffcertificate' ),
					'expiredCode'     => __( 'This code has expired.', 'ffcertificate' ),
					'qrLabel'         => __( 'QR code to hold to the terminal\'s reader.', 'ffcertificate' ),
					/* translators: %d: terminal number */
					'unnamed'         => __( 'Terminal %d', 'ffcertificate' ),
					/* translators: %d: number of events */
					'events'          => __( 'Events: %d', 'ffcertificate' ),
					/* translators: %s: date and time */
					'seen'            => __( 'Last read: %s', 'ffcertificate' ),
					'neverSeen'       => __( 'Not used yet.', 'ffcertificate' ),
					/* translators: %s: date and time */
					'until'           => __( 'Works until %s', 'ffcertificate' ),
					'ended'           => __( 'Terminal ended.', 'ffcertificate' ),
					'changed'         => __( 'Events saved. The terminal picks them up within a minute.', 'ffcertificate' ),
					/* translators: 1: active terminals listed, 2: the site's limit, 3: the limit per event */
					'summary'         => __( 'Your active terminals: %1$d. The site allows %2$d at a time, and %3$d per event.', 'ffcertificate' ),
					/* translators: 1: active terminals listed, 2: the site's limit, 3: the limit per event */
					'summaryAll'      => __( 'Active terminals on the site: %1$d. The site allows %2$d at a time, and %3$d per event.', 'ffcertificate' ),
					/* translators: %s: minutes and seconds left, such as 1:59 */
					'operatorExpires' => __( 'A new code appears before this one expires in %s.', 'ffcertificate' ),
				),
			)
		);

		$ffc_terminal_auto_end         = self::default_auto_end();
		$ffc_terminal_auto_end_options = self::auto_end_options( $ffc_terminal_auto_end );

		ob_start();
		include FFC_PLUGIN_DIR . 'templates/public/terminal/operator.php';
		return (string) ob_get_clean();
	}
	/**
	 * The hours a new terminal ends after its last event unless the operator
	 * picks another: the Check-in settings' value.
	 *
	 * @return int
	 */
	private static function default_auto_end(): int {
		return max( 1, intdiv( AudienceCheckinSettings::auto_end_seconds(), HOUR_IN_SECONDS ) );
	}

	/**
	 * The choices the Pair terminal screen offers for ending by itself
	 * (#2781): 1, 2 and 4 hours, plus the settings' value when it is none of
	 * them, so the default is always one of the choices.
	 *
	 * @param int $default_hours The settings' value.
	 * @return array<int, string> Hours => label.
	 */
	private static function auto_end_options( int $default_hours ): array {
		$hours = array_values( array_unique( array( 1, 2, 4, $default_hours ) ) );
		sort( $hours );
		$options = array();
		foreach ( $hours as $h ) {
			/* translators: %d: hours */
			$options[ $h ] = sprintf( __( '%d h', 'ffcertificate' ), $h );
		}
		return $options;
	}
}
