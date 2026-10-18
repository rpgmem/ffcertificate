<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Audience\AudienceTreeRules;

/**
 * Members-only and keep-at-least-one, read on the top-level audience (#1918).
 *
 * The tree used throughout: 1 (top-level) → 2, 3; 2 → 4.
 *
 * @covers \FreeFormCertificate\Audience\AudienceTreeRules
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AudienceTreeRulesTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var \Mockery\MockInterface */
	private $wpdb;

	/** @var array<int, object> Audience rows by id. */
	private array $rows = array();

	/** @var list<array<string, mixed>> Memberships the members query answers. */
	private array $memberships = array();

	/** @var array<int, mixed> Arguments of the last prepare(). */
	private array $bound = array();

	/** @var list<int> Self-join group ids the leave-all query answers. */
	private array $self_join_ids = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$parents = array( 1 => null, 2 => 1, 3 => 1, 4 => 2, 9 => null, 10 => 9 );
		$reader  = Mockery::mock( 'alias:\FreeFormCertificate\Audience\AudienceReader' );
		$reader->shouldReceive( 'get_ancestor_ids' )->andReturnUsing(
			static function ( int $id ) use ( $parents ): array {
				$chain = array( $id );
				while ( null !== ( $parents[ $id ] ?? null ) ) {
					$id      = $parents[ $id ];
					$chain[] = $id;
				}
				return $chain;
			}
		);
		$reader->shouldReceive( 'get_descendant_ids' )->andReturnUsing(
			static fn( int $id ): array => 1 === $id ? array( 2, 4, 3 ) : ( 9 === $id ? array( 10 ) : array() )
		);
		$reader->shouldReceive( 'get_by_id' )->andReturnUsing( fn( int $id ) => $this->rows[ $id ] ?? null );

		$this->rows[9] = (object) array( 'id' => '9', 'name' => 'Clubs', 'parent_id' => null, 'members_only' => '0', 'require_membership' => '0' );

		$this->wpdb         = Mockery::mock( 'wpdb' );
		$this->wpdb->prefix = 'wp_';
		// The memberships query binds the tree's ids after the two tables and
		// the user: answer only the rows of that tree.
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( $sql, ...$args ) {
				$this->bound = is_array( $args[0] ?? null ) ? $args[0] : $args;
				return $sql;
			}
		);
		$this->wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function () {
				$ids = array_map( 'intval', array_slice( $this->bound, 3 ) );
				return array_values( array_filter( $this->memberships, static fn( $row ) => in_array( (int) $row['id'], $ids, true ) ) );
			}
		);
		$this->wpdb->shouldReceive( 'get_col' )->andReturnUsing( fn() => $this->self_join_ids );
		$GLOBALS['wpdb'] = $this->wpdb;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	private function top( bool $members_only, bool $keep ): void {
		$this->rows[1] = (object) array(
			'id'                 => '1',
			'name'               => 'Staff',
			'parent_id'          => null,
			'members_only'       => $members_only ? '1' : '0',
			'require_membership' => $keep ? '1' : '0',
		);
	}

	/**
	 * @param array<int, bool> $groups   Group id => allows self-join.
	 * @param array<int, int>  $joined   Group id => membership row id (join order); defaults to the group id.
	 */
	private function member_of( array $groups, array $joined = array() ): void {
		$this->memberships = array();
		foreach ( $groups as $id => $self_join ) {
			$this->memberships[] = array(
				'id'              => (string) $id,
				'name'            => 'G' . $id,
				'allow_self_join' => $self_join ? '1' : '0',
				'membership_id'   => (string) ( $joined[ $id ] ?? $id ),
			);
		}
	}

	public function test_the_root_of_a_grandchild_is_the_top_level_audience(): void {
		$this->assertSame( 1, AudienceTreeRules::root_id( 4 ) );
		$this->assertSame( 1, AudienceTreeRules::root_id( 1 ) );
	}

	public function test_keep_reads_off_while_members_only_is_off(): void {
		$this->top( false, true );

		$this->assertSame( array( 'members_only' => false, 'require_membership' => false ), AudienceTreeRules::access( 1 ) );
	}

	public function test_a_tree_without_members_only_is_seen_by_everyone(): void {
		$this->top( false, false );
		$this->member_of( array() );

		$this->assertTrue( AudienceTreeRules::can_see( 1, 5 ) );
	}

	public function test_a_members_only_tree_is_hidden_from_a_non_member(): void {
		$this->top( true, false );
		$this->member_of( array() );

		$this->assertFalse( AudienceTreeRules::can_see( 1, 5 ) );
	}

	public function test_an_admin_added_membership_counts(): void {
		$this->top( true, false );
		// Group 4 does not allow self-join: an administrator or an import put the user there.
		$this->member_of( array( 4 => false ) );

		$this->assertTrue( AudienceTreeRules::can_see( 1, 5 ) );
	}

	public function test_memberships_reads_ids_names_self_join_and_join_order(): void {
		$this->member_of( array( 2 => true, 4 => false ) );

		$this->assertSame(
			array(
				array( 'id' => 2, 'name' => 'G2', 'self_join' => true, 'membership_id' => 2 ),
				array( 'id' => 4, 'name' => 'G4', 'self_join' => false, 'membership_id' => 4 ),
			),
			AudienceTreeRules::memberships( 1, 5 )
		);
	}

	public function test_the_last_group_of_a_keep_tree_is_locked(): void {
		$this->top( true, true );
		$this->member_of( array( 3 => true ) );

		$this->assertTrue( AudienceTreeRules::is_last_kept_group( 3, 5 ) );
	}

	public function test_a_group_may_be_left_while_another_of_the_tree_is_held(): void {
		$this->top( true, true );
		$this->member_of( array( 3 => true, 4 => false ) );

		$this->assertFalse( AudienceTreeRules::is_last_kept_group( 3, 5 ) );
	}

	public function test_the_last_group_is_free_without_keep(): void {
		$this->top( true, false );
		$this->member_of( array( 3 => true ) );

		$this->assertFalse( AudienceTreeRules::is_last_kept_group( 3, 5 ) );
	}

	/**
	 * Several groups held in a kept tree: the earliest membership row stays,
	 * whatever the names' order.
	 */
	public function test_leave_all_keeps_the_group_joined_first(): void {
		$this->top( true, true );
		$this->self_join_ids = array( '2', '3', '4' );
		$this->member_of( array( 2 => true, 3 => true, 4 => true ), array( 2 => 40, 3 => 12, 4 => 31 ) );

		$this->assertSame(
			array( array( 'tree_id' => 1, 'tree_name' => 'Staff', 'group_id' => 3, 'group_name' => 'G3' ) ),
			AudienceTreeRules::groups_kept_on_leave_all( 5 )
		);
	}

	public function test_leave_all_keeps_nothing_when_an_admin_added_group_remains(): void {
		$this->top( true, true );
		$this->self_join_ids = array( '2' );
		$this->member_of( array( 2 => true, 4 => false ) );

		$this->assertSame( array(), AudienceTreeRules::groups_kept_on_leave_all( 5 ) );
	}

	public function test_leave_all_keeps_nothing_without_keep(): void {
		$this->top( true, false );
		$this->self_join_ids = array( '2' );
		$this->member_of( array( 2 => true ) );

		$this->assertSame( array(), AudienceTreeRules::groups_kept_on_leave_all( 5 ) );
	}

	/** One kept tree beside an open one: only the kept tree holds a group back. */
	public function test_leave_all_keeps_one_group_per_kept_tree_only(): void {
		$this->top( true, true );
		$this->self_join_ids = array( '2', '10' );
		$this->member_of( array( 2 => true, 10 => true ) );

		$this->assertSame(
			array( array( 'tree_id' => 1, 'tree_name' => 'Staff', 'group_id' => 2, 'group_name' => 'G2' ) ),
			AudienceTreeRules::groups_kept_on_leave_all( 5 )
		);
	}
}
