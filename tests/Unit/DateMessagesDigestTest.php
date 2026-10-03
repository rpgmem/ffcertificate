<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\DateMessages\Digest;
use FreeFormCertificate\DateMessages\Rule;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * The manager digest of a run (#1538).
 *
 * @covers \FreeFormCertificate\DateMessages\Digest
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class DateMessagesDigestTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $log;

	/** @var Mockery\MockInterface */
	private $rules;

	/** @var Mockery\MockInterface */
	private $mailer;

	/**
	 * Accounts by id: [email, caps].
	 *
	 * @var array<int, array{0: string, 1: array<int, string>}>
	 */
	private array $users = array();

	/**
	 * Bodies sent, by address.
	 *
	 * @var array<string, string>
	 */
	private array $sent = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->sent  = array();
		$this->users = array(
			1 => array( 'admin@example.org', array( 'manage_options' ) ),
			2 => array( 'viewer@example.org', array( 'ffc_view_date_messages' ) ),
			3 => array( 'nobody@example.org', array() ),
			4 => array( 'not-an-address', array( 'manage_options' ) ),
		);

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( static function ( $t ) { echo $t; } );
		Functions\when( 'number_format_i18n' )->alias( static fn( $n ) => (string) $n );
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => trim( (string) $v ) );
		Functions\when( 'get_bloginfo' )->justReturn( 'Site' );
		Functions\when( 'is_email' )->alias( static fn( $v ) => false !== filter_var( $v, FILTER_VALIDATE_EMAIL ) );
		Functions\when( 'get_userdata' )->alias(
			function ( $id ) {
				if ( ! isset( $this->users[ $id ] ) ) {
					return false;
				}
				$user             = new \WP_User();
				$user->ID         = $id;
				$user->user_email = $this->users[ $id ][0];
				return $user;
			}
		);
		Functions\when( 'user_can' )->alias( fn( $user, $cap ) => in_array( $cap, $this->users[ $user->ID ][1] ?? array(), true ) );

		Mockery::mock( 'alias:FreeFormCertificate\Core\DateFormatter' )->shouldReceive( 'format_wallclock_date' )->andReturnUsing( static fn( $d ) => (string) $d );

		$this->log    = Mockery::mock( 'alias:FreeFormCertificate\DateMessages\DeliveryLog' );
		$this->rules  = Mockery::mock( 'alias:FreeFormCertificate\DateMessages\RuleReader' );
		$this->mailer = Mockery::mock( 'alias:FreeFormCertificate\Scheduling\SchedulingMailer' );
		$this->mailer->shouldReceive( 'send' )->andReturnUsing(
			function ( $to, $subject, $body ) {
				$this->sent[ $to ] = $body;
				return true;
			}
		)->byDefault();

		global $wpdb;
		$wpdb        = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->users  = 'wp_users';
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'NAMES' )->byDefault();
		$wpdb->shouldReceive( 'get_col' )->andReturn( array( 'Ana', 'Bruno' ) )->byDefault();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param array<string, mixed> $over Overrides.
	 */
	private function rule( array $over = array() ): Rule {
		$rule = Rule::from_array(
			array_merge(
				array(
					'id'              => 3,
					'name'            => 'Birthdays',
					'subject'         => 's',
					'body'            => 'b',
					'digest_enabled'  => '1',
					'digest_mode'     => 'summary',
					'digest_user_ids' => array( 1, 2, 3, 4, 99 ),
				),
				$over
			)
		);
		$this->assertInstanceOf( Rule::class, $rule );
		return $rule;
	}

	/**
	 * @param array<string, mixed> $over Overrides.
	 * @return array<string, mixed>
	 */
	private function run_row( array $over = array() ): array {
		return array_merge(
			array(
				'id'              => '7',
				'rule_id'         => '3',
				'trigger_kind'    => 'cron',
				'target_from'     => '2026-10-09',
				'target_to'       => '2026-10-10',
				'sent'            => '5',
				'opted_out'       => '1',
				'no_email'        => '0',
				'out_of_audience' => '2',
				'failed'          => '0',
				'digest_sent_at'  => null,
			),
			$over
		);
	}

	public function test_schedule_waits_a_day_and_only_when_the_rule_asks(): void {
		$scheduled = array();
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->alias(
			static function ( $ts, $hook, $args ) use ( &$scheduled ) {
				$scheduled[] = array( $ts - time(), $hook, $args );
				return true;
			}
		);

		Digest::schedule( 7, $this->rule() );
		Digest::schedule( 8, $this->rule( array( 'digest_enabled' => '0' ) ) );
		Digest::schedule( 9, $this->rule( array( 'digest_user_ids' => array() ) ) );
		Digest::schedule( 0, $this->rule() );

		$this->assertCount( 1, $scheduled );
		$this->assertEqualsWithDelta( DAY_IN_SECONDS, $scheduled[0][0], 2 );
		$this->assertSame( array( Digest::HOOK, array( 7 ) ), array( $scheduled[0][1], $scheduled[0][2] ) );
	}

	public function test_a_summary_goes_to_every_manager_still_allowed_and_marks_the_run(): void {
		$this->log->shouldReceive( 'get_run' )->with( 7 )->andReturn( $this->run_row() );
		$this->rules->shouldReceive( 'get_by_id' )->with( 3 )->andReturn( $this->rule() );
		$this->log->shouldReceive( 'mark_digest_sent' )->once()->with( 7 );

		Digest::send( 7 );

		$this->assertSame( array( 'admin@example.org', 'viewer@example.org' ), array_keys( $this->sent ), 'No capability, a bad address and a deleted account receive nothing.' );
		$this->assertStringContainsString( 'Birthdays', $this->sent['admin@example.org'] );
		$this->assertStringNotContainsString( 'Ana', $this->sent['admin@example.org'], 'The summary mode lists no names.' );
	}

	public function test_detailed_names_reach_only_the_managers_allowed_to_see_them(): void {
		$this->log->shouldReceive( 'get_run' )->andReturn( $this->run_row() );
		$this->rules->shouldReceive( 'get_by_id' )->andReturn( $this->rule( array( 'digest_mode' => 'detailed' ) ) );
		$this->log->shouldReceive( 'mark_digest_sent' )->once();

		Digest::send( 7 );

		$this->assertStringContainsString( 'Ana', $this->sent['admin@example.org'] );
		$this->assertStringNotContainsString( 'Ana', $this->sent['viewer@example.org'], 'A viewer without the PII capability gets the counts only.' );
	}

	public function test_a_run_already_reported_sends_nothing(): void {
		$this->log->shouldReceive( 'get_run' )->andReturn( $this->run_row( array( 'digest_sent_at' => '1700000000' ) ) );
		$this->rules->shouldReceive( 'get_by_id' )->never();
		$this->mailer->shouldReceive( 'send' )->never();

		Digest::send( 7 );
	}

	public function test_a_run_that_reached_nobody_is_closed_without_a_digest(): void {
		$this->log->shouldReceive( 'get_run' )->andReturn(
			$this->run_row(
				array(
					'sent'            => '0',
					'opted_out'       => '0',
					'out_of_audience' => '0',
				)
			)
		);
		$this->rules->shouldReceive( 'get_by_id' )->andReturn( $this->rule() );
		$this->mailer->shouldReceive( 'send' )->never();
		$this->log->shouldReceive( 'mark_digest_sent' )->once()->with( 7 );

		Digest::send( 7 );
	}

	public function test_a_deleted_rule_or_a_digest_switched_off_sends_nothing(): void {
		$this->log->shouldReceive( 'get_run' )->andReturn( $this->run_row() );
		$this->rules->shouldReceive( 'get_by_id' )->andReturn( null, $this->rule( array( 'digest_enabled' => '0' ) ) );
		$this->mailer->shouldReceive( 'send' )->never();
		$this->log->shouldReceive( 'mark_digest_sent' )->never();

		Digest::send( 7 );
		Digest::send( 7 );
	}

	public function test_an_unknown_run_sends_nothing(): void {
		$this->log->shouldReceive( 'get_run' )->andReturn( null );
		$this->mailer->shouldReceive( 'send' )->never();

		Digest::send( 99 );
	}
}
