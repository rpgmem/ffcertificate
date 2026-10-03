<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\DateMessages\DateMessagesAjaxEndpoint;
use FreeFormCertificate\DateMessages\Rule;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Recipient preview and test send (#1538).
 *
 * @covers \FreeFormCertificate\DateMessages\DateMessagesAjaxEndpoint
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class DateMessagesAjaxEndpointTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $resolver;

	/** @var Mockery\MockInterface */
	private $reader;

	/**
	 * Capabilities the current user holds.
	 *
	 * @var array<int, string>
	 */
	private array $caps = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$_POST      = array();
		$this->caps = array( 'ffc_view_date_messages' );

		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => trim( (string) $v ) );
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'is_wp_error' )->alias( static fn( $v ) => $v instanceof \WP_Error );
		Functions\when( 'wp_timezone' )->alias( static fn() => new \DateTimeZone( 'UTC' ) );
		Functions\when( 'current_user_can' )->alias( fn( $cap ) => in_array( $cap, $this->caps, true ) );
		Functions\when( 'check_ajax_referer' )->justReturn( 1 );
		Functions\when( 'is_email' )->alias( static fn( $v ) => false !== filter_var( $v, FILTER_VALIDATE_EMAIL ) );
		Functions\when( 'wp_send_json_success' )->alias(
			static function ( $data = null ) {
				throw new JsonResponse( true, $data, 200 );
			}
		);
		Functions\when( 'wp_send_json_error' )->alias(
			static function ( $data = null, $status = 400 ) {
				throw new JsonResponse( false, $data, (int) $status );
			}
		);

		Mockery::getConfiguration()->setConstantsMap(
			array(
				'FreeFormCertificate\DateMessages\RecipientResolver' => array(
					'WILL_SEND'       => 'will_send',
					'OUT_OF_AUDIENCE' => 'out_of_audience',
					'OPTED_OUT'       => 'opted_out',
					'NO_EMAIL'        => 'no_email',
					'ALREADY_SENT'    => 'already_sent',
				),
			)
		);
		$this->resolver = Mockery::mock( 'overload:FreeFormCertificate\DateMessages\RecipientResolver' );
		$this->reader   = Mockery::mock( 'alias:FreeFormCertificate\DateMessages\RuleReader' );
	}

	protected function tearDown(): void {
		$_POST = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	private function respond( callable $handler ): JsonResponse {
		try {
			$handler();
		} catch ( JsonResponse $r ) {
			return $r;
		}
		$this->fail( 'The handler did not answer.' );
	}

	private function rule(): Rule {
		$rule = Rule::from_array(
			array(
				'id'      => 3,
				'name'    => 'r',
				'subject' => 'Hi {{first_name}}',
				'body'    => 'b',
			)
		);
		$this->assertInstanceOf( Rule::class, $rule );
		return $rule;
	}

	/**
	 * @param int    $id       User id.
	 * @param string $decision Decision.
	 * @return array{user_id: int, email: string, name: string, decision: string}
	 */
	private function row( int $id, string $decision ): array {
		return array(
			'user_id'  => $id,
			'email'    => "person{$id}@example.org",
			'name'     => "Person {$id}",
			'decision' => $decision,
		);
	}

	public function test_preview_refuses_an_operator_without_the_view_capability(): void {
		$this->caps = array();
		$this->resolver->shouldReceive( 'resolve' )->never();

		$r = $this->respond( array( DateMessagesAjaxEndpoint::class, 'preview' ) );

		$this->assertFalse( $r->success );
		$this->assertSame( 403, $r->status );
	}

	/**
	 * @dataProvider bad_ranges
	 */
	public function test_preview_refuses_a_bad_range( string $from, string $to ): void {
		$this->reader->shouldReceive( 'get_by_id' )->andReturn( $this->rule() );
		$this->resolver->shouldReceive( 'resolve' )->never();
		$_POST = array(
			'rule_id' => '3',
			'from'    => $from,
			'to'      => $to,
		);

		$r = $this->respond( array( DateMessagesAjaxEndpoint::class, 'preview' ) );

		$this->assertFalse( $r->success );
		$this->assertSame( 400, $r->status );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function bad_ranges(): array {
		return array(
			'missing'  => array( '', '2026-10-01' ),
			'reversed' => array( '2026-10-02', '2026-10-01' ),
			'too wide' => array( '2026-10-01', '2026-11-01' ),
		);
	}

	public function test_preview_without_pii_returns_totals_only(): void {
		$this->reader->shouldReceive( 'get_by_id' )->andReturn( $this->rule() );
		$this->resolver->shouldReceive( 'resolve' )->twice()->andReturn(
			array(
				'rows'     => array( $this->row( 1, 'will_send' ), $this->row( 2, 'opted_out' ) ),
				'cursor'   => 2,
				'complete' => true,
			)
		);
		$_POST = array(
			'rule_id' => '3',
			'from'    => '2026-10-01',
			'to'      => '2026-10-02',
		);

		$r = $this->respond( array( DateMessagesAjaxEndpoint::class, 'preview' ) );

		$this->assertTrue( $r->success );
		$this->assertSame( 2, $r->data['totals']['will_send'], 'One per day, two days.' );
		$this->assertSame( 2, $r->data['totals']['opted_out'] );
		$this->assertSame( 0, $r->data['totals']['no_email'] );
		$this->assertSame( array(), $r->data['rows'], 'No names without the PII capability.' );
		$this->assertFalse( $r->data['pii'] );
	}

	public function test_preview_with_pii_lists_people_with_the_address_masked_and_follows_the_cursor(): void {
		$this->caps = array( 'ffc_view_date_messages', 'ffc_view_date_messages_pii' );
		$this->reader->shouldReceive( 'get_by_id' )->andReturn( $this->rule() );
		$this->resolver->shouldReceive( 'resolve' )->with( Mockery::any(), Mockery::any(), 0, Mockery::any() )->once()->andReturn(
			array(
				'rows'     => array( $this->row( 1, 'will_send' ) ),
				'cursor'   => 1,
				'complete' => false,
			)
		);
		$this->resolver->shouldReceive( 'resolve' )->with( Mockery::any(), Mockery::any(), 1, Mockery::any() )->once()->andReturn(
			array(
				'rows'     => array( $this->row( 2, 'no_email' ) ),
				'cursor'   => 2,
				'complete' => true,
			)
		);
		$_POST = array(
			'rule_id' => '3',
			'from'    => '2026-10-01',
			'to'      => '2026-10-01',
		);

		$r = $this->respond( array( DateMessagesAjaxEndpoint::class, 'preview' ) );

		$this->assertTrue( $r->data['pii'] );
		$this->assertSame( array( 'Person 1', 'Person 2' ), array_column( $r->data['rows'], 'name' ) );
		$this->assertStringNotContainsString( 'person1@example.org', (string) json_encode( $r->data ), 'The address is masked.' );
		$this->assertSame( '2026-10-01', $r->data['rows'][0]['date'] );
	}

	public function test_preview_of_an_unsaved_rule_validates_the_form_but_not_its_name(): void {
		$this->resolver->shouldReceive( 'resolve' )->once()->andReturn(
			array(
				'rows'     => array(),
				'cursor'   => 0,
				'complete' => true,
			)
		);
		$this->reader->shouldReceive( 'get_by_id' )->never();
		$_POST = array(
			'rule_id' => '0',
			'rule'    => array(
				'name'        => '',
				'subject'     => 's',
				'body'        => 'b',
				'offset_days' => '-3',
			),
			'from'    => '2026-10-01',
			'to'      => '2026-10-01',
		);

		$r = $this->respond( array( DateMessagesAjaxEndpoint::class, 'preview' ) );

		$this->assertTrue( $r->success );
	}

	public function test_preview_reports_an_invalid_unsaved_rule(): void {
		$this->resolver->shouldReceive( 'resolve' )->never();
		$_POST = array(
			'rule'    => array(
				'subject'     => 's',
				'body'        => 'b',
				'offset_days' => '99',
			),
			'from'    => '2026-10-01',
			'to'      => '2026-10-01',
		);

		$r = $this->respond( array( DateMessagesAjaxEndpoint::class, 'preview' ) );

		$this->assertSame( 400, $r->status );
	}

	public function test_collect_caps_the_listed_rows_but_counts_everyone(): void {
		$rows = array();
		for ( $i = 1; $i <= DateMessagesAjaxEndpoint::PREVIEW_ROW_LIMIT + 5; $i++ ) {
			$rows[] = $this->row( $i, 'will_send' );
		}
		$this->resolver->shouldReceive( 'resolve' )->andReturn(
			array(
				'rows'     => $rows,
				'cursor'   => 0,
				'complete' => true,
			)
		);
		$day = new \DateTimeImmutable( '2026-10-01' );

		$out = DateMessagesAjaxEndpoint::collect( $this->rule(), $day, $day, true );

		$this->assertCount( DateMessagesAjaxEndpoint::PREVIEW_ROW_LIMIT, $out['rows'] );
		$this->assertTrue( $out['truncated'] );
		$this->assertSame( DateMessagesAjaxEndpoint::PREVIEW_ROW_LIMIT + 5, $out['totals']['will_send'] );
	}

	public function test_test_send_needs_the_manage_capability(): void {
		Mockery::mock( 'alias:FreeFormCertificate\Scheduling\SchedulingMailer' )->shouldReceive( 'send' )->never();

		$r = $this->respond( array( DateMessagesAjaxEndpoint::class, 'test_send' ) );

		$this->assertSame( 403, $r->status );
	}

	public function test_test_send_goes_to_the_operator_marked_as_a_test_and_records_nothing(): void {
		$this->caps = array( 'ffc_manage_date_messages' );
		Functions\when( 'wp_get_current_user' )->justReturn( (object) array( 'user_email' => 'op@example.org' ) );
		Mockery::mock( 'alias:FreeFormCertificate\DateMessages\MessageBuilder' )->shouldReceive( 'sample' )->once()->andReturn(
			array(
				'subject' => 'Hi Maria',
				'body'    => '<p>B</p>',
			)
		);
		Mockery::mock( 'alias:FreeFormCertificate\DateMessages\DeliveryLog' )->shouldReceive( 'claim', 'start_run', 'bump' )->never();
		Mockery::mock( 'alias:FreeFormCertificate\Scheduling\SchedulingMailer' )->shouldReceive( 'send' )->once()->with(
			'op@example.org',
			'[TEST] Hi Maria',
			'<p>B</p>',
			array(),
			true,
			'plugin:ffcertificate_date_messages'
		)->andReturn( true );
		$this->reader->shouldReceive( 'get_by_id' )->andReturn( $this->rule() );
		$_POST = array( 'rule_id' => '3' );

		$r = $this->respond( array( DateMessagesAjaxEndpoint::class, 'test_send' ) );

		$this->assertTrue( $r->success );
	}

	public function test_a_refused_test_send_is_reported(): void {
		$this->caps = array( 'ffc_manage_date_messages' );
		Functions\when( 'wp_get_current_user' )->justReturn( (object) array( 'user_email' => 'op@example.org' ) );
		Mockery::mock( 'alias:FreeFormCertificate\DateMessages\MessageBuilder' )->shouldReceive( 'sample' )->andReturn(
			array(
				'subject' => 'S',
				'body'    => 'B',
			)
		);
		Mockery::mock( 'alias:FreeFormCertificate\Scheduling\SchedulingMailer' )->shouldReceive( 'send' )->andReturn( false );
		$this->reader->shouldReceive( 'get_by_id' )->andReturn( $this->rule() );
		$_POST = array( 'rule_id' => '3' );

		$r = $this->respond( array( DateMessagesAjaxEndpoint::class, 'test_send' ) );

		$this->assertSame( 500, $r->status );
	}
}

/**
 * A `wp_send_json_*` call, caught.
 */
class JsonResponse extends \Exception {

	/**
	 * @param bool  $success Success flag.
	 * @param mixed $data    Payload.
	 * @param int   $status  HTTP status.
	 */
	public function __construct( public bool $success, public $data, public int $status ) {
		parent::__construct( 'json' );
	}
}
