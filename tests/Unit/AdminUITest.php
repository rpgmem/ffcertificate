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
		$this->assertStringContainsString( 'class="ffc-control-toggle ffc-control-toggle-mark"', $html );
		$this->assertStringContainsString( 'name="my_key"', $html );
		$this->assertStringContainsString( 'id="my_key"', $html );
		$this->assertStringContainsString( 'type="checkbox"', $html );
		$this->assertStringContainsString( 'value="1"', $html );
		$this->assertStringNotContainsString( 'checked', $html );
		$this->assertStringNotContainsString( 'disabled', $html );
		$this->assertStringContainsString( '<span class="ffc-control-toggle-track"', $html );
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
		$this->assertStringContainsString( '<span class="ffc-control-toggle-label">My toggle</span>', $html );
	}

	public function test_separate_id_and_name(): void {
		$html = $this->capture( array( 'name' => 'k', 'id' => 'distinct_id' ) );
		$this->assertStringContainsString( 'id="distinct_id"', $html );
		$this->assertStringContainsString( 'for="distinct_id"', $html );
		$this->assertStringContainsString( 'name="k"', $html );
	}

	public function test_appends_extra_class(): void {
		$html = $this->capture( array( 'name' => 'k', 'class' => 'is-large' ) );
		$this->assertStringContainsString( 'class="ffc-control-toggle ffc-control-toggle-mark is-large"', $html );
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

	public function test_no_description_leaves_the_bare_label(): void {
		$html = $this->capture( array( 'name' => 'k', 'label' => 'My toggle' ) );
		$this->assertStringStartsWith( '<label class="ffc-control-toggle ffc-control-toggle-mark"', $html );
		$this->assertStringNotContainsString( 'ffc-control-toggle-field', $html );
		$this->assertStringNotContainsString( 'aria-describedby', $html );
		$this->assertStringNotContainsString( 'class="description"', $html );
	}

	/**
	 * A description wraps the toggle and its sentence in one block and ties
	 * the sentence to the checkbox (#1816).
	 */
	public function test_description_wraps_the_toggle_and_describes_the_checkbox(): void {
		$html = $this->capture(
			array(
				'name'        => 'opt[k]',
				'id'          => 'k_id',
				'label'       => 'My toggle',
				'description' => 'What changes.',
			)
		);
		$this->assertSame(
			'<div class="ffc-control-toggle-field"><label class="ffc-control-toggle ffc-control-toggle-mark" for="k_id">'
			. '<input type="checkbox" id="k_id" name="opt[k]" value="1" aria-describedby="k_id-description">'
			. '<span class="ffc-control-toggle-track" aria-hidden="true"></span>'
			. '<span class="ffc-control-toggle-label">My toggle</span></label>'
			. '<p class="description" id="k_id-description">What changes.</p></div>',
			$html
		);
	}

	public function test_blank_description_is_ignored(): void {
		$html = $this->capture( array( 'name' => 'k', 'description' => '   ' ) );
		$this->assertStringNotContainsString( 'ffc-control-toggle-field', $html );
	}

	public function test_get_toggle_returns_markup_instead_of_echoing(): void {
		ob_start();
		$returned = AdminUI::get_toggle( array( 'name' => 'k', 'label' => 'My toggle' ) );
		$echoed = (string) ob_get_clean();

		$this->assertSame( '', $echoed, 'get_toggle must not echo' );
		$this->assertStringContainsString( 'class="ffc-control-toggle ffc-control-toggle-mark"', $returned );
		$this->assertStringContainsString( 'name="k"', $returned );
		$this->assertStringContainsString( '<span class="ffc-control-toggle-label">My toggle</span>', $returned );
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
					array( 'label' => 'Create', 'url' => '/new', 'category' => 'create', 'icon' => 'plus' ),
					array( 'label' => 'No URL' ),
					'not an array',
				),
			)
		);

		$this->assertStringContainsString( '<div class="ffc-empty-state__actions"><a href="/clear" class="ffc-btn ffc-btn-neutral">Clear</a><a href="/new" class="ffc-btn ffc-btn-create ffc-icon-plus">Create</a></div>', $html );
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

	public function test_toggle_mark_is_drawn_by_default(): void {
		ob_start();
		AdminUI::render_toggle(
			array(
				'name'  => 'flag',
				'class' => 'extra',
			)
		);
		$default = (string) ob_get_clean();
		ob_start();
		AdminUI::render_toggle(
			array(
				'name' => 'flag',
				'mark' => true,
			)
		);
		$explicit = (string) ob_get_clean();

		$this->assertStringContainsString( '<label class="ffc-control-toggle ffc-control-toggle-mark extra" for="flag">', $default, 'The on/off mark is the default (#1873).' );
		$this->assertStringContainsString( '<label class="ffc-control-toggle ffc-control-toggle-mark" for="flag">', $explicit, 'Passing true still draws it.' );
		$this->assertStringNotContainsString( 'ffc-toggle', str_replace( 'ffc-control-toggle', '', $default ), 'No class of the pre-family name is left (#1748).' );
	}

	public function test_toggle_mark_opts_out_with_false(): void {
		foreach ( array( false, 0, '' ) as $off ) {
			ob_start();
			AdminUI::render_toggle(
				array(
					'name' => 'flag',
					'mark' => $off,
				)
			);
			$plain = (string) ob_get_clean();

			$this->assertStringContainsString( '<label class="ffc-control-toggle" for="flag">', $plain, var_export( $off, true ) . ' opts the toggle out of the mark.' );
			$this->assertStringNotContainsString( 'ffc-control-toggle-mark', $plain );
		}
	}

	// ==================================================================
	// Steps control (#1745)
	// ==================================================================

	private function steps( array $args ): string {
		ob_start();
		AdminUI::render_steps(
			$args + array(
				'name'    => 'cfg[level]',
				'options' => array(
					'0' => 'Off',
					'1' => 'Moderate',
					'2' => 'Strict',
				),
			)
		);
		return (string) ob_get_clean();
	}

	public function test_steps_is_one_radio_per_stop_with_its_visible_name(): void {
		$html = $this->steps( array( 'value' => '1', 'id' => 'lvl', 'legend' => 'Reset level' ) );

		$this->assertSame( 3, substr_count( $html, 'type="radio"' ) );
		$this->assertSame( 3, substr_count( $html, 'name="cfg[level]"' ) );
		$this->assertStringContainsString( '<legend class="screen-reader-text">Reset level</legend>', $html );
		$this->assertStringContainsString( 'id="lvl-2" name="cfg[level]" value="1" checked', $html );
		$this->assertSame( 1, substr_count( $html, ' checked' ) );
		$this->assertStringContainsString( '<label class="ffc-control-steps-stop"><input type="radio" id="lvl-3" name="cfg[level]" value="2"><span class="ffc-control-steps-mark" aria-hidden="true"></span><span class="ffc-control-steps-label">Strict</span></label>', $html );
	}

	public function test_steps_palette_defaults_to_primary_and_takes_the_others(): void {
		$this->assertStringContainsString( 'class="ffc-control-steps ffc-control-steps-primary"', $this->steps( array() ) );
		$this->assertStringContainsString( 'class="ffc-control-steps ffc-control-steps-severity"', $this->steps( array( 'palette' => 'severity' ) ) );
		$this->assertStringContainsString( 'class="ffc-control-steps ffc-control-steps-traffic"', $this->steps( array( 'palette' => 'traffic' ) ) );
		$this->assertStringContainsString( 'class="ffc-control-steps ffc-control-steps-primary"', $this->steps( array( 'palette' => 'neon' ) ), 'An unknown palette falls back rather than printing a class nothing styles.' );
	}

	public function test_steps_layout_parameters_become_classes(): void {
		$html = $this->steps(
			array(
				'size'   => 'lg',
				'shape'  => 'square',
				'fluid'  => true,
				'labels' => 'selected',
			)
		);
		$this->assertStringContainsString( 'class="ffc-control-steps ffc-control-steps-primary ffc-control-steps-lg ffc-control-steps-square ffc-control-steps-fluid ffc-control-steps-labels-selected"', $html );
		$this->assertStringContainsString( 'class="ffc-control-steps ffc-control-steps-primary"', $this->steps( array( 'size' => 'xl', 'shape' => 'star', 'labels' => 'none' ) ), 'Unknown values add nothing.' );
	}

	public function test_steps_marks_a_long_scale(): void {
		$options = array_combine( range( 1, 6 ), array( 'a', 'b', 'c', 'd', 'e', 'f' ) );
		$this->assertStringContainsString( 'ffc-control-steps-many', $this->steps( array( 'options' => $options ) ) );
		$this->assertStringNotContainsString( 'ffc-control-steps-many', $this->steps( array() ) );
	}

	public function test_steps_tones_name_a_stop_and_ignore_unknown_ones(): void {
		$html = $this->steps( array( 'tones' => array( '0' => 'info', '2' => 'lilac' ) ) );
		$this->assertSame( 1, substr_count( $html, 'data-tone=' ) );
		$this->assertStringContainsString( '<label class="ffc-control-steps-stop" data-tone="info"><input type="radio" id="cfg-level-1"', $html );
	}

	public function test_steps_checks_the_default_and_then_the_first_when_the_value_is_not_one(): void {
		$this->assertStringContainsString( 'value="0" checked', $this->steps( array( 'value' => 'x' ) ) );
		$this->assertStringContainsString( 'value="1" checked', $this->steps( array( 'value' => 'x', 'default' => '1' ) ), 'A stored value the scale does not know shows the default, as the select it replaced did.' );
		$this->assertStringContainsString( 'value="0" checked', $this->steps( array( 'value' => 'x', 'default' => 'y' ) ), 'A default outside the options falls back to the first.' );
		$this->assertStringContainsString( 'value="2" checked', $this->steps( array( 'value' => '2', 'default' => '1' ) ) );
	}

	public function test_steps_carries_data_on_every_radio_and_disables_the_group(): void {
		$html = $this->steps(
			array(
				'disabled' => true,
				'data'     => array( 'ffc-autosave-form-key' => 'level' ),
			)
		);
		$this->assertSame( 3, substr_count( $html, ' data-ffc-autosave-form-key="level"' ) );
		$this->assertStringContainsString( 'ffc-control-steps-primary" disabled>', $html, 'A disabled fieldset disables every radio in it.' );
	}

	public function test_steps_disables_only_the_stops_named_in_disabled_options(): void {
		// A value the site cannot run right now stays on the rail (#1758).
		$html = $this->steps( array( 'disabled_options' => array( 2, 'nope' ) ) );
		$this->assertSame( 1, substr_count( $html, ' disabled>' ) );
		$this->assertStringContainsString( 'id="cfg-level-3" name="cfg[level]" value="2" disabled>', $html );
		$this->assertStringContainsString( 'class="ffc-control-steps ffc-control-steps-primary">', $html, 'The group itself stays enabled.' );
		$this->assertStringNotContainsString( ' disabled', $this->steps( array() ) );
	}

	public function test_steps_prints_a_description_per_value_given_tied_to_its_stop(): void {
		$html = $this->steps( array( 'descriptions' => array( '2' => 'Also the focus.' ) ) );
		$this->assertStringContainsString( '<span class="description ffc-control-steps-desc" data-step="3">Also the focus.</span>', $html );
		$this->assertSame( 1, substr_count( $html, 'ffc-control-steps-desc' ) );
	}

	public function test_steps_needs_a_name_and_options(): void {
		$this->assertSame( '', $this->steps( array( 'name' => '' ) ) );
		$this->assertSame( '', $this->steps( array( 'options' => array() ) ) );
	}

	public function test_steps_derives_an_id_from_the_name(): void {
		$this->assertStringContainsString( 'id="cfg-level-1"', $this->steps( array() ) );
	}

	/**
	 * Out of range the field still works, as a select, and the developer is
	 * told which control fits.
	 */
	public function test_steps_out_of_range_prints_a_select_and_tells_the_developer(): void {
		$notices = array();
		Functions\when( '_doing_it_wrong' )->alias(
			static function ( $fn, $message ) use ( &$notices ) {
				$notices[] = $message;
			}
		);
		Functions\when( 'selected' )->alias( static fn( $a, $b, $echo = true ) => (string) $a === (string) $b ? ' selected=\'selected\'' : '' );

		$two = $this->steps(
			array(
				'value'   => '1',
				'options' => array(
					'0' => 'Off',
					'1' => 'On',
				),
			)
		);
		$this->assertStringContainsString( '<select id="cfg-level" name="cfg[level]">', $two );
		$this->assertStringContainsString( '<option value="1" selected=\'selected\'>On</option>', $two );
		$this->assertStringNotContainsString( 'ffc-control-steps', $two );

		$twelve = $this->steps( array( 'options' => array_combine( range( 1, 12 ), range( 1, 12 ) ) ) );
		$this->assertSame( 12, substr_count( $twelve, '<option ' ) );

		$this->assertCount( 2, $notices );
		$this->assertStringContainsString( '2 were given', $notices[0] );
		$this->assertStringContainsString( 'render_toggle()', $notices[0] );
		$this->assertStringContainsString( '12 were given', $notices[1] );

		$notices = array();
		$this->steps( array( 'options' => array_combine( range( 1, 11 ), range( 1, 11 ) ) ) );
		$this->assertSame( array(), $notices, 'Eleven is still a steps control.' );
	}

	// ==================================================================
	// Segmented control (#1750)
	// ==================================================================

	private function segmented( array $args ): string {
		ob_start();
		AdminUI::render_segmented(
			$args + array(
				'name'    => 'cfg[theme]',
				'options' => array(
					'off'  => 'Off',
					'on'   => 'On',
					'auto' => 'Auto',
				),
			)
		);
		return (string) ob_get_clean();
	}

	public function test_segmented_is_one_radio_per_option_with_its_visible_name(): void {
		$html = $this->segmented( array( 'value' => 'on', 'id' => 'thm', 'legend' => 'Theme' ) );

		$this->assertStringContainsString( '<fieldset id="thm" class="ffc-control-segmented">', $html );
		$this->assertSame( 3, substr_count( $html, 'type="radio"' ) );
		$this->assertSame( 3, substr_count( $html, 'name="cfg[theme]"' ) );
		$this->assertStringContainsString( '<legend class="screen-reader-text">Theme</legend>', $html );
		$this->assertStringContainsString( 'id="thm-2" name="cfg[theme]" value="on" checked', $html );
		$this->assertSame( 1, substr_count( $html, ' checked' ) );
		$this->assertStringContainsString( '<label class="ffc-control-segmented-option"><input type="radio" id="thm-3" name="cfg[theme]" value="auto"><span class="ffc-control-segmented-label">Auto</span></label>', $html );
	}

	/**
	 * `allow_none` (#1901): a value no option describes checks nothing, and
	 * the default does not stand in for it; a value that is an option is
	 * still checked.
	 */
	public function test_segmented_allow_none_checks_nothing_for_an_unknown_value(): void {
		foreach ( array( null, '', 'mixed' ) as $value ) {
			$html = $this->segmented( array( 'value' => $value, 'default' => 'on', 'allow_none' => true ) );
			$this->assertSame( 3, substr_count( $html, 'type="radio"' ) );
			$this->assertStringNotContainsString( ' checked', $html, var_export( $value, true ) );
		}

		$this->assertSame( 1, substr_count( $this->segmented( array( 'value' => 'auto', 'allow_none' => true ) ), 'value="auto" checked' ) );
		$this->assertSame( 1, substr_count( $this->segmented( array( 'value' => 'mixed' ) ), 'value="off" checked' ), 'without it the first option still stands in' );
	}

	public function test_segmented_layout_parameters_become_classes(): void {
		$this->assertStringContainsString( 'class="ffc-control-segmented ffc-control-segmented-sm ffc-control-segmented-fluid"', $this->segmented( array( 'size' => 'sm', 'fluid' => true ) ) );
		$this->assertStringContainsString( 'class="ffc-control-segmented ffc-control-segmented-lg"', $this->segmented( array( 'size' => 'lg' ) ) );
		$this->assertStringContainsString( '<fieldset id="cfg-theme" class="ffc-control-segmented">', $this->segmented( array( 'size' => 'xl' ) ), 'An unknown size adds nothing.' );
	}

	public function test_segmented_checks_the_default_and_then_the_first_when_the_value_is_not_one(): void {
		$this->assertStringContainsString( 'value="off" checked', $this->segmented( array( 'value' => 'x' ) ) );
		$this->assertStringContainsString( 'value="auto" checked', $this->segmented( array( 'value' => 'x', 'default' => 'auto' ) ), 'A stored value the control does not know shows the default, as the select it replaced did.' );
		$this->assertStringContainsString( 'value="off" checked', $this->segmented( array( 'value' => 'x', 'default' => 'y' ) ), 'A default outside the options falls back to the first.' );
	}

	public function test_segmented_carries_data_on_every_radio_and_disables_the_group(): void {
		$html = $this->segmented(
			array(
				'disabled' => true,
				'data'     => array( 'ffc-autosave-key' => 'dark_mode' ),
			)
		);
		$this->assertSame( 3, substr_count( $html, ' data-ffc-autosave-key="dark_mode"' ) );
		$this->assertStringContainsString( '<fieldset id="cfg-theme" class="ffc-control-segmented" disabled>', $html, 'A disabled fieldset disables every radio in it.' );
	}

	public function test_segmented_prints_a_description_per_value_given_tied_to_its_option(): void {
		$html = $this->segmented( array( 'descriptions' => array( 'auto' => 'Follows the system.' ) ) );
		$this->assertStringContainsString( '<span class="description ffc-control-segmented-desc" data-option="3">Follows the system.</span>', $html );
		$this->assertSame( 1, substr_count( $html, 'ffc-control-segmented-desc' ) );
	}

	public function test_segmented_needs_a_name_and_options_and_derives_an_id(): void {
		$this->assertSame( '', $this->segmented( array( 'name' => '' ) ) );
		$this->assertSame( '', $this->segmented( array( 'options' => array() ) ) );
		$this->assertStringContainsString( 'id="cfg-theme-1"', $this->segmented( array() ) );
	}

	/**
	 * Out of range the field still works, as a select, and the developer is
	 * told why.
	 */
	public function test_segmented_out_of_range_prints_a_select_and_tells_the_developer(): void {
		$notices = array();
		Functions\when( '_doing_it_wrong' )->alias(
			static function ( $fn, $message ) use ( &$notices ) {
				$notices[] = $message;
			}
		);
		Functions\when( 'selected' )->alias( static fn( $a, $b, $echo = true ) => (string) $a === (string) $b ? ' selected=\'selected\'' : '' );

		$one = $this->segmented( array( 'options' => array( 'only' => 'Only' ) ) );
		$this->assertStringContainsString( '<select id="cfg-theme" name="cfg[theme]">', $one );
		$this->assertStringContainsString( '<option value="only" selected=\'selected\'>Only</option>', $one );
		$this->assertStringNotContainsString( 'ffc-control-segmented', $one );

		$seven = $this->segmented(
			array(
				'disabled' => true,
				'options'  => array_combine( range( 1, 7 ), range( 1, 7 ) ),
			)
		);
		$this->assertSame( 7, substr_count( $seven, '<option ' ) );
		$this->assertStringContainsString( 'name="cfg[theme]" disabled>', $seven );

		$this->assertCount( 2, $notices );
		$this->assertStringContainsString( '1 were given', $notices[0] );
		$this->assertStringContainsString( '7 were given', $notices[1] );

		$notices = array();
		$this->segmented( array( 'options' => array( 'a' => 'A', 'b' => 'B' ) ) );
		$this->segmented( array( 'options' => array_combine( range( 1, 6 ), range( 1, 6 ) ) ) );
		$this->assertSame( array(), $notices, 'Two and six are still a segmented control.' );
	}

	public function test_vertical_tabs_enqueue_keeps_the_old_handle_as_an_alias(): void {
		$enqueued   = array();
		$registered = array();
		Functions\when( 'wp_enqueue_script' )->alias(
			static function ( $handle, $src = '', $deps = array() ) use ( &$enqueued ) {
				$enqueued[] = array( $handle, $src, $deps );
			}
		);
		Functions\when( 'wp_register_script' )->alias(
			static function ( $handle, $src = '', $deps = array() ) use ( &$registered ) {
				$registered[] = array( $handle, $src, $deps );
			}
		);

		AdminUI::enqueue_vertical_tabs();

		// ffc-core first: the required-tag banner is drawn by FFC.notice() (#1843).
		$this->assertCount( 2, $enqueued );
		$this->assertSame( 'ffc-core', $enqueued[0][0] );
		$this->assertSame( 'ffc-vertical-tabs', $enqueued[1][0] );
		$this->assertStringContainsString( 'assets/js/ffc-vertical-tabs', $enqueued[1][1] );
		$this->assertContains( 'ffc-core', $enqueued[1][2] );
		$this->assertSame(
			array( array( 'ffc-form-editor-tabs', false, array( 'ffc-vertical-tabs' ) ) ),
			$registered,
			'The handle the script had before #1788 must stay a source-less alias until its removal release.'
		);
	}

	public function test_shortcode_copy_enqueue_keeps_the_old_handle_as_an_alias(): void {
		$enqueued   = array();
		$registered = array();
		Functions\when( 'wp_enqueue_script' )->alias(
			static function ( $handle, $src = '', $deps = array() ) use ( &$enqueued ) {
				$enqueued[] = array( $handle, $src, $deps );
			}
		);
		Functions\when( 'wp_register_script' )->alias(
			static function ( $handle, $src = '', $deps = array() ) use ( &$registered ) {
				$registered[] = array( $handle, $src, $deps );
			}
		);

		AdminUI::enqueue_shortcode_copy();

		$this->assertCount( 1, $enqueued );
		$this->assertSame( AdminUI::SHORTCODE_COPY_HANDLE, $enqueued[0][0] );
		$this->assertStringContainsString( 'assets/js/ffc-copy-shortcode', $enqueued[0][1] );
		$this->assertSame(
			array( array( 'ffc-form-list-copy-shortcode', false, array( 'ffc-copy-shortcode' ) ) ),
			$registered,
			'The handle the script had before #1814 must stay a source-less alias until its removal release.'
		);
	}

	public function test_render_shortcode_copy_escapes_the_shortcode_and_labels_the_button(): void {
		$escape = static fn( $text ) => htmlspecialchars( (string) $text, ENT_QUOTES );
		Functions\when( 'esc_html' )->alias( $escape );
		Functions\when( 'esc_attr' )->alias( $escape );
		Functions\when( '__' )->justReturn( 'Copy shortcode' );

		ob_start();
		AdminUI::render_shortcode_copy( '[ffc_audience schedule_id="7"]' );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<span class="ffc-shortcode-cell">', $html );
		$this->assertStringContainsString( '<code class="ffc-shortcode-code">[ffc_audience schedule_id=&quot;7&quot;]</code>', $html );
		$this->assertStringContainsString( 'data-shortcode="[ffc_audience schedule_id=&quot;7&quot;]"', $html );
		$this->assertStringContainsString( '<button type="button" class="ffc-copy-shortcode"', $html );
		$this->assertStringContainsString( 'aria-label="Copy shortcode"', $html, 'An icon-only button needs an accessible name.' );
		$this->assertStringContainsString( 'title="Copy shortcode"', $html );
		$this->assertStringContainsString( '<span class="ffc-icon-copy" aria-hidden="true"></span>', $html );
		$this->assertStringNotContainsString( 'schedule_id="7"', $html, 'The raw quotes must never reach the markup.' );
	}

	public function test_render_vertical_tabs_prints_what_the_streaming_primitives_print(): void {
		Functions\when( 'esc_attr__' )->returnArg();
		$tabs = array(
			array(
				'key'     => 'general',
				'icon'    => 'ffc-icon-settings',
				'label'   => 'General',
				'title'   => 'General settings',
				'masters' => array(),
			),
			array(
				'key'     => 'emails',
				'icon'    => 'ffc-icon-email',
				'label'   => 'Emails',
				'title'   => 'Emails',
				'masters' => array( 'send_mail' ),
				'needs'   => '#to',
			),
		);

		ob_start();
		AdminUI::render_vertical_tabs(
			$tabs,
			static function ( string $key ): void {
				echo 'body-' . $key;
			}
		);
		$callback = (string) ob_get_clean();

		ob_start();
		AdminUI::vertical_tabs_open( $tabs, 'general' );
		foreach ( $tabs as $tab ) {
			AdminUI::vertical_tab_panel_open( $tab, 'general' );
			echo 'body-' . $tab['key'];
			AdminUI::vertical_tab_panel_close();
		}
		AdminUI::vertical_tabs_close();
		$streamed = (string) ob_get_clean();

		$this->assertSame( $callback, $streamed );
		$this->assertStringStartsWith( '<div class="ffc-vtabs" data-ffc-vtabs><ul class="ffc-vtabs__nav" role="tablist" aria-orientation="vertical" data-ffc-state-on="On" data-ffc-state-off="Off" data-ffc-state-pending="Incomplete">', $callback );
		$this->assertStringContainsString( 'class="ffc-vtabs__tab" role="tab" aria-controls="ffc-tabpanel-emails" aria-selected="false" tabindex="-1" data-ffc-tab-masters="send_mail" data-ffc-tab-needs="#to">', $callback );
		$this->assertStringContainsString( '<section id="ffc-tabpanel-general" class="ffc-vtabs__panel is-active" role="tabpanel" aria-labelledby="ffc-tabnav-general" tabindex="0"><h2 class="ffc-vtabs__panel-title"><span class="ffc-vtabs__icon ffc-icon-settings" aria-hidden="true"></span>General settings</h2>body-general</section>', $callback );
		$this->assertStringEndsWith( '</section></div></div>', $callback );
	}

	public function test_vertical_tabs_without_a_state_dot_print_no_state_texts(): void {
		$tabs = array(
			array(
				'key'   => 'members',
				'icon'  => 'ffc-icon-users',
				'label' => 'Members',
			),
		);

		ob_start();
		AdminUI::vertical_tabs_open( $tabs, 'members', 'ffc-aud-editor__tabs' );
		AdminUI::vertical_tab_panel_open( $tabs[0], 'members' );
		$html = (string) ob_get_clean();

		$this->assertStringStartsWith( '<div class="ffc-aud-editor__tabs ffc-vtabs" data-ffc-vtabs><ul class="ffc-vtabs__nav" role="tablist" aria-orientation="vertical"><li', $html );
		$this->assertStringContainsString( '<h2 class="ffc-vtabs__panel-title"><span class="ffc-vtabs__icon ffc-icon-users" aria-hidden="true"></span>Members</h2>', $html, 'Without a title the panel heading is the label.' );
	}
}
