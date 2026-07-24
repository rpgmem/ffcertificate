<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Audience\AudienceSampleCsvSource;

/**
 * Tests for AudienceSampleCsvSource: the downloadable import-template source
 * derives its header + rows from the single source of truth
 * {@see \FreeFormCertificate\Audience\AudienceCsvImporter::get_sample_rows()}
 * and picks the right filename per type. authorize() is a no-op (the page
 * handler gates). (Issue #772.)
 *
 * @covers \FreeFormCertificate\Audience\AudienceSampleCsvSource
 */
class AudienceSampleCsvSourceTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		class_exists( '\\FreeFormCertificate\Audience\AudienceSampleCsvSource' );
	}

	public function test_members_variant(): void {
		$source = new AudienceSampleCsvSource( 'members' );
		$this->assertSame( 'members-sample.csv', $source->filename() );
		$this->assertSame( array( 'email', 'name', 'audience_name' ), $source->header() );
		$rows = $source->rows();
		$this->assertContains( array( 'john@example.com', 'John Doe', 'Group A' ), $rows );
	}

	public function test_audiences_variant(): void {
		$source = new AudienceSampleCsvSource( 'audiences' );
		$this->assertSame( 'audiences-sample.csv', $source->filename() );
		$this->assertSame( array( 'name', 'color', 'parent' ), $source->header() );
		$rows = $source->rows();
		// A parent row (empty parent) and a child row (parent set) are present.
		$this->assertContains( array( 'Group A', '#3788d8', '' ), $rows );
		$this->assertContains( array( 'Subgroup A1', '#dc3545', 'Group A' ), $rows );
	}

	public function test_unknown_type_falls_back_to_members(): void {
		$source = new AudienceSampleCsvSource( 'garbage' );
		$this->assertSame( 'members-sample.csv', $source->filename() );
		$this->assertSame( array( 'email', 'name', 'audience_name' ), $source->header() );
	}

	public function test_authorize_is_noop(): void {
		// No cap/nonce funcs are stubbed; a no-op authorize() must not call any.
		( new AudienceSampleCsvSource( 'members' ) )->authorize();
		$this->assertTrue( true );
	}
}
