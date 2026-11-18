<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Core\CertificateRecord;
use FreeFormCertificate\Core\SigningKeys;
use FreeFormCertificate\Generators\QrDesign;
use FreeFormCertificate\Generators\SignedQrLink;
use FreeFormCertificate\Generators\SignedQrSize;
use FreeFormCertificate\Submissions\CertificateSigner;
use FreeFormCertificate\Tests\Support\ActivityLogBuffer;
use FreeFormCertificate\Tests\Support\FakeSignatureTable;
use FreeFormCertificate\Tests\Support\Identifiers;
use PHPUnit\Framework\TestCase;

/**
 * The link a signed certificate's QR code carries (#2639), and the vector
 * the JS verifier is tested against.
 *
 * @covers \FreeFormCertificate\Generators\SignedQrLink
 * @covers \FreeFormCertificate\Generators\QrDesign::without_logo
 */
final class SignedQrLinkTest extends TestCase {

	private const LINK = 'https://site.test/valid/#token=0123456789abcdef0123456789abcdef';

	/** @var array<string, mixed> */
	private array $options = array();

	/** @var array<int, array<string, mixed>> */
	private array $meta = array();

	private FakeSignatureTable $table;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		global $wpdb;
		$this->table = new FakeSignatureTable();
		$wpdb        = $this->table;

		$this->options = array( 'ffc_settings' => ActivityLogBuffer::ENABLED_SETTINGS );
		$this->meta    = array(
			12 => array(
				'_ffc_form_config'     => array( 'qr_mode' => 'full' ),
				'_ffc_template_values' => array( 'workload' => '40 horas' ),
			),
		);
		Functions\when( 'get_option' )->alias( fn( string $name, $default = false ) => $this->options[ $name ] ?? $default );
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ): bool {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'get_post_meta' )->alias( fn( int $id, string $key ) => $this->meta[ $id ][ $key ] ?? '' );
		Functions\when( 'get_post' )->alias(
			static function ( int $id ) {
				$post             = new \WP_Post();
				$post->ID         = $id;
				$post->post_title = 'Formação em Libras';
				return $post;
			}
		);
		Functions\when( 'wp_date' )->alias( static fn( string $format, int $ts ): string => gmdate( $format, $ts ) );
		ActivityLogBuffer::reset();
		ActivityLogBuffer::stub_functions();
	}

	protected function tearDown(): void {
		ActivityLogBuffer::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Sign submission 345 of form 12.
	 */
	private function sign(): void {
		SigningKeys::rotate();
		CertificateSigner::sign(
			345,
			array(
				'id'              => 345,
				'form_id'         => 12,
				'auth_code'       => 'A1B2C3D4E5F6',
				'submission_date' => 1795392000,
				'cpf_rf'          => Identifiers::CPF_A,
				'data'            => (string) json_encode( array( 'name' => 'Maria Aparecida da Silva Santos' ) ),
			)
		);
	}

	/**
	 * Whether a fragment's signature holds under the active key.
	 *
	 * @param string $fragment `<payload>.<signature>`.
	 */
	private static function verifies( string $fragment ): bool {
		list( $payload, $signature ) = explode( '.', $fragment );
		return SigningKeys::verify( SigningKeys::unb64( $payload ), $signature, 'k1' );
	}

	public function test_a_full_form_carries_both_payloads_and_both_verify(): void {
		$this->sign();

		$fragments = SignedQrLink::fragments( 345 );

		$this->assertNotNull( $fragments );
		$this->assertTrue( self::verifies( $fragments['full'] ) );
		$this->assertTrue( self::verifies( $fragments['compact'] ) );
		$this->assertStringContainsString( '|Maria Aparecida da Silva Santos|', SigningKeys::unb64( explode( '.', $fragments['full'] )[0] ) );
	}

	public function test_no_payload_carries_the_cpf(): void {
		$this->sign();
		$digits = (string) preg_replace( '/\D+/', '', Identifiers::CPF_A );

		foreach ( (array) SignedQrLink::fragments( 345 ) as $fragment ) {
			$this->assertStringNotContainsString( $digits, SigningKeys::unb64( explode( '.', $fragment )[0] ) );
		}
	}

	public function test_a_compact_form_carries_no_full_payload(): void {
		$this->meta[12]['_ffc_form_config'] = array( 'qr_mode' => 'compact' );
		$this->sign();

		$fragments = SignedQrLink::fragments( 345 );

		$this->assertSame( '', $fragments['full'] ?? null );
		$this->assertTrue( self::verifies( $fragments['compact'] ) );
	}

	public function test_a_form_back_on_link_only_prints_the_plain_link(): void {
		$this->sign();
		$this->meta[12]['_ffc_form_config'] = array( 'qr_mode' => 'link' );

		$this->assertNull( SignedQrLink::fragments( 345 ) );
	}

	public function test_an_unsigned_certificate_prints_the_plain_link(): void {
		SigningKeys::rotate();

		$this->assertNull( SignedQrLink::fragments( 999 ) );
	}

	public function test_the_payload_fits_the_size_the_placeholder_prints(): void {
		$this->sign();
		$fragments = SignedQrLink::fragments( 345 );
		$full      = self::LINK . '&s=' . $fragments['full'];
		$compact   = self::LINK . '&s=' . $fragments['compact'];
		$full_px   = SignedQrSize::px_for( SignedQrSize::modules( $full, 'M' ), 2 );
		$comp_px   = SignedQrSize::px_for( SignedQrSize::modules( $compact, 'M' ), 2 );
		$this->assertLessThan( $full_px, $comp_px, 'The compact code is the smaller one.' );

		$this->assertSame( $full, SignedQrLink::for_placement( self::LINK, $fragments, $full_px, 2, 'M' ) );
		$this->assertSame( $compact, SignedQrLink::for_placement( self::LINK, $fragments, $full_px - 1, 2, 'M' ), 'Too small for full: compact.' );
		$this->assertSame( self::LINK, SignedQrLink::for_placement( self::LINK, $fragments, $comp_px - 1, 2, 'M' ), 'Too small for either: the link alone.' );
	}

	public function test_a_link_with_no_fragment_is_left_alone(): void {
		$this->sign();

		$this->assertSame( 'https://site.test/valid', SignedQrLink::for_placement( 'https://site.test/valid', SignedQrLink::fragments( 345 ), 400, 2, 'M' ) );
		$this->assertFalse( SignedQrLink::is_signed( self::LINK ) );
		$this->assertTrue( SignedQrLink::is_signed( self::LINK . '&s=a.b' ) );
	}

	public function test_a_signed_code_drops_the_logo_and_keeps_the_rest_of_the_design(): void {
		$design = new QrDesign(
			array(
				'dots'  => 'rounded',
				'color' => '#123456',
				'logo'  => 'data:image/png;base64,iVBORw0KGgo=',
			)
		);
		$this->assertSame( 'H', $design->error_level( 'M' ), 'Precondition: the logo forces H.' );

		$plain = $design->without_logo();

		$this->assertSame( 'M', $plain->error_level( 'M' ) );
		$this->assertSame( array_merge( $design->to_array(), array( 'logo' => '' ) ), $plain->to_array() );
	}

	/**
	 * PHP still produces the vector's bytes from its inputs: the payload text,
	 * the public key and the signature. The JS test verifies the same vector,
	 * so between them PHP and the browser agree on every byte.
	 */
	public function test_php_reproduces_the_vector_the_js_verifier_is_tested_against(): void {
		$vector = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/signed-qr-vector.json' ), true );
		$pair   = sodium_crypto_sign_seed_keypair( (string) hex2bin( $vector['seed_hex'] ) );
		$secret = sodium_crypto_sign_secretkey( $pair );

		$this->assertSame( $vector['public'], SigningKeys::b64( sodium_crypto_sign_publickey( $pair ) ) );
		$payloads = array(
			'full'    => CertificateRecord::full_payload( 'k1', $vector['fields'], $vector['hash'] ),
			'compact' => CertificateRecord::compact_payload( 'k1', $vector['fields'], $vector['hash'] ),
		);
		foreach ( $payloads as $mode => $payload ) {
			$this->assertSame( $vector[ $mode ]['payload'], $payload, $mode . ' payload text' );
			$this->assertSame( $vector[ $mode ]['s'], SigningKeys::b64( $payload ) . '.' . SigningKeys::b64( sodium_crypto_sign_detached( $payload, $secret ) ), $mode . ' signed value' );
		}
	}
}
