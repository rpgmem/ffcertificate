<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * The reregistration campaign editor is a boxed screen (#1629).
 *
 * The template runs in the scope of the renderer that includes it, so this
 * includes it from a stand-in whose `render_audience_transfer_list()` is the
 * one sibling it calls through `self::`. What is under test is the markup the
 * template owns: the campaign and its emails each in a card with an icon
 * heading, inside the form; the import and invitation boxes as cards outside
 * it.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class ReregistrationFormTemplateTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_html_e' )->alias(
			static function ( $text ) {
				echo $text;
			}
		);
		Functions\when( 'settings_errors' )->justReturn( '' );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'checked' )->justReturn( '' );
		Functions\when( 'disabled' )->justReturn( '' );
		Functions\when( 'submit_button' )->alias(
			static function () {
				echo '<p class="submit"></p>';
			}
		);
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );

		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( $query ) {
				return $query;
			}
		);
		$wpdb->shouldReceive( 'get_col' )->andReturn( array() );
		$wpdb->shouldReceive( 'get_results' )->andReturn( array() );

		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', '/tmp/' );
		}
		if ( ! defined( 'FFC_PLUGIN_DIR' ) ) {
			define( 'FFC_PLUGIN_DIR', dirname( __DIR__, 2 ) . '/' );
		}
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}

	/**
	 * Render the template for a campaign that exists.
	 *
	 * @return string
	 */
	private function render(): string {
		$host = new class() {
			/**
			 * Include the template the way the renderer does.
			 *
			 * @return string
			 */
			public static function run(): string {
				$id               = 3;
				$title            = 'Edit Reregistration';
				$back_url         = '/back';
				$item             = (object) array(
					'title'                      => 'Campaign',
					'start_date'                 => '2026-10-01 00:00:00',
					'end_date'                   => '2026-11-01 00:00:00',
					'status'                     => 'draft',
					'auto_approve'               => 0,
					'email_invitation_enabled'   => 0,
					'email_reminder_enabled'     => 0,
					'email_confirmation_enabled' => 0,
					'reminder_days'              => 7,
				);
				$audiences        = array();
				$selected_ids     = array();
				$import_audiences = array();
				ob_start();
				include FFC_PLUGIN_DIR . 'templates/admin/reregistration/form.php';
				return (string) ob_get_clean();
			}

			/**
			 * The renderer's sibling the template calls through `self::`.
			 *
			 * @return void
			 */
			public static function render_audience_transfer_list(): void {
				echo '<div class="transfer"></div>';
			}
		};
		return $host::run();
	}

	public function test_campaign_and_emails_are_cards_inside_the_form(): void {
		$output = $this->render();

		$form = substr( $output, (int) strpos( $output, '<form' ), (int) strpos( $output, '</form>' ) - (int) strpos( $output, '<form' ) );
		$this->assertStringContainsString( '<h2 class="ffc-icon-user-check">Campaign</h2>', $form );
		$this->assertStringContainsString( '<h2 class="ffc-icon-email">Email Notifications</h2>', $form );
		$this->assertSame( 2, substr_count( $form, '<div class="card">' ) );
		// Every field the save handler reads is still posted by this form.
		foreach ( array( 'rereg_title', 'rereg_start_date', 'rereg_end_date', 'rereg_status', 'rereg_auto_approve', 'rereg_email_invitation', 'rereg_email_reminder', 'rereg_email_confirmation', 'rereg_reminder_days' ) as $name ) {
			$this->assertStringContainsString( 'name="' . $name . '"', $form, $name . ' left the form' );
		}
	}

	public function test_import_and_invitations_are_cards_outside_the_form(): void {
		$output = $this->render();
		$tail   = substr( $output, (int) strpos( $output, '</form>' ) );

		$this->assertStringContainsString( '<div class="card ffc-rereg-import-box">', $tail );
		$this->assertStringContainsString( '<h2 class="ffc-icon-upload">', $tail );
		$this->assertStringContainsString( '<div class="card ffc-rereg-invite-box">', $tail );
		$this->assertStringContainsString( '<h2 class="ffc-icon-send">', $tail );
		$this->assertStringNotContainsString( 'postbox', $output );
	}
}
