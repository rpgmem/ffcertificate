<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Settings\Tabs\TabReregistration;
use FreeFormCertificate\Admin\CertTemplateRecordResolver;
use FreeFormCertificate\Admin\CertTemplateCpt;

/**
 * @covers \FreeFormCertificate\Settings\Tabs\TabReregistration
 */
class TabReregistrationTest extends TestCase {

	private TabReregistration $tab;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// `SettingsTab` requires core's formatting.php when `wp_kses_post()`
		// is missing, which is a file the unit suite does not have: without
		// this stub the class only loaded when an earlier test had defined
		// the function, so the file errored under `--filter` (#1887).
		Functions\when( 'wp_kses_post' )->returnArg();
		class_exists( '\\FreeFormCertificate\\Settings\\Tabs\\TabReregistration' );

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( static function ( $t ) { echo $t; } );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'admin_url' )->alias( static fn( $p = '' ) => 'https://example.com/wp-admin/' . $p );

		$this->tab = new TabReregistration();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_tab_identity(): void {
		$this->assertSame( 'reregistration', $this->tab->get_id() );
		$this->assertSame( 'Reregistration', $this->tab->get_title() );
	}

	public function test_gated_by_reregistration_caps(): void {
		$this->assertSame( 'ffc_view_reregistration', $this->tab->get_view_cap() );
		$this->assertSame( 'ffc_manage_reregistration', $this->tab->get_manage_cap() );
	}


	public function test_render_shows_the_selector_and_hub_links(): void {
		Functions\when( 'get_option' )->justReturn( 0 );        // nothing selected.
		Functions\when( 'get_posts' )->justReturn( array() );   // no record templates yet.
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'submit_button' )->justReturn( '' );
		Functions\when( 'selected' )->justReturn( '' );

		ob_start();
		$this->tab->render();
		$out = ob_get_clean();

		$this->assertStringContainsString( 'name="ffc_record_template"', $out );
		$this->assertStringContainsString( 'ffc_kind=' . CertTemplateCpt::KIND_RECORD, $out );
		$this->assertStringContainsString( 'post-new.php?post_type=' . CertTemplateCpt::POST_TYPE, $out );
	}

	public function test_handle_save_persists_a_valid_record_id(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'get_post_meta' )->justReturn( CertTemplateCpt::KIND_RECORD );
		Functions\when( 'wp_safe_redirect' )->alias(
			static function (): void {
				throw new \RuntimeException( 'redirected' );
			}
		);
		$saved = null;
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$saved ) {
				$saved = array( $key, $value );
				return true;
			}
		);

		$_POST['ffc_record_template'] = '42';
		try {
			$this->tab->handle_save();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirected', $e->getMessage() );
		}

		$this->assertSame( CertTemplateRecordResolver::OPTION, $saved[0] );
		$this->assertSame( 42, $saved[1] );

		unset( $_POST['ffc_record_template'] );
	}

	public function test_handle_save_drops_an_id_of_the_wrong_kind(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'get_post_meta' )->justReturn( CertTemplateCpt::KIND_CERTIFICATE );
		Functions\when( 'wp_safe_redirect' )->alias(
			static function (): void {
				throw new \RuntimeException( 'redirected' );
			}
		);
		$saved = null;
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$saved ) {
				$saved = array( $key, $value );
				return true;
			}
		);

		$_POST['ffc_record_template'] = '42';
		try {
			$this->tab->handle_save();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirected', $e->getMessage() );
		}

		$this->assertSame( 0, $saved[1] );

		unset( $_POST['ffc_record_template'] );
	}

	// ==================================================================
	// The approved banner's window (#1887)
	// ==================================================================

	/**
	 * Run the save handler with a POST and return what `ffc_settings` received,
	 * or null when it was not written.
	 *
	 * @param array<string, string> $post   Posted fields.
	 * @param array<string, mixed>  $stored What `ffc_settings` holds before.
	 * @return array<string, mixed>|null
	 */
	private function saveSettings( array $post, array $stored ): ?array {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'get_post_meta' )->justReturn( CertTemplateCpt::KIND_RECORD );
		Functions\when( 'get_option' )->justReturn( $stored );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_safe_redirect' )->alias(
			static function (): void {
				throw new \RuntimeException( 'redirected' );
			}
		);
		$settings = null;
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$settings ) {
				if ( 'ffc_settings' === $key ) {
					$settings = $value;
				}
				return true;
			}
		);

		$_POST = $post + array( 'ffc_record_template' => '0' );
		try {
			$this->tab->handle_save();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirected', $e->getMessage() );
		} finally {
			$_POST = array();
		}

		return $settings;
	}

	public function test_handle_save_writes_the_banner_window_and_keeps_other_keys(): void {
		$saved = $this->saveSettings(
			array(
				'ffc_rereg_approved_banner_days' => '45',
				'ffc_invite_password_link_hours' => '48',
			),
			array(
				'rereg_approved_banner_days' => 30,
				'dark_mode'                  => 'on',
			)
		);

		$this->assertSame( 45, $saved['rereg_approved_banner_days'] );
		$this->assertSame( 48, $saved['invite_password_link_hours'] );
		$this->assertSame( 'on', $saved['dark_mode'], 'A key another tab owns survives the merge.' );
	}

	public function test_handle_save_accepts_zero_as_never_hide(): void {
		$saved = $this->saveSettings( array( 'ffc_rereg_approved_banner_days' => '0' ), array( 'rereg_approved_banner_days' => 30 ) );

		$this->assertSame( 0, $saved['rereg_approved_banner_days'] );
	}

	public function test_handle_save_keeps_the_stored_window_when_the_field_is_empty(): void {
		$saved = $this->saveSettings(
			array(
				'ffc_rereg_approved_banner_days' => '',
				'ffc_invite_password_link_hours' => '24',
			),
			array( 'rereg_approved_banner_days' => 60 )
		);

		$this->assertSame( 60, $saved['rereg_approved_banner_days'], 'Empty is "not supplied", never 0 -- which here would mean "never hide".' );
		$this->assertSame( 24, $saved['invite_password_link_hours'] );
	}

	public function test_handle_save_writes_nothing_when_both_numbers_are_empty(): void {
		$saved = $this->saveSettings(
			array(
				'ffc_rereg_approved_banner_days' => '',
				'ffc_invite_password_link_hours' => '',
			),
			array( 'rereg_approved_banner_days' => 60 )
		);

		$this->assertNull( $saved, 'Nothing supplied, nothing written.' );
	}

	public function test_handle_save_clamps_the_window_into_range(): void {
		$high = $this->saveSettings( array( 'ffc_rereg_approved_banner_days' => '9999' ), array() );
		$this->assertSame( 365, $high['rereg_approved_banner_days'] );

		$low = $this->saveSettings( array( 'ffc_rereg_approved_banner_days' => '-7' ), array() );
		$this->assertSame( 0, $low['rereg_approved_banner_days'] );
	}

	public function test_handle_save_ignores_a_non_numeric_window(): void {
		$saved = $this->saveSettings(
			array(
				'ffc_rereg_approved_banner_days' => 'abc',
				'ffc_invite_password_link_hours' => '24',
			),
			array( 'rereg_approved_banner_days' => 60 )
		);

		$this->assertSame( 60, $saved['rereg_approved_banner_days'] );
	}

	public function test_render_prints_the_banner_window_as_a_required_bounded_number(): void {
		Functions\when( 'get_option' )->alias(
			static fn( $key, $default = false ) => 'ffc_settings' === $key ? array( 'rereg_approved_banner_days' => 12 ) : 0
		);
		Functions\when( 'get_posts' )->justReturn( array() );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'submit_button' )->justReturn( '' );
		Functions\when( 'selected' )->justReturn( '' );

		ob_start();
		$this->tab->render();
		$out = ob_get_clean();

		$this->assertMatchesRegularExpression(
			'/<input type="number" name="ffc_rereg_approved_banner_days"[^>]*min="0"[^>]*max="365"[^>]*required[^>]*value="12"/s',
			$out
		);
		$this->assertStringContainsString( 'Hide the approved banner after (days)', $out );
	}
}
