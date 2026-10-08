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

	public function test_emits_title_on_wrapper_when_provided(): void {
		$html = $this->capture( array( 'name' => 'k', 'title' => 'Encrypt at rest' ) );
		$this->assertStringContainsString( 'title="Encrypt at rest"', $html );
	}

	public function test_no_title_attribute_by_default(): void {
		$html = $this->capture( array( 'name' => 'k' ) );
		$this->assertStringNotContainsString( 'title=', $html );
	}

	public function test_get_toggle_returns_markup_instead_of_echoing(): void {
		ob_start();
		$returned = AdminUI::get_toggle( array( 'name' => 'k', 'label' => 'My toggle' ) );
		$echoed = (string) ob_get_clean();

		$this->assertSame( '', $echoed, 'get_toggle must not echo' );
		$this->assertStringContainsString( 'class="ffc-toggle"', $returned );
		$this->assertStringContainsString( 'name="k"', $returned );
		$this->assertStringContainsString( '<span class="ffc-toggle-label">My toggle</span>', $returned );
	}

	/**
	 * The autosave badge strings are shared by four enqueue sites (#1116).
	 *
	 * `invalid` is the one a reader is likeliest to drop, because nothing
	 * renders it on a happy path: it is the fallback the validity guard
	 * shows when the browser supplies no `validationMessage` of its own
	 * (#1114). Losing a key here degrades to an English literal in the JS,
	 * which is exactly the silent half-translation this method exists to
	 * prevent.
	 */
	public function test_autosave_strings_cover_every_badge_state(): void {
		$strings = AdminUI::autosave_strings();

		$this->assertSame( array( 'saving', 'saved', 'error', 'invalid' ), array_keys( $strings ) );
		foreach ( $strings as $key => $value ) {
			$this->assertNotSame( '', $value, "The '{$key}' badge string must not be empty." );
		}
	}

	// ------------------------------------------------------------------
	// get_empty_state()
	// ------------------------------------------------------------------

	public function test_empty_state_without_a_title_renders_nothing(): void {
		$this->assertSame( '', AdminUI::get_empty_state( array( 'text' => 'Orphan sentence' ) ) );
	}

	public function test_empty_state_draws_icon_title_and_text(): void {
		$html = AdminUI::get_empty_state(
			array(
				'icon'  => 'filter',
				'title' => 'Nothing here',
				'text'  => 'Because of the filter.',
			)
		);

		$this->assertStringStartsWith( '<div class="ffc-empty-state">', $html );
		$this->assertStringContainsString( 'class="ffc-empty-state__icon ffc-icon-badge ffc-icon-badge-primary ffc-icon-filter" aria-hidden="true"', $html );
		$this->assertStringContainsString( '<p class="ffc-empty-state__title">Nothing here</p>', $html );
		$this->assertStringContainsString( '<p class="ffc-empty-state__text">Because of the filter.</p>', $html );
		$this->assertStringNotContainsString( 'ffc-empty-state__actions', $html, 'no actions, no empty actions row' );
	}

	public function test_empty_state_defaults_to_the_inbox_icon_and_omits_an_empty_text(): void {
		$html = AdminUI::get_empty_state( array( 'title' => 'Nothing here' ) );

		$this->assertStringContainsString( 'ffc-icon-inbox', $html );
		$this->assertStringNotContainsString( 'ffc-empty-state__text', $html );
	}

	public function test_empty_state_renders_actions_and_skips_incomplete_ones(): void {
		Functions\when( 'esc_url' )->returnArg();

		$html = AdminUI::get_empty_state(
			array(
				'title'   => 'Nothing here',
				'actions' => array(
					array( 'label' => 'Clear', 'url' => '/clear' ),
					array( 'label' => 'Create', 'url' => '/new', 'primary' => true ),
					array( 'label' => 'No URL' ),
					'not an array',
				),
			)
		);

		$this->assertStringContainsString( '<div class="ffc-empty-state__actions"><a href="/clear" class="button">Clear</a><a href="/new" class="button button-primary">Create</a></div>', $html );
		$this->assertStringNotContainsString( 'No URL', $html );
	}

	public function test_empty_state_escapes_every_value(): void {
		Functions\when( 'esc_attr' )->alias( 'htmlspecialchars' );
		Functions\when( 'esc_html' )->alias( 'htmlspecialchars' );
		Functions\when( 'esc_url' )->alias( static fn( $u ) => 'escaped:' . $u );

		$html = AdminUI::get_empty_state(
			array(
				'icon'    => '"><script>',
				'title'   => '<b>t</b>',
				'text'    => '<i>x</i>',
				'actions' => array( array( 'label' => '<u>a</u>', 'url' => 'javascript:alert(1)' ) ),
			)
		);

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( '<b>', $html );
		$this->assertStringNotContainsString( '<i>', $html );
		$this->assertStringNotContainsString( '<u>', $html );
		$this->assertStringContainsString( 'href="escaped:javascript:alert(1)"', $html );
	}
}
