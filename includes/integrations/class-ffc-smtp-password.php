<?php
/**
 * SmtpPassword
 *
 * The SMTP password in `ffc_settings['smtp_pass']`: how it is stored, read
 * back for sending, and described on the SMTP tab (#2342).
 *
 * It is a secret the plugin must hand back to PHPMailer, so it is encrypted
 * rather than hashed, with the plugin's own key (`Core\Encryption`, the one
 * CPF and RF use). It never goes back to the browser: the tab only learns
 * whether one is saved and whether it can still be read.
 *
 * A value written before this class existed is plaintext. It keeps working
 * as it is, and it is encrypted the next time the password is saved. An
 * install without encryption keys stores the password as typed, as before.
 *
 * @package FreeFormCertificate\Integrations
 * @since   6.44.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Integrations;

use FreeFormCertificate\Core\Encryption;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores, reads and describes the SMTP password.
 */
final class SmtpPassword {

	/**
	 * Key inside `ffc_settings`.
	 */
	public const KEY = 'smtp_pass';

	/**
	 * Nothing is saved.
	 */
	public const STATE_NONE = 'none';

	/**
	 * A password is saved and can be read.
	 */
	public const STATE_SAVED = 'saved';

	/**
	 * A password is saved but no longer decrypts (the key changed).
	 */
	public const STATE_UNREADABLE = 'unreadable';

	/**
	 * The value to store for a password the operator typed.
	 *
	 * Stored as typed: no text sanitiser, because one that strips `<`,
	 * percent-encoded sequences or extra spaces changes the password and
	 * sending then fails with no visible reason. Control characters are
	 * removed, since an SMTP AUTH exchange cannot carry them and a password
	 * field cannot produce them.
	 *
	 * @param string $typed Password as typed (unslashed).
	 * @return string Ciphertext, or the password itself when encryption is not configured; '' for nothing typed.
	 */
	public static function for_storage( string $typed ): string {
		$typed = (string) preg_replace( '/[\x00-\x1F\x7F]/', '', $typed );
		if ( '' === $typed ) {
			return '';
		}

		$encrypted = Encryption::encrypt( $typed );
		return null === $encrypted ? $typed : $encrypted;
	}

	/**
	 * The password to send with, from the settings array.
	 *
	 * @param array<mixed> $settings The `ffc_settings` option.
	 * @return string The password, or '' when none is saved or it no longer decrypts.
	 */
	public static function read( array $settings ): string {
		$stored = isset( $settings[ self::KEY ] ) && is_string( $settings[ self::KEY ] ) ? $settings[ self::KEY ] : '';

		// Only the v2 envelope is ever written here. Anything else is a
		// password stored before encryption, read as it is: the legacy v1
		// test (any long base64 string) would mistake such a password for a
		// ciphertext and lose it.
		if ( 0 !== strpos( $stored, Encryption::V2_PREFIX ) || ! Encryption::looks_like_envelope( $stored ) ) {
			return $stored;
		}

		return (string) Encryption::decrypt( $stored );
	}

	/**
	 * Encrypt a password stored before this class existed.
	 *
	 * Runs on `admin_init`. It needs no flag: once the stored value is a
	 * ciphertext it returns at the first check, and an install whose key
	 * cannot encrypt keeps the password as it is.
	 *
	 * @return void
	 */
	public static function maybe_encrypt_stored(): void {
		self::encrypt_stored();
	}

	/**
	 * The rewrite itself, checks included.
	 *
	 * @return bool Whether the stored value was rewritten.
	 */
	public static function encrypt_stored(): bool {
		$settings = get_option( 'ffc_settings', array() );
		if ( ! is_array( $settings ) ) {
			return false;
		}

		$stored = isset( $settings[ self::KEY ] ) && is_string( $settings[ self::KEY ] ) ? $settings[ self::KEY ] : '';
		if ( '' === $stored || 0 === strpos( $stored, Encryption::V2_PREFIX ) ) {
			return false;
		}

		$encrypted = self::for_storage( $stored );
		if ( 0 !== strpos( $encrypted, Encryption::V2_PREFIX ) || self::read( array( self::KEY => $encrypted ) ) !== $stored ) {
			return false;
		}

		$settings[ self::KEY ] = $encrypted;
		return (bool) update_option( 'ffc_settings', $settings );
	}

	/**
	 * What the SMTP tab may say about the saved password, without its value.
	 *
	 * @param array<mixed> $settings The `ffc_settings` option.
	 * @return string One of the STATE_* constants.
	 */
	public static function state( array $settings ): string {
		$stored = isset( $settings[ self::KEY ] ) && is_string( $settings[ self::KEY ] ) ? $settings[ self::KEY ] : '';
		if ( '' === $stored ) {
			return self::STATE_NONE;
		}

		return '' === self::read( $settings ) ? self::STATE_UNREADABLE : self::STATE_SAVED;
	}
}
