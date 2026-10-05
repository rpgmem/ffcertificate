<?php
/**
 * Serves the .ics file behind a manual QR code's event link.
 *
 * @package FreeFormCertificate\UrlShortener
 * @since   6.34.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\UrlShortener;

use FreeFormCertificate\Generators\QrEventLink;
use FreeFormCertificate\Scheduling\IcsGenerator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `admin-post.php?action=ffc_qr_ics` (#1563).
 *
 * Public on purpose -- whoever scans the code is not logged in -- and safe
 * because it serves only events {@see QrEventLink} signed: the link is the
 * whole state, so nothing is looked up and nothing is stored. The file is
 * built by the same `IcsGenerator` the scheduling invitations use, with
 * METHOD:PUBLISH, since it is an event to save, not an invitation to answer.
 */
class QrEventIcsHandler {

	/**
	 * Register hooks. Both the logged-in and the anonymous variant: a phone
	 * scanning the code usually carries no WordPress session.
	 */
	public function init(): void {
		add_action( 'admin_post_' . QrEventLink::ACTION, array( $this, 'handle' ) );
		add_action( 'admin_post_nopriv_' . QrEventLink::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Send the file, or refuse a link this site did not sign (403) or whose
	 * `until` date has passed (410, gone on purpose rather than forged).
	 */
	public function handle(): void {
		$signature = \FreeFormCertificate\Core\RequestInput::get_get_string( 's' );
		$event     = self::signed_event( \FreeFormCertificate\Core\RequestInput::get_get_string( 'e' ), $signature );

		if ( null === $event ) {
			wp_die( esc_html__( 'This event link is not valid.', 'ffcertificate' ), '', array( 'response' => 403 ) );
		}
		if ( QrEventLink::expired( $event ) ) {
			wp_die( esc_html__( 'This event link has expired.', 'ffcertificate' ), '', array( 'response' => 410 ) );
		}

		nocache_headers();
		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="event.ics"' );
		echo self::ics( $event, $signature ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- an iCalendar file, not HTML; every text value is escaped by IcsGenerator.
		exit;
	}

	/**
	 * The iCalendar body for a signed, unexpired link, or '' otherwise.
	 *
	 * @param string $data      The `e` parameter.
	 * @param string $signature The `s` parameter.
	 * @return string
	 */
	public static function build( string $data, string $signature ): string {
		$event = self::signed_event( $data, $signature );
		if ( null === $event || QrEventLink::expired( $event ) ) {
			return '';
		}
		return self::ics( $event, $signature );
	}

	/**
	 * The event of a link this site signed, or null; an untitled event
	 * cannot be one the generator produced.
	 *
	 * @param string $data      The `e` parameter.
	 * @param string $signature The `s` parameter.
	 * @return array<string, string>|null
	 */
	private static function signed_event( string $data, string $signature ): ?array {
		$event = QrEventLink::verify( $data, $signature );
		return ( null === $event || '' === $event['title'] ) ? null : $event;
	}

	/**
	 * The iCalendar body of an event.
	 *
	 * @param array<string, string> $event     Verified event.
	 * @param string                $signature Its signature, which seeds the UID.
	 * @return string
	 */
	private static function ics( array $event, string $signature ): string {
		return IcsGenerator::generate(
			array(
				// Stable for the same link: re-importing updates instead of duplicating.
				'uid'         => 'ffc-qr-' . substr( $signature, 0, 16 ),
				'summary'     => $event['title'],
				'description' => $event['description'],
				'location'    => $event['location'],
				'date'        => $event['date'],
				'start_time'  => $event['start'] . ':00',
				'end_time'    => $event['end'] . ':00',
			),
			'PUBLISH'
		);
	}
}
