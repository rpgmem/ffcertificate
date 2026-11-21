<?php
/**
 * AppointmentReceiptOpenHandler
 *
 * Opens an appointment receipt for staff through an audited route (#2732),
 * the way CertificateOpenHandler opens a certificate. The receipt prints the
 * person's name, CPF/RF and e-mail, and its magic link is a bearer token to
 * all of it, so staff reach it only as a reveal: the module's PII capability,
 * under the shared policy, and one activity-log row per opening.
 *
 * It also owns the confirmation token: an appointment booked before tokens
 * existed gets one the first time an allowed reader asks for its receipt.
 *
 * @package FreeFormCertificate\SelfScheduling
 * @since 6.45.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\SelfScheduling;

use FreeFormCertificate\Core\PiiAccessPolicy;
use FreeFormCertificate\Core\RequestInput;
use FreeFormCertificate\Repositories\AppointmentRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The `admin_post_ffc_open_appointment_receipt` handler.
 */
class AppointmentReceiptOpenHandler {

	/**
	 * The `admin_post_*` action.
	 */
	public const ACTION = 'ffc_open_appointment_receipt';

	/**
	 * The module's PII capability, handed to the shared policy.
	 */
	public const PII_CAP = 'ffc_view_appointments_pii';

	/**
	 * Appointment repository.
	 *
	 * @var AppointmentRepository
	 */
	private AppointmentRepository $repository;

	/**
	 * Constructor.
	 *
	 * @param AppointmentRepository|null $repository Appointment repository.
	 */
	public function __construct( ?AppointmentRepository $repository = null ) {
		$this->repository = $repository ?? new AppointmentRepository();
	}

	/**
	 * Register the request hook. `admin_post_*` runs only for a logged-in
	 * user, the only kind that can hold the capability.
	 */
	public function init(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Nonce action for one appointment.
	 *
	 * @param int $appointment_id Appointment ID.
	 */
	public static function nonce_action( int $appointment_id ): string {
		return self::ACTION . '_' . $appointment_id;
	}

	/**
	 * The audited URL that opens one appointment's receipt.
	 *
	 * @param int $appointment_id Appointment ID.
	 */
	public static function url( int $appointment_id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'         => self::ACTION,
					'appointment_id' => $appointment_id,
				),
				admin_url( 'admin-post.php' )
			),
			self::nonce_action( $appointment_id )
		);
	}

	/**
	 * Whether the current user may open an appointment's receipt.
	 *
	 * @param int $owner_user_id The appointment's linked account, 0 when none.
	 */
	public static function current_user_can_open( int $owner_user_id ): bool {
		return PiiAccessPolicy::can_reveal( self::PII_CAP, $owner_user_id > 0 ? $owner_user_id : null );
	}

	/**
	 * Handle the request: check, log, and send the user to the receipt.
	 */
	public function handle(): void {
		$id = RequestInput::get_get_int( 'appointment_id' );
		check_admin_referer( self::nonce_action( $id ) );

		$target = $this->resolve( $id );
		if ( '' === $target ) {
			wp_die( esc_html__( 'You do not have permission to open this receipt.', 'ffcertificate' ), '', array( 'response' => 403 ) );
		}

		wp_safe_redirect( $target );
		exit;
	}

	/**
	 * The receipt's magic link for the current user, logging the opening,
	 * or '' when the appointment is missing or cancelled, or the user may not
	 * reveal it.
	 *
	 * @param int $id Appointment ID.
	 */
	public function resolve( int $id ): string {
		$appointment = $id > 0 ? $this->repository->findById( $id ) : null;
		if ( ! is_array( $appointment ) || 'cancelled' === ( $appointment['status'] ?? '' ) ) {
			return '';
		}
		if ( ! self::current_user_can_open( (int) ( $appointment['user_id'] ?? 0 ) ) ) {
			return '';
		}

		$link = $this->magic_link( $appointment );
		if ( '' === $link ) {
			return '';
		}

		\FreeFormCertificate\Core\ActivityLog::log(
			'appointment_pii_revealed',
			\FreeFormCertificate\Core\ActivityLog::LEVEL_INFO,
			array( 'field_key' => 'receipt' ),
			get_current_user_id(),
			$id
		);

		return $link;
	}

	/**
	 * The appointment's magic link, giving it a confirmation token first
	 * when it was booked before tokens existed. Callers check who may read
	 * it: the link is a bearer token to the whole receipt.
	 *
	 * @param array<string, mixed> $appointment Appointment row.
	 */
	public function magic_link( array $appointment ): string {
		$token = (string) ( $appointment['confirmation_token'] ?? '' );
		if ( '' === $token ) {
			$token  = bin2hex( random_bytes( 32 ) );
			$stored = $this->repository->update( (int) ( $appointment['id'] ?? 0 ), array( 'confirmation_token' => $token ) );
			if ( false === $stored ) {
				return '';
			}
		}

		return \FreeFormCertificate\Generators\MagicLinkHelper::generate_magic_link( $token );
	}
}
