<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Core\SensitiveFieldRegistry;
use FreeFormCertificate\Recruitment\CandidatePersister;
use FreeFormCertificate\Recruitment\RecruitmentPcdHasher;
use FreeFormCertificate\Tests\Support\ActivityLogBuffer;
use FreeFormCertificate\Tests\Support\Identifiers;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CandidatePersister.
 *
 * Runs the real collaborators — CsvParser, SensitiveFieldRegistry,
 * DataSanitizer, Encryption (with the bootstrap's key constants), the
 * candidate Reader/Writer, RecruitmentPcdHasher, UserCreator,
 * CapabilityManager, UserProfileRepository, the adjutancy readers and
 * RecruitmentActivityLogger down to the real ActivityLog write buffer — over
 * a `$wpdb` double that answers each query by its SQL text and bound values
 * from the rows a test sets, records every write, and fails the test on a
 * query it was not taught.
 *
 * Coverage targets every public path:
 *   - upsert_candidate(): new-candidate insert (full PII), insert with empty
 *     fields, insert DB failure, existing-candidate update (matched by cpf,
 *     then by rf), and the wp_user promotion branch.
 *   - build_adjutancy_map(): empty notice and a populated slug→id map.
 *
 * @covers \FreeFormCertificate\Recruitment\CandidatePersister
 */
class CandidatePersisterTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const CANDIDATES = 'wp_ffc_recruitment_candidate';

	private const PROFILES = 'wp_ffc_user_profiles';

	/** @var Mockery\MockInterface */
	private $wpdb;

	/** @var mixed The `$wpdb` global before the test, restored in tearDown(). */
	private $previous_wpdb;

	/** Whether `$wpdb` was set before the test. */
	private bool $had_wpdb = false;

	/** @var list<array{0: string, 1: array<int, mixed>}> Every prepared statement: [ sql, args ]. */
	private array $prepared = array();

	/** @var array<string, object> Stored candidates by cpf_hash. */
	private array $by_cpf = array();

	/** @var array<string, object> Stored candidates by rf_hash. */
	private array $by_rf = array();

	/** @var array<string, string> The user id the identity lookup finds, by table. */
	private array $identity_owner = array();

	/** @var array<string, mixed>|null The `ffc_user_profiles` row findByUserId() answers. */
	private ?array $profile_row = null;

	/** @var array<int, int> Adjutancy ids attached to each notice. */
	private array $notice_adjutancies = array();

	/** @var array<int, object> Stored adjutancies by id. */
	private array $adjutancies = array();

	/** @var int|false What `insert()` answers. */
	private $insert_result = 1;

	/** @var list<array{table: string, data: array<string, mixed>}> Every insert. */
	private array $inserts = array();

	/** @var list<array{table: string, data: array<string, mixed>, where: array<string, mixed>}> Every update. */
	private array $updates = array();

	/** @var list<string> Every raw query() statement. */
	private array $queries = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->had_wpdb      = array_key_exists( 'wpdb', $GLOBALS );
		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;

		ActivityLogBuffer::reset();
		ActivityLogBuffer::stub_functions();
		Functions\when( 'get_option' )->alias(
			static fn( $key, $fallback = false ) => 'ffc_settings' === $key ? ActivityLogBuffer::ENABLED_SETTINGS : $fallback
		);
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'current_time' )->justReturn( '2026-06-28 10:00:00' );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'wp_salt' )->justReturn( 'test-salt' );

		// Account creation fails, which UserCreator reports as a WP_Error and
		// the persister swallows: the candidate stays unpromoted.
		Functions\when( 'get_user_by' )->justReturn( false );
		Functions\when( 'username_exists' )->justReturn( false );
		Functions\when( 'remove_accents' )->returnArg();
		Functions\when( 'sanitize_user' )->returnArg();
		Functions\when( 'wp_generate_password' )->justReturn( 'generated-password' );
		Functions\when( 'wp_create_user' )->justReturn( new \WP_Error( 'existing_user_login', 'refused' ) );

		$this->wpdb         = Mockery::mock( 'wpdb' );
		$this->wpdb->prefix = 'wp_';
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( $sql, ...$args ) {
				if ( 1 === count( $args ) && is_array( $args[0] ) ) {
					$args = $args[0];
				}
				$this->prepared[] = array( (string) $sql, array_values( $args ) );
				return 'Q' . ( count( $this->prepared ) - 1 );
			}
		);
		$this->wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function ( $query ) {
				[ $sql, $args ] = $this->statement( $query );
				if ( self::CANDIDATES === $args[0] && false !== strpos( $sql, 'WHERE cpf_hash = %s LIMIT 1' ) ) {
					return $this->by_cpf[ $args[1] ] ?? null;
				}
				if ( self::CANDIDATES === $args[0] && false !== strpos( $sql, 'WHERE rf_hash = %s LIMIT 1' ) ) {
					return $this->by_rf[ $args[1] ] ?? null;
				}
				if ( self::PROFILES === $args[0] && false !== strpos( $sql, 'WHERE user_id = %d' ) ) {
					return $this->profile_row;
				}
				if ( 'wp_ffc_recruitment_adjutancy' === $args[0] && false !== strpos( $sql, 'WHERE id = %d' ) ) {
					return $this->adjutancies[ $args[1] ] ?? null;
				}
				$this->fail( 'Unexpected get_row: ' . $sql );
			}
		);
		$this->wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( $query ) {
				[ $sql, $args ] = $this->statement( $query );
				if ( false !== strpos( $sql, 'SELECT user_id FROM %i WHERE (' ) ) {
					return $this->identity_owner[ $args[0] ] ?? null;
				}
				if ( false !== strpos( $sql, 'SHOW TABLES LIKE' ) ) {
					return null;
				}
				$this->fail( 'Unexpected get_var: ' . $sql );
			}
		);
		$this->wpdb->shouldReceive( 'get_col' )->andReturnUsing(
			function ( $query ) {
				[ $sql, $args ] = $this->statement( $query );
				if ( 'wp_ffc_recruitment_notice_adjutancy' === $args[0] && false !== strpos( $sql, 'WHERE notice_id = %d' ) ) {
					return array_map( 'strval', $this->notice_adjutancies[ $args[1] ] ?? array() );
				}
				$this->fail( 'Unexpected get_col: ' . $sql );
			}
		);
		$this->wpdb->shouldReceive( 'query' )->andReturnUsing(
			function ( $query ) {
				[ $sql ] = $this->statement( $query );
				if ( 0 === strpos( $sql, 'UPDATE %i SET user_id = %d' ) ) {
					$this->queries[] = $sql;
					return 0;
				}
				$this->fail( 'Unexpected query: ' . $sql );
			}
		);
		$this->wpdb->shouldReceive( 'insert' )->andReturnUsing(
			function ( $table, $data ) {
				$this->inserts[] = array(
					'table' => $table,
					'data'  => $data,
				);
				return $this->insert_result;
			}
		);
		$this->wpdb->shouldReceive( 'update' )->andReturnUsing(
			function ( $table, $data, $where ) {
				$this->updates[] = array(
					'table' => $table,
					'data'  => $data,
					'where' => $where,
				);
				return 1;
			}
		);

		$GLOBALS['wpdb'] = $this->wpdb;
	}

	protected function tearDown(): void {
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->previous_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		ActivityLogBuffer::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The SQL and bound values a prepared token stands for.
	 *
	 * @param mixed $query Token returned by prepare().
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	private function statement( $query ): array {
		$index = (int) substr( (string) $query, 1 );
		$this->assertArrayHasKey( $index, $this->prepared, 'Every query goes through prepare().' );
		return $this->prepared[ $index ];
	}

	/**
	 * How many prepared statements contain a fragment.
	 *
	 * @param string $fragment SQL fragment.
	 * @return int
	 */
	private function preparedCount( string $fragment ): int {
		return count(
			array_filter(
				$this->prepared,
				static fn( array $p ): bool => false !== strpos( $p[0], $fragment )
			)
		);
	}

	/**
	 * Updates sent to the candidate table, in order.
	 *
	 * @return list<array{table: string, data: array<string, mixed>, where: array<string, mixed>}>
	 */
	private function candidateUpdates(): array {
		return array_values(
			array_filter(
				$this->updates,
				static fn( array $u ): bool => self::CANDIDATES === $u['table']
			)
		);
	}

	private function row( array $overrides = array() ): array {
		return array_merge(
			array(
				'cpf'   => Identifiers::CPF_A,
				'rf'    => Identifiers::RF_A,
				'email' => 'Person@Example.COM',
				'name'  => '  Maria  ',
				'phone' => '11999998888',
				'pcd'   => 'sim',
			),
			$overrides
		);
	}

	public function test_upsert_inserts_new_candidate_with_full_pii(): void {
		$this->wpdb->insert_id = 42;

		$id = CandidatePersister::upsert_candidate( $this->row() );

		$this->assertSame( 42, $id );
		$this->assertCount( 1, $this->inserts );
		$this->assertSame( self::CANDIDATES, $this->inserts[0]['table'] );
		$captured = $this->inserts[0]['data'];
		$this->assertSame( 'Maria', $captured['name'] );
		$this->assertSame( 'pending', $captured['pcd_hash'] );
		$this->assertSame( Identifiers::CPF_A, Encryption::decrypt( $captured['cpf_encrypted'] ) );
		$this->assertSame( SensitiveFieldRegistry::hash_identifier( 'cpf', Identifiers::CPF_A ), $captured['cpf_hash'] );
		$this->assertSame( Identifiers::RF_A, Encryption::decrypt( $captured['rf_encrypted'] ) );
		// Email is lowercased before encryption/hash.
		$this->assertSame( 'person@example.com', Encryption::decrypt( $captured['email_encrypted'] ) );
		$this->assertSame( Encryption::hash( 'person@example.com' ), $captured['email_hash'] );
		$this->assertSame( '11999998888', $captured['phone'] );

		// The placeholder is replaced by the real PCD hash once the id is known.
		$updates = $this->candidateUpdates();
		$this->assertCount( 1, $updates, 'Only the pcd_hash refresh; no promotion link.' );
		$this->assertSame( array( 'id' => 42 ), $updates[0]['where'] );
		$this->assertSame( RecruitmentPcdHasher::compute( 42, true ), $updates[0]['data']['pcd_hash'] );
	}

	public function test_upsert_insert_omits_empty_optional_fields(): void {
		$this->wpdb->insert_id = 7;

		// All PII empty: no cpf/rf/email hashes, no promotion attempt.
		$row = $this->row(
			array(
				'cpf'   => '',
				'rf'    => '',
				'email' => '',
				'phone' => '',
				'name'  => 'OnlyName',
			)
		);

		$id = CandidatePersister::upsert_candidate( $row );

		$this->assertSame( 7, $id );
		$captured = $this->inserts[0]['data'];
		$this->assertSame( 'OnlyName', $captured['name'] );
		$this->assertArrayNotHasKey( 'cpf_encrypted', $captured );
		$this->assertArrayNotHasKey( 'rf_encrypted', $captured );
		$this->assertArrayNotHasKey( 'email_encrypted', $captured );
		$this->assertArrayNotHasKey( 'phone', $captured );
		$this->assertSame( 0, $this->preparedCount( 'cpf_hash = %s' ), 'No lookup without an identifier.' );
		$this->assertSame( 0, $this->preparedCount( 'SELECT user_id FROM %i WHERE (' ), 'No promotion attempt.' );
	}

	public function test_upsert_returns_false_on_insert_failure(): void {
		$this->insert_result = false;

		$result = CandidatePersister::upsert_candidate( $this->row() );

		$this->assertFalse( $result );
		$this->assertCount( 1, $this->inserts );
		$this->assertSame( array(), $this->updates, 'Nothing is refreshed after a failed insert.' );
	}

	public function test_upsert_updates_existing_candidate_matched_by_cpf(): void {
		$cpf_hash                  = (string) SensitiveFieldRegistry::hash_identifier( 'cpf', Identifiers::CPF_A );
		$this->by_cpf[ $cpf_hash ] = (object) array( 'id' => '99' );

		$id = CandidatePersister::upsert_candidate( $this->row() );

		$this->assertSame( 99, $id );
		$this->assertSame( 1, $this->preparedCount( 'WHERE cpf_hash = %s LIMIT 1' ) );
		// rf lookup must NOT happen when cpf already matched.
		$this->assertSame( 0, $this->preparedCount( 'WHERE rf_hash = %s LIMIT 1' ) );
		$this->assertSame( array(), $this->inserts, 'An existing candidate is never re-created.' );

		$updates = $this->candidateUpdates();
		$this->assertSame( array( 'id' => 99 ), $updates[0]['where'] );
		// array_filter drops nulls; full row keeps all keys.
		$this->assertArrayHasKey( 'cpf_hash', $updates[0]['data'] );
		$this->assertArrayHasKey( 'email_hash', $updates[0]['data'] );
		$this->assertSame( 'Maria', $updates[0]['data']['name'] );
	}

	public function test_upsert_falls_back_to_rf_lookup_when_cpf_misses(): void {
		$rf_hash                 = (string) SensitiveFieldRegistry::hash_identifier( 'rf', Identifiers::RF_A );
		$this->by_rf[ $rf_hash ] = (object) array( 'id' => 5 );

		$id = CandidatePersister::upsert_candidate( $this->row( array( 'pcd' => 'no' ) ) );

		$this->assertSame( 5, $id );
		$this->assertSame( 1, $this->preparedCount( 'WHERE cpf_hash = %s LIMIT 1' ) );
		$this->assertSame( 1, $this->preparedCount( 'WHERE rf_hash = %s LIMIT 1' ) );
		$this->assertSame( array(), $this->inserts );
		$updates = $this->candidateUpdates();
		$this->assertArrayHasKey( 'name', $updates[0]['data'], 'The first write is the field refresh.' );
		$this->assertSame( array( 'id' => 5 ), $updates[0]['where'] );
	}

	public function test_upsert_promotes_candidate_when_user_resolved(): void {
		$this->wpdb->insert_id = 314;
		$cpf_hash              = SensitiveFieldRegistry::hash_identifier( 'cpf', Identifiers::CPF_A );
		$rf_hash               = SensitiveFieldRegistry::hash_identifier( 'rf', Identifiers::RF_A );
		// The identity index already knows the person, with both hashes.
		$this->identity_owner[ self::PROFILES ] = '808';
		$this->profile_row                      = array(
			'user_id'  => '808',
			'cpf_hash' => $cpf_hash,
			'rf_hash'  => $rf_hash,
		);

		$id = CandidatePersister::upsert_candidate( $this->row( array( 'pcd' => 'no' ) ) );

		$this->assertSame( 314, $id );
		$links = array_values(
			array_filter(
				$this->candidateUpdates(),
				static fn( array $u ): bool => array_key_exists( 'user_id', $u['data'] )
			)
		);
		$this->assertCount( 1, $links );
		$this->assertSame( 808, $links[0]['data']['user_id'] );
		$this->assertSame( array( 'id' => 314 ), $links[0]['where'] );

		$logged = array_values(
			array_filter(
				ActivityLogBuffer::contexts(),
				static fn( array $e ): bool => 'recruitment_candidate_promoted' === $e['action']
			)
		);
		$this->assertCount( 1, $logged );
		$this->assertSame(
			array(
				'candidate_id' => 314,
				'user_id'      => 808,
			),
			$logged[0]['context']
		);
	}

	public function test_build_adjutancy_map_returns_empty_when_no_ids(): void {
		$this->assertSame( array(), CandidatePersister::build_adjutancy_map( 11 ) );
		$this->assertSame( 1, $this->preparedCount( 'WHERE notice_id = %d' ) );
		$this->assertSame( 0, $this->preparedCount( 'WHERE id = %d' ), 'No adjutancy is read.' );
	}

	public function test_build_adjutancy_map_builds_slug_to_id_map(): void {
		$this->notice_adjutancies[11] = array( 1, 2, 3 );
		$this->adjutancies[1]         = (object) array( 'slug' => 'norte' );
		// id 2 resolves to null → skipped.
		$this->adjutancies[3] = (object) array( 'slug' => 'sul' );

		$map = CandidatePersister::build_adjutancy_map( 11 );

		$this->assertSame(
			array(
				'norte' => 1,
				'sul'   => 3,
			),
			$map
		);
	}
}
