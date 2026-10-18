<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Core\ReregistrationBannerWindow;
use PHPUnit\Framework\TestCase;

/**
 * When the approved reregistration banner stops showing (#1887).
 *
 * Every instant below is written as a literal UTC timestamp, worked out by hand
 * from the site-timezone wall clock it stands for, so the test does not compute
 * its expectation with the same date arithmetic the class uses. The clock and
 * the timezone are arguments of the method under test: nothing here reads
 * `time()`, so no run can straddle a boundary.
 *
 * São Paulo is UTC-3 all year (no daylight saving since 2019); New York is the
 * daylight-saving case.
 *
 * @covers \FreeFormCertificate\Core\ReregistrationBannerWindow
 */
class ReregistrationBannerWindowTest extends TestCase {

	private \DateTimeZone $sp;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->sp = new \DateTimeZone( 'America/Sao_Paulo' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * End 2026-05-20, no approval, 30 days: the cut-off is the end of
	 * 2026-06-19 in São Paulo, 23:59:59 -03:00 = 2026-06-20 02:59:59 UTC.
	 */
	public function test_boundary_is_inclusive_and_one_second_later_hides(): void {
		$cutoff = gmmktime( 2, 59, 59, 6, 20, 2026 );

		$this->assertFalse( ReregistrationBannerWindow::approved_banner_expired( '2026-05-20', null, 30, $cutoff, $this->sp ), 'Exactly N days after the end of the end day, the banner still shows.' );
		$this->assertTrue( ReregistrationBannerWindow::approved_banner_expired( '2026-05-20', null, 30, $cutoff + 1, $this->sp ), 'One second later it is hidden.' );
	}

	public function test_zero_days_never_hides(): void {
		$far_future = gmmktime( 0, 0, 0, 1, 1, 2099 );

		$this->assertFalse( ReregistrationBannerWindow::approved_banner_expired( '2026-05-20', gmmktime( 12, 0, 0, 5, 1, 2026 ), 0, $far_future, $this->sp ) );
		$this->assertFalse( ReregistrationBannerWindow::approved_banner_expired( '2026-05-20', null, -5, $far_future, $this->sp ), 'A negative window behaves as 0.' );
	}

	/**
	 * Approved 2026-05-10 12:00 in São Paulo, before the end: the end governs,
	 * with the same cut-off as the no-approval case.
	 */
	public function test_approval_before_the_end_the_end_governs(): void {
		$approved = gmmktime( 15, 0, 0, 5, 10, 2026 );
		$cutoff   = gmmktime( 2, 59, 59, 6, 20, 2026 );

		$this->assertFalse( ReregistrationBannerWindow::approved_banner_expired( '2026-05-20', $approved, 30, $cutoff, $this->sp ) );
		$this->assertTrue( ReregistrationBannerWindow::approved_banner_expired( '2026-05-20', $approved, 30, $cutoff + 1, $this->sp ) );
	}

	/**
	 * Approved 2026-06-01 10:00 in São Paulo (13:00 UTC), after the end: the
	 * cut-off is 2026-07-01 10:00 local, and the end-based cut-off is passed
	 * while the banner still shows.
	 */
	public function test_approval_after_the_end_the_approval_governs(): void {
		$approved   = gmmktime( 13, 0, 0, 6, 1, 2026 );
		$end_cutoff = gmmktime( 2, 59, 59, 6, 20, 2026 );
		$cutoff     = gmmktime( 13, 0, 0, 7, 1, 2026 );

		$this->assertFalse( ReregistrationBannerWindow::approved_banner_expired( '2026-05-20', $approved, 30, $end_cutoff + 1, $this->sp ), 'Past the end-based cut-off, the later approval keeps it.' );
		$this->assertFalse( ReregistrationBannerWindow::approved_banner_expired( '2026-05-20', $approved, 30, $cutoff, $this->sp ) );
		$this->assertTrue( ReregistrationBannerWindow::approved_banner_expired( '2026-05-20', $approved, 30, $cutoff + 1, $this->sp ) );
	}

	public function test_a_zero_approval_is_read_as_missing(): void {
		$cutoff = gmmktime( 2, 59, 59, 6, 20, 2026 );

		$this->assertFalse( ReregistrationBannerWindow::approved_banner_expired( '2026-05-20', 0, 30, $cutoff, $this->sp ) );
		$this->assertTrue( ReregistrationBannerWindow::approved_banner_expired( '2026-05-20', 0, 30, $cutoff + 1, $this->sp ) );
	}

	public function test_a_time_on_the_end_date_still_counts_the_whole_day(): void {
		$cutoff = gmmktime( 2, 59, 59, 6, 20, 2026 );

		$this->assertFalse( ReregistrationBannerWindow::approved_banner_expired( '2026-05-20 08:00:00', null, 30, $cutoff, $this->sp ) );
		$this->assertTrue( ReregistrationBannerWindow::approved_banner_expired( '2026-05-20 08:00:00', null, 30, $cutoff + 1, $this->sp ) );
	}

	public function test_an_unreadable_end_falls_back_to_the_approval_or_keeps_the_banner(): void {
		$approved = gmmktime( 13, 0, 0, 6, 1, 2026 );
		$cutoff   = gmmktime( 13, 0, 0, 7, 1, 2026 );

		$this->assertTrue( ReregistrationBannerWindow::approved_banner_expired( '', $approved, 30, $cutoff + 1, $this->sp ) );
		$this->assertFalse( ReregistrationBannerWindow::approved_banner_expired( '2026-02-31', null, 30, gmmktime( 0, 0, 0, 1, 1, 2099 ), $this->sp ), 'Nothing to count from: keep the banner.' );
	}

	/**
	 * The end of the end day is the SITE's end of day. In UTC the same date
	 * ends three hours earlier, so between the two cut-offs the answer depends
	 * on the timezone passed in.
	 */
	public function test_end_of_day_is_read_in_the_site_timezone(): void {
		$utc_cutoff = gmmktime( 23, 59, 59, 6, 19, 2026 );
		$utc        = new \DateTimeZone( 'UTC' );

		$this->assertTrue( ReregistrationBannerWindow::approved_banner_expired( '2026-05-20', null, 30, $utc_cutoff + 1, $utc ) );
		$this->assertFalse( ReregistrationBannerWindow::approved_banner_expired( '2026-05-20', null, 30, $utc_cutoff + 1, $this->sp ), 'Still the 19th, 21:00, in São Paulo.' );
	}

	/**
	 * Days are calendar days on the site's clock: New York moves to daylight
	 * time on 2026-03-08, and the cut-off for an end on 2026-03-01 is still
	 * 23:59:59 local on 2026-03-31 -- -04:00, so 2026-04-01 03:59:59 UTC, not
	 * the 04:59:59 that 30 × 86,400 seconds from -05:00 would give.
	 */
	public function test_days_are_calendar_days_across_a_daylight_saving_change(): void {
		$ny     = new \DateTimeZone( 'America/New_York' );
		$cutoff = gmmktime( 3, 59, 59, 4, 1, 2026 );

		$this->assertFalse( ReregistrationBannerWindow::approved_banner_expired( '2026-03-01', null, 30, $cutoff, $ny ) );
		$this->assertTrue( ReregistrationBannerWindow::approved_banner_expired( '2026-03-01', null, 30, $cutoff + 1, $ny ) );
	}

	public function test_clamp_days_holds_the_range(): void {
		$this->assertSame( 0, ReregistrationBannerWindow::clamp_days( -3 ) );
		$this->assertSame( 0, ReregistrationBannerWindow::clamp_days( 0 ) );
		$this->assertSame( 45, ReregistrationBannerWindow::clamp_days( 45 ) );
		$this->assertSame( 365, ReregistrationBannerWindow::clamp_days( 365 ) );
		$this->assertSame( 365, ReregistrationBannerWindow::clamp_days( 9999 ) );
	}

	public function test_days_reads_the_setting_and_clamps_it_on_read(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		$this->assertSame( ReregistrationBannerWindow::DEFAULT_DAYS, ReregistrationBannerWindow::days(), 'An install that never saved the tab reads the default.' );

		Functions\when( 'get_option' )->justReturn( array( 'rereg_approved_banner_days' => '12' ) );
		$this->assertSame( 12, ReregistrationBannerWindow::days() );

		Functions\when( 'get_option' )->justReturn( array( 'rereg_approved_banner_days' => 5000 ) );
		$this->assertSame( 365, ReregistrationBannerWindow::days(), 'A value stored before the bound is clamped on read.' );
	}
}
