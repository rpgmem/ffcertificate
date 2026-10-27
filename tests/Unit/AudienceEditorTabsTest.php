<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Audience\AudienceEditorTabs;

/**
 * The vertical tabs of the audience editors (#1761): the shared component,
 * printed by `AdminUI`'s streaming primitives (#1788), so the markup must be
 * the shape `ffc-vertical-tabs.js` reads.
 *
 * @covers \FreeFormCertificate\Audience\AudienceEditorTabs
 * @covers \FreeFormCertificate\Admin\AdminUI
 */
class AudienceEditorTabsTest extends TestCase {

	private const TABS = array(
		array(
			'key'   => 'general',
			'label' => 'General',
			'icon'  => 'ffc-icon-calendar',
		),
		array(
			'key'   => 'closed-dates',
			'label' => 'Closed dates',
			'icon'  => 'ffc-icon-lock',
		),
	);

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		// The global stubs only: PHP's fallback resolves them from the Core
		// namespace (CLAUDE.md "Test infrastructure").
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
	}

	protected function tearDown(): void {
		unset( $_GET['tab'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_active_reads_the_query_only_when_it_names_a_tab(): void {
		$keys = array( 'general', 'closed-dates' );
		$this->assertSame( 'general', AudienceEditorTabs::active( $keys, 'general' ) );
		$this->assertSame( 'closed-dates', AudienceEditorTabs::active( $keys, 'closed-dates' ), 'the fallback a handler names' );
		$this->assertSame( 'general', AudienceEditorTabs::active( $keys, 'members' ), 'a fallback the screen does not draw falls to the first tab' );
		$_GET['tab'] = 'closed-dates';
		$this->assertSame( 'closed-dates', AudienceEditorTabs::active( $keys, 'general' ) );
		$_GET['tab'] = '<script>';
		$this->assertSame( 'general', AudienceEditorTabs::active( $keys, 'general' ) );
	}

	public function test_markup_is_the_shared_component(): void {
		ob_start();
		AudienceEditorTabs::open( self::TABS, 'closed-dates' );
		foreach ( self::TABS as $tab ) {
			AudienceEditorTabs::panel_open( $tab, 'closed-dates' );
			echo 'body-' . $tab['key'];
			AudienceEditorTabs::panel_close();
		}
		AudienceEditorTabs::close();
		$html = (string) ob_get_clean();

		$this->assertStringStartsWith( '<div class="ffc-aud-editor__tabs ffc-vtabs" data-ffc-vtabs><ul class="ffc-vtabs__nav" role="tablist" aria-orientation="vertical">', $html );
		$this->assertStringContainsString( '<a href="#ffc-tab-general" id="ffc-tabnav-general" class="ffc-vtabs__tab" role="tab" aria-controls="ffc-tabpanel-general" aria-selected="false" tabindex="-1"><span class="ffc-vtabs__icon ffc-icon-calendar" aria-hidden="true"></span><span class="ffc-vtabs__label">General</span></a>', $html );
		$this->assertStringContainsString( '<a href="#ffc-tab-closed-dates" id="ffc-tabnav-closed-dates" class="ffc-vtabs__tab is-active" role="tab" aria-controls="ffc-tabpanel-closed-dates" aria-selected="true" tabindex="0">', $html );
		$this->assertStringContainsString( '<section id="ffc-tabpanel-general" class="ffc-vtabs__panel" role="tabpanel" aria-labelledby="ffc-tabnav-general" tabindex="0"><h2 class="ffc-vtabs__panel-title"><span class="ffc-vtabs__icon ffc-icon-calendar" aria-hidden="true"></span>General</h2>body-general</section>', $html );
		$this->assertStringContainsString( '<section id="ffc-tabpanel-closed-dates" class="ffc-vtabs__panel is-active"', $html );
		$this->assertStringEndsWith( '</section></div></div>', $html );
		$this->assertSame( substr_count( $html, '<div' ), substr_count( $html, '</div>' ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: bool}>
	 */
	public function screens(): array {
		return array(
			'calendar edit'     => array( 'ffc-scheduling-calendars', 'edit', true ),
			'calendar new'      => array( 'ffc-scheduling-calendars', 'new', true ),
			'calendar list'     => array( 'ffc-scheduling-calendars', '', false ),
			'calendar members'  => array( 'ffc-scheduling-calendars', 'members', false ),
			'audience edit'     => array( 'ffc-scheduling-audiences', 'edit', true ),
			'audience new'      => array( 'ffc-scheduling-audiences', 'new', true ),
			'audience members'  => array( 'ffc-scheduling-audiences', 'members', true ),
			'audience list'     => array( 'ffc-scheduling-audiences', 'list', false ),
			'environments edit' => array( 'ffc-scheduling-environments', 'edit', false ),
		);
	}

	/**
	 * @dataProvider screens
	 */
	public function test_script_loads_on_the_editors_only( string $page, string $action, bool $loads ): void {
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

		$this->assertSame( $loads, AudienceEditorTabs::maybe_enqueue( $page, $action ) );
		if ( $loads ) {
			// ffc-core first: the required-tag banner is FFC.notice() (#1843).
			$this->assertCount( 2, $enqueued );
			$this->assertSame( 'ffc-core', $enqueued[0][0] );
			$this->assertSame( 'ffc-vertical-tabs', $enqueued[1][0], 'the shared handle, so the editors never load it twice' );
			$this->assertStringContainsString( 'assets/js/ffc-vertical-tabs', $enqueued[1][1] );
			$this->assertSame( array( 'jquery', 'ffc-core' ), $enqueued[1][2] );
			$this->assertNotContains( 'ffc-form-editor-tabs', array_column( $registered, 0 ), 'the deprecated alias left in 6.41.0 (#1788)' );
		} else {
			$this->assertSame( array(), $enqueued );
			$this->assertSame( array(), $registered );
		}
	}
}
