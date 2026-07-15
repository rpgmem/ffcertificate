<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Frontend\Submission\PdfStage;
use FreeFormCertificate\Frontend\Submission\SubmissionContext;
use FreeFormCertificate\Frontend\Submission\SubmissionRejected;
use FreeFormCertificate\Submissions\SubmissionHandler;

/**
 * Coverage for PdfStage — generates the certificate PDF payload onto the
 * submission context, rejecting on a WP_Error from the generator.
 *
 * @covers \FreeFormCertificate\Frontend\Submission\PdfStage
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class PdfStageTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		class_exists( '\FreeFormCertificate\Frontend\Submission\PdfStage' );

		Functions\when( 'is_wp_error' )->alias(
			static fn( $thing ) => $thing instanceof \WP_Error
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_stores_pdf_data_on_success(): void {
		$pdf = Mockery::mock( 'overload:\FreeFormCertificate\Generators\PdfGenerator' );
		$pdf->shouldReceive( 'generate_pdf_data' )
			->once()
			->with( 123, Mockery::type( SubmissionHandler::class ) )
			->andReturn( array( 'filename' => 'cert.pdf', 'content' => 'BASE64' ) );

		$handler = Mockery::mock( SubmissionHandler::class );

		$ctx                = new SubmissionContext();
		$ctx->submission_id = 123;

		( new PdfStage( $handler ) )->apply( $ctx );

		$this->assertSame( 'cert.pdf', $ctx->pdf_data['filename'] );
		$this->assertSame( 'BASE64', $ctx->pdf_data['content'] );
	}

	public function test_rejects_when_generator_returns_wp_error(): void {
		$error = new \WP_Error( 'pdf_failed', 'Could not render PDF.' );

		$pdf = Mockery::mock( 'overload:\FreeFormCertificate\Generators\PdfGenerator' );
		$pdf->shouldReceive( 'generate_pdf_data' )->once()->andReturn( $error );

		$handler = Mockery::mock( SubmissionHandler::class );

		$ctx                = new SubmissionContext();
		$ctx->submission_id = 456;

		try {
			( new PdfStage( $handler ) )->apply( $ctx );
			$this->fail( 'expected SubmissionRejected' );
		} catch ( SubmissionRejected $e ) {
			$payload = $e->get_payload();
			$this->assertSame( 'pdf_failed', $payload['code'] );
			$this->assertSame( 'Could not render PDF.', $payload['message'] );
		}

		// pdf_data stays untouched on the reject path.
		$this->assertNull( $ctx->pdf_data );
	}
}
