<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use FreeFormCertificate\Settings\SettingsTab;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Settings\Tabs\TabEmailTexts;
use FreeFormCertificate\Core\AssetHelper;
use FreeFormCertificate\Core\EmailTemplates;

/**
 * Tests for TabEmailTexts: the per-email texts hub + "All plugin emails"
 * directory (split out of the SMTP tab in #976).
 *
 * @covers \FreeFormCertificate\Settings\Tabs\TabEmailTexts
 * @preserveGlobalState disabled
 */
class TabEmailTextsTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var TabEmailTexts */
	private $tab;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// Shared scripts are enqueued through AssetRegistry, which registers
		// them first; false is WordPress's answer for a handle already
		// registered, so nothing is localized twice (#1931).
		Functions\when( 'wp_register_script' )->justReturn( false );

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'sanitize_key' )->alias( function ( $key ) {
			return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
		} );

		$this->tab = new TabEmailTexts();
	}

	protected function tearDown(): void {
		SettingsTab::set_resolved_active_tab( null );
		unset( $_GET['tab'], $_POST['ffc_save_email_bodies'], $_POST['ffc_email_bodies'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	// ==================================================================
	// init() — tab properties
	// ==================================================================

	public function test_tab_id_is_email_texts(): void {
		$this->assertSame( 'email_texts', $this->tab->get_id() );
	}

	public function test_tab_title(): void {
		$this->assertSame( 'Email texts', $this->tab->get_title() );
	}

	public function test_manage_cap_is_email_templates(): void {
		$this->assertSame( 'ffc_manage_email_templates', $this->tab->get_manage_cap() );
	}


	// ==================================================================
	// render_email_index() — the read-only "All plugin emails" directory
	// ==================================================================

	public function test_render_email_index_lists_features_with_deeplinks(): void {
		Functions\when( 'current_user_can' )->justReturn( true ); // reaches every feature.
		Functions\when( 'esc_html_e' )->alias( static function ( $t ) { echo $t; } );
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'admin_url' )->alias( static fn( $p = '' ) => 'https://example.com/wp-admin/' . $p );

		ob_start();
		$this->tab->render_email_index();
		$out = (string) ob_get_clean();

		$this->assertStringContainsString( 'All plugin emails', $out );
		$this->assertStringContainsString( 'ffc-email-index', $out );
		// Toggle/system rows still deep-link to their feature's own screen.
		$this->assertStringContainsString( 'post_type=ffc_form', $out );
		$this->assertStringContainsString( 'post_type=ffc_self_scheduling', $out );
		// The hub-editable (global) rows point to the Email texts hub (#976).
		$this->assertStringContainsString( 'page=ffc-settings&tab=email_texts', $out );
		// The Email Model row points to its own tab.
		$this->assertStringContainsString( 'page=ffc-settings&tab=email_model', $out );
		// Personalisation states are labelled.
		$this->assertStringContainsString( 'Editable text (global)', $out );
		$this->assertStringContainsString( 'On/off only', $out );
		$this->assertStringContainsString( 'System default', $out );
		// Date messages were missing from the directory (#1574).
		$this->assertStringContainsString( 'page=ffc-date-messages', $out );
		$this->assertStringContainsString( 'Manager digest', $out );
	}

	public function test_render_email_index_hidden_without_any_feature_cap(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'esc_html_e' )->alias( static function ( $t ) { echo $t; } );
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'admin_url' )->alias( static fn( $p = '' ) => 'https://example.com/wp-admin/' . $p );

		ob_start();
		$this->tab->render_email_index();
		$out = (string) ob_get_clean();

		$this->assertSame( '', trim( $out ) );
	}

	// ==================================================================
	// render_email_body_hub() — the global email-body hub (#964)
	// ==================================================================

	public function test_render_hub_hidden_without_cap(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		ob_start();
		$this->tab->render_email_body_hub();
		$out = (string) ob_get_clean();

		$this->assertSame( '', trim( $out ) );
	}

	public function test_render_hub_shows_the_hub_emails(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'esc_html_e' )->alias( static function ( $t ) { echo $t; } );
		Functions\when( 'esc_textarea' )->returnArg();
		Functions\when( 'wp_kses' )->returnArg();
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'submit_button' )->alias( static function ( $t = '' ) { echo '<submit>'; } );
		Functions\when( 'get_option' )->justReturn( array() );

		ob_start();
		$this->tab->render_email_body_hub();
		$out = (string) ob_get_clean();

		// Each email renders a plain <textarea> keyed by template (TinyMCE is
		// initialized on demand client-side, #976 B2).
		$this->assertStringContainsString( 'ffc_email_bodies[certificate-user][body]', $out );
		$this->assertStringContainsString( 'ffc_email_bodies[recruitment-convocation][body]', $out );
		$this->assertStringContainsString( 'ffc_email_bodies[selfscheduling-confirmation][body]', $out );
		$this->assertStringContainsString( 'ffc_email_bodies[access-granted][body]', $out );
		$this->assertStringContainsString( 'ffc_save_email_bodies', $out );
		$this->assertStringContainsString( 'validation_url', $out );
		// The picker + its feature optgroups drive which editor is shown.
		$this->assertStringContainsString( 'id="ffc-email-texts-select"', $out );
		$this->assertStringContainsString( '<optgroup label="Personal Calendars">', $out );
		$this->assertStringContainsString( '<optgroup label="Account access">', $out );
		// The tab opens on the placeholder with EVERY editor hidden — nothing is
		// initialized until an email is picked, so TinyMCE is never asked to boot
		// before wp_enqueue_editor() has printed its runtime.
		$this->assertStringContainsString( '<option value=""', $out );
		$this->assertSame(
			substr_count( $out, 'class="ffc-email-body-hub__item"' ),
			substr_count( $out, 'style="display:none;"' ),
			'Every hub item must start hidden.'
		);
		// With JS off the picker cannot run, so a <noscript> rule reveals them all.
		$this->assertStringContainsString( '<noscript>', $out );
	}

	/**
	 * Stub the options API over an in-memory store and record every write of
	 * the hub's option, so a test reads what the real EmailTemplates persisted.
	 *
	 * @param array<string, mixed> $stored Initial `ffc_email_bodies` value.
	 * @return array{0: \ArrayObject<string, mixed>, 1: \ArrayObject<int, mixed>} The store and the write log.
	 */
	private function stub_email_bodies_option( array $stored ): array {
		$store  = new \ArrayObject( array( EmailTemplates::OPTION => $stored ) );
		$writes = new \ArrayObject();
		Functions\when( 'get_option' )->alias(
			static function ( $name, $fallback = false ) use ( $store ) {
				return $store->offsetExists( $name ) ? $store[ $name ] : $fallback;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value ) use ( $store, $writes ) {
				if ( EmailTemplates::OPTION === $name ) {
					$writes->append( $value );
				}
				$store[ $name ] = $value;
				return true;
			}
		);
		return array( $store, $writes );
	}

	public function test_maybe_save_persists_edited_override(): void {
		$_POST['ffc_save_email_bodies'] = '1';
		$_POST['ffc_email_bodies']      = array(
			'certificate-user' => array(
				'subject' => 'Your certificate',
				'body'    => '<p>Custom body</p>',
			),
		);
		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		list( $store ) = $this->stub_email_bodies_option( array() );

		$ref = new \ReflectionMethod( TabEmailTexts::class, 'maybe_save_email_bodies' );
		$ref->setAccessible( true );
		$this->assertTrue( $ref->invoke( $this->tab ) );

		// The real EmailTemplates::save_global() stored the edited override.
		$this->assertSame(
			array(
				'subject' => 'Your certificate',
				'body'    => '<p>Custom body</p>',
			),
			$store[ EmailTemplates::OPTION ]['certificate-user'] ?? null
		);
	}

	public function test_maybe_save_clears_override_when_equal_to_file_default(): void {
		$shipped_subject = EmailTemplates::body( 'certificate-user', 'subject' );
		$shipped_body    = EmailTemplates::body( 'certificate-user', 'body' );
		$this->assertNotSame( '', $shipped_body, 'The shipped default must load for this test to mean anything.' );

		$_POST['ffc_save_email_bodies'] = '1';
		$_POST['ffc_email_bodies']      = array(
			'certificate-user' => array(
				'subject' => $shipped_subject,
				'body'    => $shipped_body,
			),
		);
		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		list( $store, $writes ) = $this->stub_email_bodies_option(
			array(
				'certificate-user' => array(
					'subject' => 'Old custom subject',
					'body'    => 'Old custom body',
				),
			)
		);

		$ref = new \ReflectionMethod( TabEmailTexts::class, 'maybe_save_email_bodies' );
		$ref->setAccessible( true );
		$this->assertTrue( $ref->invoke( $this->tab ) );

		// clear_global() removed the stale override ...
		$this->assertArrayNotHasKey( 'certificate-user', $store[ EmailTemplates::OPTION ] );
		// ... and save_global() never persisted the shipped text as an override.
		$this->assertGreaterThan( 0, count( $writes ) );
		foreach ( $writes as $written ) {
			$this->assertNotSame(
				array(
					'subject' => $shipped_subject,
					'body'    => $shipped_body,
				),
				$written['certificate-user'] ?? null,
				'A value equal to the shipped default must not be stored as an override.'
			);
		}
	}

	public function test_maybe_save_ignored_without_presence_flag(): void {
		unset( $_POST['ffc_save_email_bodies'] );

		$ref = new \ReflectionMethod( TabEmailTexts::class, 'maybe_save_email_bodies' );
		$ref->setAccessible( true );
		$this->assertFalse( $ref->invoke( $this->tab ) );
	}

	// ==================================================================
	// enqueue_scripts() — the selector JS + editor API (#976 B2)
	// ==================================================================

	public function test_enqueue_scripts_loads_editor_and_selector_when_cap_held(): void {
		SettingsTab::set_resolved_active_tab( 'email_texts' );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'current_user_can' )->justReturn( true ); // hub cap held.

		$registered = array();
		Functions\when( 'wp_register_script' )->alias(
			static function ( ...$args ) use ( &$registered ) {
				$registered[ $args[0] ] = $args;
				return false;
			}
		);
		$enqueued = array();
		Functions\when( 'wp_enqueue_script' )->alias(
			static function ( ...$args ) use ( &$enqueued ) {
				$enqueued[] = $args;
			}
		);
		$localized = array();
		Functions\when( 'wp_localize_script' )->alias(
			static function ( $handle, $name, $data ) use ( &$localized ) {
				$localized[ $name ] = array( $handle, $data );
				return true;
			}
		);
		Functions\expect( 'wp_enqueue_editor' )->atLeast()->once();

		$this->tab->enqueue_scripts( 'toplevel_page_ffc-settings' );

		$suffix = AssetHelper::asset_suffix();
		// The restore-default script goes through AssetRegistry: registered with
		// its source and jquery dependency, then enqueued by handle, once.
		$this->assertSame(
			array( 'ffc-email-restore-default', FFC_PLUGIN_URL . "assets/js/ffc-email-restore-default{$suffix}.js", array( 'jquery' ), FFC_VERSION, true ),
			$registered['ffc-email-restore-default'] ?? null
		);
		$this->assertSame(
			1,
			count( array_filter( $enqueued, static fn( $call ) => 'ffc-email-restore-default' === $call[0] ) )
		);
		$selector = array_values( array_filter( $enqueued, static fn( $call ) => 'ffc-email-texts' === $call[0] ) );
		$this->assertSame(
			array( array( 'ffc-email-texts', FFC_PLUGIN_URL . "assets/js/ffc-email-texts{$suffix}.js", array( 'jquery', 'editor', 'quicktags' ), FFC_VERSION, true ) ),
			$selector
		);
		// The restore defaults carry the real shipped bodies.
		$this->assertSame( 'ffc-email-restore-default', $localized['ffcEmailRestoreDefaults'][0] ?? null );
		$this->assertSame(
			EmailTemplates::body( 'certificate-user', 'body' ),
			$localized['ffcEmailRestoreDefaults'][1]['certificate-user']['body'] ?? null
		);
	}

	public function test_enqueue_scripts_noop_without_cap(): void {
		$_GET['tab'] = 'email_texts';
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'current_user_can' )->justReturn( false ); // hub cap not held.

		Functions\expect( 'wp_enqueue_editor' )->never();
		Functions\expect( 'wp_enqueue_script' )->never();

		$this->tab->enqueue_scripts( 'toplevel_page_ffc-settings' );
	}
}
