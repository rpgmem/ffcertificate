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
		Functions\when( 'get_option' )->justReturn( 5 ); // >= SEED_VERSION
		Functions\expect( 'wp_insert_post' )->never();
		Functions\expect( 'update_option' )->never();

		CertTemplateSeeder::maybe_seed();

		$this->assertTrue( true ); // Reaching here without an insert is the assertion.
	}

	public function test_maybe_seed_refreshes_default_bodies_on_version_bump(): void {
		// Already seeded under an older version (applied 1 < current SEED_VERSION):
		// maybe_seed() must refresh existing default bodies via restore() so the
		// #871 assets/ image-path fix reaches the install — not re-run the
		// create-only seed() (which would leave the stale body in place).
		Functions\when( 'get_option' )->justReturn( 1 );
		// All six shipped defaults already present (3 certificate + 2 receipt +
		// 1 ficha) → restore() refreshes every body, inserts nothing.
		Functions\when( 'get_posts' )->justReturn( array( 11, 12, 13, 14, 15, 16 ) );
		Functions\when( 'get_post_meta' )->alias(
			static function ( $id ) {
				$map = array(
					11 => 'default_certificate_1',
					12 => 'default_certificate_2',
					13 => 'default_certificate_3',
					14 => 'default_appointment_receipt_regular',
					15 => 'default_appointment_receipt_custom',
					16 => 'default_ficha',
				);
				return $map[ $id ] ?? '';
			}
		);
		Functions\expect( 'wp_insert_post' )->never(); // all defaults present → refresh only

		$refreshed = array();
		Functions\when( 'update_post_meta' )->alias(
			static function ( $id, $key, $value ) use ( &$refreshed ) {
				if ( CertTemplateCpt::META_HTML === $key ) {
					$refreshed[ $id ] = $value;
				}
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

		$this->assertCount( 6, $refreshed, 'all six default bodies refreshed on the bump' );
		// The three certificate defaults still resolve their background under assets/.
		foreach ( array( 11, 12, 13 ) as $cert_id ) {
			$this->assertStringContainsString( 'assets/img/certificate-defaults/', (string) $refreshed[ $cert_id ] );
		}
		$this->assertSame( 5, $bumped[1], 'flag bumped to the new seed version' );
	}

	public function test_maybe_seed_seeds_all_defaults_and_bumps_flag(): void {
		Functions\when( 'get_option' )->justReturn( 0 );

		$meta = array();
		// Stateful pool: empty when seed() looks, populated by the time the
		// post-run guard checks whether anything was actually created.
		Functions\when( 'get_posts' )->alias(
			static function () use ( &$meta ) {
				return array_keys( $meta );
			}
		);
		Functions\when( 'get_post_meta' )->alias(
			static function ( $id ) use ( &$meta ) {
				return $meta[ $id ][ CertTemplateCpt::META_DEFAULT_SLUG ] ?? '';
			}
		);

		$inserted = 0;
		Functions\when( 'wp_insert_post' )->alias(
			static function () use ( &$inserted ) {
				return 100 + ( ++$inserted );
			}
		);
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

		// 3 certificate defaults + 2 appointment-receipt defaults (#945) + 1 ficha (#951).
		$this->assertSame( 6, $inserted, 'seeds all six shipped defaults' );
		$this->assertCount( 6, $meta );

		$certificates = array();
		$receipts     = array();
		$fichas       = array();
		foreach ( $meta as $kv ) {
			$this->assertSame( '1', $kv[ CertTemplateCpt::META_IS_DEFAULT ] );
			$this->assertSame( '1', $kv[ CertTemplateCpt::META_VISIBLE ] );
			$this->assertNotSame( '', $kv[ CertTemplateCpt::META_DEFAULT_SLUG ] );
			$this->assertStringContainsString( '<div', $kv[ CertTemplateCpt::META_HTML ] );
			$this->assertArrayHasKey( CertTemplateCpt::META_KIND, $kv );

			if ( CertTemplateCpt::KIND_APPOINTMENT_RECEIPT === $kv[ CertTemplateCpt::META_KIND ] ) {
				$receipts[] = $kv;
			} elseif ( CertTemplateCpt::KIND_FICHA === $kv[ CertTemplateCpt::META_KIND ] ) {
				$fichas[] = $kv;
			} else {
				$certificates[] = $kv;
			}
		}

		$this->assertCount( 3, $certificates, 'three certificate defaults' );
		$this->assertCount( 2, $receipts, 'two appointment-receipt defaults' );
		$this->assertCount( 1, $fichas, 'one ficha default' );

		foreach ( $certificates as $kv ) {
			// Certificate defaults carry the shipped background in the dedicated field.
			$this->assertStringContainsString( 'certificate-defaults/default_background_certificate', $kv[ CertTemplateCpt::META_BG_IMAGE ] );
			$this->assertStringNotContainsString( 'default_background_certificate', $kv[ CertTemplateCpt::META_HTML ] );
		}
		foreach ( $receipts as $kv ) {
			// Receipt defaults have no background image.
			$this->assertSame( '', $kv[ CertTemplateCpt::META_BG_IMAGE ] );
		}

		$this->assertSame( 'ffc_cert_templates_seeded_version', $bumped[0] );
		$this->assertSame( 5, $bumped[1] );
	}

	public function test_seed_is_non_destructive_when_all_slugs_exist(): void {
		Functions\when( 'get_posts' )->justReturn( array( 11, 12, 13, 14, 15, 16 ) );
		Functions\when( 'get_post_meta' )->alias(
			static function ( $id ) {
				$map = array(
					11 => 'default_certificate_1',
					12 => 'default_certificate_2',
					13 => 'default_certificate_3',
					14 => 'default_appointment_receipt_regular',
					15 => 'default_appointment_receipt_custom',
					16 => 'default_ficha',
				);
				return $map[ $id ] ?? '';
			}
		);
		Functions\expect( 'wp_insert_post' )->never();

		CertTemplateSeeder::seed();

		$this->assertTrue( true ); // No insert attempted = non-destructive.
	}

	public function test_restore_refreshes_existing_and_adds_missing(): void {
		// One shipped default already present (slug _1 → id 11); the other two missing.
		Functions\when( 'get_posts' )->justReturn( array( 11 ) );
		Functions\when( 'get_post_meta' )->alias(
			static fn( $id ) => 11 === $id ? 'default_certificate_1' : ''
		);

		$updated = array();
		Functions\when( 'update_post_meta' )->alias(
			static function ( $id, $key, $value ) use ( &$updated ) {
				$updated[] = array( $id, $key, $value );
				return true;
			}
		);
		$inserted = 0;
		Functions\when( 'wp_insert_post' )->alias(
			static function () use ( &$inserted ) {
				return 100 + ( ++$inserted );
			}
		);

		CertTemplateSeeder::restore();

		// Existing default #11 had its HTML refreshed in place (not re-inserted).
		$refreshed = array_filter(
			$updated,
			static fn( $u ) => 11 === $u[0] && CertTemplateCpt::META_HTML === $u[1] && '' !== (string) $u[2]
		);
		$this->assertNotEmpty( $refreshed, 'existing default HTML is refreshed' );

		// The five missing defaults were (re)created (cert_2, cert_3, the two
		// receipt defaults + the ficha default); the present one (#11) was not.
		$this->assertSame( 5, $inserted, 'the five missing defaults are inserted' );
	}

	/**
	 * The #865 phase-4 hole. `maybe_seed()` used to write the seed flag
	 * unconditionally, so a first seed that created nothing still recorded
	 * itself as applied — and because the flag short-circuits every later run,
	 * the pool stayed empty forever. An empty pool is precisely what makes
	 * `AdminAssetsManager::discover_layout_templates()` fall through to the
	 * deprecated legacy `html/` glob, so the fallback could never meet its own
	 * exit condition ("removed once the pool seeds on every install").
	 *
	 * Simulated here through a failing `wp_insert_post()`. The real-world cause
	 * is usually the other one — `Utils::read_file_contents()` returning '' for
	 * an unreadable seed file, which `seed()` silently `continue`s past — but
	 * that path cannot be exercised from a unit test: it keys on
	 * `FFC_PLUGIN_DIR`, a process-wide constant the suite defines once. Both
	 * causes funnel into the same post-run check, which is what this pins.
	 */
	public function test_maybe_seed_does_not_mark_seeded_when_nothing_was_created(): void {
		Functions\when( 'get_option' )->justReturn( 0 );
		Functions\when( 'get_posts' )->justReturn( array() ); // pool stays empty
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'update_post_meta' )->justReturn( true );
		// wp_insert_post( …, true ) returning WP_Error is the failure shape.
		Functions\when( 'wp_insert_post' )->justReturn( new \WP_Error( 'db_insert_error', 'nope' ) );

		$written = null;
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$written ) {
				$written = array( $key, $value );
				return true;
			}
		);

		CertTemplateSeeder::maybe_seed();

		$this->assertNull(
			$written,
			'the seed flag must stay unwritten so the next admin request retries'
		);
	}

	/**
	 * Same guard on the version-bump branch: an already-seeded install whose
	 * defaults were all deleted must not have the new seed version recorded
	 * when restore() fails to put any of them back.
	 */
	public function test_maybe_seed_does_not_bump_the_version_when_restore_creates_nothing(): void {
		Functions\when( 'get_option' )->justReturn( 1 ); // seeded under an older version
		Functions\when( 'get_posts' )->justReturn( array() ); // …but the pool is empty now
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'update_post_meta' )->justReturn( true );
		Functions\when( 'wp_insert_post' )->justReturn( new \WP_Error( 'db_insert_error', 'nope' ) );

		$written = null;
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$written ) {
				$written = array( $key, $value );
				return true;
			}
		);

		CertTemplateSeeder::maybe_seed();

		$this->assertNull(
			$written,
			'the seed flag must stay unwritten so the next admin request retries'
		);
	}

	/**
	 * The guard is deliberately narrow — "the pool is not empty", not "every
	 * definition seeded". A partial seed still populates the layout picker and
	 * keeps the legacy fallback dormant, so it counts as applied. Blocking the
	 * flag on completeness instead would re-run the seeder on every admin
	 * request for as long as a single seed file stayed unreadable.
	 */
	public function test_maybe_seed_marks_seeded_when_only_some_defaults_could_be_created(): void {
		Functions\when( 'get_option' )->justReturn( 0 );

		$meta = array();
		Functions\when( 'get_posts' )->alias(
			static function () use ( &$meta ) {
				return array_keys( $meta );
			}
		);
		Functions\when( 'get_post_meta' )->alias(
			static function ( $id ) use ( &$meta ) {
				return $meta[ $id ][ CertTemplateCpt::META_DEFAULT_SLUG ] ?? '';
			}
		);
		Functions\when( 'update_post_meta' )->alias(
			static function ( $id, $key, $value ) use ( &$meta ) {
				$meta[ $id ][ $key ] = $value;
				return true;
			}
		);

		// Only the first insert succeeds; the other five fail.
		$calls = 0;
		Functions\when( 'wp_insert_post' )->alias(
			static function () use ( &$calls ) {
				++$calls;
				return 1 === $calls ? 101 : new \WP_Error( 'db_insert_error', 'nope' );
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

		$this->assertSame( 'ffc_cert_templates_seeded_version', $bumped[0] ?? null );
		$this->assertSame( 5, $bumped[1] ?? null, 'a partial seed still counts as applied' );
	}

	public function test_seed_html_references_shipped_assets_not_html_folder(): void {
		// #865 crit #7: default images moved to the versioned assets/ dir so
		// html/ can eventually be retired; guard against a seed reference
		// regressing back to the update-fragile plugin html/ folder.
		$dir   = dirname( __DIR__, 2 ) . '/templates/certificate-defaults/';
		$files = glob( $dir . '*.html' );
		$this->assertNotEmpty( $files );

		foreach ( (array) $files as $file ) {
			$html = (string) file_get_contents( $file );
			$this->assertStringNotContainsString(
				'plugins/ffcertificate/html/',
				$html,
				basename( (string) $file ) . ' must not reference the update-fragile html/ folder'
			);
			if ( false !== strpos( $html, '<img' ) ) {
				$this->assertStringContainsString(
					'assets/img/certificate-defaults/',
					$html,
					basename( (string) $file ) . ' images must resolve under the shipped assets/ dir'
				);
			}
		}
	}
}
