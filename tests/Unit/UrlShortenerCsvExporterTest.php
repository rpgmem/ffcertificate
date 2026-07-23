<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\UrlShortener\UrlShortenerCsvExporter;

/**
 * Tests for UrlShortenerCsvExporter: header shape and per-row formatting
 * (creator-name resolution). The streaming export_csv() path (headers / exit)
 * is exercised manually; here we cover the pure row/header logic via Reflection.
 *
 * @covers \FreeFormCertificate\UrlShortener\UrlShortenerCsvExporter
 */
class UrlShortenerCsvExporterTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var UrlShortenerCsvExporter */
	private $exporter;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		class_exists( '\FreeFormCertificate\UrlShortener\UrlShortenerCsvExporter' );

		Functions\when( '__' )->returnArg();
		Functions\when( 'add_action' )->justReturn( true );

		$service        = Mockery::mock( 'FreeFormCertificate\UrlShortener\UrlShortenerService' );
		$this->exporter = new UrlShortenerCsvExporter( $service );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param array<string, mixed> $row Row.
	 * @return array<int, string>
	 */
	private function format_row( array $row ): array {
		$ref = new \ReflectionMethod( UrlShortenerCsvExporter::class, 'format_row' );
		$ref->setAccessible( true );
		return $ref->invokeArgs( $this->exporter, array( $row ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function base_row(): array {
		return array(
			'id'          => 5,
			'short_code'  => 'abc123',
			'title'       => 'My Link',
			'target_url'  => 'https://example.com/page',
			'click_count' => 42,
			'status'      => 'active',
			'post_id'     => 10,
			'created_by'  => 3,
			'created_at'  => '2026-01-01 10:00:00',
			'updated_at'  => '2026-01-02 11:00:00',
		);
	}

	public function test_headers_shape(): void {
		$ref = new \ReflectionMethod( UrlShortenerCsvExporter::class, 'get_headers' );
		$ref->setAccessible( true );
		$headers = $ref->invoke( $this->exporter );
		$this->assertCount( 10, $headers );
		$this->assertSame( 'ID', $headers[0] );
		$this->assertSame( 'Short Code', $headers[1] );
	}

	public function test_row_columns_map_in_order(): void {
		Functions\when( 'get_userdata' )->justReturn( false );

		$line = $this->format_row( $this->base_row() );
		$this->assertSame( '5', $line[0] );
		$this->assertSame( 'abc123', $line[1] );
		$this->assertSame( 'My Link', $line[2] );
		$this->assertSame( 'https://example.com/page', $line[3] );
		$this->assertSame( '42', $line[4] );
		$this->assertSame( 'active', $line[5] );
		$this->assertSame( '10', $line[6] );
		$this->assertSame( '2026-01-01 10:00:00', $line[8] );
		$this->assertSame( '2026-01-02 11:00:00', $line[9] );
	}

	public function test_creator_name_resolves_display_name(): void {
		$user               = new \WP_User( 3 );
		$user->display_name = 'Ana Souza';
		Functions\when( 'get_userdata' )->alias( fn( $id ) => 3 === $id ? $user : false );

		$line = $this->format_row( $this->base_row() );
		$this->assertSame( 'Ana Souza', $line[7] );
	}

	public function test_creator_name_deleted_user_shows_id(): void {
		Functions\when( 'get_userdata' )->justReturn( false );

		$row               = $this->base_row();
		$row['created_by'] = 99;
		$line              = $this->format_row( $row );
		$this->assertSame( 'ID: 99', $line[7] );
	}

	public function test_creator_name_empty_for_zero(): void {
		Functions\when( 'get_userdata' )->justReturn( false );

		$row               = $this->base_row();
		$row['created_by'] = 0;
		$line              = $this->format_row( $row );
		$this->assertSame( '', $line[7] );
	}
}
