<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\CertTemplateReceiptSettings;
use FreeFormCertificate\Admin\CertTemplateReceiptResolver;
use FreeFormCertificate\Admin\CertTemplateCpt;

/**
 * Tests for the appointment-receipt template selector tab (#945; #951 turned it
 * into a pure selector — editing/creating happens in the hub).
 *
 * @covers \FreeFormCertificate\Admin\CertTemplateReceiptSettings
 */
class CertTemplateReceiptSettingsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		class_exists( '\\FreeFormCertificate\\Admin\\CertTemplateReceiptSettings' );

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'admin_url' )->alias( static fn( $p = '' ) => 'https://example.com/wp-admin/' . $p );
		// Redirect terminates the handler — turn it into a catchable throw.
		Functions\when( 'wp_safe_redirect' )->alias(
			static function (): void {
				throw new \RuntimeException( 'redirected' );
			}
		);

		// Capabilities::current_user_can_admin_or() delegates to current_user_can();
		// stub that directly (avoids an alias mock colliding with the real class).
		Functions\when( 'current_user_can' )->justReturn( true );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_add_tab_inserts_receipt_tab(): void {
		$tabs = ( new CertTemplateReceiptSettings() )->add_tab(
			array( 'general' => array( 'label' => 'General', 'icon' => 'admin-generic' ) )
		);
		$this->assertArrayHasKey( 'receipt', $tabs );
		$this->assertSame( 'Receipt', $tabs['receipt']['label'] );
	}

	public function test_selected_id_reads_the_option_per_mode(): void {
		Functions\when( 'get_option' )->justReturn( array( 'regular' => 5, 'custom' => 9 ) );

		$this->assertSame( 5, CertTemplateReceiptSettings::selected_id( 'regular' ) );
		$this->assertSame( 9, CertTemplateReceiptSettings::selected_id( 'custom' ) );
	}

	public function test_selected_id_defaults_to_zero_when_unset(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$this->assertSame( 0, CertTemplateReceiptSettings::selected_id( 'regular' ) );
		$this->assertSame( 0, CertTemplateReceiptSettings::selected_id( 'custom' ) );
	}

	public function test_handle_save_persists_valid_receipt_ids(): void {
		// Both ids point at appointment-receipt templates → both kept.
		Functions\when( 'get_post_meta' )->justReturn( CertTemplateCpt::KIND_APPOINTMENT_RECEIPT );

		$_POST['ffc_receipt_regular'] = '5';
		$_POST['ffc_receipt_custom']  = '9';

		$saved = null;
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$saved ) {
				$saved = array( $key, $value );
				return true;
			}
		);

		try {
			( new CertTemplateReceiptSettings() )->handle_save();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirected', $e->getMessage() );
		}

		$this->assertSame( CertTemplateReceiptResolver::OPTION, $saved[0] );
		$this->assertSame( array( 'regular' => 5, 'custom' => 9 ), $saved[1] );

		unset( $_POST['ffc_receipt_regular'], $_POST['ffc_receipt_custom'] );
	}

	public function test_handle_save_drops_ids_of_the_wrong_kind(): void {
		// The selected ids are certificate templates, not receipts → coerced to 0.
		Functions\when( 'get_post_meta' )->justReturn( CertTemplateCpt::KIND_CERTIFICATE );

		$_POST['ffc_receipt_regular'] = '5';
		$_POST['ffc_receipt_custom']  = '9';

		$saved = null;
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$saved ) {
				$saved = array( $key, $value );
				return true;
			}
		);

		try {
			( new CertTemplateReceiptSettings() )->handle_save();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirected', $e->getMessage() );
		}

		$this->assertSame( array( 'regular' => 0, 'custom' => 0 ), $saved[1] );

		unset( $_POST['ffc_receipt_regular'], $_POST['ffc_receipt_custom'] );
	}
}
