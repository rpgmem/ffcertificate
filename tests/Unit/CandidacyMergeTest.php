<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Maintenance\CandidacyMerge;

/**
 * Folding one person's mistyped candidacy into their sound one.
 *
 * The promise this class makes an operator is that a classification is never
 * deleted or altered by a correction of a document number -- only re-pointed.
 * So the refusals are driven by their exact condition, and the write is
 * asserted statement by statement: what reaches the classification table is
 * the new `candidate_id` and nothing else.
 *
 * @covers \FreeFormCertificate\Maintenance\CandidacyMerge
 */
class CandidacyMergeTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Candidacy rows by id.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $candidates = array();

	/**
	 * The overlapping classification `get_row` answers with, or null.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $overlap = null;

	/**
	 * PCD condition per candidacy id; a missing id reads as unreadable.
	 *
	 * @var array<int, bool>
	 */
	public array $pcd = array();

	/**
	 * Every write, in order, as `[verb, table, data, where]`.
	 *
	 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>, 3: array<string, mixed>}>
	 */
	private array $writes = array();

	/**
	 * Tables whose writes answer false.
	 *
	 * @var array<int, string>
	 */
	private array $refusing = array();

	/**
	 * Whether the staging table exists.
	 *
	 * @var bool
	 */
	private bool $staging = true;

	/**
	 * Set up Brain\Monkey and the $wpdb double.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->candidates = array();
		$this->overlap    = null;
		$this->pcd        = array();
		$this->writes     = array();
		$this->refusing   = array();
		$this->staging    = true;

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'current_time' )->justReturn( '2026-10-12 10:00:00' );

		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';

		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( $sql, ...$args ) {
				return array( 'sql' => $sql, 'args' => $args );
			}
		);

		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $prepared ) {
				$ids = array( (int) $prepared['args'][1], (int) $prepared['args'][2] );

				return array_values( array_intersect_key( $this->candidates, array_flip( $ids ) ) );
			}
		);

		$wpdb->shouldReceive( 'get_row' )->andReturnUsing( fn() => $this->overlap );

		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			fn( $prepared ) => $this->staging ? $prepared['args'][0] : null
		);

		$wpdb->shouldReceive( 'update' )->andReturnUsing(
			function ( $table, $data, $where ) {
				$this->writes[] = array( 'update', (string) $table, (array) $data, (array) $where );

				return in_array( (string) $table, $this->refusing, true ) ? false : 2;
			}
		);

		$wpdb->shouldReceive( 'delete' )->andReturnUsing(
			function ( $table, $where ) {
				$this->writes[] = array( 'delete', (string) $table, array(), (array) $where );

				return in_array( (string) $table, $this->refusing, true ) ? false : 1;
			}
		);

		$GLOBALS['wpdb'] = $wpdb;
	}

	/**
	 * Tear down Brain\Monkey.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A merge whose PCD reading the case steers.
	 *
	 * @return CandidacyMerge
	 */
	private function merge(): CandidacyMerge {
		return new class( $this ) extends CandidacyMerge {

			/** @var CandidacyMergeTest */
			private $test;

			/**
			 * @param CandidacyMergeTest $test The case.
			 */
			public function __construct( $test ) {
				$this->test = $test;
			}

			/**
			 * @param array<string, mixed> $row A candidacy row.
			 * @return bool|null
			 */
			protected function is_pcd( array $row ): ?bool {
				return $this->test->pcd[ (int) $row['id'] ] ?? null;
			}
		};
	}

	/**
	 * Two candidacies of account 398, both not PCD: the production shape.
	 *
	 * @param array<string, mixed> $from Overrides for the candidacy that goes.
	 * @param array<string, mixed> $into Overrides for the candidacy that stays.
	 * @return void
	 */
	private function pair( array $from = array(), array $into = array() ): void {
		$this->candidates[1] = array_merge(
			array(
				'id'              => 1,
				'user_id'         => 398,
				'cpf_encrypted'   => 'c-wrong',
				'cpf_hash'        => 'h-wrong',
				'rf_encrypted'    => null,
				'rf_hash'         => null,
				'email_encrypted' => null,
				'email_hash'      => null,
				'phone'           => null,
				'notes'           => null,
			),
			$from
		);
		$this->candidates[5] = array_merge(
			array(
				'id'              => 5,
				'user_id'         => 398,
				'cpf_encrypted'   => 'c-right',
				'cpf_hash'        => 'h-right',
				'rf_encrypted'    => null,
				'rf_hash'         => null,
				'email_encrypted' => 'e-right',
				'email_hash'      => 'eh-right',
				'phone'           => null,
				'notes'           => null,
			),
			$into
		);
		$this->pcd = array(
			1 => false,
			5 => false,
		);
	}

	/**
	 * Two candidacies on different notices merge.
	 */
	public function test_candidacies_on_different_notices_can_merge(): void {
		$this->pair();

		$this->assertTrue( $this->merge()->check( 1, 5, 'cpf' ) );
		$this->assertSame( array(), $this->writes, 'A check writes nothing.' );
	}

	/**
	 * Both classified on one notice, adjutancy and list: one standing per
	 * person there, so merging would mean deleting one. Refused, naming it.
	 */
	public function test_a_shared_notice_adjutancy_and_list_is_refused(): void {
		$this->pair();
		$this->overlap = array(
			'notice_id' => 12,
			'list_type' => 'definitive',
			'code'      => '6016.2026/0026032-4',
		);

		$result = $this->merge()->check( 1, 5, 'cpf' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_identity_candidacy_overlap', $result->get_error_code() );
		$this->assertStringContainsString( '6016.2026/0026032-4', $result->get_error_message() );
	}

	/**
	 * PCD belongs to the candidacy, so a different condition is refused:
	 * moving a classification would change what it competes under.
	 */
	public function test_a_different_pcd_condition_is_refused(): void {
		$this->pair();
		$this->pcd[1] = true;

		$result = $this->merge()->check( 1, 5, 'cpf' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_identity_candidacy_pcd', $result->get_error_code() );
	}

	/**
	 * An unreadable condition cannot be shown to agree, so it is refused too.
	 */
	public function test_an_unreadable_pcd_condition_is_refused(): void {
		$this->pair();
		unset( $this->pcd[1], $this->pcd[5] );

		$result = $this->merge()->check( 1, 5, 'cpf' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_identity_candidacy_pcd', $result->get_error_code() );
	}

	/**
	 * The OTHER identifier filled on both with different values is a finding
	 * of its own, not a gap to fill.
	 */
	public function test_a_conflicting_other_identifier_is_refused(): void {
		$this->pair( array( 'rf_hash' => 'rf-a' ), array( 'rf_hash' => 'rf-b' ) );

		$result = $this->merge()->check( 1, 5, 'cpf' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_identity_candidacy_identifier', $result->get_error_code() );
	}

	/**
	 * Two accounts are two logins: folding them is the account merge.
	 */
	public function test_candidacies_of_different_accounts_are_refused(): void {
		$this->pair( array( 'user_id' => 513 ) );

		$result = $this->merge()->check( 1, 5, 'cpf' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_identity_candidacy_accounts', $result->get_error_code() );
	}

	/**
	 * A candidacy that vanished between the list and the click is refused.
	 */
	public function test_a_missing_candidacy_is_refused(): void {
		$this->pair();
		unset( $this->candidates[5] );

		$check = $this->merge()->check( 1, 5, 'cpf' );
		$apply = $this->merge()->apply( 1, 5 );

		$this->assertSame( 'ffc_identity_candidacy_missing', $check->get_error_code() );
		$this->assertSame( 'ffc_identity_candidacy_missing', $apply->get_error_code() );
		$this->assertSame( array(), $this->writes );
	}

	/**
	 * THE PROMISE: a classification only changes candidacy.
	 *
	 * The statement that reaches the classification table carries the new
	 * `candidate_id` and no other column, so rank, score, status, preview
	 * status and list cannot move. The emptied candidacy is deleted BEFORE
	 * the survivor takes anything from it -- the unique keys' order.
	 */
	public function test_apply_moves_classifications_untouched_then_deletes_the_emptied_candidacy(): void {
		$this->pair();

		$result = $this->merge()->apply( 1, 5 );

		$this->assertSame( array( 'classifications' => 2 ), $result );
		$this->assertSame(
			array(
				array( 'update', 'wp_ffc_recruitment_classification', array( 'candidate_id' => 5 ), array( 'candidate_id' => 1 ) ),
				array( 'update', 'wp_ffc_recruitment_import_staging', array( 'candidate_id' => 5 ), array( 'candidate_id' => 1 ) ),
				array( 'delete', 'wp_ffc_recruitment_candidate', array(), array( 'id' => 1 ) ),
			),
			$this->writes,
			'Nothing gained, so the survivor is not written at all.'
		);
	}

	/**
	 * The survivor only gains what it lacks, never loses or overwrites, and
	 * keeps both notes.
	 */
	public function test_the_survivor_gains_only_what_it_lacks(): void {
		$this->pair(
			array(
				'rf_encrypted'    => 'r-cipher',
				'rf_hash'         => 'r-hash',
				'email_encrypted' => 'e-wrong',
				'email_hash'      => 'eh-wrong',
				'phone'           => '11999990000',
				'notes'           => 'Imported 2025.',
			),
			array( 'notes' => 'Confirmed by HR.' )
		);

		$this->merge()->apply( 1, 5 );

		$last = end( $this->writes );

		$this->assertSame( 'wp_ffc_recruitment_candidate', $last[1] );
		$this->assertSame( array( 'id' => 5 ), $last[3] );
		$this->assertSame(
			array(
				'rf_encrypted' => 'r-cipher',
				'rf_hash'      => 'r-hash',
				'phone'        => '11999990000',
				'notes'        => "Confirmed by HR.\n\nImported 2025.",
				'updated_at'   => '2026-10-12 10:00:00',
			),
			$last[2],
			'The survivor\'s own e-mail stays; the CPF is never touched.'
		);
	}

	/**
	 * Without a staging table there is nothing to re-point there.
	 */
	public function test_a_missing_staging_table_is_skipped(): void {
		$this->pair();
		$this->staging = false;

		$this->merge()->apply( 1, 5 );

		$this->assertNotContains( 'wp_ffc_recruitment_import_staging', array_column( $this->writes, 1 ) );
	}

	/**
	 * A refused write stops the merge before the candidacy is deleted, and
	 * says which store refused; the caller's transaction rolls it back.
	 */
	public function test_a_refused_classification_write_stops_before_the_delete(): void {
		$this->pair();
		$this->refusing = array( 'wp_ffc_recruitment_classification' );

		$result = $this->merge()->apply( 1, 5 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_identity_repair_failed', $result->get_error_code() );
		$this->assertNotContains( 'delete', array_column( $this->writes, 0 ) );
	}

	/**
	 * A refused delete is reported, not swallowed.
	 */
	public function test_a_refused_delete_is_reported(): void {
		$this->pair();
		$this->refusing = array( 'wp_ffc_recruitment_candidate' );

		$result = $this->merge()->apply( 1, 5 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_identity_repair_failed', $result->get_error_code() );
	}
}
