<?php
/**
 * Recruitment REST Support Trait
 *
 * Shared permission gates and `\WP_Error` envelope helpers used by every
 * domain REST controller under the `ffcertificate/v1/recruitment`
 * namespace. Extracted from the original god-object
 * `RecruitmentRestController` (sprint S2 of #141).
 *
 * Authorization model:
 *
 *   - Admin endpoints: `permission_callback` checks
 *     `current_user_can('ffc_manage_recruitment')`. The cap is granted to
 *     the `administrator` role on activation (sprint 3) and to the
 *     dedicated `ffc_recruitment_manager` role.
 *   - Candidate-self endpoint (`GET /me/recruitment`): just
 *     `is_user_logged_in()`. The query joins `candidate.user_id =
 *     current_user_id` so users only ever see their own data.
 *
 * Error contract: every failure returns a `\WP_Error` with a stable
 * `recruitment_*` code, an HTTP status (400/403/404/409/500), and an
 * optional `data` payload (e.g. `blocked_by` reference counts from the
 * delete service).
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.0.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Recruitment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared permission + error-envelope helpers for recruitment REST controllers.
 */
trait RecruitmentRestSupport {

	/**
	 * Permission gate for every admin endpoint.
	 *
	 * The umbrella `ffc_manage_recruitment` cap remains the catch-all
	 * — anyone holding it passes every admin route. The granular 6.2.0
	 * caps (`ffc_view_recruitment`, `ffc_import_recruitment_csv`, etc.)
	 * are layered on top via dedicated permission callbacks for the
	 * higher-blast-radius routes ({@see self::check_can_view_recruitment()},
	 * {@see self::check_can_import_csv()}, {@see self::check_can_call_candidates()}),
	 * so an operator with only `ffc_view_recruitment` can hit GET endpoints
	 * but not the CSV importer or call dispatcher.
	 *
	 * @return bool
	 */
	public function check_admin_cap(): bool {
		return current_user_can( 'ffc_manage_recruitment' );
	}

	/**
	 * Permission gate for read-only endpoints. Accepts either the
	 * granular `ffc_view_recruitment` cap or the umbrella
	 * `ffc_manage_recruitment`.
	 *
	 * @since 6.2.0
	 * @return bool
	 */
	public function check_can_view_recruitment(): bool {
		return current_user_can( 'ffc_view_recruitment' ) || current_user_can( 'ffc_manage_recruitment' );
	}

	/**
	 * Permission gate for CSV import + promote-preview routes. The
	 * highest-blast-radius operations on the module — replace entire
	 * preview / definitive lists atomically. Accepts either the granular
	 * `ffc_import_recruitment_csv` cap or the umbrella
	 * `ffc_manage_recruitment`.
	 *
	 * @since 6.2.0
	 * @return bool
	 */
	public function check_can_import_csv(): bool {
		return current_user_can( 'ffc_import_recruitment_csv' ) || current_user_can( 'ffc_manage_recruitment' );
	}

	/**
	 * Permission gate for the call routes. Each call sends an email +
	 * commits the candidate to a date / time, so operators that should
	 * only manage data (without disparate communication authority) get
	 * a separate cap. Accepts either the granular
	 * `ffc_call_recruitment_candidates` cap or the umbrella
	 * `ffc_manage_recruitment`.
	 *
	 * @since 6.2.0
	 * @return bool
	 */
	public function check_can_call_candidates(): bool {
		return current_user_can( 'ffc_call_recruitment_candidates' ) || current_user_can( 'ffc_manage_recruitment' );
	}

	/**
	 * Permission gate for the reasons-catalog routes. Reasons are global
	 * across every notice — managing them is a config-style operation
	 * separated from day-to-day notice management.
	 *
	 * @since 6.2.0
	 * @return bool
	 */
	public function check_can_manage_reasons(): bool {
		return current_user_can( 'ffc_manage_recruitment_reasons' ) || current_user_can( 'ffc_manage_recruitment' );
	}

	/**
	 * Permission gate for the candidate-self endpoint.
	 *
	 * @return bool
	 */
	public function check_logged_in(): bool {
		return is_user_logged_in();
	}

	/**
	 * Convert an envelope-style errors array into a `\WP_Error`.
	 *
	 * @param array<int, string> $errors  Error codes.
	 * @param int                $status  HTTP status code.
	 * @return \WP_Error
	 */
	private function wp_error_from_envelope( array $errors, int $status ): \WP_Error {
		$code    = $errors[0] ?? 'recruitment_error';
		$message = RecruitmentErrorMessages::translate( $code );
		return new \WP_Error(
			$code,
			$message,
			array(
				'status'   => $status,
				'errors'   => $errors,
				'messages' => RecruitmentErrorMessages::translate_all( $errors ),
			)
		);
	}

	/**
	 * Same as `wp_error_from_envelope` but additionally surfaces a
	 * `blocked_by` reference-count map (returned by the delete service).
	 *
	 * @param array{success: bool, errors: list<string>, blocked_by?: array<string, int>} $envelope Service-result envelope.
	 * @param int                                                                         $status   HTTP status code.
	 * @return \WP_Error
	 */
	private function wp_error_from_envelope_with_blocked( array $envelope, int $status ): \WP_Error {
		$first   = $envelope['errors'][0] ?? 'recruitment_error';
		$message = RecruitmentErrorMessages::translate( $first );
		$data    = array(
			'status'   => $status,
			'errors'   => $envelope['errors'],
			'messages' => RecruitmentErrorMessages::translate_all( $envelope['errors'] ),
		);
		if ( isset( $envelope['blocked_by'] ) ) {
			$data['blocked_by'] = $envelope['blocked_by'];
		}
		return new \WP_Error( $first, $message, $data );
	}
}
