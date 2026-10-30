<?php
/**
 * AppointmentVerificationLookup
 *
 * Finds an appointment receipt for the verification page (by validation code)
 * and the magic link (by confirmation token). Split out of
 * `VerificationHandler` (#2241), which keeps the routing between kinds.
 *
 * @package FreeFormCertificate\Frontend
 */

declare(strict_types=1);

namespace FreeFormCertificate\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Appointment lookup for verification.
 */
class AppointmentVerificationLookup {

	/**
	 * The appointment a validation code names.
	 *
	 * @since 4.2.0
	 * @param string $code Cleaned validation code (no hyphens).
	 * @return array<string, mixed> Result array with 'found', 'submission', 'data', 'type'.
	 */
	public function by_code( string $code ): array {
		if ( ! class_exists( '\\FreeFormCertificate\\Repositories\\AppointmentRepository' ) ) {
			return VerificationResult::not_found();
		}

		$appointment = ( new \FreeFormCertificate\Repositories\AppointmentRepository() )->findByValidationCode( $code );

		return $appointment ? $this->result( $appointment ) : VerificationResult::not_found();
	}

	/**
	 * The appointment a confirmation token (magic link) names.
	 *
	 * @since 4.2.0
	 * @param string $token Confirmation token (64 hex chars).
	 * @return array<string, mixed> Result array with 'found', 'submission', 'data', 'type'.
	 */
	public function by_token( string $token ): array {
		if ( ! class_exists( '\\FreeFormCertificate\\Repositories\\AppointmentRepository' ) ) {
			return VerificationResult::not_found();
		}

		$appointment = ( new \FreeFormCertificate\Repositories\AppointmentRepository() )->findByConfirmationToken( $token );

		return $appointment ? $this->result( $appointment ) : VerificationResult::not_found();
	}

	/**
	 * Build the result from an appointment row.
	 *
	 * @since 4.2.0
	 * @param array<string, mixed> $appointment Appointment row.
	 * @return array<string, mixed> Standardized result array.
	 */
	public function result( array $appointment ): array {
		$email = \FreeFormCertificate\Core\Encryption::decrypt_field( $appointment, 'email' );

		$cpf_val = \FreeFormCertificate\Core\Encryption::decrypt_field( $appointment, 'cpf' );
		$rf_val  = \FreeFormCertificate\Core\Encryption::decrypt_field( $appointment, 'rf' );
		$cpf_rf  = ! empty( $cpf_val ) ? $cpf_val : ( ! empty( $rf_val ) ? $rf_val : '' );

		$data = array(
			'name'             => $appointment['name'] ?? '',
			'email'            => $email,
			'cpf_rf'           => $cpf_rf,
			'auth_code'        => $appointment['validation_code'] ?? '',
			'calendar_title'   => '',
			'appointment_date' => $appointment['appointment_date'] ?? '',
			'start_time'       => $appointment['start_time'] ?? '',
			'end_time'         => $appointment['end_time'] ?? '',
			'status'           => $appointment['status'] ?? 'pending',
			'magic_token'      => $appointment['confirmation_token'] ?? '',
		);

		if ( ! empty( $appointment['calendar_id'] ) ) {
			$calendar = ( new \FreeFormCertificate\Repositories\CalendarRepository() )->findById( (int) $appointment['calendar_id'] );
			if ( $calendar ) {
				$data['calendar_title'] = $calendar['title'] ?? '';
			}
		}

		// A pseudo-submission, so the renderer's certificate helpers apply.
		// `submission_date` on the real table is unix UTC int since 6.6.0
		// (#249 sub-escopo a), so the appointment's `created_at` DATETIME —
		// interpreted in the site TZ — is adapted to match.
		$created_at_raw    = (string) ( $appointment['created_at'] ?? '' );
		$created_at_ts     = '' !== $created_at_raw ? (int) strtotime( $created_at_raw ) : 0;
		$pseudo_submission = array(
			'id'              => $appointment['id'],
			'form_id'         => 0,
			'submission_date' => $created_at_ts,
			'auth_code'       => $appointment['validation_code'] ?? '',
			'email'           => $email,
			'cpf_rf'          => $cpf_rf,
			'data'            => '{}',
			'magic_token'     => $appointment['confirmation_token'] ?? '',
		);

		return array(
			'found'       => true,
			'submission'  => (object) $pseudo_submission,
			'data'        => $data,
			'type'        => 'appointment',
			'appointment' => $appointment,
			'magic_token' => $appointment['confirmation_token'] ?? '',
		);
	}
}
