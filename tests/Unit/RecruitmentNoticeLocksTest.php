<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentNoticeLocks;

/**
 * The table of which change a notice takes in which status (#2245).
 *
 * Written out in full, so a change to any cell is a change to this test.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentNoticeLocks
 */
class RecruitmentNoticeLocksTest extends TestCase {

	private const STATUSES = array( 'draft', 'preliminary', 'definitive', 'closed' );

	/**
	 * Change => the statuses that take it.
	 */
	private const EXPECTED = array(
		RecruitmentNoticeLocks::IMPORT_PREVIEW    => array( 'draft', 'preliminary' ),
		RecruitmentNoticeLocks::IMPORT_DEFINITIVE => array( 'preliminary' ),
		RecruitmentNoticeLocks::PROMOTE           => array( 'preliminary' ),
		RecruitmentNoticeLocks::ADJUTANCIES       => array( 'draft', 'preliminary' ),
		RecruitmentNoticeLocks::ROW_ADJUTANCY     => array( 'draft', 'preliminary' ),
		RecruitmentNoticeLocks::PREVIEW_MARKS     => array( 'preliminary' ),
		RecruitmentNoticeLocks::CALLS             => array( 'definitive' ),
		RecruitmentNoticeLocks::DELETE            => array( 'draft' ),
	);

	public function test_every_cell_of_the_matrix(): void {
		$this->assertSame( array_keys( self::EXPECTED ), RecruitmentNoticeLocks::changes(), 'The table knows exactly these changes.' );
		foreach ( self::EXPECTED as $change => $allowed ) {
			$this->assertSame( $allowed, RecruitmentNoticeLocks::statuses( $change ) );
			foreach ( self::STATUSES as $status ) {
				$this->assertSame(
					in_array( $status, $allowed, true ),
					RecruitmentNoticeLocks::allows( $change, $status ),
					$change . ' on a ' . $status . ' notice'
				);
			}
		}
	}

	public function test_a_closed_notice_takes_no_change(): void {
		foreach ( RecruitmentNoticeLocks::changes() as $change ) {
			$this->assertFalse( RecruitmentNoticeLocks::allows( $change, 'closed' ), $change );
		}
	}

	public function test_an_unknown_change_or_status_is_refused(): void {
		$this->assertFalse( RecruitmentNoticeLocks::allows( 'no_such_change', 'draft' ), 'A typo locks rather than opens.' );
		$this->assertSame( array(), RecruitmentNoticeLocks::statuses( 'no_such_change' ) );
		$this->assertFalse( RecruitmentNoticeLocks::allows( RecruitmentNoticeLocks::IMPORT_PREVIEW, 'bogus' ) );
	}
}
