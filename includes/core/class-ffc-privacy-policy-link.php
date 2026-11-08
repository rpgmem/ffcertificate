<?php
/**
 * The "Privacy Policy" words of a consent sentence.
 *
 * @package FreeFormCertificate\Core
 * @since   6.44.1
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Links the site's privacy policy, or names it as plain text when there is
 * none (#2537).
 *
 * `get_privacy_policy_url()` returns `''` until a privacy page is chosen in
 * Settings → Privacy, and every consent sentence printed it straight into
 * `href`: an empty link that reloads the page in a new tab, as if a policy
 * existed. One helper, so the certificate form and the personal-calendar
 * booking form cannot disagree about it.
 */
final class PrivacyPolicyLink {

	/**
	 * The words "Privacy Policy" as escaped HTML: a link opening the policy
	 * in a new tab, or the plain words without a privacy page.
	 *
	 * @return string Escaped HTML, safe to print or to pass through `wp_kses_post()`.
	 */
	public static function html(): string {
		$label = esc_html__( 'Privacy Policy', 'ffcertificate' );
		$url   = (string) get_privacy_policy_url();

		if ( '' === $url ) {
			return $label;
		}

		return '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . $label . '</a>';
	}
}
