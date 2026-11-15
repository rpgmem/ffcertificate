<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\EventDate;

/**
 * The event's date and the `{{schedule:date}}` / `{{schedule:dates}}` tokens (#2579).
 *
 * @covers \FreeFormCertificate\Core\EventDate
 */
class EventDateTest extends TestCase {

	private string $tz;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->tz = date_default_timezone_get();
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'esc_html' )->alias( static fn( $v ) => htmlspecialchars( (string) $v, ENT_QUOTES ) );
		Functions\when( '_x' )->returnArg();
		// wp_date() formats in the timezone it is given, as WordPress does.
		Functions\when( 'wp_date' )->alias(
			static function ( $format, $ts = null, $tz = null ) {
				$d = ( new \DateTimeImmutable( '@' . ( $ts ?? time() ) ) )->setTimezone( $tz instanceof \DateTimeZone ? $tz : new \DateTimeZone( 'UTC' ) );
				return $d->format( $format );
			}
		);
		Functions\when( 'wp_timezone' )->alias( static fn() => new \DateTimeZone( 'America/Sao_Paulo' ) );
	}

	protected function tearDown(): void {
		date_default_timezone_set( $this->tz );
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_the_range_comes_from_the_event_schedule_never_from_the_opening_window(): void {
		$this->assertSame( array( '2025-12-19', '2025-12-20' ), EventDate::range_from_config( array( 'class_date_start' => '2025-12-19', 'class_date_end' => '2025-12-20', 'date_start' => '2025-12-01' ) ) );
		$this->assertSame( array( '', '' ), EventDate::range_from_config( array( 'date_start' => '2025-12-01', 'date_end' => '2025-12-02' ) ), 'no event date means none, not the opening date' );
	}

	public function test_an_invalid_start_empties_the_range_and_a_non_later_end_is_dropped(): void {
		$this->assertSame( array( '', '' ), EventDate::range_from_config( array( 'class_date_start' => '2025-02-30', 'class_date_end' => '2025-03-01' ) ) );
		$this->assertSame( array( '2025-12-19', '' ), EventDate::range_from_config( array( 'class_date_start' => '2025-12-19', 'class_date_end' => '2025-12-18' ) ) );
		$this->assertSame( array( '2025-12-19', '' ), EventDate::range_from_config( array( 'class_date_start' => '2025-12-19', 'class_date_end' => '2025-12-19' ) ) );
		$this->assertSame( array( '2025-12-19', '' ), EventDate::range_from_config( array( 'class_date_start' => ' 2025-12-19 ', 'class_date_end' => 'tomorrow' ) ) );
	}

	public function test_a_date_prints_in_the_plugin_pdf_format_unless_the_token_gives_one(): void {
		$this->assertSame( '19/12/2025', EventDate::format_one( '2025-12-19' ) );
		$this->assertSame( '2025-12-19', EventDate::format_one( '2025-12-19', 'Y-m-d' ) );
		$this->assertSame( '', EventDate::format_one( '' ) );
	}

	public function test_a_wall_clock_date_does_not_move_with_the_timezone(): void {
		date_default_timezone_set( 'America/Sao_Paulo' );
		$this->assertSame( '19/12/2025', EventDate::format_one( '2025-12-19', 'd/m/Y' ) );
		$this->assertSame( '19/12/2025', EventDate::format_one( '2025-12-19' ) );
	}

	public function test_dates_print_one_day_or_the_first_and_last(): void {
		$this->assertSame( '19/12/2025', EventDate::format_range( '2025-12-19', '' ) );
		$this->assertSame( '19/12/2025 to 20/12/2025', EventDate::format_range( '2025-12-19', '2025-12-20' ) );
		$this->assertSame( '19/12/2025', EventDate::format_range( '2025-12-19', '2025-12-19' ) );
		$this->assertSame( '', EventDate::format_range( '', '2025-12-20' ) );
	}

	public function test_tokens_are_replaced_with_their_format_and_escaped(): void {
		$layout = '<p>{{schedule:date}} | {{schedule:dates}} | {{schedule:date:format=Y}} | {{schedule:dates:format=d/m H:i}} | {{schedule}}</p>';
		$this->assertSame(
			'<p>19/12/2025 | 19/12/2025 to 20/12/2025 | 2025 | 19/12 00:00 to 20/12 00:00 | {{schedule}}</p>',
			EventDate::replace_tokens( $layout, '2025-12-19', '2025-12-20' ),
			'the format runs to the braces, colon included; {{schedule}} is left to the hours'
		);
		$this->assertSame( '<p></p>', EventDate::replace_tokens( '<p>{{schedule:date}}</p>', '', '' ), 'no event date prints nothing' );
		$this->assertSame( '&lt;b&gt;', EventDate::replace_tokens( '{{schedule:date:format=\<\b\>}}', '2025-12-19', '' ), 'the formatted text is escaped' );
	}

	public function test_a_layout_is_known_to_use_the_date(): void {
		$this->assertTrue( EventDate::layout_uses_date( 'no dia {{schedule:date:format=d/m/Y}}' ) );
		$this->assertTrue( EventDate::layout_uses_date( '{{schedule:dates}}' ) );
		$this->assertFalse( EventDate::layout_uses_date( 'das {{schedule}} ({{schedule_total}})' ) );
	}
}
