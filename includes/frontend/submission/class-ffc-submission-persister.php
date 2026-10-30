<?php
/**
 * SubmissionPersister — pipeline stage 14 (#563 Sprint 1, PR 1b).
 *
 * Persists the submission and resolves the reprint/quiz outcome. Covers both
 * the quiz flow (score, attempt limits, status transitions, reprint of an
 * already-passed certificate) and the normal flow (reprint detection, fresh
 * insert, one-use ticket consumption, schedule-exception override). Rejects
 * via SubmissionRejected for exhausted quiz attempts, a failed quiz attempt
 * (feedback payload), or a repository save error — preserving the exact
 * wp_send_json_error payloads the monolith emitted.
 *
 * Takes the SubmissionHandler as an instance dependency (it is already an
 * object, not a static hub) — the first pipeline stage to carry a collaborator
 * by constructor.
 *
 * @package FreeFormCertificate\Frontend\Submission
 */

declare(strict_types=1);

namespace FreeFormCertificate\Frontend\Submission;

use FreeFormCertificate\Core\ArrayValue;
use FreeFormCertificate\Core\SubmissionStatus;
use FreeFormCertificate\Submissions\SubmissionHandler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Submission persistence + reprint/quiz resolution stage.
 */
class SubmissionPersister {

	/**
	 * Highest window-leave count a submission records (#1731).
	 */
	public const MAX_FOCUS_LOSSES = 999;

	/**
	 * Submission handler.
	 *
	 * @var SubmissionHandler
	 */
	private $submission_handler;

	/**
	 * Constructor.
	 *
	 * @param SubmissionHandler $submission_handler Submission handler.
	 */
	public function __construct( SubmissionHandler $submission_handler ) {
		$this->submission_handler = $submission_handler;
	}

	/**
	 * Persist the submission, populating the context for the response stages.
	 *
	 * @param SubmissionContext $ctx Submission context.
	 * @throws SubmissionRejected On exhausted/failed quiz attempts or a save error.
	 */
	public function apply( SubmissionContext $ctx ): void {
		$form_id                    = $ctx->form_id;
		$form_config                = $ctx->form_config;
		$fields_config              = $ctx->fields_config;
		$user_email                 = $ctx->user_email;
		$val_cpf                    = $ctx->val_cpf;
		$val_ticket                 = $ctx->val_ticket;
		$has_exception              = $ctx->has_exception;
		$schedule_exception_payload = $ctx->schedule_exception_payload;
		$restriction_result         = $ctx->restriction_result;

		// Operate on a local copy (process_submission mutates it by reference);
		// written back to the context at the end for the response stages.
		$submission_data = $ctx->submission_data;

		// === Quiz Mode Processing (v4.9.0) ===.
		$is_quiz         = ! empty( $form_config['quiz_enabled'] ) && '1' === $form_config['quiz_enabled'];
		$form_post       = get_post( $form_id );
		$form_post_title = $form_post ? (string) $form_post->post_title : '';
		$is_reprint      = false;
		$quiz_score      = null;
		$awaiting        = false;

		if ( $is_quiz ) {
			// Calculate quiz score.
			$quiz_score    = $this->calculate_quiz_score( $fields_config, $submission_data );
			$passing_score = ArrayValue::int( $form_config, 'quiz_passing_score', 70 );
			$max_attempts  = ArrayValue::int( $form_config, 'quiz_max_attempts', 0 );
			$passed        = $quiz_score['percent'] >= $passing_score;

			// Store quiz data in submission.
			$submission_data['_quiz_score']     = $quiz_score['score'];
			$submission_data['_quiz_max_score'] = $quiz_score['max_score'];
			$submission_data['_quiz_percent']   = $quiz_score['percent'];
			$submission_data['_quiz_passed']    = $passed ? '1' : '0';
			// Read by `{{correct_count}}` / `{{question_count}}` (#1730).
			$submission_data['_quiz_correct_count']  = $quiz_score['correct_count'];
			$submission_data['_quiz_question_count'] = $quiz_score['question_count'];
			// How many times the participant left the window (#1731). The
			// browser counts it, so it is a record, not evidence: it is kept
			// only when the form asks for it, and capped.
			if ( \FreeFormCertificate\Core\QuizLeaveLevel::OFF !== \FreeFormCertificate\Core\QuizLeaveLevel::of( $form_config ) ) {
				$submission_data['_quiz_focus_losses'] = min( self::MAX_FOCUS_LOSSES, SubmissionInput::int( $ctx, 'ffc_quiz_focus_losses' ) );
			}

			// An open answer a person grades holds the result back (#1732).
			$awaiting_grading = array() !== $quiz_score['pending'];
			$awaiting         = $awaiting_grading;
			if ( $awaiting_grading ) {
				$submission_data['_quiz_pending'] = $quiz_score['pending'];
			}

			// Find existing quiz submission for this CPF + form.
			$existing = $this->find_quiz_submission( $form_id, $val_cpf );

			// While an attempt waits to be graded, its result is not known, so
			// neither is whether another attempt is needed (#1732).
			if ( $existing && SubmissionStatus::QUIZ_PENDING === $existing->status ) {
				throw new SubmissionRejected(
					array(
						'message' => __( 'Your previous answers are still being graded. The result will be sent to your email.', 'ffcertificate' ),
						'quiz'    => array( 'awaiting_grading' => true ),
					)
				);
			}

			// If already passed (status=publish), treat as reprint.
			if ( $existing && 'publish' === $existing->status ) {
				$submission_id        = (int) $existing->id;
				$real_submission_date = $existing->submission_date;
				$is_reprint           = true;

				// Surface the stored auth_code for the success card/message.
				if ( ! empty( $existing->auth_code ) && empty( $submission_data['auth_code'] ) ) {
					$submission_data['auth_code'] = $existing->auth_code;
				}
			} else {
				// Count attempts.
				$prev_attempt                     = $existing ? self::previous_attempt( $existing ) : 0;
				$attempt_number                   = $prev_attempt + 1;
				$submission_data['_quiz_attempt'] = $attempt_number;

				// Check attempt limit.
				if ( $max_attempts > 0 && $attempt_number > $max_attempts ) {
					throw new SubmissionRejected(
						array(
							'message' => __( 'Maximum quiz attempts reached for this CPF/RF.', 'ffcertificate' ),
							'quiz'    => array( 'attempts_exhausted' => true ),
						)
					);
				}

				// Determine status.
				if ( $awaiting_grading ) {
					$quiz_status = SubmissionStatus::QUIZ_PENDING;
				} elseif ( $passed ) {
					$quiz_status = 'publish';
				} elseif ( $max_attempts > 0 && $attempt_number >= $max_attempts ) {
					$quiz_status = 'quiz_failed';
				} else {
					$quiz_status = 'quiz_in_progress';
				}

				if ( $existing ) {
					// UPDATE existing submission.
					$submission_id = (int) $existing->id;
					$repo          = $this->submission_handler->get_repository();

					// Build updated data JSON.
					$mandatory_keys = array( 'email', 'cpf_rf', 'auth_code', 'ffc_lgpd_consent' );
					$extra_data     = array_diff_key( $submission_data, array_flip( $mandatory_keys ) );
					$data_json_raw  = wp_json_encode( $extra_data );
					$data_json      = $data_json_raw ? $data_json_raw : '{}';

					// `submission_date` is a unix UTC int (Category A); a MySQL
					// date string here was truncated to its year (#2241).
					$now           = time();
					$update_fields = array(
						'status'          => $quiz_status,
						'submission_date' => $now,
					);
					if ( class_exists( '\FreeFormCertificate\Core\Encryption' ) && \FreeFormCertificate\Core\Encryption::is_configured() ) {
						$update_fields['data_encrypted'] = \FreeFormCertificate\Core\Encryption::encrypt( $data_json );
					} else {
						$update_fields['data'] = $data_json;
					}

					if ( false === $repo->update( $submission_id, $update_fields ) ) {
						throw new SubmissionRejected(
							array(
								'code'    => 'db_error',
								'message' => __( "We couldn't save your submission. Try again — if the problem persists, contact the organizer.", 'ffcertificate' ),
							)
						);
					}
					$real_submission_date = (string) $now;

					// A retry that passes is the moment the certificate exists,
					// so it is e-mailed now, as a first attempt's insert is.
					if ( 'publish' === $quiz_status ) {
						$this->submission_handler->schedule_async_pipeline( $submission_id );
					}
				} else {
					// INSERT new submission via existing handler.
					$submission_id = $this->submission_handler->process_submission(
						$form_id,
						$form_post_title,
						$submission_data,
						$user_email,
						$fields_config,
						$form_config
					);

					if ( is_wp_error( $submission_id ) ) {
						// 6.6.4 Sprint 7 — wrap the raw repository error with
						// empathetic guidance. The raw message is often a
						// SQL/wpdb string ("Duplicate entry"…) actionable only
						// for admins. Surface the original as `code` for support
						// triage and give the user a recovery path.
						throw new SubmissionRejected(
							array(
								'code'    => $submission_id->get_error_code(),
								'message' => __( "We couldn't save your submission. Try again — if the problem persists, contact the organizer.", 'ffcertificate' ),
								'detail'  => $submission_id->get_error_message(),
							)
						);
					}

					// Update status if not publish.
					if ( 'publish' !== $quiz_status ) {
						$this->submission_handler->get_repository()->updateStatus( $submission_id, $quiz_status );
					}

					$real_submission_date = current_time( 'mysql' );
				}

				// If not passed, return quiz feedback (no certificate). An
				// attempt awaiting grading has no result yet (#1732).
				if ( ! $passed && ! $awaiting_grading ) {
					$remaining  = ( $max_attempts > 0 ) ? max( 0, $max_attempts - $attempt_number ) : -1;
					$show_score = ( $form_config['quiz_show_score'] ?? '1' ) === '1';

					if ( 'quiz_failed' === $quiz_status ) {
						$msg = $show_score
							/* translators: %d: quiz score percentage */
							? sprintf( __( 'Quiz failed. Score: %d%%. Maximum attempts reached.', 'ffcertificate' ), $quiz_score['percent'] )
							: __( 'Quiz failed. Maximum attempts reached.', 'ffcertificate' );
					} else {
						$msg = $show_score
							/* translators: 1: score percentage, 2: remaining attempts */
							? sprintf( __( 'Score: %1$d%%. You can try again (%2$d attempts remaining).', 'ffcertificate' ), $quiz_score['percent'], $remaining )
							/* translators: %d: number of remaining quiz attempts */
							: sprintf( __( 'Not passed. You can try again (%d attempts remaining).', 'ffcertificate' ), $remaining );

						if ( -1 === $remaining ) {
							$msg = $show_score
								/* translators: %d: quiz score percentage */
								? sprintf( __( 'Score: %d%%. You can try again.', 'ffcertificate' ), $quiz_score['percent'] )
								: __( 'Not passed. You can try again.', 'ffcertificate' );
						}
					}

					$shown = self::shown_results( $form_config, $quiz_score );
					if ( null !== $shown ) {
						$msg .= ' ' . sprintf(
							/* translators: 1: questions answered right, 2: questions scored. */
							_n( '%1$s of %2$s question answered correctly.', '%1$s of %2$s questions answered correctly.', $shown['question_count'], 'ffcertificate' ),
							number_format_i18n( $shown['correct_count'] ),
							number_format_i18n( $shown['question_count'] )
						);
					}

					throw new SubmissionRejected(
						array(
							'message' => $msg,
							'quiz'    => array(
								'passed'    => false,
								'score'     => $show_score ? $quiz_score['score'] : null,
								'max_score' => $show_score ? $quiz_score['max_score'] : null,
								'percent'   => $show_score ? $quiz_score['percent'] : null,
								'attempt'   => $attempt_number,
								'remaining' => $remaining,
								'status'    => $quiz_status,
								'results'   => $shown,
							),
						)
					);
				}
			}
		} else {
			// === Normal (non-quiz) flow ===.

			// Detect reprint — delegated to ReprintDetector.
			$reprint_result = \FreeFormCertificate\Frontend\ReprintDetector::detect( $form_id, $val_cpf, $val_ticket );
			$is_reprint     = $reprint_result['is_reprint'];

			if ( $is_reprint ) {
				// Reprint - use existing submission ID (convert to int from wpdb string).
				$submission_id        = (int) $reprint_result['id'];
				$real_submission_date = $reprint_result['date'];

				// Surface the existing auth_code so the success card and message can show it.
				if ( isset( $reprint_result['data']['auth_code'] ) && empty( $submission_data['auth_code'] ) ) {
					$submission_data['auth_code'] = $reprint_result['data']['auth_code'];
				}
			} else {
				// New submission - save to database.
				$submission_id = $this->submission_handler->process_submission(
					$form_id,
					$form_post_title,
					$submission_data,
					$user_email,
					$fields_config,
					$form_config
				);

				if ( is_wp_error( $submission_id ) ) {
					// 6.6.4 Sprint 7 — see the quiz branch above for the
					// rationale. Empathetic top-level message; raw repo error
					// preserved under `detail` for admin triage.
					throw new SubmissionRejected(
						array(
							'code'    => $submission_id->get_error_code(),
							'message' => __( "We couldn't save your submission. Try again — if the problem persists, contact the organizer.", 'ffcertificate' ),
							'detail'  => $submission_id->get_error_message(),
						)
					);
				}

				// Get the submission date from the newly created submission.
				$real_submission_date = current_time( 'mysql' );

				// Remove used ticket if applicable — delegated to AccessRestrictionChecker.
				if ( $restriction_result['is_ticket'] && ! empty( $val_ticket ) ) {
					\FreeFormCertificate\Frontend\AccessRestrictionChecker::consume_ticket( $form_id, $val_ticket );
				}

				// #366 Sprint 6 — persist the override TIME columns on the
				// freshly inserted row + emit the two audit log entries. Runs
				// only on new submissions (reprint path returns the original
				// row unmodified — exceptions are strictly one-use).
				// `$has_exception` and `$schedule_exception_payload` are
				// correlated (both set together in ScheduleExceptionGuard), so
				// the null payload case is already excluded by the boolean.
				if ( $has_exception ) {
					$this->maybe_persist_schedule_exception(
						(int) $submission_id,
						$form_id,
						(array) $schedule_exception_payload,
						(string) $val_cpf
					);
				}
			}
		}

		// An invited submission names its booking (#1948). A reprint already
		// carries it from the first time.
		if ( null !== $ctx->invite && ! $is_reprint && (int) $submission_id > 0 ) {
			$this->submission_handler->get_repository()->update( (int) $submission_id, array( 'audience_booking_id' => $ctx->invite['booking_id'] ) );
		}

		$ctx->submission_data      = $submission_data;
		$ctx->submission_id        = (int) $submission_id;
		$ctx->real_submission_date = (string) $real_submission_date;
		$ctx->is_reprint           = $is_reprint;
		$ctx->is_quiz              = $is_quiz;
		$ctx->quiz_score           = $quiz_score;
		$ctx->awaiting_grading     = $awaiting && ! $is_reprint;
	}

	/**
	 * Which questions were answered right, when the form shows it (#1730).
	 *
	 * Names the question and whether it was right — never the right
	 * answer, which a participant with attempts left would simply copy.
	 *
	 * @param array<string, mixed> $form_config Form configuration.
	 * @param array<string, mixed> $quiz_score  Score, as {@see QuizScorer::score()} returns it.
	 * @return array{correct_count: int, question_count: int, fields: array<string, bool>}|null
	 */
	public static function shown_results( array $form_config, array $quiz_score ): ?array {
		if ( '1' !== ArrayValue::string( $form_config, 'quiz_show_correct', '0' ) ) {
			return null;
		}
		$fields = array();
		foreach ( is_array( $quiz_score['results'] ?? null ) ? $quiz_score['results'] : array() as $name => $right ) {
			$fields[ (string) $name ] = true === $right;
		}
		return array(
			'correct_count'  => ArrayValue::int( $quiz_score, 'correct_count', 0 ),
			'question_count' => ArrayValue::int( $quiz_score, 'question_count', 0 ),
			'fields'         => $fields,
		);
	}

	/**
	 * The attempt number a stored quiz row records (#2241).
	 *
	 * With encryption on, the attempt lives in `data_encrypted` and `data` is
	 * NULL, so reading `data` alone counted every attempt as the first and
	 * the limit never held. A row that exists records one attempt at least,
	 * so a row whose payload cannot be read still counts as one.
	 *
	 * @param object $existing Stored submission row.
	 * @return int At least 1.
	 */
	public static function previous_attempt( object $existing ): int {
		$json = \FreeFormCertificate\Core\Encryption::decrypt_field( (array) $existing, 'data' );
		$data = json_decode( '' !== $json ? $json : '{}', true );
		return max( 1, ArrayValue::int( is_array( $data ) ? $data : array(), '_quiz_attempt', 0 ) );
	}

	/**
	 * Calculate the quiz score of a submission (#1730: {@see QuizScorer}).
	 *
	 * @param array<int, array<string, mixed>> $fields_config Form fields configuration.
	 * @param array<string, mixed>             $submission_data User's submitted data.
	 * @return array{score: int, max_score: int, percent: int, correct_count: int, question_count: int, results: array<string, bool>, pending: list<string>}
	 */
	public function calculate_quiz_score( array $fields_config, array $submission_data ): array {
		return ( new QuizScorer() )->score( $fields_config, $submission_data );
	}

	/**
	 * Find existing quiz submission for a CPF + form combination
	 *
	 * Returns the most recent submission (any quiz status: in_progress, failed, or publish).
	 *
	 * Without encryption keys no identifier is stored at all — the insert
	 * writes neither the CPF/RF nor a hash of it — so there is nothing to find
	 * by, and attempt limits cannot hold on such an install. WordPress writes
	 * the keys at install, so this is a broken configuration, not a mode.
	 *
	 * @param int    $form_id Form ID.
	 * @param string $cpf     CPF/RF value.
	 * @return (\stdClass&object{id: numeric-string, status: string, data: string|null, submission_date: string})|null
	 */
	public function find_quiz_submission( int $form_id, string $cpf ): ?object {
		if ( empty( $cpf ) ) {
			return null;
		}

		global $wpdb;
		$table     = \FreeFormCertificate\Repositories\SubmissionRepository::get_submissions_table();
		$clean_cpf = \FreeFormCertificate\Core\DataSanitizer::normalize_cpf_rf( $cpf );

		if ( class_exists( '\FreeFormCertificate\Core\Encryption' ) && \FreeFormCertificate\Core\Encryption::is_configured() ) {
			$id_hash     = \FreeFormCertificate\Core\SensitiveFieldRegistry::hash_identifier( 'cpf', $clean_cpf );
			$hash_column = 'rf' === \FreeFormCertificate\Core\DataSanitizer::classify_cpf_rf( $clean_cpf ) ? 'rf_hash' : 'cpf_hash';

			// Search the specific split column based on digit count.
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $hash_column is derived from strlen() check, not user input.
			/**
			 * Cast wpdb result to typed shape.
			 *
			 * @var (\stdClass&object{id: numeric-string, status: string, data: string|null, submission_date: string})|null $result
			 */
			$result = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uniqueness probe — serving this from cache would defeat the check it exists to perform.
				$wpdb->prepare(
					"SELECT * FROM %i WHERE form_id = %d AND {$hash_column} = %s ORDER BY id DESC LIMIT 1",
					$table,
					$form_id,
					$id_hash
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			return $result;
		}

		return null;
	}

	/**
	 * Atomically claim a schedule-exception token's one-time use, then
	 * persist the override only if the claim won (#Item11).
	 *
	 * The first submission to claim the payload's `jti` wins; a racing
	 * double-click (or a refreshed tab re-POSTing the still-valid token)
	 * loses the {@see ScheduleExceptionSession::try_consume_jti()} INSERT
	 * and is recorded at baseline, so the operator-issued exception mints
	 * exactly one adjusted certificate. The claim runs here, at the success
	 * point, rather than at token-verify time — otherwise a downstream
	 * validation failure would burn the one-use token before any
	 * certificate existed.
	 *
	 * @param int                  $submission_id         Newly inserted row.
	 * @param int                  $form_id               Form post id.
	 * @param array<string, mixed> $payload               Verified token payload.
	 * @param string               $participant_cpf_plain Plaintext participant CPF.
	 * @return bool True when the claim won and the override was persisted.
	 */
	public function maybe_persist_schedule_exception(
		int $submission_id,
		int $form_id,
		array $payload,
		string $participant_cpf_plain
	): bool {
		$jti = ArrayValue::string( $payload, 'jti' );
		$exp = ArrayValue::int( $payload, 'exp' );
		if ( ! \FreeFormCertificate\Frontend\ScheduleExceptionSession::try_consume_jti( $jti, $exp ) ) {
			return false;
		}
		$this->persist_schedule_exception( $submission_id, $form_id, $payload, $participant_cpf_plain );
		return true;
	}

	/**
	 * Persist a verified schedule-exception payload onto an existing
	 * submission row and emit the matching audit pair.
	 *
	 * Two writes:
	 *   1. Repository UPDATE setting `schedule_start_override` /
	 *      `schedule_end_override` to the verified payload values. Either
	 *      column stays NULL when the operator left that end at baseline.
	 *   2. Two `ActivityLog::log()` rows (#366): `schedule_override_created`
	 *      carries the full context JSON (form_id, submission_id, participant
	 *      CPF hash, operator CPF hash + masked, the override range, and a `ts`
	 *      Category A unix UTC int); the companion `operator_ip_bypass` carries
	 *      the bypassed IP so a reviewer can correlate the exception with the
	 *      venue's outbound IP.
	 *
	 * @param int                  $submission_id         Newly inserted row.
	 * @param int                  $form_id               Form post id.
	 * @param array<string, mixed> $payload               Verified token payload.
	 * @param string               $participant_cpf_plain Plaintext participant CPF digits
	 *                                                    (already validated upstream); used
	 *                                                    to derive the audit hash.
	 */
	public function persist_schedule_exception(
		int $submission_id,
		int $form_id,
		array $payload,
		string $participant_cpf_plain
	): void {
		$start_override = isset( $payload['start'] ) && is_string( $payload['start'] ) ? $payload['start'] : null;
		$end_override   = isset( $payload['end'] ) && is_string( $payload['end'] ) ? $payload['end'] : null;

		$update_data = array();
		if ( null !== $start_override && '' !== $start_override ) {
			$update_data['schedule_start_override'] = $start_override;
		}
		if ( null !== $end_override && '' !== $end_override ) {
			$update_data['schedule_end_override'] = $end_override;
		}
		if ( ! empty( $update_data ) ) {
			$this->submission_handler->get_repository()->update( $submission_id, $update_data );
		}

		if ( ! class_exists( '\FreeFormCertificate\Core\ActivityLog' ) ) {
			return;
		}

		$participant_cpf_hash = '' !== $participant_cpf_plain ? hash( 'sha256', $participant_cpf_plain ) : '';

		\FreeFormCertificate\Core\ActivityLog::log(
			'schedule_override_created',
			'info',
			array(
				'form_id'               => $form_id,
				'submission_id'         => $submission_id,
				'participant_cpf_hash'  => $participant_cpf_hash,
				'operator_cpf_hash'     => ArrayValue::string( $payload, 'operator_cpf_hash' ),
				'operator_cpf_masked'   => ArrayValue::string( $payload, 'operator_cpf_masked' ),
				'schedule_start_before' => ArrayValue::string( $payload, 'baseline_start' ),
				'schedule_end_before'   => ArrayValue::string( $payload, 'baseline_end' ),
				'schedule_start_after'  => $start_override,
				'schedule_end_after'    => $end_override,
				'ts'                    => time(),
			),
			0,
			$submission_id
		);

		\FreeFormCertificate\Core\ActivityLog::log(
			'operator_ip_bypass',
			'info',
			array(
				'form_id'             => $form_id,
				'submission_id'       => $submission_id,
				'bypassed_ip'         => \FreeFormCertificate\Core\RequestInput::get_user_ip(),
				'operator_cpf_hash'   => ArrayValue::string( $payload, 'operator_cpf_hash' ),
				'operator_cpf_masked' => ArrayValue::string( $payload, 'operator_cpf_masked' ),
				'ts'                  => time(),
			),
			0,
			$submission_id
		);
	}
}
