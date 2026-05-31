<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Maintenance\MaintenanceToolInterface;
use FreeFormCertificate\Maintenance\PublicOperatorAccessDisabler;

/**
 * Tests for the PublicOperatorAccessDisabler maintenance tool.
 *
 * @covers \FreeFormCertificate\Maintenance\PublicOperatorAccessDisabler
 */
class PublicOperatorAccessDisablerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<int, array{int, string, string}> */
	private $meta_writes = array();

	/** @var array<int, array<string, mixed>> */
	private $geofence_meta = array();

	/** @var array<int, object> */
	private $post_store = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->meta_writes   = array();
		$this->geofence_meta = array();
		$this->post_store    = array();

		$GLOBALS['ffc_test_wp_query_queue'] = array();
		$GLOBALS['ffc_test_wp_query_calls'] = array();

		Functions\when( '__' )->returnArg();

		// Capture meta writes (both global and namespaced resolution).
		$capture = function ( $post_id, $key, $value ) {
			$this->meta_writes[] = array( (int) $post_id, (string) $key, (string) $value );
			return true;
		};
		Functions\when( 'update_post_meta' )->alias( $capture );
		Functions\when( 'FreeFormCertificate\Maintenance\update_post_meta' )->alias( $capture );

		// Store-backed get_post (for the report title).
		$get_post = function ( $post_id ) {
			return $this->post_store[ (int) $post_id ] ?? null;
		};
		Functions\when( 'get_post' )->alias( $get_post );
		Functions\when( 'FreeFormCertificate\Maintenance\get_post' )->alias( $get_post );

		// Geofence reads _ffc_geofence_config via get_post_meta + wp_timezone.
		Functions\when( 'get_post_meta' )->alias(
			function ( $post_id, $key, $single = false ) {
				if ( '_ffc_geofence_config' !== $key ) {
					return '';
				}
				return $this->geofence_meta[ (int) $post_id ] ?? '';
			}
		);
		Functions\when( 'wp_timezone' )->alias(
			function () {
				return new \DateTimeZone( 'UTC' );
			}
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['ffc_test_wp_query_queue'], $GLOBALS['ffc_test_wp_query_calls'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	private function seed_expired_form( int $form_id, int $days_ago ): void {
		$this->geofence_meta[ $form_id ] = array(
			'date_end' => gmdate( 'Y-m-d', time() - ( $days_ago * DAY_IN_SECONDS ) ),
			'time_end' => '00:00:00',
		);
		$post              = new \WP_Post();
		$post->ID          = $form_id;
		$post->post_title  = 'Form ' . $form_id;
		$this->post_store[ $form_id ] = $post;
	}

	private function seed_future_form( int $form_id ): void {
		$this->geofence_meta[ $form_id ] = array(
			'date_end' => gmdate( 'Y-m-d', time() + ( 30 * DAY_IN_SECONDS ) ),
			'time_end' => '23:59:59',
		);
	}

	private function queue_wp_query_result( array $posts ): void {
		$GLOBALS['ffc_test_wp_query_queue'][] = $posts;
	}

	public function test_metadata_and_actionable(): void {
		$tool = new PublicOperatorAccessDisabler();
		$this->assertInstanceOf( MaintenanceToolInterface::class, $tool );
		$this->assertSame( 'public_access_disabler', $tool->get_id() );
		$this->assertTrue( $tool->is_actionable() );
		$this->assertSame(
			array(
				'days'    => PublicOperatorAccessDisabler::DEFAULT_DAYS,
				'dry_run' => true,
			),
			$tool->get_default_options()
		);
	}

	public function test_find_candidate_form_ids_filters_by_expiry(): void {
		$this->seed_expired_form( 10, 120 );
		$this->seed_future_form( 11 );
		$this->seed_expired_form( 12, 200 );
		$this->queue_wp_query_result( array( 10, 11, 12 ) );

		$ids = ( new PublicOperatorAccessDisabler() )->find_candidate_form_ids( 90 );

		$this->assertSame( array( 10, 12 ), $ids );
	}

	public function test_dry_run_reports_without_writing(): void {
		$this->seed_expired_form( 10, 120 );
		$this->seed_expired_form( 12, 200 );
		$this->queue_wp_query_result( array( 10, 12 ) );

		$report = ( new PublicOperatorAccessDisabler() )->run(
			array(
				'days'    => 90,
				'dry_run' => true,
			)
		);

		$this->assertTrue( $report['dry_run'] );
		$this->assertSame( 2, $report['candidates'] );
		$this->assertSame( 0, $report['disabled'] );
		$this->assertSame( array(), $this->meta_writes );
		$this->assertCount( 2, $report['affected'] );
		$this->assertSame( 'Form 10', $report['affected'][0]['title'] );
	}

	public function test_execute_disables_all_five_flags_per_form(): void {
		$this->seed_expired_form( 10, 120 );
		$this->queue_wp_query_result( array( 10 ) );

		$report = ( new PublicOperatorAccessDisabler() )->run(
			array(
				'days'    => 90,
				'dry_run' => false,
			)
		);

		$this->assertSame( 1, $report['disabled'] );
		// All five enable flags set to '0' for form 10.
		$this->assertCount( 5, $this->meta_writes );
		foreach ( $this->meta_writes as $write ) {
			$this->assertSame( 10, $write[0] );
			$this->assertSame( '0', $write[2] );
			$this->assertContains( $write[1], PublicOperatorAccessDisabler::ENABLE_FLAGS );
		}
		// Config meta must never be touched.
		$written_keys = array_column( $this->meta_writes, 1 );
		$this->assertNotContains( '_ffc_csv_public_hash', $written_keys );
		$this->assertNotContains( '_ffc_csv_public_cpf_mode', $written_keys );
	}

	public function test_disable_public_access_writes_exactly_the_enable_flags(): void {
		( new PublicOperatorAccessDisabler() )->disable_public_access( 42 );

		$written_keys = array_column( $this->meta_writes, 1 );
		$this->assertSame( PublicOperatorAccessDisabler::ENABLE_FLAGS, $written_keys );
	}

	public function test_no_candidates_returns_empty_report(): void {
		$this->seed_future_form( 11 );
		$this->queue_wp_query_result( array( 11 ) );

		$report = ( new PublicOperatorAccessDisabler() )->run( array( 'days' => 90 ) );

		$this->assertSame( 0, $report['candidates'] );
		$this->assertSame( array(), $report['affected'] );
		$this->assertFalse( $report['truncated'] );
	}
}
