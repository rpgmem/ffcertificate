<?php
/**
 * User Messages REST Controller
 *
 * GET /user/messages: what the date-messages module sent the dashboard's
 * owner, and their two message preferences (#2503).
 *
 * @package FreeFormCertificate\API
 * @since   6.44.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\API;

use FreeFormCertificate\DateMessages\OptOut;
use FreeFormCertificate\DateMessages\OptOutCategory;
use FreeFormCertificate\DateMessages\UserMessages;
use FreeFormCertificate\Settings\SettingsReader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Messages tab's data.
 */
class UserMessagesRestController {

	use UserContextTrait;

	/**
	 * API namespace.
	 *
	 * @var non-falsy-string
	 */
	private string $namespace;

	/**
	 * Constructor.
	 *
	 * @param non-falsy-string $namespace API namespace.
	 */
	public function __construct( string $namespace ) {
		$this->namespace = $namespace;
	}

	/**
	 * Register the route.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/user/messages',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_user_messages' ),
				'permission_callback' => array( $this, 'rest_permission' ),
			)
		);
	}

	/**
	 * The owner's messages, current and earlier, and their preferences.
	 *
	 * Another account reading it (view as user) sees the person's e-mail and
	 * the source's values masked, as every other tab shows them.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_user_messages( $request ) {
		if ( ! SettingsReader::module_enabled( 'date_messages' ) ) {
			return new \WP_Error( 'ffc_module_disabled', __( 'Messages are not available.', 'ffcertificate' ), array( 'status' => 404 ) );
		}

		$ctx     = $this->resolve_user_context( $request );
		$user_id = $ctx['user_id'];
		if ( $user_id <= 0 ) {
			return new \WP_Error( 'not_logged_in', __( 'You must be logged in', 'ffcertificate' ), array( 'status' => 401 ) );
		}

		$messages = UserMessages::for_user( $user_id, $ctx['is_view_as'], time() );

		return rest_ensure_response(
			array(
				'current'     => $messages['current'],
				'earlier'     => $messages['earlier'],
				'noticeDays'  => UserMessages::notice_days(),
				'preferences' => array(
					'notify_date_messages'      => ! isset( OptOut::among( array( $user_id ), OptOutCategory::PERSONAL )[ $user_id ] ),
					'notify_date_announcements' => ! isset( OptOut::among( array( $user_id ), OptOutCategory::ANNOUNCEMENT )[ $user_id ] ),
				),
			)
		);
	}
}
