<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Generators\QrPayload;

/**
 * QrPayload (#1563): the text each content type encodes, and the validation
 * that refuses what a phone could not act on.
 *
 * The formats were also checked end to end: every type below was rendered,
 * rasterised and decoded with ZXing back to the exact payload, UTF-8 text and
 * Wi-Fi escapes included.
 *
 * @covers \FreeFormCertificate\Generators\QrPayload
 */
class QrPayloadTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_http_validate_url' )->alias( static fn( $u ) => filter_var( $u, FILTER_VALIDATE_URL ) ? $u : false );
		Functions\when( 'sanitize_email' )->alias( static fn( $e ) => trim( $e ) );
		Functions\when( 'is_email' )->alias( static fn( $e ) => false !== filter_var( $e, FILTER_VALIDATE_EMAIL ) );
		Functions\when( 'is_wp_error' )->alias( static fn( $v ) => $v instanceof \WP_Error );
		Functions\when( 'add_query_arg' )->alias( static fn( $args, $url ) => $url . '?' . implode( '&', array_map( static fn( $k, $v ) => $k . '=' . $v, array_keys( $args ), $args ) ) );
		Functions\when( 'wp_timezone_string' )->justReturn( 'America/Sao_Paulo' );
		Functions\when( 'admin_url' )->alias( static fn( $p ) => 'https://site.test/wp-admin/' . $p );
		Functions\when( 'wp_json_encode' )->alias( static fn( $v, $f = 0 ) => json_encode( $v, $f ) );
		Functions\when( 'wp_salt' )->justReturn( 'salt' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function error( string $type, array $fields ): string {
		$result = QrPayload::build( $type, $fields );
		$this->assertInstanceOf( \WP_Error::class, $result );
		return $result->get_error_message();
	}

	public function test_url_gains_a_scheme_and_refuses_garbage(): void {
		$this->assertSame( 'https://example.com/a?b=1', QrPayload::build( 'url', array( 'url' => 'example.com/a?b=1' ) ) );
		$this->assertSame( 'http://example.com', QrPayload::build( 'url', array( 'url' => ' http://example.com ' ) ) );
		$this->error( 'url', array( 'url' => '' ) );
		$this->error( 'url', array( 'url' => 'not a url' ) );
	}

	public function test_text_is_kept_as_typed(): void {
		$this->assertSame( "Olá\nmundo", QrPayload::build( 'text', array( 'text' => "Olá\nmundo" ) ) );
		$this->error( 'text', array( 'text' => '   ' ) );
	}

	public function test_wifi_escapes_its_special_characters(): void {
		$this->assertSame(
			'WIFI:T:WPA;S:Escola\;Rede\\:1;P:p@ss\\,\\"x\\\\y;H:true;;',
			QrPayload::build(
				'wifi',
				array(
					'ssid'     => 'Escola;Rede:1',
					'password' => 'p@ss,"x\\y',
					'security' => 'WPA',
					'hidden'   => '1',
				)
			)
		);
	}

	public function test_an_open_network_carries_no_password(): void {
		$this->assertSame( 'WIFI:T:nopass;S:Guest;;', QrPayload::build( 'wifi', array( 'ssid' => 'Guest', 'password' => 'ignored', 'security' => 'nopass' ) ) );
	}

	public function test_wifi_needs_a_name_and_a_password_unless_open(): void {
		$this->error( 'wifi', array( 'password' => 'x' ) );
		$this->error( 'wifi', array( 'ssid' => 'Net', 'security' => 'WPA' ) );
		// An unknown security type falls back to WPA rather than failing.
		$this->assertStringStartsWith( 'WIFI:T:WPA;', (string) QrPayload::build( 'wifi', array( 'ssid' => 'Net', 'password' => 'x', 'security' => 'WPA9' ) ) );
	}

	public function test_an_enterprise_network_carries_the_user_name_and_its_methods(): void {
		$this->assertSame(
			'WIFI:T:WPA2-EAP;S:Escola;P:s3nha\\;x;E:TTLS;PH2:PAP;I:maria@sme;A:anon;H:true;;',
			QrPayload::build(
				'wifi',
				array(
					'ssid'      => 'Escola',
					'password'  => 's3nha;x',
					'security'  => 'WPA2-EAP',
					'identity'  => 'maria@sme',
					'eap'       => 'TTLS',
					'phase2'    => 'PAP',
					'anonymous' => 'anon',
					'hidden'    => '1',
				)
			)
		);
	}

	public function test_an_enterprise_network_defaults_its_methods_and_omits_a_blank_anonymous_identity(): void {
		$this->assertSame(
			'WIFI:T:WPA2-EAP;S:Net;P:x;E:PEAP;PH2:MSCHAPV2;I:joao;;',
			QrPayload::build( 'wifi', array( 'ssid' => 'Net', 'password' => 'x', 'security' => 'WPA2-EAP', 'identity' => 'joao', 'eap' => 'TLS', 'phase2' => 'CHAP' ) )
		);
	}

	public function test_an_enterprise_network_needs_a_user_name(): void {
		$this->assertSame(
			'Enter the user name for the Enterprise network.',
			$this->error( 'wifi', array( 'ssid' => 'Net', 'password' => 'x', 'security' => 'WPA2-EAP', 'identity' => '  ' ) )
		);
	}

	public function test_a_personal_network_ignores_the_enterprise_fields(): void {
		$this->assertSame( 'WIFI:T:WPA;S:Net;P:x;;', QrPayload::build( 'wifi', array( 'ssid' => 'Net', 'password' => 'x', 'security' => 'WPA', 'identity' => 'maria' ) ) );
	}

	public function test_email_is_a_canonical_mailto_with_encoded_fields(): void {
		$this->assertSame(
			'mailto:fulano@example.com?subject=Ol%C3%A1%20%26%20tal&body=a%20b%0Ac',
			QrPayload::build( 'email', array( 'email' => 'Fulano@Example.com', 'subject' => 'Olá & tal', 'body' => "a b\nc" ) )
		);
		$this->assertSame( 'mailto:a@b.co', QrPayload::build( 'email', array( 'email' => 'a@b.co' ) ) );
		$this->error( 'email', array( 'email' => 'nobody@' ) );
	}

	public function test_phone_sms_and_whatsapp_normalise_the_number(): void {
		$this->assertSame( 'tel:+5511987654321', QrPayload::build( 'phone', array( 'phone' => '+55 (11) 98765-4321' ) ) );
		$this->assertSame( 'SMSTO:11987654321:Oi: tudo bem?', QrPayload::build( 'sms', array( 'phone' => '11 98765 4321', 'message' => 'Oi: tudo bem?' ) ) );
		$this->assertSame( 'https://wa.me/5511987654321?text=Quero%20o%20certificado', QrPayload::build( 'whatsapp', array( 'phone' => '+55 11 98765-4321', 'message' => 'Quero o certificado' ) ) );
		$this->assertSame( 'https://wa.me/5511987654321', QrPayload::build( 'whatsapp', array( 'phone' => '5511987654321' ) ) );
	}

	public function test_a_number_too_short_or_too_long_is_refused(): void {
		$this->assertSame( 'Enter a valid phone number.', $this->error( 'phone', array( 'phone' => '12' ) ) );
		$this->assertSame( 'Enter a valid phone number.', $this->error( 'sms', array( 'phone' => str_repeat( '9', 16 ) ) ) );
		$this->assertSame( 'Enter a valid phone number.', $this->error( 'whatsapp', array( 'phone' => 'abc' ) ) );
	}

	public function test_an_unknown_type_and_non_scalar_fields_are_refused(): void {
		$this->assertSame( 'ffc_qr_type', QrPayload::build( 'fax', array() )->get_error_code() );
		$this->error( 'text', array( 'text' => array( 'x' ) ) );
	}

	public function test_usage_reports_fill_version_and_density(): void {
		$this->assertSame(
			array(
				'bytes'    => 100,
				'capacity' => 2331,
				'percent'  => 5,
				'version'  => 5,
				'dense'    => false,
			),
			QrPayload::usage( str_repeat( 'x', 100 ), 'M', 37 )
		);

		$dense = QrPayload::usage( str_repeat( 'x', 1300 ), 'H', 17 + 4 * 30 );
		$this->assertSame( 30, $dense['version'] );
		$this->assertTrue( $dense['dense'] );
		$this->assertSame( 100, $dense['percent'] );
		$this->assertSame( 0, QrPayload::usage( 'x', 'Z', 0 )['version'] );
		$this->assertSame( 2331, QrPayload::usage( 'x', 'Z', 0 )['capacity'] );
	}

	public function test_vcard_carries_every_filled_field_escaped(): void {
		$this->assertSame(
			implode(
				"\r\n",
				array(
					'BEGIN:VCARD',
					'VERSION:3.0',
					'N:da Silva\\; Souza;Maria;;;',
					'FN:Maria da Silva\\; Souza',
					'ORG:SME\\, São Paulo',
					'TEL;TYPE=CELL:+5511987654321',
					'EMAIL;TYPE=INTERNET:maria@sme.gov.br',
					'URL:https://sme.gov.br',
					'NOTE:Linha1\\nLinha2',
					'ADR;TYPE=WORK:;;Rua X\\, 10;São Paulo;SP;;Brasil',
					'END:VCARD',
				)
			),
			QrPayload::build(
				'vcard',
				array(
					'first_name'   => 'Maria',
					'last_name'    => 'da Silva; Souza',
					'organization' => 'SME, São Paulo',
					'mobile'       => '+55 11 98765-4321',
					'email'        => 'Maria@sme.gov.br',
					'website'      => 'sme.gov.br',
					'note'         => "Linha1\nLinha2",
					'street'       => 'Rua X, 10',
					'city'         => 'São Paulo',
					'region'       => 'SP',
					'country'      => 'Brasil',
				)
			)
		);
	}

	public function test_a_vcard_of_an_organisation_alone_names_it(): void {
		$card = (string) QrPayload::build( 'vcard', array( 'organization' => 'SME' ) );

		$this->assertStringContainsString( "FN:SME\r\n", $card );
		$this->assertStringNotContainsString( 'ADR', $card );
	}

	public function test_a_vcard_needs_a_name_and_valid_contacts(): void {
		$this->assertSame( 'Enter a name or an organisation.', $this->error( 'vcard', array( 'email' => 'a@b.co' ) ) );
		$this->assertSame( 'Enter a valid e-mail address.', $this->error( 'vcard', array( 'first_name' => 'A', 'email' => 'nobody@' ) ) );
		$this->assertSame( 'Enter a valid web address (http or https).', $this->error( 'vcard', array( 'first_name' => 'A', 'website' => 'not a url' ) ) );
	}

	public function test_social_joins_the_prefix_and_the_user_name(): void {
		$this->assertSame( 'https://www.instagram.com/prefeiturasp', QrPayload::build( 'social', array( 'network' => 'instagram', 'username' => '@prefeiturasp' ) ) );
		$this->assertSame( 'https://www.linkedin.com/in/maria-silva', QrPayload::build( 'social', array( 'network' => 'linkedin', 'username' => 'https://www.linkedin.com/in/maria-silva/' ) ) );
		$this->assertSame( 'https://www.youtube.com/@canal', QrPayload::build( 'social', array( 'network' => 'youtube', 'username' => 'https://www.youtube.com/@canal' ) ) );
		$this->assertSame( 'Choose a social network.', $this->error( 'social', array( 'network' => 'myspace', 'username' => 'x' ) ) );
		$this->assertSame( 'Enter the user name (letters, digits, dots, dashes and underscores).', $this->error( 'social', array( 'network' => 'x', 'username' => 'a b/c' ) ) );
	}

	/**
	 * @return array<string, string>
	 */
	private function event( array $extra = array() ): array {
		return array_merge(
			array(
				'title'    => 'Formação; módulo 2',
				'date'     => '2026-11-10',
				'start'    => '09:00',
				'end'      => '12:30',
				'location' => 'Auditório, 3º andar',
			),
			$extra
		);
	}

	public function test_event_vevent_is_floating_local_time_escaped(): void {
		$this->assertSame(
			"BEGIN:VEVENT\r\nSUMMARY:Formação\\; módulo 2\r\nDTSTART:20261110T090000\r\nDTEND:20261110T123000\r\nLOCATION:Auditório\\, 3º andar\r\nEND:VEVENT",
			QrPayload::build( 'event', $this->event() )
		);
	}

	public function test_event_google_link_names_the_site_time_zone(): void {
		$link = (string) QrPayload::build( 'event', $this->event( array( 'mode' => 'google' ) ) );

		$this->assertStringStartsWith( 'https://calendar.google.com/calendar/render?action=TEMPLATE&text=Forma%C3%A7%C3%A3o%3B%20m%C3%B3dulo%202', $link );
		$this->assertStringContainsString( 'dates=20261110T090000%2F20261110T123000', $link );
		$this->assertStringContainsString( 'ctz=America%2FSao_Paulo', $link );
		$this->assertStringNotContainsString( 'details=', $link, 'An empty field is left out.' );
	}

	public function test_event_ics_mode_is_a_signed_link(): void {
		$link = (string) QrPayload::build( 'event', $this->event( array( 'mode' => 'ics' ) ) );

		$this->assertStringStartsWith( 'https://site.test/wp-admin/admin-post.php?action=ffc_qr_ics&e=', $link );
		$this->assertMatchesRegularExpression( '/&s=[0-9a-f]{32}$/', $link );
	}

	public function test_an_event_is_validated(): void {
		$this->assertSame( 'Enter the event title.', $this->error( 'event', $this->event( array( 'title' => '' ) ) ) );
		$this->assertSame( 'Enter a valid date.', $this->error( 'event', $this->event( array( 'date' => '2026-02-30' ) ) ) );
		$this->assertSame( 'Enter the start and end times.', $this->error( 'event', $this->event( array( 'start' => '9h' ) ) ) );
		$this->assertSame( 'The event must end after it starts.', $this->error( 'event', $this->event( array( 'end' => '09:00' ) ) ) );
		// An unknown mode falls back to the event itself.
		$this->assertStringStartsWith( 'BEGIN:VEVENT', (string) QrPayload::build( 'event', $this->event( array( 'mode' => 'outlook' ) ) ) );
	}

	public function test_the_ics_link_carries_its_expiry_in_the_signed_payload(): void {
		$link = (string) QrPayload::build( 'event', $this->event( array( 'mode' => 'ics', 'until' => '2026-11-30' ) ) );
		parse_str( (string) parse_url( $link, PHP_URL_QUERY ), $query );

		$event = \FreeFormCertificate\Generators\QrEventLink::verify( (string) $query['e'], (string) $query['s'] );
		$this->assertSame( '2026-11-30', $event['until'] ?? null );
	}

	public function test_the_ics_expiry_is_validated_and_ignored_by_other_modes(): void {
		$this->assertSame( 'Enter a valid date for the link expiry.', $this->error( 'event', $this->event( array( 'mode' => 'ics', 'until' => '2026-13-01' ) ) ) );
		$this->assertSame( 'The link cannot expire before the event.', $this->error( 'event', $this->event( array( 'mode' => 'ics', 'until' => '2026-11-09' ) ) ) );
		// The same day as the event is allowed: the link lasts through it.
		$this->assertStringStartsWith( 'https://site.test/', (string) QrPayload::build( 'event', $this->event( array( 'mode' => 'ics', 'until' => '2026-11-10' ) ) ) );
		// Only the .ics link can expire; a malformed date is no error for the others.
		$this->assertStringStartsWith( 'BEGIN:VEVENT', (string) QrPayload::build( 'event', $this->event( array( 'until' => 'garbage' ) ) ) );
	}
}
