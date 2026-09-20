<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Maintenance\IdentityAuditExportSource;
use FreeFormCertificate\Maintenance\IdentityConflictQuery;

/**
 * The export with the schema probe stubbed out.
 *
 * `foreign_key_note()` reaches `information_schema` through the global
 * `$wpdb`, which a test about WHICH COLUMN a value lands in has no business
 * standing up -- and which returned `null` for every test in this file the
 * moment the note was added. The probe is driven for real by
 * {@see IdentityAuditExportSourceTest::test_the_foreign_key_note_reads_the_live_constraints()},
 * so stubbing it here hides nothing.
 */
class ExportSourceWithStubbedSchemaProbe extends IdentityAuditExportSource {

	/**
	 * What the stubbed probe answers.
	 *
	 * @var array<string, mixed>
	 */
	public array $fk_status = array(
		'total_constraints'    => 7,
		'existing_constraints' => 7,
		'is_complete'          => true,
		'existing'             => array(),
	);

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed>
	 */
	protected function foreign_key_status(): array {
		return $this->fk_status;
	}
}

/**
 * The link audit's CSV export (#1295).
 *
 * Every assertion here is on the ROWS the source hands the streamer, because
 * that is the artefact an operator opens. The auditor is injected, so what is
 * under test is the normalisation — seven checks return seven different column
 * sets onto one header — and never the queries, which have their own tests.
 *
 * @covers \FreeFormCertificate\Maintenance\IdentityAuditExportSource
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class IdentityAuditExportSourceTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		class_exists( '\\FreeFormCertificate\\Maintenance\\IdentityAuditExportSource' );

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * An auditor double that returns a fixed report and records the options it
	 * was called with.
	 *
	 * @param array<string, mixed> $checks Report checks.
	 * @param array<string, mixed> $seen   Filled with the options passed in.
	 * @return \FreeFormCertificate\Maintenance\MaintenanceToolInterface
	 */
	private function auditor( array $checks, array &$seen ) {
		$tool = Mockery::mock( '\\FreeFormCertificate\\Maintenance\\MaintenanceToolInterface' );
		$tool->shouldReceive( 'run' )->andReturnUsing(
			function ( array $options ) use ( $checks, &$seen ) {
				$seen = $options;
				return array( 'checks' => $checks, 'total' => 1 );
			}
		);
		return $tool;
	}

	/**
	 * Index the rendered rows by their header name, for readable assertions.
	 *
	 * @param IdentityAuditExportSource $source Source.
	 * @return array<int, array<string, string>>
	 */
	private function rows( IdentityAuditExportSource $source ): array {
		$header = $source->header();
		$out    = array();
		foreach ( $source->rows() as $row ) {
			$out[] = array_combine( $header, array_map( 'strval', $row ) );
		}
		return $out;
	}

	/**
	 * The export asks for its OWN cap, not the screen's sample.
	 *
	 * The screen's 50 is what makes the download pointless — it is the number
	 * the operator already has. If this regressed to the default, the CSV would
	 * duplicate the screen and the issue it closes would still be open.
	 */
	public function test_it_asks_the_auditor_for_the_export_cap(): void {
		$seen   = array();
		$source = new ExportSourceWithStubbedSchemaProbe( $this->auditor( array(), $seen ) );
		$this->rows( $source );

		$this->assertSame(
			IdentityAuditExportSource::EXPORT_LIMIT,
			$seen['limit'] ?? null,
			'The export must raise the per-check cap; the screen sample is what it exists to go beyond.'
		);
		$this->assertGreaterThan( 50, IdentityAuditExportSource::EXPORT_LIMIT );
	}

	/**
	 * `subject` means a hash in one cross-store check and a user id in the
	 * other, because `grouped()` aliases whatever it grouped BY.
	 *
	 * Reading it positionally puts a 64-character hash in the `user_id` column
	 * of a file somebody is about to filter by user id.
	 */
	public function test_subject_lands_in_the_column_its_check_means(): void {
		$seen   = array();
		$hash   = str_repeat( 'a', 64 );
		$source = new ExportSourceWithStubbedSchemaProbe(
			$this->auditor(
				array(
					'cross_store_shared_identities'   => array(
						'count'     => 1,
						'truncated' => false,
						'rows'      => array( array( 'subject' => $hash, IdentityConflictQuery::ALIAS_USER_COUNT => 2, 'identifier_column' => 'cpf_hash' ) ),
					),
					'cross_store_multiple_identities' => array(
						'count'     => 1,
						'truncated' => false,
						'rows'      => array( array( 'subject' => 77, IdentityConflictQuery::ALIAS_IDENTITY_COUNT => 3, 'identifier_column' => 'rf_hash' ) ),
					),
				),
				$seen
			)
		);

		$rows = $this->rows( $source );

		$this->assertSame( '', $rows[0]['user_ids'], 'A shared-identity row groups by HASH; its subject is not a user id.' );
		$this->assertSame( substr( $hash, 0, IdentityAuditExportSource::HASH_PREFIX_CHARS ), $rows[0]['identifier_hash_prefixes'] );
		$this->assertSame( '2', $rows[0]['related_count'] );

		$this->assertSame( '77', $rows[1]['user_ids'], 'A multiple-identity row groups by USER; its subject is the user id.' );
		$this->assertSame( '', $rows[1]['identifier_hash_prefixes'] );
		$this->assertSame( '3', $rows[1]['related_count'] );
	}

	/**
	 * The hash is a grouping prefix, never the whole value.
	 */
	public function test_the_hash_is_truncated_to_a_grouping_prefix(): void {
		$seen   = array();
		$hash   = str_repeat( 'b', 64 );
		$source = new ExportSourceWithStubbedSchemaProbe(
			$this->auditor(
				array(
					'shared_identities' => array(
						'count'     => 1,
						'truncated' => false,
						'rows'      => array( array( 'cpf_hash' => $hash, IdentityConflictQuery::ALIAS_USER_COUNT => 2 ) ),
					),
				),
				$seen
			)
		);

		$prefix = $this->rows( $source )[0]['identifier_hash_prefixes'];

		$this->assertSame( IdentityAuditExportSource::HASH_PREFIX_CHARS, strlen( $prefix ) );
		$this->assertNotSame( $hash, $prefix, 'The full hash must not reach the file.' );
	}

	/**
	 * A shared row names the accounts that share the identifier.
	 *
	 * The finding said an RF belongs to two accounts and withheld which two,
	 * so a merge had nowhere to start. Measured on the production export of
	 * 2026-09-19: all 13 shared rows carried an empty account column (#1344).
	 */
	public function test_a_shared_row_names_the_accounts(): void {
		$seen   = array();
		$source = new ExportSourceWithStubbedSchemaProbe(
			$this->auditor(
				array(
					'cross_store_shared_identities' => array(
						'count'     => 1,
						'truncated' => false,
						'rows'      => array(
							array(
								'subject'                                => str_repeat( 'a', 64 ),
								IdentityConflictQuery::ALIAS_USER_COUNT  => 2,
								IdentityConflictQuery::COLUMN_RELATED    => '85|107',
								'identifier_column'                      => 'cpf_hash',
							),
						),
					),
				),
				$seen
			)
		);

		$this->assertSame( '85|107', $this->rows( $source )[0]['user_ids'] );
	}

	/**
	 * A multiple row names the identifiers the account holds — truncated.
	 *
	 * The mirror gap: the row said an account holds eleven RFs and named none
	 * of them, so there was nothing to choose between. The values are hashes,
	 * so each one is cut to the grouping prefix on the way out — the export's
	 * standing rule, which a LIST must not quietly escape.
	 */
	public function test_a_multiple_row_names_the_identifiers_and_prefixes_each(): void {
		$seen   = array();
		$first  = str_repeat( 'a', 64 );
		$second = str_repeat( 'b', 64 );
		$source = new ExportSourceWithStubbedSchemaProbe(
			$this->auditor(
				array(
					'cross_store_multiple_identities' => array(
						'count'     => 1,
						'truncated' => false,
						'rows'      => array(
							array(
								'subject'                                   => 438,
								IdentityConflictQuery::ALIAS_IDENTITY_COUNT => 2,
								IdentityConflictQuery::COLUMN_RELATED       => $first . '|' . $second,
								'identifier_column'                         => 'rf_hash',
							),
						),
					),
				),
				$seen
			)
		);

		$row  = $this->rows( $source )[0];
		$cut  = IdentityAuditExportSource::HASH_PREFIX_CHARS;
		$want = substr( $first, 0, $cut ) . '|' . substr( $second, 0, $cut );

		$this->assertSame( '438', $row['user_ids'] );
		$this->assertSame( $want, $row['identifier_hash_prefixes'] );
		$this->assertStringNotContainsString( $first, $row['identifier_hash_prefixes'], 'No full hash reaches the file, in a list either.' );
	}

	/**
	 * A row with no list names one value, not one blank one.
	 *
	 * `explode()` on an empty string returns a one-element array holding the
	 * empty string, so a naive split would make every listless row report a
	 * value it does not have.
	 */
	public function test_a_row_without_a_list_stays_empty(): void {
		$seen   = array();
		$source = new ExportSourceWithStubbedSchemaProbe(
			$this->auditor(
				array(
					'unindexed_links' => array(
						'count'     => 1,
						'truncated' => false,
						'rows'      => array( array( 'user_id' => 12, 'identifier_column' => 'rf_hash', 'stores' => 'submissions' ) ),
					),
				),
				$seen
			)
		);

		$row = $this->rows( $source )[0];

		$this->assertSame( '12', $row['user_ids'] );
		$this->assertSame( '', $row['identifier_hash_prefixes'] );
	}

	/**
	 * A truncated list is declared in the row, never printed short.
	 *
	 * The export already refuses to let its own row cap pass silently; a
	 * `GROUP_CONCAT` that dropped its tail is the same defect one level down,
	 * and the note is where an operator sees it (#1344).
	 */
	public function test_a_truncated_list_is_declared_in_the_note(): void {
		$seen   = array();
		$source = new ExportSourceWithStubbedSchemaProbe(
			$this->auditor(
				array(
					'cross_store_multiple_identities' => array(
						'count'     => 1,
						'truncated' => false,
						'rows'      => array(
							array(
								'subject'                                      => 438,
								IdentityConflictQuery::ALIAS_IDENTITY_COUNT    => 11,
								IdentityConflictQuery::COLUMN_RELATED          => 'aaaa|bbbb',
								IdentityConflictQuery::COLUMN_RELATED_TRUNCATED => true,
								'identifier_column'                            => 'rf_hash',
							),
						),
					),
				),
				$seen
			)
		);

		$this->assertStringContainsString( 'INCOMPLETE', $this->rows( $source )[0]['note'] );
	}

	/**
	 * The two-count check lists the column it decided to report.
	 *
	 * It carries one list per column, so a row that picked `cpf_hash` by count
	 * and then printed the RF list would name one column and list the other's
	 * — worse than the blank column it replaces, because it looks answered.
	 */
	public function test_the_two_count_check_lists_the_column_it_picked(): void {
		$seen   = array();
		$source = new ExportSourceWithStubbedSchemaProbe(
			$this->auditor(
				array(
					'multiple_identities' => array(
						'count'     => 1,
						'truncated' => false,
						'rows'      => array(
							array(
								'user_id'     => 5,
								'cpf_count'   => 1,
								'rf_count'    => 3,
								'cpf_related' => str_repeat( 'c', 64 ),
								'rf_related'  => str_repeat( 'r', 64 ),
							),
						),
					),
				),
				$seen
			)
		);

		$row = $this->rows( $source )[0];

		$this->assertSame( 'rf_hash', $row['identifier_column'] );
		$this->assertSame( str_repeat( 'r', IdentityAuditExportSource::HASH_PREFIX_CHARS ), $row['identifier_hash_prefixes'] );
	}

	/**
	 * What the addresses said about the account travels to its own column.
	 *
	 * A machine value rather than prose, so an operator can filter the file by
	 * it — which is how the measurement this issue asks for gets taken at all
	 * (#1345).
	 */
	public function test_the_email_verdict_travels_to_its_own_column(): void {
		$seen   = array();
		$source = new ExportSourceWithStubbedSchemaProbe(
			$this->auditor(
				array(
					'cross_store_multiple_identities' => array(
						'count'     => 1,
						'truncated' => false,
						'rows'      => array(
							array(
								'subject' => 438,
								IdentityConflictQuery::ALIAS_IDENTITY_COUNT => 2,
								IdentityConflictQuery::COLUMN_EMAIL_VERDICT => IdentityConflictQuery::VERDICT_DISTINCT_EMAILS,
								'identifier_column' => 'rf_hash',
							),
						),
					),
				),
				$seen
			)
		);

		$this->assertSame(
			IdentityConflictQuery::VERDICT_DISTINCT_EMAILS,
			$this->rows( $source )[0]['email_verdict']
		);
	}

	/**
	 * A check that cannot have a verdict leaves the column empty, not absent.
	 *
	 * A row narrower than the header is a broken CSV, so "no verdict" has to
	 * be an empty cell rather than a missing one.
	 */
	public function test_a_check_without_a_verdict_leaves_the_column_empty(): void {
		$seen   = array();
		$source = new ExportSourceWithStubbedSchemaProbe(
			$this->auditor(
				array(
					'cross_store_shared_identities' => array(
						'count'     => 1,
						'truncated' => false,
						'rows'      => array(
							array(
								'subject' => str_repeat( 'a', 64 ),
								IdentityConflictQuery::ALIAS_USER_COUNT => 2,
								'identifier_column' => 'cpf_hash',
							),
						),
					),
				),
				$seen
			)
		);

		$row = $this->rows( $source )[0];

		$this->assertArrayHasKey( 'email_verdict', $row );
		$this->assertSame( '', $row['email_verdict'] );
	}

	/**
	 * EVERY row is exactly as wide as the header.
	 *
	 * `note_row()` exists because a row whose width drifts from the header is
	 * a broken CSV and hand-counting empty strings is how that drift happens —
	 * and the one row that was hand-counted, the tool-unavailable fallback,
	 * was ALREADY one column short before #1345 widened the header. It went
	 * through `note_row()` with this test, so the next column added cannot
	 * reintroduce it.
	 *
	 * `array_combine()` in `rows()` would itself fail on a mismatch, so this
	 * asserts the width directly on the raw rows instead.
	 */
	public function test_every_row_is_as_wide_as_the_header(): void {
		$seen   = array();
		$source = new ExportSourceWithStubbedSchemaProbe(
			$this->auditor(
				array(
					'cross_store_multiple_identities' => array(
						'count'     => 1,
						'truncated' => true,
						'rows'      => array(
							array(
								'subject' => 438,
								IdentityConflictQuery::ALIAS_IDENTITY_COUNT => 2,
								'identifier_column' => 'rf_hash',
							),
						),
					),
				),
				$seen
			)
		);

		$width = count( $source->header() );
		$rows  = iterator_to_array( ( function () use ( $source ) {
			yield from $source->rows();
		} )() );

		// A data row, the cap's note row and the closing foreign-key note, so
		// the assertion covers every shape this class emits.
		$this->assertCount( 3, $rows, 'Expected one finding, the truncation note and the foreign-key note.' );

		foreach ( $rows as $index => $row ) {
			$this->assertCount( $width, $row, "Row {$index} is not as wide as the header." );
		}
	}

	/**
	 * …and including the fallback row, which is the one that was wrong.
	 *
	 * It is the only row the class ever wrote out by hand, and it had drifted
	 * a column behind the header before anyone widened it. Reaching it needs
	 * the registry to hand back something that is not a tool, which is why
	 * this alias-mocks the registry rather than injecting — the constructor is
	 * typed, so a non-tool cannot be passed in.
	 */
	public function test_the_tool_unavailable_row_is_as_wide_as_the_header(): void {
		$registry = Mockery::mock( 'alias:\\FreeFormCertificate\\Maintenance\\MaintenanceToolRegistry' );
		$registry->shouldReceive( 'create_default' )->andReturnSelf();
		$registry->shouldReceive( 'get' )->andReturn( null );

		$source = new ExportSourceWithStubbedSchemaProbe();
		$rows   = $source->rows();

		foreach ( $rows as $row ) {
			$this->assertCount( count( $source->header() ), $row );
		}

		$this->assertCount( 1, (array) $rows, 'The fallback emits exactly one row.' );
	}

	/**
	 * …including the empty report, whose only row is a note.
	 */
	public function test_the_empty_report_row_is_as_wide_as_the_header(): void {
		$seen   = array();
		$source = new ExportSourceWithStubbedSchemaProbe( $this->auditor( array(), $seen ) );

		foreach ( $source->rows() as $row ) {
			$this->assertCount( count( $source->header() ), $row );
		}
	}

	/**
	 * `multiple_identities` carries two counts, and a row saying "2" without
	 * saying two of WHAT is not a lead anybody can act on.
	 */
	public function test_the_two_count_check_names_the_column_it_reports(): void {
		$seen   = array();
		$source = new ExportSourceWithStubbedSchemaProbe(
			$this->auditor(
				array(
					'multiple_identities' => array(
						'count'     => 2,
						'truncated' => false,
						'rows'      => array(
							array( 'user_id' => 5, 'cpf_count' => 3, 'rf_count' => 1 ),
							array( 'user_id' => 6, 'cpf_count' => 1, 'rf_count' => 4 ),
						),
					),
				),
				$seen
			)
		);

		$rows = $this->rows( $source );

		$this->assertSame( array( '3', 'cpf_hash' ), array( $rows[0]['related_count'], $rows[0]['identifier_column'] ) );
		$this->assertSame( array( '4', 'rf_hash' ), array( $rows[1]['related_count'], $rows[1]['identifier_column'] ) );
	}

	/**
	 * A capped list must never read as a complete one.
	 *
	 * This is the export's half of the rule the schema gates state as "never
	 * count as clean what it did not look at": the operator acting on this file
	 * has to know when it is partial.
	 */
	public function test_a_truncated_check_says_so_in_the_file(): void {
		$seen   = array();
		$source = new ExportSourceWithStubbedSchemaProbe(
			$this->auditor(
				array(
					'unindexed_links' => array(
						'count'     => 1,
						'truncated' => true,
						'rows'      => array( array( 'user_id' => 9, 'identifier_column' => 'cpf_hash' ) ),
					),
				),
				$seen
			)
		);

		$rows = $this->rows( $source );

		$this->assertCount( 3, $rows, 'A truncated check emits its finding, the truncation note and the closing foreign-key note.' );
		$this->assertStringContainsString( 'TRUNCATED', $rows[1]['note'] );
		$this->assertStringContainsString( 'FOREIGN KEYS', $rows[2]['note'] );
		$this->assertSame( 'unindexed_links', $rows[1]['check'], 'The note has to name which check was cut.' );
	}

	/**
	 * A clean install still produces a file that says something.
	 */
	public function test_an_empty_report_still_says_so(): void {
		$seen   = array();
		$source = new ExportSourceWithStubbedSchemaProbe( $this->auditor( array(), $seen ) );

		$rows = $this->rows( $source );

		// Two rows, not one: a file that says nothing is wrong AND that every
		// constraint is in place says something the first line alone does not
		// -- which is why the foreign-key note is unconditional.
		$this->assertCount( 2, $rows );
		$this->assertStringContainsString( 'No link problems found', $rows[0]['note'] );
		$this->assertStringContainsString( 'FOREIGN KEYS', $rows[1]['note'] );
	}

	/**
	 * The three account columns carry what the auditor worked out, and are
	 * POSITIONAL against `user_ids`.
	 *
	 * The whole point of #1354 is that an operator can act on a finding, and
	 * acting means knowing which of the ids is still an account, how much each
	 * owns, and where to click. Misaligning any of the three by one slot
	 * answers about the wrong person.
	 */
	public function test_the_account_columns_line_up_with_the_ids(): void {
		Functions\when( 'admin_url' )->alias(
			static function ( $path ) {
				return 'https://example.test/wp-admin/' . $path;
			}
		);

		$seen   = array();
		$source = new ExportSourceWithStubbedSchemaProbe(
			$this->auditor(
				array(
					'cross_store_shared_identities' => array(
						'count'     => 1,
						'truncated' => false,
						'rows'      => array(
							array(
								'subject'           => 'hash-of-one-cpf',
								IdentityConflictQuery::ALIAS_USER_COUNT => 2,
								IdentityConflictQuery::COLUMN_RELATED   => '355|5276',
								'identifier_column' => 'cpf_hash',
								IdentityConflictQuery::COLUMN_ACCOUNT_STATUS => 'exists|missing',
								IdentityConflictQuery::COLUMN_ACCOUNT_ROWS   => '355=submissions:3,user_profiles:1|5276=',
							),
						),
					),
				),
				$seen
			)
		);

		$rows = $this->rows( $source );

		$this->assertSame( '355|5276', $rows[0]['user_ids'] );
		$this->assertSame( 'exists|missing', $rows[0]['account_status'] );
		$this->assertSame( '355=submissions:3,user_profiles:1|5276=', $rows[0]['account_rows'] );

		// A link to a deleted user is a 404 dressed as a lead, so the second
		// slot is EMPTY rather than absent: dropping it would shift every
		// later URL onto the wrong account.
		$this->assertSame(
			'https://example.test/wp-admin/user-edit.php?user_id=355|',
			$rows[0]['account_urls']
		);
	}

	/**
	 * A row from a check that names no account leaves all three columns empty
	 * rather than inventing a slot.
	 */
	public function test_a_check_without_accounts_leaves_the_columns_empty(): void {
		$seen   = array();
		$source = new ExportSourceWithStubbedSchemaProbe(
			$this->auditor(
				array(
					'should_be_linked' => array(
						'count'     => 1,
						'truncated' => false,
						'rows'      => array( array( 'id' => 4, 'form_id' => 2, 'cpf_hash' => 'abc' ) ),
					),
				),
				$seen
			)
		);

		$rows = $this->rows( $source );

		$this->assertSame( '', $rows[0]['account_status'] );
		$this->assertSame( '', $rows[0]['account_rows'] );
		$this->assertSame( '', $rows[0]['account_urls'] );
	}

	/**
	 * The closing note reads the LIVE constraints, not the option flag.
	 *
	 * `ffc_foreign_keys_db_version` records that the migration RAN, not that
	 * every `ALTER` inside it succeeded — and the difference is precisely the
	 * case this row exists to report. This test drives the real seam, so the
	 * 21 tests above that stub it hide nothing.
	 */
	public function test_the_foreign_key_note_reads_the_live_constraints(): void {
		Mockery::mock( 'alias:FreeFormCertificate\Migrations\MigrationForeignKeys' )
			->shouldReceive( 'get_status' )
			->once()
			->andReturn(
				array(
					'total_constraints'    => 7,
					'existing_constraints' => 7,
					'is_complete'          => true,
					'existing'             => array( 'fk_ffc_submissions_user' ),
				)
			);

		$seen   = array();
		$source = new IdentityAuditExportSource( $this->auditor( array(), $seen ) );

		$rows = $this->rows( $source );
		$last = end( $rows );

		$this->assertSame( 'foreign_keys', $last['check'] );
		$this->assertStringContainsString( 'all 7', $last['note'] );
	}

	/**
	 * An incomplete constraint set names the ones that ARE installed.
	 *
	 * Never the missing ones: the canonical list lives in
	 * `MigrationForeignKeys` and is not public, so restating it here would be
	 * a claim about a value another file owns — the kind that goes stale in
	 * silence while still reading as authoritative.
	 */
	public function test_an_incomplete_constraint_set_names_what_is_installed(): void {
		Mockery::mock( 'alias:FreeFormCertificate\Migrations\MigrationForeignKeys' )
			->shouldReceive( 'get_status' )
			->andReturn(
				array(
					'total_constraints'    => 7,
					'existing_constraints' => 2,
					'is_complete'          => false,
					'existing'             => array( 'fk_ffc_user_profiles_user', 'fk_ffc_submissions_user' ),
				)
			);

		$seen   = array();
		$source = new IdentityAuditExportSource( $this->auditor( array(), $seen ) );

		$rows = $this->rows( $source );
		$last = end( $rows );

		$this->assertStringContainsString( 'only 2 of 7', $last['note'] );
		$this->assertStringContainsString( 'fk_ffc_submissions_user, fk_ffc_user_profiles_user', $last['note'] );
	}

	/**
	 * No capability, no file — and the source runs its own gate rather than
	 * trusting whoever constructed it.
	 */
	public function test_authorize_refuses_without_the_danger_zone_capability(): void {
		Mockery::mock( 'alias:FreeFormCertificate\Core\Capabilities' )
			->shouldReceive( 'current_user_can_admin_or' )->andReturn( false );

		Functions\when( 'wp_die' )->alias(
			function () {
				throw new \RuntimeException( 'denied' );
			}
		);

		$this->expectException( \RuntimeException::class );
		( new ExportSourceWithStubbedSchemaProbe() )->authorize();
	}

	/**
	 * The capability is not the whole gate: every operator who can run the
	 * audit holds it, so the nonce is what ties the download to this request.
	 */
	public function test_authorize_refuses_without_the_nonce(): void {
		Mockery::mock( 'alias:FreeFormCertificate\Core\Capabilities' )
			->shouldReceive( 'current_user_can_admin_or' )->andReturn( true );
		Mockery::mock( 'alias:FreeFormCertificate\Core\RequestInput' )
			->shouldReceive( 'get_get_string' )->andReturn( 'wrong' );

		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		Functions\when( 'wp_die' )->alias(
			function () {
				throw new \RuntimeException( 'denied' );
			}
		);

		$this->expectException( \RuntimeException::class );
		( new ExportSourceWithStubbedSchemaProbe() )->authorize();
	}


	/**
	 * The count columns are read by CONSTANT, and this is the canary for the
	 * defect that shipped.
	 *
	 * The export looked for `identifier_count`; `IdentityConflictQuery` emits
	 * `identity_count`. Every cross-store row therefore reached the operator
	 * with an empty count, and the original test agreed with the bug because
	 * its own fixture carried the same invented name — asserting against the
	 * value the test supplies, which passes any checker.
	 *
	 * Hard-coding the strings HERE would reintroduce exactly that. The fixture
	 * takes them from the class that emits them, so a rename moves both sides
	 * or fails loudly.
	 */
	public function test_the_count_alias_comes_from_the_query_that_emits_it(): void {
		$seen   = array();
		$source = new ExportSourceWithStubbedSchemaProbe(
			$this->auditor(
				array(
					'cross_store_multiple_identities' => array(
						'count'     => 1,
						'truncated' => false,
						'rows'      => array( array( 'subject' => 4103, IdentityConflictQuery::ALIAS_IDENTITY_COUNT => 2, 'identifier_column' => 'cpf_hash' ) ),
					),
				),
				$seen
			)
		);

		$this->assertSame(
			'2',
			$this->rows( $source )[0]['related_count'],
			'A finding with no count is a row the operator cannot act on.'
		);
	}

	/**
	 * A finding says WHERE the rows are.
	 *
	 * "This account has two CPFs" leaves an operator to search certificates,
	 * appointments, candidacies and the index by hand. The store labels turn it
	 * into one place to look.
	 */
	public function test_a_finding_names_the_stores_it_was_found_in(): void {
		$seen   = array();
		$source = new ExportSourceWithStubbedSchemaProbe(
			$this->auditor(
				array(
					'cross_store_multiple_identities' => array(
						'count'     => 1,
						'truncated' => false,
						'rows'      => array(
							array(
								'subject'                             => 4103,
								IdentityConflictQuery::ALIAS_IDENTITY_COUNT => 2,
								'identifier_column'                   => 'cpf_hash',
								IdentityConflictQuery::COLUMN_STORES  => 'appointments|submissions',
							),
						),
					),
				),
				$seen
			)
		);

		$this->assertSame( 'appointments|submissions', $this->rows( $source )[0]['stores'] );
	}

	/**
	 * The filename carries a timestamp, so two exports on one day do not
	 * overwrite each other in the operator's downloads folder.
	 */
	public function test_filename_is_dated(): void {
		$this->assertMatchesRegularExpression(
			'/^ffc-identity-audit-\d{4}-\d{2}-\d{2}-\d{6}\.csv$/',
			( new ExportSourceWithStubbedSchemaProbe() )->filename()
		);
	}
}
