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
 * (anonymous + unlinked-user → empty render) and, through a real `$wpdb`
 * double, which list a notice shows: the preliminary one for a
 * preliminary notice, the definitive one otherwise (#1889 groups them in
 * Waiting).
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentDashboardSection
 */
class RecruitmentDashboardSectionTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $wpdb;

	/** @var array<int, string> Stylesheet handles enqueued during the test. */
	private array $styles = array();

	/** @var array<int, string> Script handles enqueued during the test. */
	private array $scripts = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' )->makePartial();
		$wpdb->prefix = 'wp_';
		$this->wpdb   = $wpdb;

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( '_x' )->returnArg();
		Functions\when( 'esc_html_x' )->returnArg();
		Functions\when( '_n' )->alias( static fn ( $single, $plural, $n ) => 1 === (int) $n ? $single : $plural );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 100 );
		Functions\when( 'number_format_i18n' )->alias( function ( $number, $decimals = 0 ) {
			return number_format( (float) $number, (int) $decimals );
		} );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_date' )->alias( function ( $format, $ts = null ) {
			return gmdate( $format, $ts ?? time() );
		} );
		Functions\when( 'wp_timezone' )->alias( function () {
			return new \DateTimeZone( 'UTC' );
		} );

		$this->wpdb->shouldReceive( 'prepare' )
			->andReturnUsing( static fn ( $sql ) => $sql )
			->byDefault();

		// A rendered section loads the dashboard's sheets (#1832).
		$this->styles = array();
		Functions\when( 'wp_enqueue_style' )->alias(
			function ( $handle ) {
				$this->styles[] = $handle;
			}
		);
		// ... and the script that pages its tables (#1889).
		$this->scripts = array();
		Functions\when( 'wp_enqueue_script' )->alias(
			function ( $handle ) {
				$this->scripts[] = $handle;
			}
		);
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
		// Nothing to style, so nothing is loaded.
		$this->assertSame( array(), $this->styles );
	}

	public function test_render_skips_draft_notices(): void {
		// User has a candidate row + 1 classification, but the parent notice
		// is in `draft` → must be filtered out → definitive render is empty.
		$this->wpdb->shouldReceive( 'get_results' )
			->twice()
			->andReturn( array( $this->candidate_stub() ), array( $this->classification_stub( 'preview' ) ) );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $this->notice_stub( 'draft' ) );

		$this->assertSame( '', RecruitmentDashboardSection::render() );
	}

	public function test_a_preliminary_notice_lists_its_preview_row_in_the_preliminary_group(): void {
		// get_results: candidates → classifications → calls; get_row: the
		// notice, then the adjutancy the Waiting row names.
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

		$this->assertStringContainsString( 'Preliminary classification — subject to review', $html );
		$this->assertStringContainsString( 'ffc-mycalls-badge-preliminary', $html );
		$this->assertStringNotContainsString( 'ffc-mycalls-badge-final', $html );
		$this->assertStringContainsString( 'Matemática', $html );
		$this->assertStringNotContainsString( 'id="ffc-mycalls-called"', $html, 'No call, no Called section.' );
	}

	public function test_a_definitive_notice_lists_its_row_in_the_final_group(): void {
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
				$this->notice_stub( 'definitive' ),
				$this->adjutancy_stub()
			);

		$html = RecruitmentDashboardSection::render();

		$this->assertStringContainsString( 'ffc-mycalls-badge-final', $html );
		$this->assertStringNotContainsString( 'ffc-mycalls-badge-preliminary', $html );
		// #1832: on a page of its own the section's rules come from the
		// dashboard's sheet, so the shortcode loads it.
		$this->assertSame( array( 'ffc-common', 'ffc-dashboard' ), $this->styles );
		$this->assertSame( array( 'ffc-recruitment-my-calls' ), $this->scripts );
	}
}
