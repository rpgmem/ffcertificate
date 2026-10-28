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
 * The delivery log, the rule reader, the date formatter and the scheduling
 * mailer are the real classes. The log and the rules live in a `$wpdb`
 * double that answers the run, the rule and the recipient-name reads and
 * records every update; the digest goes out through the plugin's e-mail path
 * to a stubbed `wp_mail()`, whose bodies the tests read.
 *
 * @covers \FreeFormCertificate\DateMessages\Digest
 */
class DateMessagesDigestTest extends TestCase {

	use MockeryPHPUnitIntegration;

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

	/** @var int How many times `wp_mail()` was called. */
	private int $mail_calls = 0;

	/** @var array<string, mixed>|null The stored run 7, or null when there is none. */
	private ?array $run = null;

	/** @var array<string, mixed>|null The stored rule 3, or null when it was deleted. */
	private ?array $rule_row = null;

	/** @var int How many times the rules table was read. */
	private int $rule_reads = 0;

	/**
	 * Every `$wpdb->update()`, as `[ table, data, where ]`.
	 *
	 * @var list<array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}>
	 */
	private array $updates = array();

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;

		// The detailed digest links to the screen where names are revealed (#1863).
		Functions\when( 'admin_url' )->alias( static fn( $path = '' ) => 'https://example.com/wp-admin/' . $path );

		$this->sent       = array();
		$this->mail_calls = 0;
		$this->run        = null;
		$this->rule_row   = null;
		$this->rule_reads = 0;
		$this->updates    = array();
		$this->users      = array(
			1 => array( 'admin@example.org', array( 'manage_options' ) ),
			2 => array( 'viewer@example.org', array( 'ffc_view_date_messages' ) ),
			3 => array( 'nobody@example.org', array() ),
			4 => array( 'not-an-address', array( 'manage_options' ) ),
		);

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $v ) => trim( strip_tags( (string) $v ) ) );
		Functions\when( 'esc_html_e' )->alias( static function ( $t ) { echo $t; } );
		Functions\when( 'number_format_i18n' )->alias( static fn( $n ) => (string) $n );
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => trim( (string) $v ) );
		Functions\when( 'get_bloginfo' )->justReturn( 'Site' );
		Functions\when( 'home_url' )->alias( static fn( $path = '' ) => 'https://example.com/' . ltrim( (string) $path, '/' ) );
		Functions\when( 'is_email' )->alias( static fn( $v ) => false !== filter_var( $v, FILTER_VALIDATE_EMAIL ) );
		// Emails enabled, debug off, default date format: an install nobody configured.
		Functions\when( 'get_option' )->alias( static fn( $key, $default = false ) => $default );
		Functions\when( 'wp_date' )->alias( static fn( $format, $ts ) => gmdate( (string) $format, (int) $ts ) );
		Functions\when( 'wp_timezone' )->alias( static fn() => new \DateTimeZone( 'UTC' ) );
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
		Functions\when( 'wp_mail' )->alias(
			function ( $to, $subject, $body ) {
				++$this->mail_calls;
				$this->sent[ $to ] = $body;
				return true;
			}
		);

		$GLOBALS['wpdb'] = $this->database();
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
	 * A database holding `$this->run` as run 7 and `$this->rule_row` as rule
	 * 3, whose run delivered to Ana and Bruno. Any other statement fails.
	 *
	 * @return Mockery\MockInterface
	 */
	private function database() {
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->users  = 'wp_users';

		// Interpolates, so the reads below can dispatch on the statement.
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( $sql, ...$args ) {
				$values = ( 1 === count( $args ) && is_array( $args[0] ) ) ? $args[0] : $args;
				foreach ( $values as $v ) {
					$sql = (string) preg_replace( '/%[ids]/', (string) $v, (string) $sql, 1 );
				}
				return $sql;
			}
		);

		$wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function ( $sql ) {
				if ( 1 === preg_match( '/^SELECT \* FROM wp_ffc_date_message_runs WHERE id = (\d+)$/', (string) $sql, $m ) ) {
					return 7 === (int) $m[1] ? $this->run : null;
				}
				if ( 1 === preg_match( '/^SELECT \* FROM wp_ffc_date_message_rules WHERE id = (\d+)$/', (string) $sql, $m ) ) {
					++$this->rule_reads;
					return 3 === (int) $m[1] ? $this->rule_row : null;
				}
				$this->fail( 'Unexpected get_row: ' . $sql );
			}
		);

		$wpdb->shouldReceive( 'get_col' )->andReturnUsing(
			function ( $sql ) {
				if ( str_contains( (string) $sql, 'FROM wp_ffc_date_message_log l INNER JOIN wp_users u' ) && str_contains( (string) $sql, 'l.run_id = 7 AND l.channel = user' ) ) {
					return array( 'Ana', 'Bruno' );
				}
				$this->fail( 'Unexpected get_col: ' . $sql );
			}
		);

		$wpdb->shouldReceive( 'update' )->andReturnUsing(
			function ( $table, $data, $where ) {
				$this->updates[] = array( $table, $data, $where );
				return 1;
			}
		);

		return $wpdb;
	}

	/**
	 * Store run 7 and rule 3.
	 *
	 * @param array<string, mixed>|null $run  The run row, or null for none.
	 * @param array<string, mixed>|null $rule Rule overrides, or null for a deleted rule.
	 */
	private function store( ?array $run, ?array $rule = array() ): void {
		$this->run      = $run;
		if ( null === $rule ) {
			$this->rule_row = null;
			return;
		}
		// As stored: the id list is a JSON column.
		$row                    = $this->rule_data( $rule );
		$row['digest_user_ids'] = (string) json_encode( $row['digest_user_ids'] );
		$this->rule_row         = $row;
	}

	/**
	 * Run ids marked as reported.
	 *
	 * @return list<int>
	 */
	private function marked(): array {
		$out = array();
		foreach ( $this->updates as $update ) {
			$this->assertSame( 'wp_ffc_date_message_runs', $update[0] );
			$this->assertArrayHasKey( 'digest_sent_at', $update[1] );
			$out[] = (int) $update[2]['id'];
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $over Overrides.
	 * @return array<string, mixed>
	 */
	private function rule_data( array $over = array() ): array {
		return array_merge(
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
		);
	}

	/**
	 * @param array<string, mixed> $over Overrides.
	 */
	private function rule( array $over = array() ): Rule {
		$rule = Rule::from_array( $this->rule_data( $over ) );
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
		$this->store( $this->run_row() );

		Digest::send( 7 );

		$this->assertSame( array( 'admin@example.org', 'viewer@example.org' ), array_keys( $this->sent ), 'No capability, a bad address and a deleted account receive nothing.' );
		$this->assertStringContainsString( 'Birthdays', $this->sent['admin@example.org'] );
		$this->assertStringNotContainsString( 'Ana', $this->sent['admin@example.org'], 'The summary mode lists no names.' );
		$this->assertSame( array( 7 ), $this->marked() );
	}

	public function test_forced_sends_and_the_announcement_trigger_are_named(): void {
		$this->store( $this->run_row( array( 'trigger_kind' => 'announcement', 'forced' => '2' ) ) );

		Digest::send( 7 );

		$body = $this->sent['admin@example.org'];
		$this->assertStringContainsString( 'Summary of the announcement', $body );
		$this->assertMatchesRegularExpression( '#Sent despite an opt-out \\(included in Sent\\)</td>\\s*<td[^>]*><strong>2</strong>#', $body );
		$this->assertSame( array( 7 ), $this->marked() );
	}

	public function test_a_run_that_forced_nothing_shows_no_forced_row(): void {
		$this->store( $this->run_row() );

		Digest::send( 7 );

		$this->assertStringContainsString( 'Summary of the daily run', $this->sent['admin@example.org'] );
		$this->assertStringNotContainsString( 'despite an opt-out', $this->sent['admin@example.org'] );
		$this->assertSame( array( 7 ), $this->marked() );
	}

	public function test_detailed_names_reach_only_the_managers_allowed_to_see_them(): void {
		$this->store( $this->run_row(), array( 'digest_mode' => 'detailed' ) );

		Digest::send( 7 );

		// Masked even for a manager who may see names (#1863), with a link
		// to the screen where a name is revealed.
		$this->assertStringContainsString( '<li>A**</li>', $this->sent['admin@example.org'] );
		$this->assertStringNotContainsString( '<li>Ana</li>', $this->sent['admin@example.org'] );
		$this->assertStringContainsString( 'https://example.com/wp-admin/admin.php?page=ffc-date-messages&tab=upcoming', $this->sent['admin@example.org'] );
		$this->assertStringNotContainsString( 'A**', $this->sent['viewer@example.org'], 'A viewer without the PII capability gets the counts only.' );
		$this->assertSame( array( 7 ), $this->marked() );
	}

	public function test_a_run_already_reported_sends_nothing(): void {
		$this->store( $this->run_row( array( 'digest_sent_at' => '1700000000' ) ) );

		Digest::send( 7 );

		$this->assertSame( 0, $this->rule_reads, 'The rule is not even read.' );
		$this->assertSame( 0, $this->mail_calls );
		$this->assertSame( array(), $this->marked() );
	}

	public function test_a_run_that_reached_nobody_is_closed_without_a_digest(): void {
		$this->store(
			$this->run_row(
				array(
					'sent'            => '0',
					'opted_out'       => '0',
					'out_of_audience' => '0',
				)
			)
		);

		Digest::send( 7 );

		$this->assertSame( 0, $this->mail_calls );
		$this->assertSame( array( 7 ), $this->marked() );
	}

	public function test_a_deleted_rule_or_a_digest_switched_off_sends_nothing(): void {
		$this->store( $this->run_row(), null );
		Digest::send( 7 );

		$this->store( $this->run_row(), array( 'digest_enabled' => '0' ) );
		Digest::send( 7 );

		$this->assertSame( 2, $this->rule_reads );
		$this->assertSame( 0, $this->mail_calls );
		$this->assertSame( array(), $this->marked() );
	}

	public function test_an_unknown_run_sends_nothing(): void {
		$this->store( $this->run_row() );

		Digest::send( 99 );

		$this->assertSame( 0, $this->rule_reads );
		$this->assertSame( 0, $this->mail_calls );
		$this->assertSame( array(), $this->marked() );
	}
}
