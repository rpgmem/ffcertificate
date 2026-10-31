<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Tests for UserContextTrait: resolve_user_context() and user_has_capability().
 *
 * The trait is used by REST controllers. We create a concrete test class that
 * uses the trait so we can invoke its private methods via Reflection.
 */
class UserContextTraitTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'absint' )->alias( function ( $v ) { return abs( (int) $v ); } );
		Functions\when( '__' )->returnArg();
		// The view-as gate asks whether the target account exists (#2246).
		Functions\when( 'get_userdata' )->alias( static fn( $id ) => 42 === (int) $id ? (object) array( 'ID' => 42 ) : false );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Create a concrete object that uses the trait.
	 */
	private function make_trait_user(): object {
		return new class {
			use \FreeFormCertificate\API\UserContextTrait;

			public function call_resolve( $request ): array {
				return $this->resolve_user_context( $request );
			}

			public function call_has_capability( string $cap, int $user_id, bool $is_view_as ): bool {
				return $this->user_has_capability( $cap, $user_id, $is_view_as );
			}
		};
	}

	private function mock_request( ?string $view_as_user_id = null ): \Mockery\MockInterface {
		$request = Mockery::mock( 'WP_REST_Request' );
		$request->shouldReceive( 'get_param' )->with( 'viewAsUserId' )->andReturn( $view_as_user_id );
		return $request;
	}

	// ==================================================================
	// resolve_user_context()
	// ==================================================================

	public function test_resolve_returns_current_user_when_no_view_as(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 10 );

		$obj     = $this->make_trait_user();
		$request = $this->mock_request( null );

		$ctx = $obj->call_resolve( $request );

		$this->assertSame( 10, $ctx['user_id'] );
		$this->assertFalse( $ctx['is_view_as'] );
	}

	public function test_resolve_activates_view_as_for_admin(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );

		$obj     = $this->make_trait_user();
		$request = $this->mock_request( '42' );

		$ctx = $obj->call_resolve( $request );

		$this->assertSame( 42, $ctx['user_id'] );
		$this->assertTrue( $ctx['is_view_as'] );
	}

	public function test_resolve_ignores_view_as_for_non_admin(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );

		$obj     = $this->make_trait_user();
		$request = $this->mock_request( '42' );

		$ctx = $obj->call_resolve( $request );

		$this->assertSame( 5, $ctx['user_id'] );
		$this->assertFalse( $ctx['is_view_as'] );
	}

	public function test_resolve_ignores_empty_view_as_param(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 7 );

		$obj     = $this->make_trait_user();
		$request = $this->mock_request( '' );

		$ctx = $obj->call_resolve( $request );

		$this->assertSame( 7, $ctx['user_id'] );
		$this->assertFalse( $ctx['is_view_as'] );
	}

	// ==================================================================
	// user_has_capability()
	// ==================================================================

	public function test_has_capability_in_view_as_checks_target_user(): void {
		Functions\when( 'user_can' )->alias( function ( $uid, $cap ) {
			return $uid === 42 && $cap === 'ffc_view_certificates';
		});

		$obj = $this->make_trait_user();

		$this->assertTrue( $obj->call_has_capability( 'ffc_view_certificates', 42, true ) );
		$this->assertFalse( $obj->call_has_capability( 'ffc_view_certificates', 99, true ) );
	}

	public function test_has_capability_normal_checks_current_user(): void {
		Functions\when( 'current_user_can' )->alias( function ( $cap ) {
			return $cap === 'manage_options';
		});

		$obj = $this->make_trait_user();

		$this->assertTrue( $obj->call_has_capability( 'ffc_some_cap', 5, false ) );
	}

	public function test_has_capability_normal_checks_specific_cap(): void {
		Functions\when( 'current_user_can' )->alias( function ( $cap ) {
			return $cap === 'ffc_view_certificates';
		});

		$obj = $this->make_trait_user();

		$this->assertTrue( $obj->call_has_capability( 'ffc_view_certificates', 5, false ) );
	}

	public function test_has_capability_normal_denies_without_cap(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$obj = $this->make_trait_user();

		$this->assertFalse( $obj->call_has_capability( 'ffc_view_certificates', 5, false ) );
	}

	// ==================================================================
	// rest_permission() — the page's gate on every route (#2246)
	// ==================================================================

	/**
	 * Who the current user is, for the view-as gate.
	 *
	 * @param array<int, string> $caps Capabilities the current user holds.
	 */
	private function as_user_with( array $caps ): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->alias( static fn( $cap ) => in_array( $cap, $caps, true ) );
	}

	public function test_permission_refuses_a_logged_out_caller(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( false );

		$this->assertFalse( $this->make_trait_user()->rest_permission( $this->mock_request( null ) ) );
	}

	public function test_permission_lets_a_signed_in_caller_read_their_own_data(): void {
		$this->as_user_with( array() );

		$this->assertTrue( $this->make_trait_user()->rest_permission( $this->mock_request( null ) ) );
		$this->assertTrue( $this->make_trait_user()->rest_permission( $this->mock_request( '' ) ) );
	}

	/**
	 * @dataProvider gate_cases
	 *
	 * @param array<int, string> $caps     Capabilities held.
	 * @param string             $target   Requested view-as id.
	 * @param bool               $expected Whether the gate opens.
	 */
	public function test_permission_and_context_answer_the_same_gate( array $caps, string $target, bool $expected ): void {
		$this->as_user_with( $caps );
		$obj = $this->make_trait_user();

		$permission = $obj->rest_permission( $this->mock_request( $target ) );
		$ctx        = $obj->call_resolve( $this->mock_request( $target ) );

		if ( $expected ) {
			$this->assertTrue( $permission );
			$this->assertTrue( $ctx['is_view_as'] );
			$this->assertSame( (int) $target, $ctx['user_id'] );
		} else {
			$this->assertInstanceOf( \WP_Error::class, $permission );
			$this->assertSame( 403, $permission->get_error_data()['status'] );
			$this->assertFalse( $ctx['is_view_as'], 'A refused target is never read.' );
			$this->assertSame( 1, $ctx['user_id'] );
		}
		// The page asks the same class, so it answers the same way.
		$this->assertSame( $expected, \FreeFormCertificate\Core\ViewAs::can_view( (int) $target ) );
	}

	/**
	 * @return array<string, array{0: array<int, string>, 1: string, 2: bool}>
	 */
	public static function gate_cases(): array {
		return array(
			'administrator who may edit the account' => array( array( 'manage_options', 'edit_user' ), '42', true ),
			'delegated view-as who may edit it'      => array( array( 'ffc_view_as_user', 'edit_user' ), '42', true ),
			'view-as without edit_user'              => array( array( 'ffc_view_as_user' ), '42', false ),
			'edit_user without view-as'              => array( array( 'edit_user' ), '42', false ),
			'an account that does not exist'         => array( array( 'manage_options', 'edit_user' ), '999', false ),
		);
	}
}
