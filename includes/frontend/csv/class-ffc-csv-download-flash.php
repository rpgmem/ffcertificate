<?php
/**
 * CsvDownloadFlash
 *
 * Flash-message helper for the public CSV download flow. Extracted from
 * {@see \FreeFormCertificate\Frontend\PublicCsvDownload} (#589 phase-2,
 * Sprint E3) so the facade stops owning transient bookkeeping directly.
 *
 * The transient key format MUST stay identical to the pre-extraction
 * implementation — `ffc_pcd_flash_` + sha1( visitor IP ) — so in-flight
 * flash messages survive the refactor.
 *
 * @package FreeFormCertificate\Frontend\Csv
 * @since   6.7.x
 */

declare(strict_types=1);

namespace FreeFormCertificate\Frontend\Csv;

use FreeFormCertificate\Core\RequestInput;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Flash messages (transient keyed by IP hash).
 *
 * @since 6.7.x
 */
class CsvDownloadFlash {

	/**
	 * Flash transient TTL in seconds.
	 */
	const FLASH_TRANSIENT_TTL = 60;

	/**
	 * Build a transient key scoped to the current visitor's IP.
	 */
	public function flash_transient_key(): string {
		$ip = RequestInput::get_user_ip();
		return 'ffc_pcd_flash_' . sha1( $ip );
	}

	/**
	 * Redirect back to the referring page after saving a flash message.
	 *
	 * @param string $message User-facing error message.
	 * @return never
	 */
	public function fail_redirect( string $message ): void {
		set_transient(
			$this->flash_transient_key(),
			array(
				'type'    => 'error',
				'message' => $message,
			),
			self::FLASH_TRANSIENT_TTL
		);

		$redirect = wp_get_referer();
		if ( ! $redirect ) {
			$redirect = home_url( '/' );
		}
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Pull and clear the current visitor's flash message, if any.
	 *
	 * @return array{type: string, message: string}|null
	 */
	public function get_flash_message(): ?array {
		$key  = $this->flash_transient_key();
		$data = get_transient( $key );
		if ( ! is_array( $data ) || empty( $data['message'] ) ) {
			return null;
		}
		delete_transient( $key );
		return array(
			'type'    => isset( $data['type'] ) ? (string) $data['type'] : 'error',
			'message' => (string) $data['message'],
		);
	}
}
