<?php
/**
 * Default email-miolo loader.
 *
 * Single home for loading the shipped, translatable default "miolo" (inner
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
 * Loads allowlisted default miolo templates from templates/emails/.
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
	);

	/**
	 * Load a default miolo template.
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
	 * Convenience reader for a single key (e.g. the audience body-only templates).
	 *
	 * @param string $name Allowlisted template basename.
	 * @param string $key  Array key to read (default 'body').
	 * @return string The value, or '' when unavailable.
	 */
	public static function body( string $name, string $key = 'body' ): string {
		$data = self::load( $name );
		return ( null !== $data && isset( $data[ $key ] ) ) ? $data[ $key ] : '';
	}
}
