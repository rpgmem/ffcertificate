<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\CertTemplateCpt;

/**
 * Tests for the certificate-template pool CPT registration (#865).
 *
 * @covers \FreeFormCertificate\Admin\CertTemplateCpt
 */
class CertTemplateCptTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		class_exists( '\\FreeFormCertificate\\Admin\\CertTemplateCpt' );
		Functions\when( '__' )->returnArg();
		Functions\when( '_x' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_constructor_registers_init_action(): void {
		$hooks = array();
		Functions\when( 'add_action' )->alias(
			static function ( $hook ) use ( &$hooks ) {
				$hooks[] = $hook;
				return true;
			}
		);

		new CertTemplateCpt();

		$this->assertContains( 'init', $hooks );
	}

	public function test_register_calls_register_post_type_with_pool_contract(): void {
		Functions\when( 'add_action' )->justReturn( true );

		$captured_type = null;
		$captured_args = null;
		Functions\expect( 'register_post_type' )
			->once()
			->andReturnUsing(
				function ( $type, $args ) use ( &$captured_type, &$captured_args ) {
					$captured_type = $type;
					$captured_args = $args;
				}
			);

		( new CertTemplateCpt() )->register();

		$this->assertSame( 'ffc_cert_template', $captured_type );
		$this->assertSame( 'ffc_cert_template', CertTemplateCpt::POST_TYPE );
		$this->assertFalse( $captured_args['public'] );
		// #865/#951 management UI: native list table + edit screen stay registered
		// (show_ui) but carry no menu item of their own — the entry point is the
		// "Document Templates" launcher tab inside FFC Settings (TabTemplates).
		$this->assertTrue( $captured_args['show_ui'] );
		$this->assertFalse( $captured_args['show_in_menu'] );
		$this->assertTrue( $captured_args['map_meta_cap'] );
		$this->assertSame( 'ffc_cert_template', $captured_args['capability_type'] );
		$this->assertSame( array( 'title' ), $captured_args['supports'] );
		$this->assertFalse( $captured_args['has_archive'] );

		// #739-style decoupling mirrored from ffc_form: read primitives → view
		// cap, write primitives → manage cap.
		$this->assertSame( 'ffc_view_forms', $captured_args['capabilities']['edit_posts'] );
		$this->assertSame( 'ffc_view_forms', $captured_args['capabilities']['edit_others_posts'] );
		$this->assertSame( 'ffc_manage_forms', $captured_args['capabilities']['create_posts'] );
		$this->assertSame( 'ffc_manage_forms', $captured_args['capabilities']['delete_posts'] );

		// Per-post meta caps deliberately NOT mapped (menu-check poisoning, #739).
		$this->assertArrayNotHasKey( 'read_post', $captured_args['capabilities'] );
		$this->assertArrayNotHasKey( 'edit_post', $captured_args['capabilities'] );
		$this->assertArrayNotHasKey( 'delete_post', $captured_args['capabilities'] );
	}

	public function test_meta_key_constants_are_the_storage_contract(): void {
		// These strings are shared with the seeder/reader — locking them guards
		// the pool's storage contract against an accidental rename.
		$this->assertSame( '_ffc_template_html', CertTemplateCpt::META_HTML );
		$this->assertSame( '_ffc_is_default', CertTemplateCpt::META_IS_DEFAULT );
		$this->assertSame( '_ffc_default_slug', CertTemplateCpt::META_DEFAULT_SLUG );
		$this->assertSame( '_ffc_template_visible', CertTemplateCpt::META_VISIBLE );
	}

	public function test_is_valid_kind_accepts_only_known_kinds(): void {
		$this->assertTrue( CertTemplateCpt::is_valid_kind( CertTemplateCpt::KIND_CERTIFICATE ) );
		$this->assertTrue( CertTemplateCpt::is_valid_kind( CertTemplateCpt::KIND_APPOINTMENT_RECEIPT ) );
		$this->assertFalse( CertTemplateCpt::is_valid_kind( 'bogus' ) );
		$this->assertFalse( CertTemplateCpt::is_valid_kind( '' ) );
	}
}
