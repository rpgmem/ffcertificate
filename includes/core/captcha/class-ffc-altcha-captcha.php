<?php
/**
 * AltchaCaptcha
 *
 * Proof-of-work captcha strategy backed by the ALTCHA widget, served entirely
 * from this site: the challenge is issued by our own AJAX endpoint, the work
 * happens in the visitor's browser, and verification is plain PHP. No request
 * ever leaves the server.
 *
 * The wire format is the 3.x widget's own ("v2") challenge: a `parameters`
 * object and a `signature`. The solver derives `SHA-256(salt || nonce ||
 * uint32_be(counter))` for counter = 0, 1, 2, … until the key starts with
 * `keyPrefix`, and posts back `{challenge: {parameters, signature}, solution:
 * {counter, derivedKey, time}}`, base64-encoded.
 *
 * It replaced ALTCHA's original ("v1") shape in #1928. The widget only ever
 * read v1 through a conversion shim, and ALTCHA 3.4.0 broke that shim
 * (`isChallengeV1()` compares the challenge with the string "object"), so a
 * v1 challenge fails "Challenge validation failed" on every visitor. v2 is
 * the path the widget is built around; a v1 payload is refused, not
 * translated.
 *
 * Difficulty is the size of the secret counter, and nothing else. The
 * challenge is deterministic: the server draws the counter, derives the key
 * and publishes its first half as `keyPrefix`, so the solver must reproduce
 * that one counter. Expected work is about half of {@see COMPLEXITY} hashes,
 * and the cap matters: without one, a mistyped setting would leave visitors
 * grinding until the widget's own 90-second timeout.
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
	 * Key-derivation rounds. One: the work is the counter search, not the KDF.
	 *
	 * @var int
	 */
	private const COST = 1;

	/**
	 * Derived key length in bytes: the whole SHA-256 digest.
	 *
	 * @var int
	 */
	private const KEY_LENGTH = 32;

	/**
	 * Bytes of the derived key published as `keyPrefix`: half of it, which is
	 * the widget library's own default for a deterministic challenge. Any
	 * counter other than the drawn one matches it with probability 2^-128.
	 *
	 * @var int
	 */
	private const PREFIX_LENGTH = 16;

	/**
	 * Largest counter the widget encodes in its `uint32` mode.
	 *
	 * @var int
	 */
	private const MAX_COUNTER = 0xFFFFFFFF;

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
	 * to this site is the signature; what bounds its life is `expiresAt`.
	 *
	 * EVERY PARAMETER IS SIGNED, THE EXPIRY INCLUDED
	 *
	 * The v1 format carried the expiry inside the salt, and signing only the
	 * hash let an attacker move the boundary between salt and number without
	 * changing the hashed string -- CVE-2025-68113, which #1357 fixed by
	 * signing the parsed expiry beside the hash. In v2 the expiry is a field
	 * of its own and the signature covers the canonical JSON of every field,
	 * so there is no boundary left to move.
	 *
	 * @return array{parameters: array<string, int|string>, signature: string} Challenge in the widget's v2 wire format.
	 */
	public static function create_challenge(): array {
		$nonce   = bin2hex( random_bytes( 16 ) );
		$salt    = bin2hex( random_bytes( 16 ) );
		$counter = random_int( 0, CaptchaSettings::complexity() );

		$parameters = array(
			'algorithm' => self::ALGORITHM,
			'cost'      => self::COST,
			'expiresAt' => time() + CaptchaSettings::ttl(),
			'keyLength' => self::KEY_LENGTH,
			'keyPrefix' => substr( self::derive_key( $salt, $nonce, $counter ), 0, self::PREFIX_LENGTH * 2 ),
			'nonce'     => $nonce,
			'salt'      => $salt,
		);

		return array(
			'parameters' => $parameters,
			'signature'  => ChallengeSigner::sign( self::signed_payload( $parameters ) ),
		);
	}

	/**
	 * The key the widget derives for a counter: `SHA-256(salt || nonce ||
	 * uint32_be(counter))`, as lowercase hex.
	 *
	 * One round, the whole digest -- `cost` 1 and `keyLength` 32, which is
	 * what this class issues and what {@see self::parameters_of()} accepts.
	 *
	 * @param string $salt    Salt, 32 hex characters.
	 * @param string $nonce   Nonce, 32 hex characters.
	 * @param int    $counter Counter, 0 to {@see self::MAX_COUNTER}.
	 * @return string 64 hex characters.
	 */
	private static function derive_key( string $salt, string $nonce, int $counter ): string {
		return hash( 'sha256', (string) hex2bin( $salt ) . (string) hex2bin( $nonce ) . pack( 'N', $counter ) );
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
		 * Only ten attributes exist on the 3.x element — auto, challenge,
		 * configuration, display, form, language, name, theme, type, workers — so
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
	 * is believed, so a forged challenge is rejected without the server
	 * hashing on its behalf; the expiry is read only after it is known to be
	 * ours; the solution is checked last, against parameters that are.
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

		$parameters = self::parameters_of( $payload['parameters'] );
		if ( null === $parameters ) {
			return $generic;
		}

		if ( ! ChallengeSigner::matches( self::signed_payload( $parameters ), $payload['signature'] ) ) {
			return $generic;
		}

		if ( $parameters['expiresAt'] <= time() ) {
			return \__( 'Error: The verification expired. Please try again.', 'ffcertificate' );
		}

		$key = self::derive_key( $parameters['salt'], $parameters['nonce'], $payload['counter'] );
		if ( ! hash_equals( $key, $payload['derivedKey'] ) || 0 !== strpos( $key, $parameters['keyPrefix'] ) ) {
			return $generic;
		}

		return array(
			'signature' => $payload['signature'],
			'ttl'       => $parameters['expiresAt'] - time(),
		);
	}

	/**
	 * Decode and shape-check the base64 payload the widget posts.
	 *
	 * Strict base64 and a type check on every field, because every byte here
	 * is attacker-controlled: a `salt` that is an array would otherwise reach
	 * `hex2bin()` and fatal under `strict_types`. A v1 payload has none of
	 * these keys and is refused here.
	 *
	 * @param mixed $raw Raw request value.
	 * @return array{parameters: array<mixed>, signature: string, counter: int, derivedKey: string}|null
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

		$challenge = $decoded['challenge'] ?? null;
		$solution  = $decoded['solution'] ?? null;
		if ( ! is_array( $challenge ) || ! is_array( $solution ) ) {
			return null;
		}

		if ( ! isset( $challenge['parameters'] ) || ! is_array( $challenge['parameters'] ) ) {
			return null;
		}
		if ( ! isset( $challenge['signature'] ) || ! is_string( $challenge['signature'] ) ) {
			return null;
		}

		// An integer, not a numeric string: the widget sends a JSON number,
		// and `pack( 'N' )` must see exactly the value that was hashed.
		$counter = $solution['counter'] ?? null;
		if ( ! is_int( $counter ) || $counter < 0 || $counter > self::MAX_COUNTER ) {
			return null;
		}

		$derived_key = $solution['derivedKey'] ?? null;
		if ( ! is_string( $derived_key ) || 1 !== preg_match( '/^[0-9a-f]{64}$/', $derived_key ) ) {
			return null;
		}

		return array(
			'parameters' => $challenge['parameters'],
			'signature'  => $challenge['signature'],
			'counter'    => $counter,
			'derivedKey' => $derived_key,
		);
	}

	/**
	 * The challenge parameters this class issues, read back from a payload.
	 *
	 * Only the fields {@see self::create_challenge()} writes are read, each
	 * against the one value or shape it is issued with; anything else the
	 * client adds is dropped rather than signed, so it cannot change what the
	 * signature is checked against.
	 *
	 * @param array<mixed> $raw Posted `parameters`.
	 * @return array{algorithm: string, cost: int, expiresAt: int, keyLength: int, keyPrefix: string, nonce: string, salt: string}|null
	 */
	private static function parameters_of( array $raw ): ?array {
		if ( self::ALGORITHM !== ( $raw['algorithm'] ?? null )
			|| self::COST !== ( $raw['cost'] ?? null )
			|| self::KEY_LENGTH !== ( $raw['keyLength'] ?? null ) ) {
			return null;
		}

		$expires_at = $raw['expiresAt'] ?? null;
		if ( ! is_int( $expires_at ) || $expires_at <= 0 ) {
			return null;
		}

		foreach ( array( 'keyPrefix', 'nonce', 'salt' ) as $key ) {
			if ( ! isset( $raw[ $key ] ) || ! is_string( $raw[ $key ] ) || 1 !== preg_match( '/^[0-9a-f]{32}$/', $raw[ $key ] ) ) {
				return null;
			}
		}

		return array(
			'algorithm' => self::ALGORITHM,
			'cost'      => self::COST,
			'expiresAt' => $expires_at,
			'keyLength' => self::KEY_LENGTH,
			'keyPrefix' => $raw['keyPrefix'],
			'nonce'     => $raw['nonce'],
			'salt'      => $raw['salt'],
		);
	}

	/**
	 * What the signature binds: every challenge parameter.
	 *
	 * ONE METHOD BECAUSE BOTH SIDES MUST COMPOSE IT IDENTICALLY
	 *
	 * Issuing and verifying building this string separately is how the two
	 * drift, and a drift here does not fail loudly -- it rejects every
	 * legitimate visitor while still accepting nothing, which looks like a
	 * widget problem rather than a signing one.
	 *
	 * The fields are written in sorted key order as `key=value`, joined by
	 * `|`. Every key is fixed and every value is an integer or lowercase hex,
	 * so neither `=` nor `|` can occur inside a field and there is exactly
	 * one way to read the result.
	 *
	 * The leading `altcha` is domain separation from the math strategy's
	 * `math|…` payload (`SecurityService::payload()`), so no payload of one
	 * strategy can ever be read as the other's.
	 *
	 * @param array<string, int|string> $parameters Challenge parameters.
	 * @return string Canonical payload for {@see ChallengeSigner}.
	 */
	private static function signed_payload( array $parameters ): string {
		ksort( $parameters );

		$fields = array( self::ID );
		foreach ( $parameters as $key => $value ) {
			$fields[] = $key . '=' . (string) $value;
		}

		return implode( '|', $fields );
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
