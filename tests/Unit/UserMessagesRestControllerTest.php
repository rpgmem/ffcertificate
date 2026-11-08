<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\API\UserMessagesRestController;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * GET /user/messages (#2503): the route, the module gate, and the payload
 * over a `$wpdb` double with no deliveries.
 *
 * @covers \FreeFormCertificate\API\UserMessagesRestController
 */
class UserMessagesRestControllerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<string, mixed> The ffc_settings option. */
	private array $settings = array();

	/** @var array<string, array<string, mixed>> Routes registered. */
	private array $routes = array();

	/** @var list<int> Ids opted out of announcements. */
	private array $opted_out_announcements = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->settings = array();
		$this->routes   = array();
		Functions\when( '__' )->returnArg();
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'rest_ensure_response' )->returnArg();
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'get_option' )->alias( fn( $key, $default = false ) => 'ffc_settings' === $key ? $this->settings : $default );
		Functions\when( 'register_rest_route' )->alias(
			function ( $ns, $route, $args ) {
				$this->routes[ $route ] = $args;
				return true;
			}
		);

		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn( ...$args ) => $args );
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $q ) {
				if ( is_array( $q ) && false !== strpos( (string) $q[0], 'preferences' ) ) {
					return array_map(
						static fn( $id ) => array(
							'user_id'     => $id,
							'preferences' => '{"notify_date_announcements":false}',
						),
						$this->opted_out_announcements
					);
				}
				// The delivery log: nothing was sent.
				return array();
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function request(): \WP_REST_Request {
		$request = Mockery::mock( 'WP_REST_Request' );
		$request->shouldReceive( 'get_param' )->andReturn( null );
		return $request;
	}

	public function test_the_route_is_read_only_and_behind_the_dashboard_gate(): void {
		$controller = new UserMessagesRestController( 'ffc/v1' );
		$controller->register_routes();

		$this->assertArrayHasKey( '/user/messages', $this->routes );
		$this->assertSame( \WP_REST_Server::READABLE, $this->routes['/user/messages']['methods'] );
		$this->assertSame( array( $controller, 'rest_permission' ), $this->routes['/user/messages']['permission_callback'] );
	}

	public function test_a_disabled_module_answers_not_found(): void {
		$this->settings = array( 'module_date_messages_enabled' => 0 );

		$result = ( new UserMessagesRestController( 'ffc/v1' ) )->get_user_messages( $this->request() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_the_payload_carries_both_groups_the_window_and_the_preferences(): void {
		$this->opted_out_announcements = array( 5 );

		$result = ( new UserMessagesRestController( 'ffc/v1' ) )->get_user_messages( $this->request() );

		$this->assertSame(
			array(
				'current'     => array(),
				'earlier'     => array(),
				'noticeDays'  => 7,
				'preferences' => array(
					'notify_date_messages'      => true,
					'notify_date_announcements' => false,
				),
			),
			$result
		);
	}
}
