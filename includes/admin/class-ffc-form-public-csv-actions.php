<?php
/**
 * Public Operator Access actions on a form.
 *
 * The access hash, the download counter and the first-enable defaults of a
 * form's Public Operator Access, in one place. The full save handler, the
 * autosave of the master toggle and the two buttons of the Operator tab
 * call the same methods, so all three paths make the same writes (#1755).
 *
 * @package FreeFormCertificate\Admin
 * @since   6.40.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

use FreeFormCertificate\Core\RequestInput;
use FreeFormCertificate\Settings\SettingsReader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hash, counter and enable defaults of a form's Public Operator Access.
 */
class FormPublicCsvActions {

	/**
	 * AJAX action for the Regenerate and Reset buttons, and its nonce action.
	 */
	public const AJAX_ACTION = 'ffc_csv_public_action';

	/**
	 * CPF modes, from the loosest to the strictest check.
	 */
	public const CPF_MODES = array( 'none', 'audit', 'participants', 'owner', 'whitelist' );

	/**
	 * The mode a form uses when none was ever saved. Audit records who
	 * downloaded without matching the CPF against anything.
	 */
	public const DEFAULT_CPF_MODE = 'audit';

	/**
	 * Register the AJAX handler.
	 */
	public static function init(): void {
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( self::class, 'handle' ) );
	}

	/**
	 * The work of a first enable: a hash when there is none, and the audit
	 * mode when the stored mode is empty or 'none'.
	 *
	 * @param int $post_id Form id.
	 * @return array{share_link: string, cpf_mode: string} The values the page shows.
	 */
	public static function on_enable( int $post_id ): array {
		$hash = self::hash( $post_id );
		if ( '' === $hash ) {
			$hash = self::regenerate_hash( $post_id );
		}
		$stored = get_post_meta( $post_id, '_ffc_csv_public_cpf_mode', true );
		$mode   = self::cpf_mode( $post_id );
		if ( ! is_scalar( $stored ) || in_array( (string) $stored, array( '', 'none' ), true ) ) {
			$mode = self::DEFAULT_CPF_MODE;
			update_post_meta( $post_id, '_ffc_csv_public_cpf_mode', $mode );
		}
		return array(
			'share_link' => self::share_link( $post_id, $hash ),
			'cpf_mode'   => $mode,
		);
	}

	/**
	 * Replace the access hash, which invalidates every link already shared.
	 *
	 * @param int $post_id Form id.
	 * @return string The new hash.
	 */
	public static function regenerate_hash( int $post_id ): string {
		try {
			$hash = bin2hex( random_bytes( 16 ) );
		} catch ( \Exception $e ) {
			$hash = wp_generate_password( 32, false, false );
		}
		update_post_meta( $post_id, '_ffc_csv_public_hash', $hash );
		return $hash;
	}

	/**
	 * Set the download counter back to zero.
	 *
	 * @param int $post_id Form id.
	 */
	public static function reset_counter( int $post_id ): void {
		update_post_meta( $post_id, '_ffc_csv_public_count', 0 );
	}

	/**
	 * The stored access hash, or an empty string.
	 *
	 * @param int $post_id Form id.
	 * @return string
	 */
	public static function hash( int $post_id ): string {
		$stored = get_post_meta( $post_id, '_ffc_csv_public_hash', true );
		return is_scalar( $stored ) ? (string) $stored : '';
	}

	/**
	 * The stored CPF mode, or the default when none was saved.
	 *
	 * @param int $post_id Form id.
	 * @return string One of {@see self::CPF_MODES}.
	 */
	public static function cpf_mode( int $post_id ): string {
		$stored = get_post_meta( $post_id, '_ffc_csv_public_cpf_mode', true );
		$mode   = is_scalar( $stored ) ? (string) $stored : '';
		return in_array( $mode, self::CPF_MODES, true ) ? $mode : self::DEFAULT_CPF_MODE;
	}

	/**
	 * The link an operator opens: the CSV download page URL from Settings →
	 * General with the form and hash appended, or the bare query string when
	 * that URL is not set.
	 *
	 * @param int    $post_id Form id.
	 * @param string $hash    Access hash.
	 * @return string Empty when there is no hash yet.
	 */
	public static function share_link( int $post_id, string $hash ): string {
		if ( '' === $hash ) {
			return '';
		}
		$page  = (string) SettingsReader::get( 'csv_download_page_url', '' );
		$query = 'form_id=' . $post_id . '&hash=' . $hash;
		if ( '' === $page ) {
			return '?' . $query;
		}
		return $page . ( str_contains( $page, '?' ) ? '&' : '?' ) . $query;
	}

	/**
	 * Handle a Regenerate or Reset click.
	 */
	public static function handle(): void {
		check_ajax_referer( self::AJAX_ACTION, 'nonce' );

		$post_id = RequestInput::get_post_int( 'post_id', 0 );
		if ( $post_id <= 0 || 'ffc_form' !== get_post_type( $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid form.', 'ffcertificate' ) ), 400 );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to edit this form.', 'ffcertificate' ) ), 403 );
		}

		$op = RequestInput::get_post_string( 'op', '' );
		if ( 'regenerate' === $op ) {
			$hash = self::regenerate_hash( $post_id );
			wp_send_json_success( array( 'share_link' => self::share_link( $post_id, $hash ) ) );
		}
		if ( 'reset' === $op ) {
			self::reset_counter( $post_id );
			wp_send_json_success( array( 'count' => 0 ) );
		}
		wp_send_json_error( array( 'message' => __( 'Unknown action.', 'ffcertificate' ) ), 400 );
	}
}
