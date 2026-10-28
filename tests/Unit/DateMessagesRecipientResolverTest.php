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
 * OptOut, DeliveryLog and AudienceReader are the real ones: they read the
 * profile preferences, the delivery log and the audience tree through the
 * `$wpdb` double below, which answers each query from the arrays the test
 * fills in.
 *
 * @covers \FreeFormCertificate\DateMessages\RecipientResolver
 */
class DateMessagesRecipientResolverTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $source;

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	/** @var array<int, mixed> Arguments of the last prepare(). */
	private array $bound = array();

	/** @var array<int, array<string, bool>> Stored profile preferences by user id. */
	private array $preferences = array();

	/** @var list<array{0: int, 1: string, 2: string, 3: int}> Log rows: rule, occurrence, channel, user. */
	private array $delivered = array();

	/** @var array<int, int> Audience id => parent id. */
	private array $parents = array();

	/** @var array<int, list<int>> Audience id => member user ids. */
	private array $memberships = array();

	/** @var list<int> Audience each members query was asked for, in order. */
	private array $member_queries = array();

	/** @var list<list<int>> User ids each opt-out query bound, in order. */
	private array $opt_out_queries = array();

	/** @var list<list<int>> User ids each delivery-log query bound, in order. */
	private array $log_queries = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => trim( (string) $v ) );
		Functions\when( 'is_email' )->alias( static fn( $v ) => false !== filter_var( $v, FILTER_VALIDATE_EMAIL ) );
		Functions\when( 'wp_parse_args' )->alias( static fn( $args, $defaults ) => array_merge( $defaults, $args ) );
		Functions\when( 'sanitize_sql_orderby' )->returnArg();
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );

		$this->source = Mockery::mock( 'FreeFormCertificate\DateMessages\DateSourceInterface' );

		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( $sql, ...$args ) {
				$this->bound = is_array( $args[0] ?? null ) ? array_values( $args[0] ) : $args;
				return $sql;
			}
		);
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing( fn( $sql ) => $this->answer_results( (string) $sql ) );
		$wpdb->shouldReceive( 'get_col' )->andReturnUsing( fn( $sql ) => $this->answer_col( (string) $sql ) );

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']   = $wpdb;
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
	 * Answer a get_results(): the profile preferences OptOut reads, or the
	 * children of an audience AudienceReader walks.
	 *
	 * @param string $sql Prepared SQL (the double returns it unchanged).
	 * @return array<int, mixed>
	 */
	private function answer_results( string $sql ): array {
		if ( str_contains( $sql, 'preferences' ) ) {
			$ids                     = array_map( 'intval', array_slice( $this->bound, 1 ) );
			$this->opt_out_queries[] = $ids;
			$rows                    = array();
			foreach ( $ids as $id ) {
				if ( isset( $this->preferences[ $id ] ) ) {
					$rows[] = array(
						'user_id'     => (string) $id,
						'preferences' => (string) json_encode( $this->preferences[ $id ] ),
					);
				}
			}
			return $rows;
		}
		if ( str_contains( $sql, 'parent_id = %d' ) ) {
			$parent = (int) $this->bound[1];
			$rows   = array();
			foreach ( $this->parents as $id => $of ) {
				if ( $of === $parent ) {
					$rows[] = (object) array(
						'id'        => (string) $id,
						'parent_id' => (string) $of,
					);
				}
			}
			return $rows;
		}
		$this->fail( 'Unexpected get_results(): ' . $sql );
	}

	/**
	 * Answer a get_col(): the members of an audience tree, or the deliveries
	 * the log already holds.
	 *
	 * @param string $sql Prepared SQL (the double returns it unchanged).
	 * @return array<int, string>
	 */
	private function answer_col( string $sql ): array {
		if ( str_contains( $sql, 'DISTINCT user_id' ) ) {
			$audiences              = array_map( 'intval', array_slice( $this->bound, 1 ) );
			$this->member_queries[] = $audiences[0];
			$users                  = array();
			foreach ( $audiences as $audience ) {
				foreach ( $this->memberships[ $audience ] ?? array() as $user ) {
					$users[ $user ] = (string) $user;
				}
			}
			return array_values( $users );
		}
		if ( str_contains( $sql, 'occurrence_key' ) ) {
			list( , $rule, $occurrence, $channel ) = $this->bound;
			$ids                                     = array_map( 'intval', array_slice( $this->bound, 4 ) );
			$this->log_queries[]                     = $ids;
			$found                                   = array();
			foreach ( $this->delivered as $row ) {
				if ( (int) $rule === $row[0] && $occurrence === $row[1] && $channel === $row[2] && in_array( $row[3], $ids, true ) ) {
					$found[] = (string) $row[3];
				}
			}
			return $found;
		}
		$this->fail( 'Unexpected get_col(): ' . $sql );
	}

	/**
	 * How many times the members of an audience were read.
	 *
	 * @param int $audience_id Audience.
	 * @return int
	 */
	private function member_reads( int $audience_id ): int {
		return count( array_keys( $this->member_queries, $audience_id, true ) );
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

	public function test_an_announcement_that_ignores_the_opt_out_sends_to_them_and_marks_it_forced(): void {
		$target = new \DateTimeImmutable( '2026-10-10' );
		$this->source->shouldReceive( 'due' )->andReturn(
			array(
				$this->candidate( 1 ),
				$this->candidate( 2 ),
				$this->candidate( 3, 'not-an-address' ),
			)
		);
		// The announcement category is the one checked, never the personal one:
		// 1 and 3 turned announcements off, 2 turned only birthdays off.
		$this->preferences = array(
			1 => array( 'notify_date_announcements' => false ),
			2 => array( 'notify_date_messages' => false ),
			3 => array( 'notify_date_announcements' => false ),
		);

		$rule = $this->rule(
			array(
				'kind'            => 'announcement',
				'source'          => 'announcement',
				'respect_opt_out' => '0',
			)
		);
		$page = ( new RecipientResolver( $this->source ) )->resolve( $rule, $target, 0, 10 );

		$this->assertSame( array( array( 1, 2, 3 ) ), $this->opt_out_queries, 'One opt-out read over the page.' );
		$this->assertSame( RecipientResolver::WILL_SEND, $page['rows'][0]['decision'] );
		$this->assertTrue( $page['rows'][0]['forced'], 'Reached despite the opt-out, so it is labelled.' );
		$this->assertSame( RecipientResolver::WILL_SEND, $page['rows'][1]['decision'] );
		$this->assertFalse( $page['rows'][1]['forced'], 'Nobody opted out of user 2, so nothing was forced.' );
		$this->assertSame( RecipientResolver::NO_EMAIL, $page['rows'][2]['decision'], 'Forcing does not skip a missing address.' );
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
		// 5 is opted out AND has no address: opting out wins. 1 turned only
		// announcements off, which a dated rule does not read.
		$this->preferences = array(
			1 => array( 'notify_date_announcements' => false ),
			2 => array( 'notify_date_messages' => false ),
			5 => array( 'notify_date_messages' => false ),
		);
		// Only rule 3 on 2026-10-10 counts; the other rows are another rule and another day.
		$this->delivered   = array(
			array( 3, '2026-10-10', 'user', 4 ),
			array( 8, '2026-10-10', 'user', 1 ),
			array( 3, '2026-10-09', 'user', 1 ),
		);
		$this->memberships = array( 9 => array( 1, 2, 3, 4, 5 ) );

		$page = ( new RecipientResolver( $this->source ) )->resolve( $this->rule( array( 'audience_ids' => array( 9 ) ) ), $target, 0, 10 );

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
		$this->assertSame( array( array( 1, 2, 3, 4, 5, 6 ) ), $this->log_queries, 'The log is read once, over every candidate.' );
		$this->assertSame( 6, $page['cursor'] );
		$this->assertTrue( $page['complete'], 'Six candidates under a page of ten is the last page.' );
	}

	public function test_a_full_page_is_not_complete_and_no_audience_means_everyone(): void {
		$this->source->shouldReceive( 'due' )->andReturn( array( $this->candidate( 11 ), $this->candidate( 12 ) ) );

		$page = ( new RecipientResolver( $this->source ) )->resolve( $this->rule(), new \DateTimeImmutable( '2026-10-10' ), 10, 2 );

		$this->assertSame( array( RecipientResolver::WILL_SEND, RecipientResolver::WILL_SEND ), array_column( $page['rows'], 'decision' ) );
		$this->assertSame( 12, $page['cursor'] );
		$this->assertFalse( $page['complete'] );
		$this->assertSame( array(), $this->member_queries, 'No audience on the rule, so no membership is read.' );
	}

	public function test_an_empty_audience_excludes_everyone_rather_than_widening(): void {
		$this->source->shouldReceive( 'due' )->andReturn( array( $this->candidate( 1 ) ) );
		$this->memberships = array( 9 => array() );

		$page = ( new RecipientResolver( $this->source ) )->resolve( $this->rule( array( 'audience_ids' => array( 9 ) ) ), new \DateTimeImmutable( '2026-10-10' ), 0, 10 );

		$this->assertSame( RecipientResolver::OUT_OF_AUDIENCE, $page['rows'][0]['decision'] );
	}

	public function test_belonging_to_any_of_the_audiences_is_enough(): void {
		// #1648: a rule may name several audiences; the person qualifies
		// through any of them, never through all.
		$this->source->shouldReceive( 'due' )->andReturn( array( $this->candidate( 1 ), $this->candidate( 2 ), $this->candidate( 3 ) ) );
		$this->memberships = array(
			7 => array( 1 ),
			9 => array( 2 ),
		);

		$page = ( new RecipientResolver( $this->source ) )->resolve( $this->rule( array( 'audience_ids' => array( 7, 9 ) ) ), new \DateTimeImmutable( '2026-10-10' ), 0, 10 );

		$this->assertSame(
			array( RecipientResolver::WILL_SEND, RecipientResolver::WILL_SEND, RecipientResolver::OUT_OF_AUDIENCE ),
			array_column( $page['rows'], 'decision' )
		);
		$this->assertSame( 1, $this->member_reads( 7 ), 'Each audience is read once.' );
		$this->assertSame( 1, $this->member_reads( 9 ), 'Each audience is read once.' );
	}

	/**
	 * An audience with nobody in it has no members, so an announcement to it is
	 * not run (#1949).
	 *
	 * @return void
	 */
	public function test_an_audience_with_nobody_in_it_has_no_members(): void {
		$this->memberships = array( 9 => array() );
		$this->assertFalse( ( new RecipientResolver( $this->source ) )->audience_has_members( array( 9 ) ) );
	}

	public function test_an_audience_with_a_member_has_members(): void {
		$this->memberships = array( 9 => array( 1 ) );
		$this->assertTrue( ( new RecipientResolver( $this->source ) )->audience_has_members( array( 9 ) ) );
	}

	public function test_a_member_of_a_sub_audience_counts(): void {
		// Sub-audiences count: get_members() is asked to include the children.
		$this->parents     = array( 10 => 9 );
		$this->memberships = array( 10 => array( 1 ) );
		$this->assertTrue( ( new RecipientResolver( $this->source ) )->audience_has_members( array( 9 ) ) );
	}

	/**
	 * An announcement to an audience pages over the members, never over the
	 * site (#1996): the page is the ids, so an id whose account is gone moves
	 * the cursor and does not end the run early.
	 */
	public function test_an_audience_announcement_pages_over_the_members_not_the_site(): void {
		$target = new \DateTimeImmutable( '2026-10-22' );
		$source = Mockery::mock( 'FreeFormCertificate\DateMessages\MemberScopedSourceInterface' );
		$source->shouldNotReceive( 'due' );
		$source->shouldReceive( 'due_among' )->once()->with( $target, array( 7, 50 ) )->andReturn( array( $this->candidate( 7 ), $this->candidate( 50 ) ) );
		// 900 was deleted: the second page asks for it and gets nothing back.
		$source->shouldReceive( 'due_among' )->once()->with( $target, array( 900 ) )->andReturn( array() );
		// Two audiences sharing member 50, listed out of order.
		$this->memberships = array(
			4 => array( 900, 50 ),
			5 => array( 50, 7 ),
		);

		$rule     = $this->rule(
			array(
				'kind'         => 'announcement',
				'source'       => 'announcement',
				'audience_ids' => array( 4, 5 ),
			)
		);
		$resolver = new RecipientResolver( $source );

		$first = $resolver->resolve( $rule, $target, 0, 2 );
		$this->assertSame( array( 7, 50 ), array_column( $first['rows'], 'user_id' ) );
		$this->assertSame( array( RecipientResolver::WILL_SEND, RecipientResolver::WILL_SEND ), array_column( $first['rows'], 'decision' ) );
		$this->assertSame( 50, $first['cursor'] );
		$this->assertFalse( $first['complete'], 'A full page of ids may have more after it.' );

		$second = $resolver->resolve( $rule, $target, $first['cursor'], 2 );
		$this->assertSame( array(), $second['rows'] );
		$this->assertSame( 900, $second['cursor'], 'The missing account still moves the cursor past it.' );
		$this->assertTrue( $second['complete'] );
	}

	public function test_an_announcement_to_everyone_still_pages_every_account(): void {
		$target = new \DateTimeImmutable( '2026-10-22' );
		$source = Mockery::mock( 'FreeFormCertificate\DateMessages\MemberScopedSourceInterface' );
		$source->shouldNotReceive( 'due_among' );
		$source->shouldReceive( 'due' )->once()->with( $target, 0, 2 )->andReturn( array( $this->candidate( 1 ), $this->candidate( 2 ) ) );

		$page = ( new RecipientResolver( $source ) )->resolve(
			$this->rule(
				array(
					'kind'   => 'announcement',
					'source' => 'announcement',
				)
			),
			$target,
			0,
			2
		);

		$this->assertSame( array( 1, 2 ), array_column( $page['rows'], 'user_id' ) );
		$this->assertSame( 2, $page['cursor'] );
		$this->assertFalse( $page['complete'] );
		$this->assertSame( array(), $this->member_queries, 'Everyone means no membership is read.' );
	}

	public function test_an_announcement_to_an_empty_audience_reads_nobody(): void {
		$target = new \DateTimeImmutable( '2026-10-22' );
		$source = Mockery::mock( 'FreeFormCertificate\DateMessages\MemberScopedSourceInterface' );
		$source->shouldNotReceive( 'due' );
		$source->shouldNotReceive( 'due_among' );
		$this->memberships = array( 4 => array() );

		$page = ( new RecipientResolver( $source ) )->resolve(
			$this->rule(
				array(
					'kind'         => 'announcement',
					'source'       => 'announcement',
					'audience_ids' => array( 4 ),
				)
			),
			$target,
			0,
			200
		);

		$this->assertSame( array(), $page['rows'] );
		$this->assertSame( 0, $page['cursor'] );
		$this->assertTrue( $page['complete'], 'Nobody in the audience is never "everybody".' );
		$this->assertSame( array( 4 ), $this->member_queries, 'The audience was read, and found empty.' );
	}
}
