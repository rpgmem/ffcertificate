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
	 * Hook the registration action.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'site_pwa_register_screens', array( self::class, 'register' ), 10, 2 );
		add_filter( 'site_pwa_profile_line', array( self::class, 'profile_line' ), 10, 2 );
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
			'calendar'       => array(
				// "Agenda", as the approved app names it (site-pwa#23); the id stays.
				'label'    => __( 'Agenda', 'ffcertificate' ),
				'icon'     => 'calendar',
				'place'    => 'nav',
				'position' => 1,
				'tabs'     => array( 'appointments', 'audience' ),
				// The app draws its own screen over the same routes (#2781).
				'render'   => array( AgendaAppScreen::class, 'render' ),
			),
			'certificates'   => array(
				'label'    => __( 'Certificates', 'ffcertificate' ),
				'icon'     => 'document',
				'place'    => 'nav',
				'position' => 2,
				'tabs'     => array( 'certificates' ),
				// The app draws its own screen over the same route (#2781).
				'render'   => array( CertificatesAppScreen::class, 'render' ),
			),
			'messages'       => array(
				'label'    => __( 'Messages', 'ffcertificate' ),
				'icon'     => 'news',
				'place'    => 'more',
				'position' => 20,
				'tabs'     => array( 'messages' ),
			),
			'reregistration' => array(
				'label'    => __( 'Reregistration', 'ffcertificate' ),
				'icon'     => 'edit',
				'place'    => 'more',
				'position' => 21,
				'tabs'     => array( 'reregistrations' ),
				'subtitle' => __( 'Keep your registration up to date', 'ffcertificate' ),
				'badge'    => array( self::class, 'reregistration_badge' ),
			),
			'recruitment'    => array(
				'label'    => __( 'Recruitment', 'ffcertificate' ),
				'icon'     => 'grid',
				'place'    => 'more',
				'position' => 22,
				'tabs'     => array( 'recruitment' ),
			),
			'profile'        => array(
				'label'    => __( 'Profile', 'ffcertificate' ),
				'icon'     => 'user',
				'place'    => 'more',
				'position' => 30,
				'tabs'     => array( 'profile' ),
			),
		);
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
