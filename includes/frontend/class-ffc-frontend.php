<?php
/**
 * Frontend orchestrator.
 *
 * Registers shortcodes, AJAX handlers, and enqueues frontend assets.
 *
 * @package FreeFormCertificate
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace (Phase 2)
 */

declare(strict_types=1);

namespace FreeFormCertificate\Frontend;

use FreeFormCertificate\Submissions\SubmissionHandler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Frontend entry point that wires up all public-facing functionality.
 *
 * @since 1.0.0
 */
class Frontend {

	/**
	 * Shortcode renderer.
	 *
	 * @var Shortcodes
	 */
	private $shortcodes;

	/**
	 * Form submission processor.
	 *
	 * @var FormProcessor
	 */
	private $form_processor;

	/**
	 * Certificate verification handler.
	 *
	 * @var VerificationHandler
	 */
	private $verification_handler;

	/**
	 * Public CSV download handler.
	 *
	 * @var PublicCsvDownload
	 */
	private $public_csv_download;

	/**
	 * Constructor.
	 *
	 * @param SubmissionHandler $submission_handler Submission handler instance.
	 */
	public function __construct( SubmissionHandler $submission_handler ) {
		$this->verification_handler = new VerificationHandler( $submission_handler );
		$this->form_processor       = new FormProcessor( $submission_handler );
		$this->shortcodes           = new Shortcodes();
		$this->public_csv_download  = new PublicCsvDownload();

		// DynamicFragments registers AJAX hooks in its constructor; WP holds the reference.
		new DynamicFragments();

		// 6.6.4 follow-up (#361 Sprint 2) — PreflightTelemetry also
		// registers its own AJAX hooks. WP holds the reference via
		// the action callback array.
		new PreflightTelemetry();

		$this->register_hooks();
		$this->public_csv_download->register_hooks();
	}

	/**
	 * Register WordPress hooks for frontend functionality.
	 */
	private function register_hooks(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'frontend_assets' ) );

		add_shortcode( 'ffc_form', array( $this->shortcodes, 'render_form' ) );
		add_shortcode( 'ffc_verification', array( $this->shortcodes, 'render_verification_page' ) );

		add_action( 'wp_ajax_ffc_submit_form', array( $this->form_processor, 'handle_submission_ajax' ) );
		add_action( 'wp_ajax_nopriv_ffc_submit_form', array( $this->form_processor, 'handle_submission_ajax' ) );

		add_action( 'wp_ajax_ffc_verify_certificate', array( $this->verification_handler, 'handle_verification_ajax' ) );
		add_action( 'wp_ajax_nopriv_ffc_verify_certificate', array( $this->verification_handler, 'handle_verification_ajax' ) );

		add_action( 'wp_ajax_ffc_verify_magic_token', array( $this->verification_handler, 'handle_magic_verification_ajax' ) );
		add_action( 'wp_ajax_nopriv_ffc_verify_magic_token', array( $this->verification_handler, 'handle_magic_verification_ajax' ) );
	}

	/**
	 * Enqueue frontend CSS and JavaScript assets.
	 */
	public function frontend_assets(): void {
		global $post;

		if ( ! is_a( $post, 'WP_Post' ) ) {
			return;
		}

		$has_form         = has_shortcode( $post->post_content, 'ffc_form' );
		$has_verification = has_shortcode( $post->post_content, 'ffc_verification' );
		$has_csv_download = has_shortcode( $post->post_content, 'ffc_csv_download' );

		if ( $has_form || $has_verification || $has_csv_download ) {
			$s = \FreeFormCertificate\Core\Utils::asset_suffix();

			// Dark mode script (loaded early to prevent flash).
			\FreeFormCertificate\Core\Utils::enqueue_dark_mode();

			// CSS - Using centralized version constant.
			wp_enqueue_style( 'ffc-pdf-core', FFC_PLUGIN_URL . "assets/css/ffc-pdf-core{$s}.css", array(), FFC_VERSION );
			wp_enqueue_style( 'ffc-common', FFC_PLUGIN_URL . "assets/css/ffc-common{$s}.css", array(), FFC_VERSION );
			wp_enqueue_style( 'ffc-frontend-css', FFC_PLUGIN_URL . "assets/css/ffc-frontend{$s}.css", array( 'ffc-pdf-core', 'ffc-common' ), FFC_VERSION );

			// Dynamic fragments: refresh captcha + nonces on cached pages so
			// LiteSpeed/Varnish visitors don't submit with a stale nonce. The
			// CSV download shortcode also renders a WP nonce + captcha, so it
			// needs this refresh too.
			wp_enqueue_script( 'ffc-dynamic-fragments' );

			// 6.3.7: WebView (in-app browser) preventive warning. Detects
			// FB/Insta/WhatsApp/TikTok/LinkedIn/Android-WebView at page
			// load and offers an "Open in browser" CTA before the user
			// invests time filling the form. Pure UX layer — the v6.3.6
			// pre-open + manual-tap fallbacks still cover anyone who
			// dismisses the banner and proceeds.
			if ( $has_form || $has_csv_download ) {
				wp_enqueue_script( 'ffc-webview-warning', FFC_PLUGIN_URL . "assets/js/ffc-webview-warning{$s}.js", array(), FFC_VERSION, true );
				wp_localize_script(
					'ffc-webview-warning',
					'ffc_webview_warning',
					array(
						'strings' => array(
							'title'           => __( 'Download may fail in this app', 'ffcertificate' ),
							'body'            => __( 'You are viewing this page inside an app browser. To make sure the certificate downloads correctly, please open the page in your main browser (Chrome or Safari).', 'ffcertificate' ),
							'openInBrowser'   => __( 'Open in browser', 'ffcertificate' ),
							'continueAnyway'  => __( 'Continue anyway', 'ffcertificate' ),
							'iosInstructions' => __( 'Tap the menu icon (•••) at the bottom of the app and choose "Open in Safari".', 'ffcertificate' ),
						),
					)
				);
			}

			// 6.3.10: friendly "already submitted" notice. Pure client-side
			// hint backed by localStorage; reads `ffc_submitted_forms` and,
			// if the current form_id is in the list, shows a dismissable
			// banner pointing the user at the reprint flow. Hooks into the
			// global ajaxComplete to add the form_id to that list once a
			// submission succeeds. Only enqueued where [ffc_form] renders.
			if ( $has_form ) {
				wp_enqueue_script( 'ffc-already-submitted-notice', FFC_PLUGIN_URL . "assets/js/ffc-already-submitted-notice{$s}.js", array( 'jquery' ), FFC_VERSION, true );
				wp_localize_script(
					'ffc-already-submitted-notice',
					'ffc_already_submitted',
					array(
						'strings' => array(
							'title'   => __( 'You may have already submitted this form', 'ffcertificate' ),
							'body'    => __( 'We detected a previous submission from this device. If you lost your certificate, just fill in your CPF and submit — the system recognises it and returns the existing certificate.', 'ffcertificate' ),
							'dismiss' => __( 'Got it', 'ffcertificate' ),
						),
					)
				);
			}
		}

		if ( $has_form || $has_verification ) {
			$s = \FreeFormCertificate\Core\Utils::asset_suffix();

			// PDF Libraries - Using centralized version constants.
			wp_enqueue_script( 'html2canvas', FFC_PLUGIN_URL . 'libs/js/html2canvas.min.js', array(), FFC_HTML2CANVAS_VERSION, true );
			wp_enqueue_script( 'jspdf', FFC_PLUGIN_URL . 'libs/js/jspdf.umd.min.js', array(), FFC_JSPDF_VERSION, true );

			// PDF Generator (shared module).
			wp_enqueue_script( 'ffc-pdf-generator', FFC_PLUGIN_URL . "assets/js/ffc-pdf-generator{$s}.js", array( 'jquery', 'html2canvas', 'jspdf' ), FFC_VERSION, true );

			wp_enqueue_script( 'ffc-frontend-js', FFC_PLUGIN_URL . "assets/js/ffc-frontend{$s}.js", array( 'jquery', 'ffc-core', 'ffc-pdf-generator', 'ffc-rate-limit' ), FFC_VERSION, true );

			// ffc-core dep: ffc-geofence-frontend.js calls FFC.request() (6 sites incl. the pre-flight telemetry endpoint added in 6.6.4 follow-up).
			wp_enqueue_script( 'ffc-geofence-frontend', FFC_PLUGIN_URL . "assets/js/ffc-geofence-frontend{$s}.js", array( 'jquery', 'ffc-core' ), FFC_VERSION, true );

			// Validation modules split out of the former monolith. Each extends
			// the window.FFCGeofence object (Object.assign) and depends on the
			// core handle so it loads after it. The geofence config localize
			// stays on 'ffc-geofence-frontend'.
			foreach (
				array(
					'ffc-geofence-datetime',
					'ffc-geofence-gps',
					'ffc-geofence-preflight',
				) as $ffc_geofence_module
			) {
				wp_enqueue_script( $ffc_geofence_module, FFC_PLUGIN_URL . "assets/js/{$ffc_geofence_module}{$s}.js", array( 'jquery', 'ffc-geofence-frontend' ), FFC_VERSION, true );
			}

			// Pass geofence configurations to frontend.
			$this->localize_geofence_config();

			// Device fingerprint signals collector (only when globally enabled).
			//
			// thumbmarkjs (vendored at libs/js/) provides the raw component
			// probes; ffc-device-signals.js disables thumbmarkjs telemetry
			// (setOption('logging', false)), reads getFingerprintData(),
			// hashes each component locally with SubtleCrypto, and ships
			// the JSON ffc_device_signals hidden input. Server algorithm
			// is unchanged from 6.3.0.
			if ( class_exists( '\FreeFormCertificate\Security\RateLimiter' ) ) {
				$rl_settings = \FreeFormCertificate\Security\RateLimiter::get_settings();
				if ( ! empty( $rl_settings['device']['enabled'] ) ) {
					wp_enqueue_script( 'ffc-thumbmark', FFC_PLUGIN_URL . 'libs/js/thumbmark-' . FFC_THUMBMARK_VERSION . '.umd.js', array(), FFC_THUMBMARK_VERSION, true );
					wp_enqueue_script( 'ffc-device-signals', FFC_PLUGIN_URL . "assets/js/ffc-device-signals{$s}.js", array( 'ffc-thumbmark' ), FFC_VERSION, true );
					wp_localize_script(
						'ffc-device-signals',
						'ffc_device_config',
						array(
							'signals' => array_values( (array) ( $rl_settings['device']['signals_enabled'] ?? array() ) ),
						)
					);
				}
			}

			// 6.6.4 follow-up (#361 Sprint 1) — gate the browser-env
			// diagnostic log on the dedicated `debug_browser_env`
			// toggle. JS reads the boolean and short-circuits when
			// false (zero console noise in production).
			$debug_browser_env = class_exists( '\FreeFormCertificate\Core\Debug' )
				&& \FreeFormCertificate\Core\Debug::is_enabled( \FreeFormCertificate\Core\Debug::AREA_BROWSER_ENV );

			wp_localize_script(
				'ffc-frontend-js',
				'ffc_ajax',
				array(
					'ajax_url'          => admin_url( 'admin-ajax.php' ),
					'nonce'             => wp_create_nonce( 'ffc_frontend_nonce' ),
					'debug_browser_env' => $debug_browser_env,
					'strings'           => array(
						// Verification.
						'verifying'              => __( 'Verifying...', 'ffcertificate' ),
						'verify'                 => __( 'Verify', 'ffcertificate' ),
						'processing'             => __( 'Processing...', 'ffcertificate' ),
						'submit'                 => __( 'Submit', 'ffcertificate' ),
						'connectionError'        => __( 'Connection error.', 'ffcertificate' ),
						'enterCode'              => __( 'Please enter the code.', 'ffcertificate' ),
						'generatingCertificate'  => __( 'Generating certificate in the background, please wait 10 seconds and check your downloads folder...', 'ffcertificate' ),
						'idMustHaveDigits'       => __( 'The ID must have exactly 7 digits (RF) or 11 digits (CPF).', 'ffcertificate' ),
						'invalidToken'           => __( 'Invalid token.', 'ffcertificate' ),
						'generating'             => __( 'Generating...', 'ffcertificate' ),
						'downloadAgain'          => __( 'Download Again', 'ffcertificate' ),

						// Document Verification Display.
						'certificateValid'       => __( 'Document Valid!', 'ffcertificate' ),
						'certificateInvalid'     => __( 'Document Invalid', 'ffcertificate' ),
						'formTitle'              => __( 'Form', 'ffcertificate' ),
						'authCode'               => __( 'Auth Code', 'ffcertificate' ),
						'issueDate'              => __( 'Issue Date', 'ffcertificate' ),
						'downloadPDF'            => __( 'Download PDF', 'ffcertificate' ),
						'tryManually'            => __( 'Or try manual verification', 'ffcertificate' ),
						'enterAuthCode'          => __( 'Enter validation code', 'ffcertificate' ),

						// Validation (CPF/RF).
						'rfInvalid'              => __( 'Invalid RF', 'ffcertificate' ),
						'cpfInvalid'             => __( 'Invalid CPF', 'ffcertificate' ),
						'enterValidCpfRf'        => __( 'Enter a valid CPF (11 digits) or RF (7 digits)', 'ffcertificate' ),

						// Success/Error Messages.
						'success'                => __( 'Success!', 'ffcertificate' ),
						'submissionSuccessful'   => __( 'Your submission was successful.', 'ffcertificate' ),
						'error'                  => __( 'Error occurred', 'ffcertificate' ),
						'fillRequired'           => __( 'Please fill all required fields', 'ffcertificate' ),

						// Rate Limiting.
						'wait'                   => __( 'Wait...', 'ffcertificate' ),
						'send'                   => __( 'Send', 'ffcertificate' ),

						// PDF Generation.
						'pdfLibrariesFailed'     => __( 'PDF libraries failed to load. Please refresh the page.', 'ffcertificate' ),
						'pdfGenerationError'     => __( 'Error generating PDF (html2canvas). Please try again.', 'ffcertificate' ),
						'pleaseWait'             => __( 'Please wait, this may take a few seconds...', 'ffcertificate' ),
						'generatingPdf'          => __( 'Generating PDF...', 'ffcertificate' ),
						'pdfContainerNotFound'   => __( 'Error: PDF container not found', 'ffcertificate' ),
						'errorGeneratingPdf'     => __( 'Error generating PDF', 'ffcertificate' ),
						'html2canvasFailed'      => __( 'Error: html2canvas failed', 'ffcertificate' ),

						// 6.3.6 — placeholder tab + manual fallback strings (iOS Safari, Samsung Internet, Android WebView).
						'pdfGeneratingTab'       => __( 'Generating your certificate…', 'ffcertificate' ),
						'pdfGeneratingTabHint'   => __( 'Please do not close this tab. The PDF will appear automatically in a few seconds.', 'ffcertificate' ),
						'pdfManualOpenIOS'       => __( 'Tap to open the PDF', 'ffcertificate' ),
						'pdfManualHintIOS'       => __( 'Pop-ups are blocked in this browser. Tap the button below to open the PDF — then use the share icon to save or print.', 'ffcertificate' ),
						'pdfOpenedAndroidTab'    => __( 'PDF opened in a new tab. Use the menu to save or share.', 'ffcertificate' ),

						// 6.3.11 — post-download confirmation strings (were JS-only fallbacks before).
						'pdfOpenedIOS'           => __( 'PDF opened in a new tab. Tap the share icon to save or print.', 'ffcertificate' ),
						'pdfSavedAndroid'        => __( 'PDF saved! Check your Downloads folder.', 'ffcertificate' ),
						'pdfDownloaded'          => __( 'PDF downloaded successfully.', 'ffcertificate' ),
						'pdfBlankWarning'        => __( 'Warning: the generated PDF appears to be blank. Please try again.', 'ffcertificate' ),

						// 6.6.2 — success-card copy / clipboard feedback.
						'copy'                   => __( 'Copy', 'ffcertificate' ),
						'copied'                 => __( 'Copied!', 'ffcertificate' ),
						'copyFailed'             => __( 'Could not copy. Long-press the text to copy it manually.', 'ffcertificate' ),

						// 6.6.2 (Sprint 2) — desktop fallback when pdf.save() is silently blocked.
						'pdfDesktopFallbackHint' => __( "Didn't download? Click here to open the PDF in a new tab.", 'ffcertificate' ),

						// 6.6.2 (Sprint 3) — actionable error panel in the overlay (replaces alert()).
						'pdfErrorTitle'          => __( "Couldn't generate the certificate", 'ffcertificate' ),
						'pdfErrorRetry'          => __( 'Try again', 'ffcertificate' ),
						'pdfErrorClose'          => __( 'Close', 'ffcertificate' ),
						'pdfErrorGeneric'        => __( 'Something went wrong while building the PDF. Try again — if it keeps failing, use a different browser or contact the organizer.', 'ffcertificate' ),
						'pdfErrorBlank'          => __( 'The certificate came out blank — usually a slow-loading image. Try again. If it persists, try a different browser or contact the organizer.', 'ffcertificate' ),
						'pdfErrorCors'           => __( 'A certificate image failed to load (cross-origin restriction). This is a configuration problem on the server, not on your device. Please contact the organizer with your authentication code.', 'ffcertificate' ),
						'pdfErrorHtml2canvas'    => __( "We couldn't render the certificate. This usually clears up on a retry; if not, try a different browser.", 'ffcertificate' ),
						'pdfErrorLibrariesBody'  => __( 'A required script blocked or timed out. Reload the page (Ctrl+R / Cmd+R) and try again. If the issue persists, try a different browser.', 'ffcertificate' ),

						// 6.6.2 (Sprint 4) — pre-submission warnings: don't-close + offline.
						'pdfDontCloseTab'        => __( 'Please do not close this tab until the PDF appears.', 'ffcertificate' ),
						'offlineMessage'         => __( 'You appear to be offline. Reconnect to the internet and try again.', 'ffcertificate' ),
					),
				)
			);
		}

		if ( $has_csv_download ) {
			$s = $s ?? \FreeFormCertificate\Core\Utils::asset_suffix();

			wp_enqueue_script(
				'ffc-csv-download',
				FFC_PLUGIN_URL . "assets/js/ffc-csv-download{$s}.js",
				// 'ffc-core' provides window.FFC.request() (the AJAX helper
				// the file uses after the #277 migration). 'ffc-rate-limit'
				// is the registered handle for ffc-frontend-helpers.js, which
				// exposes window.FFC.Frontend.Masks.applyCpfRf() — used to
				// mask the optional CPF input rendered when
				// _ffc_csv_public_cpf_mode is set.
				array( 'jquery', 'ffc-core', 'ffc-rate-limit' ),
				FFC_VERSION,
				true
			);

			// Flow modules split out of the former monolith. Each depends on
			// 'ffc-csv-download' (the shared core that owns window.FFCCsv) and
			// reads the localized config from it — no per-file localize needed.
			foreach (
				array(
					'ffc-csv-info-screen',
					'ffc-csv-cert-preview',
					'ffc-csv-download-flow',
					'ffc-csv-open-early',
					'ffc-csv-extend-end',
					'ffc-csv-schedule-exception',
				) as $ffc_csv_module
			) {
				wp_enqueue_script(
					$ffc_csv_module,
					FFC_PLUGIN_URL . "assets/js/{$ffc_csv_module}{$s}.js",
					array( 'jquery', 'ffc-core', 'ffc-csv-download' ),
					FFC_VERSION,
					true
				);
			}
			wp_localize_script(
				'ffc-csv-download',
				'ffc_csv_download',
				array(
					'ajax_url'       => admin_url( 'admin-ajax.php' ),
					'min_display_ms' => 1500,
					'strings'        => array(
						// Progress overlay.
						'validating'                     => __( 'Validating access…', 'ffcertificate' ),
						/* translators: %d is the total number of records */
						'generating'                     => __( 'Generating CSV — %d records…', 'ffcertificate' ),
						/* translators: %1$d is processed count, %2$d is total count */
						'exporting'                      => __( 'Exporting %1$d / %2$d…', 'ffcertificate' ),
						'downloading'                    => __( 'Starting download…', 'ffcertificate' ),
						'complete'                       => __( 'Download complete!', 'ffcertificate' ),
						'error'                          => __( 'Error', 'ffcertificate' ),
						'downloadCsv'                    => __( 'Download CSV', 'ffcertificate' ),
						'timeout'                        => __( 'Export timed out. Please try again.', 'ffcertificate' ),
						'noRecords'                      => __( 'No records found to export.', 'ffcertificate' ),
						'connError'                      => __( 'Connection error. Please try again.', 'ffcertificate' ),

						// Info screen: headers.
						'formDetails'                    => __( 'Form Details', 'ffcertificate' ),
						'backToForm'                     => __( 'Back', 'ffcertificate' ),
						'formTitle'                      => __( 'Form', 'ffcertificate' ),
						'totalSubmissions'               => __( 'Total submissions', 'ffcertificate' ),

						// Info screen: restrictions.
						'accessRestrictions'             => __( 'Access Restrictions', 'ffcertificate' ),
						'passwordRequired'               => __( 'Password required', 'ffcertificate' ),
						'approvedUsersOnly'              => __( 'Restricted to approved users', 'ffcertificate' ),
						'blockedUsers'                   => __( 'Blocked users list active', 'ffcertificate' ),
						'accessCodeRequired'             => __( 'Access code (ticket) required', 'ffcertificate' ),

						// Info screen: availability.
						'availability'                   => __( 'Availability Period', 'ffcertificate' ),
						'dateStart'                      => __( 'Start date', 'ffcertificate' ),
						'dateEnd'                        => __( 'End date', 'ffcertificate' ),
						'timeStart'                      => __( 'Start time', 'ffcertificate' ),
						'timeEnd'                        => __( 'End time', 'ffcertificate' ),
						'infinity'                       => '∞',
						'noEndDateAlert'                 => __( 'This form has no end date configured. The CSV download will only be available after the administrator sets an end date.', 'ffcertificate' ),

						// Info screen: geolocation.
						'geolocation'                    => __( 'Geolocation', 'ffcertificate' ),
						'gpsLocations'                   => __( 'GPS Locations', 'ffcertificate' ),
						'ipLocations'                    => __( 'IP Locations', 'ffcertificate' ),
						'geolocationEnabled'             => __( 'Geolocation enabled', 'ffcertificate' ),

						// Info screen: quiz.
						'quizEvaluation'                 => __( 'Quiz / Evaluation', 'ffcertificate' ),
						'passingScore'                   => __( 'Minimum passing score', 'ffcertificate' ),
						'maxAttempts'                    => __( 'Maximum attempts', 'ffcertificate' ),
						'unlimited'                      => __( 'Unlimited', 'ffcertificate' ),

						// Info screen: download.
						'csvDownload'                    => __( 'CSV Download', 'ffcertificate' ),
						'downloadQuota'                  => __( 'Download quota', 'ffcertificate' ),
						/* translators: %1$d is current download count, %2$d is download limit */
						'quotaUsed'                      => __( '%1$d of %2$d used', 'ffcertificate' ),

						// Info screen: status messages.
						/* translators: %s is the formatted end date */
						'formActiveUntil'                => __( 'This form is still active until %s. The download will be available after the end date.', 'ffcertificate' ),
						'quotaExhausted'                 => __( 'The download quota for this form has been exhausted.', 'ffcertificate' ),
						'downloadReady'                  => __( 'The form collection period has ended. The CSV is ready for download.', 'ffcertificate' ),
						'csvDownloadDisabled'            => __( 'The CSV download is not available for this form.', 'ffcertificate' ),
						// Tooltips on action buttons rendered disabled because
						// the admin explicitly turned the sub-feature off
						// (master is on but the per-feature toggle is off).
						// See `*_disabled_by_admin` flags in the info builder.
						'csvDownloadDisabledTip'         => __( 'CSV download disabled', 'ffcertificate' ),
						'startEarlyDisabledTip'          => __( 'Start Form Early disabled', 'ffcertificate' ),
						'extendEndDisabledTip'           => __( 'Postpone Close disabled', 'ffcertificate' ),
						'certPreviewDisabledTip'         => __( 'Certificate Preview disabled', 'ffcertificate' ),
						/* translators: %s is the formatted start date */
						'beforeStartMsg'                 => __( 'The form collection has not started yet. It will begin on %s.', 'ffcertificate' ),

						// Info screen: cert preview.
						'previewCertificate'             => __( 'Preview Certificate', 'ffcertificate' ),
						'certPreviewTitle'               => __( 'Certificate Preview', 'ffcertificate' ),
						'certPreviewNote'                => __( 'Placeholders replaced with sample data. QR code shown as placeholder.', 'ffcertificate' ),
						'close'                          => __( 'Close', 'ffcertificate' ),
						'loadingPreview'                 => __( 'Loading preview…', 'ffcertificate' ),

						// Info screen: Start Form Now (early-open).
						'startFormNow'                   => __( 'Start Form Now', 'ffcertificate' ),
						'openEarlyTooltip'               => __( 'Overrides the scheduled start time. Form opens immediately.', 'ffcertificate' ),
						'openEarlyTitle'                 => __( 'Start form now?', 'ffcertificate' ),
						'openEarlyBody1'                 => __( 'This will override the scheduled start time. The form will open immediately for all users.', 'ffcertificate' ),
						'openEarlyOrigLabel'             => __( 'Scheduled start:', 'ffcertificate' ),
						'openEarlyNewLabel'              => __( 'New start will be:', 'ffcertificate' ),
						'openEarlyNewNow'                => __( 'now', 'ffcertificate' ),
						'openEarlyIrreversible'          => __( 'This cannot be undone from this page.', 'ffcertificate' ),
						'openEarlyCacheWarn'             => __( 'If your site uses page caching (Cloudflare, W3 Total Cache, etc.), some visitors may see the old "not yet started" state until the cache refreshes. Ask them to reload if needed.', 'ffcertificate' ),
						'cancel'                         => __( 'Cancel', 'ffcertificate' ),
						'openEarlyConfirm'               => __( 'Confirm and start form', 'ffcertificate' ),
						'starting'                       => __( 'Starting…', 'ffcertificate' ),
						'openEarlySuccess'               => __( 'Form is now open.', 'ffcertificate' ),
						// Postpone close (6.5.12).
						'postponeClose'                  => __( 'Postpone close', 'ffcertificate' ),
						'postponeCloseTooltip'           => __( 'Move the form\'s close time later within the same day. One-shot per form.', 'ffcertificate' ),
						'postponeCloseTitle'             => __( 'Postpone form close?', 'ffcertificate' ),
						'postponeCloseBody'              => __( 'Pick a new close time within the same day. This action is one-shot — once confirmed it cannot be repeated from this page.', 'ffcertificate' ),
						'postponeCurrentLabel'           => __( 'Current scheduled close:', 'ffcertificate' ),
						'postponeNewLabel'               => __( 'New close time:', 'ffcertificate' ),
						'postponeIrreversible'           => __( 'This action can only be performed once per form.', 'ffcertificate' ),
						'postponeConfirm'                => __( 'Confirm postponement', 'ffcertificate' ),
						'postponing'                     => __( 'Postponing…', 'ffcertificate' ),
						'postponeSuccess'                => __( 'Close time postponed.', 'ffcertificate' ),
						'postponeInvalid'                => __( 'Please pick a valid time later than the current close (HH:MM).', 'ffcertificate' ),
						// Specific client-side validation messages (#243 Sprint 3).
						'postponeInvalidFormat'          => __( 'Please enter a valid time (HH:MM).', 'ffcertificate' ),
						/* translators: %s is the current close time in HH:MM */
						'postponeBeforeCurrent'          => __( 'Time must be later than the current close (%s).', 'ffcertificate' ),
						'postponeBeforeNow'              => __( 'Time must be in the future.', 'ffcertificate' ),
						// Schedule exception (#366 Sprint 4).
						'scheduleException'              => __( 'Entry/exit exception', 'ffcertificate' ),
						'scheduleExceptionTooltip'       => __( 'Create a one-use schedule exception for a single participant submission.', 'ffcertificate' ),
						'scheduleExceptionTitle'         => __( 'Schedule exception', 'ffcertificate' ),
						'scheduleExceptionBody'          => __( 'Set a different schedule for one participant. The next form submission opened from this modal will record this exception.', 'ffcertificate' ),
						'scheduleExceptionModeNow'       => __( 'End now (start stays at baseline)', 'ffcertificate' ),
						'scheduleExceptionModeManual'    => __( 'Edit both ends manually', 'ffcertificate' ),
						'scheduleExceptionStartLabel'    => __( 'New start:', 'ffcertificate' ),
						'scheduleExceptionEndLabel'      => __( 'New end:', 'ffcertificate' ),
						'scheduleExceptionConfirm'       => __( 'Create exception', 'ffcertificate' ),
						'scheduleExceptionSubmitting'    => __( 'Creating…', 'ffcertificate' ),
						'scheduleExceptionStaged'        => __( 'Exception staged. Open the participant\'s form in the next tab to consume it.', 'ffcertificate' ),
						'scheduleExceptionOpenForm'      => __( 'Open participant form', 'ffcertificate' ),
						'scheduleExceptionOpening'       => __( 'Opening the participant form in a new tab…', 'ffcertificate' ),
						'scheduleExceptionFormUrlLabel'  => __( 'The participant form opens at:', 'ffcertificate' ),
						'scheduleExceptionBadFormat'     => __( 'Please pick valid times (HH:MM).', 'ffcertificate' ),
						'scheduleExceptionRangeInverted' => __( 'Start must be earlier than end.', 'ffcertificate' ),
						/* translators: %1$s and %2$s are HH:MM bounds of the form's open window */
						'scheduleExceptionOutOfWindow'   => __( 'Range must stay within %1$s–%2$s.', 'ffcertificate' ),
						'scheduleExceptionNoChange'      => __( 'Override matches the baseline — nothing to do.', 'ffcertificate' ),

						// CPF / RF input validation messages. These ALSO
						// live in `ffc_ajax.strings` for pages that load
						// `ffc-frontend-js`; ffc-frontend-helpers.js reads
						// from whichever object is present. Duplication is
						// intentional pre-tech-debt cleanup (#243 Sprint 4):
						// the proper fix is to extract these into a shared
						// `ffc_common_strings` object localized on every
						// page that loads ffc-frontend-helpers.js, so each
						// shortcode-specific localize doesn't have to copy
						// the validation strings. Tracking issue: TBD.
						'cpfInvalid'                     => __( 'Invalid CPF', 'ffcertificate' ),
						'enterValidCpfRf'                => __( 'Enter a valid CPF (11 digits) or RF (7 digits)', 'ffcertificate' ),
						'idMustHaveDigits'               => __( 'The ID must have exactly 7 digits (RF) or 11 digits (CPF).', 'ffcertificate' ),
					),
				)
			);
		}
	}

	/**
	 * Localize geofence configuration for frontend
	 *
	 * @since 3.0.0
	 */
	private function localize_geofence_config(): void {
		global $post;

		if ( ! is_a( $post, 'WP_Post' ) ) {
			return;
		}

		// Find all form IDs in post content.
		//
		// Pre-6.6.8 used a hand-rolled regex that ONLY matched
		// `[ffc_form id="42"]` (double-quoted) and `[ffc_form id='42']`
		// (single-quoted). It silently missed two WP-valid formats that
		// are common in the wild:
		// `[ffc_form id=42]` (quotes are optional in shortcode atts) and
		// `[ffc_form id="42" class="extra"]` (additional attributes).
		// When the regex missed, `wp_localize_script` was never called
		// for that form, so the JS geofence layer never received its
		// config, never validated the datetime window, and the form
		// rendered as if no geofence existed. Users would set
		// "hide before start" in admin and still see the form on the
		// public page.
		//
		// Fix: use `get_shortcode_regex(['ffc_form'])`, the canonical
		// WP helper, then parse atts via `shortcode_parse_atts()` which
		// handles all the quoting variants WP's own parser accepts.
		$form_ids = array();
		$pattern  = get_shortcode_regex( array( 'ffc_form' ) );
		if ( preg_match_all( '/' . $pattern . '/', $post->post_content, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				// $match[3] holds the raw attribute string in WP's
				// get_shortcode_regex layout (group 3).
				$atts_raw = isset( $match[3] ) ? (string) $match[3] : '';
				$atts     = shortcode_parse_atts( $atts_raw );
				$id       = isset( $atts['id'] ) ? (int) $atts['id'] : 0;
				if ( $id > 0 ) {
					$form_ids[] = $id;
				}
			}
		}

		if ( empty( $form_ids ) ) {
			return;
		}

		$geofence_configs = array();

		foreach ( array_unique( $form_ids ) as $form_id_int ) {
			$config = \FreeFormCertificate\Security\Geofence::get_frontend_config( $form_id_int );

			if ( null !== $config ) {
				$geofence_configs[ $form_id_int ] = $config;
			}
		}

		// Add global settings without re-indexing form IDs.
		$ffc_global_debug            = class_exists( '\FreeFormCertificate\Core\Debug' )
			&& \FreeFormCertificate\Core\Debug::is_enabled( \FreeFormCertificate\Core\Debug::AREA_GEOFENCE );
		$geofence_configs['_global'] = array(
			'debug'   => $ffc_global_debug,
			'strings' => array(
				// Admin bypass messages.
				'bypassGeneric'             => __( 'Admin Bypass Mode Active - Geofence restrictions are disabled for administrators', 'ffcertificate' ),
				'bypassDatetime'            => __( 'Admin Bypass: Date/Time restrictions are disabled for administrators', 'ffcertificate' ),
				'bypassGeo'                 => __( 'Admin Bypass: Geolocation restrictions are disabled for administrators', 'ffcertificate' ),
				'bypassActive'              => __( 'Admin Bypass Mode Active', 'ffcertificate' ),

				// Geolocation messages.
				'detectingLocation'         => __( 'Detecting your location...', 'ffcertificate' ),
				'browserNoSupport'          => __( 'Your browser does not support geolocation.', 'ffcertificate' ),
				'httpsRequired'             => __( 'This form requires a secure connection (HTTPS) to access your location. Please contact the site administrator.', 'ffcertificate' ),
				'locationError'             => __( 'Unable to determine your location.', 'ffcertificate' ),
				'permissionDenied'          => __( 'Location permission denied. Please enable location services.', 'ffcertificate' ),
				'positionUnavailable'       => __( 'Location information is unavailable.', 'ffcertificate' ),
				'timeout'                   => __( 'Location request timed out.', 'ffcertificate' ),
				'outsideArea'               => __( 'You are outside the allowed area for this form.', 'ffcertificate' ),
				// Safari/iOS progressive loading phases.
				'safariPhase1'              => __( 'Requesting your location… If prompted, tap "Allow".', 'ffcertificate' ),
				'safariPhase2'              => __( 'Waiting for location permission… Check if a browser prompt appeared.', 'ffcertificate' ),
				'safariPhase3'              => __( 'Still trying to get your location… If it is not working, check that Location Services is enabled in Settings > Privacy & Security > Location Services.', 'ffcertificate' ),
				// Non-Safari progressive loading phases (used by Chrome /
				// Firefox / Edge). 6.7.7 — added to the map so Loco
				// translates them; before this fix the JS fell back to
				// the English literals because the keys were absent.
				'awaitingPermission'        => __( 'Waiting for location permission. Confirm the browser prompt if it appeared.', 'ffcertificate' ),
				'stillTrying'               => __( 'Still trying to get your location… Check that location is enabled in your device settings.', 'ffcertificate' ),
				// Safety-timeout fallback messages (fired when the
				// `navigator.geolocation` callback never resolves).
				// 6.7.7 — same backfill as the progressive-phase keys.
				'safariSafetyTimeout'       => __( "Your device didn't respond to the location request. On Safari/iOS, ensure Location Services is enabled in Settings > Privacy & Security > Location Services, then reload the page.", 'ffcertificate' ),
				'safetyTimeout'             => __( "Your device didn't respond to the location request. Make sure location is enabled and reload the page.", 'ffcertificate' ),
				// "Reload page" button label rendered alongside the
				// safety-timeout messages above. 6.7.7 — also was
				// missing from the map.
				'reloadPageBtn'             => __( 'Reload page', 'ffcertificate' ),
				// Safari/iOS specific error messages.
				'safariPermissionDenied'    => __( 'Location access was denied. On Safari/iOS, go to Settings > Privacy & Security > Location Services and ensure it is enabled for your browser.', 'ffcertificate' ),
				'safariPositionUnavailable' => __( 'Unable to determine your location. On Safari/iOS, ensure Location Services is enabled in Settings > Privacy & Security > Location Services.', 'ffcertificate' ),
				'safariTimeout'             => __( 'Location request timed out. On Safari/iOS, ensure Location Services is enabled in Settings > Privacy & Security > Location Services.', 'ffcertificate' ),

				// DateTime messages.
				'formNotYetAvailable'       => __( 'This form is not yet available.', 'ffcertificate' ),
				'formNoLongerAvailable'     => __( 'This form is no longer available.', 'ffcertificate' ),
				'formOnlyDuringHours'       => __( 'This form is only available during specific hours.', 'ffcertificate' ),

				// 6.6.4 Sprint 2 — cookie pre-flight banner.
				'cookieBlockedTitle'        => __( 'Cookies blocked', 'ffcertificate' ),
				'cookieBlockedBody'         => __( 'Cookies are blocked in this browser. Without cookies, the form submission may fail. Enable cookies for this site, or try a different browser.', 'ffcertificate' ),
				'cookieBlockedHowIos'       => __( 'On iOS: Settings → Safari → turn OFF "Block All Cookies".', 'ffcertificate' ),
				'cookieBlockedHowAndroid'   => __( 'On Android Chrome: Settings → Site Settings → Cookies → Allow.', 'ffcertificate' ),
				'cookieBlockedHowDesktop'   => __( 'On desktop: open Settings → Privacy → Cookies and allow first-party cookies for this site.', 'ffcertificate' ),
				'cookieTryAnyway'           => __( 'Try anyway', 'ffcertificate' ),

				// 6.6.4 Sprint 3 — GPS permission pre-check banners.
				'gpsDeniedTitle'            => __( 'Location blocked', 'ffcertificate' ),
				'gpsDeniedBody'             => __( 'This site needs your location, but the browser has it set to "block" for this site.', 'ffcertificate' ),
				'gpsDeniedHowIos'           => __( 'On iOS: tap the "AA" button in the address bar → Website Settings → Location → Allow.', 'ffcertificate' ),
				'gpsDeniedHowAndroid'       => __( 'On Android Chrome: tap the padlock in the address bar → Permissions → Location → Allow.', 'ffcertificate' ),
				'gpsDeniedHowDesktop'       => __( 'On desktop: click the padlock in the address bar → Site Settings → Location → Allow.', 'ffcertificate' ),
				'gpsTryAnyway'              => __( 'Try anyway', 'ffcertificate' ),
				'gpsPromptTitle'            => __( 'We need your location', 'ffcertificate' ),
				'gpsPromptBody'             => __( 'This site needs to confirm you are at the venue. After you tap "Continue", your browser will ask for permission.', 'ffcertificate' ),
				'gpsPromptContinue'         => __( 'Continue', 'ffcertificate' ),
			),
		);

		// Localize script with preserved array keys.
		wp_localize_script( 'ffc-geofence-frontend', 'ffcGeofenceConfig', $geofence_configs );
	}
}
