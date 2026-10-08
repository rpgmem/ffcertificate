<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * FormsListTable overrides one method of core's posts list table. Core's class
 * is not loaded without WordPress, so a minimal parent is declared here, in a
 * separate process so it cannot leak into another test.
 *
 * @covers \FreeFormCertificate\Admin\FormsListTable
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class FormsListTableTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		if ( ! class_exists( 'WP_Posts_List_Table' ) ) {
			eval( 'class WP_Posts_List_Table extends WP_List_Table {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- a parent class for the unit under test, declared only in this isolated process.
		}
		foreach ( array( '__', 'esc_html', 'esc_attr', 'esc_url', 'wp_unslash', 'sanitize_text_field', 'sanitize_key' ) as $fn ) {
			Functions\when( $fn )->returnArg();
		}
		Functions\when( 'get_post_type_object' )->justReturn( null );
		Functions\when( 'get_post_status_object' )->justReturn( null );
		Functions\when( 'admin_url' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_no_items_prints_the_forms_empty_state(): void {
		$table = new \FreeFormCertificate\Admin\FormsListTable();

		ob_start();
		$table->no_items();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<div class="ffc-empty-state">', $html );
		$this->assertStringContainsString( 'No forms yet', $html );
		$this->assertInstanceOf( \WP_Posts_List_Table::class, $table, 'it stays core\'s table, so views, search and bulk actions are unchanged' );
	}
}
