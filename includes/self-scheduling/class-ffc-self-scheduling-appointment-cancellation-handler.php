<?php
/**
 * Appointment Cancellation Handler
 *
 * Public, login-free cancellation page reached from the cancellation link in
 * the appointment e-mails (#Item9, resolving the Item 6 `get_cancellation_url`
 * debt). The link carries the appointment id + its `confirmation_token`; this
 * handler renders a confirmation page and, on POST, delegates to
 * {@see AppointmentHandler::cancel_appointment()} — which re-validates the
 * token via hash_equals and enforces every calendar rule (cancellation
 * disabled, deadline, already-cancelled). No account required.
 *
 * Mirrors the AppointmentReceiptHandler pattern: a registered public query
 * var (`ffc_cancel_appointment`) + a `template_redirect` interceptor that
 * renders a self-contained page and exits, so it works on any permalink
 * configuration without rewrite rules.
 *
 * @package FreeFormCertificate\SelfScheduling
 * @since   6.10.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\SelfScheduling;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders + processes the token-based public cancellation page.
 */
class AppointmentCancellationHandler {

	/**
	 * Query var carrying the appointment id on the cancellation URL.
	 */
	public const QUERY_VAR = 'ffc_cancel_appointment';

	/**
	 * Nonce action for the confirm POST. The token is the real credential;
	 * the nonce only blocks trivial cross-site POSTs against the endpoint.
	 */
	private const NONCE_ACTION = 'ffc_cancel_appointment';

	/**
	 * Business-logic handler that owns cancel_appointment().
	 *
	 * @var AppointmentHandler
	 */
	private AppointmentHandler $handler;

	/**
	 * Constructor — registers the query var + template_redirect interceptor.
	 *
	 * @param AppointmentHandler $handler Appointment business-logic handler.
	 */
	public function __construct( AppointmentHandler $handler ) {
		$this->handler = $handler;
		add_filter( 'query_vars', array( $this, 'add_query_vars' ) );
		add_action( 'template_redirect', array( $this, 'handle_cancellation_request' ) );
	}

	/**
	 * Register the public query vars this handler reads.
	 *
	 * @param array<int, string> $vars Existing query vars.
	 * @return array<int, string>
	 */
	public function add_query_vars( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		// `token` may already be registered by the receipt handler; WP keeps
		// the duplicate harmlessly, and registering it here keeps this
		// handler self-contained.
		$vars[] = 'token';
		return $vars;
	}

	/**
	 * Build the public cancellation URL for an appointment.
	 *
	 * @param int    $appointment_id Appointment id.
	 * @param string $token          Confirmation token (guest credential).
	 * @return string
	 */
	public static function get_cancellation_url( int $appointment_id, string $token = '' ): string {
		$url = add_query_arg( self::QUERY_VAR, $appointment_id, home_url() );
		if ( '' !== $token ) {
			// add_query_arg() URL-encodes values itself — pass the token raw
			// (mirrors AppointmentReceiptHandler::get_receipt_url) so it isn't
			// double-encoded.
			$url = add_query_arg( 'token', $token, $url );
		}
		return $url;
	}

	/**
	 * `template_redirect` entry point. No-ops unless our query var is present.
	 *
	 * @return void
	 */
	public function handle_cancellation_request(): void {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}

		$appointment_id = absint( get_query_var( self::QUERY_VAR ) );
		$token          = (string) get_query_var( 'token' );

		$appointment = null;
		if ( $appointment_id ) {
			$appointment_repo = new \FreeFormCertificate\Repositories\AppointmentRepository();
			$appointment      = $appointment_repo->findById( $appointment_id );
		}

		$is_post = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === strtoupper( (string) wp_unslash( $_SERVER['REQUEST_METHOD'] ) );
		$outcome = self::classify_request( $appointment_id, is_array( $appointment ) ? $appointment : null, $token, $is_post, $this->confirm_submitted() );

		switch ( $outcome ) {
			case 'invalid_link':
				$this->render_error( __( 'Invalid cancellation link.', 'ffcertificate' ) );
				return;

			case 'invalid_token':
				// Generic message whether the appointment is missing or the
				// token is wrong — don't leak which appointment ids exist to
				// an unauthenticated caller poking the endpoint.
				$this->render_error( __( 'This cancellation link is invalid or has expired.', 'ffcertificate' ) );
				return;

			case 'already_cancelled':
				$this->render_notice(
					__( 'Already cancelled', 'ffcertificate' ),
					__( 'This appointment has already been cancelled. No further action is needed.', 'ffcertificate' )
				);
				return;

			case 'process':
				$this->process_cancellation( $appointment_id, $token );
				return;

			case 'confirm':
			default:
				$calendar = array();
				if ( is_array( $appointment ) && ! empty( $appointment['calendar_id'] ) ) {
					$calendar_repo = new \FreeFormCertificate\Repositories\CalendarRepository();
					$found         = $calendar_repo->findById( (int) $appointment['calendar_id'] );
					$calendar      = is_array( $found ) ? $found : array();
				}
				$this->render_confirm_form( is_array( $appointment ) ? $appointment : array(), $calendar );
				return;
		}
	}

	/**
	 * Pure branch decision for the cancellation request — which page to
	 * render. Extracted so the gating logic (id present, token match,
	 * already-cancelled short-circuit, confirm-vs-process) is unit-testable
	 * without the render methods, which end the request with exit().
	 *
	 * @param int                       $appointment_id Parsed appointment id (0 when absent/invalid).
	 * @param array<string, mixed>|null $appointment    Loaded appointment row, or null.
	 * @param string                    $token          Token from the URL.
	 * @param bool                      $is_post        Whether the request is a POST.
	 * @param bool                      $confirm_ok     Whether a valid confirm POST (nonce ok) was submitted.
	 * @return string One of: invalid_link, invalid_token, already_cancelled, process, confirm.
	 */
	public static function classify_request( int $appointment_id, ?array $appointment, string $token, bool $is_post, bool $confirm_ok ): string {
		if ( ! $appointment_id ) {
			return 'invalid_link';
		}
		if ( null === $appointment || ! self::token_matches( $appointment, $token ) ) {
			return 'invalid_token';
		}
		if ( 'cancelled' === ( $appointment['status'] ?? '' ) ) {
			return 'already_cancelled';
		}
		if ( $is_post && $confirm_ok ) {
			return 'process';
		}
		return 'confirm';
	}

	/**
	 * Constant-time token comparison against the appointment's stored token.
	 *
	 * @param array<string, mixed> $appointment Appointment row.
	 * @param string               $token       Token from the URL.
	 * @return bool
	 */
	private static function token_matches( array $appointment, string $token ): bool {
		$stored = $appointment['confirmation_token'] ?? '';
		if ( '' === $token || ! is_string( $stored ) || '' === $stored ) {
			return false;
		}
		return hash_equals( $stored, $token );
	}

	/**
	 * Was a valid confirm POST submitted (nonce present + verified)?
	 *
	 * @return bool
	 */
	private function confirm_submitted(): bool {
		if ( ! isset( $_POST['ffc_cancel_confirm'], $_POST['_wpnonce'] ) ) {
			return false;
		}
		$nonce = sanitize_text_field( (string) wp_unslash( $_POST['_wpnonce'] ) );
		return (bool) wp_verify_nonce( $nonce, self::NONCE_ACTION );
	}

	/**
	 * Run the cancellation and render the outcome.
	 *
	 * @param int    $appointment_id Appointment id.
	 * @param string $token          Confirmation token (re-validated downstream).
	 * @return void
	 */
	private function process_cancellation( int $appointment_id, string $token ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in confirm_submitted() before this method is reached; the token is the primary credential and is re-checked in cancel_appointment().
		$reason = isset( $_POST['ffc_cancel_reason'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see above; nonce already verified.
			? sanitize_textarea_field( (string) wp_unslash( $_POST['ffc_cancel_reason'] ) )
			: '';

		$result = $this->handler->cancel_appointment( $appointment_id, $token, $reason );

		if ( is_wp_error( $result ) ) {
			$this->render_notice(
				__( 'Could not cancel', 'ffcertificate' ),
				$result->get_error_message()
			);
			return;
		}

		$this->render_notice(
			__( 'Appointment cancelled', 'ffcertificate' ),
			__( 'Your appointment has been cancelled. A confirmation e-mail is on its way.', 'ffcertificate' )
		);
	}

	/**
	 * Render the confirmation form (GET, or POST without a valid nonce).
	 *
	 * @param array<string, mixed> $appointment Appointment row.
	 * @param array<string, mixed> $calendar    Calendar row (may be empty).
	 * @return void
	 */
	private function render_confirm_form( array $appointment, array $calendar ): void {
		$date          = (string) ( $appointment['appointment_date'] ?? '' );
		$start         = (string) ( $appointment['start_time'] ?? '' );
		$calendar_name = (string) ( $calendar['title'] ?? __( 'Appointment', 'ffcertificate' ) );

		$when = '';
		if ( '' !== $date ) {
			$when = \FreeFormCertificate\Core\DateFormatter::format_wallclock_date( $date );
			if ( '' !== $start ) {
				$when .= ' · ' . \FreeFormCertificate\Core\DateFormatter::format_wallclock_time( $start );
			}
		}

		ob_start();
		?>
		<h1><?php esc_html_e( 'Cancel appointment', 'ffcertificate' ); ?></h1>
		<p><?php esc_html_e( 'Please confirm that you want to cancel the following appointment.', 'ffcertificate' ); ?></p>
		<dl class="ffc-cancel-summary">
			<dt><?php esc_html_e( 'Service', 'ffcertificate' ); ?></dt>
			<dd><?php echo esc_html( $calendar_name ); ?></dd>
			<?php if ( '' !== $when ) : ?>
				<dt><?php esc_html_e( 'When', 'ffcertificate' ); ?></dt>
				<dd><?php echo esc_html( $when ); ?></dd>
			<?php endif; ?>
		</dl>
		<form method="post" class="ffc-cancel-form">
			<?php wp_nonce_field( self::NONCE_ACTION ); ?>
			<p>
				<label for="ffc-cancel-reason"><?php esc_html_e( 'Reason (optional)', 'ffcertificate' ); ?></label><br>
				<textarea id="ffc-cancel-reason" name="ffc_cancel_reason" rows="3" cols="40"></textarea>
			</p>
			<p>
				<button type="submit" name="ffc_cancel_confirm" value="1" class="ffc-cancel-confirm-btn">
					<?php esc_html_e( 'Confirm cancellation', 'ffcertificate' ); ?>
				</button>
			</p>
		</form>
		<?php
		$this->render_page( __( 'Cancel appointment', 'ffcertificate' ), (string) ob_get_clean() );
	}

	/**
	 * Render a titled informational notice page (success / already-cancelled
	 * / business-rule rejection) and exit.
	 *
	 * @param string $title Heading.
	 * @param string $body  Message (plain text).
	 * @return void
	 */
	private function render_notice( string $title, string $body ): void {
		$inner = '<h1>' . esc_html( $title ) . '</h1><p>' . esc_html( $body ) . '</p>';
		$this->render_page( $title, $inner );
	}

	/**
	 * Render a generic error page and exit.
	 *
	 * @param string $message Error message.
	 * @return void
	 */
	private function render_error( string $message ): void {
		$this->render_notice( __( 'Cancellation', 'ffcertificate' ), $message );
	}

	/**
	 * Emit a minimal self-contained HTML page wrapping the given inner markup
	 * and stop WordPress from rendering the theme on top. Kept standalone (no
	 * theme header/footer) so the e-mail link lands reliably regardless of the
	 * active theme — the same idiom AppointmentReceiptHandler uses.
	 *
	 * @param string $title Page <title>.
	 * @param string $inner Pre-escaped inner HTML.
	 * @return void
	 */
	private function render_page( string $title, string $inner ): void {
		if ( ! headers_sent() ) {
			nocache_headers();
			status_header( 200 );
			header( 'Content-Type: text/html; charset=utf-8' );
		}

		$suffix    = \FreeFormCertificate\Core\AssetHelper::asset_suffix();
		$style_url = FFC_PLUGIN_URL . "assets/css/ffc-appointment-cancellation{$suffix}.css";

		echo '<!DOCTYPE html><html ' . get_language_attributes() . '><head>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_language_attributes() returns a safe attribute string.
		echo '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
		echo '<meta name="robots" content="noindex,nofollow">';
		echo '<title>' . esc_html( $title ) . ' — ' . esc_html( get_bloginfo( 'name' ) ) . '</title>';
		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- standalone page rendered outside the wp_enqueue lifecycle (template_redirect → exit), mirroring AppointmentReceiptHandler.
		echo '<link rel="stylesheet" href="' . esc_url( $style_url ) . '">';
		echo '</head><body class="ffc-cancel-page"><main class="ffc-cancel-card">';
		echo $inner; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- callers pass pre-escaped markup.
		echo '</main></body></html>';

		exit;
	}
}
