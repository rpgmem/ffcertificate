<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\UrlShortener\QrGeneratorDesignMemory;

/**
 * The generator's remembered design (#1568): per user, design only, every
 * value normalised, and the global design when nothing is remembered.
 *
 * @covers \FreeFormCertificate\UrlShortener\QrGeneratorDesignMemory
 */
class QrGeneratorDesignMemoryTest extends TestCase {

	/** @var array<int, array<string, mixed>> */
	private array $meta = array();

	/** @var array<string, mixed> */
	private array $settings = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_option' )->alias( fn( $key, $default = false ) => 'ffc_settings' === $key ? $this->settings : $default );
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $s ) => strip_tags( (string) $s ) );
		Functions\when( 'get_user_meta' )->alias( fn( $id, $key, $single ) => $this->meta[ $id ][ $key ] ?? '' );
		Functions\when( 'update_user_meta' )->alias(
			function ( $id, $key, $value ) {
				$this->meta[ $id ][ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_user_meta' )->alias(
			function ( $id, $key ) {
				unset( $this->meta[ $id ][ $key ] );
				return true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_without_a_remembered_design_the_global_one_is_used(): void {
		$this->settings = array(
			'qr_design_dots'     => 'dots',
			'qr_design_color'    => '#123456',
			'qr_default_margin'  => 4,
			'qr_design_gradient' => 0,
		);

		$state = QrGeneratorDesignMemory::state( 7 );

		$this->assertSame( 'dots', $state['qr_design_dots'] );
		$this->assertSame( '#123456', $state['qr_design_color'] );
		$this->assertSame( 4, $state['margin'] );
		$this->assertSame( 'M', $state['error_level'] );
		$this->assertFalse( $state['qr_design_gradient'] );
		$this->assertSame( '#2271b1', $state['qr_design_color_end'], 'The end colour survives with the gradient off.' );
		$this->assertSame( QrGeneratorDesignMemory::global_state(), $state );
	}

	public function test_a_remembered_design_is_normalised_and_wins_over_the_global_one(): void {
		QrGeneratorDesignMemory::remember(
			7,
			array(
				'qr_design_dots'       => 'diamond',
				'qr_design_eye_frame'  => '<script>',
				'qr_design_color'      => 'red',
				'qr_design_gradient'   => '1',
				'qr_design_color_end'  => '#ABCDEF',
				'qr_design_frame_text' => str_repeat( 'x', 40 ),
				'qr_design_logo_id'    => '-3',
				'margin'               => '99',
				'error_level'          => 'q',
				'wifi_password'        => 'secret',
			)
		);

		$stored = $this->meta[7][ QrGeneratorDesignMemory::META_KEY ];
		$this->assertArrayNotHasKey( 'wifi_password', $stored, 'Only design keys are stored, never content.' );
		$this->assertSame( 'diamond', $stored['qr_design_dots'] );
		$this->assertSame( 'square', $stored['qr_design_eye_frame'] );
		$this->assertSame( '#000000', $stored['qr_design_color'] );
		$this->assertTrue( $stored['qr_design_gradient'] );
		$this->assertSame( '#abcdef', $stored['qr_design_color_end'] );
		$this->assertSame( 24, mb_strlen( (string) $stored['qr_design_frame_text'] ) );
		$this->assertSame( 0, $stored['qr_design_logo_id'] );
		$this->assertSame( 10, $stored['margin'] );
		$this->assertSame( 'Q', $stored['error_level'] );

		$this->assertSame( $stored, QrGeneratorDesignMemory::state( 7 ) );
		$this->assertSame( 'square', QrGeneratorDesignMemory::state( 8 )['qr_design_dots'], 'Per user: another user still gets the global design.' );
	}

	public function test_forget_goes_back_to_the_global_design(): void {
		QrGeneratorDesignMemory::remember( 7, array( 'qr_design_dots' => 'fluid' ) );
		QrGeneratorDesignMemory::forget( 7 );

		$this->assertSame( QrGeneratorDesignMemory::global_state(), QrGeneratorDesignMemory::state( 7 ) );
	}

	public function test_a_logged_out_user_is_never_written(): void {
		QrGeneratorDesignMemory::remember( 0, array( 'qr_design_dots' => 'fluid' ) );
		QrGeneratorDesignMemory::forget( 0 );

		$this->assertSame( array(), $this->meta );
		$this->assertSame( QrGeneratorDesignMemory::global_state(), QrGeneratorDesignMemory::state( 0 ) );
	}

	public function test_a_corrupt_meta_value_falls_back_to_the_global_design(): void {
		$this->meta[7][ QrGeneratorDesignMemory::META_KEY ] = 'not-an-array';

		$this->assertSame( QrGeneratorDesignMemory::global_state(), QrGeneratorDesignMemory::state( 7 ) );
	}

	public function test_the_design_of_a_state_draws_what_the_form_shows(): void {
		QrGeneratorDesignMemory::remember( 7, array( 'qr_design_dots' => 'rounded', 'qr_design_gradient' => '', 'qr_design_color_end' => '#ff0000' ) );

		$design = QrGeneratorDesignMemory::design( QrGeneratorDesignMemory::state( 7 ) );

		$this->assertSame( 'rounded', $design->dots );
		$this->assertSame( '', $design->color_end, 'With the gradient off the code is drawn without it.' );
		$this->assertSame( '', $design->logo );
	}

	public function test_the_frame_icon_and_transparency_are_remembered(): void {
		QrGeneratorDesignMemory::remember( 7, array( 'qr_design_frame_icon' => 'wifi', 'qr_design_transparent' => '1', 'qr_design_eye_ball' => 'star' ) );
		$state = QrGeneratorDesignMemory::state( 7 );

		$this->assertSame( 'wifi', $state['qr_design_frame_icon'] );
		$this->assertTrue( $state['qr_design_transparent'] );
		$this->assertTrue( QrGeneratorDesignMemory::design( $state )->transparent );
		$this->assertSame( 'star', QrGeneratorDesignMemory::design( $state )->eye_ball );

		QrGeneratorDesignMemory::remember( 7, array( 'qr_design_frame_icon' => 'javascript:' ) );
		$this->assertSame( 'scan', QrGeneratorDesignMemory::state( 7 )['qr_design_frame_icon'] );
		$this->assertFalse( QrGeneratorDesignMemory::state( 7 )['qr_design_transparent'] );
	}
}
