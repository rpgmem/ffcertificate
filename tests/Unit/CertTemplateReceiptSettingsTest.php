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

	public function test_constructor_registers_the_tab_renderer_and_save_handler(): void {
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

		new CertTemplateReceiptSettings();

		$this->assertSame(
			array( 'ffc_scheduling_settings_tabs', 'ffc_scheduling_settings_render_tab_receipt', 'admin_post_ffc_save_receipt_templates' ),
			$hooks
		);
	}

	public function test_selected_id_is_zero_for_a_corrupt_option(): void {
		Functions\when( 'get_option' )->justReturn( 'not-an-array' );

		$this->assertSame( 0, CertTemplateReceiptSettings::selected_id( 'custom' ) );
	}

	public function test_handle_save_refuses_without_the_manage_cap(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'wp_die' )->alias(
			static function ( $message ) {
				throw new \DomainException( (string) $message );
			}
		);
		$saved = false;
		Functions\when( 'update_option' )->alias(
			static function () use ( &$saved ) {
				$saved = true;
				return true;
			}
		);

		try {
			( new CertTemplateReceiptSettings() )->handle_save();
			$this->fail( 'handle_save did not refuse.' );
		} catch ( \DomainException $e ) {
			$this->assertStringContainsString( 'permission', $e->getMessage() );
		}
		$this->assertFalse( $saved );
	}

	public function test_handle_save_stores_zero_for_unselected_modes(): void {
		$saved = null;
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$saved ) {
				$saved = $value;
				return true;
			}
		);
		$redirect = null;
		Functions\when( 'wp_safe_redirect' )->alias(
			static function ( $url ) use ( &$redirect ): void {
				$redirect = $url;
				throw new \RuntimeException( 'redirected' );
			}
		);

		try {
			( new CertTemplateReceiptSettings() )->handle_save();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirected', $e->getMessage() );
		}

		$this->assertSame( array( 'regular' => 0, 'custom' => 0 ), $saved );
		$this->assertStringContainsString( 'page=ffc-scheduling-settings&tab=receipt&ffc_saved=1', (string) $redirect );
	}

	/**
	 * Stub the markup helpers render_tab() echoes through.
	 */
	private function stub_render(): void {
		Functions\when( 'esc_html_e' )->alias( static fn( $t ) => print( $t ) );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_nonce_field' )->alias( static fn( $a ) => print( '<input type="hidden" name="_wpnonce" data-action="' . $a . '">' ) );
		Functions\when( 'submit_button' )->alias( static fn( $t ) => print( '<button type="submit">' . $t . '</button>' ) );
		// selected() echoes by default, which is how the selector calls it.
		Functions\when( 'selected' )->alias( static fn( $a, $b ) => print( (string) $a === (string) $b ? ' selected' : '' ) );
	}

	/**
	 * Capture echoed output.
	 */
	private function capture( callable $fn ): string {
		ob_start();
		$fn();
		return (string) ob_get_clean();
	}

	public function test_render_tab_refuses_without_the_manage_cap(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\expect( 'get_posts' )->never();

		$html = $this->capture( fn() => ( new CertTemplateReceiptSettings() )->render_tab() );

		$this->assertSame( '<p>You do not have permission to manage these settings.</p>', $html );
	}

	public function test_render_tab_lists_receipt_templates_per_mode_with_edit_link(): void {
		$this->stub_render();
		$_GET['ffc_saved'] = '1';
		$notices           = array();
		Functions\when( 'wp_admin_notice' )->alias(
			static function ( $message, $args ) use ( &$notices ) {
				$notices[] = array( $message, $args['type'] );
			}
		);
		// Regular selects the editable user template 12, Custom the seeded default 11.
		Functions\when( 'get_option' )->justReturn(
			array(
				'regular' => 12,
				'custom'  => 11,
			)
		);
		$queried = null;
		Functions\when( 'get_posts' )->alias(
			function ( $args ) use ( &$queried ) {
				$queried = $args;
				$default             = new \WP_Post();
				$default->ID         = 11;
				$default->post_title = 'Standard receipt';
				$mine                = new \WP_Post();
				$mine->ID            = 12;
				$mine->post_title    = 'My receipt';
				return array( $mine, $default );
			}
		);
		Functions\when( 'get_post_meta' )->alias(
			static fn( $id, $key ) => ( CertTemplateCpt::META_IS_DEFAULT === $key && 11 === $id ) ? '1' : ''
		);

		$html = $this->capture( fn() => ( new CertTemplateReceiptSettings() )->render_tab() );
		unset( $_GET['ffc_saved'] );

		$this->assertSame( array( array( 'Appointment receipt templates saved.', 'success' ) ), $notices );
		$this->assertContains(
			array(
				'key'   => CertTemplateCpt::META_KIND,
				'value' => CertTemplateCpt::KIND_APPOINTMENT_RECEIPT,
			),
			$queried['meta_query']
		);
		$this->assertStringContainsString( 'name="ffc_receipt_regular"', $html );
		$this->assertStringContainsString( 'name="ffc_receipt_custom"', $html );
		$this->assertStringContainsString( 'Standard receipt (default)', $html );
		$this->assertStringContainsString( 'data-action="ffc_receipt_templates"', $html );
		$this->assertStringContainsString( 'value="ffc_save_receipt_templates"', $html );
		$this->assertStringContainsString( 'post-new.php?post_type=' . CertTemplateCpt::POST_TYPE . '&ffc_kind=' . CertTemplateCpt::KIND_APPOINTMENT_RECEIPT, $html );
		$this->assertStringContainsString( 'edit.php?post_type=' . CertTemplateCpt::POST_TYPE . '&ffc_kind=' . CertTemplateCpt::KIND_APPOINTMENT_RECEIPT, $html );
		// Only the editable selection (12) gets an Edit link; the default (11) does not.
		$this->assertSame( 1, substr_count( $html, 'Edit this template' ) );
		$this->assertStringContainsString( 'post.php?post=12&action=edit', $html );
		$this->assertMatchesRegularExpression( '/<option value="12" selected>\s*My receipt/', $html );
	}

	public function test_render_tab_without_saved_flag_shows_no_notice(): void {
		$this->stub_render();
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'get_posts' )->justReturn( array() );
		Functions\expect( 'wp_admin_notice' )->never();

		$html = $this->capture( fn() => ( new CertTemplateReceiptSettings() )->render_tab() );

		$this->assertMatchesRegularExpression( '/<option value="0" selected>Shipped default/', $html );
		$this->assertStringNotContainsString( 'Edit this template', $html );
	}
}
