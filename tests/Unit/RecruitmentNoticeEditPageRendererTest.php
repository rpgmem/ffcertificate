<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentNoticeEditPageRenderer;

/**
 * Tests for RecruitmentNoticeEditPageRenderer::compute_empties_by_adjutancy() —
 * the authoritative out-of-order map that seeds the client justification gate
 * from the full (unfiltered/unpaginated) definitive queue (#Item7). Previously
 * the JS scanned the rendered DOM, which a filter/page narrows — so a filtered
 * view hid the true lower-rank empties and skipped the prompt.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentNoticeEditPageRenderer
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class RecruitmentNoticeEditPageRendererTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * @param array<int, object> $rows
	 * @return array<string, array<int, array{id:int, rank:int}>>
	 */
	private function invoke( array $rows ): array {
		$ref = new \ReflectionMethod( RecruitmentNoticeEditPageRenderer::class, 'compute_empties_by_adjutancy' );
		$ref->setAccessible( true );
		/** @var array<string, array<int, array{id:int, rank:int}>> $out */
		$out = $ref->invoke( null, $rows );
		return $out;
	}

	private function row( int $id, int $rank, string $status, int $adjutancy_id ): object {
		return (object) array(
			'id'           => $id,
			'rank'         => $rank,
			'status'       => $status,
			'adjutancy_id' => $adjutancy_id,
		);
	}

	public function test_groups_empties_by_adjutancy_slug_sorted_by_rank_excluding_non_empty(): void {
		$adj = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentAdjutancyReader' );
		$adj->shouldReceive( 'get_by_id' )->andReturnUsing(
			static fn( $id ) => (object) array( 'slug' => 'adj-' . (int) $id )
		);

		$rows = array(
			$this->row( 10, 3, 'empty', 1 ),
			$this->row( 11, 1, 'empty', 1 ),
			$this->row( 12, 2, 'called', 1 ), // not empty → excluded.
			$this->row( 13, 5, 'hired', 1 ),  // not empty → excluded.
			$this->row( 14, 1, 'empty', 2 ),
			$this->row( 15, 4, 'empty', 2 ),
		);

		$map = $this->invoke( $rows );

		$this->assertSame(
			array(
				'adj-1' => array(
					array( 'id' => 11, 'rank' => 1 ),
					array( 'id' => 10, 'rank' => 3 ),
				),
				'adj-2' => array(
					array( 'id' => 14, 'rank' => 1 ),
					array( 'id' => 15, 'rank' => 4 ),
				),
			),
			$map
		);
	}

	public function test_returns_empty_map_when_no_empty_rows(): void {
		// No alias needed — the method short-circuits before touching the repo.
		$rows = array(
			$this->row( 1, 1, 'called', 1 ),
			$this->row( 2, 2, 'hired', 1 ),
		);

		$this->assertSame( array(), $this->invoke( $rows ) );
	}

	public function test_falls_back_to_hashed_id_key_when_adjutancy_slug_missing(): void {
		$adj = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentAdjutancyReader' );
		// Repo can't resolve the adjutancy → lookup_map skips it → slug falls
		// back to '#<id>' so the row still appears in the queue map.
		$adj->shouldReceive( 'get_by_id' )->andReturn( null );

		$map = $this->invoke( array( $this->row( 99, 1, 'empty', 7 ) ) );

		$this->assertSame(
			array( '#7' => array( array( 'id' => 99, 'rank' => 1 ) ) ),
			$map
		);
	}
}
