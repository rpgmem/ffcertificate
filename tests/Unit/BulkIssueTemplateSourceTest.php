<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Admin\BulkIssueTemplateSource;
use PHPUnit\Framework\TestCase;

/**
 * Tests for BulkIssueTemplateSource: the blank participants file of a form (#1945).
 *
 * @covers \FreeFormCertificate\Admin\BulkIssueTemplateSource
 */
class BulkIssueTemplateSourceTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_header_lists_the_required_identifiers_first_then_the_form_fields(): void {
		Functions\when( 'get_post_meta' )->justReturn(
			array(
				array( 'name' => 'course' ),
				array( 'name' => 'email' ),
				array( 'name' => 'cpf_rf' ),
				array( 'name' => 'name' ),
				array( 'label' => 'Heading without a name' ),
			)
		);

		$header = ( new BulkIssueTemplateSource( 12 ) )->header();

		$this->assertSame( array( 'name', 'cpf_rf', 'email', 'course' ), $header );
	}

	public function test_header_is_the_required_identifiers_when_the_form_has_no_fields(): void {
		Functions\when( 'get_post_meta' )->justReturn( '' );

		$this->assertSame( array( 'name', 'cpf_rf', 'email' ), ( new BulkIssueTemplateSource( 12 ) )->header() );
	}

	public function test_a_template_carries_no_rows(): void {
		$this->assertSame( array(), iterator_to_array( ( new BulkIssueTemplateSource( 12 ) )->rows() ) );
	}

	public function test_the_filename_names_the_form(): void {
		$this->assertSame( 'ffc-issue-form-12.csv', ( new BulkIssueTemplateSource( 12 ) )->filename() );
	}
}
