<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Audience\AudienceTerminalJoinCode;
use FreeFormCertificate\Audience\AudienceTerminalPairing;
use FreeFormCertificate\Core\AttendanceTargetRegistry;
use FreeFormCertificate\Core\EcdsaP256;
use FreeFormCertificate\Core\TerminalTargetInterface;
use FreeFormCertificate\Tests\Support\FakeTerminalTables;
use PHPUnit\Framework\TestCase;

/**
 * The on-site sign-up code a terminal signs with its own key (#2968): the
 * key the terminal registers, and every reason the site refuses a code.
 *
 * @covers \FreeFormCertificate\Audience\AudienceTerminalJoinCode
 * @covers \FreeFormCertificate\Audience\AudienceTerminalPairing::register_join_key
 * @covers \FreeFormCertificate\Audience\AudienceTerminalPairing::join_key_id
 */
class AudienceTerminalJoinCodeTest extends TestCase {

	private const NOW = 1800000000;

	private const TERMINAL = 7;

	private FakeTerminalTables $tables;

	/** @var array{public: string, secret: string} */
	private array $key;

	/** The certificate form the terminal checks in, as a target the test controls. */
	private object $form;

	/** @var mixed */
	private $wpdb_backup;

	private bool $had_wpdb = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		if ( ! EcdsaP256::available() ) {
			$this->markTestSkipped( 'openssl has no P-256 here.' );
		}

		$this->tables = new FakeTerminalTables();
		$this->had_wpdb = array_key_exists( 'wpdb', $GLOBALS );
		if ( $this->had_wpdb ) {
			$this->wpdb_backup = $GLOBALS['wpdb'];
		}
		$GLOBALS['wpdb'] = $this->tables;

		$key = EcdsaP256::generate();
		$this->assertNotNull( $key );
		$this->key = $key;

		$this->form = new class() implements TerminalTargetInterface {
			public bool $accepts = true;
			public bool $open    = true;
			public int $opens    = 0;
			public int $closes   = 0;
			public function type(): string {
				return 'certificate_form';
			}
			public function record( int $target_id, int $user_id, int $checked_at, string $method, ?int $marked_by ): string {
				return 'recorded';
			}
			public function terminal_windows( int $target_id ): array {
				return 12 === $target_id ? array( array( 'date' => '2027-01-15', 'opens' => $this->opens, 'closes' => $this->closes ) ) : array();
			}
			public function terminal_ends_at( int $target_id ): ?int {
				return $this->closes;
			}
			public function terminal_queue_error( int $target_id ): ?\WP_Error {
				return null;
			}
			public function terminal_title( int $target_id ): string {
				return 'Workshop ' . $target_id;
			}
			public function terminal_accepts_join( int $target_id ): bool {
				return $this->accepts;
			}
			public function terminal_entry( int $target_id, array $window, int $now ): array {
				return array(
					'title'    => 'Workshop ' . $target_id,
					'place'    => '',
					'hours'    => '',
					'open'     => $this->open,
					'closes'   => $window['closes'],
					'present'  => 0,
					'enrolled' => 0,
				);
			}
			public function terminal_read( int $target_id, array $reading ): array {
				return \FreeFormCertificate\Core\TerminalVerdict::refused( 'demo', 'demo' );
			}
		};
		$this->form->opens  = self::NOW - 600;
		$this->form->closes = self::NOW + 1200;
		$form               = $this->form;
		AttendanceTargetRegistry::register( 'certificate_form', static fn() => $form );

		$this->tables->terminals[ self::TERMINAL ] = array(
			'id'                       => self::TERMINAL,
			'token_hash'               => str_repeat( 'a', 64 ),
			'device_hash'              => str_repeat( 'b', 64 ),
			'label'                    => 'Gate',
			'bookings'                 => '["certificate_form:12"]',
			'removed'                  => null,
			'paired_by'                => 5,
			'paired_at'                => self::NOW - 3600,
			'expires_at'               => self::NOW + 86400,
			'last_seen_at'             => null,
			'queued'                   => 0,
			'readings'                 => 0,
			'auto_end_hours'           => null,
			'refused_after_revocation' => 0,
			'location_id'              => '',
			'join_public_key'          => AudienceTerminalJoinCode::encode( $this->key['public'] ),
			'join_key_at'              => self::NOW - 3600,
			'revoked_at'               => null,
			'revoked_by'               => null,
		);
	}

	protected function tearDown(): void {
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		AttendanceTargetRegistry::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A code as a terminal signs it.
	 *
	 * @param int         $issued   Issue time.
	 * @param int         $type     Target type byte.
	 * @param int         $target   Target id.
	 * @param string|null $secret   PEM secret; the terminal's key by default.
	 * @param int         $terminal Terminal id.
	 * @param int         $version  Format version.
	 */
	private function code( int $issued = self::NOW, int $type = 2, int $target = 12, ?string $secret = null, int $terminal = self::TERMINAL, int $version = 1 ): string {
		$body      = chr( $version ) . pack( 'N', $terminal ) . chr( $type ) . pack( 'N', $target ) . pack( 'N', $issued );
		$signature = EcdsaP256::sign( AudienceTerminalJoinCode::DOMAIN . $body, $secret ?? $this->key['secret'] );
		$this->assertNotNull( $signature );
		return AudienceTerminalJoinCode::encode( $body . $signature );
	}

	private function refusal( string $code, int $now = self::NOW ): string {
		$result = AudienceTerminalJoinCode::verify( $code, $now );
		$this->assertInstanceOf( \WP_Error::class, $result );
		return $result->get_error_code();
	}

	public function test_a_code_the_terminal_signed_names_the_event_and_the_moment(): void {
		$result = AudienceTerminalJoinCode::verify( $this->code(), self::NOW + 20 );

		$this->assertIsArray( $result );
		$this->assertSame( self::TERMINAL, $result['terminal_id'] );
		$this->assertSame( 'certificate_form', $result['target_type'] );
		$this->assertSame( 12, $result['target_id'] );
		$this->assertSame( 'certificate_form:12', $result['key'] );
		$this->assertSame( self::NOW, $result['issued_at'] );
		$this->assertSame( 'Workshop 12', $result['title'] );
		$this->assertSame( 104, strlen( $this->code() ), 'base64url of 78 bytes, short enough for a coarse QR' );
	}

	public function test_text_that_is_not_a_version_1_code_is_refused(): void {
		$this->assertSame( 'ffc_join_malformed', $this->refusal( '' ) );
		$this->assertSame( 'ffc_join_malformed', $this->refusal( 'not a code!' ) );
		$this->assertSame( 'ffc_join_malformed', $this->refusal( substr( $this->code(), 0, -4 ) ), 'too short' );
		$this->assertSame( 'ffc_join_malformed', $this->refusal( $this->code( self::NOW, 2, 12, null, self::TERMINAL, 2 ) ), 'another version' );
		$this->assertSame( 'ffc_join_malformed', $this->refusal( $this->code( self::NOW, 9 ) ), 'an unknown target type' );
		$this->assertSame( 'ffc_join_malformed', $this->refusal( $this->code( self::NOW, 2, 12, null, 0 ) ), 'no terminal' );
	}

	public function test_a_code_from_an_unknown_terminal_or_one_without_a_key_is_refused(): void {
		$this->assertSame( 'ffc_join_terminal', $this->refusal( $this->code( self::NOW, 2, 12, null, 99 ) ) );
		$this->tables->terminals[ self::TERMINAL ]['join_public_key'] = '';
		$this->assertSame( 'ffc_join_terminal', $this->refusal( $this->code() ) );
	}

	public function test_a_code_signed_by_another_key_is_refused(): void {
		$other = EcdsaP256::generate();
		$this->assertNotNull( $other );
		$this->assertSame( 'ffc_join_signature', $this->refusal( $this->code( self::NOW, 2, 12, $other['secret'] ) ) );

		$tampered = AudienceTerminalJoinCode::decode( $this->code() );
		$this->assertIsString( $tampered );
		$tampered[9] = chr( 13 );
		$this->assertSame( 'ffc_join_signature', $this->refusal( AudienceTerminalJoinCode::encode( $tampered ) ), 'another event in a signed body' );
	}

	public function test_a_code_from_an_ended_terminal_is_refused(): void {
		$this->tables->terminals[ self::TERMINAL ]['revoked_at'] = self::NOW - 60;
		$this->assertSame( 'ffc_join_ended', $this->refusal( $this->code() ) );

		$this->tables->terminals[ self::TERMINAL ]['revoked_at'] = null;
		$this->tables->terminals[ self::TERMINAL ]['expires_at'] = self::NOW;
		$this->assertSame( 'ffc_join_ended', $this->refusal( $this->code() ) );
	}

	public function test_a_code_lives_one_rotation_give_or_take_the_clock(): void {
		$limit = AudienceTerminalJoinCode::ROTATION + AudienceTerminalJoinCode::SKEW;
		$this->assertIsArray( AudienceTerminalJoinCode::verify( $this->code(), self::NOW + $limit ) );
		$this->assertSame( 'ffc_join_stale', $this->refusal( $this->code(), self::NOW + $limit + 1 ) );
		$this->assertIsArray( AudienceTerminalJoinCode::verify( $this->code( self::NOW + AudienceTerminalJoinCode::SKEW ), self::NOW ) );
		$this->assertSame( 'ffc_join_future', $this->refusal( $this->code( self::NOW + AudienceTerminalJoinCode::SKEW + 1 ) ) );
	}

	public function test_a_code_for_an_event_the_terminal_did_not_serve_is_refused(): void {
		$this->assertSame( 'ffc_join_other_event', $this->refusal( $this->code( self::NOW, 2, 13 ) ) );

		// An event that left the queue after the code was drawn still counts.
		$this->tables->terminals[ self::TERMINAL ]['bookings'] = '[]';
		$this->tables->terminals[ self::TERMINAL ]['removed']  = '{"certificate_form:12":' . ( self::NOW + 5 ) . '}';
		$this->assertIsArray( AudienceTerminalJoinCode::verify( $this->code(), self::NOW + 10 ) );
		$this->tables->terminals[ self::TERMINAL ]['removed'] = '{"certificate_form:12":' . ( self::NOW - 5 ) . '}';
		$this->assertSame( 'ffc_join_other_event', $this->refusal( $this->code(), self::NOW + 10 ) );
	}

	public function test_an_event_that_takes_no_sign_up_or_is_not_open_refuses(): void {
		$this->form->accepts = false;
		$this->assertSame( 'ffc_join_not_accepted', $this->refusal( $this->code() ) );

		$this->form->accepts = true;
		$this->form->open    = false;
		$this->assertSame( 'ffc_join_closed', $this->refusal( $this->code() ), 'check-in not opened' );

		$this->form->open   = true;
		$this->form->closes = self::NOW;
		$this->assertSame( 'ffc_join_closed', $this->refusal( $this->code() ), 'the window closed' );
	}

	public function test_the_terminal_registers_its_key_and_is_told_its_fingerprint(): void {
		$other = EcdsaP256::generate();
		$this->assertNotNull( $other );
		$encoded = AudienceTerminalJoinCode::encode( $other['public'] );

		$id = AudienceTerminalPairing::register_join_key( self::TERMINAL, $encoded, self::NOW );
		$this->assertSame( substr( hash( 'sha256', $other['public'] ), 0, 16 ), $id );
		$this->assertSame( $encoded, $this->tables->terminals[ self::TERMINAL ]['join_public_key'] );
		$this->assertSame( self::NOW, $this->tables->terminals[ self::TERMINAL ]['join_key_at'] );
		$this->assertSame( 'ffc_join_signature', $this->refusal( $this->code() ), 'the old key no longer signs' );

		$this->assertSame( 'ffc_terminal_join_key', AudienceTerminalPairing::register_join_key( self::TERMINAL, 'not+base64url', self::NOW )->get_error_code() );
		$this->assertSame( 'ffc_terminal_join_key', AudienceTerminalPairing::register_join_key( self::TERMINAL, AudienceTerminalJoinCode::encode( str_repeat( "\x04", 65 ) ), self::NOW )->get_error_code(), 'not a point on the curve' );
		$this->assertSame( 'ffc_terminal_save', AudienceTerminalPairing::register_join_key( 99, $encoded, self::NOW )->get_error_code() );
		$this->assertSame( '', AudienceTerminalPairing::join_key_id( '' ) );
	}
}
