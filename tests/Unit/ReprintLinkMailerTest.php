<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Integrations\EmailHandler;
use FreeFormCertificate\Submissions\ReprintLinkMailer;

/**
 * A reprint asked for by CPF/RF sends the link to the stored address, at most
 * once per day in the site's timezone (#2342), and the screen names that
 * address masked only (#2241).
 *
 * @covers \FreeFormCertificate\Submissions\ReprintLinkMailer
 */
class ReprintLinkMailerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Transients written during the test, keyed by name.
	 *
	 * @var array<string, array{value: mixed, ttl: int}>
	 */
	private array $transients = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->transients = array();
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'America/Sao_Paulo' ) );
		Functions\when( 'is_email' )->alias( static fn( $v ) => false !== filter_var( $v, FILTER_VALIDATE_EMAIL ) );
		Functions\when( 'get_transient' )->alias( fn( $key ) => $this->transients[ $key ]['value'] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value, $ttl ) {
				$this->transients[ $key ] = array(
					'value' => $value,
					'ttl'   => $ttl,
				);
				return true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_sends_once_a_day_and_throttles_until_local_midnight(): void {
		$email = Mockery::mock( EmailHandler::class );
		$email->shouldReceive( 'resend_link' )->once()->with( 42 )->andReturn( true );
		$mailer = new ReprintLinkMailer( $email );

		$before = ( new \DateTimeImmutable( 'tomorrow', new \DateTimeZone( 'America/Sao_Paulo' ) ) )->getTimestamp() - time();
		$first  = $mailer->send( 42, 'fulano@example.com' );
		$second = $mailer->send( 42, 'fulano@example.com' );
		$after  = ( new \DateTimeImmutable( 'tomorrow', new \DateTimeZone( 'America/Sao_Paulo' ) ) )->getTimestamp() - time();

		$this->assertSame( 'sent', $first['status'] );
		$this->assertSame( 'f***@example.com', $first['masked_email'] );
		$this->assertSame( 'throttled', $second['status'], 'A second request the same day sends nothing.' );
		// The mark lasts until the site's midnight, read on both sides of the
		// call because a run may cross a second or the day boundary.
		$ttl = $this->transients['ffc_reprint_link_42']['ttl'];
		$this->assertContains( $ttl, array_unique( array( max( 60, $before ), max( 60, $after ), max( 60, $before - 1 ), max( 60, $after + 1 ) ) ) );
	}

	public function test_a_certificate_with_no_address_sends_nothing(): void {
		$email = Mockery::mock( EmailHandler::class );
		$email->shouldNotReceive( 'resend_link' );

		$result = ( new ReprintLinkMailer( $email ) )->send( 42, '  ' );

		$this->assertSame( 'no_email', $result['status'] );
		$this->assertStringContainsString( 'contact the organizer', ReprintLinkMailer::message( $result ) );
	}

	public function test_a_refused_send_is_not_throttled(): void {
		$email = Mockery::mock( EmailHandler::class );
		$email->shouldReceive( 'resend_link' )->twice()->andReturn( new \WP_Error( 'ffc_link_not_sent', 'x' ) );
		$mailer = new ReprintLinkMailer( $email );

		$this->assertSame( 'failed', $mailer->send( 42, 'fulano@example.com' )['status'] );
		$this->assertSame( 'failed', $mailer->send( 42, 'fulano@example.com' )['status'], 'A failed send may be tried again.' );
		$this->assertArrayNotHasKey( 'ffc_reprint_link_42', $this->transients );
	}

	public function test_the_message_names_only_the_masked_address(): void {
		$message = ReprintLinkMailer::message(
			array(
				'status'       => 'sent',
				'masked_email' => 'f***@example.com',
			)
		);
		$this->assertStringContainsString( 'f***@example.com', $message );
		$throttled = ReprintLinkMailer::message(
			array(
				'status'       => 'throttled',
				'masked_email' => 'f***@example.com',
			)
		);
		$this->assertStringContainsString( 'already sent today', $throttled, 'Later the same day the notice says the link already went.' );
		$this->assertStringContainsString( 'f***@example.com', $throttled );
		$this->assertStringContainsString( 'could not be sent', ReprintLinkMailer::message( array( 'status' => 'failed', 'masked_email' => 'f***@example.com' ) ) );
	}
}
