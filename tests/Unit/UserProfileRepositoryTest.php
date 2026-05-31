<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Repositories\UserProfileRepository;

/**
 * Tests for UserProfileRepository — issue #340 centralization of the
 * `ffc_user_profiles` table access points that lived inline in
 * UserManager + UserCreator.
 *
 * @covers \FreeFormCertificate\Repositories\UserProfileRepository
 */
class UserProfileRepositoryTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		global $wpdb;
		$wpdb            = Mockery::mock( 'wpdb' )->makePartial();
		$wpdb->prefix    = 'wp_';
		$wpdb->insert_id = 0;
		$this->wpdb      = $wpdb;

		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'wp_cache_flush' )->justReturn( true );
		Functions\when( 'wp_cache_flush_group' )->justReturn( true );
		Functions\when( 'current_time' )->justReturn( '2026-05-19 10:00:00' );

		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn ( $sql ) => $sql )->byDefault();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function repo(): UserProfileRepository {
		return new UserProfileRepository();
	}

	public function test_find_by_user_id_returns_row_when_present(): void {
		$row = array( 'id' => '1', 'user_id' => '7', 'display_name' => 'Alice' );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $row );

		$this->assertSame( $row, $this->repo()->findByUserId( 7 ) );
	}

	public function test_find_by_user_id_returns_null_when_missing(): void {
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );
		$this->assertNull( $this->repo()->findByUserId( 7 ) );
	}

	public function test_find_by_user_id_short_circuits_on_invalid_input(): void {
		$this->wpdb->shouldNotReceive( 'get_row' );
		$this->assertNull( $this->repo()->findByUserId( 0 ) );
	}

	public function test_exists_for_user_id_true_when_row_present(): void {
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '42' );
		$this->assertTrue( $this->repo()->existsForUserId( 7 ) );
	}

	public function test_exists_for_user_id_false_when_no_row(): void {
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( null );
		$this->assertFalse( $this->repo()->existsForUserId( 7 ) );
	}

	public function test_create_for_user_returns_insert_id_on_success(): void {
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( null );
		$this->wpdb->shouldReceive( 'insert' )->once()->andReturn( 1 );
		$this->wpdb->insert_id = 99;

		$this->assertSame( 99, $this->repo()->createForUser( 7, 'Alice' ) );
	}

	public function test_create_for_user_is_idempotent_when_row_exists(): void {
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '42' );
		$this->wpdb->shouldNotReceive( 'insert' );

		$this->assertFalse( $this->repo()->createForUser( 7, 'Alice' ) );
	}

	public function test_create_for_user_short_circuits_on_non_positive_id(): void {
		$this->wpdb->shouldNotReceive( 'insert' );
		$this->assertFalse( $this->repo()->createForUser( 0, 'Alice' ) );
	}

	public function test_create_for_user_returns_false_when_insert_fails(): void {
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( null );
		$this->wpdb->shouldReceive( 'insert' )->once()->andReturn( false );

		$this->assertFalse( $this->repo()->createForUser( 7, 'Alice' ) );
	}

	// ------------------------------------------------------------------
	// upsertForUserId() — full UserProfileService write path
	// ------------------------------------------------------------------

	public function test_upsert_for_user_id_updates_when_row_exists(): void {
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '42' );
		$this->wpdb->shouldReceive( 'update' )->once()->andReturn( 1 );

		$this->assertTrue( $this->repo()->upsertForUserId( 7, array( 'display_name' => 'Bob' ) ) );
	}

	public function test_upsert_for_user_id_inserts_when_row_missing(): void {
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( null );
		$this->wpdb->shouldReceive( 'insert' )->once()->andReturn( 1 );

		$this->assertTrue( $this->repo()->upsertForUserId( 7, array( 'display_name' => 'Bob' ) ) );
	}

	public function test_upsert_for_user_id_returns_false_when_update_fails(): void {
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '42' );
		$this->wpdb->shouldReceive( 'update' )->once()->andReturn( false );

		$this->assertFalse( $this->repo()->upsertForUserId( 7, array( 'display_name' => 'Bob' ) ) );
	}

	public function test_upsert_for_user_id_short_circuits_on_empty_data(): void {
		$this->wpdb->shouldNotReceive( 'update' );
		$this->wpdb->shouldNotReceive( 'insert' );

		$this->assertFalse( $this->repo()->upsertForUserId( 7, array() ) );
	}

	public function test_upsert_for_user_id_short_circuits_on_invalid_user(): void {
		$this->wpdb->shouldNotReceive( 'update' );
		$this->wpdb->shouldNotReceive( 'insert' );

		$this->assertFalse( $this->repo()->upsertForUserId( 0, array( 'display_name' => 'Bob' ) ) );
	}
}
