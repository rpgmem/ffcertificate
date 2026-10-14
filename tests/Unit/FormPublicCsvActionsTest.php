<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\FormPublicCsvActions;

/**
 * The Public Operator Access actions (#1755): the Regenerate and Reset
 * buttons, the first-enable defaults and the share link.
 *
 * @covers \FreeFormCertificate\Admin\FormPublicCsvActions
 */
class FormPublicCsvActionsTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<int, array<string, mixed>> */
	private array $meta = array();

	/** @var array<int, array{ok: bool, data: mixed, status: int|null}> */
	private array $responses = array();

	/** @var array<string, mixed> */
	private array $settings = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'absint' )->alias( static fn( $v ) => (int) $v );
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_post_type' )->justReturn( 'ffc_form' );
		Functions\when( 'get_option' )->alias( fn() => $this->settings );

		Functions\when( 'get_post_meta' )->alias( fn( $id, $key ) => $this->meta[ (int) $id ][ $key ] ?? '' );
		Functions\when( 'update_post_meta' )->alias(
			function ( $id, $key, $value ) {
				$this->meta[ (int) $id ][ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'wp_send_json_success' )->alias(
			function ( $data = null ) {
				$this->responses[] = array( 'ok' => true, 'data' => $data, 'status' => null );
				throw new \RuntimeException( 'sent' );
			}
		);
		Functions\when( 'wp_send_json_error' )->alias(
			function ( $data = null, $status = null ) {
				$this->responses[] = array( 'ok' => false, 'data' => $data, 'status' => $status );
				throw new \RuntimeException( 'sent' );
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		$_POST = array();
		parent::tearDown();
	}

	private function dispatch( array $post ): void {
		$_POST = $post + array( 'nonce' => 'n' );
		try {
			FormPublicCsvActions::handle();
		} catch ( \RuntimeException $e ) {
			unset( $e ); // wp_send_json_* stubs throw to stop the handler.
		}
	}

	public function test_regenerate_replaces_the_hash_and_returns_the_new_link(): void {
		$this->meta[7]['_ffc_csv_public_hash'] = 'old';

		$this->dispatch( array( 'post_id' => 7, 'op' => 'regenerate' ) );

		$hash = $this->meta[7]['_ffc_csv_public_hash'];
		$this->assertNotSame( 'old', $hash );
		$this->assertSame( 32, strlen( $hash ) );
		$this->assertSame( '?form_id=7&hash=' . $hash, $this->responses[0]['data']['share_link'] );
	}

	public function test_reset_zeroes_the_counter(): void {
		$this->meta[7]['_ffc_csv_public_count'] = 9;

		$this->dispatch( array( 'post_id' => 7, 'op' => 'reset' ) );

		$this->assertSame( 0, $this->meta[7]['_ffc_csv_public_count'] );
		$this->assertSame( array( 'count' => 0 ), $this->responses[0]['data'] );
	}

	public function test_an_unknown_operation_writes_nothing(): void {
		$this->dispatch( array( 'post_id' => 7, 'op' => 'delete' ) );

		$this->assertSame( 400, $this->responses[0]['status'] );
		$this->assertSame( array(), $this->meta );
	}

	public function test_a_user_who_cannot_edit_the_form_is_refused(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->dispatch( array( 'post_id' => 7, 'op' => 'regenerate' ) );

		$this->assertSame( 403, $this->responses[0]['status'] );
		$this->assertSame( array(), $this->meta );
	}

	public function test_a_post_that_is_not_a_form_is_refused(): void {
		Functions\when( 'get_post_type' )->justReturn( 'page' );

		$this->dispatch( array( 'post_id' => 7, 'op' => 'reset' ) );

		$this->assertSame( 400, $this->responses[0]['status'] );
		$this->assertSame( array(), $this->meta );
	}

	public function test_share_link_uses_the_download_page_when_set(): void {
		$this->settings = array( 'csv_download_page_url' => 'https://example.com/csv/' );
		$this->assertSame( 'https://example.com/csv/?form_id=3&hash=h', FormPublicCsvActions::share_link( 3, 'h' ) );

		$this->settings = array( 'csv_download_page_url' => 'https://example.com/?page_id=9' );
		$this->assertSame( 'https://example.com/?page_id=9&form_id=3&hash=h', FormPublicCsvActions::share_link( 3, 'h' ) );

		$this->assertSame( '', FormPublicCsvActions::share_link( 3, '' ), 'No hash, no link.' );
	}

	public function test_cpf_mode_falls_back_to_audit_for_an_unset_or_unknown_value(): void {
		$this->assertSame( 'audit', FormPublicCsvActions::cpf_mode( 5 ) );
		$this->meta[5]['_ffc_csv_public_cpf_mode'] = 'bogus';
		$this->assertSame( 'audit', FormPublicCsvActions::cpf_mode( 5 ) );
		$this->meta[5]['_ffc_csv_public_cpf_mode'] = 'none';
		$this->assertSame( 'none', FormPublicCsvActions::cpf_mode( 5 ), 'An explicit none is kept.' );
	}

	public function test_on_enable_upgrades_none_to_audit(): void {
		$this->meta[5]['_ffc_csv_public_cpf_mode'] = 'none';

		$result = FormPublicCsvActions::on_enable( 5 );

		$this->assertSame( 'audit', $this->meta[5]['_ffc_csv_public_cpf_mode'] );
		$this->assertSame( 'audit', $result['cpf_mode'] );
	}

	public function test_init_registers_the_ajax_action(): void {
		Functions\expect( 'add_action' )->once()->with( 'wp_ajax_ffc_csv_public_action', array( FormPublicCsvActions::class, 'handle' ) );
		FormPublicCsvActions::init();
	}
}
