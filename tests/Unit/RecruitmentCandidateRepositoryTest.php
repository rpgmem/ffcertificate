<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentCandidateRepository;

/**
 * Tests for RecruitmentCandidateRepository — covers CRUD primitives plus the
 * promotion link setter and the hash-based lookups (cpf, rf, email) used by
 * the CSV importer for cross-CSV / cross-notice candidate reuse.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentCandidateRepository
 */
class RecruitmentCandidateRepositoryTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		global $wpdb;
		$wpdb             = Mockery::mock( 'wpdb' );
		$wpdb->prefix     = 'wp_';
		$wpdb->insert_id  = 0;
		$wpdb->last_error = '';
		$this->wpdb       = $wpdb;

		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'current_time' )->justReturn( '2026-05-01 10:00:00' );

		$this->wpdb->shouldReceive( 'prepare' )
			->andReturnUsing(
				function () {
					return func_get_args()[0];
				}
			)
			->byDefault();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_get_table_name(): void {
		$this->assertSame( 'wp_ffc_recruitment_candidate', RecruitmentCandidateRepository::get_table_name() );
	}

	public function test_get_by_cpf_hash_returns_null_when_not_found(): void {
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );

		$this->assertNull( RecruitmentCandidateRepository::get_by_cpf_hash( 'abcd1234' ) );
	}

	public function test_get_by_cpf_hash_returns_row_on_match(): void {
		$row = (object) array( 'id' => '1', 'cpf_hash' => 'abcd1234' );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $row );

		$this->assertSame( $row, RecruitmentCandidateRepository::get_by_cpf_hash( 'abcd1234' ) );
	}

	public function test_create_rejects_when_required_fields_missing(): void {
		$this->wpdb->shouldNotReceive( 'insert' );

		$this->assertFalse( RecruitmentCandidateRepository::create( array( 'name' => 'Alice' ) ) );
		$this->assertFalse( RecruitmentCandidateRepository::create( array( 'pcd_hash' => 'h' ) ) );
		$this->assertFalse( RecruitmentCandidateRepository::create( array() ) );
	}

	public function test_create_includes_only_supplied_optional_columns(): void {
		$captured_data   = array();
		$captured_format = array();
		$this->wpdb->shouldReceive( 'insert' )
			->once()
			->andReturnUsing(
				function ( $table, $data, $format ) use ( &$captured_data, &$captured_format ) {
					$captured_data   = $data;
					$captured_format = $format;
					return 1;
				}
			);
		$this->wpdb->insert_id = 50;

		$id = RecruitmentCandidateRepository::create(
			array(
				'name'     => 'Alice',
				'pcd_hash' => 'pcd_hash_value',
				'cpf_hash' => 'cpf_hash_value',
			)
		);

		$this->assertSame( 50, $id );
		$this->assertArrayHasKey( 'name', $captured_data );
		$this->assertArrayHasKey( 'pcd_hash', $captured_data );
		$this->assertArrayHasKey( 'cpf_hash', $captured_data );
		$this->assertArrayNotHasKey( 'rf_hash', $captured_data, 'rf_hash should not be in INSERT when not supplied' );
		$this->assertArrayNotHasKey( 'email_hash', $captured_data );
		$this->assertCount( count( $captured_data ), $captured_format, 'format array must match data array length' );
	}

	public function test_create_returns_false_on_insert_failure(): void {
		$this->wpdb->shouldReceive( 'insert' )->once()->andReturn( false );

		$result = RecruitmentCandidateRepository::create(
			array(
				'name'     => 'Alice',
				'pcd_hash' => 'h',
			)
		);
		$this->assertFalse( $result );
	}

	public function test_update_does_not_allow_user_id_or_pcd_hash(): void {
		$captured = array();
		$this->wpdb->shouldReceive( 'update' )
			->once()
			->andReturnUsing(
				function ( $table, $data ) use ( &$captured ) {
					$captured = $data;
					return 1;
				}
			);

		RecruitmentCandidateRepository::update(
			3,
			array(
				'name'     => 'New Name',
				'phone'    => '555-0100',
				'user_id'  => 99,
				'pcd_hash' => 'tampered',
			)
		);

		$this->assertArrayHasKey( 'name', $captured );
		$this->assertArrayHasKey( 'phone', $captured );
		$this->assertArrayNotHasKey( 'user_id', $captured, 'user_id is set via set_user_id, not update' );
		$this->assertArrayNotHasKey( 'pcd_hash', $captured, 'PCD value is CSV-only per §12' );
	}

	public function test_set_user_id_writes_link(): void {
		$captured = array();
		$this->wpdb->shouldReceive( 'update' )
			->once()
			->andReturnUsing(
				function ( $table, $data, $where ) use ( &$captured ) {
					$captured = $data;
					return 1;
				}
			);

		$this->assertTrue( RecruitmentCandidateRepository::set_user_id( 5, 100 ) );
		$this->assertSame( 100, $captured['user_id'] );
	}

	public function test_set_user_id_can_clear_link(): void {
		$captured = array();
		$this->wpdb->shouldReceive( 'update' )
			->once()
			->andReturnUsing(
				function ( $table, $data, $where ) use ( &$captured ) {
					$captured = $data;
					return 1;
				}
			);

		$this->assertTrue( RecruitmentCandidateRepository::set_user_id( 5, null ) );
		$this->assertNull( $captured['user_id'] );
	}

	public function test_get_by_user_id_returns_array_for_each_match(): void {
		$rows = array(
			(object) array( 'id' => '1', 'user_id' => '100' ),
			(object) array( 'id' => '2', 'user_id' => '100' ),
		);
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( $rows );

		$result = RecruitmentCandidateRepository::get_by_user_id( 100 );
		$this->assertCount( 2, $result );
	}

	public function test_delete_returns_true_on_success(): void {
		$this->wpdb->shouldReceive( 'delete' )->once()->andReturn( 1 );

		$this->assertTrue( RecruitmentCandidateRepository::delete( 5 ) );
	}
}
