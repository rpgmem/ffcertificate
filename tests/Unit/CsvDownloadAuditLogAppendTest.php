<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use FreeFormCertificate\Frontend\Csv\CsvDownloadAuditLog;
use PHPUnit\Framework\TestCase;

/**
 * The download log's append rule (#2341): a repeated failure folds into one
 * row, and failures and records are capped apart, so a stream of bad
 * requests never pushes a delivery out.
 *
 * @covers \FreeFormCertificate\Frontend\Csv\CsvDownloadAuditLog
 */
class CsvDownloadAuditLogAppendTest extends TestCase {

	/**
	 * An entry as the validator writes it.
	 *
	 * @param string $result Outcome tag.
	 * @param int    $ts     Time.
	 * @param string $fp     Address fingerprint.
	 * @param string $mode   CPF gate mode.
	 * @return array<string, mixed>
	 */
	private static function entry( string $result, int $ts, string $fp = 'fp-a', string $mode = 'captcha' ): array {
		return array(
			'ts'            => $ts,
			'ip_encrypted'  => 'cipher-' . $ts,
			'ip_fp'         => $fp,
			'mode'          => $mode,
			'cpf_encrypted' => '',
			'result'        => $result,
		);
	}

	public function test_a_repeated_failure_folds_into_one_row(): void {
		$log = array();
		$log = CsvDownloadAuditLog::append( $log, self::entry( 'fail_captcha', 100 ), 100 );
		$log = CsvDownloadAuditLog::append( $log, self::entry( 'download_delivered', 150 ), 100 );
		$log = CsvDownloadAuditLog::append( $log, self::entry( 'fail_captcha', 200 ), 100 );
		$log = CsvDownloadAuditLog::append( $log, self::entry( 'fail_captcha', 300 ), 100 );

		$this->assertCount( 2, $log );
		$this->assertSame( 'download_delivered', $log[0]['result'], 'The folded row moves to the end, in order of last attempt.' );
		$this->assertSame( 'fail_captcha', $log[1]['result'] );
		$this->assertSame( 3, $log[1]['count'] );
		$this->assertSame( 100, $log[1]['first_ts'] );
		$this->assertSame( 300, $log[1]['ts'] );
		$this->assertSame( 3, CsvDownloadAuditLog::times( $log[1] ) );
	}

	public function test_failures_that_differ_are_kept_apart(): void {
		$log = array();
		$log = CsvDownloadAuditLog::append( $log, self::entry( 'fail_captcha', 1 ), 100 );
		$log = CsvDownloadAuditLog::append( $log, self::entry( 'fail_captcha', 2, 'fp-b' ), 100 );
		$log = CsvDownloadAuditLog::append( $log, self::entry( 'fail_match', 3 ), 100 );
		$log = CsvDownloadAuditLog::append( $log, self::entry( 'fail_captcha', 4, 'fp-a', 'audit' ), 100 );

		$this->assertCount( 4, $log );
		foreach ( $log as $row ) {
			$this->assertArrayNotHasKey( 'count', $row );
		}
	}

	public function test_records_are_never_folded(): void {
		$log = array();
		$log = CsvDownloadAuditLog::append( $log, self::entry( 'download_delivered', 1 ), 100 );
		$log = CsvDownloadAuditLog::append( $log, self::entry( 'download_delivered', 2 ), 100 );

		$this->assertCount( 2, $log );
	}

	public function test_a_failure_without_a_fingerprint_is_not_folded(): void {
		$log = array();
		$log = CsvDownloadAuditLog::append( $log, self::entry( 'fail_other', 1, '' ), 100 );
		$log = CsvDownloadAuditLog::append( $log, self::entry( 'fail_other', 2, '' ), 100 );

		$this->assertCount( 2, $log );
	}

	public function test_failures_never_push_a_delivery_out(): void {
		$log = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$log = CsvDownloadAuditLog::append( $log, self::entry( 'download_delivered', $i ), 5 );
		}
		// Failures from many addresses, so none folds.
		for ( $i = 0; $i < 50; $i++ ) {
			$log = CsvDownloadAuditLog::append( $log, self::entry( 'fail_captcha', 100 + $i, 'fp-' . $i ), 5 );
		}

		$deliveries = array_filter( $log, static fn( $row ) => 'download_delivered' === $row['result'] );
		$failures   = array_values( array_filter( $log, static fn( $row ) => 'fail_captcha' === $row['result'] ) );
		$this->assertCount( 5, $deliveries );
		$this->assertCount( 5, $failures );
		$this->assertSame( 145, $failures[0]['ts'], 'The newest failures stay.' );
	}

	public function test_records_are_capped_among_themselves(): void {
		$log = array( self::entry( 'fail_captcha', 0 ) );
		for ( $i = 1; $i <= 4; $i++ ) {
			$log = CsvDownloadAuditLog::append( $log, self::entry( 'download_delivered', $i ), 3 );
		}

		$this->assertSame( array( 'fail_captcha', 'download_delivered', 'download_delivered', 'download_delivered' ), array_column( $log, 'result' ) );
		$this->assertSame( 2, $log[1]['ts'], 'The oldest record left.' );
	}

	public function test_an_unknown_tag_counts_as_a_failure(): void {
		$this->assertTrue( CsvDownloadAuditLog::is_failure( 'fail_new_reason' ) );
		$this->assertTrue( CsvDownloadAuditLog::is_failure( 'something_else' ) );
		$this->assertFalse( CsvDownloadAuditLog::is_failure( 'download_delivered' ) );
		$this->assertFalse( CsvDownloadAuditLog::is_failure( 'success' ) );
	}
}
