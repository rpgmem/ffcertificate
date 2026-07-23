<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Audience\AudienceBookingCsvExporter;
use FreeFormCertificate\Core\CsvStreamer;
use FreeFormCertificate\Core\CsvDownloadInterface;

/**
 * Tests for AudienceBookingCsvExporter: header shape, per-row formatting
 * (type/status/all-day labels, audience-name join, participant count, user-name
 * resolution) and the paged streaming path (captured through a buffered download
 * double). The static reader helpers are alias-mocked, so this test runs in a
 * separate process.
 *
 * @covers \FreeFormCertificate\Audience\AudienceBookingCsvExporter
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AudienceBookingCsvExporterTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var AudienceBookingCsvExporter */
	private $exporter;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		class_exists( '\FreeFormCertificate\Audience\AudienceBookingCsvExporter' );

		Functions\when( '__' )->returnArg();
		Functions\when( 'add_action' )->justReturn( true );

		$this->exporter = new AudienceBookingCsvExporter();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function format_row( array $row ): array {
		$ref = new \ReflectionMethod( AudienceBookingCsvExporter::class, 'format_row' );
		$ref->setAccessible( true );
		return $ref->invokeArgs( $this->exporter, array( $row ) );
	}

	private function base_row(): array {
		return array(
			'id'                  => 7,
			'environment_name'    => 'Sala A',
			'schedule_id'         => 2,
			'booking_date'        => '2026-05-20',
			'start_time'          => '09:00:00',
			'end_time'            => '10:00:00',
			'is_all_day'          => 0,
			'booking_type'        => 'audience',
			'description'         => 'Team meeting',
			'status'              => 'active',
			'created_by'          => 3,
			'created_at'          => '2026-05-01 08:00:00',
			'cancelled_by'        => 0,
			'cancelled_at'        => null,
			'cancellation_reason' => null,
		);
	}

	private function stub_reader( array $audiences, array $user_ids ): void {
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceBookingReader' )
			->shouldReceive( 'get_booking_audiences' )->andReturn( $audiences )
			->shouldReceive( 'get_booking_users' )->andReturn( $user_ids );
	}

	public function test_headers_shape(): void {
		$ref = new \ReflectionMethod( AudienceBookingCsvExporter::class, 'get_headers' );
		$ref->setAccessible( true );
		$headers = $ref->invoke( $this->exporter );
		$this->assertCount( 17, $headers );
		$this->assertSame( 'ID', $headers[0] );
		$this->assertSame( 'Participants', $headers[11] );
	}

	public function test_row_labels_and_relations(): void {
		Functions\when( 'get_userdata' )->justReturn( false );
		$this->stub_reader(
			array( (object) array( 'name' => 'Turma X' ), (object) array( 'name' => 'Turma Y' ) ),
			array( 1, 2, 3 )
		);

		$line = $this->format_row( $this->base_row() );

		$this->assertSame( '7', $line[0] );
		$this->assertSame( 'Sala A', $line[1] );
		$this->assertSame( '2', $line[2] );
		$this->assertSame( '2026-05-20', $line[3] );
		$this->assertSame( 'No', $line[6] );          // is_all_day 0
		$this->assertSame( 'Audience', $line[7] );    // booking_type label
		$this->assertSame( 'Active', $line[9] );      // status label
		$this->assertSame( 'Turma X, Turma Y', $line[10] );
		$this->assertSame( '3', $line[11] );          // participant count
	}

	public function test_all_day_and_individual_type(): void {
		Functions\when( 'get_userdata' )->justReturn( false );
		$this->stub_reader( array(), array() );

		$row                 = $this->base_row();
		$row['is_all_day']   = 1;
		$row['booking_type'] = 'individual';
		$line                = $this->format_row( $row );

		$this->assertSame( 'Yes', $line[6] );
		$this->assertSame( 'Individual', $line[7] );
		$this->assertSame( '', $line[10] );  // no audiences
		$this->assertSame( '0', $line[11] ); // no participants
	}

	public function test_creator_name_resolved_and_cancelled_blank(): void {
		$user               = new \WP_User( 3 );
		$user->display_name = 'Bruno Lima';
		Functions\when( 'get_userdata' )->alias( fn( $id ) => 3 === $id ? $user : false );
		$this->stub_reader( array(), array() );

		$line = $this->format_row( $this->base_row() );
		$this->assertSame( 'Bruno Lima', $line[12] ); // created_by
		$this->assertSame( '', $line[14] );           // cancelled_by 0
		$this->assertSame( '', $line[15] );           // cancelled_at null
		$this->assertSame( '', $line[16] );           // reason null
	}

	/**
	 * A CsvDownloadInterface that captures the export bytes instead of writing
	 * to php://output / calling exit.
	 */
	private function buffered_download(): CsvDownloadInterface {
		return new class() implements CsvDownloadInterface {
			public bool $finished = false;
			public string $output = '';
			/** @var resource|null */
			private $stream = null;

			public function send_headers( string $filename ): void {
				unset( $filename );
			}

			public function open_stream() {
				if ( ! is_resource( $this->stream ) ) {
					$this->stream = fopen( 'php://memory', 'w+' );
				}
				return $this->stream;
			}

			public function finish(): void {
				$this->finished = true;
				if ( is_resource( $this->stream ) ) {
					rewind( $this->stream );
					$this->output = (string) stream_get_contents( $this->stream );
				}
			}
		};
	}

	/**
	 * The formerly-untestable path: export_csv() pages the reader and streams
	 * header + every row. With the injected CsvStreamer we capture the output and
	 * assert both the header and rows from *both* pages are present.
	 */
	public function test_export_csv_streams_header_and_pages_all_rows(): void {
		Functions\when( 'get_userdata' )->justReturn( false );

		$download = $this->buffered_download();

		$row   = $this->base_row();
		$page1 = array_fill( 0, 500, (object) $row );
		$page2 = array( (object) ( array( 'id' => 8, 'environment_name' => 'Sala Z' ) + $row ) );

		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceBookingReader' )
			->shouldReceive( 'get_all' )
				->with( Mockery::on( fn( $a ) => 0 === $a['offset'] ) )->andReturn( $page1 )
			->shouldReceive( 'get_all' )
				->with( Mockery::on( fn( $a ) => 500 === $a['offset'] ) )->andReturn( $page2 )
			->shouldReceive( 'get_booking_audiences' )->andReturn( array() )
			->shouldReceive( 'get_booking_users' )->andReturn( array() );

		$exporter = new AudienceBookingCsvExporter( new CsvStreamer( $download ) );

		$ref = new \ReflectionMethod( AudienceBookingCsvExporter::class, 'export_csv' );
		$ref->setAccessible( true );
		$ref->invokeArgs( $exporter, array( array( 'orderby' => 'booking_date', 'order' => 'DESC' ) ) );

		$this->assertTrue( $download->finished, 'stream finished' );
		$this->assertStringContainsString( 'Environment', $download->output, 'header row present' );
		$this->assertStringContainsString( 'Sala A', $download->output, 'page-1 rows present' );
		$this->assertStringContainsString( 'Sala Z', $download->output, 'page-2 row present (paging worked)' );
	}
}
