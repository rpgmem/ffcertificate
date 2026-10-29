<?php
/**
 * Tests for the kinds of closed date (#2202): a holiday or an optional
 * holiday, and their names.
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

	public function test_a_custom_name_renames_the_kind_everywhere_it_is_read(): void {
		$this->options['ffc_calendar_appearance'] = array(
			'legend' => array( 'optional' => 'Ponto' ),
			'cell'   => array( 'optional' => 'PF' ),
		);

		$this->assertSame( 'Ponto', ClosedDateKind::label( 'optional' ) );
		$this->assertSame( 'PF', ClosedDateKind::short_label( 'optional' ) );
		$this->assertSame( '<span class="ffc-closed-date-pill ffc-closed-date-pill-optional">Ponto</span>', ClosedDateKind::pill( 'optional' ) );
		$this->assertSame( 'Holiday', ClosedDateKind::label( 'holiday' ), 'An empty name keeps the default.' );
	}
}
