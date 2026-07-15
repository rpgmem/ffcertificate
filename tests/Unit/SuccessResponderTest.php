<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Frontend\Submission\SuccessResponder;
use FreeFormCertificate\Frontend\Submission\SubmissionContext;
use FreeFormCertificate\Submissions\SubmissionHandler;

/**
 * Coverage for SuccessResponder — builds the success message and final
 * response payload (message, pdf_data, html, optional quiz block) onto the
 * context. SuccessHtmlRenderer is alias-mocked so only the responder's own
 * message/branch logic is under test.
 *
 * @covers \FreeFormCertificate\Frontend\Submission\SuccessResponder
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class SuccessResponderTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		class_exists( '\FreeFormCertificate\Frontend\Submission\SuccessResponder' );

		Functions\when( '__' )->returnArg();
		// sprintf-style translator passthrough already handled by __ returnArg.

		// Static HTML renderer is mocked — return a sentinel regardless of args.
		Mockery::mock( 'alias:\FreeFormCertificate\Frontend\Submission\SuccessHtmlRenderer' )
			->shouldReceive( 'generate_success_html' )
			->andReturn( '<div class="ffc-success">OK</div>' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function responder(): SuccessResponder {
		return new SuccessResponder( Mockery::mock( SubmissionHandler::class ) );
	}

	public function test_default_success_message_when_no_custom(): void {
		$ctx              = new SubmissionContext();
		$ctx->form_config = array();

		$this->responder()->apply( $ctx );

		$this->assertSame( 'Success!', $ctx->response['message'] );
		$this->assertSame( '<div class="ffc-success">OK</div>', $ctx->response['html'] );
		$this->assertArrayNotHasKey( 'quiz', $ctx->response );
	}

	public function test_custom_success_message_used_and_trimmed(): void {
		$ctx              = new SubmissionContext();
		$ctx->form_config = array( 'success_message' => '  Well done!  ' );

		$this->responder()->apply( $ctx );

		$this->assertSame( 'Well done!', $ctx->response['message'] );
	}

	public function test_reprint_message_overrides_custom(): void {
		$ctx              = new SubmissionContext();
		$ctx->is_reprint  = true;
		$ctx->form_config = array( 'success_message' => 'ignored on reprint' );

		$this->responder()->apply( $ctx );

		$this->assertSame( 'Certificate previously issued (Reprint).', $ctx->response['message'] );
	}

	public function test_pdf_data_passed_through_to_response(): void {
		$ctx           = new SubmissionContext();
		$ctx->pdf_data = array( 'filename' => 'c.pdf' );

		$this->responder()->apply( $ctx );

		$this->assertSame( array( 'filename' => 'c.pdf' ), $ctx->response['pdf_data'] );
	}

	public function test_quiz_message_with_score_shown(): void {
		$ctx              = new SubmissionContext();
		$ctx->is_quiz     = true;
		$ctx->quiz_score  = array( 'score' => 8, 'max_score' => 10, 'percent' => 80 );
		$ctx->form_config = array( 'quiz_show_score' => '1' );

		$this->responder()->apply( $ctx );

		$this->assertStringContainsString( '80', $ctx->response['message'] );
		$this->assertTrue( $ctx->response['quiz']['passed'] );
		$this->assertSame( 8, $ctx->response['quiz']['score'] );
		$this->assertSame( 10, $ctx->response['quiz']['max_score'] );
		$this->assertSame( 80, $ctx->response['quiz']['percent'] );
	}

	public function test_quiz_message_without_score_shown_nulls_quiz_block(): void {
		$ctx              = new SubmissionContext();
		$ctx->is_quiz     = true;
		$ctx->quiz_score  = array( 'score' => 5, 'max_score' => 10, 'percent' => 50 );
		$ctx->form_config = array( 'quiz_show_score' => '0' );

		$this->responder()->apply( $ctx );

		$this->assertSame( 'Congratulations! Quiz passed. Certificate generated.', $ctx->response['message'] );
		$this->assertTrue( $ctx->response['quiz']['passed'] );
		$this->assertNull( $ctx->response['quiz']['score'] );
		$this->assertNull( $ctx->response['quiz']['max_score'] );
		$this->assertNull( $ctx->response['quiz']['percent'] );
	}

	public function test_quiz_reprint_keeps_reprint_message_but_emits_quiz_block(): void {
		// is_reprint short-circuits the quiz message, but the quiz block is
		// still attached because quiz_score is non-null.
		$ctx              = new SubmissionContext();
		$ctx->is_quiz     = true;
		$ctx->is_reprint  = true;
		$ctx->quiz_score  = array( 'score' => 9, 'max_score' => 10, 'percent' => 90 );
		$ctx->form_config = array( 'quiz_show_score' => '1' );

		$this->responder()->apply( $ctx );

		$this->assertSame( 'Certificate previously issued (Reprint).', $ctx->response['message'] );
		$this->assertSame( 9, $ctx->response['quiz']['score'] );
	}

	public function test_quiz_show_score_defaults_to_true_when_key_absent(): void {
		$ctx              = new SubmissionContext();
		$ctx->is_quiz     = true;
		$ctx->quiz_score  = array( 'score' => 7, 'max_score' => 10, 'percent' => 70 );
		$ctx->form_config = array(); // quiz_show_score missing → defaults to '1'.

		$this->responder()->apply( $ctx );

		$this->assertStringContainsString( '70', $ctx->response['message'] );
		$this->assertSame( 7, $ctx->response['quiz']['score'] );
	}
}
