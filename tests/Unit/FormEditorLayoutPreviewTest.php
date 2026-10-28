<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Admin\FormEditor;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * The form editor's layout preview request: what the handler sanitises and
 * hands to the renderer, and who may ask for it (#1958 follow-up).
 *
 * The samples and the page come from the real CertificatePreviewSamples and
 * FormLayoutPreview, so what reached the render is read off the page it
 * returns (#2119).
 *
 * @covers \FreeFormCertificate\Admin\FormEditor::ajax_preview_form_layout
 */
class FormEditorLayoutPreviewTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * JSON responses sent.
	 *
	 * @var array<int, array{type: string, data: mixed, status: mixed}>
	 */
	private array $responses = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$_POST           = array();
		$this->responses = array();

		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => trim( strip_tags( (string) $v ) ) );
		Functions\when( 'esc_url_raw' )->alias( static fn( $v ) => 0 === strpos( (string) $v, 'https://' ) ? (string) $v : '' );
		Functions\when( 'sanitize_hex_color' )->alias( static fn( $v ) => 1 === preg_match( '/^#[0-9a-f]{6}$/i', (string) $v ) ? (string) $v : null );
		Functions\when( 'wp_kses' )->alias( static fn( $html ) => str_replace( '<script>x</script>', '', (string) $html ) );
		Functions\when( 'check_ajax_referer' )->justReturn( 1 );

		$responses = &$this->responses;
		Functions\when( 'wp_send_json_success' )->alias(
			static function ( $data = null ) use ( &$responses ) {
				$responses[] = array( 'type' => 'success', 'data' => $data, 'status' => null );
				throw new \RuntimeException( 'json' );
			}
		);
		Functions\when( 'wp_send_json_error' )->alias(
			static function ( $data = null, $status = null ) use ( &$responses ) {
				$responses[] = array( 'type' => 'error', 'data' => $data, 'status' => $status );
				throw new \RuntimeException( 'json' );
			}
		);

		// CertificatePreviewSamples and FormLayoutPreview are the real
		// classes: what reaches the render is read off the page it returns.
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'wp_timezone' )->alias( static fn() => new \DateTimeZone( 'UTC' ) );
		Functions\when( 'wp_date' )->alias( static fn( $f, $ts = null ) => gmdate( $f, (int) $ts ) );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'get_bloginfo' )->justReturn( 'Example School' );
		Functions\when( 'home_url' )->justReturn( 'https://example.test' );
		Functions\when( 'site_url' )->justReturn( 'https://example.test' );
		Functions\when( 'get_home_url' )->justReturn( 'https://example.test' );
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'get_stylesheet_directory' )->justReturn( '/tmp/theme' );
		Functions\when( 'get_template_directory' )->justReturn( '/tmp/theme' );
		Functions\when( 'wp_normalize_path' )->returnArg();
	}

	protected function tearDown(): void {
		$_POST = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	private function call(): void {
		try {
			( new FormEditor() )->ajax_preview_form_layout();
			$this->fail( 'The handler did not answer.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'json', $e->getMessage() );
		}
	}

	public function test_an_operator_who_cannot_manage_forms_gets_a_403_and_no_render(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$_POST['html'] = '<p>{{name}}</p>';

		$this->call();

		$this->assertSame( 'error', $this->responses[0]['type'] );
		$this->assertSame( 403, $this->responses[0]['status'] );
	}

	public function test_the_unsaved_values_are_sanitised_before_the_render(): void {
		Functions\when( 'current_user_can' )->alias( static fn( $cap ) => 'ffc_manage_forms' === $cap );
		$_POST = array(
			'html'     => '<p>{{name}} {{school}} {{form_title}}</p><script>x</script>',
			'title'    => '<b>Course</b>',
			'bg_image' => 'javascript:alert(1)',
			'bg_color' => 'red; background:url(x)',
			'fields'   => array(
				array( 'name' => 'school', 'label' => 'School name' ),
				array( 'name' => '', 'label' => 'No name' ),
				'not-a-row',
			),
		);
		$this->call();

		$data = $this->responses[0]['data'];
		$this->assertSame( 1123, $data['width'] );
		$this->assertSame( 794, $data['height'] );
		$this->assertStringStartsWith( '<div style="position:relative;width:1123px;height:794px;background-color:#ffffff;">', $data['html'] );
		$this->assertStringContainsString( 'School name', $data['html'], 'a form field shows its label' );
		$this->assertStringContainsString( 'João da Silva', $data['html'], 'a system placeholder shows its sample' );
		$this->assertStringNotContainsString( '<script>', $data['html'] );
		$this->assertStringNotContainsString( 'javascript:', $data['html'] );
		$this->assertStringNotContainsString( 'No name', $data['html'] );
		$this->assertStringContainsString( 'Course', $data['html'], 'the title reaches the render' );
		$this->assertStringNotContainsString( '<b>', $data['html'], 'the title is sanitised' );
	}

	public function test_a_valid_colour_and_image_reach_the_render(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$_POST = array(
			'html'     => '<p>x</p>',
			'bg_image' => 'https://example.test/bg.png',
			'bg_color' => '#f3e9d2',
		);
		$this->call();

		$this->assertSame( 'success', $this->responses[0]['type'] );
		$html = $this->responses[0]['data']['html'];
		$this->assertStringContainsString( 'background-color:#f3e9d2;', $html );
		$this->assertStringContainsString( '<img src="https://example.test/bg.png"', $html );
	}
}
