<?php
/**
 * Date-message builder.
 *
 * @package FreeFormCertificate\DateMessages
 * @since 6.33.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\DateMessages;

use FreeFormCertificate\Core\EmailTemplates;
use FreeFormCertificate\Core\PasswordInvite;
use FreeFormCertificate\Core\TokenResolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns a rule and one recipient into the subject and body to send (#1538).
 *
 * The rule edits the BODY only; the chrome is added by `SchedulingMailer`
 * through `ffc_email_document()`, the one pipeline every plugin email uses
 * (#662). Token values are escaped for the context they land in -- text with
 * `esc_html()`, links with `esc_url()` -- because a display name is data a
 * person typed, and the body is HTML.
 *
 * Every message carries the unsubscribe link. A body that does not place
 * `{{unsubscribe_url}}` itself gets a footer line appended, so an operator
 * editing the text cannot remove the way out by accident.
 */
final class MessageBuilder {

	/**
	 * The template a new birthday rule starts from.
	 */
	public const DEFAULT_TEMPLATE = 'date-message-birthday';

	/**
	 * Default subject and body for a new rule, as edited in the hub.
	 *
	 * @return array{subject: string, body: string}
	 */
	public static function defaults(): array {
		return array(
			'subject' => EmailTemplates::effective_body( self::DEFAULT_TEMPLATE, 'subject' ),
			'body'    => EmailTemplates::effective_body( self::DEFAULT_TEMPLATE, 'body' ),
		);
	}

	/**
	 * Build a real message.
	 *
	 * @param Rule                                             $rule      The rule.
	 * @param DateSourceInterface                              $source    Its source.
	 * @param array{user_id: int, email: string, name: string} $recipient Who.
	 * @param \DateTimeImmutable                               $target    The day of the date.
	 * @param \DateTimeImmutable                               $today     Today (site timezone).
	 * @return array{subject: string, body: string}
	 */
	public static function build( Rule $rule, DateSourceInterface $source, array $recipient, \DateTimeImmutable $target, \DateTimeImmutable $today ): array {
		$first = get_user_meta( $recipient['user_id'], 'first_name', true );

		$tokens = array_merge(
			array(
				'name'            => $recipient['name'],
				'first_name'      => is_string( $first ) && '' !== trim( $first ) ? trim( $first ) : self::first_word( $recipient['name'] ),
				'email'           => $recipient['email'],
				'days_until'      => (string) self::days_between( $today, $target ),
				'site_name'       => (string) get_bloginfo( 'name' ),
				'dashboard_url'   => PasswordInvite::dashboard_url(),
				'unsubscribe_url' => Unsubscribe::url( $recipient['user_id'] ),
			),
			$source->tokens( $recipient['user_id'], $target )
		);

		return self::render( $rule->subject, $rule->body, $tokens );
	}

	/**
	 * Build a message with fictional values, for previews and test sends.
	 *
	 * @param string              $subject Subject template.
	 * @param string              $body    Body template.
	 * @param DateSourceInterface $source  Source.
	 * @param \DateTimeImmutable  $target  The day of the date.
	 * @param \DateTimeImmutable  $today   Today.
	 * @return array{subject: string, body: string}
	 */
	public static function sample( string $subject, string $body, DateSourceInterface $source, \DateTimeImmutable $target, \DateTimeImmutable $today ): array {
		$tokens = array_merge(
			array(
				'name'            => __( 'Maria da Silva', 'ffcertificate' ),
				'first_name'      => __( 'Maria', 'ffcertificate' ),
				'email'           => 'maria.silva@example.org',
				'days_until'      => (string) self::days_between( $today, $target ),
				'site_name'       => (string) get_bloginfo( 'name' ),
				'dashboard_url'   => PasswordInvite::dashboard_url(),
				'unsubscribe_url' => home_url( '/' ),
			),
			$source->sample_tokens( $target )
		);

		return self::render( $subject, $body, $tokens );
	}

	/**
	 * Resolve tokens, escaping each value for HTML, and guarantee the
	 * unsubscribe link.
	 *
	 * @param string                $subject Subject template.
	 * @param string                $body    Body template.
	 * @param array<string, string> $tokens  Token name => plain value.
	 * @return array{subject: string, body: string}
	 */
	private static function render( string $subject, string $body, array $tokens ): array {
		$plain = array();
		$html  = array();
		foreach ( $tokens as $name => $value ) {
			$key           = '{{' . $name . '}}';
			$plain[ $key ] = $value;
			$html[ $key ]  = str_ends_with( $name, '_url' ) ? esc_url( $value ) : esc_html( $value );
		}

		if ( ! str_contains( $body, '{{unsubscribe_url}}' ) ) {
			$body .= '<p style="margin: 20px 0 0 0; font-size: 12px; color: #646970;">'
				. sprintf(
					/* translators: %s: unsubscribe link URL */
					__( 'Prefer not to receive these messages? <a href="%s">Unsubscribe</a>.', 'ffcertificate' ),
					'{{unsubscribe_url}}'
				)
				. '</p>';
		}

		return array(
			'subject' => wp_strip_all_tags( TokenResolver::resolve( $subject, $plain ) ),
			'body'    => TokenResolver::resolve( $body, $html ),
		);
	}

	/**
	 * Whole days from one day to another (negative when the target is past).
	 *
	 * @param \DateTimeImmutable $from From.
	 * @param \DateTimeImmutable $to   To.
	 * @return int
	 */
	private static function days_between( \DateTimeImmutable $from, \DateTimeImmutable $to ): int {
		$diff = $from->setTime( 0, 0 )->diff( $to->setTime( 0, 0 ) );
		return ( 1 === $diff->invert ? -1 : 1 ) * (int) $diff->days;
	}

	/**
	 * First word of a name.
	 *
	 * @param string $name Full name.
	 * @return string
	 */
	private static function first_word( string $name ): string {
		$parts = preg_split( '/\s+/', trim( $name ) );
		return is_array( $parts ) && isset( $parts[0] ) ? $parts[0] : '';
	}
}
