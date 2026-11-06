<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\SettingsSaveHandler;

/**
 * Saving one Settings tab leaves every other tab's keys alone (#2338).
 *
 * Every tab posts to the same handler with its `_ffc_tab` marker. A section
 * that ran on every Save rebuilt its switches from a POST that never carried
 * them, so Save on Advanced, SMTP or Cache turned off auto-delete, the RF
 * check digit and the CEP lookup. This test stores a sentinel under every
 * key, saves each tab with an empty form, and asserts that only keys the
 * tab's own view names changed.
 *
 * A key belongs to a tab when its view file names it. Two tabs render part
 * of their form through a shared class rather than literal names: the QR
 * Code tab (`qr_design_*`, drawn by the design form) and Public Design
 * (`PublicDesign::KEYS`); the Advanced tab builds its `activity_log_item_*`
 * names from `ActivityLogItems::ITEMS`.
 *
 * @covers \FreeFormCertificate\Admin\SettingsSaveHandler
 */
class SettingsSaveTabIsolationTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Tab marker => view file.
	 */
	private const TABS = array(
		'general'       => 'ffc-tab-general.php',
		'certificates'  => 'ffc-tab-certificates.php',
		'advanced'      => 'ffc-tab-advanced.php',
		'cache'         => 'ffc-tab-cache.php',
		'smtp'          => 'ffc-tab-smtp.php',
		'qr_code'       => 'ffc-tab-qr-code.php',
		'captcha'       => 'ffc-tab-captcha.php',
		'url_shortener' => 'ffc-tab-url-shortener.php',
		'public_design' => 'ffc-tab-public-design.php',
	);

	/**
	 * Sentinel stored under every key.
	 */
	private const SENTINEL = 'sentinel-value';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->alias( static fn( $key ) => preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ) );
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'sanitize_hex_color' )->returnArg();
		Functions\when( 'absint' )->alias( static fn( $val ) => abs( (int) $val ) );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'is_ssl' )->justReturn( true );
		Functions\when( 'get_post_mime_type' )->justReturn( '' );
		Functions\when( 'number_format_i18n' )->alias( static fn( $n ) => (string) $n );
		Functions\when( 'add_settings_error' )->justReturn( null );
		Functions\when( 'apply_filters' )->alias( static fn( $hook, $value ) => $value );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		// A save audits its Danger Zone changes (#2340); this test is about
		// which keys a tab writes, so nothing is logged here.
		\FreeFormCertificate\Core\ActivityLog::disable_logging();
	}

	protected function tearDown(): void {
		\FreeFormCertificate\Core\ActivityLog::enable_logging();
		unset( $_POST['_ffc_tab'], $_POST['ffc_settings'], $_POST['ffc_settings_nonce'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Every key the settings know: the declared defaults, the autosave
	 * allowlist of `ffc_settings`, and the keys the tab views name.
	 *
	 * @return array<int, string>
	 */
	private function all_keys(): array {
		$keys = array_keys( ( new \FreeFormCertificate\Admin\Settings( Mockery::mock( 'FreeFormCertificate\Submissions\SubmissionHandler' ) ) )->get_default_settings() );
		foreach ( \FreeFormCertificate\Admin\SettingsAjaxEndpoint::allowlist() as $key => $entry ) {
			if ( 'ffc_settings' === ( $entry['option'] ?? '' ) ) {
				$keys[] = (string) ( $entry['path'][0] ?? $key );
			}
		}
		foreach ( array_keys( self::TABS ) as $tab ) {
			$keys = array_merge( $keys, $this->owned( $tab ) );
		}
		return array_values( array_unique( $keys ) );
	}

	/**
	 * The keys a tab's view names.
	 *
	 * @param string $tab Tab marker.
	 * @return array<int, string>
	 */
	private function owned( string $tab ): array {
		$view = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/settings/views/' . self::TABS[ $tab ] );
		preg_match_all( '/\b([a-z][a-z0-9_]{2,})\b/', $view, $m );
		$owned = $m[1];
		if ( 'public_design' === $tab ) {
			$owned = array_merge( $owned, array_values( \FreeFormCertificate\Core\PublicDesign::KEYS ) );
		}
		return array_values( array_unique( $owned ) );
	}

	/**
	 * Whether a key belongs to a tab.
	 *
	 * @param string             $tab   Tab marker.
	 * @param string             $key   Setting key.
	 * @param array<int, string> $owned Keys the view names.
	 * @return bool
	 */
	private function belongs( string $tab, string $key, array $owned ): bool {
		if ( in_array( $key, $owned, true ) ) {
			return true;
		}
		return ( 'qr_code' === $tab && 0 === strpos( $key, 'qr_design_' ) )
			|| ( 'advanced' === $tab && 0 === strpos( $key, \FreeFormCertificate\Core\ActivityLogItems::SETTING_PREFIX ) );
	}

	public function test_saving_a_tab_changes_only_that_tabs_keys(): void {
		$stored = array_fill_keys( $this->all_keys(), self::SENTINEL );
		Functions\when( 'get_option' )->alias( static fn( $name, $fallback = false ) => 'ffc_settings' === $name ? $stored : $fallback );

		$stray = array();
		foreach ( array_keys( self::TABS ) as $tab ) {
			$saved = null;
			Functions\when( 'update_option' )->alias(
				static function ( $name, $value ) use ( &$saved ) {
					if ( 'ffc_settings' === $name ) {
						$saved = $value;
					}
					return true;
				}
			);

			$_POST['_ffc_tab']     = $tab;
			$_POST['ffc_settings'] = array();

			$handler = new SettingsSaveHandler( Mockery::mock( 'FreeFormCertificate\Submissions\SubmissionHandler' ) );
			$method  = new \ReflectionMethod( SettingsSaveHandler::class, 'save_general_and_specific_settings' );
			$method->setAccessible( true );
			$method->invoke( $handler );

			$this->assertIsArray( $saved, "Saving the {$tab} tab wrote nothing." );
			$owned = $this->owned( $tab );
			foreach ( $stored as $key => $value ) {
				if ( ( $saved[ $key ] ?? null ) !== $value && ! $this->belongs( $tab, $key, $owned ) ) {
					$stray[] = "{$tab} → {$key}";
				}
			}
		}

		$this->assertSame( array(), $stray, "Saving a tab changed keys another tab owns:\n  " . implode( "\n  ", $stray ) );
	}

	public function test_the_scan_reads_every_tab_view(): void {
		foreach ( array_keys( self::TABS ) as $tab ) {
			$this->assertContains( 'ffc_settings_nonce', $this->owned( $tab ), "The {$tab} view was not read, or no longer posts the settings form." );
		}
		$this->assertContains( 'cleanup_enabled', $this->owned( 'general' ) );
		$this->assertContains( 'smtp_port', $this->owned( 'smtp' ) );
	}
}
