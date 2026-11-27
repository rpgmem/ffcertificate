<?php
/**
 * AppHome
 *
 * What the installable app's Home shows a participant (site-pwa#23): the
 * attendance just confirmed, the next commitment and what waits for them.
 *
 * It answers Site PWA's `site_pwa_home` filter. Without that plugin the
 * filter never runs, and nothing here does either.
 *
 * @package FreeFormCertificate\Shortcodes
 * @since   6.50.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Shortcodes;

use FreeFormCertificate\Audience\AudienceAppScreens;
use FreeFormCertificate\Audience\AudiencePresenceEvents;
use FreeFormCertificate\Audience\AudienceQueryService;
use FreeFormCertificate\Core\DateFormatter;
use FreeFormCertificate\Frontend\CertificateCompletionLink;
use FreeFormCertificate\Reregistration\ReregistrationFrontend;
use FreeFormCertificate\Repositories\SubmissionRepository;
use FreeFormCertificate\Settings\SettingsReader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Feeds the app's Home from what the site already holds; nothing is stored.
 *
 * Every item names an FFC screen of the app by its id, so the app decides
 * whether this person may open it and drops the item when not.
 */
final class AppHome {

	/**
	 * The version of Site PWA's home contract this class speaks.
	 */
	public const API_VERSION = 1;

	/**
	 * How long an issued certificate is news on Home, in days: the same as
	 * the "New" badge on the app's Certificates screen.
	 */
	public const NEW_CERTIFICATE_DAYS = CertificatesAppScreen::NEW_DAYS;

	/**
	 * Hook the home filter.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'site_pwa_home', array( self::class, 'feed' ), 10, 3 );
	}

	/**
	 * Add FFC's items to what the app was handed.
	 *
	 * @param mixed $home The app's home items.
	 * @param mixed $user The signed-in user.
	 * @param mixed $api  The contract version the app speaks.
	 * @return mixed
	 */
	public static function feed( $home, $user = null, $api = 0 ) {
		if ( self::API_VERSION !== (int) $api || ! is_array( $home ) || ! $user instanceof \WP_User || $user->ID <= 0 ) {
			return $home;
		}
		return self::merge( $home, self::items( (int) $user->ID, time() ) );
	}

	/**
	 * What the app was handed, with FFC's items added: banners and pending
	 * items after the ones already there, and the next commitment only when
	 * no other plugin set one.
	 *
	 * @param array<mixed> $home  The app's home items.
	 * @param array<mixed> $items FFC's items, as build() returns them.
	 * @return array<mixed>
	 */
	public static function merge( array $home, array $items ): array {
		foreach ( array( 'banners', 'pending' ) as $list ) {
			$home[ $list ] = array_merge(
				is_array( $home[ $list ] ?? null ) ? $home[ $list ] : array(),
				is_array( $items[ $list ] ?? null ) ? $items[ $list ] : array()
			);
		}
		if ( null === ( $home['next'] ?? null ) && null !== ( $items['next'] ?? null ) ) {
			$home['next'] = $items['next'];
		}
		return $home;
	}

	/**
	 * FFC's own items for one person, read from what the site holds.
	 *
	 * @param int $user_id User.
	 * @param int $now     Unix time.
	 * @return array{banners: list<array<string, string>>, next: array<string, mixed>|null, pending: list<array<string, string>>}
	 */
	public static function items( int $user_id, int $now ): array {
		$attendance = SettingsReader::module_enabled( 'audiences' ) && user_can( $user_id, AudienceAppScreens::PARTICIPANT_CAPABILITY );
		$campaigns  = SettingsReader::module_enabled( 'reregistration' ) ? ReregistrationFrontend::get_user_reregistrations( $user_id ) : array();
		$latest     = SettingsReader::module_enabled( 'certificates' ) && user_can( $user_id, 'ffc_view_own_certificates' )
			? ( new SubmissionRepository() )->latest_certificate_for_user( $user_id, $now - self::NEW_CERTIFICATE_DAYS * DAY_IN_SECONDS )
			: null;

		return self::build(
			$attendance ? AudiencePresenceEvents::pending( $user_id, $now ) : array(),
			$attendance ? self::next_event( $user_id, $now ) : null,
			$campaigns,
			$latest,
			SettingsReader::module_enabled( 'certificates' ) ? CertificateCompletionLink::pending_for_user( $user_id, $now ) : array()
		);
	}

	/**
	 * The items, from what was read.
	 *
	 * @param list<array<string, mixed>>                                           $pending     The Presence screen's pending list (confirmations and post-event forms).
	 * @param array{title: string, place: string, date: string, time: string}|null $event       The next booking.
	 * @param array<int, array<string, mixed>>                                     $campaigns   The person's reregistration campaigns.
	 * @param array{id: int, form_title: string, submission_date: int}|null        $certificate The newest recent certificate.
	 * @param list<array{title: string, url: string, until: string}>               $to_complete Certificates waiting on profile data after a terminal reading (#2893).
	 * @return array{banners: list<array<string, string>>, next: array<string, mixed>|null, pending: list<array<string, string>>}
	 */
	public static function build( array $pending, ?array $event, array $campaigns, ?array $certificate, array $to_complete = array() ): array {
		$banners = array();
		$items   = array();
		$next    = null;

		foreach ( $pending as $item ) {
			$type  = (string) ( $item['type'] ?? '' );
			$title = (string) ( $item['title'] ?? '' );
			if ( 'confirmed' === $type ) {
				$banners[] = array(
					'id'       => 'ffc:' . (string) ( $item['key'] ?? '' ),
					/* translators: %s: event title */
					'text'     => __( 'Attendance confirmed at %s', 'ffcertificate' ),
					'emphasis' => $title,
					'tone'     => 'success',
				);
				continue;
			}
			if ( 'form' === $type && is_string( $item['url'] ?? null ) ) {
				$closes  = (string) ( $item['closes'] ?? '' );
				$items[] = array(
					'label' => __( 'Post-event form', 'ffcertificate' ),
					'meta'  => '' === $closes
						? $title
						/* translators: 1: event title, 2: date the form closes */
						: sprintf( __( '%1$s · until %2$s', 'ffcertificate' ), $title, $closes ),
					'url'   => $item['url'],
					'icon'  => 'document',
					'tone'  => 'info',
				);
			}
		}

		if ( null !== $event ) {
			$next = array(
				'title'   => $event['title'],
				'meta'    => implode( ' · ', array_filter( array( $event['place'], $event['date'], $event['time'] ), static fn( string $part ): bool => '' !== $part ) ),
				'actions' => array(
					array(
						'label'   => __( 'Presence QR', 'ffcertificate' ),
						'screen'  => 'presence',
						'icon'    => 'qr',
						'primary' => true,
					),
					array(
						'label'  => __( 'Details', 'ffcertificate' ),
						'screen' => 'calendar',
					),
				),
			);
		}

		foreach ( $campaigns as $campaign ) {
			if ( empty( $campaign['can_submit'] ) ) {
				continue;
			}
			$end     = DateFormatter::format_wallclock_date( (string) ( $campaign['end_date'] ?? '' ) );
			$items[] = array(
				'label'  => (string) ( $campaign['title'] ?? '' ),
				/* translators: %s: the campaign's last day */
				'meta'   => '' === $end ? '' : sprintf( __( 'Deadline %s', 'ffcertificate' ), $end ),
				'screen' => 'reregistration',
				'icon'   => 'edit',
				'tone'   => 'warning',
			);
		}

		foreach ( $to_complete as $item ) {
			// Without a link the item leads to the Certificates screen, which
			// lists it (#2936); Site PWA drops an item with neither.
			$link    = '' !== $item['url'] ? array( 'url' => $item['url'] ) : array( 'screen' => CertificateCompletionLink::APP_SCREEN );
			$items[] = array(
				'label' => __( 'Complete your certificate', 'ffcertificate' ),
				/* translators: 1: form title, 2: the date the completion link closes */
				'meta'  => sprintf( __( '%1$s · until %2$s', 'ffcertificate' ), $item['title'], $item['until'] ),
			) + $link + array(
				'icon' => 'edit',
				'tone' => 'warning',
			);
		}

		if ( null !== $certificate ) {
			$items[] = array(
				'label'  => __( 'Certificate available', 'ffcertificate' ),
				'meta'   => $certificate['form_title'],
				'screen' => 'certificates',
				'icon'   => 'award',
				'tone'   => 'success',
			);
		}

		return array(
			'banners' => $banners,
			'next'    => $next,
			'pending' => $items,
		);
	}

	/**
	 * The person's next booking over the presence window, soonest first: one
	 * they take part in, never an event merely open to everyone, which the
	 * Presence screen lists but nobody has committed to.
	 *
	 * @param int $user_id User.
	 * @param int $now     Unix time.
	 * @return array{title: string, place: string, date: string, time: string}|null
	 */
	private static function next_event( int $user_id, int $now ): ?array {
		$bookings = AudienceQueryService::find_user_bookings(
			$user_id,
			array(
				'start_date'     => (string) wp_date( 'Y-m-d', $now ),
				'end_date'       => (string) wp_date( 'Y-m-d', $now + AudiencePresenceEvents::DAYS_AHEAD * DAY_IN_SECONDS ),
				'exclude_status' => 'cancelled',
			)
		);
		usort(
			$bookings,
			static fn( array $a, array $b ): int => array( $a['booking_date'], $a['start_time'], (int) $a['id'] )
				<=> array( $b['booking_date'], $b['start_time'], (int) $b['id'] )
		);
		foreach ( $bookings as $booking ) {
			// event() leaves out what has already ended, as the Presence screen does.
			$event = AudiencePresenceEvents::event( $booking, $user_id, $now );
			if ( null !== $event ) {
				return array(
					'title' => $event['title'],
					'place' => $event['place'],
					'date'  => $event['date'],
					'time'  => $event['time'],
				);
			}
		}
		return null;
	}
}
