<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Settings\SettingsReader;

/**
 * Tests for SettingsReader: generic + typed accessors over `ffc_settings`.
 *
 * @covers \FreeFormCertificate\Settings\SettingsReader
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class SettingsReaderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Helper: stub `get_option('ffc_settings', [])` to return $settings.
	 *
	 * @param array<string, mixed> $settings
	 */
	private function stub_option( array $settings ): void {
		Functions\when( 'get_option' )->alias( function ( $key, $default = null ) use ( $settings ) {
			if ( SettingsReader::OPTION_KEY === $key ) {
				return $settings;
			}
			return $default;
		} );
	}

	// ------------------------------------------------------------------
	// Generic accessors
	// ------------------------------------------------------------------

	public function test_get_returns_value_when_key_present(): void {
		$this->stub_option( array( 'foo' => 'bar', 'baz' => 42 ) );

		$this->assertSame( 'bar', SettingsReader::get( 'foo' ) );
		$this->assertSame( 42, SettingsReader::get( 'baz' ) );
	}

	public function test_get_returns_default_when_key_absent(): void {
		$this->stub_option( array( 'foo' => 'bar' ) );

		$this->assertSame( 'fallback', SettingsReader::get( 'missing', 'fallback' ) );
		$this->assertNull( SettingsReader::get( 'missing' ) );
	}

	public function test_get_returns_default_when_settings_is_not_array(): void {
		// Misconfigured option returning false or a scalar instead of array.
		Functions\when( 'get_option' )->justReturn( false );

		$this->assertSame( 'fallback', SettingsReader::get( 'anything', 'fallback' ) );
	}

	public function test_all_returns_raw_array(): void {
		$this->stub_option( array( 'a' => 1, 'b' => 'two' ) );

		$this->assertSame( array( 'a' => 1, 'b' => 'two' ), SettingsReader::all() );
	}

	public function test_all_returns_empty_array_when_option_is_not_array(): void {
		Functions\when( 'get_option' )->justReturn( false );

		$this->assertSame( array(), SettingsReader::all() );
	}

	public function test_get_bool_casts_truthy_values_to_true(): void {
		$this->stub_option( array(
			'as_one'    => 1,
			'as_string' => '1',
			'as_true'   => true,
			'as_yes'    => 'yes',
		) );

		$this->assertTrue( SettingsReader::get_bool( 'as_one' ) );
		$this->assertTrue( SettingsReader::get_bool( 'as_string' ) );
		$this->assertTrue( SettingsReader::get_bool( 'as_true' ) );
		$this->assertTrue( SettingsReader::get_bool( 'as_yes' ) );
	}

	public function test_get_bool_casts_falsy_values_to_false(): void {
		$this->stub_option( array(
			'as_zero'         => 0,
			'as_string_zero'  => '0',
			'as_false'        => false,
			'as_empty_string' => '',
		) );

		$this->assertFalse( SettingsReader::get_bool( 'as_zero' ) );
		$this->assertFalse( SettingsReader::get_bool( 'as_string_zero' ) );
		$this->assertFalse( SettingsReader::get_bool( 'as_false' ) );
		$this->assertFalse( SettingsReader::get_bool( 'as_empty_string' ) );
	}

	public function test_get_bool_returns_default_when_key_absent(): void {
		$this->stub_option( array() );

		$this->assertFalse( SettingsReader::get_bool( 'missing' ) );
		$this->assertTrue( SettingsReader::get_bool( 'missing', true ) );
	}

	public function test_get_int_casts_numeric_strings(): void {
		$this->stub_option( array(
			'as_int'    => 42,
			'as_string' => '99',
			'as_float'  => 3.7,
		) );

		$this->assertSame( 42, SettingsReader::get_int( 'as_int' ) );
		$this->assertSame( 99, SettingsReader::get_int( 'as_string' ) );
		$this->assertSame( 3, SettingsReader::get_int( 'as_float' ) );
	}

	public function test_get_int_returns_default_when_key_absent(): void {
		$this->stub_option( array() );

		$this->assertSame( 0, SettingsReader::get_int( 'missing' ) );
		$this->assertSame( 600, SettingsReader::get_int( 'missing', 600 ) );
	}

	// ------------------------------------------------------------------
	// Typed bool accessors
	// ------------------------------------------------------------------

	public function test_emails_disabled_reads_disable_all_emails_key(): void {
		$this->stub_option( array( 'disable_all_emails' => 1 ) );
		$this->assertTrue( SettingsReader::emails_disabled() );

		$this->stub_option( array() );
		$this->assertFalse( SettingsReader::emails_disabled() );
	}

	public function test_activity_log_enabled_reads_enable_activity_log_key(): void {
		$this->stub_option( array( 'enable_activity_log' => 1 ) );
		$this->assertTrue( SettingsReader::activity_log_enabled() );

		$this->stub_option( array() );
		$this->assertFalse( SettingsReader::activity_log_enabled() );
	}

	public function test_activity_log_min_level_defaults_to_debug(): void {
		$this->stub_option( array() );
		$this->assertSame( 'debug', SettingsReader::activity_log_min_level() );
	}

	public function test_activity_log_min_level_reads_and_validates(): void {
		$this->stub_option( array( 'activity_log_min_level' => 'warning' ) );
		$this->assertSame( 'warning', SettingsReader::activity_log_min_level() );

		// Invalid value falls back to debug.
		$this->stub_option( array( 'activity_log_min_level' => 'bogus' ) );
		$this->assertSame( 'debug', SettingsReader::activity_log_min_level() );
	}

	public function test_activity_log_category_enabled_defaults_true(): void {
		$this->stub_option( array() );
		$this->assertTrue( SettingsReader::activity_log_category_enabled( 'submissions' ) );

		$this->stub_option( array( 'activity_log_cat_submissions' => 0 ) );
		$this->assertFalse( SettingsReader::activity_log_category_enabled( 'submissions' ) );
	}

	public function test_url_shortener_enabled_defaults_to_true(): void {
		// Key absent → default true (feature is on out-of-the-box).
		$this->stub_option( array() );
		$this->assertTrue( SettingsReader::url_shortener_enabled() );

		// Explicit off.
		$this->stub_option( array( 'url_shortener_enabled' => 0 ) );
		$this->assertFalse( SettingsReader::url_shortener_enabled() );
	}

	public function test_url_shortener_auto_create_enabled_defaults_to_true(): void {
		$this->stub_option( array() );
		$this->assertTrue( SettingsReader::url_shortener_auto_create_enabled() );

		$this->stub_option( array( 'url_shortener_auto_create' => 0 ) );
		$this->assertFalse( SettingsReader::url_shortener_auto_create_enabled() );
	}

	/**
	 * @dataProvider provider_bool_accessors_default_false
	 * @param string $method   Accessor method name.
	 * @param string $key      Option key it reads.
	 */
	public function test_bool_accessors_default_to_false( string $method, string $key ): void {
		$this->stub_option( array() );
		$this->assertFalse( SettingsReader::$method() );

		$this->stub_option( array( $key => 1 ) );
		$this->assertTrue( SettingsReader::$method() );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function provider_bool_accessors_default_false(): array {
		return array(
			'emails_disabled'                 => array( 'emails_disabled', 'disable_all_emails' ),
			'activity_log_enabled'            => array( 'activity_log_enabled', 'enable_activity_log' ),
			'admin_bar_allowed'               => array( 'admin_bar_allowed', 'allow_admin_bar' ),
			'wp_admin_blocked'                => array( 'wp_admin_blocked', 'block_wp_admin' ),
			'admins_bypassed'                 => array( 'admins_bypassed', 'bypass_for_admins' ),
			'qr_cache_enabled'                => array( 'qr_cache_enabled', 'qr_cache_enabled' ),
			'ip_cache_enabled'                => array( 'ip_cache_enabled', 'ip_cache_enabled' ),
			'ip_api_enabled'                  => array( 'ip_api_enabled', 'ip_api_enabled' ),
			'notify_capability_grant_enabled' => array( 'notify_capability_grant_enabled', 'notify_capability_grant' ),
		);
	}

	// ------------------------------------------------------------------
	// Typed int accessors
	// ------------------------------------------------------------------

	/**
	 * @dataProvider provider_int_accessors
	 * @param string $method   Accessor method name.
	 * @param string $key      Option key it reads.
	 * @param int    $default  Documented default.
	 */
	public function test_int_accessor_returns_default_when_absent( string $method, string $key, int $default ): void {
		$this->stub_option( array() );
		$this->assertSame( $default, SettingsReader::$method() );
	}

	/**
	 * @dataProvider provider_int_accessors
	 * @param string $method   Accessor method name.
	 * @param string $key      Option key it reads.
	 */
	public function test_int_accessor_returns_configured_value( string $method, string $key ): void {
		$this->stub_option( array( $key => 1234 ) );
		$this->assertSame( 1234, SettingsReader::$method() );
	}

	/**
	 * @return array<string, array{string, string, int}>
	 */
	public static function provider_int_accessors(): array {
		return array(
			'activity_log_retention_days' => array( 'activity_log_retention_days', 'activity_log_retention_days', 90 ),
			'cache_expiration_seconds'    => array( 'cache_expiration_seconds', 'cache_expiration', 3600 ),
			'obsolete_shortcode_days'     => array( 'obsolete_shortcode_days', 'obsolete_shortcode_days', 30 ),
			'gps_cache_ttl'               => array( 'gps_cache_ttl', 'gps_cache_ttl', 600 ),
			'ip_cache_ttl'                => array( 'ip_cache_ttl', 'ip_cache_ttl', 600 ),
			'public_csv_default_limit'    => array( 'public_csv_default_limit', 'public_csv_default_limit', 100 ),
			'public_csv_sync_max_rows'    => array( 'public_csv_sync_max_rows', 'public_csv_sync_max_rows', 5000 ),
			'qr_default_size'             => array( 'qr_default_size', 'qr_default_size', 256 ),
			'url_shortener_code_length'   => array( 'url_shortener_code_length', 'url_shortener_code_length', 6 ),
		);
	}

	// ------------------------------------------------------------------
	// OPTION_KEY constant
	// ------------------------------------------------------------------

	public function test_option_key_constant_is_ffc_settings(): void {
		$this->assertSame( 'ffc_settings', SettingsReader::OPTION_KEY );
	}

	// ------------------------------------------------------------------
	// required_certificate_tags()
	// ------------------------------------------------------------------

	public function test_required_certificate_tags_defaults_to_historical_trio(): void {
		$this->stub_option( array() );

		$this->assertSame(
			array( '{{auth_code}}', '{{name}}', '{{cpf_rf}}' ),
			SettingsReader::required_certificate_tags()
		);
	}

	public function test_required_certificate_tags_parses_newline_list(): void {
		$this->stub_option(
			array( 'required_certificate_tags' => "{{auth_code}}\n{{name}}\n{{course}}" )
		);

		$this->assertSame(
			array( '{{auth_code}}', '{{name}}', '{{course}}' ),
			SettingsReader::required_certificate_tags()
		);
	}

	public function test_required_certificate_tags_force_injects_auth_code(): void {
		// Admin removed auth_code — it must come back, first.
		$this->stub_option(
			array( 'required_certificate_tags' => "{{name}}\n{{cpf_rf}}" )
		);

		$this->assertSame(
			array( '{{auth_code}}', '{{name}}', '{{cpf_rf}}' ),
			SettingsReader::required_certificate_tags()
		);
	}

	public function test_required_certificate_tags_dedupes_and_accepts_array(): void {
		$this->stub_option(
			array( 'required_certificate_tags' => array( '{{auth_code}}', '{{name}}', '{{name}}' ) )
		);

		$this->assertSame(
			array( '{{auth_code}}', '{{name}}' ),
			SettingsReader::required_certificate_tags()
		);
	}
}
