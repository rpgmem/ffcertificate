<?php
/**
 * QuizGradingHandler
 *
 * Saves the grades a person gives an attempt's open answers (#1732), on the
 * `admin_post_ffc_grade_submission` action the grading card of the submission
 * edit screen posts to. The card is its own `<form>` rather than a part of the
 * edit form, because the edit form's save rebuilds the answers from what is
 * posted and must not also decide a result.
 *
 * Grading is the moment the attempt's result becomes known, so this is also
 * where the participant hears about it: one email for a pass, carrying the
 * document link the attempt already owns, one for a result below the passing
 * score. Both go through the shared pipeline, so the global kill-switch holds.
 *
 * @package FreeFormCertificate\Admin
 * @since 6.39.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

use FreeFormCertificate\Core\ArrayValue;
use FreeFormCertificate\Core\RequestInput;
use FreeFormCertificate\Core\SubmissionStatus;
use FreeFormCertificate\Submissions\SubmissionHandler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Request handler for the grading card.
 *
 * @phpstan-import-type GradingResult from QuizGrading
 */
class QuizGradingHandler {

	use \FreeFormCertificate\Core\EmailHelperTrait;

	/**
	 * The `admin_post_*` action.
	 */
	public const ACTION = 'ffc_grade_submission';

	/**
	 * Capability that may grade.
	 */
	public const CAPABILITY = 'ffc_grade_certificates';

	/**
	 * Submission handler.
	 *
	 * @var SubmissionHandler
	 */
	private SubmissionHandler $submission_handler;

	/**
	 * Constructor.
	 *
	 * @param SubmissionHandler $handler Submission handler.
	 */
	public function __construct( SubmissionHandler $handler ) {
		$this->submission_handler = $handler;
	}

	/**
	 * Register the request hook. `admin_post_*` runs only for a logged-in user,
	 * which is the only kind that can hold the capability.
	 */
	public function init(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Nonce action for one submission.
	 *
	 * @param int $submission_id Submission ID.
	 */
	public static function nonce_action( int $submission_id ): string {
		return self::ACTION . '_' . $submission_id;
	}

	/**
	 * Whether the current user may grade.
	 */
	public static function current_user_can_grade(): bool {
		return \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( self::CAPABILITY );
	}

	/**
	 * Handle the grading POST.
	 */
	public function handle(): void {
		$id = RequestInput::get_post_int( 'submission_id' );
		check_admin_referer( self::nonce_action( $id ) );

		if ( ! self::current_user_can_grade() ) {
			wp_die( esc_html__( 'You do not have permission to grade quiz answers.', 'ffcertificate' ), '', array( 'response' => 403 ) );
		}

		$this->redirect( $this->grade( $id, $this->posted_grades() ) );
	}

	/**
	 * Grade one attempt. Returns the notice key the list screen shows.
	 *
	 * @param int                $id     Submission ID.
	 * @param array<string, int> $grades Points given, keyed by question name.
	 * @return string `quiz_graded_passed`, `quiz_graded_not_passed`, `quiz_grade_stale` or `quiz_grade_incomplete`.
	 */
	public function grade( int $id, array $grades ): string {
		$sub = $this->submission_handler->get_submission( $id );
		if ( ! is_array( $sub ) || SubmissionStatus::QUIZ_PENDING !== ( $sub['status'] ?? '' ) ) {
			// Already graded in another tab, or never waiting.
			return 'quiz_grade_stale';
		}

		$form_id     = (int) ( $sub['form_id'] ?? 0 );
		$data        = json_decode( is_string( $sub['data'] ?? null ) ? $sub['data'] : '', true );
		$data        = is_array( $data ) ? $data : array();
		$fields      = get_post_meta( $form_id, '_ffc_form_fields', true );
		$form_config = get_post_meta( $form_id, '_ffc_form_config', true );

		$result = QuizGrading::apply(
			$data,
			QuizGrading::pending_questions( $data, is_array( $fields ) ? $fields : array() ),
			is_array( $form_config ) ? $form_config : array(),
			$grades
		);
		if ( is_wp_error( $result ) ) {
			return 'quiz_grade_incomplete';
		}

		$this->persist( $id, $result, $sub );

		\FreeFormCertificate\Core\ActivityLog::log(
			'quiz_graded',
			\FreeFormCertificate\Core\ActivityLog::LEVEL_INFO,
			array(
				'form_id' => $form_id,
				'percent' => $result['percent'],
				'status'  => $result['status'],
			),
			get_current_user_id(),
			$id
		);

		$this->send_result_email( $sub, $result, is_array( $form_config ) ? $form_config : array() );

		return $result['passed'] ? 'quiz_graded_passed' : 'quiz_graded_not_passed';
	}

	/**
	 * The grades posted by the card, as integers. A blank input is left out,
	 * so {@see QuizGrading::apply()} reports it missing instead of reading 0.
	 *
	 * @return array<string, int>
	 */
	private function posted_grades(): array {
		$grades = array();
		foreach ( RequestInput::get_post_array( 'ffc_grade' ) as $name => $value ) {
			if ( is_string( $value ) && is_numeric( $value ) ) {
				$grades[ sanitize_key( (string) $name ) ] = (int) $value;
			}
		}
		return $grades;
	}

	/**
	 * Write the new answers and status, the way the persister writes an attempt.
	 *
	 * @param int                  $id     Submission ID.
	 * @param array                $result Grading result.
	 * @param array<string, mixed> $sub    Stored row.
	 * @phpstan-param GradingResult $result
	 */
	private function persist( int $id, array $result, array $sub ): void {
		$json   = wp_json_encode( $result['data'], JSON_UNESCAPED_UNICODE );
		$json   = is_string( $json ) ? $json : '{}';
		$fields = array( 'status' => $result['status'] );
		if ( class_exists( '\FreeFormCertificate\Core\Encryption' ) && \FreeFormCertificate\Core\Encryption::is_configured() ) {
			$fields['data_encrypted'] = \FreeFormCertificate\Core\Encryption::encrypt( $json );
		} else {
			$fields['data'] = $json;
		}
		$this->submission_handler->get_repository()->update( $id, $fields );

		// The lookups the certificate is reached by are cached under their own
		// keys, which an update by id does not clear.
		foreach ( array(
			'auth_'  => 'auth_code',
			'token_' => 'magic_token',
		) as $prefix => $column ) {
			$value = $sub[ $column ] ?? '';
			if ( is_string( $value ) && '' !== $value ) {
				wp_cache_delete( $prefix . $value, 'ffc_submissions' );
			}
		}
	}

	/**
	 * Tell the participant the result.
	 *
	 * @param array<string, mixed> $sub         Stored row (decrypted).
	 * @param array                $result      Grading result.
	 * @param array<string, mixed> $form_config Form configuration.
	 * @phpstan-param GradingResult $result
	 */
	private function send_result_email( array $sub, array $result, array $form_config ): void {
		$message = self::compose( $sub, $result, $form_config );
		if ( null !== $message ) {
			$this->deliver( $message['to'], $message['subject'], $message['body'] );
		}
	}

	/**
	 * The result email, or null when the attempt carries no usable address.
	 *
	 * @param array<string, mixed> $sub         Stored row (decrypted).
	 * @param array                $result      Grading result.
	 * @param array<string, mixed> $form_config Form configuration.
	 * @phpstan-param GradingResult $result
	 * @return array{to: string, subject: string, body: string}|null The body is the inner content, before the shared chrome.
	 */
	public static function compose( array $sub, array $result, array $form_config ): ?array {
		$to = is_string( $sub['email'] ?? null ) ? $sub['email'] : '';
		if ( ! is_email( $to ) ) {
			return null;
		}

		$template = $result['passed'] ? 'quiz-graded-passed' : 'quiz-graded-not-passed';
		$code     = is_string( $sub['auth_code'] ?? null ) ? $sub['auth_code'] : '';
		$tokens   = array(
			'{{name}}'          => \FreeFormCertificate\Core\SubmitterName::from( $result['data'] ),
			'{{form_title}}'    => get_the_title( (int) ( $sub['form_id'] ?? 0 ) ),
			'{{percent}}'       => (string) $result['percent'],
			'{{auth_code}}'     => '' !== $code ? \FreeFormCertificate\Core\DocumentFormatter::format_auth_code( $code, \FreeFormCertificate\Core\DocumentFormatter::PREFIX_CERTIFICATE ) : '',
			'{{result_line}}'   => self::result_line( $result, $form_config ),
			'{{attempts_line}}' => self::attempts_line( $result ),
		);

		$subject = \FreeFormCertificate\Core\TokenResolver::resolve( \FreeFormCertificate\Core\EmailTemplates::effective_body( $template, 'subject' ), $tokens );
		// Braces a visual editor percent-encoded still substitute.
		$body = str_ireplace( array( '%7B%7B', '%7D%7D' ), array( '{{', '}}' ), \FreeFormCertificate\Core\EmailTemplates::effective_body( $template, 'body' ) );
		$body = \FreeFormCertificate\Generators\TemplateRenderer::email(
			$body,
			$tokens,
			array( 'magic_token' => is_string( $sub['magic_token'] ?? null ) ? $sub['magic_token'] : '' )
		);

		return array(
			'to'      => $to,
			'subject' => $subject,
			'body'    => wpautop( wp_kses_post( $body ) ),
		);
	}

	/**
	 * Wrap the body in the shared chrome and send it through the pipeline,
	 * which holds the global kill-switch.
	 *
	 * @param string $to      Recipient.
	 * @param string $subject Subject.
	 * @param string $body    Inner body.
	 */
	protected function deliver( string $to, string $subject, string $body ): void {
		self::ffc_send_mail( $to, $subject, self::ffc_email_document( $body, array( 'recipient' => $to ) ), array(), \FreeFormCertificate\Core\EmailSource::CERTIFICATE );
	}

	/**
	 * The result sentence: the score only when the form shows it.
	 *
	 * @param array                $result      Grading result.
	 * @param array<string, mixed> $form_config Form configuration.
	 * @phpstan-param GradingResult $result
	 */
	public static function result_line( array $result, array $form_config ): string {
		if ( '1' === ArrayValue::string( $form_config, 'quiz_show_score', '1' ) ) {
			/* translators: %d: quiz score percentage */
			return sprintf( __( 'Your score was %d%%, below the passing score.', 'ffcertificate' ), $result['percent'] );
		}
		return __( 'Your score did not reach the passing score.', 'ffcertificate' );
	}

	/**
	 * Whether another attempt is possible.
	 *
	 * @param array $result Grading result.
	 * @phpstan-param GradingResult $result
	 */
	public static function attempts_line( array $result ): string {
		if ( SubmissionStatus::QUIZ_FAILED === $result['status'] || 0 === $result['remaining'] ) {
			return __( 'No attempts are left.', 'ffcertificate' );
		}
		if ( $result['remaining'] < 0 ) {
			return __( 'You can submit the form again to try once more.', 'ffcertificate' );
		}
		/* translators: %d: number of attempts left */
		return sprintf( _n( 'You have %d attempt left: submit the form again to use it.', 'You have %d attempts left: submit the form again to use them.', $result['remaining'], 'ffcertificate' ), $result['remaining'] );
	}

	/**
	 * Back to the submissions list, with the notice for the outcome.
	 *
	 * @param string $msg Notice key.
	 */
	private function redirect( string $msg ): void {
		wp_safe_redirect( admin_url( 'edit.php?post_type=ffc_form&page=ffc-submissions&msg=' . rawurlencode( $msg ) ) );
		exit;
	}
}
