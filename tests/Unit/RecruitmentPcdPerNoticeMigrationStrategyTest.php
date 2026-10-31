<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Migrations\Strategies\RecruitmentPcdPerNoticeMigrationStrategy;
use FreeFormCertificate\Recruitment\RecruitmentPcdHasher;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * The card that copies each candidate's PCD flag onto every one of its notice
 * classifications (#2245).
 *
 * Runs with the real hasher and the real Encryption over a `$wpdb` double
 * that holds the classifications and their candidates' legacy hashes.
 *
 * @covers \FreeFormCertificate\Migrations\Strategies\RecruitmentPcdPerNoticeMigrationStrategy
 */
class RecruitmentPcdPerNoticeMigrationStrategyTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const CLS = 'wp_ffc_recruitment_classification';

	/**
	 * Classifications by id.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $rows = array();

	/**
	 * Candidate-level legacy hash by candidate id.
	 *
	 * @var array<int, string>
	 */
	private array $candidates = array();

	/** @var array<string, mixed> */
	private array $options = array();

	/** @var bool */
	private bool $table = true;

	/** @var bool Whether the classification UPDATE fails. */
	private bool $refuse = false;

	/** @var array{0: bool, 1: mixed} */
	private array $wpdb_backup = array( false, null );

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->rows        = array();
		$this->candidates  = array();
		$this->options     = array();
		$this->table       = true;
		$this->refuse      = false;
		$this->wpdb_backup = array( array_key_exists( 'wpdb', $GLOBALS ), $GLOBALS['wpdb'] ?? null );

		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_salt' )->justReturn( 'test-auth-salt' );
		Functions\when( 'get_option' )->alias( fn( $key, $default = false ) => $this->options[ $key ] ?? $default );
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) {
				$this->options[ $key ] = $value;
				return true;
			}
		);

		global $wpdb;
		$wpdb             = Mockery::mock( 'wpdb' );
		$wpdb->prefix     = 'wp_';
		$wpdb->last_error = 'refused';

		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static fn( string $sql, ...$args ): array => array(
				'sql'  => $sql,
				'args' => $args,
			)
		);
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( array $prepared ) {
				if ( 0 === strpos( $prepared['sql'], 'SHOW TABLES' ) ) {
					return $this->table ? $prepared['args'][0] : null;
				}
				if ( false !== strpos( $prepared['sql'], 'WHERE pcd_hash IS NULL' ) ) {
					return (string) count( $this->pending() );
				}
				return (string) count( $this->rows );
			}
		);
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( array $prepared ) {
				$after = (int) $prepared['args'][2];
				$limit = (int) $prepared['args'][3];
				$out   = array();
				foreach ( $this->pending() as $id => $row ) {
					if ( $id > $after && count( $out ) < $limit ) {
						$out[] = array(
							'id'                 => (string) $id,
							'notice_id'          => (string) $row['notice_id'],
							'candidate_id'       => (string) $row['candidate_id'],
							'adjutancy_id'       => (string) $row['adjutancy_id'],
							'candidate_pcd_hash' => $this->candidates[ $row['candidate_id'] ] ?? null,
						);
					}
				}
				return $out;
			}
		);
		$wpdb->shouldReceive( 'update' )->andReturnUsing(
			function ( string $table, array $data, array $where ) {
				if ( $this->refuse ) {
					return false;
				}
				$this->rows[ (int) $where['id'] ]['pcd_hash'] = $data['pcd_hash'];
				return 1;
			}
		);
	}

	protected function tearDown(): void {
		if ( $this->wpdb_backup[0] ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup[1];
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Rows still without a hash.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function pending(): array {
		ksort( $this->rows );
		return array_filter( $this->rows, static fn( array $r ): bool => null === $r['pcd_hash'] );
	}

	/**
	 * Store a classification awaiting the move.
	 */
	private function cls( int $id, int $notice, int $candidate ): void {
		$this->rows[ $id ] = array(
			'notice_id'    => $notice,
			'candidate_id' => $candidate,
			'adjutancy_id' => 4,
			'pcd_hash'     => null,
		);
	}

	/**
	 * The flag a classification now records.
	 */
	private function flag( int $id ): ?bool {
		$row = $this->rows[ $id ];
		return RecruitmentPcdHasher::read_row(
			(object) array(
				'notice_id'    => $row['notice_id'],
				'candidate_id' => $row['candidate_id'],
				'adjutancy_id' => $row['adjutancy_id'],
				'pcd_hash'     => $row['pcd_hash'],
			)
		)['pcd'];
	}

	private function config(): array {
		return array( 'batch_size' => 2 );
	}

	public function test_each_classification_takes_its_candidate_s_flag(): void {
		$this->candidates[7] = RecruitmentPcdHasher::compute( 7, true );
		$this->candidates[8] = RecruitmentPcdHasher::compute( 8, false );
		$this->cls( 1, 100, 7 );
		$this->cls( 2, 200, 7 );
		$this->cls( 3, 100, 8 );

		$strategy = new RecruitmentPcdPerNoticeMigrationStrategy();
		$first    = $strategy->execute( 'recruitment_pcd_per_notice', $this->config() );
		$second   = $strategy->execute( 'recruitment_pcd_per_notice', $this->config() );

		$this->assertSame( 2, $first['processed'] );
		$this->assertTrue( $first['has_more'] );
		$this->assertSame( 1, $second['processed'] );
		$this->assertFalse( $second['has_more'] );
		$this->assertTrue( $this->flag( 1 ) );
		$this->assertTrue( $this->flag( 2 ), 'Every notice of a PCD candidate starts PCD.' );
		$this->assertFalse( $this->flag( 3 ) );
		$this->assertSame( 0, $strategy->calculate_status( 'recruitment_pcd_per_notice', $this->config() )['pending'] );
	}

	public function test_an_unreadable_flag_is_recorded_as_general_and_counted(): void {
		$this->candidates[7] = str_repeat( 'a', 64 );
		$this->cls( 1, 100, 7 );
		$this->cls( 2, 100, 9 );

		$result = ( new RecruitmentPcdPerNoticeMigrationStrategy() )->execute( 'recruitment_pcd_per_notice', $this->config() );

		$this->assertSame( 2, $result['processed'] );
		$this->assertFalse( $this->flag( 1 ) );
		$this->assertFalse( $this->flag( 2 ), 'A candidate with no hash at all reads as general too.' );
		$this->assertSame( 2, $this->options[ RecruitmentPcdPerNoticeMigrationStrategy::STATE_OPTION ]['unreadable'] );
		$this->assertStringContainsString( '2 candidate flags could not be read', $result['message'] );
	}

	public function test_a_failed_write_is_reported_and_the_cursor_still_moves(): void {
		$this->candidates[7] = RecruitmentPcdHasher::compute( 7, true );
		$this->cls( 1, 100, 7 );
		$this->refuse = true;

		$strategy = new RecruitmentPcdPerNoticeMigrationStrategy();
		$result   = $strategy->execute( 'recruitment_pcd_per_notice', $this->config() );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 0, $result['processed'] );
		$this->assertSame( array( 'refused' ), $result['errors'] );
		$this->assertSame( 1, $this->options[ RecruitmentPcdPerNoticeMigrationStrategy::STATE_OPTION ]['cursor'] );

		$next = $strategy->execute( 'recruitment_pcd_per_notice', $this->config() );
		$this->assertFalse( $next['has_more'], 'The cursor passed the row, so the run ends instead of looping on it (#1378).' );
		$this->assertSame( 0, $this->options[ RecruitmentPcdPerNoticeMigrationStrategy::STATE_OPTION ]['cursor'], 'The empty read rewinds the cursor for a retry.' );

		$this->refuse = false;
		$retry        = $strategy->execute( 'recruitment_pcd_per_notice', $this->config() );
		$this->assertSame( 1, $retry['processed'], 'A later run retries the row that failed.' );
		$this->assertTrue( $this->flag( 1 ) );
	}

	public function test_status_counts_rows_without_a_hash(): void {
		$this->cls( 1, 100, 7 );
		$this->rows[2] = array(
			'notice_id'    => 100,
			'candidate_id' => 8,
			'adjutancy_id' => 4,
			'pcd_hash'     => RecruitmentPcdHasher::compute_for_row( 100, 8, 4, false ),
		);

		$status = ( new RecruitmentPcdPerNoticeMigrationStrategy() )->calculate_status( 'recruitment_pcd_per_notice', $this->config() );

		$this->assertSame( 2, $status['total'] );
		$this->assertSame( 1, $status['pending'] );
		$this->assertSame( 1, $status['migrated'] );
		$this->assertFalse( $status['is_complete'] );
	}

	public function test_a_missing_table_is_complete_and_writes_nothing(): void {
		$this->table = false;
		$strategy    = new RecruitmentPcdPerNoticeMigrationStrategy();

		$this->assertTrue( $strategy->calculate_status( 'recruitment_pcd_per_notice', array() )['is_complete'] );
		$result = $strategy->execute( 'recruitment_pcd_per_notice', array() );
		$this->assertSame( 0, $result['processed'] );
		$this->assertFalse( $result['has_more'] );
		$this->assertTrue( $strategy->can_run( 'recruitment_pcd_per_notice', array() ) );
		$this->assertSame( 'Recruitment: PCD per Notice', $strategy->get_name() );
	}
}
