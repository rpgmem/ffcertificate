<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentAdjutancyRepository;

/**
 * Tests for RecruitmentAdjutancyRepository — the slug-keyed catalog of adjutancies
 * (matérias) reused across notices.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentAdjutancyRepository
 */
class RecruitmentRecruitmentAdjutancyRepositoryTest extends TestCase {

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

	public function test_get_table_name_uses_plugin_prefix(): void {
		$this->assertSame( 'wp_ffc_recruitment_adjutancy', RecruitmentAdjutancyRepository::get_table_name() );
	}

	public function test_get_by_id_returns_null_when_no_row_found(): void {
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );

		$this->assertNull( RecruitmentAdjutancyRepository::get_by_id( 999 ) );
	}

	public function test_get_by_id_returns_typed_row_and_caches_it(): void {
		$row       = (object) array(
			'id'         => '5',
			'slug'       => 'matematica',
			'name'       => 'Matemática',
			'created_at' => '2026-05-01 10:00:00',
			'updated_at' => '2026-05-01 10:00:00',
		);
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $row );

		$cache_set_called = false;
		Functions\when( 'wp_cache_set' )->alias(
			function () use ( &$cache_set_called ) {
				$cache_set_called = true;
				return true;
			}
		);

		$result = RecruitmentAdjutancyRepository::get_by_id( 5 );

		$this->assertSame( $row, $result );
		$this->assertTrue( $cache_set_called, 'A successful lookup should populate the object cache' );
	}

	public function test_get_by_slug_returns_null_when_slug_unknown(): void {
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );

		$this->assertNull( RecruitmentAdjutancyRepository::get_by_slug( 'nao-existe' ) );
	}

	public function test_get_by_slug_returns_row(): void {
		$row = (object) array(
			'id'   => '7',
			'slug' => 'portugues',
			'name' => 'Português',
		);
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $row );

		$result = RecruitmentAdjutancyRepository::get_by_slug( 'portugues' );
		$this->assertSame( $row, $result );
	}

	public function test_create_returns_new_id_on_success(): void {
		$this->wpdb->shouldReceive( 'insert' )
			->once()
			->with(
				'wp_ffc_recruitment_adjutancy',
				Mockery::on(
					function ( $data ) {
						return 'matematica' === $data['slug']
							&& 'Matemática' === $data['name']
							&& '#e9ecef' === $data['color']
							&& '2026-05-01 10:00:00' === $data['created_at']
							&& '2026-05-01 10:00:00' === $data['updated_at'];
					}
				),
				array( '%s', '%s', '%s', '%s', '%s' )
			)
			->andReturn( 1 );

		$this->wpdb->insert_id = 42;

		$id = RecruitmentAdjutancyRepository::create( 'matematica', 'Matemática' );
		$this->assertSame( 42, $id );
	}

	public function test_create_returns_false_on_insert_failure(): void {
		$this->wpdb->shouldReceive( 'insert' )->once()->andReturn( false );

		$this->assertFalse( RecruitmentAdjutancyRepository::create( 'dup', 'Duplicate' ) );
	}

	public function test_update_only_writes_known_keys(): void {
		$captured_data = null;
		$this->wpdb->shouldReceive( 'update' )
			->once()
			->andReturnUsing(
				function ( $table, $data, $where ) use ( &$captured_data ) {
					$captured_data = $data;
					return 1;
				}
			);

		$result = RecruitmentAdjutancyRepository::update(
			3,
			array(
				'name'             => 'Novo Nome',
				'forbidden_field'  => 'should be ignored',
				'created_at'       => 'should be ignored',
			)
		);

		$this->assertTrue( $result );
		$this->assertArrayHasKey( 'name', $captured_data );
		$this->assertArrayHasKey( 'updated_at', $captured_data );
		$this->assertArrayNotHasKey( 'forbidden_field', $captured_data );
		$this->assertArrayNotHasKey( 'created_at', $captured_data );
	}

	public function test_update_returns_false_when_no_writable_fields_supplied(): void {
		$this->wpdb->shouldNotReceive( 'update' );

		$result = RecruitmentAdjutancyRepository::update( 3, array( 'forbidden' => 'x' ) );

		$this->assertFalse( $result );
	}

	public function test_delete_returns_true_on_successful_delete(): void {
		$this->wpdb->shouldReceive( 'delete' )
			->once()
			->with( 'wp_ffc_recruitment_adjutancy', array( 'id' => 9 ), array( '%d' ) )
			->andReturn( 1 );

		$this->assertTrue( RecruitmentAdjutancyRepository::delete( 9 ) );
	}
}
