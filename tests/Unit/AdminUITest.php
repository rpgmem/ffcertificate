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

	// ------------------------------------------------------------------
	// section_open() / section_close()
	// ------------------------------------------------------------------

	public function test_section_with_a_master_renders_its_state_and_both_labels(): void {
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();

		$html = AdminUI::section_open(
			array(
				'title'  => 'Email',
				'hint'   => 'Sent after submission.',
				'icon'   => 'email',
				'master' => 'toggle_id',
				'on'     => true,
				'open'   => true,
				'id'     => 'sec-email',
			)
		) . 'BODY' . AdminUI::section_close();

		$this->assertStringStartsWith( '<details class="ffc-section" id="sec-email" open data-ffc-section><summary class="ffc-section__summary">', $html );
		$this->assertStringContainsString( '<span class="ffc-section__icon"><svg', $html );
		$this->assertStringContainsString( '<span class="ffc-section__title">Email</span><span class="ffc-section__hint">Sent after submission.</span>', $html );
		$this->assertStringContainsString( '<span class="ffc-section__chip is-on" data-ffc-section-master="toggle_id" data-on="On" data-off="Off">On</span>', $html );
		$this->assertStringEndsWith( '<div class="ffc-section__body">BODY</div></details>', $html );
	}

	public function test_section_off_closed_and_with_fixed_chip(): void {
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();

		$off = AdminUI::section_open( array( 'title' => 'T', 'master' => 'm', 'on' => false ) );
		$this->assertStringStartsWith( '<details class="ffc-section" data-ffc-section>', $off, 'closed unless asked' );
		$this->assertStringContainsString( 'class="ffc-section__chip is-off"', $off );
		$this->assertStringContainsString( '>Off</span>', $off );

		$fixed = AdminUI::section_open( array( 'title' => 'T', 'chip' => '09:00 – 12:00' ) );
		$this->assertStringContainsString( '<span class="ffc-section__chip">09:00 – 12:00</span>', $fixed );
		$this->assertStringNotContainsString( 'data-ffc-section-master', $fixed );

		$bare = AdminUI::section_open( array( 'title' => 'T', 'icon' => 'not-an-icon' ) );
		$this->assertStringNotContainsString( 'ffc-section__chip', $bare );
		$this->assertStringNotContainsString( 'ffc-section__hint', $bare );
		$this->assertStringContainsString( '<span class="ffc-section__icon"></span>', $bare, 'an unknown icon draws nothing rather than failing' );
	}

	public function test_section_escapes_its_values(): void {
		Functions\when( 'esc_attr' )->alias( 'htmlspecialchars' );
		Functions\when( 'esc_html' )->alias( 'htmlspecialchars' );
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();

		$html = AdminUI::section_open( array( 'title' => '<b>', 'hint' => '<i>', 'chip' => '<u>', 'id' => '"x' ) );

		$this->assertStringNotContainsString( '<b>', $html );
		$this->assertStringNotContainsString( '<i>', $html );
		$this->assertStringNotContainsString( '<u>', $html );
		$this->assertStringContainsString( 'id="&quot;x"', $html );
	}

	public function test_render_section_helpers_print_the_same_markup(): void {
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		$args = array( 'title' => 'T', 'master' => 'm', 'on' => true );

		ob_start();
		AdminUI::render_section_open( $args );
		AdminUI::render_section_close();

		$this->assertSame( AdminUI::section_open( $args ) . AdminUI::section_close(), ob_get_clean() );
	}

	// ------------------------------------------------------------------
	// get_stat_card()
	// ------------------------------------------------------------------

	public function test_stat_card_formats_an_int_and_ids_its_value(): void {
		Functions\when( 'number_format_i18n' )->alias( static fn( $n ) => number_format( (float) $n, 0, ',', '.' ) );

		$html = AdminUI::get_stat_card( array( 'label' => 'Submissions', 'value' => 1234, 'icon' => 'inbox', 'id' => 'stat-x' ) );

		$this->assertSame(
			'<div class="ffc-stat-card"><span class="ffc-stat-card__icon ffc-icon-badge ffc-icon-badge-primary ffc-icon-inbox" aria-hidden="true"></span><span class="ffc-stat-card__value" id="stat-x">1.234</span><span class="ffc-stat-card__label">Submissions</span></div>',
			$html
		);
	}

	public function test_stat_card_defaults_and_refusals(): void {
		$this->assertSame( '', AdminUI::get_stat_card( array( 'value' => 3 ) ), 'no label, no card' );

		$html = AdminUI::get_stat_card( array( 'label' => 'Pending' ) );
		$this->assertStringContainsString( '<span class="ffc-stat-card__value">—</span>', $html, 'a value not known yet reads as a dash' );
		$this->assertStringNotContainsString( 'ffc-stat-card__icon', $html );
	}

	public function test_stat_card_tone_picks_the_badge_and_neutral_is_the_plain_one(): void {
		$html = AdminUI::get_stat_card( array( 'label' => 'Approved', 'value' => '2', 'icon' => 'checkmark', 'tone' => 'success' ) );
		$this->assertStringContainsString( 'class="ffc-stat-card__icon ffc-icon-badge ffc-icon-badge-success ffc-icon-checkmark"', $html );

		// The plain badge is the neutral tone; an unknown tone falls back to it
		// rather than printing a class nothing styles.
		foreach ( array( 'neutral', 'bogus' ) as $tone ) {
			$html = AdminUI::get_stat_card( array( 'label' => 'Expired', 'value' => '0', 'icon' => 'history', 'tone' => $tone ) );
			$this->assertStringContainsString( 'class="ffc-stat-card__icon ffc-icon-badge ffc-icon-history"', $html, $tone );
		}
	}

	public function test_stat_card_link_needs_both_url_and_label(): void {
		Functions\when( 'esc_url' )->returnArg();
		$html = AdminUI::get_stat_card( array( 'label' => 'Calendars', 'value' => '1', 'url' => '/x', 'link' => 'Manage' ) );
		$this->assertStringContainsString( '<a class="ffc-stat-card__link" href="/x">Manage &rarr;</a>', $html );

		$this->assertStringNotContainsString( '<a ', AdminUI::get_stat_card( array( 'label' => 'Calendars', 'url' => '/x' ) ) );
		$this->assertStringNotContainsString( '<a ', AdminUI::get_stat_card( array( 'label' => 'Calendars', 'link' => 'Manage' ) ) );
	}
}
