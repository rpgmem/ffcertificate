<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\SelfScheduling\AppointmentReceiptHandler;

/**
 * Tests for AppointmentReceiptHandler (#2732): the legacy receipt address
 * redirects to the magic link for whoever may read the receipt, sends staff
 * to the appointments list, and never hands the token to anyone else.
 *
 * Everything below the handler is real: AppointmentRepository over a `$wpdb`
 * double that answers the appointment row by its id and records any other
 * query, AppointmentReceiptOpenHandler and MagicLinkHelper.
 *
 * @covers \FreeFormCertificate\SelfScheduling\AppointmentReceiptHandler
 */
class AppointmentReceiptHandlerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const TOKEN = 'fake-token-0123456789abcdef0123456789abcdef0123456789abcdef01234';

	/** @var array<int, array<string, mixed>> Appointment rows by id. */
	private array $appointments = array();

	/** @var list<array<string, mixed>> Every row update the double received. */
	private array $updates = array();

	/** @var list<string> Every query the double did not expect. */
	private array $unexpected = array();

	/** @var array{0: string, 1: array<int, mixed>} SQL and bound arguments of the last prepare(). */
	private array $prepared = array( '', array() );

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->appointments = array();
		$this->updates      = array();
		$this->unexpected   = array();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'add_query_arg' )->alias(
			static fn( $key, $value, $url ) => $url . ( str_contains( (string) $url, '?' ) ? '&' : '?' ) . $key . '=' . $value
		);
		Functions\when( 'home_url' )->alias( static fn( $path = '' ) => 'https://example.com' . $path );
		Functions\when( 'admin_url' )->alias( static fn( $path = '' ) => 'https://example.com/wp-admin/' . $path );
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		// No verification page stored: the magic link falls back to /valid/.
		Functions\when( 'get_option' )->justReturn( 0 );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		$this->signed_in_as( 0, array() );

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']   = $this->make_wpdb();
	}

	protected function tearDown(): void {
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	protected function assertPostConditions(): void {
		$this->assertSame( array(), $this->unexpected, 'Unexpected queries reached $wpdb.' );
		parent::assertPostConditions();
	}

	/**
	 * A `$wpdb` double answering the appointment read by id and recording updates.
	 *
	 * @return \Mockery\MockInterface
	 */
	private function make_wpdb() {
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';

		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( $sql, ...$args ) {
				$this->prepared = array( (string) $sql, $args );
				return (string) $sql;
			}
		);
		$wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function () {
				list( $sql, $args ) = $this->prepared;
				if ( 'SELECT * FROM %i WHERE id = %d' === $sql && 'wp_ffc_self_scheduling_appointments' === $args[0] ) {
					return $this->appointments[ (int) $args[1] ] ?? null;
				}
				$this->unexpected[] = 'get_row: ' . $sql;
				return null;
			}
		);
		$wpdb->shouldReceive( 'update' )->andReturnUsing(
			function ( $table, $data, $where ) {
				$this->updates[] = array( 'table' => $table, 'data' => $data, 'where' => $where );
				return 1;
			}
		);
		foreach ( array( 'get_results', 'get_col', 'get_var', 'insert', 'delete', 'query' ) as $method ) {
			$wpdb->shouldReceive( $method )->andReturnUsing(
				function () use ( $method ) {
					$this->unexpected[] = $method . ': ' . $this->prepared[0];
					return null;
				}
			);
		}

		return $wpdb;
	}

	/**
	 * Sign in as a user holding the given capabilities (0 = signed out).
	 *
	 * @param int          $user_id User id.
	 * @param list<string> $caps    Capabilities held.
	 */
	private function signed_in_as( int $user_id, array $caps ): void {
		Functions\when( 'is_user_logged_in' )->justReturn( $user_id > 0 );
		Functions\when( 'get_current_user_id' )->justReturn( $user_id );
		Functions\when( 'current_user_can' )->alias( static fn( $cap ) => in_array( $cap, $caps, true ) );
	}

	/**
	 * Store an appointment row.
	 *
	 * @param array<string, mixed> $row Overrides.
	 */
	private function appointment( array $row = array() ): void {
		$row = array_merge(
			array(
				'id'                 => 7,
				'user_id'            => 42,
				'status'             => 'confirmed',
				'confirmation_token' => self::TOKEN,
			),
			$row
		);
		$this->appointments[ (int) $row['id'] ] = $row;
	}

	private function magic_link( string $token ): string {
		return 'https://example.com/valid/#token=' . $token;
	}

	public function test_add_query_vars_appends_receipt_vars(): void {
		$vars = ( new AppointmentReceiptHandler() )->add_query_vars( array( 'p' ) );

		$this->assertSame( array( 'p', 'ffc_appointment_receipt', 'token' ), $vars );
	}

	public function test_handle_receipt_request_returns_early_without_query_var(): void {
		Functions\when( 'get_query_var' )->justReturn( '' );
		Functions\expect( 'wp_safe_redirect' )->never();
		Functions\expect( 'wp_die' )->never();

		( new AppointmentReceiptHandler() )->handle_receipt_request();
	}

	public function test_an_invalid_id_is_refused(): void {
		$result = ( new AppointmentReceiptHandler() )->resolve_request( 0, '' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_a_missing_appointment_is_not_found(): void {
		$result = ( new AppointmentReceiptHandler() )->resolve_request( 99, self::TOKEN );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_the_token_holder_goes_to_the_magic_link(): void {
		$this->appointment();

		$this->assertSame( $this->magic_link( self::TOKEN ), ( new AppointmentReceiptHandler() )->resolve_request( 7, self::TOKEN ) );
	}

	public function test_a_pending_appointment_opens_through_the_magic_link_too(): void {
		$this->appointment( array( 'status' => 'pending' ) );

		$this->assertSame( $this->magic_link( self::TOKEN ), ( new AppointmentReceiptHandler() )->resolve_request( 7, self::TOKEN ) );
	}

	public function test_a_wrong_token_is_refused_and_the_real_one_never_leaks(): void {
		$this->appointment();

		$result = ( new AppointmentReceiptHandler() )->resolve_request( 7, 'fake-wrong-token' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		$this->assertStringNotContainsString( self::TOKEN, $result->get_error_message() );
	}

	public function test_the_signed_in_owner_goes_to_the_magic_link_without_a_token(): void {
		$this->appointment();
		$this->signed_in_as( 42, array() );

		$this->assertSame( $this->magic_link( self::TOKEN ), ( new AppointmentReceiptHandler() )->resolve_request( 7, '' ) );
		$this->assertSame( array(), $this->updates );
	}

	public function test_an_owner_whose_appointment_has_no_token_gets_one(): void {
		$this->appointment( array( 'confirmation_token' => null ) );
		$this->signed_in_as( 42, array() );

		$target = ( new AppointmentReceiptHandler() )->resolve_request( 7, '' );

		$this->assertCount( 1, $this->updates );
		$stored = $this->updates[0]['data']['confirmation_token'];
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $stored );
		$this->assertSame( array( 'id' => 7 ), $this->updates[0]['where'] );
		$this->assertSame( $this->magic_link( $stored ), $target );
	}

	public function test_staff_go_to_the_appointments_list_never_to_the_magic_link(): void {
		$this->appointment();
		$this->signed_in_as( 5, array( 'ffc_view_appointments', 'ffc_view_appointments_pii' ) );

		$target = ( new AppointmentReceiptHandler() )->resolve_request( 7, '' );

		$this->assertSame( 'https://example.com/wp-admin/admin.php?page=ffc-appointments', $target );
		$this->assertSame( array(), $this->updates );
	}

	public function test_a_signed_in_user_who_is_neither_owner_nor_staff_is_refused(): void {
		$this->appointment();
		$this->signed_in_as( 9, array() );

		$result = ( new AppointmentReceiptHandler() )->resolve_request( 7, '' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		$this->assertSame( array(), $this->updates );
	}

	public function test_handle_receipt_request_redirects(): void {
		$this->appointment();
		Functions\when( 'get_query_var' )->alias( static fn( $var ) => 'ffc_appointment_receipt' === $var ? '7' : self::TOKEN );
		Functions\expect( 'wp_safe_redirect' )->once()->with( $this->magic_link( self::TOKEN ) )->andReturnUsing(
			static function () {
				throw new \RuntimeException( 'redirected' );
			}
		);

		$this->expectExceptionMessage( 'redirected' );
		( new AppointmentReceiptHandler() )->handle_receipt_request();
	}

	public function test_handle_receipt_request_dies_with_the_refusal_status(): void {
		$this->appointment();
		Functions\when( 'get_query_var' )->alias( static fn( $var ) => 'ffc_appointment_receipt' === $var ? '7' : '' );
		Functions\expect( 'wp_safe_redirect' )->never();
		Functions\expect( 'wp_die' )->once()->andReturnUsing(
			static function ( $message, $title, $args ) {
				throw new \RuntimeException( $args['response'] . ' ' . $message );
			}
		);

		$this->expectExceptionMessage( '403 You do not have permission to view this appointment receipt.' );
		( new AppointmentReceiptHandler() )->handle_receipt_request();
	}

	public function test_get_receipt_url_is_the_magic_link_when_the_token_is_known(): void {
		$this->assertSame( $this->magic_link( self::TOKEN ), AppointmentReceiptHandler::get_receipt_url( 7, self::TOKEN ) );
	}

	public function test_get_receipt_url_falls_back_to_the_legacy_address_without_a_token(): void {
		$this->assertSame( 'https://example.com?ffc_appointment_receipt=7', AppointmentReceiptHandler::get_receipt_url( 7 ) );
	}
}
