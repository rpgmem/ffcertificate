<?php
/**
 * MathCaptcha
 *
 * The arithmetic challenge the plugin has always shipped, behind the provider
 * contract. Generation and verification still live in
 * {@see \FreeFormCertificate\Core\SecurityService} — this is the strategy
 * wrapper, not a reimplementation.
 *
 * It stays available whatever else is configured: it is the only challenge
 * that works without JavaScript, and the rollback that needs no redeploy.
 *
 * @package FreeFormCertificate\Core\Captcha
 * @since 6.23.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core\Captcha;

use FreeFormCertificate\Core\SecurityService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Arithmetic captcha strategy.
 */
class MathCaptcha implements CaptchaProviderInterface {

	/**
	 * Provider id.
	 *
	 * @var string
	 */
	public const ID = 'math';

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string Escaped HTML.
	 */
	public function render_fields(): string {
		$challenge = SecurityService::generate_simple_captcha();

		$ffc_captcha_label = (string) $challenge['label'];
		$ffc_captcha_token = (string) $challenge['hash'];

		ob_start();
		include FFC_PLUGIN_DIR . 'templates/captcha/math-fields.php';
		$html = ob_get_clean();

		return false === $html ? '' : $html;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $request Request data.
	 * @return true|string
	 */
	public function verify( array $request ) {
		if ( ! isset( $request['ffc_captcha_ans'] ) || ! isset( $request['ffc_captcha_hash'] ) ) {
			return \__( 'Error: Please answer the security question.', 'ffcertificate' );
		}

		if ( ! SecurityService::verify_simple_captcha( (string) $request['ffc_captcha_ans'], (string) $request['ffc_captcha_hash'] ) ) {
			return \__( 'Error: The math answer is incorrect.', 'ffcertificate' );
		}

		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * The `new_label` / `new_hash` keys are the shape the frontend has read
	 * since long before this contract existed; `provider` is additive, so a
	 * client that only knows the old keys keeps working.
	 *
	 * @return array<string, mixed>
	 */
	public function challenge_payload(): array {
		$challenge = SecurityService::generate_simple_captcha();

		return array(
			'provider'  => self::ID,
			'new_label' => $challenge['label'],
			'new_hash'  => $challenge['hash'],
		);
	}
}
