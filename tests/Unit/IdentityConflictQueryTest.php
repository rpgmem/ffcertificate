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

	/**
	 * Tables the `SHOW COLUMNS` probe should report as having no `email_hash`.
	 *
	 * @var array<int, string>
	 */
	private array $without_email = array();

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
					foreach ( $this->without_email as $table ) {
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
}
