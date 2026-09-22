<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
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

	/**
	 * Tables the `SHOW COLUMNS` probe should report as having no `email_hash`.
	 *
	 * @var array<int, string>
	 */
	private array $without_email = array();

	/**
	 * Stores the ciphertext probe must not resolve.
	 *
	 * The sibling of `$without_email`, and it has a real occupant:
	 * `ffc_user_profiles` declares `cpf_hash` and `rf_hash` with no
	 * `*_encrypted` column at all, because it is the identity index.
	 *
	 * @var array<int, string>
	 */
	private array $without_ciphertext = array();

	/**
	 * Stores whose activity timestamp column the probe must not resolve.
	 *
	 * @var array<int, string>
	 */
	private array $without_activity_column = array();

	/**
	 * Stores the row-id probe must not resolve.
	 *
	 * Its own bucket rather than a share of the activity one: both land in
	 * the same `SHOW COLUMNS` branch, so without the split a test suppressing
	 * an activity column would silently suppress the `id` probe too and the
	 * scan would report an empty store as clean.
	 *
	 * @var array<int, string>
	 */
	private array $without_row_id = array();

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
		// stores resolve, and every `SHOW COLUMNS … LIKE email_hash` answers
		// with the column -- unless a test listed that table in
		// `$without_email`, which is how the address probe's negative side is
		// exercised without a schema.
		$this->wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( $query ) {
				$sql = (string) $query;

				if ( false !== strpos( $sql, 'SHOW COLUMNS' ) ) {
					if ( false !== strpos( $sql, '_encrypted' ) ) {
						$absent = $this->without_ciphertext;
					} elseif ( false !== strpos( $sql, 'email_hash' ) ) {
						$absent = $this->without_email;
					} elseif ( (bool) preg_match( '/LIKE id$/', $sql ) ) {
						$absent = $this->without_row_id;
					} else {
						$absent = $this->without_activity_column;
					}

					foreach ( $absent as $table ) {
						if ( false !== strpos( $sql, $table ) ) {
							return null;
						}
					}
				}

				return preg_match( '/LIKE (\S+)/', $sql, $m ) ? $m[1] : null;
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
	 * Drive `multiple_identities()` with a findings set and an address set.
	 *
	 * One `get_results` expectation dispatching on the statement, because a
	 * second `shouldReceive` while the first is live silently shadows it --
	 * the trap `test_the_count_aliases_are_the_published_constants()` records.
	 * The address statement is the one aliasing the address column `AS e`.
	 *
	 * @param array<int, array<string, mixed>> $findings  Rows for the `rf_hash` grouping.
	 * @param array<int, array<string, mixed>> $addresses Rows for the address union.
	 * @return array<int, array<string, mixed>>
	 */
	private function findings_with_addresses( array $findings, array $addresses ): array {
		$this->wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $query ) use ( $findings, $addresses ) {
				$sql = (string) $query;

				if ( false !== strpos( $sql, 'AS e FROM' ) ) {
					return $addresses;
				}

				// Only the rf_hash pass returns findings, so the cpf_hash pass
				// does not duplicate them under the other column.
				return false !== strpos( $sql, 'rf_hash' ) ? $findings : array();
			}
		);

		return ( new IdentityConflictQuery() )->multiple_identities( 10 );
	}

	/**
	 * One finding for an account holding two RFs.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function two_rf_finding(): array {
		return array(
			array(
				'subject' => 438,
				IdentityConflictQuery::ALIAS_IDENTITY_COUNT => 2,
				IdentityConflictQuery::COLUMN_RELATED => 'hashA|hashB',
				'identifier_column' => 'rf_hash',
			),
		);
	}

	/**
	 * Two identifiers used from one address is one person.
	 *
	 * A person holds one RF, so an account with two is never history. The
	 * hashes cannot say which of the three readings it is -- a hash is
	 * one-way -- but the addresses stored beside them can separate "two
	 * people" from "one person, one bad value", with no key and no
	 * decryption (#1345).
	 */
	public function test_one_address_under_two_identifiers_reads_as_one_person(): void {
		$rows = $this->findings_with_addresses(
			$this->two_rf_finding(),
			array(
				array( 'user_id' => 438, 'h' => 'hashA', 'e' => 'mailX' ),
				array( 'user_id' => 438, 'h' => 'hashB', 'e' => 'mailX' ),
			)
		);

		$this->assertNotEmpty( $rows, 'An empty result would pass over the whole assertion.' );
		$this->assertSame(
			IdentityConflictQuery::VERDICT_SHARED_EMAIL,
			$rows[0][ IdentityConflictQuery::COLUMN_EMAIL_VERDICT ]
		);
	}

	/**
	 * An address each, none shared, is two people under one account.
	 */
	public function test_an_address_each_reads_as_two_people(): void {
		$rows = $this->findings_with_addresses(
			$this->two_rf_finding(),
			array(
				array( 'user_id' => 438, 'h' => 'hashA', 'e' => 'mailX' ),
				array( 'user_id' => 438, 'h' => 'hashB', 'e' => 'mailY' ),
			)
		);

		$this->assertSame(
			IdentityConflictQuery::VERDICT_DISTINCT_EMAILS,
			$rows[0][ IdentityConflictQuery::COLUMN_EMAIL_VERDICT ]
		);
	}

	/**
	 * An identifier with no address anywhere is `unknown`, never `distinct`.
	 *
	 * The distinction that decides whether an operator detaches rows: absence
	 * of an address is not evidence of a second person, and a verdict that
	 * folded it into `distinct_emails` would send somebody to split an account
	 * on nothing at all. `ffc_user_profiles` is the ordinary cause -- it is
	 * the identity index and stores no address.
	 */
	public function test_an_identifier_with_no_address_is_unknown(): void {
		$rows = $this->findings_with_addresses(
			$this->two_rf_finding(),
			array( array( 'user_id' => 438, 'h' => 'hashA', 'e' => 'mailX' ) )
		);

		$this->assertSame(
			IdentityConflictQuery::VERDICT_UNKNOWN,
			$rows[0][ IdentityConflictQuery::COLUMN_EMAIL_VERDICT ]
		);
	}

	/**
	 * A shared address wins over an unshared one on the same account.
	 *
	 * Three identifiers where two were used from one address: the pair is
	 * evidence of one person typing, so the account is not split on the third.
	 */
	public function test_one_shared_address_among_three_still_reads_as_one_person(): void {
		$rows = $this->findings_with_addresses(
			array(
				array(
					'subject' => 5493,
					IdentityConflictQuery::ALIAS_IDENTITY_COUNT => 3,
					'identifier_column' => 'rf_hash',
				),
			),
			array(
				array( 'user_id' => 5493, 'h' => 'hashA', 'e' => 'mailX' ),
				array( 'user_id' => 5493, 'h' => 'hashB', 'e' => 'mailX' ),
				array( 'user_id' => 5493, 'h' => 'hashC', 'e' => 'mailZ' ),
			)
		);

		$this->assertSame(
			IdentityConflictQuery::VERDICT_SHARED_EMAIL,
			$rows[0][ IdentityConflictQuery::COLUMN_EMAIL_VERDICT ]
		);
	}

	/**
	 * The address probe asks the live schema, and a store without the column
	 * is left out of the union rather than named in a statement it cannot
	 * satisfy.
	 *
	 * `ffc_user_profiles` really is that store, so a hard-coded list would be
	 * right today and is a claim about a schema this class does not own --
	 * the shape `CLAUDE.md` records as going stale in silence.
	 */
	public function test_a_store_without_the_address_column_is_not_queried(): void {
		$this->without_email = array( 'wp_ffc_user_profiles' );

		$seen = array();
		$this->wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $query ) use ( &$seen ) {
				$sql = (string) $query;
				if ( false !== strpos( $sql, 'AS e FROM' ) ) {
					$seen[] = $sql;
				}
				return false !== strpos( $sql, 'rf_hash' ) && false === strpos( $sql, 'AS e FROM' )
					? $this->two_rf_finding()
					: array();
			}
		);

		( new IdentityConflictQuery() )->multiple_identities( 10 );

		$this->assertNotEmpty( $seen, 'The address statement never ran; the assertion below would prove nothing.' );

		foreach ( $seen as $statement ) {
			$this->assertStringNotContainsString( 'wp_ffc_user_profiles', $statement );
			$this->assertStringContainsString( 'wp_ffc_submissions', $statement, 'The stores that DO carry an address must still be asked.' );
		}
	}

	/**
	 * The shared check gains no verdict.
	 *
	 * It groups by identifier, so its subject is a hash and there is no single
	 * account whose addresses could be compared -- a verdict column there
	 * would be one that is always empty.
	 */
	public function test_the_shared_check_gains_no_verdict(): void {
		$this->wpdb->shouldReceive( 'get_results' )->andReturn(
			array(
				array(
					'subject' => 'abcd',
					IdentityConflictQuery::ALIAS_USER_COUNT => 2,
					'identifier_column' => 'cpf_hash',
				),
			)
		);

		$rows = ( new IdentityConflictQuery() )->shared_identities( 10 );

		$this->assertNotEmpty( $rows );
		$this->assertArrayNotHasKey( IdentityConflictQuery::COLUMN_EMAIL_VERDICT, $rows[0] );
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
	/**
	 * An account is `missing` until `wp_users` says otherwise.
	 *
	 * The seeded default is the whole safety property: a query that returns
	 * nothing must read as "no account found", never as a blank that a later
	 * reader takes for "fine". An empty result must never read as clean.
	 */
	public function test_account_facts_seed_missing_and_only_wp_users_lifts_it(): void {
		Functions\when( 'get_users' )->justReturn( array( '355' ) );
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( array() );

		$facts = ( new IdentityConflictQuery() )->account_facts( array( 355, 5276 ) );

		$this->assertSame( IdentityConflictQuery::STATUS_EXISTS, $facts[355]['status'] );
		$this->assertSame(
			IdentityConflictQuery::STATUS_MISSING,
			$facts[5276]['status'],
			'An id `wp_users` did not return is missing, not unknown and not blank.'
		);
	}

	/**
	 * The counts are of every row an account owns in a store.
	 *
	 * That is the question they answer -- what a merge would move, or a
	 * deletion destroy -- so they deliberately do NOT narrow to the rows
	 * carrying the identifier the finding is about.
	 */
	public function test_account_facts_report_rows_per_store(): void {
		Functions\when( 'get_users' )->justReturn( array( '355' ) );
		$this->wpdb->shouldReceive( 'get_results' )->andReturn(
			array(
				array( 'user_id' => '355', 'src' => 'submissions', 'n' => '3' ),
				array( 'user_id' => '355', 'src' => 'user_profiles', 'n' => '1' ),
			)
		);

		$facts = ( new IdentityConflictQuery() )->account_facts( array( 355 ) );

		$this->assertSame( array( 'submissions' => 3, 'user_profiles' => 1 ), $facts[355]['rows'] );
		$this->assertSame(
			'submissions:3,user_profiles:1',
			IdentityConflictQuery::format_account_rows( $facts[355]['rows'] )
		);
	}

	/**
	 * Two statements per chunk, not five per account.
	 *
	 * The obvious shape -- ask `wp_users` once per id, then `COUNT(*)` once
	 * per id per store -- is 360 statements for the 72 accounts the first
	 * production export named. Both questions are set questions, so both are
	 * one `IN` list, and the counts are a single `UNION ALL`.
	 */
	public function test_account_facts_ask_in_one_statement_per_question(): void {
		$asked = array();
		Functions\when( 'get_users' )->alias(
			function ( $args ) use ( &$asked ) {
				$asked[] = $args;
				return array();
			}
		);

		$seen = array();
		$this->wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $query ) use ( &$seen ) {
				$seen[] = (string) $query;
				return array();
			}
		);

		( new IdentityConflictQuery() )->account_facts( array( 1, 2, 3 ) );

		$this->assertCount( 1, $asked, 'Existence is one call for the whole set, bounded by `include`.' );
		$this->assertSame( array( 1, 2, 3 ), $asked[0]['include'] );
		$this->assertSame( 'ID', $asked[0]['fields'] );

		// Load-bearing, not tidy: the default scopes the query to users with a
		// role on the CURRENT site, so on multisite a live account with no
		// role here would be reported as deleted.
		$this->assertSame( 0, $asked[0]['blog_id'] );

		foreach ( $seen as $statement ) {
			$this->assertStringContainsString( 'UNION ALL', $statement );
			$this->assertStringContainsString( 'GROUP BY user_id', $statement );
		}

		$this->assertSame(
			4,
			substr_count( implode( ' ', $seen ), 'COUNT(*)' ),
			'One counted branch per store the probe resolved.'
		);
		$this->assertSame(
			4,
			substr_count( implode( ' ', $seen ), 'MAX(' ),
			'One activity branch per store whose timestamp column the probe resolved.'
		);

		// The INVARIANT, not the count: what must hold is that asking about
		// more accounts does not issue more statements -- the questions are
		// fixed and the accounts ride inside an `IN` list. Asserting "one
		// statement" instead was a reading of how many questions there were
		// at the time, and it went stale the moment #1346 added a second.
		$before = count( $seen );
		$seen   = array();

		( new IdentityConflictQuery() )->account_facts( range( 1, 9 ) );

		$this->assertSame(
			$before,
			count( $seen ),
			'Three times the accounts must cost the same number of statements.'
		);
	}

	/**
	 * An id that is not a positive integer names no account and is dropped
	 * before any statement runs.
	 */
	public function test_account_facts_ignore_what_is_not_an_account(): void {
		Functions\expect( 'get_users' )->never();
		$this->wpdb->shouldReceive( 'get_results' )->never();

		$this->assertSame( array(), ( new IdentityConflictQuery() )->account_facts( array( 0, -3, '', 'abc' ) ) );
	}

	/**
	 * An account owning no row anywhere formats as an empty string.
	 *
	 * Which is itself a finding: an account named by this audit is named
	 * BECAUSE rows point at it, so an empty value means every such row sits in
	 * a store the probe did not resolve on this install.
	 */
	public function test_an_account_owning_nothing_formats_empty(): void {
		$this->assertSame( '', IdentityConflictQuery::format_account_rows( array() ) );
	}

	/**
	 * Drive `multiple_identities()` with findings, addresses and ciphertexts.
	 *
	 * One `get_results` expectation dispatching on the statement, for the
	 * reason {@see self::findings_with_addresses()} records. The two unions
	 * are told apart by their alias -- the address read names its column
	 * `AS e`, the ciphertext read names its own `AS c` -- which is why the
	 * production query bothers to use a different one.
	 *
	 * Decryption is supplied by overriding the class's own seam rather than by
	 * an alias mock on `Encryption`: an alias mock replaces that class for the
	 * whole PROCESS, so every later test in the run would get the double too.
	 *
	 * @param array<int, array<string, mixed>> $findings Rows for the `rf_hash` grouping.
	 * @param array<int, array<string, mixed>> $ciphers  Rows for the ciphertext union.
	 * @param array<string, string>            $plain    Ciphertext → what it decrypts to.
	 * @return array<int, array<string, mixed>>
	 */
	private function findings_with_ciphertexts( array $findings, array $ciphers, array $plain ): array {
		$this->wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $query ) use ( $findings, $ciphers ) {
				$sql = (string) $query;

				if ( false !== strpos( $sql, 'AS c FROM' ) ) {
					return $ciphers;
				}

				if ( false !== strpos( $sql, 'AS e FROM' ) ) {
					return array();
				}

				return false !== strpos( $sql, 'rf_hash' ) ? $findings : array();
			}
		);

		return $this->query_reading( $plain )->multiple_identities( 10 );
	}

	/**
	 * A query whose one key-touching seam answers from a map.
	 *
	 * Overriding `decrypt()` is what lets every verdict be driven without a
	 * key, and it is the same seam the production class keeps named so that
	 * "emits a verdict, never a value" can be checked by reading.
	 *
	 * @param array<string, string> $plain Ciphertext → plaintext.
	 * @return IdentityConflictQuery
	 */
	private function query_reading( array $plain ): IdentityConflictQuery {
		return new class( $plain ) extends IdentityConflictQuery {

			/**
			 * @var array<string, string>
			 */
			private array $plain;

			/**
			 * @param array<string, string> $plain Ciphertext → plaintext.
			 */
			public function __construct( array $plain ) {
				$this->plain = $plain;
			}

			protected function decrypt( string $cipher ): ?string {
				return $this->plain[ $cipher ] ?? null;
			}
		};
	}

	/**
	 * The shape of one finding for an account holding two RFs.
	 *
	 * @param string $a One stored identifier.
	 * @param string $b The other.
	 * @return string The `SHAPE_*` value reported.
	 */
	private function shape_of( string $a, string $b ): string {
		$rows = $this->findings_with_ciphertexts(
			$this->two_rf_finding(),
			array(
				array( 'user_id' => 438, 'h' => 'hashA', 'c' => 'cipherA' ),
				array( 'user_id' => 438, 'h' => 'hashB', 'c' => 'cipherB' ),
			),
			array( 'cipherA' => $a, 'cipherB' => $b )
		);

		$this->assertNotEmpty( $rows, 'An empty result would pass over the whole assertion.' );

		return (string) $rows[0][ IdentityConflictQuery::COLUMN_SHAPE_VERDICT ];
	}

	/**
	 * One digit different is a typo, so the rows stay the account holder's.
	 *
	 * The reading #1345 predicts should dominate the RF conflicts:
	 * `validate_rf()` checks only `strlen === 7 && is_numeric`, so a mistyped
	 * RF passes validation almost always and lands as a second hash on one
	 * person's account.
	 */
	public function test_one_digit_apart_reads_as_a_typo(): void {
		$this->assertSame(
			IdentityConflictQuery::SHAPE_SINGLE_DIGIT_EDIT,
			$this->shape_of( '1234567', '1234577' )
		);
	}

	/**
	 * A dropped digit is the same reading, and the lengths differ by one.
	 */
	public function test_a_dropped_digit_reads_as_a_typo(): void {
		$this->assertSame(
			IdentityConflictQuery::SHAPE_SINGLE_DIGIT_EDIT,
			$this->shape_of( '1234567', '123456' )
		);
	}

	/**
	 * Two adjacent digits swapped is reported as the transposition it is.
	 */
	public function test_two_adjacent_digits_swapped_read_as_a_transposition(): void {
		$this->assertSame(
			IdentityConflictQuery::SHAPE_TRANSPOSITION,
			$this->shape_of( '1234567', '1243567' )
		);
	}

	/**
	 * A leading zero is a canonicaliser gap, NOT a dropped digit.
	 *
	 * `0123456` against `123456` satisfies the single-deletion test too, and
	 * the order the classifier checks them in is what decides which of the two
	 * an operator is told. Only one of them names a rule the canonicaliser is
	 * missing: `normalize_cpf_rf()` strips non-digits and stops, so the two
	 * hash differently -- and `classify()` routes by `strlen() === 7`, so an
	 * RF written with a leading zero is filed under the CPF column entirely.
	 */
	public function test_a_leading_zero_reads_as_a_canonicaliser_gap(): void {
		$this->assertSame(
			IdentityConflictQuery::SHAPE_LEADING_ZEROS,
			$this->shape_of( '0123456', '123456' )
		);
	}

	/**
	 * Two unrelated numbers are reported as unrelated.
	 */
	public function test_two_unrelated_numbers_read_as_unrelated(): void {
		$this->assertSame(
			IdentityConflictQuery::SHAPE_UNRELATED,
			$this->shape_of( '1234567', '7654321' )
		);
	}

	/**
	 * Values that canonicalise to one are reported, though none should exist.
	 *
	 * This is #1345's own verdict, and the premise that says it cannot occur
	 * is that `IdentityNormalizationMigrationStrategy` (#1313 PR 3) has
	 * rewritten every row of all four stores. A production row reporting this
	 * means that card is not complete -- which is the whole reason the verdict
	 * is emitted rather than assumed away.
	 */
	public function test_values_that_canonicalise_to_one_are_still_reported(): void {
		$this->assertSame(
			IdentityConflictQuery::SHAPE_SAME_AFTER_CANONICALISATION,
			$this->shape_of( '123.456.789-09', '12345678909' )
		);
	}

	/**
	 * The set verdict is the WORST pair, never the closest.
	 *
	 * The operator's question is "can I treat these as one person's typos?",
	 * and one value the classifier cannot explain answers no however many
	 * near-misses sit beside it. Reporting the closest relation would read as
	 * reassurance about a set containing something nobody accounted for --
	 * the same reason `unknown` was never folded into `distinct_emails`.
	 */
	public function test_one_unrelated_value_decides_the_whole_set(): void {
		$rows = $this->findings_with_ciphertexts(
			array(
				array(
					'subject' => 438,
					IdentityConflictQuery::ALIAS_IDENTITY_COUNT => 3,
					IdentityConflictQuery::COLUMN_RELATED => 'hashA|hashB|hashC',
					'identifier_column' => 'rf_hash',
				),
			),
			array(
				array( 'user_id' => 438, 'h' => 'hashA', 'c' => 'cipherA' ),
				array( 'user_id' => 438, 'h' => 'hashB', 'c' => 'cipherB' ),
				array( 'user_id' => 438, 'h' => 'hashC', 'c' => 'cipherC' ),
			),
			// A and B are one digit apart; C is neither's near-miss.
			array( 'cipherA' => '1234567', 'cipherB' => '1234577', 'cipherC' => '9876543' )
		);

		$this->assertSame(
			IdentityConflictQuery::SHAPE_UNRELATED,
			$rows[0][ IdentityConflictQuery::COLUMN_SHAPE_VERDICT ]
		);
	}

	/**
	 * An identifier that cannot be read is said so, never guessed at.
	 *
	 * The mirror of `VERDICT_UNKNOWN`. A ciphertext this install's key no
	 * longer opens, or an identifier carried only by `ffc_user_profiles`,
	 * must not leave the remaining value looking conclusive: not having read
	 * a value is not evidence that it differs.
	 */
	public function test_an_identifier_that_cannot_be_read_is_not_decryptable(): void {
		$rows = $this->findings_with_ciphertexts(
			$this->two_rf_finding(),
			array(
				array( 'user_id' => 438, 'h' => 'hashA', 'c' => 'cipherA' ),
				array( 'user_id' => 438, 'h' => 'hashB', 'c' => 'cipherB' ),
			),
			array( 'cipherA' => '1234567' )
		);

		$this->assertSame(
			IdentityConflictQuery::SHAPE_NOT_DECRYPTABLE,
			$rows[0][ IdentityConflictQuery::COLUMN_SHAPE_VERDICT ]
		);
	}

	/**
	 * The identity index carries no ciphertext, so it resolves no store.
	 *
	 * `ffc_user_profiles` declares `cpf_hash` and `rf_hash` and nothing else,
	 * which is why the probe is a probe: a list written into this class would
	 * be a claim about a schema it does not own.
	 */
	public function test_a_store_without_a_ciphertext_column_is_not_read(): void {
		$this->without_ciphertext = array( 'wp_ffc_user_profiles' );

		// `statements()` answers every read with an empty set, so no finding
		// would reach the annotation and no ciphertext statement would run at
		// all -- the self-check below caught exactly that. The capture has to
		// return findings for the grouping pass.
		$findings         = $this->two_rf_finding();
		$ciphertext_reads = array();

		$this->wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $query ) use ( $findings, &$ciphertext_reads ) {
				$sql = (string) $query;

				if ( false !== strpos( $sql, 'AS c FROM' ) ) {
					$ciphertext_reads[] = $sql;
					return array();
				}

				if ( false !== strpos( $sql, 'AS e FROM' ) ) {
					return array();
				}

				return false !== strpos( $sql, 'rf_hash' ) ? $findings : array();
			}
		);

		( new IdentityConflictQuery() )->multiple_identities( 10 );

		$this->assertNotEmpty( $ciphertext_reads, 'No ciphertext statement ran at all, so this proves nothing.' );

		foreach ( $ciphertext_reads as $sql ) {
			$this->assertStringNotContainsString( 'wp_ffc_user_profiles', $sql );
		}
	}

	/**
	 * Drive `account_facts()` with an existence answer and store rows.
	 *
	 * The two statements are told apart by what they select: the counting
	 * union names `COUNT(*)`, the activity union names `MAX(`.
	 *
	 * @param array<int, int>                  $ids      Accounts to ask about.
	 * @param array<int, array<string, mixed>> $counts   Rows for the counting union.
	 * @param array<int, array<string, mixed>> $activity Rows for the activity union.
	 * @return array<int, array<string, mixed>>
	 */
	private function facts_with_activity( array $ids, array $counts, array $activity ): array {
		Functions\when( 'get_users' )->justReturn( $ids );

		$this->wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $query ) use ( $counts, $activity ) {
				$sql = (string) $query;

				return ( false !== strpos( $sql, 'MAX(' ) ) ? $activity : $counts;
			}
		);

		return ( new IdentityConflictQuery() )->account_facts( $ids );
	}

	/**
	 * The latest date across every store is what the account reports.
	 *
	 * #1346 chooses the surviving account **by use, never by id**, so the
	 * question is which account holds the person's records -- and that is the
	 * most recent activity anywhere, not the most recent in whichever store
	 * happens to be read first.
	 */
	public function test_the_account_reports_its_latest_activity_anywhere(): void {
		Functions\when( 'wp_date' )->justReturn( '2024-01-05' );

		$early = array( 'user_id' => 438, 'src' => 'wp_ffc_user_profiles', 'last_seen' => '2025-11-30 23:59:59' );
		$late  = array( 'user_id' => 438, 'src' => 'wp_ffc_recruitment_candidate', 'last_seen' => '2026-03-02 09:00:00' );

		// BOTH orders, and that is the whole point of the test. With the late
		// row first, "keep the latest" and "keep the first" agree and a
		// first-wins bug passes -- which is exactly what the mutation run
		// caught here before this assertion existed.
		$this->assertSame(
			'2026-03-02',
			$this->facts_with_activity( array( 438 ), array(), array( $late, $early ) )[438]['activity'],
			'Latest first.'
		);

		$this->wpdb->mockery_verify();
		$this->setUp();
		Functions\when( 'wp_date' )->justReturn( '2024-01-05' );

		$this->assertSame(
			'2026-03-02',
			$this->facts_with_activity( array( 438 ), array(), array( $early, $late ) )[438]['activity'],
			'Latest last -- a first-wins reduction returns the earlier date here.'
		);
	}

	/**
	 * A unix instant is rendered through the site timezone, never sliced.
	 *
	 * `ffc_submissions.submission_date` is a Category A instant -- unix UTC
	 * seconds -- while the other three stores hold housekeeping DATETIMEs the
	 * site's own timezone already wrote. Treating the two the same is how a
	 * comparison silently skews three stores against one.
	 */
	public function test_a_unix_store_is_rendered_through_the_site_timezone(): void {
		$asked = array();
		Functions\when( 'wp_date' )->alias(
			function ( $format, $stamp ) use ( &$asked ) {
				$asked[] = array( $format, $stamp );
				return '2026-07-04';
			}
		);

		$facts = $this->facts_with_activity(
			array( 438 ),
			array(),
			array( array( 'user_id' => 438, 'src' => 'wp_ffc_submissions', 'last_seen' => '1782172800' ) )
		);

		$this->assertSame( '2026-07-04', $facts[438]['activity'] );
		$this->assertSame( array( array( 'Y-m-d', 1782172800 ) ), $asked, 'The instant must reach wp_date() as an int.' );
	}

	/**
	 * An account with nothing anywhere reports an empty date, not a fake one.
	 */
	public function test_an_account_with_no_activity_reports_nothing(): void {
		$facts = $this->facts_with_activity( array( 438 ), array(), array() );

		$this->assertSame( '', $facts[438]['activity'] );
	}

	/**
	 * A zero DATETIME is not a date and must not be reported as one.
	 */
	public function test_a_zero_datetime_is_not_reported_as_a_date(): void {
		$facts = $this->facts_with_activity(
			array( 438 ),
			array(),
			array( array( 'user_id' => 438, 'src' => 'wp_ffc_user_profiles', 'last_seen' => '0000-00-00 00:00:00' ) )
		);

		$this->assertSame( '', $facts[438]['activity'] );
	}

	/**
	 * A store whose timestamp column is absent is skipped, never fatal.
	 *
	 * The column each store is read on is a MAP and not a probe -- nothing in
	 * a schema says which of an appointment's seven timestamps means "was
	 * used" -- so the column it names is probed before being read, and a
	 * store that lost it degrades to "no activity here".
	 */
	public function test_a_store_without_its_timestamp_column_is_skipped(): void {
		$this->without_activity_column = array( 'wp_ffc_user_profiles' );

		$seen = array();
		Functions\when( 'get_users' )->justReturn( array( 438 ) );
		$this->wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $query ) use ( &$seen ) {
				$sql = (string) $query;

				if ( false !== strpos( $sql, 'MAX(' ) ) {
					$seen[] = $sql;
				}

				return array();
			}
		);

		( new IdentityConflictQuery() )->account_facts( array( 438 ) );

		$this->assertNotEmpty( $seen, 'No activity statement ran at all, so this proves nothing.' );
		$this->assertStringNotContainsString( 'wp_ffc_user_profiles', $seen[0] );
		$this->assertSame( 3, substr_count( $seen[0], 'MAX(' ), 'The other three stores must still be read.' );
	}


	// ==================================================================
	// rf_check_digit_failures() -- #1345
	// ==================================================================

	/**
	 * Drive the check-digit scan with a row set and a decryption map.
	 *
	 * @param array<int, array<string, mixed>> $rows  Rows the grouped statement returns.
	 * @param array<string, string>            $plain Ciphertext → plaintext.
	 * @param int                              $limit Sample size.
	 * @return array<int, array<string, mixed>>
	 */
	private function scan( array $rows, array $plain, int $limit = 50 ): array {
		$this->wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $query ) use ( $rows ) {
				return ( false !== strpos( (string) $query, 'rf_encrypted' ) ) ? $rows : array();
			}
		);

		return $this->query_reading( $plain )->rf_check_digit_failures( $limit );
	}

	/**
	 * One grouped row as the statement returns it.
	 *
	 * @param array<string, mixed> $over Keys to replace.
	 * @return array<string, mixed>
	 */
	private static function scan_row( array $over = array() ): array {
		return array_merge(
			array(
				'subject'                                 => 'hashA',
				'c'                                       => 'cipherA',
				IdentityConflictQuery::ALIAS_ROW_COUNT    => 1,
				IdentityConflictQuery::COLUMN_STORES      => 'submissions',
				IdentityConflictQuery::COLUMN_RELATED     => '5',
				IdentityConflictQuery::COLUMN_ROW_IDS     => 'submissions:12',
				'identifier_column'                       => 'rf_hash',
			),
			$over
		);
	}

	/**
	 * `1234567` has a weighted sum of 77, residue 0, so its check digit should
	 * be 1 and is 7. The value this whole slice exists to surface.
	 */
	public function test_a_stored_rf_whose_check_digit_disagrees_is_reported(): void {
		$rows = $this->scan( array( self::scan_row() ), array( 'cipherA' => '1234567' ) );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'hashA', $rows[0]['subject'] );
		$this->assertSame( 'rf_hash', $rows[0]['identifier_column'] );
	}

	/**
	 * `prepare()` substitutes in the order the placeholders appear IN THE SQL,
	 * so the values must be listed in that order and not in the order the
	 * statement was composed. The outer `%s AS identifier_column` is written
	 * before `FROM ({$union})` while its value was appended after the union's,
	 * which shifted every placeholder by one: `MIN(%i)` received the store's
	 * LABEL as an identifier, the server rejected the statement, and
	 * `get_results()` answered empty -- so the scan reported no RF to look at
	 * on an install holding thousands (#1384).
	 *
	 * It asserts on the STATEMENT because nothing downstream can see this: the
	 * `get_results` double dispatches on a substring that the misaligned SQL
	 * still contains, so every behavioural test above stayed green while the
	 * query could not run at all.
	 */
	public function test_every_identifier_placeholder_receives_the_name_it_names(): void {
		$sql = '';

		$this->wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $query ) use ( &$sql ) {
				$statement = (string) $query;

				if ( false !== strpos( $statement, 'identifier_column' ) ) {
					$sql = $statement;
				}

				return array();
			}
		);

		$this->query_reading( array() )->rf_check_digit_failures( 50 );

		$this->assertNotSame( '', $sql, 'The scan composed no statement to inspect.' );

		$this->assertStringContainsString(
			'rf_hash AS h',
			$sql,
			'The union must group by the hash column: ' . $sql
		);

		$this->assertStringContainsString(
			'MIN(rf_encrypted) AS c',
			$sql,
			'The union must read the ciphertext column, not the store label: ' . $sql
		);

		$this->assertStringContainsString(
			'FROM wp_ffc_submissions',
			$sql,
			'The union must read FROM the table, not from a column: ' . $sql
		);

		$this->assertStringContainsString(
			'rf_hash AS identifier_column',
			$sql,
			'The outer select must still name the column it scanned: ' . $sql
		);
	}

	public function test_a_consistent_rf_is_not_reported(): void {
		$this->assertSame(
			array(),
			$this->scan( array( self::scan_row() ), array( 'cipherA' => '1000021' ) ),
			'1000021 satisfies the rule, so it is not a finding.'
		);
	}

	/**
	 * The mirror of `SHAPE_NOT_DECRYPTABLE`, and for its reason: not having
	 * read a value is not evidence that it is wrong. This finding's action is
	 * to contact a person about their own number, so acting on a
	 * classification that was never made is the irreversible mistake here.
	 */
	public function test_a_value_that_cannot_be_read_is_not_reported(): void {
		$this->assertSame(
			array(),
			$this->scan( array( self::scan_row() ), array() ),
			'A null from the decrypt seam must not read as a failure.'
		);
	}

	/**
	 * The handle for a finding that names no account -- which is ordinary
	 * here, since a candidacy carries no `user_id` before promotion.
	 */
	public function test_the_finding_names_the_rows_to_look_at(): void {
		$rows = $this->scan(
			array(
				self::scan_row(
					array(
						IdentityConflictQuery::COLUMN_RELATED => '',
						IdentityConflictQuery::COLUMN_ROW_IDS => 'recruitment_candidate:7,9',
						IdentityConflictQuery::ALIAS_ROW_COUNT => 2,
					)
				),
			),
			array( 'cipherA' => '1234567' )
		);

		$this->assertCount( 1, $rows );
		$this->assertSame( 'recruitment_candidate:7,9', $rows[0][ IdentityConflictQuery::COLUMN_ROW_IDS ] );
		$this->assertSame( '', $rows[0][ IdentityConflictQuery::COLUMN_RELATED ], 'No account is a legitimate answer here.' );
		$this->assertFalse( $rows[0][ IdentityConflictQuery::COLUMN_ROW_IDS_TRUNCATED ] );
	}

	/**
	 * `GROUP_CONCAT` truncates at `group_concat_max_len` in silence while
	 * `COUNT(*)` does not, so their disagreement is the only signal there is.
	 */
	public function test_a_short_row_id_list_is_flagged_against_its_own_count(): void {
		$rows = $this->scan(
			array(
				self::scan_row(
					array(
						IdentityConflictQuery::COLUMN_ROW_IDS  => 'submissions:12,34',
						IdentityConflictQuery::ALIAS_ROW_COUNT => 9,
					)
				),
			),
			array( 'cipherA' => '1234567' )
		);

		$this->assertTrue(
			$rows[0][ IdentityConflictQuery::COLUMN_ROW_IDS_TRUNCATED ],
			'Two ids listed against a count of nine is a partial list.'
		);
	}

	/**
	 * The outer `GROUP_CONCAT` joins each store's own list, so an account
	 * holding the RF in two stores is named twice. `DISTINCT` cannot fix it
	 * there -- it deduplicates the LISTS, not the ids inside them.
	 */
	public function test_an_account_named_by_two_stores_is_listed_once(): void {
		$rows = $this->scan(
			array(
				self::scan_row(
					array(
						IdentityConflictQuery::COLUMN_RELATED => '5|7|5',
						IdentityConflictQuery::COLUMN_STORES  => 'recruitment_candidate|submissions',
					)
				),
			),
			array( 'cipherA' => '1234567' )
		);

		$this->assertSame( '5|7', $rows[0][ IdentityConflictQuery::COLUMN_RELATED ] );
	}

	/**
	 * A capped scan that found nothing still has to say so.
	 *
	 * THE CASE THAT MOVED THE FLAG OFF THE FINDINGS
	 *
	 * With the signal as a column on each failure, this exact run -- cap
	 * reached, every value consistent -- emits no rows at all and the report
	 * reads CLEAN while values went unexamined. That is the failure mode
	 * `#1295` argues against, so the signal is a row of its own.
	 */
	public function test_a_capped_scan_reports_itself_even_with_no_failures(): void {
		$rows = array();
		for ( $i = 0; $i < 20000; $i++ ) {
			$rows[] = self::scan_row( array( 'subject' => 'hash' . $i ) );
		}

		$out = $this->scan( $rows, array( 'cipherA' => '1000021' ) );

		$this->assertCount( 1, $out, 'The scan found no failure and must still report that it did not finish.' );
		$this->assertTrue( $out[0][ IdentityConflictQuery::COLUMN_SCAN_TRUNCATED ] );
		$this->assertArrayNotHasKey( 'subject', $out[0], 'It is a report about the scan, not a finding about a value.' );
	}

	/**
	 * The identity index stores `rf_hash` with no `rf_encrypted` beside it, so
	 * there is nothing there to read a check digit out of.
	 */
	public function test_the_identity_index_is_never_scanned(): void {
		$this->without_ciphertext = array( 'wp_ffc_user_profiles' );

		$seen = $this->statements(
			static function ( IdentityConflictQuery $query ): void {
				$query->rf_check_digit_failures( 10 );
			}
		);

		$this->assertNotEmpty( $seen, 'No statement ran at all, so this proves nothing.' );
		$this->assertStringNotContainsString( 'wp_ffc_user_profiles', $seen[0] );
	}

	/**
	 * A store with the ciphertext but no `id` cannot name the rows to look at,
	 * which is the one handle a finding without an account has.
	 */
	public function test_a_store_without_a_row_id_is_not_scanned(): void {
		$this->without_ciphertext = array( 'wp_ffc_user_profiles' );
		$this->without_row_id     = array( 'wp_ffc_self_scheduling_appointments' );

		$seen = $this->statements(
			static function ( IdentityConflictQuery $query ): void {
				$query->rf_check_digit_failures( 10 );
			}
		);

		$this->assertNotEmpty( $seen, 'No statement ran at all, so this proves nothing.' );
		$this->assertStringNotContainsString( 'wp_ffc_self_scheduling_appointments', $seen[0] );
		$this->assertStringContainsString( 'wp_ffc_submissions', $seen[0], 'The stores that CAN be scanned still must be.' );
	}

	/**
	 * The scan reads rows a candidacy owns before promotion, so it must not
	 * inherit `pairs()`'s account filter -- which would drop exactly the
	 * population most likely to carry a wrong value.
	 */
	public function test_the_scan_does_not_filter_on_an_account(): void {
		$seen = $this->statements(
			static function ( IdentityConflictQuery $query ): void {
				$query->rf_check_digit_failures( 10 );
			}
		);

		$this->assertNotEmpty( $seen, 'No statement ran at all, so this proves nothing.' );
		$this->assertStringNotContainsString( 'user_id IS NOT NULL', $seen[0] );
	}

	/**
	 * Nothing the scan emits is a value. The column an operator reads names a
	 * hash, a store and a row -- never a digit of the identifier itself.
	 */
	public function test_the_scan_emits_no_identifier(): void {
		$rows = $this->scan( array( self::scan_row() ), array( 'cipherA' => '1234567' ) );

		$this->assertCount( 1, $rows );
		$this->assertArrayNotHasKey( 'c', $rows[0], 'The ciphertext must not travel with the finding.' );

		foreach ( $rows[0] as $key => $value ) {
			$this->assertStringNotContainsString(
				'1234567',
				(string) $value,
				"Column '{$key}' carries the identifier the scan read."
			);
		}
	}

	/**
	 * The packed row ids round-trip, per store.
	 *
	 * These ids are what an operator opens, and the format is this class's
	 * own -- so the parser lives beside the packer for the reason
	 * `format_account_rows()` does.
	 */
	public function test_it_unpacks_row_ids_per_store(): void {
		$this->assertSame(
			array(
				'submissions'  => array( 4, 9 ),
				'appointments' => array( 12 ),
			),
			IdentityConflictQuery::parse_row_ids( 'submissions:4,9|appointments:12' )
		);
	}

	/**
	 * A store named twice accumulates rather than overwriting.
	 *
	 * The scan groups by store before packing, so this should not arise --
	 * which is exactly why dropping the first half on a malformed value would
	 * go unnoticed until an operator worked a row that was silently short.
	 */
	public function test_a_repeated_store_accumulates(): void {
		$this->assertSame(
			array( 'submissions' => array( 1, 2 ) ),
			IdentityConflictQuery::parse_row_ids( 'submissions:1|submissions:2' )
		);
	}

	/**
	 * Unreadable input yields nothing, never a partial list.
	 *
	 * Half a list of row ids is worse than none: an operator who works it
	 * believes they finished the finding.
	 */
	public function test_unreadable_row_ids_yield_nothing(): void {
		foreach ( array( '', 'submissions', 'submissions:', ':4', 'submissions:abc', null, 42, array() ) as $bad ) {
			$this->assertSame(
				array(),
				IdentityConflictQuery::parse_row_ids( $bad ),
				sprintf( 'A value the parser cannot read must yield an empty array: %s', var_export( $bad, true ) )
			);
		}
	}

	/**
	 * Accounts unpack, deduplicated and in order.
	 */
	public function test_it_unpacks_accounts(): void {
		$this->assertSame( array( 7, 9 ), IdentityConflictQuery::parse_accounts( '7|9|7' ) );
	}

	/**
	 * A finding naming NO account is an answer, not a failure.
	 *
	 * This is the case the Migrations card drops by construction and the
	 * resolution screen exists to show: a recruitment candidacy carries no
	 * `user_id` until promotion.
	 */
	public function test_no_account_is_an_answer(): void {
		$this->assertSame( array(), IdentityConflictQuery::parse_accounts( '' ) );
		$this->assertSame( array(), IdentityConflictQuery::parse_accounts( null ) );
	}

	/**
	 * The scan reports what it READ, so an empty result is never ambiguous.
	 *
	 * A value it cannot decrypt is correctly not a failure -- not having read
	 * a number is no evidence that it is wrong. But then an empty failure list
	 * means either "all fine" or "read nothing", and a screen cannot tell
	 * them apart without this. `#1071` / `#1094`.
	 */
	public function test_it_reports_values_it_could_not_read(): void {
		$this->wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			static function ( $query ) {
				return ( false !== strpos( (string) $query, 'rf_encrypted' ) )
					? array(
						self::scan_row(),
						self::scan_row( array( 'subject' => 'hashB', 'c' => 'cipherB' ) ),
					)
					: array();
			}
		);

		// Neither ciphertext is in the map, so neither decrypts.
		$query    = $this->query_reading( array() );
		$findings = $query->rf_check_digit_failures( 50 );
		$coverage = $query->rf_scan_coverage();

		$this->assertSame( array(), $findings, 'An unreadable value is not a failure.' );
		$this->assertSame( 2, $coverage['examined'], 'Both distinct hashes were read.' );
		$this->assertSame( 2, $coverage['unreadable'], 'Neither could be decrypted, and that must be visible.' );
		$this->assertGreaterThan( 0, $coverage['stores'] );
	}

	/**
	 * A readable value that passes the digit is examined and NOT unreadable.
	 *
	 * The other half: without it, always incrementing `unreadable` would pass
	 * the test above and make every clean install look broken.
	 */
	public function test_a_readable_value_is_not_counted_unreadable(): void {
		$this->wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			static function ( $query ) {
				return ( false !== strpos( (string) $query, 'rf_encrypted' ) ) ? array( self::scan_row() ) : array();
			}
		);

		// 1234561 satisfies the digit: 7·1+6·2+5·3+4·4+3·5+2·6 = 77, 77 mod 11 = 0, dv = 1.
		$query = $this->query_reading( array( 'cipherA' => '1234561' ) );
		$query->rf_check_digit_failures( 50 );
		$coverage = $query->rf_scan_coverage();

		$this->assertSame( 1, $coverage['examined'] );
		$this->assertSame( 0, $coverage['unreadable'] );
	}

	/**
	 * Coverage from a scan that bailed is this call's zero, never the last
	 * call's numbers.
	 */
	public function test_coverage_starts_at_zero_before_a_scan(): void {
		$coverage = ( new IdentityConflictQuery() )->rf_scan_coverage();

		$this->assertSame( 0, $coverage['examined'] );
		$this->assertSame( 0, $coverage['unreadable'] );
	}
	// ==================================================================
	// check_digit_verdicts() -- #1386
	// ==================================================================

	/**
	 * Drive the per-hash verdict with a row set and a decryption map.
	 *
	 * @param array<int, array<string, mixed>> $rows  Rows the statement returns.
	 * @param array<string, string>            $plain Ciphertext => plaintext.
	 * @param array<int, string>               $ask   Hashes to judge.
	 * @return array<string, string>
	 */
	private function verdicts( array $rows, array $plain, array $ask ): array {
		$this->wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $query ) use ( $rows ) {
				return ( false !== strpos( (string) $query, 'rf_encrypted' ) ) ? $rows : array();
			}
		);

		return $this->query_reading( $plain )->check_digit_verdicts( 'rf_hash', $ask );
	}

	/**
	 * `1234561` satisfies the rule and `1234567` does not, so the two verdicts
	 * are what the whole tiering rests on.
	 */
	public function test_it_judges_each_identifier_it_was_asked_about(): void {
		$out = $this->verdicts(
			array(
				array( 'h' => 'hashA', 'c' => 'cipherA' ),
				array( 'h' => 'hashB', 'c' => 'cipherB' ),
			),
			array( 'cipherA' => '1234567', 'cipherB' => '1234561' ),
			array( 'hashA', 'hashB' )
		);

		$this->assertSame( IdentityConflictQuery::VERDICT_INVALID, $out['hashA'] );
		$this->assertSame( IdentityConflictQuery::VERDICT_VALID, $out['hashB'] );
	}

	/**
	 * A HASH NOBODY CARRIES IS A VERDICT, NOT A MISSING KEY.
	 *
	 * This is the whole reason the method exists rather than the caller
	 * reading absence from the failure list: a gap in a map reads as "fine" to
	 * any caller that uses `??`, and here it means the value was never seen.
	 */
	public function test_a_hash_no_store_carries_is_reported_absent(): void {
		$out = $this->verdicts( array(), array(), array( 'hashZ' ) );

		$this->assertArrayHasKey( 'hashZ', $out );
		$this->assertSame( IdentityConflictQuery::VERDICT_ABSENT, $out['hashZ'] );
	}

	/**
	 * A value that cannot be decrypted is not a failure, for the reason
	 * `SHAPE_NOT_DECRYPTABLE` is not one.
	 */
	public function test_a_value_that_does_not_decrypt_is_reported_unreadable(): void {
		$out = $this->verdicts(
			array( array( 'h' => 'hashA', 'c' => 'cipherA' ) ),
			array(),
			array( 'hashA' )
		);

		$this->assertSame( IdentityConflictQuery::VERDICT_UNREADABLE, $out['hashA'] );
	}

	/**
	 * Asking about nothing returns nothing, and asking about an identifier
	 * this class does not index likewise -- never a partial answer.
	 */
	public function test_it_answers_nothing_when_there_is_nothing_to_judge(): void {
		$this->assertSame( array(), $this->verdicts( array(), array(), array() ) );

		$this->wpdb->shouldReceive( 'get_results' )->andReturn( array() );

		$this->assertSame(
			array(),
			$this->query_reading( array() )->check_digit_verdicts( 'phone_hash', array( 'hashA' ) )
		);
	}

	/**
	 * Every identifier travels as a placeholder, and the values are listed in
	 * the order the placeholders appear -- the #1384 defect, which a mocked
	 * `get_results` cannot see because it never parses the statement.
	 */
	public function test_the_statement_names_what_each_placeholder_names(): void {
		$sql = '';

		$this->wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $query ) use ( &$sql ) {
				$statement = (string) $query;

				if ( false !== strpos( $statement, 'rf_encrypted' ) && '' === $sql ) {
					$sql = $statement;
				}

				return array();
			}
		);

		$this->query_reading( array() )->check_digit_verdicts( 'rf_hash', array( 'hashA', 'hashB' ) );

		$this->assertStringContainsString( 'rf_hash AS h', $sql, $sql );
		$this->assertStringContainsString( 'MIN(rf_encrypted) AS c', $sql, $sql );
		$this->assertStringContainsString( 'FROM wp_ffc_submissions', $sql, $sql );
		$this->assertStringContainsString( 'WHERE rf_hash IN (hashA,hashB)', $sql, $sql );
		$this->assertStringContainsString( 'GROUP BY rf_hash', $sql, $sql );
	}
}
