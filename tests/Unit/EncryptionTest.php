<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\Encryption;

/**
 * Tests for Encryption class: round-trip, hashing, batch ops, configuration.
 */
class EncryptionTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Define WordPress encryption keys if not set.
        if ( ! defined( 'SECURE_AUTH_KEY' ) ) {
            define( 'SECURE_AUTH_KEY', 'test-secure-auth-key-1234567890abcdef' );
        }
        if ( ! defined( 'LOGGED_IN_KEY' ) ) {
            define( 'LOGGED_IN_KEY', 'test-logged-in-key-1234567890abcdef' );
        }
        if ( ! defined( 'NONCE_KEY' ) ) {
            define( 'NONCE_KEY', 'test-nonce-key-1234567890abcdef' );
        }
        if ( ! defined( 'AUTH_KEY' ) ) {
            define( 'AUTH_KEY', 'test-auth-key-1234567890abcdef' );
        }
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
        $original = 'João da Silva — cpf: 123.456.789-09 — ação';
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
    // encrypt_submission() batch
    // ------------------------------------------------------------------

    public function test_encrypt_submission_encrypts_all_fields(): void {
        $submission = array(
            'email'   => 'user@example.com',
            'user_ip' => '192.168.1.1',
            'data'    => '{"name":"João"}',
        );

        $result = Encryption::encrypt_submission( $submission );

        $this->assertArrayHasKey( 'email_encrypted', $result );
        $this->assertArrayHasKey( 'email_hash', $result );
        $this->assertArrayHasKey( 'user_ip_encrypted', $result );
        $this->assertArrayHasKey( 'data_encrypted', $result );

        // cpf_rf no longer encrypted by batch method (use split cpf/rf columns)
        $this->assertArrayNotHasKey( 'cpf_rf_encrypted', $result );
        $this->assertArrayNotHasKey( 'cpf_rf_hash', $result );

        // Encrypted values should decrypt back
        $this->assertSame( 'user@example.com', Encryption::decrypt( $result['email_encrypted'] ) );
        $this->assertSame( '192.168.1.1', Encryption::decrypt( $result['user_ip_encrypted'] ) );
        $this->assertSame( '{"name":"João"}', Encryption::decrypt( $result['data_encrypted'] ) );
    }

    public function test_encrypt_submission_skips_empty_fields(): void {
        $submission = array( 'email' => '' );

        $result = Encryption::encrypt_submission( $submission );

        $this->assertArrayNotHasKey( 'email_encrypted', $result );
    }

    // ------------------------------------------------------------------
    // decrypt_submission() batch
    // ------------------------------------------------------------------

    public function test_decrypt_submission_restores_fields(): void {
        $original_email = 'user@example.com';
        $encrypted_email = Encryption::encrypt( $original_email );

        $submission = array(
            'id'              => 1,
            'email'           => '',
            'email_encrypted' => $encrypted_email,
            'cpf_rf'          => '12345678901',
        );

        $result = Encryption::decrypt_submission( $submission );

        $this->assertSame( $original_email, $result['email'] );
        $this->assertSame( '12345678901', $result['cpf_rf'] ); // plain kept
    }

    public function test_decrypt_submission_keeps_plain_when_no_encrypted(): void {
        $submission = array(
            'email'  => 'plain@example.com',
            'cpf_rf' => '12345678901',
        );

        $result = Encryption::decrypt_submission( $submission );

        $this->assertSame( 'plain@example.com', $result['email'] );
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
            'email'                 => '',
            'email_encrypted'       => Encryption::encrypt( 'a@b.com' ),
            'cpf_rf'               => '',
            'cpf_rf_encrypted'     => Encryption::encrypt( '12345678901' ),
            'phone'                => '',
            'phone_encrypted'      => Encryption::encrypt( '11999999999' ),
            'user_ip'              => '',
            'user_ip_encrypted'    => Encryption::encrypt( '10.0.0.1' ),
            'custom_data'          => '',
            'custom_data_encrypted' => Encryption::encrypt( '{"key":"val"}' ),
        );

        $result = Encryption::decrypt_appointment( $appt );

        $this->assertSame( 'a@b.com', $result['email'] );
        $this->assertSame( '12345678901', $result['cpf_rf'] );
        $this->assertSame( '11999999999', $result['phone'] );
        $this->assertSame( '10.0.0.1', $result['user_ip'] );
        $this->assertSame( '{"key":"val"}', $result['custom_data'] );
    }

    // ------------------------------------------------------------------
    // is_configured() and get_info()
    // ------------------------------------------------------------------

    public function test_is_configured_returns_true_when_keys_exist(): void {
        $this->assertTrue( Encryption::is_configured() );
    }

    public function test_get_info_returns_expected_keys(): void {
        $info = Encryption::get_info();

        $this->assertArrayHasKey( 'configured', $info );
        $this->assertArrayHasKey( 'cipher', $info );
        $this->assertArrayHasKey( 'iv_length', $info );
        $this->assertArrayHasKey( 'key_source', $info );
        $this->assertSame( 'AES-256-CBC', $info['cipher'] );
        $this->assertSame( 16, $info['iv_length'] );
    }

    // ------------------------------------------------------------------
    // test() self-test utility
    // ------------------------------------------------------------------

    public function test_self_test_returns_matching_round_trip(): void {
        $result = Encryption::test();

        $this->assertTrue( $result['match'], 'Self-test round-trip should match' );
        $this->assertSame( 'Test Value 123!@#', $result['original'] );
        $this->assertSame( $result['original'], $result['decrypted'] );
        $this->assertNotNull( $result['encrypted'] );
        $this->assertSame( 64, $result['hash_length'] );
    }
}
