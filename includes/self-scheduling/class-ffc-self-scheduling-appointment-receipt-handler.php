<?php
/**
 * Appointment Receipt Handler
 *
 * Handles displaying and printing appointment receipts/confirmations
 *
 * @package FreeFormCertificate\SelfScheduling
 * @since 4.1.1
 * @version 4.1.1
 */

declare(strict_types=1);

namespace FreeFormCertificate\SelfScheduling;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handler for appointment receipt operations.
 */
class AppointmentReceiptHandler {

	/**
	 * Constructor
	 */
	public function __construct() {
		// Register query var for receipt token.
		add_filter( 'query_vars', array( $this, 'add_query_vars' ) );

		// Hook into template redirect to catch receipt requests.
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
	 * Handle receipt request
	 *
	 * @return void
	 */
	public function handle_receipt_request(): void {
		if ( ! get_query_var( 'ffc_appointment_receipt' ) ) {
			return;
		}

		$appointment_id = absint( get_query_var( 'ffc_appointment_receipt' ) );
		$token          = get_query_var( 'token' );

		if ( ! $appointment_id ) {
			wp_die( esc_html__( 'Invalid appointment receipt request.', 'ffcertificate' ), 400 );
		}

		// Load repositories.
		$appointment_repo = new \FreeFormCertificate\Repositories\AppointmentRepository();
		$calendar_repo    = new \FreeFormCertificate\Repositories\CalendarRepository();

		// Get appointment.
		$appointment = $appointment_repo->findById( $appointment_id );

		if ( ! $appointment ) {
			wp_die( esc_html__( 'Appointment not found.', 'ffcertificate' ), 404 );
		}

		// Who may read it, and how (#2244). The owner and the holder of the
		// confirmation token get the receipt as it is. Staff who can open the
		// appointments get it masked, the way every staff screen draws it;
		// the audited Reveal lives on the appointment's admin screen. Before,
		// manage_options read any receipt unmasked, and nothing was logged.
		$owner_id  = (int) ( $appointment['user_id'] ?? 0 );
		$is_owner  = $owner_id > 0 && is_user_logged_in() && get_current_user_id() === $owner_id;
		$has_token = ! empty( $token ) && ! empty( $appointment['confirmation_token'] ) && hash_equals( (string) $appointment['confirmation_token'], (string) $token );
		$is_staff  = \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_view_appointments' );

		if ( ! $is_owner && ! $has_token && ! $is_staff ) {
			wp_die( esc_html__( 'You do not have permission to view this appointment receipt.', 'ffcertificate' ), 403 );
		}
		$masked = ! $is_owner && ! $has_token;

		// Block receipt for pending appointments (awaiting admin approval).
		$appointment_status = $appointment['status'] ?? 'pending';
		if ( 'pending' === $appointment_status && ! $is_staff ) {
			wp_die( esc_html__( 'This appointment is awaiting admin approval. The receipt will be available after confirmation.', 'ffcertificate' ), 403 );
		}

		// Get calendar (may be null if deleted).
		$calendar = null;
		if ( ! empty( $appointment['calendar_id'] ) ) {
			$calendar = $calendar_repo->findById( (int) $appointment['calendar_id'] );
		}

		// If calendar was deleted, create a placeholder.
		if ( ! $calendar ) {
			$calendar = array(
				'id'          => 0,
				'title'       => __( '(Calendar Deleted)', 'ffcertificate' ),
				'description' => '',
			);
		}

		// Generate and display receipt.
		$this->display_receipt( $appointment, $calendar, $masked );
		exit;
	}

	/**
	 * Enqueue PDF generation scripts
	 *
	 * @return void
	 */
	private function enqueue_pdf_scripts(): void {
		$s = \FreeFormCertificate\Core\AssetHelper::asset_suffix();

		\FreeFormCertificate\Core\AssetRegistry::enqueue_scripts( 'html2canvas' );

		\FreeFormCertificate\Core\AssetRegistry::enqueue_scripts( 'jspdf' );

		// Enqueue PDF generator (reuse from certificates).
		\FreeFormCertificate\Core\AssetRegistry::enqueue_scripts( 'ffc-pdf-generator', 'ffc-preflight' );

		// The pre-flight's telemetry goes through the request helper, which
		// reads its URL and nonce here (#2040).
		wp_localize_script( 'ffc-core', 'ffc_ajax', \FreeFormCertificate\Frontend\BrowserGate::request_config() );

		// Receipt "Download PDF" button wiring (extracted from an inline
		// <script>); reads the localized ffcReceiptData object below.
		wp_enqueue_script(
			'ffc-self-scheduling-receipt',
			FFC_PLUGIN_URL . "assets/js/ffc-self-scheduling-receipt{$s}.js",
			array( 'jquery', 'ffc-pdf-generator', 'ffc-preflight' ),
			FFC_VERSION,
			true
		);
	}

	/**
	 * Display receipt HTML
	 *
	 * @param array<string, mixed> $appointment Appointment.
	 * @param array<string, mixed> $calendar Calendar.
	 * @param bool                 $masked   Staff copy: e-mail, phone and CPF/RF masked.
	 * @return void
	 */
	private function display_receipt( array $appointment, array $calendar, bool $masked = false ): void {
		// Enqueue PDF scripts.
		$this->enqueue_pdf_scripts();

		// Generate server-side PDF data (uses template with QR code).
		$pdf_data = null;
		try {
			$pdf_generator = new \FreeFormCertificate\Generators\PdfGenerator();
			$pdf_data      = $pdf_generator->generate_appointment_pdf_data( $appointment, $calendar, $masked );
		} catch ( \Exception $e ) {
			// Fallback: PDF download will capture receipt HTML (without QR code).
			$pdf_data = null;
		}

		// Decrypt sensitive data with safety checks.
		$email = $appointment['email'] ?? '';
		if ( empty( $email ) && ! empty( $appointment['email_encrypted'] ) ) {
			if ( class_exists( '\FreeFormCertificate\Core\Encryption' ) ) {
				try {
					$email = \FreeFormCertificate\Core\Encryption::decrypt( $appointment['email_encrypted'] );
				} catch ( \Exception $e ) {
					$email = '';
				}
			}
		}

		$phone = $appointment['phone'] ?? '';
		if ( empty( $phone ) && ! empty( $appointment['phone_encrypted'] ) ) {
			if ( class_exists( '\FreeFormCertificate\Core\Encryption' ) ) {
				try {
					$phone = \FreeFormCertificate\Core\Encryption::decrypt( $appointment['phone_encrypted'] );
				} catch ( \Exception $e ) {
					$phone = '';
				}
			}
		}

		if ( $masked ) {
			$email = '' === $email ? '' : \FreeFormCertificate\Core\DocumentFormatter::mask_email( (string) $email );
			$phone = '' === $phone ? '' : \FreeFormCertificate\Core\DocumentFormatter::mask_phone( (string) $phone );
		}

		// appointment_date / start_time / end_time are Category B wall-clock
		// values (DATE / TIME) — render them literally, no timezone shift.
		// strtotime() + format_date/time() would parse them as UTC instants
		// and then re-apply the site offset, shifting a 09:00 slot to 06:00 on
		// a UTC-3 site.
		$appointment_date = __( 'N/A', 'ffcertificate' );
		if ( ! empty( $appointment['appointment_date'] ) ) {
			$formatted = \FreeFormCertificate\Core\DateFormatter::format_wallclock_date( (string) $appointment['appointment_date'] );
			if ( '' !== $formatted ) {
				$appointment_date = $formatted;
			}
		}

		$start_time = __( 'N/A', 'ffcertificate' );
		if ( ! empty( $appointment['start_time'] ) ) {
			$formatted = \FreeFormCertificate\Core\DateFormatter::format_wallclock_time( (string) $appointment['start_time'] );
			if ( '' !== $formatted ) {
				$start_time = $formatted;
			}
		}

		$end_time = '';
		if ( ! empty( $appointment['end_time'] ) ) {
			$end_time = \FreeFormCertificate\Core\DateFormatter::format_wallclock_time( (string) $appointment['end_time'] );
		}

		$created_at = __( 'N/A', 'ffcertificate' );
		if ( ! empty( $appointment['created_at'] ) ) {
			$timestamp = strtotime( $appointment['created_at'] );
			if ( false !== $timestamp ) {
				$created_at = \FreeFormCertificate\Core\DateFormatter::format_datetime( $timestamp );
			}
		}

		// Status label with safety check.
		$status_labels      = array(
			'pending'   => __( 'Pending Approval', 'ffcertificate' ),
			'confirmed' => __( 'Confirmed', 'ffcertificate' ),
			'cancelled' => __( 'Cancelled', 'ffcertificate' ),
			'completed' => __( 'Completed', 'ffcertificate' ),
			'no_show'   => __( 'No Show', 'ffcertificate' ),
		);
		$appointment_status = $appointment['status'] ?? 'pending';
		$status_label       = $status_labels[ $appointment_status ] ?? $appointment_status;

		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1.0">
			<title><?php echo esc_html__( 'Appointment Receipt', 'ffcertificate' ); ?> #<?php echo esc_html( $appointment['id'] ?? '0' ); ?></title>
			<style>
				* {
					margin: 0;
					padding: 0;
					box-sizing: border-box;
				}
				body {
					font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
					font-size: 14px;
					line-height: 1.6;
					color: #333;
					background: #f5f5f5;
					padding: 20px;
				}
				.receipt-container {
					max-width: 800px;
					margin: 0 auto;
					background: white;
					padding: 40px;
					box-shadow: 0 2px 10px rgba(0,0,0,0.1);
				}
				.header {
					text-align: center;
					margin-bottom: 40px;
					padding-bottom: 20px;
					border-bottom: 3px solid #0073aa;
				}
				.header h1 {
					margin: 0 0 10px 0;
					color: #0073aa;
					font-size: 32px;
				}
				.header .site-name {
					font-size: 18px;
					color: #666;
				}
				.status-badge {
					display: inline-block;
					padding: 10px 20px;
					border-radius: 5px;
					font-weight: bold;
					font-size: 16px;
					margin: 20px 0;
					text-transform: uppercase;
				}
				.status-pending {
					background: #f0f0f1;
					color: #646970;
				}
				.status-confirmed {
					background: #d5e8d4;
					color: #2e7d32;
				}
				.status-cancelled {
					background: #f8d7da;
					color: #b32d2e;
				}
				.status-completed {
					background: #cfe2ff;
					color: #004085;
				}
				.info-section {
					margin-bottom: 30px;
				}
				.info-section h2 {
					font-size: 20px;
					color: #0073aa;
					margin-bottom: 15px;
					border-bottom: 2px solid #e0e0e0;
					padding-bottom: 8px;
				}
				.info-row {
					margin-bottom: 12px;
					padding: 8px 0;
					border-bottom: 1px solid #f0f0f0;
				}
				.info-label {
					font-weight: bold;
					color: #555;
					display: inline-block;
					min-width: 150px;
				}
				.info-value {
					color: #333;
				}
				.footer {
					margin-top: 50px;
					padding-top: 20px;
					border-top: 2px solid #e0e0e0;
					text-align: center;
					font-size: 12px;
					color: #666;
				}
				.action-buttons {
					position: fixed;
					top: 20px;
					right: 20px;
					z-index: 1000;
					display: flex;
					gap: 10px;
				}
				.action-button {
					background: #0073aa;
					color: white;
					border: none;
					padding: 12px 24px;
					border-radius: 5px;
					cursor: pointer;
					font-size: 16px;
					box-shadow: 0 2px 5px rgba(0,0,0,0.2);
					text-decoration: none;
					display: inline-block;
				}
				.action-button:hover {
					background: #005a87;
				}
				.action-button.secondary {
					background: #50575e;
				}
				.action-button.secondary:hover {
					background: #3c434a;
				}
				.action-button .ffc-svg-icon {
					vertical-align: -3px;
					margin-right: 6px;
				}
				.status-row {
					text-align: center;
				}
				.info-value.validation-code {
					font-weight: bold;
					font-size: 1.1em;
					letter-spacing: 1px;
				}
				@media print {
					body {
						background: white;
						padding: 0;
					}
					.receipt-container {
						box-shadow: none;
						padding: 20px;
					}
					.action-buttons {
						display: none;
					}
				}
			</style>
		</head>
		<body>
			<div class="action-buttons">
				<button class="action-button" id="ffc-download-pdf-btn"><?php echo \FreeFormCertificate\Core\Icons::svg( 'download', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- constant icon markup. ?><?php echo esc_html__( 'Download PDF', 'ffcertificate' ); ?></button>
				<button class="action-button secondary" onclick="window.print()"><?php echo \FreeFormCertificate\Core\Icons::svg( 'print', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- constant icon markup. ?><?php echo esc_html__( 'Print', 'ffcertificate' ); ?></button>
			</div>

			<div class="receipt-container" id="ffc-receipt-content">
				<div class="header">
					<h1><?php echo esc_html__( 'Appointment Receipt', 'ffcertificate' ); ?></h1>
					<div class="site-name"><?php bloginfo( 'name' ); ?></div>
				</div>

				<div class="status-row">
					<span class="status-badge status-<?php echo esc_attr( $appointment_status ); ?>">
						<?php echo esc_html( $status_label ); ?>
					</span>
				</div>

				<div class="info-section">
					<h2><?php echo esc_html__( 'Event Details', 'ffcertificate' ); ?></h2>
					<div class="info-row">
						<span class="info-label"><?php echo esc_html__( 'Event:', 'ffcertificate' ); ?></span>
						<span class="info-value"><?php echo esc_html( $calendar['title'] ?? __( 'N/A', 'ffcertificate' ) ); ?></span>
					</div>
					<?php if ( ! empty( $calendar['description'] ) ) : ?>
					<div class="info-row">
						<span class="info-label"><?php echo esc_html__( 'Description:', 'ffcertificate' ); ?></span>
						<span class="info-value"><?php echo esc_html( $calendar['description'] ); ?></span>
					</div>
					<?php endif; ?>
				</div>

				<div class="info-section">
					<h2><?php echo esc_html__( 'Appointment Information', 'ffcertificate' ); ?></h2>
					<div class="info-row">
						<span class="info-label"><?php echo esc_html__( 'Appointment ID:', 'ffcertificate' ); ?></span>
						<span class="info-value">#<?php echo esc_html( $appointment['id'] ?? '0' ); ?></span>
					</div>
					<?php if ( ! empty( $appointment['validation_code'] ) ) : ?>
					<div class="info-row">
						<span class="info-label"><?php echo esc_html__( 'Validation Code:', 'ffcertificate' ); ?></span>
						<span class="info-value validation-code"><?php echo esc_html( \FreeFormCertificate\Core\DocumentFormatter::format_auth_code( $appointment['validation_code'], \FreeFormCertificate\Core\DocumentFormatter::PREFIX_APPOINTMENT ) ); ?></span>
					</div>
					<?php endif; ?>
					<div class="info-row">
						<span class="info-label"><?php echo esc_html__( 'Date:', 'ffcertificate' ); ?></span>
						<span class="info-value"><?php echo esc_html( $appointment_date ); ?></span>
					</div>
					<div class="info-row">
						<span class="info-label"><?php echo esc_html__( 'Time:', 'ffcertificate' ); ?></span>
						<span class="info-value"><?php echo esc_html( $start_time ); ?><?php echo ! empty( $end_time ) ? ' - ' . esc_html( $end_time ) : ''; ?></span>
					</div>
					<div class="info-row">
						<span class="info-label"><?php echo esc_html__( 'Booked on:', 'ffcertificate' ); ?></span>
						<span class="info-value"><?php echo esc_html( $created_at ); ?></span>
					</div>
				</div>

				<div class="info-section">
					<h2><?php echo esc_html__( 'Personal Information', 'ffcertificate' ); ?></h2>
					<div class="info-row">
						<span class="info-label"><?php echo esc_html__( 'Name:', 'ffcertificate' ); ?></span>
						<span class="info-value"><?php echo esc_html( $appointment['name'] ?? '' ); ?></span>
					</div>
					<?php if ( ! empty( $email ) ) : ?>
					<div class="info-row">
						<span class="info-label"><?php echo esc_html__( 'Email:', 'ffcertificate' ); ?></span>
						<span class="info-value"><?php echo esc_html( $email ); ?></span>
					</div>
					<?php endif; ?>
					<?php if ( ! empty( $phone ) ) : ?>
					<div class="info-row">
						<span class="info-label"><?php echo esc_html__( 'Phone:', 'ffcertificate' ); ?></span>
						<span class="info-value"><?php echo esc_html( $phone ); ?></span>
					</div>
					<?php endif; ?>
				</div>

				<?php if ( ! empty( $appointment['user_notes'] ) ) : ?>
				<div class="info-section">
					<h2><?php echo esc_html__( 'Notes', 'ffcertificate' ); ?></h2>
					<div class="info-value"><?php echo nl2br( esc_html( $appointment['user_notes'] ) ); ?></div>
				</div>
				<?php endif; ?>

				<div class="footer">
					<p>
					<?php
					/* translators: %s: date and time of generation */
					echo esc_html( sprintf( __( 'Generated on %s', 'ffcertificate' ), \FreeFormCertificate\Core\DateFormatter::format_datetime( time() ) ) );
					?>
					</p>
					<p><?php echo esc_html( get_bloginfo( 'name' ) ); ?> - <?php echo esc_url( home_url() ); ?></p>
				</div>
			</div>

			<?php
			$validation_code = ! empty( $appointment['validation_code'] )
				? \FreeFormCertificate\Core\DocumentFormatter::format_auth_code(
					$appointment['validation_code'],
					\FreeFormCertificate\Core\DocumentFormatter::PREFIX_APPOINTMENT
				)
				: '';

			wp_localize_script(
				'ffc-self-scheduling-receipt',
				'ffcReceiptData',
				array(
					'pdfData'        => $pdf_data ? $pdf_data : null,
					'appointmentId'  => $appointment['id'] ?? '0',
					'validationCode' => $validation_code,
					'errorMsg'       => __( 'Error: PDF generator not loaded. Please refresh the page.', 'ffcertificate' ),
					'strings'        => array(
						'generatingPdf' => __( 'Generating PDF...', 'ffcertificate' ),
						'pleaseWait'    => __( 'Please wait, this may take a few seconds...', 'ffcertificate' ),
					),
					// The browser step before the download (#2040).
					'preflight'      => \FreeFormCertificate\Frontend\BrowserGate::script_config( self::preflight_strings() ),
				)
			);

			wp_print_scripts( 'jquery' );
			wp_print_scripts( 'html2canvas' );
			wp_print_scripts( 'jspdf' );
			wp_print_scripts( 'ffc-pdf-generator' );
			wp_print_scripts( 'ffc-preflight' );
			wp_print_scripts( 'ffc-self-scheduling-receipt' );
			?>
		</body>
		</html>
		<?php
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
	 * Generate receipt URL for an appointment
	 *
	 * @param int    $appointment_id Appointment ID.
	 * @param string $token Optional confirmation token for guest access.
	 * @return string
	 */
	public static function get_receipt_url( int $appointment_id, string $token = '' ): string {
		$url = home_url();
		$url = add_query_arg( 'ffc_appointment_receipt', $appointment_id, $url );

		if ( ! empty( $token ) ) {
			$url = add_query_arg( 'token', $token, $url );
		}

		return $url;
	}
}
