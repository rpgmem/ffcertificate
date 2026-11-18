<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Core\SigningKeys;
use FreeFormCertificate\Tests\Support\Identifiers;
use PHPUnit\Framework\TestCase;

/**
 * The site's certificate signing keys (#2639).
 *
 * Sodium and Encryption are the real ones: what is under test is that a key
 * signs, verifies and survives being stored.
 *
 * @covers \FreeFormCertificate\Core\SigningKeys
 */
final class SigningKeysTest extends TestCase {

	/**
	 * @var array<string, mixed>
	 */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options = array();
		Functions\when( 'get_option' )->alias( fn( string $name, $default = false ) => $this->options[ $name ] ?? $default );
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ): bool {
				$this->options[ $name ] = $value;
				return true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_a_fresh_install_has_no_key_and_cannot_sign(): void {
		$this->assertFalse( SigningKeys::has_active() );
		$this->assertNull( SigningKeys::sign( 'anything' ) );
	}

	public function test_a_generated_key_signs_and_its_signature_verifies(): void {
		$id     = SigningKeys::rotate();
		$signed = SigningKeys::sign( 'v1|k1|code' );

		$this->assertSame( 'k1', $id );
		$this->assertSame( 'k1', $signed['key'] );
		$this->assertTrue( SigningKeys::verify( 'v1|k1|code', $signed['signature'], 'k1' ) );
		$this->assertFalse( SigningKeys::verify( 'v1|k1|cods', $signed['signature'], 'k1' ), 'A changed message fails.' );
	}

	public function test_the_secret_is_stored_encrypted(): void {
		SigningKeys::rotate();

		$stored = $this->options[ SigningKeys::OPTION ]['keys']['k1']['secret'];
		$this->assertStringStartsWith( Encryption::V2_PREFIX, $stored );
		$this->assertTrue( SigningKeys::secret_is_encrypted() );
	}

	public function test_rotating_retires_the_old_key_which_still_verifies_what_it_signed(): void {
		SigningKeys::rotate();
		$old = SigningKeys::sign( 'signed by k1' );

		$this->assertSame( 'k2', SigningKeys::rotate() );

		$state = SigningKeys::all();
		$this->assertSame( 'k2', $state['active'] );
		$this->assertGreaterThan( 0, $state['keys']['k1']['retired'] );
		$this->assertSame( 0, $state['keys']['k2']['retired'] );
		$this->assertTrue( SigningKeys::verify( 'signed by k1', $old['signature'], 'k1' ) );
		$this->assertFalse( SigningKeys::verify( 'signed by k1', $old['signature'], 'k2' ), 'A signature belongs to its key.' );
		$this->assertSame( array( 'k1', 'k2' ), array_keys( SigningKeys::public_keys() ) );
	}

	public function test_the_identifier_mac_is_stable_per_key_and_ignores_punctuation(): void {
		SigningKeys::rotate();
		$digits = (string) preg_replace( '/\D+/', '', Identifiers::CPF_A );

		$mac = SigningKeys::identifier_mac( Identifiers::CPF_A, 'k1' );

		$this->assertSame( 64, strlen( $mac ) );
		$this->assertSame( $mac, SigningKeys::identifier_mac( $digits, 'k1' ) );
		$this->assertNotSame( hash( 'sha256', $digits ), $mac, 'Not a bare hash, which a table of every CPF reverses.' );

		SigningKeys::rotate();
		$this->assertSame( $mac, SigningKeys::identifier_mac( Identifiers::CPF_A, 'k1' ), 'Retiring a key keeps what it computed.' );
		$this->assertNotSame( $mac, SigningKeys::identifier_mac( Identifiers::CPF_A, 'k2' ) );
		$this->assertSame( '', SigningKeys::identifier_mac( '', 'k1' ) );
		$this->assertSame( '', SigningKeys::identifier_mac( Identifiers::CPF_A, 'k9' ) );
	}

	public function test_a_malformed_signature_or_unknown_key_never_verifies(): void {
		SigningKeys::rotate();

		$this->assertFalse( SigningKeys::verify( 'x', 'not-base64!', 'k1' ) );
		$this->assertFalse( SigningKeys::verify( 'x', SigningKeys::b64( str_repeat( "\0", 64 ) ), 'k9' ) );
	}

	public function test_the_fingerprint_reads_in_four_groups(): void {
		SigningKeys::rotate();

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{4}( [0-9a-f]{4}){3}$/', SigningKeys::fingerprint( 'k1' ) );
		$this->assertSame( '', SigningKeys::fingerprint( 'k9' ) );
	}

	public function test_a_corrupt_option_reads_as_no_key(): void {
		$this->options[ SigningKeys::OPTION ] = array( 'active' => 'k5', 'keys' => array( 'k5' => 'nope' ) );

		$this->assertFalse( SigningKeys::has_active() );
	}
}
