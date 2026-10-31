<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentDeleteService;

/**
 * Tests for RecruitmentDeleteService — pins each §7-bis gate (candidate,
 * classification, adjutancy) and the `blocked_by` reference-count payload
 * surfaced when a gate fires.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentDeleteService
 */
class RecruitmentDeleteServiceTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' )->makePartial();
		$wpdb->prefix = 'wp_';
		$this->wpdb   = $wpdb;

		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'current_time' )->justReturn( '2026-05-01 10:00:00' );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'absint' )->alias( 'intval' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'add_action' )->justReturn( true );

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

	private function candidate_stub( int $id ): object {
		return (object) array(
			'id'              => (string) $id,
			'user_id'         => null,
			'name'            => 'Test',
			'cpf_encrypted'   => null,
			'cpf_hash'        => null,
			'rf_encrypted'    => null,
			'rf_hash'         => null,
			'email_encrypted' => null,
			'email_hash'      => null,
			'phone'           => null,
			'notes'           => null,
			'pcd_hash'        => 'h',
			'created_at'      => '2026-05-01 10:00:00',
			'updated_at'      => '2026-05-01 10:00:00',
		);
	}

	private function classification_stub( int $id, string $status ): object {
		return (object) array(
			'id'           => (string) $id,
			'candidate_id' => '1',
			'adjutancy_id' => '2',
			'notice_id'    => '5',
			'list_type'    => 'preview',
			'rank'         => '1',
			'score'        => '90.0000',
			'status'       => $status,
			'created_at'   => '2026-05-01 10:00:00',
			'updated_at'   => '2026-05-01 10:00:00',
		);
	}

	private function notice_stub( string $status ): object {
		return (object) array(
			'id'                    => '5',
			'code'                  => 'EDITAL-2026-01',
			'name'                  => 'Test',
			'status'                => $status,
			'opened_at'             => null,
			'closed_at'             => null,
			'was_reopened'          => '0',
			'public_columns_config' => '{}',
			'created_at'            => '2026-05-01 10:00:00',
			'updated_at'            => '2026-05-01 10:00:00',
		);
	}

	private function adjutancy_stub( int $id ): object {
		return (object) array(
			'id'         => (string) $id,
			'slug'       => 'mat',
			'name'       => 'Matemática',
			'created_at' => '2026-05-01 10:00:00',
			'updated_at' => '2026-05-01 10:00:00',
		);
	}

	// ==================================================================
	// delete_candidate
	// ==================================================================

	public function test_delete_candidate_rejects_unknown_id(): void {
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );

		$result = RecruitmentDeleteService::delete_candidate( 999 );

		$this->assertFalse( $result['success'] );
		$this->assertSame( array( 'recruitment_candidate_not_found' ), $result['errors'] );
	}

	public function test_delete_candidate_blocked_by_classifications(): void {
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $this->candidate_stub( 1 ) );
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '3' );

		$result = RecruitmentDeleteService::delete_candidate( 1 );

		$this->assertFalse( $result['success'] );
		$this->assertSame( array( 'recruitment_candidate_has_classifications' ), $result['errors'] );
		$this->assertSame( array( 'classifications' => 3 ), $result['blocked_by'] );
	}

	public function test_delete_candidate_succeeds_when_no_classifications(): void {
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $this->candidate_stub( 1 ) );
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '0' );
		$this->wpdb->shouldReceive( 'delete' )
			->once()
			->with( 'wp_ffc_recruitment_candidate', array( 'id' => 1 ), array( '%d' ) )
			->andReturn( 1 );

		$result = RecruitmentDeleteService::delete_candidate( 1 );

		$this->assertTrue( $result['success'] );
		$this->assertArrayNotHasKey( 'blocked_by', $result );
	}

	// ==================================================================
	// delete_classification
	// ==================================================================

	public function test_delete_classification_rejects_unknown_id(): void {
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );

		$result = RecruitmentDeleteService::delete_classification( 999 );

		$this->assertFalse( $result['success'] );
		$this->assertSame( array( 'recruitment_classification_not_found' ), $result['errors'] );
	}

	public function test_delete_classification_rejects_when_not_empty(): void {
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $this->classification_stub( 10, 'called' ) );

		$result = RecruitmentDeleteService::delete_classification( 10 );

		$this->assertFalse( $result['success'] );
		$this->assertSame( array( 'recruitment_classification_not_empty_for_delete' ), $result['errors'] );
	}

	public function test_delete_classification_rejects_when_notice_active(): void {
		$this->wpdb->shouldReceive( 'get_row' )
			->twice()
			->andReturn(
				$this->classification_stub( 10, 'empty' ),
				$this->notice_stub( 'definitive' )
			);

		$result = RecruitmentDeleteService::delete_classification( 10 );

		$this->assertFalse( $result['success'] );
		$this->assertSame(
			array( 'recruitment_classification_delete_requires_draft_or_preliminary' ),
			$result['errors']
		);
	}

	public function test_delete_classification_succeeds_when_empty_and_draft(): void {
		$this->wpdb->shouldReceive( 'get_row' )
			->twice()
			->andReturn(
				$this->classification_stub( 10, 'empty' ),
				$this->notice_stub( 'draft' )
			);
		$this->wpdb->shouldReceive( 'delete' )->once()->andReturn( 1 );

		$result = RecruitmentDeleteService::delete_classification( 10 );

		$this->assertTrue( $result['success'] );
	}

	public function test_delete_classification_succeeds_when_empty_and_preliminary(): void {
		$this->wpdb->shouldReceive( 'get_row' )
			->twice()
			->andReturn(
				$this->classification_stub( 10, 'empty' ),
				$this->notice_stub( 'preliminary' )
			);
		$this->wpdb->shouldReceive( 'delete' )->once()->andReturn( 1 );

		$result = RecruitmentDeleteService::delete_classification( 10 );

		$this->assertTrue( $result['success'] );
	}

	// ==================================================================
	// delete_adjutancy
	// ==================================================================

	public function test_delete_adjutancy_rejects_unknown_id(): void {
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );

		$result = RecruitmentDeleteService::delete_adjutancy( 999 );

		$this->assertFalse( $result['success'] );
		$this->assertSame( array( 'recruitment_adjutancy_not_found' ), $result['errors'] );
	}

	public function test_delete_adjutancy_blocked_by_notice_attachment(): void {
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $this->adjutancy_stub( 2 ) );
		// notice_adjutancy lookup returns one notice ID; classification count is 0.
		$this->wpdb->shouldReceive( 'get_col' )->once()->andReturn( array( 5 ) );
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '0' );

		$result = RecruitmentDeleteService::delete_adjutancy( 2 );

		$this->assertFalse( $result['success'] );
		$this->assertSame( array( 'recruitment_adjutancy_in_use' ), $result['errors'] );
		$this->assertSame(
			array(
				'notice_adjutancies' => 1,
				'classifications'    => 0,
			),
			$result['blocked_by']
		);
	}

	public function test_delete_adjutancy_blocked_by_classification_history(): void {
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $this->adjutancy_stub( 2 ) );
		// No notice attachment, but historical classifications still reference the slug.
		$this->wpdb->shouldReceive( 'get_col' )->once()->andReturn( array() );
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '7' );

		$result = RecruitmentDeleteService::delete_adjutancy( 2 );

		$this->assertFalse( $result['success'] );
		$this->assertSame(
			array(
				'notice_adjutancies' => 0,
				'classifications'    => 7,
			),
			$result['blocked_by']
		);
	}

	public function test_delete_adjutancy_succeeds_when_unused(): void {
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $this->adjutancy_stub( 2 ) );
		$this->wpdb->shouldReceive( 'get_col' )->once()->andReturn( array() );
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '0' );
		$this->wpdb->shouldReceive( 'delete' )
			->once()
			->with( 'wp_ffc_recruitment_adjutancy', array( 'id' => 2 ), array( '%d' ) )
			->andReturn( 1 );

		$result = RecruitmentDeleteService::delete_adjutancy( 2 );

		$this->assertTrue( $result['success'] );
		$this->assertArrayNotHasKey( 'blocked_by', $result );
	}

	// ------------------------------------------------------------------
	// delete_notice() — draft only, in one transaction (#2245)
	// ------------------------------------------------------------------

	public function test_delete_notice_removes_a_draft_with_its_rows_in_one_transaction(): void {
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $this->notice_stub( 'draft' ) );
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '0' );
		$statements = array();
		$this->wpdb->shouldReceive( 'query' )->andReturnUsing(
			function ( $sql ) use ( &$statements ) {
				$statements[] = $sql;
				return 1;
			}
		);
		$deletes = array();
		$this->wpdb->shouldReceive( 'delete' )->andReturnUsing(
			function ( $table, $where ) use ( &$deletes ) {
				$deletes[] = array( $table, $where );
				return 'wp_ffc_recruitment_classification' === $table ? 4 : 1;
			}
		);

		$result = RecruitmentDeleteService::delete_notice( 5 );

		$this->assertTrue( $result['success'] );
		$this->assertSame( array( 'START TRANSACTION', 'COMMIT' ), $statements );
		$this->assertSame(
			array(
				array( 'wp_ffc_recruitment_classification', array( 'notice_id' => 5 ) ),
				array( 'wp_ffc_recruitment_notice_adjutancy', array( 'notice_id' => 5 ) ),
				array( 'wp_ffc_recruitment_notice', array( 'id' => 5 ) ),
			),
			$deletes
		);
	}

	/**
	 * @dataProvider provider_published_statuses
	 */
	public function test_delete_notice_refuses_every_status_but_draft( string $status ): void {
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $this->notice_stub( $status ) );
		$this->wpdb->shouldNotReceive( 'delete' );
		$this->wpdb->shouldNotReceive( 'query' );

		$result = RecruitmentDeleteService::delete_notice( 5 );

		$this->assertFalse( $result['success'] );
		$this->assertSame( array( 'recruitment_notice_delete_requires_draft' ), $result['errors'] );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function provider_published_statuses(): array {
		return array(
			'preliminary' => array( 'preliminary' ),
			'definitive'  => array( 'definitive' ),
			'closed'      => array( 'closed' ),
		);
	}

	public function test_delete_notice_refuses_a_draft_that_somehow_has_calls(): void {
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $this->notice_stub( 'draft' ) );
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '2' );
		$this->wpdb->shouldNotReceive( 'delete' );

		$result = RecruitmentDeleteService::delete_notice( 5 );

		$this->assertFalse( $result['success'] );
		$this->assertSame( array( 'calls' => 2 ), $result['blocked_by'] );
	}

	public function test_delete_notice_rolls_back_when_the_notice_row_is_not_deleted(): void {
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $this->notice_stub( 'draft' ) );
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '0' );
		$statements = array();
		$this->wpdb->shouldReceive( 'query' )->andReturnUsing(
			function ( $sql ) use ( &$statements ) {
				$statements[] = $sql;
				return 1;
			}
		);
		$this->wpdb->shouldReceive( 'delete' )->andReturnUsing(
			static fn( $table ) => 'wp_ffc_recruitment_notice' === $table ? false : 0
		);

		$result = RecruitmentDeleteService::delete_notice( 5 );

		$this->assertFalse( $result['success'] );
		$this->assertSame( array( 'recruitment_notice_delete_failed' ), $result['errors'] );
		$this->assertSame( array( 'START TRANSACTION', 'ROLLBACK' ), $statements );
	}

	public function test_delete_notice_reports_a_missing_notice(): void {
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );

		$result = RecruitmentDeleteService::delete_notice( 5 );

		$this->assertSame( array( 'recruitment_notice_not_found' ), $result['errors'] );
	}
}
