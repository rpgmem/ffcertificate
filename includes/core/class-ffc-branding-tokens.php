<?php
/**
 * BrandingTokens
 *
 * Resolves the site-level branding logo tokens `{{logo_gov}}` (government /
 * institution) and `{{logo_org}}` (organization) used by the default
 * certificate, ficha and appointment-receipt templates (issue #865, Phase 2).
 *
 * Each token resolves to a URL configured in **Settings → General → Branding**
 * (`logo_gov` / `logo_org`, a Media Library URL), falling back to a generic
 * shipped placeholder under `assets/img/certificate-defaults/` when unset — so
 * the default templates never render a broken image, and no instance-specific
 * logo is hardcoded into the plugin. Both PDF renderers (the certificate
 * {@see \FreeFormCertificate\Generators\PdfHtmlRenderer} and the reregistration
 * ficha generator) route through this one helper so the fallback stays uniform.
 *
 * @package FreeFormCertificate\Core
 * @since   6.20.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Site branding logo token resolver.
 */
class BrandingTokens {

	/**
	 * Directory (under the plugin URL) holding the shipped fallback logo.
	 */
	private const FALLBACK_DIR = 'assets/img/certificate-defaults/';

	/**
	 * Basename of the generic placeholder used when a logo isn't configured.
	 */
	private const FALLBACK_LOGO = 'logo-placeholder.svg';

	/**
	 * Government / institution logo URL — `{{logo_gov}}`.
	 *
	 * @return string
	 */
	public static function logo_gov_url(): string {
		return self::resolve( 'logo_gov' );
	}

	/**
	 * Organization logo URL — `{{logo_org}}`.
	 *
	 * @return string
	 */
	public static function logo_org_url(): string {
		return self::resolve( 'logo_org' );
	}

	/**
	 * Configured URL for a branding key, or the shipped placeholder when empty.
	 *
	 * @param string $key Settings key (`logo_gov` / `logo_org`).
	 * @return string
	 */
	private static function resolve( string $key ): string {
		$url = (string) \FreeFormCertificate\Settings\SettingsReader::get( $key, '' );
		if ( '' !== trim( $url ) ) {
			return $url;
		}
		return FFC_PLUGIN_URL . self::FALLBACK_DIR . self::FALLBACK_LOGO;
	}
}
