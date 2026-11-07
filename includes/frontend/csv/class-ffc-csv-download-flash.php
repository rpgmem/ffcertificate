<?php
/**
 * CsvDownloadFlash
 *
 * Flash-message helper for the public CSV download flow. Extracted from
 * {@see \FreeFormCertificate\Frontend\PublicCsvDownload} (#589 phase-2,
 * Sprint E3) so the facade stops owning transient bookkeeping directly.
 *
 * A message is keyed by a random token the redirect carries, never by the
 * visitor's address (#2341).
 *
 * @package FreeFormCertificate\Frontend\Csv
 * @since   6.12.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Frontend\Csv;

use FreeFormCertificate\Core\RequestInput;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Flash messages, keyed by a token the redirect carries (#2341).
 *
 * They were keyed by the visitor's address, so everyone behind one NAT (a
 * school, an office) shared one slot: one visitor's refusal showed on
 * another's screen, and the first page load among them took it. The token
 * is random, travels in the address of the page the visitor is sent back
 * to, and is spent on that page's first render, so a message reaches the
 * browser that caused it and nobody else.
 *
 * @since 6.12.0
 */
class CsvDownloadFlash {

	/**
	 * Flash transient TTL in seconds.
	 */
	const FLASH_TRANSIENT_TTL = 60;

	/**
	 * The query argument that carries the token.
	 */
	const QUERY_ARG = 'ffc_pcd_flash';

	/**
	 * The transient key of a token.
	 *
	 * @param string $token Flash token.
	 * @return string
	 */
	public function flash_transient_key( string $token ): string {
		return 'ffc_pcd_flash_' . $token;
	}

	/**
	 * Redirect back to the referring page after saving a flash message.
	 *
	 * @param string $message User-facing error message.
	 * @return never
	 */
	public function fail_redirect( string $message ): void {
		$token = bin2hex( random_bytes( 16 ) );
		set_transient(
			$this->flash_transient_key( $token ),
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
		wp_safe_redirect( add_query_arg( self::QUERY_ARG, $token, $redirect ) );
		exit;
	}

	/**
	 * Pull and clear the flash message this request's token names, if any.
	 *
	 * @return array{type: string, message: string}|null
	 */
	public function get_flash_message(): ?array {
		$token = (string) preg_replace( '/[^a-f0-9]/', '', RequestInput::get_get_string( self::QUERY_ARG ) );
		if ( 32 !== strlen( $token ) ) {
			return null;
		}
		$key  = $this->flash_transient_key( $token );
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
