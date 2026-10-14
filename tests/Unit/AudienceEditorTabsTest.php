<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Audience\AudienceEditorTabs;

/**
 * The vertical tabs of the audience editors (#1761): the form editor's
 * component, so the markup must be the shape `ffc-form-editor-tabs.js` reads.
 *
 * @covers \FreeFormCertificate\Audience\AudienceEditorTabs
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AudienceEditorTabsTest extends TestCase {

	private const TABS = array(
		array(
			'key'   => 'general',
			'label' => 'General',
			'icon'  => 'calendar',
		),
		array(
			'key'   => 'closed-dates',
			'label' => 'Closed dates',
			'icon'  => 'lock',
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
		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', '/tmp/' );
		}
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

	public function test_markup_is_the_form_editor_component(): void {
		ob_start();
		AudienceEditorTabs::open( self::TABS, 'closed-dates' );
		foreach ( self::TABS as $tab ) {
			AudienceEditorTabs::panel_open( $tab, 'closed-dates' );
			echo 'body-' . $tab['key'];
			AudienceEditorTabs::panel_close();
		}
		AudienceEditorTabs::close();
		$html = (string) ob_get_clean();

		$this->assertStringStartsWith( '<div class="ffc-aud-editor__tabs ffc-form-tabs" data-ffc-form-tabs><ul class="ffc-form-tabs__nav" role="tablist" aria-orientation="vertical">', $html );
		$this->assertStringContainsString( '<a href="#ffc-tab-general" id="ffc-tabnav-general" class="ffc-form-tabs__tab" role="tab" aria-controls="ffc-tabpanel-general" aria-selected="false" tabindex="-1"><span class="ffc-form-tabs__icon ffc-icon-calendar" aria-hidden="true"></span><span class="ffc-form-tabs__label">General</span></a>', $html );
		$this->assertStringContainsString( '<a href="#ffc-tab-closed-dates" id="ffc-tabnav-closed-dates" class="ffc-form-tabs__tab is-active" role="tab" aria-controls="ffc-tabpanel-closed-dates" aria-selected="true" tabindex="0">', $html );
		$this->assertStringContainsString( '<section id="ffc-tabpanel-general" class="ffc-form-tabs__panel" role="tabpanel" aria-labelledby="ffc-tabnav-general" tabindex="0"><h2 class="ffc-form-tabs__panel-title ffc-icon-calendar">General</h2>body-general</section>', $html );
		$this->assertStringContainsString( '<section id="ffc-tabpanel-closed-dates" class="ffc-form-tabs__panel is-active"', $html );
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
		$enqueued = array();
		Functions\when( 'wp_enqueue_script' )->alias(
			static function ( $handle, $src = '', $deps = array() ) use ( &$enqueued ) {
				$enqueued[] = array( $handle, $src, $deps );
			}
		);

		$this->assertSame( $loads, AudienceEditorTabs::maybe_enqueue( $page, $action ) );
		if ( $loads ) {
			$this->assertCount( 1, $enqueued );
			$this->assertSame( 'ffc-form-editor-tabs', $enqueued[0][0], 'the form editor\'s handle, so the two never load twice' );
			$this->assertStringContainsString( 'assets/js/ffc-form-editor-tabs', $enqueued[0][1] );
			$this->assertSame( array( 'jquery' ), $enqueued[0][2] );
		} else {
			$this->assertSame( array(), $enqueued );
		}
	}
}
