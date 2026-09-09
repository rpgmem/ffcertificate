<?php
/**
 * Every audience mutator must drop the per-user audience cache (#1127).
 *
 * `AudienceReader::get_user_audiences()` is cached, and the cache used to live
 * in a literal `'ffcertificate'` group of its own — outside the group the
 * Reader/Writer pair shares, so nothing about the pair's own invalidation
 * reached it and each mutator had to remember on its own. Five of them did not,
 * `bulk_add_members()` among them, which is the path an operator actually uses
 * from the admin screen. The reader is the entry point of the profile custom
 * fields, the user dashboard and the reregistration campaign lookup, so a stale
 * entry decides those wrong.
 *
 * The key now lives in `cache_group()` and every mutator routes through one
 * private helper. This is what stops the next mutator from being written
 * without it: one test per mutator, each failing on the code as it was.
 *
 * **It does not need a persistent object cache to be a real defect.** Without
 * Redis/Memcached `wp_cache_*` lives inside the request and the stale entry
 * dies with it — the hole is the same, it just does not surface. With one, it
 * survived until the entry was evicted, because the old `wp_cache_set()` call
 * passed no expiry either.
 *
 * @package FreeFormCertificate\Tests\Unit
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Audience\AudienceReader;
use FreeFormCertificate\Audience\AudienceWriter;

/**
 * @covers \FreeFormCertificate\Audience\AudienceWriter
 * @covers \FreeFormCertificate\Audience\AudienceReader
 */
final class AudienceUserCacheInvalidationTest extends TestCase {

	/**
	 * The group every entry this pair writes belongs to.
	 */
	private const GROUP = 'ffc_audiences';

	/**
	 * @var \Mockery\MockInterface&\wpdb
	 */
	private $wpdb;

	/**
	 * Deleted cache entries, as `group::key`.
	 *
	 * @var array<int, string>
	 */
	private array $deleted = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		global $wpdb;
		$wpdb             = Mockery::mock( 'wpdb' );
		$wpdb->prefix     = 'wp_';
		$wpdb->last_error = '';
		$wpdb->insert_id  = 1;
		$this->wpdb       = $wpdb;

		// pcov attribution (CLAUDE.md gotcha).
		class_exists( '\FreeFormCertificate\Audience\AudienceReader' );
		class_exists( '\FreeFormCertificate\Audience\AudienceWriter' );

		$this->deleted = array();
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->alias(
			function ( $key, $group = '' ) {
				$this->deleted[] = $group . '::' . $key;
				return true;
			}
		);
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'absint' )->alias(
			static function ( $value ) {
				return abs( (int) $value );
			}
		);
		Functions\when( '__' )->returnArg();
		Functions\when( 'sanitize_sql_orderby' )->returnArg();
		Functions\when( 'wp_parse_args' )->alias(
			static function ( $args, $defaults = array() ) {
				return array_merge( $defaults, is_array( $args ) ? $args : array() );
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}

	/**
	 * Assert both variants of a user's cached list were dropped.
	 *
	 * Both, always: `include_parents` is a different key holding a different
	 * answer, and dropping one of the two leaves the other lying.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	private function assertUserInvalidated( int $user_id ): void {
		$this->assertContains(
			self::GROUP . '::user_aud_' . $user_id . '_0',
			$this->deleted,
			"The plain list of user {$user_id} was not invalidated."
		);
		$this->assertContains(
			self::GROUP . '::user_aud_' . $user_id . '_1',
			$this->deleted,
			"The include_parents list of user {$user_id} was not invalidated."
		);
	}

	// ==================================================================
	// The two leaf mutators — and the bulk wrappers they carry
	// ==================================================================

	public function test_add_member_invalidates_the_user(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'QUERY' );
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( '0' );
		$this->wpdb->shouldReceive( 'insert' )->once()->andReturn( 1 );

		AudienceWriter::add_member( 7, 42 );

		$this->assertUserInvalidated( 42 );
	}

	public function test_a_failed_add_does_not_invalidate(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'QUERY' );
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( '0' );
		$this->wpdb->shouldReceive( 'insert' )->once()->andReturn( false );

		AudienceWriter::add_member( 7, 42 );

		$this->assertSame( array(), $this->deleted, 'Nothing changed, so nothing should be dropped.' );
	}

	public function test_remove_member_invalidates_the_user(): void {
		$this->wpdb->shouldReceive( 'delete' )->once()->andReturn( 1 );

		AudienceWriter::remove_member( 7, 42 );

		$this->assertUserInvalidated( 42 );
	}

	/**
	 * The path the admin screen actually uses.
	 */
	public function test_bulk_add_members_invalidates_every_user(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'QUERY' );
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( '0' );
		$this->wpdb->shouldReceive( 'insert' )->andReturn( 1 );

		AudienceWriter::bulk_add_members( 7, array( 10, 20, 30 ) );

		$this->assertUserInvalidated( 10 );
		$this->assertUserInvalidated( 20 );
		$this->assertUserInvalidated( 30 );
	}

	public function test_bulk_remove_members_invalidates_every_user(): void {
		$this->wpdb->shouldReceive( 'delete' )->andReturn( 1 );

		AudienceWriter::bulk_remove_members( 7, array( 10, 20 ) );

		$this->assertUserInvalidated( 10 );
		$this->assertUserInvalidated( 20 );
	}

	// ==================================================================
	// set_members — the half that was missing even where it invalidated
	// ==================================================================

	public function test_set_members_invalidates_the_users_it_drops(): void {
		// The audience currently holds 10 and 99; the new list keeps only 10.
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'QUERY' );
		$this->wpdb->shouldReceive( 'get_col' )->once()->andReturn( array( '10', '99' ) );
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( '0' );
		$this->wpdb->shouldReceive( 'delete' )->andReturn( 2 );
		$this->wpdb->shouldReceive( 'insert' )->andReturn( 1 );

		AudienceWriter::set_members( 7, array( 10 ) );

		$this->assertUserInvalidated( 10 );
		$this->assertUserInvalidated( 99 );
	}

	// ==================================================================
	// The audience row itself — not membership, same cached answer
	// ==================================================================

	public function test_delete_invalidates_the_members_of_the_whole_subtree(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'QUERY' );
		// get_members( id, true ): descendant ids, then the member ids.
		$this->wpdb->shouldReceive( 'get_col' )->andReturn( array( '11', '22' ) );
		// get_children() during the recursion — none, so it stops immediately.
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( array() );
		$this->wpdb->shouldReceive( 'delete' )->andReturn( 1 );

		AudienceWriter::delete( 7 );

		$this->assertUserInvalidated( 11 );
		$this->assertUserInvalidated( 22 );
	}

	public function test_deactivating_an_audience_invalidates_its_members(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'QUERY' );
		$this->wpdb->shouldReceive( 'get_col' )->andReturn( array( '11' ) );
		// get_descendant_ids() walks the tree through get_children().
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( array() );
		$this->wpdb->shouldReceive( 'update' )->once()->andReturn( 1 );

		AudienceWriter::update( 7, array( 'status' => 'inactive' ) );

		$this->assertUserInvalidated( 11 );
	}

	public function test_reparenting_an_audience_invalidates_its_members(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'QUERY' );
		$this->wpdb->shouldReceive( 'get_col' )->andReturn( array( '11' ) );
		// get_descendant_ids() walks the tree through get_children().
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( array() );
		$this->wpdb->shouldReceive( 'update' )->once()->andReturn( 1 );

		AudienceWriter::update( 7, array( 'parent_id' => 3 ) );

		$this->assertUserInvalidated( 11 );
	}

	/**
	 * A rename changes nothing the cached list answers, so it pays nothing.
	 *
	 * Without this the cheap path would be free to become "invalidate always",
	 * which is a different bug: a query per update on a hot admin screen.
	 */
	public function test_renaming_an_audience_does_not_read_the_member_list(): void {
		$this->wpdb->shouldReceive( 'update' )->once()->andReturn( 1 );
		$this->wpdb->shouldNotReceive( 'get_col' );

		AudienceWriter::update( 7, array( 'name' => 'Novo nome' ) );

		$this->assertNotContains( self::GROUP . '::user_aud_11_0', $this->deleted );
	}

	/**
	 * The eighth mutator, which the issue's census missed entirely.
	 */
	public function test_cascade_self_join_invalidates_the_members_it_touches(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'QUERY' );
		$this->wpdb->shouldReceive( 'get_col' )->andReturn( array( '11' ) );
		$this->wpdb->shouldReceive( 'query' )->andReturn( 1 );

		$child = (object) array(
			'id'        => 9,
			'parent_id' => 7,
		);
		// One level of children, then none.
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( array( $child ) );
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( array() );

		AudienceWriter::cascade_self_join( 7, 1 );

		$this->assertUserInvalidated( 11 );
		$this->assertContains(
			self::GROUP . '::id_9',
			$this->deleted,
			'The child row whose allow_self_join just changed is still cached by id.'
		);
	}

	// ==================================================================
	// The key itself
	// ==================================================================

	public function test_the_reader_and_the_writer_agree_on_the_key(): void {
		$this->assertSame( 'user_aud_5_0', AudienceReader::user_audiences_cache_key( 5, false ) );
		$this->assertSame( 'user_aud_5_1', AudienceReader::user_audiences_cache_key( 5, true ) );
		$this->assertNotSame(
			AudienceReader::user_audiences_cache_key( 5, false ),
			AudienceReader::user_audiences_cache_key( 5, true ),
			'The two variants hold different answers and must not share a key.'
		);
	}

	/**
	 * The read path writes to the same group the mutators delete from.
	 *
	 * This is the whole point of the move: before #1127 the reader wrote to a
	 * literal `'ffcertificate'` group and a `cache_delete()` in the writer
	 * could never have reached it, however many mutators remembered to call it.
	 */
	public function test_the_read_path_caches_into_the_pair_s_own_group(): void {
		$groups = array();
		Functions\when( 'wp_cache_set' )->alias(
			static function ( $key, $value, $group = '', $ttl = 0 ) use ( &$groups ) {
				$groups[] = array( $group, $ttl );
				return true;
			}
		);
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'QUERY' );
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( array() );

		AudienceReader::get_user_audiences( 5 );

		$this->assertNotEmpty( $groups, 'The read path did not cache anything.' );
		$this->assertSame( self::GROUP, $groups[0][0] );
		$this->assertGreaterThan(
			0,
			$groups[0][1],
			'The entry is written with a TTL now — the old call passed none, so a stale list survived until eviction.'
		);
	}
}
