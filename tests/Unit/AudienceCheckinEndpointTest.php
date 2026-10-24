<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Audience\AudienceCheckinEndpoint;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * The public check-in URL (#1947): what a scan and a receipt URL answer.
 *
 * The flow behind it is {@see AudienceCheckinTest}; here it is an alias mock,
 * so these tests pin the routing — a logged-out visitor goes to the login, a
 * GET never writes, and a POST without a valid nonce never reaches the write.
 *
 * @covers \FreeFormCertificate\Audience\AudienceCheckinEndpoint
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AudienceCheckinEndpointTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const NOW = 1800000000;

	/** @var \Mockery\MockInterface */
	private $checkin;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Mockery::getConfiguration()->setConstantsMap(
			array(
				'FreeFormCertificate\\Audience\\AudienceAttendanceWriter' => array(
					'RECORDED' => 'recorded',
					'ALREADY'  => 'already',
					'FAILED'   => 'failed',
				),
			)
		);
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceAttendanceWriter' );

		$this->checkin = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceCheckin' );

		$button = Mockery::mock( 'alias:FreeFormCertificate\Core\Button' );
		$button->shouldReceive( 'html' )->andReturn( '<button type="submit">Confirm</button>' );
		$dates = Mockery::mock( 'alias:FreeFormCertificate\Core\DateFormatter' );
		$dates->shouldReceive( 'format_time' )->andReturn( '09:30' );
		$dates->shouldReceive( 'format_wallclock_date' )->andReturn( '15/01/2027' );

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'current_time' )->justReturn( '2027-01-15' );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'is_wp_error' )->alias( static fn( $v ) => $v instanceof \WP_Error );
		Functions\when( 'home_url' )->alias( static fn( $path = '' ) => 'https://example.org' . $path );
		Functions\when( 'add_query_arg' )->alias( static fn( $key, $value, $url ) => $url . '?' . $key . '=' . $value );
		Functions\when( 'wp_login_url' )->alias( static fn( $to ) => 'https://example.org/wp-login.php?redirect_to=' . rawurlencode( $to ) );
		Functions\when( 'wp_nonce_field' )->justReturn( '<input type="hidden" name="_wpnonce" value="n">' );
		Functions\when( 'get_current_user_id' )->justReturn( 42 );
	}

	protected function tearDown(): void {
		$_POST = array();
		unset( $_SERVER['REQUEST_METHOD'] );
		Mockery::getConfiguration()->setConstantsMap( array() );
		Monkey\tearDown();
		parent::tearDown();
	}

	private function booking(): object {
		return (object) array(
			'id'               => 31,
			'description'      => 'Workshop',
			'environment_name' => 'Room 3',
		);
	}

	/**
	 * A context the confirmation page can be drawn from.
	 *
	 * @param array<int, string> $geofence Window geofence location ids.
	 * @param int|null           $present  When already present.
	 * @return array<string, mixed>
	 */
	private function context( array $geofence = array(), ?int $present = null ): array {
		return array(
			'booking'    => $this->booking(),
			'window'     => array( 'geofence_locations' => $geofence ),
			'date'       => '2027-01-15',
			'present_at' => $present,
		);
	}

	public function test_init_routes_before_canonical_redirects(): void {
		AudienceCheckinEndpoint::init();

		$this->assertSame( 1, has_action( 'template_redirect', array( AudienceCheckinEndpoint::class, 'route' ) ) );
	}

	public function test_a_valid_scan_redirects_to_its_receipt(): void {
		$this->checkin->shouldReceive( 'scan' )->with( 'CODE', self::NOW )->andReturn( 'RECEIPT' );

		$this->assertSame(
			array( 'redirect' => 'https://example.org/?ffc_checkin_r=RECEIPT' ),
			AudienceCheckinEndpoint::handle_scan( 'CODE', self::NOW )
		);
	}

	public function test_an_invalid_scan_draws_the_error_with_403(): void {
		$this->checkin->shouldReceive( 'scan' )->andReturn( new \WP_Error( 'ffc_checkin_invalid_code', 'Scan again.' ) );

		$response = AudienceCheckinEndpoint::handle_scan( 'CODE', self::NOW );

		$this->assertSame( 403, $response['status'] );
		$this->assertStringContainsString( 'Scan again.', $response['page'] );
	}

	public function test_a_logged_out_visitor_is_sent_to_log_in_and_back(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		$this->checkin->shouldNotReceive( 'context' );
		$this->checkin->shouldNotReceive( 'confirm' );

		$response = AudienceCheckinEndpoint::handle_receipt( 'RECEIPT', self::NOW );

		$this->assertSame(
			'https://example.org/wp-login.php?redirect_to=' . rawurlencode( 'https://example.org/?ffc_checkin_r=RECEIPT' ),
			$response['redirect']
		);
	}

	public function test_a_get_shows_the_confirmation_and_never_writes(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$this->checkin->shouldReceive( 'context' )->with( 'RECEIPT', 42, self::NOW )->andReturn( $this->context() );
		$this->checkin->shouldNotReceive( 'confirm' );

		$response = AudienceCheckinEndpoint::handle_receipt( 'RECEIPT', self::NOW );

		$this->assertSame( 200, $response['status'] );
		$this->assertFalse( $response['script'] );
		$this->assertStringContainsString( '<form method="post"', $response['page'] );
		$this->assertStringContainsString( 'Workshop', $response['page'] );
		$this->assertStringNotContainsString( 'data-ffc-checkin-geo', $response['page'] );
	}

	public function test_a_geofenced_window_asks_for_the_location_script(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		$this->checkin->shouldReceive( 'context' )->andReturn( $this->context( array( 'loc_a' ) ) );

		$response = AudienceCheckinEndpoint::handle_receipt( 'RECEIPT', self::NOW );

		$this->assertTrue( $response['script'] );
		$this->assertStringContainsString( 'data-ffc-checkin-geo="1"', $response['page'] );
		// The margin of error travels with the position (#2040), and the
		// pre-flight's telemetry names the booking.
		$this->assertStringContainsString( 'name="ffc_accuracy"', $response['page'] );
		$this->assertSame( 31, $response['booking'] );
	}

	public function test_a_get_by_someone_already_present_says_so(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		$this->checkin->shouldReceive( 'context' )->andReturn( $this->context( array(), self::NOW - 600 ) );

		$response = AudienceCheckinEndpoint::handle_receipt( 'RECEIPT', self::NOW );

		$this->assertStringContainsString( 'already recorded at 09:30', $response['page'] );
		$this->assertStringNotContainsString( '<form', $response['page'] );
	}

	public function test_a_post_without_a_valid_nonce_never_writes(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST['_wpnonce']          = 'stale';
		$this->checkin->shouldNotReceive( 'confirm' );

		$response = AudienceCheckinEndpoint::handle_receipt( 'RECEIPT', self::NOW );

		$this->assertSame( 403, $response['status'] );
	}

	public function test_a_post_confirms_with_the_posted_position(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'_wpnonce'     => 'n',
			'ffc_lat'      => '-23.5',
			'ffc_lng'      => '-46.6',
			'ffc_accuracy' => '12',
		);
		$position = array(
			'lat'      => -23.5,
			'lng'      => -46.6,
			'accuracy' => 12.0,
		);
		$this->checkin->shouldReceive( 'position' )->with( '-23.5', '-46.6', '12' )->andReturn( $position );
		$this->checkin->shouldReceive( 'confirm' )->once()->with( 'RECEIPT', 42, $position, self::NOW )->andReturn(
			array(
				'status'     => 'recorded',
				'booking'    => $this->booking(),
				'checked_at' => self::NOW,
			)
		);

		$response = AudienceCheckinEndpoint::handle_receipt( 'RECEIPT', self::NOW );

		$this->assertSame( 200, $response['status'] );
		$this->assertStringContainsString( 'Recorded at 09:30', $response['page'] );
	}

	public function test_a_refused_confirmation_draws_its_reason(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST['_wpnonce']          = 'n';
		$this->checkin->shouldReceive( 'position' )->andReturn( null );
		$this->checkin->shouldReceive( 'confirm' )->andReturn( new \WP_Error( 'ffc_checkin_outside_area', 'Outside the area.' ) );

		$response = AudienceCheckinEndpoint::handle_receipt( 'RECEIPT', self::NOW );

		$this->assertSame( 403, $response['status'] );
		$this->assertStringContainsString( 'Outside the area.', $response['page'] );
	}
}
