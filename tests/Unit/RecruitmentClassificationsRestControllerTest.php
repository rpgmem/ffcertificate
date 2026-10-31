<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\SensitiveFieldRegistry;
use FreeFormCertificate\Recruitment\RecruitmentClassificationsRestController;
use FreeFormCertificate\Tests\Support\ActivityLogBuffer;
use FreeFormCertificate\Tests\Support\CallLog;
use FreeFormCertificate\Tests\Support\Identifiers;

/**
 * Tests for the recruitment classifications REST controller: route
 * registration, the read endpoints, the CSV import entry points (single and
 * batched), promotion, calls, status changes, preview status, adjutancy
 * changes, deletion and call cancellation.
 *
 * Everything behind the controller is the real class: the classification,
 * notice, notice-adjutancy, adjutancy, reason, call and candidate stores, both
 * state machines, the CSV importer, parser, validator and staging service, the
 * candidate persister and the account promotion it runs, the promotion, call
 * and delete services, the settings, the error catalogue, Encryption (with the
 * bootstrap's keys) and ActivityLog (observed through its buffer). They run
 * over a `$wpdb` double that holds rows per table, answers each statement by
 * its interpolated SQL text, applies the conditional status UPDATEs the state
 * machines rely on, and records every write. A statement it does not expect is
 * recorded and fails the test after it ran, because several services catch
 * every Throwable inside their transaction.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentClassificationsRestController
 */
class RecruitmentClassificationsRestControllerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** Table names, with the double's `wp_` prefix. */
	private const T_CLASSIFICATION = 'wp_ffc_recruitment_classification';
	private const T_NOTICE         = 'wp_ffc_recruitment_notice';
	private const T_NOTICE_ADJ     = 'wp_ffc_recruitment_notice_adjutancy';
	private const T_ADJUTANCY      = 'wp_ffc_recruitment_adjutancy';
	private const T_REASON         = 'wp_ffc_recruitment_reason';
	private const T_CALL           = 'wp_ffc_recruitment_call';
	private const T_CANDIDATE      = 'wp_ffc_recruitment_candidate';
	private const T_JOBS           = 'wp_ffc_recruitment_import_jobs';
	private const T_STAGING        = 'wp_ffc_recruitment_import_staging';
	private const T_PROFILES       = 'wp_ffc_user_profiles';
	private const T_SUBMISSIONS    = 'wp_ffc_submissions';

	/** The account an imported candidate's identifiers already belong to. */
	private const ACCOUNT = 77;

	/** The operator making every request. */
	private const OPERATOR = 42;

	private RecruitmentClassificationsRestController $controller;

	/** @var array<int, array{namespace: string, route: string, args: array}> */
	private array $registered_routes = array();

	/** @var array<int, string> */
	private array $tmp_files = array();

	/**
	 * Stored rows, by table and id.
	 *
	 * @var array<string, array<int, object>>
	 */
	private array $rows = array();

	/**
	 * Adjutancy ids attached to each notice.
	 *
	 * @var array<int, list<int>>
	 */
	private array $attached = array();

	/**
	 * Import jobs, by job id.
	 *
	 * @var array<string, object>
	 */
	private array $jobs = array();

	/** @var list<object> The rows the staging batch read answers. */
	private array $staged = array();

	/** @var list<object> Stored candidate identities the pre-import check reads (#2245). */
	private array $stored_identities = array();

	/** @var list<object> Staged rows as the batched identity check reads them. */
	private array $staged_identities = array();

	/** @var int What the "unpromoted staging rows" count answers. */
	private int $unpromoted = 0;

	/** @var int What the staging-to-live INSERT ... SELECT answers. */
	private int $swapped = 0;

	/** @var array<string, int|false> What `update()` answers per table; 1 when absent. */
	private array $update_result = array();

	/** @var list<array{0: string, 1: string, 2: array<string, mixed>, 3: array<string, mixed>}> Every write, as `[ op, table, data, where ]`. */
	private array $writes = array();

	/** @var list<string> Every `query()` statement other than the transaction controls. */
	private array $queries = array();

	/** @var list<string> Every read statement, as interpolated. */
	private array $reads = array();

	/** @var list<string> Statements the double does not answer. */
	private array $unexpected = array();

	/**
	 * Calls the notice already has, as `count_calls_for_notice()` reads them.
	 *
	 * @var int
	 */
	private int $notice_calls = 0;

	/** @var array<string, mixed> The stored `ffc_settings` option. */
	private array $ffc_settings = array();

	/** @var array<string, mixed> The stored `ffc_recruitment_settings` option. */
	private array $recruitment_settings = array();

	/** @var int The next auto-increment id. */
	private int $next_id = 500;

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;

		$this->rows                 = array();
		$this->attached             = array();
		$this->jobs                 = array();
		$this->staged               = array();
		$this->unpromoted           = 0;
		$this->swapped              = 0;
		$this->update_result        = array();
		$this->writes               = array();
		$this->queries              = array();
		$this->reads                = array();
		$this->unexpected           = array();
		$this->notice_calls         = 0;
		$this->next_id              = 500;
		$this->ffc_settings         = ActivityLogBuffer::ENABLED_SETTINGS;
		$this->recruitment_settings = array( 'email_mode' => 'ask' );

		ActivityLogBuffer::reset();
		ActivityLogBuffer::stub_functions();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'get_current_user_id' )->justReturn( self::OPERATOR );
		Functions\when( 'size_format' )->justReturn( '10 MB' );
		Functions\when( 'remove_accents' )->returnArg();
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'wp_salt' )->justReturn( 'test-salt' );
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'job-1' );
		Functions\when( 'get_user_by' )->justReturn( false );
		Functions\when( 'is_wp_error' )->alias( static fn( $v ) => $v instanceof \WP_Error );
		Functions\when( 'get_option' )->alias(
			function ( $key, $default = false ) {
				if ( 'ffc_settings' === $key ) {
					return $this->ffc_settings;
				}
				if ( 'ffc_recruitment_settings' === $key ) {
					return $this->recruitment_settings;
				}
				return $default;
			}
		);

		$this->registered_routes = array();
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->justReturn( true );
		Functions\when( 'register_rest_route' )->alias(
			function ( $namespace, $route, $args ) {
				$this->registered_routes[] = compact( 'namespace', 'route', 'args' );
				return true;
			}
		);

		$GLOBALS['wpdb'] = $this->database();

		$this->controller = new RecruitmentClassificationsRestController();
	}

	protected function assertPostConditions(): void {
		$this->assertSame( array(), $this->unexpected, 'The controller ran a statement the test did not expect.' );
		parent::assertPostConditions();
	}

	protected function tearDown(): void {
		foreach ( $this->tmp_files as $f ) {
			if ( is_file( $f ) ) {
				unlink( $f );
			}
		}
		$this->tmp_files = array();
		ActivityLogBuffer::reset();
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// The database double
	// ------------------------------------------------------------------

	/**
	 * A `$wpdb` double over the rows a test stores.
	 *
	 * @return Mockery\MockInterface
	 */
	private function database() {
		$wpdb            = Mockery::mock( 'wpdb' );
		$wpdb->prefix    = 'wp_';
		$wpdb->insert_id = 0;

		// Interpolates, so every read and query below dispatches on its text.
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( $sql, ...$args ) {
				$values = ( 1 === count( $args ) && is_array( $args[0] ) ) ? $args[0] : $args;
				foreach ( $values as $v ) {
					$sql = (string) preg_replace_callback( '/%[ids]/', static fn() => (string) $v, (string) $sql, 1 );
				}
				return $sql;
			}
		);

		$wpdb->shouldReceive( 'get_row' )->andReturnUsing( fn( $sql ) => $this->answer_row( (string) $sql ) );
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing( fn( $sql ) => $this->answer_results( (string) $sql ) );
		$wpdb->shouldReceive( 'get_col' )->andReturnUsing( fn( $sql ) => $this->answer_col( (string) $sql ) );
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing( fn( $sql ) => $this->answer_var( (string) $sql ) );
		$wpdb->shouldReceive( 'query' )->andReturnUsing( fn( $sql ) => $this->answer_query( (string) $sql ) );

		$wpdb->shouldReceive( 'insert' )->andReturnUsing(
			function ( $table, $data ) use ( $wpdb ) {
				$this->writes[]  = array( 'insert', (string) $table, $data, array() );
				$id              = ++$this->next_id;
				$wpdb->insert_id = $id;
				$this->rows[ (string) $table ][ $id ] = (object) array_merge( array( 'id' => $id ), $data );
				return 1;
			}
		);
		$wpdb->shouldReceive( 'update' )->andReturnUsing(
			function ( $table, $data, $where ) {
				$this->writes[] = array( 'update', (string) $table, $data, $where );
				$result         = $this->update_result[ (string) $table ] ?? 1;
				if ( false !== $result && isset( $where['id'] ) && isset( $this->rows[ (string) $table ][ (int) $where['id'] ] ) ) {
					foreach ( $data as $column => $value ) {
						$this->rows[ (string) $table ][ (int) $where['id'] ]->{$column} = $value;
					}
				}
				return $result;
			}
		);
		$wpdb->shouldReceive( 'delete' )->andReturnUsing(
			function ( $table, $where ) {
				$this->writes[] = array( 'delete', (string) $table, array(), $where );
				return 1;
			}
		);

		return $wpdb;
	}

	/**
	 * Answer a `get_row()`.
	 *
	 * @param string $sql Interpolated statement.
	 * @return mixed
	 */
	private function answer_row( string $sql ) {
		$this->reads[] = $sql;
		if ( 1 === preg_match( '/^SELECT \* FROM (wp_\w+) WHERE id = (\d+)$/', $sql, $m ) ) {
			return $this->rows[ $m[1] ][ (int) $m[2] ] ?? null;
		}
		if ( 1 === preg_match( '/^SELECT (?:\*|job_id, status) FROM ' . self::T_JOBS . ' WHERE job_id = (\S+)$/', $sql, $m ) ) {
			return $this->jobs[ $m[1] ] ?? null;
		}
		if ( 1 === preg_match( '/^SELECT \* FROM ' . self::T_CANDIDATE . ' WHERE (?:cpf|rf)_hash = \S+ LIMIT 1$/', $sql ) ) {
			// No candidate carries the imported identifiers yet.
			return null;
		}
		if ( 'SELECT * FROM ' . self::T_PROFILES . ' WHERE user_id = ' . self::ACCOUNT === $sql ) {
			// The account's profile already indexes both identifiers.
			return array(
				'user_id'  => self::ACCOUNT,
				'cpf_hash' => 'indexed',
				'rf_hash'  => 'indexed',
			);
		}
		if ( 1 === preg_match( '/^SELECT \* FROM ' . self::T_CLASSIFICATION . '\s+WHERE notice_id = (\d+) AND adjutancy_id = (\d+) AND list_type = (\w+) AND status = empty\s+ORDER BY `rank` ASC, candidate_id ASC LIMIT 1$/', $sql, $m ) ) {
			return $this->lowest_empty( (int) $m[1], (int) $m[2], $m[3] );
		}
		$this->unexpected[] = 'get_row: ' . $sql;
		return null;
	}

	/**
	 * Answer a `get_results()`.
	 *
	 * @param string $sql Interpolated statement.
	 * @return array<int, object>
	 */
	private function answer_results( string $sql ): array {
		$this->reads[] = $sql;
		if ( 1 === preg_match( '/^SELECT \* FROM ' . self::T_CLASSIFICATION . ' WHERE notice_id = (\d+)(?: AND list_type = (\w+))?(?: AND adjutancy_id = (\d+))? ORDER BY/', $sql, $m ) ) {
			$out = array();
			foreach ( $this->rows[ self::T_CLASSIFICATION ] ?? array() as $row ) {
				if ( (int) $row->notice_id !== (int) $m[1] ) {
					continue;
				}
				if ( isset( $m[2] ) && '' !== $m[2] && (string) $row->list_type !== $m[2] ) {
					continue;
				}
				if ( isset( $m[3] ) && (int) $row->adjutancy_id !== (int) $m[3] ) {
					continue;
				}
				$out[] = $row;
			}
			return $out;
		}
		if ( 0 === strpos( $sql, 'SELECT id, name, cpf_normalized, rf_normalized, email, phone, pcd FROM ' . self::T_STAGING . ' WHERE job_id = ' ) ) {
			return $this->staged;
		}
		if ( 0 === strpos( $sql, 'SELECT line_no, cpf_normalized, rf_normalized, email, name FROM ' . self::T_STAGING . ' WHERE job_id = ' ) ) {
			return $this->staged_identities;
		}
		if ( 1 === preg_match( '/^SELECT id, cpf_hash, rf_hash, email_hash, name FROM ' . self::T_CANDIDATE . ' WHERE (cpf_hash|rf_hash) IN \((.*)\)$/', $sql, $m ) ) {
			$wanted = array_map( static fn( string $h ): string => trim( $h, " '" ), explode( ',', $m[2] ) );
			return array_values(
				array_filter(
					$this->stored_identities,
					static fn( object $c ): bool => in_array( (string) $c->{$m[1]}, $wanted, true )
				)
			);
		}
		if ( 0 === strpos( $sql, 'SELECT line_no, adjutancy_slug FROM ' . self::T_STAGING )
			|| ( false !== strpos( $sql, 'GROUP_CONCAT(line_no ORDER BY row_no)' ) && false !== strpos( $sql, self::T_STAGING ) ) ) {
			// A clean staged job: no unknown adjutancy, no duplicate, no divergence.
			return array();
		}
		$this->unexpected[] = 'get_results: ' . $sql;
		return array();
	}

	/**
	 * Answer a `get_col()`.
	 *
	 * @param string $sql Interpolated statement.
	 * @return array<int, mixed>
	 */
	private function answer_col( string $sql ): array {
		$this->reads[] = $sql;
		if ( 1 === preg_match( '/^SELECT adjutancy_id FROM ' . self::T_NOTICE_ADJ . ' WHERE notice_id = (\d+)$/', $sql, $m ) ) {
			return array_map( 'strval', $this->attached[ (int) $m[1] ] ?? array() );
		}
		if ( 0 === strpos( $sql, 'SELECT line_no FROM ' . self::T_STAGING ) ) {
			// No staged row is missing its identifiers or its adjutancy.
			return array();
		}
		$this->unexpected[] = 'get_col: ' . $sql;
		return array();
	}

	/**
	 * Answer a `get_var()`.
	 *
	 * @param string $sql Interpolated statement.
	 * @return mixed
	 */
	private function answer_var( string $sql ) {
		$this->reads[] = $sql;
		if ( 1 === preg_match( '/^SELECT COUNT\(\*\) FROM ' . self::T_NOTICE_ADJ . ' WHERE notice_id = (\d+) AND adjutancy_id = (\d+)$/', $sql, $m ) ) {
			return in_array( (int) $m[2], $this->attached[ (int) $m[1] ] ?? array(), true ) ? '1' : '0';
		}
		if ( 0 === strpos( $sql, 'SELECT user_id FROM ' . self::T_PROFILES . ' WHERE (cpf_hash = ' ) ) {
			// The imported identifiers already belong to an account.
			return (string) self::ACCOUNT;
		}
		if ( 0 === strpos( $sql, 'SHOW TABLES LIKE ' ) ) {
			return null;
		}
		if ( 0 === strpos( $sql, 'SELECT COUNT(*) FROM ' . self::T_CALL . ' c' ) ) {
			return (string) $this->notice_calls;
		}
		if ( 1 === preg_match( '/^SELECT COUNT\(\*\) FROM ' . self::T_STAGING . ' WHERE job_id = \S+ AND processed = 0$/', $sql ) ) {
			return (string) $this->unpromoted;
		}
		$this->unexpected[] = 'get_var: ' . $sql;
		return null;
	}

	/**
	 * Answer a `query()`, applying the conditional status UPDATEs.
	 *
	 * @param string $sql Interpolated statement.
	 * @return mixed
	 */
	private function answer_query( string $sql ) {
		if ( in_array( $sql, array( 'START TRANSACTION', 'COMMIT', 'ROLLBACK' ), true ) ) {
			return true;
		}
		$this->queries[] = $sql;
		if ( 1 === preg_match( '/^UPDATE (' . self::T_CLASSIFICATION . '|' . self::T_NOTICE . ') SET status = (\w+), updated_at = .+ WHERE id = (\d+) AND status = (\w+)$/', $sql, $m ) ) {
			$row = $this->rows[ $m[1] ][ (int) $m[3] ] ?? null;
			if ( null === $row || (string) $row->status !== $m[4] ) {
				return 0;
			}
			$row->status = $m[2];
			return 1;
		}
		if ( 1 === preg_match( '/^UPDATE ' . self::T_NOTICE . ' SET opened_at = (.+), updated_at = .+ WHERE id = (\d+) AND opened_at IS NULL$/', $sql, $m ) ) {
			$this->rows[ self::T_NOTICE ][ (int) $m[2] ]->opened_at = $m[1];
			return 1;
		}
		if ( 1 === preg_match( '/^UPDATE ' . self::T_CALL . ' SET cancellation_reason = .+ WHERE id = (\d+) AND cancelled_at IS NULL$/s', $sql ) ) {
			return 1;
		}
		if ( 0 === strpos( $sql, 'UPDATE ' . self::T_SUBMISSIONS . ' SET user_id = ' ) ) {
			// No orphaned certificate carries the imported identifiers.
			return 0;
		}
		if ( 0 === strpos( $sql, 'DELETE s FROM ' . self::T_STAGING ) || 0 === strpos( $sql, 'DELETE FROM ' . self::T_JOBS . ' WHERE created_at' ) ) {
			// No stale import job to clean up.
			return 0;
		}
		if ( 0 === strpos( $sql, 'INSERT INTO ' . self::T_STAGING . ' (job_id' ) ) {
			return substr_count( $sql, '), (' ) + 1;
		}
		if ( 0 === strpos( $sql, 'UPDATE ' . self::T_JOBS . ' SET processed_count = processed_count + ' ) ) {
			return 1;
		}
		if ( 0 === strpos( $sql, 'INSERT INTO ' . self::T_CLASSIFICATION ) ) {
			return $this->swapped;
		}
		$this->unexpected[] = 'query: ' . $sql;
		return false;
	}

	/**
	 * The empty classification with the lowest rank in a notice's list for an adjutancy.
	 *
	 * @param int    $notice_id    Notice.
	 * @param int    $adjutancy_id Adjutancy.
	 * @param string $list_type    List.
	 * @return object|null
	 */
	private function lowest_empty( int $notice_id, int $adjutancy_id, string $list_type ): ?object {
		$best = null;
		foreach ( $this->rows[ self::T_CLASSIFICATION ] ?? array() as $row ) {
			if ( (int) $row->notice_id !== $notice_id || (int) $row->adjutancy_id !== $adjutancy_id
				|| (string) $row->list_type !== $list_type || 'empty' !== (string) $row->status ) {
				continue;
			}
			if ( null === $best || (int) $row->rank < (int) $best->rank ) {
				$best = $row;
			}
		}
		return $best;
	}

	// ------------------------------------------------------------------
	// Fixtures
	// ------------------------------------------------------------------

	/**
	 * Store a row.
	 *
	 * @param string               $table Table.
	 * @param array<string, mixed> $row   Columns, `id` included.
	 */
	private function store( string $table, array $row ): void {
		$this->rows[ $table ][ (int) $row['id'] ] = (object) $row;
	}

	/**
	 * Store a classification.
	 *
	 * @param int                  $id        Id.
	 * @param array<string, mixed> $overrides Column overrides.
	 */
	private function classification( int $id, array $overrides = array() ): void {
		$this->store(
			self::T_CLASSIFICATION,
			array_merge(
				array(
					'id'                => $id,
					'candidate_id'      => 11,
					'notice_id'         => 1,
					'adjutancy_id'      => 3,
					'list_type'         => 'definitive',
					'rank'              => $id,
					'score'             => '9.5',
					'status'            => 'empty',
					'preview_status'    => 'empty',
					'preview_reason_id' => null,
				),
				$overrides
			)
		);
	}

	/**
	 * Store a notice.
	 *
	 * @param int    $id     Id.
	 * @param string $status Status.
	 */
	private function notice( int $id, string $status ): void {
		$this->store(
			self::T_NOTICE,
			array(
				'id'           => $id,
				'code'         => 'N-' . $id,
				'name'         => 'Notice ' . $id,
				'status'       => $status,
				'opened_at'    => null,
				'closed_at'    => null,
				'was_reopened' => '0',
			)
		);
	}

	/**
	 * Attach an adjutancy, stored with a slug, to a notice.
	 *
	 * @param int    $notice_id    Notice.
	 * @param int    $adjutancy_id Adjutancy.
	 * @param string $slug         Its slug in the CSV.
	 */
	private function attach( int $notice_id, int $adjutancy_id, string $slug ): void {
		$this->attached[ $notice_id ][] = $adjutancy_id;
		$this->store(
			self::T_ADJUTANCY,
			array(
				'id'   => $adjutancy_id,
				'slug' => $slug,
				'name' => ucfirst( $slug ),
			)
		);
	}

	/**
	 * A CSV with the required header and one valid row, for adjutancy `teacher`.
	 *
	 * @return string
	 */
	private function valid_csv(): string {
		return "name,cpf,rf,email,adjutancy,rank,score,pcd\n"
			. 'Ana,' . Identifiers::CPF_A . ',' . Identifiers::RF_A . ",ana@example.com,teacher,1,9.5,0\n";
	}

	/**
	 * Build a $_FILES-style array pointing at a real temp file so the
	 * controller's file_get_contents() succeeds.
	 *
	 * @param string $content CSV body.
	 * @return array<string, array{tmp_name: string, name: string, size: int}>
	 */
	private function csv_files( string $content = "rank,name\n1,Ana\n" ): array {
		$path = tempnam( sys_get_temp_dir(), 'ffc-csv-' );
		file_put_contents( $path, $content );
		$this->tmp_files[] = $path;
		return array(
			'csv_file' => array(
				'tmp_name' => $path,
				'name'     => 'ranking.csv',
				'size'     => strlen( $content ),
			),
		);
	}

	private function make_request( array $params, array $files = array() ): \WP_REST_Request {
		$req = Mockery::mock( 'WP_REST_Request' );
		$req->shouldReceive( 'get_param' )->andReturnUsing( fn( $k ) => $params[ $k ] ?? null );
		$req->shouldReceive( 'get_params' )->andReturn( $params );
		$req->shouldReceive( 'get_file_params' )->andReturn( $files );
		return $req;
	}

	/**
	 * The writes of one kind to one table.
	 *
	 * @param string $op    insert, update or delete.
	 * @param string $table Table.
	 * @return list<array{0: string, 1: string, 2: array<string, mixed>, 3: array<string, mixed>}>
	 */
	private function writes_to( string $op, string $table ): array {
		return array_values(
			array_filter( $this->writes, static fn( $w ) => $op === $w[0] && $table === $w[1] )
		);
	}

	/**
	 * The buffered activity-log entries for one action, as their decoded contexts.
	 *
	 * @param string $action Action.
	 * @return list<mixed>
	 */
	private function logged( string $action ): array {
		$out = array();
		foreach ( ActivityLogBuffer::contexts() as $entry ) {
			if ( $action === $entry['action'] ) {
				$out[] = $entry['context'];
			}
		}
		return $out;
	}

	// ------------------------------------------------------------------
	// register_routes()
	// ------------------------------------------------------------------

	public function test_register_routes_registers_classification_route_groups(): void {
		$this->controller->register_routes();

		// 15 register_rest_route calls: list/item/import/promote/call/bulk-call/
		// status/preview-status/override-to-empty/cancel-call/adjutancy + 4
		// batched-import endpoints (import-job/start, import-job/validate,
		// import-job/batch, import-job/commit).
		$this->assertCount( 15, $this->registered_routes );
	}

	public function test_register_routes_includes_classifications_and_import_routes(): void {
		$this->controller->register_routes();

		$routes = array_column( $this->registered_routes, 'route' );
		$combined = implode( ' ', $routes );

		$this->assertStringContainsString( '/classifications', $combined );
		$this->assertStringContainsString( '/import', $combined );
		$this->assertStringContainsString( '/promote-preview', $combined );
		$this->assertStringContainsString( '/override-to-empty', $combined );
	}

	// ------------------------------------------------------------------
	// override_classification_to_empty() — #Item 8
	// ------------------------------------------------------------------

	public function test_override_to_empty_returns_409_when_reason_missing(): void {
		// Routed through the real state machine: empty reason is gated before
		// any DB read, so the handler surfaces a 409 WP_Error.
		$result = $this->controller->override_classification_to_empty(
			$this->make_request( array( 'id' => 10, 'reason' => '' ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'recruitment_transition_reason_required', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertSame( array(), $this->reads );
	}

	public function test_override_to_empty_returns_409_when_classification_missing(): void {
		// No classification 999 is stored → the state machine reports
		// not-found, the handler maps it to a 409 WP_Error.
		$result = $this->controller->override_classification_to_empty(
			$this->make_request( array( 'id' => 999, 'reason' => 'Undo hire' ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'recruitment_classification_not_found', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
	}

	// ------------------------------------------------------------------
	// list_classifications()
	// ------------------------------------------------------------------

	public function test_list_classifications_returns_200_with_repository_payload(): void {
		$this->classification( 1, array( 'notice_id' => 7 ) );
		$this->classification( 2, array( 'notice_id' => 7 ) );
		$this->classification( 3, array( 'notice_id' => 8 ) );

		$response = $this->controller->list_classifications( $this->make_request( array( 'id' => 7 ) ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array( $this->rows[ self::T_CLASSIFICATION ][1], $this->rows[ self::T_CLASSIFICATION ][2] ),
			$response->get_data()
		);
	}

	public function test_list_classifications_passes_list_type_filter_through(): void {
		$this->controller->list_classifications(
			$this->make_request( array( 'id' => 7, 'list_type' => 'preview' ) )
		);

		$this->assertCount( 1, $this->reads );
		$this->assertStringStartsWith(
			'SELECT * FROM ' . self::T_CLASSIFICATION . ' WHERE notice_id = 7 AND list_type = preview ORDER BY',
			$this->reads[0]
		);
		$this->assertStringNotContainsString( 'adjutancy_id', $this->reads[0] );
	}

	// ------------------------------------------------------------------
	// import_csv() — failure path
	// ------------------------------------------------------------------

	public function test_import_csv_rejects_non_csv_extension(): void {
		// #839 S9 — a spoofed non-CSV upload is rejected before the whole-file
		// read, regardless of the (spoofable) browser MIME type.
		$files                     = $this->csv_files();
		$files['csv_file']['name'] = 'payload.php';
		$result                    = $this->controller->import_csv( $this->make_request( array( 'id' => 7 ), $files ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'recruitment_csv_file_type', $result->get_error_code() );
	}

	public function test_import_csv_rejects_oversize_upload(): void {
		// #839 S9 — an upload over the size cap is rejected (413) before it is
		// read whole into memory.
		$files                     = $this->csv_files();
		$files['csv_file']['size'] = 20 * 1024 * 1024; // 20 MB > 10 MB cap.
		$result                    = $this->controller->import_csv( $this->make_request( array( 'id' => 7 ), $files ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'recruitment_csv_file_too_large', $result->get_error_code() );
	}

	public function test_import_csv_returns_400_when_file_missing(): void {
		$result = $this->controller->import_csv( $this->make_request( array( 'id' => 7 ), array() ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'recruitment_csv_file_missing', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	// ------------------------------------------------------------------
	// change_classification_adjutancy() — issue #331 "Edit estendido"
	// ------------------------------------------------------------------

	public function test_change_classification_adjutancy_returns_400_when_id_zero(): void {
		$result = $this->controller->change_classification_adjutancy(
			$this->make_request( array( 'id' => 7, 'adjutancy_id' => 0 ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_invalid_adjutancy', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_change_classification_adjutancy_returns_404_when_row_missing(): void {
		$result = $this->controller->change_classification_adjutancy(
			$this->make_request( array( 'id' => 7, 'adjutancy_id' => 5 ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_classification_not_found', $result->get_error_code() );
	}

	public function test_change_classification_adjutancy_returns_409_when_unchanged(): void {
		$this->classification( 7, array( 'adjutancy_id' => 5 ) );

		$result = $this->controller->change_classification_adjutancy(
			$this->make_request( array( 'id' => 7, 'adjutancy_id' => 5 ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_classification_adjutancy_unchanged', $result->get_error_code() );
	}

	public function test_change_classification_adjutancy_rejects_when_not_attached_to_notice(): void {
		$this->classification( 7, array( 'adjutancy_id' => 3 ) );

		$result = $this->controller->change_classification_adjutancy(
			$this->make_request( array( 'id' => 7, 'adjutancy_id' => 9 ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_classification_adjutancy_not_attached_to_notice', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertSame( array(), $this->writes );
	}

	public function test_change_classification_adjutancy_success_path_returns_envelope(): void {
		$this->classification( 7, array( 'adjutancy_id' => 3 ) );
		$this->attached[1] = array( 9 );

		$response = $this->controller->change_classification_adjutancy(
			$this->make_request( array( 'id' => 7, 'adjutancy_id' => 9 ) )
		);

		$this->assertNotInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertSame( 7, $data['classification_id'] );
		$this->assertSame( 3, $data['from'] );
		$this->assertSame( 9, $data['to'] );

		$updates = $this->writes_to( 'update', self::T_CLASSIFICATION );
		$this->assertCount( 1, $updates );
		$this->assertSame( array( 'adjutancy_id' => 9 ), $updates[0][2] );
		$this->assertSame( array( 'id' => 7 ), $updates[0][3] );
		$this->assertSame(
			array(
				array(
					'classification_id' => 7,
					'from'              => 3,
					'to'                => 9,
				),
			),
			$this->logged( 'recruitment_classification_adjutancy_changed' )
		);
	}

	public function test_change_classification_adjutancy_returns_500_when_update_fails(): void {
		$this->classification( 7, array( 'adjutancy_id' => 3 ) );
		$this->attached[1] = array( 9 );
		$this->update_result[ self::T_CLASSIFICATION ] = false;

		$result = $this->controller->change_classification_adjutancy(
			$this->make_request( array( 'id' => 7, 'adjutancy_id' => 9 ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_classification_adjutancy_update_failed', $result->get_error_code() );
		$this->assertSame( 500, $result->get_error_data()['status'] );
		$this->assertSame( array(), $this->logged( 'recruitment_classification_adjutancy_changed' ) );
	}

	// ------------------------------------------------------------------
	// permission callbacks (trait)
	// ------------------------------------------------------------------

	public function test_check_admin_cap_allow_and_deny(): void {
		Functions\when( 'current_user_can' )->alias( fn( $c ) => 'ffc_manage_recruitment' === $c );
		$this->assertTrue( $this->controller->check_admin_cap() );

		Functions\when( 'current_user_can' )->justReturn( false );
		$this->assertFalse( $this->controller->check_admin_cap() );
	}

	public function test_check_can_import_csv_allow_and_deny(): void {
		Functions\when( 'current_user_can' )->alias( fn( $c ) => 'ffc_import_recruitment' === $c );
		$this->assertTrue( $this->controller->check_can_import_csv() );

		// Umbrella cap is NOT a fallback for import (GAP H).
		Functions\when( 'current_user_can' )->alias( fn( $c ) => 'ffc_manage_recruitment' === $c );
		$this->assertFalse( $this->controller->check_can_import_csv() );
	}

	public function test_check_can_call_candidates_allow_via_umbrella_and_deny(): void {
		Functions\when( 'current_user_can' )->alias( fn( $c ) => 'ffc_manage_recruitment' === $c );
		$this->assertTrue( $this->controller->check_can_call_candidates() );

		Functions\when( 'current_user_can' )->alias( fn( $c ) => 'ffc_call_recruitment' === $c );
		$this->assertTrue( $this->controller->check_can_call_candidates() );

		Functions\when( 'current_user_can' )->justReturn( false );
		$this->assertFalse( $this->controller->check_can_call_candidates() );
	}

	public function test_check_can_delete_recruitment_allow_and_deny(): void {
		Functions\when( 'current_user_can' )->alias( fn( $c ) => 'ffc_delete_recruitment' === $c );
		$this->assertTrue( $this->controller->check_can_delete_recruitment() );

		Functions\when( 'current_user_can' )->justReturn( false );
		$this->assertFalse( $this->controller->check_can_delete_recruitment() );
	}

	// ------------------------------------------------------------------
	// list_classifications() — adjutancy filter passthrough
	// ------------------------------------------------------------------

	public function test_list_classifications_passes_adjutancy_filter_through(): void {
		$this->controller->list_classifications(
			$this->make_request( array( 'id' => 7, 'adjutancy_id' => 3 ) )
		);

		$this->assertCount( 1, $this->reads );
		$this->assertStringStartsWith(
			'SELECT * FROM ' . self::T_CLASSIFICATION . ' WHERE notice_id = 7 AND adjutancy_id = 3 ORDER BY',
			$this->reads[0]
		);
		$this->assertStringNotContainsString( 'list_type', $this->reads[0] );
	}

	// ------------------------------------------------------------------
	// import_csv() — success + service-failure paths
	// ------------------------------------------------------------------

	public function test_import_csv_success_returns_200_with_preview(): void {
		$this->notice( 7, 'draft' );
		$this->attach( 7, 3, 'teacher' );

		$response = $this->controller->import_csv(
			$this->make_request( array( 'id' => 7 ), $this->csv_files( $this->valid_csv() ) )
		);

		$this->assertNotInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['success'] );
		$this->assertSame( 1, $response->get_data()['inserted'] );

		$inserted = $this->writes_to( 'insert', self::T_CLASSIFICATION );
		$this->assertCount( 1, $inserted );
		$this->assertSame( 'preview', $inserted[0][2]['list_type'] );
		$this->assertSame( 7, $inserted[0][2]['notice_id'] );
		$this->assertSame( 3, $inserted[0][2]['adjutancy_id'] );
	}

	public function test_import_csv_returns_400_when_importer_reports_errors(): void {
		$this->notice( 7, 'draft' );

		$result = $this->controller->import_csv(
			$this->make_request( array( 'id' => 7 ), $this->csv_files( "name,rank\nAna,1\n" ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'recruitment_csv_missing_headers: cpf,rf,email,adjutancy,score,pcd', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	/**
	 * A stored candidate as the identity check reads it.
	 *
	 * @param int         $id   Candidate ID.
	 * @param string|null $cpf  CPF, or null when none is stored.
	 * @param string|null $rf   RF, or null when none is stored.
	 * @param string      $name Stored name.
	 */
	private function stored_identity( int $id, ?string $cpf, ?string $rf, string $name ): object {
		return (object) array(
			'id'         => (string) $id,
			'cpf_hash'   => null === $cpf ? null : SensitiveFieldRegistry::hash_identifier( 'cpf', $cpf ),
			'rf_hash'    => null === $rf ? null : SensitiveFieldRegistry::hash_identifier( 'rf', $rf ),
			'email_hash' => null,
			'name'       => $name,
		);
	}

	public function test_import_csv_asks_before_attaching_a_row_to_a_differently_named_candidate(): void {
		$this->notice( 7, 'draft' );
		$this->attach( 7, 3, 'teacher' );
		$this->stored_identities = array( $this->stored_identity( 40, Identifiers::CPF_A, null, 'Beatriz Lima' ) );

		$result = $this->controller->import_csv(
			$this->make_request( array( 'id' => 7 ), $this->csv_files( $this->valid_csv() ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'recruitment_import_identity_mismatch', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertSame( 409, $data['status'] );
		$this->assertSame(
			array(
				array(
					'line'         => 2,
					'candidate_id' => 40,
					'fields'       => array( 'name' ),
				),
			),
			$data['mismatches']
		);
		$this->assertCount( 1, $data['mismatch_messages'] );
		$this->assertStringNotContainsString( 'Ana', $data['mismatch_messages'][0], 'The report names fields, never values.' );
		$this->assertSame( array(), $this->writes, 'Nothing is written before the operator answers.' );
	}

	public function test_import_csv_keeps_the_stored_identity_once_acknowledged(): void {
		$this->notice( 7, 'draft' );
		$this->attach( 7, 3, 'teacher' );
		$this->stored_identities = array( $this->stored_identity( 40, Identifiers::CPF_A, null, 'Beatriz Lima' ) );

		$response = $this->controller->import_csv(
			$this->make_request(
				array(
					'id'                   => 7,
					'acknowledge_identity' => '1',
				),
				$this->csv_files( $this->valid_csv() )
			)
		);

		$this->assertNotInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 200, $response->get_status() );
		foreach ( $this->writes_to( 'update', self::T_CANDIDATE ) as $update ) {
			$this->assertArrayNotHasKey( 'name', $update[2], 'The stored name is kept.' );
			$this->assertArrayNotHasKey( 'cpf_hash', $update[2], 'The stored CPF is kept.' );
		}
	}

	public function test_import_csv_refuses_a_cpf_and_an_rf_of_two_candidates(): void {
		$this->notice( 7, 'draft' );
		$this->attach( 7, 3, 'teacher' );
		$this->stored_identities = array(
			$this->stored_identity( 40, Identifiers::CPF_A, null, 'Ana' ),
			$this->stored_identity( 41, null, Identifiers::RF_A, 'Bia' ),
		);

		$result = $this->controller->import_csv(
			$this->make_request(
				array(
					'id'                   => 7,
					'acknowledge_identity' => '1',
				),
				$this->csv_files( $this->valid_csv() )
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'line=2: recruitment_csv_identity_conflict', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertSame( array(), $this->writes, 'An acknowledgement does not override a conflict.' );
	}

	// ------------------------------------------------------------------
	// import_job_start()
	// ------------------------------------------------------------------

	public function test_import_job_start_returns_400_when_file_missing(): void {
		$result = $this->controller->import_job_start( $this->make_request( array( 'id' => 7 ), array() ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'recruitment_csv_file_missing', $result->get_error_code() );
	}

	public function test_import_job_start_success_returns_job_envelope(): void {
		$this->notice( 7, 'draft' );
		$this->attach( 7, 3, 'teacher' );

		$response = $this->controller->import_job_start(
			$this->make_request( array( 'id' => 7 ), $this->csv_files( $this->valid_csv() ) )
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'job-1', $response->get_data()['job_id'] );
		$this->assertSame( 1, $response->get_data()['total'] );

		$jobs = $this->writes_to( 'insert', self::T_JOBS );
		$this->assertCount( 1, $jobs );
		$this->assertSame( 'preview', $jobs[0][2]['list_type'] );
	}

	public function test_import_job_start_returns_400_when_ingest_fails(): void {
		$this->notice( 7, 'draft' );

		$result = $this->controller->import_job_start(
			$this->make_request( array( 'id' => 7 ), $this->csv_files( "name,cpf,rf,email,adjutancy,rank,score,pcd\n" ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'recruitment_csv_empty', $result->get_error_code() );
		$this->assertSame( array(), $this->writes_to( 'insert', self::T_JOBS ) );
	}

	// ------------------------------------------------------------------
	// import_job_validate / batch / commit
	// ------------------------------------------------------------------

	public function test_import_job_validate_success(): void {
		$this->jobs['job-1'] = (object) array(
			'job_id' => 'job-1',
			'status' => 'ingested',
		);

		$response = $this->controller->import_job_validate(
			$this->make_request( array( 'job_id' => 'job-1' ) )
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array(), $response->get_data()['errors'] );
		$updates = $this->writes_to( 'update', self::T_JOBS );
		$this->assertCount( 1, $updates );
		$this->assertSame( 'validated', $updates[0][2]['status'] );
		$this->assertSame( array( 'job_id' => 'job-1' ), $updates[0][3] );
	}

	public function test_import_job_validate_failure(): void {
		$result = $this->controller->import_job_validate(
			$this->make_request( array( 'job_id' => 'bad' ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'recruitment_import_job_not_found', $result->get_error_code() );
	}

	public function test_import_job_batch_success_with_explicit_size(): void {
		// The activity log is off here: promote_batch() flushes its buffer to
		// the database, which this double does not hold.
		$this->ffc_settings  = array();
		$this->jobs['job-1'] = (object) array(
			'job_id'          => 'job-1',
			'notice_id'       => 7,
			'list_type'       => 'preview',
			'status'          => 'validated',
			'processed_count' => 0,
			'total'           => 5,
		);
		$this->staged        = array(
			(object) array(
				'id'             => 1,
				'name'           => 'Ana',
				'cpf_normalized' => Identifiers::CPF_A,
				'rf_normalized'  => Identifiers::RF_A,
				'email'          => 'ana@example.com',
				'phone'          => '',
				'pcd'            => 0,
			),
		);

		$response = $this->controller->import_job_batch(
			$this->make_request( array( 'job_id' => 'job-1', 'size' => 25 ) )
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $response->get_data()['done'] );
		$this->assertSame( 1, $response->get_data()['processed'] );

		$batch_reads = array_values(
			array_filter( $this->reads, static fn( $sql ) => false !== strpos( $sql, 'AND processed = 0 ORDER BY row_no LIMIT' ) )
		);
		$this->assertCount( 1, $batch_reads );
		$this->assertStringEndsWith( 'LIMIT 25', $batch_reads[0] );
	}

	public function test_import_job_batch_failure(): void {
		$this->jobs['job-1'] = (object) array(
			'job_id'          => 'job-1',
			'status'          => 'ingested',
			'processed_count' => 0,
			'total'           => 5,
		);

		$result = $this->controller->import_job_batch(
			$this->make_request( array( 'job_id' => 'job-1', 'size' => 10 ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'recruitment_import_job_invalid_state_for_promote', $result->get_error_code() );
	}

	public function test_import_job_commit_success(): void {
		$this->jobs['job-1'] = (object) array(
			'job_id'    => 'job-1',
			'notice_id' => 7,
			'list_type' => 'preview',
			'status'    => 'promoting',
		);
		$this->swapped       = 5;

		$response = $this->controller->import_job_commit(
			$this->make_request( array( 'job_id' => 'job-1' ) )
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 5, $response->get_data()['inserted'] );
		$this->assertSame(
			array(
				array(
					'notice_id'      => 7,
					'list_type'      => 'preview',
					'inserted_count' => 5,
				),
			),
			$this->logged( 'recruitment_csv_imported' )
		);
	}

	public function test_import_job_commit_failure(): void {
		$this->jobs['job-1'] = (object) array(
			'job_id'    => 'job-1',
			'notice_id' => 7,
			'list_type' => 'preview',
			'status'    => 'promoting',
		);
		$this->unpromoted    = 2;

		$result = $this->controller->import_job_commit(
			$this->make_request( array( 'job_id' => 'job-1' ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'recruitment_import_job_not_finished', $result->get_error_code() );
		$this->assertSame( array(), $this->writes_to( 'delete', self::T_CLASSIFICATION ) );
	}

	// ------------------------------------------------------------------
	// promote_preview()
	// ------------------------------------------------------------------

	public function test_promote_preview_snapshot_success(): void {
		$this->notice( 7, 'preliminary' );
		$this->classification( 1, array( 'notice_id' => 7, 'list_type' => 'preview' ) );

		$response = $this->controller->promote_preview(
			$this->make_request( array( 'id' => 7, 'mode' => 'snapshot' ) )
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $response->get_data()['copied'] );
		$this->assertSame( 'definitive', $this->rows[ self::T_NOTICE ][7]->status );
		$copies = $this->writes_to( 'insert', self::T_CLASSIFICATION );
		$this->assertCount( 1, $copies );
		$this->assertSame( 'definitive', $copies[0][2]['list_type'] );
	}

	public function test_promote_preview_snapshot_failure_returns_409(): void {
		$this->notice( 7, 'preliminary' );

		$result = $this->controller->promote_preview(
			$this->make_request( array( 'id' => 7, 'mode' => 'snapshot' ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'recruitment_promotion_no_preview_rows', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
	}

	public function test_promote_preview_definitive_import_missing_file_returns_400(): void {
		$result = $this->controller->promote_preview(
			$this->make_request( array( 'id' => 7, 'mode' => 'definitive_import' ), array() )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'recruitment_csv_file_missing', $result->get_error_code() );
	}

	public function test_promote_preview_definitive_import_importer_failure(): void {
		// No notice 7 is stored, so the importer refuses before parsing.
		$result = $this->controller->promote_preview(
			$this->make_request( array( 'id' => 7, 'mode' => 'definitive_import' ), $this->csv_files( $this->valid_csv() ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'recruitment_notice_not_found', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_promote_preview_definitive_import_refuses_a_draft_before_any_write(): void {
		$this->notice( 7, 'draft' );
		$this->attach( 7, 3, 'teacher' );

		$result = $this->controller->promote_preview(
			$this->make_request( array( 'id' => 7, 'mode' => 'definitive_import' ), $this->csv_files( $this->valid_csv() ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'recruitment_definitive_import_requires_preliminary', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertSame( array(), $this->writes_to( 'delete', self::T_CLASSIFICATION ), 'Refused before any write (#2245).' );
		$this->assertSame( array(), $this->logged( 'recruitment_notice_promoted' ) );
	}

	/**
	 * @dataProvider provider_published_statuses
	 */
	public function test_promote_preview_definitive_import_refuses_a_published_notice( string $status ): void {
		$this->notice( 7, $status );
		$this->attach( 7, 3, 'teacher' );

		$result = $this->controller->promote_preview(
			$this->make_request( array( 'id' => 7, 'mode' => 'definitive_import' ), $this->csv_files( $this->valid_csv() ) )
		);

		$this->assertSame( 'recruitment_definitive_import_requires_preliminary', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertSame( array(), $this->writes_to( 'delete', self::T_CLASSIFICATION ) );
		$this->assertSame( $status, $this->rows[ self::T_NOTICE ][7]->status );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function provider_published_statuses(): array {
		return array(
			'definitive' => array( 'definitive' ),
			'closed'     => array( 'closed' ),
		);
	}

	public function test_promote_preview_definitive_import_refuses_a_notice_with_calls(): void {
		$this->notice( 7, 'preliminary' );
		$this->attach( 7, 3, 'teacher' );
		$this->notice_calls = 2;

		$result = $this->controller->promote_preview(
			$this->make_request( array( 'id' => 7, 'mode' => 'definitive_import' ), $this->csv_files( $this->valid_csv() ) )
		);

		$this->assertSame( 'recruitment_definitive_import_blocked_by_calls', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertSame( array(), $this->writes_to( 'delete', self::T_CLASSIFICATION ) );
	}

	public function test_promote_preview_definitive_import_full_success(): void {
		$this->notice( 7, 'preliminary' );
		$this->attach( 7, 3, 'teacher' );

		$response = $this->controller->promote_preview(
			$this->make_request( array( 'id' => 7, 'mode' => 'definitive_import' ), $this->csv_files( $this->valid_csv() ) )
		);

		$this->assertNotInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'definitive', $this->rows[ self::T_NOTICE ][7]->status );
		$this->assertSame(
			array(
				array(
					'notice_id' => 7,
					'mode'      => 'definitive_import',
					'copied'    => 0,
				),
			),
			$this->logged( 'recruitment_notice_promoted' )
		);
	}

	// ------------------------------------------------------------------
	// call_classification()
	// ------------------------------------------------------------------

	public function test_call_classification_success_returns_201(): void {
		$this->classification( 5 );
		$wp_mail = CallLog::record( 'wp_mail', true );

		$response = $this->controller->call_classification(
			$this->make_request(
				array(
					'id'             => 5,
					'date_to_assume' => '2026-05-20',
					'time_to_assume' => '09:00',
				)
			)
		);

		$this->assertSame( 201, $response->get_status() );
		$calls = $this->writes_to( 'insert', self::T_CALL );
		$this->assertCount( 1, $calls );
		$this->assertSame( 5, $calls[0][2]['classification_id'] );
		$this->assertSame( '2026-05-20', $calls[0][2]['date_to_assume'] );
		$this->assertSame( '09:00', $calls[0][2]['time_to_assume'] );
		$this->assertSame( self::OPERATOR, $calls[0][2]['created_by'] );
		$this->assertSame( 0, $calls[0][2]['out_of_order'] );
		$this->assertNull( $calls[0][2]['out_of_order_reason'] );
		$this->assertNull( $calls[0][2]['notes'] );
		// No notify_email in the request reaches the dispatcher as null, which
		// the `ask` mode reads as "don't send": the e-mail path is never entered.
		$this->assertSame( 0, $wp_mail->count() );
		$this->assertSame(
			array(),
			array_filter( $this->reads, static fn( $sql ) => 0 === strpos( $sql, 'SELECT * FROM ' . self::T_CALL ) )
		);
	}

	public function test_call_classification_passes_reason_and_notes(): void {
		// Classification 4 ranks first, so calling 5 is out of order.
		$this->classification( 4 );
		$this->classification( 5 );

		$response = $this->controller->call_classification(
			$this->make_request(
				array(
					'id'                  => 5,
					'date_to_assume'      => '2026-05-20',
					'time_to_assume'      => '09:00',
					'out_of_order_reason' => 'skip',
					'notes'               => 'note here',
				)
			)
		);

		$this->assertSame( 201, $response->get_status() );
		$calls = $this->writes_to( 'insert', self::T_CALL );
		$this->assertCount( 1, $calls );
		$this->assertSame( 1, $calls[0][2]['out_of_order'] );
		$this->assertSame( 'skip', $calls[0][2]['out_of_order_reason'] );
		$this->assertSame( 'note here', $calls[0][2]['notes'] );
	}

	public function test_call_classification_failure_returns_409(): void {
		$this->classification( 5, array( 'status' => 'called' ) );

		$result = $this->controller->call_classification(
			$this->make_request(
				array( 'id' => 5, 'date_to_assume' => '2026-05-20', 'time_to_assume' => '09:00' )
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'recruitment_classification_not_empty', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertSame( array(), $this->writes_to( 'insert', self::T_CALL ) );
	}

	// ------------------------------------------------------------------
	// bulk_call_classifications()
	// ------------------------------------------------------------------

	public function test_bulk_call_returns_400_when_id_list_empty(): void {
		$result = $this->controller->bulk_call_classifications(
			$this->make_request( array( 'classification_ids' => array() ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'recruitment_bulk_call_empty_id_list', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_bulk_call_returns_400_when_ids_not_array(): void {
		$result = $this->controller->bulk_call_classifications(
			$this->make_request( array( 'classification_ids' => 'nope' ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'recruitment_bulk_call_empty_id_list', $result->get_error_code() );
	}

	public function test_bulk_call_success_returns_201_with_filtered_ids(): void {
		// Classification 1 ranks first and is not called, so 3 and 5 are both
		// out of order and each needs the reason keyed by its own id.
		$this->classification( 1 );
		$this->classification( 3 );
		$this->classification( 5 );

		$response = $this->controller->bulk_call_classifications(
			$this->make_request(
				array(
					'classification_ids'   => array( '3', '0', 'x', 5 ),
					'date_to_assume'       => '2026-05-20',
					'time_to_assume'       => '09:00',
					'out_of_order_reasons' => array(
						3 => 'a',
						5 => 'b',
					),
					'notes'                => 'bulk note',
				)
			)
		);

		$this->assertSame( 201, $response->get_status() );
		$calls = $this->writes_to( 'insert', self::T_CALL );
		$this->assertSame( array( 3, 5 ), array_map( static fn( $w ) => $w[2]['classification_id'], $calls ) );
		$this->assertSame( array( 'a', 'b' ), array_map( static fn( $w ) => $w[2]['out_of_order_reason'], $calls ) );
		$this->assertSame( array( 'bulk note', 'bulk note' ), array_map( static fn( $w ) => $w[2]['notes'], $calls ) );
		$this->assertSame( array( self::OPERATOR, self::OPERATOR ), array_map( static fn( $w ) => $w[2]['created_by'], $calls ) );
		$this->assertSame( 'empty', $this->rows[ self::T_CLASSIFICATION ][1]->status );
	}

	public function test_bulk_call_failure_returns_409(): void {
		$result = $this->controller->bulk_call_classifications(
			$this->make_request(
				array(
					'classification_ids' => array( 1, 2 ),
					'date_to_assume'     => '2026-05-20',
					'time_to_assume'     => '09:00',
				)
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'recruitment_bulk_call_failed_at_classification: 1', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
	}

	// ------------------------------------------------------------------
	// change_classification_status()
	// ------------------------------------------------------------------

	public function test_change_status_success(): void {
		$this->classification( 5, array( 'status' => 'called' ) );

		$response = $this->controller->change_classification_status(
			$this->make_request( array( 'id' => 5, 'status' => 'hired', 'reason' => 'good fit' ) )
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'hired', $this->rows[ self::T_CLASSIFICATION ][5]->status );
		$this->assertSame(
			array(
				array(
					'classification_id' => 5,
					'from'              => 'called',
					'to'                => 'hired',
					'reason'            => 'good fit',
				),
			),
			$this->logged( 'recruitment_classification_status_changed' )
		);
	}

	public function test_change_status_null_reason_when_absent(): void {
		$this->classification( 5, array( 'status' => 'called' ) );

		$response = $this->controller->change_classification_status(
			$this->make_request( array( 'id' => 5, 'status' => 'hired' ) )
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				array(
					'classification_id' => 5,
					'from'              => 'called',
					'to'                => 'hired',
				),
			),
			$this->logged( 'recruitment_classification_status_changed' )
		);
	}

	public function test_change_status_failure_returns_409(): void {
		$this->classification( 5 );

		$result = $this->controller->change_classification_status(
			$this->make_request( array( 'id' => 5, 'status' => 'bogus' ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'recruitment_invalid_transition: empty->bogus', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertSame( 'empty', $this->rows[ self::T_CLASSIFICATION ][5]->status );
	}

	// ------------------------------------------------------------------
	// override_classification_to_empty() — success
	// ------------------------------------------------------------------

	public function test_override_to_empty_success(): void {
		$this->classification( 5, array( 'status' => 'hired' ) );

		$response = $this->controller->override_classification_to_empty(
			$this->make_request( array( 'id' => 5, 'reason' => 'undo' ) )
		);

		$this->assertNotInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'empty', $this->rows[ self::T_CLASSIFICATION ][5]->status );
		$logged = $this->logged( 'recruitment_classification_override_to_empty' );
		$this->assertCount( 1, $logged );
		$this->assertSame( 'undo', $logged[0]['reason'] );
	}

	public function test_override_to_empty_service_failure_returns_409(): void {
		$this->classification( 5, array( 'status' => 'called' ) );

		$result = $this->controller->override_classification_to_empty(
			$this->make_request( array( 'id' => 5, 'reason' => 'undo' ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'recruitment_override_not_overridable: called', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
	}

	// ------------------------------------------------------------------
	// change_classification_preview_status()
	// ------------------------------------------------------------------

	public function test_preview_status_404_when_classification_missing(): void {
		$result = $this->controller->change_classification_preview_status(
			$this->make_request( array( 'id' => 5, 'preview_status' => 'granted' ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'recruitment_classification_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_preview_status_409_when_not_preview_list(): void {
		$this->classification( 5, array( 'list_type' => 'definitive' ) );

		$result = $this->controller->change_classification_preview_status(
			$this->make_request( array( 'id' => 5, 'preview_status' => 'granted' ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'recruitment_preview_status_only_on_preview_list', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
	}

	public function test_preview_status_400_when_status_invalid(): void {
		$this->classification( 5, array( 'list_type' => 'preview' ) );

		$result = $this->controller->change_classification_preview_status(
			$this->make_request( array( 'id' => 5, 'preview_status' => 'bogus' ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'recruitment_preview_status_invalid', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_preview_status_400_when_reason_required_but_missing(): void {
		$this->classification( 5, array( 'list_type' => 'preview' ) );
		$this->recruitment_settings = array( 'preview_reason_required_granted' => true );

		$result = $this->controller->change_classification_preview_status(
			$this->make_request( array( 'id' => 5, 'preview_status' => 'granted' ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'recruitment_preview_reason_required', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_preview_status_404_when_reason_not_found(): void {
		$this->classification( 5, array( 'list_type' => 'preview' ) );

		$result = $this->controller->change_classification_preview_status(
			$this->make_request(
				array( 'id' => 5, 'preview_status' => 'granted', 'preview_reason_id' => 8 )
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'recruitment_preview_reason_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_preview_status_400_when_reason_status_mismatch(): void {
		$this->classification( 5, array( 'list_type' => 'preview' ) );
		$this->store( self::T_REASON, array( 'id' => 8, 'applies_to' => 'denied' ) );

		$result = $this->controller->change_classification_preview_status(
			$this->make_request(
				array( 'id' => 5, 'preview_status' => 'granted', 'preview_reason_id' => 8 )
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'recruitment_preview_reason_status_mismatch', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_preview_status_400_when_update_fails(): void {
		$this->classification( 5, array( 'list_type' => 'preview' ) );
		$this->update_result[ self::T_CLASSIFICATION ] = false;

		$result = $this->controller->change_classification_preview_status(
			$this->make_request( array( 'id' => 5, 'preview_status' => 'granted' ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'recruitment_preview_status_update_failed', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$updates = $this->writes_to( 'update', self::T_CLASSIFICATION );
		$this->assertCount( 1, $updates );
		$this->assertSame( 'granted', $updates[0][2]['preview_status'] );
		$this->assertNull( $updates[0][2]['preview_reason_id'] );
		$this->assertSame( array( 'id' => 5 ), $updates[0][3] );
	}

	public function test_preview_status_success_with_valid_reason(): void {
		$this->classification( 5, array( 'list_type' => 'preview' ) );
		$this->store( self::T_REASON, array( 'id' => 8, 'applies_to' => 'granted' ) );

		$response = $this->controller->change_classification_preview_status(
			$this->make_request(
				array( 'id' => 5, 'preview_status' => 'granted', 'preview_reason_id' => 8 )
			)
		);

		$this->assertNotInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $this->rows[ self::T_CLASSIFICATION ][5], $response->get_data() );
		$this->assertSame( 'granted', $response->get_data()->preview_status );
		$this->assertSame( 8, $response->get_data()->preview_reason_id );
	}

	public function test_preview_status_empty_clears_reason_and_succeeds(): void {
		$this->classification( 5, array( 'list_type' => 'preview', 'preview_reason_id' => 8 ) );

		$response = $this->controller->change_classification_preview_status(
			$this->make_request(
				array( 'id' => 5, 'preview_status' => 'empty', 'preview_reason_id' => 8 )
			)
		);

		$this->assertSame( 200, $response->get_status() );
		// 'empty' forces the reason to 0, which the setter stores as null.
		$updates = $this->writes_to( 'update', self::T_CLASSIFICATION );
		$this->assertCount( 1, $updates );
		$this->assertSame( 'empty', $updates[0][2]['preview_status'] );
		$this->assertNull( $updates[0][2]['preview_reason_id'] );
	}

	// ------------------------------------------------------------------
	// delete_classification()
	// ------------------------------------------------------------------

	public function test_delete_classification_success(): void {
		$this->classification( 5 );
		$this->notice( 1, 'draft' );

		$response = $this->controller->delete_classification(
			$this->make_request( array( 'id' => 5 ) )
		);

		$this->assertSame( 200, $response->get_status() );
		$deletes = $this->writes_to( 'delete', self::T_CLASSIFICATION );
		$this->assertCount( 1, $deletes );
		$this->assertSame( array( 'id' => 5 ), $deletes[0][3] );
	}

	/**
	 * The delete service refuses a classification that has been called, and
	 * that refusal carries no reference counts: only the candidate and
	 * adjutancy deletions report `blocked_by`.
	 */
	public function test_delete_classification_refused_returns_409_without_blocked_by(): void {
		$this->classification( 5, array( 'status' => 'called' ) );

		$result = $this->controller->delete_classification(
			$this->make_request( array( 'id' => 5 ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'recruitment_classification_not_empty_for_delete', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertArrayNotHasKey( 'blocked_by', $result->get_error_data() );
		$this->assertSame( array(), $this->writes_to( 'delete', self::T_CLASSIFICATION ) );
	}

	// ------------------------------------------------------------------
	// cancel_call()
	// ------------------------------------------------------------------

	public function test_cancel_call_success(): void {
		$this->classification( 5, array( 'status' => 'called' ) );
		$this->store(
			self::T_CALL,
			array(
				'id'                => 99,
				'classification_id' => 5,
				'cancelled_at'      => null,
			)
		);

		$response = $this->controller->cancel_call(
			$this->make_request( array( 'call_id' => 99, 'reason' => 'mistake' ) )
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'empty', $this->rows[ self::T_CLASSIFICATION ][5]->status );
		$cancels = array_values(
			array_filter( $this->queries, static fn( $sql ) => 0 === strpos( $sql, 'UPDATE ' . self::T_CALL . ' SET cancellation_reason' ) )
		);
		$this->assertCount( 1, $cancels );
		$this->assertStringContainsString( 'cancellation_reason = mistake', $cancels[0] );
		$this->assertStringContainsString( 'cancelled_by = ' . self::OPERATOR, $cancels[0] );
		$this->assertStringContainsString( 'WHERE id = 99', $cancels[0] );
	}

	public function test_cancel_call_failure_returns_409(): void {
		$this->store(
			self::T_CALL,
			array(
				'id'                => 99,
				'classification_id' => 5,
				'cancelled_at'      => 1800000000,
			)
		);

		$result = $this->controller->cancel_call(
			$this->make_request( array( 'call_id' => 99, 'reason' => 'mistake' ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'recruitment_call_already_cancelled', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
	}
}
