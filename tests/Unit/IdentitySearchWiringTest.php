<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\IdentityResolutionPage;
use FreeFormCertificate\Admin\IdentityPreflightAjaxEndpoint;
use FreeFormCertificate\Admin\IdentitySearchAjaxEndpoint;

/**
 * The dialog's two halves name the same things (#1397 sprint 3).
 *
 * The script is one file and the markup another, and nothing makes them
 * agree: the button says which input to write into by naming an id, and a
 * renamed id breaks the dialog with no error anywhere -- the button opens,
 * the operator chooses, and the number lands in nothing. That is the failure
 * `CLAUDE.md` records twice on this screen already, where an anchored insert
 * silently did not match and a checker that read the wrong thing passed
 * against the defect.
 *
 * So this reads the view as TEXT and checks the ids line up, and reads the
 * enqueue to check the script is loaded where the markup is printed.
 *
 * @covers \FreeFormCertificate\Admin\IdentitySearchAjaxEndpoint
 * @covers \FreeFormCertificate\Admin\IdentityResolutionPage
 */
class IdentitySearchWiringTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Scripts `enqueue()` asked for, `handle => src`.
	 *
	 * @var array<string, string>
	 */
	private array $scripts = array();

	/**
	 * Objects `enqueue()` localised, `handle => [ name, data ]`.
	 *
	 * @var array<int, array{handle: string, name: string, data: array<string, mixed>}>
	 */
	private array $localised = array();

	/**
	 * Stand up the WordPress boundary.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		class_exists( '\FreeFormCertificate\Admin\IdentityResolutionPage' );
		class_exists( '\FreeFormCertificate\Admin\IdentitySearchAjaxEndpoint' );

		$this->scripts   = array();
		$this->localised = array();

		if ( ! defined( 'FFC_PLUGIN_URL' ) ) {
			define( 'FFC_PLUGIN_URL', 'https://example.org/wp-content/plugins/ffcertificate/' );
		}
		if ( ! defined( 'FFC_VERSION' ) ) {
			define( 'FFC_VERSION', '0.0.0' );
		}

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'admin_url' )->returnArg( 1 );
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce' );
		Functions\when( 'wp_enqueue_script' )->alias(
			function ( $handle, $src = '' ) {
				$this->scripts[ (string) $handle ] = (string) $src;
			}
		);
		Functions\when( 'wp_localize_script' )->alias(
			function ( $handle, $name, $data ) {
				$this->localised[] = array(
					'handle' => (string) $handle,
					'name'   => (string) $name,
					'data'   => (array) $data,
				);

				return true;
			}
		);
	}

	/**
	 * Tear down Brain\Monkey.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The view's source.
	 *
	 * @return string
	 */
	private static function view(): string {
		$path = dirname( __DIR__, 2 ) . '/includes/admin/views/identity-resolution-page.php';

		return (string) file_get_contents( $path );
	}

	/**
	 * A script's source.
	 *
	 * @param string $name The file under `assets/js/`.
	 * @return string
	 */
	private static function script( string $name = 'ffc-identity-search.js' ): string {
		$path = dirname( __DIR__, 2 ) . '/assets/js/' . $name;

		return (string) file_get_contents( $path );
	}

	/**
	 * The dialog loads on the identity screen and on no other.
	 */
	public function test_the_script_loads_on_this_screen_only(): void {
		$page = new IdentityResolutionPage();

		$page->enqueue( 'users.php' );
		$this->assertSame( array(), $this->scripts, 'The dialog must not load on a screen that is not ours.' );

		$page->enqueue( 'ffc_form_page_' . IdentityResolutionPage::MENU_SLUG );

		$this->assertArrayHasKey( 'ffc-identity-search', $this->scripts );
		$this->assertStringEndsWith( 'assets/js/ffc-identity-search.js', $this->scripts['ffc-identity-search'] );
		$this->assertArrayHasKey( 'ffc-identity-preflight', $this->scripts );
		$this->assertStringEndsWith( 'assets/js/ffc-identity-preflight.js', $this->scripts['ffc-identity-preflight'] );
	}

	/**
	 * The preflight script is told the action ITS endpoint registers, which is
	 * a different one from the dialog's.
	 */
	public function test_the_preflight_is_localised_with_its_own_action(): void {
		$page = new IdentityResolutionPage();
		$page->enqueue( 'ffc_form_page_' . IdentityResolutionPage::MENU_SLUG );

		$found = array();

		foreach ( $this->localised as $entry ) {
			$found[ $entry['name'] ] = $entry['data'];
		}

		$this->assertArrayHasKey( 'ffcIdentityPreflight', $found );
		$this->assertSame(
			IdentityPreflightAjaxEndpoint::AJAX_ACTION,
			$found['ffcIdentityPreflight']['action'] ?? ''
		);
		$this->assertNotSame(
			IdentitySearchAjaxEndpoint::AJAX_ACTION,
			$found['ffcIdentityPreflight']['action'] ?? '',
			'Two endpoints under one action is a copy-paste that would route every check to the search.'
		);
		$this->assertNotSame( '', (string) ( $found['ffcIdentityPreflight']['nonce'] ?? '' ) );
	}

	/**
	 * Every id the PREFLIGHT script reads is printed by the view too.
	 *
	 * Same scan as its sibling below, over the other file — the ids it reads
	 * are built by concatenation, so they are checked as prefixes on the data
	 * attributes that name them.
	 */
	public function test_the_preflight_names_ids_the_view_emits(): void {
		$view = self::view();

		$pairs = array(
			'data-ffc-value="ffc-rf-'     => 'id="ffc-rf-',
			'data-ffc-verdict="ffc-check-' => 'id="ffc-check-',
		);

		foreach ( $pairs as $named => $emitted ) {
			$this->assertStringContainsString( $named, $view, 'The Check button must name its target.' );
			$this->assertStringContainsString( $emitted, $view, $named . ': what it names must be emitted as an id.' );
		}

		// Every `data-` key the script reads off the verdict region must be
		// printed, or the region renders an empty sentence.
		preg_match_all( "/\\\$region\\.data\\('([a-z]+)'\\)/", self::script( 'ffc-identity-preflight.js' ), $matches );

		$this->assertNotEmpty( $matches[1], 'The scan found no data lookups, so it proves nothing.' );

		foreach ( array_unique( $matches[1] ) as $key ) {
			$this->assertStringContainsString(
				'data-' . $key . '=',
				$view,
				$key . ': the script reads this attribute, so the view must print it.'
			);
		}
	}

	/**
	 * The script is told the action the endpoint actually registers.
	 *
	 * A literal on either side is how the two drift; both read the constant,
	 * and this fails if one stops.
	 */
	public function test_the_localised_action_is_the_registered_one(): void {
		$page = new IdentityResolutionPage();
		$page->enqueue( 'ffc_form_page_' . IdentityResolutionPage::MENU_SLUG );

		// BY NAME, NOT BY POSITION OR BY COUNT.
		//
		// This asserted `assertCount( 1, … )` and broke the moment the screen
		// enqueued a second script — a claim about a number that says nothing
		// about the thing under test, which is `CLAUDE.md`'s own rule turning
		// up inside a test rather than in prose.
		$found = array();

		foreach ( $this->localised as $entry ) {
			$found[ $entry['name'] ] = $entry['data'];
		}

		$this->assertArrayHasKey( 'ffcIdentitySearch', $found );
		$this->assertSame(
			IdentitySearchAjaxEndpoint::AJAX_ACTION,
			$found['ffcIdentitySearch']['action'] ?? ''
		);
		$this->assertNotSame( '', (string) ( $found['ffcIdentitySearch']['nonce'] ?? '' ) );
	}

	/**
	 * Every id the search button names is an id the view emits.
	 *
	 * The button carries four: the number input it writes into, the submit it
	 * relabels, the split form it bars, and — through the form — the chosen
	 * note. Each is built by concatenating a prefix onto the subject, so the
	 * check is on the PREFIXES, which is the only form a static scan can see
	 * (the `CssClassEmitters` finding, one screen along).
	 */
	public function test_the_button_names_ids_the_view_emits(): void {
		$view = self::view();

		$pairs = array(
			'data-ffc-input="ffc-relink-'     => 'id="ffc-relink-',
			'data-ffc-submit="ffc-relink-go-' => 'id="ffc-relink-go-',
			'data-ffc-split="ffc-split-form-' => 'id="ffc-split-form-',
		);

		foreach ( $pairs as $named => $emitted ) {
			$this->assertStringContainsString(
				$named,
				$view,
				'The search button must name its target.'
			);
			$this->assertStringContainsString(
				$emitted,
				$view,
				$named . ': what the button names must be emitted as an id.'
			);
		}

		$this->assertStringContainsString( 'class="ffc-identity-chosen"', $view );
		$this->assertStringContainsString( 'ffc-identity-split-barred', $view );
	}

	/**
	 * Every element the script looks up by id exists in the view.
	 *
	 * This is the half that actually breaks silently: `$('#missing')` is an
	 * empty set, and jQuery is happy to do nothing to it.
	 */
	public function test_every_id_the_script_reads_is_printed_by_the_view(): void {
		$view = self::view();

		preg_match_all( "/\\\$\\('#(ffc-identity-[a-z-]+)'\\)/", self::script(), $matches );

		$this->assertNotEmpty( $matches[1], 'The scan found no id lookups, so it proves nothing.' );

		foreach ( array_unique( $matches[1] ) as $id ) {
			$this->assertStringContainsString(
				'id="' . $id . '"',
				$view,
				$id . ': the script reads this element, so the view must print it.'
			);
		}
	}

	/**
	 * The script names only classes and hooks the view prints.
	 */
	public function test_the_script_and_the_view_agree_on_the_dismiss_hook(): void {
		$this->assertStringContainsString( 'data-ffc-dialog-dismiss', self::view() );
		$this->assertStringContainsString( 'data-ffc-dialog-dismiss', self::script() );
		$this->assertStringContainsString( 'ffc-identity-find', self::view() );
		$this->assertStringContainsString( 'ffc-identity-find', self::script() );
	}
}
