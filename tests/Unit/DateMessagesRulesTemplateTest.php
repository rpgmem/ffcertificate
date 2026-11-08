<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\DateMessages\Rule;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * The rules list of Date Messages describes an announcement as what it is
 * (#2336): no date to count from, and sent at its time or from Send now.
 *
 * @coversNothing
 */
class DateMessagesRulesTemplateTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		foreach ( array( '__', 'esc_html', 'esc_attr', 'esc_url', 'esc_html__', 'sanitize_text_field', 'wp_kses_post' ) as $fn ) {
			Functions\when( $fn )->returnArg();
		}
		Functions\when( 'esc_html_e' )->alias( static function ( $text ) { echo $text; } );
		Functions\when( '_n' )->alias( static fn( $single, $plural, $n ) => 1 === $n ? $single : $plural );
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'wp_timezone' )->alias( static fn() => new \DateTimeZone( 'UTC' ) );
		Functions\when( 'wp_date' )->alias(
			static fn( $format, $ts = null, $tz = null ) => ( new \DateTimeImmutable( '@' . (int) $ts ) )->setTimezone( $tz ?? new \DateTimeZone( 'UTC' ) )->format( $format )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Render the list, read-only, for the given rules.
	 *
	 * @param array<int, Rule> $rules Rules.
	 * @return string
	 */
	private function render( array $rules ): string {
		$can_manage = false;
		$audiences  = array();
		ob_start();
		include dirname( __DIR__, 2 ) . '/templates/admin/date-messages/rules.php';
		return (string) ob_get_clean();
	}

	/**
	 * An announcement.
	 *
	 * @param int $send_at Send time, 0 for by hand.
	 * @return Rule
	 */
	private function announcement( int $send_at ): Rule {
		$rule = Rule::from_array(
			array(
				'id'      => 4,
				'name'    => 'Closing',
				'kind'    => 'announcement',
				'subject' => 's',
				'body'    => 'b',
				'send_at' => $send_at,
			)
		);
		$this->assertInstanceOf( Rule::class, $rule );
		return $rule;
	}

	public function test_an_announcement_has_no_date_and_says_when_it_goes(): void {
		$by_hand = $this->render( array( $this->announcement( 0 ) ) );
		$this->assertStringContainsString( 'None (announcement)', $by_hand );
		$this->assertStringContainsString( 'From Send now', $by_hand );
		$this->assertStringNotContainsString( 'On the day', $by_hand );

		$timed = $this->render( array( $this->announcement( 1791702000 ) ) );
		$this->assertMatchesRegularExpression( '#At \d{2}/\d{2}/2026#', $timed );
	}
}
