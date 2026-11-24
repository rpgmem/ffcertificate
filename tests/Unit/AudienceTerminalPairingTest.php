<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Audience\AudienceAdminTerminals;
use FreeFormCertificate\Audience\AudienceOperatorCode;
use FreeFormCertificate\Audience\AudienceTerminalLockout;
use FreeFormCertificate\Audience\AudienceTerminalPairing;
use FreeFormCertificate\Audience\AudienceTerminalReader;
use FreeFormCertificate\Audience\AudienceTerminalRestController;
use FreeFormCertificate\Audience\AudienceTerminalWriter;
use FreeFormCertificate\Core\Base45;
use FreeFormCertificate\Tests\Support\ActivityLogBuffer;
use FreeFormCertificate\Tests\Support\FakeCredentials;
use FreeFormCertificate\Tests\Support\FakeTerminalTables;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Terminal pairing (#2744): the one-time `P-` codes, the lockout counted on
 * the server, the token a terminal keeps, ending a terminal, the operator's
 * unlock code and who may call each route; and the event queue (#2746):
 * check-in windows that may not cross, changing the events of a terminal
 * that is already paired, and what the terminal shows.
 *
 * @covers \FreeFormCertificate\Audience\AudienceTerminalPairing
 * @covers \FreeFormCertificate\Audience\AudienceTerminalLockout
 * @covers \FreeFormCertificate\Audience\AudienceTerminalReader
 * @covers \FreeFormCertificate\Audience\AudienceTerminalWriter
 * @covers \FreeFormCertificate\Audience\AudienceOperatorCode
 * @covers \FreeFormCertificate\Audience\AudienceTerminalRestController
 * @covers \FreeFormCertificate\Audience\AudienceTerminalSchedule
 * @covers \FreeFormCertificate\Audience\AudienceAdminTerminals
 */
class AudienceTerminalPairingTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const NOW = 1800000000;

	private const OPERATOR = 5;

	private const DEVICE = 'terminal-device-0001';

	private const ADDRESS = 'address-fingerprint-0001';

	private FakeTerminalTables $tables;

	/** @var array<string, mixed> */
	private array $options = array();

	/** @var array<string, mixed> */
	private array $transients = array();

	/** @var array<int, object> */
	private array $bookings = array();

	/** @var mixed */
	private $wpdb_backup;

	private bool $had_wpdb = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->tables  = new FakeTerminalTables();
		$this->options = array( 'ffc_settings' => ActivityLogBuffer::ENABLED_SETTINGS );
		ActivityLogBuffer::stub_functions();
		ActivityLogBuffer::reset();

		Functions\when( '__' )->returnArg();
		Functions\when( '_n' )->alias( static fn( $single, $plural, $n ) => 1 === (int) $n ? $single : $plural );
		Functions\when( 'sanitize_text_field' )->alias( static fn( $s ) => trim( strip_tags( (string) $s ) ) );
		Functions\when( 'wp_json_encode' )->alias( static fn( $v ) => json_encode( $v ) );
		Functions\when( 'wp_salt' )->justReturn( FakeCredentials::WP_SALT );
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
		Functions\when( 'wp_rand' )->alias( static fn( $min = 0, $max = 0 ) => random_int( (int) $min, (int) $max ) );
		Functions\when( 'wp_date' )->alias( static fn( $format, $ts = null ) => gmdate( $format, $ts ?? time() ) );
		Functions\when( 'date_i18n' )->alias( static fn( $format, $ts = null ) => gmdate( $format, $ts ?? time() ) );
		Functions\when( 'apply_filters' )->alias( static fn( $hook, $value ) => $value );
		Functions\when( 'get_option' )->alias(
			fn( $key, $default = false ) => array_key_exists( $key, $this->options ) ? $this->options[ $key ] : $default
		);
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) {
				$this->options[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'get_transient' )->alias( fn( $key ) => $this->transients[ $key ] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value ) {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( $key ) {
				unset( $this->transients[ $key ] );
				return true;
			}
		);
		// The booking reader looks in the object cache first; serving the
		// bookings from there keeps its own queries out of this test.
		Functions\when( 'wp_cache_get' )->alias(
			fn( $key ) => 1 === preg_match( '/^id_(\d+)$/', (string) $key, $m ) && isset( $this->bookings[ (int) $m[1] ] ) ? $this->bookings[ (int) $m[1] ] : false
		);

		$this->had_wpdb = array_key_exists( 'wpdb', $GLOBALS );
		if ( $this->had_wpdb ) {
			$this->wpdb_backup = $GLOBALS['wpdb'];
		}
		$GLOBALS['wpdb'] = $this->tables;

		$this->booking( 11, '2027-01-15' );
		$this->booking( 12, '2027-01-16' );
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

	// ---- Codes ------------------------------------------------------------

	public function test_a_code_is_a_p_code_stored_only_as_a_keyed_hash_for_five_minutes(): void {
		$result = AudienceTerminalPairing::create_code( self::OPERATOR, array( 11, '12', 11, -3 ), ' Entrance <b>A</b> ', self::NOW );

		$this->assertIsArray( $result );
		$this->assertMatchesRegularExpression( '/^P-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $result['code'] );
		$this->assertSame( self::NOW + AudienceTerminalPairing::CODE_TTL, $result['expires_at'] );
		$this->assertSame( array( 11, 12 ), $result['bookings'] );

		$row = array_values( $this->tables->pairings )[0];
		$this->assertSame( AudienceTerminalPairing::code_hash( $result['code'] ), $row['code_hash'] );
		$this->assertStringNotContainsString( substr( $result['code'], 2, 4 ), json_encode( $row ), 'the code itself is never stored' );
		$this->assertSame( 'Entrance A', $row['label'] );

		$log = ActivityLogBuffer::contexts();
		$this->assertSame( 'terminal_pairing_created', $log[0]['action'] );
		$this->assertStringNotContainsString( substr( $result['code'], 2, 4 ), json_encode( $log ), 'nor logged' );
	}

	public function test_a_code_reads_the_same_in_any_case_with_or_without_its_prefix_and_hyphens(): void {
		$hash = AudienceTerminalPairing::code_hash( 'P-AB12-CD34-EF56' );
		$this->assertSame( $hash, AudienceTerminalPairing::code_hash( 'ab12cd34ef56' ) );
		$this->assertSame( $hash, AudienceTerminalPairing::code_hash( 'p ab12 cd34 ef56' ) );
		$this->assertNotSame( $hash, AudienceTerminalPairing::code_hash( 'P-AB12-CD34-EF57' ) );
	}

	public function test_a_code_needs_active_bookings_that_end_within_seven_days(): void {
		$this->assertSame( 'ffc_terminal_no_bookings', AudienceTerminalPairing::create_code( self::OPERATOR, array(), '', self::NOW )->get_error_code() );
		$this->assertSame( 'ffc_terminal_too_many', AudienceTerminalPairing::create_code( self::OPERATOR, range( 1, 11 ), '', self::NOW )->get_error_code() );
		$this->assertSame( 'ffc_terminal_booking', AudienceTerminalPairing::create_code( self::OPERATOR, array( 99 ), '', self::NOW )->get_error_code(), 'no such booking' );

		$this->booking( 13, '2027-01-15', 'cancelled' );
		$this->assertSame( 'ffc_terminal_booking', AudienceTerminalPairing::create_code( self::OPERATOR, array( 13 ), '', self::NOW )->get_error_code(), 'cancelled' );
		$this->booking( 14, '2026-12-01' );
		$this->assertSame( 'ffc_terminal_booking', AudienceTerminalPairing::create_code( self::OPERATOR, array( 14 ), '', self::NOW )->get_error_code(), 'already over' );
		$this->booking( 15, '2027-03-01' );
		$this->assertSame( 'ffc_terminal_booking', AudienceTerminalPairing::create_code( self::OPERATOR, array( 15 ), '', self::NOW )->get_error_code(), 'too far ahead' );
		$this->assertSame( array(), $this->tables->pairings );
	}

	public function test_an_event_with_the_terminal_path_off_cannot_be_held(): void {
		$this->booking( 16, '2027-01-15' );
		$this->bookings[16]->checkin_terminal = '0';

		$this->assertSame( 'ffc_terminal_path_off', AudienceTerminalPairing::create_code( self::OPERATOR, array( 16 ), '', self::NOW )->get_error_code() );
		$this->assertSame( array(), $this->tables->pairings );
	}

	// ---- Pairing ----------------------------------------------------------

	public function test_a_code_pairs_once_and_the_terminal_keeps_a_token_the_site_only_hashes(): void {
		$code   = AudienceTerminalPairing::create_code( self::OPERATOR, array( 11, 12 ), 'Entrance', self::NOW )['code'];
		$result = AudienceTerminalPairing::pair( strtolower( $code ), self::DEVICE, self::ADDRESS, self::NOW + 60 );

		$this->assertIsArray( $result );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $result['token'] );
		$this->assertSame( array( 11, 12 ), $result['bookings'] );
		$this->assertSame( 'Entrance', $result['label'] );
		$this->assertSame( AudienceTerminalPairing::booking_end( 12 ) + \FreeFormCertificate\Audience\AudienceCheckinSettings::auto_end_seconds(), $result['expires_at'], 'two hours after the last booking ends' );

		$terminal = AudienceTerminalReader::terminal( $result['terminal_id'] );
		$this->assertSame( hash( 'sha256', $result['token'] ), $terminal['token_hash'] );
		$this->assertSame( self::OPERATOR, $terminal['paired_by'], 'attributed to the operator who generated the code' );
		$this->assertStringNotContainsString( $result['token'], json_encode( $this->tables->terminals ) );
		$this->assertSame( $result['terminal_id'], array_values( $this->tables->pairings )[0]['terminal_id'] );

		$this->assertSame( 'terminal_paired', ActivityLogBuffer::contexts()[1]['action'] );
		$this->assertStringNotContainsString( $result['token'], json_encode( ActivityLogBuffer::contexts() ) );

		$again = AudienceTerminalPairing::pair( $code, 'another-terminal-0002', self::ADDRESS, self::NOW + 61 );
		$this->assertSame( 'ffc_terminal_code_wrong', $again->get_error_code(), 'single use' );
	}

	public function test_an_operator_chooses_how_long_after_the_last_event_the_terminal_ends(): void {
		$code   = AudienceTerminalPairing::create_code( self::OPERATOR, array( 11, 12 ), '', self::NOW, 4 )['code'];
		$result = AudienceTerminalPairing::pair( $code, self::DEVICE, self::ADDRESS, self::NOW + 60 );

		$this->assertSame( AudienceTerminalPairing::booking_end( 12 ) + 4 * HOUR_IN_SECONDS, $result['expires_at'] );
		$this->assertSame( 4, array_values( $this->tables->pairings )[0]['auto_end_hours'] );
		$this->assertSame( 4, AudienceTerminalReader::terminal( $result['terminal_id'] )['auto_end_hours'], 'kept for a later change of events' );

		$this->assertNull( AudienceTerminalPairing::clean_auto_end( null ) );
		$this->assertNull( AudienceTerminalPairing::clean_auto_end( 0 ), 'no choice follows the Check-in settings' );
		$this->assertSame( \FreeFormCertificate\Audience\AudienceCheckinSettings::AUTO_END_HOURS_MAX, AudienceTerminalPairing::clean_auto_end( 99 ) );
		$this->assertSame(
			AudienceTerminalPairing::booking_end( 11 ) + \FreeFormCertificate\Audience\AudienceCheckinSettings::auto_end_seconds(),
			AudienceTerminalPairing::expiry( array( 11 ), self::NOW, null )
		);
	}

	public function test_an_expired_code_does_not_pair(): void {
		$code = AudienceTerminalPairing::create_code( self::OPERATOR, array( 11 ), '', self::NOW )['code'];
		$late = AudienceTerminalPairing::pair( $code, self::DEVICE, self::ADDRESS, self::NOW + AudienceTerminalPairing::CODE_TTL + 1 );
		$this->assertSame( 'ffc_terminal_code_wrong', $late->get_error_code() );
		$this->assertSame( 403, $late->get_error_data()['status'] );
	}

	public function test_a_terminal_without_a_device_id_is_refused_before_anything_counts(): void {
		$this->assertSame( 'ffc_terminal_device', AudienceTerminalPairing::pair( 'P-AAAA-BBBB-CCCC', 'short', self::ADDRESS, self::NOW )->get_error_code() );
		$this->assertSame( array(), $this->transients );
	}

	// ---- Lockout ----------------------------------------------------------

	public function test_three_wrong_codes_lock_the_device_for_fifteen_minutes_then_each_one_for_thirty(): void {
		for ( $i = 1; $i <= 2; $i++ ) {
			$this->assertSame( 'ffc_terminal_code_wrong', $this->wrong( self::NOW + $i )->get_error_code() );
		}
		$third = $this->wrong( self::NOW + 3 );
		$this->assertSame( 'ffc_terminal_locked', $third->get_error_code() );
		$this->assertSame( 429, $third->get_error_data()['status'] );
		$this->assertSame( AudienceTerminalLockout::FIRST_LOCK, $third->get_error_data()['retry_after'] );
		$this->assertStringContainsString( '15 minutes', $third->get_error_message() );
		$this->assertStringNotContainsString( 'WRONG', $third->get_error_message(), 'never echoes the code typed' );

		// A correct code is refused while the lock holds.
		$code = AudienceTerminalPairing::create_code( self::OPERATOR, array( 11 ), '', self::NOW )['code'];
		$this->assertSame( 'ffc_terminal_locked', AudienceTerminalPairing::pair( $code, self::DEVICE, self::ADDRESS, self::NOW + 600 )->get_error_code() );

		$fourth = $this->wrong( self::NOW + 3 + AudienceTerminalLockout::FIRST_LOCK );
		$this->assertSame( AudienceTerminalLockout::NEXT_LOCK, $fourth->get_error_data()['retry_after'], 'each further error: 30 minutes' );
		$fifth = $this->wrong( self::NOW + 3 + AudienceTerminalLockout::FIRST_LOCK + AudienceTerminalLockout::NEXT_LOCK );
		$this->assertSame( AudienceTerminalLockout::NEXT_LOCK, $fifth->get_error_data()['retry_after'], 'capped at 30' );

		$actions = array_column( ActivityLogBuffer::contexts(), 'action' );
		$this->assertContains( 'terminal_pairing_locked', $actions );
		$this->assertContains( 'terminal_pairing_refused', $actions );
	}

	public function test_a_correct_pairing_or_an_hour_of_quiet_resets_the_count(): void {
		$this->wrong( self::NOW );
		$this->wrong( self::NOW + 1 );
		$code = AudienceTerminalPairing::create_code( self::OPERATOR, array( 11 ), '', self::NOW )['code'];
		$this->assertIsArray( AudienceTerminalPairing::pair( $code, self::DEVICE, self::ADDRESS, self::NOW + 2 ) );
		$this->assertSame( 'ffc_terminal_code_wrong', $this->wrong( self::NOW + 3 )->get_error_code(), 'counting starts over after a pairing' );
		$this->assertSame( 'ffc_terminal_code_wrong', $this->wrong( self::NOW + 4 )->get_error_code() );

		$later = self::NOW + 4 + AudienceTerminalLockout::RESET_AFTER;
		$this->assertSame( 'ffc_terminal_code_wrong', $this->wrong( $later )->get_error_code(), 'an hour of quiet forgets the two' );
		$this->assertSame( 'ffc_terminal_code_wrong', $this->wrong( $later + 1 )->get_error_code() );
		$this->assertSame( 'ffc_terminal_locked', $this->wrong( $later + 2 )->get_error_code() );
	}

	public function test_one_address_may_send_twenty_wrong_codes_an_hour_across_every_device(): void {
		$start = intdiv( self::NOW, AudienceTerminalLockout::ADDRESS_WINDOW ) * AudienceTerminalLockout::ADDRESS_WINDOW;
		for ( $i = 1; $i < AudienceTerminalLockout::ADDRESS_MAX; $i++ ) {
			$result = AudienceTerminalPairing::pair( 'P-WRON-GCOD-E000', 'device-number-' . str_pad( (string) $i, 4, '0', STR_PAD_LEFT ), self::ADDRESS, $start + $i );
			$this->assertSame( 'ffc_terminal_code_wrong', $result->get_error_code(), "device $i" );
		}
		$last = AudienceTerminalPairing::pair( 'P-WRON-GCOD-E000', 'device-number-0020', self::ADDRESS, $start + 20 );
		$this->assertSame( 'ffc_terminal_locked', $last->get_error_code() );
		$this->assertSame( AudienceTerminalLockout::ADDRESS_WINDOW - 20, $last->get_error_data()['retry_after'], 'until the hour turns' );

		$fresh = AudienceTerminalPairing::pair( 'P-WRON-GCOD-E000', 'brand-new-device-0021', self::ADDRESS, $start + 30 );
		$this->assertSame( 'ffc_terminal_locked', $fresh->get_error_code(), 'a new device id still meets the wall' );
		$other = AudienceTerminalPairing::pair( 'P-WRON-GCOD-E000', 'brand-new-device-0021', 'another-address', $start + 30 );
		$this->assertSame( 'ffc_terminal_code_wrong', $other->get_error_code(), 'other addresses are not affected' );
	}

	// ---- Limits -----------------------------------------------------------

	public function test_three_terminals_per_event_and_ten_per_site(): void {
		for ( $i = 0; $i < 3; $i++ ) {
			$this->paired( array( 11 ), 'device-for-eleven-' . $i );
		}
		$this->assertSame( 'ffc_terminal_booking_limit', AudienceTerminalPairing::create_code( self::OPERATOR, array( 11 ), '', self::NOW )->get_error_code() );

		// A code made before the limit was reached refuses at pairing, unspent.
		$code = AudienceTerminalPairing::create_code( self::OPERATOR, array( 12 ), '', self::NOW )['code'];
		Functions\when( 'apply_filters' )->alias(
			static fn( $hook, $value ) => 'ffc_terminal_limits' === $hook ? array(
				'site'    => 3,
				'booking' => 3,
			) : $value
		);
		$this->assertSame( 'ffc_terminal_site_limit', AudienceTerminalPairing::pair( $code, self::DEVICE, self::ADDRESS, self::NOW + 5 )->get_error_code() );
		$this->assertNull( array_values( $this->tables->pairings )[3]['used_at'], 'still usable once a terminal ends' );

		$this->assertSame(
			array(
				'site'    => 3,
				'booking' => 3,
			),
			AudienceTerminalPairing::limits()
		);
	}

	// ---- Tokens and ending ------------------------------------------------

	public function test_a_token_authenticates_until_its_terminal_ends_or_expires(): void {
		$paired = $this->paired( array( 11 ), self::DEVICE );
		$this->assertSame( $paired['terminal_id'], AudienceTerminalPairing::authenticate( $paired['token'], self::NOW + 100 )['id'] );
		$this->assertSame( self::NOW + 100, $this->tables->terminals[ $paired['terminal_id'] ]['last_seen_at'] );
		$this->assertNull( AudienceTerminalPairing::authenticate( 'not-a-token', self::NOW ) );
		$this->assertNull( AudienceTerminalPairing::authenticate( str_repeat( 'a', 64 ), self::NOW ) );
		$this->assertNull( AudienceTerminalPairing::authenticate( $paired['token'], $paired['expires_at'] ), 'expired' );

		$this->assertSame( 'ffc_terminal_missing', AudienceTerminalPairing::revoke( $paired['terminal_id'], 99, false, self::NOW )->get_error_code(), 'an operator ends only their own' );
		$this->assertTrue( AudienceTerminalPairing::revoke( $paired['terminal_id'], self::OPERATOR, false, self::NOW + 200 ) );
		$this->assertNull( AudienceTerminalPairing::authenticate( $paired['token'], self::NOW + 201 ) );
		$this->assertSame( 'ffc_terminal_ended', AudienceTerminalPairing::revoke( $paired['terminal_id'], 1, true, self::NOW + 300 )->get_error_code() );
		$this->assertSame( 'terminal_revoked', ActivityLogBuffer::contexts()[ count( ActivityLogBuffer::contexts() ) - 1 ]['action'] );
	}

	public function test_an_administrator_ends_any_terminal_and_lists_are_per_operator(): void {
		$mine   = $this->paired( array( 11 ), self::DEVICE );
		$theirs = AudienceTerminalPairing::pair( AudienceTerminalPairing::create_code( 8, array( 12 ), '', self::NOW )['code'], 'second-device-0002', self::ADDRESS, self::NOW + 2 );

		$this->assertSame( array( $mine['terminal_id'] ), array_column( AudienceTerminalReader::active_terminals( self::NOW + 3, self::OPERATOR ), 'id' ) );
		$this->assertCount( 2, AudienceTerminalReader::active_terminals( self::NOW + 3 ) );
		$this->assertTrue( AudienceTerminalPairing::revoke( $theirs['terminal_id'], 1, true, self::NOW + 4 ) );
		$this->assertSame( 2, AudienceTerminalWriter::purge_pairings( self::NOW + AudienceTerminalPairing::CODE_TTL + 1 ), 'spent or expired codes go' );
		$this->assertSame( array(), $this->tables->pairings );
	}

	// ---- Operator code ----------------------------------------------------

	public function test_an_operator_code_is_signed_by_the_site_and_expires(): void {
		$issued = AudienceOperatorCode::issue( self::OPERATOR, self::NOW );
		$this->assertIsArray( $issued );
		$this->assertStringStartsWith( 'FFO1', $issued['code'] );
		$this->assertSame( 1, preg_match( '/^[0-9A-Z $%*+\-.\/:]+$/', $issued['code'] ), 'QR alphanumeric set' );

		$read = AudienceOperatorCode::verify( $issued['code'], self::NOW + 30 );
		$this->assertSame( self::OPERATOR, $read['user_id'] );
		$this->assertSame( 'ffc_operator_code_expired', AudienceOperatorCode::verify( $issued['code'], $issued['expires_at'] + AudienceOperatorCode::SKEW + 1 )->get_error_code() );
		$this->assertIsArray( AudienceOperatorCode::verify( $issued['code'], $issued['expires_at'] + AudienceOperatorCode::SKEW ), 'clock tolerance' );

		$raw    = Base45::decode( substr( $issued['code'], 4 ) );
		$forged = substr( $raw, 0, -70 ) . pack( 'N', 1 ) . substr( $raw, -66 );
		$this->assertSame( 'ffc_operator_code_signature', AudienceOperatorCode::verify( 'FFO1' . Base45::encode( $forged ), self::NOW )->get_error_code(), 'another user id under the same signature' );
		$this->assertSame( 'ffc_operator_code_malformed', AudienceOperatorCode::verify( 'FFP1ABC', self::NOW )->get_error_code() );
		$this->assertNull( AudienceOperatorCode::issue( 0, self::NOW ) );
	}

	// ---- Routes -----------------------------------------------------------

	public function test_pairing_routes_need_the_terminals_capability_and_the_rest_nonce(): void {
		$controller = new AudienceTerminalRestController();
		$headers    = array( 'X-WP-Nonce' => 'good' );
		$request    = \Mockery::mock( 'WP_REST_Request' );
		$request->shouldReceive( 'get_header' )->andReturnUsing(
			static function ( $name ) use ( &$headers ) {
				return $headers[ $name ] ?? null;
			}
		);
		$caps = array( AudienceTerminalPairing::CAP );
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'current_user_can' )->alias(
			static function ( $cap ) use ( &$caps ) {
				return in_array( $cap, $caps, true );
			}
		);
		Functions\when( 'wp_verify_nonce' )->alias( static fn( $nonce ) => 'good' === $nonce ? 1 : false );

		$this->assertTrue( $controller->check_manage_permission( $request ) );
		$this->assertFalse( $controller->check_operator_permission( $request ), 'the unlock code is for whoever runs check-ins' );
		$caps = array( 'ffc_checkin_audiences' );
		$this->assertFalse( $controller->check_manage_permission( $request ), 'running check-ins is not enough to pair' );
		$this->assertTrue( $controller->check_operator_permission( $request ) );
		$headers = array();
		$this->assertFalse( $controller->check_operator_permission( $request ), 'no nonce' );
		$this->assertTrue( $controller->check_pair_permission(), 'a terminal has no account' );
	}

	public function test_a_terminal_token_opens_the_revocation_list(): void {
		$paired  = $this->paired( array( 11 ), self::DEVICE );
		$token   = $paired['token'];
		$request = \Mockery::mock( 'WP_REST_Request' );
		$request->shouldReceive( 'get_header' )->with( 'X-FFC-Terminal-Queue' )->andReturn( '' );
		$request->shouldReceive( 'get_header' )->with( 'X-FFC-Terminal' )->andReturnUsing( static function () use ( &$token ) {
			return $token;
		} );
		Functions\when( 'is_user_logged_in' )->justReturn( false );

		$presence = new \FreeFormCertificate\Audience\AudiencePresenceRestController();
		$this->assertTrue( $presence->check_terminal_permission( $request ) );
		$token = str_repeat( 'b', 64 );
		$this->assertFalse( $presence->check_terminal_permission( $request ) );
	}

	public function test_the_list_never_shows_a_token_or_device_hash(): void {
		$paired   = $this->paired( array( 11 ), self::DEVICE );
		$terminal = AudienceTerminalReader::terminal( $paired['terminal_id'] );
		$shown    = AudienceTerminalRestController::present( $terminal );
		$this->assertArrayNotHasKey( 'token_hash', $shown );
		$this->assertArrayNotHasKey( 'device_hash', $shown );
		$this->assertSame( array( 3, 7 ), AudienceTerminalRestController::sanitize_ids( array( '3', 7, 'x', -1, 3 ) ) );
		$this->assertSame( 'P-AB12-CD34script', AudienceTerminalRestController::sanitize_code( 'P-AB12-CD34<script>' ) );
		$this->assertSame( '', AudienceTerminalRestController::sanitize_code( array( 'P' ) ) );
	}

	public function test_the_event_list_offers_bookings_still_running_with_their_terminal_count(): void {
		$now = time();
		Functions\when( 'wp_parse_args' )->alias( static fn( $args, $defaults ) => array_merge( $defaults, $args ) );
		$soon = gmdate( 'Y-m-d', $now + DAY_IN_SECONDS );
		$past = gmdate( 'Y-m-d', $now - 3 * DAY_IN_SECONDS );
		$this->booking( 21, $soon );
		$this->booking( 22, $past );
		$this->bookings[21]->start_time = '09:00:00';
		$this->tables->booking_list     = array(
			(object) array( 'id' => 21, 'description' => 'Workshop', 'environment_name' => 'Room 1', 'booking_date' => $soon, 'end_date' => null, 'start_time' => '09:00:00', 'end_time' => '17:00:00' ),
			(object) array( 'id' => 22, 'description' => 'Gone', 'environment_name' => 'Room 2', 'booking_date' => $past, 'end_date' => null, 'start_time' => '09:00:00', 'end_time' => '17:00:00' ),
			(object) array( 'id' => 23, 'description' => 'No terminals', 'environment_name' => 'Room 3', 'booking_date' => $soon, 'end_date' => null, 'start_time' => '09:00:00', 'end_time' => '17:00:00', 'checkin_terminal' => '0' ),
		);

		$data = ( new AudienceTerminalRestController() )->list_bookings()->get_data();

		$this->assertSame( AudienceTerminalPairing::MAX_BOOKINGS, $data['max'] );
		$this->assertSame( array( 21 ), array_column( $data['bookings'], 'id' ), 'a booking that already ended, or takes no terminal, is not offered' );
		$this->assertSame( 'Workshop', $data['bookings'][0]['description'] );
		$this->assertSame( 'Room 1', $data['bookings'][0]['place'] );
		$this->assertSame( 0, $data['bookings'][0]['terminals'] );
		$this->assertFalse( $data['bookings'][0]['full'] );
		$this->assertNotSame( '', $data['bookings'][0]['when'] );
	}

	public function test_the_list_carries_its_dates_formatted_by_the_site(): void {
		$paired   = $this->paired( array( 11 ), self::DEVICE );
		$terminal = AudienceTerminalReader::terminal( $paired['terminal_id'] );
		$shown    = AudienceTerminalRestController::present( $terminal );

		$this->assertNotSame( '', $shown['paired_label'] );
		$this->assertNotSame( '', $shown['expires_label'] );
		$this->assertSame( '', $shown['last_seen_label'], 'a terminal not used yet has no last read' );
	}

	public function test_an_operator_code_unlocks_the_menu_and_ends_the_terminal_in_that_operators_name(): void {
		$paired  = $this->paired( array( 11 ), self::DEVICE );
		$token   = $paired['token'];
		$params  = array();
		$request = \Mockery::mock( 'WP_REST_Request' );
		$request->shouldReceive( 'get_header' )->with( 'X-FFC-Terminal-Queue' )->andReturn( '' );
		$request->shouldReceive( 'get_header' )->with( 'X-FFC-Terminal' )->andReturnUsing( static function () use ( &$token ) {
			return $token;
		} );
		$request->shouldReceive( 'get_param' )->andReturnUsing( static function ( $name ) use ( &$params ) {
			return $params[ $name ] ?? null;
		} );
		$may = true;
		Functions\when( 'user_can' )->alias( static function ( $user, $cap ) use ( &$may ) {
			return $may && 'ffc_checkin_audiences' === $cap;
		} );
		Functions\when( 'get_userdata' )->justReturn( (object) array( 'display_name' => 'Ana Lima' ) );
		$controller = new AudienceTerminalRestController();
		$this->assertTrue( $controller->check_terminal_permission( $request ) );

		$params['code'] = AudienceOperatorCode::issue( 9, time() )['code'];
		$this->assertSame( 200, $controller->unlock( $request )->get_status() );

		$may = false;
		$refused = $controller->unlock( $request );
		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertSame( 'ffc_operator_not_allowed', $refused->get_error_code() );
		$this->assertInstanceOf( \WP_Error::class, $controller->end_self( $request ), 'an operator who lost the capability cannot end it' );

		$params['code'] = 'FFO1NOTACODE';
		$may            = true;
		$this->assertSame( 403, $controller->unlock( $request )->get_error_data()['status'] );

		$params['code'] = AudienceOperatorCode::issue( 9, time() )['code'];
		$this->assertSame( 200, $controller->end_self( $request )->get_status() );
		$this->assertSame( 9, $this->tables->terminals[ $paired['terminal_id'] ]['revoked_by'] );
		$this->assertFalse( $controller->check_terminal_permission( $request ), 'an ended terminal is no longer paired' );
	}

	// ---- Event queue (#2746) ---------------------------------------------

	public function test_check_in_windows_may_touch_but_not_cross(): void {
		// Windows run from the start to the end plus the 30-minute grace.
		$this->booking( 31, '2027-01-15', 'active', '09:00:00', '11:00:00' );
		$this->booking( 32, '2027-01-15', 'active', '11:30:00', '13:00:00' );
		$this->booking( 33, '2027-01-15', 'active', '11:00:00', '12:00:00' );

		$this->assertIsArray( AudienceTerminalPairing::create_code( self::OPERATOR, array( 31, 32 ), '', self::NOW ), 'one closes at 11:30, the next opens at 11:30' );

		$crossing = AudienceTerminalPairing::create_code( self::OPERATOR, array( 31, 33 ), '', self::NOW );
		$this->assertInstanceOf( \WP_Error::class, $crossing );
		$this->assertSame( 'ffc_terminal_overlap', $crossing->get_error_code(), 'the event ends at 11:00 but its check-in runs to 11:30' );
		$this->assertStringContainsString( 'Event 33', $crossing->get_error_message() );
		$this->assertStringContainsString( 'Event 31', $crossing->get_error_message(), 'names the event it collides with' );
		$this->assertSame( 409, $crossing->get_error_data()['status'] );
		$this->assertCount( 1, $this->tables->pairings );
	}

	public function test_a_window_that_already_closed_collides_with_nothing(): void {
		$this->booking( 34, '2027-01-15', 'active', '08:00:00', '09:00:00' );
		$this->booking( 35, '2027-01-15', 'active', '08:00:00', '09:00:00' );

		$this->assertNotNull( \FreeFormCertificate\Audience\AudienceTerminalSchedule::collision( array( 34, 35 ), self::NOW ), 'both run 08:00 today' );
		$this->assertNull( \FreeFormCertificate\Audience\AudienceTerminalSchedule::collision( array( 34, 35 ), self::NOW + 2 * HOUR_IN_SECONDS ), 'both windows closed at 09:30' );
		$this->assertNull( \FreeFormCertificate\Audience\AudienceTerminalSchedule::collision( array( 34, 99 ), self::NOW ), 'a booking that does not exist has no window' );
	}

	public function test_an_all_day_event_takes_the_whole_day(): void {
		$this->booking( 37, '2027-01-16', 'active', '10:00:00', '11:00:00' );
		$this->bookings[37]->is_all_day = 1;
		$this->booking( 38, '2027-01-16', 'active', '15:00:00', '16:00:00' );

		$windows = \FreeFormCertificate\Audience\AudienceTerminalSchedule::windows( $this->bookings[37] );
		$this->assertSame( gmmktime( 0, 0, 0, 1, 16, 2027 ), $windows[0]['opens'] );
		$this->assertSame( gmmktime( 0, 29, 0, 1, 17, 2027 ), $windows[0]['closes'], '23:59 plus the grace' );
		$this->assertSame( 'ffc_terminal_overlap', AudienceTerminalPairing::create_code( self::OPERATOR, array( 37, 38 ), '', self::NOW )->get_error_code() );
	}

	public function test_the_planned_window_follows_the_times_the_event_sets(): void {
		$this->booking( 39, '2027-01-16', 'active', '10:00:00', '11:00:00' );
		$this->bookings[39]->checkin_opens_before = 30;
		$this->bookings[39]->checkin_closes_after = 0;

		$windows = \FreeFormCertificate\Audience\AudienceTerminalSchedule::windows( $this->bookings[39] );
		$this->assertSame( gmmktime( 9, 30, 0, 1, 16, 2027 ), $windows[0]['opens'] );
		$this->assertSame( gmmktime( 11, 0, 0, 1, 16, 2027 ), $windows[0]['closes'] );
	}

	public function test_an_operator_changes_the_events_of_their_terminal(): void {
		$paired = $this->paired( array( 11 ), self::DEVICE );
		$id     = $paired['terminal_id'];

		$changed = AudienceTerminalPairing::update_bookings( $id, array( 12 ), self::OPERATOR, false, self::NOW + 100 );
		$this->assertIsArray( $changed );
		$this->assertSame( array( 12 ), $changed['bookings'] );
		$this->assertSame( array( 11 => self::NOW + 100 ), $changed['removed'], 'when the event left the queue' );
		$this->assertSame( AudienceTerminalPairing::booking_end( 12 ) + \FreeFormCertificate\Audience\AudienceCheckinSettings::auto_end_seconds(), $changed['expires_at'], 'the end follows the new last event' );
		$stored = AudienceTerminalReader::terminal( $id );
		$this->assertSame( array( 12 ), $stored['bookings'] );
		$this->assertSame( array( 11 => self::NOW + 100 ), $stored['removed'] );

		$last = ActivityLogBuffer::contexts()[ count( ActivityLogBuffer::contexts() ) - 1 ];
		$this->assertSame( 'terminal_events_changed', $last['action'] );

		$back = AudienceTerminalPairing::update_bookings( $id, array( 11, 12 ), self::OPERATOR, false, self::NOW + 200 );
		$this->assertSame( array(), $back['removed'], 'an event put back is held again' );

		$writes = count( ActivityLogBuffer::contexts() );
		$this->assertSame( array( 11, 12 ), AudienceTerminalPairing::update_bookings( $id, array( 12, 11 ), self::OPERATOR, false, self::NOW + 300 )['bookings'], 'the same events in another order change nothing' );
		$this->assertCount( $writes, ActivityLogBuffer::contexts() );
	}

	public function test_a_changed_queue_is_held_to_the_rules_of_a_pairing(): void {
		$paired = $this->paired( array( 11 ), self::DEVICE );
		$id     = $paired['terminal_id'];
		$this->booking( 31, '2027-01-15', 'active', '09:00:00', '11:00:00' );
		$this->booking( 33, '2027-01-15', 'active', '11:00:00', '12:00:00' );

		$this->assertSame( 'ffc_terminal_no_bookings', AudienceTerminalPairing::update_bookings( $id, array(), self::OPERATOR, false, self::NOW )->get_error_code() );
		$this->assertSame( 'ffc_terminal_too_many', AudienceTerminalPairing::update_bookings( $id, range( 1, 11 ), self::OPERATOR, false, self::NOW )->get_error_code() );
		$this->assertSame( 'ffc_terminal_booking', AudienceTerminalPairing::update_bookings( $id, array( 99 ), self::OPERATOR, false, self::NOW )->get_error_code() );
		$this->assertSame( 'ffc_terminal_overlap', AudienceTerminalPairing::update_bookings( $id, array( 31, 33 ), self::OPERATOR, false, self::NOW )->get_error_code() );
		$this->assertSame( 'ffc_terminal_missing', AudienceTerminalPairing::update_bookings( $id, array( 12 ), 99, false, self::NOW )->get_error_code(), 'another operator\'s terminal' );
		$this->assertIsArray( AudienceTerminalPairing::update_bookings( $id, array( 12 ), 99, true, self::NOW ), 'an administrator changes any' );
		$this->assertSame( 'ffc_terminal_missing', AudienceTerminalPairing::update_bookings( 404, array( 12 ), self::OPERATOR, true, self::NOW )->get_error_code() );

		AudienceTerminalPairing::revoke( $id, self::OPERATOR, false, self::NOW + 10 );
		$this->assertSame( 'ffc_terminal_missing', AudienceTerminalPairing::update_bookings( $id, array( 11 ), self::OPERATOR, false, self::NOW + 20 )->get_error_code(), 'an ended terminal' );
	}

	public function test_an_added_event_must_have_room_and_a_held_one_keeps_its_place(): void {
		$first = $this->paired( array( 11 ), 'device-for-eleven-0' );
		$this->paired( array( 11 ), 'device-for-eleven-1' );
		$this->paired( array( 11 ), 'device-for-eleven-2' );
		$other = $this->paired( array( 12 ), 'device-for-twelve-0' );

		$this->assertSame( 'ffc_terminal_booking_limit', AudienceTerminalPairing::update_bookings( $other['terminal_id'], array( 11, 12 ), self::OPERATOR, false, self::NOW + 5 )->get_error_code(), 'three terminals already hold 11' );
		$this->assertIsArray( AudienceTerminalPairing::update_bookings( $first['terminal_id'], array( 11, 12 ), self::OPERATOR, false, self::NOW + 5 ), 'a terminal that holds 11 keeps it while adding another' );
	}

	public function test_a_terminal_held_an_event_until_the_moment_it_left(): void {
		$paired = $this->paired( array( 11, 12 ), self::DEVICE );
		AudienceTerminalPairing::update_bookings( $paired['terminal_id'], array( 12 ), self::OPERATOR, false, self::NOW + 600 );
		$terminal = AudienceTerminalReader::terminal( $paired['terminal_id'] );

		$this->assertTrue( AudienceTerminalPairing::held_at( $terminal, 11, self::NOW + 599 ), 'read offline before the change: still recorded' );
		$this->assertFalse( AudienceTerminalPairing::held_at( $terminal, 11, self::NOW + 600 ) );
		$this->assertTrue( AudienceTerminalPairing::held_at( $terminal, 12, self::NOW + 600 ) );
		$this->assertFalse( AudienceTerminalPairing::held_at( $terminal, 13, self::NOW ) );
		$this->assertSame( array( 11 => 5 ), AudienceTerminalReader::removals( '{"11":5,"x":3,"12":0,"-1":4}' ) );
		$this->assertSame( array(), AudienceTerminalReader::removals( 'not json' ) );
	}

	public function test_the_schedule_lists_open_windows_soonest_first_and_says_which_are_open(): void {
		$this->booking( 31, '2027-01-15', 'active', '09:00:00', '11:00:00' );
		$this->booking( 32, '2027-01-15', 'active', '07:00:00', '08:00:00' );
		$this->booking( 39, '2027-01-15', 'cancelled', '13:00:00', '14:00:00' );
		$this->bookings[32]->environment_name = 'Hall';
		$terminal = AudienceTerminalReader::terminal( $this->paired( array( 31, 32, 12 ), self::DEVICE )['terminal_id'] );
		$terminal['bookings'][] = 39;
		$this->tables->windows[] = array(
			'id'                 => 1,
			'booking_id'         => 32,
			'window_date'        => '2027-01-15',
			'rotation_seconds'   => 30,
			'opened_at'          => self::NOW - 1800,
			'opened_by'          => 5,
			'closes_at'          => self::NOW + 1800,
			'closed_at'          => self::NOW + 900,
			'closed_by'          => null,
			'geofence_locations' => '',
		);

		foreach ( array( 'terminal', 'app' ) as $method ) {
			$this->tables->attendance[] = array(
				'booking_id'      => 32,
				'user_id'         => 7,
				'attendance_date' => '2027-01-15',
				'method'          => $method,
			);
		}

		$plan = \FreeFormCertificate\Audience\AudienceTerminalSchedule::plan( $terminal, self::NOW + 60 );

		$this->assertSame( array( 32, 31, 12 ), array_column( $plan, 'booking_id' ), 'soonest first; a cancelled event is left out' );
		$this->assertTrue( $plan[0]['open'] );
		$this->assertSame( self::NOW + 900, $plan[0]['closes'], 'an open window closes when the operator said, not at its plan' );
		$this->assertSame( 'Hall', $plan[0]['place'] );
		$this->assertSame( 'Event 32', $plan[0]['title'] );
		$this->assertFalse( $plan[1]['open'] );
		$this->assertSame( gmmktime( 9, 0, 0, 1, 15, 2027 ), $plan[1]['opens'] );
		$this->assertNotSame( '', $plan[1]['opens_label'] );
		$this->assertNotSame( '', $plan[1]['closes_label'] );
		$this->assertNotSame( '', $plan[1]['opens_time'], 'the screen between events names the opening time' );
		$this->assertStringContainsString( '07', $plan[0]['hours'], 'the event\'s own hours, not its check-in window' );
		$this->assertSame( 1, $plan[0]['present'], 'the open event counts who is already in, once per person' );
		$this->assertSame( 0, $plan[2]['present'], 'an event on another day counts nobody' );
		$this->assertSame( 0, $plan[2]['enrolled'] );

		$later = \FreeFormCertificate\Audience\AudienceTerminalSchedule::plan( $terminal, gmmktime( 12, 0, 0, 1, 15, 2027 ) );
		$this->assertSame( array( 12 ), array_column( $later, 'booking_id' ), 'a window past its close leaves the schedule' );
	}

	public function test_the_routes_change_a_terminal_and_hand_it_its_schedule(): void {
		// The routes read the real clock, so the events are near it.
		$this->booking( 41, gmdate( 'Y-m-d', time() + DAY_IN_SECONDS ) );
		$this->booking( 42, gmdate( 'Y-m-d', time() + 2 * DAY_IN_SECONDS ) );
		$paired  = $this->paired( array( 11 ), self::DEVICE );
		$token   = $paired['token'];
		$params  = array(
			'id'       => $paired['terminal_id'],
			'bookings' => array( '42', 41 ),
		);
		$request = \Mockery::mock( 'WP_REST_Request' );
		$request->shouldReceive( 'get_header' )->with( 'X-FFC-Terminal-Queue' )->andReturn( '' );
		$request->shouldReceive( 'get_header' )->with( 'X-FFC-Terminal' )->andReturnUsing( static function () use ( &$token ) {
			return $token;
		} );
		$request->shouldReceive( 'get_param' )->andReturnUsing( static function ( $name ) use ( &$params ) {
			return $params[ $name ] ?? null;
		} );
		Functions\when( 'get_current_user_id' )->justReturn( self::OPERATOR );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		$controller = new AudienceTerminalRestController();

		$response = $controller->update_terminal( $request );
		$this->assertInstanceOf( \WP_REST_Response::class, $response, is_wp_error( $response ) ? $response->get_error_code() : '' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 42, 41 ), $response->get_data()['bookings'] );
		$this->assertArrayNotHasKey( 'token_hash', $response->get_data() );

		$schedule = $controller->schedule( $request )->get_data();
		$this->assertSame( array( 42, 41 ), $schedule['bookings'], 'the terminal reads the change on its next request' );
		$this->assertSame( array( 41, 42 ), array_column( $schedule['schedule'], 'booking_id' ) );
		$this->assertNotSame( '', $schedule['expires_label'] );

		$params['bookings'] = array();
		$this->assertSame( 'ffc_terminal_no_bookings', $controller->update_terminal( $request )->get_error_code() );

		$token = str_repeat( 'c', 64 );
		$this->assertSame( 'ffc_terminal_unpaired', $controller->schedule( $request )->get_error_code() );
	}

	public function test_an_operator_who_may_pair_changes_the_queue_from_the_terminal(): void {
		$this->booking( 41, gmdate( 'Y-m-d', time() + DAY_IN_SECONDS ) );
		$this->booking( 42, gmdate( 'Y-m-d', time() + 2 * DAY_IN_SECONDS ) );
		// Paired on the fixture's clock; the routes then read the real one.
		$paired  = $this->paired( array( 11 ), self::DEVICE );
		$token   = $paired['token'];
		$params  = array();
		$request = \Mockery::mock( 'WP_REST_Request' );
		$request->shouldReceive( 'get_header' )->with( 'X-FFC-Terminal-Queue' )->andReturn( '' );
		$request->shouldReceive( 'get_header' )->with( 'X-FFC-Terminal' )->andReturnUsing( static function () use ( &$token ) {
			return $token;
		} );
		$request->shouldReceive( 'get_param' )->andReturnUsing( static function ( $name ) use ( &$params ) {
			return $params[ $name ] ?? null;
		} );
		$caps = array( 'ffc_checkin_audiences' );
		Functions\when( 'user_can' )->alias( static function ( $user, $cap ) use ( &$caps ) {
			return in_array( $cap, $caps, true );
		} );
		Functions\when( 'get_userdata' )->justReturn( (object) array( 'display_name' => 'Ana Lima' ) );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		Functions\when( 'wp_parse_args' )->alias( static fn( $args, $defaults ) => array_merge( $defaults, $args ) );
		$this->tables->booking_list = array(
			(object) array( 'id' => 42, 'description' => 'Workshop', 'environment_name' => 'Room 1', 'booking_date' => gmdate( 'Y-m-d', time() + 2 * DAY_IN_SECONDS ), 'end_date' => null, 'start_time' => '09:00:00', 'end_time' => '17:00:00' ),
		);
		$controller = new AudienceTerminalRestController();

		$params['code'] = AudienceOperatorCode::issue( 9, time() )['code'];
		$this->assertFalse( $controller->unlock( $request )->get_data()['may_edit'], 'checking people in is not enough to change the queue' );
		$this->assertSame( 'ffc_operator_queue_not_allowed', $controller->self_events( $request )->get_error_code() );
		$params['bookings'] = array( 41, 42 );
		$refused            = $controller->update_self( $request );
		$this->assertSame( 403, $refused->get_error_data()['status'] );
		$this->assertSame( array( 11 ), AudienceTerminalReader::terminal( $paired['terminal_id'] )['bookings'] );

		$caps[] = AudienceTerminalPairing::CAP;
		$this->assertTrue( $controller->unlock( $request )->get_data()['may_edit'] );
		$offered = $controller->self_events( $request )->get_data();
		$this->assertSame( AudienceTerminalPairing::MAX_BOOKINGS, $offered['max'] );
		$this->assertContains( 42, array_column( $offered['bookings'], 'id' ) );

		$response = $controller->update_self( $request );
		$this->assertInstanceOf( \WP_REST_Response::class, $response, is_wp_error( $response ) ? $response->get_error_code() : '' );
		$this->assertSame( array( 41, 42 ), $response->get_data()['bookings'] );
		$this->assertSame( array( 41, 42 ), array_column( $response->get_data()['schedule'], 'booking_id' ), 'the menu redraws from the new plan' );
		$this->assertSame( array( 41, 42 ), AudienceTerminalReader::terminal( $paired['terminal_id'] )['bookings'], 'an operator who did not pair it may change it at the terminal' );

		$params['bookings'] = array();
		$this->assertSame( 'ffc_terminal_no_bookings', $controller->update_self( $request )->get_error_code(), 'the rules of a pairing still hold' );

		$params['code'] = 'FFO1NOTACODE';
		$this->assertSame( 403, $controller->self_events( $request )->get_error_data()['status'] );

		$token = str_repeat( 'c', 64 );
		$this->assertSame( 'ffc_terminal_unpaired', $controller->update_self( $request )->get_error_code() );
	}

	public function test_an_operator_sets_a_terminal_up_on_the_terminal_without_a_pairing_code_shown(): void {
		$this->booking( 41, gmdate( 'Y-m-d', time() + DAY_IN_SECONDS ) );
		$params  = array();
		$request = \Mockery::mock( 'WP_REST_Request' );
		$request->shouldReceive( 'get_header' )->andReturn( '' );
		$request->shouldReceive( 'get_param' )->andReturnUsing( static function ( $name ) use ( &$params ) {
			return $params[ $name ] ?? null;
		} );
		$caps = array( 'ffc_checkin_audiences' );
		Functions\when( 'user_can' )->alias( static function ( $user, $cap ) use ( &$caps ) {
			return in_array( $cap, $caps, true );
		} );
		Functions\when( 'get_userdata' )->justReturn( (object) array( 'display_name' => 'Ana Lima' ) );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		Functions\when( 'wp_parse_args' )->alias( static fn( $args, $defaults ) => array_merge( $defaults, $args ) );
		$this->tables->booking_list = array(
			(object) array( 'id' => 41, 'description' => 'Workshop', 'environment_name' => 'Hall', 'booking_date' => gmdate( 'Y-m-d', time() + DAY_IN_SECONDS ), 'end_date' => null, 'start_time' => '09:00:00', 'end_time' => '17:00:00' ),
		);
		$controller = new AudienceTerminalRestController();

		$params = array(
			'code'     => AudienceOperatorCode::issue( 9, time() )['code'],
			'device'   => self::DEVICE,
			'bookings' => array( 41 ),
			'label'    => 'Entrance',
		);
		$this->assertSame( 'ffc_operator_queue_not_allowed', $controller->setup_events( $request )->get_error_code(), 'checking people in is not enough to set a terminal up' );
		$this->assertSame( 403, $controller->setup( $request )->get_error_data()['status'] );
		$this->assertSame( array(), $this->tables->terminals );

		$caps[] = AudienceTerminalPairing::CAP;
		$offered = $controller->setup_events( $request )->get_data();
		$this->assertSame( array( 41 ), array_column( $offered['bookings'], 'id' ) );
		$this->assertArrayHasKey( 'name', $offered, 'the screen says who is setting it up' );

		$response = $controller->setup( $request );
		$this->assertInstanceOf( \WP_REST_Response::class, $response, is_wp_error( $response ) ? $response->get_error_code() : '' );
		$this->assertSame( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 64, strlen( $data['token'] ) );
		$this->assertSame( array( 41 ), $data['bookings'] );
		$this->assertSame( 'Entrance', $data['label'] );
		$this->assertArrayHasKey( 'keys', $data );
		$this->assertArrayNotHasKey( 'code', $data, 'the pairing code never leaves the server' );
		$this->assertSame( 9, $this->tables->terminals[ $data['terminal_id'] ]['paired_by'], 'the terminal is attributed to the operator who set it up' );

		$params['bookings'] = array();
		$this->assertSame( 'ffc_terminal_no_bookings', $controller->setup( $request )->get_error_code() );

		$params['code'] = 'FFO1NOTACODE';
		$this->assertSame( 403, $controller->setup_events( $request )->get_error_data()['status'] );
	}

	// ---- Terminals screen (#2748) --------------------------------------

	public function test_a_terminal_reports_its_queue_and_counts_what_it_recorded(): void {
		$paired = $this->paired( array( 11 ), self::DEVICE );
		$id     = $paired['terminal_id'];

		AudienceTerminalPairing::authenticate( $paired['token'], self::NOW + 100, 4 );
		$this->assertSame( 4, $this->tables->terminals[ $id ]['queued'] );
		$this->assertSame( self::NOW + 100, $this->tables->terminals[ $id ]['last_seen_at'] );

		AudienceTerminalPairing::authenticate( $paired['token'], self::NOW + 110, 4 );
		$this->assertSame( self::NOW + 100, $this->tables->terminals[ $id ]['last_seen_at'], 'nothing changed within the minute: no write' );
		AudienceTerminalPairing::authenticate( $paired['token'], self::NOW + 120, 0 );
		$this->assertSame( 0, $this->tables->terminals[ $id ]['queued'], 'a changed count is written at once' );
		AudienceTerminalPairing::authenticate( $paired['token'], self::NOW + 200 );
		$this->assertSame( 0, $this->tables->terminals[ $id ]['queued'], 'a request that does not say keeps the count' );

		$this->assertTrue( AudienceTerminalWriter::count_reading( $id ) );
		$this->assertSame( 1, AudienceTerminalReader::terminal( $id )['readings'] );
	}

	public function test_the_screen_names_each_state_and_shows_an_operator_their_own(): void {
		$mine   = $this->paired( array( 11 ), self::DEVICE );
		$theirs = AudienceTerminalPairing::pair( AudienceTerminalPairing::create_code( 8, array( 12 ), '', self::NOW )['code'], 'second-device-0002', self::ADDRESS, self::NOW + 2 );
		$ended  = $this->paired( array( 11 ), 'third-device-0003' );
		AudienceTerminalPairing::revoke( $ended['terminal_id'], self::OPERATOR, false, self::NOW + 3 );

		$terminal = AudienceTerminalReader::terminal( $mine['terminal_id'] );
		$this->assertSame( AudienceAdminTerminals::STATE_OFFLINE, AudienceAdminTerminals::state( $terminal, self::NOW ), 'never heard from' );
		$terminal['last_seen_at'] = self::NOW - 60;
		$this->assertSame( AudienceAdminTerminals::STATE_ONLINE, AudienceAdminTerminals::state( $terminal, self::NOW ) );
		$this->assertSame( AudienceAdminTerminals::STATE_OFFLINE, AudienceAdminTerminals::state( $terminal, self::NOW + AudienceAdminTerminals::ONLINE_SECONDS ) );
		$this->assertSame( AudienceAdminTerminals::STATE_EXPIRED, AudienceAdminTerminals::state( $terminal, $terminal['expires_at'] ) );
		$this->assertSame( AudienceAdminTerminals::STATE_REVOKED, AudienceAdminTerminals::state( AudienceTerminalReader::terminal( $ended['terminal_id'] ), self::NOW ) );
		foreach ( array( 'online', 'offline', 'expired', 'revoked' ) as $state ) {
			$this->assertNotSame( $state, AudienceAdminTerminals::state_label( $state ) );
		}

		$recent = AudienceTerminalReader::recent_terminals( self::NOW - DAY_IN_SECONDS );
		$this->assertCount( 3, $recent, 'the ended one stays on the list' );
		$this->assertSame( array( $ended['terminal_id'], $mine['terminal_id'] ), array_column( AudienceAdminTerminals::visible( $recent, self::OPERATOR ), 'id' ) );
		$this->assertCount( 3, AudienceAdminTerminals::visible( $recent, null ), 'an administrator sees all' );
		$this->assertCount( 2, AudienceTerminalReader::recent_terminals( self::NOW + 10 ), 'one that ended before the moment is gone' );
		unset( $theirs );
	}

	public function test_the_screen_changes_the_events_of_a_terminal_and_ends_one(): void {
		$paired = $this->paired( array( 11 ), self::DEVICE );
		$screen = new AudienceAdminTerminals( 'ffc-scheduling' );

		$this->assertSame(
			array(
				'code'  => 'events_saved',
				'error' => '',
			),
			$screen->run( 'events', $paired['terminal_id'], array( 12 ), self::OPERATOR, false, self::NOW + 10 )
		);
		$this->assertSame( array( 12 ), AudienceTerminalReader::terminal( $paired['terminal_id'] )['bookings'] );
		$this->assertSame(
			array(
				'code'  => 'events_failed',
				'error' => 'ffc_terminal_no_bookings',
			),
			$screen->run( 'events', $paired['terminal_id'], array(), self::OPERATOR, false, self::NOW + 20 )
		);
		$this->assertSame( 'events_failed', $screen->run( 'events', $paired['terminal_id'], array( 11 ), 99, false, self::NOW + 20 )['code'], 'another operator\'s terminal' );

		$this->assertSame( 'ended', $screen->run( 'end', $paired['terminal_id'], array(), self::OPERATOR, false, self::NOW + 30 )['code'] );
		$this->assertSame( 'stale', $screen->run( 'end', $paired['terminal_id'], array(), self::OPERATOR, false, self::NOW + 40 )['code'] );
		$this->assertSame( 'unknown', $screen->run( 'nope', $paired['terminal_id'], array(), self::OPERATOR, false, self::NOW )['code'] );
	}

	public function test_the_screen_words_the_queue_the_moment_and_the_state_as_the_mockup_does(): void {
		$this->assertSame( 'One event', AudienceAdminTerminals::queue_label( array( 11 ) ) );
		$this->assertSame( '2 events', AudienceAdminTerminals::queue_label( array( 11, 12 ) ) );
		$this->bookings[11]->environment_name = 'Room 3';
		$this->bookings[12]->environment_name = 'Room 3';
		$this->assertSame( '2 events · Room 3', AudienceAdminTerminals::queue_label( array( 11, 12 ) ), 'one place for every event' );

		Functions\when( 'human_time_diff' )->alias( static fn( $from, $to ) => intdiv( $to - $from, 60 ) . ' mins' );
		$this->assertSame( 'Not used yet', AudienceAdminTerminals::seen( null, self::NOW ) );
		$this->assertSame( 'now', AudienceAdminTerminals::seen( self::NOW - 30, self::NOW ) );
		$this->assertSame( '14 mins ago', AudienceAdminTerminals::seen( self::NOW - 14 * 60, self::NOW ) );
		$this->assertStringNotContainsString( 'ago', AudienceAdminTerminals::seen( self::NOW - 2 * DAY_IN_SECONDS, self::NOW ) );

		$ended = $this->paired( array( 11 ), self::DEVICE );
		AudienceTerminalPairing::revoke( $ended['terminal_id'], self::OPERATOR, false, self::NOW + 3 );
		Functions\when( 'get_userdata' )->justReturn( false );
		$terminal = AudienceTerminalReader::terminal( $ended['terminal_id'] );
		$this->assertSame( 'Revoked by Account #' . self::OPERATOR, AudienceAdminTerminals::state_text( $terminal, AudienceAdminTerminals::STATE_REVOKED ) );
	}

	public function test_an_operator_pairs_a_terminal_from_the_admin_and_sees_the_code_once(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( self::OPERATOR );
		Functions\when( 'admin_url' )->alias( static fn( $path = '' ) => 'https://example.com/wp-admin/' . $path );
		Functions\when( 'add_query_arg' )->alias( static fn( $args, $url ) => $url . '?' . http_build_query( $args ) );
		foreach ( array( 'esc_url', 'esc_attr', 'esc_html', 'esc_html__', 'esc_attr__' ) as $fn ) {
			Functions\when( $fn )->returnArg();
		}
		Functions\when( 'get_settings_errors' )->justReturn( array() );
		Functions\when( 'number_format_i18n' )->alias( static fn( $n ) => (string) $n );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_parse_args' )->alias( static fn( $args, $defaults ) => array_merge( $defaults, $args ) );
		Functions\when( 'checked' )->alias( static fn( $a, $b = true, $echo = true ) => $a === $b ? ' checked="checked"' : '' );
		Functions\when( 'disabled' )->alias( static fn( $a, $b = true, $echo = true ) => $a === $b ? ' disabled="disabled"' : '' );
		Functions\when( 'selected' )->alias( static fn( $a, $b = true, $echo = true ) => (string) $a === (string) $b ? ' selected="selected"' : '' );
		$screen = new AudienceAdminTerminals( 'ffc-scheduling' );
		// The screen pairs at the real clock, so the event is tomorrow by it.
		$this->booking( 13, gmdate( 'Y-m-d', time() + DAY_IN_SECONDS ) );

		$_GET  = array( 'page' => 'ffc-scheduling-terminals', 'pair' => '1' );
		$_POST = array(
			'ffc_action'          => 'terminals_admin',
			'ffc_terminals_nonce' => 'nonce',
			'op'                  => 'pair',
			'bookings'            => array( '13' ),
			'label'               => 'Front desk',
			'auto_end'            => '4',
		);
		try {
			$screen->handle_actions();
			ob_start();
			$screen->render_page();
			$html = (string) ob_get_clean();
		} finally {
			$_GET  = array();
			$_POST = array();
		}

		$pairing = array_values( $this->tables->pairings )[0];
		$this->assertSame( 4, $pairing['auto_end_hours'] );
		$this->assertSame( 'Front desk', $pairing['label'] );
		$this->assertMatchesRegularExpression( '/P-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}/', $html, 'the code is shown' );
		$this->assertStringContainsString( '<svg', $html, 'as a QR' );
		$this->assertStringContainsString( 'single use', $html );
		$this->assertStringNotContainsString( 'P-', json_encode( $this->transients ), 'never kept beyond the response' );
	}

	public function test_the_screen_draws_the_cards_the_list_and_the_editor(): void {
		$paired = $this->paired( array( 11 ), self::DEVICE );
		$this->tables->terminals[ $paired['terminal_id'] ]['label']        = 'Entrance A';
		$this->tables->terminals[ $paired['terminal_id'] ]['last_seen_at'] = time() - 30;
		$this->tables->terminals[ $paired['terminal_id'] ]['queued']       = 3;
		$this->tables->terminals[ $paired['terminal_id'] ]['readings']     = 12;
		$this->tables->terminals[ $paired['terminal_id'] ]['expires_at']   = time() + HOUR_IN_SECONDS;
		$this->tables->attendance = array(
			array( 'attendance_date' => gmdate( 'Y-m-d' ), 'method' => 'terminal' ),
			array( 'attendance_date' => gmdate( 'Y-m-d' ), 'method' => 'qr' ),
		);
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( self::OPERATOR );
		Functions\when( 'admin_url' )->alias( static fn( $path = '' ) => 'https://example.com/wp-admin/' . $path );
		Functions\when( 'add_query_arg' )->alias( static fn( $args, $url ) => $url . '?' . http_build_query( $args ) );
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'get_settings_errors' )->justReturn( array() );
		Functions\when( 'number_format_i18n' )->alias( static fn( $n ) => (string) $n );
		Functions\when( 'get_userdata' )->justReturn( (object) array( 'display_name' => 'Ana Lima' ) );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'checked' )->alias( static fn( $a, $b = true, $echo = true ) => $a === $b ? ' checked="checked"' : '' );
		Functions\when( 'disabled' )->alias( static fn( $a, $b = true, $echo = true ) => $a === $b ? ' disabled="disabled"' : '' );
		$screen = new AudienceAdminTerminals( 'ffc-scheduling' );

		ob_start();
		$screen->render_page();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'ffc-page-scheduling-terminals', $html );
		$this->assertStringContainsString( 'Entrance A', $html );
		$this->assertStringContainsString( 'Account #' . self::OPERATOR, $html, 'who paired it' );
		$this->assertStringContainsString( 'ffc-terminal-state-online', $html );
		$this->assertStringContainsString( '3 in the queue', $html );
		$this->assertStringContainsString( 'Presence terminals', $html );
		$this->assertStringContainsString( 'pair=1', $html, 'Pair terminal in the header' );
		$this->assertStringContainsString( 'Edit queue', $html );
		$this->assertStringContainsString( 'Revoke', $html );
		$this->assertStringContainsString( '>12 · ', $html );
		$this->assertStringContainsString( 'data-confirm=', $html, 'Revoke asks first' );
		$this->assertStringContainsString( 'edit=' . $paired['terminal_id'], $html );

		$_GET['edit'] = (string) $paired['terminal_id'];
		Functions\when( 'wp_parse_args' )->alias( static fn( $args, $defaults ) => array_merge( $defaults, $args ) );
		ob_start();
		$screen->render_page();
		$editor = (string) ob_get_clean();
		unset( $_GET['edit'] );
		$this->assertStringContainsString( 'name="op" value="events"', $editor );
		$this->assertStringContainsString( 'Save events', $editor );
		$this->assertStringNotContainsString( 'ffc-terminals-table', $editor, 'the editor replaces the list' );
	}

	// ---- Helpers ----------------------------------------------------------

	/**
	 * A booking the reader finds in the object cache.
	 *
	 * @param int    $id     Booking id.
	 * @param string $day    Day.
	 * @param string $status Status.
	 */
	private function booking( int $id, string $day, string $status = 'active', string $start = '', string $end = '17:00:00' ): void {
		$this->bookings[ $id ] = (object) array(
			'id'           => $id,
			'description'  => 'Event ' . $id,
			'booking_date' => $day,
			'end_date'     => null,
			'start_time'   => $start,
			'end_time'     => $end,
			'status'       => $status,
		);
	}

	/**
	 * Send a wrong code from the test device.
	 *
	 * @param int $now Unix time.
	 * @return \WP_Error
	 */
	private function wrong( int $now ): \WP_Error {
		$result = AudienceTerminalPairing::pair( 'P-WRON-GCOD-E000', self::DEVICE, self::ADDRESS, $now );
		$this->assertInstanceOf( \WP_Error::class, $result );
		return $result;
	}

	/**
	 * Generate a code and pair a terminal with it.
	 *
	 * @param list<int> $bookings Bookings.
	 * @param string    $device   Device id.
	 * @return array{token: string, terminal_id: int, label: string, bookings: list<int>, expires_at: int}
	 */
	private function paired( array $bookings, string $device ): array {
		$code   = AudienceTerminalPairing::create_code( self::OPERATOR, $bookings, '', self::NOW )['code'];
		$result = AudienceTerminalPairing::pair( $code, $device, self::ADDRESS, self::NOW + 1 );
		$this->assertIsArray( $result );
		return $result;
	}
}
