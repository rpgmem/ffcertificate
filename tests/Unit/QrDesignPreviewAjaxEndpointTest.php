<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\QrDesignPreviewAjaxEndpoint;

/**
 * The QR Code tab's live preview (#1563): read-only, gated on the settings
 * view tier, and drawn from the form's unsaved values through the same
 * normaliser a save uses.
 *
 * @covers \FreeFormCertificate\Admin\QrDesignPreviewAjaxEndpoint
 */
class QrDesignPreviewAjaxEndpointTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Capabilities the current user holds.
	 *
	 * @var array<int, string>
	 */
	private array $caps = array( 'ffc_view_settings' );

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'home_url' )->justReturn( 'https://example.com/' );
		Functions\when( 'current_user_can' )->alias( fn( $cap ) => in_array( $cap, $this->caps, true ) );
		Functions\when( 'wp_send_json_error' )->alias(
			static function ( $data = null, $status = null ) {
				throw new \RuntimeException( 'error:' . ( $status ?? '' ) . ':' . ( $data['message'] ?? '' ) );
			}
		);
		Functions\when( 'wp_send_json_success' )->alias(
			static function ( $data = null ) {
				throw new \RuntimeException( 'success:' . json_encode( $data ) );
			}
		);
	}

	protected function tearDown(): void {
		$_POST = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Run the handler and return the decoded success payload.
	 *
	 * @return array<string, mixed>
	 */
	private function payload(): array {
		try {
			QrDesignPreviewAjaxEndpoint::handle();
		} catch ( \RuntimeException $e ) {
			$this->assertStringStartsWith( 'success:', $e->getMessage() );
			return json_decode( substr( $e->getMessage(), 8 ), true );
		}
		$this->fail( 'The handler must answer.' );
	}

	public function test_init_registers_the_action(): void {
		Functions\expect( 'add_action' )->once()->with( 'wp_ajax_ffc_qr_design_preview', array( QrDesignPreviewAjaxEndpoint::class, 'handle' ) );

		QrDesignPreviewAjaxEndpoint::init();
	}

	public function test_the_nonce_is_checked_against_the_action(): void {
		Functions\expect( 'check_ajax_referer' )->once()->with( 'ffc_qr_design_preview', 'nonce' )->andReturn( 1 );

		$this->assertArrayHasKey( 'svg', $this->payload() );
	}

	public function test_a_user_without_the_view_tier_is_refused(): void {
		Functions\when( 'check_ajax_referer' )->justReturn( 1 );
		$this->caps = array( 'read' );

		$this->expectExceptionMessage( 'error:403:Permission denied.' );
		QrDesignPreviewAjaxEndpoint::handle();
	}

	public function test_it_draws_the_unsaved_design_and_reports_the_checks(): void {
		Functions\when( 'check_ajax_referer' )->justReturn( 1 );
		$_POST = array(
			'design'      => array(
				'dots'       => 'dots',
				'color'      => '#ffffff',
				'background' => '#000000',
			),
			'margin'      => '4',
			'error_level' => 'h',
		);

		$data = $this->payload();

		$this->assertStringContainsString( '<circle', $data['svg'] );
		$this->assertStringContainsString( 'width="240" height="240"', $data['svg'] );
		$this->assertTrue( $data['checks']['inverted'] );
	}

	public function test_hostile_values_are_normalised_before_drawing(): void {
		Functions\when( 'check_ajax_referer' )->justReturn( 1 );
		$_POST = array(
			'design'      => array(
				'dots'  => '"><script>',
				'color' => 'url(javascript:alert(1))',
			),
			'margin'      => '999',
			'error_level' => 'X',
		);

		$data = $this->payload();

		$this->assertStringNotContainsString( 'script', $data['svg'] );
		$this->assertStringNotContainsString( 'javascript', $data['svg'] );
		$this->assertStringContainsString( 'fill="#000000"', $data['svg'] );
		$this->assertFalse( $data['checks']['low_contrast'] );
	}

	public function test_a_logo_the_user_may_read_is_embedded_and_forces_h(): void {
		Functions\when( 'check_ajax_referer' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->alias( fn( $cap, $id = null ) => 'read_post' === $cap ? 3 === $id : in_array( $cap, $this->caps, true ) );
		Functions\when( 'get_post_mime_type' )->justReturn( 'image/png' );
		$file = (string) tempnam( sys_get_temp_dir(), 'ffc_logo_' );
		file_put_contents( $file, 'PNG' );
		Functions\when( 'get_attached_file' )->justReturn( $file );
		\FreeFormCertificate\Generators\QrLogo::reset();

		$_POST = array( 'logo_id' => '3' );
		$with  = $this->payload();
		$_POST = array( 'logo_id' => '4' );
		$other = $this->payload();

		@unlink( $file );
		\FreeFormCertificate\Generators\QrLogo::reset();
		$this->assertStringContainsString( '<image href="data:image/png;base64,' . base64_encode( 'PNG' ) . '"', $with['svg'] );
		$this->assertStringNotContainsString( '<image', $other['svg'], 'An attachment the user cannot read is not embedded.' );
	}
}
