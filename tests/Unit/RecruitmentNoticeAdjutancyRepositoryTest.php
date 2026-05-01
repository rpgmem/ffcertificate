<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentNoticeAdjutancyRepository;

/**
 * Tests for RecruitmentNoticeAdjutancyRepository — the N:N junction.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentNoticeAdjutancyRepository
 */
class RecruitmentNoticeAdjutancyRepositoryTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$this->wpdb   = $wpdb;

		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );

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
		$this->assertSame( 'wp_ffc_recruitment_notice_adjutancy', RecruitmentNoticeAdjutancyRepository::get_table_name() );
	}

	public function test_attach_returns_true_on_successful_insert(): void {
		$this->wpdb->shouldReceive( 'insert' )->once()->andReturn( 1 );

		$this->assertTrue( RecruitmentNoticeAdjutancyRepository::attach( 1, 2 ) );
	}

	public function test_attach_returns_false_on_pk_conflict(): void {
		$this->wpdb->shouldReceive( 'insert' )->once()->andReturn( false );

		$this->assertFalse( RecruitmentNoticeAdjutancyRepository::attach( 1, 2 ) );
	}

	public function test_detach_returns_true_when_pair_existed(): void {
		$this->wpdb->shouldReceive( 'delete' )->once()->andReturn( 1 );

		$this->assertTrue( RecruitmentNoticeAdjutancyRepository::detach( 1, 2 ) );
	}

	public function test_detach_returns_false_when_pair_did_not_exist(): void {
		$this->wpdb->shouldReceive( 'delete' )->once()->andReturn( 0 );

		$this->assertFalse( RecruitmentNoticeAdjutancyRepository::detach( 1, 2 ) );
	}

	public function test_is_attached_returns_true_when_count_positive(): void {
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '1' );

		$this->assertTrue( RecruitmentNoticeAdjutancyRepository::is_attached( 1, 2 ) );
	}

	public function test_is_attached_returns_false_on_zero_count(): void {
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '0' );

		$this->assertFalse( RecruitmentNoticeAdjutancyRepository::is_attached( 1, 2 ) );
	}

	public function test_get_adjutancy_ids_for_notice_returns_int_array(): void {
		$this->wpdb->shouldReceive( 'get_col' )->once()->andReturn( array( '1', '2', '3' ) );

		$result = RecruitmentNoticeAdjutancyRepository::get_adjutancy_ids_for_notice( 5 );
		$this->assertSame( array( 1, 2, 3 ), $result );
	}

	public function test_get_notice_ids_for_adjutancy_returns_int_array(): void {
		$this->wpdb->shouldReceive( 'get_col' )->once()->andReturn( array( '10', '20' ) );

		$result = RecruitmentNoticeAdjutancyRepository::get_notice_ids_for_adjutancy( 7 );
		$this->assertSame( array( 10, 20 ), $result );
	}

	public function test_detach_all_for_notice_returns_count_of_removed_pairs(): void {
		$this->wpdb->shouldReceive( 'delete' )->once()->andReturn( 3 );

		$this->assertSame( 3, RecruitmentNoticeAdjutancyRepository::detach_all_for_notice( 5 ) );
	}
}
