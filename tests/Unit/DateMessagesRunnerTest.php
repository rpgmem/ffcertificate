<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\DateMessages\RecipientResolver;
use FreeFormCertificate\DateMessages\Rule;
use FreeFormCertificate\DateMessages\Runner;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Sending a rule's messages in batches (#1538).
 *
 * Every collaborator is an alias mock, so each test runs in its own process.
 *
 * @covers \FreeFormCertificate\DateMessages\Runner
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class DateMessagesRunnerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $log;

	/** @var Mockery\MockInterface */
	private $rules;

	/** @var Mockery\MockInterface */
	private $settings;

	/** @var Mockery\MockInterface */
	private $mailer;

	/** @var Mockery\MockInterface */
	private $resolver;

	/**
	 * Counter bumps: counter => total.
	 *
	 * @var array<string, int>
	 */
	private array $bumps = array();

	/**
	 * Continuations scheduled: list of args.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $scheduled = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->bumps     = array();
		$this->scheduled = array();

		Functions\when( '__' )->returnArg();
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => trim( (string) $v ) );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_timezone' )->alias( static fn() => new \DateTimeZone( 'America/Sao_Paulo' ) );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( $ts, $hook, $args ) {
				$this->scheduled[] = $args;
				return true;
			}
		);

		$this->log = Mockery::mock( 'alias:FreeFormCertificate\DateMessages\DeliveryLog' );
		$this->log->shouldReceive( 'bump' )->andReturnUsing(
			function ( $run, $counter, $by = 1 ) {
				$this->bumps[ $counter ] = ( $this->bumps[ $counter ] ?? 0 ) + $by;
			}
		)->byDefault();
		$this->log->shouldReceive( 'get_run' )->andReturn(
			array(
				'id'        => '7',
				'rule_id'   => '3',
				'target_to' => '2026-10-10',
			)
		)->byDefault();

		$this->rules    = Mockery::mock( 'alias:FreeFormCertificate\DateMessages\RuleReader' );
		$this->settings = Mockery::mock( 'alias:FreeFormCertificate\Settings\SettingsReader' );
		$this->settings->shouldReceive( 'emails_disabled' )->andReturn( false )->byDefault();
		$this->mailer   = Mockery::mock( 'alias:FreeFormCertificate\Scheduling\SchedulingMailer' );
		// The overload replaces the class, constants included, so the decision
		// names the runner switches on are declared on the double too.
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

		Mockery::mock( 'alias:FreeFormCertificate\DateMessages\MessageBuilder' )
			->shouldReceive( 'build' )->andReturn(
				array(
					'subject' => 'S',
					'body'    => 'B',
				)
			)->byDefault();
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
	 * @param int    $id       User id.
	 * @param string $decision Decision.
	 * @return array{user_id: int, email: string, name: string, decision: string}
	 */
	private function row( int $id, string $decision ): array {
		return array(
			'user_id'  => $id,
			'email'    => "u{$id}@example.org",
			'name'     => "User {$id}",
			'decision' => $decision,
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $rows     Rows.
	 * @param bool                             $complete Whether the day is done.
	 * @param int                              $cursor   Cursor.
	 */
	private function page( array $rows, bool $complete, int $cursor = 0 ): void {
		$this->resolver->shouldReceive( 'resolve' )->andReturn(
			array(
				'rows'     => $rows,
				'cursor'   => $cursor,
				'complete' => $complete,
			)
		);
	}

	public function test_each_decision_is_counted_and_only_will_send_is_sent(): void {
		$this->rules->shouldReceive( 'get_by_id' )->with( 3 )->andReturn( $this->rule() );
		$this->page(
			array(
				$this->row( 1, RecipientResolver::WILL_SEND ),
				$this->row( 2, RecipientResolver::OPTED_OUT ),
				$this->row( 3, RecipientResolver::NO_EMAIL ),
				$this->row( 4, RecipientResolver::OUT_OF_AUDIENCE ),
				$this->row( 5, RecipientResolver::ALREADY_SENT ),
			),
			true,
			5
		);
		$this->log->shouldReceive( 'claim' )->once()->with( 7, 3, 1, '2026-10-10' )->andReturn( true );
		$this->mailer->shouldReceive( 'send' )->once()->with( 'u1@example.org', 'S', 'B', array(), true, 'plugin:ffcertificate_date_messages', array() )->andReturn( true );
		$this->log->shouldReceive( 'finish_run' )->once()->with( 7 );

		Runner::process( 7, '2026-10-10', 0 );

		$this->assertSame(
			array(
				'sent'            => 1,
				'opted_out'       => 1,
				'no_email'        => 1,
				'out_of_audience' => 1,
			),
			$this->bumps,
			'An already-sent person is neither sent nor counted again.'
		);
	}

	public function test_a_lost_claim_sends_nothing(): void {
		$this->rules->shouldReceive( 'get_by_id' )->andReturn( $this->rule() );
		$this->page( array( $this->row( 1, RecipientResolver::WILL_SEND ) ), true, 1 );
		$this->log->shouldReceive( 'claim' )->andReturn( false );
		$this->mailer->shouldReceive( 'send' )->never();
		$this->log->shouldReceive( 'finish_run' )->once();

		Runner::process( 7, '2026-10-10', 0 );

		$this->assertSame( array(), $this->bumps );
	}

	public function test_a_refused_send_releases_its_claim_and_counts_a_failure(): void {
		$this->rules->shouldReceive( 'get_by_id' )->andReturn( $this->rule() );
		$this->page( array( $this->row( 1, RecipientResolver::WILL_SEND ) ), true, 1 );
		$this->log->shouldReceive( 'claim' )->andReturn( true );
		$this->mailer->shouldReceive( 'send' )->andReturn( false );
		$this->log->shouldReceive( 'release' )->once()->with( 3, 1, '2026-10-10' );
		$this->log->shouldReceive( 'finish_run' )->once();

		Runner::process( 7, '2026-10-10', 0 );

		$this->assertSame( array( 'failed' => 1 ), $this->bumps );
	}

	public function test_an_unfinished_day_continues_from_the_cursor(): void {
		$this->rules->shouldReceive( 'get_by_id' )->andReturn( $this->rule() );
		$this->page( array(), false, 412 );
		$this->log->shouldReceive( 'finish_run' )->never();

		Runner::process( 7, '2026-10-09', 0 );

		$this->assertSame( array( array( 7, '2026-10-09', 412 ) ), $this->scheduled );
	}

	public function test_a_finished_day_moves_to_the_next_one_inside_the_range(): void {
		$this->rules->shouldReceive( 'get_by_id' )->andReturn( $this->rule() );
		$this->page( array(), true, 0 );
		$this->log->shouldReceive( 'finish_run' )->never();

		Runner::process( 7, '2026-10-09', 0 );

		$this->assertSame( array( array( 7, '2026-10-10', 0 ) ), $this->scheduled );
	}

	public function test_a_deleted_rule_or_disabled_emails_finish_the_run_without_claiming(): void {
		$this->rules->shouldReceive( 'get_by_id' )->andReturn( null );
		$this->log->shouldReceive( 'claim' )->never();
		$this->log->shouldReceive( 'finish_run' )->once()->with( 7 );

		Runner::process( 7, '2026-10-10', 0 );
	}

	public function test_a_rule_deactivated_mid_run_finishes_it_without_claiming(): void {
		$this->rules->shouldReceive( 'get_by_id' )->andReturn( $this->rule( array( 'is_active' => '0' ) ) );
		$this->resolver->shouldReceive( 'resolve' )->never();
		$this->log->shouldReceive( 'claim' )->never();
		$this->log->shouldReceive( 'finish_run' )->once()->with( 7 );

		Runner::process( 7, '2026-10-10', 0 );

		$this->assertSame( array(), $this->scheduled, 'No later batch is queued.' );
	}

	public function test_start_refuses_an_inactive_rule(): void {
		$this->log->shouldReceive( 'start_run' )->never();

		$result = Runner::start( $this->rule( array( 'is_active' => '0' ) ), new \DateTimeImmutable( '2026-10-06' ), new \DateTimeImmutable( '2026-10-06' ), 'manual', 1 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_date_messages_inactive', $result->get_error_code() );
	}

	public function test_disabled_emails_stop_the_run_before_any_claim(): void {
		$this->rules->shouldReceive( 'get_by_id' )->andReturn( $this->rule() );
		$this->settings->shouldReceive( 'emails_disabled' )->andReturn( true );
		$this->log->shouldReceive( 'claim' )->never();
		$this->log->shouldReceive( 'finish_run' )->once();

		Runner::process( 7, '2026-10-10', 0 );
	}

	/**
	 * @dataProvider bad_ranges
	 *
	 * @param string $from From.
	 * @param string $to   To.
	 */
	public function test_start_refuses_a_range_that_is_reversed_or_too_wide( string $from, string $to ): void {
		$this->log->shouldReceive( 'start_run' )->never();

		$result = Runner::start( $this->rule(), new \DateTimeImmutable( $from ), new \DateTimeImmutable( $to ), 'manual', 1 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_date_messages_range', $result->get_error_code() );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function bad_ranges(): array {
		return array(
			'reversed'    => array( '2026-10-10', '2026-10-09' ),
			'32 days'     => array( '2026-10-01', '2026-11-01' ),
		);
	}

	public function test_the_daily_job_targets_only_the_date_the_offset_points_at(): void {
		$week_before = $this->rule( array( 'id' => 3, 'offset_days' => -7 ) );
		$on_the_day  = $this->rule( array( 'id' => 4 ) );
		$silent      = $this->rule( array( 'id' => 5, 'send_to_user' => '0' ) );
		$this->rules->shouldReceive( 'active' )->andReturn( array( $week_before, $on_the_day, $silent ) );

		$runs = array();
		$this->log->shouldReceive( 'start_run' )->andReturnUsing(
			static function ( $rule_id, $trigger, $from, $to ) use ( &$runs ) {
				$runs[ $rule_id ] = array( $trigger, $from, $to );
				return 0; // Stop before processing.
			}
		);

		$this->log->shouldReceive( 'purge_expired' )->once()->andReturn( 0 );

		$today = Runner::today()->format( 'Y-m-d' );
		Runner::run_daily();

		$this->assertSame(
			array(
				// Today's target only: no catch-up of yesterday, which would reach
				// people a day late (#1598).
				3 => array( 'cron', gmdate( 'Y-m-d', strtotime( $today . ' +7 days' ) ), gmdate( 'Y-m-d', strtotime( $today . ' +7 days' ) ) ),
				4 => array( 'cron', $today, $today ),
			),
			$runs,
			'A rule that does not e-mail the person opens no run.'
		);
	}

	public function test_the_daily_job_sends_nothing_while_emails_are_disabled_but_still_prunes(): void {
		$this->settings->shouldReceive( 'emails_disabled' )->andReturn( true );
		$this->rules->shouldReceive( 'active' )->never();
		// The kill-switch stops sending, not the one-year history (#1647).
		$this->log->shouldReceive( 'purge_expired' )->once()->andReturn( 0 );

		Runner::run_daily();
	}
	public function test_start_schedules_the_digest_before_the_first_batch(): void {
		$order = array();
		$rule  = $this->rule();
		$this->log->shouldReceive( 'start_run' )->once()->with( 3, 'manual', '2026-10-01', '2026-10-02', 5 )->andReturn( 7 );
		Mockery::mock( 'alias:FreeFormCertificate\DateMessages\Digest' )->shouldReceive( 'schedule' )->once()->with( 7, $rule )->andReturnUsing(
			static function () use ( &$order ) {
				$order[] = 'digest';
			}
		);
		$this->log->shouldReceive( 'get_run' )->andReturnUsing(
			static function () use ( &$order ) {
				$order[] = 'batch';
				return null;
			}
		);

		$result = Runner::start( $rule, new \DateTimeImmutable( '2026-10-01' ), new \DateTimeImmutable( '2026-10-02' ), 'manual', 5 );

		$this->assertSame( 7, $result );
		$this->assertSame( array( 'digest', 'batch' ), $order );
	}
}
