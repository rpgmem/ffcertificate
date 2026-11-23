<?php
/**
 * Audience Terminal Pairing
 *
 * Pairing codes, the terminals they create and the tokens terminals use
 * (#2744).
 *
 * @package FreeFormCertificate\Audience
 * @since 6.50.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

use FreeFormCertificate\Core\ActivityLog;
use FreeFormCertificate\Core\AuthCodeService;
use FreeFormCertificate\Core\Encryption;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pair, authenticate and end terminals.
 *
 * An operator generates a code for the bookings a terminal will serve. The
 * code is `P-XXXX-XXXX-XXXX`: the verification-code scheme with a virtual
 * prefix the verification page refuses before any lookup (#2739). It lives
 * five minutes, pairs once, and the site keeps only its keyed hash.
 *
 * The terminal sends the code with an id it generated for itself, and gets
 * back a token: random, returned once, stored only as its SHA-256. The
 * token is limited to those bookings, ends a set time after the last of them
 * ends (two hours unless the Check-in settings say otherwise), can be ended by hand, and is attributed to the operator who
 * generated the code.
 *
 * @phpstan-import-type TerminalRow from AudienceTerminalReader
 */
final class AudienceTerminalPairing {

	/**
	 * What pairing, listing and ending terminals need.
	 */
	public const CAP = 'ffc_manage_audiences_terminals';

	/**
	 * How long a code lives, in seconds.
	 */
	public const CODE_TTL = 300;

	/**
	 * How far ahead a booking may end, in days.
	 */
	public const DAYS_AHEAD = 7;

	/**
	 * Bookings one terminal may hold.
	 */
	public const MAX_BOOKINGS = 10;

	/**
	 * The virtual prefix of a pairing code.
	 */
	public const PREFIX = 'P';

	/**
	 * The limits in force: Scheduling → Settings → Check-in (#2748), then the
	 * filter.
	 *
	 * @return array{site: int, booking: int}
	 */
	public static function limits(): array {
		$site  = AudienceCheckinSettings::site_limit();
		$event = AudienceCheckinSettings::event_limit();
		/**
		 * Filters how many terminals may be active at once (#2744). The
		 * values arrive from the Check-in settings (#2748).
		 *
		 * @since 6.50.0
		 * @param array<string, mixed> $limits Per site (`site`) and per booking (`booking`).
		 */
		$limits = apply_filters(
			'ffc_terminal_limits',
			array(
				'site'    => $site,
				'booking' => $event,
			)
		);
		return array(
			'site'    => max( 1, isset( $limits['site'] ) && is_numeric( $limits['site'] ) ? (int) $limits['site'] : $site ),
			'booking' => max( 1, isset( $limits['booking'] ) && is_numeric( $limits['booking'] ) ? (int) $limits['booking'] : $event ),
		);
	}

	/**
	 * Generate a pairing code.
	 *
	 * @param int    $user_id  The operator.
	 * @param int[]  $bookings Booking ids.
	 * @param string $label    What to call the terminal.
	 * @param int    $now      Unix time.
	 * @phpstan-param list<int> $bookings
	 * @return array{code: string, expires_at: int, bookings: list<int>}|\WP_Error
	 */
	public static function create_code( int $user_id, array $bookings, string $label, int $now ) {
		$bookings = self::clean_bookings( $bookings );
		$invalid  = self::queue_error( $bookings, $now );
		if ( null !== $invalid ) {
			return $invalid;
		}
		$limit = self::limit_error( $bookings, $now );
		if ( null !== $limit ) {
			return $limit;
		}

		$label = self::clean_label( $label );
		for ( $attempt = 0; $attempt < 5; $attempt++ ) {
			$code = self::PREFIX . '-' . AuthCodeService::generate_auth_code();
			$id   = AudienceTerminalWriter::create_pairing( self::code_hash( $code ), $bookings, $label, $user_id, $now, $now + self::CODE_TTL );
			if ( $id > 0 ) {
				ActivityLog::log(
					'terminal_pairing_created',
					ActivityLog::LEVEL_INFO,
					array(
						'pairing_id' => $id,
						'bookings'   => $bookings,
					),
					$user_id
				);
				return array(
					'code'       => $code,
					'expires_at' => $now + self::CODE_TTL,
					'bookings'   => $bookings,
				);
			}
		}
		return new \WP_Error( 'ffc_terminal_code', __( 'The pairing code could not be saved. Try again.', 'ffcertificate' ), array( 'status' => 500 ) );
	}

	/**
	 * Pair a terminal with a code.
	 *
	 * @param string $code      What the terminal read or the operator typed.
	 * @param string $device_id The id the terminal generated for itself.
	 * @param string $address   Keyed fingerprint of the caller's address.
	 * @param int    $now       Unix time.
	 * @return array{token: string, terminal_id: int, label: string, bookings: list<int>, expires_at: int}|\WP_Error
	 */
	public static function pair( string $code, string $device_id, string $address, int $now ) {
		$device_id = self::clean_device( $device_id );
		if ( '' === $device_id ) {
			return new \WP_Error( 'ffc_terminal_device', __( 'This terminal sent no device id. Reload the page and try again.', 'ffcertificate' ), array( 'status' => 400 ) );
		}
		$device_hash = Encryption::keyed_hash( $device_id, 'terminal_device' );

		$wait = AudienceTerminalLockout::wait( $device_hash, $address, $now );
		if ( $wait > 0 ) {
			return self::locked( $wait );
		}

		$pairing = AudienceTerminalReader::pairing_by_hash( self::code_hash( $code ) );
		$usable  = null !== $pairing && null === $pairing['used_at'] && $pairing['expires_at'] >= $now;
		if ( ! $usable ) {
			return self::wrong_code( $device_hash, $address, $now );
		}
		// A full site or event refuses before the code is spent, so the
		// operator can end a terminal and the same code still works.
		$limit = self::limit_error( $pairing['bookings'], $now );
		if ( null !== $limit ) {
			return $limit;
		}
		if ( ! AudienceTerminalWriter::spend_pairing( $pairing['id'], $now ) ) {
			return self::wrong_code( $device_hash, $address, $now );
		}
		$expires = self::expiry( $pairing['bookings'], $now );
		$token   = bin2hex( random_bytes( 32 ) );
		$id      = AudienceTerminalWriter::create_terminal( self::token_hash( $token ), $device_hash, $pairing['label'], $pairing['bookings'], $pairing['created_by'], $now, $expires );
		if ( $id <= 0 ) {
			return new \WP_Error( 'ffc_terminal_save', __( 'The terminal could not be saved. Generate a new code and try again.', 'ffcertificate' ), array( 'status' => 500 ) );
		}
		AudienceTerminalWriter::link_pairing( $pairing['id'], $id );
		AudienceTerminalLockout::succeed( $device_hash );

		ActivityLog::log(
			'terminal_paired',
			ActivityLog::LEVEL_INFO,
			array(
				'terminal_id' => $id,
				'bookings'    => $pairing['bookings'],
				'expires_at'  => $expires,
			),
			(int) $pairing['created_by']
		);

		return array(
			'token'       => $token,
			'terminal_id' => $id,
			'label'       => $pairing['label'],
			'bookings'    => $pairing['bookings'],
			'expires_at'  => $expires,
		);
	}

	/**
	 * Change the events of an active terminal, from the app or the admin
	 * (#2746). The terminal picks the change up the next time it asks for
	 * its schedule.
	 *
	 * The new queue is held to the rules a pairing is: at least one event,
	 * at most {@see self::MAX_BOOKINGS}, each active and ending within
	 * {@see self::DAYS_AHEAD} days, no two whose check-in windows cross, and
	 * an added event not already held by as many terminals as it may be.
	 * An event that leaves keeps every reading taken for it: the moment it
	 * left is kept, so a reading the terminal took offline before then is
	 * still recorded when it syncs.
	 *
	 * @param int   $id       Terminal id.
	 * @param int[] $bookings The events it should hold.
	 * @param int   $by       Who changes it.
	 * @param bool  $any      Whether they may change a terminal someone else paired.
	 * @param int   $now      Unix time.
	 * @phpstan-param list<int> $bookings
	 * @return TerminalRow|\WP_Error The terminal as it is now.
	 */
	public static function update_bookings( int $id, array $bookings, int $by, bool $any, int $now ) {
		$terminal = AudienceTerminalReader::terminal( $id );
		if ( null === $terminal || ( ! $any && $terminal['paired_by'] !== $by ) || null !== $terminal['revoked_at'] || $terminal['expires_at'] <= $now ) {
			return new \WP_Error( 'ffc_terminal_missing', __( 'There is no such terminal.', 'ffcertificate' ), array( 'status' => 404 ) );
		}
		$bookings = self::clean_bookings( $bookings );
		$invalid  = self::queue_error( $bookings, $now );
		if ( null !== $invalid ) {
			return $invalid;
		}
		$added   = array_values( array_diff( $bookings, $terminal['bookings'] ) );
		$dropped = array_values( array_diff( $terminal['bookings'], $bookings ) );
		if ( array() === $added && array() === $dropped ) {
			return $terminal;
		}
		$limit = self::booking_limit_error( $added, $now );
		if ( null !== $limit ) {
			return $limit;
		}

		$removed = $terminal['removed'];
		foreach ( $dropped as $booking_id ) {
			$removed[ $booking_id ] = $now;
		}
		foreach ( $added as $booking_id ) {
			unset( $removed[ $booking_id ] );
		}
		$expires = self::expiry( $bookings, $now );
		if ( ! AudienceTerminalWriter::set_bookings( $id, $bookings, $removed, $expires ) ) {
			return new \WP_Error( 'ffc_terminal_save', __( 'The terminal could not be saved. Try again.', 'ffcertificate' ), array( 'status' => 500 ) );
		}
		ActivityLog::log(
			'terminal_events_changed',
			ActivityLog::LEVEL_INFO,
			array(
				'terminal_id' => $id,
				'added'       => $added,
				'removed'     => $dropped,
			),
			$by
		);

		$terminal['bookings']   = $bookings;
		$terminal['removed']    = $removed;
		$terminal['expires_at'] = $expires;
		return $terminal;
	}

	/**
	 * Whether a terminal held an event at a moment: it holds it now, or it
	 * left the queue after that moment (#2746).
	 *
	 * @param array $terminal   Terminal row.
	 * @param int   $booking_id Booking.
	 * @param int   $at         Unix time.
	 * @phpstan-param TerminalRow $terminal
	 * @return bool
	 */
	public static function held_at( array $terminal, int $booking_id, int $at ): bool {
		return in_array( $booking_id, $terminal['bookings'], true )
			|| ( isset( $terminal['removed'][ $booking_id ] ) && $at < $terminal['removed'][ $booking_id ] );
	}

	/**
	 * The terminal a token belongs to, while it may still record attendance.
	 *
	 * Notes that it was heard from at most once a minute, or at once when the
	 * count of readings it holds unsent changed, so the Terminals screen
	 * (#2748) can say which terminal still has readings to send.
	 *
	 * @param string   $token  The token the terminal holds.
	 * @param int      $now    Unix time.
	 * @param int|null $queued Readings the terminal says it holds unsent, or null when it did not say.
	 * @return TerminalRow|null
	 */
	public static function authenticate( string $token, int $now, ?int $queued = null ): ?array {
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
			return null;
		}
		$terminal = AudienceTerminalReader::terminal_by_token_hash( self::token_hash( $token ) );
		if ( null === $terminal || null !== $terminal['revoked_at'] || $terminal['expires_at'] <= $now ) {
			return null;
		}
		$queued  = null === $queued ? $terminal['queued'] : max( 0, $queued );
		$changed = $queued !== $terminal['queued'];
		if ( $changed || null === $terminal['last_seen_at'] || $now - $terminal['last_seen_at'] >= 60 ) {
			AudienceTerminalWriter::touch( $terminal['id'], $now, $queued );
			$terminal['last_seen_at'] = $now;
			$terminal['queued']       = $queued;
		}
		return $terminal;
	}

	/**
	 * End a terminal.
	 *
	 * @param int  $id    Terminal id.
	 * @param int  $by    Who ends it.
	 * @param bool $any   Whether they may end a terminal someone else paired.
	 * @param int  $now   Unix time.
	 * @return true|\WP_Error
	 */
	public static function revoke( int $id, int $by, bool $any, int $now ) {
		$terminal = AudienceTerminalReader::terminal( $id );
		if ( null === $terminal || ( ! $any && $terminal['paired_by'] !== $by ) ) {
			return new \WP_Error( 'ffc_terminal_missing', __( 'There is no such terminal.', 'ffcertificate' ), array( 'status' => 404 ) );
		}
		if ( ! AudienceTerminalWriter::revoke( $id, $by, $now ) ) {
			return new \WP_Error( 'ffc_terminal_ended', __( 'This terminal was already ended.', 'ffcertificate' ), array( 'status' => 409 ) );
		}
		ActivityLog::log(
			'terminal_revoked',
			ActivityLog::LEVEL_INFO,
			array(
				'terminal_id' => $id,
				'paired_by'   => $terminal['paired_by'],
			),
			$by
		);
		return true;
	}

	/**
	 * The keyed hash a code is stored under. Case, spaces, hyphens and the
	 * virtual prefix do not matter, so a code typed in any of those shapes
	 * finds its row.
	 *
	 * @param string $code The code.
	 * @return string
	 */
	public static function code_hash( string $code ): string {
		$clean = preg_replace( '/[^A-Z0-9]/', '', strtoupper( $code ) ) ?? '';
		if ( 13 === strlen( $clean ) && self::PREFIX === $clean[0] ) {
			$clean = substr( $clean, 1 );
		}
		return Encryption::keyed_hash( $clean, 'terminal_pairing' );
	}

	/**
	 * The hash a token is stored under. The token is 256 random bits, so a
	 * plain digest cannot be reversed by enumeration.
	 *
	 * @param string $token The token.
	 * @return string
	 */
	public static function token_hash( string $token ): string {
		return hash( 'sha256', $token );
	}

	/**
	 * When a terminal stops: the auto-end of the Check-in settings (two hours
	 * by default) after the last of its bookings ends.
	 *
	 * @param int[] $bookings Booking ids.
	 * @param int   $now      Unix time.
	 * @phpstan-param list<int> $bookings
	 * @return int
	 */
	public static function expiry( array $bookings, int $now ): int {
		$last = $now;
		foreach ( $bookings as $booking_id ) {
			$end = self::booking_end( $booking_id );
			if ( null !== $end && $end > $last ) {
				$last = $end;
			}
		}
		return $last + AudienceCheckinSettings::auto_end_seconds();
	}

	/**
	 * When an active booking ends, in the site's time zone.
	 *
	 * @param int $booking_id Booking.
	 * @return int|null Unix time, or null when there is no active booking.
	 */
	public static function booking_end( int $booking_id ): ?int {
		$booking = AudienceBookingReader::get_by_id( $booking_id );
		if ( null === $booking || 'active' !== ( $booking->status ?? '' ) ) {
			return null;
		}
		$day  = is_string( $booking->end_date ?? null ) && '' !== $booking->end_date ? $booking->end_date : (string) ( $booking->booking_date ?? '' );
		$time = (string) ( $booking->end_time ?? '23:59:59' );
		try {
			return ( new \DateTimeImmutable( $day . ' ' . $time, wp_timezone() ) )->getTimestamp();
		} catch ( \Exception $e ) {
			unset( $e );
			return null;
		}
	}

	/**
	 * Positive, unique booking ids.
	 *
	 * @param int[] $bookings Booking ids.
	 * @return list<int>
	 */
	private static function clean_bookings( array $bookings ): array {
		return array_values( array_unique( array_filter( array_map( 'intval', $bookings ), static fn( int $id ): bool => $id > 0 ) ) );
	}

	/**
	 * The refusal a queue of events gives, or null when it may be held.
	 *
	 * @param int[] $bookings Booking ids.
	 * @param int   $now      Unix time.
	 * @phpstan-param list<int> $bookings
	 * @return \WP_Error|null
	 */
	private static function queue_error( array $bookings, int $now ): ?\WP_Error {
		if ( array() === $bookings ) {
			return new \WP_Error( 'ffc_terminal_no_bookings', __( 'Choose at least one event for the terminal.', 'ffcertificate' ), array( 'status' => 400 ) );
		}
		if ( count( $bookings ) > self::MAX_BOOKINGS ) {
			/* translators: %d: maximum number of events */
			return new \WP_Error( 'ffc_terminal_too_many', sprintf( __( 'A terminal holds at most %d events.', 'ffcertificate' ), self::MAX_BOOKINGS ), array( 'status' => 400 ) );
		}
		foreach ( $bookings as $booking_id ) {
			$end = self::booking_end( $booking_id );
			if ( null === $end || $end < $now || $end > $now + self::DAYS_AHEAD * DAY_IN_SECONDS + DAY_IN_SECONDS ) {
				/* translators: %d: number of days */
				return new \WP_Error( 'ffc_terminal_booking', sprintf( __( 'Every event must be active and end within the next %d days.', 'ffcertificate' ), self::DAYS_AHEAD ), array( 'status' => 400 ) );
			}
		}
		return AudienceTerminalSchedule::collision_error( $bookings, $now );
	}

	/**
	 * The refusal a limit gives, or null when there is room.
	 *
	 * @param int[] $bookings Booking ids.
	 * @param int   $now      Unix time.
	 * @phpstan-param list<int> $bookings
	 * @return \WP_Error|null
	 */
	private static function limit_error( array $bookings, int $now ): ?\WP_Error {
		$limits = self::limits();
		if ( count( AudienceTerminalReader::active_terminals( $now ) ) >= $limits['site'] ) {
			/* translators: %d: maximum number of terminals */
			return new \WP_Error( 'ffc_terminal_site_limit', sprintf( __( 'This site already has %d active terminals. End one before pairing another.', 'ffcertificate' ), $limits['site'] ), array( 'status' => 409 ) );
		}
		return self::booking_limit_error( $bookings, $now );
	}

	/**
	 * The refusal the per-event limit gives, or null when every event has room.
	 *
	 * @param int[] $bookings Booking ids.
	 * @param int   $now      Unix time.
	 * @phpstan-param list<int> $bookings
	 * @return \WP_Error|null
	 */
	private static function booking_limit_error( array $bookings, int $now ): ?\WP_Error {
		$limit = self::limits()['booking'];
		foreach ( $bookings as $booking_id ) {
			if ( AudienceTerminalReader::count_active_for_booking( $booking_id, $now ) >= $limit ) {
				/* translators: %d: maximum number of terminals */
				return new \WP_Error( 'ffc_terminal_booking_limit', sprintf( __( 'One of these events already has %d active terminals. End one before pairing another.', 'ffcertificate' ), $limit ), array( 'status' => 409 ) );
			}
		}
		return null;
	}

	/**
	 * Count a wrong code and answer it.
	 *
	 * @param string $device_hash Keyed hash of the device id.
	 * @param string $address     Keyed fingerprint of the address.
	 * @param int    $now         Unix time.
	 * @return \WP_Error
	 */
	private static function wrong_code( string $device_hash, string $address, int $now ): \WP_Error {
		$result = AudienceTerminalLockout::fail( $device_hash, $address, $now );
		ActivityLog::log(
			$result['wait'] > 0 ? 'terminal_pairing_locked' : 'terminal_pairing_refused',
			ActivityLog::LEVEL_WARNING,
			array(
				'device' => substr( $device_hash, 0, 16 ),
				'fails'  => $result['fails'],
				'wait'   => $result['wait'],
			)
		);
		if ( $result['wait'] > 0 ) {
			return self::locked( $result['wait'] );
		}
		return new \WP_Error( 'ffc_terminal_code_wrong', __( 'This pairing code is wrong, used or expired. Generate a new one and try again.', 'ffcertificate' ), array( 'status' => 403 ) );
	}

	/**
	 * The refusal a lock gives. It names the wait, never the code typed.
	 *
	 * @param int $wait Seconds left.
	 * @return \WP_Error
	 */
	private static function locked( int $wait ): \WP_Error {
		return new \WP_Error(
			'ffc_terminal_locked',
			/* translators: %d: minutes */
			sprintf( _n( 'Too many wrong codes. Try again in %d minute.', 'Too many wrong codes. Try again in %d minutes.', (int) ceil( $wait / 60 ), 'ffcertificate' ), (int) ceil( $wait / 60 ) ),
			array(
				'status'      => 429,
				'retry_after' => $wait,
			)
		);
	}

	/**
	 * A device id as the terminal may send it.
	 *
	 * @param string $device_id Raw id.
	 * @return string The id, or '' when it is not one.
	 */
	private static function clean_device( string $device_id ): string {
		return 1 === preg_match( '/^[A-Za-z0-9_-]{16,64}$/', $device_id ) ? $device_id : '';
	}

	/**
	 * A terminal label: plain text, at most 100 characters.
	 *
	 * @param string $label Raw label.
	 * @return string
	 */
	private static function clean_label( string $label ): string {
		return mb_substr( trim( sanitize_text_field( $label ) ), 0, 100 );
	}
}
