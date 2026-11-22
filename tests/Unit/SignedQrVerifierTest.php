<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Admin\SigningKeyHandler;
use FreeFormCertificate\Core\SigningKeys;
use FreeFormCertificate\Frontend\SignedQrVerifier;
use PHPUnit\Framework\TestCase;

/**
 * What the browser gets to check a signed QR code (#2639): the verification
 * page's data and the standalone verifier file.
 *
 * @covers \FreeFormCertificate\Frontend\SignedQrVerifier
 * @covers \FreeFormCertificate\Admin\SigningKeyHandler::download_verifier
 */
final class SignedQrVerifierTest extends TestCase {

	/** @var array<string, mixed> */
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
		foreach ( array( '__', 'esc_html', 'esc_attr' ) as $fn ) {
			Functions\when( $fn )->returnArg();
		}
		Functions\when( 'get_bloginfo' )->justReturn( 'Escola Teste' );
		Functions\when( 'get_locale' )->justReturn( 'pt_BR' );
		Functions\when( 'wp_json_encode' )->alias( static fn( $value ) => json_encode( $value ) );
		Functions\when( 'wp_date' )->alias( static fn( string $format, int $ts ): string => gmdate( $format, $ts ) );
		Functions\when( 'wp_timezone' )->alias( static fn() => new \DateTimeZone( 'UTC' ) );
		Functions\when( 'determine_locale' )->justReturn( 'pt_BR' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_the_strings_are_the_ones_the_script_reads(): void {
		$script = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/ffc-signed-qr.js' );
		preg_match_all( '/\bs\.(\w+)/', $script, $read );

		$this->assertEqualsCanonicalizing( array_unique( $read[1] ), array_keys( SignedQrVerifier::strings() ), 'Every string the script reads is published, and nothing else.' );
	}

	public function test_the_data_carries_the_plugin_date_format(): void {
		$this->options['ffc_settings'] = array( 'date_format' => 'Y.m.d' );

		$this->assertSame( 'Y.m.d', SignedQrVerifier::data()['dates']['date'], 'The payload date prints in the plugin format, offline too (#2676).' );
	}

	public function test_nothing_loads_before_a_key_exists(): void {
		$enqueued = 0;
		Functions\when( 'wp_enqueue_script' )->alias(
			static function () use ( &$enqueued ): void {
				++$enqueued;
			}
		);

		SignedQrVerifier::enqueue();

		$this->assertSame( 0, $enqueued );
	}

	public function test_the_standalone_file_carries_every_public_key_and_no_secret(): void {
		SigningKeys::rotate();
		SigningKeys::rotate();
		$stored = $this->options[ SigningKeys::OPTION ];

		$html = SignedQrVerifier::standalone_html();

		$this->assertStringContainsString( 'window.FFCSignedQr', $html, 'The verifier script is inline.' );
		foreach ( $stored['keys'] as $key ) {
			$this->assertStringContainsString( (string) $key['public'], $html );
			$this->assertStringNotContainsString( (string) $key['secret'], $html );
		}
		$this->assertStringContainsString( 'Escola Teste', $html );
		$this->assertStringNotContainsString( '<script src', $html, 'Nothing is fetched: it works offline.' );
	}

	public function test_the_download_needs_the_tab_capability_and_a_nonce(): void {
		SigningKeys::rotate();
		$nonce = '';
		Functions\when( 'check_admin_referer' )->alias(
			static function ( string $action ) use ( &$nonce ): bool {
				$nonce = $action;
				return true;
			}
		);
		Functions\when( 'current_user_can' )->alias( static fn( string $cap ): bool => 'ffc_manage_settings' === $cap );
		$handler = new class() extends SigningKeyHandler {
			/** @var array{0: string, 1: string} */
			public array $sent = array( '', '' );
			protected function emit_download( string $filename, string $body ): void {
				$this->sent = array( $filename, $body );
			}
		};

		$handler->download_verifier();

		$this->assertSame( SigningKeyHandler::VERIFIER_ACTION, $nonce );
		$this->assertSame( 'certificate-verifier.html', $handler->sent[0] );
		$this->assertStringContainsString( SigningKeys::public_keys()['k1'], $handler->sent[1] );
	}

	public function test_the_download_is_refused_without_the_capability(): void {
		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'wp_die' )->alias(
			static function (): void {
				throw new \RuntimeException( 'died' );
			}
		);

		$this->expectExceptionMessage( 'died' );
		( new SigningKeyHandler() )->download_verifier();
	}
}
