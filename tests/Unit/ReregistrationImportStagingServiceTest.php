<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Reregistration\ReregistrationImportStagingService;

/**
 * Tests for the reregistration CSV import's ingest phase (#1214 sprint 2).
 *
 * The decisions under test are the ones #1214 took explicitly, so each has a
 * test that fails if the decision is reversed: an unknown column is ignored
 * and reported, and a required field with no column at all refuses the file
 * before anything is staged.
 *
 * The three collaborators are alias mocks, so each test needs a process in
 * which the real class was never loaded — Mockery refuses an alias for a class
 * that already exists, and in the full suite something earlier loads all three.
 * Under `--filter` nothing does, which is why these passed filtered and errored
 * thirteen times in the full run. Same annotation every sibling that alias-mocks
 * `CustomFieldReader` carries.
 *
 * @covers \FreeFormCertificate\Reregistration\ReregistrationImportStagingService
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class ReregistrationImportStagingServiceTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $wpdb;

	/** @var list<array{sql: string, values: array<int, mixed>}> */
	private array $queries = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// pcov attribution preload (CLAUDE.md pcov gotcha).
		class_exists( '\FreeFormCertificate\Reregistration\ReregistrationImportStagingService' );

		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' )->makePartial();
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( $sql, ...$args ) {
				$this->queries[] = array(
					'sql'    => (string) $sql,
					'values' => ( 1 === count( $args ) && is_array( $args[0] ) ) ? $args[0] : $args,
				);
				return (string) $sql;
			}
		)->byDefault();
		$wpdb->shouldReceive( 'query' )->andReturn( 1 )->byDefault();
		$wpdb->shouldReceive( 'insert' )->andReturn( 1 )->byDefault();
		$wpdb->shouldReceive( 'delete' )->andReturn( 1 )->byDefault();
		$this->wpdb = $wpdb;

		Functions\when( 'wp_generate_uuid4' )->justReturn( 'job-uuid-0001' );
		Functions\when( 'wp_json_encode' )->alias(
			static fn( $v ) => json_encode( $v ) // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- the stub IS the alternative.
		);
	}

	protected function tearDown(): void {
		$this->queries = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Build a field definition row the way CustomFieldReader returns one.
	 *
	 * @param string $key      Field key.
	 * @param string $label    Field label.
	 * @param bool   $required Whether the field is required.
	 * @return object
	 */
	private function field( string $key, string $label, bool $required = false ): object {
		return (object) array(
			'id'          => (string) crc32( $key ),
			'field_key'   => $key,
			'field_label' => $label,
			'field_type'  => 'text',
			'is_required' => $required ? '1' : '0',
		);
	}

	/**
	 * Point CustomFieldReader and ReregistrationRepository at fixtures.
	 *
	 * @param list<object> $fields       Field definitions for the audience.
	 * @param list<int>    $audience_ids Audiences linked to the campaign.
	 * @return void
	 */
	private function with_campaign( array $fields, array $audience_ids = array( 7 ) ): void {
		$reader = Mockery::mock( 'alias:FreeFormCertificate\Reregistration\CustomFieldReader' );
		$reader->shouldReceive( 'get_by_audience_with_parents' )->andReturn( $fields );
		$reader->shouldReceive( 'validate_field_value' )->andReturnUsing(
			function ( object $field, $value ) {
				$key = (string) $field->field_key;
				if ( array_key_exists( $key, $this->field_validity ) && false === $this->field_validity[ $key ] ) {
					return new \WP_Error( 'field_invalid_number', 'bad' );
				}
				return true;
			}
		);

		$repo = Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationRepository' );
		$repo->shouldReceive( 'get_audience_ids' )->andReturn( $audience_ids );

		$sanitizer = Mockery::mock( 'alias:FreeFormCertificate\Core\DataSanitizer' );
		$sanitizer->shouldReceive( 'normalize_cpf_rf' )->andReturnUsing(
			static fn( string $v ) => (string) preg_replace( '/\D/', '', $v )
		);
	}

	/**
	 * Wire the validate phase's collaborators.
	 *
	 * @param array<string, int>    $resolves    cpf|rf|email => user id (0 = would create).
	 * @param array<int, ?object>   $submissions user id => seeded submission, or null.
	 * @param array<string, bool>   $valid       field key => whether validation passes.
	 * @return void
	 */
	private function with_validation( array $resolves, array $submissions = array(), array $valid = array() ): void {
		$encryption = Mockery::mock( 'alias:FreeFormCertificate\Core\Encryption' );
		$encryption->shouldReceive( 'hash' )->andReturnUsing( static fn( string $v ) => 'h:' . $v );

		$manager = Mockery::mock( 'alias:FreeFormCertificate\UserDashboard\UserManager' );
		$manager->shouldReceive( 'resolve_existing_user' )->andReturnUsing(
			static function ( ?string $cpf_hash, ?string $rf_hash, string $email ) use ( $resolves ): int {
				foreach ( array( $cpf_hash, $rf_hash ) as $hash ) {
					if ( null !== $hash && isset( $resolves[ $hash ] ) ) {
						return $resolves[ $hash ];
					}
				}
				return $resolves[ $email ] ?? 0;
			}
		);

		$reader = Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationSubmissionReader' );
		$reader->shouldReceive( 'get_by_reregistration_and_user' )->andReturnUsing(
			static fn( int $rereg_id, int $user_id ) => $submissions[ $user_id ] ?? null
		);

		$this->field_validity = $valid;
	}

	/** @var array<string, bool> */
	private array $field_validity = array();

	/** A seeded submission row. */
	private function submission( int $id, string $status ): object {
		return (object) array(
			'id'     => (string) $id,
			'status' => $status,
		);
	}

	/** The staging INSERT, or null when none ran. */
	private function staging_insert(): ?array {
		foreach ( $this->queries as $q ) {
			if ( str_contains( $q['sql'], 'ffc_reregistration_import_staging' ) && str_contains( $q['sql'], 'INSERT INTO' ) ) {
				return $q;
			}
		}
		return null;
	}

	// ==================================================================
	// map_header() — the decision: unknown column ignored and reported
	// ==================================================================

	public function test_header_matches_on_field_key_and_on_label(): void {
		$this->with_campaign(
			array(
				$this->field( 'nome_completo', 'Full name' ),
				$this->field( 'cpf', 'CPF' ),
			)
		);

		$map = ReregistrationImportStagingService::map_header( array( 'nome_completo', 'CPF' ), 7 );

		$this->assertSame( array( 0 => 'nome_completo', 1 => 'cpf' ), $map['mapped'] );
		$this->assertSame( array(), $map['ignored'] );
	}

	public function test_matching_ignores_case_and_surrounding_space(): void {
		$this->with_campaign( array( $this->field( 'cpf', 'CPF' ) ) );

		$map = ReregistrationImportStagingService::map_header( array( '  Cpf  ' ), 7 );

		$this->assertSame( array( 0 => 'cpf' ), $map['mapped'] );
	}

	/**
	 * The #1214 decision, stated as a test: a column matching no field is
	 * ignored and reported — never a reason to refuse the file. An operator's
	 * spreadsheet carries notes and working columns that are none of ours.
	 */
	public function test_unknown_column_is_ignored_and_reported(): void {
		$this->with_campaign( array( $this->field( 'cpf', 'CPF' ) ) );

		$map = ReregistrationImportStagingService::map_header( array( 'CPF', 'Observações do RH' ), 7 );

		$this->assertSame( array( 0 => 'cpf' ), $map['mapped'] );
		$this->assertSame( array( 'Observações do RH' ), $map['ignored'] );
	}

	/**
	 * Two columns claiming one field: the first wins and the second is
	 * reported like any other column we cannot place. Letting the later one
	 * overwrite would make the import depend on column order, which the
	 * operator has no reason to think matters.
	 */
	public function test_duplicate_column_for_one_field_keeps_the_first(): void {
		$this->with_campaign( array( $this->field( 'cpf', 'CPF' ) ) );

		$map = ReregistrationImportStagingService::map_header( array( 'cpf', 'CPF' ), 7 );

		$this->assertSame( array( 0 => 'cpf' ), $map['mapped'] );
		$this->assertSame( array( 'CPF' ), $map['ignored'] );
	}

	public function test_required_field_without_a_column_is_reported_by_the_map(): void {
		$this->with_campaign(
			array(
				$this->field( 'cpf', 'CPF' ),
				$this->field( 'nome_completo', 'Full name', true ),
			)
		);

		$map = ReregistrationImportStagingService::map_header( array( 'cpf' ), 7 );

		$this->assertSame( array( 'nome_completo' ), $map['missing_required'] );
	}

	// ==================================================================
	// ingest_job() — refusals
	// ==================================================================

	/**
	 * The #1214 decision: a required field with no column refuses the file
	 * before anything is staged. An absent COLUMN means every row would fail,
	 * so one line beats staging the file to say it once per row — and the
	 * outcome is the same all-or-nothing the issue asked for.
	 */
	public function test_required_column_absent_refuses_before_staging(): void {
		$this->with_campaign(
			array(
				$this->field( 'cpf', 'CPF' ),
				$this->field( 'nome_completo', 'Full name', true ),
			)
		);

		$result = ReregistrationImportStagingService::ingest_job( 1, 7, "cpf\n12345678901\n", 3 );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( array( 'rereg_import_required_column_absent' ), $result['errors'] );
		$this->assertSame( array( 'nome_completo' ), $result['missing_required'] );
		$this->assertNull( $this->staging_insert(), 'Nothing may be staged when a required column is absent.' );
	}

	public function test_audience_outside_the_campaign_is_refused(): void {
		$this->with_campaign( array( $this->field( 'cpf', 'CPF' ) ), array( 99 ) );

		$result = ReregistrationImportStagingService::ingest_job( 1, 7, "cpf\n123\n", 3 );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( array( 'rereg_import_audience_not_in_campaign' ), $result['errors'] );
	}

	public function test_a_header_matching_nothing_is_refused(): void {
		$this->with_campaign( array( $this->field( 'cpf', 'CPF' ) ) );

		$result = ReregistrationImportStagingService::ingest_job( 1, 7, "notes,more notes\na,b\n", 3 );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( array( 'rereg_import_no_column_matched' ), $result['errors'] );
	}

	public function test_a_header_with_no_body_rows_is_refused(): void {
		$this->with_campaign( array( $this->field( 'cpf', 'CPF' ) ) );

		$result = ReregistrationImportStagingService::ingest_job( 1, 7, "cpf\n", 3 );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( array( 'rereg_import_csv_has_no_rows' ), $result['errors'] );
	}

	// ==================================================================
	// ingest_job() — the staged row
	// ==================================================================

	public function test_the_row_is_staged_as_json_under_its_field_keys(): void {
		$this->with_campaign(
			array(
				$this->field( 'cpf', 'CPF' ),
				$this->field( 'nome_completo', 'Full name' ),
			)
		);

		$result = ReregistrationImportStagingService::ingest_job( 1, 7, "CPF,Full name\n123.456.789-01,Ada Lovelace\n", 3 );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 1, $result['total'] );
		$this->assertSame( 'job-uuid-0001', $result['job_id'] );

		$insert = $this->staging_insert();
		$this->assertNotNull( $insert );
		$payload = json_decode( (string) $insert['values'][5], true );
		$this->assertSame(
			array(
				'cpf'           => '123.456.789-01',
				'nome_completo' => 'Ada Lovelace',
			),
			$payload,
			'The payload keeps the operator\'s value verbatim; normalising belongs to the resolution columns.'
		);
	}

	/**
	 * The resolution columns are lifted out of the payload and normalised,
	 * because validation and promotion query on them. The payload keeps what
	 * the operator typed, so "this CPF is malformed" can quote it back.
	 */
	public function test_identifiers_are_lifted_out_and_normalised(): void {
		$this->with_campaign(
			array(
				$this->field( 'cpf', 'CPF' ),
				$this->field( 'rf', 'RF' ),
				$this->field( 'email', 'E-mail' ),
			)
		);

		ReregistrationImportStagingService::ingest_job( 1, 7, "CPF,RF,E-mail\n123.456.789-01,123.456-7,  Ada@Example.COM \n", 3 );

		$values = $this->staging_insert()['values'];
		$this->assertSame( '12345678901', $values[6], 'cpf_normalized' );
		$this->assertSame( '1234567', $values[7], 'rf_normalized' );
		$this->assertSame( 'ada@example.com', $values[8], 'email' );
	}

	/**
	 * `line_no` is the line in the file the operator opens; `row_no` counts
	 * body rows. They differ by the header, which is exactly the off-by-one
	 * an error message gets wrong.
	 */
	public function test_row_and_line_numbers_differ_by_the_header(): void {
		$this->with_campaign( array( $this->field( 'cpf', 'CPF' ) ) );

		ReregistrationImportStagingService::ingest_job( 1, 7, "cpf\n111\n222\n", 3 );

		$values = $this->staging_insert()['values'];
		// First row: row_no 1, line_no 2. Second row: row_no 2, line_no 3.
		$this->assertSame( 1, $values[1] );
		$this->assertSame( 2, $values[2] );
		$this->assertSame( 2, $values[11] );
		$this->assertSame( 3, $values[12] );
	}

	public function test_a_missing_cell_stages_as_empty_rather_than_shifting_the_row(): void {
		$this->with_campaign(
			array(
				$this->field( 'cpf', 'CPF' ),
				$this->field( 'rf', 'RF' ),
			)
		);

		// The second row is short: only one cell for two columns.
		ReregistrationImportStagingService::ingest_job( 1, 7, "cpf,rf\n111,222\n333\n", 3 );

		$values  = $this->staging_insert()['values'];
		$second  = json_decode( (string) $values[15], true );
		$this->assertSame( array( 'cpf' => '333', 'rf' => '' ), $second );
	}

	// ==================================================================
	// validate_job() — the all-or-nothing boundary (#1214)
	// ==================================================================

	/**
	 * Run validate over a fixed set of staged rows.
	 *
	 * @param list<array<string, string>> $staged Each row's cpf/rf/email/payload.
	 * @return array<string, mixed>
	 */
	private function run_validate( array $staged ): array {
		$this->wpdb->shouldReceive( 'get_row' )->andReturn(
			(object) array(
				'job_id'            => 'job-uuid-0001',
				'reregistration_id' => '1',
				'audience_id'       => '7',
				'status'            => 'ingested',
			)
		);

		$rows = array();
		foreach ( $staged as $i => $row ) {
			$rows[] = (object) array(
				'id'             => (string) ( $i + 1 ),
				'row_no'         => (string) ( $i + 1 ),
				'line_no'        => (string) ( $i + 2 ),
				'payload'        => $row['payload'] ?? '{}',
				'cpf_normalized' => $row['cpf'] ?? '',
				'rf_normalized'  => $row['rf'] ?? '',
				'email'          => $row['email'] ?? '',
			);
		}
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( $rows );

		$this->updates = array();
		$this->wpdb->shouldReceive( 'update' )->andReturnUsing(
			function ( $table, $data ) {
				$this->updates[] = $data;
				return 1;
			}
		);

		return ReregistrationImportStagingService::validate_job( 'job-uuid-0001' );
	}

	/** @var list<array<string, mixed>> */
	private array $updates = array();

	/** The row_status written for the Nth staged row (0-based). */
	private function row_status( int $index ): string {
		return (string) ( $this->updates[ $index ]['row_status'] ?? '' );
	}

	public function test_a_clean_job_validates_and_is_ready_to_promote(): void {
		$this->with_campaign( array( $this->field( 'cpf', 'CPF' ) ) );
		$this->with_validation( array( 'h:11111111111' => 42 ), array( 42 => $this->submission( 900, 'pending' ) ) );

		$result = $this->run_validate( array( array( 'cpf' => '11111111111', 'payload' => '{"cpf":"111"}' ) ) );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'validated', $result['status'] );
		$this->assertSame( 1, $result['ready'] );
		$this->assertSame( 0, $result['failed'] );
		$this->assertSame( 42, $this->updates[0]['user_id'] );
		$this->assertSame( 900, $this->updates[0]['submission_id'], 'The seeded submission is recorded so promote re-reads a decision.' );
	}

	/**
	 * The #1214 decision: one bad row blocks the whole job. Promotion is
	 * batched across requests and cannot roll back an earlier batch, so this
	 * boundary is the only place all-or-nothing can hold.
	 */
	public function test_one_invalid_row_blocks_the_entire_job(): void {
		$this->with_campaign( array( $this->field( 'cpf', 'CPF' ), $this->field( 'idade', 'Idade' ) ) );
		$this->with_validation(
			array( 'h:111' => 42, 'h:222' => 43 ),
			array( 42 => null, 43 => null ),
			array( 'idade' => false )
		);

		$result = $this->run_validate(
			array(
				array( 'cpf' => '111', 'payload' => '{"cpf":"111","idade":"x"}' ),
				array( 'cpf' => '222', 'payload' => '{"cpf":"222","idade":"y"}' ),
			)
		);

		$this->assertFalse( $result['ok'], 'A job with any failure must not be promotable.' );
		$this->assertSame( 'blocked', $result['status'] );
		$this->assertSame( 2, $result['failed'] );
		$this->assertSame( 'field_invalid_number:idade', $result['failures'][0]['error'] );
		$this->assertSame( 2, $result['failures'][0]['line'], 'The operator reads the line in the file, not the row ordinal.' );
	}

	/**
	 * The other half of the decision: "already submitted" is expected state,
	 * not a defect in the spreadsheet, so it skips its row and does NOT block.
	 * The person got there first and their own answers are theirs to keep.
	 */
	public function test_an_already_submitted_row_skips_without_blocking(): void {
		$this->with_campaign( array( $this->field( 'cpf', 'CPF' ) ) );
		$this->with_validation(
			array( 'h:111' => 42, 'h:222' => 43 ),
			array(
				42 => $this->submission( 900, 'approved' ),
				43 => $this->submission( 901, 'pending' ),
			)
		);

		$result = $this->run_validate(
			array(
				array( 'cpf' => '111', 'payload' => '{"cpf":"111"}' ),
				array( 'cpf' => '222', 'payload' => '{"cpf":"222"}' ),
			)
		);

		$this->assertTrue( $result['ok'], 'A skip is not a failure.' );
		$this->assertSame( 'validated', $result['status'] );
		$this->assertSame( 1, $result['skipped'] );
		$this->assertSame( 1, $result['ready'] );
		$this->assertSame( 'skipped', $this->row_status( 0 ) );
		$this->assertSame( 'ready', $this->row_status( 1 ) );
	}

	public function test_a_draft_submission_is_still_fillable(): void {
		$this->with_campaign( array( $this->field( 'cpf', 'CPF' ) ) );
		$this->with_validation( array( 'h:111' => 42 ), array( 42 => $this->submission( 900, 'draft' ) ) );

		$result = $this->run_validate( array( array( 'cpf' => '111', 'payload' => '{"cpf":"111"}' ) ) );

		$this->assertSame( 1, $result['ready'], 'A draft is the user having started, not having submitted.' );
	}

	/**
	 * Two rows resolving to one account is certainly wrong, and it is exactly
	 * how a shared institutional mailbox presents: e-mail matching binds every
	 * one of them to whichever account that address hits (#1295). Catching it
	 * here means the operator sees it before a single write.
	 */
	public function test_two_rows_resolving_to_one_user_block_the_job(): void {
		$this->with_campaign( array( $this->field( 'email', 'E-mail' ) ) );
		$this->with_validation( array( 'shared@unit.example' => 42 ), array( 42 => null ) );

		$result = $this->run_validate(
			array(
				array( 'email' => 'shared@unit.example', 'payload' => '{"email":"shared@unit.example"}' ),
				array( 'email' => 'shared@unit.example', 'payload' => '{"email":"shared@unit.example"}' ),
			)
		);

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 1, $result['failed'] );
		$this->assertSame( 'rereg_import_duplicate_identity:2', $result['failures'][0]['error'], 'The error names the line that claimed the user first.' );
		$this->assertSame( 3, $result['failures'][0]['line'] );
	}

	/**
	 * A row that matches nobody and carries no e-mail cannot be promoted:
	 * `wp_create_user` rejects an empty address, so it fails here rather than
	 * halfway through a batch.
	 */
	public function test_a_row_with_no_identifier_and_no_email_fails(): void {
		$this->with_campaign( array( $this->field( 'cpf', 'CPF' ) ) );
		$this->with_validation( array() );

		$result = $this->run_validate( array( array( 'cpf' => '', 'email' => '', 'payload' => '{}' ) ) );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'rereg_import_no_identifier', $result['failures'][0]['error'] );
	}

	/**
	 * A row for somebody who does not exist yet is valid — promotion creates
	 * them. Recording `user_id` as 0 is what lets the operator see, before any
	 * write, how many accounts the import would open.
	 */
	public function test_a_row_that_would_create_a_user_is_ready_and_visible(): void {
		$this->with_campaign( array( $this->field( 'email', 'E-mail' ) ) );
		$this->with_validation( array() );

		$result = $this->run_validate( array( array( 'email' => 'new@example.com', 'payload' => '{"email":"new@example.com"}' ) ) );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 1, $result['ready'] );
		$this->assertSame( 0, $this->updates[0]['user_id'], '0 means "promotion would create this one".' );
	}

	public function test_an_unknown_job_is_refused(): void {
		$this->with_campaign( array( $this->field( 'cpf', 'CPF' ) ) );
		$this->wpdb->shouldReceive( 'get_row' )->andReturn( null );

		$result = ReregistrationImportStagingService::validate_job( 'nope' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( array( 'rereg_import_job_not_found' ), $result['errors'] );
	}
}
