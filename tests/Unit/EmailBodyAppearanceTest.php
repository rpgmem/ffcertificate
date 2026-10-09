<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Core\EmailBodyAppearance;
use PHPUnit\Framework\TestCase;

/**
 * A message body's background and text column are validated in one place,
 * and an empty appearance changes nothing in the e-mail (#1660).
 *
 * @covers \FreeFormCertificate\Core\EmailBodyAppearance
 */
class EmailBodyAppearanceTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'sanitize_hex_color' )->alias(
			static fn( $c ) => 1 === preg_match( '/^#([A-Fa-f0-9]{3}){1,2}$/', (string) $c ) ? (string) $c : null
		);
		Functions\when( 'wp_attachment_is_image' )->alias( static fn( $id ) => 7 === (int) $id || 8 === (int) $id );
		Functions\when( 'wp_get_attachment_image_url' )->alias( static fn( $id ) => 7 === (int) $id ? 'https://example.org/art.png' : false );
		Functions\when( 'wp_get_attachment_metadata' )->alias(
			static fn( $id ) => 7 === (int) $id
				? array(
					'width'  => 1200,
					'height' => 720,
				)
				: false
		);
		// EmailTemplateOptions::all() reads its option; an empty one yields the defaults.
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_parse_args' )->alias( static fn( $a, $b ) => array_merge( (array) $b, (array) $a ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_an_empty_value_is_the_appearance_that_changes_nothing(): void {
		foreach ( array( null, '', '[]', '{}', array(), 'not json' ) as $raw ) {
			$app = EmailBodyAppearance::from_array( $raw );
			$this->assertInstanceOf( EmailBodyAppearance::class, $app );
			$this->assertTrue( $app->is_default() );
			$this->assertSame( array(), $app->document_args(), 'the layout must render exactly as before' );
		}
	}

	public function test_a_valid_appearance_round_trips_through_its_stored_form(): void {
		$app = EmailBodyAppearance::from_array(
			array(
				'image_id'       => '7',
				'fallback_color' => '#FFF3E8',
				'text_color'     => '#2b2d42',
				'position'       => 'left',
				'text_width'     => '70',
			)
		);

		$this->assertInstanceOf( EmailBodyAppearance::class, $app );
		$this->assertSame(
			array(
				'image_id'       => 7,
				'fallback_color' => '#fff3e8',
				'text_color'     => '#2b2d42',
				'position'       => 'left',
				'text_width'     => 70,
			),
			$app->to_array()
		);
		$again = EmailBodyAppearance::from_array( (string) json_encode( $app->to_array() ) );
		$this->assertInstanceOf( EmailBodyAppearance::class, $again );
		$this->assertSame( $app->to_array(), $again->to_array() );
	}

	public function test_document_args_resolve_the_image_and_its_height_at_the_email_width(): void {
		$app = EmailBodyAppearance::from_array(
			array(
				'image_id'       => 7,
				'fallback_color' => '#fff3e8',
				'position'       => 'right',
			)
		);

		$args = $app->document_args();
		$this->assertSame( 'https://example.org/art.png', $args['body_appearance']['image_url'] );
		// 1200 × 720 drawn at the Email Model's default 600 px width.
		$this->assertSame( 360, $args['body_appearance']['min_height'] );
		$this->assertSame( 'right', $args['body_appearance']['position'] );
		$this->assertSame( 60, $args['body_appearance']['text_width'] );
	}

	public function test_an_image_deleted_since_the_save_leaves_the_fallback_colour(): void {
		// 8 is still an image when saved, but has no URL any more at send time.
		$app = EmailBodyAppearance::from_array(
			array(
				'image_id'       => 8,
				'fallback_color' => '#fff3e8',
			)
		);

		$args = $app->document_args();
		$this->assertSame( '', $args['body_appearance']['image_url'] );
		$this->assertSame( 0, $args['body_appearance']['min_height'] );
		$this->assertSame( '#fff3e8', $args['body_appearance']['fallback_color'] );
	}

	public function test_an_image_needs_a_fallback_colour(): void {
		$error = EmailBodyAppearance::from_array( array( 'image_id' => 7 ) );

		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 'ffc_appearance_fallback', $error->get_error_code() );
	}

	/**
	 * @dataProvider refused
	 *
	 * @param array<string, mixed> $raw  Submitted values.
	 * @param string               $code Expected error code.
	 */
	public function test_values_the_editor_never_offers_are_refused( array $raw, string $code ): void {
		$error = EmailBodyAppearance::from_array( $raw );

		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( $code, $error->get_error_code() );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function refused(): array {
		return array(
			'colour that is not hex'  => array( array( 'text_color' => 'red' ), 'ffc_appearance_color' ),
			'fallback with script'    => array( array( 'fallback_color' => '#fff;background:url(x)' ), 'ffc_appearance_color' ),
			'unknown position'        => array( array( 'position' => 'center' ), 'ffc_appearance_position' ),
			'unknown width'           => array( array( 'text_width' => 55 ), 'ffc_appearance_width' ),
			'attachment not an image' => array(
				array(
					'image_id'       => 9,
					'fallback_color' => '#fff',
				),
				'ffc_appearance_image',
			),
		);
	}

	public function test_contrast_falls_back_to_the_email_model_colours(): void {
		$none = EmailBodyAppearance::none();
		$dark = EmailBodyAppearance::from_array(
			array(
				'fallback_color' => '#000000',
				'text_color'     => '#ffffff',
			)
		);

		// Email Model defaults: #333333 on #ffffff.
		$this->assertEqualsWithDelta( 12.63, (float) $none->contrast(), 0.01 );
		$this->assertInstanceOf( EmailBodyAppearance::class, $dark );
		$this->assertEqualsWithDelta( 21.0, (float) $dark->contrast(), 0.01 );
	}
}
