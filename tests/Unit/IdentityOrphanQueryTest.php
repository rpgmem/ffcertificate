<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Maintenance\IdentityOrphanQuery;

/**
 * Records with an identifier and no account (#1397 sprint 6).
 *
 * THE POPULATION IS WIDER THAN THE QUERY IT REPLACES, IN THREE DIRECTIONS,
 * AND EACH ONE IS DRIVEN HERE.
 *
 * `SubmissionReader::find_unlinked_with_matching_identity()` reads submissions
 * only, CPF only, and — by construction — only orphans whose CPF already sits
 * on a LINKED submission. The third is the one that matters: an orphan nobody
 * can be matched to can never be returned by it, and that is precisely the
 * population needing an account opened rather than a link made.
 *
 * @covers \FreeFormCertificate\Maintenance\IdentityOrphanQuery
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class IdentityOrphanQueryTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Rows each table answers with.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $rows = array();

	/**
	 * Accounts the identity index files under each hash.
	 *
	 * @var array<string, array<int, int>>
	 */
	private array $indexed = array();

	/**
	 * Statements the query composed, for asserting what it looked at.
	 *
	 * @var array<int, string>
	 */
	private array $statements = array();

	/**
	 * Set up Brain\Monkey and the `$wpdb` double.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		class_exists( '\FreeFormCertificate\Maintenance\IdentityOrphanQuery' );

		$this->rows       = array();
		$this->indexed    = array();
		$this->statements = array();

		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';

		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( $sql, ...$args ) {
				return array(
					'sql'  => $sql,
					'args' => $args,
				);
			}
		);

		// Every store exists.
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			static function ( $prepared ) {
				return $prepared['args'][0] ?? null;
			}
		);

		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $prepared ) {
				$table              = (string) ( $prepared['args'][0] ?? '' );
				$this->statements[] = (string) ( $prepared['sql'] ?? '' );

				return $this->rows[ $table ] ?? array();
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
	 * Teach a store its orphaned rows.
	 *
	 * @param string                           $table Unprefixed table.
	 * @param array<int, array<string, mixed>> $rows  The rows.
	 * @return void
	 */
	private function given( string $table, array $rows ): void {
		$this->rows[ 'wp_' . $table ] = $rows;
	}

	/**
	 * A query whose identity-index read is stubbed.
	 *
	 * @return IdentityOrphanQuery
	 */
	private function query(): IdentityOrphanQuery {
		return new class( $this ) extends IdentityOrphanQuery {

			/** @var IdentityOrphanQueryTest */
			private $test;

			/**
			 * @param IdentityOrphanQueryTest $test The case.
			 */
			public function __construct( $test ) {
				$this->test = $test;
			}

			/**
			 * @return \FreeFormCertificate\Repositories\UserProfileRepository
			 */
			protected function profiles(): \FreeFormCertificate\Repositories\UserProfileRepository {
				return $this->test->profiles_double();
			}
		};
	}

	/**
	 * A profile repository answering from the fixture.
	 *
	 * @return \FreeFormCertificate\Repositories\UserProfileRepository
	 */
	public function profiles_double(): \FreeFormCertificate\Repositories\UserProfileRepository {
		$double = Mockery::mock( \FreeFormCertificate\Repositories\UserProfileRepository::class );
		$double->shouldReceive( 'findUserIdsByHash' )->andReturnUsing(
			function ( $column, $hash ) {
				return $this->indexed[ $column . '|' . $hash ] ?? array();
			}
		);

		return $double;
	}

	/**
	 * Teach the index which account files a hash.
	 *
	 * @param string          $column   `cpf_hash` or `rf_hash`.
	 * @param string          $hash     The hash.
	 * @param array<int, int> $accounts The accounts.
	 * @return void
	 */
	private function indexed( string $column, string $hash, array $accounts ): void {
		$this->indexed[ $column . '|' . $hash ] = $accounts;
	}

	/**
	 * THE DIRECTION THE OLD QUERY COULD NOT REACH.
	 *
	 * An orphan whose identifier appears on no account at all is returned,
	 * and reported as naming none — that is the finding that needs an account
	 * opened, and it is exactly what an `EXISTS ( … user_id IS NOT NULL )`
	 * filter drops.
	 */
	public function test_it_returns_an_orphan_no_account_can_be_matched_to(): void {
		$this->given(
			'ffc_recruitment_candidate',
			array(
				array(
					'id'         => 7,
					'cpf_hash'   => 'cpfLonely',
					'rf_hash'    => '',
					'email_hash' => '',
					'name'       => 'Clarice Fontes Miranda',
				),
			)
		);

		$found = $this->query()->orphans();

		$this->assertCount( 1, $found );
		$this->assertSame( 'cpf', $found[0]['field'] );
		$this->assertSame( 'cpfLonely', $found[0]['hash'] );
		$this->assertSame( array(), $found[0]['accounts'], 'No account files it, and that is the finding.' );
		$this->assertSame( array( 'Clarice Fontes Miranda' ), $found[0]['names'] );
	}

	/**
	 * RF as well as CPF: an orphan carrying only an RF is a finding keyed on
	 * the RF, which the CPF-only query could not see at all.
	 */
	public function test_an_orphan_carrying_only_an_rf_is_found(): void {
		$this->given(
			'ffc_submissions',
			array(
				array(
					'id'         => 1,
					'cpf_hash'   => '',
					'rf_hash'    => 'rfOnly',
					'email_hash' => 'mail',
				),
			)
		);

		$found = $this->query()->orphans();

		$this->assertCount( 1, $found );
		$this->assertSame( 'rf', $found[0]['field'] );
		$this->assertSame( 'rfOnly', $found[0]['hash'] );
	}

	/**
	 * Every store, not only submissions — and a person's rows across them
	 * fold into ONE finding, because they are one decision.
	 */
	public function test_rows_in_three_stores_fold_into_one_finding(): void {
		$this->given(
			'ffc_submissions',
			array(
				array(
					'id'         => 1,
					'cpf_hash'   => 'cpfA',
					'rf_hash'    => '',
					'email_hash' => '',
				),
			)
		);
		$this->given(
			'ffc_self_scheduling_appointments',
			array(
				array(
					'id'         => 2,
					'cpf_hash'   => 'cpfA',
					'rf_hash'    => '',
					'email_hash' => '',
					'name'       => 'Clarice',
				),
			)
		);
		$this->given(
			'ffc_recruitment_candidate',
			array(
				array(
					'id'         => 3,
					'cpf_hash'   => 'cpfA',
					'rf_hash'    => 'rfA',
					'email_hash' => 'mailA',
					'name'       => 'Clarice F. Miranda',
				),
			)
		);

		$found = $this->query()->orphans();

		$this->assertCount( 1, $found );
		$this->assertSame( 3, $found[0]['rows'] );
		$this->assertCount( 3, $found[0]['stores'] );
		$this->assertSame( array( 2 ), $found[0]['stores']['wp_ffc_self_scheduling_appointments'] );
	}

	/**
	 * WHAT THE PERSON HAS IS THE UNION OVER THEIR ROWS, NOT ONE ROW'S.
	 *
	 * The submission carries all three; the candidacy carries the CPF alone.
	 * Reading one row would report two gaps that are not there — and the gap
	 * is what decides whether an account can be opened at all.
	 *
	 * THE ORDER OF THE FIXTURE IS THE TEST.
	 *
	 * The first version put the RICH row in the candidacy, which is read
	 * LAST — so "the last row wins" produced the same answer as the union and
	 * the test passed against the defect. It was caught by neutralising the
	 * union into an assignment and watching nothing fail. The poorer row is
	 * read last here, which is the only arrangement the two readings disagree
	 * on.
	 */
	public function test_what_it_carries_is_the_union_over_its_rows(): void {
		$this->given(
			'ffc_submissions',
			array(
				array(
					'id'         => 1,
					'cpf_hash'   => 'cpfA',
					'rf_hash'    => 'rfA',
					'email_hash' => 'mailA',
				),
			)
		);
		$this->given(
			'ffc_recruitment_candidate',
			array(
				array(
					'id'         => 3,
					'cpf_hash'   => 'cpfA',
					'rf_hash'    => '',
					'email_hash' => '',
					'name'       => '',
				),
			)
		);

		$found = $this->query()->orphans();

		$this->assertSame(
			array(
				'cpf'   => true,
				'rf'    => true,
				'email' => true,
			),
			$found[0]['has'],
			'A later, poorer row must not take away what an earlier one recorded.'
		);
	}

	/**
	 * A row carrying no identifier at all is not a finding: there is nothing
	 * to match it on and nothing to type, which is a different problem from a
	 * record whose account is missing.
	 */
	public function test_a_row_with_no_identifier_is_not_returned(): void {
		$this->given(
			'ffc_submissions',
			array(
				array(
					'id'         => 1,
					'cpf_hash'   => '',
					'rf_hash'    => '',
					'email_hash' => 'mailOnly',
				),
			)
		);

		$this->assertSame( array(), $this->query()->orphans() );
	}

	/**
	 * The accounts already filing the identifier are reported as EVIDENCE —
	 * the finding is returned either way, which is the whole widening.
	 */
	public function test_an_account_that_files_the_identifier_is_named(): void {
		$this->given(
			'ffc_submissions',
			array(
				array(
					'id'         => 1,
					'cpf_hash'   => 'cpfA',
					'rf_hash'    => '',
					'email_hash' => '',
				),
			)
		);
		$this->indexed( 'cpf_hash', 'cpfA', array( 513 ) );

		$found = $this->query()->orphans();

		$this->assertSame( array( 513 ), $found[0]['accounts'] );
	}

	/**
	 * More findings than the cap says so rather than trimming in silence —
	 * the rule the queue's own per-check cap follows.
	 */
	public function test_more_findings_than_the_cap_says_so(): void {
		$rows = array();

		foreach ( range( 1, 5 ) as $i ) {
			$rows[] = array(
				'id'         => $i,
				'cpf_hash'   => 'cpf' . $i,
				'rf_hash'    => '',
				'email_hash' => '',
			);
		}

		$this->given( 'ffc_submissions', $rows );

		$query = $this->query();
		$found = $query->orphans( 3 );

		$this->assertCount( 3, $found );
		$this->assertTrue( $query->capped() );
	}

	/**
	 * The two statements differ only in the `name` column, and the store
	 * without one is never asked for it — selecting a column `ffc_submissions`
	 * does not have is an error, not an empty value.
	 */
	public function test_the_store_without_a_name_column_is_not_asked_for_one(): void {
		$this->given( 'ffc_submissions', array() );
		$this->given( 'ffc_recruitment_candidate', array() );

		$this->query()->orphans();

		$this->assertNotEmpty( $this->statements, 'The scan read nothing, so it proves nothing.' );

		$named = array_values( array_filter( $this->statements, static fn( $sql ) => false !== strpos( $sql, ', name FROM' ) ) );
		$bare  = array_values( array_filter( $this->statements, static fn( $sql ) => false === strpos( $sql, ', name FROM' ) ) );

		$this->assertNotEmpty( $named, 'The stores that record a name must be asked for it.' );
		$this->assertNotEmpty( $bare, 'The store that does not must not be.' );
	}
}
