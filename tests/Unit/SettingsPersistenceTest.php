<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\SettingsPersistence;

/**
 * Tests for the Core\SettingsPersistence settings-write chokepoint: nonce +
 * capability gating, required per-field sanitisation, per-field capability
 * overrides, and the callback/option-key store.
 *
 * @covers \FreeFormCertificate\Core\SettingsPersistence
 */
class SettingsPersistenceTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// pcov attributes coverage only when the class is loaded before the
		// test body runs (see CLAUDE.md); preload the engine + its collaborator.
		class_exists( '\\FreeFormCertificate\\Core\\SettingsPersistence' );
		class_exists( '\\FreeFormCertificate\\Core\\Capabilities' );
		// The missing-sanitiser guard wraps its message in esc_html (WPCS).
		Functions\when( 'esc_html' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A minimal valid spec: passthrough `trim` sanitiser + option-key store.
	 *
	 * @param array<string, mixed> $overrides Keys to override.
	 * @return array<string, mixed>
	 */
	private function spec( array $overrides = array() ): array {
		return array_merge(
			array(
				'cap'    => 'ffc_manage_settings',
				'nonce'  => array( 'action' => 'ffc_x_action', 'field' => '_wpnonce' ),
				'input'  => array( '_wpnonce' => 'tok', 'a' => ' hi ' ),
				'fields' => array(
					'a' => array( 'sanitize' => 'trim' ),
				),
				'store'  => 'ffc_test_option',
			),
			$overrides
		);
	}

	public function test_returns_false_on_invalid_nonce(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\expect( 'update_option' )->never();

		$this->assertFalse( SettingsPersistence::save( $this->spec() ) );
	}

	public function test_returns_false_without_capability(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\expect( 'update_option' )->never();

		$this->assertFalse( SettingsPersistence::save( $this->spec() ) );
	}

	public function test_throws_when_field_missing_sanitize(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );

		$this->expectException( \InvalidArgumentException::class );
		SettingsPersistence::save( $this->spec( array( 'fields' => array( 'a' => array() ) ) ) );
	}

	public function test_sanitizes_and_persists_to_option_key(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\expect( 'update_option' )->once()->with( 'ffc_test_option', array( 'a' => 'hi' ) );

		$this->assertTrue( SettingsPersistence::save( $this->spec() ) );
	}

	public function test_persists_via_store_callback(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\expect( 'update_option' )->never();

		$captured = null;
		$spec     = $this->spec(
			array(
				'store' => function ( array $sanitized ) use ( &$captured ): void {
					$captured = $sanitized;
				},
			)
		);

		$this->assertTrue( SettingsPersistence::save( $spec ) );
		$this->assertSame( array( 'a' => 'hi' ), $captured );
	}

	public function test_skips_unsubmitted_fields(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );
		// 'b' is declared but absent from input -> not persisted.
		Functions\expect( 'update_option' )->once()->with( 'ffc_test_option', array( 'a' => 'hi' ) );

		$spec = $this->spec(
			array(
				'fields' => array(
					'a' => array( 'sanitize' => 'trim' ),
					'b' => array( 'sanitize' => 'trim' ),
				),
			)
		);
		$this->assertTrue( SettingsPersistence::save( $spec ) );
	}

	public function test_skips_field_lacking_per_field_capability(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		// manage_options -> false; coarse cap -> true; stricter field cap -> false.
		Functions\when( 'current_user_can' )->alias(
			function ( $cap ) {
				return 'ffc_manage_settings' === $cap;
			}
		);
		Functions\expect( 'update_option' )->once()->with( 'ffc_test_option', array( 'a' => 'hi' ) );

		$spec = $this->spec(
			array(
				'input'  => array( '_wpnonce' => 'tok', 'a' => ' hi ', 'smtp_pass' => ' secret ' ),
				'fields' => array(
					'a'         => array( 'sanitize' => 'trim' ),
					'smtp_pass' => array( 'sanitize' => 'trim', 'cap' => 'ffc_manage_settings_smtp' ),
				),
			)
		);
		$this->assertTrue( SettingsPersistence::save( $spec ) );
	}

	public function test_saves_field_when_per_field_capability_held(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->alias(
			function ( $cap ) {
				return in_array( $cap, array( 'ffc_manage_settings', 'ffc_manage_settings_smtp' ), true );
			}
		);
		Functions\expect( 'update_option' )->once()->with(
			'ffc_test_option',
			array( 'a' => 'hi', 'smtp_pass' => 'secret' )
		);

		$spec = $this->spec(
			array(
				'input'  => array( '_wpnonce' => 'tok', 'a' => ' hi ', 'smtp_pass' => ' secret ' ),
				'fields' => array(
					'a'         => array( 'sanitize' => 'trim' ),
					'smtp_pass' => array( 'sanitize' => 'trim', 'cap' => 'ffc_manage_settings_smtp' ),
				),
			)
		);
		$this->assertTrue( SettingsPersistence::save( $spec ) );
	}
}
