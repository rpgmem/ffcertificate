<?php
/**
 * Email Service
 *
 * The single email transport chokepoint (#653). Every outbound email funnels
 * through {@see self::send()} — the low-level `wp_mail` wrapper plus failure
 * logging — instead of the previous three paths (`EmailHelperTrait::ffc_send_mail`,
 * `EmailTemplateService::send`, and raw `wp_mail` calls). Content-type/headers
 * stay caller-supplied so each existing send keeps its exact behaviour (some
 * are text/html, some default text/plain, recruitment is multipart).
 *
 * Because every send is centralised here, this is also where the whole plugin
 * gains a `multipart/alternative` plain-text part for free: for HTML messages
 * {@see self::send()} derives a text body from the composed HTML and attaches
 * it as PHPMailer's `AltBody` (#673). No per-handler work, no bypass, and it
 * works on WordPress core — it does not depend on an SMTP/queue plugin.
 *
 * @package FreeFormCertificate\Core
 * @since 6.14.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

use PHPMailer\PHPMailer\PHPMailer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Low-level email transport.
 */
final class EmailService {

	/**
	 * Plain-text alternative for the message currently being sent, or null.
	 *
	 * Set immediately before `wp_mail()` and cleared immediately after, so the
	 * `phpmailer_init` callback ({@see self::set_plain_text_alternative()}) can
	 * reach it. Sends are synchronous — `wp_mail()` blocks until PHPMailer has
	 * fired `phpmailer_init` — so there is no reentrancy on this static.
	 *
	 * @var string|null
	 */
	private static ?string $pending_alt_body = null;

	/**
	 * Send an email via `wp_mail`, logging failures.
	 *
	 * Enforces the global "disable all emails" kill-switch here, at the single
	 * transport chokepoint, so it is bypass-proof: every path (including
	 * `SchedulingMailer::send`, recruitment, capability-manager and the
	 * certificate send-site) funnels through this method and honours the
	 * toggle regardless of whether the caller remembered to check it. This
	 * deliberately reverses the earlier "kill-switch stays caller-side"
	 * decision (#655) — see #662 P1. Template rendering remains the caller's
	 * concern; this is pure transport plus the master gate.
	 *
	 * For HTML messages it also attaches a derived `text/plain` alternative
	 * (multipart) so the mail renders in text-only clients and reads better to
	 * spam filters (#673). The text is derived from the already-composed HTML
	 * (tokens already resolved) and can be customised — or suppressed — via the
	 * `ffcertificate_email_plain_text` filter.
	 *
	 * @param string             $to          Recipient.
	 * @param string             $subject     Subject.
	 * @param string             $body        Body (already rendered).
	 * @param array<int, string> $headers     Mail headers (caller decides content-type).
	 * @param array<int, string> $attachments Attachment paths.
	 * @return bool Whether `wp_mail` accepted the message (false when emails are globally disabled).
	 */
	public static function send( string $to, string $subject, string $body, array $headers = array(), array $attachments = array() ): bool {
		if ( \FreeFormCertificate\Settings\SettingsReader::emails_disabled() ) {
			return false;
		}

		$registered = self::maybe_register_plain_text_alternative( $body, $headers );

		$sent = wp_mail( $to, $subject, $body, $headers, $attachments );

		if ( $registered ) {
			remove_action( 'phpmailer_init', array( __CLASS__, 'set_plain_text_alternative' ) );
			self::$pending_alt_body = null;
		}

		if ( ! $sent && class_exists( '\FreeFormCertificate\Core\Utils' ) ) {
			Debug::log_email(
				'Email send failed',
				array(
					'to'      => $to,
					'subject' => $subject,
				)
			);
		}

		return $sent;
	}

	/**
	 * Derive and stage the plain-text alternative for an HTML message.
	 *
	 * No-op (returns false) for non-HTML messages, or when the derived text is
	 * empty or a caller suppressed it via `ffcertificate_email_plain_text`.
	 *
	 * @param string             $body    Composed HTML body.
	 * @param array<int, string> $headers Mail headers.
	 * @return bool Whether a `phpmailer_init` callback was registered.
	 */
	private static function maybe_register_plain_text_alternative( string $body, array $headers ): bool {
		if ( ! self::headers_are_html( $headers ) ) {
			return false;
		}

		$plain = self::html_to_plain_text( $body );

		/**
		 * Filter the derived plain-text alternative for an HTML email.
		 *
		 * Return an empty string to suppress the alternative and send HTML-only.
		 *
		 * @since 6.14.0
		 * @param string $plain Plain-text body derived from the composed HTML.
		 * @param string $body  The composed HTML body it was derived from.
		 */
		$plain = (string) apply_filters( 'ffcertificate_email_plain_text', $plain, $body );

		if ( '' === trim( $plain ) ) {
			return false;
		}

		self::$pending_alt_body = $plain;
		add_action( 'phpmailer_init', array( __CLASS__, 'set_plain_text_alternative' ) );

		return true;
	}

	/**
	 * `phpmailer_init` callback: set the staged plain-text body on PHPMailer.
	 *
	 * Assigning `AltBody` makes PHPMailer emit a `multipart/alternative`
	 * message (or `multipart/mixed` when attachments are present).
	 *
	 * @param PHPMailer $phpmailer PHPMailer instance (passed by reference by core).
	 * @return void
	 */
	public static function set_plain_text_alternative( $phpmailer ): void {
		if ( null !== self::$pending_alt_body && '' !== self::$pending_alt_body ) {
			$phpmailer->AltBody = self::$pending_alt_body;
		}
	}

	/**
	 * Whether the headers declare an HTML content type.
	 *
	 * @param array<int, string> $headers Mail headers.
	 * @return bool
	 */
	private static function headers_are_html( array $headers ): bool {
		foreach ( $headers as $header ) {
			if ( false !== stripos( $header, 'content-type' )
				&& false !== stripos( $header, 'text/html' )
			) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Convert composed email HTML into a readable plain-text approximation.
	 *
	 * Keeps links as "label (url)", turns block/line-break tags into newlines,
	 * strips the remaining markup, decodes entities and collapses blank runs.
	 * Good enough for the `text/plain` alternative — not a full HTML renderer.
	 *
	 * @param string $html Composed HTML (tokens already resolved).
	 * @return string Plain-text body.
	 */
	public static function html_to_plain_text( string $html ): string {
		// Preserve links as "label (url)".
		$text = (string) preg_replace_callback(
			'/<a\b[^>]*href=(["\'])(.*?)\1[^>]*>(.*?)<\/a>/is',
			static function ( array $m ): string {
				$url   = trim( $m[2] );
				$label = trim( (string) wp_strip_all_tags( $m[3] ) );
				if ( '' === $url || $url === $label ) {
					return $label;
				}
				if ( '' === $label ) {
					return $url;
				}
				return $label . ' (' . $url . ')';
			},
			$html
		);

		// Turn block-level and line-break tags into newlines before stripping.
		$text = (string) preg_replace( '#<(?:br|/p|/div|/h[1-6]|/li|/tr)\b[^>]*>#i', "\n", $text );

		// Drop the remaining markup and decode entities.
		$text = (string) wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// Normalise whitespace: trim each line, collapse blank runs.
		$split = preg_split( '/\n/', $text );
		$lines = array_map( 'trim', false === $split ? array() : $split );
		$text  = implode( "\n", $lines );
		$text  = (string) preg_replace( "/\n{3,}/", "\n\n", $text );

		return trim( $text );
	}
}
