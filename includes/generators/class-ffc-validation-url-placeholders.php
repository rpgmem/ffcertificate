<?php
/**
 * Validation URL placeholder DSL
 *
 * Shared processor for the `{{validation_url ...}}` template DSL, used by both
 * the certificate PDF layout ({@see PdfHtmlRenderer}) and the submitter
 * confirmation email. Extracted from `PdfHtmlRenderer` so the two paths share
 * one implementation (#649).
 *
 * Supported forms:
 * - `{{validation_url}}`                         → href = magic link, text = /valid
 * - `{{validation_url link:m>v}}`                → href = magic link, text = /valid
 * - `{{validation_url link:v>v}}`                → href = /valid,     text = /valid
 * - `{{validation_url link:m>m}}`                → href = magic link, text = magic link
 * - `{{validation_url link:v>m}}`                → href = /valid,     text = magic link
 * - `{{validation_url link:m>"Custom Text"}}`    → href = magic link, custom text
 * - `{{validation_url link:m>v target:_blank}}`  → adds target
 * - `{{validation_url link:m>v color:#0073aa}}`  → adds inline text color
 *
 * `m` (magic link) is the view/download link for the issued document; `v` is
 * the public `/valid` verification page.
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
 * Processor for the `{{validation_url ...}}` DSL.
 */
final class ValidationUrlPlaceholders {

	/**
	 * Replace every `{{validation_url ...}}` placeholder in a template with the
	 * corresponding `<a>` tag.
	 *
	 * @param string               $template Template HTML containing placeholders.
	 * @param array<string, mixed> $data     Submission data (reads `magic_token`).
	 * @return string Processed HTML.
	 */
	public static function process( string $template, array $data ): string {
		$valid_url = untrailingslashit( site_url( 'valid' ) );

		$magic_token = isset( $data['magic_token'] ) ? (string) $data['magic_token'] : '';
		$magic_url   = \FreeFormCertificate\Generators\MagicLinkHelper::generate_magic_link( $magic_token );
		if ( empty( $magic_url ) ) {
			$magic_url = $valid_url; // Fallback when no token.
		}

		if ( ! preg_match_all( '/{{validation_url(?:\s+([^}]+))?}}/', $template, $matches, PREG_SET_ORDER ) ) {
			return $template;
		}

		foreach ( $matches as $match ) {
			$full_placeholder = $match[0];
			$params           = self::parse( isset( $match[1] ) ? trim( $match[1] ) : '' );

			$href = ( 'm' === $params['to'] ) ? $magic_url : $valid_url;

			if ( ! in_array( $params['text'], array( 'm', 'v' ), true ) ) {
				$text = $params['text']; // Custom literal text.
			} elseif ( 'm' === $params['text'] ) {
				$text = $magic_url;
			} else {
				$text = $valid_url;
			}

			$link = '<a href="' . esc_url( $href ) . '" class="ffc-validation-link"';
			if ( '' !== $params['target'] ) {
				$link .= ' target="' . esc_attr( $params['target'] ) . '"';
			}
			if ( '' !== $params['color'] ) {
				$link .= ' style="color: ' . esc_attr( $params['color'] ) . ';"';
			}
			$link .= '>' . esc_html( $text ) . '</a>';

			$template = str_replace( $full_placeholder, $link, $template );
		}

		return $template;
	}

	/**
	 * Parse a `{{validation_url ...}}` parameter string.
	 *
	 * The tokenizer keeps double-quoted substrings intact, so custom text that
	 * contains spaces (e.g. `link:m>"Download document (PDF)"`) parses
	 * correctly — the previous naive `preg_split('/\s+/')` split such text
	 * apart and dropped it (#649).
	 *
	 * @param string $params_string Parameter string (e.g. `link:m>"A B" color:#000`).
	 * @return array{to: string, text: string, target: string, color: string}
	 */
	public static function parse( string $params_string ): array {
		$params = array(
			'to'     => 'm', // Default destination: magic link.
			'text'   => 'v', // Default text: /valid URL.
			'target' => '',
			'color'  => '',
		);

		if ( '' === $params_string ) {
			return $params;
		}

		// Quote-aware tokenizer: each token is a run of non-space/non-quote
		// characters and/or fully double-quoted substrings, so a quoted value
		// with spaces stays a single token.
		if ( ! preg_match_all( '/(?:[^\s"]+|"[^"]*")+/', $params_string, $token_matches ) ) {
			return $params;
		}

		foreach ( $token_matches[0] as $part ) {
			if ( preg_match( '/^link:(.+)$/', $part, $link_match ) ) {
				$link_value = $link_match[1];
				if ( preg_match( '/^([mv])>"([^"]*)"$/', $link_value, $custom_match ) ) {
					$params['to']   = $custom_match[1];
					$params['text'] = $custom_match[2]; // Literal custom text (may contain spaces).
				} elseif ( preg_match( '/^([mv])>([mv])$/', $link_value, $standard_match ) ) {
					$params['to']   = $standard_match[1];
					$params['text'] = $standard_match[2];
				}
			} elseif ( preg_match( '/^target:(.+)$/', $part, $target_match ) ) {
				$params['target'] = $target_match[1];
			} elseif ( preg_match( '/^color:(.+)$/', $part, $color_match ) ) {
				$params['color'] = $color_match[1];
			}
		}

		return $params;
	}
}
