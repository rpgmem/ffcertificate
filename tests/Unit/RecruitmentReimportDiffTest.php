<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentReimportDiff;

/**
 * The comparison a re-import shows before it writes, and the manual
 * preliminary marks it carries across (#2245).
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentReimportDiff
 */
class RecruitmentReimportDiffTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var mixed */
	private $wpdb_backup;

	private bool $had_wpdb = false;

	/** @var \Mockery\MockInterface */
	private $wpdb;

	/** @var list<array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}> */
	private array $updates = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;

		Functions\when( '__' )->returnArg();
		Functions\when( '_n' )->alias( static fn( $single, $plural, $n ) => 1 === (int) $n ? $single : $plural );
		Functions\when( 'number_format_i18n' )->alias( static fn( $n ) => (string) $n );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'current_time' )->justReturn( '2026-10-31 12:00:00' );

		$this->wpdb         = Mockery::mock( 'wpdb' );
		$this->wpdb->prefix = 'wp_';
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( $sql, ...$args ) {
				return (string) $sql;
			}
		);
		$this->wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			static function ( $sql ) {
				if ( false !== strpos( (string) $sql, 'WHERE id IN' ) ) {
					return array(
						(object) array( 'id' => '100', 'name' => 'Ana' ),
						(object) array( 'id' => '101', 'name' => 'Bruno' ),
						(object) array( 'id' => '102', 'name' => 'Carla' ),
					);
				}
				return array( (object) array( 'id' => '2', 'name' => 'Science' ) );
			}
		);
		$updates = &$this->updates;
		$this->wpdb->shouldReceive( 'update' )->andReturnUsing(
			static function ( $table, $data, $where ) use ( &$updates ) {
				$updates[] = array( (string) $table, (array) $data, (array) $where );
				return 1;
			}
		);
		$GLOBALS['wpdb'] = $this->wpdb;
	}

	protected function tearDown(): void {
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A stored row.
	 */
	private function stored( int $candidate, int $rank, string $score, string $mark = 'empty', ?string $reason = null, int $adjutancy = 2 ): object {
		return (object) array(
			'candidate_id'      => (string) $candidate,
			'adjutancy_id'      => (string) $adjutancy,
			'rank'              => (string) $rank,
			'score'             => $score,
			'preview_status'    => $mark,
			'preview_reason_id' => $reason,
		);
	}

	/**
	 * A row of the new list.
	 *
	 * @return array{candidate_id: int, adjutancy_id: int, rank: int, score: string}
	 */
	private function incoming( int $candidate, int $rank, string $score, int $adjutancy = 2 ): array {
		return array(
			'candidate_id' => $candidate,
			'adjutancy_id' => $adjutancy,
			'rank'         => $rank,
			'score'        => $score,
		);
	}

	public function test_rows_are_matched_by_candidate_and_adjutancy(): void {
		$diff = RecruitmentReimportDiff::compute(
			array(
				$this->stored( 100, 1, '90.0000', 'denied' ),
				$this->stored( 101, 2, '80' ),
				$this->stored( 101, 1, '70', 'granted', null, 3 ),
			),
			array(
				$this->incoming( 100, 1, '90' ),
				$this->incoming( 101, 1, '85' ),
				$this->incoming( 102, 3, '60' ),
			)
		);

		$this->assertSame( 1, $diff['unchanged'], 'A score written as 90.0000 and as 90 is the same.' );
		$this->assertSame( array( 101 ), array_column( $diff['changed'], 'candidate_id' ) );
		$this->assertSame( 2, $diff['changed'][0]['old_rank'] );
		$this->assertSame( array( 102 ), array_column( $diff['added'], 'candidate_id' ) );
		$this->assertSame( array( 3 ), array_column( $diff['removed'], 'adjutancy_id' ), 'The same candidate in another adjutancy is another row.' );
		$this->assertSame( 1, $diff['marks_kept'] );
		$this->assertSame( 1, $diff['marks_lost'] );
		$this->assertTrue( $diff['removed'][0]['marked'] );
		$this->assertFalse( RecruitmentReimportDiff::is_identical( $diff ) );
	}

	public function test_an_unchanged_list_is_identical(): void {
		$diff = RecruitmentReimportDiff::compute(
			array( $this->stored( 100, 1, '90' ) ),
			array( $this->incoming( 100, 1, '90.00' ) )
		);

		$this->assertTrue( RecruitmentReimportDiff::is_identical( $diff ) );
	}

	public function test_marks_skip_rows_with_nothing_set_by_hand(): void {
		$marks = RecruitmentReimportDiff::marks(
			array(
				$this->stored( 100, 1, '90', 'denied', '7' ),
				$this->stored( 101, 2, '80' ),
				$this->stored( 102, 3, '70', 'empty', '4' ),
			)
		);

		$this->assertSame(
			array(
				'100|2' => array(
					'status'    => 'denied',
					'reason_id' => 7,
				),
				'102|2' => array(
					'status'    => 'empty',
					'reason_id' => 4,
				),
			),
			$marks
		);
	}

	public function test_restore_marks_writes_only_the_rows_that_stay(): void {
		$ok = RecruitmentReimportDiff::restore_marks(
			array(
				'100|2' => array(
					'status'    => 'denied',
					'reason_id' => 7,
				),
				'999|2' => array(
					'status'    => 'granted',
					'reason_id' => null,
				),
			),
			array( '100|2' => 300 )
		);

		$this->assertTrue( $ok );
		$this->assertCount( 1, $this->updates );
		$this->assertSame( array( 'id' => 300 ), $this->updates[0][2] );
		$this->assertSame( 'denied', $this->updates[0][1]['preview_status'] );
		$this->assertSame( 7, $this->updates[0][1]['preview_reason_id'] );
	}

	public function test_messages_count_every_group_and_name_the_rows(): void {
		$diff = RecruitmentReimportDiff::compute(
			array(
				$this->stored( 100, 1, '90', 'denied' ),
				$this->stored( 101, 2, '80' ),
			),
			array(
				$this->incoming( 101, 1, '85' ),
				$this->incoming( 102, 2, '60' ),
			)
		);

		$messages = RecruitmentReimportDiff::messages( $diff );

		$this->assertSame(
			array(
				'1 row enters the list.',
				'1 row leaves the list.',
				'1 row changes rank or score.',
				'0 rows are unchanged.',
				'Manual preliminary statuses: 0 kept, 1 lost with the rows that leave.',
			),
			$messages['summary']
		);
		$this->assertSame(
			array(
				'Leaves: Ana, Science, rank 1. Its manual status is lost.',
				'Enters: Carla, Science, rank 2.',
				'Changes: Bruno, Science, rank 2 → 1, score 80 → 85.',
			),
			$messages['lines']
		);
	}

	public function test_messages_list_at_most_the_limit_and_count_the_rest(): void {
		$incoming = array();
		for ( $i = 1; $i <= RecruitmentReimportDiff::LIST_LIMIT + 3; $i++ ) {
			$incoming[] = $this->incoming( 1000 + $i, $i, '50' );
		}
		$diff = RecruitmentReimportDiff::compute( array( $this->stored( 100, 1, '90' ) ), $incoming );

		$lines = RecruitmentReimportDiff::messages( $diff )['lines'];

		$this->assertCount( 1 + RecruitmentReimportDiff::LIST_LIMIT + 1, $lines );
		$this->assertSame( '3 more rows are not listed.', end( $lines ) );
	}
}
