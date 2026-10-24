<?php
/**
 * Audience Check-in Endpoint
 *
 * The public URL a check-in QR opens (#1947).
 *
 * @package FreeFormCertificate\Audience
 * @since 6.42.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

use FreeFormCertificate\Core\AssetHelper;
use FreeFormCertificate\Core\AssetRegistry;
use FreeFormCertificate\Core\Button;
use FreeFormCertificate\Frontend\BrowserGate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes `/?ffc_checkin=<code>` and `/?ffc_checkin_r=<receipt>`.
 *
 * - A scanned code is exchanged at once for a receipt and the browser is
 *   redirected to the receipt URL, which also drops the rotating code from
 *   the address bar and the history.
 * - The receipt URL asks a logged-out visitor to log in and come back to it,
 *   shows a logged-in one what they are checking in to, and records the
 *   attendance only on a POST carrying a nonce: a GET never writes, so a
 *   link preview or a prefetch cannot check anybody in.
 *
 * The page is a document of its own (CLAUDE.md "Inline CSS"): it is reached
 * from a phone camera on any theme, so it does not depend on the theme's
 * templates, and it prints the plugin's palette, its own sheet and, when the
 * window asks for a location, its own script.
 *
 * @since 6.42.0
 */
class AudienceCheckinEndpoint {

	/**
	 * Query variable carrying a receipt.
	 *
	 * @var string
	 */
	public const RECEIPT_VAR = 'ffc_checkin_r';

	/**
	 * Nonce action of the confirmation form.
	 *
	 * @var string
	 */
	public const NONCE_ACTION = 'ffc_audience_checkin';

	/**
	 * Register the route.
	 *
	 * `template_redirect` at priority 1: after the query is parsed and the
	 * user is known, before `redirect_canonical()` (priority 10) can rewrite
	 * a home URL carrying a query string, and before any theme template is
	 * chosen.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'template_redirect', array( self::class, 'route' ), 1 );
	}

	/**
	 * The receipt URL a scan redirects to.
	 *
	 * @param string $receipt Receipt.
	 * @return string
	 */
	public static function receipt_url( string $receipt ): string {
		return add_query_arg( self::RECEIPT_VAR, rawurlencode( $receipt ), home_url( '/' ) );
	}

	/**
	 * Entry point: no-ops unless one of the two query variables is present.
	 *
	 * @return void
	 */
	public static function route(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- The code and the receipt are signed tokens verified below; the write path checks its own nonce.
		$code    = isset( $_GET[ AudienceCheckinCode::QUERY_VAR ] ) ? sanitize_text_field( wp_unslash( $_GET[ AudienceCheckinCode::QUERY_VAR ] ) ) : '';
		$receipt = isset( $_GET[ self::RECEIPT_VAR ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::RECEIPT_VAR ] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( '' !== $code ) {
			self::send( self::handle_scan( $code, time() ) );
		}
		if ( '' !== $receipt ) {
			self::send( self::handle_receipt( $receipt, time() ) );
		}
	}

	/**
	 * What a scan answers: a redirect to the receipt, or an error page.
	 *
	 * @param string $code Code.
	 * @param int    $now  Unix time.
	 * @return array{redirect?: string, page?: string, status?: int}
	 */
	public static function handle_scan( string $code, int $now ): array {
		$receipt = AudienceCheckin::scan( $code, $now );
		if ( is_wp_error( $receipt ) ) {
			return self::error_page( $receipt, 403 );
		}

		return array( 'redirect' => self::receipt_url( $receipt ) );
	}

	/**
	 * What a receipt URL answers.
	 *
	 * @param string $receipt Receipt.
	 * @param int    $now     Unix time.
	 * @return array{redirect?: string, page?: string, status?: int}
	 */
	public static function handle_receipt( string $receipt, int $now ): array {
		if ( ! is_user_logged_in() ) {
			return array( 'redirect' => wp_login_url( self::receipt_url( $receipt ) ) );
		}

		$user_id = get_current_user_id();

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Server-set REQUEST_METHOD, compared against the literal 'POST'.
		$is_post = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === strtoupper( (string) wp_unslash( $_SERVER['REQUEST_METHOD'] ) );
		if ( ! $is_post ) {
			$context = AudienceCheckin::context( $receipt, $user_id, $now );
			if ( is_wp_error( $context ) ) {
				return self::error_page( $context, 403 );
			}
			if ( null !== $context['present_at'] ) {
				return self::done_page( $context['booking'], $context['present_at'], true );
			}
			return self::confirm_page( $receipt, $context['booking'], array() !== $context['window']['geofence_locations'] );
		}

		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return self::error_page( new \WP_Error( 'ffc_checkin_nonce', __( 'This page expired. Scan the QR code shown at the event again.', 'ffcertificate' ) ), 403 );
		}

		$position = AudienceCheckin::position(
			isset( $_POST['ffc_lat'] ) ? sanitize_text_field( wp_unslash( $_POST['ffc_lat'] ) ) : null,
			isset( $_POST['ffc_lng'] ) ? sanitize_text_field( wp_unslash( $_POST['ffc_lng'] ) ) : null,
			isset( $_POST['ffc_accuracy'] ) ? sanitize_text_field( wp_unslash( $_POST['ffc_accuracy'] ) ) : null
		);

		$result = AudienceCheckin::confirm( $receipt, $user_id, $position, $now );
		if ( is_wp_error( $result ) ) {
			return self::error_page( $result, 403 );
		}

		return self::done_page( $result['booking'], $result['checked_at'], AudienceAttendanceWriter::ALREADY === $result['status'] );
	}

	/**
	 * The confirmation page.
	 *
	 * @param string $receipt     Receipt.
	 * @param object $booking     Booking row.
	 * @param bool   $needs_place Whether the window asks for a location.
	 * @return array{page: string, status: int, script: bool, booking: int}
	 */
	private static function confirm_page( string $receipt, object $booking, bool $needs_place ): array {
		$form  = '<form method="post" class="ffc-checkin-form" action="' . esc_url( self::receipt_url( $receipt ) ) . '"' . ( $needs_place ? ' data-ffc-checkin-geo="1"' : '' ) . '>';
		$form .= wp_nonce_field( self::NONCE_ACTION, '_wpnonce', false, false );
		$form .= '<input type="hidden" name="ffc_lat" value=""><input type="hidden" name="ffc_lng" value=""><input type="hidden" name="ffc_accuracy" value="">';
		if ( $needs_place ) {
			$form .= '<p class="ffc-checkin-hint ffc-icon-map-pin">' . esc_html__( 'This check-in confirms you are at the event: your browser will ask for your location.', 'ffcertificate' ) . '</p>';
			$form .= '<p class="ffc-checkin-geo-error" role="alert" hidden></p>';
		}
		$form .= Button::html(
			array(
				'label'    => __( 'Confirm my attendance', 'ffcertificate' ),
				'category' => 'primary',
				'icon'     => 'user-check',
				'type'     => 'submit',
				'class'    => 'ffc-btn-block ffc-checkin-submit',
			)
		);
		$form .= '</form>';

		return array(
			'page'    => self::card( __( 'Check in', 'ffcertificate' ), self::summary( $booking ) . $form, 'qr' ),
			'status'  => 200,
			'script'  => $needs_place,
			'booking' => (int) ( $booking->id ?? 0 ),
		);
	}

	/**
	 * The page after a check-in, or when one was already recorded.
	 *
	 * @param object $booking    Booking row.
	 * @param int    $checked_at When it was recorded.
	 * @param bool   $already    Whether it had been recorded before.
	 * @return array{page: string, status: int}
	 */
	private static function done_page( object $booking, int $checked_at, bool $already ): array {
		$time = \FreeFormCertificate\Core\DateFormatter::format_time( $checked_at );
		$body = $already
			/* translators: %s: time the attendance was recorded */
			? sprintf( __( 'Your attendance for today was already recorded at %s.', 'ffcertificate' ), $time )
			/* translators: %s: time the attendance was recorded */
			: sprintf( __( 'Recorded at %s. You can close this page.', 'ffcertificate' ), $time );

		return array(
			'page'   => self::card(
				__( 'Attendance recorded', 'ffcertificate' ),
				self::summary( $booking ) . '<p class="ffc-checkin-message ffc-checkin-success">' . esc_html( $body ) . '</p>',
				'calendar-check'
			),
			'status' => 200,
		);
	}

	/**
	 * A page saying why a check-in cannot go on.
	 *
	 * @param \WP_Error $error  Error.
	 * @param int       $status HTTP status.
	 * @return array{page: string, status: int}
	 */
	private static function error_page( \WP_Error $error, int $status ): array {
		return array(
			'page'   => self::card(
				__( 'Check-in not recorded', 'ffcertificate' ),
				'<p class="ffc-checkin-message ffc-checkin-error" role="alert">' . esc_html( $error->get_error_message() ) . '</p>',
				'warning'
			),
			'status' => $status,
		);
	}

	/**
	 * What the participant is checking in to.
	 *
	 * @param object $booking Booking row.
	 * @return string
	 */
	private static function summary( object $booking ): string {
		$place = (string) ( $booking->environment_name ?? '' );
		$html  = '<p class="ffc-checkin-event">' . esc_html( (string) ( $booking->description ?? '' ) ) . '</p>';
		$html .= '<p class="ffc-checkin-meta ffc-icon-calendar">' . esc_html( \FreeFormCertificate\Core\DateFormatter::format_wallclock_date( AudienceBookingRules::today() ) ) . ( '' !== $place ? ' · ' . esc_html( $place ) : '' ) . '</p>';

		return $html;
	}

	/**
	 * The card every page is drawn in.
	 *
	 * @param string $title Heading.
	 * @param string $inner Pre-escaped inner markup.
	 * @param string $icon  Icon class of the heading.
	 * @return string
	 */
	private static function card( string $title, string $inner, string $icon ): string {
		return '<main class="ffc-checkin-card"><h1 class="ffc-checkin-title ffc-icon-' . esc_attr( $icon ) . '">' . esc_html( $title ) . '</h1>' . $inner . '</main>';
	}

	/**
	 * Send a response and end the request.
	 *
	 * @param array{redirect?: string, page?: string, status?: int, script?: bool, booking?: int} $response Response.
	 * @return void
	 */
	private static function send( array $response ): void {
		if ( isset( $response['redirect'] ) ) {
			wp_safe_redirect( $response['redirect'] );
			exit;
		}

		if ( ! headers_sent() ) {
			nocache_headers();
			status_header( (int) ( $response['status'] ?? 200 ) );
			header( 'Content-Type: text/html; charset=utf-8' );
			header( 'Referrer-Policy: no-referrer' );
		}

		self::enqueue_assets( ! empty( $response['script'] ), (int) ( $response['booking'] ?? 0 ) );

		echo '<!DOCTYPE html><html ' . get_language_attributes() . '><head>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_language_attributes() returns a safe attribute string.
		echo '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow">';
		echo '<title>' . esc_html__( 'Check in', 'ffcertificate' ) . ' — ' . esc_html( get_bloginfo( 'name' ) ) . '</title>';
		wp_print_styles();
		wp_print_head_scripts();
		echo '</head><body class="ffc-shortcode ffc-checkin-page">';
		echo $response['page'] ?? ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts by the page methods above.
		wp_print_footer_scripts();
		echo '</body></html>';

		exit;
	}

	/**
	 * Enqueue what the page prints: the palette and its dark-mode toggle, the
	 * page's sheet and, when a location is needed, the visitor pre-flight
	 * and the page's script.
	 *
	 * The pre-flight runs the `secure` and `browser` steps a certificate form
	 * runs (#2040), and the page adds its own location step: an insecure page
	 * or an app's own browser cannot read the location the check-in needs.
	 * Its stops are logged against the booking.
	 *
	 * @param bool $script     Whether the location script is needed.
	 * @param int  $booking_id Booking the page checks in to; 0 when unknown.
	 * @return void
	 */
	private static function enqueue_assets( bool $script, int $booking_id = 0 ): void {
		$s = AssetHelper::asset_suffix();

		AssetHelper::enqueue_common_style();
		AssetHelper::enqueue_dark_mode();
		wp_enqueue_style( 'ffc-audience-checkin', FFC_PLUGIN_URL . "assets/css/ffc-audience-checkin{$s}.css", array( 'ffc-common' ), FFC_VERSION );

		if ( $script ) {
			AssetRegistry::enqueue_scripts( 'ffc-core', 'ffc-preflight' );
			// The request helper reads its URL and nonce here; the
			// pre-flight's telemetry goes through it.
			wp_localize_script( 'ffc-core', 'ffc_ajax', BrowserGate::request_config() );
			wp_enqueue_script( 'ffc-audience-checkin', FFC_PLUGIN_URL . "assets/js/ffc-audience-checkin{$s}.js", array( 'jquery', 'ffc-core', 'ffc-preflight' ), FFC_VERSION, true );
			wp_localize_script(
				'ffc-audience-checkin',
				'ffcCheckin',
				array_merge(
					array(
						'denied'      => __( 'Your location is needed to check in here. Allow it in your browser settings and try again.', 'ffcertificate' ),
						'unavailable' => __( 'Your location could not be read. Move to an open area and try again.', 'ffcertificate' ),
						'locating'    => __( 'Reading your location…', 'ffcertificate' ),
						'telemetry'   => array(
							'type' => 'booking',
							'id'   => $booking_id,
						),
					),
					BrowserGate::script_config(
						array(
							'secureRequired' => __( 'This check-in needs a secure connection (HTTPS) to read your location. Please tell the event organiser.', 'ffcertificate' ),
							/* translators: %s: a list of browsers, such as "Safari, Chrome, Firefox or Edge". */
							'inAppBody'      => __( 'You are viewing this page inside an app, where your location often cannot be read. Open it in %s.', 'ffcertificate' ),
						)
					)
				)
			);
		}
	}
}
