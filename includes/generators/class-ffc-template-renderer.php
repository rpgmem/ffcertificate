<?php
/**
 * Template Renderer
 *
 * Thin orchestrator that composes the placeholder resolvers into the named
 * render pipelines each surface needs (#653). It defines *what a render is* in
 * one place instead of each call site re-assembling the steps — and, crucially,
 * the email pipeline never includes the QR resolver, so emails don't drag a QR
 * dependency into their module.
 *
 * Resolvers (each small + independently tested):
 * - Core\TokenResolver              — scalar `{{token}}` substitution.
 * - {@see ValidationUrlPlaceholders} — the `{{validation_url …}}` link DSL.
 * - QR (document only)               — added in a later step of #653.
 *
 * @package FreeFormCertificate\Generators
 * @since 6.14.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Generators;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Composes placeholder resolvers into per-surface render pipelines.
 */
final class TemplateRenderer {

	/**
	 * Email render pipeline: scalar tokens, then the `{{validation_url …}}`
	 * link DSL. No QR (emails don't use it).
	 *
	 * @param string                $template Template text.
	 * @param array<string, string> $tokens   Full-placeholder => value map.
	 * @param array<string, mixed>  $data     Context for the DSL (reads `magic_token`).
	 * @return string
	 */
	public static function email( string $template, array $tokens, array $data = array() ): string {
		$template = \FreeFormCertificate\Core\TokenResolver::resolve( $template, $tokens );
		return ValidationUrlPlaceholders::process( $template, $data );
	}
}
