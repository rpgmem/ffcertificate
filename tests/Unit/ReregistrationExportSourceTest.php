<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Reregistration\ReregistrationExportSource;

/**
 * Tests for ReregistrationExportSource: the fixed + per-campaign-field column
 * layout, per-row formatting (field-type stringify, sensitive decrypt, instant
 * formatting), the campaign-field context resolution, the count/keyset-page
 * delegation, and the per-phase authorization gates. The job lifecycle it plugs
 * into is tested in BatchedCsvExportTest. Migrated from the former synchronous
 * ReregistrationCsvExporter (issue #772).
 *
 * Process isolation is used so the count()/build_context()/fetch_page()
 * delegation tests can alias-mock the static readers without leaking.
 *
 * @covers \FreeFormCertificate\Reregistration\ReregistrationExportSource
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class ReregistrationExportSourceTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var ReregistrationExportSource */
	private $source;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		class_exists( '\\FreeFormCertificate\\Reregistration\\ReregistrationExportSource' );

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_file_name' )->alias( static fn( $v ) => str_replace( ' ', '-', (string) $v ) );
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );

		$this->source = new ReregistrationExportSource();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		unset( $_POST['id'] );
		parent::tearDown();
	}

	private function field( string $key, string $label, string $type = 'text', int $sensitive = 0 ): object {
		return (object) array(
			'id'           => crc32( $key ),
			'field_key'    => $key,
			'field_label'  => $label,
			'field_type'   => $type,
			'is_sensitive' => $sensitive,
		);
	}

	private function sample_row(): array {
		return array(
			'id'           => 7,
			'user_id'      => 10,
			'user_name'    => 'Jane Doe',
			'user_email'   => 'jane@example.com',
			'status'       => 'submitted',
			'submitted_at' => '',
			'reviewed_at'  => '',
			'data'         => '{"fields":{"ramal":"42","setor":"TI"}}',
		);
	}

	// ==================================================================
	// type() / header()
	// ==================================================================

	public function test_type_is_reregistration(): void {
		$this->assertSame( 'reregistration', $this->source->type() );
	}

	public function test_header_has_six_fixed_plus_field_labels(): void {
		$context = array( 'fields' => array( $this->field( 'ramal', 'Ramal' ), $this->field( 'setor', 'Setor' ) ) );
		$header  = $this->source->header( array(), $context );

		$this->assertCount( 8, $header );
		$this->assertSame( 'User ID', $header[0] );
		$this->assertSame( 'Reviewed At', $header[5] );
		$this->assertSame( 'Ramal', $header[6] );
		$this->assertSame( 'Setor', $header[7] );
	}

	// ==================================================================
	// format_row()
	// ==================================================================

	public function test_format_row_layout_and_dynamic_values(): void {
		$context = array( 'fields' => array( $this->field( 'ramal', 'Ramal' ), $this->field( 'setor', 'Setor' ) ) );
		$result  = $this->source->format_row( $this->sample_row(), $context );

		$this->assertCount( 8, $result );
		$this->assertSame( 10, $result[0] );
		$this->assertSame( 'Jane Doe', $result[1] );
		$this->assertSame( 'jane@example.com', $result[2] );
		$this->assertSame( 'submitted', $result[3] );
		$this->assertSame( '', $result[4] );
		$this->assertSame( '', $result[5] );
		$this->assertSame( '42', $result[6] );
		$this->assertSame( 'TI', $result[7] );
	}

	public function test_format_row_checkbox_and_dependent_select(): void {
		$context = array(
			'fields' => array(
				$this->field( 'agree', 'Agree', 'checkbox' ),
				$this->field( 'unit', 'Unit', 'dependent_select' ),
			),
		);
		$row         = $this->sample_row();
		$row['data'] = '{"fields":{"agree":"1","unit":{"parent":"North","child":"HQ"}}}';

		$result = $this->source->format_row( $row, $context );
		$this->assertSame( 'Yes', $result[6] );
		$this->assertSame( 'North / HQ', $result[7] );
	}

	public function test_format_row_decrypts_sensitive(): void {
		Mockery::mock( 'alias:\FreeFormCertificate\Core\Encryption' )
			->shouldReceive( 'decrypt' )->with( 'CIPHER' )->andReturn( '123.456.789-00' );

		$context = array( 'fields' => array( $this->field( 'cpf', 'CPF', 'text', 1 ) ) );
		$row         = $this->sample_row();
		$row['data'] = '{"fields":{"cpf":"CIPHER"}}';

		$result = $this->source->format_row( $row, $context );
		$this->assertSame( '123.456.789-00', $result[6] );
	}

	public function test_format_row_formats_instant_when_present(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_date' )->alias( static fn( $format, $ts = null ) => gmdate( (string) $format, $ts ) );
		Functions\when( 'wp_timezone' )->alias( static fn() => new \DateTimeZone( 'UTC' ) );

		$row                 = $this->sample_row();
		$row['submitted_at'] = 1747731600;
		$result              = $this->source->format_row( $row, array( 'fields' => array() ) );

		$this->assertNotSame( '', $result[4] );
	}

	// ==================================================================
	// sanitize_filters() / count() / build_context() / fetch_page()
	// ==================================================================

	public function test_sanitize_filters_reads_id(): void {
		$_POST['id'] = '5';
		$this->assertSame( array( 'reregistration_id' => 5 ), $this->source->sanitize_filters() );
	}

	public function test_count_delegates(): void {
		Mockery::mock( 'alias:\FreeFormCertificate\Reregistration\ReregistrationSubmissionReader' )
			->shouldReceive( 'count_by_reregistration' )->once()->with( 5 )->andReturn( 12 );

		$this->assertSame( 12, $this->source->count( array( 'reregistration_id' => 5 ) ) );
	}

	public function test_build_context_resolves_fields_and_title(): void {
		Mockery::mock( 'alias:\FreeFormCertificate\Reregistration\ReregistrationRepository' )
			->shouldReceive( 'get_by_id' )->with( 5 )->andReturn( (object) array( 'id' => 5, 'title' => 'Campaign X' ) )
			->shouldReceive( 'get_audience_ids' )->with( 5 )->andReturn( array( 1 ) );
		Mockery::mock( 'alias:\FreeFormCertificate\Reregistration\CustomFieldReader' )
			->shouldReceive( 'get_by_audience_with_parents' )->with( 1, true )
			->andReturn( array( $this->field( 'ramal', 'Ramal' ) ) );

		$context = $this->source->build_context( array( 'reregistration_id' => 5 ) );
		$this->assertSame( 'Campaign X', $context['title'] );
		$this->assertCount( 1, $context['fields'] );
	}

	public function test_build_context_empty_for_missing_campaign(): void {
		Mockery::mock( 'alias:\FreeFormCertificate\Reregistration\ReregistrationRepository' )
			->shouldReceive( 'get_by_id' )->with( 99 )->andReturn( null );

		$context = $this->source->build_context( array( 'reregistration_id' => 99 ) );
		$this->assertSame( array(), $context['fields'] );
		$this->assertSame( '', $context['title'] );
	}

	public function test_fetch_page_casts_reader_objects_to_arrays(): void {
		Mockery::mock( 'alias:\FreeFormCertificate\Reregistration\ReregistrationSubmissionReader' )
			->shouldReceive( 'find_by_cursor_for_export' )->once()->with( 5, 10, 50 )
			->andReturn( array( (object) array( 'id' => 5 ), (object) array( 'id' => 4 ) ) );

		$page = $this->source->fetch_page( array( 'reregistration_id' => 5 ), array(), 10, 50 );
		$this->assertSame( array( array( 'id' => 5 ), array( 'id' => 4 ) ), $page );
	}

	public function test_cursor_of_reads_id(): void {
		$this->assertSame( 4, $this->source->cursor_of( array( 'id' => 4 ) ) );
		$this->assertSame( 0, $this->source->cursor_of( array() ) );
	}

	public function test_filename_is_dated(): void {
		$this->assertMatchesRegularExpression(
			'/^reregistration-.*\d{4}-\d{2}-\d{2}\.csv$/',
			$this->source->filename( array(), array( 'title' => 'My Campaign' ) )
		);
	}

	// ==================================================================
	// authorize_*()
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
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'json_error' );
		$this->source->authorize_start();
	}

	public function test_authorize_batch_rejects_on_user_mismatch(): void {
		$this->stub_terminators();
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'json_error' );
		$this->source->authorize_batch( array( 'user_id' => 99 ) );
	}

	public function test_authorize_download_rejects_on_bad_nonce(): void {
		$this->stub_terminators();
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'wp_die' );
		$this->source->authorize_download( array( 'user_id' => 1 ) );
	}

	public function test_job_owner_fields_returns_user_id(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 42 );
		$this->assertSame( array( 'user_id' => 42 ), $this->source->job_owner_fields() );
	}
}
