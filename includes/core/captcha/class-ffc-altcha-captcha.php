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
	 * Fallback work factor, used only where no settings read is possible.
	 *
	 * The live value comes from {@see CaptchaSettings::complexity()}, which
	 * bounds whatever the option holds — a work factor is the one setting
	 * here that can make a public form unusable when it is wrong.
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
		$complexity = CaptchaSettings::complexity();

		$expires = time() + CaptchaSettings::ttl();
		$secret  = random_int( 0, $complexity );
		$salt    = bin2hex( random_bytes( 12 ) ) . '?expires=' . $expires;
		$hash    = hash( 'sha256', $salt . $secret );

		return array(
			'algorithm' => self::ALGORITHM,
			'challenge' => $hash,
			// Ignored by the 3.x widget, which counts up without a ceiling.
			// Kept because it is part of the published format and other
			// ALTCHA clients still read it.
			'maxnumber' => $complexity,
			'salt'      => $salt,
			'signature' => ChallengeSigner::sign( $hash ),
		);
	}

	/**
	 * Register the widget bundle and its glue, without enqueueing either.
	 *
	 * Registration is cheap and unconditional; the enqueue happens in
	 * {@see render_fields()}, so the scripts load exactly where the widget
	 * renders. Tying them to a list of page types instead is what put an
	 * empty widget on the public CSV download: that page is neither a
	 * certificate form nor a verification page, and the self-scheduling
	 * booking form is enqueued by a different class entirely. A list of
	 * surfaces drifts; following the render cannot.
	 *
	 * @return void
	 */
	public static function register_assets(): void {
		$suffix = \FreeFormCertificate\Core\AssetHelper::asset_suffix();

		\wp_register_script(
			'ffc-altcha',
			FFC_PLUGIN_URL . 'libs/js/altcha-' . FFC_ALTCHA_VERSION . '.umd.js',
			array(),
			FFC_ALTCHA_VERSION,
			true
		);

		// Depends on the bundle so the i18n store exists before the glue
		// writes to it, and on `ffc-core` because it listens for the
		// rejection event that module dispatches.
		\wp_register_script(
			'ffc-captcha',
			FFC_PLUGIN_URL . "assets/js/ffc-captcha{$suffix}.js",
			array( 'ffc-altcha', 'ffc-core' ),
			FFC_VERSION,
			true
		);

		\wp_localize_script( 'ffc-captcha', 'ffcCaptcha', self::localization() );
	}

	/**
	 * Data the glue script needs.
	 *
	 * The strings go through the plugin's own catalogue rather than the
	 * widget's 52 KB i18n bundle: the widget reads whatever is put in its
	 * store, so shipping a second catalogue for a handful of labels would be
	 * pure weight. Keys are the widget's, spelled its way.
	 *
	 * @return array<string, mixed>
	 */
	private static function localization(): array {
		return array(
			// Must match the `language` attribute rendered below, or the
			// widget falls back to English without saying so.
			'language'        => self::language(),
			'strings'         => array(
				'ariaLinkLabel' => \__( 'Altcha (official website)', 'ffcertificate' ),
				'error'         => \__( 'Verification failed. Try again later.', 'ffcertificate' ),
				'expired'       => \__( 'Verification expired. Try again.', 'ffcertificate' ),
				'footer'        => \__( 'Protected by ALTCHA', 'ffcertificate' ),
				'label'         => \__( 'I am not a robot', 'ffcertificate' ),
				'verified'      => \__( 'Verified', 'ffcertificate' ),
				'verifying'     => \__( 'Verifying…', 'ffcertificate' ),
				'waitAlert'     => \__( 'Verifying… please wait.', 'ffcertificate' ),
			),
			// Shown beside a widget that reported an error. The insecure-context
			// case is named separately because it is not transient: the widget
			// refuses to run outside a secure context, so "try again" would be
			// advice that can never work.
			'errorMessage'    => \__( 'Could not verify that you are human. Reload the page and try again.', 'ffcertificate' ),
			'insecureMessage' => \__( 'Verification is unavailable because this page is not served over a secure connection (HTTPS). Please contact the site administrator.', 'ffcertificate' ),
		);
	}

	/**
	 * Language key shared by the element attribute and the i18n store.
	 *
	 * Lowercased with a hyphen because that is the shape the widget's own
	 * lookup normalises to.
	 *
	 * @return string
	 */
	private static function language(): string {
		return strtolower( str_replace( '_', '-', (string) \get_locale() ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * On its own the widget is the only way through, so a visitor without
	 * JavaScript is told so.
	 *
	 * @return string Escaped HTML.
	 */
	public function render_fields(): string {
		return $this->render_widget( true );
	}

	/**
	 * Render the widget, with or without the no-JavaScript notice.
	 *
	 * The notice is a property of the *mode*, not of the widget: it is true
	 * in ALTCHA-only mode and false in the composite one, where a math
	 * challenge sits right below it. Rendered there anyway it contradicts the
	 * field the visitor is about to answer — telling them in red that the
	 * form cannot be submitted, immediately above the thing that submits it.
	 *
	 * @param bool $ffc_altcha_noscript_notice Whether to emit the notice.
	 * @return string Escaped HTML.
	 */
	public function render_widget( bool $ffc_altcha_noscript_notice ): string {
		// Enqueued here rather than from a page-type branch, so the scripts
		// follow the widget onto every surface that renders it. Shortcodes
		// render during `the_content`, well before `wp_footer`, and both
		// scripts are footer scripts — so this lands in time.
		\wp_enqueue_script( 'ffc-captcha' );

		$ffc_altcha_challenge_url = \add_query_arg(
			array( 'action' => AltchaChallengeEndpoint::AJAX_ACTION ),
			\admin_url( 'admin-ajax.php' )
		);

		/*
		 * Only nine attributes exist on the 3.x element — auto, challenge,
		 * configuration, display, language, name, theme, type, workers — so
		 * everything an administrator can set is split accordingly:
		 * `CaptchaSettings::widget_attributes()` returns the two that ARE
		 * attributes here, and `widget_configuration()` the rest, as JSON.
		 * Written
		 * the other way round they are ignored in silence.
		 */
		$ffc_altcha_attributes    = CaptchaSettings::widget_attributes();
		$ffc_altcha_configuration = (string) \wp_json_encode( CaptchaSettings::widget_configuration() );

		// Selects the entry `assets/js/ffc-captcha.js` registers in the
		// widget's i18n store — the same value the localisation carries.
		$ffc_altcha_language = self::language();

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
