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
	 * Renders served so far in this request.
	 *
	 * Ids have to be unique per page and the renderer has no form context, so
	 * the instance number comes from here. It is only ever used to build the
	 * `<label for>` pair — no script looks these ids up, they all match by
	 * `name` — so its value carries no meaning beyond being distinct.
	 *
	 * @var int
	 */
	private static int $instances = 0;

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

		++self::$instances;

		$ffc_captcha_label   = (string) $challenge['label'];
		$ffc_captcha_token   = (string) $challenge['hash'];
		$ffc_captcha_ans_id  = 'ffc_captcha_ans_' . self::$instances;
		$ffc_captcha_hash_id = 'ffc_captcha_hash_' . self::$instances;

		ob_start();
		include FFC_PLUGIN_DIR . 'templates/captcha/math-fields.php';
		$html = ob_get_clean();

		return false === $html ? '' : $html;
	}

	/**
	 * Reset the per-request instance counter.
	 *
	 * Test seam: the counter is static, so ids would keep climbing across
	 * test methods and assertions on a specific id would depend on run order.
	 *
	 * @return void
	 */
	public static function reset_instances(): void {
		self::$instances = 0;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $request Request data.
	 * @return true|string
	 */
	public function verify( array $request ) {
		return $this->check( $request, true );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $request Request data.
	 * @return true|string
	 */
	public function peek( array $request ) {
		return $this->check( $request, false );
	}

	/**
	 * Shared body of {@see verify()} and {@see peek()}.
	 *
	 * @param array<string, mixed> $request Request data.
	 * @param bool                 $consume Whether to spend the challenge.
	 * @return true|string
	 */
	private function check( array $request, bool $consume ) {
		if ( ! isset( $request['ffc_captcha_ans'] ) || ! isset( $request['ffc_captcha_hash'] ) ) {
			return \__( 'Error: Please answer the security question.', 'ffcertificate' );
		}

		$answer = (string) $request['ffc_captcha_ans'];
		$token  = (string) $request['ffc_captcha_hash'];

		$ok = $consume
			? SecurityService::verify_simple_captcha( $answer, $token )
			: SecurityService::peek_simple_captcha( $answer, $token );

		if ( ! $ok ) {
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
