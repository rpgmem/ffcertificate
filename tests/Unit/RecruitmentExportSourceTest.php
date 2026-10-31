<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use FreeFormCertificate\Recruitment\RecruitmentExportSource;
use FreeFormCertificate\Tests\Support\BatchedExportSourceContractTestCase;

/**
 * Tests for RecruitmentExportSource (#2245): the two kinds a notice exports,
 * their columns, the keyset each walks, and that who made or cancelled a call
 * is written only for a user who may see it on screen. The per-phase gates
 * are the contract the base class asserts.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentExportSource
 */
class RecruitmentExportSourceTest extends BatchedExportSourceContractTestCase {

	/** @var RecruitmentExportSource */
	private $source;

	/** @var array<string, bool> Capabilities the user holds. */
	private array $caps = array();

	/** @var list<array{0: string, 1: array<int, mixed>}> Statements the double prepared. */
	private array $prepared = array();

	/** @var list<array<string, mixed>> Rows the page query answers. */
	private array $page = array();

	/** @var list<object> Calls the active-call lookup answers. */
	private array $history = array();

	/** @var mixed */
	private $wpdb_backup;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( '_x' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->alias( static fn( $v ) => strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $v ) ) );
		Functions\when( 'sanitize_file_name' )->returnArg();
		Functions\when( 'current_user_can' )->alias( fn( $cap ) => $this->caps[ $cap ] ?? false );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'get_option' )->alias( static fn( $key, $default = false ) => $default );
		Functions\when( 'wp_date' )->alias( static fn( $format, $ts = null, $tz = null ) => gmdate( $format, $ts ?? time() ) );
		Functions\when( 'wp_timezone' )->alias( static fn() => new \DateTimeZone( 'UTC' ) );
		Functions\when( 'get_users' )->alias(
			static fn( $args ) => array_map(
				static fn( $id ) => (object) array(
					'ID'           => $id,
					'display_name' => 'User ' . $id,
				),
				array_values( array_intersect( $args['include'] ?? array(), array( 3, 5 ) ) )
			)
		);

		$this->install_wpdb();
		$this->source = new RecruitmentExportSource();
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->wpdb_backup;
		unset( $_POST['notice_id'], $_POST['kind'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The source this file builds, for the contract the base asserts.
	 *
	 * @return object
	 */
	protected function export_source() {
		return $this->source;
	}

	private function install_wpdb(): void {
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$wpdb              = Mockery::mock( 'wpdb' );
		$wpdb->prefix      = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( $sql, ...$args ) {
				$args             = ( 1 === count( $args ) && is_array( $args[0] ) ) ? array_values( $args[0] ) : $args;
				$this->prepared[] = array( (string) $sql, $args );
				return 'Q' . ( count( $this->prepared ) - 1 );
			}
		);
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( $q ) {
				[ $sql ] = $this->statement( $q );
				return str_contains( $sql, 'INNER JOIN' ) ? '4' : '9';
			}
		);
		$wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function ( $q ) {
				[ $sql, $args ] = $this->statement( $q );
				if ( 'wp_ffc_recruitment_adjutancy' === $args[0] ) {
					return (object) array(
						'id'   => $args[1],
						'name' => 'Math',
					);
				}
				if ( 'wp_ffc_recruitment_notice' === $args[0] ) {
					return 12 === (int) $args[1] ? (object) array(
						'id'   => '12',
						'code' => 'ED-12',
					) : null;
				}
				throw new \LogicException( 'Unexpected get_row: ' . $sql );
			}
		);
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $q ) {
				[ $sql, $args ] = $this->statement( $q );
				if ( str_contains( $sql, 'ORDER BY id DESC LIMIT' ) || str_contains( $sql, 'ORDER BY c.id DESC LIMIT' ) ) {
					return $this->page;
				}
				if ( 'wp_ffc_recruitment_candidate' === $args[0] ) {
					return array(
						(object) array(
							'id'   => '600',
							'name' => 'Ana',
						),
					);
				}
				if ( str_contains( $sql, 'WHERE classification_id IN' ) ) {
					return $this->history;
				}
				throw new \LogicException( 'Unexpected get_results: ' . $sql );
			}
		);
		$GLOBALS['wpdb'] = $wpdb;
	}

	/** @return array{0: string, 1: array<int, mixed>} */
	private function statement( $token ): array {
		$i = (int) substr( (string) $token, 1 );
		$this->assertArrayHasKey( $i, $this->prepared );
		return $this->prepared[ $i ];
	}

	private function call( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'                  => '91',
				'classification_id'   => '60',
				'called_at'           => '1780308000',
				'date_to_assume'      => '2026-06-01',
				'time_to_assume'      => '09:30:00',
				'out_of_order'        => '0',
				'out_of_order_reason' => null,
				'cancellation_reason' => null,
				'cancelled_at'        => null,
				'cancelled_by'        => null,
				'created_by'          => '3',
				'candidate_id'        => '600',
				'adjutancy_id'        => '1',
				'rank'                => '4',
			),
			$overrides
		);
	}

	/** Fetch one page and format every row, as the engine does. */
	private function export( array $filters, array $context ): array {
		return array_map(
			fn( $row ) => $this->source->format_row( $row, $context ),
			$this->source->fetch_page( $filters, $context, PHP_INT_MAX, 100 )
		);
	}

	public function test_type_is_recruitment(): void {
		$this->assertSame( 'recruitment', $this->source->type() );
	}

	public function test_filters_default_to_the_list_and_refuse_an_unknown_kind(): void {
		$_POST['notice_id'] = '12';
		$_POST['kind']      = 'everything';
		$this->assertSame( array( 'notice_id' => 12, 'kind' => 'list' ), $this->source->sanitize_filters() );

		$_POST['kind'] = 'calls';
		$this->assertSame( 'calls', $this->source->sanitize_filters()['kind'] );
	}

	public function test_no_notice_counts_nothing_and_runs_no_query(): void {
		$this->assertSame( 0, $this->source->count( array( 'notice_id' => 0, 'kind' => 'list' ) ) );
		$this->assertSame( array(), $this->prepared );
	}

	public function test_each_kind_counts_its_own_rows(): void {
		$this->assertSame( 9, $this->source->count( array( 'notice_id' => 12, 'kind' => 'list' ) ) );
		$this->assertStringContainsString( "list_type = 'definitive'", $this->prepared[0][0] );
		$this->assertSame( 4, $this->source->count( array( 'notice_id' => 12, 'kind' => 'calls' ) ) );
		$this->assertStringContainsString( 'cl.notice_id = %d', $this->prepared[1][0] );
	}

	public function test_context_freezes_the_callers_permission_and_the_notice_code(): void {
		$this->caps = array( 'ffc_view_recruitment_history' => true );
		$this->assertSame( array( 'callers' => true, 'code' => 'ED-12' ), $this->source->build_context( array( 'notice_id' => 12 ) ) );

		$this->caps = array();
		$this->assertSame( array( 'callers' => false, 'code' => '' ), $this->source->build_context( array( 'notice_id' => 99 ) ) );
	}

	public function test_filename_names_the_notice_and_the_kind(): void {
		$this->assertMatchesRegularExpression( '/^recruitment-ED-12-definitive-list-\d{4}-\d{2}-\d{2}\.csv$/', $this->source->filename( array( 'kind' => 'list' ), array( 'code' => 'ED-12' ) ) );
		$this->assertMatchesRegularExpression( '/^recruitment-calls-\d{4}-\d{2}-\d{2}\.csv$/', $this->source->filename( array( 'kind' => 'calls' ), array( 'code' => '' ) ) );
	}

	public function test_called_by_columns_follow_the_frozen_permission(): void {
		$list_with    = $this->source->header( array( 'kind' => 'list' ), array( 'callers' => true ) );
		$list_without = $this->source->header( array( 'kind' => 'list' ), array( 'callers' => false ) );
		$this->assertContains( 'Called by', $list_with );
		$this->assertNotContains( 'Called by', $list_without );
		$this->assertCount( count( $list_without ) + 1, $list_with );

		$calls_with    = $this->source->header( array( 'kind' => 'calls' ), array( 'callers' => true ) );
		$calls_without = $this->source->header( array( 'kind' => 'calls' ), array( 'callers' => false ) );
		$this->assertContains( 'Cancelled by', $calls_with );
		$this->assertNotContains( 'Cancelled by', $calls_without );
		$this->assertCount( count( $calls_without ) + 2, $calls_with );
	}

	public function test_the_list_walks_the_definitive_keyset_and_writes_the_active_call(): void {
		$this->page    = array(
			array(
				'id'           => '60',
				'notice_id'    => '12',
				'candidate_id' => '600',
				'adjutancy_id' => '1',
				'rank'         => '4',
				'score'        => '88.5',
				'status'       => 'called',
				'pcd_hash'     => '',
			),
			array(
				'id'           => '59',
				'notice_id'    => '12',
				'candidate_id' => '601',
				'adjutancy_id' => '1',
				'rank'         => '5',
				'score'        => '80',
				'status'       => 'empty',
				'pcd_hash'     => '',
			),
		);
		$this->history = array(
			(object) $this->call(),
			(object) $this->call( array( 'id' => '90', 'cancelled_at' => '1777968000', 'created_by' => '5' ) ),
		);
		$context       = array( 'callers' => true );

		$rows = $this->export( array( 'notice_id' => 12, 'kind' => 'list' ), $context );

		$this->assertStringContainsString( 'AND id < %d ORDER BY id DESC LIMIT %d', $this->prepared[0][0] );
		$this->assertSame( array( 'wp_ffc_recruitment_classification', 12, PHP_INT_MAX, 100 ), $this->prepared[0][1] );
		$this->assertSame( array( '4', 'Ana', 'Math', 'General', '88.5', 'Called' ), array_slice( $rows[0], 0, 6 ) );
		$this->assertStringContainsString( '09:30', $rows[0][7], 'The time to assume, as stored.' );
		$this->assertSame( 'User 3', $rows[0][9], 'The active call names its caller; the cancelled one is not the active one.' );
		$this->assertSame( array( '5', '', 'Math', 'General', '80', 'Waiting', '', '', '', '' ), $rows[1], 'A waiting row has no call; an unknown candidate has no name.' );
		$this->assertSame( 60, $this->source->cursor_of( $this->source->fetch_page( array( 'notice_id' => 12, 'kind' => 'list' ), $context, PHP_INT_MAX, 100 )[0] ) );
	}

	public function test_the_calls_record_writes_cancellations_and_names_only_when_allowed(): void {
		$this->page = array(
			$this->call(
				array(
					'out_of_order'        => '1',
					'out_of_order_reason' => 'PCD reserve',
					'cancelled_at'        => '1780394400',
					'cancelled_by'        => '5',
					'cancellation_reason' => 'Wrong date',
				)
			),
			$this->call( array( 'id' => '92', 'created_by' => '44' ) ),
		);

		$with = $this->export( array( 'notice_id' => 12, 'kind' => 'calls' ), array( 'callers' => true ) );
		$this->assertStringContainsString( 'AND c.id < %d ORDER BY c.id DESC LIMIT %d', $this->prepared[0][0] );
		$this->assertSame( array( 'Ana', 'Math', '4' ), array_slice( $with[0], 1, 3 ) );
		$this->assertSame( array( 'User 3', 'Yes', 'PCD reserve', 'Cancelled' ), array_slice( $with[0], 6, 4 ) );
		$this->assertSame( array( 'User 5', 'Wrong date' ), array_slice( $with[0], 11, 2 ) );
		$this->assertSame( 'Deleted user #44', $with[1][6], 'An account that no longer exists is named by its ID.' );
		$this->assertSame( array( 'No', '', 'Active', '', '', '' ), array_slice( $with[1], 7 ) );

		$without = $this->export( array( 'notice_id' => 12, 'kind' => 'calls' ), array( 'callers' => false ) );
		$this->assertCount( count( $with[0] ) - 2, $without[0] );
		$this->assertNotContains( 'User 3', $without[0] );
		$this->assertNotContains( 'User 5', $without[0] );
		$this->assertSame( 'Wrong date', end( $without[0] ) );
	}

	public function test_an_empty_page_ends_the_walk_without_lookups(): void {
		$this->page = array();
		$this->assertSame( array(), $this->source->fetch_page( array( 'notice_id' => 12, 'kind' => 'calls' ), array( 'callers' => true ), PHP_INT_MAX, 100 ) );
		$this->assertCount( 1, $this->prepared, 'Only the page query runs.' );
	}

	public function test_the_lifecycle_hooks_are_inert(): void {
		$this->assertSame( array(), $this->source->extra_start_response( 'job', array() ) );
		$this->source->on_complete( 'job', array() );
		$this->source->on_before_download( array() );
		$this->assertSame( array( 'user_id' => 7 ), $this->source->job_owner_fields() );
	}
}
