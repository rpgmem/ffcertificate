<?php
/**
 * Membership is what earns the audience capability (#1302).
 *
 * `ffc_view_own_audience_bookings` used to arrive by two unrelated routes, and
 * only one of them was about membership. The self-join REST route called
 * `AudienceWriter::add_member()` and then granted the capability; the admin-side
 * routes — `AudienceAjaxController` → `set_members()` / `bulk_add_members()` —
 * granted nothing. What covered that gap was `AudienceActivator` giving the
 * capability to WordPress's own `subscriber` role, which reached every
 * subscriber on the site whether or not they belonged to any audience, and left
 * an FFC capability on a role this plugin does not own for the life of the
 * install.
 *
 * The grant now sits at the single point every entry passes through, which is
 * the same reason the cache invalidation sits there (see
 * `AudienceUserCacheInvalidationTest`). These tests are one per entry, so a new
 * membership path that bypasses `add_member()` is a decision somebody has to
 * make deliberately rather than one that silently drops the capability.
 *
 * @package FreeFormCertificate\Tests\Unit
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Audience\AudienceWriter;

/**
 * CapabilityManager is the real class: a grant is read off the user it was
 * given to (#2121).
 *
 * @covers \FreeFormCertificate\Audience\AudienceWriter
 */
final class AudienceMembershipCapabilityTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var object */
	private $wpdb;

	/**
	 * Users get_userdata() handed out, by id.
	 *
	 * @var array<int, \WP_User>
	 */
	private array $users = array();

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
		class_exists( '\FreeFormCertificate\Audience\AudienceWriter' );

		// The real CapabilityManager adds the capability to the user
		// get_userdata() returns; each user is kept so the test can read it.
		// No settings: the activity log and the grant e-mail stay off.
		$this->users = array();
		Functions\when( 'get_userdata' )->alias(
			function ( $id ) {
				$id = (int) $id;
				if ( ! isset( $this->users[ $id ] ) ) {
					$this->users[ $id ] = new \WP_User( $id );
				}
				return $this->users[ $id ];
			}
		);
		Functions\when( 'get_option' )->alias( static fn( $key, $fallback = false ) => 'ffc_settings' === $key ? array() : $fallback );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );

		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'absint' )->alias(
			static function ( $value ) {
				return abs( (int) $value );
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}

	/**
	 * The ids of the users who now hold the audience capability.
	 *
	 * @return array<int, int>
	 */
	private function granted(): array {
		$ids = array();
		foreach ( $this->users as $id => $user ) {
			if ( $user->has_cap( 'ffc_view_own_audience_bookings' ) ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/** Make the insert succeed and the duplicate check say "not a member yet". */
	private function given_a_new_member(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'QUERY' );
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( '0' );
		$this->wpdb->shouldReceive( 'insert' )->andReturn( 1 );
	}

	public function test_adding_a_member_grants_the_audience_capability(): void {
		$this->given_a_new_member();

		AudienceWriter::add_member( 7, 42 );

		$this->assertSame( array( 42 ), $this->granted() );
	}

	/**
	 * The path the admin screen actually uses — and the one that granted
	 * nothing before this change.
	 */
	public function test_bulk_adding_members_grants_to_every_one_of_them(): void {
		$this->given_a_new_member();

		AudienceWriter::bulk_add_members( 7, array( 10, 20, 30 ) );

		$this->assertSame( array( 10, 20, 30 ), $this->granted() );
	}

	/**
	 * `set_members()` is a wipe followed by a loop over `add_member()`, so it is
	 * covered by construction — pinned because that is a property of the
	 * implementation, and a rewrite that stopped looping would take the grant
	 * with it silently.
	 */
	public function test_replacing_the_member_list_grants_to_the_new_members(): void {
		$this->given_a_new_member();
		$this->wpdb->shouldReceive( 'delete' )->andReturn( 1 );
		$this->wpdb->shouldReceive( 'get_col' )->andReturn( array() );
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( array() );

		AudienceWriter::set_members( 7, array( 4, 5 ) );

		$this->assertSame( array( 4, 5 ), $this->granted() );
	}

	/**
	 * No row, no capability. The insert is what makes someone a member, so a
	 * failed one must not hand out a grant that nothing backs.
	 */
	public function test_a_failed_insert_grants_nothing(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'QUERY' );
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( '0' );
		$this->wpdb->shouldReceive( 'insert' )->andReturn( false );

		AudienceWriter::add_member( 7, 42 );

		$this->assertSame( array(), $this->granted() );
	}

	/**
	 * Already a member: `add_member()` returns before inserting, so nothing is
	 * re-granted. Harmless either way — `grant_audience_capabilities()` is
	 * idempotent — but a grant here would mean the early return moved.
	 */
	public function test_an_existing_member_is_not_re_granted(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'QUERY' );
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( '1' );

		AudienceWriter::add_member( 7, 42 );

		$this->assertSame( array(), $this->granted() );
	}
}
