<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Generators\QrEventLink;
use FreeFormCertificate\UrlShortener\QrEventIcsHandler;

/**
 * The generator's signed event link and the .ics it serves (#1563): the
 * link is the whole state, and the site serves only events it signed.
 *
 * @covers \FreeFormCertificate\Generators\QrEventLink
 * @covers \FreeFormCertificate\UrlShortener\QrEventIcsHandler
 */
class QrEventLinkTest extends TestCase {

	private const EVENT = array(
		'title'       => 'Formação; módulo 2',
		'location'    => 'Auditório',
		'description' => "Trazer\ndocumento",
		'date'        => '2026-11-10',
		'start'       => '09:00',
		'end'         => '12:30',
	);

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_salt' )->justReturn( 'test-salt' );
		Functions\when( 'wp_json_encode' )->alias( static fn( $v, $f = 0 ) => json_encode( $v, $f ) );
		Functions\when( 'admin_url' )->alias( static fn( $p ) => 'https://site.test/wp-admin/' . $p );
		Functions\when( 'add_query_arg' )->alias( static fn( $args, $url ) => $url . '?' . http_build_query( $args ) );
		Functions\when( 'get_bloginfo' )->justReturn( 'Site' );
		Functions\when( 'home_url' )->justReturn( 'https://site.test' );
		Functions\when( 'wp_parse_url' )->alias( static fn( $u, $c = -1 ) => parse_url( $u, $c ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The `e` and `s` parameters of a link.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function params( string $url ): array {
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );
		$this->assertSame( 'ffc_qr_ics', $query['action'] );
		return array( (string) $query['e'], (string) $query['s'] );
	}

	public function test_a_link_round_trips_its_event(): void {
		list( $data, $sig ) = $this->params( QrEventLink::url( self::EVENT ) );

		$this->assertSame( self::EVENT, QrEventLink::verify( $data, $sig ) );
		$this->assertSame( 32, strlen( $sig ) );
		$this->assertDoesNotMatchRegularExpression( '/[+\/=]/', $data, 'The payload is base64url, safe in a query string.' );
	}

	public function test_a_tampered_payload_or_signature_is_refused(): void {
		list( $data, $sig ) = $this->params( QrEventLink::url( self::EVENT ) );

		$this->assertNull( QrEventLink::verify( $data . 'A', $sig ) );
		$this->assertNull( QrEventLink::verify( $data, str_repeat( '0', 32 ) ) );
		$this->assertNull( QrEventLink::verify( '', '' ) );
	}

	public function test_a_link_signed_with_another_sites_salt_is_refused(): void {
		list( $data, $sig ) = $this->params( QrEventLink::url( self::EVENT ) );
		Functions\when( 'wp_salt' )->justReturn( 'another-site' );

		$this->assertNull( QrEventLink::verify( $data, $sig ) );
	}

	public function test_unknown_keys_are_dropped_and_missing_ones_are_empty(): void {
		list( $data, $sig ) = $this->params( QrEventLink::url( array( 'title' => 'T', 'evil' => '<x>' ) ) );

		$event = QrEventLink::verify( $data, $sig );
		$this->assertSame( array( 'title', 'location', 'description', 'date', 'start', 'end' ), array_keys( (array) $event ) );
		$this->assertSame( '', $event['date'] );
	}

	public function test_the_handler_builds_a_publish_ics_for_a_signed_link(): void {
		list( $data, $sig ) = $this->params( QrEventLink::url( self::EVENT ) );

		$ics = QrEventIcsHandler::build( $data, $sig );

		$this->assertStringContainsString( "METHOD:PUBLISH\r\n", $ics );
		$this->assertStringContainsString( "DTSTART:20261110T090000\r\n", $ics );
		$this->assertStringContainsString( "DTEND:20261110T123000\r\n", $ics );
		$this->assertStringContainsString( 'SUMMARY:Formação\; módulo 2', $ics );
		$this->assertStringContainsString( 'DESCRIPTION:Trazer\\ndocumento', $ics );
		$this->assertStringContainsString( 'UID:ffc-qr-' . substr( $sig, 0, 16 ) . '@site.test', $ics );
	}

	public function test_the_handler_refuses_an_unsigned_or_untitled_link(): void {
		$this->assertSame( '', QrEventIcsHandler::build( 'eyJ0aXRsZSI6IlgifQ', 'forged' ) );

		list( $data, $sig ) = $this->params( QrEventLink::url( array( 'date' => '2026-11-10' ) ) );
		$this->assertSame( '', QrEventIcsHandler::build( $data, $sig ) );
	}

	public function test_the_handler_listens_for_anonymous_scans_too(): void {
		$hooks = array();
		Functions\when( 'add_action' )->alias(
			static function ( $hook ) use ( &$hooks ) {
				$hooks[] = $hook;
			}
		);

		( new QrEventIcsHandler() )->init();

		$this->assertSame( array( 'admin_post_ffc_qr_ics', 'admin_post_nopriv_ffc_qr_ics' ), $hooks );
	}

	public function test_handle_refuses_a_forged_link_with_403(): void {
		$_GET = array( 'e' => 'x', 's' => 'y' );
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\expect( 'wp_die' )->once()->with( 'This event link is not valid.', '', array( 'response' => 403 ) )->andThrow( new \RuntimeException( 'died' ) );

		try {
			( new QrEventIcsHandler() )->handle();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'died', $e->getMessage() );
		}
		$_GET = array();
	}
}
