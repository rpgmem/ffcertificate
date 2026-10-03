<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\DateMessages\RecipientResolver;
use FreeFormCertificate\DateMessages\Rule;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * The one selection the preview and the send share (#1538).
 *
 * @covers \FreeFormCertificate\DateMessages\RecipientResolver
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class DateMessagesRecipientResolverTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $source;

	/** @var Mockery\MockInterface */
	private $opt_out;

	/** @var Mockery\MockInterface */
	private $log;

	/** @var Mockery\MockInterface */
	private $audiences;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => trim( (string) $v ) );
		Functions\when( 'is_email' )->alias( static fn( $v ) => false !== filter_var( $v, FILTER_VALIDATE_EMAIL ) );

		$this->source    = Mockery::mock( 'FreeFormCertificate\DateMessages\DateSourceInterface' );
		$this->opt_out   = Mockery::mock( 'alias:FreeFormCertificate\DateMessages\OptOut' );
		$this->log       = Mockery::mock( 'alias:FreeFormCertificate\DateMessages\DeliveryLog' );
		$this->audiences = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceReader' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param array<string, mixed> $over Overrides.
	 * @return Rule
	 */
	private function rule( array $over = array() ): Rule {
		$rule = Rule::from_array(
			array_merge(
				array(
					'id'      => 3,
					'name'    => 'r',
					'subject' => 's',
					'body'    => 'b',
				),
				$over
			)
		);
		$this->assertInstanceOf( Rule::class, $rule );
		return $rule;
	}

	/**
	 * @param int    $id    User id.
	 * @param string $email Address.
	 * @return array{user_id: int, email: string, name: string}
	 */
	private function candidate( int $id, string $email = '' ): array {
		return array(
			'user_id' => $id,
			'email'   => '' === $email ? "u{$id}@example.org" : $email,
			'name'    => "User {$id}",
		);
	}

	public function test_decisions_and_their_precedence(): void {
		$target = new \DateTimeImmutable( '2026-10-10' );
		$this->source->shouldReceive( 'due' )->with( $target, 0, 10 )->andReturn(
			array(
				$this->candidate( 1 ),
				$this->candidate( 2 ),
				$this->candidate( 3, 'not-an-address' ),
				$this->candidate( 4 ),
				$this->candidate( 5, 'not-an-address' ),
				$this->candidate( 6 ),
			)
		);
		// 5 is opted out AND has no address: opting out wins.
		$this->opt_out->shouldReceive( 'among' )->with( array( 1, 2, 3, 4, 5, 6 ) )->andReturn( array( 2 => true, 5 => true ) );
		$this->log->shouldReceive( 'delivered_among' )->with( 3, '2026-10-10', array( 1, 2, 3, 4, 5, 6 ) )->andReturn( array( 4 => true ) );
		$this->audiences->shouldReceive( 'get_members' )->with( 9, true )->andReturn( array( '1', '2', '3', '4', '5' ) );

		$page = ( new RecipientResolver( $this->source ) )->resolve( $this->rule( array( 'audience_id' => 9 ) ), $target, 0, 10 );

		$this->assertSame(
			array(
				1 => RecipientResolver::WILL_SEND,
				2 => RecipientResolver::OPTED_OUT,
				3 => RecipientResolver::NO_EMAIL,
				4 => RecipientResolver::ALREADY_SENT,
				5 => RecipientResolver::OPTED_OUT,
				6 => RecipientResolver::OUT_OF_AUDIENCE,
			),
			array_column( $page['rows'], 'decision', 'user_id' )
		);
		$this->assertSame( 6, $page['cursor'] );
		$this->assertTrue( $page['complete'], 'Six candidates under a page of ten is the last page.' );
	}

	public function test_a_full_page_is_not_complete_and_no_audience_means_everyone(): void {
		$this->source->shouldReceive( 'due' )->andReturn( array( $this->candidate( 11 ), $this->candidate( 12 ) ) );
		$this->opt_out->shouldReceive( 'among' )->andReturn( array() );
		$this->log->shouldReceive( 'delivered_among' )->andReturn( array() );
		$this->audiences->shouldReceive( 'get_members' )->never();

		$page = ( new RecipientResolver( $this->source ) )->resolve( $this->rule(), new \DateTimeImmutable( '2026-10-10' ), 10, 2 );

		$this->assertSame( array( RecipientResolver::WILL_SEND, RecipientResolver::WILL_SEND ), array_column( $page['rows'], 'decision' ) );
		$this->assertSame( 12, $page['cursor'] );
		$this->assertFalse( $page['complete'] );
	}

	public function test_an_empty_audience_excludes_everyone_rather_than_widening(): void {
		$this->source->shouldReceive( 'due' )->andReturn( array( $this->candidate( 1 ) ) );
		$this->opt_out->shouldReceive( 'among' )->andReturn( array() );
		$this->log->shouldReceive( 'delivered_among' )->andReturn( array() );
		$this->audiences->shouldReceive( 'get_members' )->andReturn( array() );

		$page = ( new RecipientResolver( $this->source ) )->resolve( $this->rule( array( 'audience_id' => 9 ) ), new \DateTimeImmutable( '2026-10-10' ), 0, 10 );

		$this->assertSame( RecipientResolver::OUT_OF_AUDIENCE, $page['rows'][0]['decision'] );
	}
}
