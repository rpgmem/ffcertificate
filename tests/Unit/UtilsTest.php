<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\Utils;

/**
 * Tests for Utils: byte and text formatting, weekday numbers, username slugs
 * and file reads.
 *
 * It once also tested the classes Utils was split into; those cases now live
 * in each class's own test file, and the ones they already had were removed
 * (#2092).
 *
 * @covers \FreeFormCertificate\Core\Utils
 * @runClassInSeparateProcess
 * @preserveGlobalState disabled
 */
class UtilsTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// Several methods this class reaches are deprecated (#2089) and emit
		// the notice; stub it so each test chooses the branch rather than
		// inheriting it from an earlier class.
		\Brain\Monkey\Functions\when( '_deprecated_function' )->justReturn( null );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ==================================================================
	// validate_cpf() — Group A (Pure)
	// ==================================================================








	// ==================================================================
	// validate_phone() — Group A (Pure)
	// ==================================================================








	// ==================================================================
	// format_cpf() — Group A (Pure)
	// ==================================================================




	// ==================================================================
	// validate_rf() — Group A (Pure)
	// ==================================================================






	// ==================================================================
	// format_rf() — Group A (Pure)
	// ==================================================================




	// ==================================================================
	// mask_cpf() — Group A (Pure)
	// ==================================================================






	// ==================================================================
	// format_auth_code() — Group A (Pure)
	// ==================================================================




	// ==================================================================
	// format_document() — Group A (Pure)
	// ==================================================================







	// ==================================================================
	// sanitize_filename() — Group A (Pure)
	// ==================================================================

	// ==================================================================
	// build_pdf_filename() — 6.6.11 standardized PDF filename helper
	// ==================================================================
















	// ==================================================================
	// sanitize_filename()
	// ==================================================================







	// ==================================================================
	// format_bytes() — Group A (Pure)
	// ==================================================================

	public function test_format_bytes_zero(): void {
		$this->assertSame( '0 B', Utils::format_bytes( 0 ) );
	}

	public function test_format_bytes_bytes(): void {
		$this->assertSame( '512 B', Utils::format_bytes( 512 ) );
	}

	public function test_format_bytes_kilobytes(): void {
		$this->assertSame( '1 KB', Utils::format_bytes( 1024 ) );
	}

	public function test_format_bytes_megabytes(): void {
		$this->assertSame( '1.5 MB', Utils::format_bytes( 1572864 ) );
	}

	public function test_format_bytes_gigabytes(): void {
		$this->assertSame( '1 GB', Utils::format_bytes( 1073741824 ) );
	}

	public function test_format_bytes_custom_precision(): void {
		$this->assertSame( '1.5 MB', Utils::format_bytes( 1572864, 1 ) );
	}

	// ==================================================================
	// truncate() — Group A (Pure)
	// ==================================================================

	public function test_truncate_short_text_unchanged(): void {
		$this->assertSame( 'Hello', Utils::truncate( 'Hello', 10 ) );
	}

	public function test_truncate_exact_length_unchanged(): void {
		$this->assertSame( 'Hello', Utils::truncate( 'Hello', 5 ) );
	}

	public function test_truncate_long_text(): void {
		$this->assertSame( 'Hell...', Utils::truncate( 'Hello World', 7 ) );
	}

	public function test_truncate_custom_suffix(): void {
		$this->assertSame( 'Hel--', Utils::truncate( 'Hello World', 5, '--' ) );
	}

	public function test_truncate_default_length(): void {
		$long = str_repeat( 'a', 200 );
		$result = Utils::truncate( $long );
		$this->assertSame( 100, strlen( $result ) );
		$this->assertStringEndsWith( '...', $result );
	}

	// ==================================================================
	// clean_auth_code() — Group A (Pure)
	// ==================================================================




	// ==================================================================
	// clean_identifier() — Group A (Pure)
	// ==================================================================



	// ==================================================================
	// normalize_brazilian_name() — Group A (Pure)
	// ==================================================================









	// ==================================================================
	// PHONE_REGEX constant
	// ==================================================================


	// ==================================================================
	// mask_email() — Group B (WordPress mock)
	// ==================================================================




	// ==================================================================
	// generate_random_string() — Group B (WordPress mock)
	// ==================================================================




	// ==================================================================
	// generate_auth_code() — Group B (WordPress mock)
	// ==================================================================


	// ==================================================================
	// current_user_can_manage() — Group B (WordPress mock)
	// ==================================================================



	// ==================================================================
	// verify_simple_captcha() — Group B (WordPress mock)
	// ==================================================================












	// ==================================================================
	// validate_security_fields() — Group B (WordPress mock)
	// ==================================================================







	// ==================================================================
	// generate_simple_captcha() — Group B (WordPress mock)
	// ==================================================================


	// ==================================================================
	// recursive_sanitize() — Group B (WordPress mock)
	// ==================================================================



	// ==================================================================
	// get_export_filename
	// ==================================================================





	// ==================================================================
	// get_day_of_week_number
	// ==================================================================

	public function test_get_day_of_week_number_for_known_dates(): void {
		// 2026-05-17 was a Sunday (`gmdate('w', ...)` returns 0).
		$sunday_ts = strtotime( '2026-05-17 12:00:00 UTC' );
		$this->assertSame( 0, Utils::get_day_of_week_number( $sunday_ts ) );

		// 2026-05-20 was a Wednesday → 3.
		$wednesday_ts = strtotime( '2026-05-20 12:00:00 UTC' );
		$this->assertSame( 3, Utils::get_day_of_week_number( $wednesday_ts ) );

		// 2026-05-23 was a Saturday → 6.
		$saturday_ts = strtotime( '2026-05-23 12:00:00 UTC' );
		$this->assertSame( 6, Utils::get_day_of_week_number( $saturday_ts ) );
	}

	public function test_get_day_of_week_number_defaults_to_current_time(): void {
		$value = Utils::get_day_of_week_number();

		$this->assertGreaterThanOrEqual( 0, $value );
		$this->assertLessThanOrEqual( 6, $value );
	}

	// ==================================================================
	// sanitize_username_slug
	// ==================================================================

	public function test_sanitize_username_slug_strips_accents_and_invalid_chars(): void {
		// Mock `sanitize_user` to replace spaces with dashes (matches the
		// class of separators the slug helper collapses to '.').
		Functions\when( 'sanitize_user' )->alias( static function ( string $user ): string {
			return strtolower( str_replace( ' ', '-', $user ) );
		} );
		Functions\when( 'remove_accents' )->alias( static function ( string $value ): string {
			return strtr( $value, array( 'á' => 'a', 'é' => 'e', 'ç' => 'c' ) );
		} );

		$this->assertSame( 'jose.silva', Utils::sanitize_username_slug( 'José Silva' ) );
	}

	public function test_sanitize_username_slug_collapses_separators(): void {
		Functions\when( 'sanitize_user' )->returnArg();
		Functions\when( 'remove_accents' )->returnArg();

		$this->assertSame( 'a.b.c', Utils::sanitize_username_slug( 'a---b___c' ) );
	}

	public function test_sanitize_username_slug_trims_leading_trailing_dots(): void {
		Functions\when( 'sanitize_user' )->returnArg();
		Functions\when( 'remove_accents' )->returnArg();

		$this->assertSame( 'abc', Utils::sanitize_username_slug( '...abc...' ) );
	}

	public function test_sanitize_username_slug_returns_empty_when_no_valid_chars(): void {
		Functions\when( 'sanitize_user' )->returnArg();
		Functions\when( 'remove_accents' )->returnArg();

		$this->assertSame( '', Utils::sanitize_username_slug( '...' ) );
	}

	// ==================================================================
	// get_post_array
	// ==================================================================





	// ==================================================================
	// get_post_string / get_get_string
	// ==================================================================







	// ==================================================================
	// get_post_int
	// ==================================================================




	// ==================================================================
	// get_post_bool
	// ==================================================================




	public function test_read_file_contents_returns_contents_of_a_readable_file(): void {
		$path = tempnam( sys_get_temp_dir(), 'ffc' );
		file_put_contents( $path, '<h1>hello</h1>' );

		$this->assertSame( '<h1>hello</h1>', Utils::read_file_contents( $path ) );

		unlink( $path );
	}

	public function test_read_file_contents_returns_empty_string_when_unreadable(): void {
		$this->assertSame(
			'',
			Utils::read_file_contents( sys_get_temp_dir() . '/ffc-does-not-exist-' . uniqid() . '.html' )
		);
	}
}
