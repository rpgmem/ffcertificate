<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\FormEditorQuizMetabox;

/**
 * @covers \FreeFormCertificate\Admin\FormEditorQuizMetabox
 */
class FormEditorQuizMetaboxTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private FormEditorQuizMetabox $metabox;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( function ( $text ) { echo $text; } );
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'checked' )->justReturn( '' );

		$this->metabox = new FormEditorQuizMetabox();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function render( $config = array() ): string {
		Functions\when( 'get_post_meta' )->justReturn( $config );
		$post     = Mockery::mock( 'WP_Post' );
		$post->ID = 44;
		ob_start();
		$this->metabox->render( $post );
		return (string) ob_get_clean();
	}

	public function test_render_emits_master_quiz_toggle_with_default_off(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'Enable Quiz Mode', $html );
		$this->assertStringContainsString( 'ffc_quiz_enabled', $html );
		// Defaults: quiz off → settings rows carry the ffc-hidden class.
		$this->assertStringContainsString( 'ffc-quiz-setting ffc-hidden', $html );
	}

	public function test_render_offers_the_leave_the_window_levels(): void {
		$off    = $this->render( array( 'quiz_enabled' => '1' ) );
		$legacy = $this->render( array( 'quiz_enabled' => '1', 'quiz_reset_on_leave' => '1' ) );
		$strict = $this->render( array( 'quiz_enabled' => '1', 'quiz_reset_on_leave' => '2' ) );
		$junk   = $this->render( array( 'quiz_enabled' => '1', 'quiz_reset_on_leave' => 'on' ) );

		$this->assertSame( 3, substr_count( $off, 'name="ffc_config[quiz_reset_on_leave]"' ), 'Off, Moderate and Strict are three radios of one field (#1745).' );
		$this->assertStringContainsString( 'class="ffc-control-steps ffc-control-steps-severity"', $off );
		$this->assertStringContainsString( 'data-ffc-autosave-form-key="quiz_reset_on_leave"', $off );

		$this->assertMatchesRegularExpression( '/value="0" checked/', $off );
		$this->assertMatchesRegularExpression( '/value="1" checked/', $legacy, 'A form saved with the old toggle on reads as Moderate, its behaviour then.' );
		$this->assertMatchesRegularExpression( '/value="2" checked/', $strict );
		$this->assertMatchesRegularExpression( '/value="0" checked/', $junk, 'A value that is not a level reads as Off.' );
	}

	public function test_render_uncollapses_quiz_settings_when_enabled(): void {
		$html = $this->render( array( 'quiz_enabled' => '1' ) );

		// Settings rows lose the ffc-hidden suffix when quiz is on.
		$this->assertStringNotContainsString( 'ffc-quiz-setting ffc-hidden', $html );
		$this->assertStringContainsString( 'ffc-quiz-setting', $html );
	}

	public function test_render_pre_populates_passing_score_and_max_attempts(): void {
		$html = $this->render(
			array(
				'quiz_enabled'        => '1',
				'quiz_passing_score'  => '80',
				'quiz_max_attempts'   => '3',
			)
		);

		$this->assertStringContainsString( 'value="80"', $html );
		$this->assertStringContainsString( 'value="3"', $html );
		$this->assertStringContainsString( 'ffc_config[quiz_passing_score]', $html );
		$this->assertStringContainsString( 'ffc_config[quiz_max_attempts]', $html );
	}

	public function test_render_falls_back_to_defaults_when_config_not_array(): void {
		// get_post_meta returns the empty string for missing/non-array values
		// — the renderer must coerce that to a defaulted array without errors.
		$html = $this->render( '' );

		$this->assertStringContainsString( 'value="70"', $html ); // default passing score
		$this->assertStringContainsString( 'value="0"', $html );  // default max attempts
	}
}
