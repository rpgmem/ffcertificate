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
 * Every path runs the real PdfGenerator and its HTML renderer, over a
 * submission handler double and stubbed post meta, so the whole class shares
 * the PHPUnit process (#2125).
 *
 * @covers \FreeFormCertificate\Frontend\Submission\PdfStage
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
		$this->stub_certificate_render();

		$handler = Mockery::mock( SubmissionHandler::class );
		$handler->shouldReceive( 'get_submission' )->once()->with( 123 )->andReturn(
			(object) array(
				'id'              => '123',
				'email'           => 'user@example.com',
				'auth_code'       => 'AUTH77',
				'data'            => json_encode( array( 'name' => 'Marina' ) ),
				'form_id'         => '10',
				'submission_date' => '1700000000',
			)
		);

		$ctx                = new SubmissionContext();
		$ctx->submission_id = 123;

		( new PdfStage( $handler ) )->apply( $ctx );

		$this->assertIsArray( $ctx->pdf_data );
		$this->assertSame( 123, $ctx->pdf_data['submission_id'] );
		$this->assertSame( 'AUTH77', $ctx->pdf_data['auth_code'] );
		$this->assertSame( 'Certificate Form', $ctx->pdf_data['form_title'] );
		$this->assertStringContainsString( 'AUTH77', $ctx->pdf_data['filename'] );
		$this->assertStringEndsWith( '.pdf', $ctx->pdf_data['filename'] );
		$this->assertStringContainsString( 'Marina', $ctx->pdf_data['html'], 'the submission is rendered into the certificate' );
	}

	/** Stub the WordPress functions the real generator reaches on a stored submission. */
	private function stub_certificate_render(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( '_x' )->returnArg();
		Functions\when( '_n' )->alias(
			static fn ( $single, $plural, $number ) => 1 === (int) $number ? $single : $plural
		);
		Functions\when( '_deprecated_function' )->justReturn( null );
		Functions\when( 'number_format_i18n' )->alias( static fn ( $n ) => (string) $n );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_kses' )->alias( static fn ( $v ) => (string) $v );
		Functions\when( 'wp_unslash' )->alias( static fn ( $v ) => is_string( $v ) ? stripslashes( $v ) : $v );
		Functions\when( 'apply_filters' )->alias( static fn ( $tag, $value = '' ) => $value );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'get_bloginfo' )->justReturn( 'Test Site' );
		Functions\when( 'get_home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'home_url' )->alias( static fn ( $path = '' ) => 'https://example.com' . $path );
		Functions\when( 'site_url' )->alias( static fn ( $p = '' ) => 'https://example.com/' . ltrim( (string) $p, '/' ) );
		Functions\when( 'untrailingslashit' )->alias( static fn ( $u ) => rtrim( (string) $u, '/' ) );
		Functions\when( 'trailingslashit' )->alias( static fn ( $u ) => rtrim( (string) $u, '/' ) . '/' );
		Functions\when( 'wp_parse_url' )->alias( static fn ( $url, $component = -1 ) => parse_url( $url, $component ) );
		Functions\when( 'wp_normalize_path' )->alias( static fn ( $p ) => str_replace( '\\', '/', (string) $p ) );
		Functions\when( 'get_template_directory' )->justReturn( '/tmp/theme' );
		Functions\when( 'get_stylesheet_directory' )->justReturn( '/tmp/theme' );
		Functions\when( 'sanitize_hex_color' )->justReturn( null );
		Functions\when( 'wp_date' )->alias( static fn ( $format, $ts = null ) => gmdate( $format, $ts ?? 0 ) );
		Functions\when( 'wp_timezone' )->alias( static fn () => new \DateTimeZone( 'UTC' ) );
		Functions\when( 'get_the_title' )->justReturn( 'Certificate Form' );
		Functions\when( 'get_post_meta' )->alias(
			static function ( $id, $key ) {
				if ( '_ffc_form_config' === $key ) {
					return array( 'pdf_layout' => '<p>{{name}} {{auth_code}}</p>' );
				}
				return '';
			}
		);
	}

	public function test_an_attempt_awaiting_grading_gets_no_pdf(): void {
		// The generator would start by reading the submission.
		$handler = Mockery::mock( SubmissionHandler::class );
		$handler->shouldReceive( 'get_submission' )->never();

		$ctx                   = new SubmissionContext();
		$ctx->submission_id    = 7;
		$ctx->awaiting_grading = true;

		( new PdfStage( $handler ) )->apply( $ctx );

		$this->assertNull( $ctx->pdf_data );
	}

	public function test_rejects_when_generator_returns_wp_error(): void {
		// The real generator refuses a submission that does not exist.
		Functions\when( '__' )->returnArg();
		$handler = Mockery::mock( SubmissionHandler::class );
		$handler->shouldReceive( 'get_submission' )->once()->with( 456 )->andReturn( null );

		$ctx                = new SubmissionContext();
		$ctx->submission_id = 456;

		try {
			( new PdfStage( $handler ) )->apply( $ctx );
			$this->fail( 'expected SubmissionRejected' );
		} catch ( SubmissionRejected $e ) {
			$payload = $e->get_payload();
			$this->assertSame( 'submission_not_found', $payload['code'] );
			$this->assertSame( 'Submission not found.', $payload['message'] );
		}

		// pdf_data stays untouched on the reject path.
		$this->assertNull( $ctx->pdf_data );
	}
}
