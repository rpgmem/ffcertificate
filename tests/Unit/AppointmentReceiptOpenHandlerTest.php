<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\SelfScheduling\AppointmentReceiptOpenHandler;
use FreeFormCertificate\Tests\Support\ActivityLogBuffer;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Staff open an appointment receipt through an audited route (#2732), the
 * way certificates open: the shared PII policy decides, and every opening
 * writes one activity-log row.
 *
 * The real PiiAccessPolicy, MagicLinkHelper and ActivityLog run; the
 * capabilities come from `user_can()` and the log is read from its buffer.
 *
 * @covers \FreeFormCertificate\SelfScheduling\AppointmentReceiptOpenHandler
 */
class AppointmentReceiptOpenHandlerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const TOKEN = 'fake-receipt-token-0123456789abcdef';

	/** @var \Mockery\MockInterface */
	private $repository;

	/** @var list<string> Capabilities the current user (id 7) holds. */
	private array $caps = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		ActivityLogBuffer::reset();
		ActivityLogBuffer::stub_functions();

		$this->caps = array();
		Functions\when( 'get_option' )->alias( static fn( $k, $d = false ) => 'ffc_settings' === $k ? ActivityLogBuffer::ENABLED_SETTINGS : $d );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'user_can' )->alias( fn( $uid, $cap ) => in_array( $cap, $this->caps, true ) );
		Functions\when( 'home_url' )->alias( static fn( $path = '' ) => 'https://example.test' . $path );
		Functions\when( 'esc_html__' )->returnArg();

		$this->repository = Mockery::mock( 'FreeFormCertificate\Repositories\AppointmentRepository' );
	}

	protected function tearDown(): void {
		ActivityLogBuffer::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Store appointment 5.
	 *
	 * @param array<string, mixed> $row Overrides.
	 */
	private function stored( array $row = array() ): void {
		$this->repository->shouldReceive( 'findById' )->with( 5 )->andReturn(
			array_merge(
				array(
					'id'                 => 5,
					'user_id'            => 9,
					'status'             => 'confirmed',
					'confirmation_token' => self::TOKEN,
				),
				$row
			)
		);
	}

	private function handler(): AppointmentReceiptOpenHandler {
		return new AppointmentReceiptOpenHandler( $this->repository );
	}

	public function test_init_registers_the_admin_post_action(): void {
		Functions\expect( 'add_action' )->once()->with( 'admin_post_ffc_open_appointment_receipt', Mockery::type( 'array' ) );

		$this->handler()->init();
	}

	public function test_the_url_is_bound_to_the_appointment_by_its_nonce(): void {
		Functions\when( 'admin_url' )->returnArg();
		Functions\when( 'add_query_arg' )->alias( static fn( $args, $url ) => $url . '?' . http_build_query( $args ) );
		Functions\when( 'wp_nonce_url' )->alias( static fn( $url, $action ) => $url . '&_wpnonce=' . $action );

		$this->assertSame(
			'admin-post.php?action=ffc_open_appointment_receipt&appointment_id=5&_wpnonce=ffc_open_appointment_receipt_5',
			AppointmentReceiptOpenHandler::url( 5 )
		);
		$this->assertNotSame( AppointmentReceiptOpenHandler::nonce_action( 5 ), AppointmentReceiptOpenHandler::nonce_action( 6 ) );
	}

	public function test_a_holder_of_the_pii_capability_gets_the_link_and_the_opening_is_logged(): void {
		$this->caps = array( 'ffc_view_appointments_pii' );
		$this->stored();

		$link = $this->handler()->resolve( 5 );

		$this->assertSame( 'https://example.test/valid/#token=' . self::TOKEN, $link );
		$logged = ActivityLogBuffer::contexts();
		$this->assertCount( 1, $logged );
		$this->assertSame( 'appointment_pii_revealed', $logged[0]['action'] );
		$this->assertSame( array( 'field_key' => 'receipt' ), $logged[0]['context'] );
		$this->assertSame( 5, (int) ActivityLogBuffer::entries()[0]['submission_id'] );
	}

	public function test_a_site_administrator_reveals_too(): void {
		$this->caps = array( 'manage_options' );
		$this->stored();

		$this->assertNotSame( '', $this->handler()->resolve( 5 ) );
	}

	public function test_a_user_who_cannot_reveal_gets_nothing_and_nothing_is_logged(): void {
		$this->caps = array( 'ffc_view_appointments', 'ffc_manage_appointments' );
		$this->stored();

		$this->assertSame( '', $this->handler()->resolve( 5 ) );
		$this->assertSame( array(), ActivityLogBuffer::entries() );
	}

	public function test_a_cancelled_appointment_has_no_receipt_to_open(): void {
		$this->caps = array( 'manage_options' );
		$this->stored( array( 'status' => 'cancelled' ) );

		$this->assertSame( '', $this->handler()->resolve( 5 ) );
		$this->assertSame( array(), ActivityLogBuffer::entries() );
	}

	public function test_a_missing_appointment_gets_nothing(): void {
		$this->caps = array( 'manage_options' );
		$this->repository->shouldReceive( 'findById' )->with( 404 )->andReturn( null );

		$this->assertSame( '', $this->handler()->resolve( 404 ) );
		$this->assertSame( array(), ActivityLogBuffer::entries() );
	}

	public function test_an_appointment_booked_before_tokens_gets_one(): void {
		$this->caps = array( 'ffc_view_appointments_pii' );
		$this->stored( array( 'confirmation_token' => '' ) );
		$stored = '';
		$this->repository->shouldReceive( 'update' )->once()->with( 5, Mockery::on(
			static function ( $data ) use ( &$stored ) {
				$stored = (string) ( $data['confirmation_token'] ?? '' );
				return 1 === preg_match( '/^[0-9a-f]{64}$/', $stored );
			}
		) )->andReturn( 1 );

		$link = $this->handler()->resolve( 5 );

		$this->assertSame( 'https://example.test/valid/#token=' . $stored, $link );
	}

	public function test_a_token_that_cannot_be_stored_opens_nothing(): void {
		$this->caps = array( 'ffc_view_appointments_pii' );
		$this->stored( array( 'confirmation_token' => '' ) );
		$this->repository->shouldReceive( 'update' )->once()->andReturn( false );

		$this->assertSame( '', $this->handler()->resolve( 5 ) );
		$this->assertSame( array(), ActivityLogBuffer::entries() );
	}

	public function test_handle_checks_the_nonce_of_that_appointment_first(): void {
		$_GET = array( 'appointment_id' => '5' );
		Functions\expect( 'check_admin_referer' )->once()->with( 'ffc_open_appointment_receipt_5' )->andThrow( new \RuntimeException( 'bad nonce' ) );

		try {
			$this->handler()->handle();
			$this->fail( 'A bad nonce must stop the request.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'bad nonce', $e->getMessage() );
		} finally {
			$_GET = array();
		}
		$this->assertSame( array(), ActivityLogBuffer::entries() );
	}

	public function test_handle_refuses_with_403_a_user_who_cannot_reveal(): void {
		$_GET = array( 'appointment_id' => '5' );
		Functions\when( 'check_admin_referer' )->justReturn( 1 );
		$this->stored();
		Functions\expect( 'wp_die' )->once()->with( Mockery::type( 'string' ), '', array( 'response' => 403 ) )->andThrow( new \RuntimeException( 'die' ) );

		try {
			$this->handler()->handle();
			$this->fail( 'A user who cannot reveal must be stopped.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'die', $e->getMessage() );
		} finally {
			$_GET = array();
		}
	}

	public function test_handle_redirects_an_allowed_user_to_the_magic_link(): void {
		$_GET = array( 'appointment_id' => '5' );
		$this->caps = array( 'ffc_view_appointments_pii' );
		Functions\when( 'check_admin_referer' )->justReturn( 1 );
		$this->stored();
		Functions\expect( 'wp_safe_redirect' )->once()->with( 'https://example.test/valid/#token=' . self::TOKEN )->andThrow( new \RuntimeException( 'redirected' ) );

		try {
			$this->handler()->handle();
			$this->fail( 'An allowed user must be redirected.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirected', $e->getMessage() );
		} finally {
			$_GET = array();
		}
	}
}
