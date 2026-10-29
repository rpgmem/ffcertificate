<?php
/**
 * Tests for the calendar states' colours and names (#2215): the stored
 * settings read safely, the save keeps what a cleared colour field had, the
 * light-theme CSS prints only what changed, and the scripts get the names.
 *
 * @covers \FreeFormCertificate\Scheduling\CalendarAppearance
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Scheduling\CalendarAppearance;
use PHPUnit\Framework\TestCase;

final class CalendarAppearanceTest extends TestCase {

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
		Functions\when( 'sanitize_text_field' )->alias( static fn( $value ) => trim( strip_tags( (string) $value ) ) );
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
		Functions\when( 'delete_option' )->alias(
			function ( $name ) {
				unset( $this->options[ $name ] );
				return true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_nothing_stored_reads_as_every_default_with_the_label_shown(): void {
		$stored = CalendarAppearance::stored();

		$this->assertSame( CalendarAppearance::DEFAULT_COLORS, $stored['colors'] );
		$this->assertTrue( $stored['show_closed_label'] );
		$this->assertSame( 'Available', CalendarAppearance::legend( 'available' ) );
		$this->assertSame( 'Booked', CalendarAppearance::legend( 'booked' ) );
		$this->assertSame( 'Optional Holiday', CalendarAppearance::legend( 'optional' ) );
		$this->assertSame( 'Optional', CalendarAppearance::cell( 'optional' ) );
		$this->assertSame( 'Closed', CalendarAppearance::cell( 'closed' ) );
	}

	public function test_garbage_in_the_option_falls_back_to_the_defaults(): void {
		$this->options[ CalendarAppearance::OPTION ] = 'garbage';
		$this->assertSame( CalendarAppearance::DEFAULT_COLORS, CalendarAppearance::colors() );

		$this->options[ CalendarAppearance::OPTION ] = array(
			'colors' => array(
				'available' => 'red',
				'booked'    => '#ABCDEF',
			),
			'legend' => array( 'closed' => array( 'not', 'a', 'string' ) ),
		);
		$this->assertSame( '#00801e', CalendarAppearance::colors()['available'] );
		$this->assertSame( '#abcdef', CalendarAppearance::colors()['booked'] );
		$this->assertSame( 'Closed', CalendarAppearance::legend( 'closed' ) );
	}

	public function test_saving_keeps_a_cleared_colour_and_clears_an_empty_name(): void {
		CalendarAppearance::save(
			array(
				'available' => '#AA0000',
				'closed'    => 'red; } body { display: none',
			),
			array(
				'closed'  => '  Encerrado  ',
				'booked'  => '<b>Ocupado</b>',
				'holiday' => '',
			),
			array( 'closed' => str_repeat( 'x', 60 ) ),
			false
		);

		$stored = $this->options[ CalendarAppearance::OPTION ];
		$this->assertSame( '#aa0000', $stored['colors']['available'] );
		$this->assertSame( '#f6f7f7', $stored['colors']['closed'], 'An invalid colour keeps the stored one.' );
		$this->assertSame( 'Encerrado', $stored['legend']['closed'] );
		$this->assertSame( 'Ocupado', $stored['legend']['booked'], 'A name is plain text.' );
		$this->assertSame( '', $stored['legend']['holiday'] );
		$this->assertSame( 40, strlen( $stored['cell']['closed'] ), 'A name is cut at 40 characters.' );
		$this->assertFalse( CalendarAppearance::show_closed_label() );
		$this->assertSame( 'Holiday', CalendarAppearance::legend( 'holiday' ), 'An empty name is the default.' );
	}

	public function test_reset_returns_every_state_to_its_default(): void {
		CalendarAppearance::save( array( 'booked' => '#123456' ), array( 'booked' => 'X' ), array(), false );
		CalendarAppearance::reset();

		$this->assertArrayNotHasKey( CalendarAppearance::OPTION, $this->options );
		$this->assertSame( CalendarAppearance::DEFAULT_COLORS, CalendarAppearance::colors() );
		$this->assertTrue( CalendarAppearance::show_closed_label() );
	}

	public function test_no_css_while_every_colour_is_the_default(): void {
		$this->assertSame( '', CalendarAppearance::inline_css() );

		$attached = array();
		Functions\when( 'wp_add_inline_style' )->alias(
			function ( $handle, $data ) use ( &$attached ) {
				$attached[] = array( $handle, $data );
				return true;
			}
		);
		CalendarAppearance::enqueue_colors( 'ffc-audience-css' );
		$this->assertSame( array(), $attached, 'Nothing is attached when the sheet already declares the colours.' );
	}

	public function test_each_chosen_colour_sets_its_own_tokens_in_the_light_theme_only(): void {
		$this->options[ CalendarAppearance::OPTION ] = array(
			'colors' => array(
				'available' => '#6a1b9a',
				'booked'    => '#ffd54f',
				'holiday'   => '#8a6500',
				'optional'  => '#000080',
				'closed'    => '#333333',
			),
		);

		$this->assertSame(
			':root:not(.ffc-dark-mode){'
				. '--ffc-cal-available:#6a1b9a;--ffc-cal-available-ring:#6a1b9a;'
				. '--ffc-cal-booked:#ffd54f;--ffc-text-on-cal-booked:#000000;'
				. '--ffc-optional-holiday:#000080;--ffc-text-on-optional-holiday:#ffffff;'
				. '--ffc-cal-closed:#333333;--ffc-text-on-cal-closed:#ffffff;--ffc-cal-closed-dot:#333333;'
				. '}',
			CalendarAppearance::inline_css(),
			'The default holiday prints nothing; every other state its tokens, with ContrastColor text.'
		);

		$attached = array();
		Functions\when( 'wp_add_inline_style' )->alias(
			function ( $handle, $data ) use ( &$attached ) {
				$attached[] = $handle;
				return true;
			}
		);
		CalendarAppearance::enqueue_colors( 'ffc-audience-admin' );
		$this->assertSame( array( 'ffc-audience-admin' ), $attached );
	}

	public function test_the_scripts_get_the_names_and_the_switch(): void {
		$this->options[ CalendarAppearance::OPTION ] = array(
			'legend'            => array( 'closed' => 'Sem expediente' ),
			'cell'              => array( 'closed' => 'Fechado' ),
			'show_closed_label' => false,
		);

		$strings = CalendarAppearance::strings();

		$this->assertSame( 'Sem expediente', $strings['closed'] );
		$this->assertSame( 'Fechado', $strings['closedCell'] );
		$this->assertFalse( $strings['showClosedLabel'] );
		$this->assertSame( 'Available', $strings['available'] );
		$this->assertSame( array( 'label', 'short' ), array_keys( $strings['closedDateKinds']['optional'] ) );
	}

	public function test_the_booking_badge_takes_its_two_names_from_the_card(): void {
		$this->assertSame( 'booking', CalendarAppearance::cell( CalendarAppearance::BOOKING_SINGULAR ) );
		$this->assertSame( 'bookings', CalendarAppearance::cell( CalendarAppearance::BOOKING_PLURAL ) );

		CalendarAppearance::save( array(), array(), array( 'booking_singular' => 'sessão', 'booking_plural' => '' ), true );

		$strings = CalendarAppearance::strings();
		$this->assertSame( 'sessão', $strings['booking'] );
		$this->assertSame( 'bookings', $strings['bookings'], 'An empty plural keeps the default (#2221).' );
	}

	public function test_the_available_contrast_is_measured_on_white(): void {
		$this->assertGreaterThan( 4.5, CalendarAppearance::available_contrast( '#00801e' ) );
		$this->assertLessThan( 4.5, CalendarAppearance::available_contrast( '#ffd54f' ) );
	}

	public function test_the_defaults_are_the_values_the_stylesheet_draws(): void {
		$sheet = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/ffc-common.css' );
		$root  = substr( $sheet, 0, (int) strpos( $sheet, ':root.ffc-dark-mode' ) );

		$chains = array(
			'holiday'   => '--ffc-holiday',
			'optional'  => '--ffc-optional-holiday',
			'available' => '--ffc-success',
			'booked'    => '--ffc-primary',
			'closed'    => '--ffc-bg-alt',
		);
		foreach ( $chains as $state => $token ) {
			$this->assertMatchesRegularExpression(
				'/' . preg_quote( $token, '/' ) . ':\s*' . preg_quote( CalendarAppearance::DEFAULT_COLORS[ $state ], '/' ) . ';/',
				$root,
				$state . ' default disagrees with ' . $token . '.'
			);
		}
		$this->assertStringContainsString( '--ffc-cal-available: var(--ffc-success);', $root );
		$this->assertStringContainsString( '--ffc-cal-booked: var(--ffc-primary);', $root );
		$this->assertStringContainsString( '--ffc-cal-closed: var(--ffc-bg-alt);', $root );
	}
}
