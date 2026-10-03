<?php
/**
 * Tests for BirthDate — the value rules of a stored birth date (#1538).
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use FreeFormCertificate\Core\BirthDate;
use PHPUnit\Framework\TestCase;

/**
 * @covers \FreeFormCertificate\Core\BirthDate
 */
class BirthDateTest extends TestCase {

	/**
	 * @dataProvider normalizable
	 */
	public function test_normalize_returns_the_iso_form( string $input, string $expected ): void {
		$this->assertSame( $expected, BirthDate::normalize( $input ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function normalizable(): array {
		return array(
			'iso'                => array( '1990-05-20', '1990-05-20' ),
			'iso with spaces'    => array( '  1990-05-20 ', '1990-05-20' ),
			'day first, slashes' => array( '20/05/1990', '1990-05-20' ),
			'day first, dashes'  => array( '20-05-1990', '1990-05-20' ),
			'single digits'      => array( '1/2/1990', '1990-02-01' ),
			'leap day'           => array( '29/02/2000', '2000-02-29' ),
		);
	}

	/**
	 * @dataProvider not_dates
	 */
	public function test_normalize_refuses_what_is_not_a_date( string $input ): void {
		$this->assertNull( BirthDate::normalize( $input ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function not_dates(): array {
		return array(
			'empty'               => array( '' ),
			'blank'               => array( '   ' ),
			'word'                => array( 'yesterday' ),
			'impossible iso'      => array( '1990-02-30' ),
			'impossible day first' => array( '31/04/1990' ),
			'leap day off-year'   => array( '29/02/2001' ),
			'month first'         => array( '05/20/1990' ),
			'two-digit year'      => array( '20/05/90' ),
		);
	}

	public function test_month_day_drops_the_year(): void {
		$this->assertSame( '05-20', BirthDate::month_day( '1990-05-20' ) );
		$this->assertSame( '02-29', BirthDate::month_day( '29/02/2000' ) );
		$this->assertNull( BirthDate::month_day( 'nope' ) );
	}

	public function test_age_on_counts_whole_years_and_turns_on_the_birthday(): void {
		$tz = new \DateTimeZone( 'America/Sao_Paulo' );

		$this->assertSame( 35, BirthDate::age_on( '1990-05-20', new \DateTimeImmutable( '2025-05-20 00:30', $tz ) ) );
		$this->assertSame( 34, BirthDate::age_on( '1990-05-20', new \DateTimeImmutable( '2025-05-19 23:59', $tz ) ) );
		$this->assertNull( BirthDate::age_on( 'nope', new \DateTimeImmutable( '2025-05-20', $tz ) ) );
	}

	public function test_is_plausible_bounds_the_age_and_refuses_the_future(): void {
		$today = new \DateTimeImmutable( '2026-10-03', new \DateTimeZone( 'UTC' ) );

		$this->assertTrue( BirthDate::is_plausible( '1990-05-20', $today ) );
		$this->assertTrue( BirthDate::is_plausible( '2012-10-03', $today ), 'Exactly MIN_AGE today is accepted.' );
		$this->assertFalse( BirthDate::is_plausible( '2012-10-04', $today ), 'One day short of MIN_AGE is refused.' );
		$this->assertTrue( BirthDate::is_plausible( '1916-10-03', $today ), 'Exactly MAX_AGE today is accepted.' );
		$this->assertFalse( BirthDate::is_plausible( '1915-10-02', $today ) );
		$this->assertFalse( BirthDate::is_plausible( '2026-10-04', $today ), 'A future date is never a birth date.' );
		$this->assertFalse( BirthDate::is_plausible( '', $today ) );
	}
}
