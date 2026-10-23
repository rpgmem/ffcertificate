<?php
/**
 * Shortcodes
 * Handles shortcode rendering for forms and verification pages.
 *
 * V2.8.0: Added magic link detection and certificate preview
 * v2.9.0: Added hash-based token support (#token=)
 * v2.9.2: OPTIMIZED to use FFC_Utils functions
 * v3.3.0: Added strict types and type hints
 * v3.2.0: Migrated to namespace
 *
 * @package FreeFormCertificate\Frontend
 */

declare(strict_types=1);

namespace FreeFormCertificate\Frontend;

use FreeFormCertificate\Core\Button;
use FreeFormCertificate\Core\Utils;
use FreeFormCertificate\Core\RequestInput;

use FreeFormCertificate\Core\SecurityService;
use FreeFormCertificate\Submissions\SubmissionHandler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shortcodes.
 */
class Shortcodes {

	/**
	 * Constructor
	 */
	public function __construct() {
	}

	/**
	 * Generate HTML for security fields (honeypot + captcha)
	 *
	 * Thin delegate since 6.23.0: the markup is the shared
	 * `templates/security-fields.php`, and the challenge inside it comes from
	 * whichever provider is configured. Kept as a method because the public
	 * CSV download shortcode renders through it.
	 */
	public function generate_security_fields(): string {
		return SecurityService::render_security_fields();
	}

	/**
	 * Render certificate preview for magic link access
	 *
	 * @since 2.8.0 Magic Links feature
	 * @param string $token Magic token.
	 * @return string HTML output
	 */
	private function render_magic_link_preview( string $token ): string {
		\FreeFormCertificate\Core\Debug::log_frontend(
			'Magic link shortcode rendered',
			array(
				'token' => substr( $token, 0, 8 ) . '...',
				'ip'    => \FreeFormCertificate\Core\RequestInput::get_user_ip(),
			)
		);

		ob_start();
		?>
		<div class="ffc-shortcode ffc-verification-container ffc-magic-link-container" data-token="<?php echo esc_attr( $token ); ?>">
			<div class="ffc-verify-loading" role="status" aria-live="polite">
				<div class="ffc-spinner" aria-hidden="true"></div>
				<p><?php esc_html_e( 'Verifying certificate...', 'ffcertificate' ); ?></p>
			</div>
			<div class="ffc-verify-result" role="region" aria-live="polite"></div>
		</div>
		<?php
		$verify_html = ob_get_clean();
		return $verify_html ? $verify_html : '';
	}

	/**
	 * Shortcode: [ffc_verification]
	 * Displays certificate verification form (or magic link preview)
	 *
	 * V2.8.0: Detects ?token= parameter and renders preview instead of form
	 * v2.9.0: Also supports #token= (hash format) via JavaScript detection
	 *
	 * @param array<string, mixed> $atts Shortcode attributes.
	 */
	public function render_verification_page( array $atts ): string {
		// Check for magic token in URL query string (?token=).
		$magic_token = RequestInput::get_get_string( 'token' );

		if ( ! empty( $magic_token ) ) {
			// Magic link access via query string - render preview container.
			return $this->render_magic_link_preview( $magic_token );
		}

		\FreeFormCertificate\Core\Debug::log_frontend(
			'Verification shortcode rendered',
			array(
				'ip'        => \FreeFormCertificate\Core\RequestInput::get_user_ip(),
				'has_token' => false,
			)
		);

		// Render verification page using template.
		$security_fields = $this->generate_security_fields();

		ob_start();
		include FFC_PLUGIN_DIR . 'templates/verification-page.php';
		$verification_page_html = ob_get_clean();
		return $verification_page_html ? $verification_page_html : '';
	}

	/**
	 * What a form shows instead of itself when an invitation is refused or
	 * required (#1948).
	 *
	 * @param int    $form_id Form post ID.
	 * @param string $message Why.
	 * @return string
	 */
	private function invite_refusal( int $form_id, string $message ): string {
		return '<div class="ffc-shortcode ffc-form-wrapper" id="ffc-form-' . esc_attr( (string) $form_id ) . '">'
			. \FreeFormCertificate\Core\Notice::render( 'warning', 'lock', $message, '', array( 'hook' => 'invite' ) )
			. '</div>';
	}

	/**
	 * Shortcode: [ffc_form id="123"]
	 * Displays certificate issuance form
	 *
	 * @param array<string, mixed> $atts Shortcode attributes.
	 */
	public function render_form( array $atts ): string {
		$atts    = shortcode_atts( array( 'id' => 0 ), $atts, 'ffc_form' );
		$form_id = absint( $atts['id'] );

		if ( ! $form_id || get_post_type( $form_id ) !== 'ffc_form' ) {
			\FreeFormCertificate\Core\Debug::log_frontend(
				'Invalid form shortcode',
				array(
					'form_id' => $form_id,
					'ip'      => \FreeFormCertificate\Core\RequestInput::get_user_ip(),
				)
			);
			return '<p>' . esc_html__( 'Form not found.', 'ffcertificate' ) . '</p>';
		}

		$form_post  = get_post( $form_id );
		$form_title = $form_post ? $form_post->post_title : '';
		$fields     = get_post_meta( $form_id, '_ffc_form_fields', true );

		if ( empty( $fields ) ) {
			\FreeFormCertificate\Core\Debug::log_frontend(
				'Form has no fields',
				array(
					'form_id' => $form_id,
				)
			);
			return '<p>' . esc_html__( 'Form has no fields.', 'ffcertificate' ) . '</p>';
		}

		\FreeFormCertificate\Core\Debug::log_frontend(
			'Form shortcode rendered',
			array(
				'form_id'      => $form_id,
				'form_title'   => \FreeFormCertificate\Core\Utils::truncate( $form_title, 50 ),
				'fields_count' => count( $fields ),
				'ip'           => \FreeFormCertificate\Core\RequestInput::get_user_ip(),
			)
		);

		$geofence_config = get_post_meta( $form_id, '_ffc_geofence_config', true );
		$has_geofence    = false;
		if ( is_array( $geofence_config ) ) {
			$has_datetime = ! empty( $geofence_config['datetime_enabled'] ) && '1' === $geofence_config['datetime_enabled'];
			$has_geo      = ! empty( $geofence_config['geo_enabled'] ) && '1' === $geofence_config['geo_enabled'];
			$has_geofence = $has_datetime || $has_geo;
		}
		$wrapper_class = $has_geofence ? 'ffc-shortcode ffc-form-wrapper ffc-has-geofence' : 'ffc-shortcode ffc-form-wrapper';

		// Schedule exception consumption (#366 Sprint 5). If the operator
		// just staged an exception in another tab, the per-form cookie is
		// present here. Read + verify + clear in one shot (the cookie is
		// strictly one-use; the submission handler in Sprint 6 verifies
		// the in-form hidden-input copy of the same token, not the
		// cookie, so the cookie has done its job once we've embedded the
		// token below). When the cookie is missing / tampered / expired,
		// the read returns null and the form renders untouched — zero
		// behaviour change for the 99% who never trigger this flow.
		//
		// We capture the raw cookie value BEFORE clearing so the hidden
		// input below carries the exact bytes the operator's token
		// produced — Sprint 6 can hash_equals against the cookie token
		// for forensic match if needed, and a re-sign here would emit a
		// new signature for the same payload, breaking that property.
		$schedule_exception_cookie = '';
		$cookie_name               = \FreeFormCertificate\Frontend\ScheduleExceptionSession::cookie_name( $form_id );
		if ( ! empty( $_COOKIE[ $cookie_name ] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cookie payload is only passed to ScheduleExceptionSession, which verifies its HMAC before use.
			$schedule_exception_cookie = (string) wp_unslash( $_COOKIE[ $cookie_name ] );
		}
		$schedule_exception = \FreeFormCertificate\Frontend\ScheduleExceptionSession::read_from_cookie( $form_id );
		if ( null !== $schedule_exception ) {
			// #Item11 — a spent exception (its jti already claimed by a
			// prior submission) is dead: don't re-render the banner or
			// re-embed the token, even though the cookie survived. The
			// cookie can't be cleared from here (this runs in the_content,
			// after headers are sent), so the consumed-jti ledger — not the
			// cookie — is the source of truth for one-use.
			$exc_jti = (string) ( $schedule_exception['jti'] ?? '' );
			if ( '' === $exc_jti || \FreeFormCertificate\Frontend\ScheduleExceptionSession::is_jti_consumed( $exc_jti ) ) {
				$schedule_exception        = null;
				$schedule_exception_cookie = '';
			} else {
				// Best-effort clear (no-op once headers are sent — see above).
				\FreeFormCertificate\Frontend\ScheduleExceptionSession::clear( $form_id );
			}
		} else {
			// Clear the captured raw value too so a tampered cookie can't
			// reach the hidden input via the back door.
			$schedule_exception_cookie = '';
		}

		$form_config = get_post_meta( $form_id, '_ffc_form_config', true );

		// A personal invitation fills in and locks the person's name and
		// e-mail (#1948); a form that admits invited people only shows nothing
		// else to whoever arrives without one.
		$invite      = null;
		$invite_code = \FreeFormCertificate\Core\RequestInput::get_get_string( FormInvite::QUERY_VAR );
		if ( '' !== $invite_code ) {
			$resolved = FormInvite::resolve( $form_id, $invite_code );
			if ( $resolved instanceof \WP_Error ) {
				return $this->invite_refusal( $form_id, $resolved->get_error_message() );
			}
			$invite = $resolved;
			$fields = FormInvite::fill_fields( $fields, $invite );
		} elseif ( FormInvite::invite_only( is_array( $form_config ) ? $form_config : array() ) ) {
			return $this->invite_refusal( $form_id, __( 'This form is open by invitation only. Use the link in the e-mail you received.', 'ffcertificate' ) );
		}

		$quiz_leave  = $this->quiz_leave_questions( is_array( $form_config ) ? $form_config : array(), $fields );
		$leave_level = \FreeFormCertificate\Core\QuizLeaveLevel::of( is_array( $form_config ) ? $form_config : array() );

		ob_start();
		?>
		<div class="<?php echo esc_attr( $wrapper_class ); ?>" id="ffc-form-<?php echo esc_attr( (string) $form_id ); ?>">
			<?php
			// System notices sit above the title, after the admin-bypass ones
			// the geofence script prepends to the wrapper (#1842).
			if ( null !== $schedule_exception ) {
				$schedule_notice = \FreeFormCertificate\Core\Notice::render(
					'info',
					'clock',
					sprintf(
						/* translators: 1: override start time HH:MM, 2: override end time HH:MM. "baseline" displayed when the operator did not override that end. */
						__( '%1$s to %2$s. This submission will be recorded with the adjusted schedule.', 'ffcertificate' ),
						'' !== (string) ( $schedule_exception['start'] ?? '' ) ? (string) $schedule_exception['start'] : __( 'baseline', 'ffcertificate' ),
						'' !== (string) ( $schedule_exception['end'] ?? '' ) ? (string) $schedule_exception['end'] : __( 'baseline', 'ffcertificate' )
					),
					__( 'Schedule exception active:', 'ffcertificate' ),
					array(
						'role' => 'status',
						'live' => 'polite',
						'hook' => 'schedule-exception',
					)
				);
				echo $schedule_notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Notice::render() escapes every value.
			}
			if ( null !== $quiz_leave ) {
				echo $this->quiz_leave_notice( $leave_level ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Notice::render() escapes every value.
			}
			if ( null !== $invite ) {
				$invite_notice = \FreeFormCertificate\Core\Notice::render(
					'info',
					'award',
					'' !== $invite['event']
						/* translators: 1: the invited person's name, 2: the event */
						? sprintf( __( 'Filling in as %1$s, for %2$s. Your name and e-mail come from your registration.', 'ffcertificate' ), $invite['name'], $invite['event'] )
						/* translators: %s: the invited person's name */
						: sprintf( __( 'Filling in as %s. Your name and e-mail come from your registration.', 'ffcertificate' ), $invite['name'] ),
					__( 'Invitation:', 'ffcertificate' ),
					array( 'hook' => 'invite' )
				);
				echo $invite_notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Notice::render() escapes every value.
			}

			// What the visitor sees when this browser cannot run the form
			// (#2030). The form below posts with POST, so a submit that reaches
			// the browser's default action (no script ran) keeps the name,
			// e-mail and CPF/RF out of the URL, the history and the server logs.
			echo BrowserGate::form_markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Notice::render() and Button::html() escape every value.
			?>
			<h2 class="ffc-form-title"><?php echo esc_html( $form_title ); ?></h2>
			<form class="ffc-submission-form" id="ffc-form-element-<?php echo esc_attr( (string) $form_id ); ?>" method="post" autocomplete="off" aria-label="<?php echo esc_attr( $form_title ); ?>"<?php echo null !== $quiz_leave ? $this->quiz_leave_attributes( $quiz_leave, $leave_level ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every value escaped in quiz_leave_attributes(). ?>>
				<input type="hidden" name="form_id" value="<?php echo esc_attr( (string) $form_id ); ?>">
				<?php if ( null !== $invite ) : ?>
					<input type="hidden" name="<?php echo esc_attr( FormInvite::QUERY_VAR ); ?>" value="<?php echo esc_attr( $invite_code ); ?>">
				<?php endif; ?>
				<?php if ( null !== $quiz_leave ) : ?>
					<?php $this->render_quiz_leave_dialog( $form_id ); ?>
				<?php endif; ?>
				<?php if ( null !== $schedule_exception && '' !== $schedule_exception_cookie ) : ?>
					<input type="hidden" name="ffc_schedule_exception_token" value="<?php echo esc_attr( $schedule_exception_cookie ); ?>">
				<?php endif; ?>

				<?php
				foreach ( $fields as $field ) :
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_field() escapes all output internally
					echo $this->render_field( $field );
				endforeach;
				?>

				<?php
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- generate_security_fields() escapes all output internally
				echo $this->generate_security_fields();
				?>

				<?php
				$restrictions = isset( $form_config['restrictions'] ) ? $form_config['restrictions'] : array();

				// Password field (if active).
				if ( ! empty( $restrictions['password'] ) && '1' === $restrictions['password'] ) {
					?>
					<div class="ffc-form-field ffc-restriction-field">
						<label for="ffc_password">
							<?php esc_html_e( 'Password', 'ffcertificate' ); ?> <span class="ffc-required" aria-hidden="true">*</span>
						</label>
						<input type="password"
								class="ffc-input"
								name="ffc_password"
								id="ffc_password"
								required
								aria-required="true"
								autocomplete="off"
								maxlength="20"
								placeholder="<?php esc_attr_e( 'Enter password', 'ffcertificate' ); ?>">
					</div>
					<?php
				}

				// Ticket field (if active).
				if ( ! empty( $restrictions['ticket'] ) && '1' === $restrictions['ticket'] ) {
					?>
					<div class="ffc-form-field ffc-restriction-field">
						<label for="ffc_ticket">
							<?php esc_html_e( 'Ticket Code', 'ffcertificate' ); ?> <span class="ffc-required" aria-hidden="true">*</span>
						</label>
						<input type="text"
								class="ffc-input ffc-ticket-input ffc-uppercase"
								name="ffc_ticket"
								id="ffc_ticket"
								required
								aria-required="true"
								aria-describedby="ffc-ticket-desc"
								placeholder="ABCD-1234"
								autocomplete="off"
								maxlength="9">
						<p class="description" id="ffc-ticket-desc"><?php esc_html_e( 'Enter your unique ticket code', 'ffcertificate' ); ?></p>
					</div>
					<?php
				}
				?>

				<div class="ffc-lgpd-consent">
					<label class="ffc-consent-label">
						<input type="checkbox"
								name="ffc_lgpd_consent"
								id="ffc_lgpd_consent"
								required
								aria-required="true"
								value="1">
						
						<span class="ffc-consent-text">
							<?php
							echo wp_kses_post(
								sprintf(
								/* translators: %s: Privacy Policy page link */
									esc_html__( 'I have read and agree to the %s and authorize the storage of my personal data for certificate issuance.', 'ffcertificate' ),
									'<a href="' . esc_url( get_privacy_policy_url() ) . '" target="_blank">' . esc_html__( 'Privacy Policy', 'ffcertificate' ) . '</a>'
								)
							);
							?>
							<span class="ffc-required" aria-hidden="true">*</span>
						</span>
					</label>
					
					<p class="ffc-consent-description">
						<?php esc_html_e( 'Your data will be stored securely and encrypted. You can request deletion at any time.', 'ffcertificate' ); ?>
					</p>

					<?php
					$ffc_form_device_on   = '1' === (string) get_post_meta( $form_id, '_ffc_device_limit_enabled', true );
					$ffc_device_global_on = false;
					if ( class_exists( '\FreeFormCertificate\Security\RateLimiter' ) ) {
						$ffc_rl_settings      = \FreeFormCertificate\Security\RateLimiter::get_settings();
						$ffc_device_global_on = ! empty( $ffc_rl_settings['device']['enabled'] );
					}
					if ( $ffc_device_global_on && $ffc_form_device_on ) :
						?>
						<details class="ffc-consent-description ffc-device-disclosure">
							<summary>
								<?php esc_html_e( 'We anonymously identify your device to prevent duplicate submissions.', 'ffcertificate' ); ?>
								<span class="ffc-device-disclosure-more"><?php esc_html_e( 'Learn more.', 'ffcertificate' ); ?></span>
							</summary>
							<p>
								<?php esc_html_e( 'Technical details: this form uses the open-source thumbmarkjs library (MIT) to produce an anonymized device identifier (browser, screen, GPU, fonts). Everything is processed locally; no data is sent to any third-party server.', 'ffcertificate' ); ?>
							</p>
						</details>
						<?php
					elseif ( $ffc_device_global_on ) :
						// Reaching here means the global subsystem is on but this
						// form has the device limit off — so no device signal is
						// collected. The notice must stay a generic logging/audit
						// statement (true regardless of the device limit) and make
						// NO device-duplicate claim (#647).
						?>
						<p class="ffc-consent-description ffc-fraud-monitoring-notice">
							<?php esc_html_e( 'Submissions to this form are logged and may be audited for fraud prevention.', 'ffcertificate' ); ?>
						</p>
					<?php endif; ?>
				</div>

				<?php
				// `ffc-submit-btn` is the hook scripts and an install's own CSS
				// read; the look is the family's, full width (#1880).
				Button::render(
					array(
						'label'    => __( 'Submit', 'ffcertificate' ),
						'category' => 'primary',
						'icon'     => 'checkmark',
						'type'     => 'submit',
						'class'    => 'ffc-btn-block ffc-submit-btn',
					)
				);
				?>
			</form>
		</div>
		<?php
		$form_html = ob_get_clean();
		return $form_html ? $form_html : '';
	}

	/**
	 * The scored questions a "leave the window" reset clears, or null when
	 * the form does not reset (#1731).
	 *
	 * Only the answers are cleared: the name, the CPF/RF and every other
	 * field the quiz does not score are the participant's, not the quiz's.
	 *
	 * @param array<array-key, mixed>          $form_config Form configuration.
	 * @param array<int, array<string, mixed>> $fields      Form fields.
	 * @return list<string>|null
	 */
	private function quiz_leave_questions( array $form_config, array $fields ): ?array {
		if ( \FreeFormCertificate\Core\QuizLeaveLevel::OFF === \FreeFormCertificate\Core\QuizLeaveLevel::of( $form_config ) ) {
			return null;
		}
		$names = array();
		foreach ( $fields as $field ) {
			if ( \FreeFormCertificate\Core\QuizQuestion::is_scored( $field ) && is_scalar( $field['name'] ?? null ) ) {
				$names[] = (string) $field['name'];
			}
		}
		return $names;
	}

	/**
	 * The attributes that tell `ffc-quiz-leave.js` what to watch (#1731,
	 * #1745): the scored questions, the level, and at Strict the grace a
	 * lost focus must outlast.
	 *
	 * @param array  $questions Scored question names.
	 * @param string $level     A {@see \FreeFormCertificate\Core\QuizLeaveLevel} value.
	 * @phpstan-param list<string> $questions
	 * @return string Escaped attributes, each with its leading space.
	 */
	private function quiz_leave_attributes( array $questions, string $level ): string {
		$attrs = ' data-ffc-quiz-leave="' . esc_attr( (string) wp_json_encode( $questions ) ) . '"'
			. ' data-ffc-quiz-leave-level="' . esc_attr( $level ) . '"';
		if ( \FreeFormCertificate\Core\QuizLeaveLevel::STRICT === $level ) {
			$attrs .= ' data-ffc-quiz-leave-grace="' . esc_attr( (string) \FreeFormCertificate\Settings\SettingsReader::quiz_focus_grace_ms() ) . '"';
		}
		return $attrs;
	}

	/**
	 * The notice a reset quiz shows before the participant starts (#1731).
	 * It is a system notice, so it sits above the form title (#1842).
	 *
	 * @param string $level A {@see \FreeFormCertificate\Core\QuizLeaveLevel} value.
	 * @return string
	 */
	private function quiz_leave_notice( string $level ): string {
		return \FreeFormCertificate\Core\Notice::render(
			'info',
			'eye',
			\FreeFormCertificate\Core\QuizLeaveLevel::STRICT === $level
				? __( 'Switching to another tab, minimising the browser or clicking another window clears your answers, and the number of times you leave is recorded with your submission.', 'ffcertificate' )
				: __( 'Switching to another tab or minimising the browser clears your answers, and the number of times you leave is recorded with your submission.', 'ffcertificate' ),
			__( 'Stay on this page while you answer.', 'ffcertificate' ),
			array(
				'role' => 'note',
				'hook' => 'quiz-leave',
			)
		);
	}

	/**
	 * The leave counter a reset quiz posts, and the dialog it opens on
	 * return (#1731).
	 *
	 * The dialog is markup rather than a script string, so it is translated
	 * where every other string of the form is.
	 *
	 * @param int $form_id Form ID.
	 */
	private function render_quiz_leave_dialog( int $form_id ): void {
		$title_id = 'ffc-quiz-leave-title-' . $form_id;
		$text_id  = 'ffc-quiz-leave-text-' . $form_id;
		?>
		<input type="hidden" name="ffc_quiz_focus_losses" value="0" class="ffc-quiz-focus-losses">
		<div class="ffc-modal ffc-quiz-leave-overlay" role="alertdialog" aria-modal="true" aria-labelledby="<?php echo esc_attr( $title_id ); ?>" aria-describedby="<?php echo esc_attr( $text_id ); ?>" hidden>
			<div class="ffc-modal-backdrop"></div>
			<div class="ffc-modal-content">
				<div class="ffc-modal-header">
					<h3 id="<?php echo esc_attr( $title_id ); ?>"><?php esc_html_e( 'Your answers were cleared', 'ffcertificate' ); ?></h3>
				</div>
				<div class="ffc-modal-body">
					<p id="<?php echo esc_attr( $text_id ); ?>"><?php esc_html_e( 'You left this page while answering the quiz, so the answers were cleared. Leaving does not count as an attempt. Start again reloads the quiz.', 'ffcertificate' ); ?></p>
				</div>
				<div class="ffc-modal-footer">
					<?php
					Button::render(
						array(
							'label'    => __( 'Start again', 'ffcertificate' ),
							'category' => 'neutral',
							'icon'     => 'sync',
							'class'    => 'ffc-submit-btn ffc-quiz-leave-restart',
						)
					);
					?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render individual form field
	 *
	 * @param array<string, mixed> $field Field configuration.
	 */
	private function render_field( array $field ): string {
		$type    = isset( $field['type'] ) ? $field['type'] : 'text';
		$name    = isset( $field['name'] ) ? $field['name'] : '';
		$label   = isset( $field['label'] ) ? $field['label'] : '';
		$default = isset( $field['default_value'] ) ? $field['default_value'] : '';

		// Info block: display-only, no input.
		if ( 'info' === $type ) {
			$content = isset( $field['content'] ) ? $field['content'] : '';
			if ( empty( $content ) && empty( $label ) ) {
				return '';
			}
			ob_start();
			?>
			<div class="ffc-form-info-block">
				<?php if ( ! empty( $label ) ) : ?>
					<h4 class="ffc-info-title"><?php echo esc_html( $label ); ?></h4>
				<?php endif; ?>
				<?php if ( ! empty( $content ) ) : ?>
					<div class="ffc-info-content"><?php echo wp_kses_post( $content ); ?></div>
				<?php endif; ?>
			</div>
			<?php
			$info_block_html = ob_get_clean();
			return $info_block_html ? $info_block_html : '';
		}

		// Embed block: display media via oembed or img tag.
		if ( 'embed' === $type ) {
			$embed_url = isset( $field['embed_url'] ) ? $field['embed_url'] : '';
			if ( empty( $embed_url ) ) {
				return '';
			}
			ob_start();
			?>
			<div class="ffc-form-embed-block">
				<?php if ( ! empty( $label ) ) : ?>
					<p class="ffc-embed-caption"><?php echo esc_html( $label ); ?></p>
				<?php endif; ?>
				<div class="ffc-embed-media">
					<?php
					// Check if URL is a direct image.
					if ( preg_match( '/\.(jpe?g|png|gif|webp|svg)(\?.*)?$/i', $embed_url ) ) {
						echo '<img src="' . esc_url( $embed_url ) . '" alt="' . esc_attr( $label ) . '" class="ffc-embed-image">';
					} else {
						// Try oembed (YouTube, Vimeo, audio, etc.).
						$embed_html = wp_oembed_get( $embed_url, array( 'width' => 600 ) );
						if ( $embed_html ) {
							echo $embed_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_oembed_get returns sanitized HTML from trusted providers.
						} else {
							// Fallback: show as a link.
							echo '<a href="' . esc_url( $embed_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $embed_url ) . '</a>';
						}
					}
					?>
				</div>
			</div>
			<?php
			$embed_html_out = ob_get_clean();
			return $embed_html_out ? $embed_html_out : '';
		}

		if ( empty( $name ) ) {
			return '';
		}

		// Special treatment for CPF/RF.
		if ( 'cpf_rf' === $name ) {
			$type = 'tel';
		}

		// Render hidden field outside the visual structure.
		if ( 'hidden' === $type ) {
			return '<input type="hidden" name="' . esc_attr( $name ) . '" id="' . esc_attr( $name ) . '" value="' . esc_attr( $default ) . '">';
		}

		return ( new FormFieldRenderer() )->render( $field, $type );
	}
}