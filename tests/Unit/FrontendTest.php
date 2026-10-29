<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Frontend\Frontend;

/**
 * @covers \FreeFormCertificate\Frontend\Frontend
 */
class FrontendTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * The global $wpdb as it was before the test, restored in tearDown.
	 *
	 * @var mixed
	 */
	private $wpdb_backup;

	/**
	 * Whether a global $wpdb existed before the test.
	 *
	 * @var bool
	 */
	private bool $had_wpdb = false;

	/**
	 * The global $post as it was before the test, restored in tearDown.
	 *
	 * @var mixed
	 */
	private $post_backup;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$this->post_backup = $GLOBALS['post'] ?? null;
		// Shared scripts are enqueued through AssetRegistry, which registers
		// them first; false is WordPress's answer for a handle already
		// registered, so nothing is localized twice (#1931).
		Functions\when( 'wp_register_script' )->justReturn( false );

		Functions\when( '__' )->returnArg();
		Functions\when( '_x' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_shortcode' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		// A form with no restriction gets the pre-flight's minimal config
		// (#2030), which asks whether an administrator is under bypass.
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'wp_enqueue_style' )->justReturn( true );
		Functions\when( 'wp_enqueue_script' )->justReturn( true );
		Functions\when( 'wp_localize_script' )->justReturn( true );
		Functions\when( 'wp_create_nonce' )->justReturn( 'test_nonce' );
		Functions\when( 'admin_url' )->justReturn( 'https://example.com/wp-admin/admin-ajax.php' );
		Functions\when( 'wp_parse_args' )->alias( function ( $args, $defaults = array() ) {
			if ( is_array( $args ) ) {
				return array_merge( $defaults, $args );
			}
			return $defaults;
		} );
		// Stubs for the WP shortcode helpers used by
		// localize_geofence_config() since the 6.6.8 fix. Default
		// regex pattern matches `[ffc_form ...]` and captures the
		// attribute string in group 3 (WP's actual layout).
		Functions\when( 'get_shortcode_regex' )->justReturn(
			'\[(\[?)(ffc_form)(?![\w-])([^\]\/]*(?:\/(?!\])[^\]\/]*)*?)(?:(\/)\]|\](?:([^\[]*+(?:\[(?!\/\2\])[^\[]*+)*+)\[\/\2\])?)(\]?)'
		);
		Functions\when( 'shortcode_parse_atts' )->alias( function ( $text ) {
			$atts    = array();
			$pattern = '/(\w+)\s*=\s*"([^"]*)"|(\w+)\s*=\s*\'([^\']*)\'|(\w+)\s*=\s*([^\s\]]+)/';
			if ( preg_match_all( $pattern, (string) $text, $m, PREG_SET_ORDER ) ) {
				foreach ( $m as $row ) {
					$key            = $row[1] !== '' ? $row[1] : ( $row[3] !== '' ? $row[3] : $row[5] );
					$val            = $row[1] !== '' ? $row[2] : ( $row[3] !== '' ? $row[4] : $row[6] );
					$atts[ $key ] = $val;
				}
			}
			return $atts;
		} );

		// PublicCsvDownload::register_hooks() instantiates PublicCsvExporter,
		// whose SubmissionRepository constructor reads $wpdb->prefix. No test
		// here queries the database, so the double answers no method and any
		// query fails the test.
		$wpdb            = Mockery::mock( 'wpdb' );
		$wpdb->prefix    = 'wp_';
		$GLOBALS['wpdb'] = $wpdb;
	}

	protected function tearDown(): void {
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		$GLOBALS['post'] = $this->post_backup;
		Monkey\tearDown();
		parent::tearDown();
	}

	private function makeSubmissionHandlerMock(): object {
		return Mockery::mock( 'FreeFormCertificate\Submissions\SubmissionHandler' );
	}

	// ==================================================================
	// Constructor
	// ==================================================================

	public function test_constructor_registers_hooks(): void {
		$handler = $this->makeSubmissionHandlerMock();
		$frontend = new Frontend( $handler );
		$this->assertInstanceOf( Frontend::class, $frontend );
	}

	// ==================================================================
	// frontend_assets() — no global $post
	// ==================================================================

	public function test_frontend_assets_returns_early_when_no_post(): void {
		global $post;
		$post = null;

		// #1030: asserted nothing, so the guard could be deleted and it would
		// still pass. Collect what gets enqueued and say nothing did.
		$enqueued = array();
		$collect  = static function ( $handle ) use ( &$enqueued ) {
			$enqueued[] = $handle;
		};
		Functions\when( 'wp_enqueue_script' )->alias( $collect );
		Functions\when( 'wp_enqueue_style' )->alias( $collect );

		$handler  = $this->makeSubmissionHandlerMock();
		$frontend = new Frontend( $handler );

		$frontend->frontend_assets();

		$this->assertSame( array(), $enqueued );
	}

	// ==================================================================
	// frontend_assets() — post is not WP_Post
	// ==================================================================

	public function test_frontend_assets_returns_early_when_post_is_not_wp_post(): void {
		global $post;
		$post = 'not_a_post';

		// #1030: asserted nothing, so the guard could be deleted and it would
		// still pass. Collect what gets enqueued and say nothing did.
		$enqueued = array();
		$collect  = static function ( $handle ) use ( &$enqueued ) {
			$enqueued[] = $handle;
		};
		Functions\when( 'wp_enqueue_script' )->alias( $collect );
		Functions\when( 'wp_enqueue_style' )->alias( $collect );

		$handler  = $this->makeSubmissionHandlerMock();
		$frontend = new Frontend( $handler );

		$frontend->frontend_assets();

		$this->assertSame( array(), $enqueued );
	}

	// ==================================================================
	// frontend_assets() — post without shortcodes
	// ==================================================================

	public function test_frontend_assets_returns_early_when_no_shortcodes(): void {
		global $post;
		$post = Mockery::mock( 'WP_Post' );
		$post->post_content = 'Just a regular post.';

		Functions\when( 'has_shortcode' )->justReturn( false );

		// #1030: asserted nothing, so the guard could be deleted and it would
		// still pass. Collect what gets enqueued and say nothing did.
		$enqueued = array();
		$collect  = static function ( $handle ) use ( &$enqueued ) {
			$enqueued[] = $handle;
		};
		Functions\when( 'wp_enqueue_script' )->alias( $collect );
		Functions\when( 'wp_enqueue_style' )->alias( $collect );

		$handler  = $this->makeSubmissionHandlerMock();
		$frontend = new Frontend( $handler );

		$frontend->frontend_assets();

		$this->assertSame( array(), $enqueued );
	}

	// ==================================================================
	// frontend_assets() — post with ffc_form shortcode
	// ==================================================================

	public function test_frontend_assets_enqueues_when_form_shortcode_present(): void {
		global $post;
		$post = Mockery::mock( 'WP_Post' );
		$post->post_content = '[ffc_form id="42"]';

		Functions\when( 'has_shortcode' )->alias( function ( $content, $tag ) {
			return $tag === 'ffc_form' && strpos( $content, '[ffc_form' ) !== false;
		} );

		$enqueued_styles = array();
		$enqueued_scripts = array();
		Functions\when( 'wp_enqueue_style' )->alias( function () use ( &$enqueued_styles ) {
			$enqueued_styles[] = func_get_arg( 0 );
		} );
		Functions\when( 'wp_enqueue_script' )->alias( function () use ( &$enqueued_scripts ) {
			$enqueued_scripts[] = func_get_arg( 0 );
		} );

		$handler = $this->makeSubmissionHandlerMock();
		$frontend = new Frontend( $handler );

		$frontend->frontend_assets();

		$this->assertContains( 'ffc-frontend-css', $enqueued_styles );
		$this->assertContains( 'ffc-frontend-js', $enqueued_scripts );
		$this->assertContains( 'html2canvas', $enqueued_scripts );
		$this->assertContains( 'jspdf', $enqueued_scripts );
	}

	// ==================================================================
	// frontend_assets() — post with ffc_verification shortcode
	// ==================================================================

	public function test_frontend_assets_enqueues_when_verification_shortcode_present(): void {
		global $post;
		$post = Mockery::mock( 'WP_Post' );
		$post->post_content = '[ffc_verification]';

		Functions\when( 'has_shortcode' )->alias( function ( $content, $tag ) {
			return $tag === 'ffc_verification' && strpos( $content, '[ffc_verification' ) !== false;
		} );

		$enqueued_scripts = array();
		Functions\when( 'wp_enqueue_script' )->alias( function () use ( &$enqueued_scripts ) {
			$enqueued_scripts[] = func_get_arg( 0 );
		} );

		$handler = $this->makeSubmissionHandlerMock();
		$frontend = new Frontend( $handler );

		$frontend->frontend_assets();

		$this->assertContains( 'ffc-frontend-js', $enqueued_scripts );
		$this->assertContains( 'ffc-geofence-frontend', $enqueued_scripts );
	}

	/**
	 * The device test's script and sheet load on the verification page only in
	 * its "Test my device" mode (#2040).
	 *
	 * @return void
	 */
	public function test_device_check_assets_load_only_in_device_check_mode(): void {
		global $post;
		$post = Mockery::mock( 'WP_Post' );
		$post->post_content = '[ffc_verification]';

		Functions\when( 'has_shortcode' )->alias( function ( $content, $tag ) {
			return $tag === 'ffc_verification' && strpos( $content, '[ffc_verification' ) !== false;
		} );

		$run = function () {
			$enqueued = array();
			Functions\when( 'wp_enqueue_script' )->alias( function () use ( &$enqueued ) {
				$enqueued[] = func_get_arg( 0 );
			} );
			Functions\when( 'wp_enqueue_style' )->alias( function () use ( &$enqueued ) {
				$enqueued[] = func_get_arg( 0 );
			} );
			( new Frontend( $this->makeSubmissionHandlerMock() ) )->frontend_assets();
			return $enqueued;
		};

		unset( $_GET['ffc_device_check'] );
		$this->assertNotContains( 'ffc-device-check', $run() );

		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_localize_script' )->justReturn( true );
		Functions\when( 'wp_script_is' )->justReturn( false );
		$_GET['ffc_device_check'] = '1';
		try {
			$this->assertContains( 'ffc-device-check', $run() );
		} finally {
			unset( $_GET['ffc_device_check'] );
		}
	}

	// ==================================================================
	// frontend_assets() — geofence config localized
	// ==================================================================

	public function test_frontend_assets_localizes_geofence_config(): void {
		global $post;
		$post = Mockery::mock( 'WP_Post' );
		$post->post_content = '[ffc_form id="99"]';

		Functions\when( 'has_shortcode' )->justReturn( true );

		$localized = array();
		Functions\when( 'wp_localize_script' )->alias( function ( $handle, $name, $data ) use ( &$localized ) {
			$localized[ $name ] = $data;
		} );

		$handler = $this->makeSubmissionHandlerMock();
		$frontend = new Frontend( $handler );

		$frontend->frontend_assets();

		// ffc_ajax localization should contain ajax_url and nonce
		$this->assertArrayHasKey( 'ffc_ajax', $localized );
		$this->assertArrayHasKey( 'ajax_url', $localized['ffc_ajax'] );
		$this->assertArrayHasKey( 'nonce', $localized['ffc_ajax'] );
		$this->assertArrayHasKey( 'strings', $localized['ffc_ajax'] );

		// Geofence config should be localized
		$this->assertArrayHasKey( 'ffcGeofenceConfig', $localized );
		$this->assertArrayHasKey( '_global', $localized['ffcGeofenceConfig'] );

		// A form with no restriction still gets a config, so the visitor
		// pre-flight runs on it (#2030), with both checks off.
		$this->assertSame(
			array(
				'formId'      => 99,
				'adminBypass' => false,
				'bypassInfo'  => null,
				'datetime'    => array( 'enabled' => false ),
				'geo'         => array( 'enabled' => false ),
			),
			$localized['ffcGeofenceConfig'][99]
		);
		$global = $localized['ffcGeofenceConfig']['_global'];
		$this->assertTrue( $global['browserCheck'] );
		$this->assertFalse( $global['altchaOnly'] );
		$this->assertArrayHasKey( 'inAppTitle', $global['strings'] );
		$this->assertArrayHasKey( 'cookieBlockedTitle', $global['strings'] );
		// The in-app banner is gone: its script is never enqueued.
		$this->assertArrayNotHasKey( 'ffc_webview_warning', $localized );
	}

	/**
	 * Stub `get_shortcode_regex` + `shortcode_parse_atts` with simplified
	 * WP-shape implementations so the production regex switch (#XXX 6.6.8)
	 * can be driven through both quoted and unquoted attribute formats.
	 * Returns the captured `wp_localize_script` payload bag.
	 *
	 * @param string $post_content Raw `$post->post_content` to drive.
	 * @return array<string, mixed>
	 */
	private function capture_geofence_for_content( string $post_content ): array {
		global $post;
		$post = Mockery::mock( 'WP_Post' );
		$post->post_content = $post_content;

		Functions\when( 'has_shortcode' )->justReturn( true );

		// Form 42 carries a stored date/time restriction, so the real
		// Geofence builds its config only when the regex+atts path found the
		// id; a missed id would leave no entry, and the minimal pre-flight
		// config a form without a restriction gets has datetime disabled.
		Functions\when( 'get_post_meta' )->alias(
			static function ( $post_id, $key = '', $single = false ) {
				if ( 42 === (int) $post_id && '_ffc_geofence_config' === $key ) {
					return array(
						'datetime_enabled' => '1',
						'date_start'       => '2026-01-01',
						'date_end'         => '2026-12-31',
					);
				}
				return '';
			}
		);

		$localized = array();
		Functions\when( 'wp_localize_script' )->alias( function ( $handle, $name, $data ) use ( &$localized ) {
			$localized[ $name ] = $data;
		} );

		$handler  = $this->makeSubmissionHandlerMock();
		$frontend = new Frontend( $handler );
		$frontend->frontend_assets();

		return $localized;
	}

	public function test_localize_geofence_accepts_quoted_id(): void {
		$localized = $this->capture_geofence_for_content( '[ffc_form id="42"]' );

		$this->assertArrayHasKey( 'ffcGeofenceConfig', $localized );
		// Form id was extracted — `_global` PLUS the per-form key share the array.
		$form_keys = array_filter(
			array_keys( $localized['ffcGeofenceConfig'] ),
			static fn( $k ) => is_int( $k ) || ( is_string( $k ) && ctype_digit( $k ) )
		);
		$this->assertContains( 42, array_map( 'intval', $form_keys ) );
		$this->assertTrue( $localized['ffcGeofenceConfig'][42]['datetime']['enabled'], 'the stored restriction reached the config' );
	}

	public function test_localize_geofence_accepts_unquoted_id(): void {
		// The bug fixed in 6.6.8: pre-fix regex required quotes around
		// the id attribute, so `[ffc_form id=42]` (WP-valid unquoted
		// shorthand) silently failed to localize and the JS geofence
		// never received its config. The form rendered as if no
		// datetime restriction existed.
		$localized = $this->capture_geofence_for_content( '[ffc_form id=42]' );

		$this->assertArrayHasKey( 'ffcGeofenceConfig', $localized );
		$form_keys = array_filter(
			array_keys( $localized['ffcGeofenceConfig'] ),
			static fn( $k ) => is_int( $k ) || ( is_string( $k ) && ctype_digit( $k ) )
		);
		$this->assertContains( 42, array_map( 'intval', $form_keys ), 'unquoted id MUST localize too' );
		$this->assertTrue( $localized['ffcGeofenceConfig'][42]['datetime']['enabled'], 'the stored restriction reached the config' );
	}

	public function test_localize_geofence_accepts_extra_attributes(): void {
		// Same root cause, second failing shape: pre-fix regex required
		// `id=...` to be the LAST attribute before the closing bracket.
		$localized = $this->capture_geofence_for_content( '[ffc_form id="42" class="ffc-custom"]' );

		$this->assertArrayHasKey( 'ffcGeofenceConfig', $localized );
		$form_keys = array_filter(
			array_keys( $localized['ffcGeofenceConfig'] ),
			static fn( $k ) => is_int( $k ) || ( is_string( $k ) && ctype_digit( $k ) )
		);
		$this->assertContains( 42, array_map( 'intval', $form_keys ), 'extra attributes MUST NOT block localize' );
		$this->assertTrue( $localized['ffcGeofenceConfig'][42]['datetime']['enabled'], 'the stored restriction reached the config' );
	}

	// ==================================================================
	// verification_page_robots() — #836 (P2)
	// ==================================================================

	public function test_verification_robots_unchanged_when_no_post(): void {
		global $post;
		$post = null;

		$frontend = new Frontend( $this->makeSubmissionHandlerMock() );
		$result   = $frontend->verification_page_robots( array( 'follow' => true ) );

		$this->assertSame( array( 'follow' => true ), $result );
		$this->assertArrayNotHasKey( 'noindex', $result );
	}

	public function test_verification_robots_noindex_when_shortcode_present(): void {
		global $post;
		$post               = Mockery::mock( 'WP_Post' );
		$post->ID           = 7;
		$post->post_content = '[ffc_verification]';

		Functions\when( 'get_option' )->justReturn( 0 );
		Functions\when( 'has_shortcode' )->alias(
			static fn( $content, $tag ) => 'ffc_verification' === $tag && false !== strpos( $content, '[ffc_verification' )
		);

		$frontend = new Frontend( $this->makeSubmissionHandlerMock() );
		$result   = $frontend->verification_page_robots( array() );

		$this->assertTrue( $result['noindex'] );
	}

	public function test_verification_robots_noindex_when_configured_page_id_matches(): void {
		global $post;
		$post               = Mockery::mock( 'WP_Post' );
		$post->ID           = 55;
		$post->post_content = 'No shortcode here.';

		Functions\when( 'get_option' )->justReturn( 55 );
		Functions\when( 'has_shortcode' )->justReturn( false );

		$frontend = new Frontend( $this->makeSubmissionHandlerMock() );
		$result   = $frontend->verification_page_robots( array() );

		$this->assertTrue( $result['noindex'] );
	}

	public function test_verification_robots_unchanged_on_unrelated_page(): void {
		global $post;
		$post               = Mockery::mock( 'WP_Post' );
		$post->ID           = 10;
		$post->post_content = 'Just a regular page.';

		Functions\when( 'get_option' )->justReturn( 0 );
		Functions\when( 'has_shortcode' )->justReturn( false );

		$frontend = new Frontend( $this->makeSubmissionHandlerMock() );
		$result   = $frontend->verification_page_robots( array( 'follow' => true ) );

		$this->assertSame( array( 'follow' => true ), $result );
		$this->assertArrayNotHasKey( 'noindex', $result );
	}

	/**
	 * The public CSV download runs the browser check too (#2030): its
	 * script waits for the pre-flight and receives the same switch and
	 * strings as a certificate form.
	 */
	public function test_csv_download_page_gets_the_browser_check(): void {
		global $post;
		$post = Mockery::mock( 'WP_Post' );
		$post->post_content = '[ffc_csv_download]';

		Functions\when( 'has_shortcode' )->alias( function ( $content, $tag ) {
			return 'ffc_csv_download' === $tag;
		} );

		$deps = array();
		Functions\when( 'wp_enqueue_script' )->alias( function ( $handle, $src = '', $dependencies = array() ) use ( &$deps ) {
			$deps[ $handle ] = $dependencies;
		} );
		$localized = array();
		Functions\when( 'wp_localize_script' )->alias( function ( $handle, $name, $data ) use ( &$localized ) {
			$localized[ $name ] = $data;
		} );

		$frontend = new Frontend( $this->makeSubmissionHandlerMock() );
		$frontend->frontend_assets();

		$this->assertContains( 'ffc-preflight', $deps['ffc-csv-download'] );
		$this->assertTrue( $localized['ffc_csv_download']['preflight']['browserCheck'] );
		$this->assertArrayHasKey( 'inAppTitle', $localized['ffc_csv_download']['preflight']['strings'] );
	}
}
