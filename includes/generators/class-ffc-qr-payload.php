<?php
/**
 * QR code payload builders.
 *
 * @package FreeFormCertificate\Generators
 * @since   6.34.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Generators;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns the manual generator's fields into the text a QR code carries (#1563).
 *
 * Every format here is the de-facto one phone cameras act on: `WIFI:` for a
 * network, `mailto:`, `tel:`, `SMSTO:` and `wa.me` for WhatsApp. Nothing is
 * stored: the builder is pure, so the generator stays stateless.
 */
final class QrPayload {

	/** Types the manual generator offers. */
	public const TYPES = array( 'url', 'text', 'wifi', 'email', 'phone', 'sms', 'whatsapp', 'vcard', 'social', 'event' );

	/**
	 * Social networks: label and profile prefix; the operator types only the
	 * user name. Labels are brand names and are not translated.
	 */
	public const NETWORKS = array(
		'instagram' => array( 'Instagram', 'https://www.instagram.com/' ),
		'facebook'  => array( 'Facebook', 'https://www.facebook.com/' ),
		'x'         => array( 'X (Twitter)', 'https://x.com/' ),
		'linkedin'  => array( 'LinkedIn', 'https://www.linkedin.com/in/' ),
		'youtube'   => array( 'YouTube', 'https://www.youtube.com/@' ),
		'tiktok'    => array( 'TikTok', 'https://www.tiktok.com/@' ),
		'threads'   => array( 'Threads', 'https://www.threads.net/@' ),
		'telegram'  => array( 'Telegram', 'https://t.me/' ),
		'github'    => array( 'GitHub', 'https://github.com/' ),
	);

	/** How an event is delivered by the code. */
	public const EVENT_MODES = array( 'vevent', 'google', 'ics' );

	/**
	 * Wi-Fi security types (`nopass` is an open network; `WPA2-EAP` is
	 * WPA2/WPA3 Enterprise, an 802.1X network that asks for a user name).
	 */
	public const WIFI_SECURITY = array( 'WPA', 'WEP', 'nopass', 'WPA2-EAP' );

	/** Enterprise outer methods that sign in with a user name and password. */
	public const WIFI_EAP_METHODS = array( 'PEAP', 'TTLS' );

	/** Enterprise inner (phase 2) authentication methods. */
	public const WIFI_PHASE2 = array( 'MSCHAPV2', 'GTC', 'PAP' );

	/**
	 * Byte-mode capacity of a version-40 code, per error-correction level.
	 * Anything longer cannot be encoded at that level at all.
	 */
	public const CAPACITY = array(
		'L' => 2953,
		'M' => 2331,
		'Q' => 1663,
		'H' => 1273,
	);

	/**
	 * From this version on the modules get small enough that a code printed
	 * at a usual size stops scanning reliably: a warning, not a refusal.
	 */
	public const DENSE_VERSION = 10;

	/**
	 * Build the payload for a type.
	 *
	 * @param string               $type   One of self::TYPES.
	 * @param array<string, mixed> $fields Raw fields for that type.
	 * @return string|WP_Error
	 */
	public static function build( string $type, array $fields ) {
		$get = static function ( string $key ) use ( $fields ): string {
			$value = $fields[ $key ] ?? '';
			return is_scalar( $value ) ? trim( (string) $value ) : '';
		};

		switch ( $type ) {
			case 'url':
				return self::url( $get( 'url' ) );
			case 'text':
				return '' === $get( 'text' ) ? self::missing( __( 'Enter the text to encode.', 'ffcertificate' ) ) : $get( 'text' );
			case 'wifi':
				return self::wifi( $get, '' !== $get( 'hidden' ) );
			case 'email':
				return self::email( $get( 'email' ), $get( 'subject' ), $get( 'body' ) );
			case 'phone':
				$number = self::phone_number( $get( 'phone' ) );
				return '' === $number ? self::missing( __( 'Enter a valid phone number.', 'ffcertificate' ) ) : 'tel:' . $number;
			case 'sms':
				$number = self::phone_number( $get( 'phone' ) );
				return '' === $number ? self::missing( __( 'Enter a valid phone number.', 'ffcertificate' ) ) : 'SMSTO:' . $number . ':' . $get( 'message' );
			case 'whatsapp':
				return self::whatsapp( $get( 'phone' ), $get( 'message' ) );
			case 'vcard':
				return self::vcard( $get );
			case 'social':
				return self::social( $get( 'network' ), $get( 'username' ) );
			case 'event':
				return self::event( $get );
			default:
				return new WP_Error( 'ffc_qr_type', __( 'Unknown QR code type.', 'ffcertificate' ) );
		}
	}

	/**
	 * How full the code is and whether it is getting too dense.
	 *
	 * @param string $payload Encoded text.
	 * @param string $ecc     Error correction level actually used.
	 * @param int    $side    Modules per side of the encoded matrix (0 if it failed).
	 * @return array{bytes: int, capacity: int, percent: int, version: int, dense: bool}
	 */
	public static function usage( string $payload, string $ecc, int $side ): array {
		$bytes    = strlen( $payload );
		$capacity = self::CAPACITY[ $ecc ] ?? self::CAPACITY['M'];
		$version  = $side >= 21 ? (int) ( ( $side - 17 ) / 4 ) : 0;

		return array(
			'bytes'    => $bytes,
			'capacity' => $capacity,
			'percent'  => (int) min( 100, (int) ceil( $bytes * 100 / $capacity ) ),
			'version'  => $version,
			'dense'    => $version >= self::DENSE_VERSION,
		);
	}

	/**
	 * A web address.
	 *
	 * @param string $url Candidate.
	 * @return string|WP_Error
	 */
	private static function url( string $url ) {
		if ( '' !== $url && ! preg_match( '#^[a-z][a-z0-9+.-]*://#i', $url ) ) {
			$url = 'https://' . $url;
		}
		$clean = esc_url_raw( $url, array( 'http', 'https' ) );
		if ( '' === $clean || false === wp_http_validate_url( $clean ) ) {
			return self::missing( __( 'Enter a valid web address (http or https).', 'ffcertificate' ) );
		}
		return $clean;
	}

	/**
	 * A Wi-Fi network, in the `WIFI:` format phone cameras join.
	 *
	 * An Enterprise (802.1X) network adds the user name (`I:`), the outer
	 * method (`E:`), the phase-2 method (`PH2:`) and an optional anonymous
	 * identity (`A:`) — the fields Android reads; the iPhone camera does not
	 * join Enterprise networks from a code at all.
	 *
	 * @param callable(string): string $get    Field reader.
	 * @param bool                     $hidden Whether the network hides its name.
	 * @return string|WP_Error
	 */
	private static function wifi( callable $get, bool $hidden ) {
		$ssid     = $get( 'ssid' );
		$password = $get( 'password' );
		if ( '' === $ssid ) {
			return self::missing( __( 'Enter the network name.', 'ffcertificate' ) );
		}
		$security = in_array( $get( 'security' ), self::WIFI_SECURITY, true ) ? $get( 'security' ) : 'WPA';
		if ( 'nopass' !== $security && '' === $password ) {
			return self::missing( __( 'Enter the network password, or choose an open network.', 'ffcertificate' ) );
		}

		$payload = 'WIFI:T:' . $security . ';S:' . self::wifi_escape( $ssid ) . ';';
		if ( 'nopass' !== $security ) {
			$payload .= 'P:' . self::wifi_escape( $password ) . ';';
		}
		if ( 'WPA2-EAP' === $security ) {
			$identity = $get( 'identity' );
			if ( '' === $identity ) {
				return self::missing( __( 'Enter the user name for the Enterprise network.', 'ffcertificate' ) );
			}
			$method   = in_array( $get( 'eap' ), self::WIFI_EAP_METHODS, true ) ? $get( 'eap' ) : 'PEAP';
			$phase2   = in_array( $get( 'phase2' ), self::WIFI_PHASE2, true ) ? $get( 'phase2' ) : 'MSCHAPV2';
			$payload .= 'E:' . $method . ';PH2:' . $phase2 . ';I:' . self::wifi_escape( $identity ) . ';';
			if ( '' !== $get( 'anonymous' ) ) {
				$payload .= 'A:' . self::wifi_escape( $get( 'anonymous' ) ) . ';';
			}
		}
		if ( $hidden ) {
			$payload .= 'H:true;';
		}
		return $payload . ';';
	}

	/**
	 * Escape a Wi-Fi field: backslash, semicolon, comma, colon and quote are special.
	 *
	 * @param string $value Field.
	 * @return string
	 */
	private static function wifi_escape( string $value ): string {
		return (string) preg_replace( '/([\\\\;,:"])/', '\\\\$1', $value );
	}

	/**
	 * An e-mail draft, as a `mailto:` link.
	 *
	 * @param string $address Recipient.
	 * @param string $subject Subject.
	 * @param string $body    Body.
	 * @return string|WP_Error
	 */
	private static function email( string $address, string $subject, string $body ) {
		$address = \FreeFormCertificate\Core\DataSanitizer::normalize_email( sanitize_email( $address ) );
		if ( '' === $address || ! is_email( $address ) ) {
			return self::missing( __( 'Enter a valid e-mail address.', 'ffcertificate' ) );
		}

		$query = array();
		if ( '' !== $subject ) {
			$query[] = 'subject=' . rawurlencode( $subject );
		}
		if ( '' !== $body ) {
			$query[] = 'body=' . rawurlencode( $body );
		}
		return 'mailto:' . $address . ( array() === $query ? '' : '?' . implode( '&', $query ) );
	}

	/**
	 * A WhatsApp chat link with an optional pre-filled message.
	 *
	 * @param string $phone   Number with country code.
	 * @param string $message Message.
	 * @return string|WP_Error
	 */
	private static function whatsapp( string $phone, string $message ) {
		$digits = ltrim( self::phone_number( $phone ), '+' );
		if ( '' === $digits ) {
			return self::missing( __( 'Enter a valid phone number.', 'ffcertificate' ) );
		}
		return 'https://wa.me/' . $digits . ( '' === $message ? '' : '?text=' . rawurlencode( $message ) );
	}

	/**
	 * A dialable number: digits with an optional leading `+`, 3 to 15 digits.
	 *
	 * @param string $raw As typed, with spaces, dashes or brackets.
	 * @return string '' when it is not a phone number.
	 */
	private static function phone_number( string $raw ): string {
		$plus   = 0 === strpos( ltrim( $raw ), '+' );
		$digits = (string) preg_replace( '/\D+/', '', $raw );
		$length = strlen( $digits );
		return $length >= 3 && $length <= 15 ? ( $plus ? '+' : '' ) . $digits : '';
	}

	/**
	 * A contact card (vCard 3.0), carried whole in the code: nothing is
	 * hosted, so scanning works offline and nothing about the person is stored.
	 *
	 * @param callable(string): string $get Field reader.
	 * @return string|WP_Error
	 */
	private static function vcard( callable $get ) {
		$first = $get( 'first_name' );
		$last  = $get( 'last_name' );
		$org   = $get( 'organization' );
		if ( '' === $first && '' === $last && '' === $org ) {
			return self::missing( __( 'Enter a name or an organisation.', 'ffcertificate' ) );
		}

		$email = $get( 'email' );
		if ( '' !== $email ) {
			$email = \FreeFormCertificate\Core\DataSanitizer::normalize_email( sanitize_email( $email ) );
			if ( '' === $email || ! is_email( $email ) ) {
				return self::missing( __( 'Enter a valid e-mail address.', 'ffcertificate' ) );
			}
		}

		$website = '' === $get( 'website' ) ? '' : self::url( $get( 'website' ) );
		if ( is_wp_error( $website ) ) {
			return $website;
		}

		$full  = trim( $first . ' ' . $last );
		$lines = array(
			'BEGIN:VCARD',
			'VERSION:3.0',
			'N:' . self::text_escape( $last ) . ';' . self::text_escape( $first ) . ';;;',
			'FN:' . self::text_escape( '' !== $full ? $full : $org ),
		);

		$optional = array(
			'ORG'                 => $org,
			'TITLE'               => $get( 'job_title' ),
			'TEL;TYPE=WORK,VOICE' => self::phone_number( $get( 'phone' ) ),
			'TEL;TYPE=CELL'       => self::phone_number( $get( 'mobile' ) ),
			'EMAIL;TYPE=INTERNET' => $email,
			'URL'                 => $website,
			'NOTE'                => $get( 'note' ),
		);
		foreach ( $optional as $property => $value ) {
			if ( '' !== $value ) {
				$lines[] = $property . ':' . ( 'URL' === $property ? $value : self::text_escape( $value ) );
			}
		}

		$address = array( $get( 'street' ), $get( 'city' ), $get( 'region' ), $get( 'postcode' ), $get( 'country' ) );
		if ( '' !== implode( '', $address ) ) {
			$lines[] = 'ADR;TYPE=WORK:;;' . implode( ';', array_map( array( self::class, 'text_escape' ), $address ) );
		}

		$lines[] = 'END:VCARD';
		return implode( "\r\n", $lines );
	}

	/**
	 * A social profile: the network's prefix and the user name.
	 *
	 * @param string $network  Key of self::NETWORKS.
	 * @param string $username As typed, with or without `@` or a pasted URL.
	 * @return string|WP_Error
	 */
	private static function social( string $network, string $username ) {
		if ( ! isset( self::NETWORKS[ $network ] ) ) {
			return self::missing( __( 'Choose a social network.', 'ffcertificate' ) );
		}

		// Accept a pasted profile URL: keep only its last path segment.
		$username = (string) preg_replace( '#^https?://[^/]+/(?:in/)?@?#i', '', $username );
		$username = ltrim( trim( $username, " /\t\n\r" ), '@' );
		if ( '' === $username || ! preg_match( '/^[A-Za-z0-9._-]{1,100}$/', $username ) ) {
			return self::missing( __( 'Enter the user name (letters, digits, dots, dashes and underscores).', 'ffcertificate' ) );
		}

		return self::NETWORKS[ $network ][1] . $username;
	}

	/**
	 * An event on one day, delivered three ways: the event itself in the
	 * code (`vevent`, which phone cameras offer to add), a Google Calendar
	 * link, or a signed link to an `.ics` file.
	 *
	 * Times are the site's wall clock (Category B): the VEVENT and the `.ics`
	 * carry floating local times, and the Google link names the site time zone.
	 *
	 * @param callable(string): string $get Field reader.
	 * @return string|WP_Error
	 */
	private static function event( callable $get ) {
		$title = $get( 'title' );
		$date  = $get( 'date' );
		$start = $get( 'start' );
		$end   = $get( 'end' );
		$mode  = in_array( $get( 'mode' ), self::EVENT_MODES, true ) ? $get( 'mode' ) : 'vevent';

		if ( '' === $title ) {
			return self::missing( __( 'Enter the event title.', 'ffcertificate' ) );
		}
		$day = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date );
		if ( false === $day || $day->format( 'Y-m-d' ) !== $date ) {
			return self::missing( __( 'Enter a valid date.', 'ffcertificate' ) );
		}
		if ( ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $start ) || ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $end ) ) {
			return self::missing( __( 'Enter the start and end times.', 'ffcertificate' ) );
		}
		if ( strcmp( $end, $start ) <= 0 ) {
			return self::missing( __( 'The event must end after it starts.', 'ffcertificate' ) );
		}

		$until = $get( 'until' );
		if ( 'ics' === $mode && '' !== $until ) {
			$last = \DateTimeImmutable::createFromFormat( '!Y-m-d', $until );
			if ( false === $last || $last->format( 'Y-m-d' ) !== $until ) {
				return self::missing( __( 'Enter a valid date for the link expiry.', 'ffcertificate' ) );
			}
			if ( strcmp( $until, $date ) < 0 ) {
				return self::missing( __( 'The link cannot expire before the event.', 'ffcertificate' ) );
			}
		}

		$stamp_start = str_replace( '-', '', $date ) . 'T' . str_replace( ':', '', $start ) . '00';
		$stamp_end   = str_replace( '-', '', $date ) . 'T' . str_replace( ':', '', $end ) . '00';
		$location    = $get( 'location' );
		$description = $get( 'description' );

		if ( 'google' === $mode ) {
			return add_query_arg(
				array_map(
					'rawurlencode',
					array_filter(
						array(
							'action'   => 'TEMPLATE',
							'text'     => $title,
							'dates'    => $stamp_start . '/' . $stamp_end,
							'ctz'      => wp_timezone_string(),
							'location' => $location,
							'details'  => $description,
						),
						static fn( string $v ): bool => '' !== $v
					)
				),
				'https://calendar.google.com/calendar/render'
			);
		}

		if ( 'ics' === $mode ) {
			return QrEventLink::url(
				array(
					'title'       => $title,
					'location'    => $location,
					'description' => $description,
					'date'        => $date,
					'start'       => $start,
					'end'         => $end,
					'until'       => $until,
				)
			);
		}

		$lines = array( 'BEGIN:VEVENT', 'SUMMARY:' . self::text_escape( $title ), 'DTSTART:' . $stamp_start, 'DTEND:' . $stamp_end );
		if ( '' !== $location ) {
			$lines[] = 'LOCATION:' . self::text_escape( $location );
		}
		if ( '' !== $description ) {
			$lines[] = 'DESCRIPTION:' . self::text_escape( $description );
		}
		$lines[] = 'END:VEVENT';
		return implode( "\r\n", $lines );
	}

	/**
	 * Escape a vCard / iCalendar text value (RFC 6350 §3.4, RFC 5545 §3.3.11).
	 *
	 * @param string $value Text.
	 * @return string
	 */
	private static function text_escape( string $value ): string {
		return str_replace(
			array( '\\', ';', ',', "\r\n", "\n", "\r" ),
			array( '\\\\', '\\;', '\\,', '\\n', '\\n', '\\n' ),
			$value
		);
	}

	/**
	 * A validation error for a missing or malformed field.
	 *
	 * @param string $message Message.
	 * @return WP_Error
	 */
	private static function missing( string $message ): WP_Error {
		return new WP_Error( 'ffc_qr_field', $message );
	}
}
