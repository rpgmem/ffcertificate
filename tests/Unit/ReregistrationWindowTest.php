<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Reregistration\ReregistrationWindow;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ReregistrationWindow: the campaign window read on the site's
 * wall clock (#2242).
 *
 * @covers \FreeFormCertificate\Reregistration\ReregistrationWindow
 */
class ReregistrationWindowTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @dataProvider state_provider
	 */
	public function test_state_reads_the_window_inclusively( string $now, string $expected ): void {
		$this->assertSame( $expected, ReregistrationWindow::state( '2026-11-01 08:00:00', '2026-11-30 18:00:00', $now ) );
	}

	/** @return array<string, array{string, string}> */
	public static function state_provider(): array {
		return array(
			'a second before it opens' => array( '2026-11-01 07:59:59', ReregistrationWindow::BEFORE ),
			'the moment it opens'      => array( '2026-11-01 08:00:00', ReregistrationWindow::OPEN ),
			'in the middle'            => array( '2026-11-15 12:00:00', ReregistrationWindow::OPEN ),
			'the moment it closes'     => array( '2026-11-30 18:00:00', ReregistrationWindow::OPEN ),
			'a second after it closes' => array( '2026-11-30 18:00:01', ReregistrationWindow::AFTER ),
		);
	}

	public function test_the_site_clock_is_the_default(): void {
		Functions\expect( 'current_time' )->once()->with( 'mysql' )->andReturn( '2026-12-01 00:00:00' );

		$this->assertFalse( ReregistrationWindow::is_open( '2026-11-01 08:00:00', '2026-11-30 18:00:00' ), 'The window closes on the site clock, without waiting for the daily run.' );
	}

	public function test_the_editor_format_reads_the_same_as_the_stored_one(): void {
		$this->assertSame( '2026-11-30 18:00:00', ReregistrationWindow::normalise( '2026-11-30T18:00' ) );
		$this->assertSame( '2026-11-30 00:00:00', ReregistrationWindow::normalise( '2026-11-30' ) );
		$this->assertSame( '2026-11-30 18:00:05', ReregistrationWindow::normalise( '2026-11-30 18:00:05' ) );
		$this->assertSame( '', ReregistrationWindow::normalise( 'not a date' ) );
		$this->assertSame( '', ReregistrationWindow::normalise( '' ) );
	}

	public function test_an_unreadable_end_does_not_close_the_window(): void {
		$this->assertTrue( ReregistrationWindow::is_open( '2026-11-01 08:00:00', '', '2027-01-01 00:00:00' ) );
	}

	/**
	 * @dataProvider days_left_provider
	 */
	public function test_days_left_counts_calendar_days( string $end, string $today, int $expected ): void {
		$this->assertSame( $expected, ReregistrationWindow::days_left( $end, $today ) );
	}

	/** @return array<string, array{string, string, int}> */
	public static function days_left_provider(): array {
		return array(
			// The old count read the wall-clock end as UTC and divided seconds,
			// so in America/Sao_Paulo a campaign closing at 01:00 tomorrow read
			// as 0 days left this evening.
			'ends early tomorrow' => array( '2026-11-30 01:00:00', '2026-11-29', 1 ),
			'ends today'          => array( '2026-11-29 23:59:00', '2026-11-29', 0 ),
			'ends in a week'      => array( '2026-12-06 18:00:00', '2026-11-29', 7 ),
			'already over'        => array( '2026-11-20 18:00:00', '2026-11-29', 0 ),
			'unreadable end'      => array( 'soon', '2026-11-29', 0 ),
			'crosses DST change'  => array( '2026-03-10 12:00:00', '2026-03-01', 9 ),
		);
	}

	public function test_days_left_reads_the_site_date_by_default(): void {
		Functions\expect( 'current_time' )->once()->with( 'Y-m-d' )->andReturn( '2026-11-29' );

		$this->assertSame( 1, ReregistrationWindow::days_left( '2026-11-30 01:00:00' ) );
	}

	public function test_the_closed_message_names_the_side(): void {
		$this->assertStringContainsString( 'not opened yet', ReregistrationWindow::closed_message( ReregistrationWindow::BEFORE ) );
		$this->assertStringContainsString( 'has closed', ReregistrationWindow::closed_message( ReregistrationWindow::AFTER ) );
	}
}
