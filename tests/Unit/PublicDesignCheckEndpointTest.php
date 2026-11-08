<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Admin\PublicDesignCheckEndpoint;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * The live contrast check of Settings → Public Design (#2341).
 *
 * `PublicDesign` and `Capabilities` are the real classes: the answer is the
 * warning the save would give, and the gate reads `current_user_can()`.
 *
 * @covers \FreeFormCertificate\Admin\PublicDesignCheckEndpoint
 */
class PublicDesignCheckEndpointTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<string, mixed>|null */
	private ?array $answer = null;

	private string $failure = '';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'number_format_i18n' )->alias( static fn( $n, $d = 0 ) => number_format( (float) $n, $d ) );
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_send_json_success' )->alias(
			function ( $data = null ) {
				$this->answer = $data;
				throw new \RuntimeException( 'sent' );
			}
		);
		Functions\when( 'wp_send_json_error' )->alias(
			function ( $data = null, $status = null ) {
				$this->failure = (string) $status;
				throw new \RuntimeException( 'sent' );
			}
		);
	}

	protected function tearDown(): void {
		unset( $_POST );
		$_POST = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	private function call(): void {
		try {
			PublicDesignCheckEndpoint::handle();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'sent', $e->getMessage() );
		}
	}

	public function test_it_answers_with_the_warning_the_posted_colours_earn(): void {
		$_POST = array(
			'accent' => '#2271b1',
			'border' => '#eeeeee',
			'muted'  => '#646970',
			'error'  => '#d63638',
			'info'   => '#006ba1',
		);
		$this->call();
		$this->assertStringContainsString( 'field borders on white', (string) $this->answer['warning'] );
	}

	public function test_the_defaults_and_bad_values_answer_with_no_warning(): void {
		$_POST = array( 'accent' => 'not-a-colour' );
		$this->call();
		$this->assertSame( '', $this->answer['warning'], 'A bad value falls back to its default, which passes.' );
	}

	public function test_a_caller_who_cannot_change_settings_is_refused(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->call();
		$this->assertSame( '403', $this->failure );
		$this->assertNull( $this->answer );
	}

	public function test_it_registers_on_its_own_ajax_action(): void {
		$hooks = array();
		Functions\when( 'add_action' )->alias(
			static function ( $hook ) use ( &$hooks ) {
				$hooks[] = $hook;
				return true;
			}
		);
		PublicDesignCheckEndpoint::init();
		$this->assertSame( array( 'wp_ajax_ffc_public_design_check' ), $hooks );
	}
}
