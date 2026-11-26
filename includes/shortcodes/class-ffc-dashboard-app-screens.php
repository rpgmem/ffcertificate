<?php
/**
 * DashboardAppScreens
 *
 * The participant's dashboard inside the installable app (#2750). Each
 * screen the app shows draws one part of `[user_dashboard_personal]`
 * through its `tabs` attribute, inside the app's own frame.
 *
 * Registration goes through the `site_pwa_register_screens` action of the
 * Site PWA plugin. Without that plugin the action never fires and nothing
 * here runs, so the dashboard works exactly as before.
 *
 * @package FreeFormCertificate\Shortcodes
 * @since   6.46.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Shortcodes;

use FreeFormCertificate\Core\AssetHelper;
use FreeFormCertificate\Core\AssetRegistry;
use FreeFormCertificate\UserDashboard\UserManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the dashboard's screens with Site PWA.
 *
 * @phpstan-type ScreenDef array{label: string, icon: string, place: string, position: int, tabs: list<string>, render?: callable(array<int, string>): string, subtitle?: string, badge?: callable(): string}
 */
final class DashboardAppScreens {

	/**
	 * The version of Site PWA's screen contract this class speaks.
	 */
	public const API_VERSION = 1;

	/**
	 * The id of the screen that holds the person's details.
	 */
	public const PROFILE_SCREEN = 'profile';

	/**
	 * Hook the registration action.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'site_pwa_register_screens', array( self::class, 'register' ), 10, 2 );
		add_filter( 'site_pwa_profile_line', array( self::class, 'profile_line' ), 10, 2 );
		add_filter( 'site_pwa_details_screen', array( self::class, 'details_screen' ) );
		add_filter( 'site_pwa_preference_sections', array( self::class, 'preference_sections' ), 10, 2 );
	}

	/**
	 * The Notifications section of the app's Preferences (site-pwa#66): the
	 * e-mail toggles the dashboard keeps on its Profile and Messages tabs.
	 * The appointment reminder is everyone's, as on Profile; the two
	 * date-message toggles join it for a person who sees the Messages tab.
	 *
	 * @param mixed $sections What the app or another plugin handed.
	 * @param mixed $user     The signed-in user.
	 * @return mixed
	 */
	public static function preference_sections( $sections, $user = null ) {
		if ( ! is_array( $sections ) || ! $user instanceof \WP_User || $user->ID <= 0 ) {
			return $sections;
		}
		// Sections are keyed by id; one another plugin named `notifications` is kept.
		if ( ! isset( $sections['notifications'] ) ) {
			$user_id                   = (int) $user->ID;
			$sections['notifications'] = array(
				'label'  => __( 'Notifications', 'ffcertificate' ),
				'render' => static fn(): string => self::notification_preferences( $user_id ),
			);
		}
		return $sections;
	}

	/**
	 * The notification toggles, with the script that saves them through the
	 * same `user/profile` route the dashboard uses.
	 *
	 * @param int $user_id Person.
	 * @return string
	 */
	public static function notification_preferences( int $user_id ): string {
		AssetHelper::enqueue_common_style();
		AssetHelper::enqueue_dark_mode();
		AssetRegistry::enqueue_scripts( 'ffc-core' );
		$suffix = AssetHelper::asset_suffix();
		wp_enqueue_style(
			'ffc-app-notification-preferences-css',
			FFC_PLUGIN_URL . "assets/css/ffc-app-notification-preferences{$suffix}.css",
			array( 'ffc-common' ),
			FFC_VERSION
		);
		wp_enqueue_script(
			'ffc-app-notification-preferences',
			FFC_PLUGIN_URL . "assets/js/ffc-app-notification-preferences{$suffix}.js",
			array( 'ffc-core' ),
			FFC_VERSION,
			true
		);
		wp_localize_script(
			'ffc-app-notification-preferences',
			'ffcAppNotificationPreferences',
			array(
				'rest'    => esc_url_raw( rest_url( 'ffc/v1/user/profile' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'strings' => array(
					'saved'  => __( 'Preferences saved', 'ffcertificate' ),
					'failed' => __( 'Error saving profile', 'ffcertificate' ),
				),
			)
		);

		$toggles  = array(
			UserManager::NOTIFY_APPOINTMENT_REMINDER => array( __( 'Appointment reminder', 'ffcertificate' ), '' ),
		);
		$messages = ! empty( DashboardAssetManager::tab_permissions( $user_id )['messages'] );
		if ( $messages ) {
			$toggles['notify_date_messages']      = array( __( 'Date messages', 'ffcertificate' ), __( 'Such as birthday greetings.', 'ffcertificate' ) );
			$toggles['notify_date_announcements'] = array( __( 'Announcements from this site', 'ffcertificate' ), '' );
		}
		$html = '<div class="ffc-shortcode ffc-app-notification-preferences">';
		foreach ( $toggles as $key => $text ) {
			$html .= '<label class="ffc-control-toggle ffc-control-toggle-mark">'
				. '<input type="checkbox" class="ffc-app-notification-toggle" data-key="' . esc_attr( $key ) . '"'
				. checked( UserManager::wants_notification( $user_id, $key ), true, false ) . ' />'
				. '<span class="ffc-control-toggle-track" aria-hidden="true"></span>'
				. '<span class="ffc-control-toggle-label">' . esc_html( $text[0] )
				. ( '' === $text[1] ? '' : '<small class="ffc-app-notification-desc">' . esc_html( $text[1] ) . '</small>' )
				. '</span></label>';
		}
		if ( $messages ) {
			$html .= '<p class="ffc-app-notification-hint">' . esc_html__( 'The unsubscribe link at the end of these e-mails turns off the same preference.', 'ffcertificate' ) . '</p>';
		}
		$html .= '<span class="ffc-app-notification-status ffc-status" role="status" aria-live="polite" hidden></span>';
		return $html . '</div>';
	}

	/**
	 * The screen More's "My details" opens (site-pwa#56): the Profile screen,
	 * which holds the person's details, instead of the app's account screen.
	 * A screen another plugin named is kept; the app checks the person may
	 * open it and falls back to its own otherwise.
	 *
	 * @param mixed $id What the app or another plugin handed.
	 * @return mixed
	 */
	public static function details_screen( $id ) {
		return '' === $id ? self::PROFILE_SCREEN : $id;
	}

	/**
	 * The line under the person's name on More (site-pwa#23): their RF,
	 * masked as every screen shows it to its owner, and their department.
	 * Either part may be missing; a line another plugin set is kept.
	 *
	 * @param mixed $line What the app or another plugin handed.
	 * @param mixed $user The signed-in user.
	 * @return mixed
	 */
	public static function profile_line( $line, $user = null ) {
		if ( ! is_string( $line ) || '' !== $line || ! $user instanceof \WP_User || $user->ID <= 0 ) {
			return $line;
		}
		$profile = \FreeFormCertificate\UserDashboard\UserProfileService::read( (int) $user->ID, array( 'rf', 'department' ) );
		return self::line( $profile['rf'] ?? null, $profile['department'] ?? null );
	}

	/**
	 * The profile line from what was read: "RF <masked> · <department>",
	 * leaving out a part that is empty.
	 *
	 * @param mixed $rf         The RF, already masked.
	 * @param mixed $department The department.
	 * @return string
	 */
	public static function line( $rf, $department ): string {
		$rf         = is_string( $rf ) ? trim( $rf ) : '';
		$department = is_string( $department ) ? trim( $department ) : '';
		/* translators: %s: the person's RF, masked */
		$parts = array( '' === $rf ? '' : sprintf( __( 'RF %s', 'ffcertificate' ), $rf ), $department );
		return implode( ' · ', array_filter( $parts, static fn( string $part ): bool => '' !== $part ) );
	}

	/**
	 * Add the screens this person has a tab for.
	 *
	 * The app asks once per request, after the user is known, so a screen
	 * whose tab this account would not see is never added.
	 *
	 * @param mixed $screens The app's screen registry.
	 * @param mixed $api     The contract version the app speaks.
	 * @return void
	 */
	public static function register( $screens, $api = 0 ): void {
		if ( ! self::speaks( $screens, $api ) ) {
			return;
		}
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return;
		}

		$visible = self::visible_tabs( $user_id );
		foreach ( self::definitions() as $id => $screen ) {
			$tabs = array_values( array_intersect( $screen['tabs'], $visible ) );
			if ( array() === $tabs ) {
				continue;
			}
			$attribute = implode( ',', $tabs );
			$render    = $screen['render'] ?? null;
			$args      = array(
				'label'    => $screen['label'],
				'icon'     => $screen['icon'],
				'place'    => $screen['place'],
				'position' => $screen['position'],
				'render'   => null !== $render
					? static fn(): string => $render( $tabs )
					: static fn(): string => DashboardShortcode::render( array( 'tabs' => $attribute ) ),
			);
			// More's line and status (site-pwa#23); an older app ignores them.
			foreach ( array( 'subtitle', 'badge' ) as $key ) {
				if ( isset( $screen[ $key ] ) ) {
					$args[ $key ] = $screen[ $key ];
				}
			}
			$screens->add( $id, $args );
		}
	}

	/**
	 * Whether the app speaks the version of the contract this class knows.
	 *
	 * @param mixed $screens The app's screen registry.
	 * @param mixed $api     The contract version.
	 * @return bool
	 */
	public static function speaks( $screens, $api ): bool {
		return self::API_VERSION === (int) $api && is_object( $screens ) && method_exists( $screens, 'add' );
	}

	/**
	 * The dashboard's screens, by id. A screen with its own `render` draws
	 * the app's design and is handed the tabs this person sees; the rest
	 * draw their dashboard tab.
	 *
	 * @return array<string, ScreenDef>
	 */
	public static function definitions(): array {
		return array(
			'calendar'           => array(
				// "Agenda", as the approved app names it (site-pwa#23); the id stays.
				'label'    => __( 'Agenda', 'ffcertificate' ),
				'icon'     => 'calendar',
				'place'    => 'nav',
				'position' => 1,
				'tabs'     => array( 'appointments', 'audience' ),
				// The app draws its own screen over the same routes (#2781).
				'render'   => array( AgendaAppScreen::class, 'render' ),
			),
			'certificates'       => array(
				'label'    => __( 'Certificates', 'ffcertificate' ),
				'icon'     => 'document',
				'place'    => 'nav',
				'position' => 2,
				'tabs'     => array( 'certificates' ),
				// The app draws its own screen over the same route (#2781).
				'render'   => array( CertificatesAppScreen::class, 'render' ),
			),
			'messages'           => array(
				'label'    => __( 'Messages', 'ffcertificate' ),
				'icon'     => 'news',
				'place'    => 'more',
				'position' => 20,
				'tabs'     => array( 'messages' ),
				// The toggles live in the app's Preferences (site-pwa#66).
				'render'   => array( self::class, 'render_without_preferences' ),
			),
			'reregistration'     => array(
				'label'    => __( 'Reregistration', 'ffcertificate' ),
				'icon'     => 'edit',
				'place'    => 'more',
				'position' => 21,
				'tabs'     => array( 'reregistrations' ),
				'subtitle' => __( 'Keep your registration up to date', 'ffcertificate' ),
				'badge'    => array( self::class, 'reregistration_badge' ),
			),
			'recruitment'        => array(
				'label'    => __( 'Recruitment', 'ffcertificate' ),
				'icon'     => 'grid',
				'place'    => 'more',
				'position' => 22,
				'tabs'     => array( 'recruitment' ),
			),
			self::PROFILE_SCREEN => array(
				'label'    => __( 'Profile', 'ffcertificate' ),
				'icon'     => 'user',
				'place'    => 'more',
				'position' => 30,
				'tabs'     => array( 'profile' ),
				// The toggles live in the app's Preferences (site-pwa#66).
				'render'   => array( self::class, 'render_without_preferences' ),
			),
		);
	}

	/**
	 * The app's Messages and Profile screens: their dashboard tab, without
	 * the notification toggles where the app shows them in Preferences.
	 *
	 * @param array<int, string> $tabs The tabs this person sees.
	 * @return string
	 */
	public static function render_without_preferences( array $tabs ): string {
		$html = DashboardShortcode::render( array( 'tabs' => implode( ',', $tabs ) ) );
		if ( self::app_has_preference_sections() ) {
			wp_add_inline_script( 'ffc-dashboard', 'window.ffcDashboard && (window.ffcDashboard.notificationPreferences = false);', 'after' );
		}
		return $html;
	}

	/**
	 * Whether the running Site PWA draws plugin sections in Preferences
	 * (site-pwa#66). The two plugins update apart, and an app without them
	 * would leave the toggles nowhere, so Messages and Profile keep them
	 * until it has. The one Site PWA name this plugin reads.
	 *
	 * @param string $frame The app's frame class; a test names its own.
	 * @return bool
	 */
	public static function app_has_preference_sections( string $frame = '\\SitePwa\\App\\Frame' ): bool {
		return method_exists( $frame, 'preferenceSections' );
	}

	/**
	 * Reregistration's status beside its name in More: "Pending" while a
	 * campaign is still open for this person to submit, the same campaigns
	 * Home lists. Evaluated only when More is drawn.
	 *
	 * @param int|null $user_id Person; the current user when null.
	 * @return string
	 */
	public static function reregistration_badge( ?int $user_id = null ): string {
		$user_id = $user_id ?? get_current_user_id();
		if ( $user_id <= 0 || ! \FreeFormCertificate\Settings\SettingsReader::module_enabled( 'reregistration' ) ) {
			return '';
		}
		foreach ( \FreeFormCertificate\Reregistration\ReregistrationFrontend::get_user_reregistrations( $user_id ) as $campaign ) {
			if ( ! empty( $campaign['can_submit'] ) ) {
				return __( 'Pending', 'ffcertificate' );
			}
		}
		return '';
	}

	/**
	 * The dashboard tabs a person sees, by the same rules as the dashboard.
	 *
	 * @param int $user_id Person.
	 * @return list<string>
	 */
	public static function visible_tabs( int $user_id ): array {
		$visible = array_keys( array_filter( DashboardAssetManager::tab_permissions( $user_id ) ) );
		if ( \FreeFormCertificate\Settings\SettingsReader::module_enabled( 'recruitment' )
			&& class_exists( '\FreeFormCertificate\Recruitment\RecruitmentDashboardSection' )
			&& '' !== \FreeFormCertificate\Recruitment\RecruitmentDashboardSection::render_for_user( $user_id ) ) {
			$visible[] = 'recruitment';
		}
		$visible[] = 'profile';

		return array_values( array_intersect( DashboardShortcode::TABS, $visible ) );
	}
}
