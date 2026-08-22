<?php
/**
 * Default email-body loader.
 *
 * Single home for loading the shipped, translatable default "email body" (inner
 * body) templates that live in `templates/emails/` and `return` an associative
 * array — e.g. `array( 'subject' => …, 'body' => … )` for the reregistration
 * emails, or `array( 'body' => … )` for the audience ones. Generalizes the
 * bespoke per-handler loaders into one allowlisted reader (#662 P2/P4).
 *
 * These are the *defaults*: the token-based body text a handler falls back to
 * (audience) or ships as its content (reregistration). The chrome around them
 * is the configurable "Email Model" (layout.php); this class only loads the
 * inner content.
 *
 * @package FreeFormCertificate\Core
 * @since   6.14.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads allowlisted default email-body templates from templates/emails/.
 */
final class EmailTemplates {

	/**
	 * Allowlisted template basenames (no path/extension). Each corresponding
	 * `templates/emails/<name>.php` must `return` an associative array.
	 *
	 * @var array<int, string>
	 */
	private const TEMPLATES = array(
		'reregistration-invitation',
		'reregistration-reminder',
		'reregistration-confirmation',
		'audience-booking',
		'audience-cancellation',
		'certificate-user',
		'recruitment-convocation',
		'selfscheduling-confirmation',
	);

	/**
	 * Option holding the admin-edited GLOBAL overrides, shaped
	 * `array<string, array{subject?:string, body?:string}>` keyed by the
	 * allowlisted template name. Absent/empty ⇒ every email uses its shipped
	 * file default (so a fresh install renders identically). Edited via the
	 * email-body hub (#964); an empty string is never stored (that means
	 * "restore to file", i.e. remove the override).
	 */
	public const OPTION = 'ffc_email_bodies';

	/**
	 * Keys an override may carry.
	 *
	 * @var array<int, string>
	 */
	private const KEYS = array( 'subject', 'body' );

	/**
	 * Load a default email-body template.
	 *
	 * @param string $name Allowlisted template basename.
	 * @return array<string, string>|null The returned array, or null when the
	 *                                     name is unknown / the file is missing
	 *                                     or does not return an array.
	 */
	public static function load( string $name ): ?array {
		if ( ! in_array( $name, self::TEMPLATES, true ) ) {
			return null;
		}

		$file = FFC_PLUGIN_DIR . 'templates/emails/' . $name . '.php';
		if ( ! is_readable( $file ) ) {
			return null;
		}

		$data = include $file;
		return is_array( $data ) ? $data : null;
	}

	/**
	 * The SHIPPED file default for a single key (the pre-6.x behaviour of
	 * {@see self::body()}), ignoring any global override. This is the base of
	 * the cascade — used by the hub's "restore to shipped default".
	 *
	 * @param string $name Allowlisted template basename.
	 * @param string $key  Array key to read (default 'body').
	 * @return string The file value, or '' when unavailable.
	 */
	public static function shipped_body( string $name, string $key = 'body' ): string {
		$data = self::load( $name );
		return ( null !== $data && isset( $data[ $key ] ) ) ? $data[ $key ] : '';
	}

	/**
	 * All stored global overrides — the raw option, treated as untrusted (an
	 * admin/DB-authored blob), hence the `mixed` value type: readers guard each
	 * value with `is_string()` before use.
	 *
	 * @return array<string, mixed>
	 */
	public static function global_overrides(): array {
		$opt = get_option( self::OPTION, array() );
		return is_array( $opt ) ? $opt : array();
	}

	/**
	 * The admin-edited GLOBAL override for a single key, or '' when none is set.
	 *
	 * @param string $name Allowlisted template basename.
	 * @param string $key  Array key to read (default 'body').
	 * @return string The stored override, or '' when unset / not allowlisted.
	 */
	public static function global_body( string $name, string $key = 'body' ): string {
		if ( ! in_array( $name, self::TEMPLATES, true ) ) {
			return '';
		}
		$all = self::global_overrides();
		if ( ! isset( $all[ $name ] ) || ! is_array( $all[ $name ] ) ) {
			return '';
		}
		$val = $all[ $name ][ $key ] ?? '';
		return is_string( $val ) ? $val : '';
	}

	/**
	 * The EFFECTIVE default for a single key: the admin's global override when
	 * set, otherwise the shipped file default. Every send path and every
	 * "Restore Default Text" button resolves through here, so setting a global
	 * body transparently becomes the new fallback for all consumers — and a
	 * fresh install (empty {@see self::OPTION}) is byte-for-byte the old
	 * file-only behaviour.
	 *
	 * @param string $name Allowlisted template basename.
	 * @param string $key  Array key to read (default 'body').
	 * @return string The value, or '' when unavailable.
	 */
	public static function body( string $name, string $key = 'body' ): string {
		$global = self::global_body( $name, $key );
		return '' !== $global ? $global : self::shipped_body( $name, $key );
	}

	/**
	 * Persist an email's global override (subject and/or body). Empty-string
	 * values are dropped, and an override that ends up empty removes the email's
	 * entry entirely (⇒ the email falls back to its shipped file default). The
	 * caller (the hub) is responsible for per-field sanitisation
	 * (`wp_kses_post` on the body, `sanitize_text_field` on the subject).
	 *
	 * @param string               $name   Allowlisted template basename.
	 * @param array<string, mixed> $values Override values (subject / body); each
	 *                                     is used only when a non-empty string.
	 * @return bool True when the option was updated (or already matched).
	 */
	public static function save_global( string $name, array $values ): bool {
		if ( ! in_array( $name, self::TEMPLATES, true ) ) {
			return false;
		}

		$entry = array();
		foreach ( self::KEYS as $key ) {
			if ( isset( $values[ $key ] ) && is_string( $values[ $key ] ) && '' !== $values[ $key ] ) {
				$entry[ $key ] = $values[ $key ];
			}
		}

		$all = self::global_overrides();
		if ( array() === $entry ) {
			unset( $all[ $name ] );
		} else {
			$all[ $name ] = $entry;
		}

		return update_option( self::OPTION, $all );
	}

	/**
	 * Remove an email's global override so it falls back to the shipped file
	 * default (the hub's "restore to shipped default").
	 *
	 * @param string $name Allowlisted template basename.
	 * @return bool True when cleared (or nothing to clear).
	 */
	public static function clear_global( string $name ): bool {
		$all = self::global_overrides();
		if ( ! isset( $all[ $name ] ) ) {
			return true;
		}
		unset( $all[ $name ] );
		return update_option( self::OPTION, $all );
	}
}
