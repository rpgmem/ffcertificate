<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Admin\CertificateOpenHandler;
use FreeFormCertificate\Tests\Support\ActivityLogBuffer;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Staff open a certificate through an audited route (#2241): the shared PII
 * policy decides, and every opening writes one activity-log row.
 *
 * The real PiiAccessPolicy, MagicLinkHelper and ActivityLog run; the
 * capabilities come from `user_can()` and the log is read from its buffer.
 *
 * @covers \FreeFormCertificate\Admin\CertificateOpenHandler
 */
class CertificateOpenHandlerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const TOKEN = '0123456789abcdef0123456789abcdef';

	/** @var \Mockery\MockInterface */
	private $handler;

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

		$this->handler = Mockery::mock( 'FreeFormCertificate\Submissions\SubmissionHandler' );
	}

	protected function tearDown(): void {
		ActivityLogBuffer::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	private function stored( int $owner = 0 ): void {
		$this->handler->shouldReceive( 'get_submission' )->with( 5 )->andReturn(
			array(
				'id'          => 5,
				'user_id'     => $owner,
				'magic_token' => self::TOKEN,
			)
		);
		$this->handler->shouldReceive( 'ensure_magic_token' )->with( 5 )->andReturn( self::TOKEN );
	}

	public function test_init_registers_the_admin_post_action(): void {
		Functions\expect( 'add_action' )->once()->with( 'admin_post_ffc_open_certificate', Mockery::type( 'array' ) );

		( new CertificateOpenHandler( $this->handler ) )->init();
	}

	public function test_the_url_is_bound_to_the_submission_by_its_nonce(): void {
		Functions\when( 'admin_url' )->returnArg();
		Functions\when( 'add_query_arg' )->alias( static fn( $args, $url ) => $url . '?' . http_build_query( $args ) );
		Functions\when( 'wp_nonce_url' )->alias( static fn( $url, $action ) => $url . '&_wpnonce=' . $action );

		$this->assertSame( 'admin-post.php?action=ffc_open_certificate&submission_id=5&_wpnonce=ffc_open_certificate_5', CertificateOpenHandler::url( 5 ) );
		$this->assertNotSame( CertificateOpenHandler::nonce_action( 5 ), CertificateOpenHandler::nonce_action( 6 ) );
	}

	public function test_a_holder_of_the_pii_capability_gets_the_link_and_the_opening_is_logged(): void {
		$this->caps = array( 'ffc_view_certificates_pii' );
		$this->stored( 9 );

		$link = ( new CertificateOpenHandler( $this->handler ) )->resolve( 5 );

		$this->assertSame( 'https://example.test/valid/#token=' . self::TOKEN, $link );
		$logged = ActivityLogBuffer::contexts();
		$this->assertCount( 1, $logged );
		$this->assertSame( 'submission_pii_revealed', $logged[0]['action'] );
		$this->assertSame( array( 'field_key' => 'certificate' ), $logged[0]['context'] );
		$this->assertSame( 5, (int) ActivityLogBuffer::entries()[0]['submission_id'] );
	}

	public function test_a_site_administrator_reveals_too(): void {
		$this->caps = array( 'manage_options' );
		$this->stored( 9 );

		$this->assertNotSame( '', ( new CertificateOpenHandler( $this->handler ) )->resolve( 5 ) );
	}

	public function test_a_user_who_cannot_reveal_gets_nothing_and_nothing_is_logged(): void {
		$this->caps = array( 'ffc_view_certificates', 'ffc_edit_certificates' );
		$this->stored( 9 );

		$this->assertSame( '', ( new CertificateOpenHandler( $this->handler ) )->resolve( 5 ) );
		$this->assertSame( array(), ActivityLogBuffer::entries() );
	}

	public function test_the_owner_opens_their_own_certificate(): void {
		$this->stored( 7 );

		$this->assertNotSame( '', ( new CertificateOpenHandler( $this->handler ) )->resolve( 5 ) );
	}

	public function test_a_missing_submission_gets_nothing(): void {
		$this->caps = array( 'manage_options' );
		$this->handler->shouldReceive( 'get_submission' )->with( 404 )->andReturn( null );

		$this->assertSame( '', ( new CertificateOpenHandler( $this->handler ) )->resolve( 404 ) );
		$this->assertSame( array(), ActivityLogBuffer::entries() );
	}

	public function test_handle_checks_the_nonce_of_that_submission_first(): void {
		$_GET = array( 'submission_id' => '5' );
		Functions\expect( 'check_admin_referer' )->once()->with( 'ffc_open_certificate_5' )->andThrow( new \RuntimeException( 'bad nonce' ) );

		try {
			( new CertificateOpenHandler( $this->handler ) )->handle();
			$this->fail( 'A bad nonce must stop the request.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'bad nonce', $e->getMessage() );
		} finally {
			$_GET = array();
		}
		$this->assertSame( array(), ActivityLogBuffer::entries() );
	}

	public function test_handle_refuses_with_403_a_user_who_cannot_reveal(): void {
		$_GET = array( 'submission_id' => '5' );
		Functions\when( 'check_admin_referer' )->justReturn( 1 );
		$this->stored( 9 );
		Functions\expect( 'wp_die' )->once()->with( Mockery::type( 'string' ), '', array( 'response' => 403 ) )->andThrow( new \RuntimeException( 'die' ) );

		try {
			( new CertificateOpenHandler( $this->handler ) )->handle();
			$this->fail( 'A user who cannot reveal must be stopped.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'die', $e->getMessage() );
		} finally {
			$_GET = array();
		}
	}
}
