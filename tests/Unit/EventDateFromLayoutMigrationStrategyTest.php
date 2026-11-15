<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Migrations\Strategies\EventDateFromLayoutMigrationStrategy;

/**
 * The typed event date moves onto the form's field, and certificates print the
 * same text before and after (#2580).
 *
 * @covers \FreeFormCertificate\Migrations\Strategies\EventDateFromLayoutMigrationStrategy
 */
class EventDateFromLayoutMigrationStrategyTest extends TestCase {

	/** @var array<int, array<string, mixed>> Post meta per form id. */
	private array $meta = array();

	/** @var mixed The register option. */
	private $state = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( '_x' )->returnArg();
		Functions\when( 'esc_html' )->alias( static fn( $v ) => htmlspecialchars( (string) $v, ENT_QUOTES ) );
		Functions\when( 'get_the_title' )->alias( static fn( $id ) => 'Form ' . $id );
		Functions\when( 'wp_date' )->alias(
			static fn( $format, $ts = null, $tz = null ) => ( new \DateTimeImmutable( '@' . ( $ts ?? time() ) ) )->setTimezone( $tz instanceof \DateTimeZone ? $tz : new \DateTimeZone( 'UTC' ) )->format( $format )
		);
		Functions\when( 'get_posts' )->alias( fn() => array_keys( $this->meta ) );
		Functions\when( 'get_post_meta' )->alias( fn( $id, $key ) => $this->meta[ $id ][ $key ] ?? '' );
		Functions\when( 'update_post_meta' )->alias(
			function ( $id, $key, $value ) {
				$this->meta[ $id ][ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'get_option' )->alias( fn( $name, $default = false ) => EventDateFromLayoutMigrationStrategy::STATE_OPTION === $name ? $this->state : array() );
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				if ( EventDateFromLayoutMigrationStrategy::STATE_OPTION === $name ) {
					$this->state = $value;
				}
				return true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function form( int $id, string $layout, array $geofence = array() ): void {
		$this->meta[ $id ] = array(
			'_ffc_form_config'     => array( 'pdf_layout' => $layout, 'bg_color' => '#ffffff' ),
			'_ffc_geofence_config' => $geofence,
		);
	}

	public function test_the_plan_keeps_the_typed_format_and_the_printed_text(): void {
		$plan = EventDateFromLayoutMigrationStrategy::plan( '<p>compareceu no dia 19/12/2025, das {{schedule}}</p>' );
		$this->assertIsArray( $plan );
		$this->assertSame( '2025-12-19', $plan['date'] );
		$this->assertSame( '<p>compareceu no dia {{schedule:date:format=d/m/Y}}, das {{schedule}}</p>', $plan['layout'] );

		$plan = EventDateFromLayoutMigrationStrategy::plan( 'no dia 9/3/2026.' );
		$this->assertIsArray( $plan );
		$this->assertSame( 'no dia {{schedule:date:format=j/n/Y}}.', $plan['layout'], 'an unpadded date keeps printing unpadded' );
	}

	public function test_the_plan_refuses_what_it_cannot_move_without_changing_the_text(): void {
		$this->assertSame( 'no_date', EventDateFromLayoutMigrationStrategy::plan( '<p>sem data</p>' ) );
		$this->assertSame( 'several_dates', EventDateFromLayoutMigrationStrategy::plan( 'de 19/12/2025 a 20/12/2025' ) );
		$this->assertSame( 'not_a_date', EventDateFromLayoutMigrationStrategy::plan( 'em 31/02/2026' ) );
		$this->assertSame( 'no_date', EventDateFromLayoutMigrationStrategy::plan( 'RF 123/45/67890 e código 1/2/20256' ), 'part of a longer number is not a date' );
	}

	public function test_a_run_moves_the_date_skips_the_rest_and_lists_them(): void {
		$this->form( 10, '<p>no dia 19/12/2025</p>', array( 'date_start' => '2025-12-01', 'date_end' => '2025-12-01' ) );
		$this->form( 11, '<p>de 19/12/2025 a 20/12/2025</p>' );
		$this->form( 12, '<p>no dia 05/01/2026</p>', array( 'class_date_start' => '2026-01-04' ) );
		$this->form( 13, '<p>sem data</p>' );

		$strategy = new EventDateFromLayoutMigrationStrategy();
		$before   = $strategy->calculate_status( 'event_date_from_layout', array() );
		$this->assertSame( 3, $before['pending'], 'only forms that type a date are candidates' );
		$this->assertStringContainsString( 'event date 19/12/2025 (opens 01/12/2025)', $before['preview'][0] );

		$result = $strategy->execute( 'event_date_from_layout', array() );
		$this->assertSame( 1, $result['processed'], 'the batch counts what it changed' );

		$this->assertSame( '2025-12-19', $this->meta[10]['_ffc_geofence_config']['class_date_start'], 'from the layout, never the opening date' );
		$this->assertSame( '2025-12-01', $this->meta[10]['_ffc_geofence_config']['date_start'], 'the opening window is untouched' );
		$this->assertSame( '<p>no dia {{schedule:date:format=d/m/Y}}</p>', $this->meta[10]['_ffc_form_config']['pdf_layout'] );
		$this->assertSame( '#ffffff', $this->meta[10]['_ffc_form_config']['bg_color'], 'the rest of the config is kept' );
		$this->assertSame( '<p>de 19/12/2025 a 20/12/2025</p>', $this->meta[11]['_ffc_form_config']['pdf_layout'] );
		$this->assertSame( '<p>no dia 05/01/2026</p>', $this->meta[12]['_ffc_form_config']['pdf_layout'], 'an event date already set is not overwritten' );

		$after = $strategy->calculate_status( 'event_date_from_layout', array() );
		$this->assertTrue( $after['is_complete'] );
		$this->assertSame( 2, $after['failed'] );
		$this->assertStringContainsString( 'more than one date', implode( ' ', $after['failures'] ) );
		$this->assertStringContainsString( 'already has an event date', implode( ' ', $after['failures'] ) );
	}

	public function test_a_second_run_changes_nothing(): void {
		$this->form( 10, '<p>no dia 19/12/2025</p>' );
		$strategy = new EventDateFromLayoutMigrationStrategy();
		$strategy->execute( 'event_date_from_layout', array() );
		$layout = $this->meta[10]['_ffc_form_config']['pdf_layout'];

		$this->assertSame( 0, $strategy->execute( 'event_date_from_layout', array() )['processed'] );
		$this->assertSame( $layout, $this->meta[10]['_ffc_form_config']['pdf_layout'] );
		$this->assertSame( 0, $strategy->calculate_status( 'event_date_from_layout', array() )['pending'] );
	}
}
