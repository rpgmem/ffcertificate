<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Admin\SigningKeyHandler;
use FreeFormCertificate\Core\SigningKeys;
use FreeFormCertificate\Tests\Support\ActivityLogBuffer;
use PHPUnit\Framework\TestCase;

/**
 * The route behind the "Integrity signature" card (#2639).
 *
 * @covers \FreeFormCertificate\Admin\SigningKeyHandler
 */
final class SigningKeyHandlerTest extends TestCase {

	/** @var array<string, mixed> */
	private array $options = array();

	/** @var array<string, mixed> */
	private array $transients = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options    = array( 'ffc_settings' => ActivityLogBuffer::ENABLED_SETTINGS );
		$this->transients = array();
		Functions\when( 'get_option' )->alias( fn( string $name, $default = false ) => $this->options[ $name ] ?? $default );
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ): bool {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value ): bool {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'add_query_arg' )->alias( static fn( $key, $value, $url ) => $url . '&' . $key . '=' . $value );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( '__' )->returnArg();
		ActivityLogBuffer::reset();
		ActivityLogBuffer::stub_functions();
	}

	protected function tearDown(): void {
		ActivityLogBuffer::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_the_first_run_generates_a_key_logs_it_and_says_so(): void {
		$url = SigningKeyHandler::run( 'https://site.test/wp-admin/admin.php?page=ffc-settings&tab=certificates' );

		$this->assertStringEndsWith( '&ffc_notice=signing_key', $url );
		$this->assertSame( 'k1', SigningKeys::active_id() );
		$notice = $this->transients['ffc_notice_7_signing_key'];
		$this->assertSame( 'success', $notice['type'] );
		$this->assertStringContainsString( 'k1', $notice['message'] );

		$entry = ActivityLogBuffer::entries()[0] ?? array();
		$this->assertSame( SigningKeyHandler::LOG_GENERATED, $entry['action'] ?? '' );
	}

	public function test_a_second_run_rotates_and_names_the_retired_key(): void {
		SigningKeyHandler::run( 'u' );
		SigningKeyHandler::run( 'u' );

		$this->assertSame( 'k2', SigningKeys::active_id() );
		$keys = SigningKeys::all()['keys'];
		$this->assertGreaterThan( 0, $keys['k1']['retired'], 'The previous key is retired, never deleted.' );
		$this->assertStringContainsString( 'k1', $this->transients['ffc_notice_7_signing_key']['message'] );
	}

	public function test_the_route_is_gated_on_the_danger_zone_capability(): void {
		Functions\when( 'current_user_can' )->alias( static fn( string $cap ): bool => 'ffc_manage_settings_dangerzone' === $cap );
		$this->assertTrue( SigningKeyHandler::current_user_can() );

		Functions\when( 'current_user_can' )->justReturn( false );
		$this->assertFalse( SigningKeyHandler::current_user_can() );
	}
}
