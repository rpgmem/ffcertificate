<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Recruitment\RecruitmentCallMailQueue;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * The background call e-mails of a bulk call (#2694).
 *
 * Runs the real reader, writer and dispatcher over a `$wpdb` double. The
 * dispatcher finds no call row, so every send reports "not sent", which is
 * the branch that proves a batch always moves a call out of `queued`: the
 * property that makes the batch terminate.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentCallMailQueue
 */
class RecruitmentCallMailQueueTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $wpdb;

	/** @var mixed The `$wpdb` global before the test. */
	private $previous_wpdb;

	/** Whether `$wpdb` was set before the test. */
	private bool $had_wpdb = false;

	/** @var list<int> IDs the queued-email SELECT answers. */
	private array $queued = array();

	/** @var list<array{0: array<string, mixed>, 1: array<string, mixed>}> Updates: [ data, where ]. */
	private array $updates = array();

	/** @var list<string> Hooks scheduled. */
	private array $scheduled = array();

	/** @var list<array{sql: string, args: array<int, mixed>}> Statements prepared, by token. */
	private array $prepared = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->had_wpdb      = array_key_exists( 'wpdb', $GLOBALS );
		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;

		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( $ts, $hook ) {
				$this->scheduled[] = (string) $hook;
				return true;
			}
		);

		$this->wpdb         = Mockery::mock( 'wpdb' );
		$this->wpdb->prefix = 'wp_';
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( $sql, ...$args ) {
				$this->prepared[] = array(
					'sql'  => (string) $sql,
					'args' => $args,
				);
				return 'Q' . ( count( $this->prepared ) - 1 );
			}
		);
		$this->wpdb->shouldReceive( 'get_col' )->andReturnUsing(
			function ( $token ) {
				$query = $this->statement( $token );
				$this->assertStringContainsString( 'email_status = %s AND cancelled_at IS NULL', $query['sql'] );
				$this->assertSame( 'queued', $query['args'][1] );
				return array_map( 'strval', array_slice( $this->queued, 0, (int) $query['args'][2] ) );
			}
		);
		// The dispatcher's call lookup: no such call, so nothing is sent.
		$this->wpdb->shouldReceive( 'get_row' )->andReturn( null );
		$this->wpdb->shouldReceive( 'update' )->andReturnUsing(
			function ( $table, $data, $where ) {
				$this->assertSame( 'wp_ffc_recruitment_call', $table );
				$this->updates[] = array( $data, $where );
				return 1;
			}
		);
		$GLOBALS['wpdb'] = $this->wpdb;
	}

	protected function tearDown(): void {
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->previous_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The statement a prepare() token stands for.
	 *
	 * @param mixed $token Token returned by prepare().
	 * @return array{sql: string, args: array<int, mixed>}
	 */
	private function statement( $token ): array {
		$this->assertSame( 1, preg_match( '/^Q(\d+)$/', (string) $token, $m ) );
		return $this->prepared[ (int) $m[1] ];
	}

	public function test_a_batch_records_every_call_it_reads_and_stops_short_of_a_full_one(): void {
		$this->queued = array( 7, 8 );

		$this->assertSame( 2, RecruitmentCallMailQueue::run_batch() );

		$this->assertSame( array( array( 'id' => 7 ), array( 'id' => 8 ) ), array_column( $this->updates, 1 ) );
		foreach ( $this->updates as $update ) {
			$this->assertSame( RecruitmentCallMailQueue::STATUS_NOT_SENT, $update[0]['email_status'], 'Every call read leaves the queue, sent or not.' );
			$this->assertIsInt( $update[0]['email_sent_at'] );
		}
		$this->assertSame( array(), $this->scheduled, 'A short batch was the last one.' );
	}

	public function test_a_full_batch_queues_the_next_one(): void {
		$this->queued = range( 1, RecruitmentCallMailQueue::BATCH_SIZE + 5 );

		$this->assertSame( RecruitmentCallMailQueue::BATCH_SIZE, RecruitmentCallMailQueue::run_batch() );

		$this->assertSame( array( RecruitmentCallMailQueue::HOOK ), $this->scheduled );
	}

	public function test_an_empty_queue_does_nothing(): void {
		$this->assertSame( 0, RecruitmentCallMailQueue::run_batch() );

		$this->assertSame( array(), $this->updates );
		$this->assertSame( array(), $this->scheduled );
	}

	public function test_queue_marks_the_calls_and_schedules_one_batch(): void {
		$statements = array();
		$this->wpdb->shouldReceive( 'query' )->andReturnUsing(
			function ( $token ) use ( &$statements ) {
				$statements[] = $this->statement( $token );
				return 3;
			}
		);

		$this->assertSame( 3, RecruitmentCallMailQueue::queue( array( 4, 5, 6, 5, 0 ) ) );

		$this->assertCount( 1, $statements );
		$this->assertStringContainsString( 'SET email_status = %s WHERE id IN (%d, %d, %d)', $statements[0]['sql'] );
		$this->assertSame( array( 'wp_ffc_recruitment_call', 'queued', 4, 5, 6 ), $statements[0]['args'][0] );
		$this->assertSame( array( RecruitmentCallMailQueue::HOOK ), $this->scheduled );
	}

	public function test_queue_of_nothing_schedules_nothing(): void {
		$this->wpdb->shouldNotReceive( 'query' );

		$this->assertSame( 0, RecruitmentCallMailQueue::queue( array() ) );
		$this->assertSame( array(), $this->scheduled );
	}

	public function test_a_queued_batch_is_not_queued_twice(): void {
		Functions\when( 'wp_next_scheduled' )->justReturn( 123 );

		$this->assertFalse( RecruitmentCallMailQueue::schedule() );
		$this->assertSame( array(), $this->scheduled );
	}
}
