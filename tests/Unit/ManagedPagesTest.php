<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\ManagedPages;

/**
 * Which existing page activation adopts, and when it creates its own (#2690).
 *
 * @covers \FreeFormCertificate\Core\ManagedPages
 */
class ManagedPagesTest extends TestCase {

	/**
	 * Pages wp_insert_post() was asked to create.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $inserted = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->inserted = array();
		Functions\when( 'has_shortcode' )->alias( static fn( $content, $tag ) => false !== strpos( (string) $content, '[' . $tag ) );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'wp_insert_post' )->alias(
			function ( $page ) {
				$this->inserted[] = $page;
				return 321;
			}
		);
		Functions\when( 'update_post_meta' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'update_option' )->justReturn( true );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A page at the slug.
	 *
	 * @param string $status  Post status.
	 * @param string $content Post content.
	 * @return \WP_Post
	 */
	private function page( string $status, string $content ): \WP_Post {
		$page               = new \WP_Post();
		$page->ID           = 77;
		$page->post_status  = $status;
		$page->post_content = $content;
		return $page;
	}

	public function test_a_published_page_holding_the_shortcode_is_adopted(): void {
		$existing = $this->page( 'publish', '<p>Hi</p>[user_dashboard_personal]' );
		Functions\when( 'get_page_by_path' )->justReturn( $existing );

		$id = ManagedPages::ensure( 'dashboard', 'My Dashboard', '[user_dashboard_personal]' );

		$this->assertSame( 77, $id );
		$this->assertSame( array(), $this->inserted, 'Nothing is created when the page already hosts the shortcode.' );
	}

	/**
	 * A page of the site's own at the slug is never taken over.
	 *
	 * @dataProvider pages_not_adopted
	 */
	public function test_a_page_that_does_not_host_the_shortcode_is_left_alone( string $status, string $content ): void {
		Functions\when( 'get_page_by_path' )->justReturn( $this->page( $status, $content ) );

		$id = ManagedPages::ensure( 'dashboard', 'My Dashboard', '[user_dashboard_personal]' );

		$this->assertSame( 321, $id, 'A new page is created beside it.' );
		$this->assertCount( 1, $this->inserted );
		$this->assertSame( '[user_dashboard_personal]', $this->inserted[0]['post_content'] );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function pages_not_adopted(): array {
		return array(
			'published, other content'   => array( 'publish', '<p>Our team dashboard</p>' ),
			'draft holding the shortcode' => array( 'draft', '[user_dashboard_personal]' ),
		);
	}

	public function test_no_page_at_the_slug_creates_one(): void {
		Functions\when( 'get_page_by_path' )->justReturn( null );

		$this->assertSame( 321, ManagedPages::ensure( 'valid', 'Certificate Verification', '[ffc_verification]' ) );
		$this->assertCount( 1, $this->inserted );
		$this->assertSame( 'valid', $this->inserted[0]['post_name'] );
	}
}
