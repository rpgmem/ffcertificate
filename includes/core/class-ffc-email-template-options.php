<?php
/**
 * Email "Model" (chrome) options.
 *
 * Single source of truth for the one configurable email chrome shared by every
 * plugin email (#662 P2). The chrome — header band, body card, footer, outer
 * wrapper — is styled from these options and rendered by
 * `templates/emails/layout.php`; the editable/handler-built "miolo" is injected
 * into the body cell with no marker.
 *
 * Storage: a dedicated option `ffc_email_template` (NOT `ffc_settings`), so the
 * model is self-contained and easy to reset. Values are sanitized on save
 * ({@see self::sanitize()}), so {@see self::all()} always returns a clean,
 * fully-populated map — the layout template can trust it without re-sanitizing
 * (which keeps the render path free of `absint()` / `sanitize_hex_color()`).
 *
 * Inspired by the "Models" feature of the sibling plugin rpgmem/total-mail-queue.
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
 * Read / write the configurable email chrome options.
 */
final class EmailTemplateOptions {

	/**
	 * WP option key backing the model.
	 */
	public const OPTION_NAME = 'ffc_email_template';

	/**
	 * Allowed header alignments.
	 *
	 * @var array<int, string>
	 */
	public const HEADER_ALIGNMENTS = array( 'left', 'center', 'right' );

	/**
	 * Allowed body font-family keys (mapped to stacks by {@see self::font_stack()}).
	 *
	 * @var array<int, string>
	 */
	public const BODY_FONT_FAMILIES = array( 'system', 'serif', 'mono', 'arial', 'georgia' );

	/**
	 * Font-family key → CSS font stack.
	 *
	 * @var array<string, string>
	 */
	private const FONT_STACKS = array(
		'system'  => '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif',
		'serif'   => 'Georgia, "Times New Roman", Times, serif',
		'mono'    => '"SFMono-Regular", Menlo, Monaco, Consolas, "Courier New", monospace',
		'arial'   => 'Arial, Helvetica, sans-serif',
		'georgia' => 'Georgia, "Times New Roman", Times, serif',
	);

	/**
	 * Built-in defaults — every key the chrome knows about.
	 *
	 * The palette matches the plugin's admin accent (`#2271b1`). The footer
	 * source string is English (Loco renders pt-BR "Enviado por …").
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'header_bg'             => '#2271b1',
			'header_text_color'     => '#ffffff',
			'header_alignment'      => 'center',
			'header_padding'        => 24,
			'header_logo_url'       => '',
			'header_logo_max_width' => 180,
			'body_bg'               => '#ffffff',
			'body_text_color'       => '#333333',
			'body_link_color'       => '#2271b1',
			'body_font_family'      => 'system',
			'body_font_size'        => 14,
			'body_padding'          => 24,
			'body_max_width'        => 600,
			'footer_bg'             => '#f5f5f5',
			'footer_text_color'     => '#666666',
			'footer_text'           => __( 'Sent by {{site_title}}', 'ffcertificate' ),
			'wrapper_bg'            => '#f0f0f1',
			'wrapper_border_radius' => 6,
			'wrapper_padding'       => 32,
		);
	}

	/**
	 * Resolved options: stored row merged over the defaults.
	 *
	 * Every key is guaranteed present. Stored values were cleaned by
	 * {@see self::sanitize()} at save time, so callers may render them directly.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION_NAME, array() );
		$stored = is_array( $stored ) ? $stored : array();
		return array_merge( self::defaults(), $stored );
	}

	/**
	 * Read a single resolved option value.
	 *
	 * @param string $key     Option key.
	 * @param mixed  $default Returned when the key is unknown.
	 * @return mixed
	 */
	public static function get( string $key, $default = null ) {
		$all = self::all();
		return $all[ $key ] ?? $default;
	}

	/**
	 * Resolve a font-family key to its CSS stack (falls back to system).
	 *
	 * @param string $key Font-family key.
	 * @return string
	 */
	public static function font_stack( string $key ): string {
		return self::FONT_STACKS[ $key ] ?? self::FONT_STACKS['system'];
	}

	/**
	 * The full font-family key → CSS stack map (for JS-side preview rendering).
	 *
	 * @return array<string, string>
	 */
	public static function font_stacks(): array {
		return self::FONT_STACKS;
	}

	/**
	 * Sanitize raw input (typically `$_POST`) into a clean, persistable shape.
	 *
	 * Every key is present in the result — missing/invalid inputs fall back to
	 * the default, never to null/empty — so {@see self::all()} stays trustworthy.
	 *
	 * @param array<string, mixed> $raw User-supplied values.
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $raw ): array {
		$defaults = self::defaults();
		$out      = $defaults;

		$color_fields = array(
			'header_bg',
			'header_text_color',
			'body_bg',
			'body_text_color',
			'body_link_color',
			'footer_bg',
			'footer_text_color',
			'wrapper_bg',
		);
		foreach ( $color_fields as $field ) {
			if ( array_key_exists( $field, $raw ) ) {
				$clean = sanitize_hex_color( (string) $raw[ $field ] );
				if ( null !== $clean && '' !== $clean ) {
					$out[ $field ] = $clean;
				}
			}
		}

		$int_fields = array(
			'header_padding',
			'header_logo_max_width',
			'body_font_size',
			'body_padding',
			'body_max_width',
			'wrapper_border_radius',
			'wrapper_padding',
		);
		foreach ( $int_fields as $field ) {
			if ( array_key_exists( $field, $raw ) ) {
				$out[ $field ] = absint( $raw[ $field ] );
			}
		}

		if ( array_key_exists( 'header_alignment', $raw ) ) {
			$val                     = (string) $raw['header_alignment'];
			$out['header_alignment'] = in_array( $val, self::HEADER_ALIGNMENTS, true ) ? $val : $defaults['header_alignment'];
		}
		if ( array_key_exists( 'body_font_family', $raw ) ) {
			$val                     = (string) $raw['body_font_family'];
			$out['body_font_family'] = in_array( $val, self::BODY_FONT_FAMILIES, true ) ? $val : $defaults['body_font_family'];
		}

		if ( array_key_exists( 'header_logo_url', $raw ) ) {
			$out['header_logo_url'] = esc_url_raw( (string) $raw['header_logo_url'] );
		}

		// footer_text keeps a little HTML (an <a>/<strong> is common) plus the
		// {{token}} placeholders, which wp_kses_post leaves intact.
		if ( array_key_exists( 'footer_text', $raw ) ) {
			$out['footer_text'] = wp_kses_post( (string) $raw['footer_text'] );
		}

		return $out;
	}

	/**
	 * Persist sanitized options, returning the cleaned values that were stored.
	 *
	 * @param array<string, mixed> $raw Raw user input.
	 * @return array<string, mixed>
	 */
	public static function update( array $raw ): array {
		$clean = self::sanitize( $raw );
		update_option( self::OPTION_NAME, $clean );
		return $clean;
	}

	/**
	 * Reset to defaults (delete the row; subsequent reads return defaults).
	 *
	 * @return void
	 */
	public static function reset(): void {
		delete_option( self::OPTION_NAME );
	}

	/**
	 * Chrome placeholder map ({@see \FreeFormCertificate\Core\TokenResolver}
	 * `{{token}} => value`) for the footer text.
	 *
	 * @param array<string, string> $context Extra values (e.g. 'recipient').
	 * @return array<string, string>
	 */
	public static function footer_tokens( array $context = array() ): array {
		$now = time();
		return array(
			'{{site_title}}'  => (string) get_bloginfo( 'name' ),
			'{{site_url}}'    => (string) home_url(),
			'{{home_url}}'    => (string) home_url(),
			'{{admin_email}}' => (string) get_option( 'admin_email', '' ),
			'{{recipient}}'   => isset( $context['recipient'] ) ? (string) $context['recipient'] : '',
			'{{date}}'        => DateFormatter::format_date( $now ),
			// Bare 4-digit year for footer/copyright lines. wp_date applies the
			// site timezone; DateFormatter has no year-only helper.
			'{{year}}'        => (string) wp_date( 'Y', $now ),
		);
	}
}
