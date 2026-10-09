<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Audience\AudienceTransferList;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * The shared audience picker (#1648).
 *
 * @covers \FreeFormCertificate\Audience\AudienceTransferList
 */
class AudienceTransferListTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_flatten_keeps_three_levels_in_display_order(): void {
		$grandchild = (object) array( 'id' => '3', 'name' => 'Class 3A', 'color' => '#333', 'children' => array() );
		$child      = (object) array( 'id' => '2', 'name' => 'School', 'color' => '#222', 'children' => array( $grandchild ) );
		$root       = (object) array( 'id' => '1', 'name' => 'Region', 'color' => '#111', 'children' => array( $child ) );
		$other      = (object) array( 'id' => '4', 'name' => 'Other', 'children' => array() );

		$flat = AudienceTransferList::flatten( array( $root, $other, 'not a node' ) );

		$this->assertSame( array( 1, 2, 3, 4 ), array_column( $flat, 'id' ) );
		$this->assertSame( array( 0, 1, 2, 0 ), array_column( $flat, 'depth' ) );
		$this->assertSame( array( 0, 1, 2, 0 ), array_column( $flat, 'parent' ) );
		$this->assertSame( array( array( 2 ), array( 3 ), array(), array() ), array_column( $flat, 'children' ) );
		$this->assertSame( '#ccc', $flat[3]['color'], 'An audience without a colour gets the neutral one.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_render_prints_the_data_the_script_reads(): void {
		Functions\when( 'esc_attr' )->alias( static fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES ) );
		Functions\when( 'esc_html_e' )->alias( static fn( $s ) => print $s );
		Functions\when( 'esc_attr_e' )->alias( static fn( $s ) => print $s );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceReader' )
			->shouldReceive( 'get_hierarchical' )->twice()->with( 'active' )
			->andReturn( array( (object) array( 'id' => '5', 'name' => 'A', 'color' => '#abc', 'children' => array() ) ) );

		ob_start();
		AudienceTransferList::render( array( '5', 5, '0' ), 'rule[audience_ids][]', false );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'data-field-name="rule[audience_ids][]"', $html );
		$this->assertStringContainsString( 'data-selected="[5]"', $html, 'Duplicates and non-ids are dropped.' );
		$this->assertStringContainsString( '&quot;id&quot;:5', $html );
		$this->assertStringNotContainsString( 'data-required', $html, 'An optional picker lets the form submit empty.' );

		ob_start();
		AudienceTransferList::render( array(), 'rereg_audience_ids[]', true );
		$this->assertStringContainsString( 'data-required="1"', (string) ob_get_clean() );
	}

	public function test_enqueue_brings_the_script_and_its_sheet(): void {
		$handles = array();
		Functions\when( 'wp_enqueue_style' )->alias(
			static function ( $handle, $src, $deps ) use ( &$handles ) {
				$handles[] = array( 'style', $handle, $deps );
			}
		);
		Functions\when( 'wp_enqueue_script' )->alias(
			static function ( $handle, $src, $deps ) use ( &$handles ) {
				$handles[] = array( 'script', $handle, $deps );
			}
		);

		AudienceTransferList::enqueue();

		$this->assertSame(
			array(
				array( 'style', 'ffc-audience-transfer-list', array( 'ffc-common' ) ),
				array( 'script', 'ffc-audience-transfer-list', array( 'jquery' ) ),
			),
			$handles
		);
	}
}
