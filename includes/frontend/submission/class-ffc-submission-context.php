<?php
/**
 * SubmissionContext
 *
 * Mutable value object threaded through the submission entry guards
 * (#563 Sprint 1). Each guard reads what it needs and writes back the
 * state later stages depend on, replacing the long run of local
 * variables the monolithic `handle_submission_ajax()` used to carry.
 *
 * Intentionally a plain DTO (public typed properties, no behaviour) so
 * guards stay the unit under test and the context is trivial to assemble
 * in tests.
 *
 * @package FreeFormCertificate\Frontend\Submission
 */

declare(strict_types=1);

namespace FreeFormCertificate\Frontend\Submission;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared state for the submission pipeline.
 */
class SubmissionContext {

	/**
	 * Whether a valid schedule-exception token accompanied the request.
	 *
	 * @var bool
	 */
	public bool $has_exception = false;

	/**
	 * Verified schedule-exception payload, or null when none.
	 *
	 * @var array<string, mixed>|null
	 */
	public ?array $schedule_exception_payload = null;

	/**
	 * Resolved form post id.
	 *
	 * @var int
	 */
	public int $form_id = 0;

	/**
	 * `_ffc_form_config` post meta.
	 *
	 * @var array<string, mixed>
	 */
	public array $form_config = array();

	/**
	 * `_ffc_form_fields` post meta.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $fields_config = array();

	/**
	 * Sanitized submission values keyed by field name.
	 *
	 * @var array<string, mixed>
	 */
	public array $submission_data = array();

	/**
	 * Normalized (lowercased) submitter email.
	 *
	 * @var string
	 */
	public string $user_email = '';

	/**
	 * Restriction password captured from POST.
	 *
	 * @var string
	 */
	public string $val_password = '';

	/**
	 * Restriction ticket captured from POST (uppercased).
	 *
	 * @var string
	 */
	public string $val_ticket = '';

	/**
	 * Submitted CPF/RF digits, when present.
	 *
	 * @var string
	 */
	public string $val_cpf = '';

	/**
	 * Cleaned device-fingerprint signals, or null when none usable.
	 *
	 * @var array<string, string>|null
	 */
	public ?array $device_signals = null;

	/**
	 * Whether the per-device rate-limit gate is bypassed (manager / reprint).
	 *
	 * @var bool
	 */
	public bool $skip_device = false;

	/**
	 * Result of the access-restriction check (whitelist/ticket/etc.).
	 *
	 * @var array<string, mixed>
	 */
	public array $restriction_result = array();

	/**
	 * Persisted (or resolved) submission row id.
	 *
	 * @var int
	 */
	public int $submission_id = 0;

	/**
	 * Submission date string (wall-clock 'mysql') for the success render.
	 *
	 * @var string
	 */
	public string $real_submission_date = '';

	/**
	 * Whether this resolved to a reprint of an existing certificate.
	 *
	 * @var bool
	 */
	public bool $is_reprint = false;

	/**
	 * Whether the form runs in quiz mode.
	 *
	 * @var bool
	 */
	public bool $is_quiz = false;

	/**
	 * Quiz score breakdown, when quiz mode is active.
	 *
	 * @var array{score: int, max_score: int, percent: int}|null
	 */
	public ?array $quiz_score = null;

	/**
	 * Generated PDF payload handed back to the client.
	 *
	 * @var mixed
	 */
	public $pdf_data = null;

	/**
	 * Final success response payload for wp_send_json_success().
	 *
	 * @var array<string, mixed>
	 */
	public array $response = array();
}
