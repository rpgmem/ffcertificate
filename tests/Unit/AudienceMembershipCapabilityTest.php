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
 * @covers \FreeFormCertificate\Audience\AudienceWriter
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class AudienceMembershipCapabilityTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var object */
	private $wpdb;

	/** @var \Mockery\MockInterface */
	private $capabilities;

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

		// An ALIAS, not an overload: the call under test is static, and an
		// overload mock does not intercept a static call — it passes whether or
		// not the call happens, which is the vacuous-test shape #1030 swept.
		$this->capabilities = Mockery::mock( 'alias:\FreeFormCertificate\UserDashboard\CapabilityManager' );

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

	/** Make the insert succeed and the duplicate check say "not a member yet". */
	private function given_a_new_member(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'QUERY' );
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( '0' );
		$this->wpdb->shouldReceive( 'insert' )->andReturn( 1 );
	}

	public function test_adding_a_member_grants_the_audience_capability(): void {
		$this->given_a_new_member();
		$this->capabilities->shouldReceive( 'grant_audience_capabilities' )->once()->with( 42 );

		AudienceWriter::add_member( 7, 42 );
	}

	/**
	 * The path the admin screen actually uses — and the one that granted
	 * nothing before this change.
	 */
	public function test_bulk_adding_members_grants_to_every_one_of_them(): void {
		$this->given_a_new_member();
		foreach ( array( 10, 20, 30 ) as $user_id ) {
			$this->capabilities->shouldReceive( 'grant_audience_capabilities' )->once()->with( $user_id );
		}

		AudienceWriter::bulk_add_members( 7, array( 10, 20, 30 ) );
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

		foreach ( array( 4, 5 ) as $user_id ) {
			$this->capabilities->shouldReceive( 'grant_audience_capabilities' )->once()->with( $user_id );
		}

		AudienceWriter::set_members( 7, array( 4, 5 ) );
	}

	/**
	 * No row, no capability. The insert is what makes someone a member, so a
	 * failed one must not hand out a grant that nothing backs.
	 */
	public function test_a_failed_insert_grants_nothing(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'QUERY' );
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( '0' );
		$this->wpdb->shouldReceive( 'insert' )->andReturn( false );
		$this->capabilities->shouldNotReceive( 'grant_audience_capabilities' );

		AudienceWriter::add_member( 7, 42 );
	}

	/**
	 * Already a member: `add_member()` returns before inserting, so nothing is
	 * re-granted. Harmless either way — `grant_audience_capabilities()` is
	 * idempotent — but a grant here would mean the early return moved.
	 */
	public function test_an_existing_member_is_not_re_granted(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'QUERY' );
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( '1' );
		$this->capabilities->shouldNotReceive( 'grant_audience_capabilities' );

		AudienceWriter::add_member( 7, 42 );
	}
}
