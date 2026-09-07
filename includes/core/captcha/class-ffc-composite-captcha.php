<?php
/**
 * CompositeCaptcha
 *
 * Renders the ALTCHA widget and keeps the math challenge as the no-JavaScript
 * path, accepting whichever proof arrives.
 *
 * **This mode is accessibility, not security.** Because the server accepts
 * either proof, an attacker picks the cheaper one, so the effective strength
 * is the math challenge's — the same as running math alone. It exists so a
 * visitor without JavaScript, or on a plain-HTTP site where the widget
 * refuses to run at all, can still submit; and as the rollback that needs no
 * redeploy. Choosing it over ALTCHA-only is a deliberate trade of strength
 * for reach, and the settings screen says so.
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
 * ALTCHA with the math challenge as fallback.
 */
class CompositeCaptcha implements CaptchaProviderInterface {

	/**
	 * Provider id, matching the `captcha_provider` setting value.
	 *
	 * @var string
	 */
	public const ID = 'both';

	/**
	 * Proof-of-work half.
	 *
	 * @var AltchaCaptcha
	 */
	private AltchaCaptcha $altcha;

	/**
	 * Arithmetic half.
	 *
	 * @var MathCaptcha
	 */
	private MathCaptcha $math;

	/**
	 * Compose the two strategies.
	 */
	public function __construct() {
		$this->altcha = new AltchaCaptcha();
		$this->math   = new MathCaptcha();
	}

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
	 * The math half sits inside `<noscript>`, so a visitor who has the widget
	 * is never asked to do arithmetic as well — and one who does not still
	 * gets a field they can answer. A browser with JavaScript never submits
	 * the math inputs at all, because the markup inside `<noscript>` is not
	 * parsed into the DOM.
	 *
	 * The same `<noscript>` also hides the widget's own panel and suppresses
	 * its no-JavaScript notice, because without JavaScript the panel is an
	 * empty box and the notice is false here: it says the form cannot be
	 * submitted, directly above the field that submits it. A stylesheet
	 * cannot express "scripting is disabled", so the rule rides in the one
	 * element that can.
	 *
	 * @return string Escaped HTML.
	 */
	public function render_fields(): string {
		return $this->altcha->render_widget( false )
			. '<noscript>'
			. '<style>.ffc-altcha-row{display:none;}</style>'
			. $this->math->render_fields()
			. '</noscript>';
	}

	/**
	 * {@inheritDoc}
	 *
	 * Routes on which proof was actually posted rather than trying both and
	 * merging the outcomes: with two error messages in hand, reporting the
	 * wrong one is how a visitor gets told to redo arithmetic they never saw.
	 *
	 * @param array<string, mixed> $request Request data.
	 * @return true|string
	 */
	public function verify( array $request ) {
		$half = $this->half_for( $request );

		if ( $half instanceof MathCaptcha ) {
			self::log_fallback();
		}

		return $half->verify( $request );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $request Request data.
	 * @return true|string
	 */
	public function peek( array $request ) {
		return $this->half_for( $request )->peek( $request );
	}

	/**
	 * {@inheritDoc}
	 *
	 * The ALTCHA half's payload, because a page that runs JavaScript is a
	 * page whose visitor is using the widget — and the math half inside
	 * `<noscript>` is unreachable to the script that would apply a refresh.
	 *
	 * @return array<string, mixed>
	 */
	public function challenge_payload(): array {
		return $this->altcha->challenge_payload();
	}

	/**
	 * Pick the half that matches the proof in the request.
	 *
	 * Absent an ALTCHA solution the math half answers, which also produces
	 * the right message for an empty submission: "please answer the security
	 * question" is actionable for a no-JavaScript visitor, while a visitor
	 * whose widget failed to load gets that from the widget itself.
	 *
	 * @param array<string, mixed> $request Request data.
	 * @return CaptchaProviderInterface
	 */
	/**
	 * Record that a submission arrived without a proof of work.
	 *
	 * This mode's whole justification is reach, so an operator deciding
	 * whether to move to ALTCHA-only needs to know whether anyone is actually
	 * using the fallback — the alternative is switching and finding out from
	 * the people who can no longer submit.
	 *
	 * The address is hashed and truncated, never stored raw: an activity-log
	 * row is exportable, and a visitor IP is personal data under the LGPD.
	 * Sixteen hex characters are enough to tell two visitors apart within a
	 * retention window without being an identifier on their own.
	 *
	 * @return void
	 */
	private static function log_fallback(): void {
		if ( ! class_exists( '\\FreeFormCertificate\\Core\\ActivityLog' ) ) {
			return;
		}

		$ip = \FreeFormCertificate\Core\RequestInput::get_user_ip();

		\FreeFormCertificate\Core\ActivityLog::log(
			'captcha_fallback_used',
			\FreeFormCertificate\Core\ActivityLog::LEVEL_INFO,
			array(
				'provider' => MathCaptcha::ID,
				'ip_hash'  => '' === $ip ? '' : substr( hash( 'sha256', $ip ), 0, 16 ),
			)
		);
	}

	/**
	 * Pick the half that matches the proof in the request.
	 *
	 * Absent an ALTCHA solution the math half answers, which also produces
	 * the right message for an empty submission: "please answer the security
	 * question" is actionable for a no-JavaScript visitor, while a visitor
	 * whose widget failed to load gets that from the widget itself.
	 *
	 * @param array<string, mixed> $request Request data.
	 * @return CaptchaProviderInterface
	 */
	private function half_for( array $request ): CaptchaProviderInterface {
		$solution = $request[ AltchaCaptcha::FIELD ] ?? '';

		return ( is_string( $solution ) && '' !== $solution ) ? $this->altcha : $this->math;
	}
}
