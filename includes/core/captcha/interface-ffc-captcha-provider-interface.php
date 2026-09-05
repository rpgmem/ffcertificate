<?php
/**
 * CaptchaProviderInterface
 *
 * The contract every captcha strategy implements. It exists because two
 * strategies genuinely have to coexist: a proof-of-work challenge needs
 * JavaScript and a secure context, and the math challenge does not — so the
 * math one stays as the no-JS path and as the rollback that needs no redeploy.
 *
 * Scope is the captcha only. The honeypot is provider-independent and stays
 * in {@see \FreeFormCertificate\Core\SecurityService}, which composes the two.
 *
 * @package FreeFormCertificate\Core\Captcha
 * @since 6.23.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core\Captcha;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A captcha strategy.
 */
interface CaptchaProviderInterface {

	/**
	 * Stable identifier, matching the `captcha_provider` setting value.
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * Render this provider's form fields.
	 *
	 * The honeypot and its container are rendered by the caller, so this
	 * returns only the challenge itself.
	 *
	 * @return string Escaped HTML.
	 */
	public function render_fields(): string;

	/**
	 * Verify the challenge in a request payload.
	 *
	 * @param array<string, mixed> $request Request data (typically `$_POST`).
	 * @return true|string True when valid, else a translated error message.
	 */
	public function verify( array $request );

	/**
	 * A freshly issued challenge, shaped for a JSON response.
	 *
	 * Consumed by the retry path (a redeemed challenge is spent, so any
	 * rejection has to hand the client a new one) and by the cached-page
	 * fragment refresh.
	 *
	 * @return array<string, mixed>
	 */
	public function challenge_payload(): array;
}
