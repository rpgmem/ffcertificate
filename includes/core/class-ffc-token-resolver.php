<?php
/**
 * Token Resolver
 *
 * Single-pass `{{token}}` substitution — the one scalar-placeholder resolver
 * shared by the email and document (PDF) render paths, replacing the
 * per-site `str_replace` / `strtr` implementations (#653). Uses `strtr`, so a
 * substituted value that happens to contain another token is never
 * re-substituted (single pass), unlike a sequential `str_replace` loop.
 *
 * @package FreeFormCertificate\Core
 * @since 6.14.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scalar `{{token}}` → value resolver.
 */
final class TokenResolver {

	/**
	 * Replace `{{token}}` placeholders in a template.
	 *
	 * @param string                $template Template text.
	 * @param array<string, string> $tokens   Map of full placeholder (e.g.
	 *                                         `{{name}}`) to replacement value.
	 * @return string
	 */
	public static function resolve( string $template, array $tokens ): string {
		return array() === $tokens ? $template : strtr( $template, $tokens );
	}
}
