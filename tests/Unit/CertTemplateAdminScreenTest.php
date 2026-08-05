<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\CertTemplateAdminScreen;
use FreeFormCertificate\Admin\CertTemplateCpt;

/**
 * Tests for the pool management list-table screen (#865).
 *
 * @covers \FreeFormCertificate\Admin\CertTemplateAdminScreen
 */
class CertTemplateAdminScreenTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		class_exists( '\\FreeFormCertificate\\Admin\\CertTemplateAdminScreen' );

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'admin_url' )->returnArg();
		Functions\when( 'wp_nonce_url' )->alias( static fn( $url ) => $url );
		// render_edit_metabox marks a default's textarea readonly (#865 #11).
		Functions\when( 'wp_readonly' )->justReturn( null );
	}

	protected function tearDown(): void {
		unset( $_GET['post'], $_POST['ffc_cert_template_nonce'], $_POST['ffc_template_html'], $_POST['ffc_template_visible'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	private function pool_post( int $id ): \WP_Post {
		$post            = new \WP_Post();
		$post->ID        = $id;
		$post->post_type = CertTemplateCpt::POST_TYPE;
		return $post;
	}

	public function test_columns_inserts_type_and_visible_after_title(): void {
		$screen = new CertTemplateAdminScreen();
		$out    = $screen->columns(
			array(
				'cb'    => '<input />',
				'title' => 'Title',
				'date'  => 'Date',
			)
		);

		$keys = array_keys( $out );
		$this->assertSame(
			array( 'cb', 'title', 'ffc_type', 'ffc_visible', 'date' ),
			$keys,
			'Type + Visible columns sit immediately after the title, date stays last'
		);
	}

	public function test_render_column_type_reports_default_vs_custom(): void {
		Functions\when( 'get_post_meta' )->alias(
			static fn( $id, $key ) => ( CertTemplateCpt::META_IS_DEFAULT === $key && 1 === $id ) ? '1' : ''
		);
		$screen = new CertTemplateAdminScreen();

		$this->assertSame( 'Default', $this->capture( fn() => $screen->render_column( 'ffc_type', 1 ) ) );
		$this->assertSame( 'Custom', $this->capture( fn() => $screen->render_column( 'ffc_type', 2 ) ) );
	}

	public function test_render_column_visible_reflects_meta(): void {
		Functions\when( 'get_post_meta' )->alias(
			static fn( $id, $key ) => ( CertTemplateCpt::META_VISIBLE === $key && 1 === $id ) ? '1' : ''
		);
		$screen = new CertTemplateAdminScreen();

		$this->assertSame( 'Visible', $this->capture( fn() => $screen->render_column( 'ffc_visible', 1 ) ) );
		$this->assertSame( 'Hidden', $this->capture( fn() => $screen->render_column( 'ffc_visible', 2 ) ) );
	}

	public function test_row_actions_adds_toggle_for_manager(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_post_meta' )->justReturn( '1' ); // visible → "Hide"
		$screen = new CertTemplateAdminScreen();

		$actions = $screen->row_actions( array( 'edit' => 'e' ), $this->pool_post( 7 ) );

		$this->assertArrayHasKey( 'ffc_toggle_visibility', $actions );
		$this->assertStringContainsString( 'Hide', $actions['ffc_toggle_visibility'] );
	}

	public function test_row_actions_removes_delete_for_defaults(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		// META_IS_DEFAULT → '1' so the row is a shipped default; visible flag '1'.
		Functions\when( 'get_post_meta' )->justReturn( '1' );
		$screen = new CertTemplateAdminScreen();

		$actions = $screen->row_actions(
			array(
				'edit'   => 'e',
				'trash'  => 't',
				'delete' => 'd',
			),
			$this->pool_post( 3 )
		);

		$this->assertArrayNotHasKey( 'trash', $actions );
		$this->assertArrayNotHasKey( 'delete', $actions );
	}

	public function test_row_actions_no_toggle_without_manage_cap(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'get_post_meta' )->justReturn( '' ); // not a default
		$screen = new CertTemplateAdminScreen();

		$actions = $screen->row_actions( array( 'edit' => 'e' ), $this->pool_post( 7 ) );

		$this->assertArrayNotHasKey( 'ffc_toggle_visibility', $actions );
	}

	public function test_row_actions_ignores_other_post_types(): void {
		$screen           = new CertTemplateAdminScreen();
		$other            = new \WP_Post();
		$other->ID        = 1;
		$other->post_type = 'page';

		$actions = $screen->row_actions( array( 'edit' => 'e' ), $other );
		$this->assertSame( array( 'edit' => 'e' ), $actions );
	}

	public function test_handle_toggle_denies_without_cap(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'wp_die' )->alias(
			static function () {
				throw new \RuntimeException( 'wp_die' );
			}
		);

		$screen = new CertTemplateAdminScreen();
		$this->expectException( \RuntimeException::class );
		$screen->handle_toggle_visibility();
	}

	public function test_handle_toggle_flips_visibility(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'get_post' )->justReturn( $this->pool_post( 5 ) );
		// Currently visible → the handler should flip it to hidden ('0').
		Functions\when( 'get_post_meta' )->justReturn( '1' );

		$written = array();
		Functions\when( 'update_post_meta' )->alias(
			static function ( $id, $key, $value ) use ( &$written ) {
				$written[] = array( $id, $key, $value );
				return true;
			}
		);
		// Stop before the terminal exit by throwing from the redirect.
		Functions\when( 'wp_safe_redirect' )->alias(
			static function () {
				throw new \RuntimeException( 'redirect' );
			}
		);

		$_GET['post'] = '5';
		$screen       = new CertTemplateAdminScreen();

		try {
			$screen->handle_toggle_visibility();
		} catch ( \RuntimeException $e ) {
			// Expected — the redirect double throws in place of exit.
		}

		$this->assertContains( array( 5, CertTemplateCpt::META_VISIBLE, '0' ), $written );
	}

	// ==================================================================
	// Edit-screen metabox (#865)
	// ==================================================================

	public function test_add_edit_metabox_registers_box(): void {
		$captured = array();
		Functions\when( 'add_meta_box' )->alias(
			static function ( $id, $title, $cb, $screen ) use ( &$captured ) {
				$captured = array( $id, $screen );
			}
		);

		( new CertTemplateAdminScreen() )->add_edit_metabox();

		$this->assertSame( 'ffc_cert_template_body', $captured[0] );
		$this->assertSame( CertTemplateCpt::POST_TYPE, $captured[1] );
	}

	public function test_render_edit_metabox_outputs_editor_and_visibility(): void {
		Functions\when( 'esc_html_e' )->alias( static fn( $t ) => print( $t ) );
		Functions\when( 'esc_textarea' )->returnArg();
		Functions\when( 'checked' )->justReturn( '' );
		Functions\when( 'wp_nonce_field' )->alias( static fn() => print( '<input name="ffc_cert_template_nonce" />' ) );
		Functions\when( 'get_post_meta' )->justReturn( '<div>Body</div>' );

		$html = $this->capture(
			fn() => ( new CertTemplateAdminScreen() )->render_edit_metabox( $this->pool_post( 5 ) )
		);

		$this->assertStringContainsString( 'name="ffc_template_html"', $html );
		$this->assertStringContainsString( '<div>Body</div>', $html );
		$this->assertStringContainsString( 'name="ffc_template_visible"', $html );
		$this->assertStringContainsString( 'name="ffc_cert_template_nonce"', $html );
	}

	public function test_save_edit_metabox_persists_html_and_visibility(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'wp_kses' )->alias( static fn( $html ) => $html );
		Functions\when( 'get_post' )->justReturn( $this->pool_post( 5 ) );
		// A non-default template (is_default reads META_IS_DEFAULT): '' → editable.
		Functions\when( 'get_post_meta' )->justReturn( '' );

		$written = array();
		Functions\when( 'update_post_meta' )->alias(
			static function ( $id, $key, $value ) use ( &$written ) {
				$written[] = array( $id, $key, $value );
				return true;
			}
		);

		$_POST['ffc_cert_template_nonce'] = 'n';
		$_POST['ffc_template_html']       = '<div>New body</div>';
		$_POST['ffc_template_visible']    = '1';

		( new CertTemplateAdminScreen() )->save_edit_metabox( 5, $this->pool_post( 5 ) );

		$this->assertContains( array( 5, CertTemplateCpt::META_HTML, '<div>New body</div>' ), $written );
		$this->assertContains( array( 5, CertTemplateCpt::META_VISIBLE, '1' ), $written );
	}

	public function test_save_edit_metabox_bails_on_bad_nonce(): void {
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$updated = false;
		Functions\when( 'update_post_meta' )->alias(
			static function () use ( &$updated ) {
				$updated = true;
				return true;
			}
		);

		$_POST['ffc_cert_template_nonce'] = 'bad';
		( new CertTemplateAdminScreen() )->save_edit_metabox( 5, $this->pool_post( 5 ) );

		$this->assertFalse( $updated, 'no write on an invalid nonce' );
	}

	public function test_save_edit_metabox_ignores_other_post_types(): void {
		$updated = false;
		Functions\when( 'update_post_meta' )->alias(
			static function () use ( &$updated ) {
				$updated = true;
				return true;
			}
		);

		$other            = new \WP_Post();
		$other->ID        = 9;
		$other->post_type = 'page';
		( new CertTemplateAdminScreen() )->save_edit_metabox( 9, $other );

		$this->assertFalse( $updated );
	}

	// ==================================================================
	// Default templates are read-only (#865 decision #11)
	// ==================================================================

	public function test_protect_default_caps_denies_delete_of_default(): void {
		Functions\when( 'get_post' )->justReturn( $this->pool_post( 5 ) );
		Functions\when( 'get_post_meta' )->justReturn( '1' ); // META_IS_DEFAULT → default

		$out = ( new CertTemplateAdminScreen() )->protect_default_caps(
			array( 'ffc_manage_forms' ),
			'delete_post',
			1,
			array( 5 )
		);

		$this->assertSame( array( 'do_not_allow' ), $out );
	}

	public function test_protect_default_caps_allows_delete_of_user_template(): void {
		Functions\when( 'get_post' )->justReturn( $this->pool_post( 6 ) );
		Functions\when( 'get_post_meta' )->justReturn( '' ); // not a default

		$out = ( new CertTemplateAdminScreen() )->protect_default_caps(
			array( 'ffc_manage_forms' ),
			'delete_post',
			1,
			array( 6 )
		);

		$this->assertSame( array( 'ffc_manage_forms' ), $out );
	}

	public function test_protect_default_caps_ignores_non_delete_caps(): void {
		$out = ( new CertTemplateAdminScreen() )->protect_default_caps(
			array( 'ffc_manage_forms' ),
			'edit_post',
			1,
			array( 5 )
		);

		$this->assertSame( array( 'ffc_manage_forms' ), $out );
	}

	public function test_preserve_default_title_restores_shipped_title(): void {
		Functions\when( 'get_post_meta' )->justReturn( '1' ); // is_default true
		Functions\when( 'get_post_field' )->justReturn( 'Certificate model 1' );
		Functions\when( 'wp_slash' )->returnArg();

		$data = ( new CertTemplateAdminScreen() )->preserve_default_title(
			array( 'post_type' => CertTemplateCpt::POST_TYPE, 'post_title' => 'Renamed!' ),
			array( 'ID' => 5 )
		);

		$this->assertSame( 'Certificate model 1', $data['post_title'] );
	}

	public function test_preserve_default_title_leaves_user_template_untouched(): void {
		Functions\when( 'get_post_meta' )->justReturn( '' ); // not a default

		$data = ( new CertTemplateAdminScreen() )->preserve_default_title(
			array( 'post_type' => CertTemplateCpt::POST_TYPE, 'post_title' => 'My new name' ),
			array( 'ID' => 6 )
		);

		$this->assertSame( 'My new name', $data['post_title'] );
	}

	public function test_save_edit_metabox_skips_html_for_default(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'wp_kses' )->alias( static fn( $html ) => $html );
		Functions\when( 'get_post' )->justReturn( $this->pool_post( 5 ) );
		Functions\when( 'get_post_meta' )->justReturn( '1' ); // is_default true

		$written = array();
		Functions\when( 'update_post_meta' )->alias(
			static function ( $id, $key, $value ) use ( &$written ) {
				$written[] = array( $id, $key, $value );
				return true;
			}
		);

		$_POST['ffc_cert_template_nonce'] = 'n';
		$_POST['ffc_template_html']       = '<div>hacked default</div>';
		$_POST['ffc_template_visible']    = '1';

		( new CertTemplateAdminScreen() )->save_edit_metabox( 5, $this->pool_post( 5 ) );

		$keys = array_map( static fn( $w ) => $w[1], $written );
		$this->assertNotContains( CertTemplateCpt::META_HTML, $keys, 'a default HTML is never overwritten' );
		$this->assertContains( CertTemplateCpt::META_VISIBLE, $keys, 'visibility still applies to a default' );
	}

	public function test_render_edit_metabox_marks_default_readonly(): void {
		Functions\when( 'esc_html_e' )->alias( static fn( $t ) => print( $t ) );
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_textarea' )->returnArg();
		Functions\when( 'checked' )->justReturn( '' );
		Functions\when( 'wp_readonly' )->alias( static fn( $a ) => $a ? print( 'readonly' ) : null );
		Functions\when( 'wp_nonce_field' )->justReturn( null );
		Functions\when( 'get_post_meta' )->justReturn( '1' ); // is_default true

		$html = $this->capture(
			fn() => ( new CertTemplateAdminScreen() )->render_edit_metabox( $this->pool_post( 5 ) )
		);

		$this->assertStringContainsString( 'readonly', $html );
		$this->assertStringContainsString( 'read-only', $html );
	}

	/**
	 * Capture echoed output of a callable.
	 */
	private function capture( callable $fn ): string {
		ob_start();
		$fn();
		return (string) ob_get_clean();
	}
}
