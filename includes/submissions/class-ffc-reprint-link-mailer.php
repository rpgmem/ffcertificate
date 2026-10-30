<?php
/**
 * ReprintLinkMailer
 *
 * A reprint asked for by CPF/RF from the public form (#2241). Typing someone
 * else's CPF used to return their certificate, their e-mail and their magic
 * link; now the link goes to the address stored on the certificate, and the
 * screen only says where it went, masked.
 *
 * @package FreeFormCertificate\Submissions
 * @since 6.43.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Submissions;

use FreeFormCertificate\Core\DocumentFormatter;
use FreeFormCertificate\Integrations\EmailHandler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends a certificate's link to its stored address, at most once per window.
 */
class ReprintLinkMailer {

	/**
	 * How long a certificate waits before its link is sent again.
	 */
	public const THROTTLE_SECONDS = 15 * MINUTE_IN_SECONDS;

	/**
	 * Transient prefix that marks a link as sent within the window.
	 */
	private const TRANSIENT_PREFIX = 'ffc_reprint_link_';

	/**
	 * Sends the link e-mail.
	 *
	 * @var EmailHandler|null
	 */
	private ?EmailHandler $email;

	/**
	 * Constructor.
	 *
	 * @param EmailHandler|null $email Sends the link e-mail; built on first use when null.
	 */
	public function __construct( ?EmailHandler $email = null ) {
		$this->email = $email;
	}

	/**
	 * Send the certificate's link to its stored address, unless it was sent
	 * within the window.
	 *
	 * Within the window the notice repeats and nothing is sent, so the form
	 * cannot be used to flood a person's inbox.
	 *
	 * @param int    $submission_id Submission ID.
	 * @param string $stored_email  The address stored on the certificate.
	 * @return array{status: string, masked_email: string} Status is `sent`, `throttled`, `no_email` or `failed`.
	 */
	public function send( int $submission_id, string $stored_email ): array {
		$stored_email = trim( $stored_email );
		if ( '' === $stored_email ) {
			return array(
				'status'       => 'no_email',
				'masked_email' => '',
			);
		}

		$masked = DocumentFormatter::mask( DocumentFormatter::MASK_EMAIL, $stored_email );
		$key    = self::TRANSIENT_PREFIX . $submission_id;

		if ( false !== get_transient( $key ) ) {
			return array(
				'status'       => 'throttled',
				'masked_email' => $masked,
			);
		}

		$this->email ??= new EmailHandler();
		$result        = $this->email->resend_link( $submission_id );

		if ( true !== $result ) {
			return array(
				'status'       => 'failed',
				'masked_email' => $masked,
			);
		}

		set_transient( $key, time(), self::THROTTLE_SECONDS );

		return array(
			'status'       => 'sent',
			'masked_email' => $masked,
		);
	}

	/**
	 * What the screen says after a reprint request.
	 *
	 * Never names the certificate's data: only that it exists and where the
	 * link went, masked.
	 *
	 * @param array{status: string, masked_email: string} $result {@see send()}.
	 * @return string
	 */
	public static function message( array $result ): string {
		if ( 'no_email' === $result['status'] ) {
			return __( 'A certificate already exists for this CPF/RF, but it has no e-mail address on record. Please contact the organizer.', 'ffcertificate' );
		}
		if ( 'failed' === $result['status'] ) {
			return __( 'A certificate already exists for this CPF/RF, but the e-mail with its link could not be sent. Please contact the organizer.', 'ffcertificate' );
		}
		return sprintf(
			/* translators: %s: the masked e-mail address the link was sent to */
			__( 'A certificate already exists for this CPF/RF. We sent the link to the registered e-mail (%s).', 'ffcertificate' ),
			$result['masked_email']
		);
	}
}
