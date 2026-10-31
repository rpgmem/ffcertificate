<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Core\SensitiveFieldRegistry;
use FreeFormCertificate\Recruitment\RecruitmentAccountLinker;
use FreeFormCertificate\Tests\Support\ActivityLogBuffer;
use FreeFormCertificate\Tests\Support\CallLog;
use FreeFormCertificate\Tests\Support\Identifiers;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Account creation for recruitment candidates, after the import (#2245).
 *
 * Runs the real UserCreator over a `$wpdb` double: the identity index knows
 * the person, so the account is resolved by hash, which is the branch an
 * import reaches most. What is pinned is the linker's own contract: nothing
 * for a draft, a full batch queues the next one by cursor, only an empty
 * link is filled, and the "promoted" entry is written once per link made.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentAccountLinker
 */
class RecruitmentAccountLinkerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const PROFILES = 'wp_ffc_user_profiles';

	/** @var Mockery\MockInterface */
	private $wpdb;

	/** @var mixed The `$wpdb` global before the test. */
	private $previous_wpdb;

	/** Whether `$wpdb` was set before the test. */
	private bool $had_wpdb = false;

	/** @var list<array{0: string, 1: array<int, mixed>}> Prepared statements. */
	private array $prepared = array();

	/** @var object|null The notice the reader resolves. */
	private ?object $notice = null;

	/** @var list<object> Candidate rows the batch SELECT answers. */
	private array $batch = array();

	/** @var string|null The account the identity index resolves. */
	private ?string $identity_owner = null;

	/** @var int Rows the conditional link UPDATE reports. */
	private int $claimed = 1;

	/** @var list<array{0: int, 1: int}> Link UPDATEs: [ user_id, candidate_id ]. */
	private array $links = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->had_wpdb      = array_key_exists( 'wpdb', $GLOBALS );
		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;

		ActivityLogBuffer::reset();
		ActivityLogBuffer::stub_functions();
		Functions\when( 'get_option' )->alias(
			static fn( $key, $fallback = false ) => 'ffc_settings' === $key ? ActivityLogBuffer::ENABLED_SETTINGS : $fallback
		);
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'current_time' )->justReturn( '2026-10-31 10:00:00' );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'wp_salt' )->justReturn( 'test-salt' );
		Functions\when( 'wp_cache_supports' )->justReturn( false );
		Functions\when( 'wp_cache_flush' )->justReturn( true );
		Functions\when( 'get_user_by' )->justReturn( false );
		// A candidate the index does not know reaches account creation, which
		// fails here; the batch moves on either way.
		Functions\when( 'username_exists' )->justReturn( false );
		Functions\when( 'remove_accents' )->returnArg();
		Functions\when( 'sanitize_user' )->returnArg();
		Functions\when( 'wp_generate_password' )->justReturn( 'generated-password' );
		Functions\when( 'wp_create_user' )->justReturn( new \WP_Error( 'existing_user_login', 'refused' ) );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->justReturn( true );

		$this->wpdb         = Mockery::mock( 'wpdb' );
		$this->wpdb->prefix = 'wp_';
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( $sql, ...$args ) {
				if ( 1 === count( $args ) && is_array( $args[0] ) ) {
					$args = $args[0];
				}
				$this->prepared[] = array( (string) $sql, array_values( $args ) );
				return 'Q' . ( count( $this->prepared ) - 1 );
			}
		);
		$this->wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function ( $query ) {
				[ $sql, $args ] = $this->statement( $query );
				if ( 'wp_ffc_recruitment_notice' === $args[0] ) {
					return $this->notice;
				}
				if ( self::PROFILES === $args[0] ) {
					return null;
				}
				if ( 'wp_ffc_recruitment_candidate' === $args[0] ) {
					return (object) array(
						'id'      => (string) $args[1],
						'user_id' => null,
					);
				}
				$this->fail( 'Unexpected get_row: ' . $sql );
			}
		);
		$this->wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $query ) {
				[ $sql ] = $this->statement( $query );
				$this->assertStringContainsString( 'c.user_id IS NULL AND c.id > %d', $sql );
				return $this->batch;
			}
		);
		$this->wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( $query ) {
				[ $sql ] = $this->statement( $query );
				if ( false !== strpos( $sql, 'SELECT user_id FROM %i WHERE (' ) ) {
					return $this->identity_owner;
				}
				return null;
			}
		);
		$this->wpdb->shouldReceive( 'get_col' )->andReturn( array() );
		$this->wpdb->shouldReceive( 'query' )->andReturnUsing(
			function ( $query ) {
				[ $sql, $args ] = $this->statement( $query );
				if ( 0 === strpos( $sql, 'UPDATE %i SET user_id = %d, updated_at = %s WHERE id = %d AND user_id IS NULL' ) ) {
					$this->links[] = array( (int) $args[1], (int) $args[3] );
					return $this->claimed;
				}
				return 0;
			}
		);
		$this->wpdb->shouldReceive( 'insert' )->andReturn( 1 );
		$this->wpdb->shouldReceive( 'update' )->andReturn( 1 );
		$this->wpdb->shouldReceive( 'replace' )->andReturn( 1 );

		$GLOBALS['wpdb'] = $this->wpdb;
	}

	protected function tearDown(): void {
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->previous_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		ActivityLogBuffer::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The SQL and bound values a prepared token stands for.
	 *
	 * @param mixed $query Token returned by prepare().
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	private function statement( $query ): array {
		$index = (int) substr( (string) $query, 1 );
		$this->assertArrayHasKey( $index, $this->prepared, 'Every query goes through prepare().' );
		return $this->prepared[ $index ];
	}

	private function notice( string $status ): void {
		$this->notice = (object) array(
			'id'     => '7',
			'status' => $status,
		);
	}

	private function candidate( int $id ): object {
		return (object) array(
			'id'              => (string) $id,
			'name'            => 'Maria',
			'cpf_hash'        => SensitiveFieldRegistry::hash_identifier( 'cpf', Identifiers::CPF_A ),
			'rf_hash'         => null,
			'email_encrypted' => Encryption::encrypt( 'maria@example.test' ),
		);
	}

	/**
	 * "Promoted" entries the batch logged.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function promoted(): array {
		return array_values(
			array_map(
				static fn( array $e ): array => $e['context'],
				array_filter(
					ActivityLogBuffer::contexts(),
					static fn( array $e ): bool => 'recruitment_candidate_promoted' === $e['action']
				)
			)
		);
	}

	public function test_schedule_does_nothing_for_a_draft(): void {
		$queued = CallLog::record( 'wp_schedule_single_event', true );

		$this->assertFalse( RecruitmentAccountLinker::schedule( 7, 'draft' ) );
		$this->assertSame( 0, $queued->count(), 'No account for a draft notice (#2245).' );
	}

	public function test_schedule_reads_the_status_when_the_caller_has_none(): void {
		$queued = CallLog::record( 'wp_schedule_single_event', true );
		$this->notice( 'draft' );

		$this->assertFalse( RecruitmentAccountLinker::schedule( 7 ) );
		$this->assertSame( 0, $queued->count() );
	}

	public function test_schedule_queues_the_first_batch_once_the_list_is_published(): void {
		$queued = CallLog::record( 'wp_schedule_single_event', true );

		$this->assertTrue( RecruitmentAccountLinker::schedule( 7, 'preliminary' ) );
		$this->assertSame( 1, $queued->count() );
		$this->assertSame( RecruitmentAccountLinker::HOOK, $queued->calls()[0][1] );
		$this->assertSame( array( 7, 0 ), $queued->calls()[0][2] );
	}

	public function test_schedule_does_not_queue_a_second_first_batch(): void {
		Functions\when( 'wp_next_scheduled' )->justReturn( 1234 );
		$queued = CallLog::record( 'wp_schedule_single_event', true );

		$this->assertFalse( RecruitmentAccountLinker::schedule( 7, 'definitive' ) );
		$this->assertSame( 0, $queued->count() );
	}

	public function test_run_batch_does_nothing_for_a_draft(): void {
		$this->notice( 'draft' );
		$this->wpdb->shouldNotReceive( 'get_results' );

		$this->assertSame( 0, RecruitmentAccountLinker::run_batch( 7 ) );
	}

	public function test_run_batch_links_an_unlinked_candidate_and_logs_it_once(): void {
		$this->notice( 'preliminary' );
		$this->batch          = array( $this->candidate( 31 ) );
		$this->identity_owner = '808';
		$notify               = CallLog::record( 'wp_new_user_notification', null );
		$queued               = CallLog::record( 'wp_schedule_single_event', true );

		$this->assertSame( 1, RecruitmentAccountLinker::run_batch( 7, 0 ) );

		$this->assertSame( array( array( 808, 31 ) ), $this->links, 'Only an empty link is filled.' );
		$this->assertSame(
			array(
				array(
					'candidate_id' => 31,
					'user_id'      => 808,
				),
			),
			$this->promoted()
		);
		$this->assertSame( 0, $notify->count(), 'No welcome e-mail in bulk (#2245).' );
		$this->assertSame( 0, $queued->count(), 'A short batch is the last one.' );
	}

	public function test_run_batch_logs_nothing_when_the_candidate_is_linked_elsewhere(): void {
		$this->notice( 'definitive' );
		$this->batch          = array( $this->candidate( 31 ) );
		$this->identity_owner = '808';
		$this->claimed        = 0;

		$this->assertSame( 0, RecruitmentAccountLinker::run_batch( 7, 0 ) );
		$this->assertSame( array(), $this->promoted(), 'A re-import logs no false "promoted" (#2245).' );
	}

	public function test_a_full_batch_queues_the_next_one_from_its_last_candidate(): void {
		$this->notice( 'preliminary' );
		$this->batch = array_map(
			fn( int $i ): object => $this->candidate( 100 + $i ),
			range( 1, RecruitmentAccountLinker::BATCH_SIZE )
		);
		$queued      = CallLog::record( 'wp_schedule_single_event', true );

		RecruitmentAccountLinker::run_batch( 7, 0 );

		$this->assertSame( 1, $queued->count() );
		$this->assertSame( array( 7, 100 + RecruitmentAccountLinker::BATCH_SIZE ), $queued->calls()[0][2], 'The cursor moves whatever the accounts resolve to.' );
	}

	public function test_a_candidate_without_any_identifier_is_skipped(): void {
		$this->notice( 'preliminary' );
		$this->batch = array(
			(object) array(
				'id'              => '31',
				'name'            => 'Nobody',
				'cpf_hash'        => null,
				'rf_hash'         => null,
				'email_encrypted' => null,
			),
		);

		$this->assertSame( 0, RecruitmentAccountLinker::run_batch( 7, 0 ) );
		$this->assertSame( array(), $this->links );
	}

	public function test_handle_batch_runs_the_batch(): void {
		$this->notice( 'draft' );
		$this->wpdb->shouldNotReceive( 'get_results' );

		RecruitmentAccountLinker::handle_batch( 7, 0 );

		$this->assertSame( array(), $this->links );
	}

	public function test_register_hooks_the_batch_with_two_arguments(): void {
		$hooks = CallLog::record( 'add_action', true );

		RecruitmentAccountLinker::register();

		$this->assertSame(
			array( RecruitmentAccountLinker::HOOK, array( RecruitmentAccountLinker::class, 'handle_batch' ), 10, 2 ),
			$hooks->calls()[0]
		);
	}
}
