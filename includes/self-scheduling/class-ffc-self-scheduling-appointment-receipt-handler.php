<?php
/**
 * Appointment Receipt Handler
 *
 * The receipt of an appointment is its magic link, `/valid/#token=…`, which
 * shows the appointment and builds its PDF (#2732). The standalone page that
 * `?ffc_appointment_receipt=ID` used to draw is gone; the address stays as a
 * redirect, so links in e-mails already sent keep working.
 *
 * @package FreeFormCertificate\SelfScheduling
 * @since 4.1.1
 * @version 6.45.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\SelfScheduling;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Redirects the legacy receipt address and builds receipt links.
 */
class AppointmentReceiptHandler {

	/**
	 * Constructor
	 */
	public function __construct() {
		add_filter( 'query_vars', array( $this, 'add_query_vars' ) );

		// Hooked on template_redirect, which runs before any output, so the
		// redirect can still send its headers.
		add_action( 'template_redirect', array( $this, 'handle_receipt_request' ) );
	}

	/**
	 * Add custom query vars
	 *
	 * @param array<int, string> $vars Vars.
	 * @return array<int, string>
	 */
	public function add_query_vars( array $vars ): array {
		$vars[] = 'ffc_appointment_receipt';
		$vars[] = 'token';
		return $vars;
	}

	/**
	 * Send a legacy receipt request where its reader may go (#2732).
	 *
	 * @return void
	 */
	public function handle_receipt_request(): void {
		if ( ! get_query_var( 'ffc_appointment_receipt' ) ) {
			return;
		}

		$target = $this->resolve_request(
			absint( get_query_var( 'ffc_appointment_receipt' ) ),
			(string) get_query_var( 'token' )
		);

		if ( is_wp_error( $target ) ) {
			wp_die( esc_html( $target->get_error_message() ), '', array( 'response' => (int) ( $target->get_error_data()['status'] ?? 403 ) ) );
		}

		wp_safe_redirect( $target );
		exit;
	}

	/**
	 * Where a legacy receipt request goes.
	 *
	 * The token is the receipt: whoever holds a valid one goes to the magic
	 * link. The logged-in owner goes there too, the appointment getting a
	 * token if it was booked before tokens existed. Staff go to the
	 * appointments list, where the receipt opens through the audited route;
	 * the magic link shows the data unmasked, so it is never handed to them
	 * here. Anyone else is refused, and never learns the token.
	 *
	 * @param int    $appointment_id Appointment ID.
	 * @param string $token          The `token` query var, '' when absent.
	 * @return string|\WP_Error The address to redirect to.
	 */
	public function resolve_request( int $appointment_id, string $token ) {
		if ( ! $appointment_id ) {
			return new \WP_Error( 'ffc_receipt_invalid', __( 'Invalid appointment receipt request.', 'ffcertificate' ), array( 'status' => 400 ) );
		}

		$appointment = ( new \FreeFormCertificate\Repositories\AppointmentRepository() )->findById( $appointment_id );
		if ( ! is_array( $appointment ) ) {
			return new \WP_Error( 'ffc_receipt_not_found', __( 'Appointment not found.', 'ffcertificate' ), array( 'status' => 404 ) );
		}

		$stored = (string) ( $appointment['confirmation_token'] ?? '' );
		if ( '' !== $token && '' !== $stored && hash_equals( $stored, $token ) ) {
			return \FreeFormCertificate\Generators\MagicLinkHelper::generate_magic_link( $stored );
		}

		$owner_id = (int) ( $appointment['user_id'] ?? 0 );
		if ( $owner_id > 0 && is_user_logged_in() && get_current_user_id() === $owner_id ) {
			$link = ( new AppointmentReceiptOpenHandler() )->magic_link( $appointment );
			if ( '' !== $link ) {
				return $link;
			}
		}

		if ( \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_view_appointments' ) ) {
			return admin_url( 'admin.php?page=ffc-appointments' );
		}

		return new \WP_Error( 'ffc_receipt_forbidden', __( 'You do not have permission to view this appointment receipt.', 'ffcertificate' ), array( 'status' => 403 ) );
	}

	/**
	 * The browser step's strings where a receipt, not a certificate, is
	 * being downloaded (#2040). Shared by the receipt page and the booking
	 * confirmation.
	 *
	 * @return array<string, string>
	 */
	public static function preflight_strings(): array {
		return array(
			/* translators: %s: a list of browsers, such as "Safari, Chrome, Firefox or Edge". */
			'inAppBody'            => __( 'You are viewing this page inside an app, where downloading the receipt often fails. Open it in %s.', 'ffcertificate' ),
			/* translators: %s: a list of browsers, such as "Safari, Chrome, Firefox or Edge". */
			'browserFeaturesBody'  => __( 'This browser is missing features needed to build your receipt. Open this page in %s.', 'ffcertificate' ),
			'browserFeaturesTitle' => __( 'This browser cannot build the receipt', 'ffcertificate' ),
		);
	}

	/**
	 * The receipt link for an appointment: its magic link when the token is
	 * known, otherwise the legacy address, which sends its owner there once
	 * logged in (#2732).
	 *
	 * @param int    $appointment_id Appointment ID.
	 * @param string $token          The appointment's confirmation token.
	 * @return string
	 */
	public static function get_receipt_url( int $appointment_id, string $token = '' ): string {
		if ( '' !== $token ) {
			return \FreeFormCertificate\Generators\MagicLinkHelper::generate_magic_link( $token );
		}

		return add_query_arg( 'ffc_appointment_receipt', $appointment_id, home_url() );
	}
}
