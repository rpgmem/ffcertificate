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
		// #1030: this is the weakest of the batch, and deliberately so. The
		// contract is that authorize() gates nothing — the page handler does —
		// and this file does not load Brain\Monkey, so NO WordPress function
		// is defined here. A gate of any kind would fatal on the undefined
		// current_user_can()/wp_verify_nonce(); reaching the assertion is what
		// shows there is none. What is asserted is the return contract; the
		// environment is what gives the test its teeth.
		$this->assertNull( ( new AudienceSampleCsvSource( 'members' ) )->authorize() );
	}
}
