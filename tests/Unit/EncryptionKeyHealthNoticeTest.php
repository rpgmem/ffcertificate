<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\EncryptionKeyHealthNotice;
use FreeFormCertificate\Core\Encryption;

/**
 * Gating + dismiss tests for the #839 S7a encryption key-health notice.
 *
 * Encryption, Capabilities, AssetHelper and AdminNotice are the real classes.
 * The bootstrap's key constants include secrets shorter than the 32-character
 * floor, so the real key_health() reports an unsafe state and the real
 * key_health_report() carries the active key's fingerprint; the dismissal
 * signatures below are built from those. The one state the bootstrap cannot
 * produce, healthy keys, is driven in its own process (see that test).
 *
 * @covers \FreeFormCertificate\Admin\EncryptionKeyHealthNotice
 */
class EncryptionKeyHealthNoticeTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var list<string> Handles passed to wp_enqueue_script(). */
	private array $enqueued = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_kses' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( static function ( $t ) { echo $t; } );
		Functions\when( 'admin_url' )->returnArg();
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce' );
		Functions\when( 'get_option' )->alias( static fn( $key, $default = false ) => $default );

		$this->enqueued = array();
		Functions\when( 'wp_enqueue_script' )->alias(
			function ( $handle ) {
				$this->enqueued[] = (string) $handle;
			}
		);
		// wp_admin_notice() is WP 6.4 core — reproduce enough of its markup for
		// the render assertions (class list + message).
		Functions\when( 'wp_admin_notice' )->alias(
			static function ( $message, $args ) {
				$classes = 'notice notice-' . ( $args['type'] ?? 'info' )
					. ( ! empty( $args['dismissible'] ) ? ' is-dismissible' : '' )
					. ' ' . implode( ' ', $args['additional_classes'] ?? array() );
				echo '<div class="' . $classes . '">' . $message . '</div>';
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The signature the real Encryption reports for the bootstrap's keys.
	 *
	 * Asserts the precondition every render test rests on: those keys are
	 * not healthy, so the notice has something to warn about.
	 */
	private function current_signature(): string {
		$report = Encryption::key_health_report();
		$this->assertNotSame( 'ok', $report['status'], 'The bootstrap keys include secrets below the length floor.' );
		$this->assertSame( Encryption::key_health(), $report['status'] );
		return $report['status'] . ':' . $report['fingerprint'];
	}

	/**
	 * Store a dismissed signature for the notice to read.
	 *
	 * @param string $signature The stored signature.
	 */
	private function dismissed( string $signature ): void {
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) use ( $signature ) {
				return EncryptionKeyHealthNotice::OPTION_DISMISSED === $key ? $signature : $default;
			}
		);
	}

	private function rendered_output(): string {
		ob_start();
		EncryptionKeyHealthNotice::maybe_render();
		return (string) ob_get_clean();
	}

	/**
	 * Healthy keys need every active secret at 32 characters or more, and the
	 * bootstrap defines AUTH_KEY and NONCE_KEY shorter than that; only a
	 * define() could change them, so this test replaces Encryption in its own
	 * process instead.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_does_not_render_when_keys_ok(): void {
		Mockery::mock( 'alias:FreeFormCertificate\Core\Encryption' )
			->shouldReceive( 'key_health' )->andReturn( 'ok' )
			->shouldReceive( 'key_health_report' )->andReturn(
				array(
					'status'      => 'ok',
					'fingerprint' => 'abc123abc123',
				)
			);
		Functions\when( 'current_user_can' )->justReturn( true );

		$this->assertSame( '', $this->rendered_output() );
	}

	public function test_does_not_render_when_user_cannot_manage(): void {
		$this->current_signature();
		$caps = array();
		Functions\when( 'current_user_can' )->alias(
			static function ( $cap ) use ( &$caps ) {
				$caps[] = $cap;
				return false;
			}
		);

		$this->assertSame( '', $this->rendered_output() );
		$this->assertContains( 'ffc_manage_settings', $caps, 'The granular settings capability was asked too.' );
	}

	public function test_does_not_render_when_dismissed_for_current_signature(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		// Stored signature equals the current one → stay hidden.
		$this->dismissed( $this->current_signature() );

		$this->assertSame( '', $this->rendered_output() );
		$this->assertSame( array(), $this->enqueued );
	}

	public function test_renders_when_unsafe_and_not_dismissed(): void {
		$this->current_signature();
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( '' );

		$output = $this->rendered_output();

		$this->assertStringContainsString( 'ffc-encryption-key-health-notice', $output );
		$this->assertStringContainsString( 'ffc-js-dismiss-notice', $output );
		$this->assertStringContainsString( 'is-dismissible', $output );
		$this->assertSame( array( 'ffc-dismissible-notice' ), $this->enqueued );
	}

	public function test_renders_again_when_signature_changed_after_dismissal(): void {
		$current = $this->current_signature();
		list( $status, $fingerprint ) = explode( ':', $current, 2 );
		Functions\when( 'current_user_can' )->justReturn( true );

		// Dismissed under an earlier key (another fingerprint): the key has
		// since changed, so the notice must reappear.
		$this->dismissed( $status . ':oldfingerpr0' );
		$this->assertStringContainsString( 'ffc-encryption-key-health-notice', $this->rendered_output() );

		// Dismissed under another status for the same key: the state has
		// since changed, so the notice must reappear.
		$this->dismissed( ( 'empty' === $status ? 'weak' : 'empty' ) . ':' . $fingerprint );
		$this->assertStringContainsString( 'ffc-encryption-key-health-notice', $this->rendered_output() );
	}

	public function test_ajax_dismiss_stores_current_signature(): void {
		$expected = $this->current_signature();
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );

		$captured = array();
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$captured ) {
				$captured[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'wp_send_json_success' )->alias(
			static function () {
				throw new \RuntimeException( 'json_success' );
			}
		);

		try {
			EncryptionKeyHealthNotice::ajax_dismiss();
			$this->fail( 'expected halt' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'json_success', $e->getMessage() );
			$this->assertSame( $expected, $captured[ EncryptionKeyHealthNotice::OPTION_DISMISSED ] ?? null );
		}
	}

	// ------------------------------------------------------------------
	// Shared AbstractDismissibleNotice plumbing (#849), exercised through
	// this concrete subclass.
	// ------------------------------------------------------------------

	public function test_init_registers_admin_notice_and_ajax_hooks(): void {
		$hooks = array();
		Functions\when( 'add_action' )->alias(
			static function ( $hook ) use ( &$hooks ) {
				$hooks[] = $hook;
				return true;
			}
		);

		EncryptionKeyHealthNotice::init();

		$this->assertContains( 'admin_notices', $hooks );
		$this->assertContains( 'wp_ajax_' . EncryptionKeyHealthNotice::AJAX_ACTION, $hooks );
	}

	public function test_ajax_dismiss_forbidden_when_cannot_manage(): void {
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( false );

		$code = null;
		Functions\when( 'wp_send_json_error' )->alias(
			static function ( $payload, $status ) use ( &$code ) {
				$code = $status;
				throw new \RuntimeException( 'json_error' );
			}
		);

		try {
			EncryptionKeyHealthNotice::ajax_dismiss();
			$this->fail( 'expected halt' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 403, $code );
		}
	}
}
