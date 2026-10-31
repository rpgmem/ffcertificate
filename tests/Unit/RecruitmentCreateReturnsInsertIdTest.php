<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentAdjutancyWriter;
use FreeFormCertificate\Recruitment\RecruitmentCallWriter;
use FreeFormCertificate\Recruitment\RecruitmentCandidateWriter;
use FreeFormCertificate\Recruitment\RecruitmentClassificationRepository;
use FreeFormCertificate\Recruitment\RecruitmentNoticeWriter;
use FreeFormCertificate\Recruitment\RecruitmentReasonWriter;

/**
 * Every recruitment `create()` returns the id of the row IT inserted, even
 * when a listener on `ffc_recruitment_public_cache_dirty` writes to the
 * database and moves `$wpdb->insert_id`.
 *
 * The listener that does this is real: the public cache version is an option,
 * and the first bump on a fresh site INSERTs it. Reading `insert_id` after the
 * action returned that option's id instead, so on a fresh install an
 * adjutancy created first was reported under a wrong id and the notice it
 * was attached to stayed empty (#2245, found by the fresh-install flow check).
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentAdjutancyWriter::create
 * @covers \FreeFormCertificate\Recruitment\RecruitmentCallWriter::create
 * @covers \FreeFormCertificate\Recruitment\RecruitmentCandidateWriter::create
 * @covers \FreeFormCertificate\Recruitment\RecruitmentClassificationRepository::create
 * @covers \FreeFormCertificate\Recruitment\RecruitmentNoticeWriter::create
 * @covers \FreeFormCertificate\Recruitment\RecruitmentReasonWriter::create
 */
class RecruitmentCreateReturnsInsertIdTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		global $wpdb;
		$wpdb             = Mockery::mock( 'wpdb' )->makePartial();
		$wpdb->prefix     = 'wp_';
		$wpdb->insert_id  = 0;
		$wpdb->last_error = '';
		$this->wpdb       = $wpdb;

		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'current_time' )->justReturn( '2026-05-01 10:00:00' );
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_hex_color' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function () {
				return func_get_args()[0];
			}
		)->byDefault();
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( null )->byDefault();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @return array<string, array{0: callable(): (int|false)}>
	 */
	public function creators(): array {
		return array(
			'adjutancy'      => array( static fn() => RecruitmentAdjutancyWriter::create( 'math', 'Math' ) ),
			'reason'         => array( static fn() => RecruitmentReasonWriter::create( 'late', 'Late' ) ),
			'notice'         => array( static fn() => RecruitmentNoticeWriter::create( 'N-1', 'Notice' ) ),
			'candidate'      => array(
				static fn() => RecruitmentCandidateWriter::create(
					array(
						'name'     => 'Ana',
						'pcd_hash' => 'h',
					)
				),
			),
			'classification' => array(
				static fn() => RecruitmentClassificationRepository::create(
					array(
						'candidate_id' => 1,
						'adjutancy_id' => 1,
						'notice_id'    => 1,
						'list_type'    => 'preview',
						'rank'         => 1,
						'score'        => '1',
					)
				),
			),
			'call'           => array(
				static fn() => RecruitmentCallWriter::create(
					array(
						'classification_id' => 1,
						'date_to_assume'    => '2026-05-02',
						'time_to_assume'    => '09:00:00',
						'created_by'        => 1,
					)
				),
			),
		);
	}

	/**
	 * @dataProvider creators
	 *
	 * @param callable(): (int|false) $create The create call under test.
	 */
	public function test_create_returns_its_own_id_when_a_listener_writes( callable $create ): void {
		$wpdb = $this->wpdb;
		$wpdb->shouldReceive( 'insert' )->once()->andReturnUsing(
			static function () use ( $wpdb ) {
				$wpdb->insert_id = 7;
				return 1;
			}
		);

		// The listener inserts a row of its own, as add_option() does.
		Actions\expectDone( 'ffc_recruitment_public_cache_dirty' )->whenHappen(
			static function () use ( $wpdb ) {
				$wpdb->insert_id = 184;
			}
		);

		$this->assertSame( 7, $create() );
	}
}
