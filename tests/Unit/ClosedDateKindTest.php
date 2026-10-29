<?php
/**
 * Tests for the kinds of closed date (#2202): a holiday or an optional
 * holiday, their labels, and the light-theme colours an administrator picks.
 *
 * @covers \FreeFormCertificate\Scheduling\ClosedDateKind
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Scheduling\ClosedDateKind;
use PHPUnit\Framework\TestCase;

final class ClosedDateKindTest extends TestCase {

	/**
	 * Options written during the test.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->options = array();
		Functions\when( '__' )->returnArg();
		Functions\when( '_x' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'sanitize_hex_color' )->alias(
			static fn( $color ) => is_string( $color ) && 1 === preg_match( '/^#([0-9a-f]{3}){1,2}$/i', $color ) ? $color : ''
		);
		Functions\when( 'get_option' )->alias( fn( $name, $default = false ) => $this->options[ $name ] ?? $default );
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->options[ $name ] = $value;
				return true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_anything_but_optional_reads_as_a_holiday(): void {
		$this->assertSame( 'optional', ClosedDateKind::normalize( 'optional' ) );
		foreach ( array( 'holiday', null, '', 'OPTIONAL', 'closed', 1 ) as $value ) {
			$this->assertSame( 'holiday', ClosedDateKind::normalize( $value ), var_export( $value, true ) );
		}
	}

	public function test_labels_name_each_kind_and_the_cell_gets_the_short_one(): void {
		$this->assertSame( 'Holiday', ClosedDateKind::label( 'holiday' ) );
		$this->assertSame( 'Optional Holiday', ClosedDateKind::label( 'optional' ) );
		$this->assertSame( 'Holiday', ClosedDateKind::short_label( 'holiday' ) );
		$this->assertSame( 'Optional', ClosedDateKind::short_label( 'optional' ) );
		$this->assertSame(
			array(
				'holiday'  => array(
					'label' => 'Holiday',
					'short' => 'Holiday',
				),
				'optional' => array(
					'label' => 'Optional Holiday',
					'short' => 'Optional',
				),
			),
			ClosedDateKind::strings()
		);
	}

	public function test_the_pill_escapes_and_normalises_its_kind(): void {
		$this->assertSame(
			'<span class="ffc-closed-date-pill ffc-closed-date-pill-optional">Optional Holiday</span>',
			ClosedDateKind::pill( 'optional' )
		);
		$this->assertSame(
			'<span class="ffc-closed-date-pill ffc-closed-date-pill-holiday">Holiday</span>',
			ClosedDateKind::pill( '"><script>' )
		);
	}

	public function test_the_control_offers_every_kind_with_holiday_chosen(): void {
		$args = ClosedDateKind::control_args( 'global_holiday_kind' );

		$this->assertSame( 'global_holiday_kind', $args['name'] );
		$this->assertSame( 'global_holiday_kind', $args['id'] );
		$this->assertSame( 'holiday', $args['value'] );
		$this->assertSame(
			array(
				'holiday'  => 'Holiday',
				'optional' => 'Optional Holiday',
			),
			$args['options']
		);
	}

	public function test_colours_default_until_a_valid_one_is_stored(): void {
		$this->assertSame( ClosedDateKind::DEFAULT_COLORS, ClosedDateKind::colors() );

		$this->options[ ClosedDateKind::COLORS_OPTION ] = array(
			'holiday'  => 'not-a-colour',
			'optional' => '#123456',
		);
		$this->assertSame(
			array(
				'holiday'  => '#8a6500',
				'optional' => '#123456',
			),
			ClosedDateKind::colors()
		);

		$this->options[ ClosedDateKind::COLORS_OPTION ] = 'garbage';
		$this->assertSame( ClosedDateKind::DEFAULT_COLORS, ClosedDateKind::colors() );
	}

	public function test_saving_keeps_the_stored_colour_when_a_field_is_cleared_or_invalid(): void {
		ClosedDateKind::save_colors(
			array(
				'holiday'  => '#AA0000',
				'optional' => '',
			)
		);
		$this->assertSame(
			array(
				'holiday'  => '#aa0000',
				'optional' => '#4f6b8f',
			),
			$this->options[ ClosedDateKind::COLORS_OPTION ]
		);

		ClosedDateKind::save_colors( array( 'holiday' => 'red; } body { display: none' ) );
		$this->assertSame( '#aa0000', $this->options[ ClosedDateKind::COLORS_OPTION ]['holiday'] );
	}

	public function test_no_css_while_every_colour_is_the_default(): void {
		$this->assertSame( '', ClosedDateKind::inline_css() );

		$css = 'unused';
		Functions\when( 'wp_add_inline_style' )->alias(
			function ( $handle, $data ) use ( &$css ) {
				$css = $handle . '|' . $data;
				return true;
			}
		);
		ClosedDateKind::enqueue_colors( 'ffc-audience-css' );
		$this->assertSame( 'unused', $css, 'Nothing is attached when the sheet already declares the colours.' );
	}

	public function test_a_chosen_colour_repaints_the_light_theme_only_with_readable_text(): void {
		$this->options[ ClosedDateKind::COLORS_OPTION ] = array(
			'holiday'  => '#8a6500',
			'optional' => '#ffd54f',
		);

		$css = ClosedDateKind::inline_css();

		$this->assertSame(
			':root:not(.ffc-dark-mode){--ffc-optional-holiday:#ffd54f;--ffc-text-on-optional-holiday:#000000;}',
			$css,
			'Only the changed kind is printed, scoped to the light theme, with the text ContrastColor picks.'
		);

		$attached = array();
		Functions\when( 'wp_add_inline_style' )->alias(
			function ( $handle, $data ) use ( &$attached ) {
				$attached[] = array( $handle, $data );
				return true;
			}
		);
		ClosedDateKind::enqueue_colors( 'ffc-audience-css' );
		$this->assertSame( array( array( 'ffc-audience-css', $css ) ), $attached );
	}

	public function test_the_defaults_are_the_tokens_the_stylesheet_declares(): void {
		$sheet = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/ffc-common.css' );
		$root  = substr( $sheet, 0, (int) strpos( $sheet, ':root.ffc-dark-mode' ) );

		$this->assertMatchesRegularExpression( '/--ffc-holiday:\s*' . preg_quote( ClosedDateKind::DEFAULT_COLORS['holiday'], '/' ) . ';/', $root );
		$this->assertMatchesRegularExpression( '/--ffc-optional-holiday:\s*' . preg_quote( ClosedDateKind::DEFAULT_COLORS['optional'], '/' ) . ';/', $root );
	}
}
