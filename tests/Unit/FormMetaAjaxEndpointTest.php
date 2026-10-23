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

	/**
	 * #1745 — the quiz leave level is a radio group: its chosen member is
	 * stored as is, and a value outside the list is refused, not coerced.
	 */
	public function test_radio_group_stores_the_chosen_level(): void {
		$_POST = array(
			'post_id' => 42,
			'key'     => 'quiz_reset_on_leave',
			'value'   => '2',
			'nonce'   => 'n',
		);

		$this->dispatch();

		$this->assertTrue( $this->responses[0]['ok'] );
		$this->assertSame( '2', $this->meta_store[42]['_ffc_form_config']['quiz_reset_on_leave'] );
	}

	public function test_radio_group_refuses_a_value_outside_the_list(): void {
		$_POST = array(
			'post_id' => 42,
			'key'     => 'quiz_reset_on_leave',
			'value'   => '1; DROP',
			'nonce'   => 'n',
		);

		$this->dispatch();

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertSame( 400, $this->responses[0]['status'] );
		$this->assertArrayNotHasKey( 42, $this->meta_store, 'Nothing is written for a refused value.' );
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

	public function test_master_csv_public_enabled_first_enable_generates_hash_and_upgrades_cpf_mode(): void {
		// The master toggle autosaves since #1755, and its first enable does the
		// work the full save does: a hash, and `audit` in place of an unset mode.
		Functions\when( 'get_option' )->justReturn( array() );
		$_POST = array(
			'post_id' => 42,
			'key'     => 'csv_public_enabled',
			'value'   => '1',
			'nonce'   => 'n',
		);

		$this->dispatch();

		$this->assertTrue( $this->responses[0]['ok'] );
		$this->assertSame( '1', $this->meta_store[42]['_ffc_csv_public_enabled'] );
		$hash = $this->meta_store[42]['_ffc_csv_public_hash'] ?? '';
		$this->assertSame( 32, strlen( $hash ), 'A first enable generates the access hash.' );
		$this->assertSame( 'audit', $this->meta_store[42]['_ffc_csv_public_cpf_mode'] );
		$this->assertSame( '?form_id=42&hash=' . $hash, $this->responses[0]['data']['share_link'] );
		$this->assertSame( 'audit', $this->responses[0]['data']['cpf_mode'] );
	}

	public function test_master_csv_public_enabled_keeps_an_existing_hash_and_mode(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		$this->meta_store[42] = array(
			'_ffc_csv_public_enabled'  => '0',
			'_ffc_csv_public_hash'     => 'kept',
			'_ffc_csv_public_cpf_mode' => 'owner',
		);
		$_POST = array(
			'post_id' => 42,
			'key'     => 'csv_public_enabled',
			'value'   => '1',
			'nonce'   => 'n',
		);

		$this->dispatch();

		$this->assertSame( 'kept', $this->meta_store[42]['_ffc_csv_public_hash'] );
		$this->assertSame( 'owner', $this->meta_store[42]['_ffc_csv_public_cpf_mode'] );
	}

	public function test_master_csv_public_enabled_already_on_runs_nothing_more(): void {
		$this->meta_store[42] = array( '_ffc_csv_public_enabled' => '1' );
		$_POST = array(
			'post_id' => 42,
			'key'     => 'csv_public_enabled',
			'value'   => '1',
			'nonce'   => 'n',
		);

		$this->dispatch();

		$this->assertArrayNotHasKey( '_ffc_csv_public_hash', $this->meta_store[42], 'No transition, no side effect.' );
		$this->assertArrayNotHasKey( 'share_link', $this->responses[0]['data'] );
	}

	public function test_master_csv_public_disable_runs_no_side_effect(): void {
		$this->meta_store[42] = array( '_ffc_csv_public_enabled' => '1' );
		$_POST = array(
			'post_id' => 42,
			'key'     => 'csv_public_enabled',
			'value'   => '0',
			'nonce'   => 'n',
		);

		$this->dispatch();

		$this->assertSame( '0', $this->meta_store[42]['_ffc_csv_public_enabled'] );
		$this->assertArrayNotHasKey( '_ffc_csv_public_hash', $this->meta_store[42] );
	}

	public function test_map_toggle_stores_the_mapped_value(): void {
		$_POST = array(
			'post_id' => 42,
			'key'     => 'geofence_schedule_default_mode',
			'value'   => '0',
			'nonce'   => 'n',
		);
		$this->dispatch();
		$this->assertSame( 'manual', $this->meta_store[42]['_ffc_geofence_config']['schedule_default_mode'] );

		$this->responses = array();
		$_POST['value']  = '1';
		$this->dispatch();
		$this->assertSame( 'now', $this->meta_store[42]['_ffc_geofence_config']['schedule_default_mode'] );
	}

	public function test_cpf_mode_rejects_a_value_outside_the_list(): void {
		$_POST = array(
			'post_id' => 42,
			'key'     => 'csv_public_cpf_mode',
			'value'   => 'everyone',
			'nonce'   => 'n',
		);

		$this->dispatch();

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertSame( 400, $this->responses[0]['status'] );
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

	public function test_allowlist_covers_21_toggles_two_radio_groups_and_one_mapped_toggle(): void {
		$allowlist = FormMetaAjaxEndpoint::allowlist();
		$this->assertSame( 25, count( $allowlist ) );
		$this->assertSame( array( 'restrictions', 'invite' ), $allowlist['restriction_invite']['path'] ?? null, 'The invitation-only restriction (#1948) autosaves.' );
		$this->assertSame( 'map', $allowlist['geofence_schedule_default_mode']['type'] ?? null );
		$this->assertSame( 'enum', $allowlist['csv_public_cpf_mode']['type'] ?? null );
		foreach ( array( 'quiz_show_score', 'quiz_show_correct', 'geofence_geo_gps_enabled', 'geofence_geo_ip_enabled', 'csv_public_enabled' ) as $key ) {
			$this->assertArrayHasKey( $key, $allowlist, "#1755: '$key' autosaves." );
		}
		$this->assertSame( 'enum', $allowlist['quiz_reset_on_leave']['type'] ?? null, 'The quiz leave level (#1745) is a closed list, not a toggle.' );
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
