<?php
/**
 * User Messages
 *
 * What one person was sent by the date-messages module, for the Messages tab
 * of their dashboard (#2503).
 *
 * @package FreeFormCertificate\DateMessages
 * @since   6.43.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\DateMessages;

use FreeFormCertificate\Core\DateFormatter;
use FreeFormCertificate\Core\DocumentFormatter;
use FreeFormCertificate\Core\PasswordInvite;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Nothing is stored per person. The delivery log says which rule reached
 * whom and when; the subject and body are built again from the rule, with
 * the person's current values, when the tab is read. So an edited rule shows
 * its current text, and a deleted rule leaves the tab: there is nothing left
 * to build it from. A disabled rule stays, unless the administrator chose to
 * hide what disabled rules sent ({@see self::hide_disabled_rules()}).
 *
 * A failed send is never listed: the runner releases its claim, so the log
 * keeps no row for it.
 *
 * An announcement is "current" for {@see self::notice_days()} days after it
 * was sent: it opens the dashboard as a notice and heads the tab. Everything
 * else is "earlier".
 */
class UserMessages {

	/**
	 * The option the module's settings live in, shared with the send time.
	 */
	public const SETTINGS_OPTION = DateMessagesCron::SETTINGS_OPTION;

	/**
	 * The days an announcement may stay current, in order.
	 */
	public const NOTICE_DAYS_CHOICES = array( 2, 5, 7, 10, 15 );

	/**
	 * The days an announcement stays current until an administrator chooses.
	 */
	public const DEFAULT_NOTICE_DAYS = 7;

	/**
	 * Most deliveries read for one person. The log keeps a year
	 * ({@see DeliveryLog::RETENTION_DAYS}), so this bounds a busy year.
	 */
	public const MAX_DELIVERIES = 200;

	/**
	 * Most current announcements shown as notices at the top of the dashboard.
	 */
	public const MAX_NOTICES = 3;

	/**
	 * Words of the body a notice shows.
	 */
	private const EXCERPT_WORDS = 25;

	/**
	 * The days an announcement stays current.
	 *
	 * @return int One of NOTICE_DAYS_CHOICES.
	 */
	public static function notice_days(): int {
		$settings = get_option( self::SETTINGS_OPTION, array() );
		$days     = is_array( $settings ) && is_numeric( $settings['dashboard_notice_days'] ?? null ) ? (int) $settings['dashboard_notice_days'] : self::DEFAULT_NOTICE_DAYS;
		return in_array( $days, self::NOTICE_DAYS_CHOICES, true ) ? $days : self::DEFAULT_NOTICE_DAYS;
	}

	/**
	 * Whether what a disabled rule sent leaves the dashboard. Off until an
	 * administrator turns it on, so nothing disappears without a choice.
	 *
	 * @return bool
	 */
	public static function hide_disabled_rules(): bool {
		$settings = get_option( self::SETTINGS_OPTION, array() );
		return is_array( $settings ) && ! empty( $settings['hide_disabled_rules'] );
	}

	/**
	 * The person's messages, split into current and earlier, newest first.
	 *
	 * @param int  $user_id Person.
	 * @param bool $masked  Whether the reader is not the person (view as
	 *                      user): their e-mail and every value the date source
	 *                      supplies (a birth date, an age) are masked.
	 * @param int  $now     Unix time.
	 * @return array{current: list<array<string, mixed>>, earlier: list<array<string, mixed>>}
	 */
	public static function for_user( int $user_id, bool $masked, int $now ): array {
		$out = array(
			'current' => array(),
			'earlier' => array(),
		);
		// The log first: a person who was sent nothing costs one query.
		$deliveries = DeliveryLog::deliveries_for_user( $user_id, self::MAX_DELIVERIES );
		if ( array() === $deliveries ) {
			return $out;
		}
		$user = get_userdata( $user_id );
		if ( ! is_object( $user ) ) {
			return $out;
		}

		$rules = array();
		foreach ( RuleReader::all() as $rule ) {
			$rules[ $rule->id ] = $rule;
		}
		$hide_disabled = self::hide_disabled_rules();
		$window_start  = $now - self::notice_days() * DAY_IN_SECONDS;

		foreach ( $deliveries as $delivery ) {
			$rule = $rules[ $delivery['rule_id'] ] ?? null;
			if ( null === $rule || ( $hide_disabled && ! $rule->is_active ) ) {
				continue;
			}
			$message = self::build( $rule, $user, $delivery, $masked );
			if ( null === $message ) {
				continue;
			}
			$current                                   = $rule->is_announcement() && $delivery['sent_at'] >= $window_start;
			$item                                      = array(
				'key'     => $rule->id . '-' . $delivery['occurrence_key'],
				'kind'    => $rule->is_announcement() ? Rule::KIND_ANNOUNCEMENT : Rule::KIND_DATED,
				'subject' => $message['subject'],
				'body'    => wp_kses_post( $message['body'] ),
				// A block's end becomes a space, or two paragraphs read as one word.
				'excerpt' => wp_trim_words( wp_strip_all_tags( (string) preg_replace( '#<br\s*/?>|</(?:p|div|li|h[1-6]|tr|td)>#i', ' ', $message['body'] ) ), self::EXCERPT_WORDS ),
				'sent_at' => $delivery['sent_at'],
				'sent'    => DateFormatter::format_date( $delivery['sent_at'] ),
			);
			$out[ $current ? 'current' : 'earlier' ][] = $item;
		}
		return $out;
	}

	/**
	 * The announcements that open the dashboard: the current ones, unless the
	 * person turned announcements off.
	 *
	 * @param array<int, array<string, mixed>> $current The current group of {@see self::for_user()}.
	 * @param int                              $user_id Person.
	 * @return list<array<string, mixed>>
	 */
	public static function notices( array $current, int $user_id ): array {
		if ( array() === $current || isset( OptOut::among( array( $user_id ), OptOutCategory::ANNOUNCEMENT )[ $user_id ] ) ) {
			return array();
		}
		return array_slice( array_values( $current ), 0, self::MAX_NOTICES );
	}

	/**
	 * One delivery's subject and body, built from its rule as it is now.
	 *
	 * @param Rule                                                      $rule     The rule.
	 * @param object                                                    $user     The person's account (`get_userdata()`).
	 * @param array{rule_id: int, occurrence_key: string, sent_at: int} $delivery The log row.
	 * @param bool                                                      $masked   See for_user().
	 * @return array{subject: string, body: string}|null Null when the rule's source is gone or the build fails.
	 */
	private static function build( Rule $rule, object $user, array $delivery, bool $masked ): ?array {
		$source = DateSources::for_rule( $rule );
		if ( null === $source ) {
			return null;
		}

		$timezone = wp_timezone();
		$sent_day = ( new \DateTimeImmutable( '@' . max( 0, $delivery['sent_at'] ) ) )->setTimezone( $timezone )->setTime( 0, 0 );
		// A dated rule's occurrence is the day of the date; an announcement's
		// is a fixed key, and its day is the day it went out.
		$target = \DateTimeImmutable::createFromFormat( '!Y-m-d', $delivery['occurrence_key'], $timezone );
		if ( false === $target ) {
			$target = $sent_day;
		}

		$recipient = array(
			'user_id' => is_numeric( $user->ID ?? null ) ? (int) $user->ID : 0,
			'email'   => is_string( $user->user_email ?? null ) ? $user->user_email : '',
			'name'    => is_string( $user->display_name ?? null ) ? $user->display_name : '',
		);

		// The e-mail's unsubscribe link becomes the tab's own preferences:
		// on the dashboard, the person turns the messages off right there.
		$overrides = array( 'unsubscribe_url' => add_query_arg( 'tab', 'messages', PasswordInvite::dashboard_url() ) );
		if ( $masked ) {
			$overrides['email'] = DocumentFormatter::mask( DocumentFormatter::MASK_EMAIL, $recipient['email'] );
			foreach ( array_keys( $source->tokens( $recipient['user_id'], $target ) ) as $token ) {
				$overrides[ (string) $token ] = DocumentFormatter::mask_placeholder( DocumentFormatter::MASK_GENERIC );
			}
		}

		try {
			return MessageBuilder::build( $rule, $source, $recipient, $target, $sent_day, $overrides );
		} catch ( \Throwable $e ) {
			// A value that will not decrypt is one message missing from the
			// list, not a tab that does not load.
			return null;
		}
	}
}
