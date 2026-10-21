<?php
/**
 * Date-message rule.
 *
 * @package FreeFormCertificate\DateMessages
 * @since 6.33.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\DateMessages;

use FreeFormCertificate\Core\EmailBodyAppearance;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One configured message: what to send, to whom, and how many days from the
 * date (#1538).
 *
 * Immutable and built only through `from_array()`, which is also the one
 * place a rule is validated -- the writer stores what this accepts and the
 * runner reads what it builds, so a value the screen never offered (an offset
 * of a year, an unknown source) cannot reach either.
 */
final class Rule {

	/**
	 * Furthest a message may be sent before or after its date, in days.
	 */
	public const MAX_OFFSET_DAYS = 60;

	/**
	 * Digest modes: counts only, or counts plus the names of who received it.
	 */
	public const DIGEST_MODES = array( 'summary', 'detailed' );

	/**
	 * Kinds of rule (#1949). A dated rule sends on a date relative to each
	 * person's date; an announcement sends one message to everyone in its
	 * audience, now or once at `send_at`.
	 */
	public const KIND_DATED        = 'dated';
	public const KIND_ANNOUNCEMENT = 'announcement';

	/**
	 * Source an announcement reads its recipients from. Not in `DateSources`:
	 * it has no date to read, so it is not a choice the operator makes.
	 */
	public const ANNOUNCEMENT_SOURCE = 'announcement';

	/**
	 * Constructor.
	 *
	 * @param int                 $id              Rule id (0 before it is stored).
	 * @param string              $name            Operator-facing name.
	 * @param string              $source          Date source id (see DateSources).
	 * @param int                 $offset_days     Days from the date: 0 on it, -7 a week before.
	 * @param array<int>          $audience_ids    Audiences whose members qualify (sub-audiences included); empty for everyone.
	 * @param string              $subject         E-mail subject (tokens allowed).
	 * @param string              $body            E-mail body HTML (tokens allowed).
	 * @param bool                $send_to_user    Whether the person receives it.
	 * @param bool                $digest_enabled  Whether managers get a digest.
	 * @param string              $digest_mode     One of DIGEST_MODES.
	 * @param array<int>          $digest_user_ids Managers (WordPress user ids).
	 * @param bool                $is_active       Whether the cron sends it.
	 * @param EmailBodyAppearance $appearance How the body cell is drawn (#1660).
	 * @param string              $kind            KIND_DATED or KIND_ANNOUNCEMENT.
	 * @param int                 $send_at         Announcements: the instant it is sent once, 0 for "by hand".
	 * @param bool                $respect_opt_out Announcements only: false sends to people who opted out (forced, and labelled).
	 */
	private function __construct(
		public readonly int $id,
		public readonly string $name,
		public readonly string $source,
		public readonly int $offset_days,
		public readonly array $audience_ids,
		public readonly string $subject,
		public readonly string $body,
		public readonly bool $send_to_user,
		public readonly bool $digest_enabled,
		public readonly string $digest_mode,
		public readonly array $digest_user_ids,
		public readonly bool $is_active,
		public readonly EmailBodyAppearance $appearance,
		public readonly string $kind = self::KIND_DATED,
		public readonly int $send_at = 0,
		public readonly bool $respect_opt_out = true,
	) {}

	/**
	 * Build a rule from a stored row or a submitted form, validating it.
	 *
	 * Keys are the table's column names. `audience_ids`, `digest_user_ids`
	 * and `appearance` may be a JSON string (a stored row) or a list (a form).
	 *
	 * @param array<string, mixed> $data Raw data.
	 * @return Rule|\WP_Error
	 */
	public static function from_array( array $data ) {
		$name    = self::text( $data['name'] ?? '' );
		$subject = self::text( $data['subject'] ?? '' );
		$body    = is_string( $data['body'] ?? null ) ? trim( $data['body'] ) : '';
		$source  = self::text( $data['source'] ?? 'birthday' );
		$offset  = $data['offset_days'] ?? 0;
		$mode    = self::text( $data['digest_mode'] ?? 'summary' );

		if ( '' === $name ) {
			return new \WP_Error( 'ffc_rule_name', __( 'Give the rule a name.', 'ffcertificate' ) );
		}
		if ( '' === $subject || '' === $body ) {
			return new \WP_Error( 'ffc_rule_message', __( 'The e-mail needs a subject and a body.', 'ffcertificate' ) );
		}
		$offset = filter_var( $offset, FILTER_VALIDATE_INT );
		if ( false === $offset || abs( $offset ) > self::MAX_OFFSET_DAYS ) {
			return new \WP_Error(
				'ffc_rule_offset',
				sprintf(
					/* translators: %d: maximum number of days */
					__( 'The number of days must be a whole number between -%1$d and %1$d.', 'ffcertificate' ),
					self::MAX_OFFSET_DAYS
				)
			);
		}
		if ( ! in_array( $mode, self::DIGEST_MODES, true ) ) {
			return new \WP_Error( 'ffc_rule_digest_mode', __( 'Unknown digest mode.', 'ffcertificate' ) );
		}
		$appearance = EmailBodyAppearance::from_array( $data['appearance'] ?? null );
		if ( is_wp_error( $appearance ) ) {
			return $appearance;
		}

		$kind = self::text( $data['kind'] ?? self::KIND_DATED );
		if ( ! in_array( $kind, array( self::KIND_DATED, self::KIND_ANNOUNCEMENT ), true ) ) {
			return new \WP_Error( 'ffc_rule_kind', __( 'Unknown kind of message.', 'ffcertificate' ) );
		}
		$send_at = is_numeric( $data['send_at'] ?? null ) ? max( 0, (int) $data['send_at'] ) : 0;
		$respect = self::flag( $data['respect_opt_out'] ?? true );

		if ( self::KIND_ANNOUNCEMENT === $kind ) {
			// An announcement reads everyone in its audience, not a date: its
			// source and its day offset are fixed, and only the instant varies.
			$source = self::ANNOUNCEMENT_SOURCE;
			$offset = 0;
		} else {
			// Dated messages always respect the opt-out: forcing one past a
			// birthday greeting is a choice this module does not offer.
			$send_at = 0;
			$respect = true;
			if ( self::ANNOUNCEMENT_SOURCE === $source || ! DateSources::has( $source ) ) {
				return new \WP_Error( 'ffc_rule_source', __( 'Unknown date source.', 'ffcertificate' ) );
			}
		}

		return new self(
			id: is_numeric( $data['id'] ?? null ) ? max( 0, (int) $data['id'] ) : 0,
			name: $name,
			source: $source,
			offset_days: $offset,
			audience_ids: self::ids( $data['audience_ids'] ?? array() ),
			subject: $subject,
			body: $body,
			send_to_user: self::flag( $data['send_to_user'] ?? true ),
			digest_enabled: self::flag( $data['digest_enabled'] ?? false ),
			digest_mode: $mode,
			digest_user_ids: self::ids( $data['digest_user_ids'] ?? array() ),
			is_active: self::flag( $data['is_active'] ?? true ),
			appearance: $appearance,
			kind: $kind,
			send_at: $send_at,
			respect_opt_out: $respect,
		);
	}

	/**
	 * Whether this rule is an announcement rather than a dated message.
	 *
	 * @return bool
	 */
	public function is_announcement(): bool {
		return self::KIND_ANNOUNCEMENT === $this->kind;
	}

	/**
	 * The opt-out category this rule's messages are checked against (#1949).
	 *
	 * @return string One of OptOutCategory::all().
	 */
	public function opt_out_category(): string {
		return $this->is_announcement() ? OptOutCategory::ANNOUNCEMENT : OptOutCategory::PERSONAL;
	}

	/**
	 * Column => value, for the writer.
	 *
	 * @return array<string, mixed>
	 */
	public function to_columns(): array {
		return array(
			'name'            => $this->name,
			'source'          => $this->source,
			'offset_days'     => $this->offset_days,
			'audience_ids'    => (string) wp_json_encode( $this->audience_ids ),
			'subject'         => $this->subject,
			'body'            => $this->body,
			'send_to_user'    => $this->send_to_user ? 1 : 0,
			'digest_enabled'  => $this->digest_enabled ? 1 : 0,
			'digest_mode'     => $this->digest_mode,
			'digest_user_ids' => (string) wp_json_encode( $this->digest_user_ids ),
			'is_active'       => $this->is_active ? 1 : 0,
			'appearance'      => $this->appearance->is_default() ? '' : (string) wp_json_encode( $this->appearance->to_array() ),
			'kind'            => $this->kind,
			'send_at'         => $this->send_at,
			'respect_opt_out' => $this->respect_opt_out ? 1 : 0,
		);
	}

	/**
	 * Trimmed single-line text, or '' for a non-scalar.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function text( $value ): string {
		return is_scalar( $value ) ? trim( sanitize_text_field( (string) $value ) ) : '';
	}

	/**
	 * A stored or submitted boolean.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	private static function flag( $value ): bool {
		return is_scalar( $value ) && in_array( strtolower( (string) $value ), array( '1', 'true', 'on', 'yes' ), true );
	}

	/**
	 * Positive, unique ids from a JSON string or a list -- the stored and the
	 * submitted shape of both id lists a rule carries.
	 *
	 * @param mixed $value Raw value.
	 * @return array<int, int>
	 */
	private static function ids( $value ): array {
		if ( is_string( $value ) ) {
			$decoded = json_decode( $value, true );
			$value   = is_array( $decoded ) ? $decoded : array();
		}
		if ( ! is_array( $value ) ) {
			return array();
		}
		$ids = array();
		foreach ( $value as $id ) {
			if ( is_numeric( $id ) && (int) $id > 0 ) {
				$ids[] = (int) $id;
			}
		}
		return array_values( array_unique( $ids ) );
	}
}
