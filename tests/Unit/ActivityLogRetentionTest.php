<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\ActivityLogRetention;

/**
 * The activity log's retention bounds, shared by the form save, the autosave
 * and the readers (#2338).
 *
 * @covers \FreeFormCertificate\Core\ActivityLogRetention
 */
class ActivityLogRetentionTest extends TestCase {

	public function test_days_are_clamped(): void {
		$this->assertSame( 0, ActivityLogRetention::clamp_days( -3 ) );
		$this->assertSame( 90, ActivityLogRetention::clamp_days( 90 ) );
		$this->assertSame( ActivityLogRetention::DAYS_MAX, ActivityLogRetention::clamp_days( 99999 ) );
	}

	public function test_records_are_clamped(): void {
		$this->assertSame( ActivityLogRetention::RECORDS_MIN, ActivityLogRetention::clamp_records( 0 ) );
		$this->assertSame( 250000, ActivityLogRetention::clamp_records( 250000 ) );
		$this->assertSame( ActivityLogRetention::RECORDS_MAX, ActivityLogRetention::clamp_records( PHP_INT_MAX ) );
	}

	public function test_the_autosave_reads_the_same_bounds(): void {
		$allowlist = \FreeFormCertificate\Admin\SettingsAjaxEndpoint::allowlist();

		$this->assertSame( ActivityLogRetention::DAYS_MIN, $allowlist['activity_log_retention_days']['min'] );
		$this->assertSame( ActivityLogRetention::DAYS_MAX, $allowlist['activity_log_retention_days']['max'] );
		$this->assertSame( ActivityLogRetention::RECORDS_MIN, $allowlist['activity_log_max_records']['min'] );
		$this->assertSame( ActivityLogRetention::RECORDS_MAX, $allowlist['activity_log_max_records']['max'] );
	}
}
