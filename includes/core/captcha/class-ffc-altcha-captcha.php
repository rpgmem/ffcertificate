<?php
/**
 * AltchaCaptcha
 *
 * Proof-of-work captcha strategy backed by the ALTCHA widget, served entirely
 * from this site: the challenge is issued by our own AJAX endpoint, the work
 * happens in the visitor's browser, and verification is plain PHP. No request
 * ever leaves the server.
 *
 * The wire format is ALTCHA's original ("v1") challenge — `algorithm`,
 * `challenge`, `salt`, `signature` — which the 3.x widget still accepts and
 * answers in kind. That is deliberate and verified against the vendored
 * bundle: on a challenge carrying a top-level `challenge` key the widget
 * marks it `_version: 1` and posts back `{algorithm, challenge, number, salt,
 * signature, took}`, base64-encoded. Emitting the newer nested shape would
 * buy nothing here and cost a verifier that no longer matches the format
 * every other ALTCHA server speaks.
 *
 * Difficulty is the size of the secret number, and nothing else. The 3.x
 * widget dropped `maxnumber` — the word does not occur in the bundle — so the
 * solver simply counts up until it reproduces the hash. Expected work is
 * therefore about half of {@see COMPLEXITY} hashes, and the cap matters:
 * without one, a mistyped setting would leave visitors grinding until the
 * widget's own 90-second timeout.
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
 * ALTCHA proof-of-work captcha strategy.
 */
class AltchaCaptcha implements CaptchaProviderInterface {

	/**
	 * Provider id, matching the `captcha_provider` setting value.
	 *
	 * @var string
	 */
	public const ID = 'altcha';

	/**
	 * Request field the widget posts its solution in.
	 *
	 * Also the widget's `name` attribute — they are the same knob.
	 *
	 * @var string
	 */
	public const FIELD = 'altcha';

	/**
	 * Hash the challenge is built on.
	 *
	 * SHA-256 is the widget's default and the only algorithm this class
	 * issues. `verify()` still checks the value it receives rather than
	 * assuming it: the field is attacker-controlled.
	 *
	 * @var string
	 */
	public const ALGORITHM = 'SHA-256';

	/**
	 * Seconds an issued challenge stays valid.
	 *
	 * Matches the math challenge so both strategies age the same way.
	 *
	 * @var int
	 */
	public const CHALLENGE_TTL = 600;

	/**
	 * Upper bound of the secret number, i.e. the work factor.
	 *
	 * Expected work is half this many hashes. 200k lands well under a second
	 * on a phone across the widget's worker pool, while still costing an
	 * attacker real CPU per attempt. PR4 makes this configurable behind a
	 * guardrail; until then it is the only value in play.
	 *
	 * @var int
	 */
	public const COMPLEXITY = 200000;

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * Issue a challenge.
	 *
	 * Stateless by construction: nothing is written here, so a cached page
	 * costs nothing and there is no ledger to grow. What ties the challenge
	 * to this site is the signature; what bounds its life is the `expires`
	 * parameter carried inside the salt.
	 *
	 * The salt is not signed directly, and does not need to be: `challenge`
	 * is the hash of salt plus secret number, so editing the salt — to push
	 * the expiry out, say — breaks the solution check unless the attacker can
	 * find a preimage.
	 *
	 * @return array<string, mixed> Challenge in ALTCHA's v1 wire format.
	 */
	public static function create_challenge(): array {
		$expires = time() + self::CHALLENGE_TTL;
		$secret  = random_int( 0, self::COMPLEXITY );
		$salt    = bin2hex( random_bytes( 12 ) ) . '?expires=' . $expires;
		$hash    = hash( 'sha256', $salt . $secret );

		return array(
			'algorithm' => self::ALGORITHM,
			'challenge' => $hash,
			// Ignored by the 3.x widget, which counts up without a ceiling.
			// Kept because it is part of the published format and other
			// ALTCHA clients still read it.
			'maxnumber' => self::COMPLEXITY,
			'salt'      => $salt,
			'signature' => ChallengeSigner::sign( $hash ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string Escaped HTML.
	 */
	public function render_fields(): string {
		$ffc_altcha_challenge_url = \add_query_arg(
			array( 'action' => AltchaChallengeEndpoint::AJAX_ACTION ),
			\admin_url( 'admin-ajax.php' )
		);

		/*
		 * Only nine attributes exist on the 3.x element — auto, challenge,
		 * configuration, display, language, name, theme, type, workers — so
		 * everything else travels as JSON in `configuration`. Two settings
		 * there are deliberate rather than cosmetic:
		 *
		 * `humanInteractionSignature` defaults to TRUE and collects pointer
		 * and keyboard timings. This plugin serves public-sector forms under
		 * the LGPD, and the proof-of-work already carries the anti-automation
		 * load, so the extra behavioural signal is not worth its privacy cost.
		 *
		 * `setCookie` stays null: a captcha that sets a cookie changes what
		 * the site has to disclose, for a convenience nobody asked for.
		 */
		$ffc_altcha_configuration = (string) \wp_json_encode(
			array(
				'humanInteractionSignature' => false,
				'setCookie'                 => null,
			)
		);

		// Selects the entry `assets/js/ffc-captcha.js` registers in the
		// widget's i18n store. Lowercased with a hyphen because that is the
		// shape the widget's own lookup normalises to.
		$ffc_altcha_language = strtolower( str_replace( '_', '-', \get_locale() ) );

		ob_start();
		include FFC_PLUGIN_DIR . 'templates/captcha/altcha-fields.php';
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
		$proof = $this->authenticate( $request );

		if ( is_string( $proof ) ) {
			return $proof;
		}

		if ( ! ChallengeStore::redeem( $proof['signature'], $proof['ttl'] ) ) {
			return \__( 'Error: This verification was already used. Please try again.', 'ffcertificate' );
		}

		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $request Request data.
	 * @return true|string
	 */
	public function peek( array $request ) {
		$proof = $this->authenticate( $request );

		if ( is_string( $proof ) ) {
			return $proof;
		}

		// A spent proof is refused here too. Reporting it as valid and only
		// failing at the step that consumes it is the contradiction #1061
		// existed to remove.
		if ( ChallengeStore::is_spent( $proof['signature'] ) ) {
			return \__( 'Error: This verification was already used. Please try again.', 'ffcertificate' );
		}

		return true;
	}

	/**
	 * Authenticate a posted solution without touching the ledger.
	 *
	 * Order matters. The signature is checked before anything in the payload
	 * is believed, so an unsigned challenge is rejected without the server
	 * hashing on its behalf; the solution check then binds the salt and the
	 * number to that authenticated challenge; only then is the expiry read,
	 * because until the solution matches, the salt carrying it is not known
	 * to be ours.
	 *
	 * @param array<string, mixed> $request Request data.
	 * @return array{signature: string, ttl: int}|string Proof, or an error message.
	 */
	private function authenticate( array $request ) {
		$generic = \__( 'Error: Verification failed. Please try again.', 'ffcertificate' );

		$payload = $this->decode( $request[ self::FIELD ] ?? null );
		if ( null === $payload ) {
			return \__( 'Error: Please complete the verification.', 'ffcertificate' );
		}

		if ( ! hash_equals( self::ALGORITHM, $payload['algorithm'] ) ) {
			return $generic;
		}

		if ( ! ChallengeSigner::matches( $payload['challenge'], $payload['signature'] ) ) {
			return $generic;
		}

		if ( ! hash_equals( $payload['challenge'], hash( 'sha256', $payload['salt'] . $payload['number'] ) ) ) {
			return $generic;
		}

		$expires = $this->expiry_of( $payload['salt'] );
		if ( null === $expires || $expires <= time() ) {
			return \__( 'Error: The verification expired. Please try again.', 'ffcertificate' );
		}

		return array(
			'signature' => $payload['signature'],
			'ttl'       => $expires - time(),
		);
	}

	/**
	 * Decode and shape-check the base64 payload the widget posts.
	 *
	 * Strict base64 and a whole-array type check, because every byte here is
	 * attacker-controlled: a payload whose `salt` is an array would otherwise
	 * reach `hash()` and fatal under `strict_types`.
	 *
	 * @param mixed $raw Raw request value.
	 * @return array{algorithm: string, challenge: string, number: string, salt: string, signature: string}|null
	 */
	private function decode( $raw ): ?array {
		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding the widget's own base64 solution payload, which ALTCHA's wire format defines; strict mode on, and the result is shape-checked below before any of it is used.
		$json = base64_decode( $raw, true );
		if ( false === $json ) {
			return null;
		}

		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			return null;
		}

		foreach ( array( 'algorithm', 'challenge', 'salt', 'signature' ) as $key ) {
			if ( ! isset( $decoded[ $key ] ) || ! is_string( $decoded[ $key ] ) ) {
				return null;
			}
		}

		// The counter is hashed as a decimal string — the widget's own
		// `counterMode: 'string'` for this format — so it is normalised here
		// rather than cast at the comparison, where an int would silently
		// stringify differently for a float or a numeric string with padding.
		if ( ! isset( $decoded['number'] ) || ! is_scalar( $decoded['number'] ) ) {
			return null;
		}
		$number = (string) $decoded['number'];
		if ( 1 !== preg_match( '/^\d+$/', $number ) ) {
			return null;
		}

		return array(
			'algorithm' => $decoded['algorithm'],
			'challenge' => $decoded['challenge'],
			'number'    => $number,
			'salt'      => $decoded['salt'],
			'signature' => $decoded['signature'],
		);
	}

	/**
	 * Read the `expires` parameter carried in the salt.
	 *
	 * @param string $salt Salt as issued, `<hex>?expires=<unix>`.
	 * @return int|null Unix UTC timestamp, or null when absent or malformed.
	 */
	private function expiry_of( string $salt ): ?int {
		$parts = explode( '?', $salt, 2 );
		if ( 2 !== count( $parts ) ) {
			return null;
		}

		parse_str( $parts[1], $params );
		$expires = $params['expires'] ?? null;

		if ( ! is_string( $expires ) || 1 !== preg_match( '/^\d+$/', $expires ) ) {
			return null;
		}

		return (int) $expires;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Nothing to hand back. The widget fetches its own challenge from the
	 * endpoint whenever it needs one, which is exactly why that endpoint
	 * exists — a challenge embedded in the HTML would go stale in a page
	 * cache, which is the problem the fragment refresh solves for the math
	 * strategy. `provider` is still returned so the client can recognise the
	 * payload and skip it rather than guess.
	 *
	 * @return array<string, mixed>
	 */
	public function challenge_payload(): array {
		return array( 'provider' => self::ID );
	}
}
