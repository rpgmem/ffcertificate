<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\CertTemplateSeeder;
use FreeFormCertificate\Admin\CertTemplateCpt;

/**
 * Tests for the non-destructive, versioned default-template seeder (#865).
 *
 * @covers \FreeFormCertificate\Admin\CertTemplateSeeder
 */
class CertTemplateSeederTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		class_exists( '\\FreeFormCertificate\\Admin\\CertTemplateSeeder' );
		// The seeder reads the real bundled seed files under the plugin root.
		if ( ! defined( 'FFC_PLUGIN_DIR' ) ) {
			define( 'FFC_PLUGIN_DIR', dirname( __DIR__, 2 ) . '/' );
		}
		Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_maybe_seed_skips_when_already_seeded(): void {
		Functions\when( 'get_option' )->justReturn( 1 ); // >= SEED_VERSION
		Functions\expect( 'wp_insert_post' )->never();
		Functions\expect( 'update_option' )->never();

		CertTemplateSeeder::maybe_seed();

		$this->assertTrue( true ); // Reaching here without an insert is the assertion.
	}

	public function test_maybe_seed_seeds_the_three_defaults_and_bumps_flag(): void {
		Functions\when( 'get_option' )->justReturn( 0 );
		Functions\when( 'get_posts' )->justReturn( array() ); // no existing defaults
		Functions\when( 'get_post_meta' )->justReturn( '' );

		$inserted = 0;
		Functions\when( 'wp_insert_post' )->alias(
			static function () use ( &$inserted ) {
				return 100 + ( ++$inserted );
			}
		);
		$meta = array();
		Functions\when( 'update_post_meta' )->alias(
			static function ( $id, $key, $value ) use ( &$meta ) {
				$meta[ $id ][ $key ] = $value;
				return true;
			}
		);
		$bumped = null;
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$bumped ) {
				$bumped = array( $key, $value );
				return true;
			}
		);

		CertTemplateSeeder::maybe_seed();

		$this->assertSame( 3, $inserted, 'seeds the three shipped defaults' );
		$this->assertCount( 3, $meta );
		foreach ( $meta as $kv ) {
			$this->assertSame( '1', $kv[ CertTemplateCpt::META_IS_DEFAULT ] );
			$this->assertSame( '1', $kv[ CertTemplateCpt::META_VISIBLE ] );
			$this->assertNotSame( '', $kv[ CertTemplateCpt::META_DEFAULT_SLUG ] );
			$this->assertStringContainsString( '<div', $kv[ CertTemplateCpt::META_HTML ] );
		}
		$this->assertSame( 'ffc_cert_templates_seeded_version', $bumped[0] );
		$this->assertSame( 1, $bumped[1] );
	}

	public function test_seed_is_non_destructive_when_all_slugs_exist(): void {
		Functions\when( 'get_posts' )->justReturn( array( 11, 12, 13 ) );
		Functions\when( 'get_post_meta' )->alias(
			static function ( $id ) {
				$map = array(
					11 => 'default_certificate_1',
					12 => 'default_certificate_2',
					13 => 'default_certificate_3',
				);
				return $map[ $id ] ?? '';
			}
		);
		Functions\expect( 'wp_insert_post' )->never();

		CertTemplateSeeder::seed();

		$this->assertTrue( true ); // No insert attempted = non-destructive.
	}
}
