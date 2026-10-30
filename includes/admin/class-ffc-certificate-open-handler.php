<?php
/**
 * CertificateOpenHandler
 *
 * Opens a certificate for staff through an audited route (#2241). The
 * certificate prints the holder's name and CPF/RF, and its magic link is a
 * bearer token to all of it, so staff reach either only as a reveal: the
 * module's PII capability, under the shared policy, and one activity-log row
 * per opening. The list's PDF button and the edit screen's Open button both
 * point here instead of carrying the magic link in the page.
 *
 * @package FreeFormCertificate\Admin
 * @since 6.43.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

use FreeFormCertificate\Core\PiiAccessPolicy;
use FreeFormCertificate\Core\RequestInput;
use FreeFormCertificate\Submissions\SubmissionHandler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The `admin_post_ffc_open_certificate` handler.
 */
class CertificateOpenHandler {

	/**
	 * The `admin_post_*` action.
	 */
	public const ACTION = 'ffc_open_certificate';

	/**
	 * The module's PII capability, handed to the shared policy.
	 */
	public const PII_CAP = 'ffc_view_certificates_pii';

	/**
	 * Submission handler.
	 *
	 * @var SubmissionHandler
	 */
	private SubmissionHandler $submission_handler;

	/**
	 * Constructor.
	 *
	 * @param SubmissionHandler $handler Submission handler.
	 */
	public function __construct( SubmissionHandler $handler ) {
		$this->submission_handler = $handler;
	}

	/**
	 * Register the request hook. `admin_post_*` runs only for a logged-in
	 * user, the only kind that can hold the capability.
	 */
	public function init(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Nonce action for one submission.
	 *
	 * @param int $submission_id Submission ID.
	 */
	public static function nonce_action( int $submission_id ): string {
		return self::ACTION . '_' . $submission_id;
	}

	/**
	 * The audited URL that opens one submission's certificate.
	 *
	 * @param int $submission_id Submission ID.
	 */
	public static function url( int $submission_id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'        => self::ACTION,
					'submission_id' => $submission_id,
				),
				admin_url( 'admin-post.php' )
			),
			self::nonce_action( $submission_id )
		);
	}

	/**
	 * Whether the current user may open a submission's certificate.
	 *
	 * @param int $owner_user_id The submission's linked account, 0 when none.
	 */
	public static function current_user_can_open( int $owner_user_id ): bool {
		return PiiAccessPolicy::can_reveal( self::PII_CAP, $owner_user_id > 0 ? $owner_user_id : null );
	}

	/**
	 * Handle the request: check, log, and send the user to the certificate.
	 */
	public function handle(): void {
		$id = RequestInput::get_get_int( 'submission_id' );
		check_admin_referer( self::nonce_action( $id ) );

		$target = $this->resolve( $id );
		if ( '' === $target ) {
			wp_die( esc_html__( 'You do not have permission to open this certificate.', 'ffcertificate' ), '', array( 'response' => 403 ) );
		}

		wp_safe_redirect( $target );
		exit;
	}

	/**
	 * The certificate's magic link for the current user, logging the
	 * opening, or '' when the submission is missing, has no link, or the user
	 * may not reveal it.
	 *
	 * @param int $id Submission ID.
	 */
	public function resolve( int $id ): string {
		$sub = $id > 0 ? $this->submission_handler->get_submission( $id ) : null;
		if ( ! is_array( $sub ) || ! self::current_user_can_open( (int) ( $sub['user_id'] ?? 0 ) ) ) {
			return '';
		}

		$link = \FreeFormCertificate\Generators\MagicLinkHelper::get_submission_magic_link( $id, $this->submission_handler );
		if ( '' === $link ) {
			return '';
		}

		\FreeFormCertificate\Core\ActivityLog::log(
			'submission_pii_revealed',
			\FreeFormCertificate\Core\ActivityLog::LEVEL_INFO,
			array( 'field_key' => 'certificate' ),
			get_current_user_id(),
			$id
		);

		return $link;
	}
}
