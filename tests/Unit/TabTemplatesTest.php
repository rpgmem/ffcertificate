<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Settings\Tabs\TabTemplates;
use FreeFormCertificate\Admin\CertTemplateCpt;

/**
 * @covers \FreeFormCertificate\Settings\Tabs\TabTemplates
 */
class TabTemplatesTest extends TestCase {

	private TabTemplates $tab;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		class_exists( '\\FreeFormCertificate\\Settings\\Tabs\\TabTemplates' );

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( static function ( $t ) { echo $t; } );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'admin_url' )->alias( static fn( $p = '' ) => 'https://example.com/wp-admin/' . $p );

		$this->tab = new TabTemplates();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_tab_identity(): void {
		$this->assertSame( 'templates', $this->tab->get_id() );
		$this->assertSame( 'Document Templates', $this->tab->get_title() );
		$this->assertSame( 'ffc-icon-doc', $this->tab->get_icon() );
	}

	public function test_gated_by_forms_caps(): void {
		$this->assertSame( 'ffc_view_forms', $this->tab->get_view_cap() );
		$this->assertSame( 'ffc_manage_forms', $this->tab->get_manage_cap() );
	}

	public function test_extends_settings_tab(): void {
		$this->assertInstanceOf( \FreeFormCertificate\Settings\SettingsTab::class, $this->tab );
	}

	public function test_render_links_into_the_hub_and_shows_new_buttons_for_managers(): void {
		Functions\when( 'current_user_can' )->justReturn( true ); // admin → can manage.

		ob_start();
		$this->tab->render();
		$out = ob_get_clean();

		// Manage links to the native list, per kind.
		$this->assertStringContainsString( 'edit.php?post_type=' . CertTemplateCpt::POST_TYPE, $out );
		$this->assertStringContainsString( 'ffc_kind=' . CertTemplateCpt::KIND_APPOINTMENT_RECEIPT, $out );
		$this->assertStringContainsString( 'ffc_kind=' . CertTemplateCpt::KIND_CERTIFICATE, $out );
		// New-template links (kind preset) present for a manager.
		$this->assertStringContainsString( 'post-new.php?post_type=' . CertTemplateCpt::POST_TYPE, $out );
	}

	public function test_render_hides_new_buttons_for_view_only(): void {
		Functions\when( 'current_user_can' )->justReturn( false ); // not admin, no manage cap.

		ob_start();
		$this->tab->render();
		$out = ob_get_clean();

		// The read-only viewer still gets Manage links but no "post-new" create links.
		$this->assertStringContainsString( 'edit.php?post_type=' . CertTemplateCpt::POST_TYPE, $out );
		$this->assertStringNotContainsString( 'post-new.php', $out );
	}
}
