<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Integrations\CepLookupEndpoint;
use PHPUnit\Framework\TestCase;

/**
 * The address lookup's two AJAX actions (#2234): the nonce, the toggle, the
 * per-caller cap, the capability on the test, and what the browser is told.
 *
 * The caller's address comes from `$_SERVER` through the real resolver, the
 * way `DeviceCheckPingTest` reads it.
 *
 * @covers \FreeFormCertificate\Integrations\CepLookupEndpoint
 * @covers \FreeFormCertificate\Integrations\CepLookup
 * @preserveGlobalState disabled
 */
class CepLookupEndpointTest extends TestCase {

	/** @var array<string, mixed> */
	private array $server_snapshot = array();

	/** @var array<string, mixed> */
	private array $post_snapshot = array();

	/** @var array<string, mixed> Transients by key. */
	private array $transients = array();

	/** @var list<array{0: bool, 1: mixed, 2: int|null}> success, data, status. */
	private array $responses = array();

	/** @var list<string> URLs asked. */
	private array $requests = array();

	/** @var array<string, mixed> The `ffc_settings` option. */
	private array $settings = array( 'cep_lookup_enabled' => true );

	/** @var array<string, bool> Capabilities the user holds. */
	private array $caps = array();

	/** @var bool Whether the nonce check passes. */
	private bool $nonce_ok = true;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->server_snapshot = $_SERVER;
		$this->post_snapshot   = $_POST;
		foreach ( array( 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED' ) as $header ) {
			unset( $_SERVER[ $header ] );
		}
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
		$_POST                  = array();

		$this->transients = array();
		$this->responses  = array();
		$this->requests   = array();
		$this->settings   = array( 'cep_lookup_enabled' => true );
		$this->caps       = array();
		$this->nonce_ok   = true;

		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ): string => trim( (string) $v ) );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( '__' )->returnArg();
		Functions\when( 'get_option' )->alias(
			fn( $key, $default = false ) => 'ffc_settings' === $key ? $this->settings : $default
		);
		Functions\when( 'get_transient' )->alias( fn( $key ) => $this->transients[ $key ] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value ): bool {
				$this->transients[ (string) $key ] = $value;
				return true;
			}
		);
		Functions\when( 'check_ajax_referer' )->alias(
			function (): bool {
				if ( ! $this->nonce_ok ) {
					throw new \RuntimeException( 'bad nonce' );
				}
				return true;
			}
		);
		Functions\when( 'current_user_can' )->alias( fn( $cap ): bool => ! empty( $this->caps[ $cap ] ) );
		Functions\when( 'wp_send_json_success' )->alias(
			function ( $data = null, $status = null ): void {
				$this->responses[] = array( true, $data, $status );
				throw new \RuntimeException( 'sent' );
			}
		);
		Functions\when( 'wp_send_json_error' )->alias(
			function ( $data = null, $status = null ): void {
				$this->responses[] = array( false, $data, $status );
				throw new \RuntimeException( 'sent' );
			}
		);
		Functions\when( 'wp_remote_get' )->alias(
			function ( $url ) {
				$this->requests[] = (string) $url;
				return array(
					'code' => 200,
					'body' => (string) json_encode(
						array(
							'logradouro' => 'Praça da Sé',
							'bairro'     => 'Sé',
							'localidade' => 'São Paulo',
							'uf'         => 'SP',
							'street'     => 'Praça da Sé',
							'city'       => 'São Paulo',
							'state'      => 'SP',
						)
					),
				);
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( $r ): int => (int) $r['code'] );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( $r ): string => (string) $r['body'] );
	}

	protected function tearDown(): void {
		$_SERVER = $this->server_snapshot;
		$_POST   = $this->post_snapshot;
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Run a handler and return what it sent.
	 *
	 * @param callable $handler Handler.
	 * @return array{0: bool, 1: mixed, 2: int|null}
	 */
	private function send( callable $handler ): array {
		try {
			$handler();
			$this->fail( 'The handler must answer.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'sent', $e->getMessage() );
		}
		return $this->responses[ count( $this->responses ) - 1 ];
	}

	public function test_init_registers_logged_in_actions_only_and_the_config_filter(): void {
		$actions = array();
		Functions\when( 'add_action' )->alias(
			function ( $hook ) use ( &$actions ): bool {
				$actions[] = (string) $hook;
				return true;
			}
		);
		$filters = array();
		Functions\when( 'add_filter' )->alias(
			function ( $hook ) use ( &$filters ): bool {
				$filters[] = (string) $hook;
				return true;
			}
		);

		CepLookupEndpoint::init();

		$this->assertSame( array( 'wp_ajax_ffc_cep_lookup', 'wp_ajax_ffc_cep_lookup_test' ), $actions );
		$this->assertSame( array( 'ffcertificate_cep_lookup_script_config' ), $filters );
	}

	public function test_a_lookup_answers_the_address(): void {
		$_POST['cep'] = '01001-000';

		[ $ok, $data ] = $this->send( array( CepLookupEndpoint::class, 'handle_lookup' ) );

		$this->assertTrue( $ok );
		$this->assertSame( 'found', $data['status'] );
		$this->assertSame( 'Praça da Sé', $data['address']['street'] );
	}

	public function test_a_bad_nonce_stops_before_anything(): void {
		$this->nonce_ok = false;
		$_POST['cep']   = '01001-000';

		$this->expectExceptionMessage( 'bad nonce' );
		try {
			CepLookupEndpoint::handle_lookup();
		} finally {
			$this->assertSame( array(), $this->requests );
		}
	}

	public function test_a_lookup_while_the_setting_is_off_is_refused(): void {
		$this->settings = array();
		$_POST['cep']   = '01001-000';

		[ $ok, $data, $status ] = $this->send( array( CepLookupEndpoint::class, 'handle_lookup' ) );

		$this->assertFalse( $ok );
		$this->assertSame( 403, $status );
		$this->assertSame( array(), $this->requests );
	}

	public function test_an_invalid_cep_answers_not_found_and_spends_no_quota(): void {
		$_POST['cep'] = '11111-111';

		[ $ok, $data ] = $this->send( array( CepLookupEndpoint::class, 'handle_lookup' ) );

		$this->assertTrue( $ok );
		$this->assertSame( 'not_found', $data['status'] );
		$this->assertSame( array(), $this->requests );
		$this->assertSame( array(), $this->transients, 'No throttle counter and no cache entry.' );
	}

	public function test_the_thirty_first_lookup_in_a_window_is_refused_with_retry_after(): void {
		for ( $i = 0; $i < 30; $i++ ) {
			$_POST['cep'] = sprintf( '010%02d-000', $i );
			[ $ok ] = $this->send( array( CepLookupEndpoint::class, 'handle_lookup' ) );
			$this->assertTrue( $ok, 'Lookup ' . ( $i + 1 ) . ' is allowed.' );
		}

		$_POST['cep']           = '01099-000';
		[ $ok, $data, $status ] = $this->send( array( CepLookupEndpoint::class, 'handle_lookup' ) );

		$this->assertFalse( $ok );
		$this->assertSame( 429, $status );
		$this->assertGreaterThan( 0, $data['retryAfter'] );
		$this->assertLessThanOrEqual( 600, $data['retryAfter'] );
	}

	public function test_the_test_needs_the_settings_capability(): void {
		$_POST['cep'] = '01001-000';

		[ $ok, $data, $status ] = $this->send( array( CepLookupEndpoint::class, 'handle_test' ) );

		$this->assertFalse( $ok );
		$this->assertSame( 403, $status );
		$this->assertSame( 'You do not have permission to do this.', $data['message'] );
		$this->assertSame( array(), $this->requests );
	}

	public function test_the_test_refuses_an_invalid_cep(): void {
		$this->caps['ffc_manage_settings'] = true;
		$_POST['cep']                      = '123';

		[ $ok, $data, $status ] = $this->send( array( CepLookupEndpoint::class, 'handle_test' ) );

		$this->assertFalse( $ok );
		$this->assertSame( 400, $status );
		$this->assertSame( 'Type a valid CEP to test.', $data['message'] );
	}

	public function test_the_test_asks_both_providers_without_the_cache(): void {
		$this->caps['manage_options'] = true;
		$_POST['cep']                 = '01001-000';
		$this->transients             = array( 'ffc_cep_01001000' => array( 'stale' => true ) );

		[ $ok, $data ] = $this->send( array( CepLookupEndpoint::class, 'handle_test' ) );

		$this->assertTrue( $ok );
		$this->assertSame( array( 'viacep', 'brasilapi' ), array_column( $data['results'], 'provider' ) );
		$this->assertCount( 2, $this->requests );
		$this->assertSame( array( 'stale' => true ), $this->transients['ffc_cep_01001000'], 'The test writes no cache.' );
	}

	public function test_script_config_is_null_when_off_or_logged_out(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		$this->settings = array();
		$this->assertNull( CepLookupEndpoint::script_config() );

		$this->settings = array( 'cep_lookup_enabled' => true );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		$this->assertNull( CepLookupEndpoint::script_config() );
	}

	public function test_script_config_carries_the_action_nonce_and_messages(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'admin_url' )->justReturn( 'https://example.test/wp-admin/admin-ajax.php' );
		Functions\when( 'wp_create_nonce' )->alias( static fn( $action ): string => 'nonce-' . $action );

		$config = CepLookupEndpoint::script_config();

		$this->assertIsArray( $config );
		$this->assertSame( 'ffc_cep_lookup', $config['action'] );
		$this->assertSame( 'nonce-ffc_cep_lookup', $config['nonce'] );
		$this->assertSame( array( 'searching', 'filled', 'cityOnly', 'notFound' ), array_keys( $config['strings'] ) );
	}
}
