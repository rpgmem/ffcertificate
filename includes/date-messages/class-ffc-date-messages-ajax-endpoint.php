<?php
/**
 * Date-messages AJAX: recipient preview, message preview and test send.
 *
 * @package FreeFormCertificate\DateMessages
 * @since 6.33.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\DateMessages;

use FreeFormCertificate\Core\EmailSource;
use FreeFormCertificate\Core\RequestInput;
use FreeFormCertificate\Scheduling\SchedulingMailer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The date-messages actions that work on a form not saved yet (#1538).
 *
 * PREVIEW IS THE SEND, MINUS THE SENDING. It walks the same
 * `RecipientResolver` the runner sends from, day by day over the range, and
 * returns the totals per decision -- plus the people by name, but only to an
 * operator holding the PII capability, with the address masked. It writes
 * nothing: no run, no log, no event.
 *
 * MESSAGE PREVIEW renders the e-mail the form would send, with the same
 * fictional values as the test send and the rule's body appearance (#1660),
 * through the same `SchedulingMailer::document()` that sending wraps with --
 * so what the editor shows is what goes out. It sends and writes nothing.
 *
 * TEST SEND uses the fictional sample values, goes to the operator's own
 * address with `[TEST]` in the subject, and is neither logged nor counted.
 */
final class DateMessagesAjaxEndpoint {

	/**
	 * Preview action, also its nonce action.
	 */
	public const PREVIEW_ACTION = 'ffc_date_messages_preview';

	/**
	 * Test-send action, also its nonce action.
	 */
	public const TEST_ACTION = 'ffc_date_messages_test_send';

	/**
	 * Message-preview action, also its nonce action.
	 */
	public const MESSAGE_ACTION = 'ffc_date_messages_message_preview';

	/**
	 * Register the handlers. Admin-only: each needs a logged-in operator.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'wp_ajax_' . self::PREVIEW_ACTION, array( self::class, 'preview' ) );
		add_action( 'wp_ajax_' . self::TEST_ACTION, array( self::class, 'test_send' ) );
		add_action( 'wp_ajax_' . self::MESSAGE_ACTION, array( self::class, 'message_preview' ) );
	}

	/**
	 * The e-mail the form would send, rendered with sample values.
	 *
	 * @return void
	 */
	public static function message_preview(): void {
		check_ajax_referer( self::MESSAGE_ACTION, 'nonce' );
		if ( ! DateMessagesAdminPage::can_view() ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'ffcertificate' ) ), 403 );
		}

		$rule = self::posted_rule();
		if ( is_wp_error( $rule ) ) {
			wp_send_json_error( array( 'message' => $rule->get_error_message() ), 400 );
		}
		$source = DateSources::get( $rule->source );
		if ( null === $source ) {
			wp_send_json_error( array( 'message' => __( 'Unknown date source.', 'ffcertificate' ) ), 400 );
		}

		$today   = Runner::today();
		$target  = $today->modify( sprintf( '%+d days', -$rule->offset_days ) );
		$message = MessageBuilder::sample( $rule->subject, $rule->body, $source, $target, $today );
		$user    = wp_get_current_user();

		wp_send_json_success(
			array(
				'subject'  => $message['subject'],
				'html'     => SchedulingMailer::document( $message['body'], array_merge( $rule->appearance->document_args(), array( 'recipient' => (string) $user->user_email ) ) ),
				'contrast' => $rule->appearance->contrast(),
			)
		);
	}

	/**
	 * Who a rule would reach over a range.
	 *
	 * @return void
	 */
	public static function preview(): void {
		check_ajax_referer( self::PREVIEW_ACTION, 'nonce' );
		if ( ! DateMessagesAdminPage::can_view() ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'ffcertificate' ) ), 403 );
		}

		$rule = self::posted_rule();
		if ( is_wp_error( $rule ) ) {
			wp_send_json_error( array( 'message' => $rule->get_error_message() ), 400 );
		}

		$from = DateMessagesAdminPage::date( RequestInput::get_post_string( 'from' ) );
		$to   = DateMessagesAdminPage::date( RequestInput::get_post_string( 'to' ) );
		if ( null === $from || null === $to ) {
			wp_send_json_error( array( 'message' => __( 'Enter both dates.', 'ffcertificate' ) ), 400 );
		}
		$days = (int) $from->diff( $to )->format( '%r%a' );
		if ( $days < 0 || $days >= Runner::MAX_RANGE_DAYS ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %d: maximum number of days */
						__( 'Choose a range of 1 to %d days, starting on or before its end.', 'ffcertificate' ),
						Runner::MAX_RANGE_DAYS
					),
				),
				400
			);
		}

		wp_send_json_success( RecipientPreview::collect( $rule, $from, $to, DateMessagesAdminPage::can_view_pii() ) );
	}

	/**
	 * Send the form's message, with sample values, to the operator.
	 *
	 * @return void
	 */
	public static function test_send(): void {
		check_ajax_referer( self::TEST_ACTION, 'nonce' );
		if ( ! DateMessagesAdminPage::can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'ffcertificate' ) ), 403 );
		}

		$rule = self::posted_rule();
		if ( is_wp_error( $rule ) ) {
			wp_send_json_error( array( 'message' => $rule->get_error_message() ), 400 );
		}

		$source = DateSources::get( $rule->source );
		$user   = wp_get_current_user();
		if ( null === $source || ! is_email( $user->user_email ) ) {
			wp_send_json_error( array( 'message' => __( 'Your account has no valid e-mail address.', 'ffcertificate' ) ), 400 );
		}

		$today   = Runner::today();
		$target  = $today->modify( sprintf( '%+d days', -$rule->offset_days ) );
		$message = MessageBuilder::sample( $rule->subject, $rule->body, $source, $target, $today );
		$sent    = SchedulingMailer::send(
			$user->user_email,
			/* translators: %s: e-mail subject */
			sprintf( __( '[TEST] %s', 'ffcertificate' ), $message['subject'] ),
			$message['body'],
			array(),
			true,
			EmailSource::DATE_MESSAGES,
			$rule->appearance->document_args()
		);

		if ( ! $sent ) {
			wp_send_json_error( array( 'message' => __( 'The test message was not sent. Check whether e-mails are disabled in the settings.', 'ffcertificate' ) ), 500 );
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %s: e-mail address */
					__( 'Test message sent to %s.', 'ffcertificate' ),
					$user->user_email
				),
			)
		);
	}

	/**
	 * The rule a request is about: a saved one by id, or the editor's
	 * unsaved values. An unsaved rule needs no name to be previewed.
	 *
	 * @return Rule|\WP_Error
	 */
	private static function posted_rule() {
		$id = RequestInput::get_post_int( 'rule_id' );
		if ( $id > 0 && ! RequestInput::has_post( 'rule' ) ) {
			$rule = RuleReader::get_by_id( $id );
			return null === $rule ? new \WP_Error( 'ffc_rule_missing', __( 'That rule no longer exists.', 'ffcertificate' ) ) : $rule;
		}

		$data = DateMessagesAdminPage::form_data( RequestInput::get_post_raw_array( 'rule' ) );
		if ( '' === trim( is_string( $data['name'] ) ? $data['name'] : '' ) ) {
			$data['name'] = __( 'Preview', 'ffcertificate' );
		}
		$data['id'] = $id;

		return Rule::from_array( $data );
	}
}
