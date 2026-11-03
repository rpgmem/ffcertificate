<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\CreatedPagesNotice;
use FreeFormCertificate\Core\ManagedPages;

/**
 * The pages activation created are named once, with a link to edit each
 * (#2343). The shared plumbing (capability gate, AJAX 403) is covered by the
 * sibling notice tests; this file asserts what this notice decides.
 *
 * @covers \FreeFormCertificate\Admin\CreatedPagesNotice
 * @covers \FreeFormCertificate\Core\ManagedPages
 */
class CreatedPagesNoticeTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Page ids stored under ManagedPages::OPTION.
	 *
	 * @var array<int, mixed>
	 */
	private array $recorded = array();

	/**
	 * Page ids that still exist.
	 *
	 * @var array<int, int>
	 */
	private array $live = array();

	/** Stored dismissal signature. */
	private string $dismissed = '';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		foreach ( array( '__', 'esc_html__', 'esc_html', 'esc_attr', 'esc_url' ) as $fn ) {
			Functions\when( $fn )->returnArg();
		}
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce' );
		Functions\when( 'wp_enqueue_script' )->justReturn( null );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_the_title' )->alias( static fn( $id ) => 10 === $id ? 'Certificate Verification' : 'My Dashboard' );
		Functions\when( 'get_edit_post_link' )->alias( static fn( $id ) => 'post.php?post=' . $id . '&action=edit' );
		Functions\when( 'get_permalink' )->alias( static fn( $id ) => 10 === $id ? 'https://example.test/valid/' : 'https://example.test/dashboard/' );
		Functions\when( 'wp_parse_url' )->alias( static fn( $url, $part ) => parse_url( $url, $part ) );
		Functions\when( 'get_post' )->alias( fn( $id ) => in_array( $id, $this->live, true ) ? (object) array( 'ID' => $id ) : null );
		Functions\when( 'get_option' )->alias(
			function ( $key, $fallback = false ) {
				if ( ManagedPages::OPTION === $key ) {
					return $this->recorded;
				}
				if ( CreatedPagesNotice::OPTION_DISMISSED === $key ) {
					return $this->dismissed;
				}
				return $fallback;
			}
		);
		Functions\when( 'wp_admin_notice' )->alias(
			static function ( $message ) {
				echo '<div class="notice">' . $message . '</div>';
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function rendered(): string {
		ob_start();
		CreatedPagesNotice::maybe_render();
		return (string) ob_get_clean();
	}

	public function test_names_each_created_page_with_its_address_and_edit_link(): void {
		$this->recorded = array( 10, 11 );
		$this->live     = array( 10, 11 );

		$html = $this->rendered();

		$this->assertStringContainsString( 'post.php?post=10&action=edit', $html );
		$this->assertStringContainsString( 'Certificate Verification <code>/valid/</code>', $html );
		$this->assertStringContainsString( 'My Dashboard <code>/dashboard/</code>', $html );
	}

	public function test_a_deleted_page_is_left_out_and_none_left_shows_nothing(): void {
		$this->recorded = array( 10, 11 );
		$this->live     = array( 11 );

		$this->assertSame( array( 11 ), ManagedPages::created() );
		$this->assertStringNotContainsString( 'post=10', $this->rendered() );

		$this->live = array();
		$this->assertSame( '', $this->rendered(), 'Nothing to name, no notice.' );
	}

	public function test_dismissed_stays_hidden_until_another_page_is_created(): void {
		$this->recorded  = array( 10 );
		$this->live      = array( 10, 11 );
		$this->dismissed = '10';

		$this->assertSame( '', $this->rendered() );

		$this->recorded = array( 10, 11 );
		$this->assertStringContainsString( 'post=11', $this->rendered(), 'A page created later is named again.' );
	}

	public function test_a_stored_value_that_is_not_a_list_names_nothing(): void {
		$this->recorded = array();
		Functions\when( 'get_option' )->justReturn( 'garbage' );

		$this->assertSame( array(), ManagedPages::created() );
	}
}
