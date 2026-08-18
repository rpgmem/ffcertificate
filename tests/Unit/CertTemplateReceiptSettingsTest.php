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
 * Tests for the appointment-receipt template selection page save handler (#945).
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
		Functions\when( 'check_ajax_referer' )->justReturn( true );
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

	public function test_ajax_load_returns_html_and_editable(): void {
		// A non-default receipt template → editable, its body returned.
		Functions\when( 'get_post_meta' )->alias(
			static function ( $id, $key ) {
				if ( CertTemplateCpt::META_KIND === $key ) {
					return CertTemplateCpt::KIND_APPOINTMENT_RECEIPT;
				}
				if ( CertTemplateCpt::META_HTML === $key ) {
					return '<div>body</div>';
				}
				return ''; // META_IS_DEFAULT → not a default.
			}
		);
		$post            = new \WP_Post();
		$post->post_type = CertTemplateCpt::POST_TYPE;
		Functions\when( 'get_post' )->justReturn( $post );

		$_POST['id'] = '5';
		$captured    = null;
		Functions\when( 'wp_send_json_success' )->alias(
			static function ( $data ) use ( &$captured ) {
				$captured = $data;
				throw new \RuntimeException( 'sent' );
			}
		);

		try {
			( new CertTemplateReceiptSettings() )->ajax_load();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'sent', $e->getMessage() );
		}

		$this->assertSame( '<div>body</div>', $captured['html'] );
		$this->assertTrue( $captured['editable'] );

		unset( $_POST['id'] );
	}

	public function test_ajax_duplicate_creates_editable_copy(): void {
		Functions\when( 'get_post_meta' )->alias(
			static function ( $id, $key ) {
				if ( CertTemplateCpt::META_HTML === $key ) {
					return '<div>src</div>';
				}
				if ( CertTemplateCpt::META_KIND === $key ) {
					return CertTemplateCpt::KIND_APPOINTMENT_RECEIPT;
				}
				return '';
			}
		);
		$post             = new \WP_Post();
		$post->post_type  = CertTemplateCpt::POST_TYPE;
		$post->post_title = 'Src';
		Functions\when( 'get_post' )->justReturn( $post );
		Functions\when( 'wp_insert_post' )->justReturn( 99 );
		Functions\when( 'update_post_meta' )->justReturn( true );

		$_POST['id'] = '5';
		$captured    = null;
		Functions\when( 'wp_send_json_success' )->alias(
			static function ( $data ) use ( &$captured ) {
				$captured = $data;
				throw new \RuntimeException( 'sent' );
			}
		);

		try {
			( new CertTemplateReceiptSettings() )->ajax_duplicate();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'sent', $e->getMessage() );
		}

		$this->assertSame( 99, $captured['id'] );
		$this->assertSame( '<div>src</div>', $captured['html'] );

		unset( $_POST['id'] );
	}

	public function test_handle_save_saves_edited_html_for_editable_selection(): void {
		// Selected id is a non-default receipt template → editable; its HTML is
		// persisted through wp_kses on save.
		Functions\when( 'get_post_meta' )->alias(
			static fn( $id, $key ) => CertTemplateCpt::META_KIND === $key ? CertTemplateCpt::KIND_APPOINTMENT_RECEIPT : ''
		);
		$post            = new \WP_Post();
		$post->post_type = CertTemplateCpt::POST_TYPE;
		Functions\when( 'get_post' )->justReturn( $post );
		Functions\when( 'wp_kses' )->returnArg();

		$saved_html = array();
		Functions\when( 'update_post_meta' )->alias(
			static function ( $id, $key, $value ) use ( &$saved_html ) {
				if ( CertTemplateCpt::META_HTML === $key ) {
					$saved_html[ $id ] = $value;
				}
				return true;
			}
		);
		Functions\when( 'update_option' )->justReturn( true );

		$_POST['ffc_receipt_regular']      = '5';
		$_POST['ffc_receipt_regular_html'] = '<div>edited</div>';
		$_POST['ffc_receipt_custom']       = '0';

		try {
			( new CertTemplateReceiptSettings() )->handle_save();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirected', $e->getMessage() );
		}

		$this->assertSame( '<div>edited</div>', $saved_html[5] ?? null );

		unset( $_POST['ffc_receipt_regular'], $_POST['ffc_receipt_regular_html'], $_POST['ffc_receipt_custom'] );
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
