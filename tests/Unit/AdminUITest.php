<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\AdminUI;

/**
 * Tests for the AdminUI::render_toggle helper introduced in 6.5.4.
 *
 * @covers \FreeFormCertificate\Admin\AdminUI
 */
class AdminUITest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( '__' )->returnArg();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function capture( array $args ): string {
        ob_start();
        AdminUI::render_toggle( $args );
        return (string) ob_get_clean();
    }

    public function test_renders_nothing_when_name_is_missing(): void {
        $html = $this->capture( array() );
        $this->assertSame( '', $html );
    }

    public function test_renders_unchecked_toggle_by_default(): void {
        $html = $this->capture( array( 'name' => 'my_key' ) );
        $this->assertStringContainsString( 'class="ffc-toggle"', $html );
        $this->assertStringContainsString( 'name="my_key"', $html );
        $this->assertStringContainsString( 'id="my_key"', $html );
        $this->assertStringContainsString( 'type="checkbox"', $html );
        $this->assertStringContainsString( 'value="1"', $html );
        $this->assertStringNotContainsString( 'checked', $html );
        $this->assertStringNotContainsString( 'disabled', $html );
        $this->assertStringContainsString( '<span class="ffc-toggle-track"', $html );
    }

    public function test_emits_checked_when_args_checked_true(): void {
        $html = $this->capture( array( 'name' => 'k', 'checked' => true ) );
        $this->assertStringContainsString( ' checked', $html );
    }

    public function test_emits_disabled_when_args_disabled_true(): void {
        $html = $this->capture( array( 'name' => 'k', 'disabled' => true ) );
        $this->assertStringContainsString( ' disabled', $html );
    }

    public function test_renders_label_text_when_provided(): void {
        $html = $this->capture(
            array(
                'name'  => 'k',
                'label' => 'My toggle',
            )
        );
        $this->assertStringContainsString( '<span class="ffc-toggle-label">My toggle</span>', $html );
    }

    public function test_separate_id_and_name(): void {
        $html = $this->capture( array( 'name' => 'k', 'id' => 'distinct_id' ) );
        $this->assertStringContainsString( 'id="distinct_id"', $html );
        $this->assertStringContainsString( 'for="distinct_id"', $html );
        $this->assertStringContainsString( 'name="k"', $html );
    }

    public function test_appends_extra_class(): void {
        $html = $this->capture( array( 'name' => 'k', 'class' => 'is-large' ) );
        $this->assertStringContainsString( 'class="ffc-toggle is-large"', $html );
    }

    public function test_emits_data_attributes(): void {
        $html = $this->capture(
            array(
                'name' => 'k',
                'data' => array(
                    'autosave-key' => 'admin_bypass_geo',
                    'extra'        => 'foo',
                ),
            )
        );
        $this->assertStringContainsString( 'data-autosave-key="admin_bypass_geo"', $html );
        $this->assertStringContainsString( 'data-extra="foo"', $html );
    }

    public function test_custom_submitted_value(): void {
        $html = $this->capture( array( 'name' => 'k', 'value' => 'yes' ) );
        $this->assertStringContainsString( 'value="yes"', $html );
    }
}
