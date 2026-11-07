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

	/** @var array<string, mixed> */
	private array $transients = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->transients = array();
		Functions\when( 'get_transient' )->alias( fn( $key ) => $this->transients[ $key ] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value ) {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'get_post_status' )->justReturn( 'publish' );
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

	/**
	 * #2341 — the answer is kept: a second ask reads no post content
	 * search, and a kept answer that stopped being true is searched again.
	 */
	public function test_url_keeps_its_answer_and_rechecks_it(): void {
		$searches = 0;
		Functions\when( 'get_posts' )->alias(
			static function () use ( &$searches ) {
				++$searches;
				return array( 12 );
			}
		);
		$content = '[ffc_form id="31"]';
		Functions\when( 'get_post_field' )->alias( static function () use ( &$content ) {
			return $content;
		} );
		Functions\when( 'get_permalink' )->alias( static fn( $id ) => 'https://example.test/page-' . $id . '/' );

		$this->assertSame( 'https://example.test/page-12/', ShortcodePage::url( 'ffc_form', 31 ) );
		$this->assertSame( 'https://example.test/page-12/', ShortcodePage::url( 'ffc_form', 31 ) );
		$this->assertSame( 1, $searches, 'The second ask reads the kept answer.' );

		// The page stopped embedding the form: the kept answer is not
		// believed, and the search runs again.
		$content = 'nothing';
		$this->assertSame( '', ShortcodePage::url( 'ffc_form', 31 ) );
		$this->assertSame( 2, $searches );
	}

	/**
	 * #2341 — a miss searches past the fifty newest pages.
	 */
	public function test_url_finds_a_page_older_than_the_fifty_newest(): void {
		$pages = array();
		Functions\when( 'get_posts' )->alias(
			static function ( array $args ) use ( &$pages ) {
				$pages[] = $args['paged'];
				return 1 === $args['paged'] ? range( 100, 149 ) : array( 7 );
			}
		);
		Functions\when( 'get_post_field' )->alias( static fn( $field, $id ) => 7 === $id ? '[ffc_form id=31]' : '[ffc_form id=99]' );
		Functions\when( 'get_permalink' )->alias( static fn( $id ) => 'https://example.test/page-' . $id . '/' );

		$this->assertSame( 'https://example.test/page-7/', ShortcodePage::url( 'ffc_form', 31 ) );
		$this->assertSame( array( 1, 2 ), $pages );
	}

	/**
	 * #2341 — saving a page primes the answers it embeds; trashing it or
	 * unpublishing it drops them.
	 */
	public function test_saving_a_page_primes_and_leaving_drops(): void {
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		Functions\when( 'wp_is_post_autosave' )->justReturn( false );
		Functions\when( 'get_posts' )->alias( function () {
			$this->fail( 'A primed answer needs no search.' );
		} );
		Functions\when( 'get_post_field' )->justReturn( '[ffc_form id="31"] [ffc_self_scheduling id=4]' );
		Functions\when( 'get_permalink' )->alias( static fn( $id ) => 'https://example.test/page-' . $id . '/' );

		$post               = \Mockery::mock( 'WP_Post' );
		$post->post_type    = 'page';
		$post->post_status  = 'publish';
		$post->post_content = '[ffc_form id="31"] [ffc_self_scheduling id=4]';
		ShortcodePage::on_save_post( 12, $post );

		$this->assertSame( 'https://example.test/page-12/', ShortcodePage::url( 'ffc_form', 31 ) );
		$this->assertSame( 'https://example.test/page-12/', ShortcodePage::url( 'ffc_self_scheduling', 4 ) );

		ShortcodePage::forget_post( 12 );
		$this->assertSame( array(), $this->transients[ ShortcodePage::CACHE_KEY ] );

		ShortcodePage::on_save_post( 12, $post );
		$post->post_status = 'draft';
		ShortcodePage::on_save_post( 12, $post );
		$this->assertSame( array(), $this->transients[ ShortcodePage::CACHE_KEY ], 'An unpublished page holds no answer.' );
	}

	public function test_ids_in_reads_every_embedded_record(): void {
		$this->assertSame( array( 31, 7 ), ShortcodePage::ids_in( '[ffc_form id="31"] x [ffc_form id=7] [ffc_form id=\'31\'] [ffc_form_x id=9]', 'ffc_form' ) );
	}
}
