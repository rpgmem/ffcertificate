<?php
/**
 * Recruitment Candidates REST Controller
 *
 * Domain controller for the `candidates/*` slice of the
 * `ffcertificate/v1/recruitment` namespace plus the candidate-self
 * `me/recruitment` endpoint. Extracted from the original god-object
 * `RecruitmentRestController` (sprint S2 of #141).
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.0.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Recruitment;

use FreeFormCertificate\Core\DocumentFormatter;
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Core\SensitiveFieldRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-ffc-recruitment-rest-support-trait.php';

/**
 * REST controller for the recruitment candidates slice.
 *
 * @phpstan-import-type CandidateRow from RecruitmentCandidateReader
 * @phpstan-import-type CallRow from RecruitmentCallReader
 */
final class RecruitmentCandidatesRestController {

	use RecruitmentRestSupport;

	/** Namespace for all admin + candidate-self routes. */
	private const NAMESPACE = 'ffcertificate/v1';

	/** Resource base. */
	private const REST_BASE = 'recruitment';

	/**
	 * Hook callback for `rest_api_init`.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$ns   = self::NAMESPACE;
		$base = '/' . self::REST_BASE;

		// ── Candidates ──────────────────────────────────────────────────
		register_rest_route(
			$ns,
			$base . '/candidates',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_candidates' ),
				'permission_callback' => array( $this, 'check_can_view_recruitment' ),
				'args'                => array(
					'search'       => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'cpf'          => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'rf'           => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'notice_id'    => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'adjutancy_id' => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			$ns,
			$base . '/candidates/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_candidate' ),
					'permission_callback' => array( $this, 'check_can_view_recruitment' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_candidate' ),
					'permission_callback' => array( $this, 'check_admin_cap' ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_candidate' ),
					'permission_callback' => array( $this, 'check_can_delete_recruitment' ),
				),
			)
		);

		// Reveal a single sensitive field on demand (issue #330). The route
		// itself only checks "logged in" so the owner-clause inside the
		// policy can authorise the candidate themselves; the handler then
		// runs RecruitmentPiiAccessPolicy::resolve() to enforce the tier
		// and writes an audit row for every reveal on the `reveal` tier.
		register_rest_route(
			$ns,
			$base . '/candidates/(?P<id>\d+)/reveal-pii',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'reveal_pii' ),
				'permission_callback' => array( $this, 'check_logged_in' ),
				'args'                => array(
					'field' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		// ── Candidate-self dashboard ────────────────────────────────────
		register_rest_route(
			$ns,
			$base . '/me/recruitment',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_my_recruitment' ),
				'permission_callback' => array( $this, 'check_logged_in' ),
			)
		);
	}

	// ─────────────────────────────────────────────────────────────────────
	// Candidates
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * GET /candidates — filter by cpf/rf/name/notice/adjutancy.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function list_candidates( \WP_REST_Request $request ) {
		// CPF/RF are passed as plaintext digits; hash here for the lookup.
		$cpf = $request->get_param( 'cpf' );
		if ( is_string( $cpf ) && '' !== $cpf ) {
			$cpf_digits = \FreeFormCertificate\Core\DataSanitizer::normalize_cpf_rf( $cpf );
			if ( '' !== $cpf_digits ) {
				$candidate = RecruitmentCandidateReader::get_by_cpf_hash( (string) SensitiveFieldRegistry::hash_identifier( 'cpf', $cpf_digits ) );
				return new \WP_REST_Response( null === $candidate ? array() : array( $this->shape_candidate_admin( $candidate ) ), 200 );
			}
		}

		$rf = $request->get_param( 'rf' );
		if ( is_string( $rf ) && '' !== $rf ) {
			$rf_digits = \FreeFormCertificate\Core\DataSanitizer::normalize_cpf_rf( $rf );
			if ( '' !== $rf_digits ) {
				$candidate = RecruitmentCandidateReader::get_by_rf_hash( (string) SensitiveFieldRegistry::hash_identifier( 'rf', $rf_digits ) );
				return new \WP_REST_Response( null === $candidate ? array() : array( $this->shape_candidate_admin( $candidate ) ), 200 );
			}
		}

		// Generic listing not yet implemented in repository (out of scope for
		// sprint 9.1 MVP — search by name will land alongside the admin UI).
		return new \WP_Error(
			'recruitment_candidate_list_requires_filter',
			__( 'Candidate listing requires a cpf or rf filter for now.', 'ffcertificate' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * GET /candidates/{id} — admin view with decrypted fields.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_candidate( \WP_REST_Request $request ) {
		$id        = (int) $request->get_param( 'id' );
		$candidate = RecruitmentCandidateReader::get_by_id( $id );
		if ( null === $candidate ) {
			return new \WP_Error( 'recruitment_candidate_not_found', '', array( 'status' => 404 ) );
		}
		return new \WP_REST_Response( $this->shape_candidate_admin( $candidate ), 200 );
	}

	/**
	 * PATCH /candidates/{id} — the edit screen's rules (#2245).
	 *
	 * Name, phone and notes for the manage capability; the e-mail only for
	 * a caller who may see it; CPF and RF never. Every change is logged.
	 * See {@see RecruitmentCandidateEditor}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_candidate( \WP_REST_Request $request ) {
		$id     = (int) $request->get_param( 'id' );
		$before = RecruitmentCandidateReader::get_by_id( $id );
		if ( null === $before ) {
			return new \WP_Error( 'recruitment_candidate_not_found', '', array( 'status' => 404 ) );
		}

		$params = $request->get_params();
		$input  = array_intersect_key(
			$params,
			array_flip( array_merge( RecruitmentCandidateEditor::FIELDS, RecruitmentCandidateEditor::IDENTIFIERS ) )
		);

		$result = RecruitmentCandidateEditor::update( $before, $input );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$candidate = RecruitmentCandidateReader::get_by_id( $id );
		return new \WP_REST_Response(
			null === $candidate ? null : $this->shape_candidate_admin( $candidate ),
			200
		);
	}

	/**
	 * DELETE /candidates/{id} — gated hard-delete (zero classifications).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_candidate( \WP_REST_Request $request ) {
		$result = RecruitmentDeleteService::delete_candidate( (int) $request->get_param( 'id' ) );
		if ( ! $result['success'] ) {
			return $this->wp_error_from_envelope_with_blocked( $result, 409 );
		}
		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * POST /candidates/{id}/reveal-pii — return one decrypted sensitive
	 * field after enforcing the access tier (issue #330).
	 *
	 * Body / param:
	 *   field — one of `cpf`, `rf`, `email`.
	 *
	 * Response shape (200): `{ field: string, value: string }`.
	 * Errors: 400 unsupported field; 403 access denied (tier=masked);
	 *         404 candidate or encrypted column missing.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function reveal_pii( \WP_REST_Request $request ) {
		$id        = (int) $request->get_param( 'id' );
		$field_key = (string) $request->get_param( 'field' );

		$allowed = array( 'cpf', 'rf', 'email' );
		if ( ! in_array( $field_key, $allowed, true ) ) {
			return new \WP_Error(
				'recruitment_pii_field_unsupported',
				__( 'Unsupported sensitive field.', 'ffcertificate' ),
				array( 'status' => 400 )
			);
		}

		$candidate = RecruitmentCandidateReader::get_by_id( $id );
		if ( null === $candidate ) {
			return new \WP_Error( 'recruitment_candidate_not_found', '', array( 'status' => 404 ) );
		}

		$tier = RecruitmentPiiAccessPolicy::resolve( $candidate, get_current_user_id() );
		if ( RecruitmentPiiAccessPolicy::TIER_MASKED === $tier ) {
			return new \WP_Error(
				'recruitment_pii_access_denied',
				__( 'You do not have permission to reveal this field.', 'ffcertificate' ),
				array( 'status' => 403 )
			);
		}

		$column = $field_key . '_encrypted';
		if ( empty( $candidate->{$column} ) ) {
			return new \WP_Error( 'recruitment_pii_value_missing', '', array( 'status' => 404 ) );
		}

		$plain = Encryption::decrypt( (string) $candidate->{$column} );
		if ( ! is_string( $plain ) || '' === $plain ) {
			return new \WP_Error( 'recruitment_pii_decrypt_failed', '', array( 'status' => 500 ) );
		}

		// Render via DocumentFormatter so the value matches what the
		// detail screen shows when the unmasked tier is in effect.
		switch ( $field_key ) {
			case 'cpf':
				$display = DocumentFormatter::format_cpf( $plain );
				break;
			case 'rf':
				$display = DocumentFormatter::format_rf( $plain );
				break;
			default:
				$display = $plain;
		}

		if ( RecruitmentPiiAccessPolicy::should_audit( $candidate, get_current_user_id() ) ) {
			RecruitmentActivityLogger::pii_revealed( $id, $field_key );
		}

		return new \WP_REST_Response(
			array(
				'field' => $field_key,
				'value' => (string) $display,
			),
			200
		);
	}

	// ─────────────────────────────────────────────────────────────────────
	// Candidate-self dashboard
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * GET /me/recruitment.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_my_recruitment(): \WP_REST_Response {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return new \WP_REST_Response( array(), 200 );
		}

		$candidates = RecruitmentCandidateReader::get_by_user_id( $user_id );
		if ( empty( $candidates ) ) {
			return new \WP_REST_Response( array(), 200 );
		}

		// ONE read per candidate, held: the assembly loop below reuses it rather
		// than querying again. Candidacies per user are few, so the N+1 that
		// matters here is not this one -- it is the call history's.
		$by_candidate = array();
		$class_ids    = array();
		foreach ( $candidates as $candidate ) {
			$classifications = RecruitmentClassificationRepository::get_for_candidate( (int) $candidate->id );
			$by_candidate[]  = $classifications;
			foreach ( $classifications as $cls ) {
				$class_ids[] = (int) $cls->id;
			}
		}

		// ONE query for the whole history, instead of one per classification
		// inside a nested loop (#1234). The batched helper already existed and
		// was already used by the dashboard
		// (`RecruitmentDashboardSection::collect_calls`); only this site had been
		// left on the singular version.
		//
		// The order does not change: both versions emit `ORDER BY called_at
		// DESC`, and grouping an already ordered list preserves the order WITHIN
		// each group.
		$calls_by_class = array();
		foreach ( RecruitmentCallReader::get_history_for_classifications( $class_ids ) as $ffc_call ) {
			$calls_by_class[ (int) $ffc_call->classification_id ][] = $ffc_call;
		}

		// Group classifications by notice for the §9.2 grouped payload.
		$out = array();
		foreach ( $by_candidate as $classifications ) {
			foreach ( $classifications as $cls ) {
				$notice_id = (int) $cls->notice_id;
				$notice    = RecruitmentNoticeReader::get_by_id( $notice_id );
				if ( null === $notice || 'draft' === $notice->status ) {
					continue; // Draft notices are never exposed.
				}

				// What the notice shows the public is what it shows its own
				// candidate (#2245): the score only when the score column is on.
				$columns = RecruitmentPublicShortcodeRenderer::parse_columns_config( (string) $notice->public_columns_config );

				if ( ! isset( $out[ $notice_id ] ) ) {
					$out[ $notice_id ] = array(
						'notice'          => array(
							'id'           => $notice_id,
							'code'         => $notice->code,
							'name'         => $notice->name,
							'status'       => $notice->status,
							'was_reopened' => '1' === $notice->was_reopened,
						),
						'classifications' => array(),
					);
				}

				$entry = array(
					'id'        => (int) $cls->id,
					'list_type' => $cls->list_type,
					'rank'      => (int) $cls->rank,
					'status'    => $cls->status,
					'calls'     => array_map( array( self::class, 'shape_call_for_candidate' ), $calls_by_class[ (int) $cls->id ] ?? array() ),
				);
				if ( $columns['score'] ) {
					$entry['score'] = $cls->score;
				}
				$out[ $notice_id ]['classifications'][] = $entry;
			}
		}

		return new \WP_REST_Response( array_values( $out ), 200 );
	}

	// ─────────────────────────────────────────────────────────────────────
	// Helpers
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * Shape a call for its own candidate (#2245).
	 *
	 * The same facts "My calls" shows: when, where to report and when the
	 * call was reverted and why. The operator's out-of-order reason and who
	 * created or cancelled the call are staff records, not the candidate's.
	 *
	 * @param object $call Call row (CallRow shape).
	 * @phpstan-param CallRow $call
	 * @return array{id: int, called_at: int, date_to_assume: string, time_to_assume: string, notes: string|null, cancelled_at: int|null, cancellation_reason: string|null}
	 */
	public static function shape_call_for_candidate( object $call ): array {
		return array(
			'id'                  => (int) $call->id,
			'called_at'           => (int) $call->called_at,
			'date_to_assume'      => (string) $call->date_to_assume,
			'time_to_assume'      => (string) $call->time_to_assume,
			'notes'               => $call->notes,
			'cancelled_at'        => null === $call->cancelled_at ? null : (int) $call->cancelled_at,
			'cancellation_reason' => $call->cancellation_reason,
		);
	}

	/**
	 * Shape a candidate for the admin-side responses.
	 *
	 * CPF, RF and email come back masked for every caller but the candidate
	 * themselves (#1863): a staff member reveals one through
	 * `POST /candidates/{id}/reveal-pii`, which is audited, exactly as the
	 * candidate screen's Reveal button does. Before #1863 these routes
	 * returned all three in clear to every holder of the manage cap.
	 *
	 * @param object $candidate Candidate row (CandidateRow shape).
	 * @phpstan-param CandidateRow $candidate
	 * @return array<string, mixed>
	 */
	private function shape_candidate_admin( object $candidate ): array {
		$decrypt = static function ( $value ) {
			if ( ! is_string( $value ) || '' === $value ) {
				return null;
			}
			$plain = Encryption::decrypt( $value );
			return null === $plain ? null : $plain;
		};

		$mask_or_plain_email = function ( ?string $plain ): ?string {
			return null === $plain ? null : DocumentFormatter::mask_email( $plain );
		};

		$clear = RecruitmentPiiAccessPolicy::TIER_UNMASKED === RecruitmentPiiAccessPolicy::resolve( $candidate, get_current_user_id() );
		$cpf   = $decrypt( $candidate->cpf_encrypted );
		$rf    = $decrypt( $candidate->rf_encrypted );
		$email = $decrypt( $candidate->email_encrypted );

		return array(
			'id'           => (int) $candidate->id,
			'user_id'      => null === $candidate->user_id ? null : (int) $candidate->user_id,
			'name'         => $candidate->name,
			'cpf'          => $clear || null === $cpf ? $cpf : DocumentFormatter::mask_any( 'cpf', $cpf ),
			'rf'           => $clear || null === $rf ? $rf : DocumentFormatter::mask_any( 'rf', $rf ),
			'email'        => $clear ? $email : $mask_or_plain_email( $email ),
			'email_masked' => $mask_or_plain_email( $email ),
			'pii_masked'   => ! $clear,
			'phone'        => $candidate->phone,
			'notes'        => $candidate->notes,
			'is_pcd'       => RecruitmentPcdHasher::verify( $candidate->pcd_hash, (int) $candidate->id ),
			'created_at'   => $candidate->created_at,
			'updated_at'   => $candidate->updated_at,
		);
	}
}
