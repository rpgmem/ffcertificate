<?php
/**
 * SendLinkHandler
 *
 * Sends one submission's certificate link e-mail again, on the
 * `admin_post_ffc_send_link` action the "Send link by e-mail" button of the
 * submission edit screen posts to (#1945). The button is a form of its own,
 * associated by the `form` attribute, because the edit form it sits in is
 * one POST that rebuilds the submission from what is posted.
 *
 * The recipient, the token and the data come from the stored row through
 * EmailHandler::resend_link(), so this handler only checks the request and the
 * capability, then reports the outcome.
 *
 * @package FreeFormCertificate\Admin
 * @since 6.45.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

use FreeFormCertificate\Core\Capabilities;
use FreeFormCertificate\Core\RequestInput;
use FreeFormCertificate\Integrations\EmailHandler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the per-submission "Send link by e-mail" request.
 */
final class SendLinkHandler {

	/** Action name the form posts and the request hook is registered under. */
	public const ACTION = 'ffc_send_link';

	/** Capability the bulk action and this button share. */
	public const CAPABILITY = 'ffc_manage_certificates';

	/** Id of the separate form the card's button is associated with. */
	public const FORM_ID = 'ffc-send-link-form';

	/**
	 * Registers the request hook. `admin_post_*` runs only for a logged-in user.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Nonce action that binds the request to one submission.
	 *
	 * @param int $submission_id Submission ID.
	 * @return string
	 */
	public static function nonce_action( int $submission_id ): string {
		return self::ACTION . '_' . $submission_id;
	}

	/**
	 * Whether the current user may send a link.
	 *
	 * @return bool
	 */
	public static function current_user_can_send(): bool {
		return Capabilities::current_user_can_admin_or( self::CAPABILITY );
	}

	/**
	 * Handles the POST, then returns to the edit screen with the outcome.
	 *
	 * @return void
	 */
	public function handle(): void {
		$id = RequestInput::get_post_int( 'submission_id' );
		check_admin_referer( self::nonce_action( $id ) );

		if ( ! self::current_user_can_send() ) {
			wp_die( esc_html__( 'You do not have permission to send these links.', 'ffcertificate' ), '', array( 'response' => 403 ) );
		}

		$result = ( new EmailHandler() )->resend_link( $id );

		if ( true === $result ) {
			$this->redirect( $id, array( 'msg' => 'link_resent' ) );
		}

		$this->redirect(
			$id,
			array(
				'msg'       => 'link_resend_failed',
				'error_msg' => rawurlencode( $result->get_error_message() ),
			)
		);
	}

	/**
	 * Back to the submission's edit screen, with the outcome as a notice.
	 *
	 * @param int                  $id   Submission ID.
	 * @param array<string,string> $args Notice key and optional message.
	 * @return never Always exits after the redirect.
	 */
	private function redirect( int $id, array $args ): never {
		$url = add_query_arg(
			array_merge(
				array(
					'post_type'     => 'ffc_form',
					'page'          => 'ffc-submissions',
					'action'        => 'edit',
					'submission_id' => $id,
				),
				$args
			),
			admin_url( 'edit.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}
}
