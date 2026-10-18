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

	/** @var list<int> Self-join group ids the leave-all query answers. */
	private array $self_join_ids = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$parents = array( 1 => null, 2 => 1, 3 => 1, 4 => 2, 9 => null );
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
			static fn( int $id ): array => 1 === $id ? array( 2, 4, 3 ) : array()
		);
		$reader->shouldReceive( 'get_by_id' )->andReturnUsing( fn( int $id ) => $this->rows[ $id ] ?? null );

		$this->wpdb         = Mockery::mock( 'wpdb' );
		$this->wpdb->prefix = 'wp_';
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn( $sql ) => $sql );
		$this->wpdb->shouldReceive( 'get_results' )->andReturnUsing( fn() => $this->memberships );
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

	private function member_of( array $groups ): void {
		$this->memberships = array();
		foreach ( $groups as $id => $self_join ) {
			$this->memberships[] = array( 'id' => (string) $id, 'name' => 'G' . $id, 'allow_self_join' => $self_join ? '1' : '0' );
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

	public function test_memberships_reads_ids_names_and_self_join(): void {
		$this->member_of( array( 2 => true, 4 => false ) );

		$this->assertSame(
			array(
				array( 'id' => 2, 'name' => 'G2', 'self_join' => true ),
				array( 'id' => 4, 'name' => 'G4', 'self_join' => false ),
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

	public function test_leave_all_is_blocked_when_every_group_held_is_self_join(): void {
		$this->top( true, true );
		$this->self_join_ids = array( '2', '3' );
		$this->member_of( array( 2 => true, 3 => true ) );

		$this->assertSame( array( array( 'id' => 1, 'name' => 'Staff' ) ), AudienceTreeRules::trees_blocking_leave_all( 5 ) );
	}

	public function test_leave_all_is_free_when_an_admin_added_group_remains(): void {
		$this->top( true, true );
		$this->self_join_ids = array( '2' );
		$this->member_of( array( 2 => true, 4 => false ) );

		$this->assertSame( array(), AudienceTreeRules::trees_blocking_leave_all( 5 ) );
	}

	public function test_leave_all_is_free_without_keep(): void {
		$this->top( true, false );
		$this->self_join_ids = array( '2' );
		$this->member_of( array( 2 => true ) );

		$this->assertSame( array(), AudienceTreeRules::trees_blocking_leave_all( 5 ) );
	}
}
