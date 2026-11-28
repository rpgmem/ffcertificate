<?php
/**
 * CertificateFormTarget
 *
 * A certificate form as something a check-in terminal reads (#2891): the
 * participant shows the app's presence QR, and the certificate is issued
 * on the spot from their profile.
 *
 * @package FreeFormCertificate\Frontend
 * @since 6.52.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Frontend;

use FreeFormCertificate\Core\ActivityLog;
use FreeFormCertificate\Core\Attendance;
use FreeFormCertificate\Core\DataSanitizer;
use FreeFormCertificate\Core\DateFormatter;
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Core\SubmitterName;
use FreeFormCertificate\Core\TerminalTargetInterface;
use FreeFormCertificate\Core\TerminalVerdict;
use FreeFormCertificate\Frontend\Submission\SubmissionContext;
use FreeFormCertificate\Frontend\Submission\SubmissionIdentityLock;
use FreeFormCertificate\Frontend\Submission\SubmissionRejected;
use FreeFormCertificate\Repositories\SubmissionRepository;
use FreeFormCertificate\Security\Geofence;
use FreeFormCertificate\Security\RateLimiter;
use FreeFormCertificate\Submissions\SubmissionHandler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads a certificate form at a terminal.
 *
 * The terminal reads while the form is open: its window is the form's own
 * opening and closing (the date and time box), on one day only. What the
 * form already checks on the site applies, except what the terminal proves
 * by standing at the entrance:
 *
 * - the deny list, the allow list, the ticket and the per-person limits
 *   apply; the terminal asks for a ticket code before the QR (#2893);
 * - the password and the geofence do not;
 * - nor do the per-IP limit, the captcha, the honeypot and the device
 *   signals, which guard anonymous submissions: here the caller is a
 *   paired terminal and the person is named by a signed presence code.
 *
 * Name, e-mail and CPF/RF come from the profile. A required field the
 * profile cannot fill leaves the reading waiting on data: attendance is
 * recorded and the person finishes on their phone. Reading the same person
 * twice issues nothing new, and a person who already submitted on the site
 * gets that certificate.
 *
 * @phpstan-import-type Verdict from \FreeFormCertificate\Core\TerminalVerdict
 * @phpstan-import-type TerminalWindow from \FreeFormCertificate\Core\TerminalTargetInterface
 * @phpstan-import-type TerminalEntry from \FreeFormCertificate\Core\TerminalTargetInterface
 * @phpstan-import-type TerminalReading from \FreeFormCertificate\Core\TerminalTargetInterface
 */
final class CertificateFormTarget implements TerminalTargetInterface {

	/**
	 * Sends the "complete your certificate" e-mail.
	 *
	 * @var callable
	 */
	private $notify;

	/**
	 * The labels of the missing fields, as the person reads them.
	 *
	 * @param list<array<string, mixed>> $fields  Form fields.
	 * @param array                      $missing Missing field names.
	 * @phpstan-param list<string> $missing
	 * @return list<string>
	 */
	public static function missing_labels( array $fields, array $missing ): array {
		$labels = array();
		foreach ( $missing as $name ) {
			$label = 'cpf_rf' === $name ? __( 'CPF/RF', 'ffcertificate' ) : $name;
			foreach ( $fields as $field ) {
				if ( ( $field['name'] ?? '' ) === $name && isset( $field['label'] ) && is_string( $field['label'] ) && '' !== trim( $field['label'] ) ) {
					$label = trim( $field['label'] );
					break;
				}
			}
			$labels[] = $label;
		}
		return $labels;
	}

	/**
	 * Target type id.
	 */
	public const TYPE = 'certificate_form';

	/**
	 * The key of `_ffc_form_config` that holds the terminal settings:
	 * `enabled` ('1' or '') and `who` (`any` or `allowlist`).
	 */
	public const CONFIG_KEY = 'terminal';

	/**
	 * Anyone with the app enrolled may receive at the terminal.
	 */
	public const WHO_ANY = 'any';

	/**
	 * Only the people on the form's allow list may.
	 */
	public const WHO_ALLOWLIST = 'allowlist';

	/** Data key: when the reading accepted the form's consent (Unix UTC). */
	public const CONSENT_AT_KEY = '_terminal_consent_at';

	/** Data key: the terminal whose reading accepted the consent. */
	public const CONSENT_TERMINAL_KEY = '_terminal_consent_terminal';

	/**
	 * Field types that hold no answer.
	 */
	private const DISPLAY_TYPES = array( 'info', 'embed', 'hidden' );

	/**
	 * Saves the submission: `( form_id, title, data, email, fields, config, options ) => int|\WP_Error`.
	 *
	 * @var callable
	 */
	private $save;

	/**
	 * Links a submission to an account: `( submission_id, user_id ) => bool`.
	 *
	 * @var callable
	 */
	private $link;

	/**
	 * The form's restrictions: {@see AccessRestrictionChecker::check()}.
	 *
	 * @var callable
	 */
	private $restrict;

	/**
	 * The per-person limits: `( person, form_id ) => string|null`.
	 *
	 * @var callable
	 */
	private $limit;

	/**
	 * The certificate a CPF/RF already has: {@see ReprintDetector::detect()}.
	 *
	 * @var callable
	 */
	private $existing;

	/**
	 * Build the target. Every collaborator defaults to the one the site's own
	 * form uses; a test passes its own.
	 *
	 * @param callable|null $save     Saves the submission.
	 * @param callable|null $link     Links it to the account.
	 * @param callable|null $restrict Checks the form's restrictions.
	 * @param callable|null $limit    Checks the per-person limits.
	 * @param callable|null $existing Finds an existing certificate.
	 * @param callable|null $notify   Sends the "complete your certificate" e-mail.
	 */
	public function __construct( ?callable $save = null, ?callable $link = null, ?callable $restrict = null, ?callable $limit = null, ?callable $existing = null, ?callable $notify = null ) {
		$handler        = null;
		$handler_of     = static function () use ( &$handler ): SubmissionHandler {
			$handler = $handler ?? new SubmissionHandler();
			return $handler;
		};
		$this->save     = $save ?? static fn( ...$args ) => $handler_of()->process_submission( ...$args );
		$this->link     = $link ?? static fn( int $id, int $user ): bool => $handler_of()->update_user_link( $id, $user );
		$this->restrict = $restrict ?? array( AccessRestrictionChecker::class, 'check' );
		$this->limit    = $limit ?? array( self::class, 'person_limit' );
		$this->existing = $existing ?? array( ReprintDetector::class, 'detect' );
		$this->notify   = $notify ?? array( CertificateCompletionLink::class, 'send' );
	}

	/**
	 * Target type id.
	 *
	 * @return string
	 */
	public function type(): string {
		return self::TYPE;
	}

	/**
	 * A form's attendance is the certificate it issues, which only a terminal
	 * reading writes ({@see self::terminal_read()}): there is no plain
	 * attendance record to mark by hand.
	 *
	 * @param int      $target_id  Form id.
	 * @param int      $user_id    The person.
	 * @param int      $checked_at Unix time.
	 * @param string   $method     Method.
	 * @param int|null $marked_by  Operator.
	 * @return string
	 */
	public function record( int $target_id, int $user_id, int $checked_at, string $method, ?int $marked_by ): string {
		unset( $target_id, $user_id, $checked_at, $method, $marked_by );
		return Attendance::FAILED;
	}

	/**
	 * The terminal settings of a form.
	 *
	 * @param int $form_id Form id.
	 * @return array{enabled: bool, who: string}
	 */
	public static function settings( int $form_id ): array {
		$config   = get_post_meta( $form_id, '_ffc_form_config', true );
		$terminal = is_array( $config ) && isset( $config[ self::CONFIG_KEY ] ) && is_array( $config[ self::CONFIG_KEY ] ) ? $config[ self::CONFIG_KEY ] : array();
		$who      = isset( $terminal['who'] ) && self::WHO_ALLOWLIST === $terminal['who'] ? self::WHO_ALLOWLIST : self::WHO_ANY;
		return array(
			// On by default (#2900): a form that never set the switch takes
			// the terminal whenever it can; only an explicit off keeps it out.
			'enabled' => ! isset( $terminal['enabled'] ) || '1' === (string) $terminal['enabled'],
			'who'     => $who,
		);
	}

	/**
	 * The one window a form takes readings in: its opening and closing, on
	 * the same day.
	 *
	 * @param int $form_id Form id.
	 * @return TerminalWindow|null Null when the form has no dated window or it spans days.
	 */
	public static function window( int $form_id ): ?array {
		$config = Geofence::get_form_config( $form_id );
		if ( ! is_array( $config ) || empty( $config['datetime_enabled'] ) ) {
			return null;
		}
		$day = is_string( $config['date_start'] ?? null ) ? $config['date_start'] : '';
		$end = is_string( $config['date_end'] ?? null ) && '' !== $config['date_end'] ? $config['date_end'] : $day;
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) || $end !== $day ) {
			return null;
		}
		$start = self::clock( $config['time_start'] ?? '', '00:00' );
		$close = self::clock( $config['time_end'] ?? '', '23:59' );
		try {
			$zone   = wp_timezone();
			$opens  = ( new \DateTimeImmutable( $day . ' ' . $start, $zone ) )->getTimestamp();
			$closes = ( new \DateTimeImmutable( $day . ' ' . $close, $zone ) )->getTimestamp() + ( '23:59' === $close ? MINUTE_IN_SECONDS : 0 );
		} catch ( \Exception $e ) {
			unset( $e );
			return null;
		}
		return $closes > $opens ? array(
			'date'   => $day,
			'opens'  => $opens,
			'closes' => $closes,
		) : null;
	}

	/**
	 * Why a form cannot take the terminal, or null when it can.
	 *
	 * @param int $form_id Form id.
	 * @return \WP_Error|null
	 */
	public static function unavailable( int $form_id ): ?\WP_Error {
		$post = get_post( $form_id );
		if ( ! $post instanceof \WP_Post || 'ffc_form' !== $post->post_type || 'publish' !== $post->post_status || ! self::settings( $form_id )['enabled'] ) {
			return new \WP_Error( 'ffc_terminal_form_off', __( 'This certificate form does not take the terminal. Turn it on in the form\'s Terminal tab.', 'ffcertificate' ), array( 'status' => 400 ) );
		}
		return self::cannot_enable( $form_id );
	}

	/**
	 * The forms a terminal may take, for the pairing and queue screens:
	 * published, with the terminal on, and open today or on one of the next
	 * days, never one that already closed (#2900). Soonest first.
	 *
	 * @param int $now  Unix time.
	 * @param int $days How many days ahead.
	 * @return list<array{id: int, title: string, location_ids: list<string>, opens: int, closes: int}>
	 */
	public static function offered( int $now, int $days ): array {
		$ids  = get_posts(
			array(
				'post_type'      => 'ffc_form',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		$last = $now + ( $days + 1 ) * DAY_IN_SECONDS;
		$out  = array();
		foreach ( (array) $ids as $id ) {
			$id = (int) $id;
			if ( null !== self::unavailable( $id ) ) {
				continue;
			}
			$window = self::window( $id );
			if ( null === $window || $window['closes'] <= $now || $window['opens'] > $last ) {
				continue;
			}
			$out[] = array(
				'id'           => $id,
				'title'        => ( new self() )->terminal_title( $id ),
				'location_ids' => self::location_ids( $id ),
				'opens'        => $window['opens'],
				'closes'       => $window['closes'],
			);
		}
		usort( $out, static fn( array $a, array $b ): int => array( $a['opens'], $a['id'] ) <=> array( $b['opens'], $b['id'] ) );
		return $out;
	}

	/**
	 * Whether a person's app lists a form (#2893): the deny list and the
	 * allow list, as the terminal applies them, judged on the person's
	 * CPF/RF. The ticket and the password are left out, since the person
	 * brings the first and the terminal stands for the second. A form that
	 * checks no list is listed for anyone signed in, like an open event.
	 *
	 * @param int    $form_id Form id.
	 * @param string $cpf_rf  The person's CPF/RF, digits only, or ''.
	 * @return bool
	 */
	public static function lists_person( int $form_id, string $cpf_rf ): bool {
		$config = get_post_meta( $form_id, '_ffc_form_config', true );
		$config = is_array( $config ) ? $config : array();
		$checks = isset( $config['restrictions'] ) && is_array( $config['restrictions'] ) ? $config['restrictions'] : array();
		unset( $checks['password'], $checks['ticket'] );
		if ( self::WHO_ALLOWLIST === self::settings( $form_id )['who'] ) {
			$checks['allowlist'] = '1';
		}
		$lists = ( isset( $checks['allowlist'] ) && '1' === (string) $checks['allowlist'] ) || ( isset( $checks['denylist'] ) && '1' === (string) $checks['denylist'] );
		if ( ! $lists ) {
			return true;
		}
		if ( '' === $cpf_rf ) {
			return false;
		}
		$config['restrictions'] = $checks;
		$verdict                = AccessRestrictionChecker::check( $config, $cpf_rf, '', $form_id, '' );
		return ! empty( $verdict['allowed'] );
	}

	/**
	 * The text of a form's consent field, which presenting the QR accepts
	 * (#2901), or '' when the form has none. The app shows it before the
	 * code (#2893).
	 *
	 * @param int $form_id Form id.
	 * @return string
	 */
	public static function consent_text( int $form_id ): string {
		$fields = get_post_meta( $form_id, '_ffc_form_fields', true );
		foreach ( is_array( $fields ) ? $fields : array() as $field ) {
			if ( is_array( $field ) && CertificateProfileSource::CONSENT === CertificateProfileSource::of( $field ) ) {
				$label = isset( $field['label'] ) && is_string( $field['label'] ) ? $field['label'] : '';
				return trim( wp_strip_all_tags( $label ) );
			}
		}
		return '';
	}

	/**
	 * Whether a form asks for a ticket code at the terminal (#2893).
	 *
	 * @param int $form_id Form id.
	 * @return bool
	 */
	public static function asks_ticket( int $form_id ): bool {
		return self::takes_ticket( self::restrictions( $form_id ) );
	}

	/**
	 * The GPS locations a form's geolocation names, when its GPS area reads
	 * from Settings → Geolocation.
	 *
	 * @param int $form_id Form id.
	 * @return list<string>
	 */
	public static function location_ids( int $form_id ): array {
		$config = get_post_meta( $form_id, '_ffc_geofence_config', true );
		if ( ! is_array( $config ) || 'locations' !== ( $config['geo_area_source'] ?? '' ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'strval', (array) ( $config['geo_area_location_ids'] ?? array() ) ), static fn( string $id ): bool => '' !== $id ) );
	}

	/**
	 * Why the terminal cannot be turned on for a form, whatever its switch
	 * says, or null when it can: a quiz needs the answers, and the reading
	 * follows one dated window on one day.
	 *
	 * @param int $form_id Form id.
	 * @return \WP_Error|null
	 */
	public static function cannot_enable( int $form_id ): ?\WP_Error {
		$config = get_post_meta( $form_id, '_ffc_form_config', true );
		if ( is_array( $config ) && ! empty( $config['quiz_enabled'] ) ) {
			return new \WP_Error( 'ffc_terminal_form_quiz', __( 'A form with a quiz cannot issue at the terminal: the certificate needs the answers.', 'ffcertificate' ), array( 'status' => 400 ) );
		}
		if ( null === self::window( $form_id ) ) {
			return new \WP_Error( 'ffc_terminal_form_window', __( 'The terminal reads a certificate form while it is open, on one day: set the form\'s opening and closing on the same date.', 'ffcertificate' ), array( 'status' => 400 ) );
		}
		return null;
	}

	/**
	 * The required fields a terminal cannot fill: not filled from the
	 * profile automatically (name, e-mail, CPF/RF) and naming no profile
	 * source. A person reading the QR is left to complete them on the phone.
	 *
	 * @param list<array<string, mixed>> $fields Form fields.
	 * @return list<string> Their labels, or names where a field has no label.
	 */
	public static function unfilled_required( array $fields ): array {
		$out = array();
		foreach ( $fields as $field ) {
			$name = isset( $field['name'] ) && is_string( $field['name'] ) ? $field['name'] : '';
			$type = isset( $field['type'] ) && is_string( $field['type'] ) ? $field['type'] : 'text';
			if ( '' === $name || empty( $field['required'] ) || in_array( $type, self::DISPLAY_TYPES, true ) ) {
				continue;
			}
			if ( 'cpf_rf' === $name || 'email' === $type || in_array( $name, SubmitterName::CANDIDATE_KEYS, true ) || '' !== CertificateProfileSource::of( $field ) ) {
				continue;
			}
			$label = isset( $field['label'] ) && is_string( $field['label'] ) ? trim( $field['label'] ) : '';
			$out[] = '' === $label ? $name : $label;
		}
		return $out;
	}

	/**
	 * The form's one window, when it can take the terminal.
	 *
	 * @param int $target_id Form id.
	 * @return list<TerminalWindow>
	 */
	public function terminal_windows( int $target_id ): array {
		$window = null === self::unavailable( $target_id ) ? self::window( $target_id ) : null;
		return null === $window ? array() : array( $window );
	}

	/**
	 * When the form closes.
	 *
	 * @param int $target_id Form id.
	 * @return int|null
	 */
	public function terminal_ends_at( int $target_id ): ?int {
		$windows = $this->terminal_windows( $target_id );
		return array() === $windows ? null : $windows[0]['closes'];
	}

	/**
	 * Whether a person without an account may sign up from the terminal
	 * (#2968). Every target accepts until the switch that governs it lands
	 * with the sign-up page.
	 *
	 * @param int $target_id Id within the type.
	 * @return bool
	 */
	public function terminal_accepts_join( int $target_id ): bool {
		return $target_id > 0;
	}

	/**
	 * Why the form may not join a terminal's queue.
	 *
	 * @param int $target_id Form id.
	 * @return \WP_Error|null
	 */
	public function terminal_queue_error( int $target_id ): ?\WP_Error {
		return self::unavailable( $target_id );
	}

	/**
	 * The form's title.
	 *
	 * @param int $target_id Form id.
	 * @return string
	 */
	public function terminal_title( int $target_id ): string {
		$title = trim( (string) get_the_title( $target_id ) );
		/* translators: %d: form id */
		return '' !== $title ? $title : sprintf( __( 'Form #%d', 'ffcertificate' ), $target_id );
	}

	/**
	 * What the terminal shows while the form is open.
	 *
	 * @param int   $target_id Form id.
	 * @param array $window    The form's window.
	 * @param int   $now       Unix time.
	 * @phpstan-param TerminalWindow $window
	 * @return TerminalEntry
	 */
	public function terminal_entry( int $target_id, array $window, int $now ): array {
		return array(
			'title'    => $this->terminal_title( $target_id ),
			'place'    => '',
			'hours'    => DateFormatter::format_time( $window['opens'] ) . '–' . DateFormatter::format_time( $window['closes'] ),
			'open'     => $window['opens'] <= $now && $now < $window['closes'],
			'closes'   => $window['closes'],
			'present'  => 0,
			'enrolled' => 0,
			'ticket'   => self::takes_ticket( self::restrictions( $target_id ) ),
		);
	}

	/**
	 * The access restrictions a form stores.
	 *
	 * @param int $form_id Form id.
	 * @return array<array-key, mixed>
	 */
	private static function restrictions( int $form_id ): array {
		$form_config = get_post_meta( $form_id, '_ffc_form_config', true );
		return is_array( $form_config ) && isset( $form_config['restrictions'] ) && is_array( $form_config['restrictions'] ) ? $form_config['restrictions'] : array();
	}

	/**
	 * Whether a form asks for a ticket code.
	 *
	 * @param array<array-key, mixed> $restrictions The form's restrictions.
	 * @return bool
	 */
	private static function takes_ticket( array $restrictions ): bool {
		return isset( $restrictions['ticket'] ) && '1' === (string) $restrictions['ticket'];
	}

	/**
	 * What one reading means for the form.
	 *
	 * @param int   $target_id Form id.
	 * @param array $reading   The reading.
	 * @phpstan-param TerminalReading $reading
	 * @return Verdict
	 */
	public function terminal_read( int $target_id, array $reading ): array {
		$form_id = $target_id;
		$user_id = $reading['user_id'];
		$at      = $reading['at'];

		if ( null !== self::unavailable( $form_id ) ) {
			return TerminalVerdict::refused( 'ffc_terminal_form_off', __( 'This form does not issue certificates at the terminal. Speak to the organiser.', 'ffcertificate' ) );
		}
		$window = self::window( $form_id );
		if ( null === $window || $at < $window['opens'] ) {
			/* translators: %s: the time the form opens */
			return TerminalVerdict::refused( 'ffc_terminal_window_not_open', sprintf( __( 'This form opens at %s.', 'ffcertificate' ), null === $window ? '' : DateFormatter::format_time( $window['opens'] ) ) );
		}
		if ( $at >= $window['closes'] ) {
			/* translators: %s: the time the form closed */
			return $this->refuse( $form_id, $reading, 'ffc_terminal_window_closed', sprintf( __( 'This form closed at %s.', 'ffcertificate' ), DateFormatter::format_time( $window['closes'] ) ) );
		}

		$previous = CertificateTerminalReadingRepository::for_person( $form_id, $user_id );
		if ( null !== $previous && CertificateTerminalReadingRepository::ISSUED === $previous['result'] ) {
			return $this->already( $reading['name'] );
		}

		// A certificate the person already holds for this form, issued on
		// the site or by an earlier link, answers before anything is asked
		// of them: no ticket, and no "complete it on the phone" e-mail.
		$person = self::person( $user_id );
		if ( '' !== $person['cpf_rf'] ) {
			$existing = ( $this->existing )( $form_id, $person['cpf_rf'], '' );
			if ( $existing['is_reprint'] ) {
				CertificateTerminalReadingRepository::record( $form_id, $user_id, $reading['terminal_id'], $at, CertificateTerminalReadingRepository::ISSUED, '', array(), (int) $existing['id'], time() );
				return $this->already( $reading['name'] );
			}
		}

		$form_config  = get_post_meta( $form_id, '_ffc_form_config', true );
		$form_config  = is_array( $form_config ) ? $form_config : array();
		$restrictions = isset( $form_config['restrictions'] ) && is_array( $form_config['restrictions'] ) ? $form_config['restrictions'] : array();
		$has_ticket   = self::takes_ticket( $restrictions );
		if ( $has_ticket && $reading['queued'] ) {
			return $this->refuse( $form_id, $reading, 'ffc_terminal_ticket_offline', __( 'This form asks for a ticket, which the terminal cannot check offline. Show the QR again when the terminal is online.', 'ffcertificate' ) );
		}
		if ( $has_ticket && '' === $reading['ticket_code'] ) {
			// Asks for the code; the terminal sends the reading again with it.
			return TerminalVerdict::refused( 'ffc_terminal_ticket_required', __( 'Type the code of your ticket.', 'ffcertificate' ) );
		}

		$fields  = get_post_meta( $form_id, '_ffc_form_fields', true );
		$fields  = is_array( $fields ) ? array_values( array_filter( $fields, 'is_array' ) ) : array();
		$built   = self::submission_data( $fields, $person, CertificateProfileSource::values( $user_id, $fields ) );
		$missing = $built['missing'];
		if ( '' === $person['cpf_rf'] && self::has_identifier_field( $fields ) && ! in_array( 'cpf_rf', $missing, true ) ) {
			$missing[] = 'cpf_rf';
		}
		if ( array() !== $missing ) {
			CertificateTerminalReadingRepository::record( $form_id, $user_id, $reading['terminal_id'], $at, CertificateTerminalReadingRepository::AWAITING_DATA, '', $missing, null, time() );
			// The certificate's code is fixed now, at the event (#2938).
			CertificateTerminalReadingRepository::reserve_code( $form_id, $user_id );
			// The link goes out once per wait: a second reading of the same
			// person reopens the link for seven days without a second e-mail.
			if ( null === $previous || CertificateTerminalReadingRepository::AWAITING_DATA !== $previous['result'] ) {
				( $this->notify )( $form_id, $user_id, self::missing_labels( $fields, $missing ), $at );
				ActivityLog::log(
					'terminal_certificate_awaiting_data',
					ActivityLog::LEVEL_INFO,
					array(
						'form_id'     => $form_id,
						'terminal_id' => $reading['terminal_id'],
						'missing'     => $missing,
					),
					$user_id
				);
			}
			return array(
				'status'  => TerminalVerdict::PENDING,
				'code'    => 'ffc_terminal_awaiting_data',
				'message' => __( 'Attendance recorded. Finish your certificate on your phone: the terminal is free for the next person.', 'ffcertificate' ),
				'name'    => $reading['name'],
			);
		}

		$checked = $form_config;
		// The terminal stands at the entrance, which is what a password proves.
		unset( $checked['restrictions']['password'] );
		if ( self::WHO_ALLOWLIST === self::settings( $form_id )['who'] ) {
			$checked['restrictions']['allowlist'] = '1';
		}
		$allowed = ( $this->restrict )( $checked, $person['cpf_rf'], $reading['ticket_code'], $form_id, '', static fn(): bool => false );
		if ( empty( $allowed['allowed'] ) ) {
			return $this->refuse( $form_id, $reading, $has_ticket ? 'ffc_terminal_ticket_refused' : 'ffc_terminal_restricted', is_string( $allowed['message'] ?? null ) ? $allowed['message'] : '' );
		}
		$claimed = ! empty( $allowed['is_ticket'] );
		$ticket  = $claimed ? DataSanitizer::normalize_ticket( $reading['ticket_code'] ) : '';

		$limit = ( $this->limit )( $person, $form_id );
		if ( null !== $limit ) {
			if ( $claimed ) {
				AccessRestrictionChecker::release_ticket( $form_id, $ticket );
			}
			return $this->refuse( $form_id, $reading, 'ffc_terminal_limit', $limit );
		}

		return $this->issue( $form_id, $reading, $form_config, $fields, $built['data'], $person, $ticket );
	}

	/**
	 * Issue the certificate, one person at a time per form.
	 *
	 * @param int                  $form_id     Form id.
	 * @param array                $reading     The reading.
	 * @param array<string, mixed> $form_config Form configuration.
	 * @param list<array>          $fields      Form fields.
	 * @param array<string, mixed> $data        Submission data.
	 * @param array                $person      Profile data.
	 * @param string               $ticket      Claimed ticket, or ''.
	 * @phpstan-param TerminalReading $reading
	 * @phpstan-param list<array<string, mixed>> $fields
	 * @phpstan-param array{name: string, email: string, cpf_rf: string} $person
	 * @return Verdict
	 */
	private function issue( int $form_id, array $reading, array $form_config, array $fields, array $data, array $person, string $ticket ): array {
		$ctx             = new SubmissionContext();
		$ctx->form_id    = $form_id;
		$ctx->val_cpf    = $person['cpf_rf'];
		$ctx->user_email = $person['email'];
		$lock            = new SubmissionIdentityLock();
		try {
			$lock->apply( $ctx );
		} catch ( SubmissionRejected $e ) {
			unset( $e );
			if ( '' !== $ticket ) {
				AccessRestrictionChecker::release_ticket( $form_id, $ticket );
			}
			return TerminalVerdict::refused( 'ffc_terminal_busy', __( 'This certificate is already being issued. Show the QR again in a moment.', 'ffcertificate' ) );
		}

		try {
			if ( '' !== $ticket ) {
				$data['ticket'] = $ticket;
			}
			$data         += self::consent_record( $fields, $reading['at'], $reading['terminal_id'] );
			$submission_id = ( $this->save )(
				$form_id,
				(string) get_the_title( $form_id ),
				$data,
				$person['email'],
				$fields,
				$form_config,
				array(
					'submission_date' => $reading['at'],
					'record_ip'       => false,
				)
			);
			if ( is_wp_error( $submission_id ) || $submission_id <= 0 ) {
				if ( '' !== $ticket ) {
					AccessRestrictionChecker::release_ticket( $form_id, $ticket );
				}
				return TerminalVerdict::refused( 'ffc_terminal_failed', __( 'The certificate could not be issued. Show the code again.', 'ffcertificate' ) );
			}
			// The certificate belongs to the account that showed the code,
			// whatever account its CPF/RF would have matched.
			( $this->link )( (int) $submission_id, $reading['user_id'] );
			if ( '' !== $ticket ) {
				AccessRestrictionChecker::consume_ticket( $form_id, $ticket );
			}
			CertificateTerminalReadingRepository::record( $form_id, $reading['user_id'], $reading['terminal_id'], $reading['at'], CertificateTerminalReadingRepository::ISSUED, '', array(), $submission_id, time() );
			ActivityLog::log(
				'terminal_certificate_issued',
				ActivityLog::LEVEL_INFO,
				array(
					'form_id'     => $form_id,
					'terminal_id' => $reading['terminal_id'],
				),
				$reading['user_id'],
				(int) $submission_id
			);
		} finally {
			$lock->release();
		}

		return array(
			'status'  => TerminalVerdict::RECORDED,
			'code'    => 'ffc_terminal_issued',
			'message' => __( 'Certificate issued. It is in the app, under Certificates.', 'ffcertificate' ),
			'name'    => $reading['name'],
			'time'    => DateFormatter::format_time( $reading['at'] ),
		);
	}

	/**
	 * When and where a consent field was accepted by the reading: the
	 * reading's time and the terminal that read it, kept beside the answers
	 * as internal keys, so the acceptance carries its own proof.
	 *
	 * @param list<array<string, mixed>> $fields      Form fields.
	 * @param int                        $at          Reading time (Unix UTC).
	 * @param int                        $terminal_id Terminal id.
	 * @return array<string, int> Empty when no field is accepted by the reading.
	 */
	public static function consent_record( array $fields, int $at, int $terminal_id ): array {
		foreach ( $fields as $field ) {
			if ( CertificateProfileSource::CONSENT === CertificateProfileSource::of( $field ) ) {
				return array(
					self::CONSENT_AT_KEY       => $at,
					self::CONSENT_TERMINAL_KEY => $terminal_id,
				);
			}
		}
		return array();
	}

	/**
	 * The submission a form's fields take from a profile, and the required
	 * fields the profile cannot fill.
	 *
	 * Name, e-mail and CPF/RF are filled; a hidden field carries the form's
	 * value, as on the site; a field set to fill from the profile takes the
	 * value the person's profile holds, and a checkbox set to consent is
	 * accepted by the reading. Any other required field is missing until
	 * the person fills it on their phone.
	 *
	 * @param list<array<string, mixed>>                         $fields  Form fields.
	 * @param array{name: string, email: string, cpf_rf: string} $person  Profile data.
	 * @param array<string, string>                              $profile Profile values by source, from {@see CertificateProfileSource::values()}.
	 * @return array{data: array<string, mixed>, missing: list<string>}
	 */
	public static function submission_data( array $fields, array $person, array $profile = array() ): array {
		$data    = array();
		$missing = array();
		foreach ( $fields as $field ) {
			$name = isset( $field['name'] ) && is_string( $field['name'] ) ? $field['name'] : '';
			$type = isset( $field['type'] ) && is_string( $field['type'] ) ? $field['type'] : 'text';
			if ( '' === $name || in_array( $type, array( 'info', 'embed' ), true ) ) {
				continue;
			}
			if ( 'hidden' === $type ) {
				$data[ $name ] = sanitize_text_field( (string) ( $field['default_value'] ?? '' ) );
				continue;
			}
			$value = '';
			if ( 'cpf_rf' === $name ) {
				$value = $person['cpf_rf'];
			} elseif ( 'email' === $type ) {
				$value = $person['email'];
			} elseif ( in_array( $name, SubmitterName::CANDIDATE_KEYS, true ) ) {
				$value = DataSanitizer::normalize_brazilian_name( $person['name'] );
			}
			if ( '' === $value ) {
				$value = CertificateProfileSource::value_for( $field, $profile ) ?? '';
			}
			if ( '' !== $value ) {
				$data[ $name ] = $value;
			} elseif ( ! empty( $field['required'] ) && ! in_array( $type, self::DISPLAY_TYPES, true ) ) {
				$missing[] = $name;
			}
		}
		$data['ffc_lgpd_consent'] = '1';
		return array(
			'data'    => $data,
			'missing' => $missing,
		);
	}

	/**
	 * What a profile holds for a certificate: the display name, the account
	 * e-mail, and the RF or, without one, the CPF, read from the profile or,
	 * when it holds only a hash, from the person's newest submission that
	 * carries one. The RF comes first because it is what identifies a member
	 * of staff on a certificate (#2944).
	 *
	 * @param int $user_id Account id.
	 * @return array{name: string, email: string, cpf_rf: string}
	 */
	public static function person( int $user_id ): array {
		$user = get_userdata( $user_id );
		$out  = array(
			'name'   => $user instanceof \WP_User ? trim( (string) $user->display_name ) : '',
			'email'  => $user instanceof \WP_User ? DataSanitizer::normalize_email( (string) $user->user_email ) : '',
			'cpf_rf' => '',
		);
		foreach ( array( get_user_meta( $user_id, 'ffc_user_rf', true ), get_user_meta( $user_id, 'ffc_user_cpf', true ) ) as $stored ) {
			$plain = is_string( $stored ) && '' !== $stored ? (string) Encryption::decrypt( $stored ) : '';
			if ( '' !== $plain ) {
				$out['cpf_rf'] = DataSanitizer::normalize_cpf_rf( $plain );
				return $out;
			}
		}
		$latest = ( new SubmissionRepository() )->latest_identifier_for_user( $user_id );
		foreach ( null === $latest ? array() : array( $latest['rf_encrypted'], $latest['cpf_encrypted'] ) as $stored ) {
			$plain = '' !== $stored ? (string) Encryption::decrypt( $stored ) : '';
			if ( '' !== $plain ) {
				$out['cpf_rf'] = DataSanitizer::normalize_cpf_rf( $plain );
				break;
			}
		}
		return $out;
	}

	/**
	 * Whether a form asks for a CPF/RF.
	 *
	 * @param list<array<string, mixed>> $fields Form fields.
	 * @return bool
	 */
	private static function has_identifier_field( array $fields ): bool {
		foreach ( $fields as $field ) {
			if ( 'cpf_rf' === ( $field['name'] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The per-person limits of the rate-limit settings, by e-mail and CPF/RF.
	 *
	 * @param array{name: string, email: string, cpf_rf: string} $person  Profile data.
	 * @param int                                                $form_id Form id.
	 * @return string|null The refusal, or null when the person may.
	 */
	public static function person_limit( array $person, int $form_id ): ?string {
		$checks = array();
		if ( '' !== $person['email'] ) {
			$checks[] = RateLimiter::check_email_limit( $person['email'], $form_id );
		}
		if ( '' !== $person['cpf_rf'] ) {
			$checks[] = RateLimiter::check_cpf_limit( $person['cpf_rf'], $form_id );
		}
		foreach ( $checks as $check ) {
			if ( empty( $check['allowed'] ) ) {
				return $check['message'];
			}
		}
		return null;
	}

	/**
	 * A refusal that is kept on the readings list.
	 *
	 * @param int    $form_id Form id.
	 * @param array  $reading The reading.
	 * @param string $code    Reason code.
	 * @param string $message What to do.
	 * @phpstan-param TerminalReading $reading
	 * @return Verdict
	 */
	private function refuse( int $form_id, array $reading, string $code, string $message ): array {
		CertificateTerminalReadingRepository::record( $form_id, $reading['user_id'], $reading['terminal_id'], $reading['at'], CertificateTerminalReadingRepository::REFUSED, $code, array(), null, time() );
		return TerminalVerdict::refused( $code, $message );
	}

	/**
	 * The person already has this certificate.
	 *
	 * @param string $name Short display name.
	 * @return Verdict
	 */
	private function already( string $name ): array {
		return array(
			'status'  => TerminalVerdict::ALREADY,
			'code'    => 'ffc_terminal_already_issued',
			'message' => __( 'You already have this certificate. Open the app, under Certificates, to download it.', 'ffcertificate' ),
			'name'    => $name,
		);
	}

	/**
	 * A time of day as `H:i`, or a default.
	 *
	 * @param mixed  $value    Raw value.
	 * @param string $fallback Default.
	 * @return string
	 */
	private static function clock( $value, string $fallback ): string {
		return is_string( $value ) && 1 === preg_match( '/^([01]\d|2[0-3]):[0-5]\d/', $value ) ? substr( $value, 0, 5 ) : $fallback;
	}
}
