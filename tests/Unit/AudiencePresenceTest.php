<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Audience\AudiencePresenceAgent;
use FreeFormCertificate\Audience\AudiencePresenceAttestation;
use FreeFormCertificate\Audience\AudiencePresenceEnrolment;
use FreeFormCertificate\Audience\AudiencePresenceKeys;
use FreeFormCertificate\Audience\AudiencePresenceRestController;
use FreeFormCertificate\Audience\AudiencePresenceWriter;
use FreeFormCertificate\Core\EcdsaP256;
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Core\SigningKeys;
use FreeFormCertificate\Tests\Support\ActivityLogBuffer;
use FreeFormCertificate\Tests\Support\FakeCredentials;
use FreeFormCertificate\Tests\Support\FakePresenceTables;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Presence devices (#2741): the device key, the site's attestation and the
 * rules a device is activated under, against tables that keep what they are
 * given.
 *
 * The device side is played by {@see EcdsaP256} itself; one vector produced
 * by a browser's WebCrypto pins that the two speak the same shapes.
 *
 * @covers \FreeFormCertificate\Core\EcdsaP256
 * @covers \FreeFormCertificate\Audience\AudiencePresenceKeys
 * @covers \FreeFormCertificate\Audience\AudiencePresenceAttestation
 * @covers \FreeFormCertificate\Audience\AudiencePresenceAgent
 * @covers \FreeFormCertificate\Audience\AudiencePresenceEnrolment
 * @covers \FreeFormCertificate\Audience\AudiencePresenceReader
 * @covers \FreeFormCertificate\Audience\AudiencePresenceWriter
 * @covers \FreeFormCertificate\Audience\AudiencePresenceRestController
 */
class AudiencePresenceTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const NOW = 1800000000;

	private const DAY = 86400;

	/** A public key and signature WebCrypto made, over the enrolment statement of user 7 at NOW. */
	private const WEBCRYPTO_PUBLIC = 'BNeLVKsgGu10YGn4YEXcgp74bnBpgEfWJNFy7i86ETEZF5odRxLBSPq2JdSqkJQ-pj4i4qQQhvHjol9sIQ5A6OI';

	private const WEBCRYPTO_PROOF = 'oTdJCC0LlBf95FCzravzklauZdX4zIX0zbQD4Dys5Syi0uAh5dLm2pAj0EeVMEMO-ga8uRKwje2byaXNBTuNvg';

	private FakePresenceTables $tables;

	/** @var array<string, mixed> */
	private array $options = array();

	/** @var list<array{0: string, 1: string, 2: string}> */
	private array $mailed = array();

	/** @var mixed */
	private $wpdb_backup;

	private bool $had_wpdb = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->tables  = new FakePresenceTables();
		$this->options = array(
			'ffc_settings' => array_merge(
				ActivityLogBuffer::ENABLED_SETTINGS,
				array(
					'date_format' => 'Y-m-d',
					'time_format' => 'H:i',
				)
			),
		);
		$this->mailed  = array();

		ActivityLogBuffer::stub_functions();
		ActivityLogBuffer::reset();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'wp_salt' )->justReturn( FakeCredentials::WP_SALT );
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
		Functions\when( 'wp_date' )->alias( static fn( string $format, int $ts ) => gmdate( $format, $ts ) );
		Functions\when( 'esc_html' )->alias( static fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES ) );
		Functions\when( 'esc_attr' )->alias( static fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES ) );
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'is_email' )->alias( static fn( $e ) => false !== strpos( (string) $e, '@' ) );
		Functions\when( 'get_bloginfo' )->justReturn( 'School' );
		Functions\when( 'home_url' )->alias( static fn( $path = '' ) => 'https://example.org' . $path );
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_option' )->alias(
			fn( $key, $default = false ) => array_key_exists( $key, $this->options ) ? $this->options[ $key ] : $default
		);
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) {
				$this->options[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'get_userdata' )->alias(
			static function ( $id ) {
				if ( ! in_array( (int) $id, array( 7, 8, 9 ), true ) ) {
					return false;
				}
				$user               = new \WP_User( (int) $id );
				$user->display_name = 7 === (int) $id ? 'Maria da Silva' : 'Person ' . $id;
				$user->user_email   = 'p' . $id . '@example.org';
				return $user;
			}
		);
		Functions\when( 'wp_mail' )->alias(
			function ( $to, $subject, $body ) {
				$this->mailed[] = array( (string) $to, (string) $subject, (string) $body );
				return true;
			}
		);

		$this->had_wpdb = array_key_exists( 'wpdb', $GLOBALS );
		if ( $this->had_wpdb ) {
			$this->wpdb_backup = $GLOBALS['wpdb'];
		}
		$GLOBALS['wpdb'] = $this->tables;
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

	// ---- The curve --------------------------------------------------------

	public function test_a_signature_verifies_under_its_key_and_nothing_else(): void {
		$pair      = EcdsaP256::generate();
		$other     = EcdsaP256::generate();
		$signature = EcdsaP256::sign( 'message', $pair['secret'] );

		$this->assertSame( EcdsaP256::PUBLIC_BYTES, strlen( $pair['public'] ) );
		$this->assertSame( EcdsaP256::SIGNATURE_BYTES, strlen( (string) $signature ) );
		$this->assertTrue( EcdsaP256::verify( 'message', (string) $signature, $pair['public'] ) );
		$this->assertFalse( EcdsaP256::verify( 'messagE', (string) $signature, $pair['public'] ) );
		$this->assertFalse( EcdsaP256::verify( 'message', (string) $signature, $other['public'] ) );
		$this->assertFalse( EcdsaP256::verify( 'message', substr( (string) $signature, 1 ), $pair['public'] ) );
	}

	public function test_a_webcrypto_signature_verifies(): void {
		$statement = AudiencePresenceEnrolment::ENROL_DOMAIN . '7|webcrypto-device-0001|' . self::NOW;

		$this->assertTrue( EcdsaP256::verify( $statement, SigningKeys::unb64( self::WEBCRYPTO_PROOF ), SigningKeys::unb64( self::WEBCRYPTO_PUBLIC ) ) );
	}

	public function test_bytes_that_are_not_a_point_on_the_curve_are_refused(): void {
		$this->assertFalse( EcdsaP256::valid_public( "\x04" . str_repeat( "\x01", 64 ) ) );
		$this->assertFalse( EcdsaP256::valid_public( str_repeat( "\x04", 33 ) ) );
		$this->assertFalse( EcdsaP256::valid_public( '' ) );
		$this->assertTrue( EcdsaP256::valid_public( SigningKeys::unb64( self::WEBCRYPTO_PUBLIC ) ) );
	}

	// ---- The attestation --------------------------------------------------

	public function test_an_attestation_carries_the_key_and_the_short_name_only(): void {
		$device = EcdsaP256::generate();
		$issued = AudiencePresenceAttestation::issue( 7, 'Maria S.', $device['public'], self::NOW );

		$read = AudiencePresenceAttestation::verify( $issued['attestation'], self::NOW + 60 );

		$this->assertIsArray( $read );
		$this->assertSame( 7, $read['user_id'] );
		$this->assertSame( 'Maria S.', $read['name'] );
		$this->assertSame( $device['public'], $read['public_key'] );
		$this->assertSame( AudiencePresenceAttestation::key_id( $device['public'] ), $read['key_id'] );
		$this->assertSame( self::NOW + AudiencePresenceAttestation::TTL, $read['expires'] );
		$this->assertSame( array( 'site_key', 'user_id', 'public_key', 'key_id', 'issued', 'expires', 'name' ), array_keys( $read ) );
	}

	public function test_an_attestation_expires_after_seven_days(): void {
		$device = EcdsaP256::generate();
		$issued = AudiencePresenceAttestation::issue( 7, 'Maria S.', $device['public'], self::NOW );

		$this->assertIsArray( AudiencePresenceAttestation::verify( $issued['attestation'], self::NOW + 7 * self::DAY ) );
		$late = AudiencePresenceAttestation::verify( $issued['attestation'], self::NOW + 7 * self::DAY + 1 );
		$this->assertInstanceOf( \WP_Error::class, $late );
		$this->assertSame( 'ffc_presence_attestation_expired', $late->get_error_code() );
	}

	public function test_a_changed_byte_breaks_the_attestation(): void {
		$device = EcdsaP256::generate();
		$issued = AudiencePresenceAttestation::issue( 7, 'Maria S.', $device['public'], self::NOW );
		$raw    = SigningKeys::unb64( $issued['attestation'] );

		// The user id sits right after the version byte and the site key id.
		$offset         = 2 + ord( $raw[1] ) + 3;
		$raw[ $offset ] = chr( ord( $raw[ $offset ] ) ^ 1 );

		$read = AudiencePresenceAttestation::verify( SigningKeys::b64( $raw ), self::NOW );
		$this->assertInstanceOf( \WP_Error::class, $read );
		$this->assertSame( 'ffc_presence_attestation_signature', $read->get_error_code() );
		$this->assertInstanceOf( \WP_Error::class, AudiencePresenceAttestation::verify( 'not-an-attestation', self::NOW ) );
	}

	public function test_a_rotated_key_verifies_what_it_signed_until_those_attestations_expire(): void {
		$device = EcdsaP256::generate();
		$old    = AudiencePresenceAttestation::issue( 7, 'Maria S.', $device['public'], self::NOW );
		AudiencePresenceKeys::rotate( self::NOW + self::DAY );
		$new = AudiencePresenceAttestation::issue( 7, 'Maria S.', $device['public'], self::NOW + self::DAY );

		$this->assertNotSame( AudiencePresenceAttestation::verify( $old['attestation'], self::NOW )['site_key'], AudiencePresenceAttestation::verify( $new['attestation'], self::NOW + self::DAY )['site_key'] );
		$this->assertIsArray( AudiencePresenceAttestation::verify( $old['attestation'], self::NOW + 6 * self::DAY ) );
		$this->assertCount( 2, AudiencePresenceKeys::public_keys( self::NOW + 2 * self::DAY ) );

		// Retired longer than an attestation lives: the old key is no longer published.
		$this->assertCount( 1, AudiencePresenceKeys::public_keys( self::NOW + 9 * self::DAY ) );
	}

	public function test_an_unreadable_site_secret_is_rotated_out_instead_of_refusing_enrolment(): void {
		$first = AudiencePresenceKeys::ensure_active();
		$state = $this->options[ AudiencePresenceKeys::OPTION ];

		$unreadable                                      = Encryption::V2_PREFIX . 'fake-unreadable';
		$state['keys'][ $first ]['secret']               = $unreadable;
		$this->options[ AudiencePresenceKeys::OPTION ] = $state;

		$signed = AudiencePresenceKeys::sign( 'message' );

		$this->assertNotNull( $signed );
		$this->assertNotSame( $first, $signed['key'] );
	}

	public function test_the_short_name_is_a_first_name_and_an_initial(): void {
		$maria               = new \WP_User( 7 );
		$maria->display_name = 'Maria da Silva';
		$this->assertSame( 'Maria S.', AudiencePresenceAttestation::display_name( $maria ) );

		$other               = new \WP_User( 8 );
		$other->display_name = 'João Pedro Álvares';
		$this->assertSame( 'João Á.', AudiencePresenceAttestation::display_name( $other ) );

		$long               = new \WP_User( 9 );
		$long->display_name = str_repeat( 'é', 40 );
		$name               = AudiencePresenceAttestation::display_name( $long );
		$this->assertLessThanOrEqual( AudiencePresenceAttestation::NAME_BYTES, strlen( $name ) );
		$this->assertTrue( mb_check_encoding( $name, 'UTF-8' ) );
	}

	// ---- Enrolment --------------------------------------------------------

	public function test_the_first_device_activates_without_counting_as_a_change_or_mailing(): void {
		$result = $this->enrol( 7, 'device-aaaaaaaaaaaa', EcdsaP256::generate() );

		$this->assertIsArray( $result );
		$this->assertSame( 'Maria S.', $result['name'] );
		$this->assertCount( 1, $this->tables->devices );
		$this->assertSame( 0, (int) array_values( $this->tables->devices )[0]['is_change'] );
		$this->assertSame( array(), $this->mailed );
		$this->assertSame( array( 'presence_device_activated' ), $this->logged() );
	}

	public function test_a_new_device_revokes_the_old_key_and_mails_the_user(): void {
		$first  = $this->enrol( 7, 'device-aaaaaaaaaaaa', EcdsaP256::generate() );
		$second = $this->enrol( 7, 'device-bbbbbbbbbbbb', EcdsaP256::generate(), self::NOW + 60 );

		$rows = array_values( $this->tables->devices );
		$this->assertSame( self::NOW + 60, $rows[0]['revoked_at'] );
		$this->assertSame( AudiencePresenceWriter::REVOKED_REPLACED, $rows[0]['revoked_reason'] );
		$this->assertNull( $rows[1]['revoked_at'] );
		$this->assertSame( 1, (int) $rows[1]['is_change'] );
		$this->assertCount( 1, $this->mailed );
		$this->assertSame( 'p7@example.org', $this->mailed[0][0] );
		$this->assertStringNotContainsString( $first['key_id'], $this->mailed[0][2] );
		$this->assertContains( 'presence_device_revoked', $this->logged() );

		// The terminal's list now names the old key, and only by its id.
		$list = ( new AudiencePresenceRestController() )->revocations()->get_data();
		$this->assertSame( array( $first['key_id'] ), $list['revoked'] );
		$this->assertNotContains( $second['key_id'], $list['revoked'] );
		$this->assertSame( array( 'keys', 'revoked', 'generated' ), array_keys( $list ) );
		$this->assertStringNotContainsString( 'Maria', (string) wp_json_encode( $list ) );
	}

	public function test_a_new_key_on_the_same_device_is_not_a_change(): void {
		$this->enrol( 7, 'device-aaaaaaaaaaaa', EcdsaP256::generate() );
		$this->enrol( 7, 'device-aaaaaaaaaaaa', EcdsaP256::generate(), self::NOW + 60 );

		$this->assertSame( 0, (int) array_values( $this->tables->devices )[1]['is_change'] );
		$this->assertSame( array(), $this->mailed, 'the presence did not move to another phone, so nothing is mailed' );
	}

	public function test_a_device_reserved_to_another_account_is_refused_and_leaves_a_request(): void {
		$this->enrol( 7, 'device-aaaaaaaaaaaa', EcdsaP256::generate() );

		$refused = $this->enrol( 8, 'device-aaaaaaaaaaaa', EcdsaP256::generate(), self::NOW + self::DAY );

		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertSame( 'ffc_presence_device_reserved', $refused->get_error_code() );
		$this->assertSame( 403, $refused->get_error_data()['status'] );
		$this->assertCount( 1, $this->tables->devices );
		$this->assertCount( 1, $this->tables->requests );
		$this->assertSame( AudiencePresenceEnrolment::REASON_RESERVED, array_values( $this->tables->requests )[0]['reason'] );
		$this->assertContains( 'presence_enrolment_refused', $this->logged() );

		// Asking again does not pile up requests.
		$this->enrol( 8, 'device-aaaaaaaaaaaa', EcdsaP256::generate(), self::NOW + self::DAY + 60 );
		$this->assertCount( 1, $this->tables->requests );
	}

	public function test_a_reservation_ends_30_days_after_the_device_was_last_used_or_when_released(): void {
		$this->enrol( 7, 'device-aaaaaaaaaaaa', EcdsaP256::generate() );
		$this->enrol( 7, 'device-bbbbbbbbbbbb', EcdsaP256::generate(), self::NOW + self::DAY );

		$this->assertInstanceOf( \WP_Error::class, $this->enrol( 8, 'device-aaaaaaaaaaaa', EcdsaP256::generate(), self::NOW + 30 * self::DAY ) );
		$this->assertIsArray( $this->enrol( 8, 'device-aaaaaaaaaaaa', EcdsaP256::generate(), self::NOW + 32 * self::DAY ) );

		// Still active elsewhere, but released by an administrator.
		$this->tables->devices[2]['released_at'] = self::NOW + 33 * self::DAY;
		$this->assertIsArray( $this->enrol( 9, 'device-bbbbbbbbbbbb', EcdsaP256::generate(), self::NOW + 33 * self::DAY ) );
	}

	public function test_a_fourth_change_in_30_days_needs_an_approval_and_the_approval_is_used_once(): void {
		$this->enrol( 7, 'device-000000000000', EcdsaP256::generate() );
		foreach ( array( 'device-111111111111', 'device-222222222222', 'device-333333333333' ) as $i => $device ) {
			$this->assertIsArray( $this->enrol( 7, $device, EcdsaP256::generate(), self::NOW + ( $i + 1 ) * 60 ) );
		}

		$refused = $this->enrol( 7, 'device-444444444444', EcdsaP256::generate(), self::NOW + 300 );
		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertSame( 'ffc_presence_change_limit', $refused->get_error_code() );
		$request = array_key_first( $this->tables->requests );
		$this->assertSame( AudiencePresenceEnrolment::REASON_CHANGE_LIMIT, $this->tables->requests[ $request ]['reason'] );

		// An administrator approves it (#2743): one activation goes through.
		$this->tables->requests[ $request ]['status'] = AudiencePresenceWriter::REQUEST_APPROVED;
		$this->assertIsArray( $this->enrol( 7, 'device-444444444444', EcdsaP256::generate(), self::NOW + 360 ) );
		$this->assertSame( AudiencePresenceWriter::REQUEST_USED, $this->tables->requests[ $request ]['status'] );
		$this->assertInstanceOf( \WP_Error::class, $this->enrol( 7, 'device-555555555555', EcdsaP256::generate(), self::NOW + 420 ) );

		// Changes older than the window no longer count.
		$this->assertIsArray( $this->enrol( 7, 'device-555555555555', EcdsaP256::generate(), self::NOW + 31 * self::DAY ) );
	}

	public function test_asking_twice_for_the_same_key_returns_a_fresh_attestation_and_writes_nothing(): void {
		$device = EcdsaP256::generate();
		$this->enrol( 7, 'device-aaaaaaaaaaaa', $device );
		$again = $this->enrol( 7, 'device-aaaaaaaaaaaa', $device, self::NOW + 60 );

		$this->assertIsArray( $again );
		$this->assertCount( 1, $this->tables->devices );
	}

	public function test_a_revoked_key_cannot_come_back(): void {
		$device = EcdsaP256::generate();
		$this->enrol( 7, 'device-aaaaaaaaaaaa', $device );
		$this->enrol( 7, 'device-bbbbbbbbbbbb', EcdsaP256::generate(), self::NOW + 60 );

		$back = $this->enrol( 7, 'device-aaaaaaaaaaaa', $device, self::NOW + 120 );
		$this->assertInstanceOf( \WP_Error::class, $back );
		$this->assertSame( 'ffc_presence_key_used', $back->get_error_code() );
	}

	public function test_a_proof_by_another_key_or_out_of_time_is_refused(): void {
		$device = EcdsaP256::generate();
		$other  = EcdsaP256::generate();
		$proof  = $this->proof( AudiencePresenceEnrolment::ENROL_DOMAIN . '7|device-aaaaaaaaaaaa|' . self::NOW, $other );

		$wrong_key = AudiencePresenceEnrolment::enrol( 7, 'device-aaaaaaaaaaaa', SigningKeys::b64( $device['public'] ), self::NOW, $proof, '', self::NOW );
		$this->assertSame( 'ffc_presence_bad_proof', $wrong_key->get_error_code() );

		$stale = $this->enrol( 7, 'device-aaaaaaaaaaaa', $device, self::NOW, self::NOW + AudiencePresenceEnrolment::PROOF_SKEW + 1 );
		$this->assertSame( 'ffc_presence_bad_proof', $stale->get_error_code() );

		// The proof names the account: one made for user 7 does not enrol user 8.
		$theirs = $this->proof( AudiencePresenceEnrolment::ENROL_DOMAIN . '7|device-aaaaaaaaaaaa|' . self::NOW, $device );
		$swap   = AudiencePresenceEnrolment::enrol( 8, 'device-aaaaaaaaaaaa', SigningKeys::b64( $device['public'] ), self::NOW, $theirs, '', self::NOW );
		$this->assertSame( 'ffc_presence_bad_proof', $swap->get_error_code() );
		$this->assertSame( array(), $this->tables->devices );
	}

	public function test_a_malformed_device_id_or_key_is_refused(): void {
		$this->assertSame( 'ffc_presence_bad_device', $this->enrol( 7, 'short', EcdsaP256::generate() )->get_error_code() );
		$bad = AudiencePresenceEnrolment::enrol( 7, 'device-aaaaaaaaaaaa', SigningKeys::b64( str_repeat( 'x', 65 ) ), self::NOW, 'x', '', self::NOW );
		$this->assertSame( 'ffc_presence_bad_key', $bad->get_error_code() );
	}

	public function test_shared_device_signals_raise_an_alert_and_never_block(): void {
		$this->enrol( 7, 'device-aaaaaaaaaaaa', EcdsaP256::generate(), self::NOW, null, 'same-school-phone' );
		$result = $this->enrol( 8, 'device-bbbbbbbbbbbb', EcdsaP256::generate(), self::NOW + 60, null, 'same-school-phone' );

		$this->assertIsArray( $result );
		$match = array_values( array_filter( ActivityLogBuffer::contexts(), static fn( array $e ): bool => 'presence_signals_match' === $e['action'] ) );
		$this->assertCount( 1, $match );
		$this->assertSame( 'warning', $match[0]['level'] );
		$this->assertSame( array( 'device-aaaaaaaaaaaa' ), $match[0]['context']['other_devices'] );
		$this->assertNotContains( 'same-school-phone', array_column( $this->tables->devices, 'signals_hash' ) );
	}

	// ---- Renewal ----------------------------------------------------------

	public function test_an_active_device_renews_and_a_replaced_one_does_not(): void {
		$device = EcdsaP256::generate();
		$first  = $this->enrol( 7, 'device-aaaaaaaaaaaa', $device );

		$renewed = $this->renew( 7, $first['key_id'], $device, self::NOW + 5 * self::DAY );
		$this->assertIsArray( $renewed );
		$this->assertSame( self::NOW + 5 * self::DAY + AudiencePresenceAttestation::TTL, $renewed['expires'] );

		$this->assertSame( 'ffc_presence_unknown_key', $this->renew( 8, $first['key_id'], $device, self::NOW )->get_error_code() );

		$this->enrol( 7, 'device-bbbbbbbbbbbb', EcdsaP256::generate(), self::NOW + 6 * self::DAY );
		$gone = $this->renew( 7, $first['key_id'], $device, self::NOW + 6 * self::DAY + 60 );
		$this->assertSame( 'ffc_presence_revoked', $gone->get_error_code() );
		$this->assertSame( 410, $gone->get_error_data()['status'] );
	}

	public function test_the_event_list_tells_a_replaced_phone_before_its_attestation_comes_due(): void {
		$device = EcdsaP256::generate();
		$first  = $this->enrol( 7, 'device-aaaaaaaaaaaa', $device );
		$this->assertIsArray( AudiencePresenceEnrolment::active_device( 7, $first['key_id'] ) );
		$this->assertSame( 'ffc_presence_unknown_key', AudiencePresenceEnrolment::active_device( 8, $first['key_id'] )->get_error_code(), 'another account\'s key' );
		$this->assertSame( 'ffc_presence_unknown_key', AudiencePresenceEnrolment::active_device( 7, '' )->get_error_code() );

		$this->enrol( 7, 'device-bbbbbbbbbbbb', EcdsaP256::generate(), self::NOW + 60 );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		$request = \Mockery::mock( 'WP_REST_Request' );
		$request->shouldReceive( 'get_param' )->with( 'key' )->andReturn( $first['key_id'] );
		$answer = ( new AudiencePresenceRestController() )->events( $request );
		$this->assertInstanceOf( \WP_Error::class, $answer, 'the old phone hears it on its next visit, not when its attestation comes due' );
		$this->assertSame( 'ffc_presence_revoked', $answer->get_error_code() );
		$this->assertSame( 410, $answer->get_error_data()['status'] );
	}

	// ---- Who may call -----------------------------------------------------

	public function test_a_user_agent_is_read_as_two_families_only(): void {
		$cases = array(
			'Mozilla/5.0 (Linux; Android 14; SM-A546E) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Mobile Safari/537.36' => array( 'Android', 'Chrome' ),
			'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1' => array( 'iOS', 'Safari' ),
			'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/26.0 Chrome/122.0 Mobile Safari/537.36' => array( 'Android', 'Samsung Internet' ),
			'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36 Edg/129.0' => array( 'Windows', 'Edge' ),
			'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) FxiOS/132.0 Mobile/15E148 Safari/605.1.15' => array( 'iOS', 'Firefox' ),
			'curl/8.5' => array( '', '' ),
		);
		foreach ( $cases as $agent => $expected ) {
			$this->assertSame( array( 'platform' => $expected[0], 'browser' => $expected[1] ), AudiencePresenceAgent::describe( $agent ), $agent );
		}
		$this->assertSame( 'Android · Chrome', AudiencePresenceAgent::label( 'Android', 'Chrome' ) );
		$this->assertSame( 'iOS', AudiencePresenceAgent::label( 'iOS', '' ) );
		$this->assertSame( '', AudiencePresenceAgent::label( '', '' ) );
	}

	public function test_the_status_names_the_active_device_and_the_change_budget(): void {
		$empty = AudiencePresenceEnrolment::status( 7, self::NOW );
		$this->assertNull( $empty['current'] );
		$this->assertSame( 0, $empty['changes'] );

		$android = 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Mobile Safari/537.36';
		$this->enrol( 7, 'device-aaaaaaaaaaaa', EcdsaP256::generate() );
		$this->enrol( 7, 'device-bbbbbbbbbbbb', EcdsaP256::generate(), self::NOW + 60, null, '', $android );

		$status = AudiencePresenceEnrolment::status( 7, self::NOW + 120 );
		$this->assertSame( 'device-bbbbbbbbbbbb', $status['current']['device_id'] );
		$this->assertSame( 'Android · Chrome', $status['current']['label'] );
		$this->assertSame( 1, $status['changes'] );
		$this->assertSame( 3, $status['max_changes'] );
		$this->assertSame( 30, $status['window_days'] );
		$this->assertSame( 7, $status['offline_days'] );
	}

	public function test_the_app_routes_need_a_login_the_rest_nonce_and_the_participant_capability(): void {
		$controller = new AudiencePresenceRestController();
		$nonce      = null;
		$request    = \Mockery::mock( 'WP_REST_Request' );
		$request->shouldReceive( 'get_header' )->with( 'X-WP-Nonce' )->andReturnUsing( static function () use ( &$nonce ) {
			return $nonce;
		} );
		$request->shouldReceive( 'get_header' )->with( 'X-FFC-Terminal-Queue' )->andReturn( '' );
		$request->shouldReceive( 'get_header' )->with( 'X-FFC-Terminal' )->andReturn( null );
		$logged_in = true;
		$caps      = array( 'ffc_view_own_audience_bookings' );
		Functions\when( 'is_user_logged_in' )->alias( static function () use ( &$logged_in ) {
			return $logged_in;
		} );
		Functions\when( 'current_user_can' )->alias( static function ( $cap ) use ( &$caps ) {
			return in_array( $cap, $caps, true );
		} );
		Functions\when( 'wp_verify_nonce' )->alias( static fn( $nonce, $action ) => 'good' === $nonce && 'wp_rest' === $action ? 1 : false );

		$this->assertFalse( $controller->check_app_permission( $request ) );
		$nonce = 'bad';
		$this->assertFalse( $controller->check_app_permission( $request ) );
		$nonce = 'good';
		$this->assertTrue( $controller->check_app_permission( $request ) );

		$caps = array();
		$this->assertFalse( $controller->check_app_permission( $request ) );
		$logged_in = false;
		$caps      = array( 'ffc_view_own_audience_bookings' );
		$this->assertFalse( $controller->check_app_permission( $request ) );

		$logged_in = true;
		$this->assertFalse( $controller->check_terminal_permission( $request ) );
		$caps = array( 'ffc_checkin_audiences' );
		$this->assertTrue( $controller->check_terminal_permission( $request ) );
	}

	// ---- Helpers ----------------------------------------------------------

	/**
	 * Enrol as the app would: sign the statement with the device key.
	 *
	 * @param int                                       $user    User.
	 * @param string                                    $device  Device id.
	 * @param array{public: string, secret: string}     $key     Device key.
	 * @param int                                       $signed  When the app signed.
	 * @param int|null                                  $now     Server time; the signing time by default.
	 * @param string                                    $signals Device signals.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function enrol( int $user, string $device, array $key, int $signed = self::NOW, ?int $now = null, string $signals = '', string $agent = '' ) {
		$proof = $this->proof( AudiencePresenceEnrolment::ENROL_DOMAIN . $user . '|' . $device . '|' . $signed, $key );
		return AudiencePresenceEnrolment::enrol( $user, $device, SigningKeys::b64( $key['public'] ), $signed, $proof, $signals, $now ?? $signed, $agent );
	}

	/**
	 * Renew as the app would.
	 *
	 * @param int                                   $user   User.
	 * @param string                                $key_id Device key id.
	 * @param array{public: string, secret: string} $key    Device key.
	 * @param int                                   $now    Unix time.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function renew( int $user, string $key_id, array $key, int $now ) {
		$proof = $this->proof( AudiencePresenceEnrolment::RENEW_DOMAIN . $user . '|' . $key_id . '|' . $now, $key );
		return AudiencePresenceEnrolment::renew( $user, $key_id, $now, $proof, $now );
	}

	/**
	 * A device signature of a statement, base64url.
	 *
	 * @param string                                $statement Statement.
	 * @param array{public: string, secret: string} $key       Device key.
	 */
	private function proof( string $statement, array $key ): string {
		return SigningKeys::b64( (string) EcdsaP256::sign( $statement, $key['secret'] ) );
	}

	/**
	 * The actions written to the activity log, in order.
	 *
	 * @return list<string>
	 */
	private function logged(): array {
		return array_column( ActivityLogBuffer::contexts(), 'action' );
	}
}
