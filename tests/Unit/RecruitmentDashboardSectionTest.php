<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentDashboardSection;

/**
 * Tests for RecruitmentDashboardSection — pins the §9.1 visibility rule
 * (anonymous + unlinked-user → empty render), the basic notice-block
 * rendering for a logged-in candidate, and the prévia/final banner
 * dispatch.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentDashboardSection
 */
class RecruitmentDashboardSectionTest extends TestCase {

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

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 100 );

		$this->wpdb->shouldReceive( 'prepare' )
			->andReturnUsing( static fn ( $sql ) => $sql )
			->byDefault();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function candidate_stub(): object {
		return (object) array(
			'id'              => '50',
			'user_id'         => '100',
			'name'            => 'Alice',
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

	private function classification_stub( string $list_type, string $status = 'empty' ): object {
		return (object) array(
			'id'           => '10',
			'candidate_id' => '50',
			'adjutancy_id' => '2',
			'notice_id'    => '5',
			'list_type'    => $list_type,
			'rank'         => '7',
			'score'        => '85.0000',
			'status'       => $status,
			'created_at'   => '2026-05-01 10:00:00',
			'updated_at'   => '2026-05-01 10:00:00',
		);
	}

	private function notice_stub( string $status, string $code = 'EDITAL-2026-01' ): object {
		return (object) array(
			'id'                    => '5',
			'code'                  => $code,
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

	private function adjutancy_stub(): object {
		return (object) array(
			'id'         => '2',
			'slug'       => 'matematica',
			'name'       => 'Matemática',
			'created_at' => '2026-05-01 10:00:00',
			'updated_at' => '2026-05-01 10:00:00',
		);
	}

	public function test_render_returns_empty_for_anonymous(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		// wpdb must NOT be queried at all.
		$this->wpdb->shouldNotReceive( 'get_results' );
		$this->wpdb->shouldNotReceive( 'get_row' );

		$this->assertSame( '', RecruitmentDashboardSection::render() );
	}

	public function test_render_returns_empty_when_user_has_no_linked_candidate(): void {
		// get_by_user_id returns empty array.
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( array() );

		$this->assertSame( '', RecruitmentDashboardSection::render() );
	}

	public function test_render_skips_draft_notices(): void {
		// User has a candidate row + 1 classification, but the parent notice
		// is in `draft` → must be filtered out → final render is empty.
		$this->wpdb->shouldReceive( 'get_results' )
			->twice()
			->andReturn( array( $this->candidate_stub() ), array( $this->classification_stub( 'preview' ) ) );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $this->notice_stub( 'draft' ) );

		$this->assertSame( '', RecruitmentDashboardSection::render() );
	}

	public function test_render_includes_preliminary_banner(): void {
		// Two get_results: candidates → classifications.
		// One get_row for the notice, then one for the adjutancy in render_classifications_table.
		// get_history_for_classifications returns empty.
		$this->wpdb->shouldReceive( 'get_results' )
			->times( 3 )
			->andReturn(
				array( $this->candidate_stub() ),
				array( $this->classification_stub( 'preview' ) ),
				array() // call history
			);
		$this->wpdb->shouldReceive( 'get_row' )
			->times( 2 )
			->andReturn(
				$this->notice_stub( 'preliminary' ),
				$this->adjutancy_stub()
			);

		$html = RecruitmentDashboardSection::render();

		$this->assertStringContainsString( 'Preliminary classification', $html );
		$this->assertStringNotContainsString( 'Final classification', $html );
		// And the no-calls hint when no convocations exist yet.
		$this->assertStringContainsString( 'You have not been called for this notice yet', $html );
	}

	public function test_render_includes_final_banner_for_active_notice(): void {
		$this->wpdb->shouldReceive( 'get_results' )
			->times( 3 )
			->andReturn(
				array( $this->candidate_stub() ),
				array( $this->classification_stub( 'definitive' ) ),
				array()
			);
		$this->wpdb->shouldReceive( 'get_row' )
			->times( 2 )
			->andReturn(
				$this->notice_stub( 'active' ),
				$this->adjutancy_stub()
			);

		$html = RecruitmentDashboardSection::render();

		$this->assertStringContainsString( 'Final classification', $html );
		$this->assertStringNotContainsString( 'Preliminary classification', $html );
	}
}
