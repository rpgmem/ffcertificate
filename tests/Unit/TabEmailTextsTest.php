<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Settings\Tabs\TabEmailTexts;

/**
 * Tests for TabEmailTexts: the per-email texts hub + "All plugin emails"
 * directory (split out of the SMTP tab in #976).
 *
 * @covers \FreeFormCertificate\Settings\Tabs\TabEmailTexts
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class TabEmailTextsTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var TabEmailTexts */
    private $tab;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

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

    public function test_extends_settings_tab(): void {
        $this->assertInstanceOf( \FreeFormCertificate\Settings\SettingsTab::class, $this->tab );
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
        Functions\when( 'wp_kses' )->returnArg();
        Functions\when( 'wp_nonce_field' )->justReturn( '' );
        Functions\when( 'submit_button' )->alias( static function ( $t = '' ) { echo '<submit>'; } );
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\when( 'wp_editor' )->alias(
            static function ( $content, $id, $settings = array() ) {
                echo '<textarea name="' . ( $settings['textarea_name'] ?? '' ) . '"></textarea>';
            }
        );

        ob_start();
        $this->tab->render_email_body_hub();
        $out = (string) ob_get_clean();

        $this->assertStringContainsString( 'ffc_email_bodies[certificate-user][body]', $out );
        $this->assertStringContainsString( 'ffc_email_bodies[recruitment-convocation][body]', $out );
        $this->assertStringContainsString( 'ffc_email_bodies[selfscheduling-confirmation][body]', $out );
        $this->assertStringContainsString( 'ffc_email_bodies[access-granted][body]', $out );
        $this->assertStringContainsString( 'ffc_save_email_bodies', $out );
        $this->assertStringContainsString( 'validation_url', $out );
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

        $email_templates = Mockery::mock( 'alias:FreeFormCertificate\Core\EmailTemplates' );
        $email_templates->shouldReceive( 'body' )->andReturn( '' );
        $email_templates->shouldReceive( 'clear_global' )->andReturn( true );
        $email_templates->shouldReceive( 'save_global' )
            ->with(
                'certificate-user',
                array(
                    'subject' => 'Your certificate',
                    'body'    => '<p>Custom body</p>',
                )
            )
            ->once()
            ->andReturn( true );

        $ref = new \ReflectionMethod( TabEmailTexts::class, 'maybe_save_email_bodies' );
        $ref->setAccessible( true );
        $this->assertTrue( $ref->invoke( $this->tab ) );
    }

    public function test_maybe_save_clears_override_when_equal_to_file_default(): void {
        $_POST['ffc_save_email_bodies'] = '1';
        $_POST['ffc_email_bodies']      = array(
            'certificate-user' => array(
                'subject' => 'Shipped subject',
                'body'    => 'Shipped body',
            ),
        );
        Functions\when( 'check_admin_referer' )->justReturn( true );
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'wp_kses_post' )->returnArg();

        $email_templates = Mockery::mock( 'alias:FreeFormCertificate\Core\EmailTemplates' );
        $email_templates->shouldReceive( 'body' )->andReturnUsing(
            static function ( $name, $key = 'body' ) {
                if ( 'certificate-user' === $name ) {
                    return 'subject' === $key ? 'Shipped subject' : 'Shipped body';
                }
                return '';
            }
        );
        $email_templates->shouldReceive( 'clear_global' )->andReturn( true );
        $email_templates->shouldReceive( 'save_global' )->never();

        $ref = new \ReflectionMethod( TabEmailTexts::class, 'maybe_save_email_bodies' );
        $ref->setAccessible( true );
        $this->assertTrue( $ref->invoke( $this->tab ) );
    }

    public function test_maybe_save_ignored_without_presence_flag(): void {
        unset( $_POST['ffc_save_email_bodies'] );

        $ref = new \ReflectionMethod( TabEmailTexts::class, 'maybe_save_email_bodies' );
        $ref->setAccessible( true );
        $this->assertFalse( $ref->invoke( $this->tab ) );
    }
}
