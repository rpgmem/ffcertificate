<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\UrlShortener\UrlShortenerExportSource;

/**
 * Tests for UrlShortenerExportSource: the column layout + per-row formatting,
 * the creator-name cache, the filter/count/keyset-page delegation, and the
 * per-phase authorization gates. The job lifecycle it plugs into is tested in
 * BatchedCsvExportTest. Migrated from the former synchronous UrlShortenerCsvExporter
 * (issue #772).
 *
 * @covers \FreeFormCertificate\UrlShortener\UrlShortenerExportSource
 */
class UrlShortenerExportSourceTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var UrlShortenerExportSource */
	private $source;

	/** @var \Mockery\MockInterface */
	private $repository;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		class_exists( '\\FreeFormCertificate\UrlShortener\UrlShortenerExportSource' );

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();

		$this->repository = Mockery::mock( 'FreeFormCertificate\UrlShortener\UrlShortenerRepository' );
		$this->source     = new UrlShortenerExportSource( $this->repository );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		unset( $_POST['s'], $_POST['status'] );
		parent::tearDown();
	}

	/** Invoke a private/protected method on the source. */
	private function invoke( string $method, array $args = array() ) {
		$ref = new \ReflectionMethod( UrlShortenerExportSource::class, $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( $this->source, $args );
	}

	private function sample_row(): array {
		return array(
			'id'          => 7,
			'short_code'  => 'abc123',
			'title'       => 'My Link',
			'target_url'  => 'https://example.com/very/long',
			'click_count' => 42,
			'status'      => 'active',
			'post_id'     => 55,
			'created_by'  => 3,
			'created_at'  => '2026-01-15 10:30:00',
			'updated_at'  => '2026-01-16 11:00:00',
		);
	}

	// ==================================================================
	// type() / header()
	// ==================================================================

	public function test_type_is_url_shortener(): void {
		$this->assertSame( 'url_shortener', $this->source->type() );
	}

	public function test_header_has_ten_columns(): void {
		$header = $this->source->header( array(), array() );
		$this->assertCount( 10, $header );
		$this->assertSame( 'ID', $header[0] );
		$this->assertSame( 'Short Code', $header[1] );
		$this->assertSame( 'Updated At', $header[9] );
	}

	// ==================================================================
	// format_row()
	// ==================================================================

	public function test_format_row_maps_columns_in_order(): void {
		Functions\when( 'get_userdata' )->justReturn( (object) array( 'display_name' => 'Alice' ) );

		$result = $this->source->format_row( $this->sample_row(), array() );

		$this->assertSame( '7', $result[0] );
		$this->assertSame( 'abc123', $result[1] );
		$this->assertSame( 'My Link', $result[2] );
		$this->assertSame( 'https://example.com/very/long', $result[3] );
		$this->assertSame( '42', $result[4] );
		$this->assertSame( 'active', $result[5] );
		$this->assertSame( '55', $result[6] );
		$this->assertSame( 'Alice', $result[7] );
		$this->assertSame( '2026-01-15 10:30:00', $result[8] );
		$this->assertSame( '2026-01-16 11:00:00', $result[9] );
	}

	public function test_creator_name_deleted_user_shows_id(): void {
		Functions\when( 'get_userdata' )->justReturn( false );
		$this->assertSame( 'ID: 99', $this->invoke( 'creator_name', array( 99 ) ) );
	}

	public function test_creator_name_empty_for_zero(): void {
		$this->assertSame( '', $this->invoke( 'creator_name', array( 0 ) ) );
	}

	public function test_creator_name_is_cached(): void {
		$calls = 0;
		Functions\when( 'get_userdata' )->alias(
			static function () use ( &$calls ) {
				++$calls;
				return (object) array( 'display_name' => 'Bob' );
			}
		);
		$this->assertSame( 'Bob', $this->invoke( 'creator_name', array( 5 ) ) );
		$this->assertSame( 'Bob', $this->invoke( 'creator_name', array( 5 ) ) );
		$this->assertSame( 1, $calls, 'get_userdata called once for the same id' );
	}

	// ==================================================================
	// sanitize_filters() / count() / fetch_page() / cursor_of()
	// ==================================================================

	public function test_sanitize_filters_defaults_status_to_all(): void {
		Mockery::mock( 'alias:FreeFormCertificate\Core\RequestInput' )
			->shouldReceive( 'get_post_string' )->andReturnUsing(
				static function ( $key ) {
					return 's' === $key ? 'foo' : '';
				}
			);

		$filters = $this->source->sanitize_filters();
		$this->assertSame( 'foo', $filters['search'] );
		$this->assertSame( 'all', $filters['status'] );
	}

	public function test_count_delegates_to_repository(): void {
		$this->repository->shouldReceive( 'countForExport' )->once()
			->with( array( 'search' => 'x', 'status' => 'active' ) )->andReturn( 12 );
		$this->assertSame( 12, $this->source->count( array( 'search' => 'x', 'status' => 'active' ) ) );
	}

	public function test_fetch_page_delegates_keyset_to_repository(): void {
		$rows = array( array( 'id' => 5 ), array( 'id' => 4 ) );
		$this->repository->shouldReceive( 'findByCursor' )->once()
			->with( array( 'search' => '', 'status' => 'all' ), 10, 50 )->andReturn( $rows );

		$page = $this->source->fetch_page( array( 'search' => '', 'status' => 'all' ), array(), 10, 50 );
		$this->assertSame( $rows, $page );
	}

	public function test_cursor_of_reads_id(): void {
		$this->assertSame( 4, $this->source->cursor_of( array( 'id' => 4 ) ) );
		$this->assertSame( 0, $this->source->cursor_of( array() ) );
	}

	public function test_filename_is_dated(): void {
		Functions\when( 'gmdate' )->justReturn( '2026-01-15' );
		$this->assertSame( 'short-urls-2026-01-15.csv', $this->source->filename( array(), array() ) );
	}

	// ==================================================================
	// authorize_start() / authorize_batch() / authorize_download()
	// ==================================================================

	private function stub_terminators(): void {
		Functions\when( 'wp_send_json_error' )->alias(
			static function () {
				throw new \RuntimeException( 'json_error' );
			}
		);
		Functions\when( 'wp_die' )->alias(
			static function () {
				throw new \RuntimeException( 'wp_die' );
			}
		);
	}

	public function test_authorize_start_rejects_without_capability(): void {
		$this->stub_terminators();
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Mockery::mock( 'alias:FreeFormCertificate\Core\Capabilities' )
			->shouldReceive( 'current_user_can_admin_or' )->andReturn( false );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'json_error' );
		$this->source->authorize_start();
	}

	public function test_authorize_batch_rejects_on_user_mismatch(): void {
		$this->stub_terminators();
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Mockery::mock( 'alias:FreeFormCertificate\Core\Capabilities' )
			->shouldReceive( 'current_user_can_admin_or' )->andReturn( true );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'json_error' );
		$this->source->authorize_batch( array( 'user_id' => 99 ) );
	}

	public function test_authorize_download_rejects_on_bad_nonce(): void {
		$this->stub_terminators();
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		Mockery::mock( 'alias:FreeFormCertificate\Core\RequestInput' )
			->shouldReceive( 'get_get_string' )->andReturn( 'n' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'wp_die' );
		$this->source->authorize_download( array( 'user_id' => 1 ) );
	}

	public function test_authorize_download_rejects_on_user_mismatch(): void {
		$this->stub_terminators();
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Mockery::mock( 'alias:FreeFormCertificate\Core\RequestInput' )
			->shouldReceive( 'get_get_string' )->andReturn( 'n' );
		Mockery::mock( 'alias:FreeFormCertificate\Core\Capabilities' )
			->shouldReceive( 'current_user_can_admin_or' )->andReturn( true );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'wp_die' );
		$this->source->authorize_download( array( 'user_id' => 99 ) );
	}
}
