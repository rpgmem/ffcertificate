<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Settings\SettingsTab;

/**
 * Tests for SettingsTab: abstract base class for settings tabs.
 *
 * Uses an anonymous concrete subclass to test non-abstract functionality
 * (getters, render helpers, is_active, get_tab_url, get_option).
 *
 * @covers \FreeFormCertificate\Settings\SettingsTab
 */
class SettingsTabTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var SettingsTab */
    private $tab;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'wp_kses_post' )->returnArg();

        // Create a concrete anonymous subclass of the abstract SettingsTab
        $this->tab = new class() extends SettingsTab {
            protected function init(): void {
                $this->tab_id    = 'test_tab';
                $this->tab_title = 'Test Tab';
                $this->tab_icon  = 'ffc-icon-test';
                $this->tab_order = 42;
            }

            public function render(): void {
                echo '<div>Test tab content</div>';
            }

            // Expose protected methods for testing
            public function public_render_notice( string $message, string $type = 'success' ): void {
                $this->render_notice( $message, $type );
            }

            public function public_render_section_header( string $title, string $description = '' ): void {
                $this->render_section_header( $title, $description );
            }

            public function public_render_field_row( string $label, string $content, string $description = '' ): void {
                $this->render_field_row( $label, $content, $description );
            }

            public function public_is_active(): bool {
                return $this->is_active();
            }

            public function public_get_tab_url(): string {
                return $this->get_tab_url();
            }
        };
    }

    protected function tearDown(): void {
        unset( $_GET['tab'] );
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // Constructor / init()
    // ==================================================================

    public function test_constructor_calls_init_and_sets_properties(): void {
        $this->assertSame( 'test_tab', $this->tab->get_id() );
        $this->assertSame( 'Test Tab', $this->tab->get_title() );
        $this->assertSame( 'ffc-icon-test', $this->tab->get_icon() );
        $this->assertSame( 42, $this->tab->get_order() );
    }

    // ==================================================================
    // get_id()
    // ==================================================================

    public function test_get_id_returns_tab_id(): void {
        $this->assertSame( 'test_tab', $this->tab->get_id() );
    }

    // ==================================================================
    // get_title()
    // ==================================================================

    public function test_get_title_returns_tab_title(): void {
        $this->assertSame( 'Test Tab', $this->tab->get_title() );
    }

    // ==================================================================
    // get_icon()
    // ==================================================================

    public function test_get_icon_returns_tab_icon(): void {
        $this->assertSame( 'ffc-icon-test', $this->tab->get_icon() );
    }

    // ==================================================================
    // get_order()
    // ==================================================================

    public function test_get_order_returns_tab_order(): void {
        $this->assertSame( 42, $this->tab->get_order() );
    }

    public function test_get_order_defaults_to_ten_when_not_set(): void {
        $tab = new class() extends SettingsTab {
            protected function init(): void {
                $this->tab_id    = 'default_order';
                $this->tab_title = 'Default Order';
                $this->tab_icon  = '';
                // tab_order intentionally NOT set
            }

            public function render(): void {}
        };

        $this->assertSame( 10, $tab->get_order() );
    }

    // ==================================================================
    // render()
    // ==================================================================

    public function test_render_outputs_content(): void {
        ob_start();
        $this->tab->render();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'Test tab content', $output );
    }

    // ==================================================================
    // render_notice()
    // ==================================================================

    public function test_render_notice_outputs_success_notice(): void {
        ob_start();
        $this->tab->public_render_notice( 'Settings saved.' );
        $output = ob_get_clean();

        $this->assertStringContainsString( 'notice-success', $output );
        $this->assertStringContainsString( 'Settings saved.', $output );
        $this->assertStringContainsString( 'is-dismissible', $output );
    }

    public function test_render_notice_outputs_error_type(): void {
        ob_start();
        $this->tab->public_render_notice( 'Something went wrong.', 'error' );
        $output = ob_get_clean();

        $this->assertStringContainsString( 'notice-error', $output );
        $this->assertStringContainsString( 'Something went wrong.', $output );
    }

    public function test_render_notice_outputs_warning_type(): void {
        ob_start();
        $this->tab->public_render_notice( 'Be careful.', 'warning' );
        $output = ob_get_clean();

        $this->assertStringContainsString( 'notice-warning', $output );
    }

    // ==================================================================
    // render_section_header()
    // ==================================================================

    public function test_render_section_header_outputs_title(): void {
        ob_start();
        $this->tab->public_render_section_header( 'Section Title' );
        $output = ob_get_clean();

        $this->assertStringContainsString( 'ffc-section-header', $output );
        $this->assertStringContainsString( 'Section Title', $output );
    }

    public function test_render_section_header_outputs_description_when_provided(): void {
        ob_start();
        $this->tab->public_render_section_header( 'Title', 'Description text' );
        $output = ob_get_clean();

        $this->assertStringContainsString( 'Description text', $output );
        $this->assertStringContainsString( 'class="description"', $output );
    }

    public function test_render_section_header_omits_description_when_empty(): void {
        ob_start();
        $this->tab->public_render_section_header( 'Title Only' );
        $output = ob_get_clean();

        $this->assertStringNotContainsString( 'class="description"', $output );
    }

    // ==================================================================
    // render_field_row()
    // ==================================================================

    public function test_render_field_row_outputs_label_and_content(): void {
        ob_start();
        $this->tab->public_render_field_row( 'Field Label', '<input type="text" />' );
        $output = ob_get_clean();

        $this->assertStringContainsString( 'Field Label', $output );
        $this->assertStringContainsString( '<input type="text" />', $output );
        $this->assertStringContainsString( '<tr>', $output );
        $this->assertStringContainsString( '<th scope="row">', $output );
    }

    public function test_render_field_row_outputs_description_when_provided(): void {
        ob_start();
        $this->tab->public_render_field_row( 'Label', 'Content', 'Help text here' );
        $output = ob_get_clean();

        $this->assertStringContainsString( 'Help text here', $output );
        $this->assertStringContainsString( 'class="description"', $output );
    }

    public function test_render_field_row_omits_description_when_empty(): void {
        ob_start();
        $this->tab->public_render_field_row( 'Label', 'Content' );
        $output = ob_get_clean();

        // There should be no description paragraph
        $this->assertStringNotContainsString( 'class="description"', $output );
    }

    // ==================================================================
    // is_active()
    // ==================================================================

    public function test_is_active_returns_true_when_tab_matches(): void {
        Functions\when( 'sanitize_key' )->alias( function ( $key ) {
            return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
        } );
        Functions\when( 'wp_unslash' )->returnArg();

        $_GET['tab'] = 'test_tab';

        $this->assertTrue( $this->tab->public_is_active() );
    }

    public function test_is_active_returns_false_when_tab_differs(): void {
        Functions\when( 'sanitize_key' )->alias( function ( $key ) {
            return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
        } );
        Functions\when( 'wp_unslash' )->returnArg();

        $_GET['tab'] = 'other_tab';

        $this->assertFalse( $this->tab->public_is_active() );
    }

    public function test_is_active_returns_false_when_no_tab_param(): void {
        Functions\when( 'sanitize_key' )->alias( function ( $key ) {
            return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
        } );
        Functions\when( 'wp_unslash' )->returnArg();

        unset( $_GET['tab'] );

        $this->assertFalse( $this->tab->public_is_active() );
    }

    // ==================================================================
    // get_tab_url()
    // ==================================================================

    public function test_get_tab_url_returns_admin_url_with_tab_id(): void {
        Functions\when( 'admin_url' )->alias( function ( $path = '' ) {
            return 'https://example.com/wp-admin/' . $path;
        } );

        $url = $this->tab->public_get_tab_url();

        $this->assertSame(
            'https://example.com/wp-admin/edit.php?post_type=ffc_form&page=ffc-settings&tab=test_tab',
            $url
        );
    }

    // ==================================================================
    // get_option()
    // ==================================================================

    public function test_get_option_returns_saved_value(): void {
        Functions\when( 'get_option' )->justReturn( array( 'my_key' => 'saved_value' ) );

        $this->assertSame( 'saved_value', $this->tab->get_option( 'my_key' ) );
    }

    public function test_get_option_returns_default_when_key_missing(): void {
        Functions\when( 'get_option' )->justReturn( array() );

        $this->assertSame( 'fallback', $this->tab->get_option( 'missing_key', 'fallback' ) );
    }

    public function test_get_option_returns_empty_string_by_default(): void {
        Functions\when( 'get_option' )->justReturn( array() );

        $this->assertSame( '', $this->tab->get_option( 'nonexistent' ) );
    }

    public function test_get_option_casts_to_string(): void {
        Functions\when( 'get_option' )->justReturn( array( 'num_key' => 42 ) );

        $result = $this->tab->get_option( 'num_key' );
        $this->assertSame( '42', $result );
    }
}
