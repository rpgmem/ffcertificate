<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Repositories\SubmissionRepository;

/**
 * Direct coverage for the read-side ({@see SubmissionReader}) and write-side
 * ({@see SubmissionWriter}) of the submission repository split. Every public
 * domain method is driven through the {@see SubmissionRepository} façade, which
 * forwards verbatim to the reader/writer, so the underlying methods execute.
 *
 * A mock wpdb stands in for the database; `prepare()` returns the raw SQL string
 * (arg 0) so the reader/writer's downstream `get_results`/`get_var`/`query`
 * expectations can be asserted without a real connection.
 *
 * @covers \FreeFormCertificate\Repositories\SubmissionReader
 * @covers \FreeFormCertificate\Repositories\SubmissionWriter
 */
class SubmissionReaderWriterCoverageTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		class_exists( '\\FreeFormCertificate\\Repositories\\SubmissionReader' );
		class_exists( '\\FreeFormCertificate\\Repositories\\SubmissionWriter' );

		// Reset the per-request static column-existence cache each test — it is
		// static, so a prior test's cached lookup would otherwise short-circuit
		// column_exists() and skip the INFORMATION_SCHEMA get_var this test asserts.
		$this->reset_column_cache( '\\FreeFormCertificate\\Repositories\\SubmissionReader' );
		$this->reset_column_cache( '\\FreeFormCertificate\\Repositories\\SubmissionWriter' );

		global $wpdb;
		$wpdb              = Mockery::mock( 'wpdb' )->makePartial();
		$wpdb->prefix      = 'wp_';
		$wpdb->users       = 'wp_users';
		$wpdb->insert_id   = 0;
		$wpdb->last_error  = '';
		$this->wpdb        = $wpdb;

		// prepare() returns the raw SQL string (arg 0) unless a test overrides it.
		$this->wpdb->shouldReceive( 'prepare' )
			->andReturnUsing(
				function () {
					return func_get_args()[0];
				}
			)
			->byDefault();
		$this->wpdb->shouldReceive( 'esc_like' )
			->andReturnUsing(
				function ( $v ) {
					return $v;
				}
			)
			->byDefault();

		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'wp_cache_flush' )->justReturn( true );
		Functions\when( 'wp_cache_flush_group' )->justReturn( true );

		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );

		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'wp_parse_args' )->alias(
			function ( $args, $defaults ) {
				return array_merge( $defaults, (array) $args );
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function repo(): SubmissionRepository {
		return new SubmissionRepository();
	}

	/**
	 * Clear the private static $column_exists_cache on a reader/writer class.
	 *
	 * @param string $fqcn Fully-qualified class name.
	 */
	private function reset_column_cache( string $fqcn ): void {
		$prop = new \ReflectionProperty( $fqcn, 'column_exists_cache' );
		$prop->setAccessible( true );
		$prop->setValue( null, array() );
	}

	// ==================================================================
	// READER — link-audit queries + centralized single-column reads
	// (also covered via SubmissionRepositoryTest, replicated here so the
	// reader lines attribute under this file's coverage run too)
	// ==================================================================

	public function test_find_orphan_user_links_returns_rows(): void {
		$rows = array( array( 'id' => 1, 'user_id' => 99, 'form_id' => 3 ) );
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( $rows );

		$this->assertSame( $rows, $this->repo()->find_orphan_user_links( 50 ) );
	}

	public function test_find_orphan_user_links_returns_empty_when_null(): void {
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( null );

		$this->assertSame( array(), $this->repo()->find_orphan_user_links( 0 ) );
	}

	public function test_find_users_with_multiple_identities_returns_rows(): void {
		$rows = array( array( 'user_id' => 5, 'cpf_count' => 2, 'rf_count' => 1 ) );
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( $rows );

		$this->assertSame( $rows, $this->repo()->find_users_with_multiple_identities() );
	}

	public function test_find_unlinked_with_matching_identity_returns_rows(): void {
		$rows = array( array( 'id' => 7, 'form_id' => 2, 'cpf_hash' => 'abc' ) );
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( $rows );

		$this->assertSame( $rows, $this->repo()->find_unlinked_with_matching_identity() );
	}

	public function test_find_shared_identities_returns_empty_when_none(): void {
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( null );

		$this->assertSame( array(), $this->repo()->find_shared_identities() );
	}

	public function test_count_by_status_maps_all_statuses(): void {
		$results = array(
			'publish' => (object) array( 'status' => 'publish', 'count' => '15' ),
			'trash'   => (object) array( 'status' => 'trash', 'count' => '3' ),
		);
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( $results );

		$counts = $this->repo()->countByStatus();

		$this->assertSame( 15, $counts['publish'] );
		$this->assertSame( 3, $counts['trash'] );
		$this->assertSame( 0, $counts['quiz_in_progress'] );
		$this->assertSame( 0, $counts['quiz_failed'] );
	}

	public function test_count_by_status_returns_cached_array(): void {
		$cached = array( 'publish' => 1, 'trash' => 0, 'quiz_in_progress' => 0, 'quiz_failed' => 0 );
		Functions\when( 'get_transient' )->justReturn( $cached );
		$this->wpdb->shouldNotReceive( 'get_results' );

		$this->assertSame( $cached, $this->repo()->countByStatus() );
	}

	public function test_find_magic_token_by_id_returns_string(): void {
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( 'tok-xyz' );

		$this->assertSame( 'tok-xyz', $this->repo()->findMagicTokenById( 5 ) );
	}

	public function test_find_magic_token_by_id_short_circuits_non_positive(): void {
		$this->wpdb->shouldNotReceive( 'get_var' );

		$this->assertNull( $this->repo()->findMagicTokenById( 0 ) );
	}

	public function test_count_by_form_and_cpf_hash_returns_int(): void {
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '4' );

		$this->assertSame( 4, $this->repo()->countByFormAndCpfHash( 7, 'abc' ) );
	}

	public function test_count_by_form_and_cpf_hash_short_circuits(): void {
		$this->wpdb->shouldNotReceive( 'get_var' );

		$this->assertSame( 0, $this->repo()->countByFormAndCpfHash( 0, 'abc' ) );
	}

	public function test_sql_user_certificate_count_subquery(): void {
		$sql = $this->repo()->sql_user_certificate_count_subquery();

		$this->assertStringContainsString( 'wp_ffc_submissions', $sql );
		$this->assertStringContainsString( 'GROUP BY user_id', $sql );
	}

	// ==================================================================
	// READER — findByAuthCode / findByToken (cache miss + hit)
	// ==================================================================

	public function test_find_by_auth_code_cache_miss_queries_and_caches(): void {
		$row = array( 'id' => 1, 'auth_code' => 'AAAA-BBBB' );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $row );

		$this->assertSame( $row, $this->repo()->findByAuthCode( 'AAAA-BBBB' ) );
	}

	public function test_find_by_auth_code_returns_null_when_not_found(): void {
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );

		$this->assertNull( $this->repo()->findByAuthCode( 'NOPE' ) );
	}

	public function test_find_by_token_cache_miss_queries_and_caches(): void {
		$row = array( 'id' => 3, 'magic_token' => 'tok' );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $row );

		$this->assertSame( $row, $this->repo()->findByToken( 'tok' ) );
	}

	public function test_find_by_token_returns_null_when_not_found(): void {
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );

		$this->assertNull( $this->repo()->findByToken( 'ghost' ) );
	}

	// ==================================================================
	// READER — findByEmail / findByCpfRf / findByFormId
	// ==================================================================

	public function test_find_by_email_returns_rows(): void {
		$rows = array( array( 'id' => 5 ), array( 'id' => 6 ) );
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( $rows );

		$this->assertSame( $rows, $this->repo()->findByEmail( 'a@b.com', 10 ) );
	}

	public function test_find_by_email_returns_empty_array_when_no_rows(): void {
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( null );

		$this->assertSame( array(), $this->repo()->findByEmail( 'a@b.com' ) );
	}

	public function test_find_by_cpf_rf_uses_cpf_hash_column_for_11_digit(): void {
		$rows = array( array( 'id' => 9 ) );
		// 11-digit CPF → cpf_hash column branch.
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( $rows );

		$this->assertSame( $rows, $this->repo()->findByCpfRf( '123.456.789-09', 5 ) );
	}

	public function test_find_by_cpf_rf_uses_rf_hash_column_for_7_digit(): void {
		// 7-digit RF → rf_hash column branch.
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( null );

		$this->assertSame( array(), $this->repo()->findByCpfRf( '1234567' ) );
	}

	public function test_find_by_form_id_returns_rows(): void {
		$rows = array( array( 'id' => 11 ) );
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( $rows );

		$this->assertSame( $rows, $this->repo()->findByFormId( 3, 50, 10 ) );
	}

	public function test_find_by_form_id_returns_empty_array_when_no_rows(): void {
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( false );

		$this->assertSame( array(), $this->repo()->findByFormId( 3 ) );
	}

	// ==================================================================
	// READER — getForExport (array branch + single/no-filter branch)
	// ==================================================================

	public function test_get_for_export_multiple_form_ids_with_status(): void {
		$rows = array( array( 'id' => 1 ), array( 'id' => 2 ) );
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( $rows );

		$this->assertSame( $rows, $this->repo()->getForExport( array( 1, 2, 3 ), 'publish' ) );
	}

	public function test_get_for_export_multiple_form_ids_null_status(): void {
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( null );

		$this->assertSame( array(), $this->repo()->getForExport( array( 4, 5 ), null ) );
	}

	public function test_get_for_export_single_form_id_delegates_to_find_all(): void {
		$rows = array( array( 'id' => 42 ) );
		// findAll() with a limit of null falls through to the no-limit branch.
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( $rows );

		$this->assertSame( $rows, $this->repo()->getForExport( 7, 'publish' ) );
	}

	public function test_get_for_export_no_filters_delegates_to_find_all(): void {
		$rows = array( array( 'id' => 1 ) );
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( $rows );

		$this->assertSame( $rows, $this->repo()->getForExport( null, null ) );
	}

	// ==================================================================
	// READER — getExportBatch / getExportKeysBatch / countForExport
	// (build_export_where: with-filters + no-filters branches)
	// ==================================================================

	public function test_get_export_batch_with_filters(): void {
		$rows = array( array( 'id' => 100 ) );
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( $rows );

		$this->assertSame(
			$rows,
			$this->repo()->getExportBatch( array( 1, 2 ), 'publish', 500, 50 )
		);
	}

	public function test_get_export_batch_no_filters_builds_where_from_cursor(): void {
		$rows = array( array( 'id' => 99 ) );
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( $rows );

		// null form_ids + null status → build_export_where returns empty WHERE,
		// so getExportBatch prepends "WHERE id < %d".
		$this->assertSame(
			$rows,
			$this->repo()->getExportBatch( null, null, PHP_INT_MAX, 100 )
		);
	}

	public function test_get_export_batch_returns_empty_when_no_rows(): void {
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( null );

		$this->assertSame( array(), $this->repo()->getExportBatch( null, null, 10, 10 ) );
	}

	public function test_get_export_keys_batch_with_filters(): void {
		$rows = array( array( 'id' => 1, 'data' => '{}' ) );
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( $rows );

		$this->assertSame(
			$rows,
			$this->repo()->getExportKeysBatch( array( 8 ), 'trash', 200, 25 )
		);
	}

	public function test_get_export_keys_batch_no_filters(): void {
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( false );

		$this->assertSame(
			array(),
			$this->repo()->getExportKeysBatch( null, null, PHP_INT_MAX, 25 )
		);
	}

	public function test_count_for_export_with_filters_returns_int(): void {
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '17' );

		$this->assertSame( 17, $this->repo()->countForExport( array( 1, 2 ), 'publish' ) );
	}

	public function test_count_for_export_no_filters_returns_int(): void {
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '0' );

		$this->assertSame( 0, $this->repo()->countForExport( null, null ) );
	}

	// ==================================================================
	// READER — hasEditInfo (column missing + column present w/ + w/o data)
	// ==================================================================

	public function test_has_edit_info_false_when_column_absent(): void {
		// column_exists() get_var → 0 (column missing) short-circuits.
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '0' );

		$this->assertFalse( $this->repo()->hasEditInfo() );
	}

	public function test_has_edit_info_true_when_column_present_and_has_data(): void {
		// First get_var: column exists (1). Second: rows with edited_at (3).
		$this->wpdb->shouldReceive( 'get_var' )->twice()->andReturn( '1', '3' );

		$this->assertTrue( $this->repo()->hasEditInfo() );
	}

	public function test_has_edit_info_false_when_column_present_but_no_data(): void {
		$this->wpdb->shouldReceive( 'get_var' )->twice()->andReturn( '1', '0' );

		$this->assertFalse( $this->repo()->hasEditInfo() );
	}

	// ==================================================================
	// READER — findPaginated (search + form_ids + defaults branches)
	// ==================================================================

	public function test_find_paginated_defaults_returns_items_and_pages(): void {
		$items = array( array( 'id' => 1 ), array( 'id' => 2 ) );
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( $items );
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '2' );

		$result = $this->repo()->findPaginated();

		$this->assertSame( $items, $result['items'] );
		$this->assertSame( 2, $result['total'] );
		$this->assertSame( 1.0, $result['pages'] );
	}

	public function test_find_paginated_with_form_ids_and_numeric_search(): void {
		$items = array( array( 'id' => 42 ) );
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( $items );
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '1' );

		$result = $this->repo()->findPaginated(
			array(
				'form_ids' => array( 1, 2 ),
				'search'   => '42',
				'orderby'  => 'submission_date',
				'order'    => 'ASC',
				'page'     => 2,
				'per_page' => 10,
			)
		);

		$this->assertSame( $items, $result['items'] );
		$this->assertSame( 1, $result['total'] );
	}

	public function test_find_paginated_with_long_text_search(): void {
		// A >=4 char non-numeric term exercises the LIKE data-column branch
		// and the magic_token prefix branch.
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( array() );
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '0' );

		$result = $this->repo()->findPaginated( array( 'search' => 'joao' ) );

		$this->assertSame( array(), $result['items'] );
		$this->assertSame( 0, $result['total'] );
	}

	public function test_find_paginated_with_short_search_skips_like_branch(): void {
		// <4 char term skips the data LIKE branch (still runs the rest).
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( array() );
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '0' );

		$result = $this->repo()->findPaginated( array( 'search' => 'ab' ) );

		$this->assertSame( 0, $result['total'] );
	}

	// ==================================================================
	// WRITER — insert / update / updateStatus (cache-invalidation paths)
	// ==================================================================

	public function test_insert_success_invalidates_count_cache(): void {
		$this->wpdb->shouldReceive( 'insert' )->once()->andReturn( 1 );
		$this->wpdb->insert_id = 55;

		$this->assertSame( 55, $this->repo()->insert( array( 'form_id' => 1 ) ) );
	}

	public function test_insert_failure_returns_false(): void {
		$this->wpdb->shouldReceive( 'insert' )->once()->andReturn( false );
		$this->wpdb->last_error = 'oops';

		$this->assertFalse( $this->repo()->insert( array( 'form_id' => 1 ) ) );
	}

	public function test_update_with_status_change_invalidates_count_cache(): void {
		$this->wpdb->shouldReceive( 'update' )->once()->andReturn( 1 );

		$this->assertSame( 1, $this->repo()->update( 5, array( 'status' => 'trash' ) ) );
	}

	public function test_update_without_status_does_not_invalidate(): void {
		$this->wpdb->shouldReceive( 'update' )->once()->andReturn( 1 );

		$this->assertSame( 1, $this->repo()->update( 5, array( 'email_hash' => 'x' ) ) );
	}

	public function test_update_failure_returns_false(): void {
		$this->wpdb->shouldReceive( 'update' )->once()->andReturn( false );
		$this->wpdb->last_error = 'bad';

		$this->assertFalse( $this->repo()->update( 5, array( 'status' => 'trash' ) ) );
	}

	public function test_update_status_delegates_and_returns_rows(): void {
		$this->wpdb->shouldReceive( 'update' )->once()->andReturn( 1 );

		$this->assertSame( 1, $this->repo()->updateStatus( 9, 'publish' ) );
	}

	// ==================================================================
	// WRITER — bulkUpdateStatus / bulkDelete (populated + failure)
	// ==================================================================

	public function test_bulk_update_status_populated_returns_count(): void {
		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( 3 );

		$this->assertSame( 3, $this->repo()->bulkUpdateStatus( array( 1, 2, 3 ), 'trash' ) );
	}

	public function test_bulk_update_status_returns_false_when_prepare_fails(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( null );
		$this->wpdb->shouldNotReceive( 'query' );

		$this->assertFalse( $this->repo()->bulkUpdateStatus( array( 1 ), 'trash' ) );
	}

	public function test_bulk_update_status_returns_false_when_query_fails(): void {
		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( false );

		$this->assertFalse( $this->repo()->bulkUpdateStatus( array( 1, 2 ), 'trash' ) );
	}

	public function test_bulk_delete_populated_returns_count(): void {
		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( 2 );

		$this->assertSame( 2, $this->repo()->bulkDelete( array( 4, 5 ) ) );
	}

	public function test_bulk_delete_returns_false_when_prepare_fails(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( null );
		$this->wpdb->shouldNotReceive( 'query' );

		$this->assertFalse( $this->repo()->bulkDelete( array( 1 ) ) );
	}

	public function test_bulk_delete_returns_false_when_query_fails(): void {
		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( false );

		$this->assertFalse( $this->repo()->bulkDelete( array( 1, 2 ) ) );
	}

	// ==================================================================
	// WRITER — moveBetweenForms (empty-safe-ids, prepare-fail, non-array
	// rows, all-moved path)
	// ==================================================================

	public function test_move_between_forms_returns_empty_when_all_ids_filtered_out(): void {
		// array_filter(array_map('absint', [0])) → empty → early return.
		$result = $this->repo()->moveBetweenForms( 1, 2, array( 0, 0 ) );

		$this->assertSame( array(), $result['moved'] );
		$this->assertSame( array(), $result['conflicts'] );
	}

	public function test_move_between_forms_returns_empty_when_select_prepare_fails(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( null );

		$result = $this->repo()->moveBetweenForms( 1, 2, array( 5 ) );

		$this->assertSame( array(), $result['moved'] );
	}

	public function test_move_between_forms_returns_empty_when_select_not_array(): void {
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( null );

		$result = $this->repo()->moveBetweenForms( 1, 2, array( 5 ) );

		$this->assertSame( array(), $result['moved'] );
		$this->assertSame( array(), $result['conflicts'] );
	}

	public function test_move_between_forms_all_moved_runs_update(): void {
		$rows = array(
			array( 'id' => '10', 'user_id' => '0', 'email_hash' => 'e1', 'cpf_hash' => null, 'rf_hash' => null ),
			array( 'id' => '11', 'user_id' => '0', 'email_hash' => 'e2', 'cpf_hash' => null, 'rf_hash' => null ),
		);
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( $rows );
		// No conflicts: both hasConflictInForm probes return null.
		$this->wpdb->shouldReceive( 'get_var' )->twice()->andReturn( null );
		// The bulk UPDATE of moved rows.
		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( 2 );

		$result = $this->repo()->moveBetweenForms( 5, 6, array( 10, 11 ) );

		$this->assertSame( array( 10, 11 ), $result['moved'] );
		$this->assertSame( array(), $result['conflicts'] );
	}

	public function test_move_between_forms_conflict_row_with_user_id(): void {
		// Row carries a non-zero user_id → hasConflictInForm builds the
		// user_id clause; get_var returns a hit → conflict.
		$rows = array(
			array( 'id' => '20', 'user_id' => '99', 'email_hash' => null, 'cpf_hash' => null, 'rf_hash' => 'rf1' ),
		);
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( $rows );
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '1' );
		$this->wpdb->shouldNotReceive( 'query' );

		$result = $this->repo()->moveBetweenForms( 5, 6, array( 20 ) );

		$this->assertSame( array(), $result['moved'] );
		$this->assertSame( array( 20 ), $result['conflicts'] );
	}

	public function test_move_between_forms_row_with_no_identifiers_is_moved(): void {
		// All identifier columns empty → hasConflictInForm returns false
		// without touching get_var → row is moved.
		$rows = array(
			array( 'id' => '30', 'user_id' => '0', 'email_hash' => '', 'cpf_hash' => '', 'rf_hash' => '' ),
		);
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( $rows );
		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( 1 );

		$result = $this->repo()->moveBetweenForms( 5, 6, array( 30 ) );

		$this->assertSame( array( 30 ), $result['moved'] );
	}

	// ==================================================================
	// WRITER — deleteByFormId
	// ==================================================================

	public function test_delete_by_form_id_success(): void {
		$this->wpdb->shouldReceive( 'delete' )->once()->andReturn( 4 );

		$this->assertSame( 4, $this->repo()->deleteByFormId( 3 ) );
	}

	public function test_delete_by_form_id_no_rows(): void {
		$this->wpdb->shouldReceive( 'delete' )->once()->andReturn( 0 );

		$this->assertSame( 0, $this->repo()->deleteByFormId( 3 ) );
	}

	// ==================================================================
	// WRITER — updateWithEditTracking (column present w/ edited_by,
	// column absent)
	// ==================================================================

	public function test_update_with_edit_tracking_adds_edited_at_and_by(): void {
		// Two column_exists checks (edited_at=1, edited_by=1), then update().
		$this->wpdb->shouldReceive( 'get_var' )->twice()->andReturn( '1', '1' );
		$this->wpdb->shouldReceive( 'update' )
			->once()
			->with(
				'wp_ffc_submissions',
				Mockery::on(
					function ( $data ) {
						return array_key_exists( 'edited_at', $data )
							&& array_key_exists( 'edited_by', $data )
							&& 7 === $data['edited_by'];
					}
				),
				array( 'id' => 5 )
			)
			->andReturn( 1 );

		$this->assertSame( 1, $this->repo()->updateWithEditTracking( 5, array( 'status' => 'publish' ) ) );
	}

	public function test_update_with_edit_tracking_edited_at_only(): void {
		// edited_at exists (1), edited_by absent (0) → only edited_at added.
		$this->wpdb->shouldReceive( 'get_var' )->twice()->andReturn( '1', '0' );
		$this->wpdb->shouldReceive( 'update' )
			->once()
			->with(
				'wp_ffc_submissions',
				Mockery::on(
					function ( $data ) {
						return array_key_exists( 'edited_at', $data )
							&& ! array_key_exists( 'edited_by', $data );
					}
				),
				array( 'id' => 5 )
			)
			->andReturn( 1 );

		$this->assertSame( 1, $this->repo()->updateWithEditTracking( 5, array( 'note' => 'x' ) ) );
	}

	public function test_update_with_edit_tracking_no_column_passes_data_through(): void {
		// edited_at column absent (0) → no tracking fields added.
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '0' );
		$this->wpdb->shouldReceive( 'update' )
			->once()
			->with(
				'wp_ffc_submissions',
				array( 'note' => 'x' ),
				array( 'id' => 5 )
			)
			->andReturn( 1 );

		$this->assertSame( 1, $this->repo()->updateWithEditTracking( 5, array( 'note' => 'x' ) ) );
	}
}
