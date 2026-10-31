<?php
/**
 * User Profile REST Controller
 *
 * Handles:
 *   GET  /user/profile          – Current user's profile
 *   PUT  /user/profile          – Update current user's profile
 *   POST /user/change-password  – Change password
 *   POST /user/privacy-request  – Create GDPR/LGPD privacy request
 *
 * @package FreeFormCertificate\API
 * @since 4.12.7  Extracted from UserDataRestController
 */

declare(strict_types=1);

namespace FreeFormCertificate\API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API controller for user profile endpoints.
 */
class UserProfileRestController {

	use UserContextTrait;
	use \FreeFormCertificate\Core\DatabaseHelperTrait;

	/**
	 * The profile's reveal capability (#1863): the audience module's, as on
	 * the WordPress user-edit screen, whose sensitive fields are the same
	 * profile data.
	 */
	private const PII_CAP = 'ffc_view_audiences_pii';

	/**
	 * API namespace
	 *
	 * @var non-falsy-string
	 */
	private string $namespace;

	/**
	 * Constructor.
	 *
	 * @param non-falsy-string $namespace Namespace.
	 */
	public function __construct( string $namespace ) {
		$this->namespace = $namespace;
	}

	/**
	 * Register routes
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/user/profile',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_user_profile' ),
					'permission_callback' => array( $this, 'rest_permission' ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'update_user_profile' ),
					'permission_callback' => array( $this, 'rest_permission' ),
				),
			)
		);

		// Reveal one masked field of the profile: the owner's own, unaudited
		// (#1897), or the one an administrator is viewing as its owner,
		// audited (#1863).
		register_rest_route(
			$this->namespace,
			'/user/profile/reveal',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'reveal_profile_field' ),
				'permission_callback' => array( $this, 'rest_permission' ),
				'args'                => array(
					'field' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/user/change-password',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'change_password' ),
				'permission_callback' => array( $this, 'rest_permission' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/user/privacy-request',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_privacy_request' ),
				'permission_callback' => array( $this, 'rest_permission' ),
			)
		);
	}

	/**
	 * GET /user/profile
	 *
	 * @since 3.1.0
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_user_profile( $request ) {
		try {
			global $wpdb;
			$ctx     = $this->resolve_user_context( $request );
			$user_id = $ctx['user_id'];

			if ( ! $user_id ) {
				return new \WP_Error(
					'not_logged_in',
					__( 'You must be logged in to view profile', 'ffcertificate' ),
					array( 'status' => 401 )
				);
			}

			if ( ! class_exists( '\FreeFormCertificate\UserDashboard\UserManager' ) ) {
				$user_manager_file = FFC_PLUGIN_DIR . 'includes/user-dashboard/class-ffc-user-manager.php';
				if ( file_exists( $user_manager_file ) ) {
					require_once $user_manager_file;
				}
			}

			// User existence + wp_users ↔ ffc_user_profiles merge are now
			// owned by UserService (#322). Returns null when the user
			// doesn't resolve so the 404 path stays one-shot.
			$full_profile = \FreeFormCertificate\Services\UserService::get_full_profile( $user_id );
			if ( null === $full_profile ) {
				return new \WP_Error(
					'user_not_found',
					__( 'User not found', 'ffcertificate' ),
					array( 'status' => 404 )
				);
			}

			$cpfs_masked = array();
			$identifiers = array(
				'cpfs' => array(),
				'rfs'  => array(),
			);
			if ( class_exists( '\FreeFormCertificate\UserDashboard\UserManager' ) ) {
				$cpfs_masked = \FreeFormCertificate\UserDashboard\UserManager::get_user_cpfs_masked( $user_id );
				$identifiers = \FreeFormCertificate\UserDashboard\UserManager::get_user_identifiers_masked( $user_id );
			}

			$emails = array();
			if ( class_exists( '\FreeFormCertificate\UserDashboard\UserManager' ) ) {
				$emails = \FreeFormCertificate\UserDashboard\UserManager::get_user_emails( $user_id );
			}

			$names = array();
			if ( class_exists( '\FreeFormCertificate\UserDashboard\UserManager' ) ) {
				$names = \FreeFormCertificate\UserDashboard\UserManager::get_user_names( $user_id );
			}

			$member_since = '';
			if ( ! empty( $full_profile['member_since'] ) ) {
				$member_since = \FreeFormCertificate\Core\DateFormatter::format_date( (string) $full_profile['member_since'] );
			}

			$audience_groups = array();
			$members_table   = $wpdb->prefix . 'ffc_audience_members';

			if ( self::table_exists( $members_table ) ) {
				// Each group's tag text colour is computed over its own colour
				// here, not left to the script (#1826).
				$audience_groups = \FreeFormCertificate\Core\BadgeHtml::with_row_colors(
					\FreeFormCertificate\Audience\AudienceReader::get_user_audience_badges( $user_id )
				);
			}

			// Decode preferences JSON. UserService surfaces the raw blob
			// in `$full_profile['preferences']` when the row carries one.
			$preferences = array();
			if ( ! empty( $full_profile['preferences'] ) ) {
				$decoded = json_decode( (string) $full_profile['preferences'], true );
				if ( is_array( $decoded ) ) {
					$preferences = $decoded;
				}
			}

			// The canonical birth date (#1538), decrypted for its owner by
			// UserService, which already merged the rest of the profile.
			$birth_date = (string) ( $full_profile['birth_date'] ?? '' );

			$email = (string) ( $full_profile['email'] ?? '' );
			$phone = (string) ( $full_profile['phone'] ?? '' );

			// The email addresses, phone, birth date and identifiers arrive
			// masked for everyone, and each one is revealed on request through
			// /user/profile/reveal: by the owner without an audit row, so a
			// shared screen does not show them (#1897), and in "view as user"
			// by the administrator, audited (#1863, #1888).
			$email         = \FreeFormCertificate\Core\DocumentFormatter::mask_email( $email );
			$emails        = array_map( array( \FreeFormCertificate\Core\DocumentFormatter::class, 'mask_email' ), $emails );
			$phone         = \FreeFormCertificate\Core\DocumentFormatter::mask_phone( $phone );
			$birth_display = \FreeFormCertificate\Core\DocumentFormatter::mask_birth_date( $birth_date );
			$birth_date    = '';
			$can_reveal    = \FreeFormCertificate\Core\PiiAccessPolicy::TIER_MASKED !== \FreeFormCertificate\Core\PiiAccessPolicy::resolve_on_screen( self::PII_CAP, $user_id );

			return rest_ensure_response(
				array(
					'user_id'            => $user_id,
					'display_name'       => (string) ( $full_profile['display_name'] ?? '' ),
					'names'              => $names,
					'email'              => $email,
					'emails'             => $emails,
					'cpf_masked'         => ! empty( $cpfs_masked ) ? $cpfs_masked[0] : __( 'Not found', 'ffcertificate' ),
					'cpfs_masked'        => $cpfs_masked,
					'identifiers'        => $identifiers,
					'phone'              => $phone,
					'department'         => (string) ( $full_profile['department'] ?? '' ),
					'organization'       => (string) ( $full_profile['organization'] ?? '' ),
					'notes'              => (string) ( $full_profile['notes'] ?? '' ),
					'birth_date'         => $birth_date,
					'birth_date_display' => $birth_display,
					'pii_masked'         => true,
					'can_reveal'         => $can_reveal,
					'preferences'        => $preferences,
					'member_since'       => $member_since,
					'roles'              => $full_profile['roles'] ?? array(),
					'audience_groups'    => $audience_groups,
				)
			);

		} catch ( \Exception $e ) {
			if ( class_exists( '\FreeFormCertificate\Core\Utils' ) ) {
				\FreeFormCertificate\Core\Debug::log_rest_api(
					'get_user_profile error',
					array(
						'message' => $e->getMessage(),
						'file'    => $e->getFile(),
						'line'    => $e->getLine(),
					)
				);
			}
			return new \WP_Error(
				'get_profile_error',
				__( 'An unexpected error occurred.', 'ffcertificate' ),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * PUT /user/profile
	 *
	 * Allows the logged-in user to update their own profile fields:
	 * display_name, phone, department, organization, notes, birth_date.
	 *
	 * @since 4.9.6
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_user_profile( $request ) {
		try {
			$ctx     = $this->resolve_user_context( $request );
			$user_id = $ctx['user_id'];

			if ( ! $user_id ) {
				return new \WP_Error(
					'not_logged_in',
					__( 'You must be logged in to update profile', 'ffcertificate' ),
					array( 'status' => 401 )
				);
			}

			if ( ! class_exists( '\FreeFormCertificate\UserDashboard\UserManager' ) ) {
				return new \WP_Error(
					'missing_class',
					__( 'UserManager class not found', 'ffcertificate' ),
					array( 'status' => 500 )
				);
			}

			$data           = array();
			$allowed_fields = array( 'display_name', 'phone', 'department', 'organization', 'notes' );

			foreach ( $allowed_fields as $field ) {
				$value = $request->get_param( $field );
				if ( null !== $value ) {
					$data[ $field ] = \sanitize_text_field( \wp_unslash( $value ) );
				}
			}

			// The phone arrives masked until revealed (#1863, #1897); a masked
			// value posted back is the mask, not a new number, and never
			// overwrites the stored one.
			if ( isset( $data['phone'] ) && false !== strpos( $data['phone'], '*' ) ) {
				unset( $data['phone'] );
			}

			// Handle preferences (JSON object).
			$preferences = $request->get_param( 'preferences' );
			if ( null !== $preferences && is_array( $preferences ) ) {
				$data['preferences'] = $preferences;
			}

			// Birth date (#1538): validated here, at the boundary, so a value
			// that is not a believable birth date is refused with a reason
			// instead of being stored and mailed. An empty string clears it.
			$birth_date = self::parse_birth_date( $request->get_param( 'birth_date' ) );
			if ( is_wp_error( $birth_date ) ) {
				return $birth_date;
			}

			if ( empty( $data ) && null === $birth_date ) {
				return new \WP_Error(
					'no_data',
					__( 'No profile data provided', 'ffcertificate' ),
					array( 'status' => 400 )
				);
			}

			$result = true;
			if ( ! empty( $data ) ) {
				$result = \FreeFormCertificate\UserDashboard\UserManager::update_profile( $user_id, $data );
			}

			if ( null !== $birth_date ) {
				$result             = \FreeFormCertificate\UserDashboard\UserManager::update_extended_profile( $user_id, array( 'birth_date' => $birth_date ) ) && $result;
				$data['birth_date'] = true;
			}

			if ( ! $result ) {
				return new \WP_Error(
					'update_failed',
					__( 'Failed to update profile', 'ffcertificate' ),
					array( 'status' => 500 )
				);
			}

			// Log profile update.
			if ( class_exists( '\FreeFormCertificate\Core\ActivityLog' ) ) {
				\FreeFormCertificate\Core\ActivityLog::log_profile_updated( $user_id, array_keys( $data ) );
			}

			// Return updated profile.
			return $this->get_user_profile( $request );

		} catch ( \Exception $e ) {
			if ( class_exists( '\FreeFormCertificate\Core\Utils' ) ) {
				\FreeFormCertificate\Core\Debug::log_rest_api(
					'update_user_profile error',
					array(
						'message' => $e->getMessage(),
						'file'    => $e->getFile(),
						'line'    => $e->getLine(),
					)
				);
			}
			return new \WP_Error(
				'update_profile_error',
				__( 'An unexpected error occurred.', 'ffcertificate' ),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * POST /user/profile/reveal — one masked field of a dashboard profile.
	 *
	 * Two paths. The owner reveals their own profile (#1897): the account is
	 * always the current user, never one the request names, and no audit row
	 * is written, because nobody else's data is disclosed. In "view as user"
	 * the administrator reveals the viewed account when the PII policy lets
	 * them, and every served value writes an audit row naming the field and
	 * the account (#1863).
	 *
	 * @since 6.40.0
	 * @since 6.41.0 The owner path (#1897).
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function reveal_profile_field( $request ) {
		$ctx     = $this->resolve_user_context( $request );
		$view_as = $ctx['is_view_as'];
		$user_id = $view_as ? $ctx['user_id'] : get_current_user_id();
		if ( $user_id <= 0 ) {
			return new \WP_Error( 'not_logged_in', __( 'You must be logged in', 'ffcertificate' ), array( 'status' => 401 ) );
		}

		// Someone who may not view as another user asked for one: refused,
		// rather than answered with their own data as if they had not.
		if ( ! $view_as && null !== $request->get_param( 'viewAsUserId' ) && absint( $request->get_param( 'viewAsUserId' ) ) !== $user_id ) {
			return new \WP_Error( 'ffc_reveal_denied', __( 'You do not have permission to reveal this field.', 'ffcertificate' ), array( 'status' => 403 ) );
		}

		if ( \FreeFormCertificate\Core\PiiAccessPolicy::TIER_MASKED === \FreeFormCertificate\Core\PiiAccessPolicy::resolve_on_screen( self::PII_CAP, $user_id ) ) {
			return new \WP_Error( 'ffc_reveal_denied', __( 'You do not have permission to reveal this field.', 'ffcertificate' ), array( 'status' => 403 ) );
		}

		$field = (string) $request->get_param( 'field' );
		if ( ! in_array( $field, array( 'email', 'phone', 'birth_date', 'cpf_rf' ), true ) ) {
			return new \WP_Error( 'ffc_reveal_unsupported', __( 'Unsupported field.', 'ffcertificate' ), array( 'status' => 400 ) );
		}

		$full_profile = \FreeFormCertificate\Services\UserService::get_full_profile( $user_id );
		if ( null === $full_profile ) {
			return new \WP_Error( 'user_not_found', __( 'User not found', 'ffcertificate' ), array( 'status' => 404 ) );
		}

		$response = array( 'field' => $field );
		switch ( $field ) {
			case 'email':
				$response['value']  = (string) ( $full_profile['email'] ?? '' );
				$response['values'] = class_exists( '\FreeFormCertificate\UserDashboard\UserManager' )
					? \FreeFormCertificate\UserDashboard\UserManager::get_user_emails( $user_id )
					: array();
				break;
			case 'phone':
				$response['value'] = (string) ( $full_profile['phone'] ?? '' );
				break;
			case 'cpf_rf':
				// The same rows the masked list reads, formatted instead of
				// masked (#1888).
				$response['values'] = \FreeFormCertificate\Services\UserIdentifiersQueryService::get_cpfs_formatted_for_user( $user_id );
				$response['value']  = $response['values'][0] ?? '';
				break;
			default:
				$birth_date          = (string) ( $full_profile['birth_date'] ?? '' );
				$response['value']   = $birth_date;
				$response['display'] = '' !== $birth_date ? \FreeFormCertificate\Core\DateFormatter::format_wallclock_date( $birth_date ) : '';
		}

		if ( ! $view_as ) {
			return rest_ensure_response( $response );
		}

		\FreeFormCertificate\Core\ActivityLog::log(
			'user_profile_pii_revealed',
			\FreeFormCertificate\Core\ActivityLog::LEVEL_INFO,
			array(
				'field_key'       => $field,
				'view_as'         => true,
				'subject_user_id' => $user_id,
			),
			get_current_user_id(),
			0
		);

		return rest_ensure_response( $response );
	}

	/**
	 * Validate the `birth_date` parameter of a profile update.
	 *
	 * Null means the parameter was not sent, so the stored value is left
	 * alone; an empty string means "clear it"; anything else must be a real,
	 * past date implying an age between `BirthDate::MIN_AGE` and `MAX_AGE`,
	 * measured on today's date in the site timezone.
	 *
	 * @since 6.33.0
	 * @param mixed $raw Raw parameter value.
	 * @return string|null|\WP_Error Canonical `Y-m-d`, '' to clear, null when absent.
	 */
	private static function parse_birth_date( $raw ) {
		if ( null === $raw ) {
			return null;
		}

		$value = is_scalar( $raw ) ? trim( \sanitize_text_field( \wp_unslash( (string) $raw ) ) ) : '';
		if ( '' === $value ) {
			return '';
		}

		$today = new \DateTimeImmutable( 'now', wp_timezone() );
		if ( ! \FreeFormCertificate\Core\BirthDate::is_plausible( $value, $today ) ) {
			return new \WP_Error(
				'invalid_birth_date',
				sprintf(
					/* translators: 1: youngest accepted age, 2: oldest accepted age. */
					__( 'Enter a valid birth date: a past date for an age between %1$d and %2$d years.', 'ffcertificate' ),
					\FreeFormCertificate\Core\BirthDate::MIN_AGE,
					\FreeFormCertificate\Core\BirthDate::MAX_AGE
				),
				array( 'status' => 400 )
			);
		}

		return (string) \FreeFormCertificate\Core\BirthDate::normalize( $value );
	}

	/**
	 * POST /user/change-password
	 *
	 * @since 4.9.8
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function change_password( $request ) {
		try {
			$ctx     = $this->resolve_user_context( $request );
			$user_id = $ctx['user_id'];

			if ( ! $user_id ) {
				return new \WP_Error( 'not_logged_in', __( 'You must be logged in', 'ffcertificate' ), array( 'status' => 401 ) );
			}

			// No password changes while viewing as another account (#2246): it
			// skipped the current password and the log named the target as the
			// actor. The dashboard does not render the form there either.
			if ( $ctx['is_view_as'] || '' !== (string) $request->get_param( 'viewAsUserId' ) ) {
				return new \WP_Error( 'ffc_view_as_password', __( 'The password cannot be changed while viewing as another account.', 'ffcertificate' ), array( 'status' => 403 ) );
			}

			// Rate limit: 3/hour, 5/day for password changes.
			if ( class_exists( '\FreeFormCertificate\Security\RateLimiter' ) ) {
				$rate_check = \FreeFormCertificate\Security\RateLimiter::check_user_limit( $user_id, 'password_change', 3, 5 );
				if ( ! $rate_check['allowed'] ) {
					return new \WP_Error( 'rate_limited', $rate_check['message'], array( 'status' => 429 ) );
				}
			}

			$current_password = $request->get_param( 'current_password' );
			$new_password     = $request->get_param( 'new_password' );

			if ( empty( $new_password ) ) {
				return new \WP_Error( 'missing_fields', __( 'All password fields are required', 'ffcertificate' ), array( 'status' => 400 ) );
			}

			if ( strlen( $new_password ) < 8 ) {
				return new \WP_Error( 'password_too_short', __( 'Password must be at least 8 characters', 'ffcertificate' ), array( 'status' => 400 ) );
			}

			$user = get_user_by( 'id', $user_id );
			if ( ! $user ) {
				return new \WP_Error( 'user_not_found', __( 'User not found', 'ffcertificate' ), array( 'status' => 404 ) );
			}

			if ( empty( $current_password ) ) {
				return new \WP_Error( 'missing_fields', __( 'All password fields are required', 'ffcertificate' ), array( 'status' => 400 ) );
			}
			if ( ! wp_check_password( $current_password, $user->user_pass, $user_id ) ) {
				return new \WP_Error( 'wrong_password', __( 'Current password is incorrect', 'ffcertificate' ), array( 'status' => 403 ) );
			}

			wp_set_password( $new_password, $user_id );

			// wp_set_password() ends every session; keep this one signed in.
			wp_set_current_user( $user_id );
			wp_set_auth_cookie( $user_id, true );

			// Log password change.
			if ( class_exists( '\FreeFormCertificate\Core\ActivityLog' ) ) {
				\FreeFormCertificate\Core\ActivityLog::log_password_changed( $user_id );
			}

			return rest_ensure_response(
				array(
					'success' => true,
					'message' => __( 'Password changed successfully!', 'ffcertificate' ),
				)
			);

		} catch ( \Exception $e ) {
			return new \WP_Error( 'password_error', __( 'Error changing password', 'ffcertificate' ), array( 'status' => 500 ) );
		}
	}

	/**
	 * POST /user/privacy-request
	 *
	 * Creates a WordPress privacy request (export or erasure) and sends the
	 * confirmation e-mail. WordPress does not act on a request until the
	 * person confirms it from that e-mail, so a request created without it
	 * sat as "pending confirmation" forever while the screen said it had been
	 * sent (#2246). Erasure is still reviewed by an administrator afterwards.
	 *
	 * @since 4.9.8
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_privacy_request( $request ) {
		try {
			$ctx     = $this->resolve_user_context( $request );
			$user_id = $ctx['user_id'];

			if ( ! $user_id ) {
				return new \WP_Error( 'not_logged_in', __( 'You must be logged in', 'ffcertificate' ), array( 'status' => 401 ) );
			}

			// Rate limit: 2/hour, 3/day for privacy requests.
			if ( class_exists( '\FreeFormCertificate\Security\RateLimiter' ) ) {
				$rate_check = \FreeFormCertificate\Security\RateLimiter::check_user_limit( $user_id, 'privacy_request', 2, 3 );
				if ( ! $rate_check['allowed'] ) {
					return new \WP_Error( 'rate_limited', $rate_check['message'], array( 'status' => 429 ) );
				}
			}

			$type = $request->get_param( 'type' );
			if ( ! in_array( $type, array( 'export_personal_data', 'remove_personal_data' ), true ) ) {
				return new \WP_Error( 'invalid_type', __( 'Invalid request type', 'ffcertificate' ), array( 'status' => 400 ) );
			}

			$user = get_user_by( 'id', $user_id );
			if ( ! $user ) {
				return new \WP_Error( 'user_not_found', __( 'User not found', 'ffcertificate' ), array( 'status' => 404 ) );
			}
			$result = wp_create_user_request( $user->user_email, $type );

			if ( is_wp_error( $result ) ) {
				return new \WP_Error(
					'privacy_request_error',
					$result->get_error_message(),
					array( 'status' => 400 )
				);
			}

			// Log privacy request.
			if ( class_exists( '\FreeFormCertificate\Core\ActivityLog' ) ) {
				\FreeFormCertificate\Core\ActivityLog::log_privacy_request( $user_id, $type );
			}

			// The request exists from here on; WordPress lists it under Tools
			// whether or not the e-mail goes out, so a failed send is reported
			// as such rather than as a request that was never made.
			$sent = wp_send_user_request( (int) $result );
			if ( is_wp_error( $sent ) ) {
				return new \WP_Error(
					'privacy_request_email_failed',
					__( 'Your request was recorded, but the confirmation e-mail could not be sent. Please contact the site administrator.', 'ffcertificate' ),
					array( 'status' => 500 )
				);
			}

			$admin_hint = '';
			if ( current_user_can( 'manage_options' ) ) {
				$admin_hint = ' ' . sprintf(
					/* translators: %s: admin menu path, e.g. "Erase Personal Data" */
					__( 'Review it under Tools > %s in the WordPress admin.', 'ffcertificate' ),
					( 'export_personal_data' === $type )
						? __( 'Export Personal Data', 'ffcertificate' )
						: __( 'Erase Personal Data', 'ffcertificate' )
				);
			}

			return rest_ensure_response(
				array(
					'success' => true,
					'message' => __( 'Check your e-mail to confirm the request. Nothing happens until you confirm it.', 'ffcertificate' ) . $admin_hint,
				)
			);

		} catch ( \Exception $e ) {
			return new \WP_Error( 'privacy_error', __( 'Error processing privacy request', 'ffcertificate' ), array( 'status' => 500 ) );
		}
	}
}
