<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Maintenance\MaintenanceToolInterface;
use FreeFormCertificate\Maintenance\SubmissionLinkAuditor;
use FreeFormCertificate\Maintenance\IdentityAcceptance;
use FreeFormCertificate\Maintenance\IdentityConflictQuery;
use FreeFormCertificate\Repositories\SubmissionRepository;

/**
 * Tests for the SubmissionLinkAuditor maintenance tool.
 *
 * @covers \FreeFormCertificate\Maintenance\SubmissionLinkAuditor
 */
class SubmissionLinkAuditorTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var SubmissionRepository|Mockery\MockInterface */
	private $repo;

	/** @var IdentityConflictQuery|Mockery\MockInterface */
	private $conflicts;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		$this->repo = Mockery::mock( SubmissionRepository::class );

		// The cross-store questions reach four tables through the global
		// `$wpdb` (#1313 PR 10). What this file measures is how the auditor
		// AGGREGATES answers, so the query object is a double and its SQL is
		// its own test's subject.
		$this->conflicts = Mockery::mock( IdentityConflictQuery::class );
		$this->conflicts->shouldReceive( 'shared_identities' )->andReturn( array() )->byDefault();
		$this->conflicts->shouldReceive( 'multiple_identities' )->andReturn( array() )->byDefault();
		$this->conflicts->shouldReceive( 'unindexed_links' )->andReturn( array() )->byDefault();
		$this->conflicts->shouldReceive( 'check_digit_failures_of_both' )->andReturn( array() )->byDefault();

		// The account-facts pass runs over every check's rows, so it is part
		// of `run()` now and not of any one check. Answering nothing by
		// default leaves each finding at `missing`, which is the seeded
		// default and what a test that says nothing about accounts should
		// see.
		$this->conflicts->shouldReceive( 'account_facts' )->andReturn( array() )->byDefault();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The auditor with both seams filled by doubles.
	 *
	 * The acceptance record is a DOUBLE RATHER THAN A `get_option` STUB, for
	 * the reason `CLAUDE.md` records and `IdentityQueueTest` repeats: teaching
	 * Patchwork that function leaves it defined for every later test in the
	 * process, and what this file measures is how the auditor aggregates.
	 *
	 * @param array<string, array<string, mixed>> $accepted Records, keyed as `IdentityAcceptance` keys them.
	 */
	private function auditor( array $accepted = array() ): SubmissionLinkAuditor {
		$conflicts = $this->conflicts;

		return new class( $this->repo, $conflicts, $accepted ) extends SubmissionLinkAuditor {
			/** @var IdentityConflictQuery */
			private $double;

			/** @var array<string, array<string, mixed>> */
			private $accepted;

			/**
			 * @param SubmissionRepository                $repository Repository double.
			 * @param IdentityConflictQuery               $double     Cross-store double.
			 * @param array<string, array<string, mixed>> $accepted   Acceptance records.
			 */
			public function __construct( $repository, $double, array $accepted ) {
				parent::__construct( $repository );
				$this->double   = $double;
				$this->accepted = $accepted;
			}

			/**
			 * @return IdentityConflictQuery
			 */
			protected function conflicts(): IdentityConflictQuery {
				return $this->double;
			}

			/**
			 * @return IdentityAcceptance
			 */
			protected function acceptances(): IdentityAcceptance {
				$record = $this->accepted;

				return new class( $record ) extends IdentityAcceptance {

					/** @var array<string, array<string, mixed>> */
					private $record;

					/**
					 * @param array<string, array<string, mixed>> $record Records.
					 */
					public function __construct( array $record ) {
						$this->record = $record;
					}

					/**
					 * @return array<string, array<string, mixed>>
					 */
					public function all(): array {
						return $this->record;
					}
				};
			}
		};
	}

	public function test_is_report_only_metadata(): void {
		$tool = $this->auditor();
		$this->assertInstanceOf( MaintenanceToolInterface::class, $tool );
		$this->assertSame( 'submission_link_audit', $tool->get_id() );
		$this->assertFalse( $tool->is_actionable() );
		$this->assertSame( array(), $tool->get_default_options() );
		$this->assertNotSame( '', $tool->get_title() );
	}

	public function test_run_aggregates_all_four_checks(): void {
		$this->repo->shouldReceive( 'find_orphan_user_links' )->once()->andReturn(
			array(
				array(
					'id'      => 1,
					'user_id' => 99,
					'form_id' => 3,
				),
			)
		);
		$this->repo->shouldReceive( 'find_users_with_multiple_identities' )->once()->andReturn(
			array(
				array(
					'user_id'   => 5,
					'cpf_count' => 2,
					'rf_count'  => 1,
				),
				array(
					'user_id'   => 6,
					'cpf_count' => 1,
					'rf_count'  => 3,
				),
			)
		);
		$this->repo->shouldReceive( 'find_unlinked_with_matching_identity' )->once()->andReturn( array() );
		$this->repo->shouldReceive( 'find_shared_identities' )->once()->andReturn(
			array(
				array(
					'cpf_hash'   => 'abc',
					'user_count' => 2,
				),
			)
		);

		$report = $this->auditor()->run( array() );

		$this->assertSame( 1, $report['checks']['orphan_links']['count'] );
		$this->assertSame( 2, $report['checks']['multiple_identities']['count'] );
		$this->assertSame( 0, $report['checks']['should_be_linked']['count'] );
		$this->assertSame( 1, $report['checks']['shared_identities']['count'] );
		$this->assertSame( 4, $report['total'] );
		$this->assertFalse( $report['checks']['orphan_links']['truncated'] );
		$this->assertSame( 99, $report['checks']['orphan_links']['rows'][0]['user_id'] );
	}

	public function test_truncated_flag_when_sample_limit_hit(): void {
		$full = array_fill( 0, SubmissionLinkAuditor::SAMPLE_LIMIT, array( 'id' => 1 ) );
		$this->repo->shouldReceive( 'find_orphan_user_links' )->once()->andReturn( $full );
		$this->repo->shouldReceive( 'find_users_with_multiple_identities' )->once()->andReturn( array() );
		$this->repo->shouldReceive( 'find_unlinked_with_matching_identity' )->once()->andReturn( array() );
		$this->repo->shouldReceive( 'find_shared_identities' )->once()->andReturn( array() );

		$report = $this->auditor()->run( array() );

		$this->assertTrue( $report['checks']['orphan_links']['truncated'] );
		$this->assertSame( SubmissionLinkAuditor::SAMPLE_LIMIT, $report['checks']['orphan_links']['count'] );
	}

	public function test_the_cross_store_checks_are_aggregated_too(): void {
		$this->repo->shouldReceive( 'find_orphan_user_links' )->andReturn( array() );
		$this->repo->shouldReceive( 'find_users_with_multiple_identities' )->andReturn( array() );
		$this->repo->shouldReceive( 'find_unlinked_with_matching_identity' )->andReturn( array() );
		$this->repo->shouldReceive( 'find_shared_identities' )->andReturn( array() );

		$this->conflicts->shouldReceive( 'shared_identities' )->once()->andReturn(
			array(
				array(
					'subject'           => 'hash-of-one-cpf',
					'user_count'        => 2,
					'identifier_column' => 'cpf_hash',
				),
			)
		);
		$this->conflicts->shouldReceive( 'multiple_identities' )->once()->andReturn(
			array(
				array(
					'subject'           => 7,
					'identity_count'    => 2,
					'identifier_column' => 'cpf_hash',
				),
			)
		);
		$this->conflicts->shouldReceive( 'unindexed_links' )->once()->andReturn(
			array(
				array(
					'user_id'           => 9,
					'identifier_column' => 'rf_hash',
				),
			)
		);

		$report = $this->auditor()->run( array() );

		$this->assertSame( 1, $report['checks']['cross_store_shared_identities']['count'] );
		$this->assertSame( 1, $report['checks']['cross_store_multiple_identities']['count'] );
		$this->assertSame( 1, $report['checks']['unindexed_links']['count'] );
		$this->assertSame( 3, $report['total'], 'The cross-store findings are computed and left out of the total, so the screen would say the install is clean.' );
	}

	/**
	 * Every check the auditor returns is shown.
	 *
	 * THE REPORT IS NOT THE SCREEN. The Migrations tab renders the counts by
	 * iterating a hand-written label map, so a check added to the auditor
	 * without a label there is computed on every run and displayed nowhere --
	 * the "built but never wired" class `AjaxWiringTest` exists for, and the
	 * one that would quietly make #1313\'s conflict count unreadable after all
	 * this.
	 *
	 * The view is read as text rather than rendered: it is a template that
	 * expects a page full of locals, and what matters here is which keys it
	 * names.
	 */
	public function test_every_check_has_a_label_on_the_migrations_tab(): void {
		$this->repo->shouldReceive( 'find_orphan_user_links' )->andReturn( array() );
		$this->repo->shouldReceive( 'find_users_with_multiple_identities' )->andReturn( array() );
		$this->repo->shouldReceive( 'find_unlinked_with_matching_identity' )->andReturn( array() );
		$this->repo->shouldReceive( 'find_shared_identities' )->andReturn( array() );

		$report = $this->auditor()->run( array() );
		$view   = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/settings/views/ffc-tab-migrations.php' );

		$this->assertSame(
			1,
			preg_match( '/\$ffcertificate_sa_labels = array\((.*?)\n\t\);/s', $view, $m ),
			'Could not find the label map — it moved or changed shape, so this test is no longer measuring the screen.'
		);

		foreach ( array_keys( $report['checks'] ) as $key ) {
			$this->assertStringContainsString(
				"'{$key}'",
				$m[1],
				"The audit computes '{$key}' and the Migrations tab has no label for it, so its count is never shown."
			);
		}
	}

	/**
	 * Every finding that names an account says whether it still exists and
	 * what it owns.
	 *
	 * Before #1354 a finding named a bare integer read off the plugin's own
	 * rows, and nothing had ever asked `wp_users` whether an account was
	 * behind it -- so an operator told that an account held two CPFs could not
	 * tell merge from repair from deleting a row pointing at somebody removed
	 * years ago.
	 */
	public function test_every_finding_names_what_is_true_of_its_accounts(): void {
		$this->repo->shouldReceive( 'find_orphan_user_links' )->andReturn( array() );
		$this->repo->shouldReceive( 'find_users_with_multiple_identities' )->andReturn( array() );
		$this->repo->shouldReceive( 'find_unlinked_with_matching_identity' )->andReturn( array() );
		$this->repo->shouldReceive( 'find_shared_identities' )->andReturn( array() );

		$this->conflicts->shouldReceive( 'multiple_identities' )->andReturn(
			array(
				array(
					'subject'           => 438,
					'identity_count'    => 2,
					'identifier_column' => 'cpf_hash',
				),
			)
		);
		$this->conflicts->shouldReceive( 'shared_identities' )->andReturn(
			array(
				array(
					'subject'           => 'hash-of-one-cpf',
					'user_count'        => 2,
					'related'           => '355|5276',
					'identifier_column' => 'cpf_hash',
				),
			)
		);

		$asked = array();
		$this->conflicts->shouldReceive( 'account_facts' )->once()->andReturnUsing(
			function ( array $ids ) use ( &$asked ) {
				$asked = $ids;

				return array(
					438  => array( 'status' => IdentityConflictQuery::STATUS_EXISTS, 'rows' => array( 'submissions' => 12 ) ),
					355  => array( 'status' => IdentityConflictQuery::STATUS_EXISTS, 'rows' => array( 'submissions' => 3, 'user_profiles' => 1 ) ),
					5276 => array( 'status' => IdentityConflictQuery::STATUS_MISSING, 'rows' => array() ),
				);
			}
		);

		$report = $this->auditor()->run( array() );

		sort( $asked );
		$this->assertSame(
			array( 355, 438, 5276 ),
			$asked,
			'Every account named anywhere in the report is asked about in ONE pass, so two rows about the same account cannot disagree.'
		);

		$multiple = $report['checks']['cross_store_multiple_identities']['rows'][0];
		$this->assertSame( IdentityConflictQuery::STATUS_EXISTS, $multiple[ IdentityConflictQuery::COLUMN_ACCOUNT_STATUS ] );
		$this->assertSame( '438=submissions:12', $multiple[ IdentityConflictQuery::COLUMN_ACCOUNT_ROWS ] );

		// Positional against the ids the finding names, in that order: a set
		// would misalign the status from the account it answers for.
		$shared = $report['checks']['cross_store_shared_identities']['rows'][0];
		$this->assertSame(
			IdentityConflictQuery::STATUS_EXISTS . '|' . IdentityConflictQuery::STATUS_MISSING,
			$shared[ IdentityConflictQuery::COLUMN_ACCOUNT_STATUS ]
		);
		$this->assertSame(
			'355=submissions:3,user_profiles:1|5276=',
			$shared[ IdentityConflictQuery::COLUMN_ACCOUNT_ROWS ],
			'An account owning no row still holds its slot, or the list stops lining up with the ids.'
		);
	}

	/**
	 * The control: `orphan_links` selects rows whose `user_id` has no
	 * `wp_users` match, so every one of its findings MUST read `missing`.
	 *
	 * An `exists` here would mean the annotation is reading accounts off the
	 * wrong key -- which no assertion about the other checks could catch,
	 * because there the answer is whatever the facts pass supplies.
	 */
	public function test_an_orphan_link_can_only_be_missing(): void {
		$this->repo->shouldReceive( 'find_orphan_user_links' )->andReturn(
			array( array( 'id' => 5, 'user_id' => 99, 'form_id' => 2 ) )
		);
		$this->repo->shouldReceive( 'find_users_with_multiple_identities' )->andReturn( array() );
		$this->repo->shouldReceive( 'find_unlinked_with_matching_identity' )->andReturn( array() );
		$this->repo->shouldReceive( 'find_shared_identities' )->andReturn( array() );

		// The facts pass answers honestly for an id no `wp_users` row backs.
		$this->conflicts->shouldReceive( 'account_facts' )->andReturn(
			array( 99 => array( 'status' => IdentityConflictQuery::STATUS_MISSING, 'rows' => array( 'submissions' => 1 ) ) )
		);

		$report = $this->auditor()->run( array() );
		$row    = $report['checks']['orphan_links']['rows'][0];

		$this->assertSame( IdentityConflictQuery::STATUS_MISSING, $row[ IdentityConflictQuery::COLUMN_ACCOUNT_STATUS ] );
		$this->assertSame( '99=submissions:1', $row[ IdentityConflictQuery::COLUMN_ACCOUNT_ROWS ] );
	}

	/**
	 * Each row shape is read where its accounts actually are.
	 *
	 * `grouped()` aliases whatever it grouped BY as `subject`, so that key is
	 * a user id in one check and a hash in the other, and nothing in the row
	 * says which. Shape cannot decide it either: a 16-character hex prefix can
	 * be all digits and read as numeric, which is why the map is explicit.
	 */
	public function test_accounts_named_by_reads_each_row_shape(): void {
		$this->assertSame(
			array( 438 ),
			SubmissionLinkAuditor::accounts_named_by( 'cross_store_multiple_identities', array( 'subject' => 438 ) )
		);
		$this->assertSame(
			array( 355, 5276 ),
			SubmissionLinkAuditor::accounts_named_by( 'cross_store_shared_identities', array( 'related' => '355|5276' ) )
		);
		$this->assertSame(
			array( 9 ),
			SubmissionLinkAuditor::accounts_named_by( 'unindexed_links', array( 'user_id' => 9 ) )
		);

		// A hash that happens to be all digits is never read as an account,
		// because the map says this check groups by hash.
		$this->assertSame(
			array(),
			SubmissionLinkAuditor::accounts_named_by( 'cross_store_shared_identities', array( 'subject' => '1234567890123456' ) )
		);

		// The one check with no account at all: its findings ARE submissions
		// with no user, so annotating them would claim an answer it lacks.
		$this->assertSame(
			array(),
			SubmissionLinkAuditor::accounts_named_by( 'should_be_linked', array( 'id' => 4, 'cpf_hash' => 'abc' ) )
		);
	}

	/**
	 * The two maps that say which half of a grouped row is which must agree.
	 *
	 * The export keeps its own map because it formats BOTH halves; this class
	 * keeps one because only the producer knows where the accounts are. They
	 * encode the same fact from opposite ends, so a check added to one and not
	 * the other would put a hash in a column meant for account ids -- the
	 * defect #1344 fixed, wearing a new name. Read as text: the export is
	 * about formatting and standing it up here would prove nothing.
	 */
	public function test_the_account_map_agrees_with_the_export_subject_map(): void {
		$source = file_get_contents( __DIR__ . '/../../includes/maintenance/class-ffc-identity-audit-export-source.php' );
		$this->assertIsString( $source );

		$block = array();
		if ( preg_match( '/SUBJECT_IS\s*=\s*array\((.*?)\);/s', $source, $m ) ) {
			preg_match_all( "/'([a-z_]+)'\s*=>\s*'([a-z_]+)'/", $m[1], $pairs, PREG_SET_ORDER );
			foreach ( $pairs as $pair ) {
				$block[ $pair[1] ] = $pair[2];
			}
		}

		$this->assertNotEmpty( $block, 'The export map could not be read, so this comparison proves nothing.' );

		foreach ( $block as $check => $subject_is ) {
			$this->assertArrayHasKey(
				$check,
				SubmissionLinkAuditor::ACCOUNTS_IN,
				"The export formats `{$check}` but this class does not say where its accounts are."
			);

			// Grouped by HASH is the direction that must match exactly: the
			// accounts are then the half the count aggregated away, and
			// reading them off `subject` instead would put a hash where
			// account ids belong -- the #1344 defect wearing a new name.
			//
			// Grouped by ACCOUNT admits two keys, and deliberately so: the
			// cross-store checks alias their grouping column as `subject`,
			// while the two legacy ones emit a literal `user_id` and no
			// `subject` at all. What must never happen is `related`, which
			// holds hashes in that direction.
			if ( 'hash' === $subject_is ) {
				$this->assertSame(
					'related',
					SubmissionLinkAuditor::ACCOUNTS_IN[ $check ],
					"`{$check}` groups by hash, so its accounts are in `related` and nowhere else."
				);
				continue;
			}

			$this->assertContains(
				SubmissionLinkAuditor::ACCOUNTS_IN[ $check ],
				array( 'subject', 'user_id' ),
				"`{$check}` groups by account, so `related` there holds hashes, never accounts."
			);
		}
	}

	/**
	 * The scan reports an acceptance, it never drops one (#1536).
	 *
	 * The identity screen drops accepted findings because there the list IS
	 * the work. This card is a scan, and an accepted finding is still true of
	 * the data -- the stored number goes on failing its check digit -- so it
	 * stays in `rows` and is counted beside the total. That is what lets the
	 * card say `N findings, M accepted, N-M open` instead of showing a
	 * smaller number than the database holds.
	 */
	public function test_an_accepted_finding_is_counted_and_never_dropped(): void {
		$this->quiet_repo();

		$this->conflicts->shouldReceive( 'check_digit_failures_of_both' )->andReturn(
			array(
				array(
					'subject'           => 'hash-of-the-bad-rf',
					'identifier_column' => 'rf_hash',
				),
				array(
					'subject'           => 'hash-of-another-bad-rf',
					'identifier_column' => 'rf_hash',
				),
			)
		);

		$report = $this->auditor(
			array(
				IdentityAcceptance::key( 'check_digit', 'rf', 'hash-of-the-bad-rf' ) => array(
					IdentityAcceptance::FIELD_CHECK   => 'check_digit',
					IdentityAcceptance::FIELD_SUBJECT => 'hash-of-the-bad-rf',
					IdentityAcceptance::FIELD_REASON  => IdentityAcceptance::REASON_NEVER_SUPPLIED,
				),
			)
		)->run( array() );

		$this->assertSame( 2, $report['checks']['check_digit']['count'], 'The scan found two, and accepting one does not unfind it.' );
		$this->assertSame( 1, $report['checks']['check_digit']['accepted'] );
		$this->assertCount( 2, $report['checks']['check_digit']['rows'], 'A row dropped here is a finding the CSV could not carry an `accepted` column for.' );
		$this->assertSame( 2, $report['total'] );
		$this->assertSame( 1, $report['accepted'] );
	}

	/**
	 * Counted in the scan's direction, never the record's (#1536).
	 *
	 * An acceptance outlives its finding: the value may be repaired upstream,
	 * or -- for the one check keyed on an account -- the account may be
	 * deleted, after which the cross-store query no longer sees it. Walking
	 * the records instead of the findings would report accepted findings this
	 * report does not hold, and on a clean database could report some against
	 * a total of zero.
	 */
	public function test_an_acceptance_whose_finding_is_gone_is_not_counted(): void {
		$this->quiet_repo();

		$report = $this->auditor(
			array(
				IdentityAcceptance::key( 'check_digit', 'rf', 'hash-of-a-value-since-repaired' ) => array(
					IdentityAcceptance::FIELD_CHECK   => 'check_digit',
					IdentityAcceptance::FIELD_SUBJECT => 'hash-of-a-value-since-repaired',
					IdentityAcceptance::FIELD_REASON  => IdentityAcceptance::REASON_UNIDENTIFIABLE,
				),
				IdentityAcceptance::key( 'cross_store_multiple_identities', 'rf', '438' ) => array(
					IdentityAcceptance::FIELD_CHECK   => 'cross_store_multiple_identities',
					IdentityAcceptance::FIELD_SUBJECT => '438',
					IdentityAcceptance::FIELD_REASON  => IdentityAcceptance::REASON_NEVER_SUPPLIED,
				),
			)
		)->run( array() );

		$this->assertSame( 0, $report['total'] );
		$this->assertSame( 0, $report['accepted'], 'Two records on file and nothing found: the count follows the findings.' );
		$this->assertSame( 0, $report['checks']['check_digit']['accepted'] );
	}

	/**
	 * A check the screen cannot accept from counts none, record or no record.
	 *
	 * Only the three checks `IdentityQueue::CHECKS` composes have a card to
	 * accept from. `unindexed_links` carries a subject and so is keyable by
	 * shape, which is exactly why the scope test lives in
	 * `IdentityAcceptance::key_for_row()` rather than in whichever consumer
	 * happened to think of it.
	 */
	public function test_a_check_outside_the_queue_counts_none_even_carrying_a_subject(): void {
		$this->quiet_repo();

		$this->conflicts->shouldReceive( 'unindexed_links' )->andReturn(
			array(
				array(
					'subject'           => 'hash-of-an-unindexed-rf',
					'identifier_column' => 'rf_hash',
				),
			)
		);

		$report = $this->auditor(
			array(
				IdentityAcceptance::key( 'unindexed_links', 'rf', 'hash-of-an-unindexed-rf' ) => array(
					IdentityAcceptance::FIELD_CHECK   => 'unindexed_links',
					IdentityAcceptance::FIELD_SUBJECT => 'hash-of-an-unindexed-rf',
					IdentityAcceptance::FIELD_REASON  => IdentityAcceptance::REASON_NEVER_SUPPLIED,
				),
			)
		)->run( array() );

		$this->assertSame( 1, $report['checks']['unindexed_links']['count'] );
		$this->assertSame( 0, $report['checks']['unindexed_links']['accepted'] );
		$this->assertSame( 0, $report['accepted'] );
	}

	/**
	 * The field discriminates: a CPF acceptance does not cover an RF finding.
	 *
	 * Account `438` holds both an 11-digit CPF and a 7-digit RF, and each is
	 * its own finding and its own decision -- the production case #1511
	 * records. A key without the field would let one acceptance silence two.
	 */
	public function test_an_acceptance_on_one_identifier_leaves_the_other_open(): void {
		$this->quiet_repo();

		$this->conflicts->shouldReceive( 'multiple_identities' )->andReturn(
			array(
				array(
					'subject'           => '438',
					'identity_count'    => 2,
					'identifier_column' => 'cpf_hash',
				),
				array(
					'subject'           => '438',
					'identity_count'    => 2,
					'identifier_column' => 'rf_hash',
				),
			)
		);

		$report = $this->auditor(
			array(
				IdentityAcceptance::key( 'cross_store_multiple_identities', 'cpf', '438' ) => array(
					IdentityAcceptance::FIELD_CHECK   => 'cross_store_multiple_identities',
					IdentityAcceptance::FIELD_SUBJECT => '438',
					IdentityAcceptance::FIELD_REASON  => IdentityAcceptance::REASON_NEVER_SUPPLIED,
				),
			)
		)->run( array() );

		$this->assertSame( 2, $report['checks']['cross_store_multiple_identities']['count'] );
		$this->assertSame( 1, $report['checks']['cross_store_multiple_identities']['accepted'], 'The CPF decision must not silence the RF finding.' );
	}

	/**
	 * No consumer rebuilds the acceptance key (#1536).
	 *
	 * Three surfaces join one option against a scan row -- the queue's filter,
	 * the CSV's column and this report's count -- and each built the
	 * `check|field|subject` key itself until `key_for_row()` took the rule
	 * over. Two copies of a join is how two surfaces come to disagree about
	 * it, which is why the dbDelta gate and `ActivatorSqlTest` read their
	 * statements through one shared parser.
	 *
	 * It reads the CONSUMERS and not `IdentityAcceptance`, which legitimately
	 * composes the key from its own arguments when the screen writes a record.
	 */
	public function test_no_consumer_builds_the_acceptance_key_from_a_row(): void {
		$consumers = array(
			'includes/maintenance/class-ffc-submission-link-auditor.php',
			'includes/maintenance/class-ffc-identity-audit-export-source.php',
			'includes/maintenance/class-ffc-identity-queue.php',
		);

		$read = 0;

		foreach ( $consumers as $relative ) {
			$path = \dirname( __DIR__, 2 ) . '/' . $relative;

			$this->assertFileExists( $path, "The join's consumers moved; re-point this guard at them." );

			$source = (string) file_get_contents( $path );

			++$read;

			// A CALL AND NOT THE PROSE. These files legitimately NAME
			// `IdentityAcceptance::key()` in a docblock when explaining why
			// the key carries the check, and the first version of this guard
			// read that sentence as a call -- the same trap `CLAUDE.md`
			// records for the `phpcs:` token, where prose mentioning a
			// directive is not one. A call passes an argument, so the paren is
			// followed by something other than its own close.
			$this->assertDoesNotMatchRegularExpression(
				'/IdentityAcceptance::key\(\s*[^)\s]/',
				$source,
				"{$relative} builds the acceptance key itself. Go through `IdentityAcceptance::key_for_row()`, so the three surfaces cannot disagree about the same join."
			);

			$this->assertMatchesRegularExpression(
				'/IdentityAcceptance::key_for_row\(\s*[^)\s]/',
				$source,
				"{$relative} is listed as a consumer of the acceptance join but does not perform it -- drop it from this list or restore the lookup."
			);
		}

		// An empty read must never pass as clean (the #1071 / #1094 rule).
		// Exact, against a non-empty frozen list, which is the shape
		// `CLAUDE.md` prefers over any floor.
		$this->assertSame( \count( $consumers ), $read );
	}

	/**
	 * Keep the repository quiet for a case about the cross-store checks.
	 */
	private function quiet_repo(): void {
		$this->repo->shouldReceive( 'find_orphan_user_links' )->andReturn( array() );
		$this->repo->shouldReceive( 'find_users_with_multiple_identities' )->andReturn( array() );
		$this->repo->shouldReceive( 'find_unlinked_with_matching_identity' )->andReturn( array() );
		$this->repo->shouldReceive( 'find_shared_identities' )->andReturn( array() );
	}

	public function test_clean_database_reports_zero(): void {
		$this->repo->shouldReceive( 'find_orphan_user_links' )->once()->andReturn( array() );
		$this->repo->shouldReceive( 'find_users_with_multiple_identities' )->once()->andReturn( array() );
		$this->repo->shouldReceive( 'find_unlinked_with_matching_identity' )->once()->andReturn( array() );
		$this->repo->shouldReceive( 'find_shared_identities' )->once()->andReturn( array() );

		$report = $this->auditor()->run( array() );

		$this->assertSame( 0, $report['total'] );
	}
}
