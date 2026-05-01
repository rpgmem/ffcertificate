<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentNoticeRepository;

/**
 * Tests for RecruitmentNoticeRepository — covers CRUD primitives plus the
 * atomic state-machine primitives used by the notice state machine
 * (sprint 5): set_status, mark_opened, mark_closed, mark_reopened.
 *
 * The atomic primitives are tested for the "expected current matched"
 * (returns 1) and "expected current did not match" (returns 0, race lost)
 * scenarios — the core contract callers depend on.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentNoticeRepository
 */
class RecruitmentNoticeRepositoryTest extends TestCase {

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
		$this->assertSame( 'wp_ffc_recruitment_notice', RecruitmentNoticeRepository::get_table_name() );
	}

	public function test_get_by_code_uppercases_input_before_lookup(): void {
		$captured_value = null;
		$this->wpdb->shouldReceive( 'prepare' )
			->andReturnUsing(
				function ( $sql, ...$values ) use ( &$captured_value ) {
					if ( false !== stripos( $sql, 'WHERE code = %s' ) ) {
						$captured_value = $values[1];
					}
					return $sql;
				}
			);
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );

		RecruitmentNoticeRepository::get_by_code( 'edital-2026-01' );

		$this->assertSame( 'EDITAL-2026-01', $captured_value, 'Code lookup should be case-normalized' );
	}

	public function test_create_uppercases_code_and_uses_default_columns_config(): void {
		$captured = array();
		$this->wpdb->shouldReceive( 'insert' )
			->once()
			->andReturnUsing(
				function ( $table, $data ) use ( &$captured ) {
					$captured = $data;
					return 1;
				}
			);
		$this->wpdb->insert_id = 11;

		$id = RecruitmentNoticeRepository::create( 'edital-2026-01', 'Edital de 2026' );

		$this->assertSame( 11, $id );
		$this->assertSame( 'EDITAL-2026-01', $captured['code'] );
		$this->assertSame( 'draft', $captured['status'] );
		$this->assertSame( 0, $captured['was_reopened'] );
		$this->assertSame(
			RecruitmentNoticeRepository::DEFAULT_PUBLIC_COLUMNS_CONFIG,
			$captured['public_columns_config']
		);
	}

	public function test_set_status_returns_one_when_expected_current_matches(): void {
		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( 1 );

		$affected = RecruitmentNoticeRepository::set_status( 5, 'preliminary', 'active' );
		$this->assertSame( 1, $affected );
	}

	public function test_set_status_returns_zero_when_expected_current_did_not_match(): void {
		// Concurrent transition: another writer changed status before us.
		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( 0 );

		$affected = RecruitmentNoticeRepository::set_status( 5, 'preliminary', 'active' );
		$this->assertSame( 0, $affected );
	}

	public function test_mark_reopened_flips_flag_only_first_time(): void {
		// First call hits the WHERE was_reopened = 0 row.
		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( 1 );
		$this->assertSame( 1, RecruitmentNoticeRepository::mark_reopened( 7 ) );

		// Second call: row already at was_reopened=1, so 0 affected.
		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( 0 );
		$this->assertSame( 0, RecruitmentNoticeRepository::mark_reopened( 7 ) );
	}

	public function test_mark_opened_returns_zero_when_already_set(): void {
		// WHERE opened_at IS NULL fails to match — already opened.
		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( 0 );
		$this->assertSame( 0, RecruitmentNoticeRepository::mark_opened( 3 ) );
	}

	public function test_mark_closed_overwrites_existing_closed_at(): void {
		// No WHERE guard on mark_closed — every call updates.
		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( 1 );
		$this->assertSame( 1, RecruitmentNoticeRepository::mark_closed( 3 ) );
	}

	public function test_update_only_writes_meta_keys_and_uppercases_code(): void {
		$captured = array();
		$this->wpdb->shouldReceive( 'update' )
			->once()
			->andReturnUsing(
				function ( $table, $data ) use ( &$captured ) {
					$captured = $data;
					return 1;
				}
			);

		RecruitmentNoticeRepository::update(
			3,
			array(
				'code'                  => 'lowercase-code',
				'name'                  => 'Renamed',
				'public_columns_config' => '{"rank":true}',
				'status'                => 'should be ignored — use set_status',
			)
		);

		$this->assertSame( 'LOWERCASE-CODE', $captured['code'] );
		$this->assertSame( 'Renamed', $captured['name'] );
		$this->assertSame( '{"rank":true}', $captured['public_columns_config'] );
		$this->assertArrayNotHasKey( 'status', $captured );
	}
}
