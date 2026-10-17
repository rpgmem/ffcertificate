<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\PiiReveal;

/**
 * The "Reveal this page" button and the shared reveal script (#1863).
 *
 * @covers \FreeFormCertificate\Core\PiiReveal
 */
class PiiRevealTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_current_user_id' )->justReturn( 4 );
		Functions\when( 'wp_create_nonce' )->alias( static fn( $action ) => 'nonce:' . $action );
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_the_page_button_is_drawn_for_the_reveal_tier_with_the_reveal_nonce(): void {
		Functions\when( 'user_can' )->alias( static fn( $uid, $cap ) => 'ffc_view_certificates_pii' === $cap );

		$html = PiiReveal::page_button( 'ffc_view_certificates_pii' );

		$this->assertStringContainsString( '<button type="button" class="button ffc-reveal-pii-page ffc-icon-eye" data-nonce="nonce:ffc_reveal_pii_nonce">Reveal this page</button>', $html );
		$this->assertStringContainsString( 'recorded in the activity log', $html );
	}

	public function test_no_page_button_without_the_module_cap(): void {
		// FFC Read-only, a Viewer, an Operator: masked, nothing to reveal.
		Functions\when( 'user_can' )->justReturn( false );

		$this->assertSame( '', PiiReveal::page_button( 'ffc_view_certificates_pii' ) );
	}

	public function test_no_page_button_for_a_logged_out_request(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );

		$this->assertSame( '', PiiReveal::page_button( 'ffc_view_certificates_pii' ) );
	}

	public function test_the_script_is_enqueued_with_its_strings_and_ffc_core(): void {
		$enqueued  = array();
		$localized = array();
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_enqueue_script' )->alias(
			static function ( $handle, $src, $deps ) use ( &$enqueued ) {
				$enqueued[ $handle ] = array( $src, $deps );
			}
		);
		Functions\when( 'wp_localize_script' )->alias(
			static function ( $handle, $name, $data ) use ( &$localized ) {
				$localized[ $handle ] = array( $name, $data );
			}
		);

		PiiReveal::enqueue_assets();

		$this->assertArrayHasKey( 'ffc-pii-reveal', $enqueued );
		$this->assertSame( array( 'jquery', 'ffc-core' ), $enqueued['ffc-pii-reveal'][1] );
		$this->assertMatchesRegularExpression( '#assets/js/ffc-pii-reveal(\.min)?\.js$#', $enqueued['ffc-pii-reveal'][0] );
		$this->assertSame( 'ffcPiiReveal', $localized['ffc-pii-reveal'][0] );
		$this->assertArrayHasKey( 'reveal_error', $localized['ffc-pii-reveal'][1] );
	}
}
