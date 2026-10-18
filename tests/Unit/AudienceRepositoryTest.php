<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Audience\AudienceReader;
use FreeFormCertificate\Audience\AudienceWriter;

/**
 * Tests for the audience repository read/write split: table names, CRUD
 * operations, hierarchical queries, membership management, and caching.
 *
 * @covers \FreeFormCertificate\Audience\AudienceReader
 * @covers \FreeFormCertificate\Audience\AudienceWriter
 */
class AudienceRepositoryTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		global $wpdb;
		$wpdb = Mockery::mock('wpdb');
		$wpdb->prefix = 'wp_';
		$wpdb->last_error = '';
		$this->wpdb = $wpdb;

		Functions\when('wp_cache_get')->justReturn(false);
		Functions\when('wp_cache_set')->justReturn(true);
		Functions\when('wp_cache_delete')->justReturn(true);
		// CacheVersion (query-cache invalidation) reads/writes a wp_options counter.
		Functions\when('get_option')->justReturn(false);
		Functions\when('update_option')->justReturn(true);
		Functions\when('__')->returnArg();

		// pcov does not record lines for files first autoloaded mid-test-method,
		// so preload the reader/writer here for correct coverage attribution.
		class_exists('\\FreeFormCertificate\\Audience\\AudienceReader');
		class_exists('\\FreeFormCertificate\\Audience\\AudienceWriter');
		Functions\when('wp_parse_args')->alias(function($args, $defaults = array()) {
			return array_merge($defaults, $args);
		});
		Functions\when('sanitize_text_field')->alias('trim');
		Functions\when('sanitize_hex_color')->alias(function($color) {
			return $color;
		});
		Functions\when('absint')->alias(function($val) {
			return abs(intval($val));
		});
		Functions\when('get_current_user_id')->justReturn(1);
		Functions\when('sanitize_sql_orderby')->alias(function($orderby) {
			// Simplified: return the value if it looks safe, false otherwise
			if (preg_match('/^[a-zA-Z_]+\s+(ASC|DESC)$/i', $orderby)) {
				return $orderby;
			}
			return false;
		});
		Functions\when('wp_json_encode')->alias('json_encode');
		// `add_member()` grants the audience capability (#1302), and
		// `grant_audience_capabilities()` opens with `get_userdata()`. Returning
		// false makes it return at once, so this file keeps measuring the
		// repository rather than the capability grant, which has its own tests
		// in `AudienceMembershipCapabilityTest`.
		Functions\when('get_userdata')->justReturn(false);

		$this->wpdb->shouldReceive('prepare')->andReturnUsing(function() {
			return func_get_args()[0];
		})->byDefault();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ==================================================================
	// Table names
	// ==================================================================

	public function test_get_table_name_returns_audiences_table(): void {
		$this->assertSame('wp_ffc_audiences', AudienceReader::get_table_name());
	}

	public function test_get_members_table_name_returns_audience_members_table(): void {
		$this->assertSame('wp_ffc_audience_members', AudienceReader::get_members_table_name());
	}

	public function test_table_names_use_wpdb_prefix(): void {
		$this->wpdb->prefix = 'custom_';

		$this->assertSame('custom_ffc_audiences', AudienceReader::get_table_name());
		$this->assertSame('custom_ffc_audience_members', AudienceReader::get_members_table_name());

		// Restore
		$this->wpdb->prefix = 'wp_';
	}

	// ==================================================================
	// get_all()
	// ==================================================================

	public function test_get_all_returns_all_audiences_with_no_filters(): void {
		$rows = [
			(object) ['id' => 1, 'name' => 'Audience A', 'parent_id' => null, 'status' => 'active'],
			(object) ['id' => 2, 'name' => 'Audience B', 'parent_id' => null, 'status' => 'active'],
		];

		$this->wpdb->shouldReceive('prepare')->once()->andReturn('SELECT * FROM wp_ffc_audiences ORDER BY name ASC');
		$this->wpdb->shouldReceive('get_results')->once()->andReturn($rows);

		$result = AudienceReader::get_all();

		$this->assertCount(2, $result);
		$this->assertSame('Audience A', $result[0]->name);
	}

	public function test_get_all_filters_by_parent_id_zero_for_parents(): void {
		$captured_sql = '';
		$this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
			$captured_sql = func_get_args()[0];
			return $captured_sql;
		});
		$this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

		AudienceReader::get_all(['parent_id' => 0]);

		$this->assertStringContainsString('parent_id IS NULL', $captured_sql);
	}

	public function test_get_all_filters_by_specific_parent_id(): void {
		$captured_sql = '';
		$this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
			$captured_sql = func_get_args()[0];
			return $captured_sql;
		});
		$this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

		AudienceReader::get_all(['parent_id' => 5]);

		$this->assertStringContainsString('parent_id = %d', $captured_sql);
	}

	public function test_get_all_filters_by_status(): void {
		$captured_sql = '';
		$this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
			$captured_sql = func_get_args()[0];
			return $captured_sql;
		});
		$this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

		AudienceReader::get_all(['status' => 'active']);

		$this->assertStringContainsString('status = %s', $captured_sql);
	}

	public function test_get_all_applies_limit_and_offset(): void {
		$captured_sql = '';
		$this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
			$captured_sql = func_get_args()[0];
			return $captured_sql;
		});
		$this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

		AudienceReader::get_all(['limit' => 10, 'offset' => 5]);

		$this->assertStringContainsString('LIMIT 10 OFFSET 5', $captured_sql);
	}

	public function test_get_all_no_limit_clause_when_limit_is_zero(): void {
		$captured_sql = '';
		$this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
			$captured_sql = func_get_args()[0];
			return $captured_sql;
		});
		$this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

		AudienceReader::get_all(['limit' => 0]);

		$this->assertStringNotContainsString('LIMIT', $captured_sql);
	}

	public function test_get_all_combines_parent_id_and_status_filters(): void {
		$captured_sql = '';
		$this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
			$captured_sql = func_get_args()[0];
			return $captured_sql;
		});
		$this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

		AudienceReader::get_all(['parent_id' => 3, 'status' => 'active']);

		$this->assertStringContainsString('parent_id = %d', $captured_sql);
		$this->assertStringContainsString('status = %s', $captured_sql);
		$this->assertStringContainsString('AND', $captured_sql);
	}

	// ==================================================================
	// get_by_id()
	// ==================================================================

	public function test_get_by_id_returns_audience_on_cache_miss(): void {
		$audience = (object) ['id' => 1, 'name' => 'Test Audience', 'status' => 'active'];

		$this->wpdb->shouldReceive('prepare')->once()->andReturn('SELECT * FROM wp_ffc_audiences WHERE id = 1');
		$this->wpdb->shouldReceive('get_row')->once()->andReturn($audience);

		$result = AudienceReader::get_by_id(1);

		$this->assertSame('Test Audience', $result->name);
	}

	public function test_get_by_id_returns_null_when_not_found(): void {
		$this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
		$this->wpdb->shouldReceive('get_row')->once()->andReturn(null);

		$result = AudienceReader::get_by_id(999);

		$this->assertNull($result);
	}

	public function test_get_by_id_returns_cached_result(): void {
		$cached = (object) ['id' => 1, 'name' => 'Cached Audience'];

		Functions\when('wp_cache_get')->alias(function($key, $group = '') use ($cached) {
			return $key === 'id_1' && $group === 'ffc_audiences' ? $cached : false;
		});

		// wpdb should NOT be called since cache hit
		$this->wpdb->shouldNotReceive('get_row');

		$result = AudienceReader::get_by_id(1);

		$this->assertSame('Cached Audience', $result->name);
	}

	public function test_get_by_id_caches_result_on_miss(): void {
		$audience = (object) ['id' => 5, 'name' => 'Audience Five'];

		$this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
		$this->wpdb->shouldReceive('get_row')->once()->andReturn($audience);

		// Verify cache_set is called with the right key
		Functions\when('wp_cache_set')->alias(function($key, $value, $group = '') use ($audience) {
			if ($key === 'id_5' && $group === 'ffc_audiences') {
				// Verify the cached value is the audience object
				\PHPUnit\Framework\TestCase::assertSame($audience, $value);
			}
			return true;
		});

		AudienceReader::get_by_id(5);
	}

	public function test_get_by_id_does_not_cache_null_result(): void {
		$this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
		$this->wpdb->shouldReceive('get_row')->once()->andReturn(null);

		$cache_set_called = false;
		Functions\when('wp_cache_set')->alias(function() use (&$cache_set_called) {
			$cache_set_called = true;
			return true;
		});

		AudienceReader::get_by_id(999);

		$this->assertFalse($cache_set_called);
	}

	// ==================================================================
	// get_parents()
	// ==================================================================

	public function test_get_parents_returns_top_level_audiences(): void {
		$parents = [
			(object) ['id' => 1, 'name' => 'Parent A', 'parent_id' => null],
			(object) ['id' => 2, 'name' => 'Parent B', 'parent_id' => null],
		];

		$captured_sql = '';
		$this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
			$captured_sql = func_get_args()[0];
			return $captured_sql;
		});
		$this->wpdb->shouldReceive('get_results')->once()->andReturn($parents);

		$result = AudienceReader::get_parents();

		$this->assertCount(2, $result);
		$this->assertStringContainsString('parent_id IS NULL', $captured_sql);
	}

	public function test_get_parents_with_status_filter(): void {
		$captured_sql = '';
		$this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
			$captured_sql = func_get_args()[0];
			return $captured_sql;
		});
		$this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

		AudienceReader::get_parents('active');

		$this->assertStringContainsString('parent_id IS NULL', $captured_sql);
		$this->assertStringContainsString('status = %s', $captured_sql);
	}

	// ==================================================================
	// get_children()
	// ==================================================================

	public function test_get_children_returns_children_of_parent(): void {
		$children = [
			(object) ['id' => 3, 'name' => 'Child A', 'parent_id' => 1],
			(object) ['id' => 4, 'name' => 'Child B', 'parent_id' => 1],
		];

		$captured_sql = '';
		$this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
			$captured_sql = func_get_args()[0];
			return $captured_sql;
		});
		$this->wpdb->shouldReceive('get_results')->once()->andReturn($children);

		$result = AudienceReader::get_children(1);

		$this->assertCount(2, $result);
		$this->assertStringContainsString('parent_id = %d', $captured_sql);
	}

	public function test_get_children_with_status_filter(): void {
		$captured_sql = '';
		$this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
			$captured_sql = func_get_args()[0];
			return $captured_sql;
		});
		$this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

		AudienceReader::get_children(1, 'active');

		$this->assertStringContainsString('parent_id = %d', $captured_sql);
		$this->assertStringContainsString('status = %s', $captured_sql);
	}

	// ==================================================================
	// descendants_self_join_state() (#1896)
	// ==================================================================

	/**
	 * Answer `get_children()` from a parent id => rows map: the prepared
	 * arguments carry the parent id, and get_results() reads it back.
	 *
	 * @param array<int, list<object>> $tree Children per parent id.
	 */
	private function children_tree( array $tree ): void {
		$this->wpdb->shouldReceive('prepare')->andReturnUsing(
			static fn( $sql, $args ) => 'children:' . (int) ( $args[1] ?? 0 )
		);
		$this->wpdb->shouldReceive('get_results')->andReturnUsing(
			static fn( $sql ) => $tree[ (int) substr( (string) $sql, strlen( 'children:' ) ) ] ?? array()
		);
	}

	/**
	 * @return iterable<string, array{0: array<int, list<object>>, 1: ?string}>
	 */
	public static function descendants_states(): iterable {
		$row = static fn( int $id, int $flag ): object => (object) array( 'id' => (string) $id, 'parent_id' => '1', 'allow_self_join' => (string) $flag );

		yield 'no children' => array( array(), null );
		yield 'every child and grandchild on' => array( array( 1 => array( $row( 2, 1 ), $row( 3, 1 ) ), 2 => array( $row( 4, 1 ) ) ), '1' );
		yield 'every child and grandchild off' => array( array( 1 => array( $row( 2, 0 ) ), 2 => array( $row( 4, 0 ) ) ), '0' );
		yield 'children on, a grandchild off' => array( array( 1 => array( $row( 2, 1 ), $row( 3, 1 ) ), 3 => array( $row( 5, 0 ) ) ), 'mixed' );
		yield 'children off, a grandchild on' => array( array( 1 => array( $row( 2, 0 ) ), 2 => array( $row( 4, 1 ) ) ), 'mixed' );
	}

	/**
	 * @dataProvider descendants_states
	 *
	 * @param array<int, list<object>> $tree     Children per parent id.
	 * @param string|null              $expected The derived state.
	 */
	public function test_descendants_self_join_state_is_derived_from_every_level( array $tree, ?string $expected ): void {
		$this->children_tree( $tree );

		$this->assertSame( $expected, AudienceReader::descendants_self_join_state( 1 ) );
	}

	/**
	 * @dataProvider descendants_states
	 *
	 * @param array<int, list<object>> $tree     Children per parent id.
	 * @param string|null              $expected The derived state.
	 */
	public function test_descendants_self_join_count_counts_every_level( array $tree, ?string $expected ): void {
		$this->children_tree( $tree );

		$count = AudienceReader::descendants_self_join_count( 1 );
		$flags = array();
		foreach ( $tree as $rows ) {
			foreach ( $rows as $row ) {
				$flags[] = (int) $row->allow_self_join;
			}
		}
		$this->assertSame( array( 'allowed' => array_sum( $flags ), 'total' => count( $flags ) ), $count );
	}

	/**
	 * A group created under audience 1 (a top-level audience: its row has no
	 * parent) takes its descendants' state (#1901): on only when all are on;
	 * off when all are off, when they disagree and when there are none yet.
	 *
	 * @dataProvider descendants_states
	 *
	 * @param array<int, list<object>> $tree     Children per parent id.
	 * @param string|null              $expected The derived state.
	 */
	public function test_a_new_group_inherits_its_top_level_audience_s_descendants( array $tree, ?string $expected ): void {
		$this->children_tree( $tree );
		$this->wpdb->shouldReceive('get_row')->andReturn( (object) array( 'id' => '1', 'parent_id' => null, 'allow_self_join' => '0' ) );

		$this->assertSame( '1' === $expected ? 1 : 0, AudienceReader::inherited_self_join( 1 ) );
	}

	public function test_create_under_a_parent_takes_the_inherited_self_join(): void {
		$row = static fn( int $id ): object => (object) array( 'id' => (string) $id, 'parent_id' => '1', 'allow_self_join' => '1' );
		$this->children_tree( array( 1 => array( $row( 2 ) ) ) );
		$this->wpdb->shouldReceive('get_row')->andReturn( (object) array( 'id' => '1', 'parent_id' => null, 'allow_self_join' => '0' ) );
		$this->wpdb->insert_id = 9;
		$this->wpdb->shouldReceive('insert')->once()->with(
			'wp_ffc_audiences',
			Mockery::on( static fn( $data ) => 1 === $data['allow_self_join'] && 1 === $data['parent_id'] ),
			Mockery::type('array')
		)->andReturn(1);

		$this->assertSame( 9, AudienceWriter::create( array( 'name' => 'Child', 'parent_id' => 1 ) ) );
	}

	public function test_create_under_a_parent_keeps_an_explicit_self_join(): void {
		$this->wpdb->shouldNotReceive('get_row');
		$this->wpdb->insert_id = 9;
		$this->wpdb->shouldReceive('insert')->once()->with(
			'wp_ffc_audiences',
			Mockery::on( static fn( $data ) => 0 === $data['allow_self_join'] ),
			Mockery::type('array')
		)->andReturn(1);

		AudienceWriter::create( array( 'name' => 'Child', 'parent_id' => 1, 'allow_self_join' => 0 ) );
	}

	/**
	 * @return iterable<string, array{0: string, 1: int, 2: int}>
	 */
	public static function scope_writes(): iterable {
		yield 'off' => array( 'off', 0, 0 );
		yield 'this audience only' => array( 'self', 1, 0 );
		yield 'audience and descendants' => array( 'all', 1, 1 );
		yield 'descendants only' => array( 'descendants', 0, 1 );
	}

	/**
	 * Each of the four choices (#1901) writes the audience's own flag and
	 * the same value on every child and grandchild.
	 *
	 * @dataProvider scope_writes
	 *
	 * @param string $scope       The choice.
	 * @param int    $own         The audience's own flag.
	 * @param int    $descendants The descendants' flag.
	 */
	public function test_apply_self_join_scope_writes_both_flags( string $scope, int $own, int $descendants ): void {
		$child    = (object) array( 'id' => '2', 'parent_id' => '1', 'allow_self_join' => '0' );
		$prepared = array();
		$this->wpdb->shouldReceive('prepare')->andReturnUsing(
			static function ( $sql, $args = array() ) use ( &$prepared ) {
				$prepared[] = array( $sql, $args );
				if ( is_array( $args ) && 2 === count( $args ) && false !== strpos( $sql, 'parent_id = %d' ) ) {
					return 'CHILDREN_OF:' . $args[1];
				}
				return $sql;
			}
		);
		$this->wpdb->shouldReceive('get_results')->andReturnUsing( static fn( $sql ) => 'CHILDREN_OF:1' === $sql ? array( $child ) : array() );
		$this->wpdb->shouldReceive('get_col')->andReturn( array() );
		$this->wpdb->shouldReceive('update')->once()->with( 'wp_ffc_audiences', array( 'allow_self_join' => $own ), array( 'id' => 1 ), array( '%d' ), array( '%d' ) )->andReturn( 1 );
		$this->wpdb->shouldReceive('query')->once()->andReturn( 1 );

		$this->assertTrue( AudienceWriter::apply_self_join_scope( 1, $scope ) );

		$cascade = array_values( array_filter( $prepared, static fn( $p ) => 0 === stripos( (string) $p[0], 'UPDATE' ) ) );
		$this->assertCount( 1, $cascade );
		$this->assertSame( $descendants, $cascade[0][1][1], 'the descendants take the choice\'s second flag' );
	}

	public function test_apply_self_join_scope_refuses_an_unknown_choice(): void {
		$this->wpdb->shouldNotReceive('update');
		$this->wpdb->shouldNotReceive('query');

		$this->assertFalse( AudienceWriter::apply_self_join_scope( 1, 'mixed' ) );
	}

	/**
	 * @return iterable<string, array{0: bool, 1: bool, 2: int, 3: int}>
	 */
	public static function tree_access_pairs(): iterable {
		yield 'both on'                  => array( true, true, 1, 1 );
		yield 'members-only alone'       => array( true, false, 1, 0 );
		yield 'keep without members-only' => array( false, true, 0, 0 );
		yield 'both off'                 => array( false, false, 0, 0 );
	}

	/**
	 * "Keep at least one group" is stored off whenever "Visible to members
	 * only" is off (#1918).
	 *
	 * @dataProvider tree_access_pairs
	 */
	public function test_set_tree_access_stores_keep_only_under_members_only( bool $members_only, bool $keep, int $stored_members_only, int $stored_keep ): void {
		$this->wpdb->shouldReceive('update')->once()->with(
			'wp_ffc_audiences',
			array( 'members_only' => $stored_members_only, 'require_membership' => $stored_keep ),
			array( 'id' => 1 ),
			array( '%d', '%d' ),
			array( '%d' )
		)->andReturn( 1 );

		$this->assertTrue( AudienceWriter::set_tree_access( 1, $members_only, $keep ) );
	}

	// ==================================================================
	// get_hierarchical()
	// ==================================================================

	public function test_get_hierarchical_returns_parents_with_children(): void {
		$parent1 = (object) ['id' => 1, 'name' => 'Parent A', 'parent_id' => null];
		$parent2 = (object) ['id' => 2, 'name' => 'Parent B', 'parent_id' => null];
		$child1 = (object) ['id' => 3, 'name' => 'Child A', 'parent_id' => 1];
		$child2 = (object) ['id' => 4, 'name' => 'Child B', 'parent_id' => 1];

		// get_hierarchical calls get_all once and builds the tree in PHP
		$this->wpdb->shouldReceive('prepare')->andReturnUsing(function() {
			return func_get_args()[0];
		});
		$this->wpdb->shouldReceive('get_results')->once()->andReturn([$parent1, $parent2, $child1, $child2]);

		$result = AudienceReader::get_hierarchical();

		$this->assertCount(2, $result);
		$this->assertObjectHasProperty('children', $result[0]);
		$this->assertCount(2, $result[0]->children);
		$this->assertObjectHasProperty('children', $result[1]);
		$this->assertCount(0, $result[1]->children);
	}

	public function test_get_hierarchical_returns_empty_array_when_no_parents(): void {
		$this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
		$this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

		$result = AudienceReader::get_hierarchical();

		$this->assertSame([], $result);
	}

	public function test_get_hierarchical_passes_status_filter(): void {
		$captured_sqls = [];
		$this->wpdb->shouldReceive('prepare')->andReturnUsing(function() use (&$captured_sqls) {
			$sql = func_get_args()[0];
			$captured_sqls[] = $sql;
			return $sql;
		});
		$this->wpdb->shouldReceive('get_results')->andReturn([]);

		AudienceReader::get_hierarchical('active');

		$this->assertNotEmpty($captured_sqls);
		$this->assertStringContainsString('status = %s', $captured_sqls[0]);
	}

	// ==================================================================
	// create()
	// ==================================================================

	public function test_create_inserts_audience_and_returns_id(): void {
		$this->wpdb->insert_id = 42;

		$this->wpdb->shouldReceive('insert')
			->once()
			->with(
				'wp_ffc_audiences',
				Mockery::on(function($data) {
					return $data['name'] === 'New Audience'
						&& $data['color'] === '#ff0000'
						&& $data['status'] === 'active';
				}),
				Mockery::type('array')
			)
			->andReturn(1);

		$result = AudienceWriter::create([
			'name' => 'New Audience',
			'color' => '#ff0000',
		]);

		$this->assertSame(42, $result);
	}

	public function test_create_returns_false_on_failure(): void {
		$this->wpdb->shouldReceive('insert')->once()->andReturn(false);

		$result = AudienceWriter::create(['name' => 'Fail Audience']);

		$this->assertFalse($result);
	}

	public function test_create_uses_defaults(): void {
		$this->wpdb->insert_id = 1;

		$this->wpdb->shouldReceive('insert')
			->once()
			->with(
				'wp_ffc_audiences',
				Mockery::on(function($data) {
					return $data['color'] === '#3788d8'
						&& $data['status'] === 'active'
						&& $data['created_by'] === 1
						&& $data['parent_id'] === null;
				}),
				Mockery::type('array')
			)
			->andReturn(1);

		AudienceWriter::create(['name' => 'Default Test']);
	}

	public function test_create_includes_allow_self_join(): void {
		$this->wpdb->insert_id = 1;

		$this->wpdb->shouldReceive('insert')
			->once()
			->with(
				'wp_ffc_audiences',
				Mockery::on(function($data) {
					return isset($data['allow_self_join']) && $data['allow_self_join'] === 1;
				}),
				Mockery::type('array')
			)
			->andReturn(1);

		AudienceWriter::create(['name' => 'Self Join', 'allow_self_join' => 1]);
	}

	// ==================================================================
	// update()
	// ==================================================================

	public function test_update_modifies_audience_and_returns_true(): void {
		$this->wpdb->shouldReceive('update')
			->once()
			->with(
				'wp_ffc_audiences',
				Mockery::on(function($data) {
					return $data['name'] === 'Updated Name';
				}),
				['id' => 1],
				Mockery::type('array'),
				['%d']
			)
			->andReturn(1);

		$result = AudienceWriter::update(1, ['name' => 'Updated Name']);

		$this->assertTrue($result);
	}

	public function test_update_returns_false_on_failure(): void {
		$this->wpdb->shouldReceive('update')->once()->andReturn(false);

		$result = AudienceWriter::update(1, ['name' => 'Fail']);

		$this->assertFalse($result);
	}

	public function test_update_returns_false_when_data_is_empty_after_filtering(): void {
		// Only id, created_by, created_at are present -- all get unset
		$result = AudienceWriter::update(1, [
			'id' => 99,
			'created_by' => 5,
			'created_at' => '2024-01-01',
		]);

		$this->assertFalse($result);
	}

	public function test_update_strips_protected_fields(): void {
		$this->wpdb->shouldReceive('update')
			->once()
			->with(
				'wp_ffc_audiences',
				Mockery::on(function($data) {
					return !isset($data['id'])
						&& !isset($data['created_by'])
						&& !isset($data['created_at'])
						&& isset($data['name']);
				}),
				['id' => 1],
				Mockery::type('array'),
				['%d']
			)
			->andReturn(1);

		AudienceWriter::update(1, [
			'id' => 99,
			'created_by' => 5,
			'created_at' => '2024-01-01',
			'name' => 'Valid Update',
		]);
	}

	public function test_update_clears_cache(): void {
		$this->wpdb->shouldReceive('update')->once()->andReturn(1);

		$cache_deleted_key = null;
		Functions\when('wp_cache_delete')->alias(function($key, $group = '') use (&$cache_deleted_key) {
			$cache_deleted_key = $key;
			return true;
		});

		AudienceWriter::update(7, ['name' => 'Cached Update']);

		$this->assertSame('id_7', $cache_deleted_key);
	}

	public function test_update_only_includes_known_fields(): void {
		$this->wpdb->shouldReceive('update')
			->once()
			->with(
				'wp_ffc_audiences',
				Mockery::on(function($data) {
					// Unknown field 'bogus_field' should not be in the update data
					return !isset($data['bogus_field'])
						&& isset($data['name']);
				}),
				['id' => 1],
				Mockery::type('array'),
				['%d']
			)
			->andReturn(1);

		AudienceWriter::update(1, ['name' => 'Good', 'bogus_field' => 'ignored']);
	}

	// ==================================================================
	// delete()
	// ==================================================================

	public function test_delete_removes_audience_members_and_children(): void {
		// No children
		$this->wpdb->shouldReceive('prepare')->andReturnUsing(function() {
			return func_get_args()[0];
		});
		// delete() now reads the affected members before deleting anything, so
		// get_children() is reached twice: once by get_descendant_ids(), once by
		// the recursion itself (#1127).
		$this->wpdb->shouldReceive('get_results')->andReturn([]);
		$this->wpdb->shouldReceive('get_col')->andReturn([]);

		// Delete member associations
		$this->wpdb->shouldReceive('delete')
			->once()
			->with('wp_ffc_audience_members', ['audience_id' => 5], ['%d'])
			->andReturn(1);

		// Delete the audience itself
		$this->wpdb->shouldReceive('delete')
			->once()
			->with('wp_ffc_audiences', ['id' => 5], ['%d'])
			->andReturn(1);

		$result = AudienceWriter::delete(5);

		$this->assertTrue($result);
	}

	public function test_delete_returns_false_on_failure(): void {
		$this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
		$this->wpdb->shouldReceive('get_results')->andReturn([]); // no children
		$this->wpdb->shouldReceive('get_col')->andReturn([]); // no members (#1127)

		$this->wpdb->shouldReceive('delete')
			->with('wp_ffc_audience_members', Mockery::any(), Mockery::any())
			->andReturn(1);

		$this->wpdb->shouldReceive('delete')
			->with('wp_ffc_audiences', ['id' => 5], ['%d'])
			->andReturn(false);

		$result = AudienceWriter::delete(5);

		$this->assertFalse($result);
	}

	public function test_delete_recursively_deletes_children(): void {
		$child = (object) ['id' => 10, 'name' => 'Child', 'parent_id' => 5];

		// Answer by the parent id being asked about rather than by call order:
		// delete() reads the affected members first (#1127), so get_children()
		// is now reached once by get_descendant_ids() and once by the recursion,
		// and a counter-based stub silently answers the wrong question.
		$this->wpdb->shouldReceive('prepare')->andReturnUsing(function($sql, $args = []) {
			if (is_array($args) && count($args) === 2 && strpos($sql, 'parent_id = %d') !== false) {
				return 'CHILDREN_OF:' . $args[1];
			}
			return 'QUERY';
		});
		$this->wpdb->shouldReceive('get_results')->andReturnUsing(function($sql) use ($child) {
			return 'CHILDREN_OF:5' === $sql ? [$child] : [];
		});
		$this->wpdb->shouldReceive('get_col')->andReturn([]);

		// Expect deletes for child 10 first (members + audience), then parent 5
		$delete_calls = [];
		$this->wpdb->shouldReceive('delete')->andReturnUsing(function($table, $where) use (&$delete_calls) {
			$delete_calls[] = ['table' => $table, 'where' => $where];
			return 1;
		});

		AudienceWriter::delete(5);

		// Should have 4 delete calls:
		// 1. child members, 2. child audience, 3. parent members, 4. parent audience
		$this->assertCount(4, $delete_calls);
		$this->assertSame(['audience_id' => 10], $delete_calls[0]['where']);
		$this->assertSame(['id' => 10], $delete_calls[1]['where']);
		$this->assertSame(['audience_id' => 5], $delete_calls[2]['where']);
		$this->assertSame(['id' => 5], $delete_calls[3]['where']);
	}

	public function test_delete_clears_cache(): void {
		$this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
		$this->wpdb->shouldReceive('get_results')->andReturn([]);
		$this->wpdb->shouldReceive('get_col')->andReturn([]); // #1127
		$this->wpdb->shouldReceive('delete')->andReturn(1);

		$deleted_keys = [];
		Functions\when('wp_cache_delete')->alias(function($key, $group = '') use (&$deleted_keys) {
			$deleted_keys[] = $key;
			return true;
		});

		AudienceWriter::delete(8);

		$this->assertContains('id_8', $deleted_keys);
	}

	// ==================================================================
	// Query-cache version invalidation (#644)
	// ==================================================================

	public function test_create_bumps_query_cache_version(): void {
		$this->wpdb->insert_id = 42;
		$this->wpdb->shouldReceive('insert')->once()->andReturn(1);

		$bumped = null;
		Functions\when('get_option')->justReturn(3);
		Functions\when('update_option')->alias(function($name, $value, $autoload = null) use (&$bumped) {
			if ($name === 'ffc_cache_version_audience') {
				$bumped = $value;
			}
			return true;
		});

		AudienceWriter::create(['name' => 'Bumped']);

		$this->assertSame(4, $bumped, 'create() must bump the audience query-cache version');
	}

	public function test_update_bumps_query_cache_version(): void {
		$this->wpdb->shouldReceive('update')->once()->andReturn(1);

		$bumped = null;
		Functions\when('get_option')->justReturn(3);
		Functions\when('update_option')->alias(function($name, $value, $autoload = null) use (&$bumped) {
			if ($name === 'ffc_cache_version_audience') {
				$bumped = $value;
			}
			return true;
		});

		AudienceWriter::update(7, ['name' => 'Bumped Update']);

		$this->assertSame(4, $bumped, 'update() must bump the audience query-cache version');
	}

	public function test_delete_bumps_query_cache_version(): void {
		$this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
		$this->wpdb->shouldReceive('get_results')->andReturn([]);
		$this->wpdb->shouldReceive('get_col')->andReturn([]); // #1127
		$this->wpdb->shouldReceive('delete')->andReturn(1);

		$bumped = null;
		Functions\when('get_option')->justReturn(3);
		Functions\when('update_option')->alias(function($name, $value, $autoload = null) use (&$bumped) {
			if ($name === 'ffc_cache_version_audience') {
				$bumped = $value;
			}
			return true;
		});

		AudienceWriter::delete(8);

		$this->assertSame(4, $bumped, 'delete() must bump the audience query-cache version');
	}

	// ==================================================================
	// add_member()
	// ==================================================================

	public function test_add_member_inserts_and_returns_id(): void {
		$this->wpdb->insert_id = 99;

		// is_member check returns 0 (not a member)
		$this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
		$this->wpdb->shouldReceive('get_var')->once()->andReturn('0');

		$this->wpdb->shouldReceive('insert')
			->once()
			->with(
				'wp_ffc_audience_members',
				['audience_id' => 1, 'user_id' => 42],
				['%d', '%d']
			)
			->andReturn(1);

		$result = AudienceWriter::add_member(1, 42);

		$this->assertSame(99, $result);
	}

	public function test_add_member_returns_false_if_already_member(): void {
		// is_member returns count > 0
		$this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
		$this->wpdb->shouldReceive('get_var')->once()->andReturn('1');

		$this->wpdb->shouldNotReceive('insert');

		$result = AudienceWriter::add_member(1, 42);

		$this->assertFalse($result);
	}

	public function test_add_member_returns_false_on_insert_failure(): void {
		$this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
		$this->wpdb->shouldReceive('get_var')->once()->andReturn('0');
		$this->wpdb->shouldReceive('insert')->once()->andReturn(false);

		$result = AudienceWriter::add_member(1, 42);

		$this->assertFalse($result);
	}

	// ==================================================================
	// remove_member()
	// ==================================================================

	public function test_remove_member_deletes_and_returns_true(): void {
		$this->wpdb->shouldReceive('delete')
			->once()
			->with(
				'wp_ffc_audience_members',
				['audience_id' => 1, 'user_id' => 42],
				['%d', '%d']
			)
			->andReturn(1);

		$result = AudienceWriter::remove_member(1, 42);

		$this->assertTrue($result);
	}

	public function test_remove_member_returns_false_on_failure(): void {
		$this->wpdb->shouldReceive('delete')->once()->andReturn(false);

		$result = AudienceWriter::remove_member(1, 42);

		$this->assertFalse($result);
	}

	public function test_remove_member_returns_true_when_no_rows_affected(): void {
		// delete returns 0 (no rows affected, but no error => not false)
		$this->wpdb->shouldReceive('delete')->once()->andReturn(0);

		$result = AudienceWriter::remove_member(1, 999);

		$this->assertTrue($result);
	}

	// ==================================================================
	// is_member()
	// ==================================================================

	public function test_is_member_returns_true_when_member_exists(): void {
		$this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
		$this->wpdb->shouldReceive('get_var')->once()->andReturn('1');

		$this->assertTrue(AudienceReader::is_member(1, 42));
	}

	public function test_is_member_returns_false_when_not_member(): void {
		$this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
		$this->wpdb->shouldReceive('get_var')->once()->andReturn('0');

		$this->assertFalse(AudienceReader::is_member(1, 42));
	}

	public function test_is_member_returns_false_when_count_is_null(): void {
		$this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
		$this->wpdb->shouldReceive('get_var')->once()->andReturn(null);

		$this->assertFalse(AudienceReader::is_member(1, 42));
	}

	public function test_is_member_of_any_counts_the_user_across_every_audience(): void {
		$this->wpdb->shouldReceive('prepare')
			->once()
			->with('SELECT COUNT(*) FROM %i WHERE user_id = %d', 'wp_ffc_audience_members', 42)
			->andReturn('QUERY');
		$this->wpdb->shouldReceive('get_var')->once()->with('QUERY')->andReturn('3');

		$this->assertTrue(AudienceReader::is_member_of_any(42));
	}

	public function test_is_member_of_any_returns_false_without_a_membership_row(): void {
		$this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
		$this->wpdb->shouldReceive('get_var')->once()->andReturn('0');

		$this->assertFalse(AudienceReader::is_member_of_any(42));
	}

	// ==================================================================
	// get_members()
	// ==================================================================

	public function test_get_members_returns_user_ids(): void {
		$this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
		$this->wpdb->shouldReceive('get_col')->once()->andReturn(['10', '20', '30']);

		$result = AudienceReader::get_members(1);

		$this->assertSame([10, 20, 30], $result);
	}

	public function test_get_members_returns_empty_array_when_no_members(): void {
		$this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
		$this->wpdb->shouldReceive('get_col')->once()->andReturn([]);

		$result = AudienceReader::get_members(1);

		$this->assertSame([], $result);
	}

	public function test_get_members_includes_children_when_requested(): void {
		$child1 = (object) ['id' => 3, 'name' => 'Child A', 'parent_id' => 1];
		$child2 = (object) ['id' => 4, 'name' => 'Child B', 'parent_id' => 1];

		// get_descendant_ids calls get_children recursively:
		// get_children(1) → [$child1, $child2], get_children(3) → [], get_children(4) → []
		$this->wpdb->shouldReceive('prepare')->andReturnUsing(function() {
			return func_get_args()[0];
		});
		$gr_count = 0;
		$this->wpdb->shouldReceive('get_results')->andReturnUsing(function() use (&$gr_count, $child1, $child2) {
			$gr_count++;
			return $gr_count === 1 ? [$child1, $child2] : [];
		});

		// get_col for members query (should include audience_ids 1, 3, 4)
		$this->wpdb->shouldReceive('get_col')->once()->andReturn(['10', '20']);

		$result = AudienceReader::get_members(1, true);

		$this->assertSame([10, 20], $result);
	}

	public function test_get_members_without_children_queries_single_audience(): void {
		$captured_sql = '';
		$this->wpdb->shouldReceive('prepare')->andReturnUsing(function() use (&$captured_sql) {
			$sql = func_get_args()[0];
			if (strpos($sql, 'DISTINCT') !== false) {
				$captured_sql = $sql;
			}
			return $sql;
		});
		$this->wpdb->shouldReceive('get_col')->once()->andReturn([]);

		AudienceReader::get_members(1, false);

		$this->assertStringContainsString('IN (%d)', $captured_sql);
	}

	public function test_get_members_casts_results_to_integers(): void {
		$this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
		$this->wpdb->shouldReceive('get_col')->once()->andReturn(['1', '2', '3']);

		$result = AudienceReader::get_members(1);

		foreach ($result as $id) {
			$this->assertIsInt($id);
		}
	}

	// ==================================================================
	// get_user_audiences()
	// ==================================================================

	public function test_get_user_audiences_returns_audiences_for_user(): void {
		$audiences = [
			(object) ['id' => 1, 'name' => 'Audience A', 'parent_id' => null, 'status' => 'active'],
			(object) ['id' => 3, 'name' => 'Audience C', 'parent_id' => 1, 'status' => 'active'],
		];

		$this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
		$this->wpdb->shouldReceive('get_results')->once()->andReturn($audiences);

		$result = AudienceReader::get_user_audiences(42);

		$this->assertCount(2, $result);
		$this->assertSame('Audience A', $result[0]->name);
	}

	public function test_get_user_audiences_returns_empty_array_when_no_memberships(): void {
		$this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
		$this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

		$result = AudienceReader::get_user_audiences(42);

		$this->assertSame([], $result);
	}

	public function test_get_user_audiences_includes_parents_when_requested(): void {
		$child_audience = (object) [
			'id' => 3,
			'name' => 'Child Audience',
			'parent_id' => 1,
			'status' => 'active',
		];
		$parent_audience = (object) [
			'id' => 1,
			'name' => 'Parent Audience',
			'parent_id' => null,
			'status' => 'active',
		];

		$gr_count = 0;
		$this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
		// get_ancestor_ids calls get_by_id which uses get_row
		$this->wpdb->shouldReceive('get_row')->andReturn($parent_audience);
		$this->wpdb->shouldReceive('get_results')->andReturnUsing(function() use (&$gr_count, $child_audience, $parent_audience) {
			$gr_count++;
			if ($gr_count === 1) {
				return [$child_audience]; // user's direct audiences
			}
			return [$parent_audience]; // ancestor lookup by IDs
		});

		$result = AudienceReader::get_user_audiences(42, true);

		$this->assertCount(2, $result);
		// Should be sorted by name
		$names = array_map(function($a) { return $a->name; }, $result);
		$sorted = $names;
		sort($sorted);
		$this->assertSame($sorted, $names);
	}

	public function test_get_user_audiences_does_not_duplicate_when_including_parents(): void {
		// User is directly in parent audience and also in child audience
		$parent_audience = (object) [
			'id' => 1,
			'name' => 'Parent',
			'parent_id' => null,
			'status' => 'active',
		];
		$child_audience = (object) [
			'id' => 3,
			'name' => 'Child',
			'parent_id' => 1,
			'status' => 'active',
		];

		$gr_count = 0;
		$this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
		// get_ancestor_ids calls get_by_id which uses get_row
		$this->wpdb->shouldReceive('get_row')->andReturn($parent_audience);
		$this->wpdb->shouldReceive('get_results')->andReturnUsing(function() use (&$gr_count, $parent_audience, $child_audience) {
			$gr_count++;
			if ($gr_count === 1) {
				return [$parent_audience, $child_audience]; // user has both
			}
			return [$parent_audience]; // ancestor lookup returns same parent
		});

		$result = AudienceReader::get_user_audiences(42, true);

		// Should not have duplicate parent
		$ids = array_column($result, 'id');
		$this->assertCount(count(array_unique($ids)), $ids);
	}

	public function test_get_user_audiences_uses_cache(): void {
		$cached = [
			(object) ['id' => 1, 'name' => 'Cached Audience'],
		];

		Functions\when('wp_cache_get')->alias(function($key, $group = '') use ($cached) {
			// #1127 moved this key out of the literal 'ffcertificate' group and
			// into the group the Reader/Writer pair shares.
			if ($key === 'user_aud_42_0' && $group === 'ffc_audiences') {
				return $cached;
			}
			return false;
		});

		// wpdb should NOT be called since cache hit
		$this->wpdb->shouldNotReceive('get_results');

		$result = AudienceReader::get_user_audiences(42);

		$this->assertCount(1, $result);
		$this->assertSame('Cached Audience', $result[0]->name);
	}

	public function test_get_user_audiences_caches_with_parents_flag(): void {
		$cached = [
			(object) ['id' => 1, 'name' => 'Cached With Parents'],
		];

		Functions\when('wp_cache_get')->alias(function($key, $group = '') use ($cached) {
			if ($key === 'user_aud_42_1' && $group === 'ffc_audiences') {
				return $cached;
			}
			return false;
		});

		$this->wpdb->shouldNotReceive('get_results');

		$result = AudienceReader::get_user_audiences(42, true);

		$this->assertCount(1, $result);
	}

	// ==================================================================
	// get_member_count()
	// ==================================================================

	public function test_get_member_count_returns_count_of_members(): void {
		$this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
		$this->wpdb->shouldReceive('get_col')->once()->andReturn(['10', '20', '30']);
		Functions\when('get_users')->justReturn([10, 20, 30]);

		$count = AudienceReader::get_member_count(1);

		$this->assertSame(3, $count);
	}

	public function test_get_member_count_leaves_out_a_row_whose_user_is_gone(): void {
		// User 20 was deleted without UserCleanup removing its member row: the
		// members table skips it, so the count the list and the editor show
		// must skip it too (#1795).
		$this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
		$this->wpdb->shouldReceive('get_col')->once()->andReturn(['10', '20', '30']);
		$asked = null;
		Functions\when('get_users')->alias(function ($args) use (&$asked) {
			$asked = $args;
			return [10, 30];
		});

		$count = AudienceReader::get_member_count(1);

		$this->assertSame(2, $count);
		$this->assertSame([10, 20, 30], $asked['include'], 'every member row is checked against the users table');
		$this->assertSame('ID', $asked['fields']);
		$this->assertSame(0, $asked['blog_id'], 'a member is found network-wide, as get_user_by() finds them');
	}

	public function test_get_member_count_returns_zero_when_no_members(): void {
		$this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
		$this->wpdb->shouldReceive('get_col')->once()->andReturn([]);
		// An empty `include` would mean "every user" to get_users().
		Functions\expect('get_users')->never();

		$count = AudienceReader::get_member_count(1);

		$this->assertSame(0, $count);
	}

	public function test_get_member_count_includes_children_when_requested(): void {
		$child = (object) ['id' => 3, 'name' => 'Child', 'parent_id' => 1];

		$this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
		// get_descendant_ids calls get_children recursively:
		// get_children(1) → [$child], get_children(3) → []
		$gr_count = 0;
		$this->wpdb->shouldReceive('get_results')->andReturnUsing(function() use (&$gr_count, $child) {
			$gr_count++;
			return $gr_count === 1 ? [$child] : [];
		});
		// get_col for DISTINCT user_id
		$this->wpdb->shouldReceive('get_col')->once()->andReturn(['10', '20', '30', '40']);
		Functions\when('get_users')->alias(fn ($args) => $args['include']);

		$count = AudienceReader::get_member_count(1, true);

		$this->assertSame(4, $count);
	}

	// ==================================================================
	// bulk_add_members()
	// ==================================================================

	public function test_bulk_add_members_adds_all_users(): void {
		$this->wpdb->insert_id = 1;

		// Each add_member call checks is_member first
		$this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
		$this->wpdb->shouldReceive('get_var')->andReturn('0'); // not a member
		$this->wpdb->shouldReceive('insert')->andReturn(1);

		$added = AudienceWriter::bulk_add_members(1, [10, 20, 30]);

		$this->assertSame(3, $added);
	}

	public function test_bulk_add_members_skips_existing_members(): void {
		$this->wpdb->insert_id = 1;

		$get_var_call = 0;
		$this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
		$this->wpdb->shouldReceive('get_var')->andReturnUsing(function() use (&$get_var_call) {
			$get_var_call++;
			// First user is already a member, others are not
			return $get_var_call === 1 ? '1' : '0';
		});
		$this->wpdb->shouldReceive('insert')->andReturn(1);

		$added = AudienceWriter::bulk_add_members(1, [10, 20, 30]);

		$this->assertSame(2, $added);
	}

	public function test_bulk_add_members_returns_zero_for_empty_array(): void {
		$added = AudienceWriter::bulk_add_members(1, []);

		$this->assertSame(0, $added);
	}

	public function test_bulk_add_members_counts_only_successful_inserts(): void {
		$this->wpdb->insert_id = 1;

		$insert_call = 0;
		$this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
		$this->wpdb->shouldReceive('get_var')->andReturn('0'); // not a member
		$this->wpdb->shouldReceive('insert')->andReturnUsing(function() use (&$insert_call) {
			$insert_call++;
			// Second insert fails
			return $insert_call === 2 ? false : 1;
		});

		$added = AudienceWriter::bulk_add_members(1, [10, 20, 30]);

		$this->assertSame(2, $added);
	}

	// ==================================================================
	// cascade_self_join()
	// ==================================================================

	public function test_cascade_self_join_updates_children(): void {
		$child = (object) ['id' => 10, 'name' => 'Child', 'parent_id' => 1];
		$captured_sqls = [];

		// Answer by the parent id being asked about, not by call order:
		// cascade_self_join() now also reads the members of the subtree so it can
		// invalidate them (#1127), and that walks the tree as well.
		$this->wpdb->shouldReceive('prepare')->andReturnUsing(function($sql, $args = []) use (&$captured_sqls) {
			$captured_sqls[] = $sql;
			if (is_array($args) && count($args) === 2 && strpos($sql, 'parent_id = %d') !== false) {
				return 'CHILDREN_OF:' . $args[1];
			}
			return $sql;
		});
		$this->wpdb->shouldReceive('get_results')->andReturnUsing(function($sql) use ($child) {
			return 'CHILDREN_OF:1' === $sql ? [$child] : [];
		});
		$this->wpdb->shouldReceive('get_col')->andReturn([]);
		$this->wpdb->shouldReceive('query')->once()->andReturn(1);

		AudienceWriter::cascade_self_join(1, 1);

		$update_sqls = array_filter($captured_sqls, function($sql) {
			return stripos($sql, 'UPDATE') !== false;
		});
		$this->assertNotEmpty($update_sqls);
		$update_sql = array_values($update_sqls)[0];
		$this->assertStringContainsString('allow_self_join', $update_sql);
	}

	// ==================================================================
	// bulk_remove_members()
	// ==================================================================

	public function test_bulk_remove_members_removes_all_users(): void {
		$this->wpdb->shouldReceive('delete')->andReturn(1);

		$removed = AudienceWriter::bulk_remove_members(1, [10, 20, 30]);

		$this->assertSame(3, $removed);
	}

	public function test_bulk_remove_members_returns_zero_for_empty_array(): void {
		$removed = AudienceWriter::bulk_remove_members(1, []);

		$this->assertSame(0, $removed);
	}

	// ==================================================================
	// set_members()
	// ==================================================================

	public function test_set_members_replaces_all_members(): void {
		$this->wpdb->insert_id = 1;

		// First: delete all existing members
		$this->wpdb->shouldReceive('delete')
			->with('wp_ffc_audience_members', ['audience_id' => 1], ['%d'])
			->once()
			->andReturn(5);

		// Then: add_member for each user (is_member + insert)
		$this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
		// set_members() reads the current members before the wipe so it can
		// invalidate the users it drops, not only the ones it adds (#1127).
		$this->wpdb->shouldReceive('get_col')->andReturn([]);
		$this->wpdb->shouldReceive('get_var')->andReturn('0');
		$this->wpdb->shouldReceive('insert')->andReturn(1);

		$result = AudienceWriter::set_members(1, [10, 20]);

		$this->assertTrue($result);
	}

	// ==================================================================
	// count()
	// ==================================================================

	public function test_count_returns_total_audiences(): void {
		$this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
		$this->wpdb->shouldReceive('get_var')->once()->andReturn('15');

		$result = AudienceReader::count();

		$this->assertSame(15, $result);
	}

	public function test_count_filters_by_parent_id(): void {
		$captured_sql = '';
		$this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
			$captured_sql = func_get_args()[0];
			return $captured_sql;
		});
		$this->wpdb->shouldReceive('get_var')->once()->andReturn('5');

		AudienceReader::count(['parent_id' => 0]);

		$this->assertStringContainsString('parent_id IS NULL', $captured_sql);
	}

	public function test_count_filters_by_status(): void {
		$captured_sql = '';
		$this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
			$captured_sql = func_get_args()[0];
			return $captured_sql;
		});
		$this->wpdb->shouldReceive('get_var')->once()->andReturn('3');

		AudienceReader::count(['status' => 'active']);

		$this->assertStringContainsString('status = %s', $captured_sql);
	}

	public function test_count_uses_cache(): void {
		Functions\when('wp_cache_get')->alias(function($key, $group = '') {
			if ($group === 'ffc_audience_queries') {
				return 42;
			}
			return false;
		});

		$this->wpdb->shouldNotReceive('get_var');

		$result = AudienceReader::count();

		$this->assertSame(42, $result);
	}

	public function test_count_cache_key_carries_version_suffix(): void {
		Functions\when('get_option')->justReturn(9);
		$captured_key = '';
		Functions\when('wp_cache_get')->alias(function($key, $group = '') use (&$captured_key) {
			$captured_key = $key;
			return 5;
		});

		AudienceReader::count();

		$this->assertStringEndsWith('_v9', $captured_key);
		$this->assertStringStartsWith('aud_count_', $captured_key);
	}

	// ==================================================================
	// search()
	// ==================================================================

	public function test_search_returns_matching_audiences(): void {
		$audiences = [
			(object) ['id' => 1, 'name' => 'Test Audience'],
		];

		$this->wpdb->shouldReceive('esc_like')->once()->andReturnUsing(function($v) {
			return $v;
		});
		$this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
		$this->wpdb->shouldReceive('get_results')->once()->andReturn($audiences);

		$result = AudienceReader::search('Test');

		$this->assertCount(1, $result);
		$this->assertSame('Test Audience', $result[0]->name);
	}

	public function test_search_returns_empty_array_when_no_matches(): void {
		$this->wpdb->shouldReceive('esc_like')->once()->andReturn('nonexistent');
		$this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
		$this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

		$result = AudienceReader::search('nonexistent');

		$this->assertSame([], $result);
	}

	public function test_search_uses_cache(): void {
		$cached = [(object) ['id' => 1, 'name' => 'Cached Result']];

		Functions\when('wp_cache_get')->alias(function($key, $group = '') use ($cached) {
			if ($group === 'ffc_audience_queries') {
				return $cached;
			}
			return false;
		});

		$this->wpdb->shouldNotReceive('get_results');

		$result = AudienceReader::search('cached');

		$this->assertCount(1, $result);
	}

	public function test_search_respects_limit_parameter(): void {
		$captured_sql = '';
		$this->wpdb->shouldReceive('esc_like')->once()->andReturn('test');
		$this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
			$captured_sql = func_get_args()[0];
			return $captured_sql;
		});
		$this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

		AudienceReader::search('test', 5);

		$this->assertStringContainsString('LIMIT %d', $captured_sql);
	}

	// ------------------------------------------------------------------
	// get_all_ids() / get_user_audience_badges() — issue #340
	// ------------------------------------------------------------------

	public function test_get_all_ids_returns_int_list(): void {
		$this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(fn($s) => $s);
		$this->wpdb->shouldReceive('get_col')->once()->andReturn(['1', '2', '7']);

		$this->assertSame([1, 2, 7], AudienceReader::get_all_ids());
	}

	public function test_get_all_ids_returns_empty_when_no_rows(): void {
		$this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(fn($s) => $s);
		$this->wpdb->shouldReceive('get_col')->once()->andReturn([]);

		$this->assertSame([], AudienceReader::get_all_ids());
	}

	public function test_get_user_audience_badges_returns_name_color_pairs(): void {
		$this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(fn($s) => $s);
		$this->wpdb->shouldReceive('get_results')->once()->andReturn([
			['name' => 'VIP', 'color' => '#ff0'],
			['name' => 'New', 'color' => '#0f0'],
		]);

		$badges = AudienceReader::get_user_audience_badges(7);

		$this->assertCount(2, $badges);
		$this->assertSame('VIP', $badges[0]['name']);
		$this->assertSame('#0f0', $badges[1]['color']);
	}

	public function test_get_user_audience_badges_short_circuits_on_invalid_user(): void {
		$this->wpdb->shouldNotReceive('get_results');

		$this->assertSame([], AudienceReader::get_user_audience_badges(0));
	}
}
