<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\IdentityResolutionPage;
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
	 * The script's source.
	 *
	 * @return string
	 */
	private static function script(): string {
		$path = dirname( __DIR__, 2 ) . '/assets/js/ffc-identity-search.js';

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

		$this->assertCount( 1, $this->localised );
		$this->assertSame( 'ffcIdentitySearch', $this->localised[0]['name'] );
		$this->assertSame(
			IdentitySearchAjaxEndpoint::AJAX_ACTION,
			$this->localised[0]['data']['action'] ?? ''
		);
		$this->assertNotSame( '', (string) ( $this->localised[0]['data']['nonce'] ?? '' ) );
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
