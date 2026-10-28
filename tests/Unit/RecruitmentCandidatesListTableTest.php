<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Recruitment\RecruitmentCandidatesListTable;
use FreeFormCertificate\Tests\Support\Identifiers;

/**
 * Smoke tests for the Candidates list table.
 *
 * RecruitmentCandidateReader, RecruitmentAdjutancyReader, DataSanitizer,
 * SensitiveFieldRegistry and Encryption (hashing with the bootstrap's keys)
 * are the real ones, over a `$wpdb` double that answers the candidate and
 * adjutancy tables by SQL text and bound hash, and records every query, so
 * the table runs in the shared process.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentCandidatesListTable
 */
class RecruitmentCandidatesListTableTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private RecruitmentCandidatesListTable $table;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var string SQL of the last prepare(). */
	private string $sql = '';

	/** @var array<int, mixed> Arguments of the last prepare(). */
	private array $bound = array();

	/** @var list<string> SQL of every query run, in order. */
	private array $queries = array();

	/** @var int What the paginated count answers. */
	private int $count = 0;

	/** @var list<object> Rows the paginated page answers. */
	private array $rows = array();

	/** @var array<string, object> Candidate rows keyed by `cpf_hash`. */
	private array $by_cpf_hash = array();

	/** @var array<string, object> Candidate rows keyed by `rf_hash`. */
	private array $by_rf_hash = array();

	/** @var array<string, list<int>> Candidate ids keyed by `email_hash`. */
	private array $by_email_hash = array();

	/** @var list<object> Rows the adjutancy table holds. */
	private array $adjutancies = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'sanitize_key' )->alias( fn( $v ) => strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $v ) ) );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
		Functions\when( 'add_query_arg' )->alias( fn( $args, $url = '' ) => $url . '?' . http_build_query( (array) $args ) );
		Functions\when( 'admin_url' )->alias( fn( $p = '' ) => 'https://example.com/wp-admin/' . $p );
		Functions\when( 'wp_nonce_url' )->alias( fn( $url, $action = -1 ) => $url . '&_wpnonce=test' );
		Functions\when( 'wp_json_encode' )->alias( fn( $v ) => json_encode( $v ) );
		Functions\when( 'get_userdata' )->justReturn( false );
		Functions\when( 'get_edit_user_link' )->justReturn( '/wp-admin/user-edit.php?user_id=1' );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );

		$this->sql           = '';
		$this->bound         = array();
		$this->queries       = array();
		$this->count         = 0;
		$this->rows          = array();
		$this->by_cpf_hash   = array();
		$this->by_rf_hash    = array();
		$this->by_email_hash = array();
		$this->adjutancies   = array();

		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing( static fn( $v ) => (string) $v );
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( $sql, ...$args ) {
				$this->sql   = (string) $sql;
				$this->bound = ( 1 === count( $args ) && is_array( $args[0] ) ) ? $args[0] : $args;
				return (string) $sql;
			}
		);
		// RecruitmentCandidateReader::get_by_cpf_hash() / get_by_rf_hash().
		$wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function () {
				$this->queries[] = $this->sql;
				$this->assertSame( 'wp_ffc_recruitment_candidate', $this->bound[0] );
				$hash = (string) $this->bound[1];
				if ( 'SELECT * FROM %i WHERE cpf_hash = %s LIMIT 1' === $this->sql ) {
					return $this->by_cpf_hash[ $hash ] ?? null;
				}
				if ( 'SELECT * FROM %i WHERE rf_hash = %s LIMIT 1' === $this->sql ) {
					return $this->by_rf_hash[ $hash ] ?? null;
				}
				$this->fail( 'Unexpected get_row query: ' . $this->sql );
			}
		);
		// RecruitmentCandidateReader::get_ids_by_email_hash().
		$wpdb->shouldReceive( 'get_col' )->andReturnUsing(
			function () {
				$this->queries[] = $this->sql;
				$this->assertSame( 'SELECT id FROM %i WHERE email_hash = %s', $this->sql, 'Unexpected get_col query.' );
				$this->assertSame( 'wp_ffc_recruitment_candidate', $this->bound[0] );
				return array_map( 'strval', $this->by_email_hash[ (string) $this->bound[1] ] ?? array() );
			}
		);
		// RecruitmentCandidateReader::count_paginated_filtered().
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function () {
				$this->queries[] = $this->sql;
				$this->assertStringStartsWith( 'SELECT COUNT(DISTINCT c.id) FROM %i c', $this->sql, 'Unexpected get_var query.' );
				return (string) $this->count;
			}
		);
		// RecruitmentCandidateReader::get_paginated_filtered() and
		// RecruitmentAdjutancyReader::get_all().
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function () {
				$this->queries[] = $this->sql;
				if ( 'SELECT * FROM %i ORDER BY name ASC' === $this->sql ) {
					$this->assertSame( 'wp_ffc_recruitment_adjutancy', $this->bound[0] );
					return $this->adjutancies;
				}
				$this->assertStringStartsWith( 'SELECT DISTINCT c.* FROM %i c', $this->sql, 'Unexpected get_results query.' );
				return $this->rows;
			}
		);

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']   = $wpdb;

		$this->table = new RecruitmentCandidatesListTable();
	}

	protected function tearDown(): void {
		unset(
			$_REQUEST['cpf'], $_REQUEST['rf'], $_REQUEST['email'], $_REQUEST['s'],
			$_REQUEST['adjutancy_id'], $_REQUEST['status']
		);
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The CPF formatted the way an operator types it, built from the digits.
	 */
	private static function formatted_cpf( string $digits ): string {
		return sprintf( '%s.%s.%s-%s', substr( $digits, 0, 3 ), substr( $digits, 3, 3 ), substr( $digits, 6, 3 ), substr( $digits, 9, 2 ) );
	}

	private function call_protected( string $method, array $args = array() ) {
		$ref = new \ReflectionMethod( $this->table, $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( $this->table, $args );
	}

	public function test_get_columns_declares_expected_keys(): void {
		$cols = $this->table->get_columns();

		$this->assertSame(
			array( 'cb', 'name', 'user_id', 'phone', 'created_at' ),
			array_keys( $cols )
		);
	}

	public function test_get_sortable_columns_only_includes_name(): void {
		$sortable = $this->call_protected( 'get_sortable_columns' );

		$this->assertSame( array( 'name' ), array_keys( $sortable ) );
	}

	public function test_get_bulk_actions_returns_empty_until_delete_service_lands(): void {
		$actions = $this->call_protected( 'get_bulk_actions' );

		$this->assertSame( array(), $actions );
	}

	public function test_column_cb_emits_checkbox_named_candidate_ids(): void {
		$html = $this->call_protected( 'column_cb', array( array( 'id' => 77 ) ) );

		$this->assertStringContainsString( 'name="candidate_ids[]"', $html );
		$this->assertStringContainsString( 'value="77"', $html );
	}

	public function test_column_phone_em_dash_when_phone_empty(): void {
		$out = $this->call_protected( 'column_phone', array( array( 'phone' => '' ) ) );

		$this->assertSame( '—', $out );
	}

	public function test_column_phone_emits_escaped_value_when_present(): void {
		$out = $this->call_protected( 'column_phone', array( array( 'phone' => '+55 11 99999-0000' ) ) );

		$this->assertSame( '+55 11 99999-0000', $out );
	}

	public function test_column_user_id_em_dash_when_zero(): void {
		$out = $this->call_protected( 'column_user_id', array( array( 'user_id' => 0 ) ) );

		$this->assertSame( '—', $out );
	}

	public function test_column_user_id_shows_id_in_code_tags_when_user_missing(): void {
		Functions\when( 'get_userdata' )->justReturn( false );
		$out = $this->call_protected( 'column_user_id', array( array( 'user_id' => 42 ) ) );

		$this->assertStringContainsString( '<code>', $out );
		$this->assertStringContainsString( '#42', $out );
	}

	public function test_column_user_id_links_to_user_edit_when_user_exists(): void {
		$user_mock            = Mockery::mock( 'WP_User' );
		$user_mock->user_login = 'jane';
		Functions\when( 'get_userdata' )->justReturn( $user_mock );

		$out = $this->call_protected( 'column_user_id', array( array( 'user_id' => 7 ) ) );

		$this->assertStringContainsString( 'jane', $out );
		$this->assertStringContainsString( '<a href=', $out );
	}

	public function test_column_default_escapes_value(): void {
		$out = $this->call_protected( 'column_default', array( array( 'name' => 'Jane' ), 'name' ) );
		$this->assertSame( 'Jane', $out );
	}

	public function test_column_default_returns_empty_string_for_missing_column(): void {
		$out = $this->call_protected( 'column_default', array( array(), 'missing' ) );
		$this->assertSame( '', $out );
	}

	public function test_column_created_at_returns_timestamp_string(): void {
		$out = $this->call_protected( 'column_created_at', array( array( 'created_at' => '2026-03-15 09:30:00' ) ) );
		$this->assertSame( '2026-03-15 09:30:00', $out );
	}

	// ------------------------------------------------------------------
	// column_name() — row actions (Edit / Delete)
	// ------------------------------------------------------------------

	public function test_column_name_renders_edit_delete_row_actions(): void {
		$out = $this->call_protected( 'column_name', array( array( 'id' => 11, 'name' => 'Jane Doe' ) ) );

		$this->assertStringContainsString( 'Jane Doe', $out );
		$this->assertStringContainsString( 'action=edit-candidate', $out );
		$this->assertStringContainsString( 'action=delete-candidate', $out );
		$this->assertStringContainsString( 'submitdelete', $out );
	}

	// ------------------------------------------------------------------
	// prepare_items()
	// ------------------------------------------------------------------

	private function get_items(): array {
		$ref = new \ReflectionProperty( $this->table, 'items' );
		$ref->setAccessible( true );
		return (array) $ref->getValue( $this->table );
	}

	private function candidate_obj( int $id, string $name, $user_id = null ): object {
		return (object) array(
			'id'         => $id,
			'name'       => $name,
			'user_id'    => $user_id,
			'phone'      => '11999990000',
			'created_at' => '2026-03-15 09:30:00',
		);
	}

	public function test_prepare_items_maps_reader_rows_into_items(): void {
		Functions\when( '_prime_user_caches' )->justReturn( null );
		$this->count = 2;
		$this->rows  = array(
			$this->candidate_obj( 1, 'Alpha', 7 ),
			$this->candidate_obj( 2, 'Beta', null ),
		);

		$this->table->prepare_items();

		$items = $this->get_items();
		$this->assertCount( 2, $items );
		$this->assertSame( 'Alpha', $items[0]['name'] );
		$this->assertSame( 7, $items[0]['user_id'] );
		$this->assertNull( $items[1]['user_id'] );
	}

	public function test_prepare_items_empty_when_id_constraint_resolves_empty(): void {
		// CPF typed but no matching candidate → constraint = empty array.
		$_REQUEST['cpf'] = self::formatted_cpf( Identifiers::CPF_A );
		// A page the paginated query would answer, were it reached.
		$this->count = 1;
		$this->rows  = array( $this->candidate_obj( 1, 'Alpha' ) );

		$this->table->prepare_items();

		$this->assertSame( array(), $this->get_items() );
		// Only the CPF lookup ran: the empty constraint short-circuits both
		// the count and the page without a query.
		$this->assertSame( array( 'SELECT * FROM %i WHERE cpf_hash = %s LIMIT 1' ), $this->queries );
		$this->assertSame( Encryption::hash( Identifiers::CPF_A ), $this->bound[1] );
	}

	// ------------------------------------------------------------------
	// resolve_id_constraint() / resolve_cpf_or_rf_to_ids() (static private)
	// ------------------------------------------------------------------

	private function call_static_private( string $method, array $args ) {
		$ref = new \ReflectionMethod( RecruitmentCandidatesListTable::class, $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( null, $args );
	}

	public function test_resolve_id_constraint_null_when_all_empty(): void {
		$out = $this->call_static_private( 'resolve_id_constraint', array( '', '', '' ) );
		$this->assertNull( $out );
	}

	public function test_resolve_id_constraint_intersects_cpf_and_email(): void {
		// CPF resolves to candidate id 5; email resolves to ids [5, 9] → ∩ = [5].
		// The e-mail is hashed in its canonical form (#1313), so the stored
		// hash is of the lower-cased address the operator typed in mixed case.
		$this->by_cpf_hash[ Encryption::hash( Identifiers::CPF_A ) ] = (object) array( 'id' => 5 );
		$this->by_email_hash[ Encryption::hash( 'a@b.com' ) ]         = array( 5, 9 );

		$out = $this->call_static_private( 'resolve_id_constraint', array( self::formatted_cpf( Identifiers::CPF_A ), '', ' A@B.com ' ) );
		$this->assertSame( array( 5 ), $out );
	}

	public function test_resolve_id_constraint_empty_when_intersection_disjoint(): void {
		$this->by_cpf_hash[ Encryption::hash( Identifiers::CPF_A ) ] = (object) array( 'id' => 5 );
		$this->by_email_hash[ Encryption::hash( 'a@b.com' ) ]         = array( 9 );

		$out = $this->call_static_private( 'resolve_id_constraint', array( Identifiers::CPF_A, '', 'a@b.com' ) );
		$this->assertSame( array(), $out );
	}

	public function test_resolve_cpf_or_rf_to_ids_empty_for_blank_digits(): void {
		$out = $this->call_static_private( 'resolve_cpf_or_rf_to_ids', array( 'abc', 'cpf' ) );
		$this->assertSame( array(), $out );
		$this->assertSame( array(), $this->queries, 'No digits must mean no lookup.' );
	}

	public function test_resolve_cpf_or_rf_to_ids_returns_id_for_rf_match(): void {
		$this->by_rf_hash[ Encryption::hash( Identifiers::RF_A ) ] = (object) array( 'id' => 42 );
		// The same hash under the CPF column must not be what answers an RF lookup.
		$this->by_cpf_hash[ Encryption::hash( Identifiers::RF_A ) ] = (object) array( 'id' => 1 );

		$out = $this->call_static_private( 'resolve_cpf_or_rf_to_ids', array( Identifiers::RF_A, 'rf' ) );
		$this->assertSame( array( 42 ), $out );
	}

	// ------------------------------------------------------------------
	// convert_row() (static private)
	// ------------------------------------------------------------------

	public function test_convert_row_normalizes_nulls(): void {
		$row = (object) array(
			'id'         => '3',
			'name'       => 'Carol',
			'user_id'    => null,
			'phone'      => null,
			'created_at' => '2026-03-15 09:30:00',
		);
		$out = $this->call_static_private( 'convert_row', array( $row ) );

		$this->assertSame( 3, $out['id'] );
		$this->assertNull( $out['user_id'] );
		$this->assertSame( '', $out['phone'] );
	}

	// ------------------------------------------------------------------
	// extra_tablenav()
	// ------------------------------------------------------------------

	public function test_extra_tablenav_returns_early_for_bottom(): void {
		ob_start();
		$this->call_protected( 'extra_tablenav', array( 'bottom' ) );
		$out = ob_get_clean();

		$this->assertSame( '', $out );
	}

	public function test_extra_tablenav_top_renders_filters_and_adjutancy_options(): void {
		$this->adjutancies = array(
			(object) array( 'id' => 4, 'name' => 'Adjutancy Four' ),
		);

		ob_start();
		$this->call_protected( 'extra_tablenav', array( 'top' ) );
		$out = ob_get_clean();

		$this->assertStringContainsString( 'name="cpf"', $out );
		$this->assertStringContainsString( 'name="rf"', $out );
		$this->assertStringContainsString( 'name="email"', $out );
		$this->assertStringContainsString( 'Adjutancy Four', $out );
		$this->assertStringContainsString( 'name="status"', $out );
	}
}
