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
	 * Convenience reader for a single key of the SHIPPED file default (e.g. the
	 * audience body-only templates), ignoring any global override. This is the
	 * base of the cascade and the historical behaviour every existing caller
	 * relies on — it never reads options, so it is safe in any context.
	 *
	 * @param string $name Allowlisted template basename.
	 * @param string $key  Array key to read (default 'body').
	 * @return string The file value, or '' when unavailable.
	 */
	public static function body( string $name, string $key = 'body' ): string {
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
	 * set, otherwise the shipped file default ({@see self::body()}). This is the
	 * opt-in cascade — a send path (or a "Restore Default" button) that wants to
	 * honour the global override calls this instead of {@see self::body()}. It
	 * reads {@see self::OPTION}, so callers run where WordPress options are
	 * available (i.e. not during option registration); with the option empty it
	 * is byte-for-byte the file-only {@see self::body()}. Send sites migrate
	 * from `body()` to `effective_body()` per feature increment (#964).
	 *
	 * @param string $name Allowlisted template basename.
	 * @param string $key  Array key to read (default 'body').
	 * @return string The value, or '' when unavailable.
	 */
	public static function effective_body( string $name, string $key = 'body' ): string {
		$global = self::global_body( $name, $key );
		return '' !== $global ? $global : self::body( $name, $key );
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
	 * Whether a per-entity stored value is a real **Custom** override — i.e. it
	 * diverges from the shipped default and should win over the global. This is
	 * the migration-free "Global vs Custom" heuristic (#964): a stored value is
	 * treated as Global (⇒ tracks the effective global, this returns `false`)
	 * when it is empty OR, once normalised to visible text, equal to the shipped
	 * **file** default; otherwise it is a Custom override (returns `true`).
	 *
	 * The baseline is the shipped FILE default (not the effective global) on
	 * purpose: legacy entities that merely carry the old pre-seeded file default
	 * then keep resolving as Global, so a global override set *later* in the hub
	 * still reaches them (maximal reach, no data migration). Only a value the
	 * operator actually changed away from the shipped text is a Custom. Comparison
	 * is on normalised visible text (tags stripped, entities decoded, whitespace
	 * collapsed), so trivial markup drift does not force a false "Custom".
	 *
	 * @param string $name   Allowlisted template basename.
	 * @param string $stored The entity's stored value (e.g. a form's email body).
	 * @param string $key    Array key to compare (default 'body').
	 * @return bool True when `$stored` is a genuine Custom override.
	 */
	public static function overrides_global( string $name, string $stored, string $key = 'body' ): bool {
		$stored_norm = self::normalize_for_compare( $stored );
		if ( '' === $stored_norm ) {
			return false;
		}
		return self::normalize_for_compare( self::body( $name, $key ) ) !== $stored_norm;
	}

	/**
	 * Normalise an email value to its visible text for the Global/Custom compare
	 * ({@see self::overrides_global()}): strip tags, decode entities, collapse all
	 * whitespace to single spaces, trim.
	 *
	 * @param string $html Raw stored/default value.
	 * @return string Normalised visible text.
	 */
	private static function normalize_for_compare( string $html ): string {
		$text = wp_strip_all_tags( $html );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		return trim( (string) preg_replace( '/\s+/', ' ', $text ) );
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
