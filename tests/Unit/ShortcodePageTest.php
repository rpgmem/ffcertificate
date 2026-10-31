<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Core\ShortcodePage;
use PHPUnit\Framework\TestCase;

/**
 * The page lookup the form hand-off and the reschedule link share (#2244).
 *
 * @covers \FreeFormCertificate\Core\ShortcodePage
 */
class ShortcodePageTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @dataProvider provider_spellings
	 */
	public function test_embeds_reads_every_spelling_of_the_id( string $content, bool $expected ): void {
		$this->assertSame( $expected, ShortcodePage::embeds( $content, 'ffc_self_scheduling', 31 ) );
	}

	/**
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public function provider_spellings(): array {
		return array(
			'double quotes'   => array( '[ffc_self_scheduling id="31"]', true ),
			'single quotes'   => array( "[ffc_self_scheduling id='31']", true ),
			'no quotes'       => array( '[ffc_self_scheduling id=31]', true ),
			'other attribute' => array( '[ffc_self_scheduling theme="x" id="31"]', true ),
			'longer number'   => array( '[ffc_self_scheduling id="312"]', false ),
			'other tag'       => array( '[ffc_form id="31"]', false ),
			'tag prefix'      => array( '[ffc_self_scheduling_extra id="31"]', false ),
		);
	}

	public function test_url_returns_the_newest_page_that_embeds_the_record(): void {
		$searches = array();
		Functions\when( 'get_posts' )->alias(
			static function ( array $args ) use ( &$searches ) {
				$searches[] = $args['s'];
				return array( 11, 12 );
			}
		);
		Functions\when( 'get_post_field' )->alias( static fn( $field, $id ) => 11 === $id ? '[ffc_self_scheduling id="9"]' : '[ffc_self_scheduling id="31"]' );
		Functions\when( 'get_permalink' )->alias( static fn( $id ) => 'https://example.test/page-' . $id . '/' );

		$this->assertSame( 'https://example.test/page-12/', ShortcodePage::url( 'ffc_self_scheduling', 31 ) );
		$this->assertSame( array( '[ffc_self_scheduling' ), $searches );
	}

	public function test_url_is_empty_when_no_page_embeds_it_or_the_id_is_not_positive(): void {
		Functions\when( 'get_posts' )->justReturn( array( 11 ) );
		Functions\when( 'get_post_field' )->justReturn( 'nothing here' );

		$this->assertSame( '', ShortcodePage::url( 'ffc_self_scheduling', 31 ) );
		$this->assertSame( '', ShortcodePage::url( 'ffc_self_scheduling', 0 ) );
	}
}
