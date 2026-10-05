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
	 * Send the file, or refuse a link this site did not sign.
	 */
	public function handle(): void {
		$ics = self::build(
			\FreeFormCertificate\Core\RequestInput::get_get_string( 'e' ),
			\FreeFormCertificate\Core\RequestInput::get_get_string( 's' )
		);

		if ( '' === $ics ) {
			wp_die( esc_html__( 'This event link is not valid.', 'ffcertificate' ), '', array( 'response' => 403 ) );
		}

		nocache_headers();
		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="event.ics"' );
		echo $ics; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- an iCalendar file, not HTML; every text value is escaped by IcsGenerator.
		exit;
	}

	/**
	 * The iCalendar body for a signed link, or '' when the link is not valid.
	 *
	 * @param string $data      The `e` parameter.
	 * @param string $signature The `s` parameter.
	 * @return string
	 */
	public static function build( string $data, string $signature ): string {
		$event = QrEventLink::verify( $data, $signature );
		if ( null === $event || '' === $event['title'] ) {
			return '';
		}

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
