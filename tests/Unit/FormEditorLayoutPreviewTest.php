<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Admin\FormEditor;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * The form editor's layout preview request: what the handler sanitises and
 * hands to the renderer, and who may ask for it (#1958 follow-up).
 *
 * @covers \FreeFormCertificate\Admin\FormEditor::ajax_preview_form_layout
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class FormEditorLayoutPreviewTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * JSON responses sent.
	 *
	 * @var array<int, array{type: string, data: mixed, status: mixed}>
	 */
	private array $responses = array();

	/** @var Mockery\MockInterface */
	private $preview;

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

		Mockery::mock( 'alias:FreeFormCertificate\Core\CertificatePreviewSamples' )
			->shouldReceive( 'get_map' )->andReturn( array( 'name' => 'Maria' ) );
		$this->preview = Mockery::mock( 'alias:FreeFormCertificate\Generators\FormLayoutPreview' );
		$this->preview->shouldReceive( 'page_size' )->andReturn( array( 'width' => 1123, 'height' => 794 ) );
		$this->preview->shouldReceive( 'with_field_labels' )->andReturnUsing(
			static function ( array $samples, array $fields ): array {
				foreach ( $fields as $field ) {
					$samples[ $field['name'] ] = $field['label'];
				}
				return $samples;
			}
		);
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
		$this->preview->shouldNotReceive( 'body' );
		$_POST['html'] = '<p>{{name}}</p>';

		$this->call();

		$this->assertSame( 'error', $this->responses[0]['type'] );
		$this->assertSame( 403, $this->responses[0]['status'] );
	}

	public function test_the_unsaved_values_are_sanitised_before_the_render(): void {
		Functions\when( 'current_user_can' )->alias( static fn( $cap ) => 'ffc_manage_forms' === $cap );
		$_POST = array(
			'html'     => '<p>{{name}} {{school}}</p><script>x</script>',
			'title'    => '<b>Course</b>',
			'bg_image' => 'javascript:alert(1)',
			'bg_color' => 'red; background:url(x)',
			'fields'   => array(
				array( 'name' => 'school', 'label' => 'School name' ),
				array( 'name' => '', 'label' => 'No name' ),
				'not-a-row',
			),
		);
		$this->preview->shouldReceive( 'body' )->once()->with(
			'<p>{{name}} {{school}}</p>',
			'Course',
			'',
			'#ffffff',
			array(
				'name'   => 'Maria',
				'school' => 'School name',
			)
		)->andReturn( '<div>page</div>' );

		$this->call();

		$this->assertSame(
			array(
				'html'   => '<div>page</div>',
				'width'  => 1123,
				'height' => 794,
			),
			$this->responses[0]['data']
		);
	}

	public function test_a_valid_colour_and_image_reach_the_render(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$_POST = array(
			'html'     => '<p>x</p>',
			'bg_image' => 'https://example.test/bg.png',
			'bg_color' => '#f3e9d2',
		);
		$this->preview->shouldReceive( 'body' )->once()->with( '<p>x</p>', '', 'https://example.test/bg.png', '#f3e9d2', array( 'name' => 'Maria' ) )->andReturn( '' );

		$this->call();

		$this->assertSame( 'success', $this->responses[0]['type'] );
	}
}
