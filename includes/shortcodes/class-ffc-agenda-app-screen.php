<?php
/**
 * AgendaAppScreen
 *
 * The Calendar screen of the installable app (#2781): the person's personal
 * appointments and audience bookings as day cards, with what to do next on
 * each, and the calendars they can book. It reads the same two routes as the
 * dashboard, so every rule about what a person sees stays in one place.
 *
 * @package FreeFormCertificate\Shortcodes
 * @since   6.50.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Shortcodes;

use FreeFormCertificate\Audience\AudienceAppScreens;
use FreeFormCertificate\Core\AssetHelper;
use FreeFormCertificate\Core\DateFormatter;
use FreeFormCertificate\Core\ShortcodePage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Draws the app's Calendar screen; the dashboard outside the app keeps its
 * tables.
 */
final class AgendaAppScreen {

	/**
	 * The most calendars the Book view lists.
	 */
	public const BOOK_LIMIT = 20;

	/**
	 * The screen's markup, with its sheet and script enqueued.
	 *
	 * @param array<int, string> $tabs The dashboard tabs this person sees
	 *                                 among `appointments` and `audience`.
	 * @return string
	 */
	public static function render( array $tabs ): string {
		AssetHelper::enqueue_common_style();
		AssetHelper::enqueue_dark_mode();

		$suffix = AssetHelper::asset_suffix();
		wp_enqueue_style(
			'ffc-app-agenda',
			FFC_PLUGIN_URL . "assets/css/ffc-app-agenda{$suffix}.css",
			array( 'ffc-common' ),
			FFC_VERSION
		);
		wp_enqueue_script(
			'ffc-app-agenda',
			FFC_PLUGIN_URL . "assets/js/ffc-app-agenda{$suffix}.js",
			array(),
			FFC_VERSION,
			true
		);

		$routes = array();
		if ( in_array( 'appointments', $tabs, true ) ) {
			$routes['appointments'] = esc_url_raw( rest_url( 'ffc/v1/user/appointments' ) );
		}
		if ( in_array( 'audience', $tabs, true ) ) {
			$routes['audience'] = esc_url_raw( rest_url( 'ffc/v1/user/audience-bookings' ) );
		}

		wp_localize_script(
			'ffc-app-agenda',
			'ffcAppAgenda',
			array(
				'routes'   => $routes,
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'today'    => current_time( 'Y-m-d' ),
				'dates'    => DateFormatter::script_config(),
				'presence' => in_array( 'audience', $tabs, true ) && current_user_can( AudienceAppScreens::PARTICIPANT_CAPABILITY ),
				'book'     => in_array( 'appointments', $tabs, true ) ? self::bookable() : array(),
				'strings'  => array(
					'loading'   => __( 'Loading your calendar…', 'ffcertificate' ),
					'failed'    => __( 'Your calendar could not be loaded. Check the connection and try again.', 'ffcertificate' ),
					'empty'     => __( 'Nothing booked ahead.', 'ffcertificate' ),
					/* translators: %d: how many bookings already took place */
					'past'      => __( 'Already held (%d)', 'ffcertificate' ),
					'today'     => __( 'Today', 'ffcertificate' ),
					'confirmed' => __( 'Confirmed', 'ffcertificate' ),
					'enrolled'  => __( 'Enrolled', 'ffcertificate' ),
					'pending'   => __( 'Pending', 'ffcertificate' ),
					'presence'  => __( 'Presence QR', 'ffcertificate' ),
					/* translators: %s: when the post-event form closes */
					'form'      => __( 'Fill in the form · until %s', 'ffcertificate' ),
					'receipt'   => __( 'Receipt', 'ffcertificate' ),
					'newOne'    => __( 'New booking', 'ffcertificate' ),
					'noBook'    => __( 'No calendar is open for booking here. Book from the calendar\'s page on the site.', 'ffcertificate' ),
				),
			)
		);

		ob_start();
		include FFC_PLUGIN_DIR . 'templates/public/app/agenda.php';
		return (string) ob_get_clean();
	}

	/**
	 * The personal calendars a page on the site lets this person book.
	 *
	 * A calendar with no published page that embeds it cannot be booked
	 * from the app, so it is left out rather than linked to nothing.
	 *
	 * @return list<array{title: string, url: string}>
	 */
	public static function bookable(): array {
		$ids = get_posts(
			array(
				'post_type'      => 'ffc_self_scheduling',
				'post_status'    => 'publish',
				'posts_per_page' => self::BOOK_LIMIT,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'fields'         => 'ids',
			)
		);
		$out = array();
		foreach ( $ids as $id ) {
			$url = ShortcodePage::url( 'ffc_self_scheduling', (int) $id );
			// The app's own address for the page when Site PWA can draw it, so
			// booking stays inside the app instead of the theme; unchanged
			// without Site PWA (#2846).
			$url = '' === $url ? '' : (string) apply_filters( 'site_pwa_app_url', $url );
			if ( '' !== $url ) {
				$out[] = array(
					'title' => get_the_title( (int) $id ),
					'url'   => $url,
				);
			}
		}
		return $out;
	}
}
