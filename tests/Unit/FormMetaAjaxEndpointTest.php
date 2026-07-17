<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\FormMetaAjaxEndpoint;

/**
 * Unit tests for the per-form-meta autosave endpoint.
 *
 * Mirrors the SettingsAjaxEndpointTest structure: stub the WP request
 * helpers (`check_ajax_referer`, `current_user_can`, `wp_send_json_*`)
 * and exercise `handle()` end-to-end against an in-memory meta store.
 *
 * @covers \FreeFormCertificate\Admin\FormMetaAjaxEndpoint
 */
class FormMetaAjaxEndpointTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<string,mixed> */
	private array $meta_store = array();

	/** @var array<int,array{ok:bool, data:mixed, status:int|null}> */
	private array $responses = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'absint' )->alias( static fn( $v ) => (int) $v );
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_post_type' )->justReturn( 'ffc_form' );

		$this->meta_store = array();
		Functions\when( 'get_post_meta' )->alias( function ( $id, $key, $single = false ) {
			$bag = $this->meta_store[ (int) $id ] ?? array();
			return $bag[ $key ] ?? '';
		} );
		Functions\when( 'update_post_meta' )->alias( function ( $id, $key, $value ) {
			$this->meta_store[ (int) $id ][ $key ] = $value;
			return true;
		} );

		$this->responses = array();
		Functions\when( 'wp_send_json_success' )->alias( function ( $data = null ) {
			$this->responses[] = array(
				'ok'     => true,
				'data'   => $data,
				'status' => null,
			);
			throw new \RuntimeException( 'wp_send_json_success' );
		} );
		Functions\when( 'wp_send_json_error' )->alias( function ( $data = null, $status = null ) {
			$this->responses[] = array(
				'ok'     => false,
				'data'   => $data,
				'status' => $status,
			);
			throw new \RuntimeException( 'wp_send_json_error' );
		} );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		$_POST = array();
		parent::tearDown();
	}

	private function dispatch(): void {
		try {
			FormMetaAjaxEndpoint::handle();
		} catch ( \RuntimeException $e ) {
			// Swallowed — `wp_send_json_*` throws in the stub to short-circuit.
		}
	}

	public function test_flat_toggle_writes_meta(): void {
		$_POST = array(
			'post_id' => 42,
			'key'     => 'csv_public_start_early_enabled',
			'value'   => '1',
			'nonce'   => 'n',
		);

		$this->dispatch();

		$this->assertTrue( $this->responses[0]['ok'] );
		$this->assertSame( '1', $this->meta_store[42]['_ffc_csv_public_start_early_enabled'] );
	}

	public function test_nested_toggle_writes_into_array_meta(): void {
		$_POST = array(
			'post_id' => 42,
			'key'     => 'quiz_enabled',
			'value'   => '1',
			'nonce'   => 'n',
		);

		$this->dispatch();

		$this->assertTrue( $this->responses[0]['ok'] );
		$this->assertSame( '1', $this->meta_store[42]['_ffc_form_config']['quiz_enabled'] );
	}

	public function test_double_nested_toggle_writes_into_restrictions(): void {
		$_POST = array(
			'post_id' => 42,
			'key'     => 'restriction_allowlist',
			'value'   => '1',
			'nonce'   => 'n',
		);

		$this->dispatch();

		$this->assertTrue( $this->responses[0]['ok'] );
		$this->assertSame( '1', $this->meta_store[42]['_ffc_form_config']['restrictions']['allowlist'] );
	}

	public function test_writing_one_nested_key_preserves_existing_siblings(): void {
		$this->meta_store[42]['_ffc_form_config'] = array(
			'quiz_enabled' => '0',
			'pdf_layout'   => 'untouched',
			'restrictions' => array(
				'password' => '1',
				'denylist' => '1',
			),
		);
		$_POST = array(
			'post_id' => 42,
			'key'     => 'restriction_allowlist',
			'value'   => '1',
			'nonce'   => 'n',
		);

		$this->dispatch();

		$cfg = $this->meta_store[42]['_ffc_form_config'];
		$this->assertSame( '0', $cfg['quiz_enabled'] );
		$this->assertSame( 'untouched', $cfg['pdf_layout'] );
		$this->assertSame( '1', $cfg['restrictions']['password'] );
		$this->assertSame( '1', $cfg['restrictions']['denylist'] );
		$this->assertSame( '1', $cfg['restrictions']['allowlist'] );
	}

	public function test_off_value_writes_zero(): void {
		$_POST = array(
			'post_id' => 42,
			'key'     => 'send_user_email',
			'value'   => '0',
			'nonce'   => 'n',
		);

		$this->dispatch();

		$this->assertSame( '0', $this->meta_store[42]['_ffc_form_config']['send_user_email'] );
	}

	public function test_rejects_invalid_post_id(): void {
		$_POST = array(
			'post_id' => 0,
			'key'     => 'quiz_enabled',
			'value'   => '1',
			'nonce'   => 'n',
		);

		$this->dispatch();

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertSame( 400, $this->responses[0]['status'] );
	}

	public function test_rejects_non_ffc_form_post_type(): void {
		Functions\when( 'get_post_type' )->justReturn( 'page' );
		$_POST = array(
			'post_id' => 42,
			'key'     => 'quiz_enabled',
			'value'   => '1',
			'nonce'   => 'n',
		);

		$this->dispatch();

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertSame( 400, $this->responses[0]['status'] );
	}

	public function test_rejects_missing_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$_POST = array(
			'post_id' => 42,
			'key'     => 'quiz_enabled',
			'value'   => '1',
			'nonce'   => 'n',
		);

		$this->dispatch();

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertSame( 403, $this->responses[0]['status'] );
	}

	public function test_rejects_unknown_key(): void {
		$_POST = array(
			'post_id' => 42,
			'key'     => 'not_on_allowlist',
			'value'   => '1',
			'nonce'   => 'n',
		);

		$this->dispatch();

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertSame( 403, $this->responses[0]['status'] );
	}

	public function test_master_csv_public_enabled_NOT_on_allowlist(): void {
		// Explicit guard: the master `_ffc_csv_public_enabled` flips
		// were intentionally excluded from auto-save because enabling
		// the feature for the first time generates a hash and bumps
		// cpf_mode. Auto-saving the flag alone would leave the form
		// half-configured. Codified here so a future refactor cannot
		// silently re-enable it.
		$allowlist = FormMetaAjaxEndpoint::allowlist();
		$this->assertArrayNotHasKey( 'csv_public_enabled', $allowlist );
	}

	public function test_send_admin_email_autosaves(): void {
		$_POST = array(
			'post_id' => 42,
			'key'     => 'send_admin_email',
			'value'   => '1',
			'nonce'   => 'n',
		);

		$this->dispatch();

		$this->assertTrue( $this->responses[0]['ok'] );
		$this->assertSame( '1', $this->meta_store[42]['_ffc_form_config']['send_admin_email'] );
	}

	public function test_allowlist_covers_16_toggles(): void {
		$allowlist = FormMetaAjaxEndpoint::allowlist();
		$this->assertSame( 16, count( $allowlist ) );
		$this->assertArrayHasKey(
			'send_admin_email',
			$allowlist,
			'The admin-notification opt-in (#649) was wired with data-ffc-autosave-form-key but never allowlisted, so its autosave returned 403 until this fix.'
		);
		$this->assertArrayHasKey(
			'csv_public_download_enabled',
			$allowlist,
			'CSV Download sub-toggle joined the allowlist in the post-#241 Section 7 polish.'
		);
		$this->assertArrayHasKey(
			'csv_public_preview_enabled',
			$allowlist,
			'Certificate Preview sub-toggle joined the allowlist in #243 Sprint 5.'
		);
		$this->assertArrayHasKey(
			'geofence_schedule_exception_enabled',
			$allowlist,
			'Schedule Exception master toggle was wired with data-ffc-autosave-form-key in #366 but never allowlisted until the Sprint 3 fix.'
		);

		// Quick sanity-check that every entry has the required shape.
		foreach ( $allowlist as $key => $entry ) {
			$this->assertArrayHasKey( 'meta', $entry, "Entry '$key' missing 'meta'" );
			$this->assertArrayHasKey( 'path', $entry, "Entry '$key' missing 'path'" );
			$this->assertIsArray( $entry['path'] );
		}
	}
}
