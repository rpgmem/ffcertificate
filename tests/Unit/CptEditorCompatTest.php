<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\CptEditorCompat;

/**
 * Tests for the #739 CPT-decoupling deprecation shim.
 *
 * @covers \FreeFormCertificate\Admin\CptEditorCompat
 */
class CptEditorCompatTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		class_exists( '\\FreeFormCertificate\\Admin\\CptEditorCompat' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_init_registers_filter_and_notice(): void {
		$hooks = array();
		Functions\when( 'add_filter' )->alias(
			static function ( $hook ) use ( &$hooks ) {
				$hooks[] = $hook;
				return true;
			}
		);
		Functions\when( 'add_action' )->alias(
			static function ( $hook ) use ( &$hooks ) {
				$hooks[] = $hook;
				return true;
			}
		);

		CptEditorCompat::init();

		$this->assertContains( 'user_has_cap', $hooks );
		$this->assertContains( 'admin_notices', $hooks );
	}

	public function test_grants_shimmed_caps_to_editors(): void {
		$allcaps = array( 'edit_others_posts' => true );

		$forms = CptEditorCompat::grant_to_editors( $allcaps, array( 'ffc_manage_forms' ), array() );
		$this->assertTrue( $forms['ffc_manage_forms'] );

		$cals = CptEditorCompat::grant_to_editors( $allcaps, array( 'ffc_manage_calendars' ), array() );
		$this->assertTrue( $cals['ffc_manage_calendars'] );
	}

	public function test_does_not_grant_to_non_editors(): void {
		$out = CptEditorCompat::grant_to_editors( array(), array( 'ffc_manage_forms' ), array() );
		$this->assertArrayNotHasKey( 'ffc_manage_forms', $out );
	}

	public function test_ignores_unrelated_cap_checks(): void {
		$allcaps = array( 'edit_others_posts' => true );
		$out     = CptEditorCompat::grant_to_editors( $allcaps, array( 'ffc_manage_settings' ), array() );
		$this->assertArrayNotHasKey( 'ffc_manage_settings', $out );
		$this->assertArrayNotHasKey( 'ffc_manage_forms', $out );
	}

	public function test_notice_skips_for_non_admin(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		ob_start();
		CptEditorCompat::render_notice();
		$this->assertSame( '', ob_get_clean() );
	}
}
