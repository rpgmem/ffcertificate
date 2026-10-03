<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\IdentityResolutionPage;
use FreeFormCertificate\Maintenance\IdentityAcceptance;
use FreeFormCertificate\Maintenance\IdentityQueue;
use FreeFormCertificate\Maintenance\IdentityRepair;

/**
 * The record of findings nobody can resolve (#1532).
 *
 * WHAT IS WORTH PINNING HERE
 *
 * The danger in this class is not that it fails to store something -- it is
 * that it stores something nothing can match, or that what it stores reads as
 * a resolution. So the cases are: every posted part is refused before it is
 * stored, the key deliberately omits the tier, an unreadable record returns
 * its finding to the work list rather than surviving, and the two verbs are
 * disjoint from the ones the screen counts as resolved.
 *
 * `ActivityLog` IS AN ALIAS MOCK, NOT A STUBBED SETTING. Its real `log()` reads
 * `SettingsReader::activity_log_enabled()` and then the table, so driving it
 * would make these tests about the log rather than about the record; what they
 * assert is that the decision is written, which is what the alias measures.
 *
 * @covers \FreeFormCertificate\Maintenance\IdentityAcceptance
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class IdentityAcceptanceTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<string, mixed> The option, as the stubs see it. */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		class_exists( '\FreeFormCertificate\Maintenance\IdentityAcceptance' );

		$this->options = array();

		Functions\when( '__' )->returnArg();
		Functions\when( 'get_option' )->alias(
			function ( $key, $default_value = false ) {
				return $this->options[ $key ] ?? $default_value;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) {
				$this->options[ $key ] = $value;

				return true;
			}
		);

	}

	/**
	 * A record whose log calls are captured rather than written.
	 *
	 * Through the `log()` seam and NOT an `alias:` mock of `ActivityLog`: an
	 * alias replaces the class, so `LEVEL_WARNING` stops existing and the
	 * method under test dies before it reaches the log.
	 *
	 * @return IdentityAcceptance
	 */
	private function capturing(): IdentityAcceptance {
		return new class() extends IdentityAcceptance {

			/** @var array<int, array<string, mixed>> */
			public array $written = array();

			/**
			 * Capture instead of logging.
			 *
			 * Overrides `write()` and not `log()`: the truncation happens in
			 * `log()`, so a seam above it would hand this the whole hash and the
			 * assertion would pass for the wrong reason. That is not
			 * hypothetical -- it is what the first version of this did.
			 *
			 * @param string               $action  The action.
			 * @param array<string, mixed> $context What would be recorded.
			 * @param int                  $by      The operator.
			 * @return void
			 */
			protected function write( string $action, array $context, int $by ): void {
				$this->written[] = array_merge( $context, array( 'action' => $action, 'by' => $by ) );
			}
		};
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A record as `accept()` writes one.
	 *
	 * @param string $tier The tier to record.
	 * @return IdentityAcceptance
	 */
	private function with_one_accepted( string $tier = IdentityQueue::TIER_ISOLATED ): IdentityAcceptance {
		$record = new IdentityAcceptance();

		$record->accept(
			IdentityQueue::CHECK_DIGITS,
			'rf',
			'aaaaaaaabbbbbbbbcccccccc',
			$tier,
			IdentityAcceptance::REASON_NEVER_SUPPLIED,
			7
		);

		return $record;
	}

	// =====================================================================
	// What is refused before it is stored
	// =====================================================================

	/**
	 * @dataProvider provide_incomplete
	 *
	 * @param string $check   The check.
	 * @param string $subject The subject.
	 * @param string $tier    The tier.
	 */
	public function test_an_unidentifiable_finding_is_refused( string $check, string $subject, string $tier ): void {
		$result = ( new IdentityAcceptance() )->accept( $check, 'rf', $subject, $tier, IdentityAcceptance::REASON_NEVER_SUPPLIED, 7 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'identity_accept_incomplete', $result->get_error_code() );
		$this->assertSame( array(), $this->options, 'Nothing may be stored for a finding that cannot be named.' );
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function provide_incomplete(): array {
		return array(
			'no check'   => array( '', 'a-hash', IdentityQueue::TIER_ISOLATED ),
			'no subject' => array( IdentityQueue::CHECK_DIGITS, '', IdentityQueue::TIER_ISOLATED ),
			'no tier'    => array( IdentityQueue::CHECK_DIGITS, 'a-hash', '' ),
		);
	}

	/**
	 * An identifier the repair service does not manage is refused.
	 *
	 * Stored, it would be a suppression keyed on a field no finding carries --
	 * invisible and permanent. A refusal the operator reads is the whole point.
	 */
	public function test_an_unknown_identifier_is_refused(): void {
		$result = ( new IdentityAcceptance() )->accept(
			IdentityQueue::CHECK_DIGITS,
			'email',
			'a-hash',
			IdentityQueue::TIER_ISOLATED,
			IdentityAcceptance::REASON_NEVER_SUPPLIED,
			7
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'identity_accept_unknown_field', $result->get_error_code() );
		$this->assertSame( array(), $this->options );
	}

	public function test_the_managed_identifiers_are_the_repair_service_s(): void {
		// Not a second list: the store validates against `IdentityRepair`, so a
		// field added there is accepted here without this test being touched.
		$this->assertContains( 'rf', IdentityRepair::FIELDS );
		$this->assertContains( 'cpf', IdentityRepair::FIELDS );
	}

	public function test_a_reason_outside_the_closed_set_is_refused(): void {
		$result = ( new IdentityAcceptance() )->accept(
			IdentityQueue::CHECK_DIGITS,
			'rf',
			'a-hash',
			IdentityQueue::TIER_ISOLATED,
			'because',
			7
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'identity_accept_no_reason', $result->get_error_code() );
	}

	/**
	 * The reasons are a closed set and carry no free text.
	 *
	 * The decision, not a simplification: a note is where an operator writes
	 * the number itself, and this option must never hold a plaintext CPF.
	 */
	public function test_the_reasons_are_closed(): void {
		$this->assertSame(
			array(
				IdentityAcceptance::REASON_NEVER_SUPPLIED,
				IdentityAcceptance::REASON_UNIDENTIFIABLE,
			),
			IdentityAcceptance::REASONS
		);
	}

	// =====================================================================
	// What is stored, and how it is keyed
	// =====================================================================

	public function test_accepting_records_the_decision(): void {
		$record = $this->with_one_accepted();

		$stored = $record->record( IdentityQueue::CHECK_DIGITS, 'rf', 'aaaaaaaabbbbbbbbcccccccc' );

		$this->assertIsArray( $stored );
		$this->assertSame( IdentityQueue::TIER_ISOLATED, $stored[ IdentityAcceptance::FIELD_TIER ] );
		$this->assertSame( IdentityAcceptance::REASON_NEVER_SUPPLIED, $stored[ IdentityAcceptance::FIELD_REASON ] );
		$this->assertSame( 7, $stored[ IdentityAcceptance::FIELD_BY ] );
		// `time()` is a PHP internal, so Patchwork cannot redefine it without a
		// `patchwork.json` entry -- and adding one to pin a clock would widen
		// that file for every test in the suite. The claim that matters is the
		// `CLAUDE.md` one anyway: the instant is unix seconds, not a formatted
		// string that drifts with the site's timezone.
		$this->assertIsInt( $stored[ IdentityAcceptance::FIELD_AT ] );
		$this->assertGreaterThan( 1700000000, $stored[ IdentityAcceptance::FIELD_AT ] );
	}

	/**
	 * THE KEY OMITS THE TIER, AND THAT IS THE RE-SURFACE MECHANISM.
	 *
	 * Keyed on the tier, an acceptance would evaporate on any shape change.
	 * Keyed without the tier stored at all, a finding that became one click
	 * from correct would stay buried. So the tier travels in the VALUE: the key
	 * finds the record, and the stored tier decides whether it still applies.
	 */
	public function test_the_key_names_the_check_the_field_and_the_subject_and_not_the_tier(): void {
		$key = IdentityAcceptance::key( IdentityQueue::CHECK_DIGITS, 'rf', 'a-hash' );

		$this->assertStringContainsString( IdentityQueue::CHECK_DIGITS, $key );
		$this->assertStringContainsString( 'rf', $key );
		$this->assertStringContainsString( 'a-hash', $key );

		foreach ( array( IdentityQueue::TIER_ISOLATED, IdentityQueue::TIER_DECISION, IdentityQueue::TIER_MAILBOX ) as $tier ) {
			$this->assertStringNotContainsString(
				$tier,
				$key,
				'A tier in the key would make an acceptance evaporate whenever the shape moved.'
			);
		}
	}

	/**
	 * The check is in the key because `subject` means two different things.
	 *
	 * `IdentityConflictQuery::grouped()` aliases whatever it grouped by as
	 * `subject`, so it is a hash in two checks and an account id in the third.
	 */
	public function test_the_same_subject_under_two_checks_is_two_records(): void {
		$record = new IdentityAcceptance();

		$record->accept( IdentityQueue::CHECK_DIGITS, 'rf', '41', IdentityQueue::TIER_ISOLATED, IdentityAcceptance::REASON_NEVER_SUPPLIED, 7 );
		$record->accept( IdentityQueue::CHECK_MULTIPLE, 'rf', '41', IdentityQueue::TIER_DECISION, IdentityAcceptance::REASON_UNIDENTIFIABLE, 7 );

		$this->assertCount( 2, $record->all() );
		$this->assertSame( IdentityQueue::TIER_ISOLATED, $record->accepted_tier( IdentityQueue::CHECK_DIGITS, 'rf', '41' ) );
		$this->assertSame( IdentityQueue::TIER_DECISION, $record->accepted_tier( IdentityQueue::CHECK_MULTIPLE, 'rf', '41' ) );
	}

	public function test_a_finding_that_is_not_accepted_has_no_tier(): void {
		$this->assertNull( ( new IdentityAcceptance() )->accepted_tier( IdentityQueue::CHECK_DIGITS, 'rf', 'nope' ) );
	}

	/**
	 * A record this release cannot read is DROPPED, never repaired.
	 *
	 * Dropping it returns its finding to the work list, which is the safe
	 * direction: the operator is asked again, rather than a suppression
	 * surviving on a shape nothing understands.
	 */
	public function test_an_unreadable_record_is_dropped(): void {
		$this->options[ IdentityAcceptance::OPTION ] = array(
			'broken'      => 'not an array',
			'incomplete'  => array( IdentityAcceptance::FIELD_SUBJECT => 'x' ),
			'readable-ok' => array(
				IdentityAcceptance::FIELD_SUBJECT => 'x',
				IdentityAcceptance::FIELD_CHECK   => IdentityQueue::CHECK_DIGITS,
			),
		);

		$all = ( new IdentityAcceptance() )->all();

		$this->assertSame( array( 'readable-ok' ), array_keys( $all ) );
	}

	public function test_an_option_that_is_not_an_array_reads_as_nothing_accepted(): void {
		$this->options[ IdentityAcceptance::OPTION ] = 'rubbish';

		$this->assertSame( array(), ( new IdentityAcceptance() )->all() );
	}

	public function test_the_newest_decision_is_listed_first(): void {
		$this->options[ IdentityAcceptance::OPTION ] = array(
			'old' => array(
				IdentityAcceptance::FIELD_SUBJECT => 'a',
				IdentityAcceptance::FIELD_CHECK   => IdentityQueue::CHECK_DIGITS,
				IdentityAcceptance::FIELD_AT      => 100,
			),
			'new' => array(
				IdentityAcceptance::FIELD_SUBJECT => 'b',
				IdentityAcceptance::FIELD_CHECK   => IdentityQueue::CHECK_DIGITS,
				IdentityAcceptance::FIELD_AT      => 900,
			),
		);

		$this->assertSame( array( 'new', 'old' ), array_keys( ( new IdentityAcceptance() )->all() ) );
	}

	// =====================================================================
	// Withdrawing
	// =====================================================================

	public function test_withdrawing_returns_the_finding_to_the_queue(): void {
		$record = $this->with_one_accepted();

		$result = $record->withdraw( IdentityQueue::CHECK_DIGITS, 'rf', 'aaaaaaaabbbbbbbbcccccccc', 7 );

		$this->assertTrue( $result );
		$this->assertSame( array(), $record->all() );
		$this->assertNull( $record->accepted_tier( IdentityQueue::CHECK_DIGITS, 'rf', 'aaaaaaaabbbbbbbbcccccccc' ) );
	}

	public function test_withdrawing_something_that_is_not_recorded_says_so(): void {
		$result = ( new IdentityAcceptance() )->withdraw( IdentityQueue::CHECK_DIGITS, 'rf', 'never', 7 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'identity_accept_gone', $result->get_error_code() );
	}

	// =====================================================================
	// What the refusals read
	// =====================================================================

	/**
	 * Only the checks whose subject IS a hash are read.
	 *
	 * An account-side record carries a user id in the same field, so reading it
	 * here would answer "that account id is an accepted hash" -- which is how a
	 * refusal would come to excuse itself over the wrong thing.
	 */
	public function test_an_account_side_record_is_not_an_accepted_hash(): void {
		$record = new IdentityAcceptance();

		$record->accept( IdentityQueue::CHECK_MULTIPLE, 'rf', '41', IdentityQueue::TIER_DECISION, IdentityAcceptance::REASON_UNIDENTIFIABLE, 7 );

		$this->assertSame( array(), $record->accepted_hashes( 'rf' ) );
		$this->assertFalse( $record->any_accepted( 'rf', array( '41' ) ) );
	}

	public function test_a_hash_side_record_is_an_accepted_hash(): void {
		$record = $this->with_one_accepted();

		$this->assertSame( array( 'aaaaaaaabbbbbbbbcccccccc' => true ), $record->accepted_hashes( 'rf' ) );
		$this->assertTrue( $record->any_accepted( 'rf', array( 'other', 'aaaaaaaabbbbbbbbcccccccc' ) ) );
	}

	public function test_an_accepted_hash_belongs_to_its_own_identifier(): void {
		$record = $this->with_one_accepted();

		$this->assertSame( array(), $record->accepted_hashes( 'cpf' ) );
		$this->assertFalse( $record->any_accepted( 'cpf', array( 'aaaaaaaabbbbbbbbcccccccc' ) ) );
	}

	public function test_no_hashes_is_not_accepted(): void {
		$this->assertFalse( $this->with_one_accepted()->any_accepted( 'rf', array() ) );
	}

	// =====================================================================
	// The invariant that keeps this from reading as work done
	// =====================================================================

	/**
	 * ACCEPTING IS NEVER COUNTED AS RESOLVING.
	 *
	 * The screen's "resolved this sitting" counter is driven by
	 * `RESOLVED_ACTIONS`, and the whole premise of this feature is that an
	 * acceptance is not a resolution. Disjointness is the one-line statement of
	 * that, and it fails loudly if somebody ever adds one of these there.
	 */
	public function test_the_two_verbs_are_not_resolutions(): void {
		$this->assertSame(
			array(),
			array_intersect( IdentityAcceptance::NOT_RESOLUTIONS, IdentityResolutionPage::RESOLVED_ACTIONS ),
			'An accepted finding would be counted as work done.'
		);
		$this->assertNotEmpty( IdentityAcceptance::NOT_RESOLUTIONS );
	}

	/**
	 * The decision is written to the log, with a PREFIX and never the hash.
	 *
	 * The log is read by more people than the screen is, and the hash of a
	 * seven-digit number is not far from the number.
	 */
	public function test_the_decision_is_logged_without_the_whole_hash(): void {
		$record = $this->capturing();

		$record->accept(
			IdentityQueue::CHECK_DIGITS,
			'rf',
			'aaaaaaaabbbbbbbbcccccccc',
			IdentityQueue::TIER_ISOLATED,
			IdentityAcceptance::REASON_NEVER_SUPPLIED,
			7
		);

		$this->assertCount( 1, $record->written );
		$this->assertSame( IdentityAcceptance::LOG_ACCEPTED, $record->written[0]['action'] );
		$this->assertSame( 7, $record->written[0]['by'] );
		$this->assertSame( 'rf', $record->written[0]['field'] );
		$this->assertSame(
			substr( 'aaaaaaaabbbbbbbbcccccccc', 0, IdentityAcceptance::LOG_PREFIX ),
			$record->written[0]['subject'],
			'The log must carry a prefix, never the hash.'
		);
		$this->assertNotSame( 'aaaaaaaabbbbbbbbcccccccc', $record->written[0]['subject'] );
	}

	public function test_withdrawing_is_logged_too(): void {
		$record = $this->capturing();

		$record->accept( IdentityQueue::CHECK_DIGITS, 'rf', 'a-long-enough-hash-value', IdentityQueue::TIER_ISOLATED, IdentityAcceptance::REASON_NEVER_SUPPLIED, 7 );
		$record->withdraw( IdentityQueue::CHECK_DIGITS, 'rf', 'a-long-enough-hash-value', 9 );

		$this->assertCount( 2, $record->written );
		$this->assertSame( IdentityAcceptance::LOG_WITHDRAWN, $record->written[1]['action'] );
		$this->assertSame( 9, $record->written[1]['by'] );
		// The reason the record held, so the log says what was undone rather
		// than only that something was.
		$this->assertSame( IdentityAcceptance::REASON_NEVER_SUPPLIED, $record->written[1]['reason'] );
	}

	/**
	 * The key from a scan row: the rule three surfaces used to make each.
	 */
	public function test_a_row_is_keyed_by_its_check_field_and_subject(): void {
		$this->assertSame(
			IdentityAcceptance::key( IdentityQueue::CHECK_DIGITS, 'rf', 'hash-of-a-bad-rf' ),
			IdentityAcceptance::key_for_row(
				IdentityQueue::CHECK_DIGITS,
				array(
					'subject'           => 'hash-of-a-bad-rf',
					'identifier_column' => 'rf_hash',
				)
			),
			'A row must key exactly as the screen keyed the record it wrote, or an acceptance is unfindable.'
		);
	}

	/**
	 * `_hash` comes off the column, because the record is keyed by the FIELD.
	 *
	 * The query emits `cpf_hash` / `rf_hash`; the screen writes `cpf` / `rf`,
	 * which is the vocabulary `IdentityRepair::FIELDS` and every refusal
	 * speak. Keying on the column would make the two halves never meet.
	 */
	public function test_the_column_is_read_as_the_field_it_names(): void {
		$key = IdentityAcceptance::key_for_row(
			IdentityQueue::CHECK_SHARED,
			array(
				'subject'           => 'hash-of-a-shared-cpf',
				'identifier_column' => 'cpf_hash',
			)
		);

		$this->assertStringContainsString( IdentityQueue::KEY_SEPARATOR . 'cpf' . IdentityQueue::KEY_SEPARATOR, $key );
		$this->assertStringNotContainsString( 'cpf_hash', $key );
		$this->assertContains( 'cpf', IdentityRepair::FIELDS, 'The key speaks the repair vocabulary; this pins that they are the same word.' );
	}

	/**
	 * An account id is a subject too, and is not a hash.
	 */
	public function test_an_account_subject_keys_whole(): void {
		$this->assertSame(
			IdentityAcceptance::key( IdentityQueue::CHECK_MULTIPLE, 'rf', '438' ),
			IdentityAcceptance::key_for_row(
				IdentityQueue::CHECK_MULTIPLE,
				array(
					'subject'           => '438',
					'identifier_column' => 'rf_hash',
				)
			)
		);

		$this->assertNotContains(
			IdentityQueue::CHECK_MULTIPLE,
			IdentityAcceptance::HASH_SUBJECT_CHECKS,
			'This is the one check whose subject is an account, which is why the panel labels it rather than truncating it as a hash prefix.'
		);
	}

	/**
	 * A check with no card to accept from cannot be keyed at all.
	 *
	 * NOT THE SAME ANSWER AS "NOT ACCEPTED", which is why this returns an
	 * empty string rather than a key that could never be found: the CSV
	 * leaves the column blank here and prints `open` for a finding that is
	 * merely unjudged, and the audit card counts neither.
	 */
	public function test_a_check_outside_the_queue_cannot_be_keyed(): void {
		foreach ( array( 'unindexed_links', 'orphan_links', 'should_be_linked', 'multiple_identities' ) as $outside ) {
			$this->assertNotContains( $outside, IdentityQueue::CHECKS, "`{$outside}` is in the queue now -- this case is measuring nothing." );

			$this->assertSame(
				'',
				IdentityAcceptance::key_for_row(
					$outside,
					array(
						'subject'           => 'a-subject-it-really-carries',
						'identifier_column' => 'rf_hash',
					)
				),
				"`{$outside}` has no card to accept from, so a key for it could never be written and must not read as open."
			);
		}
	}

	/**
	 * A row with no subject cannot be keyed either.
	 *
	 * `should_be_linked` returns submissions with NO account, which is the
	 * whole finding; a key built from an empty subject would collide with
	 * every other subject-less row of the same check.
	 */
	public function test_a_row_without_a_subject_cannot_be_keyed(): void {
		$this->assertSame(
			'',
			IdentityAcceptance::key_for_row( IdentityQueue::CHECK_DIGITS, array( 'identifier_column' => 'rf_hash' ) )
		);

		$this->assertSame(
			'',
			IdentityAcceptance::key_for_row( IdentityQueue::CHECK_DIGITS, array( 'subject' => '' ) )
		);
	}

	/**
	 * The option is declared in the manifest the fresh-install gate enforces.
	 */
	public function test_the_option_is_in_the_uninstall_manifest(): void {
		$this->assertStringContainsString(
			"'" . IdentityAcceptance::OPTION . "'",
			(string) file_get_contents( dirname( __DIR__, 2 ) . '/uninstall.php' ),
			'An option absent from uninstall.php is a footprint nothing removes.'
		);
	}
}
