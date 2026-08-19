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
		unset( $_GET['post'], $_POST['ffc_cert_template_nonce'], $_POST['ffc_template_html'], $_POST['ffc_template_visible'], $_POST['ffc_template_bg_image'] );
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
			array( 'cb', 'title', 'ffc_category', 'ffc_type', 'ffc_visible', 'date' ),
			$keys,
			'Category + Type + Visible columns sit immediately after the title, date stays last'
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

	public function test_render_column_category_reports_kind(): void {
		// #945: the Category column reports the template kind.
		Functions\when( 'get_post_meta' )->alias(
			static fn( $id, $key ) => ( CertTemplateCpt::META_KIND === $key && 1 === $id )
				? CertTemplateCpt::KIND_APPOINTMENT_RECEIPT
				: ''
		);
		$screen = new CertTemplateAdminScreen();

		$this->assertSame( 'Appointment receipt', $this->capture( fn() => $screen->render_column( 'ffc_category', 1 ) ) );
		// Absent kind meta reports the certificate category.
		$this->assertSame( 'Certificate', $this->capture( fn() => $screen->render_column( 'ffc_category', 2 ) ) );
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

	public function test_ajax_toggle_denies_without_cap(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'wp_send_json_error' )->alias(
			static function () {
				throw new \RuntimeException( 'json_error' );
			}
		);

		$this->expectException( \RuntimeException::class );
		( new CertTemplateAdminScreen() )->ajax_toggle_visibility();
	}

	public function test_ajax_toggle_sets_visibility_and_returns_success(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		Functions\when( 'get_post' )->justReturn( $this->pool_post( 9 ) );

		$written = array();
		Functions\when( 'update_post_meta' )->alias(
			static function ( $id, $key, $value ) use ( &$written ) {
				$written[] = array( $id, $key, $value );
				return true;
			}
		);
		$payload = null;
		Functions\when( 'wp_send_json_success' )->alias(
			static function ( $data ) use ( &$payload ) {
				$payload = $data;
				throw new \RuntimeException( 'json_success' );
			}
		);

		$_POST['post_id'] = '9';
		$_POST['visible'] = '1';

		try {
			( new CertTemplateAdminScreen() )->ajax_toggle_visibility();
		} catch ( \RuntimeException $e ) {
			// Expected — wp_send_json_success throws in place of the terminal exit.
		}

		$this->assertContains( array( 9, CertTemplateCpt::META_VISIBLE, '1' ), $written );
		$this->assertSame( array( 'visible' => true ), $payload );

		unset( $_POST['post_id'], $_POST['visible'] );
	}

	// ==================================================================
	// Edit-screen metabox (#865)
	// ==================================================================

	public function test_add_edit_metabox_registers_body_and_options_boxes(): void {
		$captured = array();
		Functions\when( 'add_meta_box' )->alias(
			static function ( $id, $title, $cb, $screen, $context = 'advanced' ) use ( &$captured ) {
				$captured[] = array( $id, $screen, $context );
			}
		);
		Functions\when( 'get_post_meta' )->justReturn( '' ); // not a default → no box removal
		Functions\expect( 'remove_meta_box' )->never();

		( new CertTemplateAdminScreen() )->add_edit_metabox( $this->pool_post( 5 ) );

		$this->assertCount( 2, $captured );
		// The body editor box (normal context) + the sidebar options box (side).
		$this->assertSame( 'ffc_cert_template_body', $captured[0][0] );
		$this->assertSame( CertTemplateCpt::POST_TYPE, $captured[0][1] );
		$this->assertSame( 'normal', $captured[0][2] );
		$this->assertSame( 'ffc_cert_template_options', $captured[1][0] );
		$this->assertSame( 'side', $captured[1][2] );
	}

	public function test_add_edit_metabox_removes_publish_box_for_default(): void {
		Functions\when( 'add_meta_box' )->justReturn( null );
		Functions\when( 'get_post_meta' )->justReturn( '1' ); // META_IS_DEFAULT → default

		$removed = array();
		Functions\when( 'remove_meta_box' )->alias(
			static function ( $id ) use ( &$removed ) {
				$removed[] = $id;
			}
		);

		( new CertTemplateAdminScreen() )->add_edit_metabox( $this->pool_post( 7 ) );

		// A shipped default drops the Publish/Update box (nothing but the toggle
		// is editable, and the toggle autosaves over AJAX).
		$this->assertContains( 'submitdiv', $removed );
	}

	public function test_render_edit_metabox_outputs_editor_bg_field_and_image_tools(): void {
		Functions\when( 'esc_html_e' )->alias( static fn( $t ) => print( $t ) );
		Functions\when( 'esc_textarea' )->returnArg();
		Functions\when( 'wp_nonce_field' )->alias( static fn() => print( '<input name="ffc_cert_template_nonce" />' ) );
		// Non-default (META_IS_DEFAULT is not '1') → editable, so the image
		// toolbar + editable bg field render.
		Functions\when( 'get_post_meta' )->justReturn( '<div>Body</div>' );

		$html = $this->capture(
			fn() => ( new CertTemplateAdminScreen() )->render_edit_metabox( $this->pool_post( 5 ) )
		);

		$this->assertStringContainsString( 'name="ffc_template_html"', $html );
		$this->assertStringContainsString( '<div>Body</div>', $html );
		$this->assertStringContainsString( 'name="ffc_template_bg_image"', $html );
		$this->assertStringContainsString( 'id="ffc_btn_media_lib"', $html );
		$this->assertStringContainsString( 'id="ffc_btn_insert_image"', $html );
		$this->assertStringContainsString( 'name="ffc_cert_template_nonce"', $html );
		// The visibility control moved out of the body box into the sidebar box.
		$this->assertStringNotContainsString( 'name="ffc_template_visible"', $html );
	}

	public function test_render_options_metabox_outputs_visibility_toggle(): void {
		Functions\when( 'esc_html_e' )->alias( static fn( $t ) => print( $t ) );
		Functions\when( 'checked' )->justReturn( 'checked' );
		Functions\when( 'get_post_meta' )->justReturn( '1' );

		$html = $this->capture(
			fn() => ( new CertTemplateAdminScreen() )->render_options_metabox( $this->pool_post( 5 ) )
		);

		$this->assertStringContainsString( 'name="ffc_template_visible"', $html );
		$this->assertStringContainsString( 'ffc-toggle', $html );
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

	public function test_save_edit_metabox_persists_background_image(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'wp_kses' )->alias( static fn( $html ) => $html );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'get_post' )->justReturn( $this->pool_post( 5 ) );
		// Non-default → editable.
		Functions\when( 'get_post_meta' )->justReturn( '' );

		$written = array();
		Functions\when( 'update_post_meta' )->alias(
			static function ( $id, $key, $value ) use ( &$written ) {
				$written[] = array( $id, $key, $value );
				return true;
			}
		);

		$_POST['ffc_cert_template_nonce'] = 'n';
		$_POST['ffc_template_html']       = '<div>Body</div>';
		$_POST['ffc_template_bg_image']   = 'https://example.com/bg.png';

		( new CertTemplateAdminScreen() )->save_edit_metabox( 5, $this->pool_post( 5 ) );

		$this->assertContains( array( 5, CertTemplateCpt::META_BG_IMAGE, 'https://example.com/bg.png' ), $written );
	}

	public function test_save_edit_metabox_stamps_the_add_new_kind_preset(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'wp_kses' )->alias( static fn( $html ) => $html );
		Functions\when( 'get_post' )->justReturn( $this->pool_post( 5 ) );
		Functions\when( 'get_post_meta' )->justReturn( '' );

		$written = array();
		Functions\when( 'update_post_meta' )->alias(
			static function ( $id, $key, $value ) use ( &$written ) {
				$written[] = array( $id, $key, $value );
				return true;
			}
		);

		$_POST['ffc_cert_template_nonce'] = 'n';
		$_POST['ffc_template_html']       = '<div>Body</div>';
		$_POST['ffc_template_kind']       = CertTemplateCpt::KIND_APPOINTMENT_RECEIPT;

		( new CertTemplateAdminScreen() )->save_edit_metabox( 5, $this->pool_post( 5 ) );

		$this->assertContains( array( 5, CertTemplateCpt::META_KIND, CertTemplateCpt::KIND_APPOINTMENT_RECEIPT ), $written );

		unset( $_POST['ffc_template_kind'] );
	}

	public function test_save_edit_metabox_ignores_an_unknown_kind_preset(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'wp_kses' )->alias( static fn( $html ) => $html );
		Functions\when( 'get_post' )->justReturn( $this->pool_post( 5 ) );
		Functions\when( 'get_post_meta' )->justReturn( '' );

		$written = array();
		Functions\when( 'update_post_meta' )->alias(
			static function ( $id, $key, $value ) use ( &$written ) {
				$written[] = array( $id, $key, $value );
				return true;
			}
		);

		$_POST['ffc_cert_template_nonce'] = 'n';
		$_POST['ffc_template_html']       = '<div>Body</div>';
		$_POST['ffc_template_kind']       = 'bogus';

		( new CertTemplateAdminScreen() )->save_edit_metabox( 5, $this->pool_post( 5 ) );

		foreach ( $written as $w ) {
			$this->assertNotSame( CertTemplateCpt::META_KIND, $w[1] );
		}

		unset( $_POST['ffc_template_kind'] );
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
		$this->assertNotContains( CertTemplateCpt::META_BG_IMAGE, $keys, 'a default background is never overwritten' );
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

	// ==================================================================
	// Management actions: Duplicate / Export / Restore defaults (#865)
	// ==================================================================

	public function test_row_actions_adds_duplicate_and_export_for_manager(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_post_meta' )->justReturn( '1' );
		$screen = new CertTemplateAdminScreen();

		$actions = $screen->row_actions( array( 'edit' => 'e' ), $this->pool_post( 7 ) );

		$this->assertArrayHasKey( 'ffc_duplicate', $actions );
		$this->assertArrayHasKey( 'ffc_export', $actions );
		$this->assertStringContainsString( 'Duplicate', $actions['ffc_duplicate'] );
		$this->assertStringContainsString( 'Export', $actions['ffc_export'] );
	}

	public function test_handle_duplicate_creates_copy_and_redirects(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		Functions\when( 'check_admin_referer' )->justReturn( true );

		$src             = $this->pool_post( 5 );
		$src->post_title = 'My Template';
		Functions\when( 'get_post' )->justReturn( $src );
		Functions\when( 'get_post_meta' )->justReturn( '<div>Body</div>' );

		// CertTemplateWriter::create → wp_insert_post + update_post_meta.
		$created_title = null;
		Functions\when( 'wp_insert_post' )->alias(
			static function ( $arr ) use ( &$created_title ) {
				$created_title = $arr['post_title'];
				return 99;
			}
		);
		Functions\when( 'update_post_meta' )->justReturn( true );

		$redirected = null;
		Functions\when( 'wp_safe_redirect' )->alias(
			static function ( $url ) use ( &$redirected ) {
				$redirected = $url;
				throw new \RuntimeException( 'redirect' );
			}
		);

		$_GET['post'] = '5';
		try {
			( new CertTemplateAdminScreen() )->handle_duplicate();
		} catch ( \RuntimeException $e ) {
			// Expected — the redirect throws in place of exit.
		}

		$this->assertSame( 'My Template (copy)', $created_title );
		$this->assertStringContainsString( 'post=99', (string) $redirected );
	}

	public function test_handle_export_emits_download(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'sanitize_file_name' )->alias( static fn( $s ) => strtolower( str_replace( ' ', '-', (string) $s ) ) );

		$post             = $this->pool_post( 5 );
		$post->post_title = 'My Template';
		Functions\when( 'get_post' )->justReturn( $post );
		Functions\when( 'get_post_meta' )->justReturn( '<div>Body</div>' );

		$_GET['post'] = '5';
		$screen       = new TestableCertTemplateAdminScreen();
		try {
			$screen->handle_export();
		} catch ( \RuntimeException $e ) {
			// Expected — emit_download override throws in place of header()/exit.
		}

		$this->assertSame( 'my-template.html', $screen->emitted_filename );
		$this->assertSame( '<div>Body</div>', $screen->emitted_body );
	}

	public function test_handle_restore_denies_without_cap(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'wp_die' )->alias(
			static function () {
				throw new \RuntimeException( 'wp_die' );
			}
		);

		$this->expectException( \RuntimeException::class );
		( new CertTemplateAdminScreen() )->handle_restore_defaults();
	}

	public function test_render_restore_button_only_on_our_cpt_for_manager(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$screen = new CertTemplateAdminScreen();

		$out = $this->capture( fn() => $screen->render_restore_button( CertTemplateCpt::POST_TYPE ) );
		$this->assertStringContainsString( 'Restore defaults', $out );
		$this->assertStringContainsString( 'class="button"', $out );

		$other = $this->capture( fn() => $screen->render_restore_button( 'page' ) );
		$this->assertSame( '', $other );
	}

	public function test_render_restore_button_hidden_without_cap(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$screen = new CertTemplateAdminScreen();

		$out = $this->capture( fn() => $screen->render_restore_button( CertTemplateCpt::POST_TYPE ) );
		$this->assertSame( '', $out );
	}

	public function test_render_options_metabox_new_template_defaults_visible(): void {
		// The visibility toggle lives in the sidebar "Template options" metabox
		// (moved out of the HTML-body metabox); assert its default-visible state
		// for a new auto-draft there.
		Functions\when( 'esc_html_e' )->alias( static fn( $t ) => print( $t ) );
		Functions\when( 'get_post_meta' )->justReturn( '' ); // no stored visibility yet
		Functions\when( 'checked' )->alias(
			static function ( $a, $b = true ) {
				// phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- mirrors WP core checked() loose comparison.
				return print( $a == $b ? 'checked' : '' );
			}
		);

		$new              = $this->pool_post( 0 );
		$new->post_status = 'auto-draft';

		$html = $this->capture(
			fn() => ( new CertTemplateAdminScreen() )->render_options_metabox( $new )
		);

		$this->assertStringContainsString( 'checked', $html, 'a new (auto-draft) template is visible by default' );
	}

	// ==================================================================
	// Sample-data preview (#865)
	// ==================================================================

	public function test_row_actions_adds_preview_even_for_view_only(): void {
		// View-only (no manage cap): toggle/duplicate/export are skipped, but the
		// read-only Preview link is still present.
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'get_post_meta' )->justReturn( '' ); // not a default
		$screen = new CertTemplateAdminScreen();

		$actions = $screen->row_actions( array( 'edit' => 'e' ), $this->pool_post( 7 ) );

		$this->assertArrayHasKey( 'ffc_preview', $actions );
		$this->assertStringContainsString( 'ffc-template-preview', $actions['ffc_preview'] );
		$this->assertArrayNotHasKey( 'ffc_toggle_visibility', $actions );
		$this->assertArrayNotHasKey( 'ffc_duplicate', $actions );
	}

	public function test_handle_preview_renders_sample_and_emits(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		Functions\when( 'check_admin_referer' )->justReturn( true );

		$post             = $this->pool_post( 5 );
		$post->post_title = 'My Template';
		Functions\when( 'get_post' )->justReturn( $post );
		Functions\when( 'get_post_meta' )->justReturn( '<div>Body</div>' );

		$_GET['post'] = '5';
		$screen       = new TestableCertTemplateAdminScreen();
		try {
			$screen->handle_preview();
		} catch ( \RuntimeException $e ) {
			// Expected — emit_preview_page override throws in place of header()/exit.
		}

		$this->assertSame( 'My Template', $screen->preview_title );
		$this->assertSame( '<rendered><div>Body</div></rendered>', $screen->preview_body );
	}

	public function test_handle_preview_denies_without_view_cap(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'wp_die' )->alias(
			static function () {
				throw new \RuntimeException( 'wp_die' );
			}
		);

		$this->expectException( \RuntimeException::class );
		( new CertTemplateAdminScreen() )->handle_preview();
	}

	public function test_enqueue_preview_assets_gated_to_pool_list(): void {
		$enqueued = array();
		Functions\when( 'wp_enqueue_script' )->alias(
			static function ( $handle ) use ( &$enqueued ) {
				$enqueued[] = $handle;
			}
		);
		Functions\when( 'wp_localize_script' )->justReturn( true );

		// Wrong hook → never reaches the screen check, no enqueue.
		( new CertTemplateAdminScreen() )->enqueue_preview_assets( 'post.php' );
		$this->assertSame( array(), $enqueued );

		// Right hook + our CPT screen → enqueues the overlay script.
		$screen            = new \WP_Screen();
		$screen->post_type = CertTemplateCpt::POST_TYPE;
		Functions\when( 'get_current_screen' )->justReturn( $screen );

		( new CertTemplateAdminScreen() )->enqueue_preview_assets( 'edit.php' );
		$this->assertContains( 'ffc-cert-template-preview', $enqueued );
	}

	public function test_enqueue_edit_assets_mounts_code_editor_on_edit_screen(): void {
		$scripts = array();
		Functions\when( 'wp_enqueue_style' )->justReturn( true );
		Functions\when( 'wp_enqueue_media' )->justReturn( true ); // image-tools path (non-default) mounts wp.media.
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce' ); // ffc_ajax localization in enqueue_image_tools().
		Functions\when( 'wp_enqueue_script' )->alias(
			static function ( $handle ) use ( &$scripts ) {
				$scripts[] = $handle;
				return true;
			}
		);
		Functions\when( 'wp_localize_script' )->justReturn( true );
		Functions\when( 'wp_enqueue_code_editor' )->justReturn( array( 'codemirror' => array() ) );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'absint' )->alias( static fn( $v ) => (int) $v );
		Functions\when( 'get_post_meta' )->justReturn( '' ); // is_default → editable

		// Wrong screen base → returns early, nothing mounted.
		$wrong            = new \WP_Screen();
		$wrong->base      = 'edit';
		$wrong->post_type = CertTemplateCpt::POST_TYPE;
		Functions\when( 'get_current_screen' )->justReturn( $wrong );
		( new CertTemplateAdminScreen() )->enqueue_edit_assets();
		$this->assertNotContains( 'ffc-admin-code-editor', $scripts );

		// The CPT edit screen (post base + our CPT) mounts the shared editor.
		$_GET['post']      = 5;
		$screen            = new \WP_Screen();
		$screen->base      = 'post';
		$screen->post_type = CertTemplateCpt::POST_TYPE;
		Functions\when( 'get_current_screen' )->justReturn( $screen );
		( new CertTemplateAdminScreen() )->enqueue_edit_assets();
		$this->assertContains( 'ffc-admin-code-editor', $scripts );
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

/**
 * Subclass that intercepts the terminal file-download so the export handler can
 * be asserted without emitting real headers or calling exit.
 */
class TestableCertTemplateAdminScreen extends CertTemplateAdminScreen {

	/** @var string */
	public string $emitted_filename = '';

	/** @var string */
	public string $emitted_body = '';

	/** @var string */
	public string $preview_title = '';

	/** @var string */
	public string $preview_body = '';

	protected function emit_download( string $filename, string $body ): void {
		$this->emitted_filename = $filename;
		$this->emitted_body     = $body;
		throw new \RuntimeException( 'emit' );
	}

	protected function render_sample( string $template_html, string $title ): string {
		// Stub the real PdfHtmlRenderer/CertificatePreviewSamples pipeline.
		return '<rendered>' . $template_html . '</rendered>';
	}

	protected function emit_preview_page( string $title, string $body ): void {
		$this->preview_title = $title;
		$this->preview_body  = $body;
		throw new \RuntimeException( 'preview' );
	}
}
