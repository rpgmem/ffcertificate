<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentCallRepository;

/**
 * Tests for RecruitmentCallRepository — covers the append-only history
 * contract, the §3.6 invariant on `out_of_order` + `out_of_order_reason`,
 * and the idempotent `mark_cancelled` semantics.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentCallRepository
 */
class RecruitmentCallRepositoryTest extends TestCase {

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
		$this->assertSame( 'wp_ffc_recruitment_call', RecruitmentCallRepository::get_table_name() );
	}

	public function test_create_rejects_out_of_order_without_reason(): void {
		$this->wpdb->shouldNotReceive( 'insert' );

		$result = RecruitmentCallRepository::create(
			array(
				'classification_id' => 1,
				'date_to_assume'    => '2026-06-01',
				'time_to_assume'    => '08:00',
				'created_by'        => 1,
				'out_of_order'      => 1,
				// Missing out_of_order_reason.
			)
		);
		$this->assertFalse( $result, '§3.6 invariant: out_of_order=1 requires non-empty reason' );
	}

	public function test_create_rejects_out_of_order_with_blank_reason(): void {
		$this->wpdb->shouldNotReceive( 'insert' );

		$result = RecruitmentCallRepository::create(
			array(
				'classification_id'   => 1,
				'date_to_assume'      => '2026-06-01',
				'time_to_assume'      => '08:00',
				'created_by'          => 1,
				'out_of_order'        => 1,
				'out_of_order_reason' => '   ',
			)
		);
		$this->assertFalse( $result, 'Whitespace-only reason still violates the invariant' );
	}

	public function test_create_succeeds_with_out_of_order_and_reason(): void {
		$this->wpdb->shouldReceive( 'insert' )->once()->andReturn( 1 );
		$this->wpdb->insert_id = 7;

		$result = RecruitmentCallRepository::create(
			array(
				'classification_id'   => 1,
				'date_to_assume'      => '2026-06-01',
				'time_to_assume'      => '08:00',
				'created_by'          => 1,
				'out_of_order'        => 1,
				'out_of_order_reason' => 'Candidate withdrew earlier in the day',
			)
		);
		$this->assertSame( 7, $result );
	}

	public function test_create_in_order_call_does_not_require_reason(): void {
		$this->wpdb->shouldReceive( 'insert' )->once()->andReturn( 1 );
		$this->wpdb->insert_id = 8;

		$result = RecruitmentCallRepository::create(
			array(
				'classification_id' => 1,
				'date_to_assume'    => '2026-06-01',
				'time_to_assume'    => '08:00',
				'created_by'        => 1,
			)
		);
		$this->assertSame( 8, $result );
	}

	public function test_mark_cancelled_returns_one_on_first_cancel(): void {
		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( 1 );

		$this->assertSame( 1, RecruitmentCallRepository::mark_cancelled( 5, 'Admin reverted', 100 ) );
	}

	public function test_mark_cancelled_is_idempotent_within_a_single_cancel(): void {
		// Second call hits a row already cancelled — WHERE cancelled_at IS NULL fails.
		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( 0 );

		$this->assertSame( 0, RecruitmentCallRepository::mark_cancelled( 5, 'reason', 100 ) );
	}

	public function test_get_active_for_classification_returns_uncancelled_row(): void {
		$row = (object) array(
			'id'           => '9',
			'cancelled_at' => null,
		);
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $row );

		$this->assertSame( $row, RecruitmentCallRepository::get_active_for_classification( 1 ) );
	}

	public function test_get_active_for_classification_returns_null_when_only_cancelled_calls_exist(): void {
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );

		$this->assertNull( RecruitmentCallRepository::get_active_for_classification( 1 ) );
	}

	public function test_get_history_for_classifications_returns_empty_when_no_ids(): void {
		$this->wpdb->shouldNotReceive( 'get_results' );

		$this->assertSame( array(), RecruitmentCallRepository::get_history_for_classifications( array() ) );
	}

	public function test_count_for_classification_returns_int(): void {
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '3' );

		$this->assertSame( 3, RecruitmentCallRepository::count_for_classification( 5 ) );
	}

	public function test_update_only_writes_notes(): void {
		$captured = array();
		$this->wpdb->shouldReceive( 'update' )
			->once()
			->andReturnUsing(
				function ( $table, $data ) use ( &$captured ) {
					$captured = $data;
					return 1;
				}
			);

		RecruitmentCallRepository::update(
			3,
			array(
				'notes'        => 'Updated notes',
				'cancelled_at' => 'should be ignored — append-only contract',
				'created_by'   => 'should be ignored',
			)
		);

		$this->assertArrayHasKey( 'notes', $captured );
		$this->assertArrayNotHasKey( 'cancelled_at', $captured );
		$this->assertArrayNotHasKey( 'created_by', $captured );
	}
}
