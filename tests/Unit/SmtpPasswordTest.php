<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Integrations\SmtpPassword;
use PHPUnit\Framework\TestCase;

/**
 * The SMTP password at rest and on the SMTP tab (#2342).
 *
 * Encryption is the real class: what is under test is that the password goes
 * through it and comes back unchanged.
 *
 * @covers \FreeFormCertificate\Integrations\SmtpPassword
 */
final class SmtpPasswordTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// A failed decrypt is logged; the log reads its settings first.
		Functions\when( 'get_option' )->justReturn( array() );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_a_typed_password_is_stored_encrypted_and_read_back_unchanged(): void {
		$typed  = "p@ss <word> %41  with spaces";
		$stored = SmtpPassword::for_storage( $typed );

		$this->assertStringStartsWith( Encryption::V2_PREFIX, $stored );
		$this->assertStringNotContainsString( 'p@ss', $stored );
		$this->assertSame( $typed, SmtpPassword::read( array( 'smtp_pass' => $stored ) ) );
	}

	public function test_nothing_typed_stores_nothing(): void {
		$this->assertSame( '', SmtpPassword::for_storage( '' ) );
		$this->assertSame( '', SmtpPassword::for_storage( "\r\n" ) );
	}

	public function test_control_characters_are_removed_and_nothing_else(): void {
		$stored = SmtpPassword::for_storage( "ab\x00c\td " );

		$this->assertSame( 'abcd ', SmtpPassword::read( array( 'smtp_pass' => $stored ) ) );
	}

	public function test_a_password_saved_before_encryption_is_read_as_it_is(): void {
		$this->assertSame( 'legacy-plain', SmtpPassword::read( array( 'smtp_pass' => 'legacy-plain' ) ) );
	}

	public function test_a_legacy_password_that_looks_like_base64_is_not_taken_for_a_ciphertext(): void {
		// Forty-eight base64 characters pass the legacy v1 envelope test.
		$legacy = str_repeat( 'QUJD', 12 );
		$this->assertTrue( Encryption::looks_like_envelope( $legacy ) );

		$this->assertSame( $legacy, SmtpPassword::read( array( 'smtp_pass' => $legacy ) ) );
	}

	public function test_a_plaintext_password_is_encrypted_once_on_upgrade(): void {
		$written = array();
		Functions\when( 'get_option' )->justReturn( array( 'smtp_pass' => 'legacy-plain', 'smtp_host' => 'h' ) );
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$written ) {
				$written[ $key ] = $value;
				return true;
			}
		);

		$this->assertTrue( SmtpPassword::encrypt_stored() );
		$this->assertStringStartsWith( Encryption::V2_PREFIX, $written['ffc_settings']['smtp_pass'] );
		$this->assertSame( 'legacy-plain', SmtpPassword::read( $written['ffc_settings'] ) );
		$this->assertSame( 'h', $written['ffc_settings']['smtp_host'] );
	}

	public function test_an_encrypted_or_empty_password_is_left_alone(): void {
		$log = \FreeFormCertificate\Tests\Support\CallLog::record( 'update_option', true );

		foreach ( array( array(), array( 'smtp_pass' => '' ), array( 'smtp_pass' => SmtpPassword::for_storage( 'x1' ) ), 'not-an-array' ) as $settings ) {
			Functions\when( 'get_option' )->justReturn( $settings );
			$this->assertFalse( SmtpPassword::encrypt_stored() );
		}
		$this->assertSame( 0, $log->count() );
	}

	public function test_the_state_says_whether_a_password_is_saved_without_its_value(): void {
		$this->assertSame( SmtpPassword::STATE_NONE, SmtpPassword::state( array() ) );
		$this->assertSame( SmtpPassword::STATE_NONE, SmtpPassword::state( array( 'smtp_pass' => '' ) ) );
		$this->assertSame( SmtpPassword::STATE_SAVED, SmtpPassword::state( array( 'smtp_pass' => SmtpPassword::for_storage( 'x1' ) ) ) );
		$this->assertSame( SmtpPassword::STATE_SAVED, SmtpPassword::state( array( 'smtp_pass' => 'legacy-plain' ) ) );
	}

	public function test_a_ciphertext_the_key_no_longer_opens_is_unreadable_not_saved(): void {
		$stored   = SmtpPassword::for_storage( 'x1' );
		$tampered = Encryption::V2_PREFIX . base64_encode( str_repeat( "\0", 80 ) );
		$this->assertNotSame( $stored, $tampered );

		$this->assertSame( '', SmtpPassword::read( array( 'smtp_pass' => $tampered ) ) );
		$this->assertSame( SmtpPassword::STATE_UNREADABLE, SmtpPassword::state( array( 'smtp_pass' => $tampered ) ) );
	}
}
