<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Core\SensitiveFieldRegistry;
use FreeFormCertificate\Tests\Support\Identifiers;

/**
 * Tests for Encryption class: round-trip, hashing, batch ops, configuration.
 */
class EncryptionTest extends TestCase {

	/**
	 * A 64-character key in which no character repeats.
	 */
	private const DISTINCT_KEY = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ+/';

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// 6.2.0: Encryption error paths now log via `Debug::log_encryption()`,
		// which reads `ffc_settings` to gate per-area. Stub get_option so the
		// gate is reached without exploding.
		Functions\when( 'get_option' )->justReturn( array() );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// encrypt() + decrypt() round-trip
	// ------------------------------------------------------------------

	public function test_encrypt_decrypt_round_trip(): void {
		$original = 'user@example.com';
		$encrypted = Encryption::encrypt( $original );

		$this->assertNotNull( $encrypted, 'Encryption should not return null' );
		$this->assertNotSame( $original, $encrypted, 'Encrypted value should differ from original' );

		$decrypted = Encryption::decrypt( $encrypted );
		$this->assertSame( $original, $decrypted, 'Decrypted value should match original' );
	}

	public function test_encrypt_returns_null_for_empty_string(): void {
		$this->assertNull( Encryption::encrypt( '' ) );
	}

	public function test_decrypt_returns_null_for_empty_string(): void {
		$this->assertNull( Encryption::decrypt( '' ) );
	}

	public function test_decrypt_returns_null_for_invalid_base64(): void {
		$this->assertNull( Encryption::decrypt( '!!!invalid-base64!!!' ) );
	}

	public function test_each_encryption_produces_unique_ciphertext(): void {
		$value = 'same-value';
		$a = Encryption::encrypt( $value );
		$b = Encryption::encrypt( $value );

		$this->assertNotSame( $a, $b, 'Two encryptions of the same value should differ due to unique IV' );

		// Both should decrypt to the same value
		$this->assertSame( $value, Encryption::decrypt( $a ) );
		$this->assertSame( $value, Encryption::decrypt( $b ) );
	}

	public function test_encrypt_handles_unicode(): void {
		$cpf      = Identifiers::CPF_A;
		$original = 'João da Silva — cpf: ' . substr( $cpf, 0, 3 ) . '.' . substr( $cpf, 3, 3 ) . '.' . substr( $cpf, 6, 3 ) . '-' . substr( $cpf, 9, 2 ) . ' — ação';
		$encrypted = Encryption::encrypt( $original );
		$this->assertNotNull( $encrypted );
		$this->assertSame( $original, Encryption::decrypt( $encrypted ) );
	}

	public function test_encrypt_handles_long_string(): void {
		$original = str_repeat( 'A', 10000 );
		$encrypted = Encryption::encrypt( $original );
		$this->assertNotNull( $encrypted );
		$this->assertSame( $original, Encryption::decrypt( $encrypted ) );
	}

	// ------------------------------------------------------------------
	// hash()
	// ------------------------------------------------------------------

	public function test_hash_returns_64_char_hex(): void {
		$hash = Encryption::hash( 'test@example.com' );

		$this->assertNotNull( $hash );
		$this->assertSame( 64, strlen( $hash ), 'SHA-256 hash should be 64 hex characters' );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $hash );
	}

	public function test_hash_returns_null_for_empty_string(): void {
		$this->assertNull( Encryption::hash( '' ) );
	}

	public function test_hash_is_deterministic(): void {
		$a = Encryption::hash( 'test@example.com' );
		$b = Encryption::hash( 'test@example.com' );

		$this->assertSame( $a, $b, 'Same input should produce same hash' );
	}

	public function test_hash_differs_for_different_inputs(): void {
		$a = Encryption::hash( 'user1@example.com' );
		$b = Encryption::hash( 'user2@example.com' );

		$this->assertNotSame( $a, $b );
	}

	// ------------------------------------------------------------------
	// decrypt_field()
	// ------------------------------------------------------------------

	public function test_decrypt_field_prefers_encrypted(): void {
		$enc = Encryption::encrypt( 'secret@email.com' );
		$row = array(
			'email'           => 'old-plain@email.com',
			'email_encrypted' => $enc,
		);

		$this->assertSame( 'secret@email.com', Encryption::decrypt_field( $row, 'email' ) );
	}

	public function test_decrypt_field_falls_back_to_plain(): void {
		$row = array( 'email' => 'plain@email.com' );

		$this->assertSame( 'plain@email.com', Encryption::decrypt_field( $row, 'email' ) );
	}

	public function test_decrypt_field_returns_empty_when_missing(): void {
		$row = array();

		$this->assertSame( '', Encryption::decrypt_field( $row, 'email' ) );
	}

	public function test_decrypt_field_custom_encrypted_key(): void {
		$enc = Encryption::encrypt( 'custom' );
		$row = array( 'my_custom_enc' => $enc, 'field' => 'fallback' );

		$this->assertSame( 'custom', Encryption::decrypt_field( $row, 'field', 'my_custom_enc' ) );
	}

	// ------------------------------------------------------------------
	// decrypt_appointment()
	// ------------------------------------------------------------------

	public function test_decrypt_appointment_decrypts_all_fields(): void {
		$appt = array(
			'id'                    => 1,
			'email_encrypted'       => Encryption::encrypt( 'a@b.com' ),
			'cpf_encrypted'        => Encryption::encrypt( '51817842080' ),
			'phone'                => '',
			'phone_encrypted'      => Encryption::encrypt( '11999999999' ),
			'user_ip_encrypted'    => Encryption::encrypt( '10.0.0.1' ),
			'custom_data'          => '',
			'custom_data_encrypted' => Encryption::encrypt( '{"key":"val"}' ),
		);

		$result = Encryption::decrypt_appointment( $appt );

		$this->assertSame( 'a@b.com', $result['email'] );
		$this->assertSame( '51817842080', $result['cpf_rf'] );
		$this->assertSame( '11999999999', $result['phone'] );
		$this->assertSame( '10.0.0.1', $result['user_ip'] );
		$this->assertSame( '{"key":"val"}', $result['custom_data'] );
	}

	// ------------------------------------------------------------------
	// is_configured()
	// ------------------------------------------------------------------

	public function test_is_configured_returns_true_when_keys_exist(): void {
		$this->assertTrue( Encryption::is_configured() );
	}

	// ------------------------------------------------------------------
	// key_health() — #839 S7a
	// ------------------------------------------------------------------

	/**
	 * Invoke the private secret classifier directly — it is the pure logic
	 * behind key_health() and does not depend on the WP secret constants.
	 */
	private function secret_status( string $value ): string {
		$m = new \ReflectionMethod( Encryption::class, 'secret_status' );
		$m->setAccessible( true );
		return (string) $m->invoke( null, $value );
	}

	public function test_secret_status_classifies_each_state(): void {
		$this->assertSame( 'empty', $this->secret_status( '' ) );
		$this->assertSame( 'placeholder', $this->secret_status( 'put your unique phrase here' ) );
		// Placeholder detection is substring + case-insensitive.
		$this->assertSame( 'placeholder', $this->secret_status( 'xx PUT YOUR UNIQUE PHRASE HERE xx' ) );
		$this->assertSame( 'weak', $this->secret_status( 'short-key' ) );
		$this->assertSame( 'weak', $this->secret_status( str_repeat( 'a', 31 ) ) );
		$this->assertSame( 'ok', $this->secret_status( str_repeat( 'a', 32 ) ) );
		$this->assertSame( 'ok', $this->secret_status( str_repeat( 'x', 64 ) ) );
	}

	public function test_key_health_returns_a_valid_enum(): void {
		$this->assertContains(
			Encryption::key_health(),
			array( 'ok', 'weak', 'placeholder', 'empty' )
		);
	}

	public function test_key_health_report_shape(): void {
		$report = Encryption::key_health_report();

		// Status mirrors key_health().
		$this->assertSame( Encryption::key_health(), $report['status'] );
		// No decoupling constants defined in the unit env → derived from WP keys.
		$this->assertFalse( $report['encryption_decoupled'] );
		$this->assertFalse( $report['salt_decoupled'] );
		$this->assertStringContainsString( 'WordPress', $report['key_source'] );
		$this->assertStringContainsString( 'WordPress', $report['salt_source'] );
		// Fingerprint is a 12-char hex slice — never the raw key.
		$this->assertSame( 12, strlen( $report['fingerprint'] ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{12}$/', $report['fingerprint'] );
	}

	// ------------------------------------------------------------------
	// S7b key rotation — HMAC fallback + fingerprint (#857)
	// ------------------------------------------------------------------

	/**
	 * The parameterized encrypt/decrypt seam: a ciphertext authenticated under
	 * key pair A must decrypt under A and be rejected (null) under a different
	 * key pair B. This is the mechanism the rotation fallback relies on to tell
	 * "old key" from "new key" without decoding into garbage.
	 */
	public function test_try_decrypt_accepts_matching_key_and_rejects_others(): void {
		$encrypt = new \ReflectionMethod( Encryption::class, 'encrypt_with_keys' );
		$encrypt->setAccessible( true );
		$decrypt = new \ReflectionMethod( Encryption::class, 'try_decrypt' );
		$decrypt->setAccessible( true );

		$key_a = str_repeat( 'A', 32 );
		$mac_a = str_repeat( 'a', 32 );
		$key_b = str_repeat( 'B', 32 );
		$mac_b = str_repeat( 'b', 32 );

		$ciphertext = $encrypt->invoke( null, 'payload-123', $key_a, $mac_a );
		$this->assertNotNull( $ciphertext );

		$this->assertSame( 'payload-123', $decrypt->invoke( null, $ciphertext, $key_a, $mac_a ) );
		// Wrong key pair → HMAC mismatch → null (never a wrong plaintext).
		$this->assertNull( $decrypt->invoke( null, $ciphertext, $key_b, $mac_b ) );
		// Right enc key but wrong MAC key → still rejected.
		$this->assertNull( $decrypt->invoke( null, $ciphertext, $key_a, $mac_b ) );
	}

	/**
	 * End-to-end rotation window: data encrypted while the site derives its key
	 * from the WordPress salts must stay readable AFTER a strong
	 * FFC_ENCRYPTION_KEY is defined, via the decrypt() WP-derived fallback —
	 * while fresh writes use the new active key. Runs in a separate process so
	 * the FFC_ENCRYPTION_KEY define does not leak into other tests.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_decrypt_falls_back_to_wp_derived_key_after_decoupling(): void {
		// Encrypted under the WP-derived key (FFC_ENCRYPTION_KEY not yet defined).
		$legacy_ciphertext = Encryption::encrypt( 'secret-cpf-000' );
		$this->assertNotNull( $legacy_ciphertext );

		// Decouple mid-flight: define a strong custom key (simulates the operator
		// editing wp-config.php partway through the rotation).
		if ( ! defined( 'FFC_ENCRYPTION_KEY' ) ) {
			define( 'FFC_ENCRYPTION_KEY', str_repeat( 'N', 64 ) );
		}

		// The legacy row is now under the "old" key; the fallback must read it.
		$this->assertSame( 'secret-cpf-000', Encryption::decrypt( $legacy_ciphertext ) );

		// A fresh write now uses the active (custom) key and still round-trips.
		$fresh_ciphertext = Encryption::encrypt( 'fresh-cpf-999' );
		$this->assertNotNull( $fresh_ciphertext );
		$this->assertSame( 'fresh-cpf-999', Encryption::decrypt( $fresh_ciphertext ) );
		$this->assertNotSame( $legacy_ciphertext, $fresh_ciphertext );
	}

	/**
	 * FFC→FFC re-key window (#863): after re-keying, rows still encrypted under
	 * the previous FFC key must stay readable via the FFC_ENCRYPTION_KEY_PREVIOUS
	 * fallback, while fresh writes use the new active key. The health report must
	 * flag the re-key with a distinct, non-empty previous fingerprint. Separate
	 * process so the defines don't leak into other tests.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_decrypt_falls_back_to_previous_ffc_key_during_rekey(): void {
		// No character repeats, so a different 32-byte slice is a different key (#2098).
		$previous = strrev( self::DISTINCT_KEY );
		define( 'FFC_ENCRYPTION_KEY', self::DISTINCT_KEY ); // new active key
		define( 'FFC_ENCRYPTION_KEY_PREVIOUS', $previous ); // prior FFC key

		// Forge a row as it was written when the previous key was active, via
		// the same encrypt-with-keys seam the code derives the previous pair from.
		$encrypt  = new \ReflectionMethod( Encryption::class, 'encrypt_with_keys' );
		$encrypt->setAccessible( true );
		$prev_enc = substr( $previous, 0, 32 );
		$prev_mac = hash_hmac( 'sha256', 'ffc-hmac-key', $previous, true );
		$old_row  = $encrypt->invoke( null, 'secret-cpf-000', $prev_enc, $prev_mac );
		$this->assertNotNull( $old_row );

		// Active key is B and the row is under A → only the previous fallback reads it.
		$this->assertSame( 'secret-cpf-000', Encryption::decrypt( $old_row ) );

		// Fresh writes use the active (B) key and round-trip.
		$fresh = Encryption::encrypt( 'fresh-999' );
		$this->assertNotNull( $fresh );
		$this->assertSame( 'fresh-999', Encryption::decrypt( $fresh ) );

		// Health report reflects the re-key.
		$report = Encryption::key_health_report();
		$this->assertTrue( $report['rekey_in_progress'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{12}$/', $report['previous_fingerprint'] );
		$this->assertNotSame( $report['fingerprint'], $report['previous_fingerprint'] );
	}

	/**
	 * The active key is the first 32 bytes of FFC_ENCRYPTION_KEY, and the
	 * HMAC key is derived from the whole constant. Pinned against a key with no
	 * repeated character, so any other slice is a different key: a change to
	 * which bytes form the key would leave every stored record unreadable
	 * (#2098). Separate process for the define.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_active_key_is_the_first_32_bytes_of_the_constant(): void {
		define( 'FFC_ENCRYPTION_KEY', self::DISTINCT_KEY );

		$ciphertext = Encryption::encrypt( 'secret-cpf-000' );
		$this->assertNotNull( $ciphertext );

		$decrypt = new \ReflectionMethod( Encryption::class, 'try_decrypt' );
		$decrypt->setAccessible( true );
		$mac = hash_hmac( 'sha256', 'ffc-hmac-key', self::DISTINCT_KEY, true );

		$this->assertSame( 'secret-cpf-000', $decrypt->invoke( null, $ciphertext, substr( self::DISTINCT_KEY, 0, 32 ), $mac ) );
		// Not assertNull: the MAC key is the right one, so the HMAC check passes and
		// CBC decrypts under the wrong key, whose last block carries valid PKCS#7
		// padding about once in 256 random IVs (#2168). What holds every run is
		// that the shifted slice never yields the plaintext.
		$this->assertNotSame( 'secret-cpf-000', $decrypt->invoke( null, $ciphertext, substr( self::DISTINCT_KEY, 1, 32 ), $mac ), 'A shifted slice is another key.' );
	}

	public function test_key_health_report_reports_no_rekey_by_default(): void {
		// No FFC_ENCRYPTION_KEY_PREVIOUS in the default test env.
		$report = Encryption::key_health_report();
		$this->assertFalse( $report['rekey_in_progress'] );
		$this->assertSame( '', $report['previous_fingerprint'] );
	}

	public function test_key_fingerprint_is_stable_12_char_hex(): void {
		$fingerprint = Encryption::key_fingerprint();

		$this->assertSame( 12, strlen( $fingerprint ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{12}$/', $fingerprint );
		// Deterministic for a fixed key, and the health report reuses it.
		$this->assertSame( $fingerprint, Encryption::key_fingerprint() );
		$this->assertSame( $fingerprint, Encryption::key_health_report()['fingerprint'] );
	}

	// ------------------------------------------------------------------
	// looks_like_envelope() — a shape test, deliberately strict
	// ------------------------------------------------------------------

	public function test_looks_like_envelope_accepts_real_ciphertext_of_both_shapes(): void {
		$this->assertTrue( Encryption::looks_like_envelope( (string) Encryption::encrypt( 'x' ) ), 'A v2 envelope must be recognised.' );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- building a legacy-shaped fixture.
		$this->assertTrue( Encryption::looks_like_envelope( base64_encode( str_repeat( "\x01", 32 ) ) ), 'A v1-shaped value (IV + one block) must be recognised.' );
	}

	public function test_looks_like_envelope_refuses_ordinary_values(): void {
		$this->assertFalse( Encryption::looks_like_envelope( '518.178.420-80' ), 'A formatted CPF is not base64.' );
		$this->assertFalse( Encryption::looks_like_envelope( Identifiers::RF_A ), 'A short RF decodes too short to be an envelope.' );
		$this->assertFalse( Encryption::looks_like_envelope( 'v2:YWJj' ), 'A v2 prefix over a short payload is not an envelope.' );
		$this->assertFalse( Encryption::looks_like_envelope( 'v2:not base64!' ) );
	}

	// ------------------------------------------------------------------
	// Legacy (v1) ciphertexts
	// ------------------------------------------------------------------

	/**
	 * The active encryption key, read the way the class reads it.
	 *
	 * @return string
	 */
	private function active_key(): string {
		$method = new \ReflectionMethod( Encryption::class, 'get_encryption_key' );
		$method->setAccessible( true );
		return (string) $method->invoke( null );
	}

	public function test_a_legacy_v1_ciphertext_still_decrypts(): void {
		$iv     = str_repeat( "\x02", 16 );
		$cipher = openssl_encrypt( 'legacy-value', 'AES-256-CBC', $this->active_key(), OPENSSL_RAW_DATA, $iv );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- building a legacy fixture.
		$this->assertSame( 'legacy-value', Encryption::decrypt( base64_encode( $iv . $cipher ) ) );
	}

	public function test_a_legacy_v1_value_too_short_for_an_iv_is_refused(): void {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- building a legacy fixture.
		$this->assertNull( Encryption::decrypt( base64_encode( 'abc' ) ) );
	}

	public function test_a_legacy_v1_value_with_broken_padding_is_refused(): void {
		$iv = str_repeat( "\x03", 16 );
		// One block whose plaintext ends in a zero byte: PKCS#7 has no zero pad,
		// so openssl refuses it on decryption, deterministically.
		$block = openssl_encrypt( str_repeat( "\x00", 16 ), 'AES-256-CBC', $this->active_key(), OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- building a legacy fixture.
		$this->assertNull( Encryption::decrypt( base64_encode( $iv . $block ) ) );
	}

	// ------------------------------------------------------------------
	// Batch helpers: the columns the older tests did not reach
	// ------------------------------------------------------------------

	public function test_decrypt_appointment_falls_back_to_the_rf_column(): void {
		$out = Encryption::decrypt_appointment( array( 'rf_encrypted' => Encryption::encrypt( '5181780' ) ) );

		$this->assertSame( '5181780', $out['rf'] );
		$this->assertSame( '5181780', $out['cpf_rf'] );
		$this->assertArrayNotHasKey( 'cpf', $out );
	}

	/**
	 * A decoupled hash salt is what `hash()` uses, and the health check reads it.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_dedicated_hash_salt_is_used_and_audited(): void {
		define( 'FFC_HASH_SALT', str_repeat( 'S', 64 ) );

		$this->assertSame( hash( 'sha256', 'value' . str_repeat( 'S', 64 ) ), Encryption::hash( 'value' ) );

		$report = new \ReflectionMethod( Encryption::class, 'active_secrets' );
		$report->setAccessible( true );
		$this->assertContains( str_repeat( 'S', 64 ), $report->invoke( null ), 'The health check must audit the salt actually in use.' );
	}

	/**
	 * Every encrypted column the registry declares for an appointment is
	 * decrypted, read from the registry rather than from a list of its own
	 * (#1934): a field added there is covered here without editing this test.
	 */
	public function test_decrypt_appointment_decrypts_every_column_the_registry_declares(): void {
		$fields = SensitiveFieldRegistry::fields_for( SensitiveFieldRegistry::CONTEXT_APPOINTMENT );
		$this->assertNotEmpty( $fields, 'The registry declares no appointment field, so nothing below is measured.' );

		foreach ( $fields as $field => $spec ) {
			if ( null === $spec['encrypted_column'] ) {
				continue;
			}

			$out = Encryption::decrypt_appointment( array( $spec['encrypted_column'] => Encryption::encrypt( 'v-' . $field ) ) );

			$this->assertSame( 'v-' . $field, $out[ $field ], $field );
		}
	}

	public function test_decrypt_appointment_leaves_a_column_the_registry_does_not_declare(): void {
		$row = array( 'notes_encrypted' => Encryption::encrypt( 'kept' ) );

		$this->assertSame( $row, Encryption::decrypt_appointment( $row ) );
	}

	/**
	 * A ciphertext that does not decrypt falls back to the plaintext column, for
	 * every field alike. The e-mail and the IP used to come back empty instead,
	 * while the phone and the custom data fell back; one rule now covers all.
	 */
	public function test_decrypt_appointment_falls_back_to_the_plaintext_column_when_a_cipher_fails(): void {
		$out = Encryption::decrypt_appointment(
			array(
				'email'           => 'plain@example.com',
				'email_encrypted' => 'not-a-ciphertext',
				'user_ip_encrypted' => 'not-a-ciphertext',
			)
		);

		$this->assertSame( 'plain@example.com', $out['email'] );
		$this->assertSame( '', $out['user_ip'], 'No plaintext column: empty, as before.' );
	}

	/**
	 * A long enough FFC_ENCRYPTION_KEY is the active key, and the report says so.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_key_health_report_names_a_decoupled_key(): void {
		define( 'FFC_ENCRYPTION_KEY', str_repeat( 'K', 64 ) );

		$report = Encryption::key_health_report();

		$this->assertTrue( $report['encryption_decoupled'] );
		$this->assertSame( 'FFC_ENCRYPTION_KEY', $report['key_source'] );
	}

	/**
	 * A key shorter than 32 characters is ignored by the cipher, so the report
	 * must not name it as the source.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_key_health_report_does_not_credit_a_short_key_the_cipher_ignores(): void {
		define( 'FFC_ENCRYPTION_KEY', 'short' );

		$report = Encryption::key_health_report();

		$this->assertFalse( $report['encryption_decoupled'] );
		$this->assertStringStartsWith( 'WordPress keys', $report['key_source'] );
	}
}
