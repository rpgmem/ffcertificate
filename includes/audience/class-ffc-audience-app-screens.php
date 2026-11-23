<?php
/**
 * AudienceAppScreens
 *
 * The attendance screens inside the installable app (#2750): the
 * participant's Presence screen and the operator's section. They are
 * registered now, so the app's navigation is settled before the screens
 * behind them land; until each one does, it says so (#2738 phases C and D).
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
use FreeFormCertificate\Core\Notice;

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
				$args['render'] = array( self::class, 'render_coming_soon' );
			}
			$screens->add( $id, $args );
		}
	}

	/**
	 * The attendance screens, by id.
	 *
	 * Manual attendance already exists, on the booking's Attendance screen in
	 * wp-admin, so it links there; the others draw a "coming soon" notice
	 * until their issue lands.
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
				'capability' => self::OPERATOR_CAPABILITY,
			),
			'operator-terminals'  => array(
				'label'      => __( 'My terminals', 'ffcertificate' ),
				'icon'       => 'grid',
				'place'      => 'more',
				'position'   => 41,
				'capability' => self::OPERATOR_CAPABILITY,
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
	 * What a screen says until it lands.
	 *
	 * @return string
	 */
	public static function render_coming_soon(): string {
		AssetHelper::enqueue_common_style();
		AssetHelper::enqueue_dark_mode();

		return '<div class="ffc-shortcode">' . Notice::render(
			'info',
			'clock',
			__( 'This screen arrives in a coming update.', 'ffcertificate' ),
			'',
			array(
				'role' => 'note',
				'hook' => 'app-coming-soon',
			)
		) . '</div>';
	}
}
