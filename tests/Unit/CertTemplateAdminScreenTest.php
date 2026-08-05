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
	}

	protected function tearDown(): void {
		unset( $_GET['post'] );
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

	/**
	 * Capture echoed output of a callable.
	 */
	private function capture( callable $fn ): string {
		ob_start();
		$fn();
		return (string) ob_get_clean();
	}
}
