<?php
/**
 * Date-messages AJAX: recipient preview and test send.
 *
 * @package FreeFormCertificate\DateMessages
 * @since 6.33.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\DateMessages;

use FreeFormCertificate\Core\DocumentFormatter;
use FreeFormCertificate\Core\EmailSource;
use FreeFormCertificate\Core\RequestInput;
use FreeFormCertificate\Scheduling\SchedulingMailer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The two date-messages actions that work on a form not saved yet (#1538).
 *
 * PREVIEW IS THE SEND, MINUS THE SENDING. It walks the same
 * `RecipientResolver` the runner sends from, day by day over the range, and
 * returns the totals per decision -- plus the people by name, but only to an
 * operator holding the PII capability, with the address masked. It writes
 * nothing: no run, no log, no event.
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
	 * Most people listed by name; the totals count everyone.
	 */
	public const PREVIEW_ROW_LIMIT = 500;

	/**
	 * Register the handlers. Admin-only: both need a logged-in operator.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'wp_ajax_' . self::PREVIEW_ACTION, array( self::class, 'preview' ) );
		add_action( 'wp_ajax_' . self::TEST_ACTION, array( self::class, 'test_send' ) );
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

		wp_send_json_success( self::collect( $rule, $from, $to, DateMessagesAdminPage::can_view_pii() ) );
	}

	/**
	 * Walk the range through the resolver.
	 *
	 * @param Rule               $rule      The rule (saved or not).
	 * @param \DateTimeImmutable $from      First target day.
	 * @param \DateTimeImmutable $to        Last target day.
	 * @param bool               $with_rows Whether to list people.
	 * @return array{totals: array<string, int>, rows: array<int, array{name: string, email: string, date: string, decision: string}>, truncated: bool, pii: bool}
	 */
	public static function collect( Rule $rule, \DateTimeImmutable $from, \DateTimeImmutable $to, bool $with_rows ): array {
		$totals    = array_fill_keys( array_keys( DateMessagesAdminPage::decision_labels() ), 0 );
		$rows      = array();
		$truncated = false;
		$resolver  = new RecipientResolver();

		for ( $day = $from; $day <= $to; $day = $day->modify( '+1 day' ) ) {
			$after = 0;
			do {
				$page = $resolver->resolve( $rule, $day, $after, Runner::BATCH_SIZE );
				foreach ( $page['rows'] as $row ) {
					$totals[ $row['decision'] ] = ( $totals[ $row['decision'] ] ?? 0 ) + 1;
					if ( ! $with_rows ) {
						continue;
					}
					if ( count( $rows ) >= self::PREVIEW_ROW_LIMIT ) {
						$truncated = true;
						continue;
					}
					$rows[] = array(
						'name'     => $row['name'],
						'email'    => DocumentFormatter::mask_email( $row['email'] ),
						'date'     => $day->format( 'Y-m-d' ),
						'decision' => $row['decision'],
					);
				}
				$after = $page['cursor'];
			} while ( ! $page['complete'] );
		}

		return array(
			'totals'    => $totals,
			'rows'      => $rows,
			'truncated' => $truncated,
			'pii'       => $with_rows,
		);
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
			EmailSource::DATE_MESSAGES
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
