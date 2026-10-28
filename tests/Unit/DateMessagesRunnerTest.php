<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use FreeFormCertificate\DateMessages\Digest;
use FreeFormCertificate\DateMessages\Rule;
use FreeFormCertificate\DateMessages\Runner;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Sending a rule's messages in batches (#1538).
 *
 * The runner's collaborators run for real: DeliveryLog, RuleReader,
 * RecipientResolver with the birthday source, OptOut and the audience
 * membership, MessageBuilder, Digest, and SchedulingMailer through
 * EmailService down to a stubbed `wp_mail()`. Storage is a `$wpdb` double
 * that answers each statement by its SQL text from the rows a test sets,
 * records every write, and fails the test on any statement it does not know.
 *
 * @covers \FreeFormCertificate\DateMessages\Runner
 */
class DateMessagesRunnerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<string, mixed>|null The stored run 7; null for none. */
	private ?array $run = null;

	/** @var array<int, array<string, mixed>> Stored rules by id. */
	private array $rules = array();

	/** @var list<array<string, mixed>> What the active-rules query answers. */
	private array $active = array();

	/** @var list<array<string, mixed>> Birthday candidates the source query answers. */
	private array $candidates = array();

	/** @var array<int, string> Stored profile preferences JSON by user id. */
	private array $preferences = array();

	/** @var list<int> Users the delivery log already holds for the occurrence. */
	private array $delivered = array();

	/** @var list<string> Members of the rule's audience, as `$wpdb` returns them. */
	private array $members = array();

	/** @var int What the claim's INSERT IGNORE reports. */
	private int $claim_result = 1;

	/** @var bool What `wp_mail()` reports. */
	private bool $mail_result = true;

	/** @var bool Whether the run INSERT succeeds. */
	private bool $insert_ok = true;

	/** @var array<string, mixed> The stored `ffc_settings` option. */
	private array $settings = array();

	/** @var array<string, int> Counter bumps: counter => total. */
	private array $bumps = array();

	/** @var list<array<int, mixed>> Bound arguments of every claim. */
	private array $claims = array();

	/** @var list<int> Run ids closed. */
	private array $finished = array();

	/** @var list<array<string, mixed>> WHERE of every released claim. */
	private array $released = array();

	/** @var list<array<string, mixed>> Data of every run INSERT. */
	private array $inserts = array();

	/** @var list<array<int, mixed>> Continuations scheduled: list of args. */
	private array $scheduled = array();

	/** @var list<string> Order in which the observable steps happened. */
	private array $events = array();

	/** @var int How many times the birthday source read its candidates. */
	private int $source_reads = 0;

	/** @var list<array{0: string, 1: string, 2: string, 3: array<int, string>}> Messages handed to `wp_mail()`. */
	private array $mailed = array();

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->run          = array(
			'id'        => '7',
			'rule_id'   => '3',
			'target_to' => '2026-10-10',
		);
		$this->rules        = array();
		$this->active       = array();
		$this->candidates   = array();
		$this->preferences  = array();
		$this->delivered    = array();
		$this->members      = array();
		$this->claim_result = 1;
		$this->mail_result  = true;
		$this->insert_ok    = true;
		$this->settings     = array();
		$this->bumps        = array();
		$this->claims       = array();
		$this->finished     = array();
		$this->released     = array();
		$this->inserts      = array();
		$this->scheduled    = array();
		$this->events       = array();
		$this->source_reads = 0;
		$this->mailed       = array();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'sanitize_hex_color' )->returnArg();
		Functions\when( 'sanitize_key' )->alias( static fn( $v ) => strtolower( (string) $v ) );
		Functions\when( 'sanitize_sql_orderby' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $v ) => strip_tags( (string) $v ) );
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => trim( (string) $v ) );
		Functions\when( 'wp_parse_args' )->alias( static fn( $args, $defaults ) => array_merge( $defaults, $args ) );
		Functions\when( 'is_email' )->alias( static fn( $v ) => false !== filter_var( (string) $v, FILTER_VALIDATE_EMAIL ) );
		Functions\when( 'wp_timezone' )->alias( static fn() => new \DateTimeZone( 'America/Sao_Paulo' ) );
		Functions\when( 'wp_date' )->alias(
			static fn( $format, $ts = null, $tz = null ) => ( new \DateTimeImmutable( '@' . (int) $ts ) )->setTimezone( $tz ?? new \DateTimeZone( 'UTC' ) )->format( $format )
		);
		Functions\when( 'get_option' )->alias(
			fn( $key, $default = false ) => 'ffc_settings' === $key ? $this->settings : $default
		);
		Functions\when( 'get_bloginfo' )->justReturn( 'SME' );
		Functions\when( 'home_url' )->alias( static fn( $p = '' ) => 'https://example.org' . $p );
		Functions\when( 'admin_url' )->alias( static fn( $p = '' ) => 'https://example.org/wp-admin/' . $p );
		Functions\when( 'add_query_arg' )->alias( static fn( $args, $url ) => $url . '?' . http_build_query( $args ) );
		Functions\when( 'wp_salt' )->justReturn( 'salt' );
		Functions\when( 'get_user_meta' )->justReturn( '' );
		Functions\when( 'get_userdata' )->alias(
			static fn( $id ) => (object) array(
				'ID'              => (int) $id,
				'display_name'    => 'User ' . (int) $id,
				'first_name'      => '',
				'last_name'       => '',
				'user_registered' => '2020-01-01 00:00:00',
			)
		);
		Functions\when( 'wp_mail' )->alias(
			function ( $to, $subject, $body, $headers = array() ) {
				$this->mailed[] = array( $to, $subject, $body, $headers );
				return $this->mail_result;
			}
		);
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( $ts, $hook, $args ) {
				$this->events[] = 'schedule:' . $hook;
				if ( Runner::BATCH_HOOK === $hook ) {
					$this->scheduled[] = $args;
				}
				return true;
			}
		);

		$this->install_wpdb();
	}

	protected function tearDown(): void {
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The `$wpdb` double: statements are answered from the test's rows by
	 * their SQL text and bound arguments, and writes are recorded.
	 */
	private function install_wpdb(): void {
		$wpdb           = Mockery::mock( 'wpdb' );
		$wpdb->prefix   = 'wp_';
		$wpdb->users    = 'wp_users';
		$wpdb->usermeta = 'wp_usermeta';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( $sql, ...$args ) {
				$flat = 1 === count( $args ) && is_array( $args[0] ) ? $args[0] : $args;
				return array(
					'sql'  => $sql,
					'args' => $flat,
				);
			}
		);
		$wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function ( $q ) {
				if ( 'SELECT * FROM %i WHERE id = %d' === $q['sql'] && 'wp_ffc_date_message_runs' === $q['args'][0] ) {
					$this->events[] = 'get_run';
					return 7 === $q['args'][1] ? $this->run : null;
				}
				if ( 'SELECT * FROM %i WHERE id = %d' === $q['sql'] && 'wp_ffc_date_message_rules' === $q['args'][0] ) {
					return $this->rules[ $q['args'][1] ] ?? null;
				}
				$this->fail( 'Unexpected get_row(): ' . $q['sql'] );
			}
		);
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $q ) {
				if ( false !== strpos( $q['sql'], 'FROM wp_users u INNER JOIN wp_usermeta m' ) ) {
					++$this->source_reads;
					return $this->candidates;
				}
				if ( 0 === strpos( $q['sql'], 'SELECT user_id, preferences FROM %i WHERE user_id IN' ) ) {
					$rows = array();
					foreach ( array_slice( $q['args'], 1 ) as $id ) {
						if ( isset( $this->preferences[ $id ] ) ) {
							$rows[] = array(
								'user_id'     => (string) $id,
								'preferences' => $this->preferences[ $id ],
							);
						}
					}
					return $rows;
				}
				if ( 'SELECT * FROM %i WHERE is_active = 1 ORDER BY id ASC' === $q['sql'] ) {
					return $this->active;
				}
				if ( 0 === strpos( $q['sql'], 'SELECT * FROM %i WHERE parent_id = %d' ) ) {
					// The audience has no sub-audiences.
					return array();
				}
				$this->fail( 'Unexpected get_results(): ' . $q['sql'] );
			}
		);
		$wpdb->shouldReceive( 'get_col' )->andReturnUsing(
			function ( $q ) {
				if ( 0 === strpos( $q['sql'], 'SELECT user_id FROM %i WHERE rule_id = %d AND occurrence_key = %s' ) ) {
					return array_map( 'strval', array_values( array_intersect( $this->delivered, array_slice( $q['args'], 4 ) ) ) );
				}
				if ( 0 === strpos( $q['sql'], 'SELECT DISTINCT user_id FROM %i WHERE audience_id IN' ) ) {
					return $this->members;
				}
				if ( 0 === strpos( $q['sql'], 'SELECT id FROM %i WHERE started_at < %d' ) ) {
					$this->events[] = 'purge';
					return array();
				}
				$this->fail( 'Unexpected get_col(): ' . $q['sql'] );
			}
		);
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( $q ) {
				if ( 'SHOW TABLES LIKE %s' === $q['sql'] ) {
					// No profile table: the profile falls back to the account.
					return null;
				}
				$this->fail( 'Unexpected get_var(): ' . $q['sql'] );
			}
		);
		$wpdb->shouldReceive( 'query' )->andReturnUsing(
			function ( $q ) {
				if ( 'UPDATE %i SET %i = %i + %d WHERE id = %d' === $q['sql'] ) {
					$this->bumps[ $q['args'][1] ] = ( $this->bumps[ $q['args'][1] ] ?? 0 ) + $q['args'][3];
					return 1;
				}
				if ( 0 === strpos( $q['sql'], 'INSERT IGNORE INTO %i (run_id, rule_id, user_id, occurrence_key, channel, sent_at)' ) ) {
					$this->claims[] = array_slice( $q['args'], 0, 6 );
					return $this->claim_result;
				}
				$this->fail( 'Unexpected query(): ' . $q['sql'] );
			}
		);
		$wpdb->shouldReceive( 'update' )->andReturnUsing(
			function ( $table, $data, $where ) {
				if ( 'wp_ffc_date_message_runs' === $table && isset( $data['finished_at'] ) ) {
					$this->finished[] = $where['id'];
					return 1;
				}
				$this->fail( 'Unexpected update() on ' . $table );
			}
		);
		$wpdb->shouldReceive( 'delete' )->andReturnUsing(
			function ( $table, $where ) {
				if ( 'wp_ffc_date_message_log' === $table ) {
					$this->released[] = $where;
					return 1;
				}
				$this->fail( 'Unexpected delete() on ' . $table );
			}
		);
		$wpdb->shouldReceive( 'insert' )->andReturnUsing(
			function ( $table, $data ) use ( $wpdb ) {
				if ( 'wp_ffc_date_message_runs' === $table ) {
					$this->events[]  = 'start_run';
					$this->inserts[] = $data;
					if ( ! $this->insert_ok ) {
						return false;
					}
					$wpdb->insert_id = 7;
					return 1;
				}
				$this->fail( 'Unexpected insert() on ' . $table );
			}
		);

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']   = $wpdb;
	}

	/**
	 * A stored rule row, as `$wpdb` returns it.
	 *
	 * @param array<string, mixed> $over Overrides.
	 * @return array<string, mixed>
	 */
	private function rule_row( array $over = array() ): array {
		return array_merge(
			array(
				'id'              => '3',
				'name'            => 'r',
				'source'          => 'birthday',
				'offset_days'     => '0',
				'audience_ids'    => '[]',
				'subject'         => 'Happy birthday {{first_name}}',
				'body'            => '<p>Hi {{name}}</p>',
				'send_to_user'    => '1',
				'digest_enabled'  => '0',
				'digest_mode'     => 'summary',
				'digest_user_ids' => '[]',
				'is_active'       => '1',
				'appearance'      => '',
				'kind'            => 'dated',
				'send_at'         => '0',
				'respect_opt_out' => '1',
			),
			$over
		);
	}

	/**
	 * Store rule 3.
	 *
	 * @param array<string, mixed> $over Overrides.
	 */
	private function store_rule( array $over = array() ): void {
		$this->rules[3] = $this->rule_row( $over );
	}

	/**
	 * @param array<string, mixed> $over Overrides.
	 * @return Rule
	 */
	private function rule( array $over = array() ): Rule {
		$rule = Rule::from_array( $this->rule_row( $over ) );
		$this->assertInstanceOf( Rule::class, $rule );
		return $rule;
	}

	/**
	 * A birthday candidate row, as the source query returns it.
	 *
	 * @param int    $id    User id.
	 * @param string $email Address; the default is a valid one.
	 * @return array<string, string>
	 */
	private function candidate( int $id, ?string $email = null ): array {
		return array(
			'user_id' => (string) $id,
			'email'   => $email ?? "u{$id}@example.org",
			'name'    => "User {$id}",
		);
	}

	/**
	 * One candidate that will be sent to.
	 */
	private function one_sendable_candidate(): void {
		$this->store_rule();
		$this->candidates = array( $this->candidate( 1 ) );
	}

	public function test_each_decision_is_counted_and_only_will_send_is_sent(): void {
		$this->store_rule( array( 'audience_ids' => '[11]' ) );
		$this->candidates  = array(
			$this->candidate( 1 ),
			$this->candidate( 2 ),
			$this->candidate( 3, '' ),
			$this->candidate( 4 ),
			$this->candidate( 5 ),
		);
		$this->members     = array( '1', '2', '3', '5' );
		$this->preferences = array( 2 => '{"notify_date_messages":false}' );
		$this->delivered   = array( 5 );
		// Stamp the mail-queue source headers, so the declared source is visible.
		Filters\expectApplied( 'ffcertificate_emit_source_headers' )->once()->andReturn( true );

		Runner::process( 7, '2026-10-10', 0 );

		$this->assertSame( array( array( 'wp_ffc_date_message_log', 7, 3, 1, '2026-10-10', 'user' ) ), $this->claims );
		$this->assertCount( 1, $this->mailed, 'Only the will-send person is mailed.' );
		$this->assertSame( 'u1@example.org', $this->mailed[0][0] );
		$this->assertSame( 'Happy birthday User', $this->mailed[0][1] );
		$this->assertStringContainsString( '<p>Hi User 1</p>', $this->mailed[0][2] );
		$this->assertContains( 'Content-Type: text/html; charset=UTF-8', $this->mailed[0][3] );
		$this->assertContains( 'X-TMQ-Source-Key: plugin:ffcertificate_date_messages', $this->mailed[0][3] );
		$this->assertSame( array( 7 ), $this->finished );
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
		$this->one_sendable_candidate();
		$this->claim_result = 0;

		Runner::process( 7, '2026-10-10', 0 );

		$this->assertCount( 1, $this->claims );
		$this->assertSame( array(), $this->mailed );
		$this->assertSame( array( 7 ), $this->finished );
		$this->assertSame( array(), $this->bumps );
	}

	public function test_a_refused_send_releases_its_claim_and_counts_a_failure(): void {
		$this->one_sendable_candidate();
		$this->mail_result = false;

		Runner::process( 7, '2026-10-10', 0 );

		$this->assertCount( 1, $this->mailed );
		$this->assertSame(
			array(
				array(
					'rule_id'        => 3,
					'user_id'        => 1,
					'occurrence_key' => '2026-10-10',
					'channel'        => 'user',
				),
			),
			$this->released
		);
		$this->assertSame( array( 7 ), $this->finished );
		$this->assertSame( array( 'failed' => 1 ), $this->bumps );
	}

	public function test_an_unfinished_day_continues_from_the_cursor(): void {
		$this->store_rule();
		// A full page (BATCH_SIZE people, ids 213 to 412), every one already
		// sent, so the day is not complete and nothing is mailed.
		$ids              = range( 412 - Runner::BATCH_SIZE + 1, 412 );
		$this->candidates = array_map( fn( int $id ) => $this->candidate( $id ), $ids );
		$this->delivered  = $ids;

		Runner::process( 7, '2026-10-09', 0 );

		$this->assertSame( array( array( 7, '2026-10-09', 412 ) ), $this->scheduled );
		$this->assertSame( array(), $this->finished );
		$this->assertSame( array(), $this->mailed );
	}

	public function test_a_finished_day_moves_to_the_next_one_inside_the_range(): void {
		$this->store_rule();

		Runner::process( 7, '2026-10-09', 0 );

		$this->assertSame( array( array( 7, '2026-10-10', 0 ) ), $this->scheduled );
		$this->assertSame( array(), $this->finished );
	}

	public function test_a_deleted_rule_finishes_the_run_without_claiming(): void {
		// No rule 3 is stored.
		Runner::process( 7, '2026-10-10', 0 );

		$this->assertSame( array(), $this->claims );
		$this->assertSame( array( 7 ), $this->finished );
	}

	public function test_a_rule_deactivated_mid_run_finishes_it_without_claiming(): void {
		$this->store_rule( array( 'is_active' => '0' ) );
		$this->candidates = array( $this->candidate( 1 ) );

		Runner::process( 7, '2026-10-10', 0 );

		$this->assertSame( 0, $this->source_reads, 'No recipient is resolved.' );
		$this->assertSame( array(), $this->claims );
		$this->assertSame( array( 7 ), $this->finished );
		$this->assertSame( array(), $this->scheduled, 'No later batch is queued.' );
	}

	public function test_start_refuses_an_inactive_rule(): void {
		$result = Runner::start( $this->rule( array( 'is_active' => '0' ) ), new \DateTimeImmutable( '2026-10-06' ), new \DateTimeImmutable( '2026-10-06' ), 'manual', 1 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_date_messages_inactive', $result->get_error_code() );
		$this->assertSame( array(), $this->inserts, 'No run is opened.' );
	}

	public function test_disabled_emails_stop_the_run_before_any_claim(): void {
		$this->one_sendable_candidate();
		$this->settings = array( 'disable_all_emails' => 1 );

		Runner::process( 7, '2026-10-10', 0 );

		$this->assertSame( array(), $this->claims );
		$this->assertSame( array(), $this->mailed );
		$this->assertSame( array( 7 ), $this->finished );
	}

	/**
	 * @dataProvider bad_ranges
	 *
	 * @param string $from From.
	 * @param string $to   To.
	 */
	public function test_start_refuses_a_range_that_is_reversed_or_too_wide( string $from, string $to ): void {
		$result = Runner::start( $this->rule(), new \DateTimeImmutable( $from ), new \DateTimeImmutable( $to ), 'manual', 1 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_date_messages_range', $result->get_error_code() );
		$this->assertSame( array(), $this->inserts, 'No run is opened.' );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function bad_ranges(): array {
		return array(
			'reversed' => array( '2026-10-10', '2026-10-09' ),
			'32 days'  => array( '2026-10-01', '2026-11-01' ),
		);
	}

	public function test_the_daily_job_targets_only_the_date_the_offset_points_at(): void {
		$this->active = array(
			$this->rule_row( array( 'offset_days' => '-7' ) ),
			$this->rule_row( array( 'id' => '4' ) ),
			$this->rule_row(
				array(
					'id'           => '5',
					'send_to_user' => '0',
				)
			),
		);
		// The run INSERT fails, so each start stops before processing.
		$this->insert_ok = false;

		$today = Runner::today()->format( 'Y-m-d' );
		Runner::run_daily();

		$runs = array();
		foreach ( $this->inserts as $data ) {
			$runs[ $data['rule_id'] ] = array( $data['trigger_kind'], $data['target_from'], $data['target_to'] );
		}
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
		$this->assertSame( 1, count( array_keys( $this->events, 'purge', true ) ), 'The history is pruned once.' );
	}

	public function test_the_daily_job_sends_nothing_while_emails_are_disabled_but_still_prunes(): void {
		$this->settings = array( 'disable_all_emails' => 1 );
		// An active rule that would open a run if the job reached it.
		$this->active = array( $this->rule_row() );

		Runner::run_daily();

		$this->assertSame( array(), $this->inserts, 'No active rule is started.' );
		// The kill-switch stops sending, not the one-year history (#1647).
		$this->assertSame( array( 'purge' ), $this->events );
	}

	public function test_start_schedules_the_digest_before_the_first_batch(): void {
		$rule      = $this->rule(
			array(
				'digest_enabled'  => '1',
				'digest_user_ids' => '[9]',
			)
		);
		$this->run = null;

		$result = Runner::start( $rule, new \DateTimeImmutable( '2026-10-01' ), new \DateTimeImmutable( '2026-10-02' ), 'manual', 5 );

		$this->assertSame( 7, $result );
		$this->assertCount( 1, $this->inserts );
		$this->assertSame( 3, $this->inserts[0]['rule_id'] );
		$this->assertSame( 'manual', $this->inserts[0]['trigger_kind'] );
		$this->assertSame( '2026-10-01', $this->inserts[0]['target_from'] );
		$this->assertSame( '2026-10-02', $this->inserts[0]['target_to'] );
		$this->assertSame( 5, $this->inserts[0]['created_by'] );
		$this->assertSame( array( 'start_run', 'schedule:' . Digest::HOOK, 'get_run' ), $this->events );
	}
}
