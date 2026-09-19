<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Maintenance\IdentityConflictQuery;

/**
 * The cross-store identity questions (#1313 PR 10, #1333).
 *
 * These assert the SHAPE of the statement, which is what a mocked `$wpdb` can
 * show. That the server then groups and filters as described is MySQL's
 * contract — the "presence, never truth" limit this project records for every
 * static guard. What a real database proves lives in the `fresh-install` job.
 *
 * @covers \FreeFormCertificate\Maintenance\IdentityConflictQuery
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class IdentityConflictQueryTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		class_exists( '\\FreeFormCertificate\\Maintenance\\IdentityConflictQuery' );

		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' )->makePartial();
		$wpdb->prefix = 'wp_';
		$this->wpdb   = $wpdb;

		// `prepare()` substitutes its placeholders, because the schema probe
		// compares the RESULT against the table name — a mock that echoed the
		// raw SQL would leave `%s` there, resolve no store, and every
		// assertion below would pass over an empty scan.
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( $query, ...$args ) {
				$values = ( 1 === count( $args ) && is_array( $args[0] ) ) ? $args[0] : $args;
				return (string) preg_replace_callback(
					'/%[sid]/',
					function () use ( &$values ) {
						return (string) array_shift( $values );
					},
					(string) $query
				);
			}
		);

		// Every `SHOW TABLES LIKE <name>` answers with that name, so all four
		// stores resolve.
		$this->wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( $query ) {
				return preg_match( '/LIKE (\S+)/', (string) $query, $m ) ? $m[1] : null;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Capture the statements a call issues.
	 *
	 * @param callable $run Invoked with the query object.
	 * @return array<int, string>
	 */
	private function statements( callable $run ): array {
		$seen = array();
		$this->wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $query ) use ( &$seen ) {
				$seen[] = (string) $query;
				return array();
			}
		);

		$run( new IdentityConflictQuery() );

		return $seen;
	}

	/**
	 * The check counts only what the backfill could have resolved.
	 *
	 * Without this the two checks overlap by construction: the backfill leaves
	 * a column empty when the user carries more than one distinct hash, on
	 * purpose, and every such account was then ALSO reported here — so the
	 * check could never reach zero and read as a failure to an operator who had
	 * just run the backfill to completion. It did, on the first production run
	 * (#1333).
	 */
	public function test_unindexed_links_only_counts_a_single_resolvable_hash(): void {
		$sql = $this->statements(
			function ( IdentityConflictQuery $q ) {
				$q->unindexed_links( 10 );
			}
		);

		$this->assertNotEmpty( $sql, 'The scan must issue a statement; an empty result here would prove nothing.' );

		foreach ( $sql as $statement ) {
			$this->assertStringContainsString(
				'HAVING COUNT(DISTINCT p.h) = 1',
				$statement,
				'An account the backfill declined to resolve is a conflict, and One account, two identifiers already reports it.'
			);
		}
	}

	/**
	 * A finding says which stores it was found in.
	 *
	 * "This account has two CPFs" leaves an operator to search four screens by
	 * hand, which is the same "a lead you cannot act on is not a lead" that
	 * made the export necessary.
	 */
	public function test_every_finding_carries_the_stores_it_came_from(): void {
		$sql = $this->statements(
			function ( IdentityConflictQuery $q ) {
				$q->multiple_identities( 10 );
				$q->shared_identities( 10 );
				$q->unindexed_links( 10 );
			}
		);

		$this->assertNotEmpty( $sql );

		foreach ( $sql as $statement ) {
			$this->assertStringContainsString( 'AS src', $statement, 'The union has to carry the store with each pair.' );
			$this->assertStringContainsString(
				'GROUP_CONCAT(DISTINCT p.src',
				$statement,
				'…and the finding has to report it.'
			);
		}
	}

	/**
	 * A finding names the values its own count covers.
	 *
	 * `grouped()` aliases whatever it grouped BY as `subject` and aggregates
	 * the other half away, so each direction used to destroy exactly what the
	 * other half needs: an identifier belonging to two accounts without naming
	 * them, an account holding two identifiers without naming those. Neither
	 * is a lead anybody can act on, and neither remediation can start from one
	 * (#1344).
	 */
	public function test_every_finding_names_the_values_its_count_covers(): void {
		$seen = $this->statements(
			function ( IdentityConflictQuery $q ) {
				$q->shared_identities( 10 );
				$q->multiple_identities( 10 );
			}
		);

		$this->assertNotEmpty( $seen, 'An empty capture here would pass over the whole assertion.' );

		$shared   = array_slice( $seen, 0, 2 );
		$multiple = array_slice( $seen, 2, 2 );

		$this->assertCount( 2, $shared, 'One statement per identifier column.' );
		$this->assertCount( 2, $multiple );

		foreach ( $shared as $statement ) {
			$this->assertStringContainsString(
				'GROUP_CONCAT(DISTINCT user_id',
				$statement,
				'A check grouped by hash has to name the ACCOUNTS it counted.'
			);
			$this->assertStringContainsString( 'AS ' . IdentityConflictQuery::COLUMN_RELATED, $statement );
		}

		foreach ( $multiple as $statement ) {
			$this->assertStringContainsString(
				'GROUP_CONCAT(DISTINCT h',
				$statement,
				'A check grouped by account has to name the IDENTIFIERS it counted.'
			);
			$this->assertStringContainsString( 'AS ' . IdentityConflictQuery::COLUMN_RELATED, $statement );
		}
	}

	/**
	 * `unindexed_links()` is deliberately untouched.
	 *
	 * It names one account and one column and has no counterpart to emit, so
	 * a list there would be a column that is always empty — the shape #1344
	 * exists to remove, reintroduced by symmetry.
	 */
	public function test_the_unindexed_check_gains_no_list(): void {
		$seen = $this->statements(
			function ( IdentityConflictQuery $q ) {
				$q->unindexed_links( 10 );
			}
		);

		$this->assertNotEmpty( $seen );

		foreach ( $seen as $statement ) {
			$this->assertStringNotContainsString( 'AS ' . IdentityConflictQuery::COLUMN_RELATED, $statement );
		}
	}

	/**
	 * A list shorter than its own count is declared rather than printed.
	 *
	 * `GROUP_CONCAT` stops at `group_concat_max_len` — 1024 bytes by default —
	 * and drops the tail with NO error, so a short list reads exactly like a
	 * complete one. At 65 bytes per hash that is 15 identifiers and production
	 * already holds an account with 11, so the headroom is four. The count is
	 * aggregated separately and is never truncated, which is what makes the
	 * comparison possible at all.
	 *
	 * The flag is asserted on a key the fixture does NOT supply — the shape
	 * `AssertionCoverageTest` exists for, and the one this class's own alias
	 * defect slipped through.
	 */
	public function test_a_short_list_is_flagged_against_its_own_count(): void {
		$this->wpdb->shouldReceive( 'get_results' )->andReturn(
			array(
				array(
					'subject'                                 => 438,
					IdentityConflictQuery::ALIAS_IDENTITY_COUNT => 11,
					IdentityConflictQuery::COLUMN_RELATED     => 'aaaa|bbbb',
					'identifier_column'                       => 'rf_hash',
				),
			)
		);

		$rows = ( new IdentityConflictQuery() )->multiple_identities( 10 );

		$this->assertNotEmpty( $rows );
		$this->assertTrue(
			$rows[0][ IdentityConflictQuery::COLUMN_RELATED_TRUNCATED ],
			'Two values under a count of eleven is a truncated list, and it must never read as the whole one.'
		);
	}

	/**
	 * …and a complete list is not flagged, or the signal means nothing.
	 */
	public function test_a_complete_list_is_not_flagged(): void {
		$this->wpdb->shouldReceive( 'get_results' )->andReturn(
			array(
				array(
					'subject'                             => 'abcd',
					IdentityConflictQuery::ALIAS_USER_COUNT => 2,
					IdentityConflictQuery::COLUMN_RELATED => '85|107',
					'identifier_column'                   => 'cpf_hash',
				),
			)
		);

		$rows = ( new IdentityConflictQuery() )->shared_identities( 10 );

		$this->assertNotEmpty( $rows );
		$this->assertFalse( $rows[0][ IdentityConflictQuery::COLUMN_RELATED_TRUNCATED ] );
	}

	/**
	 * CPF and RF are separate universes.
	 *
	 * One of each is an ordinary person. The class runs one statement per
	 * column rather than counting across them, so that case can never be
	 * reported — the question a maintainer asked of this audit, verified here
	 * rather than answered from memory.
	 */
	public function test_each_identifier_column_is_asked_about_separately(): void {
		$sql = $this->statements(
			function ( IdentityConflictQuery $q ) {
				$q->multiple_identities( 10 );
			}
		);

		$this->assertCount( 2, $sql, 'One statement for cpf_hash and one for rf_hash, never one mixing them.' );

		// Each statement asks about exactly ONE column. A single statement
		// naming both would be counting across them, which is what would make
		// "one CPF and one RF" look like a conflict.
		foreach ( $sql as $statement ) {
			$mentions = array_filter(
				array( 'cpf_hash', 'rf_hash' ),
				static function ( $column ) use ( $statement ) {
					return false !== strpos( $statement, $column );
				}
			);

			$this->assertCount(
				1,
				$mentions,
				'A statement naming both columns would count across them, and one of each is an ordinary person.'
			);
		}
	}

	/**
	 * The count column is named by a constant, because a consumer reads it by
	 * name and a name nobody emits produces an empty column with nothing to say
	 * it is empty. That shipped (#1333).
	 */
	public function test_the_count_aliases_are_the_published_constants(): void {
		// Both calls go through ONE `statements()` invocation: a second one adds
		// a second `get_results` expectation while the first is still live, so
		// its capture array comes back empty and the assertion would pass over
		// nothing.
		$sql = implode(
			"\n",
			$this->statements(
				function ( IdentityConflictQuery $q ) {
					$q->multiple_identities( 10 );
					$q->shared_identities( 10 );
				}
			)
		);

		$this->assertStringContainsString( IdentityConflictQuery::ALIAS_IDENTITY_COUNT, $sql );
		$this->assertStringContainsString( IdentityConflictQuery::ALIAS_USER_COUNT, $sql );
	}
}
