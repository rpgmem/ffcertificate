<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Audience\AudienceFormInvite;
use FreeFormCertificate\Audience\AudienceFormInviteResolver;
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Core\SensitiveFieldRegistry;
use FreeFormCertificate\Tests\Support\Identifiers;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Who a post-event invitation is for, as the certificate form asks it (#1948).
 *
 * AudienceBookingReader, AudiencePostEventForm, SensitiveFieldRegistry,
 * Encryption (with the bootstrap's key constants), UserManager, UserCreator
 * and UserIdentifiersQueryService are the real classes: the `$wpdb` double
 * below answers each query by its SQL text and the values bound to it, from
 * the booking row, the booking's users, the identity owners and the stored
 * identifiers a test sets. Any other query fails the test.
 *
 * @covers \FreeFormCertificate\Audience\AudienceFormInviteResolver
 */
class AudienceFormInviteResolverTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const FORM = 40;

	private const CPF = '52998224725';

	/** @var Mockery\MockInterface */
	private $wpdb;

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	/** @var list<array<int, mixed>> Every prepare() call, as its argument list. */
	private array $prepared = array();

	/** @var array<string, int> The user id the identity lookup answers, by the hash bound to it. */
	private array $owners = array();

	/** @var list<array{sql: string, bound: array<int, mixed>}> Every identity lookup the double answered. */
	private array $lookups = array();

	/** @var list<array{cpf_encrypted: string|null, rf_encrypted: string|null}> Identifiers stored for user 7. */
	private array $stored_identifiers = array();

	/** @var list<int> The users whose stored identifiers were read. */
	private array $identifier_reads = array();

	/** @var int */
	private $now = 0;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_salt' )->justReturn( 'salt' );
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
		Functions\when( 'get_option' )->alias( static fn( $key, $default = false ) => $default );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'get_post' )->alias(
			static function ( $id ) {
				$post              = new \WP_Post();
				$post->post_type   = 'ffc_form';
				$post->post_status = 'publish';
				return $post;
			}
		);
		Functions\when( 'get_userdata' )->alias(
			static function ( $id ) {
				if ( 7 !== (int) $id ) {
					return false;
				}
				return (object) array(
					'display_name' => 'Ana Souza',
					'user_email'   => 'ana@example.org',
				);
			}
		);

		$this->prepared           = array();
		$this->owners             = array();
		$this->lookups            = array();
		$this->stored_identifiers = array();
		$this->identifier_reads   = array();

		$booking = (object) array(
			'id'                        => 5,
			'environment_id'            => 2,
			'status'                    => 'active',
			'description'               => 'Workshop',
			'booking_date'              => '2027-03-01',
			'start_time'                => '09:00:00',
			'end_time'                  => '12:00:00',
			'post_event_custom'         => '1',
			'post_event_form_id'        => (string) self::FORM,
			'post_event_window_days'    => '7',
			'post_event_attendees_only' => '0',
		);

		$this->wpdb         = Mockery::mock( 'wpdb' );
		$this->wpdb->prefix = 'wp_';
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( ...$args ) {
				$flat             = 2 === count( $args ) && is_array( $args[1] ) ? array_merge( array( $args[0] ), $args[1] ) : $args;
				$this->prepared[] = $flat;
				return 'Q' . ( count( $this->prepared ) - 1 ) . ':' . $flat[0];
			}
		);
		$this->wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function ( $sql ) use ( $booking ) {
				$bound = $this->boundTo( (string) $sql );
				if ( false !== strpos( (string) $sql, 'WHERE b.id = %d' ) ) {
					// AudienceBookingReader::get_by_id(): only booking 5 exists.
					return 5 === (int) end( $bound ) ? clone $booking : null;
				}
				$this->fail( 'Unexpected get_row(): ' . $sql );
			}
		);
		$this->wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $sql ) {
				$bound = $this->boundTo( (string) $sql );
				if ( false !== strpos( (string) $sql, 'WHERE ba.booking_id = %d' ) ) {
					// The booking reaches no audience; its people are its direct users.
					return array();
				}
				if ( false !== strpos( (string) $sql, 'SELECT cpf_encrypted, rf_encrypted' ) ) {
					// UserIdentifiersQueryService: the user id binds after each table.
					$this->identifier_reads[] = (int) $bound[2];
					return 7 === (int) $bound[2] ? $this->stored_identifiers : array();
				}
				$this->fail( 'Unexpected get_results(): ' . $sql );
			}
		);
		$this->wpdb->shouldReceive( 'get_col' )->andReturnUsing(
			function ( $sql ) {
				$bound = $this->boundTo( (string) $sql );
				if ( false !== strpos( (string) $sql, 'SELECT user_id FROM %i WHERE booking_id = %d' ) ) {
					return 5 === (int) $bound[2] ? array( '7' ) : array();
				}
				$this->fail( 'Unexpected get_col(): ' . $sql );
			}
		);
		$this->wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( $sql ) {
				$bound = $this->boundTo( (string) $sql );
				if ( false !== strpos( (string) $sql, 'SELECT user_id FROM %i WHERE (' ) ) {
					// UserCreator::find_user_id_by_hashes(): table, then the hashes.
					$this->lookups[] = array(
						'sql'   => (string) $sql,
						'bound' => $bound,
					);
					foreach ( array_slice( $bound, 2 ) as $hash ) {
						if ( isset( $this->owners[ (string) $hash ] ) ) {
							return (string) $this->owners[ (string) $hash ];
						}
					}
					return null;
				}
				$this->fail( 'Unexpected get_var(): ' . $sql );
			}
		);

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']   = $this->wpdb;

		$this->now = ( new \DateTimeImmutable( '2027-03-01 12:00', new \DateTimeZone( 'UTC' ) ) )->getTimestamp() + 3600;
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

	/**
	 * The prepare() argument list behind a prepared query string.
	 *
	 * @param string $sql The string prepare() returned.
	 * @return array<int, mixed>
	 */
	private function boundTo( string $sql ): array {
		if ( ! preg_match( '/^Q(\d+):/', $sql, $m ) ) {
			$this->fail( 'Query was not prepared: ' . $sql );
		}
		return $this->prepared[ (int) $m[1] ];
	}

	/**
	 * The hash of an identifier, as the registry stores it.
	 *
	 * @param string $kind  `cpf` or `rf`.
	 * @param string $value The identifier.
	 * @return string
	 */
	private function hashOf( string $kind, string $value ): string {
		$hash = SensitiveFieldRegistry::hash_identifier( $kind, $value );
		$this->assertIsString( $hash, 'The bootstrap keys hash identifiers.' );
		return $hash;
	}

	public function test_an_invitation_names_the_person_from_their_account(): void {
		$invite = AudienceFormInviteResolver::resolve( self::FORM, AudienceFormInvite::code( 5, 7, self::FORM ), null, $this->now );

		$this->assertSame(
			array(
				'booking_id' => 5,
				'user_id'    => 7,
				'name'       => 'Ana Souza',
				'email'      => 'ana@example.org',
				'event'      => 'Workshop',
			),
			$invite
		);
		$this->assertSame( array(), $this->lookups, 'No CPF/RF typed, so no identity lookup runs.' );
	}

	/**
	 * Booking, person and form the code is signed for, the form being
	 * filled in, whether the signature is tampered with, and the refusal.
	 *
	 * @return array<string, array{0: int, 1: int, 2: int, 3: int, 4: bool, 5: string}>
	 */
	public function refusals(): array {
		return array(
			'a tampered code'             => array( 5, 7, self::FORM, self::FORM, true, 'invalid' ),
			'a code for another form'     => array( 5, 7, 41, self::FORM, false, 'invalid' ),
			'an unknown booking'          => array( 6, 7, self::FORM, self::FORM, false, 'invalid' ),
			'a deleted account'           => array( 5, 9, self::FORM, self::FORM, false, 'invalid' ),
			'a booking now on another form' => array( 5, 7, 41, 41, false, 'no_form' ),
		);
	}

	/**
	 * @dataProvider refusals
	 *
	 * @param int    $booking_id Booking the code names.
	 * @param int    $user_id    Person the code names.
	 * @param int    $signed_for Form the code is signed for.
	 * @param int    $form_id    Form being filled in.
	 * @param bool   $tamper     Whether to change the signature.
	 * @param string $error      Expected error code.
	 */
	public function test_each_refusal_names_its_reason( int $booking_id, int $user_id, int $signed_for, int $form_id, bool $tamper, string $error ): void {
		$code = AudienceFormInvite::code( $booking_id, $user_id, $signed_for );
		if ( $tamper ) {
			$code = substr( $code, 0, -1 ) . ( 'A' === substr( $code, -1 ) ? 'B' : 'A' );
		}

		$result = AudienceFormInviteResolver::resolve( $form_id, $code, null, $this->now );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $error, $result->get_error_code() );
		$this->assertNotSame( '', AudienceFormInviteResolver::message( $error ) );
	}

	public function test_a_closed_window_is_refused_by_the_eligibility_check(): void {
		$result = AudienceFormInviteResolver::resolve( self::FORM, AudienceFormInvite::code( 5, 7, self::FORM ), null, $this->now + 8 * DAY_IN_SECONDS );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'closed', $result->get_error_code() );
	}

	public function test_a_cpf_the_person_already_holds_is_accepted(): void {
		$hash                  = $this->hashOf( 'cpf', self::CPF );
		$this->owners[ $hash ] = 7;

		$this->assertTrue( AudienceFormInviteResolver::check_identifier( 7, self::CPF ) );

		// The lookup asked for the CPF hash in the CPF column, and nothing else.
		$this->assertNotSame( array(), $this->lookups );
		$this->assertStringContainsString( 'cpf_hash = %s', $this->lookups[0]['sql'] );
		$this->assertStringNotContainsString( 'rf_hash', $this->lookups[0]['sql'] );
		$this->assertSame( array( $hash ), array_slice( $this->lookups[0]['bound'], 2 ) );
	}

	public function test_a_cpf_of_another_account_is_refused(): void {
		$this->owners[ $this->hashOf( 'cpf', self::CPF ) ] = 12;

		$result = AudienceFormInviteResolver::resolve( self::FORM, AudienceFormInvite::code( 5, 7, self::FORM ), self::CPF, $this->now );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'identity_taken', $result->get_error_code() );
	}

	public function test_an_unknown_cpf_is_refused_when_the_person_has_another_on_record(): void {
		$this->stored_identifiers = array(
			array(
				'cpf_encrypted' => Encryption::encrypt( Identifiers::CPF_B ),
				'rf_encrypted'  => null,
			),
		);

		$result = AudienceFormInviteResolver::check_identifier( 7, self::CPF );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'identity_mismatch', $result->get_error_code() );
		$this->assertSame( array( 7 ), $this->identifier_reads, 'The identifiers on record are the invited person\'s.' );
	}

	public function test_a_first_identifier_is_accepted_and_an_rf_is_checked_as_an_rf(): void {
		$this->stored_identifiers = array(
			array(
				'cpf_encrypted' => Encryption::encrypt( Identifiers::CPF_B ),
				'rf_encrypted'  => null,
			),
		);

		$this->assertTrue( AudienceFormInviteResolver::check_identifier( 7, Identifiers::RF_A ), 'A CPF on record does not stop a first RF.' );

		// The lookup asked for the RF hash in the RF column, and nothing else.
		$this->assertNotSame( array(), $this->lookups );
		foreach ( $this->lookups as $lookup ) {
			$this->assertStringContainsString( 'rf_hash = %s', $lookup['sql'] );
			$this->assertStringNotContainsString( 'cpf_hash', $lookup['sql'] );
			$this->assertSame( array( $this->hashOf( 'rf', Identifiers::RF_A ) ), array_slice( $lookup['bound'], 2 ) );
		}
		$this->assertSame( array( 7 ), $this->identifier_reads );
	}

	public function test_the_filter_keeps_an_earlier_answer_and_resolves_otherwise(): void {
		$this->assertSame( 'earlier', AudienceFormInviteResolver::filter( 'earlier', self::FORM, 'x' ) );

		$result = AudienceFormInviteResolver::filter( null, self::FORM, 'not a code', '' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid', $result->get_error_code() );
	}

	public function test_registering_hooks_the_filter_the_form_fires(): void {
		AudienceFormInviteResolver::register();

		$this->assertNotFalse( has_filter( 'ffcertificate_form_invite', array( AudienceFormInviteResolver::class, 'filter' ) ) );
		$this->assertSame( \FreeFormCertificate\Frontend\FormInvite::FILTER, AudienceFormInviteResolver::FILTER, 'The two modules name one hook.' );
	}
}
