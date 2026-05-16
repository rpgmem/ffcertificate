<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\DateFormatter;

/**
 * Unit tests for the canonical date/time formatter.
 *
 * Stubs `get_option`, `wp_date` and `wp_timezone` to drive the
 * formatter in isolation. `wp_date` is stubbed to call PHP's native
 * `gmdate` under UTC so the assertions don't depend on the host's
 * timezone (the production behaviour — locale-aware via
 * `wp_timezone()` — is exercised in integration tests).
 *
 * @covers \FreeFormCertificate\Core\DateFormatter
 */
class DateFormatterTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<string, mixed> */
	private array $ffc_settings = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->ffc_settings = array();

		Functions\when( 'get_option' )->alias( function ( $name, $default = array() ) {
			if ( 'ffc_settings' === $name ) {
				return $this->ffc_settings;
			}
			return $default;
		} );
		Functions\when( 'wp_date' )->alias( function ( $format, $ts = null, $tz = null ) {
			$ts = null === $ts ? time() : (int) $ts;
			return gmdate( $format, $ts );
		} );
		Functions\when( 'wp_timezone' )->alias( static function () {
			return new \DateTimeZone( 'UTC' );
		} );

		DateFormatter::flush_cache();
	}

	protected function tearDown(): void {
		DateFormatter::flush_cache();
		Monkey\tearDown();
		parent::tearDown();
	}

	// ──────────────────────────────────────────────────────────────.
	// Defaults
	// ──────────────────────────────────────────────────────────────.

	public function test_format_date_uses_default_when_settings_unset(): void {
		// 2026-01-04 15:30:00 UTC.
		$this->assertSame( '04/01/2026', DateFormatter::format_date( 1767540600 ) );
	}

	public function test_format_time_uses_default_when_settings_unset(): void {
		$this->assertSame( '15:30', DateFormatter::format_time( 1767540600 ) );
	}

	public function test_format_datetime_uses_default_separator(): void {
		$this->assertSame( '04/01/2026 15:30', DateFormatter::format_datetime( 1767540600 ) );
	}

	public function test_format_datetime_custom_separator(): void {
		$this->assertSame( '04/01/2026 @ 15:30', DateFormatter::format_datetime( 1767540600, 'default', ' @ ' ) );
	}

	// ──────────────────────────────────────────────────────────────.
	// Plugin settings consumed
	// ──────────────────────────────────────────────────────────────.

	public function test_format_date_consumes_ffc_settings_date_format(): void {
		$this->ffc_settings = array( 'date_format' => 'Y-m-d' );
		$this->assertSame( '2026-01-04', DateFormatter::format_date( 1767540600 ) );
	}

	public function test_format_time_consumes_ffc_settings_time_format(): void {
		$this->ffc_settings = array( 'time_format' => 'H:i:s' );
		$this->assertSame( '15:30:00', DateFormatter::format_time( 1767540600 ) );
	}

	public function test_custom_date_format_uses_date_format_custom(): void {
		$this->ffc_settings = array(
			'date_format'        => 'custom',
			'date_format_custom' => 'd-m-Y',
		);
		$this->assertSame( '04-01-2026', DateFormatter::format_date( 1767540600 ) );
	}

	public function test_custom_date_format_falls_back_to_default_when_custom_empty(): void {
		$this->ffc_settings = array(
			'date_format'        => 'custom',
			'date_format_custom' => '',
		);
		$this->assertSame( '04/01/2026', DateFormatter::format_date( 1767540600 ) );
	}

	// ──────────────────────────────────────────────────────────────.
	// PDF context overrides
	// ──────────────────────────────────────────────────────────────.

	public function test_pdf_context_uses_date_format_pdf_when_set(): void {
		$this->ffc_settings = array(
			'date_format'     => 'd/m/Y',
			'date_format_pdf' => 'd \d\e F \d\e Y',
		);
		// gmdate honours escape sequences for `\` literally.
		$this->assertSame( '04 de January de 2026', DateFormatter::format_date( 1767540600, 'pdf' ) );
	}

	public function test_pdf_context_inherits_default_when_pdf_override_empty(): void {
		$this->ffc_settings = array(
			'date_format'     => 'd/m/Y',
			'date_format_pdf' => '',
		);
		$this->assertSame( '04/01/2026', DateFormatter::format_date( 1767540600, 'pdf' ) );
	}

	public function test_pdf_context_for_time_uses_time_format_pdf_when_set(): void {
		$this->ffc_settings = array(
			'time_format'     => 'H:i',
			'time_format_pdf' => 'H:i:s',
		);
		$this->assertSame( '15:30:00', DateFormatter::format_time( 1767540600, 'pdf' ) );
	}

	// ──────────────────────────────────────────────────────────────.
	// Input handling
	// ──────────────────────────────────────────────────────────────.

	public function test_accepts_integer_timestamp(): void {
		$this->assertSame( '04/01/2026', DateFormatter::format_date( 1767540600 ) );
	}

	public function test_accepts_string_via_strtotime(): void {
		$this->assertSame( '04/01/2026', DateFormatter::format_date( '2026-01-04 15:30:00' ) );
	}

	public function test_accepts_numeric_string_as_timestamp(): void {
		$this->assertSame( '04/01/2026', DateFormatter::format_date( '1767540600' ) );
	}

	public function test_null_input_returns_empty_string(): void {
		$this->assertSame( '', DateFormatter::format_date( null ) );
		$this->assertSame( '', DateFormatter::format_time( null ) );
		$this->assertSame( '', DateFormatter::format_datetime( null ) );
	}

	public function test_empty_string_input_returns_empty_string(): void {
		$this->assertSame( '', DateFormatter::format_date( '' ) );
	}

	public function test_unparseable_string_returns_empty_string(): void {
		$this->assertSame( '', DateFormatter::format_date( 'not a date' ) );
	}

	// ──────────────────────────────────────────────────────────────.
	// Resolver public surface
	// ──────────────────────────────────────────────────────────────.

	public function test_resolve_date_format_default(): void {
		$this->assertSame( DateFormatter::DEFAULT_DATE_FORMAT, DateFormatter::resolve_date_format() );
	}

	public function test_resolve_date_format_pdf_inherits_when_unset(): void {
		$this->ffc_settings = array( 'date_format' => 'Y-m-d' );
		$this->assertSame( 'Y-m-d', DateFormatter::resolve_date_format( 'pdf' ) );
	}

	public function test_resolve_time_format_default(): void {
		$this->assertSame( DateFormatter::DEFAULT_TIME_FORMAT, DateFormatter::resolve_time_format() );
	}

	public function test_resolve_time_format_pdf_override(): void {
		$this->ffc_settings = array(
			'time_format'     => 'H:i',
			'time_format_pdf' => 'g:i a',
		);
		$this->assertSame( 'g:i a', DateFormatter::resolve_time_format( 'pdf' ) );
	}

	public function test_settings_cache_flushes_between_runs(): void {
		$this->ffc_settings = array( 'date_format' => 'Y-m-d' );
		$this->assertSame( '2026-01-04', DateFormatter::format_date( 1767540600 ) );

		// Mutate the option; without flush we'd still see the old format.
		$this->ffc_settings = array( 'date_format' => 'd-m-Y' );
		DateFormatter::flush_cache();
		$this->assertSame( '04-01-2026', DateFormatter::format_date( 1767540600 ) );
	}

	// ──────────────────────────────────────────────────────────────
	// Legacy `date_format` containing time chars — strip on read so
	// `format_datetime()` doesn't append the time portion twice.
	// ──────────────────────────────────────────────────────────────.

	public function test_resolve_date_format_strips_time_chars_from_legacy_value(): void {
		$this->ffc_settings = array( 'date_format' => 'd/m/Y H:i' );
		$this->assertSame( 'd/m/Y', DateFormatter::resolve_date_format() );
	}

	public function test_resolve_date_format_strips_time_chars_with_separator(): void {
		$this->ffc_settings = array( 'date_format' => 'F j, Y \a\t g:i a' );
		// `\a` and `\t` are escaped literals, so the "at" word survives.
		$this->assertSame( 'F j, Y \a\t', DateFormatter::resolve_date_format() );
	}

	public function test_format_datetime_no_longer_duplicates_time_for_legacy_value(): void {
		$this->ffc_settings = array(
			'date_format' => 'd/m/Y H:i',
			'time_format' => 'H:i',
		);
		$this->assertSame( '12/05/2026 18:57', DateFormatter::format_datetime( '2026-05-12 18:57:00 UTC' ) );
	}

	public function test_format_date_strips_time_from_legacy_value(): void {
		$this->ffc_settings = array( 'date_format' => 'd/m/Y H:i' );
		$this->assertSame( '12/05/2026', DateFormatter::format_date( '2026-05-12 18:57:00 UTC' ) );
	}

	public function test_resolve_date_format_pdf_override_also_stripped(): void {
		$this->ffc_settings = array(
			'date_format'     => 'd/m/Y',
			'date_format_pdf' => 'F j, Y g:i a',
		);
		$this->assertSame( 'F j, Y', DateFormatter::resolve_date_format( 'pdf' ) );
	}

	public function test_strip_falls_back_to_default_when_result_is_empty(): void {
		$this->ffc_settings = array( 'date_format' => 'H:i:s' );
		$this->assertSame( DateFormatter::DEFAULT_DATE_FORMAT, DateFormatter::resolve_date_format() );
	}

	// ──────────────────────────────────────────────────────────────
	// `strip_time_chars()` public surface — used by the Settings view
	// to smart-match legacy combined formats against the new date-only
	// dropdown (#248). The variant returns '' when stripping clears
	// everything; the runtime resolver applies the default fallback.
	// ──────────────────────────────────────────────────────────────.

	public function test_strip_time_chars_returns_empty_for_time_only_input(): void {
		$this->assertSame( '', DateFormatter::strip_time_chars( 'H:i:s' ) );
	}

	public function test_strip_time_chars_preserves_date_portion(): void {
		$this->assertSame( 'd/m/Y', DateFormatter::strip_time_chars( 'd/m/Y H:i' ) );
		$this->assertSame( 'Y-m-d', DateFormatter::strip_time_chars( 'Y-m-d H:i:s' ) );
	}

	public function test_strip_time_chars_honours_backslash_escapes(): void {
		// `\H` is an escaped literal H — must NOT be stripped.
		$this->assertSame( 'd \H \m Y', DateFormatter::strip_time_chars( 'd \H \m Y' ) );
	}

	// ──────────────────────────────────────────────────────────────
	// PDF override `'custom'` sentinel (#248): when `date_format_pdf`
	// is the literal 'custom', the resolver reads from
	// `date_format_pdf_custom` (same idiom as date_format).
	// ──────────────────────────────────────────────────────────────.

	public function test_pdf_date_format_custom_sentinel_reads_companion_value(): void {
		$this->ffc_settings = array(
			'date_format'            => 'd/m/Y',
			'date_format_pdf'        => 'custom',
			'date_format_pdf_custom' => 'd \d\e F \d\e Y',
		);
		$this->assertSame( 'd \d\e F \d\e Y', DateFormatter::resolve_date_format( 'pdf' ) );
	}

	public function test_pdf_date_format_custom_sentinel_with_empty_companion_falls_back_to_base(): void {
		$this->ffc_settings = array(
			'date_format'            => 'F j, Y',
			'date_format_pdf'        => 'custom',
			'date_format_pdf_custom' => '',
		);
		// Empty custom companion → fall through to date_format base.
		$this->assertSame( 'F j, Y', DateFormatter::resolve_date_format( 'pdf' ) );
	}
}
