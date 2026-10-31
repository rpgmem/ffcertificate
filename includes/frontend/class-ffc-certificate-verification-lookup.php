<?php
/**
 * CertificateVerificationLookup
 *
 * Finds a certificate for the verification page and the magic link, and turns
 * the row into the result the page renders. Split out of
 * `VerificationHandler` (#2241), which keeps the routing between kinds.
 *
 * @package FreeFormCertificate\Frontend
 */

declare(strict_types=1);

namespace FreeFormCertificate\Frontend;

use FreeFormCertificate\Core\SubmissionStatus;
use FreeFormCertificate\Repositories\SubmissionRepository;
use FreeFormCertificate\Submissions\SubmissionHandler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Certificate lookup for verification.
 */
class CertificateVerificationLookup {

	/**
	 * Supplies the submission handler, built only when a lookup needs it.
	 *
	 * @var \Closure(): SubmissionHandler
	 */
	private \Closure $submission_handler;

	/**
	 * Constructor.
	 *
	 * @param \Closure $submission_handler Supplies the submission handler.
	 * @phpstan-param \Closure(): SubmissionHandler $submission_handler
	 */
	public function __construct( \Closure $submission_handler ) {
		$this->submission_handler = $submission_handler;
	}

	/**
	 * The certificate an auth code names, or null.
	 *
	 * A quiz attempt that did not pass carries an auth code but is not a
	 * certificate, so it is not found (#1744).
	 *
	 * @param string $code Clean auth code.
	 * @return array<string, mixed>|null
	 */
	public function by_code( string $code ): ?array {
		$submission = ( new SubmissionRepository() )->findByAuthCode( $code );
		if ( ! is_array( $submission ) || SubmissionStatus::is_unpassed_quiz( $submission ) ) {
			return null;
		}
		return $submission;
	}

	/**
	 * The result for a certificate found by its auth code.
	 *
	 * @param array<string, mixed> $submission Submission row.
	 * @return array<string, mixed>
	 */
	public function code_result( array $submission ): array {
		if ( self::is_revoked( $submission ) ) {
			return self::revoked_result( $submission );
		}

		$submission = ( $this->submission_handler )()->decrypt_submission_data( $submission );

		$data = array(
			'email' => $submission['email'],
		);

		if ( ! empty( $submission['auth_code'] ) ) {
			$data['auth_code'] = $submission['auth_code'];
		}

		if ( ! empty( $submission['cpf_rf'] ) ) {
			$data['cpf_rf'] = $submission['cpf_rf'];
		}
		if ( ! empty( $submission['cpf'] ) ) {
			$data['cpf'] = $submission['cpf'];
		}
		if ( ! empty( $submission['rf'] ) ) {
			$data['rf'] = $submission['rf'];
		}

		$extra_data = self::decode_data( $submission['data'] );
		if ( ! empty( $extra_data ) ) {
			$data = array_merge( $extra_data, $data );
		}

		return array(
			'found'      => true,
			'submission' => (object) $submission,
			'data'       => $data,
		);
	}

	/**
	 * The certificate a magic token names, decrypted, or null.
	 *
	 * A quiz attempt that did not pass has a token but is not a certificate
	 * (#1744).
	 *
	 * @param string $token Magic token.
	 * @return array<string, mixed>|null
	 */
	public function by_token( string $token ): ?array {
		$submission = ( $this->submission_handler )()->get_submission_by_token( $token );
		if ( ! is_array( $submission ) || SubmissionStatus::is_unpassed_quiz( $submission ) ) {
			return null;
		}
		return $submission;
	}

	/**
	 * The result for a certificate opened by its magic link. The access is
	 * written to the activity log (LGPD).
	 *
	 * The row comes decrypted from {@see by_token()}, which reads through
	 * `SubmissionHandler::get_submission_by_token()`.
	 *
	 * @param array<string, mixed> $submission Submission row.
	 * @param string               $token      The token that opened it.
	 * @return array<string, mixed>
	 */
	public function token_result( array $submission, string $token ): array {
		if ( self::is_revoked( $submission ) ) {
			return self::revoked_result( $submission ) + array( 'magic_token' => '' );
		}

		if ( class_exists( '\\FreeFormCertificate\\Core\\ActivityLog' ) ) {
			\FreeFormCertificate\Core\ActivityLog::log_data_accessed(
				(int) $submission['id'],
				array(
					'method' => 'magic_link',
					'token'  => substr( $token, 0, 8 ) . '...',
					'ip'     => \FreeFormCertificate\Core\RequestInput::get_user_ip(),
				)
			);
		}

		// Columns first, then the answers; a column wins over an answer of the same key.
		$data = array();

		if ( ! empty( $submission['email'] ) ) {
			$data['email'] = $submission['email'];
		}

		if ( ! empty( $submission['auth_code'] ) ) {
			$data['auth_code'] = $submission['auth_code'];
		}

		if ( ! empty( $submission['cpf_rf'] ) ) {
			$data['cpf_rf'] = $submission['cpf_rf'];
		}

		$extra_data = self::decode_data( $submission['data'] );
		if ( ! empty( $extra_data ) ) {
			$data = array_merge( $extra_data, $data );
		}

		// An old submission may have no token stored yet.
		$magic_token = $submission['magic_token'];
		if ( empty( $magic_token ) ) {
			$magic_token = ( $this->submission_handler )()->ensure_magic_token( (int) $submission['id'] );
		}

		return array(
			'found'       => true,
			'submission'  => (object) $submission,
			'data'        => $data,
			'magic_token' => $magic_token,
		);
	}

	/**
	 * Whether a certificate row was revoked (#2241).
	 *
	 * The trash is the revocation: a trashed certificate is still found, so
	 * the page can say it was revoked instead of that it never existed.
	 *
	 * @param array<string, mixed> $submission Submission row.
	 * @return bool
	 */
	public static function is_revoked( array $submission ): bool {
		return SubmissionStatus::TRASH === ( $submission['status'] ?? '' );
	}

	/**
	 * The result for a revoked certificate: no data, no PDF.
	 *
	 * @param array<string, mixed> $submission Submission row.
	 * @return array<string, mixed>
	 */
	public static function revoked_result( array $submission ): array {
		return array(
			'found'      => false,
			'submission' => null,
			'data'       => array(),
			'revoked'    => true,
			'revoked_at' => is_numeric( $submission['revoked_at'] ?? null ) ? (int) $submission['revoked_at'] : 0,
		);
	}

	/**
	 * The stored answers, read the way they were written: plain JSON, or JSON
	 * a legacy path slashed before storing.
	 *
	 * @param mixed $raw The `data` column.
	 * @return array<string, mixed>|null
	 */
	private static function decode_data( $raw ): ?array {
		$data = json_decode( (string) $raw, true );
		if ( ! is_array( $data ) ) {
			$data = json_decode( wp_unslash( (string) $raw ), true );
		}
		return is_array( $data ) ? $data : null;
	}
}
